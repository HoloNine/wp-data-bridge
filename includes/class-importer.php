<?php

if (!defined('ABSPATH')) {
    exit;
}

class WP_Data_Bridge_Importer {
    
    private $batch_size = 100;
    private $supported_types = ['posts', 'users', 'images'];
    private $import_results = [];
    
    public function __construct() {
        
    }
    
    public function import_csv_file(string $file_path, array $import_options): array {
        $this->import_results = [
            'success' => 0,
            'skipped' => 0,
            'errors' => 0,
            'messages' => [],
            'error_details' => []
        ];
        
        try {
            // Parse CSV file
            $csv_data = $this->parse_csv_file($file_path);
            
            if (empty($csv_data)) {
                throw new Exception(__('CSV file is empty or could not be parsed.', 'wp-data-bridge'));
            }
            
            // Detect import type from headers
            $import_type = $this->detect_import_type($csv_data[0]);
            
            // Validate CSV structure
            $validation_result = $this->validate_csv_structure($csv_data, $import_type);
            if (!$validation_result['valid']) {
                throw new Exception($validation_result['message']);
            }
            
            // Apply filters before import
            $csv_data = apply_filters('wp_data_bridge_import_data', $csv_data, $import_type);
            $csv_data = apply_filters('wp_data_bridge_before_import', $csv_data, $import_options);
            
            // Trigger import started action
            do_action('wp_data_bridge_import_started', $import_options);
            
            // Switch to target site if multisite
            $target_site_id = $import_options['target_site_id'] ?? get_current_blog_id();
            $switched = false;
            
            if (is_multisite() && $target_site_id != get_current_blog_id()) {
                switch_to_blog($target_site_id);
                $switched = true;
            }
            
            try {
                // Process import based on type
                switch ($import_type) {
                    case 'posts':
                        $this->import_posts_data($csv_data, $import_options);
                        break;
                    case 'users':
                        $this->import_users_data($csv_data, $import_options);
                        break;
                    case 'images':
                        $this->import_images_data($csv_data, $import_options);
                        break;
                    default:
                        throw new Exception(__('Unsupported import type.', 'wp-data-bridge'));
                }
            } finally {
                if ($switched) {
                    restore_current_blog();
                }
            }
            
            // Apply filters after import
            $this->import_results = apply_filters('wp_data_bridge_after_import', $this->import_results, $import_options);
            
            // Trigger import completed action
            do_action('wp_data_bridge_import_completed', $this->import_results);
            
            // Create import log
            $this->create_import_log($this->import_results, $import_options);
            
        } catch (Exception $e) {
            $error_message = $e->getMessage();
            $this->import_results['error_details'][] = $error_message;
            
            // Trigger import failed action
            do_action('wp_data_bridge_import_failed', $error_message, $csv_data ?? []);
            
            throw $e;
        }
        
        return $this->import_results;
    }
    
    public function parse_csv_file(string $file_path): array {
        if (!file_exists($file_path)) {
            throw new Exception(__('CSV file does not exist.', 'wp-data-bridge'));
        }
        
        $csv_data = [];
        $handle = fopen($file_path, 'r');
        
        if (!$handle) {
            throw new Exception(__('Could not open CSV file for reading.', 'wp-data-bridge'));
        }
        
        // Skip UTF-8 BOM if present
        $first_char = fread($handle, 3);
        if ($first_char !== "\xEF\xBB\xBF") {
            rewind($handle);
        }
        
        $row_count = 0;
        while (($data = fgetcsv($handle, 0, ',')) !== false) {
            $csv_data[] = $data;
            $row_count++;
            
            // Prevent memory issues with very large files
            if ($row_count > 50000) {
                fclose($handle);
                throw new Exception(__('CSV file too large. Please split into smaller files.', 'wp-data-bridge'));
            }
        }
        
        fclose($handle);
        
        return $csv_data;
    }
    
    public function validate_csv_structure(array $csv_data, string $expected_type): array {
        if (empty($csv_data)) {
            return [
                'valid' => false,
                'message' => __('CSV file is empty.', 'wp-data-bridge')
            ];
        }
        
        $headers = $csv_data[0];
        $required_fields = $this->get_required_fields_for_type($expected_type);
        
        $missing_fields = [];
        foreach ($required_fields as $field) {
            if (!in_array($field, $headers)) {
                $missing_fields[] = $field;
            }
        }
        
        if (!empty($missing_fields)) {
            return [
                'valid' => false,
                'message' => sprintf(
                    __('Missing required fields: %s', 'wp-data-bridge'),
                    implode(', ', $missing_fields)
                )
            ];
        }
        
        return ['valid' => true, 'message' => ''];
    }
    
    public function import_posts_data(array $csv_data, array $import_options): void {
        $headers = array_shift($csv_data); // Remove headers
        $batch_size = $import_options['batch_size'] ?? $this->batch_size;
        
        // Get column indices
        $column_map = array_flip($headers);
        
        // Process in batches
        $batches = array_chunk($csv_data, $batch_size);
        
        foreach ($batches as $batch) {
            foreach ($batch as $row) {
                try {
                    $post_data = $this->map_csv_row_to_post_data($row, $column_map);
                    
                    // Check if custom post type exists
                    if (!$this->check_custom_post_type_exists($post_data['post_type'])) {
                        $action = apply_filters('wp_data_bridge_missing_post_type_action', 
                            $import_options['missing_post_type_action'] ?? 'skip', 
                            $post_data['post_type'], 
                            $post_data
                        );
                        
                        switch ($action) {
                            case 'skip':
                                $this->import_results['skipped']++;
                                $this->import_results['messages'][] = sprintf(
                                    __('Skipped post "%s" - post type "%s" does not exist.', 'wp-data-bridge'),
                                    $post_data['post_title'],
                                    $post_data['post_type']
                                );
                                continue 2;
                                
                            case 'convert_to_post':
                                $post_data['post_type'] = 'post';
                                break;
                                
                            case 'notify':
                            default:
                                throw new Exception(sprintf(
                                    __('Post type "%s" does not exist. Please install the required plugin or theme.', 'wp-data-bridge'),
                                    $post_data['post_type']
                                ));
                        }
                    }
                    
                    // Check for duplicates
                    $existing_post = $this->find_duplicate_post($post_data, $import_options);
                    
                    if ($existing_post) {
                        $duplicate_action = apply_filters('wp_data_bridge_duplicate_post_action',
                            $import_options['duplicate_handling'] ?? 'skip',
                            $existing_post,
                            $post_data
                        );
                        
                        switch ($duplicate_action) {
                            case 'skip':
                                $this->import_results['skipped']++;
                                $this->import_results['messages'][] = sprintf(
                                    __('Skipped duplicate post: "%s"', 'wp-data-bridge'),
                                    $post_data['post_title']
                                );
                                continue 2;
                                
                            case 'update':
                                $post_data['ID'] = $existing_post->ID;
                                break;
                                
                            case 'create_new':
                            default:
                                // Remove ID to create new post
                                unset($post_data['ID']);
                                break;
                        }
                    }
                    
                    // Create or update post
                    $post_id = wp_insert_post($post_data);
                    
                    if (is_wp_error($post_id)) {
                        $this->import_results['errors']++;
                        $this->import_results['error_details'][] = sprintf(
                            __('Failed to import post "%s": %s', 'wp-data-bridge'),
                            $post_data['post_title'],
                            $post_id->get_error_message()
                        );
                    } else {
                        $this->import_results['success']++;
                        
                        // Import post meta, categories, tags
                        $this->import_post_metadata($post_id, $row, $column_map, $import_options);
                    }
                    
                } catch (Exception $e) {
                    $this->import_results['errors']++;
                    $this->import_results['error_details'][] = $e->getMessage();
                }
            }
            
            // Clear memory and avoid timeouts
            if (function_exists('wp_suspend_cache_addition')) {
                wp_suspend_cache_addition(false);
                wp_suspend_cache_addition(true);
            }
            
            // Allow other processes to run
            if (!wp_doing_ajax()) {
                usleep(1000); // 1ms pause
            }
        }
    }
    
    public function import_users_data(array $csv_data, array $import_options): void {
        $headers = array_shift($csv_data);
        $column_map = array_flip($headers);
        
        foreach ($csv_data as $row) {
            try {
                $user_data = $this->map_csv_row_to_user_data($row, $column_map);
                
                // Check if user exists
                $existing_user = get_user_by('email', $user_data['user_email']);
                
                if ($existing_user) {
                    if ($import_options['update_existing_users'] ?? false) {
                        $user_data['ID'] = $existing_user->ID;
                        $user_id = wp_update_user($user_data);
                    } else {
                        $this->import_results['skipped']++;
                        continue;
                    }
                } else {
                    if ($import_options['create_missing_users'] ?? false) {
                        $user_id = wp_insert_user($user_data);
                    } else {
                        $this->import_results['skipped']++;
                        continue;
                    }
                }
                
                if (is_wp_error($user_id)) {
                    $this->import_results['errors']++;
                    $this->import_results['error_details'][] = $user_id->get_error_message();
                } else {
                    $this->import_results['success']++;
                }
                
            } catch (Exception $e) {
                $this->import_results['errors']++;
                $this->import_results['error_details'][] = $e->getMessage();
            }
        }
    }
    
    public function import_images_data(array $csv_data, array $import_options): void {
        // Implementation for image import
        $this->import_results['messages'][] = __('Image import not yet implemented.', 'wp-data-bridge');
    }
    
    public function check_custom_post_type_exists(string $post_type): bool {
        return post_type_exists($post_type);
    }
    
    public function handle_duplicate_content($existing_post, array $import_data): string {
        // This method can be overridden via filters
        return 'skip'; // Default action
    }
    
    public function create_import_log(array $results, array $options): void {
        $log_entry = [
            'timestamp' => current_time('mysql'),
            'results' => $results,
            'options' => $options
        ];
        
        // Store in WordPress option or custom table
        $existing_logs = get_option('wp_data_bridge_import_logs', []);
        $existing_logs[] = $log_entry;
        
        // Keep only last 10 import logs
        if (count($existing_logs) > 10) {
            $existing_logs = array_slice($existing_logs, -10);
        }
        
        update_option('wp_data_bridge_import_logs', $existing_logs);
    }
    
    private function detect_import_type(array $headers): string {
        // Detect based on headers
        if (in_array('post_title', $headers) || in_array('Post Title', $headers)) {
            return 'posts';
        } elseif (in_array('username', $headers) || in_array('Username', $headers)) {
            return 'users';
        } elseif (in_array('image_url', $headers) || in_array('Image URL', $headers)) {
            return 'images';
        }
        
        return 'posts'; // Default fallback
    }
    
    private function get_required_fields_for_type(string $type): array {
        switch ($type) {
            case 'posts':
                return ['Site ID', 'Post Title', 'Post Type'];
            case 'users':
                return ['Site ID', 'Username', 'Email'];
            case 'images':
                return ['Site ID', 'Image URL'];
            default:
                return [];
        }
    }
    
    private function map_csv_row_to_post_data(array $row, array $column_map): array {
        $post_data = [
            'post_title' => $row[$column_map['Post Title']] ?? '',
            'post_content' => $row[$column_map['Post Content']] ?? '',
            'post_excerpt' => $row[$column_map['Post Excerpt']] ?? '',
            'post_status' => $row[$column_map['Post Status']] ?? 'draft',
            'post_type' => $row[$column_map['Post Type']] ?? 'post',
            'post_author' => $row[$column_map['Author ID']] ?? get_current_user_id(),
            'post_date' => $row[$column_map['Post Date']] ?? '',
            'post_parent' => $row[$column_map['Parent ID']] ?? 0,
        ];
        
        // Clean up post data
        $post_data = array_filter($post_data, function($value) {
            return $value !== '';
        });
        
        return $post_data;
    }
    
    private function map_csv_row_to_user_data(array $row, array $column_map): array {
        return [
            'user_login' => $row[$column_map['Username']] ?? '',
            'user_email' => $row[$column_map['Email']] ?? '',
            'display_name' => $row[$column_map['Display Name']] ?? '',
            'role' => $row[$column_map['User Role']] ?? 'subscriber',
        ];
    }
    
    private function find_duplicate_post(array $post_data, array $import_options): ?WP_Post {
        // Look for duplicates by title and post type
        $existing_posts = get_posts([
            'title' => $post_data['post_title'],
            'post_type' => $post_data['post_type'],
            'post_status' => 'any',
            'numberposts' => 1
        ]);
        
        return !empty($existing_posts) ? $existing_posts[0] : null;
    }
    
    private function import_post_metadata(int $post_id, array $row, array $column_map, array $options): void {
        // Import categories
        if (isset($column_map['Categories']) && !empty($row[$column_map['Categories']])) {
            $categories = explode(', ', $row[$column_map['Categories']]);
            wp_set_post_categories($post_id, $categories, false);
        }
        
        // Import tags
        if (isset($column_map['Tags']) && !empty($row[$column_map['Tags']])) {
            $tags = explode(', ', $row[$column_map['Tags']]);
            wp_set_post_tags($post_id, $tags, false);
        }
        
        // Import custom fields
        if (isset($column_map['Custom Fields']) && !empty($row[$column_map['Custom Fields']])) {
            $custom_fields = json_decode($row[$column_map['Custom Fields']], true);
            if (is_array($custom_fields)) {
                foreach ($custom_fields as $key => $value) {
                    update_post_meta($post_id, $key, $value);
                }
            }
        }
        
        // Import featured image if specified and exists
        if ($options['import_images'] ?? false) {
            if (isset($column_map['Featured Image URL']) && !empty($row[$column_map['Featured Image URL']])) {
                $this->import_featured_image($post_id, $row[$column_map['Featured Image URL']]);
            }
        }
    }
    
    private function import_featured_image(int $post_id, string $image_url): void {
        // Download and attach image
        $image_id = media_sideload_image($image_url, $post_id, null, 'id');
        
        if (!is_wp_error($image_id)) {
            set_post_thumbnail($post_id, $image_id);
        }
    }
}