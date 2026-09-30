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

if (! function_exists('delete_option')) {
    function delete_option(string $option): bool
    {
        global $_wp_options;

        if (! array_key_exists($option, $_wp_options)) {
            return false;
        }

        unset($_wp_options[$option]);

        return true;
    }
}

// --- WordPress post stubs ---

$_wp_posts = [];
$_wp_post_meta = [];
$_wp_post_id_counter = 100;

// Off by default: when on, the post-write stubs fire save_post_post the way core
// does, so a test can show which saves the edit tracking counts as a person's.
$_wp_fire_save_post = false;

if (! function_exists('wp_slash')) {
    function wp_slash($value)
    {
        if (is_array($value)) {
            return array_map('wp_slash', $value);
        }
        return is_string($value) ? addslashes($value) : $value;
    }
}

if (! function_exists('wp_unslash')) {
    function wp_unslash($value)
    {
        if (is_array($value)) {
            return array_map('wp_unslash', $value);
        }
        return is_string($value) ? stripslashes($value) : $value;
    }
}

// Both unslash what they are given, as core's do: callers must pass slashed data.
if (! function_exists('wp_insert_post')) {
    function wp_insert_post(array $postarr)
    {
        global $_wp_posts, $_wp_post_id_counter, $_wp_fire_save_post;
        $id = $_wp_post_id_counter++;
        $postarr = wp_unslash($postarr);
        $postarr['ID'] = $id;
        $_wp_posts[$id] = (object) $postarr;
        if ($_wp_fire_save_post) {
            do_action('save_post_post', $id, $_wp_posts[$id], false);
        }
        return $id;
    }
}

if (! function_exists('wp_update_post')) {
    function wp_update_post(array $postarr)
    {
        global $_wp_posts, $_wp_fire_save_post;
        $id = $postarr['ID'] ?? 0;
        if (isset($_wp_posts[$id])) {
            foreach (wp_unslash($postarr) as $key => $value) {
                $_wp_posts[$id]->$key = $value;
            }
            if ($_wp_fire_save_post) {
                do_action('save_post_post', $id, $_wp_posts[$id], true);
            }
        }
        return $id;
    }
}

if (! function_exists('get_post_field')) {
    function get_post_field(string $field, $post)
    {
        global $_wp_posts;
        return $_wp_posts[(int) $post]->$field ?? '';
    }
}

if (! function_exists('wp_cache_delete')) {
    function wp_cache_delete($key, string $group = ''): bool { return true; }
}

// Real core reads the site timezone; here it is the gmt_offset option, in hours.
if (! function_exists('get_date_from_gmt')) {
    function get_date_from_gmt(string $date, string $format = 'Y-m-d H:i:s'): string
    {
        return gmdate($format, strtotime($date . ' UTC') + (int) round((float) get_option('gmt_offset', 0) * 3600));
    }
}

if (! function_exists('delete_post_meta')) {
    function delete_post_meta($post_id, $meta_key): bool
    {
        global $_wp_post_meta;
        unset($_wp_post_meta[$post_id][$meta_key]);
        return true;
    }
}

// --- Taxonomy stubs: terms are stored by name, per post and taxonomy ---

$_wp_object_terms = [];
$_wp_registered_taxonomies = [];

if (! function_exists('register_taxonomy')) {
    function register_taxonomy(string $taxonomy, $object_type, array $args = []): void
    {
        global $_wp_registered_taxonomies;
        $_wp_registered_taxonomies[$taxonomy] = ['object_type' => $object_type, 'args' => $args];
    }
}

if (! function_exists('wp_set_object_terms')) {
    function wp_set_object_terms(int $object_id, $terms, string $taxonomy, bool $append = false)
    {
        // Core's term_exists() and wp_insert_term() unslash each name.
        global $_wp_object_terms;
        $_wp_object_terms[$object_id][$taxonomy] = array_values(wp_unslash((array) $terms));
        return [];
    }
}

if (! function_exists('has_term')) {
    function has_term($term = '', string $taxonomy = '', $post = null): bool
    {
        global $_wp_object_terms;
        return ($_wp_object_terms[(int) $post][$taxonomy] ?? []) !== [];
    }
}

// --- HTTP stubs: responses are seeded by URL; anything unseeded fails ---

$_wp_http_responses = [];
$_wp_http_calls = [];

if (! function_exists('wp_safe_remote_get')) {
    function wp_safe_remote_get(string $url, array $args = [])
    {
        global $_wp_http_responses, $_wp_http_calls;
        $_wp_http_calls[] = $url;
        return $_wp_http_responses[$url] ?? new WP_Error('http_request_failed', 'stub: no network in tests');
    }
}

if (! function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response)
    {
        return is_array($response) ? ($response['response']['code'] ?? '') : '';
    }
}

if (! function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response): string
    {
        return is_array($response) ? (string) ($response['body'] ?? '') : '';
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
        // Core's update_metadata() unslashes the value, like the post writers.
        global $_wp_post_meta;
        $_wp_post_meta[$post_id][$meta_key] = wp_unslash($meta_value);
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

        /**
         * $raw is content:encoded as sent; $content is get_content(), which real
         * fetch_feed() has already sanitized. They default to the same string.
         */
        public function __construct(
            string $title,
            string $content,
            string $id = '',
            string $permalink = '',
            string $date = '',
            private ?string $raw = null,
            private ?SimplePie_Enclosure $enclosure = null,
            private array $authors = []
        ) {
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
        public function get_author(): ?object { return $this->authors[0] ?? null; }
        public function get_authors(): ?array { return $this->authors ?: null; }
        public function get_enclosure(): ?SimplePie_Enclosure { return $this->enclosure; }

        public function get_item_tags(string $namespace, string $tag): ?array
        {
            if ($namespace !== 'http://purl.org/rss/1.0/modules/content/' || $tag !== 'encoded') {
                return null;
            }

            return [['data' => $this->raw ?? $this->content]];
        }
    }
}

if (! class_exists('SimplePie_Enclosure')) {
    class SimplePie_Enclosure
    {
        public function __construct(private string $link, private string $type) {}
        public function get_link(): string { return $this->link; }
        public function get_type(): string { return $this->type; }
    }
}

if (! class_exists('SimplePie_Author')) {
    class SimplePie_Author
    {
        public function __construct(private ?string $name) {}
        public function get_name(): ?string { return $this->name; }
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
            // Seedable like get_results(): a query-substring needle maps to one row.
            global $_wp_get_row_rows;
            foreach ((array) ($_wp_get_row_rows ?? []) as $needle => $row) {
                if (str_contains($query, (string) $needle)) {
                    return $output === ARRAY_A ? $row : (object) $row;
                }
            }

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

        // No return type: real $wpdb::get_results() returns null on a query
        // error, and tests seed null to exercise that path.
        public function get_results(string $query, $output = 'OBJECT')
        {
            // Seedable by tests: map a query-substring needle to the rows it
            // should return (or null to simulate a query error), mirroring the
            // get_var() dedup shim above. The SQL is recorded like query()'s, so
            // a test can assert on what a read actually asked the database for
            // rather than on the method's source text.
            global $_wp_get_results_rows, $_wp_get_results_calls;
            $_wp_get_results_calls[] = $query;

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
            // Records the SQL and returns a seedable affected-row count, so a
            // test can drive the branches that key on "did this change
            // anything" rather than only asserting on the query text.
            global $_wp_query_calls, $_wp_query_result;
            $_wp_query_calls[] = $query;

            return $_wp_query_result ?? 0;
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

$_wp_registered_actions = [];

// Records what was registered, so a test can assert the plugin file's wiring.
// A no-op stub made every hook registration invisible to the suite, which is
// how a typo in a hook name would ship green.
if (! function_exists('add_action')) {
    function add_action(string $hook, $callback, int $priority = 10, int $accepted_args = 1): void
    {
        global $_wp_registered_actions;
        $_wp_registered_actions[$hook][] = $callback;
    }
}

$_wp_current_actions = [];

if (! function_exists('do_action')) {
    function do_action(string $hook, ...$args): void
    {
        global $_wp_registered_actions, $_wp_current_actions;
        $_wp_current_actions[] = $hook;
        foreach ($_wp_registered_actions[$hook] ?? [] as $callback) {
            $callback(...$args);
        }
        array_pop($_wp_current_actions);
    }
}

if (! function_exists('doing_action')) {
    function doing_action(?string $hook = null): bool
    {
        global $_wp_current_actions;
        $current = (array) ($_wp_current_actions ?? []);
        return $hook === null ? $current !== [] : in_array($hook, $current, true);
    }
}

if (! function_exists('has_action')) {
    function has_action(string $hook, $callback = null)
    {
        global $_wp_registered_actions;
        $registered = $_wp_registered_actions[$hook] ?? [];

        return $callback === null ? $registered !== [] : in_array($callback, $registered, true);
    }
}

// Loading substack-sync.php runs the plugin's top-level wiring, which needs
// these. The activation hooks and the cron scheduling are not what the wiring
// tests assert, so they only have to exist.
if (! function_exists('plugin_dir_path')) {
    function plugin_dir_path(string $file): string
    {
        return dirname($file) . '/';
    }
}

if (! function_exists('register_activation_hook')) {
    function register_activation_hook(string $file, $callback): void {}
}

if (! function_exists('register_deactivation_hook')) {
    function register_deactivation_hook(string $file, $callback): void {}
}

if (! function_exists('wp_next_scheduled')) {
    function wp_next_scheduled(string $hook, array $args = [])
    {
        return false;
    }
}

if (! function_exists('wp_schedule_event')) {
    function wp_schedule_event(int $timestamp, string $recurrence, string $hook, array $args = [])
    {
        return true;
    }
}

if (! function_exists('wp_doing_ajax')) {
    function wp_doing_ajax(): bool
    {
        return false;
    }
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

if (! function_exists('wp_nonce_url')) {
    function wp_nonce_url(string $url, string $action = ''): string { return $url . '&_wpnonce=nonce-for-' . $action; }
}

if (! function_exists('esc_js')) {
    function esc_js(string $text): string { return addslashes(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')); }
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

/**
 * Minimal stand-ins for the SimplePie objects fetch_feed() returns, so tests can
 * drive the sync loop itself and not only its error path. Only the five accessor
 * methods the processor actually calls are implemented.
 */
if (! class_exists('Stub_Feed_Item')) {
    class Stub_Feed_Item
    {
        public function __construct(
            private string $id = 'https://example.substack.com/p/stub',
            private string $title = 'Stub post',
            private string $content = '<p>Body</p>',
            private string $permalink = 'https://example.substack.com/p/stub'
        ) {
        }

        public function get_id(): string
        {
            return $this->id;
        }

        public function get_title(): string
        {
            return $this->title;
        }

        public function get_content(): string
        {
            return $this->content;
        }

        public function get_permalink(): string
        {
            return $this->permalink;
        }

        public function get_date(string $format): string
        {
            return date($format, 1700000000);
        }

        public function get_gmdate(string $format): string
        {
            return gmdate($format, 1700000000);
        }

        public function get_item_tags(string $namespace, string $tag): ?array
        {
            return [['data' => $this->content]];
        }

        public function get_enclosure(): ?SimplePie_Enclosure
        {
            return null;
        }

        public function get_authors(): ?array
        {
            return null;
        }
    }
}

if (! class_exists('Stub_Feed')) {
    class Stub_Feed
    {
        /** @param list<Stub_Feed_Item> $items */
        public function __construct(private array $items)
        {
        }

        /** @return list<Stub_Feed_Item> */
        public function get_items(): array
        {
            return $this->items;
        }
    }
}

$_wp_feed_items = null;

if (! function_exists('fetch_feed')) {
    function fetch_feed(string $url)
    {
        global $_wp_feed_items;

        // Tests that need the sync loop to run seed $_wp_feed_items, including
        // with [] for the empty-feed case; leaving it null keeps the fetch error
        // every other test expects.
        if (is_array($_wp_feed_items)) {
            return new Stub_Feed($_wp_feed_items);
        }

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

        // Faithful to WP: reject any URL with no image extension before the '?'.
        if (! preg_match('/[^\?]+\.(?:jpe?g|jpe|gif|png|webp)\b/i', $src)) {
            return new WP_Error('image_sideload_failed', 'Invalid image URL');
        }

        $_wp_sideload_calls[] = $src;

        if ($_wp_sideload_fail) {
            return new WP_Error('sideload_failed', 'stub sideload failure');
        }

        return $_wp_post_id_counter++;
    }
}

if (! function_exists('download_url')) {
    function download_url(string $url, int $timeout = 300)
    {
        global $_wp_sideload_calls, $_wp_sideload_fail, $_wp_download_bytes, $_wp_on_download;
        $_wp_sideload_calls[] = $url;
        if (is_callable($_wp_on_download)) {
            $_wp_on_download($url);
        }

        // A scalar fails every download; an array fails only the URLs carrying
        // one of its substrings, which is what a per-URL 404 (a video with no
        // maxres frame) needs to be expressible.
        if (is_array($_wp_sideload_fail)) {
            foreach ($_wp_sideload_fail as $needle) {
                if (strpos($url, (string) $needle) !== false) {
                    return new WP_Error('download_failed', 'stub download failure: ' . $url);
                }
            }
        } elseif ($_wp_sideload_fail) {
            return new WP_Error('download_failed', 'stub download failure');
        }

        // Default to real 1x1 PNG bytes so getimagesize() sniffs an image; a
        // test may seed $_wp_download_bytes to simulate a non-image download.
        $tmp = tempnam(sys_get_temp_dir(), 'substack-img');
        file_put_contents(
            $tmp,
            $_wp_download_bytes ?? base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC')
        );

        return $tmp;
    }
}

if (! function_exists('media_handle_sideload')) {
    function media_handle_sideload(array $file, int $post_id = 0, ?string $desc = null, array $post_data = [])
    {
        global $_wp_post_id_counter, $_wp_media_handle_fail;

        // Real WP moves the temp file on success (and on its own copy failure);
        // mirror the delete so tests don't litter the temp dir either way.
        if (isset($file['tmp_name']) && is_string($file['tmp_name']) && file_exists($file['tmp_name'])) {
            @unlink($file['tmp_name']);
        }

        if ($_wp_media_handle_fail) {
            return new WP_Error('sideload_failed', 'stub media_handle_sideload failure');
        }

        global $_wp_posts;
        $id = $_wp_post_id_counter++;
        $_wp_posts[$id] = (object) ['ID' => $id, 'post_type' => 'attachment', 'post_excerpt' => ''];

        return $id;
    }
}

if (! function_exists('wp_basename')) {
    function wp_basename(string $path, string $suffix = ''): string
    {
        return basename(str_replace('\\', '/', $path), $suffix);
    }
}

if (! function_exists('sanitize_file_name')) {
    function sanitize_file_name(string $filename): string
    {
        $filename = (string) preg_replace('/[^A-Za-z0-9._-]/', '', $filename);

        return trim($filename, '.-');
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

if (! function_exists('get_post_thumbnail_id')) {
    function get_post_thumbnail_id($post = null): int
    {
        global $_wp_thumbnails;

        return (int) ($_wp_thumbnails[(int) (is_object($post) ? $post->ID : $post)] ?? 0);
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

// --- Per-test reset of every stub's state ---

function reset_wp_stubs(): void
{
    global $_wp_options, $_wp_transients, $_wp_deleted_transients, $_wp_added_filters,
        $_wp_removed_filters, $_wp_sideload_calls, $_wp_sideload_fail, $_wp_thumbnails,
        $_wp_post_id_counter, $_wp_posts, $_wp_post_meta, $_wp_site_transients,
        $_wp_deleted_site_transients, $_wp_json_responses, $_wp_missing_attachments,
        $_wp_get_results_rows, $_wp_download_bytes, $_wp_media_handle_fail, $_wp_feed_items,
        $_wp_query_calls, $_wp_query_result, $_wp_get_results_calls, $_wp_fire_save_post,
        $_wp_object_terms, $_wp_http_responses, $_wp_http_calls, $_wp_registered_taxonomies,
        $_wp_get_row_rows, $_wp_current_actions, $_wp_on_download;

    $_wp_on_download = null;
    $_wp_current_actions = [];
    $_wp_get_row_rows = [];
    $_wp_fire_save_post = false;
    $_wp_object_terms = [];
    $_wp_http_responses = [];
    $_wp_http_calls = [];
    $_wp_registered_taxonomies = [];
    $_wp_get_results_calls = [];
    $_wp_query_calls = [];
    $_wp_query_result = null;
    $_wp_feed_items = null;
    $_wp_download_bytes = null;
    $_wp_media_handle_fail = false;
    $_wp_get_results_rows = [];
    $_wp_post_id_counter = 1000;
    $_wp_posts = [];
    $_wp_post_meta = [];
    $_wp_options = [];
    $_wp_transients = [];
    $_wp_deleted_transients = [];
    $_wp_site_transients = [];
    $_wp_deleted_site_transients = [];
    $_wp_json_responses = [];
    $_wp_added_filters = [];
    $_wp_removed_filters = [];
    $_wp_sideload_calls = [];
    $_wp_sideload_fail = false;
    $_wp_thumbnails = [];
    $_wp_missing_attachments = [];
    $_POST = [];
}

// --- Load plugin classes ---

require_once dirname(__DIR__) . '/admin/class-substack-sync-admin.php';
require_once dirname(__DIR__) . '/includes/class-substack-sync-processor.php';
