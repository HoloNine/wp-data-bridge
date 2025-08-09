# WP Data Bridge Plugin - Development Instructions

## Plugin Overview

Create a WordPress multisite-compatible plugin that exports posts, custom post types, pages, featured images, and users to CSV format. The plugin must support PHP 7.4+ and work across WordPress multisite networks.

## Plugin Structure

```
wp-data-bridge/
├── wp-data-bridge.php          # Main plugin file
├── includes/
│   ├── class-exporter.php      # Main export functionality
│   ├── class-admin.php         # Admin interface
│   ├── class-csv-generator.php # CSV generation logic
│   └── class-file-handler.php  # File download handling
├── assets/
│   ├── css/
│   │   └── admin.css          # Admin styling
│   └── js/
│       └── admin.js           # Admin JavaScript
├── templates/
│   └── admin-page.php         # Admin page template
└── languages/
    └── wp-data-bridge.pot     # Translation template
```

## Core Requirements

### 1. Main Plugin File (wp-data-bridge.php)

- Plugin header with proper metadata
- PHP 7.4 compatibility check
- WordPress version check (minimum 6.0)
- Multisite compatibility declaration
- Autoloader for classes
- Activation/deactivation hooks
- Network admin menu registration for multisite

### 2. Admin Interface Class (class-admin.php)

**Features needed:**

- Network admin page for multisite installations
- Site selection dropdown (populate from `get_sites()`)
- Export type checkboxes:
  - Posts (all post types)
  - Pages
  - Custom Post Types (dynamically detected)
  - Users
  - Featured Images metadata
- Date range filters (optional)
- Export button with AJAX handling
- Progress indicator for large exports
- Download link generation

**Form fields:**

```php
- Site selector (multisite only)
- Content type checkboxes
- Date range inputs
- Export format options
- Batch size setting
```

### 3. Exporter Class (class-exporter.php)

**Core methods needed:**

```php
public function export_site_data($site_id, $options)
public function get_posts_data($site_id, $post_types, $date_range)
public function get_users_data($site_id)
public function get_featured_images_data($post_ids)
public function switch_to_blog_safely($site_id)
public function restore_current_blog_safely()
```

**Data to export:**

**Posts/Pages/CPTs:**

- ID, title, content, excerpt, status
- Author ID and name
- Created date, modified date
- Meta data (custom fields)
- Categories and tags
- Featured image ID and URL
- Post type
- Parent ID (for hierarchical posts)

**Users:**

- ID, username, email, display_name
- Registration date
- Role/capabilities
- Meta data
- Site-specific roles (for multisite)

**Featured Images:**

- Image ID, URL, alt text
- File path, file size
- Upload date
- Associated post IDs

### 4. CSV Generator Class (class-csv-generator.php)

**Requirements:**

- Use `fputcsv()` for proper CSV formatting
- Handle large datasets with chunked processing
- UTF-8 BOM support for Excel compatibility
- Escape special characters properly
- Memory-efficient streaming for large exports

**Methods needed:**

```php
public function generate_csv($data, $filename)
public function stream_csv_headers($filename)
public function write_csv_chunk($data)
public function add_utf8_bom()
```

### 5. File Handler Class (class-file-handler.php)

**Functionality:**

- Secure file generation in uploads directory
- Temporary file cleanup
- Download headers and streaming
- File permissions handling
- Error handling for disk space issues

## Technical Specifications

### PHP 7.4 Compatibility Requirements

- Use array syntax `[]` instead of `array()`
- Utilize nullable types where appropriate: `?string`, `?int`
- Use arrow functions for simple callbacks: `fn($x) => $x * 2`
- Implement typed properties in classes
- Use null coalescing operator: `??`
- Avoid PHP 8+ features (named parameters, match expressions, etc.)

### WordPress Multisite Considerations

- Check `is_multisite()` before showing site selector
- Use `switch_to_blog()` and `restore_current_blog()` safely
- Handle network-wide vs site-specific data
- Proper capability checks: `manage_network_options` for network admin
- Site URL handling for different domains/paths
- Network-activated plugin considerations

### Security Requirements

- Nonce verification for all forms
- Capability checks: `manage_options` or `manage_network_options`
- Sanitize all input data
- Escape output data
- Secure file generation (wp-content/uploads/wp-data-bridge/)
- Validate site IDs in multisite context

### Performance Considerations

- Chunked processing for large datasets (1000 records per batch)
- Memory limit awareness
- Time limit handling with `set_time_limit(0)` if allowed
- AJAX-based export for better UX
- Progress tracking and user feedback
- Cleanup temporary files after download

## Database Queries

### Efficient Data Retrieval

```php
// Posts query structure
$posts = get_posts([
    'post_type' => $post_types,
    'post_status' => ['publish', 'draft', 'private'],
    'numberposts' => $batch_size,
    'offset' => $offset,
    'meta_query' => $meta_filters,
    'date_query' => $date_filters
]);

// Users query for multisite
$users = get_users([
    'blog_id' => $site_id,
    'number' => $batch_size,
    'offset' => $offset
]);
```

### Custom SQL for Better Performance

- Direct database queries for large datasets
- Use `$wpdb->prepare()` for all custom queries
- Index-aware queries for better performance
- Batch processing with LIMIT/OFFSET

## Export Format Specifications

### CSV Structure

**Posts CSV columns:**

```
site_id, site_name, post_id, post_title, post_content, post_excerpt,
post_status, post_type, post_author_id, post_author_name,
post_date, post_modified, featured_image_id, featured_image_url,
categories, tags, custom_fields, parent_id
```

**Users CSV columns:**

```
site_id, site_name, user_id, username, email, display_name,
registration_date, user_role, user_meta
```

**Images CSV columns:**

```
site_id, site_name, image_id, image_url, image_path, alt_text,
file_size, upload_date, associated_posts
```

## User Interface Requirements

### Admin Page Features

- Responsive design
- Clear section separation
- Real-time validation
- Export progress bar
- Download link with expiration
- Error message display
- Success notifications

### AJAX Implementation

- Non-blocking export process
- Progress updates every 10%
- Error handling and user feedback
- Abort functionality
- Download ready notification

## Error Handling

### Critical Error Scenarios

- Site doesn't exist in multisite
- Insufficient permissions
- Memory/time limit exceeded
- Disk space issues
- Database connection problems
- File write permissions

### User Feedback

- Clear error messages
- Helpful suggestions for resolution
- Graceful degradation
- Rollback on partial failures

## Plugin Hooks and Filters

### Custom Hooks to Implement

```php
// Allow filtering export data
apply_filters('wp_data_bridge_posts_data', $posts_data, $site_id);
apply_filters('wp_data_bridge_users_data', $users_data, $site_id);

// Allow custom post types filtering
apply_filters('wp_data_bridge_post_types', $post_types, $site_id);

// Allow custom CSV headers
apply_filters('wp_data_bridge_csv_headers', $headers, $export_type);
```

### WordPress Hooks to Use

- `network_admin_menu` - Add network admin page
- `admin_enqueue_scripts` - Load assets
- `wp_ajax_wp_data_bridge_export` - Handle AJAX export
- `init` - Initialize plugin classes

## Testing Considerations

### Test Scenarios

1. Single site vs multisite installations
2. Large datasets (1000+ posts, 500+ users)
3. Various post types and custom fields
4. Different user roles and permissions
5. Sites with no content
6. Memory and time limit stress tests
7. File download in different browsers

### Multisite-Specific Tests

- Export from different subsites
- Network admin access control
- Site switching functionality
- Cross-site data isolation
- Domain vs subdirectory installations

## Installation & Activation

### Plugin Header

```php
<?php
/**
 * Plugin Name: WP Data Bridge
 * Description: Export posts, pages, custom post types, users, and featured images to CSV. Multisite compatible.
 * Version: 1.0.0
 * Author: Your Name
 * Network: true
 * Requires at least: 5.0
 * Tested up to: 6.6
 * Requires PHP: 7.4
 * License: GPL v2 or later
 */
```

### Activation Checks

- PHP version verification
- WordPress version check
- Required function availability
- Permissions verification
- Multisite detection

## File Organization Best Practices

### Coding Standards

- Follow WordPress Coding Standards
- Use proper DocBlocks for all methods
- Implement proper error handling
- Use WordPress native functions where possible
- Sanitize and validate all inputs
- Escape all outputs

### Class Structure

- Single responsibility principle
- Dependency injection where appropriate
- Static methods for utilities
- Proper constructor patterns
- Interface implementations for extensibility

## Additional Features to Consider

### Advanced Options

- Custom field filtering
- Export templates/presets
- Scheduled exports (wp-cron)
- Email notifications on completion
- Export history/logs
- Import functionality (future version)

### Integration Possibilities

- WP-CLI command support
- REST API endpoints
- Third-party plugin compatibility
- Custom post type plugin integration

This specification provides a comprehensive roadmap for building a robust, multisite-compatible WordPress export plugin that meets professional standards and handles edge cases gracefully.
