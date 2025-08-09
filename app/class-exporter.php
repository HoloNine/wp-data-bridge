<?php

if (!defined('ABSPATH')) {
    exit;
}

class WP_Data_Bridge_Exporter
{

    private $batch_size = 1000;
    private $current_site_id = null;

    public function export_site_data(int $site_id, array $options): array
    {
        $data = [];
        $this->current_site_id = $site_id;

        if (!$this->switch_to_blog_safely($site_id)) {
            return ['error' => __('Invalid site ID', 'wp-data-bridge')];
        }

        try {
            $post_types = $options['post_types'] ?? ['post', 'page'];
            $include_users = $options['include_users'] ?? false;
            $date_range = $options['date_range'] ?? null;

            if (!empty($post_types)) {
                $posts_data = $this->get_posts_data($site_id, $post_types, $date_range);
                $data['posts'] = apply_filters('wp_data_bridge_posts_data', $posts_data, $site_id);
            }

            if ($include_users) {
                $users_data = $this->get_users_data($site_id);
                $data['users'] = apply_filters('wp_data_bridge_users_data', $users_data, $site_id);
            }
        } finally {
            $this->restore_current_blog_safely();
        }

        return $data;
    }

    public function get_posts_data(int $site_id, array $post_types, ?array $date_range = null): array
    {
        $posts_data = [];
        $offset = 0;
        $site_name = get_bloginfo('name');

        $post_types = apply_filters('wp_data_bridge_post_types', $post_types, $site_id);

        do {
            $args = [
                'post_type' => $post_types,
                'post_status' => ['publish', 'draft', 'private'],
                'numberposts' => $this->batch_size,
                'offset' => $offset,
                'orderby' => 'ID',
                'order' => 'ASC'
            ];

            if ($date_range && !empty($date_range['start']) && !empty($date_range['end'])) {
                $args['date_query'] = [
                    [
                        'after' => $date_range['start'],
                        'before' => $date_range['end'],
                        'inclusive' => true
                    ]
                ];
            }

            $posts = get_posts($args);

            foreach ($posts as $post) {
                $author = get_userdata($post->post_author);
                $categories = wp_get_post_categories($post->ID, ['fields' => 'names']);
                $tags = wp_get_post_tags($post->ID, ['fields' => 'names']);
                $custom_fields = get_post_meta($post->ID);
                $featured_image_id = get_post_thumbnail_id($post->ID);
                $featured_image_url = $featured_image_id ? wp_get_attachment_url($featured_image_id) : '';

                $custom_fields_formatted = [];
                foreach ($custom_fields as $key => $values) {
                    if (strpos($key, '_') !== 0) {
                        $custom_fields_formatted[$key] = maybe_unserialize($values[0]);
                    }
                }

                $posts_data[] = [
                    'site_id' => $site_id,
                    'site_name' => $site_name,
                    'post_id' => $post->ID,
                    'post_title' => $post->post_title,
                    'post_content' => $post->post_content,
                    'post_excerpt' => $post->post_excerpt,
                    'post_status' => $post->post_status,
                    'post_type' => $post->post_type,
                    'post_author_id' => $post->post_author,
                    'post_author_name' => $author ? $author->display_name : '',
                    'post_date' => $post->post_date,
                    'post_modified' => $post->post_modified,
                    'featured_image_id' => $featured_image_id,
                    'featured_image_url' => $featured_image_url,
                    'categories' => implode(', ', $categories),
                    'tags' => implode(', ', $tags),
                    'custom_fields' => json_encode($custom_fields_formatted),
                    'parent_id' => $post->post_parent
                ];
            }

            $offset += $this->batch_size;
        } while (count($posts) === $this->batch_size);

        return $posts_data;
    }

    public function get_users_data(int $site_id): array
    {
        $users_data = [];
        $offset = 0;
        $site_name = get_bloginfo('name');

        do {
            $users = get_users([
                'blog_id' => is_multisite() ? $site_id : null,
                'number' => $this->batch_size,
                'offset' => $offset,
                'orderby' => 'ID',
                'order' => 'ASC'
            ]);

            foreach ($users as $user) {
                $user_meta = get_user_meta($user->ID);
                $user_roles = $user->roles;

                $user_meta_formatted = [];
                foreach ($user_meta as $key => $values) {
                    if (strpos($key, 'wp_') !== 0) {
                        $user_meta_formatted[$key] = maybe_unserialize($values[0]);
                    }
                }

                $users_data[] = [
                    'site_id' => $site_id,
                    'site_name' => $site_name,
                    'user_id' => $user->ID,
                    'username' => $user->user_login,
                    'email' => $user->user_email,
                    'display_name' => $user->display_name,
                    'registration_date' => $user->user_registered,
                    'user_role' => implode(', ', $user_roles),
                    'user_meta' => json_encode($user_meta_formatted)
                ];
            }

            $offset += $this->batch_size;
        } while (count($users) === $this->batch_size);

        return $users_data;
    }

    public function switch_to_blog_safely(int $site_id): bool
    {
        if (!is_multisite()) {
            return get_current_blog_id() === $site_id || $site_id === 1;
        }

        $site = get_site($site_id);
        if (!$site) {
            return false;
        }

        switch_to_blog($site_id);
        return true;
    }

    public function restore_current_blog_safely(): void
    {
        if (is_multisite()) {
            restore_current_blog();
        }
    }

    public function get_available_post_types(int $site_id): array
    {
        if (!$this->switch_to_blog_safely($site_id)) {
            return [];
        }

        try {
            $post_types = get_post_types(['public' => true], 'objects');
            $available_types = [];

            foreach ($post_types as $post_type) {
                $available_types[$post_type->name] = $post_type->label;
            }

            return $available_types;
        } finally {
            $this->restore_current_blog_safely();
        }
    }

    public function get_site_info(int $site_id): ?array
    {
        if (!$this->switch_to_blog_safely($site_id)) {
            return null;
        }

        try {
            return [
                'id' => $site_id,
                'name' => get_bloginfo('name'),
                'url' => get_bloginfo('url'),
                'admin_email' => get_bloginfo('admin_email'),
                'posts_count' => wp_count_posts()->publish,
                'pages_count' => wp_count_posts('page')->publish
            ];
        } finally {
            $this->restore_current_blog_safely();
        }
    }
}
