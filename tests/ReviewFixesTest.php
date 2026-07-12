<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the 2026 adversarial review fixes (feed cache, sync
 * lock, failed-import retry routing, DOM-based content cleanup, SSRF ranges,
 * bounded image sideloading, category matching, AJAX handler consolidation).
 *
 * Behavioral where the stubs allow it; source-level assertions (matching the
 * style of SecurityFixesTest) where the fix lives in SQL or WP plumbing the
 * stubs cannot execute meaningfully.
 */
class ReviewFixesTest extends TestCase
{
    private static string $processorSource;
    private static string $adminSource;

    public static function setUpBeforeClass(): void
    {
        self::$processorSource = file_get_contents(dirname(__DIR__) . '/includes/class-substack-sync-processor.php');
        self::$adminSource = file_get_contents(dirname(__DIR__) . '/admin/class-substack-sync-admin.php');
    }

    protected function setUp(): void
    {
        global $_wp_options, $_wp_transients, $_wp_deleted_transients, $_wp_added_filters,
            $_wp_removed_filters, $_wp_sideload_calls, $_wp_sideload_fail, $_wp_thumbnails,
            $_wp_post_id_counter, $_wp_posts, $_wp_post_meta, $_wp_site_transients,
            $_wp_deleted_site_transients, $_wp_json_responses, $_wp_missing_attachments;

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

    // ---------------------------------------------------------------
    // Failed-import retry routing
    //
    // A failed import logs post_id 0. Treating that row as an existing
    // post routed the retry through update_post() with ID 0, which can
    // never succeed, so failed imports never actually retried.
    // ---------------------------------------------------------------

    public function test_get_existing_post_ignores_failed_import_rows(): void
    {
        $method = $this->extractPhpMethod(self::$processorSource, 'get_existing_post');

        $this->assertStringContainsString(
            'post_id > 0',
            $method,
            'get_existing_post() must ignore rows without a real post_id so failed imports retry as imports'
        );
    }

    // ---------------------------------------------------------------
    // Feed cache: core's 12-hour default silently defeated the hourly
    // cron and the manual "Sync Now" button.
    // ---------------------------------------------------------------

    public function test_sync_shortens_feed_cache_lifetime(): void
    {
        global $_wp_added_filters, $_wp_removed_filters;

        update_option('substack_sync_settings', ['feed_url' => 'https://example.substack.com/feed']);

        (new Substack_Sync_Processor())->run_sync(true);

        $this->assertContains('wp_feed_cache_transient_lifetime', $_wp_added_filters, 'Sync must shorten the 12h core feed cache');
        $this->assertContains('wp_feed_cache_transient_lifetime', $_wp_removed_filters, 'The lifetime filter must be removed after the fetch');
    }

    public function test_manual_sync_busts_feed_cache(): void
    {
        global $_wp_deleted_transients;

        $url = 'https://example.substack.com/feed';
        update_option('substack_sync_settings', ['feed_url' => $url]);

        (new Substack_Sync_Processor())->run_sync(true, true);

        $this->assertContains('feed_' . md5($url), $_wp_deleted_transients, 'Forced refresh must delete the SimplePie feed transient');
        $this->assertContains('feed_mod_' . md5($url), $_wp_deleted_transients, 'Forced refresh must delete the feed_mod transient');
    }

    public function test_cron_sync_does_not_bust_feed_cache(): void
    {
        global $_wp_deleted_transients;

        $url = 'https://example.substack.com/feed';
        update_option('substack_sync_settings', ['feed_url' => $url]);

        (new Substack_Sync_Processor())->run_sync(true);

        $this->assertNotContains('feed_' . md5($url), $_wp_deleted_transients, 'Cron syncs should reuse the (shortened) cache, not force a refetch');
    }

    // ---------------------------------------------------------------
    // Sync overlap lock: concurrent cron + manual sync could both see a
    // GUID as new and insert duplicate posts.
    // ---------------------------------------------------------------

    public function test_run_sync_skips_when_lock_held(): void
    {
        update_option('substack_sync_settings', ['feed_url' => 'https://example.substack.com/feed']);
        set_transient('substack_sync_running', time(), 300);

        $result = (new Substack_Sync_Processor())->run_sync(true);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('already running', $result['error']);
    }

    public function test_run_sync_acquires_and_releases_lock(): void
    {
        update_option('substack_sync_settings', ['feed_url' => 'https://example.substack.com/feed']);

        $result = (new Substack_Sync_Processor())->run_sync(true);

        // The stubbed fetch_feed() returns WP_Error, so reaching the fetch
        // error proves the lock was acquired rather than blocking ourselves.
        $this->assertStringContainsString('Error fetching feed', $result['error']);
        $this->assertFalse(get_transient('substack_sync_running'), 'Lock must be released after the run, even on error');
    }

    public function test_run_batch_sync_respects_lock(): void
    {
        update_option('substack_sync_settings', ['feed_url' => 'https://example.substack.com/feed']);
        set_transient('substack_sync_running', time(), 300);

        $result = (new Substack_Sync_Processor())->run_batch_sync(1, 0);

        $this->assertFalse($result['success']);
        $this->assertFalse($result['has_more']);
        $this->assertStringContainsString('already running', $result['error']);
    }

    // ---------------------------------------------------------------
    // process_content(): regex replacement corrupted feed URLs that
    // contained $-sequences (preg_replace backreferences) and orphaned
    // </div>s on nested markup (lazy .*? stops at the first close tag).
    // ---------------------------------------------------------------

    public function test_process_content_preserves_dollar_signs_in_feed_url(): void
    {
        update_option('substack_sync_settings', ['feed_url' => 'https://example.substack.com/feed?promo=a$100off']);

        $output = $this->invokeProcessContent('<p>Hello</p><div class="subscription-widget">subscribe here</div><p>World</p>');

        // libxml may percent-encode $ as %24 in href attributes; both are the
        // same URL. The old bug ate "$1" as a backreference, leaving "a00off".
        $this->assertMatchesRegularExpression(
            '/a(\$|%24)100off/',
            $output,
            'A $-sequence in the feed URL must survive losslessly, not be eaten as a backreference'
        );
        $this->assertStringContainsString('substack-subscribe-block', $output);
        $this->assertStringNotContainsString('subscription-widget', $output);
    }

    public function test_process_content_removes_nested_subscription_div_completely(): void
    {
        update_option('substack_sync_settings', ['feed_url' => 'https://example.substack.com/feed']);

        $output = $this->invokeProcessContent(
            '<div class="subscription-widget"><div class="inner"><p>subscribe</p></div></div><p>after</p>'
        );

        $this->assertStringNotContainsString('subscription-widget', $output);
        $this->assertStringNotContainsString('subscribe</p>', $output, 'Inner nodes of the removed block must go with it');
        $this->assertStringContainsString('<p>after</p>', $output);
        $this->assertSame(
            substr_count($output, '<div'),
            substr_count($output, '</div>'),
            'Output must not contain orphaned </div> tags (the old lazy-regex bug)'
        );
    }

    public function test_process_content_removes_nested_like_button_div(): void
    {
        update_option('substack_sync_settings', ['feed_url' => 'https://example.substack.com/feed']);

        $output = $this->invokeProcessContent(
            '<p>before</p><div class="like-button-wrap"><div><span>like</span></div></div><p>after</p>'
        );

        $this->assertStringNotContainsString('like-button', $output);
        $this->assertStringNotContainsString('like</span>', $output);
        $this->assertStringContainsString('<p>before</p>', $output);
        $this->assertStringContainsString('<p>after</p>', $output);
        $this->assertSame(substr_count($output, '<div'), substr_count($output, '</div>'));
    }

    public function test_process_content_passes_untouched_content_through_verbatim(): void
    {
        update_option('substack_sync_settings', ['feed_url' => 'https://example.substack.com/feed']);

        $content = '<p>Plain &amp; simple post with an <img src="https://cdn.example.com/x.png"> image.</p>';

        $this->assertSame($content, $this->invokeProcessContent($content), 'Content without Substack widgets must not be rewritten at all');
    }

    // ---------------------------------------------------------------
    // SSRF guard: filter_var reports CGNAT (RFC 6598) and 192.0.0.0/24
    // (RFC 6890) as public, so those literals slipped past the guard.
    // ---------------------------------------------------------------

    public function test_is_safe_remote_url_blocks_cgnat_and_rfc6890(): void
    {
        $unsafe = [
            'http://100.64.0.1/x.png',    // CGNAT range start
            'http://100.127.255.254/x.png', // CGNAT range end
            'http://192.0.0.5/x.png',     // RFC 6890 protocol assignments
        ];

        foreach ($unsafe as $url) {
            $this->assertFalse($this->invokeIsSafeRemoteUrl($url), "Must reject: {$url}");
        }
    }

    public function test_is_safe_remote_url_still_allows_public_neighbors(): void
    {
        // Addresses adjacent to the blocked ranges stay allowed, proving the
        // masks are exact rather than over-broad.
        $this->assertTrue($this->invokeIsSafeRemoteUrl('http://100.63.255.255/x.png'));
        $this->assertTrue($this->invokeIsSafeRemoteUrl('http://100.128.0.1/x.png'));
        $this->assertTrue($this->invokeIsSafeRemoteUrl('http://192.0.1.1/x.png'));
        $this->assertTrue($this->invokeIsSafeRemoteUrl('https://8.8.8.8/x.png'));
    }

    // ---------------------------------------------------------------
    // Image localization: sideload each image once (deduped by source
    // URL), rewrite content to serve the local copies, set the featured
    // image. Previously every hourly update re-downloaded every image
    // into the media library and the copies were never referenced.
    // ---------------------------------------------------------------

    public function test_images_are_localized_and_content_rewritten(): void
    {
        global $_wp_sideload_calls, $_wp_thumbnails;

        $post_id = wp_insert_post([
            'post_title' => 'Image post',
            'post_content' => 'placeholder',
            'post_status' => 'publish',
        ]);

        $content = '<p><img src="http://8.8.8.8/a.png" srcset="http://8.8.8.8/a-2x.png 2x"><img src="http://8.8.4.4/b.png"></p>';

        $this->invokeProcessPostImages($post_id, $content);

        $this->assertCount(2, $_wp_sideload_calls, 'Every remote image must be sideloaded');

        $saved = get_post($post_id)->post_content;
        $this->assertStringNotContainsString('http://8.8.8.8/a.png', $saved, 'Content must be rewritten to the local copy');
        $this->assertStringNotContainsString('srcset', $saved, 'Remote srcset must be dropped or it overrides the localized src');
        $this->assertSame(2, substr_count($saved, 'myblog.example.com/wp-content/uploads/'), 'Both images must serve locally');
        $this->assertArrayHasKey($post_id, $_wp_thumbnails, 'First localized image must become the featured image');
    }

    public function test_image_sideloads_are_deduped_across_runs(): void
    {
        global $_wp_sideload_calls;

        $post_id = wp_insert_post(['post_title' => 'x', 'post_content' => 'p', 'post_status' => 'publish']);
        $content = '<p><img src="http://8.8.8.8/a.png"></p>';

        $this->invokeProcessPostImages($post_id, $content);
        $first_run_calls = count($_wp_sideload_calls);

        // Hourly update: prepare_post_data() regenerates content from the feed
        // (remote URLs again), so the same source URL comes back through.
        $this->invokeProcessPostImages($post_id, $content);

        $this->assertSame(1, $first_run_calls);
        $this->assertCount(1, $_wp_sideload_calls, 'A source URL already in the media library must never be downloaded again');

        $saved = get_post($post_id)->post_content;
        $this->assertStringContainsString('myblog.example.com/wp-content/uploads/', $saved, 'The rerun must still rewrite content to the existing local copy');
    }

    public function test_process_post_images_skips_already_local_images(): void
    {
        global $_wp_sideload_calls;

        $post_id = wp_insert_post(['post_title' => 'x', 'post_content' => 'p', 'post_status' => 'publish']);

        $this->invokeProcessPostImages($post_id, '<p><img src="https://myblog.example.com/wp-content/uploads/42.png"></p>');

        $this->assertCount(0, $_wp_sideload_calls, 'Images already served from this site must not be re-fetched');
    }

    public function test_process_post_images_bounds_failed_downloads(): void
    {
        global $_wp_sideload_calls, $_wp_sideload_fail;

        $_wp_sideload_fail = true;
        $post_id = wp_insert_post(['post_title' => 'x', 'post_content' => 'p', 'post_status' => 'publish']);

        $imgs = '';
        for ($i = 1; $i <= 9; $i++) {
            $imgs .= sprintf('<img src="http://8.8.8.%d/x.png">', $i);
        }

        $this->invokeProcessPostImages($post_id, "<p>{$imgs}</p>");

        $this->assertCount(5, $_wp_sideload_calls, 'A feed full of failing image URLs must not trigger unbounded remote fetches');
    }

    public function test_process_post_images_bounds_new_downloads_per_run(): void
    {
        global $_wp_sideload_calls;

        $post_id = wp_insert_post(['post_title' => 'x', 'post_content' => 'p', 'post_status' => 'publish']);

        $imgs = '';
        for ($i = 1; $i <= 14; $i++) {
            $imgs .= sprintf('<img src="http://8.8.8.%d/x.png">', $i);
        }

        $this->invokeProcessPostImages($post_id, "<p>{$imgs}</p>");

        $this->assertCount(10, $_wp_sideload_calls, 'New downloads are capped per run; the rest converge on later syncs');
    }

    public function test_process_post_images_preserves_existing_thumbnail(): void
    {
        global $_wp_thumbnails;

        $post_id = wp_insert_post(['post_title' => 'x', 'post_content' => 'p', 'post_status' => 'publish']);
        set_post_thumbnail($post_id, 999);

        $this->invokeProcessPostImages($post_id, '<p><img src="http://8.8.8.8/a.png"></p>');

        $this->assertSame(999, $_wp_thumbnails[$post_id], 'An existing featured image must not be overwritten');
    }

    // ---------------------------------------------------------------
    // Category mapping: byte-wise strtolower() never matched accented
    // keywords against differently-cased content.
    // ---------------------------------------------------------------

    public function test_apply_category_mapping_matches_non_ascii_case(): void
    {
        update_option('substack_sync_settings', [
            'category_mapping' => [
                ['keyword' => 'café', 'category' => 9],
            ],
        ]);

        $processor = new Substack_Sync_Processor();
        $method = new ReflectionMethod($processor, 'apply_category_mapping');

        $this->assertContains(9, $method->invoke($processor, 'The best CAFÉ reviews in town'));
    }

    public function test_apply_category_mapping_tolerates_non_array_setting(): void
    {
        update_option('substack_sync_settings', ['category_mapping' => 'stale-scalar-value']);

        $processor = new Substack_Sync_Processor();
        $method = new ReflectionMethod($processor, 'apply_category_mapping');

        $this->assertSame([], $method->invoke($processor, 'any content'), 'Stale non-array option data must not warn or fatal');
    }

    // ---------------------------------------------------------------
    // AJAX handler consolidation and retry reset (source-level: the fix
    // lives in SQL/WP plumbing the stubs cannot execute meaningfully)
    // ---------------------------------------------------------------

    public function test_ajax_handlers_share_single_guarded_buffer_clean(): void
    {
        $this->assertSame(
            1,
            substr_count(self::$adminSource, 'ob_clean();'),
            'Exactly one ob_clean() call, inside the shared handler guard'
        );
        $this->assertStringContainsString(
            'ob_get_level() > 0',
            self::$adminSource,
            'ob_clean() must be guarded: admin-ajax.php starts no buffer, and a bare call emits a notice'
        );
        $this->assertGreaterThanOrEqual(
            6,
            substr_count(self::$adminSource, 'handle_ajax_request('),
            'All five AJAX handlers must dispatch through the shared guard'
        );
    }

    public function test_retry_reset_is_a_single_update_query(): void
    {
        $method = $this->extractPhpMethod(self::$processorSource, 'reset_failed_posts');

        $this->assertStringContainsString('UPDATE', $method);
        $this->assertStringNotContainsString(
            'reset_post_retry_count',
            self::$processorSource . self::$adminSource,
            'The per-row reset loop must be gone'
        );
    }

    public function test_rollbacks_delete_posts_and_log_rows_in_chunks(): void
    {
        $method = $this->extractPhpMethod(self::$processorSource, 'delete_synced_posts');

        $this->assertStringContainsString('LIMIT 100', $method, 'Rollback selection must be chunked, not unbounded');
        $this->assertStringContainsString('wp_delete_post', $method);

        foreach (['rollback_all_posts', 'rollback_failed_posts', 'rollback_posts_by_date'] as $rollback) {
            $this->assertStringContainsString(
                'delete_synced_posts(',
                $this->extractPhpMethod(self::$processorSource, $rollback),
                "{$rollback}() must use the chunked helper"
            );
        }
    }

    // ---------------------------------------------------------------
    // Follow-up review fixes
    // ---------------------------------------------------------------

    // Batch sync (the only handler wired to a UI button) wrapped
    // run_batch_sync()'s own failure payload in wp_send_json_success(), so a
    // lock-held/no-feed/fetch-error run surfaced to the browser as a clean
    // 0-post "completed" instead of the error.
    public function test_batch_sync_reports_error_when_lock_held(): void
    {
        global $_wp_json_responses;

        update_option('substack_sync_settings', ['feed_url' => 'https://example.substack.com/feed']);
        set_transient('substack_sync_running', time(), 300);
        $_POST['_ajax_nonce'] = 'test-nonce';
        $_POST['offset'] = '0';
        $_POST['batch_size'] = '1';

        (new Substack_Sync_Admin())->handle_batch_sync();

        $this->assertNotEmpty($_wp_json_responses, 'The handler must send a JSON response');
        $this->assertSame(
            'error',
            $_wp_json_responses[0]['type'],
            'A lock-held batch sync must send wp_send_json_error, not a success envelope wrapping success:false'
        );
        $this->assertStringContainsString('already running', (string) $_wp_json_responses[0]['data']);
    }

    // esc_url() (display context) rewrites & to the literal text &#038;, which
    // DOMDocument::saveHTML() then re-escapes to &amp;#038;, corrupting any feed
    // URL with 2+ query params. esc_url_raw() is the correct escaper for a value
    // set via setAttribute(). Source-level assertion: the stubs cannot reproduce
    // core esc_url()'s entity substitution, so testing the value would be hollow.
    public function test_subscribe_link_uses_non_display_url_escaper(): void
    {
        $method = $this->extractPhpMethod(self::$processorSource, 'build_subscribe_node');

        $this->assertStringContainsString(
            'esc_url_raw(',
            $method,
            'Subscribe href must use esc_url_raw(): display esc_url() emits &#038;, which saveHTML re-escapes into the URL'
        );
        $this->assertStringNotContainsString(
            'esc_url($',
            $method,
            'Display-context esc_url() must not be used for a DOM attribute value'
        );
    }

    // WP 6.9+ stores the cached feed via *_site_transient(), a distinct key
    // space from plain transients. The forced-refresh cache-bust must clear it
    // there too or "Sync Now" silently serves stale content on WP 6.9/7.0.
    public function test_manual_sync_busts_site_transient_cache(): void
    {
        global $_wp_deleted_site_transients;

        $url = 'https://example.substack.com/feed';
        update_option('substack_sync_settings', ['feed_url' => $url]);

        (new Substack_Sync_Processor())->run_sync(true, true);

        $this->assertContains('feed_' . md5($url), $_wp_deleted_site_transients, 'Forced refresh must delete the site-transient feed cache (WP 6.9+)');
        $this->assertContains('feed_mod_' . md5($url), $_wp_deleted_site_transients, 'Forced refresh must delete the site-transient feed_mod cache (WP 6.9+)');
    }

    // process_post_images() must localize and RETURN content, not write the post
    // itself: the caller folds the result into its single write, so an unchanged
    // hourly sync no longer double-writes (two revisions + modified bump/post/run).
    public function test_process_post_images_returns_content_without_writing(): void
    {
        $post_id = wp_insert_post([
            'post_title' => 'x',
            'post_content' => 'ORIGINAL',
            'post_status' => 'publish',
        ]);

        $processor = new Substack_Sync_Processor();
        $method = new ReflectionMethod($processor, 'process_post_images');
        $localized = $method->invoke($processor, $post_id, '<p><img src="http://8.8.8.8/a.png"></p>');

        $this->assertSame(
            'ORIGINAL',
            get_post($post_id)->post_content,
            'process_post_images() must not write the post; the caller performs the single write'
        );
        $this->assertNotNull($localized, 'It must return the localized content when an image was rewritten');
        $this->assertStringContainsString('myblog.example.com/wp-content/uploads/', $localized);
    }

    // Rollback trailing sweeps must be scoped to post_id = 0 (orphan rows) so
    // they cannot delete the log row of a post a concurrent sync inserted
    // mid-rollback, which would leave a live post with no tracking row.
    public function test_rollback_trailing_deletes_only_touch_orphan_rows(): void
    {
        $all = $this->extractPhpMethod(self::$processorSource, 'rollback_all_posts');
        $this->assertStringContainsString('WHERE post_id = 0', $all);

        $failed = $this->extractPhpMethod(self::$processorSource, 'rollback_failed_posts');
        $this->assertStringContainsString("'post_id' => 0", $failed);

        $byDate = $this->extractPhpMethod(self::$processorSource, 'rollback_posts_by_date');
        $this->assertStringContainsString('post_id = 0 AND sync_date', $byDate);
    }

    // esc_attr()/htmlspecialchars() fatals on an array argument; stale option
    // data can hold a non-scalar keyword/category, and the settings page must
    // render it instead of white-screening.
    public function test_settings_page_tolerates_non_scalar_mapping_data(): void
    {
        update_option('substack_sync_settings', [
            'category_mapping' => [
                ['keyword' => ['unexpected', 'array'], 'category' => 5],
                'not-an-array-row',
            ],
        ]);

        ob_start();
        (new Substack_Sync_Admin())->category_mapping_callback();
        $html = ob_get_clean();

        // The array-keyword row still renders (with an empty keyword), and the
        // scalar (non-array) row is skipped rather than fatalling.
        $this->assertStringContainsString('category_mapping][0][keyword]', $html);
        $this->assertStringNotContainsString('category_mapping][1][keyword]', $html);
    }

    // A dedup hit whose attachment was deleted outside the plugin resolves to no
    // local URL; that image must not become the featured image (a thumbnail
    // pointing at a nonexistent attachment) and must not be rewritten.
    public function test_featured_image_skipped_when_attachment_url_missing(): void
    {
        global $_wp_missing_attachments, $_wp_post_meta, $_wp_thumbnails;

        $post_id = wp_insert_post(['post_title' => 'x', 'post_content' => 'p', 'post_status' => 'publish']);

        $src = 'http://8.8.8.8/gone.png';
        $_wp_post_meta[500] = ['_substack_sync_source_url' => $src]; // prior sync recorded it
        $_wp_missing_attachments = [500];                            // but it was since deleted

        $processor = new Substack_Sync_Processor();
        $method = new ReflectionMethod($processor, 'process_post_images');
        $localized = $method->invoke($processor, $post_id, '<p><img src="' . $src . '"></p>');

        $this->assertArrayNotHasKey($post_id, $_wp_thumbnails, 'Featured image must not point at a since-deleted attachment');
        $this->assertNull($localized, 'Nothing should be rewritten when the only image resolves to no local URL');
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function invokeProcessContent(string $content): string
    {
        $processor = new Substack_Sync_Processor();
        $method = new ReflectionMethod($processor, 'process_content');

        return $method->invoke($processor, $content);
    }

    private function invokeProcessPostImages(int $post_id, string $content): ?string
    {
        $processor = new Substack_Sync_Processor();
        $method = new ReflectionMethod($processor, 'process_post_images');
        $localized = $method->invoke($processor, $post_id, $content);

        // Mirror the production callers: process_post_images() localizes and
        // returns the content; the caller performs the single write.
        if ($localized !== null) {
            wp_update_post(['ID' => $post_id, 'post_content' => $localized]);
        }

        return $localized;
    }

    private function invokeIsSafeRemoteUrl(string $url): bool
    {
        $processor = new Substack_Sync_Processor();
        $method = new ReflectionMethod($processor, 'is_safe_remote_url');

        return $method->invoke($processor, $url);
    }

    private function extractPhpMethod(string $source, string $methodName): string
    {
        $pattern = '/function\s+' . preg_quote($methodName) . '\s*\([^)]*\)[^{]*\{/';
        if (! preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE)) {
            self::fail("Could not find method {$methodName} in source");
        }

        $start = $match[0][1];
        $braceCount = 0;
        $len = strlen($source);
        $inMethod = false;

        for ($i = $start; $i < $len; $i++) {
            if ($source[$i] === '{') {
                $braceCount++;
                $inMethod = true;
            } elseif ($source[$i] === '}') {
                $braceCount--;
                if ($inMethod && $braceCount === 0) {
                    return substr($source, $start, $i - $start + 1);
                }
            }
        }

        self::fail("Could not extract method {$methodName}: unbalanced braces");
    }
}
