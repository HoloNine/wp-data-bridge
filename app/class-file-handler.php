<?php

if (!defined('ABSPATH')) {
    exit;
}

class WP_Data_Bridge_File_Handler {
    
    private $secure_key;
    private $download_expiry = 3600; // 1 hour
    
    public function __construct() {
        $this->secure_key = $this->get_or_create_secure_key();
    }
    
    public function create_secure_download_url(string $file_path, string $filename): string {
        if (!file_exists($file_path)) {
            throw new Exception(__('File does not exist.', 'wp-data-bridge'));
        }
        
        $expires = time() + $this->download_expiry;
        $token = $this->generate_download_token($file_path, $filename, $expires);
        
        $url_params = [
            'action' => 'wp_data_bridge_download',
            'token' => $token,
            'expires' => $expires,
            'filename' => urlencode($filename)
        ];
        
        return add_query_arg($url_params, admin_url('admin-ajax.php'));
    }
    
    public function handle_secure_download(): void {
        if (!isset($_GET['token']) || !isset($_GET['expires']) || !isset($_GET['filename'])) {
            $this->download_error(__('Invalid download parameters.', 'wp-data-bridge'));
        }
        
        $token = sanitize_text_field($_GET['token']);
        $expires = (int) $_GET['expires'];
        $filename = sanitize_file_name(urldecode($_GET['filename']));
        
        if (time() > $expires) {
            $this->download_error(__('Download link has expired.', 'wp-data-bridge'));
        }
        
        if (!current_user_can(is_multisite() ? 'manage_network_options' : 'manage_options')) {
            $this->download_error(__('Insufficient permissions.', 'wp-data-bridge'));
        }
        
        $file_path = WP_DATA_BRIDGE_UPLOAD_DIR . $filename;
        
        if (!$this->verify_download_token($token, $file_path, $filename, $expires)) {
            $this->download_error(__('Invalid download token.', 'wp-data-bridge'));
        }
        
        if (!file_exists($file_path)) {
            $this->download_error(__('File not found.', 'wp-data-bridge'));
        }
        
        $this->stream_file_download($file_path, $filename);
    }
    
    public function stream_file_download(string $file_path, string $filename): void {
        $file_size = filesize($file_path);
        $file_extension = pathinfo($filename, PATHINFO_EXTENSION);
        
        nocache_headers();
        
        header('Content-Type: ' . $this->get_mime_type($file_extension));
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . $file_size);
        header('Content-Transfer-Encoding: binary');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        header('Expires: 0');
        
        if ($file_size > 0) {
            $this->readfile_chunked($file_path);
        }
        
        // Don't immediately delete file - let scheduled cleanup handle it
        // This allows time for debugging and multiple downloads
        // $this->cleanup_downloaded_file($file_path);
        
        exit;
    }
    
    private function readfile_chunked(string $file_path, int $chunk_size = 8192): bool {
        $handle = fopen($file_path, 'rb');
        if ($handle === false) {
            return false;
        }
        
        while (!feof($handle)) {
            $chunk = fread($handle, $chunk_size);
            if ($chunk === false) {
                break;
            }
            
            echo $chunk;
            
            if (ob_get_level()) {
                ob_flush();
            }
            flush();
            
            if (connection_aborted()) {
                break;
            }
        }
        
        fclose($handle);
        return true;
    }
    
    private function generate_download_token(string $file_path, string $filename, ?int $timestamp = null): string {
        if ($timestamp === null) {
            $timestamp = time();
        }
        $data = $file_path . '|' . $filename . '|' . $timestamp;
        return hash_hmac('sha256', $data, $this->secure_key);
    }
    
    private function verify_download_token(string $token, string $file_path, string $filename, int $timestamp): bool {
        $expected_token = $this->generate_download_token($file_path, $filename, $timestamp);
        return hash_equals($expected_token, $token);
    }
    
    private function get_or_create_secure_key(): string {
        $option_name = 'wp_data_bridge_secure_key';
        $key = get_option($option_name);
        
        if (!$key) {
            $key = wp_generate_password(64, false, false);
            update_option($option_name, $key);
        }
        
        return $key;
    }
    
    private function get_mime_type(string $extension): string {
        $mime_types = [
            'csv' => 'text/csv',
            'txt' => 'text/plain',
            'json' => 'application/json',
            'xml' => 'text/xml',
            'zip' => 'application/zip'
        ];
        
        return $mime_types[strtolower($extension)] ?? 'application/octet-stream';
    }
    
    private function download_error(string $message): void {
        wp_die(
            $message,
            __('Download Error', 'wp-data-bridge'),
            [
                'response' => 400,
                'back_link' => true
            ]
        );
    }
    
    public function cleanup_downloaded_file(string $file_path): void {
        if (file_exists($file_path)) {
            unlink($file_path);
        }
    }
    
    public function cleanup_old_files(int $max_age_hours = 24): int {
        $upload_dir = WP_DATA_BRIDGE_UPLOAD_DIR;
        $cleanup_count = 0;
        
        if (!is_dir($upload_dir)) {
            return 0;
        }
        
        $files = glob($upload_dir . '*.csv');
        $current_time = time();
        $max_age_seconds = $max_age_hours * 3600;
        
        foreach ($files as $file) {
            if (is_file($file)) {
                $file_age = $current_time - filemtime($file);
                
                if ($file_age > $max_age_seconds) {
                    if (unlink($file)) {
                        $cleanup_count++;
                    }
                }
            }
        }
        
        return $cleanup_count;
    }
    
    public function get_directory_size(): int {
        $upload_dir = WP_DATA_BRIDGE_UPLOAD_DIR;
        $total_size = 0;
        
        if (!is_dir($upload_dir)) {
            return 0;
        }
        
        $files = glob($upload_dir . '*');
        
        foreach ($files as $file) {
            if (is_file($file)) {
                $total_size += filesize($file);
            }
        }
        
        return $total_size;
    }
    
    public function get_file_list(): array {
        $upload_dir = WP_DATA_BRIDGE_UPLOAD_DIR;
        $files_info = [];
        
        if (!is_dir($upload_dir)) {
            return [];
        }
        
        $files = glob($upload_dir . '*.csv');
        
        foreach ($files as $file) {
            if (is_file($file)) {
                $files_info[] = [
                    'name' => basename($file),
                    'size' => filesize($file),
                    'created' => filemtime($file),
                    'age_hours' => round((time() - filemtime($file)) / 3600, 1)
                ];
            }
        }
        
        usort($files_info, fn($a, $b) => $b['created'] <=> $a['created']);
        
        return $files_info;
    }
    
    public function create_zip_archive(array $file_paths, string $zip_filename): ?string {
        if (empty($file_paths) || !class_exists('ZipArchive')) {
            return null;
        }
        
        $zip_path = WP_DATA_BRIDGE_UPLOAD_DIR . $zip_filename;
        $zip = new ZipArchive();
        
        if ($zip->open($zip_path, ZipArchive::CREATE) !== TRUE) {
            return null;
        }
        
        foreach ($file_paths as $file_path) {
            if (file_exists($file_path)) {
                $filename = basename($file_path);
                $zip->addFile($file_path, $filename);
            }
        }
        
        $zip->close();
        
        foreach ($file_paths as $file_path) {
            if (file_exists($file_path)) {
                unlink($file_path);
            }
        }
        
        return file_exists($zip_path) ? $zip_path : null;
    }
    
    public function validate_file_security(string $file_path): array {
        $issues = [];
        
        if (!file_exists($file_path)) {
            $issues[] = __('File does not exist.', 'wp-data-bridge');
            return $issues;
        }
        
        $real_path = realpath($file_path);
        $allowed_dir = realpath(WP_DATA_BRIDGE_UPLOAD_DIR);
        
        if (strpos($real_path, $allowed_dir) !== 0) {
            $issues[] = __('File is outside allowed directory.', 'wp-data-bridge');
        }
        
        $file_extension = pathinfo($file_path, PATHINFO_EXTENSION);
        $allowed_extensions = ['csv', 'txt', 'json', 'zip'];
        
        if (!in_array(strtolower($file_extension), $allowed_extensions)) {
            $issues[] = __('File type not allowed.', 'wp-data-bridge');
        }
        
        $file_size = filesize($file_path);
        $max_file_size = 100 * 1024 * 1024; // 100MB
        
        if ($file_size > $max_file_size) {
            $issues[] = __('File size exceeds maximum allowed size.', 'wp-data-bridge');
        }
        
        return $issues;
    }
    
    public function register_download_handler(): void {
        add_action('wp_ajax_wp_data_bridge_download', [$this, 'handle_secure_download']);
        add_action('wp_ajax_nopriv_wp_data_bridge_download', [$this, 'handle_secure_download']);
    }
    
    public static function schedule_cleanup(): void {
        if (!wp_next_scheduled('wp_data_bridge_cleanup_files')) {
            wp_schedule_event(time(), 'daily', 'wp_data_bridge_cleanup_files');
        }
    }
    
    public static function handle_scheduled_cleanup(): void {
        $file_handler = new self();
        $cleaned_files = $file_handler->cleanup_old_files(24);
        
        if ($cleaned_files > 0) {
            error_log("WP Data Bridge: Cleaned up {$cleaned_files} old export files.");
        }
    }
}