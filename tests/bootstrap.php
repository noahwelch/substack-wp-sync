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
if (! defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (! defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}
if (! defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

// process_post_images() require_onces these wp-admin files; give it empty ones.
foreach (['wp-admin/includes/media.php', 'wp-admin/includes/file.php', 'wp-admin/includes/image.php'] as $fake_wp_file) {
    $fake_wp_path = ABSPATH . $fake_wp_file;
    if (! file_exists($fake_wp_path)) {
        @mkdir(dirname($fake_wp_path), 0777, true);
        file_put_contents($fake_wp_path, "<?php\n");
    }
}
unset($fake_wp_file, $fake_wp_path);

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
        public string $postmeta = 'wp_postmeta';
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
            // Support the attachment source-url dedup lookup: resolve it
            // against the in-memory post-meta store so tests can exercise
            // "sideload once, reuse forever".
            if (str_contains($query, '_substack_sync_source_url') && preg_match("/meta_value = '([^']+)'/", $query, $m)) {
                global $_wp_post_meta;
                foreach ((array) ($_wp_post_meta ?? []) as $post_id => $meta) {
                    if (($meta['_substack_sync_source_url'] ?? null) === $m[1]) {
                        return (string) $post_id;
                    }
                }
            }

            return null;
        }

        public function get_results(string $query, $output = 'OBJECT'): array
        {
            // Seedable by tests: map a query-substring needle to the rows it
            // should return, mirroring the get_var() dedup shim above.
            global $_wp_get_results_rows;
            foreach ((array) ($_wp_get_results_rows ?? []) as $needle => $rows) {
                if (str_contains($query, (string) $needle)) {
                    return $rows;
                }
            }

            return [];
        }

        public function get_col(string $query): array
        {
            return [];
        }

        public function query(string $query)
        {
            return 0;
        }

        public function delete(string $table, array $where, array $where_format = [])
        {
            return 0;
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

// Assign via $GLOBALS: PHPUnit includes this bootstrap inside a function, so
// a bare `$wpdb = ...` here would be local and `global $wpdb` would be null.
$GLOBALS['wpdb'] = new wpdb();

// --- WordPress hooks/admin stubs ---

if (! function_exists('add_action')) {
    function add_action(string $hook, $callback, int $priority = 10, int $accepted_args = 1): void {}
}

$_wp_added_filters = [];
$_wp_removed_filters = [];

if (! function_exists('add_filter')) {
    function add_filter(string $hook, $callback, int $priority = 10, int $accepted_args = 1): void
    {
        global $_wp_added_filters;
        $_wp_added_filters[] = $hook;
    }
}

if (! function_exists('remove_filter')) {
    function remove_filter(string $hook, $callback, int $priority = 10): bool
    {
        global $_wp_removed_filters;
        $_wp_removed_filters[] = $hook;

        return true;
    }
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
    function wp_json_encode($data, int $options = 0): string { return json_encode($data, $options); }
}

$_wp_json_responses = [];

if (! function_exists('wp_send_json_success')) {
    function wp_send_json_success($data = null): void
    {
        global $_wp_json_responses;
        $_wp_json_responses[] = ['type' => 'success', 'data' => $data];
    }
}

if (! function_exists('wp_send_json_error')) {
    function wp_send_json_error($data = null): void
    {
        global $_wp_json_responses;
        $_wp_json_responses[] = ['type' => 'error', 'data' => $data];
    }
}

if (! function_exists('wp_die')) {
    function wp_die(string $message = ''): void { throw new \RuntimeException($message); }
}

// --- Transient stubs ---

$_wp_transients = [];
$_wp_deleted_transients = [];

if (! function_exists('get_transient')) {
    function get_transient(string $transient)
    {
        global $_wp_transients;

        return $_wp_transients[$transient] ?? false;
    }
}

if (! function_exists('set_transient')) {
    function set_transient(string $transient, $value, int $expiration = 0): bool
    {
        global $_wp_transients;
        $_wp_transients[$transient] = $value;

        return true;
    }
}

if (! function_exists('delete_transient')) {
    function delete_transient(string $transient): bool
    {
        global $_wp_transients, $_wp_deleted_transients;
        $_wp_deleted_transients[] = $transient;
        unset($_wp_transients[$transient]);

        return true;
    }
}

// Site transients: a distinct key space from plain transients. WP_Feed_Cache_Transient
// stores the cached feed here as of WP 6.9, so the manual cache-bust must clear it.
$_wp_site_transients = [];
$_wp_deleted_site_transients = [];

if (! function_exists('get_site_transient')) {
    function get_site_transient(string $transient)
    {
        global $_wp_site_transients;

        return $_wp_site_transients[$transient] ?? false;
    }
}

if (! function_exists('set_site_transient')) {
    function set_site_transient(string $transient, $value, int $expiration = 0): bool
    {
        global $_wp_site_transients;
        $_wp_site_transients[$transient] = $value;

        return true;
    }
}

if (! function_exists('delete_site_transient')) {
    function delete_site_transient(string $transient): bool
    {
        global $_wp_site_transients, $_wp_deleted_site_transients;
        $_wp_deleted_site_transients[] = $transient;
        unset($_wp_site_transients[$transient]);

        return true;
    }
}

// --- Feed and media stubs ---

if (! function_exists('fetch_feed')) {
    function fetch_feed(string $url)
    {
        return new WP_Error('feed_error', 'stub fetch_feed: no network in tests');
    }
}

$_wp_sideload_calls = [];
$_wp_sideload_fail = false;
$_wp_thumbnails = [];

if (! function_exists('media_sideload_image')) {
    function media_sideload_image(string $src, int $post_id = 0, ?string $desc = null, string $return_type = 'html')
    {
        global $_wp_sideload_calls, $_wp_sideload_fail, $_wp_post_id_counter;
        $_wp_sideload_calls[] = $src;

        if ($_wp_sideload_fail) {
            return new WP_Error('sideload_failed', 'stub sideload failure');
        }

        return $_wp_post_id_counter++;
    }
}

if (! function_exists('home_url')) {
    function home_url(string $path = ''): string
    {
        return 'https://myblog.example.com' . $path;
    }
}

$_wp_missing_attachments = [];

if (! function_exists('wp_get_attachment_url')) {
    function wp_get_attachment_url(int $attachment_id)
    {
        global $_wp_missing_attachments;

        // Simulate an attachment that was deleted outside the plugin: its meta
        // row still resolves in the dedup lookup, but the URL no longer does.
        if (in_array($attachment_id, $_wp_missing_attachments, true)) {
            return false;
        }

        return 'https://myblog.example.com/wp-content/uploads/' . $attachment_id . '.png';
    }
}

if (! function_exists('set_post_thumbnail')) {
    function set_post_thumbnail($post, int $attachment_id): bool
    {
        global $_wp_thumbnails;
        $_wp_thumbnails[(int) (is_object($post) ? $post->ID : $post)] = $attachment_id;

        return true;
    }
}

if (! function_exists('has_post_thumbnail')) {
    function has_post_thumbnail($post = null): bool
    {
        global $_wp_thumbnails;

        return isset($_wp_thumbnails[(int) (is_object($post) ? $post->ID : $post)]);
    }
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
