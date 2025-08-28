# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

WP Data Bridge is a WordPress plugin that provides comprehensive data export and import functionality for posts, custom post types, pages, featured images, and users via CSV format. The plugin is designed to work seamlessly on both **WordPress multisite networks** and **single WordPress installations**, supporting PHP 7.4+.

### Core Functionality
- **Export**: Generate CSV files containing posts, pages, custom post types, users, and featured images
- **Import**: Import CSV files created by this plugin to restore content on different sites
- **Cross-Site Migration**: Transfer content between different WordPress installations
- **Multisite & Single Site Compatible**: Automatically adapts interface and functionality based on installation type

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
│   ├── class-importer.php      # CSV import functionality
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
- **Single Site Compatible**: Automatically detects single site installations and adapts interface accordingly
- **Admin Access Control**: Admin pages visible only to administrators and super administrators
- **Security First**: Nonce verification, capability checks, input sanitization, and output escaping
- **Performance Optimized**: Chunked processing (1000 records per batch), memory-aware operations
- **WordPress Standards**: Follows WordPress Coding Standards and uses native WordPress functions

### Access Control Requirements
- **Multisite Networks**: Admin page accessible only in Network Admin to users with `manage_network_options` capability
- **Single Site**: Admin page accessible in regular Admin to users with `manage_options` capability  
- **User Role Validation**: Strict capability checks before any export/import operations
- **AJAX Security**: All AJAX requests require proper nonce verification and capability checks

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

### Importer Class (`class-importer.php`)
Core methods needed:
```php
public function import_csv_file($file_path, $import_options)
public function parse_csv_file($file_path)
public function validate_csv_structure($csv_data, $expected_type)
public function import_posts_data($posts_data, $target_site_id)
public function import_users_data($users_data, $target_site_id)
public function check_custom_post_type_exists($post_type)
public function handle_duplicate_content($existing_post, $import_data)
public function create_import_log($results)
```

Requirements:
- Parse CSV files created by the export functionality
- Validate CSV structure and required columns
- Handle custom post type existence validation
- Provide detailed import results and error reporting
- Support duplicate content handling (skip, update, or create new)
- Memory-efficient processing for large import files

### Admin Interface Class (`class-admin.php`)
Export Features:
- Network admin page for multisite installations
- Site selection dropdown (populate from `get_sites()`)
- Export type checkboxes (Posts, Pages, Custom Post Types, Users, Featured Images)
- Date range filters, Export button with AJAX handling
- Progress indicator and download link generation

Import Features:
- CSV file upload interface with drag-and-drop support
- Import type detection from CSV headers
- Target site selection (multisite only)
- Import options: duplicate handling, content mapping
- Custom post type validation and warnings
- Import progress tracking and detailed results display
- Import preview functionality before actual import

## Export Data Structure

### Posts/Pages/CPTs CSV Columns
```
site_id, site_name, post_id, post_title, post_content, post_excerpt,
post_status, post_type, post_author_id, post_author_name,
post_date, post_modified, featured_image_id, featured_image_url,
categories, tags, custom_fields, parent_id, seo_metadata
```

**SEO Metadata Column**: Contains SmartCrawl Pro SEO data including title tags, meta descriptions, canonical URLs, robots directives, and other SEO settings stored as JSON-encoded data from post meta keys with `_wds_` prefix.

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

## Import Functionality

### CSV Import Process
1. **File Upload**: Accept CSV files via admin interface or programmatic upload
2. **Structure Validation**: Verify CSV headers match expected export format  
3. **Content Validation**: Check for required fields and data integrity
4. **Custom Post Type Verification**: Validate that custom post types exist on target site
5. **Duplicate Detection**: Check for existing content based on configurable criteria
6. **Import Execution**: Process records in batches with progress tracking
7. **Result Reporting**: Provide detailed success/failure logs with actionable feedback

### Import Options & Settings
```php
$import_options = [
    'target_site_id' => 1,                    // Target site for import (multisite only)
    'duplicate_handling' => 'skip',           // 'skip', 'update', 'create_new'
    'batch_size' => 100,                      // Records per batch
    'preserve_ids' => false,                  // Attempt to preserve original post IDs
    'import_images' => true,                  // Download and import featured images
    'create_missing_users' => false,          // Create users that don't exist
    'update_existing_users' => false,         // Update existing user data
    'missing_post_type_action' => 'skip',     // 'skip', 'convert_to_post', 'notify'
];
```

### Custom Post Type Validation
- Check `post_type_exists()` before importing custom post types
- Display warning for missing custom post types with options:
  - Skip posts of missing post type
  - Convert to standard 'post' type  
  - Abort import and notify user to install required plugins
- Log all post type validation issues for user review

### SEO Metadata Conversion (SmartCrawl to Yoast)
The plugin includes automatic SEO metadata conversion when importing:

**Supported Conversions:**
- SmartCrawl Pro `_wds_` meta keys → Yoast SEO `_yoast_wpseo_` meta keys
- Title tags, meta descriptions, canonical URLs
- Robots directives (noindex, nofollow, noarchive, nosnippet)
- Focus keywords and OpenGraph data
- Twitter Card metadata

**Conversion Process:**
1. Detects if Yoast SEO is active on target site
2. Parses SmartCrawl SEO metadata from CSV
3. Maps data using conversion table
4. Handles special value formatting (booleans, arrays)
5. Imports converted data as Yoast post meta

**Conversion Filter:**
```php
apply_filters('wp_data_bridge_seo_conversion', $yoast_data, $smartcrawl_data, $post_id);
```

### Import Error Handling & Notifications
Critical scenarios to handle:
- CSV file format errors (invalid structure, encoding issues)
- Missing required columns or data
- Custom post type doesn't exist on target site
- Insufficient permissions for post creation/editing
- Image download failures during import
- Database errors during content creation
- Memory/time limit exceeded during large imports
- SEO plugin compatibility (Yoast SEO not active)

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
- `admin_menu` - Add admin page for single sites
- `admin_enqueue_scripts` - Load assets
- `wp_ajax_wp_data_bridge_export` - Handle AJAX export
- `wp_ajax_wp_data_bridge_import` - Handle AJAX import
- `wp_ajax_wp_data_bridge_validate_import` - Validate CSV before import
- `wp_ajax_wp_data_bridge_import_preview` - Preview import data
- `init` - Initialize plugin classes

### Custom Hooks to Implement
Export Hooks:
```php
apply_filters('wp_data_bridge_posts_data', $posts_data, $site_id);
apply_filters('wp_data_bridge_users_data', $users_data, $site_id);
apply_filters('wp_data_bridge_post_types', $post_types, $site_id);
apply_filters('wp_data_bridge_csv_headers', $headers, $export_type);
```

Import Hooks:
```php
apply_filters('wp_data_bridge_import_data', $import_data, $import_type);
apply_filters('wp_data_bridge_before_import', $validated_data, $import_options);
apply_filters('wp_data_bridge_after_import', $import_results, $import_options);
apply_filters('wp_data_bridge_duplicate_post_action', $action, $existing_post, $import_data);
apply_filters('wp_data_bridge_missing_post_type_action', $action, $post_type, $import_data);

do_action('wp_data_bridge_import_started', $import_options);
do_action('wp_data_bridge_import_completed', $import_results);
do_action('wp_data_bridge_import_failed', $error_message, $import_data);
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

Export Testing:
1. Single site vs multisite installations
2. Large datasets (1000+ posts, 500+ users)
3. Various post types and custom fields
4. Different user roles and permissions
5. Memory and time limit stress tests
6. Cross-site data isolation in multisite
7. Network admin access control

Import Testing:
8. CSV file format validation and error handling
9. Custom post type existence validation
10. Duplicate content handling (skip, update, create new)
11. Large CSV import performance (10,000+ records)
12. Image import and download failures
13. Cross-site import between different WordPress versions
14. Import with missing users and user creation
15. Partial import recovery and resumption

### Error Handling
Critical scenarios to handle:
- Site doesn't exist in multisite
- Insufficient permissions
- Memory/time limit exceeded
- Disk space issues
- Database connection problems
- File write permissions