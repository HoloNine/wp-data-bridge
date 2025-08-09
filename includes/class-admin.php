<?php

if (!defined('ABSPATH')) {
    exit;
}

class WP_Data_Bridge_Admin {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        
    }
    
    public static function display_admin_page(): void {
        if (!current_user_can(is_multisite() ? 'manage_network_options' : 'manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'wp-data-bridge'));
        }
        
        include WP_DATA_BRIDGE_PLUGIN_DIR . 'templates/admin-page.php';
    }
    
    public static function handle_ajax_export(): void {
        check_ajax_referer('wp_data_bridge_nonce', 'nonce');
        
        if (!current_user_can(is_multisite() ? 'manage_network_options' : 'manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'wp-data-bridge'));
        }
        
        $site_id = (int) sanitize_text_field($_POST['site_id'] ?? 1);
        $export_types = array_map('sanitize_text_field', $_POST['export_types'] ?? []);
        $post_types = array_map('sanitize_text_field', $_POST['post_types'] ?? ['post', 'page']);
        $date_start = sanitize_text_field($_POST['date_start'] ?? '');
        $date_end = sanitize_text_field($_POST['date_end'] ?? '');
        
        if (empty($export_types)) {
            wp_send_json_error(__('No export types selected.', 'wp-data-bridge'));
        }
        
        try {
            $exporter = new WP_Data_Bridge_Exporter();
            $csv_generator = new WP_Data_Bridge_CSV_Generator();
            $file_handler = new WP_Data_Bridge_File_Handler();
            
            $options = [
                'post_types' => $post_types,
                'include_users' => in_array('users', $export_types),
                'include_images' => in_array('images', $export_types),
                'date_range' => (!empty($date_start) && !empty($date_end)) ? [
                    'start' => $date_start,
                    'end' => $date_end
                ] : null
            ];
            
            $data = $exporter->export_site_data($site_id, $options);
            
            if (isset($data['error'])) {
                wp_send_json_error($data['error']);
            }
            
            if (empty($data)) {
                wp_send_json_error(__('No data found for export.', 'wp-data-bridge'));
            }
            
            $files_generated = [];
            $timestamp = date('Y-m-d_H-i-s');
            
            foreach ($data as $export_type => $export_data) {
                if (empty($export_data)) {
                    continue;
                }
                
                $filename = "wp-data-bridge_{$export_type}_site-{$site_id}_{$timestamp}.csv";
                $file_path = $csv_generator->generate_csv($export_data, $export_type, $filename);
                
                if ($file_path) {
                    $download_url = $file_handler->create_secure_download_url($file_path, $filename);
                    $files_generated[] = [
                        'type' => $export_type,
                        'filename' => $filename,
                        'url' => $download_url,
                        'size' => self::format_file_size(filesize($file_path)),
                        'records' => count($export_data)
                    ];
                }
            }
            
            if (empty($files_generated)) {
                wp_send_json_error(__('Failed to generate CSV files.', 'wp-data-bridge'));
            }
            
            wp_send_json_success([
                'message' => __('Export completed successfully!', 'wp-data-bridge'),
                'files' => $files_generated,
                'site_id' => $site_id,
                'timestamp' => $timestamp
            ]);
            
        } catch (Exception $e) {
            error_log('WP Data Bridge Export Error: ' . $e->getMessage());
            wp_send_json_error(__('Export failed: ', 'wp-data-bridge') . $e->getMessage());
        }
    }
    
    public static function get_available_sites(): array {
        if (!is_multisite()) {
            return [
                [
                    'blog_id' => 1,
                    'domain' => get_bloginfo('url'),
                    'blogname' => get_bloginfo('name'),
                    'path' => '/'
                ]
            ];
        }
        
        $sites = get_sites([
            'number' => 500,
            'public' => 1,
            'archived' => 0,
            'deleted' => 0
        ]);
        
        $available_sites = [];
        foreach ($sites as $site) {
            switch_to_blog($site->blog_id);
            
            $available_sites[] = [
                'blog_id' => $site->blog_id,
                'domain' => $site->domain,
                'blogname' => get_bloginfo('name'),
                'path' => $site->path,
                'url' => get_bloginfo('url')
            ];
            
            restore_current_blog();
        }
        
        return $available_sites;
    }
    
    public static function get_available_post_types(int $site_id = null): array {
        $post_types = [];
        
        if ($site_id && is_multisite()) {
            switch_to_blog($site_id);
        }
        
        $all_post_types = get_post_types(['public' => true], 'objects');
        
        foreach ($all_post_types as $post_type) {
            $post_count = wp_count_posts($post_type->name);
            $published_count = isset($post_count->publish) ? (int) $post_count->publish : 0;
            
            $post_types[] = [
                'name' => $post_type->name,
                'label' => $post_type->label,
                'count' => $published_count,
                'description' => $post_type->description ?: $post_type->label
            ];
        }
        
        if ($site_id && is_multisite()) {
            restore_current_blog();
        }
        
        usort($post_types, fn($a, $b) => strcmp($a['label'], $b['label']));
        
        return $post_types;
    }
    
    public static function get_site_statistics(int $site_id): array {
        $stats = [
            'posts' => 0,
            'pages' => 0,
            'users' => 0,
            'attachments' => 0,
            'custom_post_types' => []
        ];
        
        if (is_multisite()) {
            switch_to_blog($site_id);
        }
        
        $post_counts = wp_count_posts();
        $stats['posts'] = isset($post_counts->publish) ? (int) $post_counts->publish : 0;
        
        $page_counts = wp_count_posts('page');
        $stats['pages'] = isset($page_counts->publish) ? (int) $page_counts->publish : 0;
        
        $attachment_counts = wp_count_posts('attachment');
        $stats['attachments'] = isset($attachment_counts->inherit) ? (int) $attachment_counts->inherit : 0;
        
        $user_count = count_users();
        $stats['users'] = isset($user_count['total_users']) ? (int) $user_count['total_users'] : 0;
        
        $custom_post_types = get_post_types(['public' => true, '_builtin' => false], 'objects');
        foreach ($custom_post_types as $post_type) {
            $counts = wp_count_posts($post_type->name);
            $stats['custom_post_types'][] = [
                'name' => $post_type->name,
                'label' => $post_type->label,
                'count' => isset($counts->publish) ? (int) $counts->publish : 0
            ];
        }
        
        if (is_multisite()) {
            restore_current_blog();
        }
        
        return $stats;
    }
    
    public static function handle_ajax_get_site_stats(): void {
        check_ajax_referer('wp_data_bridge_nonce', 'nonce');
        
        if (!current_user_can(is_multisite() ? 'manage_network_options' : 'manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'wp-data-bridge'));
        }
        
        $site_id = (int) sanitize_text_field($_POST['site_id'] ?? 1);
        
        try {
            $stats = self::get_site_statistics($site_id);
            $post_types = self::get_available_post_types($site_id);
            
            wp_send_json_success([
                'stats' => $stats,
                'post_types' => $post_types
            ]);
        } catch (Exception $e) {
            wp_send_json_error(__('Failed to load site statistics.', 'wp-data-bridge'));
        }
    }
    
    public static function handle_ajax_validate_date_range(): void {
        check_ajax_referer('wp_data_bridge_nonce', 'nonce');
        
        if (!current_user_can(is_multisite() ? 'manage_network_options' : 'manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'wp-data-bridge'));
        }
        
        $site_id = (int) sanitize_text_field($_POST['site_id'] ?? 1);
        $post_types = array_map('sanitize_text_field', $_POST['post_types'] ?? ['post']);
        $date_start = sanitize_text_field($_POST['date_start'] ?? '');
        $date_end = sanitize_text_field($_POST['date_end'] ?? '');
        
        if (empty($date_start) || empty($date_end)) {
            wp_send_json_error(__('Invalid date range.', 'wp-data-bridge'));
        }
        
        try {
            if (is_multisite()) {
                switch_to_blog($site_id);
            }
            
            $args = [
                'post_type' => $post_types,
                'post_status' => ['publish', 'draft', 'private'],
                'date_query' => [
                    [
                        'after' => $date_start,
                        'before' => $date_end,
                        'inclusive' => true
                    ]
                ],
                'fields' => 'ids',
                'posts_per_page' => 1
            ];
            
            $posts = get_posts($args);
            $found_posts = count($posts) > 0;
            
            if (is_multisite()) {
                restore_current_blog();
            }
            
            wp_send_json_success([
                'valid' => $found_posts,
                'message' => $found_posts ? 
                    __('Date range contains posts.', 'wp-data-bridge') : 
                    __('No posts found in selected date range.', 'wp-data-bridge')
            ]);
            
        } catch (Exception $e) {
            if (is_multisite()) {
                restore_current_blog();
            }
            wp_send_json_error(__('Failed to validate date range.', 'wp-data-bridge'));
        }
    }
    
    private static function format_file_size(int $bytes): string {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        
        return $bytes . ' bytes';
    }
    
    public static function register_ajax_hooks(): void {
        add_action('wp_ajax_wp_data_bridge_export', [self::class, 'handle_ajax_export']);
        add_action('wp_ajax_wp_data_bridge_get_site_stats', [self::class, 'handle_ajax_get_site_stats']);
        add_action('wp_ajax_wp_data_bridge_validate_date_range', [self::class, 'handle_ajax_validate_date_range']);
        
        // Import AJAX handlers
        add_action('wp_ajax_wp_data_bridge_import', [self::class, 'handle_ajax_import']);
        add_action('wp_ajax_wp_data_bridge_validate_import', [self::class, 'handle_ajax_validate_import']);
        add_action('wp_ajax_wp_data_bridge_import_preview', [self::class, 'handle_ajax_import_preview']);
    }
    
    public static function handle_ajax_import(): void {
        check_ajax_referer('wp_data_bridge_import', 'nonce');
        
        if (!current_user_can(is_multisite() ? 'manage_network_options' : 'manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'wp-data-bridge'));
        }
        
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(__('No valid CSV file uploaded.', 'wp-data-bridge'));
        }
        
        $target_site_id = (int) sanitize_text_field($_POST['target_site_id'] ?? 1);
        $duplicate_handling = sanitize_text_field($_POST['duplicate_handling'] ?? 'skip');
        $missing_post_type_action = sanitize_text_field($_POST['missing_post_type_action'] ?? 'skip');
        $import_images = !empty($_POST['import_images']);
        $create_missing_users = !empty($_POST['create_missing_users']);
        $update_existing_users = !empty($_POST['update_existing_users']);
        
        try {
            $importer = new WP_Data_Bridge_Importer();
            
            $import_options = [
                'target_site_id' => $target_site_id,
                'duplicate_handling' => $duplicate_handling,
                'missing_post_type_action' => $missing_post_type_action,
                'import_images' => $import_images,
                'create_missing_users' => $create_missing_users,
                'update_existing_users' => $update_existing_users,
                'batch_size' => 50, // Smaller batch size for imports
            ];
            
            $uploaded_file = $_FILES['csv_file']['tmp_name'];
            $results = $importer->import_csv_file($uploaded_file, $import_options);
            
            wp_send_json_success([
                'message' => __('Import completed successfully!', 'wp-data-bridge'),
                'results' => $results,
                'target_site_id' => $target_site_id
            ]);
            
        } catch (Exception $e) {
            error_log('WP Data Bridge Import Error: ' . $e->getMessage());
            wp_send_json_error(__('Import failed: ', 'wp-data-bridge') . $e->getMessage());
        }
    }
    
    public static function handle_ajax_validate_import(): void {
        check_ajax_referer('wp_data_bridge_import', 'nonce');
        
        if (!current_user_can(is_multisite() ? 'manage_network_options' : 'manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'wp-data-bridge'));
        }
        
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(__('No valid CSV file uploaded.', 'wp-data-bridge'));
        }
        
        try {
            $importer = new WP_Data_Bridge_Importer();
            $uploaded_file = $_FILES['csv_file']['tmp_name'];
            
            // Parse and validate CSV
            $csv_data = $importer->parse_csv_file($uploaded_file);
            
            if (empty($csv_data)) {
                wp_send_json_error(__('CSV file is empty or invalid.', 'wp-data-bridge'));
            }
            
            // Detect import type
            $headers = $csv_data[0];
            $import_type = 'posts'; // Default
            if (in_array('Username', $headers)) {
                $import_type = 'users';
            } elseif (in_array('Image URL', $headers)) {
                $import_type = 'images';
            }
            
            // Validate structure
            $validation = $importer->validate_csv_structure($csv_data, $import_type);
            
            if (!$validation['valid']) {
                wp_send_json_error($validation['message']);
            }
            
            // Check custom post types if importing posts
            $missing_post_types = [];
            if ($import_type === 'posts') {
                $post_type_column = array_search('Post Type', $headers);
                if ($post_type_column !== false) {
                    $post_types = array_unique(array_column(array_slice($csv_data, 1), $post_type_column));
                    foreach ($post_types as $post_type) {
                        if (!empty($post_type) && !post_type_exists($post_type)) {
                            $missing_post_types[] = $post_type;
                        }
                    }
                }
            }
            
            wp_send_json_success([
                'valid' => true,
                'import_type' => $import_type,
                'total_records' => count($csv_data) - 1, // Exclude header
                'headers' => $headers,
                'missing_post_types' => $missing_post_types,
                'has_warnings' => !empty($missing_post_types)
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Validation failed: ', 'wp-data-bridge') . $e->getMessage());
        }
    }
    
    public static function handle_ajax_import_preview(): void {
        // Debug logging
        error_log('WP Data Bridge: Import preview handler called');
        error_log('WP Data Bridge: FILES = ' . print_r($_FILES, true));
        error_log('WP Data Bridge: POST = ' . print_r($_POST, true));
        
        try {
            check_ajax_referer('wp_data_bridge_import', 'nonce');
        } catch (Exception $e) {
            error_log('WP Data Bridge: Nonce check failed: ' . $e->getMessage());
            wp_send_json_error(__('Nonce verification failed.', 'wp-data-bridge'));
        }
        
        if (!current_user_can(is_multisite() ? 'manage_network_options' : 'manage_options')) {
            error_log('WP Data Bridge: Insufficient permissions');
            wp_send_json_error(__('Insufficient permissions.', 'wp-data-bridge'));
        }
        
        if (!isset($_FILES['csv_file'])) {
            error_log('WP Data Bridge: No csv_file in $_FILES');
            wp_send_json_error(__('No CSV file uploaded.', 'wp-data-bridge'));
        }
        
        if ($_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            error_log('WP Data Bridge: File upload error: ' . $_FILES['csv_file']['error']);
            wp_send_json_error(__('File upload error.', 'wp-data-bridge'));
        }
        
        try {
            $importer = new WP_Data_Bridge_Importer();
            $uploaded_file = $_FILES['csv_file']['tmp_name'];
            
            // Parse CSV and get first few rows for preview
            $csv_data = $importer->parse_csv_file($uploaded_file);
            $headers = array_shift($csv_data);
            
            // Get first 5 rows for preview
            $preview_rows = array_slice($csv_data, 0, 5);
            
            wp_send_json_success([
                'headers' => $headers,
                'preview_rows' => $preview_rows,
                'total_rows' => count($csv_data)
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Preview failed: ', 'wp-data-bridge') . $e->getMessage());
        }
    }
}