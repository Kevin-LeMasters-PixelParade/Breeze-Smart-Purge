<?php
/**
 * Plugin Name: Breeze Smart Purge
 * Plugin URI: https://pixelparade.co
 * Description: Intelligently purges CPT Archives, Taxonomies, and Custom Page Builder Hubs via Breeze and Cloudflare.
 * Version: 1.0.0
 * Author: PixelParade LLC
 * Author URI: https://pixelparade.co
 * License: GPL v2 or later
 * Text Domain: breeze-smart-purge
 */

if (!defined('ABSPATH')) {
    exit;
}

// ====================================================================
// 0. DEPENDENCY CHECK, ACTIVATION & ADMIN BAR LINK
// ====================================================================

// Check if Breeze is active before running any logic
function bsp_check_dependencies() {
    if (!defined('BREEZE_VERSION')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p><strong>Breeze Smart Purge</strong> requires the <a href="https://wordpress.org/plugins/breeze/" target="_blank">Breeze Cache</a> plugin to be active. Please activate it to enable smart purging.</p></div>';
        });
        return false;
    }
    return true;
}

// Add link to the Plugins page
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'bsp_add_settings_link');
function bsp_add_settings_link($links) {
    $settings_link = '<a href="options-general.php?page=breeze-smart-purge">' . __('Settings', 'breeze-smart-purge') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}

// Inject link into the Breeze Top Admin Bar dropdown (Forced to the bottom)
add_action('wp_before_admin_bar_render', 'bsp_add_breeze_admin_bar_node');
function bsp_add_breeze_admin_bar_node() {
    global $wp_admin_bar;
    
    if (!current_user_can('manage_options') || !defined('BREEZE_VERSION')) return;
    
    $wp_admin_bar->add_node([
        'id'     => 'breeze-smart-purge-link',
        'title'  => 'Smart Purge Settings',
        'parent' => 'breeze-topbar', // Targets the Breeze parent dropdown
        'href'   => admin_url('options-general.php?page=breeze-smart-purge'),
    ]);
}

// Set a flag to run the initial scan safely AFTER activation
register_activation_hook(__FILE__, 'bsp_on_activation');
function bsp_on_activation() {
    update_option('bsp_needs_initial_scan', true);
}

// Run the deferred scan to prevent server timeouts during plugin activation
add_action('admin_init', 'bsp_run_deferred_scan');
function bsp_run_deferred_scan() {
    if (!bsp_check_dependencies()) return; // Abort if Breeze is missing

    if (get_option('bsp_needs_initial_scan')) {
        delete_option('bsp_needs_initial_scan');
        $settings = wp_parse_args(get_option('bsp_settings', []), ['hide_utility' => 'yes', 'force_sync' => 'yes']);
        $log = bsp_execute_auto_scanner($settings);
        set_transient('bsp_scan_summary_notice', $log, 60); // Save log to display once
    }
}

// Display the success notice after initial scan
add_action('admin_notices', 'bsp_display_scan_notice');
function bsp_display_scan_notice() {
    if ($notice = get_transient('bsp_scan_summary_notice')) {
        ?>
        <div class="notice notice-success is-dismissible">
            <p><strong>Breeze Smart Purge Activated!</strong> Initial auto-scan complete.</p>
            <p style="font-family: monospace; font-size: 13px;"><?php echo nl2br(esc_html($notice)); ?></p>
        </div>
        <?php
        delete_transient('bsp_scan_summary_notice');
    }
}

// ====================================================================
// 1. CORE CACHE LOGIC
// ====================================================================

// Only run the core logic if Breeze is active
if (defined('BREEZE_VERSION')) {
    $bsp_global_settings = wp_parse_args(get_option('bsp_settings', []), ['hide_utility' => 'yes', 'force_sync' => 'yes']);

    // Toggleable Synchronous Purge
    if ($bsp_global_settings['force_sync'] === 'yes') {
        add_filter('breeze_cf_purge_type_on_post_update', function() {
            return 'synchronous';
        });
    }

    add_action('breeze_clear_all_cache', 'bsp_force_cloudflare_flush');
    function bsp_force_cloudflare_flush() {
        if (class_exists('Breeze_CloudFlare_Helper')) {
            Breeze_CloudFlare_Helper::reset_all_cache();
        }
    }

    add_filter('breeze_purge_post_cache_urls', 'bsp_master_breeze_strategy', 10, 2);
    function bsp_master_breeze_strategy($urls, $post_id) {
        if (!is_array($urls)) $urls = [];
        
        $post = get_post($post_id);
        if (!$post) return $urls;

        $scanned_map = get_option('bsp_scanned_map', []);
        $manual_map  = get_option('bsp_manual_map', []);
        $ignored_map = get_option('bsp_ignored_map', []);
        
        $disable_archive_map = get_option('bsp_disable_archive_map', []);
        $disable_tax_map     = get_option('bsp_disable_tax_map', []);

        $combined_urls = [];
        if (isset($scanned_map[$post->post_type])) {
            $combined_urls = array_merge($combined_urls, $scanned_map[$post->post_type]);
        }
        if (isset($manual_map[$post->post_type])) {
            $combined_urls = array_merge($combined_urls, $manual_map[$post->post_type]);
        }

        // --- APPLY EXCLUSIONS ---
        $ignored_urls = isset($ignored_map[$post->post_type]) ? $ignored_map[$post->post_type] : [];
        
        // If the wildcard '*' is used, wipe all mapped pages for this post type
        if (in_array('*', $ignored_urls)) {
            $combined_urls = []; 
        } else {
            // Otherwise, subtract the ignored URLs from the combined list
            $combined_urls = array_diff($combined_urls, $ignored_urls);
        }

        foreach ($combined_urls as $page_path) {
            $page_path = trim($page_path);
            if (!empty($page_path)) {
                $urls[] = trailingslashit(home_url($page_path));
            }
        }

        // Native Archive Purging
        if (!in_array($post->post_type, $disable_archive_map)) {
            $archive_link = get_post_type_archive_link($post->post_type);
            if ($archive_link) {
                $urls[] = trailingslashit($archive_link);
            }
        }

        // Native Taxonomy Purging
        if (!in_array($post->post_type, $disable_tax_map)) {
            $taxonomies = get_object_taxonomies($post);
            foreach ($taxonomies as $tax_slug) {
                $terms = get_the_terms($post_id, $tax_slug);
                if (!empty($terms) && !is_wp_error($terms)) {
                    foreach ($terms as $term) {
                        $term_link = get_term_link($term);
                        if (!is_wp_error($term_link)) {
                            $urls[] = trailingslashit($term_link);
                        }
                    }
                }
            }
        }

        if ($post->post_type === 'post') {
            $page_for_posts_id = get_option('page_for_posts');
            if ($page_for_posts_id) {
                $urls[] = trailingslashit(get_permalink($page_for_posts_id));
            }
        }

        return array_unique($urls);
    }
}

// ====================================================================
// 2. HELPER: DYNAMIC UTILITY DETECTION
// ====================================================================

function bsp_get_utility_post_types() {
    $utility_types = [];
    $all_types = get_post_types(['public' => true], 'objects');
    
    foreach ($all_types as $slug => $pt) {
        if ($slug === 'attachment' || $slug === 'page') {
            $utility_types[$slug] = $pt->labels->name;
        } elseif (
            empty($pt->publicly_queryable) || 
            strpos($slug, 'fl-builder') !== false || 
            strpos($slug, 'cmplz') !== false || 
            strpos($slug, 'ppwp') !== false || 
            strpos($slug, 'wp_') === 0
        ) {
            $utility_types[$slug] = $pt->labels->name;
        }
    }
    return $utility_types;
}

// ====================================================================
// 3. ADMIN SETTINGS PAGE & UI
// ====================================================================

add_action('admin_menu', 'bsp_register_settings_page');
function bsp_register_settings_page() {
    add_options_page(
        'Breeze Smart Purge',
        'Breeze Smart Purge', // Changed back per your request
        'manage_options',
        'breeze-smart-purge',
        'bsp_render_settings_page'
    );
}

function bsp_render_settings_page() {
    if (!current_user_can('manage_options')) return;
    if (!bsp_check_dependencies()) return; // Stop rendering if Breeze is missing

    $settings = wp_parse_args(get_option('bsp_settings', []), [
        'hide_utility' => 'yes',
        'force_sync'   => 'yes'
    ]);
    
    $scan_log = get_option('bsp_scan_log', "System ready. Click 'Run Smart Scan' to begin.");
    $scanned_map = get_option('bsp_scanned_map', []);
    $manual_map  = get_option('bsp_manual_map', []);
    $ignored_map = get_option('bsp_ignored_map', []);
    $disable_archive_map = get_option('bsp_disable_archive_map', []);
    $disable_tax_map     = get_option('bsp_disable_tax_map', []);
    
    $public_post_types = get_post_types(['public' => true], 'objects');
    $utility_types = bsp_get_utility_post_types();
    $hidden_type_slugs = array_keys($utility_types);

    ?>
    <style>
        /* Toast Notification Styles */
        #bsp-toast {
            visibility: hidden;
            min-width: 250px;
            background-color: #333;
            color: #fff;
            text-align: center;
            border-radius: 4px;
            padding: 16px;
            position: fixed;
            z-index: 99999;
            left: 50%;
            bottom: 30px;
            transform: translateX(-50%);
            font-size: 15px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            opacity: 0;
            transition: opacity 0.3s, bottom 0.3s;
            font-weight: 500;
        }
        #bsp-toast.show {
            visibility: visible;
            opacity: 1;
            bottom: 50px;
        }
        #bsp-toast.success { background-color: #46b450; border-left: 4px solid #34823b; }
        #bsp-toast.error { background-color: #dc3232; border-left: 4px solid #a32424; }
    </style>

    <div class="wrap">
        <h1>Breeze Smart Purge</h1>
        <div style="background: #fff; padding: 15px 20px; border-left: 4px solid #2271b1; margin-bottom: 20px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
            <p style="margin: 0; font-size: 14px;"><strong>The Problem:</strong> By default, Breeze aggressively caches content. When you update a post, it only clears the cache for that specific post. This leaves your important hub pages like: post grids, custom taxonomy archives, and page builder layouts, serving stale content to users.</p>
            <p style="margin: 8px 0 0 0; font-size: 14px;"><strong>The Solution:</strong> This tool acts as a traffic controller. The Auto-Scanner detects which pages are querying specific Post Types, ensuring Breeze safely clears the cache for the parent pages whenever a post is updated.</p>
        </div>
        
        <form id="bsp-settings-form" method="post" action="" onsubmit="return false;">
            <?php wp_nonce_field('bsp_save_action'); ?>
            
            <div style="display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 20px;">
                <div style="flex: 1; min-width: 300px; background: #fff; padding: 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                    <h3 style="margin-top:0;">Global Settings</h3>
                    
                    <label>
                        <input type="checkbox" name="setting_force_sync" <?php checked($settings['force_sync'], 'yes'); ?>>
                        <strong>Force Synchronous Cloudflare Purge</strong>
                    </label>
                    <p class="description" style="margin: 0 0 15px 24px;">Bypasses the default WP-Cron delay so cache purges happen instantly on "Update".</p>

                    <label>
                        <input type="checkbox" name="setting_hide_utility" <?php checked($settings['hide_utility'], 'yes'); ?>>
                        <strong>Hide Utility Post Types from UI</strong>
                    </label>
                    <p class="description" style="margin: 0 0 20px 24px;">Hides background CPTs. <br><em>Auto-Detected: <code><?php echo esc_html(implode(', ', $hidden_type_slugs)); ?></code></em></p>
                    
                    <div style="display: flex; gap: 10px; margin-top: 20px;">
                        <button type="button" id="bsp-btn-scan" class="button button-secondary">Run Smart Scan</button>
                        <button type="button" class="button button-primary bsp-btn-save">Save All Changes</button>
                    </div>
                </div>

                <div style="flex: 2; min-width: 400px; background: #1e1e1e; color: #00ff00; padding: 15px; border-radius: 4px; font-family: monospace; min-height: 190px; max-height:400px; overflow-y: auto;">
                    <div style="color: #aaa; margin-bottom: 10px;">--- SYSTEM LOG & NOTIFICATIONS ---</div>
                    <span id="bsp-scan-log-text"><?php echo nl2br(esc_html($scan_log)); ?></span>
                </div>
            </div>

            <hr>

            <table class="form-table">
                <?php foreach ($public_post_types as $slug => $pt): ?>
                    <?php 
                        if ($settings['hide_utility'] === 'yes' && in_array($slug, $hidden_type_slugs)) continue; 
                        
                        $scanned_urls = (isset($scanned_map[$slug]) && !empty($scanned_map[$slug])) ? implode("\n", $scanned_map[$slug]) : '[No pages auto-detected]';
                        $manual_urls  = isset($manual_map[$slug]) ? implode("\n", $manual_map[$slug]) : '';
                        $ignored_urls = isset($ignored_map[$slug]) ? implode("\n", $ignored_map[$slug]) : '';
                    ?>
                    <tr>
                        <th scope="row" style="vertical-align: top; padding-right: 25px; padding-top:15px; padding-bottom:15px;">
                            <label>
                                <strong style="font-size: 1.1em;"><?php echo esc_html($pt->labels->name); ?></strong><br>
                                <code style="background: #f0f0f1; padding: 3px 6px;"><?php echo esc_html($slug); ?></code>
                            </label>
                            
                            <div style="margin-top: 5px; background: #f9f9f9; border: 1px solid #e2e4e7; padding: 12px; border-radius: 4px; font-weight: normal; font-size: 12px;">
                                <label style="display: block; margin-bottom: 6px;">
                                    <input type="checkbox" name="bsp_disable_archive[]" value="<?php echo esc_attr($slug); ?>" <?php checked(in_array($slug, $disable_archive_map)); ?>>
                                    Disable Archive Purge
                                </label>
                                <label style="display: block;">
                                    <input type="checkbox" name="bsp_disable_tax[]" value="<?php echo esc_attr($slug); ?>" <?php checked(in_array($slug, $disable_tax_map)); ?>>
                                    Disable Taxonomy Purge
                                </label>
                            </div>
                        </th>
                        <td>
                            <div style="display: flex; gap: 15px;">
                                <div style="flex: 1;">
                                    <strong>Auto-Scanned (Read Only)</strong><br>
                                    <textarea 
                                        id="scanned-map-<?php echo esc_attr($slug); ?>"
                                        readonly
                                        rows="4" 
                                        style="width: 100%; font-family: monospace; background: #f0f0f1; border-color: #ddd; color: #666; margin-top: 4px;"
                                    ><?php echo esc_textarea($scanned_urls); ?></textarea>
                                </div>

                                <div style="flex: 1;">
                                    <strong>Manual Additions</strong><br>
                                    <textarea 
                                        name="bsp_manual_map[<?php echo esc_attr($slug); ?>]" 
                                        rows="4" 
                                        style="width: 100%; font-family: monospace; margin-top: 4px;"
                                        placeholder="/example-custom-url/"
                                    ><?php echo esc_textarea($manual_urls); ?></textarea>
                                </div>

                                <div style="flex: 1;">
                                    <strong>Ignored URLs</strong> <span style="font-weight:normal; font-size:12px; color:#666;">(Type <code>*</code> to disable all)</span><br>
                                    <textarea 
                                        name="bsp_ignored_map[<?php echo esc_attr($slug); ?>]" 
                                        rows="4" 
                                        style="width: 100%; font-family: monospace; border-color: #ffbba1; margin-top: 4px;"
                                    ><?php echo esc_textarea($ignored_urls); ?></textarea>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>

            <p class="submit">
                <button type="button" class="button button-primary button-large bsp-btn-save">Save All Changes</button>
            </p>
        </form>
    </div>

    <div id="bsp-toast">Message</div>

    <script>
    document.addEventListener("DOMContentLoaded", function() {
        // Universal Toast Function
        function bspShowToast(msg, type = 'success') {
            var toast = document.getElementById("bsp-toast");
            toast.textContent = msg;
            toast.className = type + " show";
            setTimeout(function(){ toast.className = toast.className.replace("show", ""); }, 3000);
        }

        // AJAX SCAN HANDLER
        document.getElementById('bsp-btn-scan').addEventListener('click', function() {
            var btn = this;
            var logText = document.getElementById('bsp-scan-log-text');
            var originalBtnText = btn.innerHTML;
            
            btn.innerHTML = 'Scanning...';
            btn.style.pointerEvents = 'none';
            logText.innerHTML = 'Scanning in progress... this may take a few seconds.';

            var data = new FormData();
            data.append('action', 'bsp_run_ajax_scan');
            data.append('_wpnonce', document.getElementById('_wpnonce').value);

            fetch(ajaxurl, { method: 'POST', body: data })
            .then(response => response.json())
            .then(res => {
                if(res.success) {
                    // Update Log
                    logText.innerHTML = res.data.log.replace(/\n/g, "<br>");
                    // Clear all readonly boxes, then repopulate
                    document.querySelectorAll('textarea[id^="scanned-map-"]').forEach(ta => ta.value = '[No pages auto-detected]');
                    for (const [postType, urls] of Object.entries(res.data.map)) {
                        var ta = document.getElementById('scanned-map-' + postType);
                        if (ta && urls.length > 0) ta.value = urls.join('\n');
                    }
                    bspShowToast('Scan Complete!', 'success');
                } else {
                    logText.innerHTML = '<span style="color:red;">Scan failed. Please refresh and try again.</span>';
                    bspShowToast('Scan failed.', 'error');
                }
                btn.innerHTML = originalBtnText;
                btn.style.pointerEvents = 'auto';
            }).catch(err => {
                bspShowToast('Server error.', 'error');
                btn.innerHTML = originalBtnText;
                btn.style.pointerEvents = 'auto';
            });
        });

        // AJAX SAVE HANDLER (Applies to both top and bottom save buttons)
        document.querySelectorAll('.bsp-btn-save').forEach(btn => {
            btn.addEventListener('click', function() {
                var currentBtn = this;
                var originalBtnText = currentBtn.innerHTML;
                
                currentBtn.innerHTML = 'Saving...';
                currentBtn.style.pointerEvents = 'none';

                var form = document.getElementById('bsp-settings-form');
                var data = new FormData(form);
                data.append('action', 'bsp_run_ajax_save');

                fetch(ajaxurl, { method: 'POST', body: data })
                .then(response => response.json())
                .then(res => {
                    if(res.success) {
                        bspShowToast('Settings Saved Successfully!', 'success');
                    } else {
                        bspShowToast('Error saving settings.', 'error');
                    }
                    currentBtn.innerHTML = originalBtnText;
                    currentBtn.style.pointerEvents = 'auto';
                }).catch(err => {
                    bspShowToast('Server error.', 'error');
                    currentBtn.innerHTML = originalBtnText;
                    currentBtn.style.pointerEvents = 'auto';
                });
            });
        });
    });
    </script>
    <?php
}

// ====================================================================
// 4. THE AUTO-SCANNER ALGORITHM
// ====================================================================

function bsp_execute_auto_scanner($settings) {
    $scanned_map = [];
    $public_post_types = get_post_types(['public' => true], 'names');
    
    $utility_types = bsp_get_utility_post_types();
    $hidden_type_slugs = array_keys($utility_types);
    
    $pages = get_posts([
        'post_type' => 'page',
        'post_status' => 'publish',
        'posts_per_page' => -1
    ]);

    $log_output = "Scan initiated at " . current_time('mysql') . "\n";
    $log_output .= "Total Pages to scan: " . count($pages) . "\n";
    $log_output .= "----------------------------------------\n";
    $relations_found = 0;

    $normalize_meta = function($meta) {
        if (empty($meta)) return '';
        if (is_string($meta)) return stripslashes($meta);
        if (is_array($meta) || is_object($meta)) return wp_json_encode($meta);
        return '';
    };

    foreach ($pages as $page) {
        $content = $page->post_content;
        
        $page_path = wp_parse_url(get_permalink($page->ID), PHP_URL_PATH);
        if (empty($page_path) || $page_path === '') {
            $page_path = '/';
        }

        $elementor_data = $normalize_meta(get_post_meta($page->ID, '_elementor_data', true));
        $oxygen_data    = $normalize_meta(get_post_meta($page->ID, 'ct_builder_json', true));
        $beaver_data    = $normalize_meta(get_post_meta($page->ID, '_fl_builder_data', true));
        
        $bricks_data_1  = $normalize_meta(get_post_meta($page->ID, '_bricks_page_content', true));
        $bricks_data_2  = $normalize_meta(get_post_meta($page->ID, '_bricks_page_content_2', true));
        $bricks_data    = $bricks_data_1 . ' ' . $bricks_data_2;

        foreach ($public_post_types as $pt) {
            if ($settings['hide_utility'] === 'yes' && in_array($pt, $hidden_type_slugs)) continue;

            $found_builders = [];
            
            if (preg_match('/post_type=[\'"]' . preg_quote($pt, '/') . '[\'"]/i', $content) || strpos($content, '"postType":"' . $pt . '"') !== false) {
                $found_builders[] = "Gutenberg/Shortcode";
            }
            
            $json_regex = '/"[a-zA-Z0-9_-]*(?:post_type|postType|source|query)"\s*:\s*(?:\[[^\]]*?)?"' . preg_quote($pt, '/') . '"/i';

            if (preg_match($json_regex, $elementor_data)) {
                $found_builders[] = "Elementor";
            } 
            elseif ($pt === 'post' && preg_match('/"widgetType"\s*:\s*"[a-zA-Z0-9_-]*(?:post|loop|blog|magazine)[a-zA-Z0-9_-]*"/i', $elementor_data)) {
                $found_builders[] = "Elementor (Implicit Posts)";
            }

            if (preg_match($json_regex, $bricks_data)) {
                $found_builders[] = "Bricks";
            }
            elseif ($pt === 'post' && strpos($bricks_data, '"hasLoop":true') !== false && preg_match_all('/"query"\s*:\s*\{([^{}]*)\}/i', $bricks_data, $matches)) {
                foreach ($matches[1] as $query_settings) {
                    if (strpos($query_settings, '"post_type"') === false && strpos($query_settings, '"postType"') === false) {
                        $found_builders[] = "Bricks (Implicit Posts)";
                        break;
                    }
                }
            }

            if (preg_match($json_regex, $oxygen_data)) {
                $found_builders[] = "Oxygen";
            }
            
            if (preg_match($json_regex, $beaver_data) || (strpos($beaver_data, '"' . $pt . '"') !== false && strpos($beaver_data, 'post_type') !== false)) {
                $found_builders[] = "Beaver Builder";
            }
            
            if (!empty($found_builders)) {
                if (!isset($scanned_map[$pt])) {
                    $scanned_map[$pt] = [];
                }
                if (!in_array($page_path, $scanned_map[$pt])) {
                    $scanned_map[$pt][] = $page_path;
                    
                    $b_names = implode(' & ', array_unique($found_builders));
                    $log_output .= "[DETECTED] $b_names mapped '$pt' to $page_path \n";
                    $relations_found++;
                }
            }
        }
    }

    $log_output .= "----------------------------------------\n";
    $log_output .= "Scan Complete. Found $relations_found automatic URL mapping(s).\n";
    
    update_option('bsp_scanned_map', $scanned_map, false);
    return $log_output;
}

// ====================================================================
// 5. AJAX SERVER ENDPOINTS
// ====================================================================

add_action('wp_ajax_bsp_run_ajax_scan', 'bsp_ajax_scan_handler');
function bsp_ajax_scan_handler() {
    check_ajax_referer('bsp_save_action', '_wpnonce');
    if (!current_user_can('manage_options')) wp_send_json_error();
    
    $settings = wp_parse_args(get_option('bsp_settings', []), ['hide_utility' => 'yes', 'force_sync' => 'yes']);
    $log = bsp_execute_auto_scanner($settings);
    update_option('bsp_scan_log', $log, false);
    
    wp_send_json_success([
        'log' => $log,
        'map' => get_option('bsp_scanned_map', [])
    ]);
}

add_action('wp_ajax_bsp_run_ajax_save', 'bsp_ajax_save_handler');
function bsp_ajax_save_handler() {
    check_ajax_referer('bsp_save_action', '_wpnonce');
    if (!current_user_can('manage_options')) wp_send_json_error();

    // Save Manual Map
    $new_manual_map = [];
    if (isset($_POST['bsp_manual_map']) && is_array($_POST['bsp_manual_map'])) {
        $unslashed_map = wp_unslash($_POST['bsp_manual_map']);
        foreach ($unslashed_map as $post_type => $urls_string) {
            $urls_array = array_unique(array_filter(array_map('sanitize_text_field', array_map('trim', explode("\n", $urls_string)))));
            $new_manual_map[sanitize_text_field($post_type)] = $urls_array;
        }
    }
    update_option('bsp_manual_map', $new_manual_map, false);

    // Save Ignored Map
    $new_ignored_map = [];
    if (isset($_POST['bsp_ignored_map']) && is_array($_POST['bsp_ignored_map'])) {
        $unslashed_ignored = wp_unslash($_POST['bsp_ignored_map']);
        foreach ($unslashed_ignored as $post_type => $urls_string) {
            $urls_array = array_unique(array_filter(array_map('sanitize_text_field', array_map('trim', explode("\n", $urls_string)))));
            $new_ignored_map[sanitize_text_field($post_type)] = $urls_array;
        }
    }
    update_option('bsp_ignored_map', $new_ignored_map, false);

    // Save Disable Archive & Tax Maps
    $disable_archive = isset($_POST['bsp_disable_archive']) && is_array($_POST['bsp_disable_archive']) 
        ? array_map('sanitize_text_field', wp_unslash($_POST['bsp_disable_archive'])) 
        : [];
    update_option('bsp_disable_archive_map', $disable_archive);

    $disable_tax = isset($_POST['bsp_disable_tax']) && is_array($_POST['bsp_disable_tax']) 
        ? array_map('sanitize_text_field', wp_unslash($_POST['bsp_disable_tax'])) 
        : [];
    update_option('bsp_disable_tax_map', $disable_tax);

    // Save Global Settings
    $settings = [
        'hide_utility' => isset($_POST['setting_hide_utility']) ? 'yes' : 'no',
        'force_sync'   => isset($_POST['setting_force_sync']) ? 'yes' : 'no'
    ];
    update_option('bsp_settings', $settings);

    wp_send_json_success();
}

// ====================================================================
// 6. UNINSTALL CLEANUP
// ====================================================================

register_uninstall_hook(__FILE__, 'bsp_plugin_uninstall');

function bsp_plugin_uninstall() {
    delete_option('bsp_settings');
    delete_option('bsp_scanned_map');
    delete_option('bsp_manual_map');
    delete_option('bsp_ignored_map');
    delete_option('bsp_disable_archive_map');
    delete_option('bsp_disable_tax_map');
    delete_option('bsp_scan_log');
    delete_option('bsp_needs_initial_scan');
    delete_transient('bsp_scan_summary_notice');
}
