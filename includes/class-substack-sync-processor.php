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
     * Public post-meta key holding a post's canonical Substack URL. Non-underscore
     * so it appears in Elementor's dynamic Custom Field picker.
     */
    private const SOURCE_URL_META_KEY = 'substack_source_url';

    /**
     * Private attachment-meta key holding the remote URL an attachment was
     * sideloaded from. Named close to SOURCE_URL_META_KEY but a different thing
     * on a different object: that one is a post's Substack permalink, this one
     * is an attachment's origin, and it is what proves the plugin chose an image.
     */
    private const ATTACHMENT_SOURCE_URL_META_KEY = '_substack_sync_source_url';

    /**
     * Option flag marking the one-time source-URL backfill as complete.
     */
    private const SOURCE_URL_BACKFILL_OPTION = 'substack_sync_source_url_backfilled';

    /**
     * Option flag marking the one-time video featured-image repair as complete.
     */
    private const VIDEO_THUMBNAIL_REPAIR_OPTION = 'substack_sync_video_thumbnail_repaired';

    /**
     * Consecutive syncs the video featured-image repair has run without
     * repairing anything. Reset by any progress, absent once the pass finishes.
     */
    private const VIDEO_THUMBNAIL_REPAIR_ATTEMPTS_OPTION = 'substack_sync_video_thumbnail_repair_attempts';

    /**
     * What the repair gave up on: {'count': int, 'ids': list<int>}. A count in a
     * log file is not something a site owner can act on, so the pass records
     * which posts it left; the count is kept alongside because the list is
     * capped and a capped list displayed as a total understates the backlog.
     */
    private const VIDEO_THUMBNAIL_REPAIR_UNREPAIRED_OPTION = 'substack_sync_video_thumbnail_repair_unrepaired';

    /**
     * When the no-progress counter last advanced. The cap is counted in syncs
     * and Sync Now drives one on demand, so without this an owner clicking it
     * a few times while diagnosing something spends the whole budget before a
     * sideload has had any chance to retry.
     */
    private const VIDEO_THUMBNAIL_REPAIR_ADVANCED_OPTION = 'substack_sync_video_thumbnail_repair_advanced_at';

    /**
     * No-progress syncs before the repair stops and logs what it could not
     * reach. Stalling, not elapsed syncs: a pass still repairing posts is
     * converging, and one permanently stuck post must not run the clock down on
     * the posts still arriving behind it. Not every outstanding post can ever
     * finish, though: a deleted video's frame 404s forever, and a post aged out
     * of the feed is never rewritten. Counted at most once an hour, so the
     * shortest this can end a pass is five syncs an hour apart rather than five
     * clicks of Sync Now. The first advance is not rate limited, so that is four
     * hours of elapsed time, not five.
     */
    private const VIDEO_THUMBNAIL_REPAIR_MAX_ATTEMPTS = 5;

    /**
     * Option recording the plugin version this site's stored data was last
     * brought forward for.
     */
    private const UPGRADED_VERSION_OPTION = 'substack_sync_version';

    /**
     * First version whose video rewrite actually fires on an imported post.
     */
    private const VIDEO_REWRITE_FIXED_VERSION = '1.3.2';

    /**
     * How many unrepaired post IDs the pass records when it gives up. Enough to
     * work through by hand; past that the list is a symptom, not a worklist.
     * The recorded count stays exact regardless.
     */
    private const VIDEO_THUMBNAIL_REPAIR_MAX_REPORTED = 50;

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

        // Returns on both paths, not only the status one: an empty feed rewrote
        // no content, so falling through to the repair below would scan
        // pre-rewrite posts, find nothing, and set its one-time flag for good.
        if ($total_posts === 0) {
            if (! $return_status) {
                return;
            }

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

        // After the loop, not on admin_init: the repair matches on content that
        // only exists once a sync has rewritten it, so running it earlier would
        // scan pre-rewrite posts, find nothing, and set its done flag for good.
        $this->repair_video_featured_images();

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

        // Last batch only, and here as well as in run_sync_locked(): this is the
        // path the admin's Sync Now button drives, so leaving the repair on
        // run_sync() alone made the button unable to ever trigger it. Gated on
        // the final batch because the repair matches on content the loop above
        // has to rewrite first.
        if (! $has_more) {
            $this->repair_video_featured_images();
        }

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
            $this->store_source_url((int) $post_id, $item);

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
        // its ID is available for sideloading, and folding the localized content
        // into this one write means an unchanged hourly sync stores content
        // identical to what is already there, so WordPress creates no new
        // revision. (post_modified is still bumped: wp_insert_post() sets it on
        // every update regardless of whether any field changed. Suppressing that
        // would require a change-detection guard before the write, not just
        // matching content.)
        $localized = $this->process_post_images((int) $post_data['ID'], $post_data['post_content']);
        if ($localized !== null) {
            $post_data['post_content'] = $localized;
        }

        $post_id = wp_update_post($post_data);

        if ($post_id && ! is_wp_error($post_id)) {
            $this->log_sync($post_id, $guid, 'updated', $post_title);
            $this->store_source_url((int) $post_id, $item);

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
        // The video test is the bare host word, not a wrapper class, because the
        // rewrite below keys on the embed URL; a prose mention of YouTube costs
        // one wasted round-trip, which beats missing a real embed.
        if (
            stripos($content, 'subscription') === false
            && stripos($content, 'like-button') === false
            && stripos($content, 'youtube') === false
        ) {
            return $content;
        }

        // DOM-based removal, not regex: a lazy `.*?<\/div>` stops at the FIRST
        // closing tag, so Substack's nested wrapper divs left orphaned </div>s
        // in stored content, and passing the feed URL as a preg_replace
        // replacement let `$1`-style sequences in the URL corrupt it.
        $doc = new DOMDocument();
        $loaded = @$doc->loadHTML(
            '<?xml encoding="utf-8"?><div>' . $this->encode_stray_lt($content) . '</div>',
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

        // Substack embeds video as an <iframe> inside a wrapper div, and the
        // iframe never reaches WordPress: it is not an allowed post tag, so kses
        // strips it and leaves the empty wrapper with no image at all. Swap in a
        // linked thumbnail, which survives kses and is sideloaded by
        // process_post_images() like any other image.
        //
        // Two passes, because on an imported post the strip already happened:
        // fetch_feed() sanitizes with WP_SimplePie_Sanitize_KSES, which runs
        // wp_kses_post() over content:encoded while parsing, so get_content()
        // hands this method the wrapper alone. An iframe survives only for a
        // caller holding unsanitized feed HTML. Match on the embed host where
        // there is one, and on the wrapper below where there is not.
        foreach ($xpath->query('//iframe[@src]') as $iframe) {
            if (! $iframe instanceof DOMElement || ! $this->is_attached($iframe)) {
                continue;
            }

            $src = $iframe->getAttribute('src');
            if (! $this->is_youtube_embed_src($src)) {
                continue;
            }

            $chain = $this->youtube_embed_chain($iframe, $wrapper);
            $video_id = $this->youtube_id_from_embed($chain, $src);
            if ($video_id === null) {
                continue;
            }

            // Replace the outermost wrapper, not just the iframe: it carries
            // Substack's padding-bottom aspect-ratio hack, and `style` survives
            // kses, so swapping only the iframe leaves a tall empty box.
            $target = end($chain);
            $target->parentNode->replaceChild($this->build_video_thumbnail_node($doc, $video_id), $target);
        }

        // The pass that actually fires on an imported post: the iframe is gone
        // before the item reaches process_content(), so the wrapper's own
        // attributes are the only record of the video left. Selected on the two
        // attributes an ID can be read from, since a wrapper matched on nothing
        // else could never yield one.
        $wrappers = $xpath->query('//*[contains(@data-attrs, "videoId")] | //*[starts-with(@id, "youtube")]');

        foreach ($wrappers as $node) {
            // Document order, so an outer wrapper is replaced before any nested
            // candidate, and the nested one is then detached rather than matched
            // a second time inside content that no longer holds it.
            if (! $node instanceof DOMElement || ! $this->is_attached($node)) {
                continue;
            }

            // An embed whose iframe is still standing was either rewritten by
            // the loop above or is not YouTube's. Either way it is a live player
            // and this pass must not touch it. The node itself counts:
            // getElementsByTagName() reads descendants only, and an iframe
            // carrying the wrapper's own id would otherwise walk straight past a
            // guard written to stop exactly that.
            if ($node->nodeName === 'iframe' || $node->getElementsByTagName('iframe')->length > 0) {
                continue;
            }

            if (! $this->is_youtube_wrapper($node)) {
                continue;
            }

            $chain = $this->youtube_embed_chain($node, $wrapper);
            $video_id = $this->youtube_id_from_embed($chain);
            if ($video_id === null) {
                continue;
            }

            $target = end($chain);
            $target->parentNode->replaceChild($this->build_video_thumbnail_node($doc, $video_id), $target);
        }

        $html = '';
        foreach ($wrapper->childNodes as $child) {
            $html .= $doc->saveHTML($child);
        }

        return $html;
    }

    /**
     * Encode stray `<` that do not begin a tag before a fragment is handed to
     * DOMDocument::loadHTML().
     *
     * libxml's HTML parser discards the text following a bare `<` that is not
     * part of a valid tag (e.g. "Revenue < 5000" or an inline code sample), so
     * a raw `<` in prose would silently truncate the rest of the post. This
     * runs on raw feed content (process_content() executes before wp_kses_post),
     * and well-formed feeds usually pre-encode these, but the parser must never
     * eat real text. Only a `<` followed by a tag-name character, `/`, `!`, or
     * `?` is treated as real markup and left alone.
     *
     * @param string $content The raw fragment.
     * @return string The fragment with stray `<` encoded.
     */
    private function encode_stray_lt(string $content): string
    {
        // preg_replace returns null only on PCRE failure; fall back to the
        // input so a pathological string is never turned into null.
        return preg_replace('/<(?![a-zA-Z\/!?])/', '&lt;', $content) ?? $content;
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
     * Whether an iframe src points at a YouTube embed.
     *
     * Exact host match against an allowlist, not a substring or suffix test:
     * str_ends_with($host, 'youtube.com') also matches evilyoutube.com. Substack
     * currently emits the -nocookie variant; the rest are the forms the same
     * embed takes elsewhere, and cost nothing to accept.
     *
     * @param string $src The iframe src attribute.
     * @return bool True when the host is a known YouTube embed host.
     */
    private function is_youtube_embed_src(string $src): bool
    {
        $host = strtolower((string) wp_parse_url($src, PHP_URL_HOST));

        return in_array($host, [
            'youtube.com',
            'www.youtube.com',
            'm.youtube.com',
            'youtube-nocookie.com',
            'www.youtube-nocookie.com',
        ], true);
    }

    /**
     * Whether an element is one of Substack's YouTube embed wrappers.
     *
     * Three independent signals, because the markup has changed shape across
     * editor versions and a post archive spans every version the site imported
     * through. All three have to name YouTube: a data-attrs videoId on its own
     * is not enough, since Substack writes that key for other embed types too,
     * and rewriting one of those would point both the frame and the link at an
     * ID that was never a YouTube ID.
     *
     * @param DOMElement $node The candidate wrapper.
     * @return bool True when the element is a YouTube embed wrapper.
     */
    private function is_youtube_wrapper(DOMElement $node): bool
    {
        if (preg_match('/^youtube\d*-/i', $node->getAttribute('id')) === 1) {
            return true;
        }

        // Token match inside the class attribute, not a substring test:
        // "youtube-wrap" is a prefix of any number of unrelated class names.
        if (preg_match('/(?<![-\w])youtube-wrap(?![-\w])/i', $node->getAttribute('class')) === 1) {
            return true;
        }

        return stripos($node->getAttribute('data-component-name'), 'youtube') !== false;
    }

    /**
     * The embed's node chain: the iframe, then each ancestor that wraps the
     * embed and nothing else, innermost first, stopping before $boundary.
     *
     * The chain is what gets replaced, so "wraps nothing else" is the whole
     * rule. Climbing on a class test instead (Substack's youtube-wrap) is wrong
     * in both directions: it replaces an ancestor that also held a caption,
     * dropping it silently, and it stops short of a plain <p> sitting between
     * the wrapper and the iframe, leaving the wrapper's aspect-ratio box empty
     * around the thumbnail. It is also the same unstable signal that matching
     * moved off. A bare iframe with no wrapper yields a chain of one.
     *
     * @param DOMElement $iframe The embed iframe.
     * @param DOMElement $boundary The document wrapper, never crossed.
     * @return list<DOMElement> The iframe and its wrapper ancestors.
     */
    private function youtube_embed_chain(DOMElement $iframe, DOMElement $boundary): array
    {
        $chain = [$iframe];
        $node = $iframe;

        for ($parent = $node->parentNode; $parent instanceof DOMElement && $parent !== $boundary; $parent = $parent->parentNode) {
            if (! $this->wraps_only($parent, $node)) {
                break;
            }

            $chain[] = $parent;
            $node = $parent;
        }

        return $chain;
    }

    /**
     * Whether $parent contains $child and nothing else that renders.
     *
     * Whitespace and comments do not count: Substack's wrappers are pretty
     * printed, so a strict child-count test would never climb at all.
     *
     * @param DOMElement $parent The candidate wrapper.
     * @param DOMNode $child The node it must be wrapping.
     * @return bool True when replacing $parent would lose nothing but $parent.
     */
    private function wraps_only(DOMElement $parent, DOMNode $child): bool
    {
        foreach ($parent->childNodes as $sibling) {
            if ($sibling === $child || $sibling instanceof DOMComment) {
                continue;
            }

            if ($sibling instanceof DOMText && trim($sibling->wholeText) === '') {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * Extract the YouTube video ID for an embed.
     *
     * Substack writes it in the wrapper's data-attrs JSON and again in the
     * element id ("youtube2-<ID>"), and it is the last path segment of the
     * iframe src. Each has changed shape across editor versions, so every
     * source is tried and the first that validates wins: committing to whichever
     * is merely present lets one garbage value mask a usable sibling.
     *
     * @param list<DOMElement> $chain The embed chain, innermost first.
     * @param string $src The iframe src attribute, '' when no iframe survived.
     * @return string|null The video ID, or null when no source yields a valid one.
     */
    private function youtube_id_from_embed(array $chain, string $src = ''): ?string
    {
        foreach ($this->youtube_id_candidates($chain, $src) as $candidate) {
            // Feed content is attacker-influenced; never interpolate an
            // unvalidated value into the URLs built from it. Exactly 11 to match
            // a real ID, less "videoseries": a playlist embed's path segment is
            // 11 legal chars but has no thumbnail, so it would 404 every sync.
            if ($candidate !== 'videoseries' && preg_match('/^[A-Za-z0-9_-]{11}$/', $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Video-ID candidates for an embed, in order of preference.
     *
     * @param list<DOMElement> $chain The embed chain, innermost first.
     * @param string $src The iframe src attribute, '' when no iframe survived.
     * @return list<string> Unvalidated candidates.
     */
    private function youtube_id_candidates(array $chain, string $src = ''): array
    {
        $candidates = [];

        foreach ($chain as $node) {
            $attrs = json_decode($node->getAttribute('data-attrs'), true);
            if (is_array($attrs) && isset($attrs['videoId']) && is_string($attrs['videoId'])) {
                $candidates[] = $attrs['videoId'];
            }

            // Require the prefix rather than stripping it where present: the
            // chain now climbs any element that wraps nothing but the embed, so
            // an unrelated wrapper's id must not be read as a video ID. Strip
            // the prefix only, since IDs themselves contain "-" and splitting
            // "youtube2-noBX7D2-7hA" on the first hyphen would truncate it.
            if (preg_match('/^youtube\d*-(.+)$/', $node->getAttribute('id'), $matches)) {
                $candidates[] = $matches[1];
            }
        }

        // Path only, so a ?start=30 or a trailing slash never lands in the ID.
        // Skipped rather than yielding '' when the caller had no iframe to read.
        if ($src !== '') {
            $candidates[] = basename(rtrim((string) wp_parse_url($src, PHP_URL_PATH), '/'));
        }

        return $candidates;
    }

    /**
     * The poster-frame URL for a video ID.
     *
     * Shared by the rewrite and by repair_video_featured_images(), which has to
     * recognize a URL this produced earlier; they must not drift apart. It stays
     * the identity of the frame even when the bytes come from the fallback: the
     * source-URL meta records what was asked for, not what answered.
     *
     * maxresdefault is 1280x720. hqdefault is 480x360, a 4:3 box, so a 16:9
     * video comes back letterboxed with roughly a quarter of the image as black
     * bars, and those bars land in the featured slot. maxres exists only for
     * videos uploaded above 720p, hence youtube_thumbnail_fallback_url().
     *
     * @param string $video_id A validated YouTube video ID.
     * @return string The thumbnail URL.
     */
    private function youtube_thumbnail_url(string $video_id): string
    {
        return 'https://img.youtube.com/vi/' . $video_id . '/maxresdefault.jpg';
    }

    /**
     * The always-present poster frame for a video ID.
     *
     * Only YouTube knows whether a given video has a maxres frame, and it
     * answers by 404ing, so the fallback is the retry rather than a guess made
     * up front. hqdefault exists for every video ever uploaded.
     *
     * @param string $video_id A validated YouTube video ID.
     * @return string The fallback thumbnail URL.
     */
    private function youtube_thumbnail_fallback_url(string $video_id): string
    {
        return 'https://img.youtube.com/vi/' . $video_id . '/hqdefault.jpg';
    }

    /**
     * The fallback frame URL for a maxres frame URL, or '' when $src is not one.
     *
     * Both URLs are built from an ID this class already validated against
     * ^[A-Za-z0-9_-]{11}$ and a hardcoded host, so the retry target inherits the
     * is_safe_remote_url() check the caller ran on $src rather than needing its
     * own: no part of either string comes from the feed unfiltered.
     *
     * @param string $src The URL that failed to download.
     * @return string The fallback URL, or '' when there is none.
     */
    private function youtube_thumbnail_fallback_for(string $src): string
    {
        if (strtolower((string) wp_parse_url($src, PHP_URL_HOST)) !== 'img.youtube.com') {
            return '';
        }

        $path = (string) wp_parse_url($src, PHP_URL_PATH);
        if (! preg_match('#^/vi/([A-Za-z0-9_-]{11})/maxresdefault\.jpg$#', $path, $matches)) {
            return '';
        }

        return $this->youtube_thumbnail_fallback_url($matches[1]);
    }

    /**
     * Build the linked-thumbnail replacement for a stripped video embed.
     *
     * Built as DOM nodes for the same reason build_subscribe_node() is: the URL
     * is attribute-set verbatim and never run through a regex engine.
     *
     * @param DOMDocument $doc The document to create the node in.
     * @param string $video_id A validated YouTube video ID.
     * @return DOMElement The thumbnail block.
     */
    private function build_video_thumbnail_node(DOMDocument $doc, string $video_id): DOMElement
    {
        $figure = $doc->createElement('figure');
        $figure->setAttribute('class', 'substack-video-embed');

        $link = $doc->createElement('a');
        $link->setAttribute('href', 'https://www.youtube.com/watch?v=' . $video_id);
        $link->setAttribute('target', '_blank');
        $link->setAttribute('rel', 'noopener noreferrer');

        // No width/height: this markup is written before anything is fetched,
        // and the frame is 1280x720 or, when maxres 404s and the sideload falls
        // back, 480x360. Asserting either one here would stretch the other.
        $img = $doc->createElement('img');
        $img->setAttribute('src', $this->youtube_thumbnail_url($video_id));
        $img->setAttribute('alt', 'Watch the video on YouTube');

        $link->appendChild($img);
        $figure->appendChild($link);

        return $figure;
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
     * identical to what is stored, so WordPress creates no new revision. Writing
     * here separately (as an earlier version did) doubled revisions on every
     * image post, every hour, forever. (This does not stop post_modified from
     * being bumped: wp_insert_post() sets it on every update regardless.)
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
            '<?xml encoding="utf-8"?><div>' . $this->encode_stray_lt($content) . '</div>',
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
                $result = $this->sideload_remote_image($src, $post_id);

                if (is_wp_error($result)) {
                    $failed_downloads++;
                    error_log('Substack Sync: image sideload failed - ' . $result->get_error_message());

                    continue;
                }

                $attachment_id = (int) $result;
                update_post_meta($attachment_id, self::ATTACHMENT_SOURCE_URL_META_KEY, $src);
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
     * Download then media_handle_sideload() so extension-less CDN URLs (Unsplash
     * hotlinks) media_sideload_image() rejects still attach. Returns id/WP_Error.
     */
    private function sideload_remote_image(string $src, int $post_id)
    {
        $tmp = download_url($src);

        // Retry inside the sideload, not in the caller: a missing maxres frame
        // is an expected answer from YouTube, not a failure, and the caller
        // counts every WP_Error against a per-run budget that real body images
        // depend on. Once only, and only for a frame URL this class built.
        if (is_wp_error($tmp)) {
            $fallback = $this->youtube_thumbnail_fallback_for($src);
            if ($fallback !== '') {
                $tmp = download_url($fallback);
            }
        }

        if (is_wp_error($tmp)) {
            return $tmp;
        }

        // Named from $src, not from whatever answered, so a video's frame keeps
        // one name in the library whichever resolution it came back at.
        $filename = $this->filename_for_sideload($src, (string) $tmp);
        if ($filename === '') {
            @unlink($tmp);

            return new WP_Error(
                'substack_sync_unrecognized_image',
                'Downloaded file is not a supported image type: ' . $src
            );
        }

        $file_array = ['name' => $filename, 'tmp_name' => $tmp];
        $attachment_id = media_handle_sideload($file_array, $post_id);

        if (is_wp_error($attachment_id)) {
            // media_handle_sideload() deletes tmp itself when it moves the file;
            // unlink covers the WP_Error path where it never got that far.
            @unlink($file_array['tmp_name']);

            return $attachment_id;
        }

        return (int) $attachment_id;
    }

    /**
     * Sideload filename with a real extension sniffed from the bytes (the URL
     * may carry none), or '' when the download is not a supported image.
     */
    private function filename_for_sideload(string $src, string $tmp_file): string
    {
        $ext = '';

        $info = @getimagesize($tmp_file);
        if (is_array($info) && isset($info[2])) {
            $ext = ltrim((string) image_type_to_extension($info[2], false), '.');
        }

        // Fall back to an extension carried by the URL path itself.
        if ($ext === '') {
            $path_ext = strtolower(pathinfo((string) wp_parse_url($src, PHP_URL_PATH), PATHINFO_EXTENSION));
            if (in_array($path_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                $ext = $path_ext;
            }
        }

        if ($ext === '') {
            return '';
        }
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }

        $path = (string) wp_parse_url($src, PHP_URL_PATH);

        // Every YouTube frame lives at /vi/<ID>/<size>.jpg, so the basename alone
        // would fill the library with maxresdefault-1, maxresdefault-2, and so
        // on, one per video post. Name them by the video they came from.
        if (
            strtolower((string) wp_parse_url($src, PHP_URL_HOST)) === 'img.youtube.com'
            && preg_match('#^/vi/([A-Za-z0-9_-]{11})/#', $path, $matches)
        ) {
            return sanitize_file_name('youtube-' . $matches[1] . '.' . $ext);
        }

        $base = wp_basename($path);
        $name = $base !== '' ? (string) preg_replace('/\.[^.]*$/', '', $base) : '';
        if (trim($name) === '') {
            $name = 'substack-image';
        }

        return sanitize_file_name($name . '.' . $ext);
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
                self::ATTACHMENT_SOURCE_URL_META_KEY,
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
     * Record the canonical Substack URL for an imported/updated post as public
     * post meta, so front-end templates (e.g. an Elementor Loop Grid with a
     * dynamic Custom Field tag) can link each post back to its Substack
     * original. The key is intentionally non-underscore/public so it shows up
     * directly in Elementor's dynamic-field picker.
     *
     * SimplePie's get_permalink() is the feed item's <link> (the Substack post
     * URL). Sanitize first, then skip empty results so neither a link-less feed
     * item nor one whose link esc_url_raw() rejects (bad scheme, malformed)
     * ever clobbers a previously-stored URL with ''.
     *
     * @param int $post_id The WordPress post ID.
     * @param SimplePie_Item $item The feed item being imported/updated.
     */
    private function store_source_url(int $post_id, $item): void
    {
        $url = esc_url_raw(trim((string) $item->get_permalink()));
        if ($url !== '') {
            update_post_meta($post_id, self::SOURCE_URL_META_KEY, $url);
        }
    }

    /**
     * One-time backfill of the Substack source-URL meta for posts imported
     * before store_source_url() existed. The sync-log table already holds the
     * Substack GUID (which, for Substack feeds, is the post URL) keyed to
     * post_id, so we mirror it into post meta without refetching the feed.
     * Older posts that have aged out of the RSS window would otherwise never
     * get the meta, since only feed-present posts pass back through
     * update_post().
     *
     * Note the source differs from the live path: this mirrors the stored guid
     * (SimplePie get_id()), while store_source_url() writes get_permalink().
     * They are the same value for Substack feeds, but the backfilled URL is not
     * re-verified against get_permalink(). A post still present in the feed
     * self-corrects on its next sync; an aged-out post keeps the guid value.
     *
     * Idempotent and gated by an option flag: safe to call on every admin load;
     * does real work only once. Guards each GUID with a URL check so a
     * non-URL GUID (not expected from Substack, but cheap to defend) is skipped
     * rather than stored as a bogus link.
     *
     * @return int Number of posts backfilled.
     */
    public function backfill_source_urls(): int
    {
        if (get_option(self::SOURCE_URL_BACKFILL_OPTION)) {
            return 0;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'substack_sync_log';

        $rows = $wpdb->get_results(
            "SELECT post_id, substack_guid FROM $table_name WHERE post_id > 0",
            ARRAY_A
        );

        // $wpdb->get_results() returns null on a query error (vs. an empty array
        // when there is genuinely nothing to backfill). Bail without setting the
        // done flag so a transient DB failure doesn't permanently skip the
        // backfill: admin_init retries it on the next load.
        if ($rows === null) {
            return 0;
        }

        $backfilled = 0;
        foreach ($rows as $row) {
            $post_id = (int) ($row['post_id'] ?? 0);
            $guid = trim((string) ($row['substack_guid'] ?? ''));

            if ($post_id <= 0 || filter_var($guid, FILTER_VALIDATE_URL) === false) {
                continue;
            }
            if ((string) get_post_meta($post_id, self::SOURCE_URL_META_KEY, true) !== '') {
                continue;
            }

            $url = esc_url_raw($guid);
            if ($url === '') {
                continue;
            }

            update_post_meta($post_id, self::SOURCE_URL_META_KEY, $url);
            $backfilled++;
        }

        update_option(self::SOURCE_URL_BACKFILL_OPTION, true);

        return $backfilled;
    }

    /**
     * One-time repair of featured images on video posts imported while the
     * YouTube embed rewrite was not reaching them, which is every version
     * through 1.3.1: the rewrite shipped in 1.3.0 but matched on an iframe that
     * fetch_feed() had already stripped, so it never fired on an imported post.
     *
     * Those posts had no <img> at all in their stored content, because kses had
     * eaten the iframe, so process_post_images() took the featured image from
     * whatever body photo appeared further down. A later sync rewrites their
     * content to lead with the video thumbnail but cannot fix the thumbnail
     * itself: set_post_thumbnail() is gated on ! has_post_thumbnail(), which is
     * what stops ordinary syncs from overriding an image an editor chose.
     *
     * Only posts still in the feed can be repaired, since the repair matches on
     * content a sync rewrote. A video post that has aged out of the feed keeps
     * its wrong featured image and needs its featured image set by hand: no sync
     * reprocesses a post the feed no longer carries, so clearing the thumbnail
     * only leaves the post without one.
     *
     * Idempotent and option-flag gated, like backfill_source_urls(), but unlike
     * that pass this one can find work it is not yet able to finish, so the flag
     * waits: for a frame that has not been sideloaded yet, and for a post no
     * sync has rewritten yet. It waits only on posts it could actually repair,
     * since the report it leaves behind is a worklist for a person. Waiting
     * forever is its own failure, so the wait ends after
     * VIDEO_THUMBNAIL_REPAIR_MAX_ATTEMPTS hourly syncs that repaired
     * nothing. Any repair restarts that count, so a pass still making progress
     * is never cut off. Giving up records the post IDs it left behind, which the
     * settings screen reads back: an outcome only an error log knows about is one
     * the site owner never sees.
     *
     * @return int Number of posts repaired.
     */
    public function repair_video_featured_images(): int
    {
        if (get_option(self::VIDEO_THUMBNAIL_REPAIR_OPTION)) {
            return 0;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'substack_sync_log';

        $rows = $wpdb->get_results(
            "SELECT DISTINCT post_id FROM $table_name WHERE post_id > 0",
            ARRAY_A
        );

        // null is a query error, an empty array is nothing to repair: bail
        // without the flag so a transient DB failure retries on the next sync.
        if ($rows === null) {
            return 0;
        }

        $repaired = 0;
        $deferred = 0;
        $deferred_ids = [];

        foreach ($rows as $row) {
            $post_id = (int) ($row['post_id'] ?? 0);
            if ($post_id <= 0) {
                continue;
            }

            // Null-safe: a log row outlives the post it names once someone
            // deletes that post, and reading through null is a warning, not a
            // value. Empty content is correctly nothing to repair.
            $post = get_post($post_id);
            $content = (string) ($post?->post_content ?? '');

            $video_id = $this->leading_video_id($content);
            if ($video_id === null) {
                // A post no sync has rewritten still carries Substack's
                // wrapper, emptied by kses, and reading that as "nothing to
                // repair" is what forfeited such posts. Defer it only if the
                // pass would actually repair it, which both helpers can answer
                // without the frame: otherwise the give-up worklist asks a
                // person to go fix posts the pass skips by design.
                if (
                    $this->wrapper_leads_images($content)
                    && $this->thumbnail_is_replaceable((int) get_post_thumbnail_id($post_id))
                ) {
                    $deferred++;
                    $this->record_deferred($deferred_ids, $post_id);
                }

                continue;
            }

            // Resolved through the source-URL meta, so the attachment is provably
            // one this plugin sideloaded rather than anything matching by filename.
            $attachment_id = $this->find_attachment_by_source($this->youtube_thumbnail_url($video_id));
            if ($attachment_id <= 0) {
                // A video post whose frame is not in the library yet. Sideloading
                // is bounded per run and can fail on a transient network error,
                // so this is work outstanding, not work absent.
                $deferred++;
                $this->record_deferred($deferred_ids, $post_id);

                continue;
            }

            $current = (int) get_post_thumbnail_id($post_id);
            if ($current === $attachment_id) {
                continue;
            }

            if (! $this->thumbnail_is_replaceable($current)) {
                continue;
            }

            set_post_thumbnail($post_id, $attachment_id);
            $repaired++;
        }

        // Only once the pass has nothing left to do. Flagging while a frame is
        // still waiting on its sideload would forfeit that post permanently.
        if ($deferred === 0) {
            update_option(self::VIDEO_THUMBNAIL_REPAIR_OPTION, true);
            delete_option(self::VIDEO_THUMBNAIL_REPAIR_ATTEMPTS_OPTION);
            delete_option(self::VIDEO_THUMBNAIL_REPAIR_ADVANCED_OPTION);
            delete_option(self::VIDEO_THUMBNAIL_REPAIR_UNREPAIRED_OPTION);

            return $repaired;
        }

        // Outstanding is not the same as reachable: a deleted video's frame 404s
        // on every sync and a post aged out of the feed is never rewritten, so
        // waiting on either rescans the log table for the life of the site. What
        // bounds the wait is stalling, not elapsed syncs, because one such post
        // would otherwise spend the whole budget and forfeit the posts still
        // arriving behind it, which is what this pass exists to prevent.
        if ($repaired > 0) {
            delete_option(self::VIDEO_THUMBNAIL_REPAIR_ATTEMPTS_OPTION);
            delete_option(self::VIDEO_THUMBNAIL_REPAIR_ADVANCED_OPTION);

            return $repaired;
        }

        // The budget is spent in syncs and Sync Now drives a sync on demand, so
        // an owner clicking it a few times while diagnosing something would
        // otherwise end the pass in a minute. Advance at most hourly: that is
        // the cron's own cadence, and it is the interval a stalled sideload
        // needs before retrying is worth anything. A timestamp in the future is
        // a clock that moved, not a recent advance, so it does not hold the
        // counter back.
        $now = time();
        $advanced_at = (int) get_option(self::VIDEO_THUMBNAIL_REPAIR_ADVANCED_OPTION, 0);

        if ($advanced_at > 0 && $advanced_at <= $now && ($now - $advanced_at) < HOUR_IN_SECONDS) {
            return $repaired;
        }

        $attempts = (int) get_option(self::VIDEO_THUMBNAIL_REPAIR_ATTEMPTS_OPTION, 0) + 1;

        if ($attempts < self::VIDEO_THUMBNAIL_REPAIR_MAX_ATTEMPTS) {
            update_option(self::VIDEO_THUMBNAIL_REPAIR_ATTEMPTS_OPTION, $attempts);
            update_option(self::VIDEO_THUMBNAIL_REPAIR_ADVANCED_OPTION, $now);

            return $repaired;
        }

        // Post IDs, not just a count: a count tells the owner something is wrong
        // and leaves them no way to find it. The same report goes into an option
        // so the settings screen can name the posts and offer the rerun, since an
        // error log is not a place a site owner looks. The exact count rides
        // along because $deferred_ids is capped and a capped list rendered as a
        // total would tell the owner their backlog is smaller than it is.
        error_log(sprintf(
            'Substack Sync: stopping the video featured-image repair after %d syncs with no progress and %d '
            . 'post(s) unrepaired (post IDs: %s). Set their featured image by hand, or use '
            . '"Run Video Repair Again" on the plugin settings screen.',
            $attempts,
            $deferred,
            implode(', ', $deferred_ids)
        ));

        update_option(self::VIDEO_THUMBNAIL_REPAIR_OPTION, true);
        update_option(self::VIDEO_THUMBNAIL_REPAIR_UNREPAIRED_OPTION, [
            'count' => $deferred,
            'ids' => $deferred_ids,
        ]);
        delete_option(self::VIDEO_THUMBNAIL_REPAIR_ATTEMPTS_OPTION);
        delete_option(self::VIDEO_THUMBNAIL_REPAIR_ADVANCED_OPTION);

        return $repaired;
    }

    /**
     * Add a post to the give-up report, up to the cap.
     *
     * Bounded because the report is a worklist for a person. The deferred
     * *count* stays exact; only the list of IDs is truncated.
     *
     * @param list<int> $deferred_ids The report so far, modified in place.
     * @param int $post_id The post being deferred.
     */
    private function record_deferred(array &$deferred_ids, int $post_id): void
    {
        if (count($deferred_ids) < self::VIDEO_THUMBNAIL_REPAIR_MAX_REPORTED) {
            $deferred_ids[] = $post_id;
        }
    }

    /**
     * Post IDs the video repair gave up on, for the admin screen.
     *
     * @return list<int> Post IDs, empty when the pass finished or never ran.
     */
    public function get_unrepaired_video_posts(): array
    {
        return $this->unrepaired_video_report()['ids'];
    }

    /**
     * How many posts the video repair gave up on, which is not always how many
     * it can name: VIDEO_THUMBNAIL_REPAIR_MAX_REPORTED caps the list.
     *
     * @return int Posts left unrepaired, 0 when the pass finished or never ran.
     */
    public function get_unrepaired_video_count(): int
    {
        return $this->unrepaired_video_report()['count'];
    }

    /**
     * The stored give-up report, normalized.
     *
     * @return array{count: int, ids: list<int>}
     */
    private function unrepaired_video_report(): array
    {
        $stored = get_option(self::VIDEO_THUMBNAIL_REPAIR_UNREPAIRED_OPTION, []);

        if (! is_array($stored)) {
            return ['count' => 0, 'ids' => []];
        }

        $ids = array_values(array_filter(array_map('intval', (array) ($stored['ids'] ?? []))));

        // A count below the list length would render as "3 of 2", so the list is
        // the floor.
        $count = max((int) ($stored['count'] ?? 0), count($ids));

        // A snapshot worklist goes stale the moment a person acts on an entry, so
        // drop the resolved ones: post deleted, or a featured image this pass may
        // no longer replace, which is what an editor's own choice looks like. The
        // total comes down with them, or a filtered list against a whole total
        // renders as "1 of 3" and understates nothing that is left.
        $outstanding = [];
        foreach ($ids as $id) {
            if (get_post($id) === null) {
                continue;
            }
            if (! $this->thumbnail_is_replaceable((int) get_post_thumbnail_id($id))) {
                continue;
            }

            $outstanding[] = $id;
        }

        return [
            'count' => max($count - (count($ids) - count($outstanding)), count($outstanding)),
            'ids' => $outstanding,
        ];
    }

    /**
     * Bring a site's stored state forward after a plugin update.
     *
     * 1.3.0 and 1.3.1 shipped a video rewrite that never fired, so the repair
     * pass read a site with unrewritten video posts as a site with nothing to
     * repair and set its done flag. Without this, 1.3.2 would fix the content
     * and leave every existing video post's featured image on the wrong photo,
     * with no way to reach the pass again: 1.3.0 set the flag on its first sync
     * without recording a worklist, and the settings screen renders the rerun
     * button only where a worklist exists. Re-arm so the upgrade finishes
     * itself rather than waiting on a click that has nowhere to appear.
     *
     * The give-up report survives, for the reason rearm_video_thumbnail_repair()
     * gives: re-arming is a side effect here, and until the pass finishes the
     * report still names posts that are still unrepaired.
     *
     * The version arrives as an argument rather than being read from
     * SUBSTACK_SYNC_VERSION, so the upgrade can be exercised without the plugin
     * bootstrap defining it.
     *
     * @param string $version The running plugin version.
     */
    public function maybe_upgrade(string $version): void
    {
        $stored = (string) get_option(self::UPGRADED_VERSION_OPTION, '');

        if ($stored === $version) {
            return;
        }

        // '' is a site from before this option existed, which is every site that
        // ever ran the broken rewrite, so it re-arms like the rest. On a fresh
        // install the deletes are no-ops.
        if (version_compare($stored, self::VIDEO_REWRITE_FIXED_VERSION, '<')) {
            $this->rearm_video_thumbnail_repair();
        }

        update_option(self::UPGRADED_VERSION_OPTION, $version);
    }

    /**
     * Put the video featured-image repair back in play, keeping the report.
     *
     * Used where re-arming is a side effect of something else, so the give-up
     * report and the admin section it feeds have to survive: it is a worklist
     * for a person, and the pass takes several syncs to rebuild it. The pass
     * clears it itself once it finishes or gives up again.
     */
    private function rearm_video_thumbnail_repair(): void
    {
        delete_option(self::VIDEO_THUMBNAIL_REPAIR_OPTION);
        delete_option(self::VIDEO_THUMBNAIL_REPAIR_ATTEMPTS_OPTION);
        delete_option(self::VIDEO_THUMBNAIL_REPAIR_ADVANCED_OPTION);
    }

    /**
     * Put the video featured-image repair back in play and drop the report.
     *
     * The counterpart to the pass giving up. Clearing the flag by hand meant a
     * database query, which put the recovery path out of reach of the person the
     * give-up message was addressed to. Drops the report because this is the
     * path a person takes by clicking the button the report itself offers: they
     * have read the list, so the stale copy of it is noise.
     *
     * @return bool True when this cleared state a stopped pass had left behind.
     */
    public function restart_video_thumbnail_repair(): bool
    {
        // A standing report counts, not just the flag. reset_failed_posts()
        // clears the flag on its own and leaves the report up, so a report with
        // no flag is still a stopped pass's leftovers, and reading the flag
        // alone let this drop the owner's worklist while answering that nothing
        // had happened.
        $had_stopped_state = (bool) get_option(self::VIDEO_THUMBNAIL_REPAIR_OPTION)
            || $this->unrepaired_video_report()['ids'] !== [];

        $this->rearm_video_thumbnail_repair();
        delete_option(self::VIDEO_THUMBNAIL_REPAIR_UNREPAIRED_OPTION);

        return $had_stopped_state;
    }

    /**
     * Whether the video repair has stopped, as opposed to being armed and due.
     *
     * The admin section survives a re-arm on purpose, so it cannot describe the
     * pass as stopped on the strength of the report alone.
     *
     * @return bool True when the pass will not run again without a restart.
     */
    public function video_repair_has_stopped(): bool
    {
        return (bool) get_option(self::VIDEO_THUMBNAIL_REPAIR_OPTION);
    }

    /**
     * Whether an attachment is one this plugin sideloaded.
     *
     * @param int $attachment_id The attachment ID.
     * @return bool True when the attachment carries a recorded source URL.
     */
    private function is_sideloaded_attachment(int $attachment_id): bool
    {
        return (string) get_post_meta($attachment_id, self::ATTACHMENT_SOURCE_URL_META_KEY, true) !== '';
    }

    /**
     * The video ID behind a post's leading image, or null when its first image
     * is not a video frame.
     *
     * "Leading" is the whole point: this fires only when the video figure holds
     * the post's first image, which is the rule process_post_images() already
     * applies when picking a featured image, so the repair can never promote a
     * video frame past a photo that legitimately comes first.
     *
     * Deliberately stops at the ID rather than resolving the attachment: the
     * caller has to tell "this post needs no repair" apart from "this post
     * needs one and its frame has not landed in the library yet", and a single
     * 0 return collapses those into each other.
     *
     * Takes content rather than a post ID because the caller has to ask a second
     * question of the same string (wrapper_leads_images()) and must not pay for
     * a second get_post() to do it.
     *
     * @param string $content The post's stored content.
     * @return string|null The video ID, or null when there is nothing to repair.
     */
    private function leading_video_id(string $content): ?string
    {
        if (stripos($content, 'substack-video-embed') === false) {
            return null;
        }

        $doc = new DOMDocument();
        $loaded = @$doc->loadHTML(
            '<?xml encoding="utf-8"?><div>' . $this->encode_stray_lt($content) . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        if (! $loaded) {
            return null;
        }

        $first = $doc->getElementsByTagName('img')->item(0);
        if (! $first instanceof DOMElement) {
            return null;
        }

        return $this->leading_embed_video_id($first);
    }

    /**
     * Where content carries a video embed no sync has rewritten, or null.
     *
     * wp_kses_post() ate the <iframe> and left Substack's wrapper standing, so
     * a legacy video post is an empty div carrying the wrapper class, the
     * "youtube<n>-<ID>" element id, and the data-attrs JSON. The live rewrite
     * reads the same three signals, since Substack's markup changes across
     * editor versions and a post archive spans however many versions the site
     * has been importing through. Neither pass rests on any one of them: this
     * one asks all three and takes whichever appears first,
     * because missing a legacy post is what forfeits it and the errors are not
     * symmetric. Presence is the whole question here, so unlike the ID
     * extraction on the live path these tests validate nothing.
     *
     * An offset rather than a bool because the caller has to know whether the
     * wrapper leads the post's images, which is one of the two conditions the
     * repair itself gates on.
     *
     * A false positive costs a deferred count, which the attempt cap bounds, and
     * an entry on the give-up worklist, which asks a person to look at a post
     * that may be fine. The data-attrs test is the loosest of the three and will
     * also match a non-YouTube embed carrying a videoId, which is the trade it
     * is making.
     *
     * @param string $content The post's stored content.
     * @return int|null Offset of the earliest wrapper signal, or null when there
     *                  is no unrewritten embed.
     */
    private function unrewritten_wrapper_offset(string $content): ?int
    {
        if (stripos($content, 'substack-video-embed') !== false) {
            return null;
        }

        $offsets = [];

        // Anchored on token boundaries inside a class attribute, like the id test
        // below: "youtube-wrap" is a prefix of any number of unrelated class
        // names, and a post matched on one can never grow the figure that would
        // clear it, so it defers until the attempt cap runs out.
        if (
            preg_match(
                '/class=["\'][^"\']*(?<![-\w])youtube-wrap(?![-\w])/i',
                $content,
                $class_match,
                PREG_OFFSET_CAPTURE
            ) === 1
        ) {
            $offsets[] = (int) $class_match[0][1];
        }

        // Anchored on an attribute boundary so data-id="youtube1-..." on some
        // unrelated element is not read as the wrapper, and quote-agnostic
        // because nothing guarantees which one wrote the stored content.
        if (preg_match('/(?<![-\w])id=["\']youtube\d*-/i', $content, $id_match, PREG_OFFSET_CAPTURE) === 1) {
            $offsets[] = (int) $id_match[0][1];
        }

        // kses keeps data-* attributes, so the wrapper's payload survives even
        // when the class and the id prefix are both from a shape we do not know.
        if (preg_match('/(?<![-\w])data-attrs=["\'][^"\']*videoId/i', $content, $attrs_match, PREG_OFFSET_CAPTURE) === 1) {
            $offsets[] = (int) $attrs_match[0][1];
        }

        return $offsets === [] ? null : min($offsets);
    }

    /**
     * Whether an unrewritten wrapper sits ahead of the content's first image.
     *
     * The repair only promotes a frame when the video holds the post's first
     * image, so a legacy post whose embed trails a body photo is one the pass
     * would skip even once the post is rewritten. Asking here keeps such a post
     * off the give-up worklist instead of telling its owner to fix a post that
     * is behaving as designed.
     *
     * Offsets on the stored string rather than a DOM walk: kses took the iframe,
     * so the wrapper has no node the image order could be read from, and for
     * these two tags source order is document order.
     *
     * @param string $content The post's stored content.
     * @return bool True when an unrewritten wrapper precedes every image.
     */
    private function wrapper_leads_images(string $content): bool
    {
        $wrapper = $this->unrewritten_wrapper_offset($content);
        if ($wrapper === null) {
            return false;
        }

        $first_image = stripos($content, '<img');

        return $first_image === false || $wrapper < $first_image;
    }

    /**
     * Whether the repair may replace a post's current featured image.
     *
     * Ordinary syncs are gated on ! has_post_thumbnail() so an image from
     * outside this plugin survives; the pass runs without that gate, so it needs
     * its own. The gate is narrower than "never override a person": an
     * attachment carrying a source URL is one this plugin sideloaded, and an
     * editor who picked a different Substack body photo out of the library is
     * indistinguishable from the plugin having set it. Uploads from anywhere
     * else are safe.
     *
     * @param int $current The post's current thumbnail ID, 0 when unset.
     * @return bool True when nothing a person chose would be lost.
     */
    private function thumbnail_is_replaceable(int $current): bool
    {
        return $current <= 0 || $this->is_sideloaded_attachment($current);
    }

    /**
     * The video ID for an image, when that image is a video figure's thumbnail.
     *
     * Read from the watch href rather than the img src: localization rewrites
     * the src to the local copy, while the href keeps the ID verbatim.
     *
     * @param DOMElement $img The post's first image.
     * @return string|null The video ID, or null when this is not a video figure.
     */
    private function leading_embed_video_id(DOMElement $img): ?string
    {
        $link = $img->parentNode;
        if (! $link instanceof DOMElement || $link->nodeName !== 'a') {
            return null;
        }

        $figure = $link->parentNode;
        if (! $figure instanceof DOMElement || stripos($figure->getAttribute('class'), 'substack-video-embed') === false) {
            return null;
        }

        parse_str((string) wp_parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
        $video_id = is_string($query['v'] ?? null) ? $query['v'] : '';

        return preg_match('/^[A-Za-z0-9_-]{11}$/', $video_id) ? $video_id : null;
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
     * Get posts that failed to sync.
     *
     * Every errored row, including the ones past the retry ceiling. Filtering
     * those out hid exactly the posts that need a person: a row at
     * retry_count >= the ceiling is one no sync will pick up again. Named for
     * failure rather than for retry because that is the point: the rows this
     * returns are no longer all rows a sync will retry.
     *
     * Newest first, because nothing filters the result any more: oldest-first
     * fills the 200-row window with the permanently exhausted backlog and
     * pushes out the recent failures somebody is actually diagnosing. The
     * window bounds the display only; reset_failed_posts() is not bounded.
     *
     * @return array<array<string, mixed>> Failed posts.
     */
    public function get_failed_posts(): array
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'substack_sync_log';

        // Bounded: this feeds an admin display list, and an unbounded result
        // set over a large error backlog serves no one. No prepare(): the
        // retry_count placeholder was the only one, and prepare() with nothing
        // to bind is a _doing_it_wrong() notice in current WordPress.
        return $wpdb->get_results(
            "
                SELECT substack_guid, substack_title, retry_count, error_message
                FROM $table_name
                WHERE status = 'error'
                ORDER BY sync_date DESC
                LIMIT 200
            ",
            ARRAY_A
        );
    }

    /**
     * Reset retry state for every failed post in one query.
     *
     * Replaces a per-row reset loop: one UPDATE instead of N SELECT+UPDATE
     * round trips, and no unbounded row fetch just to walk it.
     *
     * No retry_count bound. should_skip_post() stops retrying at
     * retry_count >= $max_retries, so a "retry_count < $max_retries" filter here
     * reset every row that was going to be retried anyway and skipped the only
     * rows a person clicking Retry Failed Posts can be asking about. Those posts
     * were reachable by no path at all.
     *
     * @return int|null Rows reset, or null when the database rejected the query.
     */
    public function reset_failed_posts(): ?int
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'substack_sync_log';

        // No prepare() here either: the retry_count placeholder was the only
        // one this query had, and prepare() with nothing to bind is a
        // _doing_it_wrong() notice in current WordPress.
        $updated = $wpdb->query(
            "UPDATE $table_name SET retry_count = 0, status = 'pending' WHERE status = 'error'"
        );

        // null, not 0: $wpdb::query() answers false on error, and collapsing that
        // into "no rows matched" told an owner their backlog was empty on the one
        // occasion the query never ran.
        if ($updated === false) {
            error_log('Substack Sync: the database rejected the failed-post retry reset');

            return null;
        }

        // A reset puts posts back in reach of the sync loop, which is the one
        // thing that lets the repair see a video post it previously counted as
        // having no video. Let it look again, but re-arm rather than restart:
        // this button is on another tab and gets pressed about failures with
        // nothing to do with video, and dropping the give-up report would take
        // the owner's worklist and the button offering to rerun it off the
        // screen for as long as the pass needs to rebuild them.
        if ((int) $updated > 0) {
            $this->rearm_video_thumbnail_repair();
        }

        return (int) $updated;
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
