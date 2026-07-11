<?php

declare(strict_types=1);

/**
 * Test bootstrap: stubs for WordPress functions so tests can run without
 * a full WordPress installation.
 *
 * These stubs approximate the security-relevant behavior of the real
 * WordPress functions, not their full implementation. Where a test depends
 * on a specific sanitization guarantee (tag removal, event-handler and
 * javascript: URI stripping on allowed tags), the stub reproduces that
 * guarantee so the assertion is meaningful. They are NOT a substitute for
 * running the suite against a real WordPress install, and any test that
 * relies on kses behavior beyond what is stubbed here would give false
 * confidence.
 */

// WordPress constants
if (! defined('WPINC')) {
    define('WPINC', 'wp-includes');
}
if (! defined('ABSPATH')) {
    define('ABSPATH', '/tmp/fake-wp/');
}
if (! defined('SUBSTACK_SYNC_PLUGIN_DIR')) {
    define('SUBSTACK_SYNC_PLUGIN_DIR', dirname(__DIR__) . '/');
}

// --- WordPress sanitization stubs ---

if (! function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $str): string
    {
        return strip_tags($str);
    }
}

if (! function_exists('wp_kses_post')) {
    function wp_kses_post(string $content): string
    {
        // Drop disallowed tags (scripts, iframes, embeds) entirely.
        $content = strip_tags($content, '<p><a><strong><em><ul><ol><li><br><h1><h2><h3><h4><h5><h6><blockquote><img><div><span><table><tr><td><th><thead><tbody><figure><figcaption>');
        // On the tags we keep, strip the attribute-level vectors real
        // wp_kses_post also removes: inline event handlers and script: URIs.
        $content = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $content);
        $content = preg_replace('/(href|src)\s*=\s*("|\')?\s*(javascript|vbscript|data):[^"\'>\s]*("|\')?/i', '$1=""', $content);
        return $content;
    }
}

if (! function_exists('esc_url')) {
    function esc_url(string $url): string
    {
        return filter_var($url, FILTER_SANITIZE_URL) ?: '';
    }
}

if (! function_exists('esc_url_raw')) {
    function esc_url_raw(string $url): string
    {
        $cleaned = filter_var($url, FILTER_SANITIZE_URL);
        if ($cleaned && filter_var($cleaned, FILTER_VALIDATE_URL)) {
            return $cleaned;
        }
        return '';
    }
}

if (! function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('absint')) {
    function absint($maybeint): int
    {
        return abs((int) $maybeint);
    }
}

if (! function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1)
    {
        return parse_url($url, $component);
    }
}

if (! function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags(string $content): string
    {
        return strip_tags($content);
    }
}

// --- WordPress option stubs ---

$_wp_options = [];

if (! function_exists('get_option')) {
    function get_option(string $option, $default = false)
    {
        global $_wp_options;
        return $_wp_options[$option] ?? $default;
    }
}

if (! function_exists('update_option')) {
    function update_option(string $option, $value): bool
    {
        global $_wp_options;
        $_wp_options[$option] = $value;
        return true;
    }
}

// --- WordPress post stubs ---

$_wp_posts = [];
$_wp_post_meta = [];
$_wp_post_id_counter = 100;

if (! function_exists('wp_insert_post')) {
    function wp_insert_post(array $postarr)
    {
        global $_wp_posts, $_wp_post_id_counter;
        $id = $_wp_post_id_counter++;
        $postarr['ID'] = $id;
        $_wp_posts[$id] = (object) $postarr;
        return $id;
    }
}

if (! function_exists('wp_update_post')) {
    function wp_update_post(array $postarr)
    {
        global $_wp_posts;
        $id = $postarr['ID'] ?? 0;
        if (isset($_wp_posts[$id])) {
            foreach ($postarr as $key => $value) {
                $_wp_posts[$id]->$key = $value;
            }
        }
        return $id;
    }
}

if (! function_exists('get_post')) {
    function get_post($post_id)
    {
        global $_wp_posts;
        return $_wp_posts[$post_id] ?? null;
    }
}

if (! function_exists('update_post_meta')) {
    function update_post_meta($post_id, $meta_key, $meta_value): bool
    {
        global $_wp_post_meta;
        $_wp_post_meta[$post_id][$meta_key] = $meta_value;
        return true;
    }
}

if (! function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key = '', $single = false)
    {
        global $_wp_post_meta;
        if ($key) {
            $val = $_wp_post_meta[$post_id][$key] ?? null;
            return $single ? $val : [$val];
        }
        return $_wp_post_meta[$post_id] ?? [];
    }
}

if (! function_exists('is_wp_error')) {
    function is_wp_error($thing): bool
    {
        return $thing instanceof WP_Error;
    }
}

if (! class_exists('WP_Error')) {
    class WP_Error
    {
        private string $message;
        public function __construct(string $code = '', string $message = '')
        {
            $this->message = $message;
        }
        public function get_error_message(): string
        {
            return $this->message;
        }
    }
}

// --- Minimal SimplePie stub ---

if (! class_exists('SimplePie_Item')) {
    class SimplePie_Item
    {
        private string $title;
        private string $content;
        private string $id;
        private string $permalink;
        private string $date;

        public function __construct(string $title, string $content, string $id = '', string $permalink = '', string $date = '')
        {
            $this->title = $title;
            $this->content = $content;
            $this->id = $id ?: 'guid-' . md5($title);
            $this->permalink = $permalink ?: 'https://example.substack.com/p/test';
            $this->date = $date ?: '2026-01-15 12:00:00';
        }

        public function get_title(): string { return $this->title; }
        public function get_content(): string { return $this->content; }
        public function get_id(): string { return $this->id; }
        public function get_permalink(): string { return $this->permalink; }
        public function get_date(string $format = ''): string
        {
            return $format ? date($format, strtotime($this->date)) : $this->date;
        }
        public function get_gmdate(string $format = ''): string
        {
            return $this->get_date($format);
        }
        public function get_author(): ?object { return null; }
    }
}

// --- Database stubs ---

if (! class_exists('wpdb')) {
    class wpdb
    {
        public string $prefix = 'wp_';
        private array $rows = [];

        public function prepare(string $query, ...$args): string
        {
            return vsprintf(str_replace('%s', "'%s'", $query), $args);
        }

        public function get_row(string $query, $output = 'OBJECT')
        {
            return null;
        }

        public function get_var(string $query)
        {
            return null;
        }

        public function replace(string $table, array $data, array $format = []): bool
        {
            return true;
        }

        public function get_charset_collate(): string
        {
            return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        }
    }
}

$wpdb = new wpdb();

// --- WordPress hooks/admin stubs ---

if (! function_exists('add_action')) {
    function add_action(string $hook, $callback, int $priority = 10, int $accepted_args = 1): void {}
}

if (! function_exists('add_filter')) {
    function add_filter(string $hook, $callback, int $priority = 10, int $accepted_args = 1): void {}
}

if (! function_exists('register_setting')) {
    function register_setting(string $option_group, string $option_name, array $args = []): void {}
}

if (! function_exists('add_settings_section')) {
    function add_settings_section(string $id, string $title, $callback, string $page): void {}
}

if (! function_exists('add_settings_field')) {
    function add_settings_field(string $id, string $title, $callback, string $page, string $section = ''): void {}
}

if (! function_exists('add_options_page')) {
    function add_options_page(string $page_title, string $menu_title, string $capability, string $menu_slug, $callback): void {}
}

if (! function_exists('wp_create_nonce')) {
    function wp_create_nonce(string $action): string { return 'test-nonce'; }
}

if (! function_exists('check_ajax_referer')) {
    function check_ajax_referer(string $action): bool { return true; }
}

if (! function_exists('wp_verify_nonce')) {
    function wp_verify_nonce(string $nonce, string $action): bool { return true; }
}

if (! function_exists('current_user_can')) {
    function current_user_can(string $capability): bool { return true; }
}

if (! function_exists('admin_url')) {
    function admin_url(string $path = ''): string { return 'https://example.com/wp-admin/' . $path; }
}

if (! function_exists('settings_fields')) {
    function settings_fields(string $option_group): void {}
}

if (! function_exists('do_settings_sections')) {
    function do_settings_sections(string $page): void {}
}

if (! function_exists('submit_button')) {
    function submit_button(string $text = ''): void {}
}

if (! function_exists('selected')) {
    function selected($selected, $current = true, bool $echo = true): string
    {
        $result = $selected == $current ? ' selected="selected"' : '';
        if ($echo) echo $result;
        return $result;
    }
}

if (! function_exists('checked')) {
    function checked($checked, $current = true, bool $echo = true): string
    {
        $result = $checked == $current ? ' checked="checked"' : '';
        if ($echo) echo $result;
        return $result;
    }
}

if (! function_exists('wp_dropdown_users')) {
    function wp_dropdown_users(array $args = []): void {}
}

if (! function_exists('get_categories')) {
    function get_categories(array $args = []): array { return []; }
}

if (! function_exists('wp_json_encode')) {
    function wp_json_encode($data): string { return json_encode($data); }
}

if (! function_exists('wp_send_json_success')) {
    function wp_send_json_success($data = null): void {}
}

if (! function_exists('wp_send_json_error')) {
    function wp_send_json_error($data = null): void {}
}

if (! function_exists('wp_die')) {
    function wp_die(string $message = ''): void { throw new \RuntimeException($message); }
}

// --- Other stubs ---

if (! function_exists('current_time')) {
    function current_time(string $type): string
    {
        return date('Y-m-d H:i:s');
    }
}

// --- Load plugin classes ---

require_once dirname(__DIR__) . '/admin/class-substack-sync-admin.php';
require_once dirname(__DIR__) . '/includes/class-substack-sync-processor.php';
