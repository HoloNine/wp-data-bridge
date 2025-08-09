<?php
if (!defined('ABSPATH')) {
    exit;
}

$available_sites = WP_Data_Bridge_Admin::get_available_sites();
$is_multisite = is_multisite();
?>

<div class="wrap wp-data-bridge-admin">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    
    <div class="wp-data-bridge-header">
        <p class="description">
            <?php esc_html_e('Export posts, pages, custom post types, users, and featured images from your WordPress site to CSV format.', 'wp-data-bridge'); ?>
        </p>
    </div>

    <div class="wp-data-bridge-content">
        <form id="wp-data-bridge-form" method="post">
            <?php wp_nonce_field('wp_data_bridge_nonce', 'wp_data_bridge_nonce'); ?>
            
            <table class="form-table" role="presentation">
                
                <?php if ($is_multisite && count($available_sites) > 1): ?>
                <tr>
                    <th scope="row">
                        <label for="site_id"><?php esc_html_e('Select Site', 'wp-data-bridge'); ?></label>
                    </th>
                    <td>
                        <select name="site_id" id="site_id" class="regular-text" required>
                            <option value=""><?php esc_html_e('-- Select a Site --', 'wp-data-bridge'); ?></option>
                            <?php foreach ($available_sites as $site): ?>
                                <option value="<?php echo esc_attr($site['blog_id']); ?>">
                                    <?php echo esc_html($site['blogname']); ?> 
                                    (<?php echo esc_html($site['domain'] . $site['path']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            <?php esc_html_e('Choose which site to export data from.', 'wp-data-bridge'); ?>
                        </p>
                    </td>
                </tr>
                <?php else: ?>
                <input type="hidden" name="site_id" id="site_id" value="<?php echo esc_attr($available_sites[0]['blog_id']); ?>" />
                <?php endif; ?>

                <tr>
                    <th scope="row">
                        <?php esc_html_e('Export Types', 'wp-data-bridge'); ?>
                    </th>
                    <td>
                        <fieldset>
                            <legend class="screen-reader-text">
                                <span><?php esc_html_e('Export Types', 'wp-data-bridge'); ?></span>
                            </legend>
                            
                            <label for="export_posts">
                                <input name="export_types[]" type="checkbox" id="export_posts" value="posts" checked />
                                <?php esc_html_e('Posts & Pages', 'wp-data-bridge'); ?>
                            </label>
                            <br />
                            
                            <label for="export_users">
                                <input name="export_types[]" type="checkbox" id="export_users" value="users" />
                                <?php esc_html_e('Users', 'wp-data-bridge'); ?>
                            </label>
                            <br />
                            
                            <label for="export_images">
                                <input name="export_types[]" type="checkbox" id="export_images" value="images" />
                                <?php esc_html_e('Featured Images', 'wp-data-bridge'); ?>
                            </label>
                            
                            <p class="description">
                                <?php esc_html_e('Select which types of data to export.', 'wp-data-bridge'); ?>
                            </p>
                        </fieldset>
                    </td>
                </tr>

                <tr id="post-types-row">
                    <th scope="row">
                        <label for="post_types"><?php esc_html_e('Post Types', 'wp-data-bridge'); ?></label>
                    </th>
                    <td>
                        <div id="post-types-container">
                            <p class="description">
                                <?php esc_html_e('Loading available post types...', 'wp-data-bridge'); ?>
                            </p>
                        </div>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <?php esc_html_e('Date Range (Optional)', 'wp-data-bridge'); ?>
                    </th>
                    <td>
                        <fieldset>
                            <legend class="screen-reader-text">
                                <span><?php esc_html_e('Date Range', 'wp-data-bridge'); ?></span>
                            </legend>
                            
                            <label for="date_start">
                                <?php esc_html_e('From:', 'wp-data-bridge'); ?>
                                <input type="date" id="date_start" name="date_start" />
                            </label>
                            
                            <label for="date_end" style="margin-left: 20px;">
                                <?php esc_html_e('To:', 'wp-data-bridge'); ?>
                                <input type="date" id="date_end" name="date_end" />
                            </label>
                            
                            <p class="description">
                                <?php esc_html_e('Filter posts by publish date. Leave empty to export all posts.', 'wp-data-bridge'); ?>
                            </p>
                        </fieldset>
                    </td>
                </tr>
            </table>

            <div id="site-statistics" class="wp-data-bridge-stats" style="display: none;">
                <h3><?php esc_html_e('Site Statistics', 'wp-data-bridge'); ?></h3>
                <div class="stats-grid">
                    <div class="stat-item">
                        <span class="stat-number" id="posts-count">-</span>
                        <span class="stat-label"><?php esc_html_e('Posts', 'wp-data-bridge'); ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-number" id="pages-count">-</span>
                        <span class="stat-label"><?php esc_html_e('Pages', 'wp-data-bridge'); ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-number" id="users-count">-</span>
                        <span class="stat-label"><?php esc_html_e('Users', 'wp-data-bridge'); ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-number" id="attachments-count">-</span>
                        <span class="stat-label"><?php esc_html_e('Attachments', 'wp-data-bridge'); ?></span>
                    </div>
                </div>
            </div>

            <div class="wp-data-bridge-actions">
                <?php submit_button(__('Start Export', 'wp-data-bridge'), 'primary', 'submit', false, ['id' => 'start-export-btn']); ?>
                <span class="spinner" id="export-spinner"></span>
            </div>
        </form>

        <div id="export-progress" class="wp-data-bridge-progress" style="display: none;">
            <h3><?php esc_html_e('Export Progress', 'wp-data-bridge'); ?></h3>
            <div class="progress-bar">
                <div class="progress-fill" id="progress-fill"></div>
            </div>
            <p class="progress-text" id="progress-text">
                <?php esc_html_e('Preparing export...', 'wp-data-bridge'); ?>
            </p>
        </div>

        <div id="export-results" class="wp-data-bridge-results" style="display: none;">
            <h3><?php esc_html_e('Export Complete!', 'wp-data-bridge'); ?></h3>
            <div id="download-links"></div>
        </div>

        <div id="export-error" class="notice notice-error" style="display: none;">
            <p id="error-message"></p>
        </div>
    </div>

    <div class="wp-data-bridge-help">
        <h3><?php esc_html_e('Help & Information', 'wp-data-bridge'); ?></h3>
        
        <div class="help-section">
            <h4><?php esc_html_e('Export Formats', 'wp-data-bridge'); ?></h4>
            <ul>
                <li><strong><?php esc_html_e('Posts & Pages:', 'wp-data-bridge'); ?></strong> <?php esc_html_e('Includes title, content, meta data, categories, tags, and featured images.', 'wp-data-bridge'); ?></li>
                <li><strong><?php esc_html_e('Users:', 'wp-data-bridge'); ?></strong> <?php esc_html_e('Includes user details, roles, and custom user meta.', 'wp-data-bridge'); ?></li>
                <li><strong><?php esc_html_e('Featured Images:', 'wp-data-bridge'); ?></strong> <?php esc_html_e('Includes image URLs, file paths, alt text, and associated posts.', 'wp-data-bridge'); ?></li>
            </ul>
        </div>

        <div class="help-section">
            <h4><?php esc_html_e('Performance Notes', 'wp-data-bridge'); ?></h4>
            <ul>
                <li><?php esc_html_e('Large exports are processed in batches to prevent timeouts.', 'wp-data-bridge'); ?></li>
                <li><?php esc_html_e('Export files are automatically deleted after 24 hours.', 'wp-data-bridge'); ?></li>
                <li><?php esc_html_e('CSV files include UTF-8 BOM for Excel compatibility.', 'wp-data-bridge'); ?></li>
            </ul>
        </div>

        <?php if ($is_multisite): ?>
        <div class="help-section">
            <h4><?php esc_html_e('Multisite Features', 'wp-data-bridge'); ?></h4>
            <ul>
                <li><?php esc_html_e('Export data from any site in your network.', 'wp-data-bridge'); ?></li>
                <li><?php esc_html_e('Site-specific post types and user roles are respected.', 'wp-data-bridge'); ?></li>
                <li><?php esc_html_e('Network administrators can access all sites.', 'wp-data-bridge'); ?></li>
            </ul>
        </div>
        <?php endif; ?>
    </div>
</div>