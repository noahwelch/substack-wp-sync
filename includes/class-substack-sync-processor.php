<?php

declare(strict_types=1);

/**
 * Substack Sync - WordPress Plugin
 *
 * Copyright (c) 2025 Christopher S. Penn
 * Licensed under Apache License Version 2.0
 *
 * NO SUPPORT PROVIDED. USE AT YOUR OWN RISK.
 */

// If this file is called directly, abort.
defined('ABSPATH') || exit;

/**
 * The core plugin class for processing Substack content.
 *
 * This class handles fetching RSS feeds, processing content, and importing posts.
 */
class Substack_Sync_Processor
{
    /**
     * Plugin settings.
     *
     * @var array<string, mixed>
     */
    private array $settings;

    /**
     * Initialize the class and set its properties.
     */
    public function __construct()
    {
        $this->settings = get_option('substack_sync_settings', []);
    }

    /**
     * Run the sync process.
     *
     * Main method that orchestrates the synchronization process.
     *
     * @param bool $return_status Whether to return detailed status information.
     * @param bool $force_refresh Whether to bypass the feed cache (manual sync).
     * @return array<string, mixed>|void Status information if requested.
     */
    public function run_sync(bool $return_status = false, bool $force_refresh = false)
    {
        if (empty($this->settings['feed_url'])) {
            error_log('Substack Sync: No feed URL configured');

            if ($return_status) {
                return [
                    'success' => false,
                    'error' => 'No feed URL configured',
                    'total_posts' => 0,
                    'posts_processed' => 0,
                ];
            }

            return;
        }

        if (! $this->acquire_sync_lock()) {
            error_log('Substack Sync: sync already running, skipping');

            if ($return_status) {
                return [
                    'success' => false,
                    'error' => 'Another sync is already running',
                    'total_posts' => 0,
                    'posts_processed' => 0,
                ];
            }

            return;
        }

        try {
            return $this->run_sync_locked($return_status, $force_refresh);
        } finally {
            $this->release_sync_lock();
        }
    }

    /**
     * The body of run_sync(), executed while holding the sync lock.
     *
     * @param bool $return_status Whether to return detailed status information.
     * @param bool $force_refresh Whether to bypass the feed cache.
     * @return array<string, mixed>|void Status information if requested.
     */
    private function run_sync_locked(bool $return_status, bool $force_refresh)
    {
        $feed = $this->fetch_sync_feed($force_refresh);

        if (is_wp_error($feed)) {
            error_log('Substack Sync: Error fetching feed - ' . $feed->get_error_message());

            if ($return_status) {
                return [
                    'success' => false,
                    'error' => 'Error fetching feed: ' . $feed->get_error_message(),
                    'total_posts' => 0,
                    'posts_processed' => 0,
                ];
            }

            return;
        }

        $items = $feed->get_items();
        $total_posts = count($items);
        $posts_processed = 0;
        $posts_imported = 0;
        $posts_updated = 0;
        $posts_skipped = 0;
        $errors = [];

        if ($return_status && $total_posts === 0) {
            return [
                'success' => true,
                'total_posts' => 0,
                'posts_processed' => 0,
                'posts_imported' => 0,
                'posts_updated' => 0,
                'posts_skipped' => 0,
                'message' => 'No posts found in feed',
            ];
        }

        foreach ($items as $item) {
            try {
                $result = $this->process_feed_item($item, $return_status);
                $posts_processed++;

                if ($return_status && isset($result['action'])) {
                    switch ($result['action']) {
                        case 'imported':
                            $posts_imported++;

                            break;
                        case 'updated':
                            $posts_updated++;

                            break;
                        case 'skipped':
                            $posts_skipped++;

                            break;
                    }
                }
            } catch (Throwable $e) {
                error_log('Substack Sync: Error processing post - ' . $e->getMessage());
                $errors[] = $e->getMessage();
                $posts_processed++;
            }
        }

        if ($return_status) {
            return [
                'success' => true,
                'total_posts' => $total_posts,
                'posts_processed' => $posts_processed,
                'posts_imported' => $posts_imported,
                'posts_updated' => $posts_updated,
                'posts_skipped' => $posts_skipped,
                'errors' => $errors,
                'message' => sprintf(
                    'Processed %d posts: %d imported, %d updated, %d skipped',
                    $posts_processed,
                    $posts_imported,
                    $posts_updated,
                    $posts_skipped
                ),
            ];
        }
    }

    /**
     * Process a single feed item.
     *
     * @param SimplePie_Item $item The feed item to process.
     * @param bool $return_status Whether to return status information.
     * @return array<string, mixed>|void Status information if requested.
     */
    private function process_feed_item($item, bool $return_status = false)
    {
        $guid = $item->get_id();
        $existing_post = $this->get_existing_post($guid);
        $post_title = $item->get_title() ?? '';

        if ($existing_post) {
            $result = $this->update_post($item, $existing_post, $return_status);

            if ($return_status) {
                return [
                    'action' => $result['success'] ? 'updated' : ($result['message'] && strpos($result['message'], 'Skipped') !== false ? 'skipped' : 'error'),
                    'post_title' => $post_title,
                    'post_id' => $existing_post['post_id'],
                    'success' => $result['success'] ?? false,
                    'message' => $result['message'] ?? "Updated: {$post_title}",
                ];
            }
        } else {
            $result = $this->import_post($item, $return_status);

            if ($return_status) {
                return [
                    'action' => $result['success'] ? 'imported' : ($result['message'] && strpos($result['message'], 'Skipped') !== false ? 'skipped' : 'error'),
                    'post_title' => $post_title,
                    'post_id' => $result['post_id'] ?? null,
                    'success' => $result['success'] ?? false,
                    'message' => $result['message'] ?? "Imported: {$post_title}",
                ];
            }
        }
    }

    /**
     * Process individual posts with detailed progress tracking.
     *
     * @param int $batch_size Number of posts to process per batch.
     * @param int $offset Starting offset.
     * @return array<string, mixed> Detailed status information.
     */
    public function run_batch_sync(int $batch_size = 1, int $offset = 0): array
    {
        if (empty($this->settings['feed_url'])) {
            return [
                'success' => false,
                'error' => 'No feed URL configured',
                'total_posts' => 0,
                'posts_processed' => 0,
                'has_more' => false,
            ];
        }

        if (! $this->acquire_sync_lock()) {
            return [
                'success' => false,
                'error' => 'Another sync is already running. Try again in a few minutes.',
                'total_posts' => 0,
                'posts_processed' => 0,
                'has_more' => false,
            ];
        }

        try {
            return $this->run_batch_sync_locked($batch_size, $offset);
        } finally {
            $this->release_sync_lock();
        }
    }

    /**
     * The body of run_batch_sync(), executed while holding the sync lock.
     *
     * @param int $batch_size Number of posts to process per batch.
     * @param int $offset Starting offset.
     * @return array<string, mixed> Detailed status information.
     */
    private function run_batch_sync_locked(int $batch_size, int $offset): array
    {
        // Only the first batch request forces a refetch; later batches reuse the
        // cached copy so the whole run works from one consistent feed snapshot.
        $feed = $this->fetch_sync_feed($offset === 0);

        if (is_wp_error($feed)) {
            return [
                'success' => false,
                'error' => 'Error fetching feed: ' . $feed->get_error_message(),
                'total_posts' => 0,
                'posts_processed' => 0,
                'has_more' => false,
            ];
        }

        $items = $feed->get_items();
        $total_posts = count($items);

        if ($total_posts === 0) {
            return [
                'success' => true,
                'total_posts' => 0,
                'posts_processed' => 0,
                'has_more' => false,
                'message' => 'No posts found in feed',
            ];
        }

        $batch_items = array_slice($items, $offset, $batch_size);
        $posts_processed = 0;
        $processed_posts = [];
        $errors = [];

        foreach ($batch_items as $item) {
            try {
                $result = $this->process_feed_item($item, true);
                $posts_processed++;
                $processed_posts[] = $result;
            } catch (Throwable $e) {
                error_log('Substack Sync: Error processing post - ' . $e->getMessage());
                $errors[] = $e->getMessage();
                $posts_processed++;
                $processed_posts[] = [
                    'action' => 'error',
                    'post_title' => $item->get_title() ?? 'Unknown',
                    'success' => false,
                    'message' => 'Error: ' . $e->getMessage(),
                ];
            }
        }

        $new_offset = $offset + $batch_size;
        $has_more = $new_offset < $total_posts;

        return [
            'success' => true,
            'total_posts' => $total_posts,
            'posts_processed' => $posts_processed,
            'current_offset' => $offset,
            'next_offset' => $new_offset,
            'has_more' => $has_more,
            'progress_percentage' => round(($new_offset / $total_posts) * 100, 1),
            'processed_posts' => $processed_posts,
            'errors' => $errors,
        ];
    }

    /**
     * Fetch the configured feed with an hourly (not core's 12-hour) freshness.
     *
     * Core defaults SimplePie's cache_duration to 12 hours, which would quietly
     * defeat the hourly cron and the admin's "Sync Now" button. The
     * wp_feed_cache_transient_lifetime filter fires from two core call sites
     * with different second arguments: feed.php passes the raw URL and feeds the
     * value into SimplePie::set_cache_duration() (the freshness gate we care
     * about, and the closure below matches there), while WP_Feed_Cache_Transient
     * passes the md5 cache-key name (the closure does not match there, so the
     * stored transient's own garbage-collection TTL is left at core's default).
     * Shortening the freshness gate is what makes the hourly refresh work.
     *
     * On a manual sync we also delete the cached feed outright. Core stores it
     * via *_site_transient() as of WP 6.9 and via plain *_transient() before
     * that, so clear both key spaces to cover the supported 6.0+ range.
     *
     * @param bool $force_refresh Whether to bypass any existing cached copy.
     * @return SimplePie|WP_Error The feed, or an error.
     */
    private function fetch_sync_feed(bool $force_refresh = false)
    {
        $url = (string) $this->settings['feed_url'];

        if ($force_refresh) {
            // WP_Feed_Cache_Transient key names; best-effort invalidation that
            // degrades to a <=1h stale feed if core ever renames them. Clear
            // both the plain (<6.9) and site (>=6.9) transient stores.
            foreach (['feed_' . md5($url), 'feed_mod_' . md5($url)] as $key) {
                delete_transient($key);
                delete_site_transient($key);
            }
        }

        $lifetime = static function ($seconds, $feed_url) use ($url) {
            return $feed_url === $url ? HOUR_IN_SECONDS : $seconds;
        };

        add_filter('wp_feed_cache_transient_lifetime', $lifetime, 10, 2);

        try {
            return fetch_feed($url);
        } finally {
            remove_filter('wp_feed_cache_transient_lifetime', $lifetime, 10);
        }
    }

    /**
     * Acquire the cross-request sync lock.
     *
     * Prevents an overlapping cron run and a manual "Sync Now"/batch request
     * from processing the same feed concurrently, which could insert duplicate
     * posts for the same GUID before either writes its log row. The get/set
     * pair is not atomic, but it shrinks the race window from an entire sync
     * run to microseconds, and the transient expiry keeps a crashed run from
     * wedging future syncs.
     *
     * @return bool True when the lock was acquired.
     */
    private function acquire_sync_lock(): bool
    {
        if (get_transient('substack_sync_running')) {
            return false;
        }

        set_transient('substack_sync_running', time(), 5 * MINUTE_IN_SECONDS);

        return true;
    }

    /**
     * Release the cross-request sync lock.
     */
    private function release_sync_lock(): void
    {
        delete_transient('substack_sync_running');
    }

    /**
     * Check if a post with the given GUID already exists.
     *
     * Only rows with a real post_id count: a failed import logs post_id 0, and
     * treating that row as "existing" would route the retry through
     * update_post() with ID 0, which can never succeed. Filtering here lets
     * failed imports retry as imports.
     *
     * @param string $guid The Substack post GUID.
     * @return array<string, mixed>|null The existing post data or null.
     */
    private function get_existing_post(string $guid): ?array
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'substack_sync_log';

        $result = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table_name WHERE substack_guid = %s AND post_id > 0", $guid),
            ARRAY_A
        );

        return $result ?: null;
    }

    /**
     * Import a new post from Substack.
     *
     * @param SimplePie_Item $item The feed item to import.
     * @param bool $return_status Whether to return status information.
     * @return array<string, mixed>|void Status information if requested.
     */
    private function import_post($item, bool $return_status = false)
    {
        $post_data = $this->prepare_post_data($item);
        $post_title = $post_data['post_title'];
        $guid = $item->get_id();

        // Check if we should skip due to max retries
        if ($this->should_skip_post($guid)) {
            if ($return_status) {
                return [
                    'success' => false,
                    'post_id' => null,
                    'message' => "Skipped: {$post_title} (max retries exceeded)",
                ];
            }

            return;
        }

        $post_id = wp_insert_post($post_data);

        if ($post_id && ! is_wp_error($post_id)) {
            $this->log_sync($post_id, $guid, 'imported', $post_title);

            // Imports need the post to exist before images can be sideloaded
            // (attachment parent + featured image), so this is the one path that
            // writes twice: insert, then a single update with localized content.
            $localized = $this->process_post_images($post_id, $post_data['post_content']);
            if ($localized !== null) {
                wp_update_post(['ID' => $post_id, 'post_content' => $localized]);
            }

            if ($return_status) {
                return [
                    'success' => true,
                    'post_id' => $post_id,
                    'message' => "Successfully imported: {$post_title}",
                ];
            }
        } else {
            $error_message = is_wp_error($post_id) ? $post_id->get_error_message() : 'Unknown error occurred';
            error_log("Substack Sync: Failed to import post - {$error_message}");
            $this->log_sync(0, $guid, 'error', $post_title, $error_message);

            if ($return_status) {
                return [
                    'success' => false,
                    'post_id' => null,
                    'message' => "Failed to import: {$post_title} - {$error_message}",
                ];
            }
        }
    }

    /**
     * Update an existing post.
     *
     * @param SimplePie_Item $item The feed item.
     * @param array<string, mixed> $existing_post The existing post data.
     * @param bool $return_status Whether to return status information.
     * @return array<string, mixed>|void Status information if requested.
     */
    private function update_post($item, array $existing_post, bool $return_status = false)
    {
        $post_data = $this->prepare_post_data($item);
        $post_data['ID'] = $existing_post['post_id'];
        unset($post_data['post_status']);
        $post_title = $post_data['post_title'];
        $guid = $item->get_id();

        // Check if we should skip due to max retries
        if ($this->should_skip_post($guid)) {
            if ($return_status) {
                return [
                    'success' => false,
                    'post_id' => $existing_post['post_id'],
                    'message' => "Skipped: {$post_title} (max retries exceeded)",
                ];
            }

            return;
        }

        // Localize images before the single write: the post already exists, so
        // its ID is available for sideloading, and writing already-localized
        // content means an unchanged hourly sync matches what is stored, so
        // WordPress skips the revision and post_modified bump entirely.
        $localized = $this->process_post_images((int) $post_data['ID'], $post_data['post_content']);
        if ($localized !== null) {
            $post_data['post_content'] = $localized;
        }

        $post_id = wp_update_post($post_data);

        if ($post_id && ! is_wp_error($post_id)) {
            $this->log_sync($post_id, $guid, 'updated', $post_title);

            if ($return_status) {
                return [
                    'success' => true,
                    'post_id' => $post_id,
                    'message' => "Successfully updated: {$post_title}",
                ];
            }
        } else {
            $error_message = is_wp_error($post_id) ? $post_id->get_error_message() : 'Unknown error occurred';
            error_log("Substack Sync: Failed to update post - {$error_message}");
            $this->log_sync($existing_post['post_id'], $guid, 'error', $post_title, $error_message);

            if ($return_status) {
                return [
                    'success' => false,
                    'post_id' => $existing_post['post_id'],
                    'message' => "Failed to update: {$post_title} - {$error_message}",
                ];
            }
        }
    }

    /**
     * Prepare post data for WordPress insertion.
     *
     * @param SimplePie_Item $item The feed item.
     * @return array<string, mixed> Post data array.
     */
    private function prepare_post_data($item): array
    {
        // SimplePie returns null (not '') for an item with no body/title, e.g. a
        // link- or image-only Substack post. Coerce to '' so the strictly-typed
        // process_content()/sanitize helpers below never receive null (a fatal
        // TypeError under declare(strict_types=1)).
        // Sanitize unconditionally with wp_kses_post so cron imports (user 0)
        // and admin-triggered imports (an admin with unfiltered_html, for whom
        // core skips kses) store the exact same content. Substack RSS is
        // untrusted; this strips scripts and embeds on both paths alike.
        $content = wp_kses_post($this->process_content($item->get_content() ?? ''));
        $title = sanitize_text_field($item->get_title() ?? '');

        // Apply category mapping based on content and title
        $full_text = $title . ' ' . $content;
        $categories = $this->apply_category_mapping($full_text);

        // A feed pubDate in the future makes wp_insert_post() silently flip
        // post_status from the configured value to 'future' (scheduled),
        // overriding the admin's Draft/Published choice. Cap it at "now" (and
        // fall back to now when the feed omits a date) so the choice is honored.
        $post_date = $item->get_date('Y-m-d H:i:s');
        if (empty($post_date) || strtotime($post_date) > time()) {
            $post_date = current_time('mysql');
        }

        $post_data = [
            'post_title' => $title,
            'post_content' => $content,
            'post_status' => $this->settings['default_post_status'] ?? 'draft',
            'post_author' => $this->settings['default_author'] ?? 1,
            'post_date' => $post_date,
            'post_type' => 'post',
        ];

        // Add categories if mapping found any
        if (! empty($categories)) {
            $post_data['post_category'] = $categories;
        }

        return $post_data;
    }

    /**
     * Process and clean content from Substack.
     *
     * @param string $content The raw content from Substack.
     * @return string The processed content.
     */
    private function process_content(string $content): string
    {
        // Cheap pre-check so untouched posts skip the DOM round-trip entirely.
        if (stripos($content, 'subscription') === false && stripos($content, 'like-button') === false) {
            return $content;
        }

        // DOM-based removal, not regex: a lazy `.*?<\/div>` stops at the FIRST
        // closing tag, so Substack's nested wrapper divs left orphaned </div>s
        // in stored content, and passing the feed URL as a preg_replace
        // replacement let `$1`-style sequences in the URL corrupt it.
        $doc = new DOMDocument();
        $loaded = @$doc->loadHTML(
            '<?xml encoding="utf-8"?><div>' . $content . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        $wrapper = $doc->documentElement;

        if (! $loaded || ! $wrapper instanceof DOMElement) {
            return $content;
        }

        $xpath = new DOMXPath($doc);

        // Match the same class substrings the old regexes targeted.
        foreach ($xpath->query('//div[contains(@class, "subscription")]') as $node) {
            if ($node !== $wrapper && $this->is_attached($node)) {
                $node->parentNode->replaceChild($this->build_subscribe_node($doc), $node);
            }
        }

        foreach ($xpath->query('//div[contains(@class, "like-button")]') as $node) {
            if ($node !== $wrapper && $this->is_attached($node)) {
                $node->parentNode->removeChild($node);
            }
        }

        $html = '';
        foreach ($wrapper->childNodes as $child) {
            $html .= $doc->saveHTML($child);
        }

        return $html;
    }

    /**
     * Build the subscribe-block replacement node.
     *
     * Built as DOM nodes (not an HTML string) so the feed URL is attribute-set
     * verbatim and never interpreted by a serializer or regex engine.
     *
     * @param DOMDocument $doc The document to create the node in.
     * @return DOMElement The subscribe block.
     */
    private function build_subscribe_node(DOMDocument $doc): DOMElement
    {
        $div = $doc->createElement('div');
        $div->setAttribute('class', 'substack-subscribe-block');

        $link = $doc->createElement('a', 'Subscribe to our newsletter');
        // esc_url_raw, not esc_url: display-context esc_url() rewrites & to the
        // literal text &#038;, which saveHTML() then re-escapes to &amp;#038;,
        // corrupting any feed URL with 2+ query params. setAttribute stores the
        // value verbatim and saveHTML() does the correct attribute escaping, so
        // the non-display escaper is the right one for a DOM attribute.
        $link->setAttribute('href', esc_url_raw($this->settings['feed_url'] ?? ''));
        $link->setAttribute('target', '_blank');
        $div->appendChild($link);

        return $div;
    }

    /**
     * Whether a node is still attached to its document.
     *
     * Nested target divs can be detached when an ancestor from the same XPath
     * result set was already removed; touching them would throw.
     *
     * @param DOMNode $node The node to check.
     * @return bool True when the node's ancestor chain reaches the document.
     */
    private function is_attached(DOMNode $node): bool
    {
        for ($current = $node; $current !== null; $current = $current->parentNode) {
            if ($current instanceof DOMDocument) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sideload remote images and return content rewritten to the local copies.
     *
     * Sideloads (deduped by source URL) and sets the featured image as side
     * effects, but does NOT write the post: it returns the localized HTML so the
     * caller folds it into a single wp_update_post(). Localizing before the
     * caller's only write means an unchanged hourly sync produces content
     * identical to what is stored, so WordPress creates no revision and does not
     * bump post_modified. Writing here separately (as an earlier version did)
     * doubled revisions on every image post, every hour, forever.
     *
     * @param int $post_id The WordPress post ID.
     * @param string $content The post content.
     * @return string|null The localized content, or null when nothing was rewritten.
     */
    private function process_post_images(int $post_id, string $content): ?string
    {
        if (trim($content) === '' || stripos($content, '<img') === false) {
            return null;
        }

        // media_sideload_image() and its helpers live in wp-admin/includes and
        // are NOT autoloaded on the cron (wp-cron.php) or admin-ajax paths, so
        // the call would be an undefined-function fatal without these requires.
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $doc = new DOMDocument();
        $loaded = @$doc->loadHTML(
            '<?xml encoding="utf-8"?><div>' . $content . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        $wrapper = $doc->documentElement;

        if (! $loaded || ! $wrapper instanceof DOMElement) {
            return null;
        }

        $home_host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
        $failed_downloads = 0;
        $new_downloads = 0;
        $rewritten = 0;
        $first_attachment = 0;

        foreach ($doc->getElementsByTagName('img') as $img) {
            $src = $img->getAttribute('src');
            if (empty($src) || ! filter_var($src, FILTER_VALIDATE_URL)) {
                continue;
            }

            // Already served locally (rewritten on an earlier sync): skip.
            $src_host = strtolower((string) wp_parse_url($src, PHP_URL_HOST));
            if ($src_host !== '' && $src_host === $home_host) {
                continue;
            }

            // One download per source URL, ever: syncs run hourly, and without
            // this the same image would re-enter the media library every run.
            $attachment_id = $this->find_attachment_by_source($src);

            if (! $attachment_id) {
                // Each sideload is a synchronous remote HTTP fetch inside the
                // sync request. A max_execution_time kill mid-loop is not a
                // Throwable, so no catch block can save the run; bound the
                // per-run work in both directions. Skipped images are retried
                // on later runs, so localization converges incrementally.
                if ($failed_downloads >= 5 || $new_downloads >= 10) {
                    continue;
                }

                // Feed content is attacker-influenced. filter_var only checks
                // URL syntax, so an <img src="http://169.254.169.254/..."> or
                // an RFC1918/loopback target would otherwise be fetched
                // server-side (SSRF). Only sideload from public http(s) hosts.
                if (! $this->is_safe_remote_url($src)) {
                    error_log('Substack Sync: skipped unsafe image URL - ' . $src);

                    continue;
                }

                $new_downloads++;
                $result = media_sideload_image($src, $post_id, '', 'id');

                if (is_wp_error($result)) {
                    $failed_downloads++;
                    error_log('Substack Sync: image sideload failed - ' . $result->get_error_message());

                    continue;
                }

                $attachment_id = (int) $result;
                update_post_meta($attachment_id, '_substack_sync_source_url', $src);
            }

            // Serve the local copy: without this rewrite the sideloaded files
            // were never referenced, and posts kept hotlinking Substack's CDN.
            $local_url = wp_get_attachment_url($attachment_id);
            if ($local_url) {
                $img->setAttribute('src', $local_url);
                // A leftover remote srcset would make browsers ignore the
                // localized src.
                $img->removeAttribute('srcset');
                $img->removeAttribute('sizes');
                $rewritten++;

                // Only inside this block: a dedup hit against a source-URL meta
                // row whose attachment was since deleted returns a falsy URL,
                // and setting that as the featured image would point the
                // thumbnail at a nonexistent attachment.
                if (! $first_attachment) {
                    $first_attachment = $attachment_id;
                }
            }
        }

        if ($first_attachment && ! has_post_thumbnail($post_id)) {
            set_post_thumbnail($post_id, $first_attachment);
        }

        if ($rewritten > 0) {
            $html = '';
            foreach ($wrapper->childNodes as $child) {
                $html .= $doc->saveHTML($child);
            }

            return $html;
        }

        return null;
    }

    /**
     * Find a previously sideloaded attachment by its original source URL.
     *
     * @param string $src The remote image URL.
     * @return int The attachment ID, or 0 when none exists.
     */
    private function find_attachment_by_source(string $src): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
                '_substack_sync_source_url',
                $src
            )
        );
    }

    /**
     * Whether a URL is safe to fetch server-side.
     *
     * Best-effort SSRF guard for untrusted feed content. It hard-blocks the
     * cases it can prove are internal (non-http(s) schemes, embedded
     * credentials, IP-literal or resolved private/reserved/loopback/link-local
     * targets, and obvious internal hostnames), but FAILS OPEN when DNS
     * resolution is inconclusive, e.g. dns_get_record is in disable_functions
     * or the resolver returns nothing. Failing open is deliberate: silently
     * dropping every legitimate image on a locked-down resolver is worse than
     * this guard's residual gap, and it cannot defeat DNS-rebinding anyway.
     *
     * @param string $url The candidate image URL.
     * @return bool True when the URL is safe to sideload.
     */
    private function is_safe_remote_url(string $url): bool
    {
        $parts = wp_parse_url($url);

        // wp_parse_url() returns false for seriously malformed URLs; indexing
        // that bool would emit a warning on every such feed URL.
        if (! is_array($parts) || empty($parts['host'])) {
            return false;
        }
        if (empty($parts['scheme']) || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower($parts['host']);

        // Hard block: IP literals in private/reserved/loopback/link-local ranges.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->is_public_ip($host);
        }

        // Hard block: obvious internal hostnames.
        if ($host === 'localhost' || substr($host, -6) === '.local' || substr($host, -10) === '.localhost') {
            return false;
        }

        // Best-effort resolution. If the resolver is unavailable (function
        // disabled) or returns nothing, fail open rather than drop the image.
        if (! function_exists('dns_get_record')) {
            return true;
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (! is_array($records) || $records === []) {
            return true;
        }

        // Reject only when we positively resolve the host to a non-public
        // address; anything else (all-public, or records with no usable IP)
        // is allowed.
        foreach ($records as $record) {
            $ip = $record['ip'] ?? ($record['ipv6'] ?? '');
            if ($ip === '') {
                continue;
            }
            if (! $this->is_public_ip($ip)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether an IP address is publicly routable.
     *
     * filter_var's NO_PRIV_RANGE/NO_RES_RANGE flags have blind spots this
     * covers explicitly: RFC 6598 CGNAT space (100.64.0.0/10, common on
     * internal cloud networks) and RFC 6890 protocol assignments
     * (192.0.0.0/24), both reported as "public" by filter_var.
     *
     * @param string $ip The IP address to check.
     * @return bool True when the address is public.
     */
    private function is_public_ip(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            if ($long !== false) {
                if (($long & 0xFFC00000) === 0x64400000) { // 100.64.0.0/10
                    return false;
                }
                if (($long & 0xFFFFFF00) === 0xC0000000) { // 192.0.0.0/24
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Log sync activity to the database.
     *
     * @param int $post_id The WordPress post ID.
     * @param string $substack_guid The Substack GUID.
     * @param string $status The sync status.
     * @param string $post_title The post title for reference.
     * @param string $error_message Optional error message.
     */
    private function log_sync(int $post_id, string $substack_guid, string $status, string $post_title = '', string $error_message = ''): void
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'substack_sync_log';

        // Get existing record to preserve retry count
        $existing = $wpdb->get_row(
            $wpdb->prepare("SELECT retry_count FROM $table_name WHERE substack_guid = %s", $substack_guid)
        );

        $retry_count = 0;
        if ($existing && $status === 'error') {
            $retry_count = $existing->retry_count + 1;
        }

        $wpdb->replace(
            $table_name,
            [
                'post_id' => $post_id,
                'substack_guid' => $substack_guid,
                'substack_title' => $post_title,
                'sync_date' => current_time('mysql'),
                'last_modified' => current_time('mysql'),
                'status' => $status,
                'retry_count' => $retry_count,
                'error_message' => $error_message,
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s']
        );
    }

    /**
     * Get sync statistics for resumable operations.
     *
     * @return array<string, mixed> Sync statistics.
     */
    public function get_sync_stats(): array
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'substack_sync_log';

        $stats = $wpdb->get_row("
            SELECT 
                COUNT(*) as total_synced,
                SUM(CASE WHEN status = 'imported' THEN 1 ELSE 0 END) as imported_count,
                SUM(CASE WHEN status = 'updated' THEN 1 ELSE 0 END) as updated_count,
                SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as error_count,
                MAX(sync_date) as last_sync_date
            FROM $table_name
        ", ARRAY_A);

        return [
            'total_synced' => intval($stats['total_synced'] ?? 0),
            'imported_count' => intval($stats['imported_count'] ?? 0),
            'updated_count' => intval($stats['updated_count'] ?? 0),
            'error_count' => intval($stats['error_count'] ?? 0),
            'last_sync_date' => $stats['last_sync_date'] ?? null,
        ];
    }

    /**
     * Get posts that need retry due to errors.
     *
     * @param int $max_retries Maximum number of retries allowed.
     * @return array<array<string, mixed>> Posts that need retry.
     */
    public function get_posts_needing_retry(int $max_retries = 3): array
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'substack_sync_log';

        // Bounded: this feeds an admin display list, and an unbounded result
        // set over a large error backlog serves no one.
        return $wpdb->get_results(
            $wpdb->prepare("
                SELECT substack_guid, substack_title, retry_count, error_message
                FROM $table_name
                WHERE status = 'error' AND retry_count < %d
                ORDER BY sync_date ASC
                LIMIT 200
            ", $max_retries),
            ARRAY_A
        );
    }

    /**
     * Reset retry state for all retryable failed posts in one query.
     *
     * Replaces a per-row reset loop: one UPDATE instead of N SELECT+UPDATE
     * round trips, and no unbounded row fetch just to walk it.
     *
     * @param int $max_retries Maximum retries allowed.
     * @return int Number of rows reset.
     */
    public function reset_failed_posts(int $max_retries = 3): int
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'substack_sync_log';

        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE $table_name SET retry_count = 0, status = 'pending' WHERE status = 'error' AND retry_count < %d",
                $max_retries
            )
        );

        return $updated === false ? 0 : (int) $updated;
    }

    /**
     * Check if a post should be skipped due to max retries.
     *
     * @param string $guid The Substack GUID.
     * @param int $max_retries Maximum retries allowed.
     * @return bool True if post should be skipped.
     */
    private function should_skip_post(string $guid, int $max_retries = 3): bool
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'substack_sync_log';

        $retry_count = $wpdb->get_var(
            $wpdb->prepare("SELECT retry_count FROM $table_name WHERE substack_guid = %s AND status = 'error'", $guid)
        );

        return $retry_count !== null && intval($retry_count) >= $max_retries;
    }

    /**
     * Get recent sync logs for display.
     *
     * @param int $limit Number of logs to retrieve.
     * @return array<array<string, mixed>> Recent sync logs.
     */
    public function get_recent_sync_logs(int $limit = 50): array
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'substack_sync_log';

        return $wpdb->get_results(
            $wpdb->prepare("
                SELECT substack_guid, substack_title, sync_date, status, error_message 
                FROM $table_name 
                ORDER BY sync_date DESC 
                LIMIT %d
            ", $limit),
            ARRAY_A
        );
    }

    /**
     * Rollback all synced posts.
     *
     * @return int Number of posts deleted.
     */
    public function rollback_all_posts(): int
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'substack_sync_log';

        $deleted_count = $this->delete_synced_posts('');

        // Clear only the leftover post_id = 0 rows (failed imports). Scoping to
        // post_id = 0 keeps an unscoped DELETE from sweeping the log row of a
        // post a concurrent sync inserted mid-rollback, which would orphan it.
        $wpdb->query("DELETE FROM $table_name WHERE post_id = 0");

        return $deleted_count;
    }

    /**
     * Rollback only failed posts.
     *
     * @return int Number of posts deleted.
     */
    public function rollback_failed_posts(): int
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'substack_sync_log';

        $deleted_count = $this->delete_synced_posts("status = 'error'");

        // Remove only the leftover post_id = 0 error rows; scoping to post_id = 0
        // keeps this from deleting the log row of a post a concurrent sync just
        // inserted (and marked error), which would orphan it.
        $wpdb->delete($table_name, ['status' => 'error', 'post_id' => 0], ['%s', '%d']);

        return $deleted_count;
    }

    /**
     * Rollback posts by date range.
     *
     * @param string $date_from Start date.
     * @param string $date_to End date.
     * @return int Number of posts deleted.
     */
    public function rollback_posts_by_date(string $date_from, string $date_to): int
    {
        // Require well-formed YYYY-MM-DD dates. Empty or malformed input would
        // otherwise build nonsense " 00:00:00" BETWEEN bounds and delete an
        // unintended set of posts.
        if (! $this->is_valid_date($date_from) || ! $this->is_valid_date($date_to)) {
            return 0;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'substack_sync_log';
        $from = $date_from . ' 00:00:00';
        $to = $date_to . ' 23:59:59';

        $deleted_count = $this->delete_synced_posts('sync_date BETWEEN %s AND %s', [$from, $to]);

        // Remove only the leftover post_id = 0 in-range rows; scoping to
        // post_id = 0 keeps this from deleting the log row of a post a
        // concurrent sync inserted in-range mid-rollback, which would orphan it.
        $wpdb->query(
            $wpdb->prepare("DELETE FROM $table_name WHERE post_id = 0 AND sync_date BETWEEN %s AND %s", $from, $to)
        );

        return $deleted_count;
    }

    /**
     * Delete synced posts matching a log-table condition, in chunks.
     *
     * Chunked so each iteration deletes the WordPress posts AND their log rows
     * together: if the request times out mid-run, the state stays consistent
     * (re-running resumes where it stopped) instead of leaving deleted posts
     * behind a log table that was never cleared. Also avoids loading an
     * unbounded id list into memory.
     *
     * @param string $where Extra prepared WHERE condition (may be empty).
     * @param array<int, string> $params Values for the WHERE placeholders.
     * @return int Number of posts deleted.
     */
    private function delete_synced_posts(string $where, array $params = []): int
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'substack_sync_log';
        $deleted_count = 0;

        $sql = "SELECT id, post_id FROM $table_name WHERE post_id > 0"
            . ($where !== '' ? " AND $where" : '')
            . ' ORDER BY id LIMIT 100';

        do {
            $rows = $wpdb->get_results($params ? $wpdb->prepare($sql, ...$params) : $sql, ARRAY_A);

            if (empty($rows)) {
                break;
            }

            $log_ids = [];
            foreach ($rows as $row) {
                if (wp_delete_post((int) $row['post_id'], true)) {
                    $deleted_count++;
                }
                // Clear the log row even when the post was already gone, or
                // this chunk would be re-selected forever.
                $log_ids[] = (int) $row['id'];
            }

            $wpdb->query("DELETE FROM $table_name WHERE id IN (" . implode(',', $log_ids) . ')');
        } while (count($rows) === 100);

        return $deleted_count;
    }

    /**
     * Validate a date string is a real calendar date in Y-m-d format.
     *
     * @param string $date The date string to validate.
     * @return bool True when $date is a well-formed YYYY-MM-DD date.
     */
    private function is_valid_date(string $date): bool
    {
        $parsed = DateTime::createFromFormat('Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    /**
     * Apply category mapping based on keywords in post content.
     *
     * @param string $content The post content to analyze.
     * @return array<int> Array of category IDs.
     */
    private function apply_category_mapping(string $content): array
    {
        $category_mappings = $this->settings['category_mapping'] ?? [];
        $assigned_categories = [];

        // is_array, not empty(): stale option data can hold a non-array here,
        // and foreach over a scalar warns.
        if (! is_array($category_mappings) || $category_mappings === []) {
            return $assigned_categories;
        }

        // mb_strtolower, not strtolower: byte-wise lowering leaves non-ASCII
        // characters untouched (e.g. "CAFÉ"), so an accented keyword would
        // silently never match. Lower the content once, outside the loop.
        $content_lower = mb_strtolower($content, 'UTF-8');

        foreach ($category_mappings as $mapping) {
            if (! is_array($mapping)) {
                continue;
            }

            // Use a strict emptiness check, matching the sanitizer, so a keyword
            // of literally "0" is honored rather than silently dropped by
            // empty('0') === true (which would store dead, never-matching config).
            $keyword = is_scalar($mapping['keyword'] ?? null) ? mb_strtolower(trim((string) $mapping['keyword']), 'UTF-8') : '';
            $category_id = absint($mapping['category'] ?? 0);
            if ($keyword === '' || $category_id <= 0) {
                continue;
            }

            // Check if keyword exists in content
            if (strpos($content_lower, $keyword) !== false) {
                if (! in_array($category_id, $assigned_categories, true)) {
                    $assigned_categories[] = $category_id;
                }
            }
        }

        return $assigned_categories;
    }
}
