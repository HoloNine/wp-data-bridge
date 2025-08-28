<?php

if (!defined('ABSPATH')) {
    exit;
}

class WP_Data_Bridge_CSV_Generator
{

    private $file_handle = null;
    private $temp_file_path = null;
    private $headers_written = false;

    public function __construct()
    {
        // Ensure upload directory exists - use native PHP if wp_mkdir_p fails
        if (!is_dir(WP_DATA_BRIDGE_UPLOAD_DIR)) {
            if (function_exists('wp_mkdir_p')) {
                if (!wp_mkdir_p(WP_DATA_BRIDGE_UPLOAD_DIR)) {
                    // Fallback to native PHP mkdir
                    if (!mkdir(WP_DATA_BRIDGE_UPLOAD_DIR, 0755, true)) {
                        throw new Exception(__('Could not create upload directory.', 'wp-data-bridge'));
                    }
                }
            } else {
                // WordPress not available, use native PHP mkdir
                if (!mkdir(WP_DATA_BRIDGE_UPLOAD_DIR, 0755, true)) {
                    throw new Exception(__('Could not create upload directory.', 'wp-data-bridge'));
                }
            }
        }

        // Check if directory is writable
        if (!is_writable(WP_DATA_BRIDGE_UPLOAD_DIR)) {
            throw new Exception(__('Upload directory is not writable.', 'wp-data-bridge'));
        }

        // CSV Generator initialized successfully
    }

    public function generate_csv(array $data, string $export_type, ?string $filename = null): string
    {
        if (empty($data)) {
            throw new Exception(__('No data provided for export.', 'wp-data-bridge'));
        }

        if ($filename === null) {
            $filename = $this->generate_filename($export_type);
        }

        $file_path = WP_DATA_BRIDGE_UPLOAD_DIR . $filename;
        $this->temp_file_path = $file_path;

        // Prepare file path for CSV generation

        $this->file_handle = fopen($file_path, 'w');
        if (!$this->file_handle) {
            $last_error = error_get_last();
            $error_msg = "Could not create CSV file at: " . $file_path;
            if ($last_error) {
                $error_msg .= " - Error: " . $last_error['message'];
            }
            $error_msg .= " - Directory writable: " . (is_writable(dirname($file_path)) ? 'YES' : 'NO');
            $error_msg .= " - File path length: " . strlen($file_path);
            error_log("WP Data Bridge: " . $error_msg);
            throw new Exception(__('Could not create CSV file: ', 'wp-data-bridge') . basename($file_path));
        }

        // CSV file handle created successfully

        $this->write_utf8_bom();

        try {
            $this->write_data_to_csv($data, $export_type);
        } finally {
            if ($this->file_handle) {
                fclose($this->file_handle);
                $this->file_handle = null;
            }
        }

        // Verify file was created successfully
        if (!file_exists($file_path)) {
            throw new Exception(__('CSV file was not created successfully.', 'wp-data-bridge'));
        }

        // Clear temp_file_path so destructor doesn't delete the file
        $this->temp_file_path = null;

        return $file_path;
    }

    public function generate_multiple_csvs(array $datasets, ?string $base_filename = null): array
    {
        $generated_files = [];

        foreach ($datasets as $export_type => $data) {
            if (empty($data)) {
                continue;
            }

            $filename = $base_filename ?
                str_replace('.csv', "_$export_type.csv", $base_filename) :
                $this->generate_filename($export_type);

            try {
                $file_path = $this->generate_csv($data, $export_type, basename($filename));
                $generated_files[$export_type] = $file_path;
            } catch (Exception $e) {
                error_log("WP Data Bridge: Failed to generate $export_type CSV: " . $e->getMessage());
            }
        }

        return $generated_files;
    }

    private function write_data_to_csv(array $data, string $export_type): void
    {
        $chunk_size = 1000;
        $data_chunks = array_chunk($data, $chunk_size);

        foreach ($data_chunks as $chunk) {
            if (!$this->headers_written && !empty($chunk)) {
                $headers = $this->get_headers_for_type($export_type, $chunk[0]);
                $this->write_headers($headers);
                $this->headers_written = true;
            }

            foreach ($chunk as $row) {
                $this->write_row($row, $export_type);
            }

            if (!wp_doing_ajax()) {
                wp_ob_end_flush_all();
                flush();
            }
        }
    }

    private function write_headers(array $headers): void
    {
        $headers = apply_filters('wp_data_bridge_csv_headers', $headers, $this->get_current_export_type());
        fputcsv($this->file_handle, $headers);
    }

    private function write_row(array $row, string $export_type): void
    {
        $formatted_row = $this->format_row_for_csv($row, $export_type);
        fputcsv($this->file_handle, $formatted_row);
    }

    private function write_utf8_bom(): void
    {
        fwrite($this->file_handle, "\xEF\xBB\xBF");
    }

    private function get_headers_for_type(string $export_type, array $sample_row): array
    {
        switch ($export_type) {
            case 'posts':
                return [
                    'Site ID',
                    'Site Name',
                    'Post ID',
                    'Post Title',
                    'Post Content',
                    'Post Excerpt',
                    'Post Status',
                    'Post Type',
                    'Author ID',
                    'Author Name',
                    'Post Date',
                    'Post Modified',
                    'Featured Image ID',
                    'Featured Image URL',
                    'Categories',
                    'Tags',
                    'Custom Fields',
                    'Parent ID',
                    'SEO Metadata'
                ];

            case 'users':
                return [
                    'Site ID',
                    'Site Name',
                    'User ID',
                    'Username',
                    'Email',
                    'Display Name',
                    'Registration Date',
                    'User Role',
                    'User Meta'
                ];

            default:
                return array_keys($sample_row);
        }
    }

    private function format_row_for_csv(array $row, string $export_type): array
    {
        $formatted_row = [];

        foreach ($row as $key => $value) {
            if (is_array($value)) {
                $formatted_row[$key] = json_encode($value);
            } elseif (is_object($value)) {
                $formatted_row[$key] = json_encode($value);
            } elseif (is_null($value)) {
                $formatted_row[$key] = '';
            } else {
                $formatted_row[$key] = (string) $value;
            }
        }

        return array_values($formatted_row);
    }

    private function generate_filename(string $export_type): string
    {
        $timestamp = date('Y-m-d_H-i-s');
        $site_info = '';

        if (is_multisite() && function_exists('get_current_site')) {
            $current_site = get_current_site();
            $site_info = sanitize_file_name($current_site->domain) . '_';
        }

        $filename = "wp-data-bridge_{$site_info}{$export_type}_{$timestamp}.csv";

        return sanitize_file_name($filename);
    }

    private function get_current_export_type(): string
    {
        return $this->current_export_type ?? 'unknown';
    }

    public function stream_csv_download(string $file_path, ?string $filename = null): void
    {
        if (!file_exists($file_path)) {
            wp_die(__('File not found.', 'wp-data-bridge'));
        }

        if ($filename === null) {
            $filename = basename($file_path);
        }

        $file_size = filesize($file_path);

        nocache_headers();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . $file_size);
        header('Pragma: public');

        $this->output_file_in_chunks($file_path);

        // Don't immediately delete file - let scheduled cleanup handle it
        // $this->cleanup_file($file_path);

        exit;
    }

    private function output_file_in_chunks(string $file_path, int $chunk_size = 8192): void
    {
        $handle = fopen($file_path, 'rb');
        if (!$handle) {
            return;
        }

        while (!feof($handle)) {
            $chunk = fread($handle, $chunk_size);
            echo $chunk;

            if (ob_get_level()) {
                ob_flush();
            }
            flush();
        }

        fclose($handle);
    }

    public function cleanup_file(string $file_path): void
    {
        if (file_exists($file_path)) {
            unlink($file_path);
        }
    }

    public function get_memory_usage(): array
    {
        return [
            'current' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
            'limit' => ini_get('memory_limit')
        ];
    }

    public function validate_data_before_export(array $data, string $export_type): array
    {
        $errors = [];

        if (empty($data)) {
            $errors[] = __('No data to export.', 'wp-data-bridge');
            return $errors;
        }

        $required_fields = $this->get_required_fields_for_type($export_type);

        foreach ($data as $index => $row) {
            if (!is_array($row)) {
                $errors[] = sprintf(__('Row %d is not an array.', 'wp-data-bridge'), $index + 1);
                continue;
            }

            foreach ($required_fields as $field) {
                if (!array_key_exists($field, $row)) {
                    $errors[] = sprintf(__('Row %d is missing required field: %s', 'wp-data-bridge'), $index + 1, $field);
                }
            }
        }

        return $errors;
    }

    private function get_required_fields_for_type(string $export_type): array
    {
        switch ($export_type) {
            case 'posts':
                return ['site_id', 'post_id', 'post_title', 'post_type'];
            case 'users':
                return ['site_id', 'user_id', 'username', 'email'];
            default:
                return [];
        }
    }

    public static function estimate_memory_usage(array $data): int
    {
        if (empty($data)) {
            return 0;
        }

        $sample_row = reset($data);
        $estimated_row_size = strlen(serialize($sample_row));

        return count($data) * $estimated_row_size * 2;
    }

    public function test_csv_generation(): string
    {
        // Simple test function to verify CSV generation works
        $test_data = [
            [
                'site_id' => 1,
                'site_name' => 'Test Site',
                'post_id' => 123,
                'post_title' => 'Test Post',
                'post_content' => 'This is test content',
                'post_excerpt' => 'Test excerpt',
                'post_status' => 'publish',
                'post_type' => 'post',
                'post_author_id' => 1,
                'post_author_name' => 'Test Author',
                'post_date' => '2025-08-09 13:00:00',
                'post_modified' => '2025-08-09 13:30:00',
                'featured_image_id' => '',
                'featured_image_url' => '',
                'categories' => 'Test Category',
                'tags' => 'test, sample',
                'custom_fields' => '{}',
                'parent_id' => 0,
                'seo_metadata' => '{}'
            ]
        ];

        $test_filename = 'test_export_' . date('Y-m-d_H-i-s') . '.csv';

        try {
            $file_path = $this->generate_csv($test_data, 'posts', $test_filename);
            // Don't cleanup test files so we can verify they exist
            $this->temp_file_path = null;
            return "Test CSV generated successfully at: " . $file_path;
        } catch (Exception $e) {
            return "Test CSV generation failed: " . $e->getMessage();
        }
    }

    public function __destruct()
    {
        if ($this->file_handle) {
            fclose($this->file_handle);
        }

        if ($this->temp_file_path && file_exists($this->temp_file_path)) {
            $this->cleanup_file($this->temp_file_path);
        }
    }
}
