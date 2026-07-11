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
     * @return array<string, mixed>|void Status information if requested.
     */
    public function run_sync(bool $return_status = false)
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

        $feed = fetch_feed($this->settings['feed_url']);

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
        $post_title = $item->get_title();

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

        $feed = fetch_feed($this->settings['feed_url']);

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
     * Check if a post with the given GUID already exists.
     *
     * @param string $guid The Substack post GUID.
     * @return array<string, mixed>|null The existing post data or null.
     */
    private function get_existing_post(string $guid): ?array
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'substack_sync_log';

        $result = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table_name WHERE substack_guid = %s", $guid),
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
            $this->process_post_images($post_id, $post_data['post_content']);

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

        $post_id = wp_update_post($post_data);

        if ($post_id && ! is_wp_error($post_id)) {
            $this->log_sync($post_id, $guid, 'updated', $post_title);
            $this->process_post_images($post_id, $post_data['post_content']);

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
        // Replace Substack-specific elements with subscription links
        $subscription_link = sprintf(
            '<div class="substack-subscribe-block"><a href="%s" target="_blank">Subscribe to our newsletter</a></div>',
            esc_url($this->settings['feed_url'] ?? '')
        );

        // Remove or replace Substack interactive elements. preg_replace returns
        // null on PCRE failure (e.g. backtrack limit exhausted by pathological
        // feed markup); fall back to the prior content so the next call is never
        // handed null (a TypeError on PHP 8.1+) and we don't silently blank the post.
        $replaced = preg_replace('/<div[^>]*class="[^"]*subscription[^"]*"[^>]*>.*?<\/div>/is', $subscription_link, $content);
        $content = $replaced ?? $content;
        $replaced = preg_replace('/<div[^>]*class="[^"]*like-button[^"]*"[^>]*>.*?<\/div>/is', '', $content);
        $content = $replaced ?? $content;

        return $content;
    }

    /**
     * Process and import images from post content.
     *
     * @param int $post_id The WordPress post ID.
     * @param string $content The post content.
     */
    private function process_post_images(int $post_id, string $content): void
    {
        if (trim($content) === '') {
            return;
        }

        // media_sideload_image() and its helpers live in wp-admin/includes and
        // are NOT autoloaded on the cron (wp-cron.php) or admin-ajax paths, so
        // the call would be an undefined-function fatal without these requires.
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $doc = new DOMDocument();
        @$doc->loadHTML($content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $images = $doc->getElementsByTagName('img');
        $first_image_set = false;

        foreach ($images as $img) {
            $src = $img->getAttribute('src');
            if (empty($src) || ! filter_var($src, FILTER_VALIDATE_URL)) {
                continue;
            }

            // Feed content is attacker-influenced. filter_var only checks URL
            // syntax, so an <img src="http://169.254.169.254/..."> or an
            // RFC1918/loopback target would otherwise be fetched server-side
            // (SSRF). Only sideload images from public http(s) hosts.
            if (! $this->is_safe_remote_url($src)) {
                error_log('Substack Sync: skipped unsafe image URL - ' . $src);

                continue;
            }

            $attachment_id = media_sideload_image($src, $post_id, '', 'id');

            if (! is_wp_error($attachment_id) && ! $first_image_set) {
                set_post_thumbnail($post_id, $attachment_id);
                $first_image_set = true;
            }
        }
    }

    /**
     * Whether a URL is safe to fetch server-side.
     *
     * Requires an http(s) scheme, no embedded credentials, and a host that
     * resolves only to public IP addresses (no private, reserved, loopback, or
     * link-local ranges). Best-effort SSRF guard for untrusted feed content;
     * it cannot defeat DNS-rebinding, but blocks the direct internal-target
     * and cloud-metadata cases.
     *
     * @param string $url The candidate image URL.
     * @return bool True when the URL is safe to sideload.
     */
    private function is_safe_remote_url(string $url): bool
    {
        $parts = wp_parse_url($url);

        if (empty($parts['host'])) {
            return false;
        }
        if (empty($parts['scheme']) || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = $parts['host'];
        $ips = [];

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            if (strtolower($host) === 'localhost' || substr(strtolower($host), -6) === '.local') {
                return false;
            }
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (! empty($record['ip'])) {
                        $ips[] = $record['ip'];
                    } elseif (! empty($record['ipv6'])) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
        }

        // If we cannot resolve the host to any address, refuse rather than
        // hand an unverifiable target to download_url().
        if (empty($ips)) {
            return false;
        }

        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
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

        return $wpdb->get_results(
            $wpdb->prepare("
                SELECT substack_guid, substack_title, retry_count, error_message 
                FROM $table_name 
                WHERE status = 'error' AND retry_count < %d 
                ORDER BY sync_date ASC
            ", $max_retries),
            ARRAY_A
        );
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
     * Reset retry count for a specific post.
     *
     * @param string $guid The Substack GUID.
     * @return bool True if reset successfully.
     */
    public function reset_post_retry_count(string $guid): bool
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'substack_sync_log';

        return $wpdb->update(
            $table_name,
            ['retry_count' => 0, 'status' => 'pending'],
            ['substack_guid' => $guid],
            ['%d', '%s'],
            ['%s']
        ) !== false;
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

        $post_ids = $wpdb->get_col("SELECT post_id FROM $table_name WHERE post_id > 0");
        $deleted_count = 0;

        foreach ($post_ids as $post_id) {
            if (wp_delete_post($post_id, true)) {
                $deleted_count++;
            }
        }

        // Clear the sync log
        $wpdb->query("DELETE FROM $table_name");

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

        $post_ids = $wpdb->get_col("SELECT post_id FROM $table_name WHERE status = 'error' AND post_id > 0");
        $deleted_count = 0;

        foreach ($post_ids as $post_id) {
            if (wp_delete_post($post_id, true)) {
                $deleted_count++;
            }
        }

        // Remove failed entries from log
        $wpdb->delete($table_name, ['status' => 'error'], ['%s']);

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

        $post_ids = $wpdb->get_col(
            $wpdb->prepare("
                SELECT post_id 
                FROM $table_name 
                WHERE post_id > 0 
                AND sync_date BETWEEN %s AND %s
            ", $date_from . ' 00:00:00', $date_to . ' 23:59:59')
        );

        $deleted_count = 0;

        foreach ($post_ids as $post_id) {
            if (wp_delete_post($post_id, true)) {
                $deleted_count++;
            }
        }

        // Remove entries from log
        $wpdb->query(
            $wpdb->prepare("
                DELETE FROM $table_name 
                WHERE sync_date BETWEEN %s AND %s
            ", $date_from . ' 00:00:00', $date_to . ' 23:59:59')
        );

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

        if (empty($category_mappings)) {
            return $assigned_categories;
        }

        foreach ($category_mappings as $mapping) {
            if (! is_array($mapping)) {
                continue;
            }

            // Use a strict emptiness check, matching the sanitizer, so a keyword
            // of literally "0" is honored rather than silently dropped by
            // empty('0') === true (which would store dead, never-matching config).
            $keyword = is_scalar($mapping['keyword'] ?? null) ? strtolower(trim((string) $mapping['keyword'])) : '';
            $category_id = absint($mapping['category'] ?? 0);
            if ($keyword === '' || $category_id <= 0) {
                continue;
            }

            $content_lower = strtolower($content);

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
