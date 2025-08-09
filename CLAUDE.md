# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

WP Data Bridge is a WordPress multisite-compatible plugin that exports posts, custom post types, pages, featured images, and users to CSV format. The plugin supports PHP 7.4+ and works across WordPress multisite networks.

## Development Commands

### Build & Development
- `npm run build` - Build the plugin for production using wp-scripts with blocks manifest
- `npm run start` - Start development server with watch mode using wp-scripts
- `npm run format` - Format code using wp-scripts formatter
- `npm run lint:css` - Lint CSS/SCSS files using wp-scripts
- `npm run lint:js` - Lint JavaScript files using wp-scripts
- `npm run packages-update` - Update WordPress packages
- `npm run plugin-zip` - Create a plugin ZIP file for distribution

### WordPress-Specific
This project uses `@wordpress/scripts` for all build tooling, which provides WordPress-specific configurations for Webpack, Babel, ESLint, and Stylelint.

## Architecture & Structure

### Current Implementation Status
- **Main Plugin File**: `wp-data-bridge.php` - Basic plugin header and WordPress integration
- **Development Setup**: Node.js-based build system using WordPress scripts
- **Target Architecture**: Comprehensive plugin specifications exist (see sections below)

### Planned Plugin Structure
```
wp-data-bridge/
├── wp-data-bridge.php          # Main plugin file
├── includes/
│   ├── class-exporter.php      # Main export functionality  
│   ├── class-admin.php         # Admin interface
│   ├── class-csv-generator.php # CSV generation logic
│   └── class-file-handler.php  # File download handling
├── assets/
│   ├── css/admin.css          # Admin styling
│   └── js/admin.js            # Admin JavaScript
├── templates/
│   └── admin-page.php         # Admin page template
└── languages/
    └── wp-data-bridge.pot     # Translation template
```

### Key Architecture Patterns
- **Multisite Compatibility**: Uses `switch_to_blog()` and `restore_current_blog()` for cross-site data access
- **Security First**: Nonce verification, capability checks, input sanitization, and output escaping
- **Performance Optimized**: Chunked processing (1000 records per batch), memory-aware operations
- **WordPress Standards**: Follows WordPress Coding Standards and uses native WordPress functions

## Core Plugin Classes

### Exporter Class (`class-exporter.php`)
Core methods needed:
```php
public function export_site_data($site_id, $options)
public function get_posts_data($site_id, $post_types, $date_range)
public function get_users_data($site_id)
public function get_featured_images_data($post_ids)
public function switch_to_blog_safely($site_id)
public function restore_current_blog_safely()
```

### CSV Generator Class (`class-csv-generator.php`)
Requirements:
- Use `fputcsv()` for proper CSV formatting
- Handle large datasets with chunked processing
- UTF-8 BOM support for Excel compatibility
- Memory-efficient streaming for large exports

### Admin Interface Class (`class-admin.php`)
Features needed:
- Network admin page for multisite installations
- Site selection dropdown (populate from `get_sites()`)
- Export type checkboxes (Posts, Pages, Custom Post Types, Users, Featured Images)
- Date range filters, Export button with AJAX handling
- Progress indicator and download link generation

## Export Data Structure

### Posts/Pages/CPTs CSV Columns
```
site_id, site_name, post_id, post_title, post_content, post_excerpt,
post_status, post_type, post_author_id, post_author_name,
post_date, post_modified, featured_image_id, featured_image_url,
categories, tags, custom_fields, parent_id
```

### Users CSV Columns
```
site_id, site_name, user_id, username, email, display_name,
registration_date, user_role, user_meta
```

### Images CSV Columns
```
site_id, site_name, image_id, image_url, image_path, alt_text,
file_size, upload_date, associated_posts
```

## Technical Requirements

### PHP 7.4 Compatibility
- Use array syntax `[]` instead of `array()`
- Utilize nullable types: `?string`, `?int`
- Use arrow functions for simple callbacks: `fn($x) => $x * 2`
- Implement typed properties in classes
- Use null coalescing operator: `??`
- Avoid PHP 8+ features (named parameters, match expressions)

### WordPress Multisite Considerations
- Check `is_multisite()` before showing site selector
- Use `switch_to_blog()` and `restore_current_blog()` safely
- Handle network-wide vs site-specific data
- Proper capability checks: `manage_network_options` for network admin
- Network-activated plugin considerations

### Security Requirements
- Nonce verification for all forms
- Capability checks: `manage_options` or `manage_network_options`
- Sanitize all input data and escape output data
- Secure file generation (wp-content/uploads/wp-data-bridge/)
- Validate site IDs in multisite context

### Performance Considerations
- Chunked processing for large datasets (1000 records per batch)
- Memory limit awareness and time limit handling
- AJAX-based export for better UX
- Progress tracking and user feedback
- Cleanup temporary files after download

## WordPress Integration Points

### Hooks to Use
- `network_admin_menu` - Add network admin page
- `admin_enqueue_scripts` - Load assets
- `wp_ajax_wp_data_bridge_export` - Handle AJAX export
- `init` - Initialize plugin classes

### Custom Hooks to Implement
```php
apply_filters('wp_data_bridge_posts_data', $posts_data, $site_id);
apply_filters('wp_data_bridge_users_data', $users_data, $site_id);
apply_filters('wp_data_bridge_post_types', $post_types, $site_id);
apply_filters('wp_data_bridge_csv_headers', $headers, $export_type);
```

## Database Queries

### Efficient Data Retrieval Patterns
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

## Testing Focus Areas

### Critical Test Scenarios
1. Single site vs multisite installations
2. Large datasets (1000+ posts, 500+ users)
3. Various post types and custom fields
4. Different user roles and permissions
5. Memory and time limit stress tests
6. Cross-site data isolation in multisite
7. Network admin access control

### Error Handling
Critical scenarios to handle:
- Site doesn't exist in multisite
- Insufficient permissions
- Memory/time limit exceeded
- Disk space issues
- Database connection problems
- File write permissions