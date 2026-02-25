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
// 0. ACTIVATION & SETTINGS LINK
// ====================================================================

// Add Settings link on plugin page
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'bsp_add_settings_link');
function bsp_add_settings_link($links) {
    $settings_link = '<a href="options-general.php?page=breeze-smart-purge">' . __('Settings', 'breeze-smart-purge') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}

// Set a flag to run the initial scan safely AFTER activation
register_activation_hook(__FILE__, 'bsp_on_activation');
function bsp_on_activation() {
    update_option('bsp_needs_initial_scan', true);
}

// Run the deferred scan to prevent server timeouts during plugin activation
add_action('admin_init', 'bsp_run_deferred_scan');
function bsp_run_deferred_scan() {
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
// 3. ADMIN SETTINGS PAGE, UI, & CUSTOM LOG WINDOW
// ====================================================================

add_action('admin_menu', 'bsp_register_settings_page');
function bsp_register_settings_page() {
    add_options_page(
        'Breeze Smart Purge',
        'Breeze Smart Purge',
        'manage_options',
        'breeze-smart-purge',
        'bsp_render_settings_page'
    );
}

function bsp_render_settings_page() {
    if (!current_user_can('manage_options')) return;

    $settings = wp_parse_args(get_option('bsp_settings', []), [
        'hide_utility' => 'yes',
        'force_sync'   => 'yes'
    ]);
    
    $scan_log = get_option('bsp_scan_log', "System ready. Click 'Run Smart Scan' to begin.");

    // Handle Form Submission
    if (isset($_POST['bsp_save_settings']) && check_admin_referer('bsp_save_action')) {
        
        // Save Manual Map
        $new_manual_map = [];
        if (isset($_POST['bsp_manual_map']) && is_array($_POST['bsp_manual_map'])) {
            $unslashed_map = wp_unslash($_POST['bsp_manual_map']);
            foreach ($unslashed_map as $post_type => $urls_string) {
                $urls_array = array_unique(array_filter(array_map('sanitize_text_field', array_map('trim', explode("\n", $urls_string)))));
                $new_manual_map[sanitize_text_field($post_type)] = $urls_array;
            }
        }
        update_option('bsp_manual_map', $new_manual_map);

        // Save Ignored Map
        $new_ignored_map = [];
        if (isset($_POST['bsp_ignored_map']) && is_array($_POST['bsp_ignored_map'])) {
            $unslashed_ignored = wp_unslash($_POST['bsp_ignored_map']);
            foreach ($unslashed_ignored as $post_type => $urls_string) {
                $urls_array = array_unique(array_filter(array_map('sanitize_text_field', array_map('trim', explode("\n", $urls_string)))));
                $new_ignored_map[sanitize_text_field($post_type)] = $urls_array;
            }
        }
        update_option('bsp_ignored_map', $new_ignored_map);

        // Save Disable Archive & Tax Maps
        $disable_archive = isset($_POST['bsp_disable_archive']) && is_array($_POST['bsp_disable_archive']) 
            ? array_map('sanitize_text_field', wp_unslash($_POST['bsp_disable_archive'])) 
            : [];
        update_option('bsp_disable_archive_map', $disable_archive);

        $disable_tax = isset($_POST['bsp_disable_tax']) && is_array($_POST['bsp_disable_tax']) 
            ? array_map('sanitize_text_field', wp_unslash($_POST['bsp_disable_tax'])) 
            : [];
        update_option('bsp_disable_tax_map', $disable_tax);

        $settings['hide_utility'] = isset($_POST['setting_hide_utility']) ? 'yes' : 'no';
        $settings['force_sync']   = isset($_POST['setting_force_sync']) ? 'yes' : 'no';
        update_option('bsp_settings', $settings);

        echo '<div class="notice notice-success is-dismissible"><p>Settings and Custom Mappings saved securely.</p></div>';
    }

    // Handle Scan Trigger
    if (isset($_POST['bsp_run_scan']) && check_admin_referer('bsp_save_action')) {
        $settings['hide_utility'] = isset($_POST['setting_hide_utility']) ? 'yes' : 'no';
        $settings['force_sync']   = isset($_POST['setting_force_sync']) ? 'yes' : 'no';
        update_option('bsp_settings', $settings);

        $scan_log = bsp_execute_auto_scanner($settings);
        update_option('bsp_scan_log', $scan_log);
    }

    $scanned_map = get_option('bsp_scanned_map', []);
    $manual_map  = get_option('bsp_manual_map', []);
    $ignored_map = get_option('bsp_ignored_map', []);
    
    $disable_archive_map = get_option('bsp_disable_archive_map', []);
    $disable_tax_map     = get_option('bsp_disable_tax_map', []);

    $public_post_types = get_post_types(['public' => true], 'objects');
    
    $utility_types = bsp_get_utility_post_types();
    $hidden_type_slugs = array_keys($utility_types);

    ?>
    <div class="wrap">
        <h1>Breeze Smart Purge</h1>
        <div style="background: #fff; padding: 15px 20px; border-left: 4px solid #2271b1; margin-bottom: 20px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
            <p style="margin: 0; font-size: 14px;"><strong>The Problem:</strong> By default, Breeze aggressively caches content. When you update a post, it only clears the cache for that specific post. This leaves your important hub pages like: post grids, custom taxonomy archives, and page builder layouts, serving stale content to users.</p>
            <p style="margin: 8px 0 0 0; font-size: 14px;"><strong>The Solution:</strong> This tool acts as a traffic controller. The Auto-Scanner detects which pages are querying specific Post Types, ensuring Breeze safely clears the cache for the parent pages whenever a post is updated.</p>
        </div>
        
        <form method="post" action="">
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
                    
                    <button type="submit" name="bsp_run_scan" class="button button-secondary">Run Smart Scan</button>
                    <button type="submit" name="bsp_save_settings" class="button button-primary">Save Settings</button>
                </div>

                <div style="flex: 2; min-width: 400px; background: #1e1e1e; color: #00ff00; padding: 15px; border-radius: 4px; font-family: monospace; min-height: 190px; max-height:400px; overflow-y: auto;">
                    <div style="color: #aaa; margin-bottom: 10px;">--- SYSTEM LOG & NOTIFICATIONS ---</div>
                    <?php echo nl2br(esc_html($scan_log)); ?>
                </div>
            </div>

            <hr>

            <table class="form-table">
                <?php foreach ($public_post_types as $slug => $pt): ?>
                    <?php 
                        if ($settings['hide_utility'] === 'yes' && in_array($slug, $hidden_type_slugs)) continue; 
                        
                        $scanned_urls = isset($scanned_map[$slug]) ? implode("\n", $scanned_map[$slug]) : '';
                        $manual_urls  = isset($manual_map[$slug]) ? implode("\n", $manual_map[$slug]) : '';
                        $ignored_urls = isset($ignored_map[$slug]) ? implode("\n", $ignored_map[$slug]) : '';
                    ?>
                    <tr>
                        <th scope="row" style="vertical-align: top;">
                            <label>
                                <strong style="font-size: 1.1em;"><?php echo esc_html($pt->labels->name); ?></strong><br>
                                <code style="background: #f0f0f1; padding: 3px 6px;"><?php echo esc_html($slug); ?></code>
                            </label>
                            
                            <div style="margin-top: 15px; font-weight: normal; font-size: 12px;">
                                <label style="display: block; margin-bottom: 6px;">
                                    <input type="checkbox" name="bsp_disable_archive[]" value="<?php echo esc_attr($slug); ?>" <?php checked(in_array($slug, $disable_archive_map)); ?>>
                                    Disable Native Archive Purge
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
                <button type="submit" name="bsp_save_settings" class="button button-primary button-large">Save Custom Mappings</button>
            </p>
        </form>
    </div>
    <?php
}

// ====================================================================
// 4. THE AUTO-SCANNER ALGORITHM
// ====================================================================

function bsp_execute_auto_scanner($settings) {
    $scanned_map = [];
    $public_post_types = get_post_types(['public' => true], 'names');
    
    // Fetch dynamic utility types
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

    foreach ($pages as $page) {
        $content = $page->post_content;
        
        $page_path = wp_parse_url(get_permalink($page->ID), PHP_URL_PATH);
        if (empty($page_path) || $page_path === '') {
            $page_path = '/';
        }

        $elementor_data = stripslashes(get_post_meta($page->ID, '_elementor_data', true) ?: '');
        $bricks_data    = get_post_meta($page->ID, '_bricks_page_content', true) ?: '';
        $oxygen_data    = get_post_meta($page->ID, 'ct_builder_json', true) ?: '';
        $beaver_data    = get_post_meta($page->ID, '_fl_builder_data', true) ?: '';
        if (is_array($beaver_data) || is_object($beaver_data)) {
            $beaver_data = serialize($beaver_data); 
        }

        foreach ($public_post_types as $pt) {
            if ($settings['hide_utility'] === 'yes' && in_array($pt, $hidden_type_slugs)) continue;

            $found_builders = [];
            
            // 1. Gutenberg, Divi, WPBakery, & Standard Shortcodes
            if (strpos($content, '"postType":"' . $pt . '"') !== false || strpos($content, 'post_type="' . $pt . '"') !== false || strpos($content, "post_type='" . $pt . "'") !== false) {
                $found_builders[] = "Gutenberg/Shortcode";
            }
            
            // 2. Elementor
            // A) Check for explicit definitions in Addons (post_type, posts_post_type, source, query)
            $el_regex = '/"(?:[a-zA-Z0-9_]*post_type|source|query)"\s*:\s*\[?\s*"' . preg_quote($pt, '/') . '"\s*\]?/i';
            if (preg_match($el_regex, $elementor_data)) {
                $found_builders[] = "Elementor";
            } 
            // B) Fallback: Catch native Elementor and Addon widgets that omit the parameter to query 'posts' implicitly
            elseif ($pt === 'post' && preg_match('/"widgetType"\s*:\s*"[a-zA-Z0-9_-]*(?:post|loop|blog|magazine)[a-zA-Z0-9_-]*"/i', $elementor_data)) {
                $found_builders[] = "Elementor (Implicit Posts)";
            }

            // 3. Bricks Builder
            if (strpos($bricks_data, '"postType":"' . $pt . '"') !== false || strpos($bricks_data, '"post_type":"' . $pt . '"') !== false) {
                $found_builders[] = "Bricks";
            }
            // 4. Oxygen Builder
            if (strpos($oxygen_data, '"post_type":"' . $pt . '"') !== false) {
                $found_builders[] = "Oxygen";
            }
            // 5. Beaver Builder
            if (strpos($beaver_data, '"' . $pt . '"') !== false && strpos($beaver_data, 'post_type') !== false) {
                $found_builders[] = "Beaver Builder";
            }
            
            // IF FOUND BY ANY BUILDER: Add to map and log it
            if (!empty($found_builders)) {
                if (!isset($scanned_map[$pt])) {
                    $scanned_map[$pt] = [];
                }
                if (!in_array($page_path, $scanned_map[$pt])) {
                    $scanned_map[$pt][] = $page_path;
                    
                    // Combine builder names for the log (e.g., "Elementor & Gutenberg/Shortcode")
                    $b_names = implode(' & ', $found_builders);
                    $log_output .= "[DETECTED] $b_names mapped '$pt' to $page_path \n";
                    $relations_found++;
                }
            }
        }
    }

    $log_output .= "----------------------------------------\n";
    $log_output .= "Scan Complete. Found $relations_found automatic URL mapping(s).\n";
    
    update_option('bsp_scanned_map', $scanned_map);
    return $log_output;
}

// ====================================================================
// 5. UNINSTALL CLEANUP
// ====================================================================

register_uninstall_hook(__FILE__, 'bsp_plugin_uninstall');

function bsp_plugin_uninstall() {
    // Clean up all permanent options
    delete_option('bsp_settings');
    delete_option('bsp_scanned_map');
    delete_option('bsp_manual_map');
    delete_option('bsp_ignored_map');
    delete_option('bsp_disable_archive_map');
    delete_option('bsp_disable_tax_map');
    delete_option('bsp_scan_log');
    
    // Clean up any stray activation flags or transients
    delete_option('bsp_needs_initial_scan');
    delete_transient('bsp_scan_summary_notice');
}
