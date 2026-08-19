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
            $_wp_deleted_site_transients, $_wp_json_responses, $_wp_missing_attachments,
            $_wp_get_results_rows, $_wp_download_bytes, $_wp_media_handle_fail, $_wp_feed_items,
            $_wp_query_calls, $_wp_query_result, $_wp_get_results_calls;

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

    // process_content() runs on raw feed content (before wp_kses_post). libxml's
    // HTML parser discards text after a bare `<` that does not begin a tag, so a
    // raw `<` in prose used to silently truncate the post once the content also
    // tripped the "subscription"/"like-button" DOM-cleanup gate.
    public function test_process_content_preserves_bare_lt_in_prose(): void
    {
        update_option('substack_sync_settings', ['feed_url' => 'https://example.substack.com/feed']);

        $output = $this->invokeProcessContent('<p>Revenue < 5000 this month. Consider a subscription!</p>');

        $this->assertStringContainsString('5000 this month', $output, 'Text after a bare < must not be dropped');
        $this->assertStringContainsString('Consider a subscription', $output);
    }

    public function test_process_content_preserves_bare_lt_while_removing_widget(): void
    {
        update_option('substack_sync_settings', ['feed_url' => 'https://example.substack.com/feed']);

        $output = $this->invokeProcessContent(
            '<p>Use <code>if (x < 5) return</code> in code.</p>'
            . '<div class="subscription-widget">join</div><p>tail text</p>'
        );

        // The bug dropped everything from the bare `<` to the next tag boundary.
        $this->assertStringContainsString('in code.', $output, 'Prose after the code sample must survive');
        $this->assertStringContainsString('tail text', $output);
        $this->assertStringContainsString('5) return', $output, 'The code sample operands must survive');
        // Widget removal and subscribe-block insertion still work.
        $this->assertStringNotContainsString('subscription-widget', $output);
        $this->assertStringContainsString('substack-subscribe-block', $output);
    }

    // ---------------------------------------------------------------
    // YouTube embeds: Substack ships them as an <iframe>, which
    // wp_kses_post() strips, so video posts arrived with an empty
    // wrapper div and no image. They are rewritten to a linked
    // thumbnail before sanitization.
    // ---------------------------------------------------------------

    public function test_process_content_replaces_youtube_embed_with_linked_thumbnail(): void
    {
        update_option('substack_sync_settings', ['feed_url' => 'https://example.substack.com/feed']);

        // Verbatim shape from sovereigngrace.substack.com/feed. Deliberately
        // carries no subscribe or like widget: a video-only post must still
        // reach the DOM pass rather than short-circuit on the cheap pre-check.
        $output = $this->invokeProcessContent(
            '<div id="youtube2-KNFJSIj6xfQ" class="youtube-wrap" data-attrs="{&quot;videoId&quot;:&quot;KNFJSIj6xfQ&quot;,&quot;startTime&quot;:null}"'
            . ' data-component-name="Youtube2ToDOM"><div class="youtube-inner">'
            . '<iframe src="https://www.youtube-nocookie.com/embed/KNFJSIj6xfQ?rel=0" width="728" height="409"></iframe>'
            . '</div></div><h1>Why this episode matters</h1>'
        );

        $this->assertStringNotContainsString('youtube-wrap', $output, 'The stripped-iframe wrapper must not survive');
        $this->assertStringNotContainsString('<iframe', $output);
        $this->assertStringContainsString('substack-video-embed', $output);
        $this->assertStringContainsString('src="https://img.youtube.com/vi/KNFJSIj6xfQ/maxresdefault.jpg"', $output);
        $this->assertStringContainsString('href="https://www.youtube.com/watch?v=KNFJSIj6xfQ"', $output);
        $this->assertStringContainsString('<h1>Why this episode matters</h1>', $output, 'Body copy after the embed must survive');
    }

    public function test_youtube_id_falls_back_to_the_element_id_when_data_attrs_are_missing(): void
    {
        update_option('substack_sync_settings', ['feed_url' => 'https://example.substack.com/feed']);

        // "noBX7D2-7hA" contains a hyphen, so a split-on-first-hyphen parse of
        // the element id would truncate it to "noBX7D2" and 404 the thumbnail.
        $output = $this->invokeProcessContent(
            '<div id="youtube2-noBX7D2-7hA" class="youtube-wrap">'
            . '<iframe src="https://www.youtube-nocookie.com/embed/videoseries?list=PLp9pLaqAQe"></iframe></div>'
        );

        $this->assertStringContainsString('vi/noBX7D2-7hA/maxresdefault.jpg', $output);
        $this->assertStringContainsString('watch?v=noBX7D2-7hA', $output);
    }

    public function test_unparseable_youtube_wrapper_is_left_alone(): void
    {
        update_option('substack_sync_settings', ['feed_url' => 'https://example.substack.com/feed']);

        // No usable id anywhere. Feed values reach the URL builders, so an
        // unvalidated one must yield no node at all rather than a bogus link.
        $output = $this->invokeProcessContent(
            '<div class="youtube-wrap" data-attrs="not json">'
            . '<iframe src="https://www.youtube-nocookie.com/embed/"></iframe></div>'
        );

        $this->assertStringNotContainsString('substack-video-embed', $output);
        $this->assertStringNotContainsString('img.youtube.com', $output);
    }

    public function test_video_id_with_url_metacharacters_is_rejected(): void
    {
        update_option('substack_sync_settings', ['feed_url' => 'https://example.substack.com/feed']);

        $output = $this->invokeProcessContent(
            '<div class="youtube-wrap" data-attrs=\'{"videoId":"abc/../../evil?x=1"}\'>'
            . '<iframe src="https://www.youtube-nocookie.com/embed/"></iframe></div>'
        );

        // The wrapper passes through untouched (data-attrs and all); what must
        // not happen is a URL getting built out of that value.
        $this->assertStringNotContainsString('substack-video-embed', $output);
        $this->assertStringNotContainsString('img.youtube.com', $output);
        $this->assertStringNotContainsString('youtube.com/watch', $output);
    }

    public function test_video_thumbnail_survives_kses_and_becomes_the_featured_image(): void
    {
        global $_wp_sideload_calls, $_wp_thumbnails, $_wp_post_meta;

        update_option('substack_sync_settings', ['feed_url' => 'https://example.substack.com/feed']);

        // Walk the real pipeline order: process_content(), then wp_kses_post()
        // (which is what ate the original iframe), then image localization.
        $content = wp_kses_post($this->invokeProcessContent(
            '<div id="youtube2-KNFJSIj6xfQ" class="youtube-wrap" data-attrs="{&quot;videoId&quot;:&quot;KNFJSIj6xfQ&quot;}">'
            . '<iframe src="https://www.youtube-nocookie.com/embed/KNFJSIj6xfQ"></iframe></div>'
            . '<p>Body</p><img src="https://cdn.example.com/later-photo.jpg">'
        ));

        // bootstrap.php stubs wp_kses_post() and keeps every attribute on an
        // allowed tag, so this shows only that no disallowed TAG is emitted.
        // figure/a/img and their attributes were checked against core's real
        // $allowedposttags (wp-includes/kses.php) by hand.
        $this->assertStringContainsString('img.youtube.com', $content, 'No disallowed tag in the replacement');

        $post_id = wp_insert_post(['post_title' => 'Video post', 'post_content' => $content, 'post_status' => 'publish']);
        $this->invokeProcessPostImages($post_id, $content);

        $this->assertSame(
            'https://img.youtube.com/vi/KNFJSIj6xfQ/maxresdefault.jpg',
            $_wp_sideload_calls[0] ?? null,
            'The thumbnail must be sideloaded into the media library like any other image'
        );
        $this->assertArrayHasKey($post_id, $_wp_thumbnails, 'A video post must end up with a featured image');
        $this->assertSame(
            'https://img.youtube.com/vi/KNFJSIj6xfQ/maxresdefault.jpg',
            $_wp_post_meta[$_wp_thumbnails[$post_id]]['_substack_sync_source_url'] ?? null,
            'The embed leads the post, so the video frame wins the featured slot over the later body photo'
        );
    }

    public function test_garbage_data_attrs_falls_through_to_the_element_id(): void
    {
        update_option('substack_sync_settings', ['feed_url' => 'https://example.substack.com/feed']);

        // A present-but-invalid videoId must not mask a usable element id:
        // every source is tried and the first that validates wins.
        $output = $this->invokeProcessContent(
            '<div id="youtube2-KNFJSIj6xfQ" class="youtube-wrap" data-attrs=\'{"videoId":"abc/../evil"}\'>'
            . '<iframe src="https://www.youtube-nocookie.com/embed/"></iframe></div>'
        );

        $this->assertStringContainsString('vi/KNFJSIj6xfQ/maxresdefault.jpg', $output);
        $this->assertStringNotContainsString('evil', $output);
    }

    public function test_playlist_marker_is_not_treated_as_a_video_id(): void
    {
        update_option('substack_sync_settings', ['feed_url' => 'https://example.substack.com/feed']);

        // "videoseries" is 11 characters of the ID charset, so a charset-and-
        // length check alone accepts it and then 404s the thumbnail every sync.
        $output = $this->invokeProcessContent(
            '<div id="youtube2-videoseries" class="youtube-wrap">'
            . '<iframe src="https://www.youtube-nocookie.com/embed/videoseries?list=PLp9pLaqAQe"></iframe></div>'
        );

        $this->assertStringNotContainsString('substack-video-embed', $output);
        $this->assertStringNotContainsString('vi/videoseries/', $output);
    }

    public function test_lookalike_embed_host_is_left_alone(): void
    {
        update_option('substack_sync_settings', ['feed_url' => 'https://example.substack.com/feed']);

        // A str_ends_with($host, 'youtube.com') test would match this one.
        $output = $this->invokeProcessContent(
            '<div class="youtube-wrap"><iframe src="https://evilyoutube.com/embed/KNFJSIj6xfQ"></iframe></div>'
        );

        $this->assertStringNotContainsString('substack-video-embed', $output);
        $this->assertStringNotContainsString('img.youtube.com', $output);
    }

    public function test_embed_is_rewritten_without_a_substack_wrapper(): void
    {
        update_option('substack_sync_settings', ['feed_url' => 'https://example.substack.com/feed']);

        // Keying on the embed host rather than Substack's wrapper class is what
        // keeps this working when their markup changes: here the ID is only in
        // the src path, and there is no wrapper div at all.
        $output = $this->invokeProcessContent(
            '<p>Intro</p><iframe src="https://www.youtube.com/embed/KNFJSIj6xfQ?rel=0"></iframe>'
        );

        $this->assertStringContainsString('vi/KNFJSIj6xfQ/maxresdefault.jpg', $output);
        $this->assertStringNotContainsString('<iframe', $output);
        $this->assertStringContainsString('<p>Intro</p>', $output);
    }

    // ---------------------------------------------------------------
    // Featured-image repair: set_post_thumbnail() is gated on
    // ! has_post_thumbnail(), so video posts imported before the embed
    // rewrite keep the body photo they wrongly picked. One-time pass.
    // ---------------------------------------------------------------

    public function test_repair_points_a_previously_imported_video_post_at_its_video_frame(): void
    {
        global $_wp_get_results_rows, $_wp_post_meta, $_wp_thumbnails;

        $_wp_post_meta[900] = ['_substack_sync_source_url' => 'https://img.youtube.com/vi/KNFJSIj6xfQ/maxresdefault.jpg'];
        $_wp_post_meta[901] = ['_substack_sync_source_url' => 'https://cdn.example.com/later-photo.jpg'];

        // What a pre-rewrite import left behind: content since re-synced so it
        // leads with the video figure, featured image still the later photo.
        $post_id = wp_insert_post([
            'post_title' => 'Clash episode',
            'post_content' => '<figure class="substack-video-embed">'
                . '<a href="https://www.youtube.com/watch?v=KNFJSIj6xfQ"><img src="https://files.example.com/hqdefault.jpg"></a>'
                . '</figure><p>Body</p><img src="https://files.example.com/later-photo.jpg">',
        ]);
        set_post_thumbnail($post_id, 901);

        $_wp_get_results_rows = ['SELECT DISTINCT post_id' => [['post_id' => $post_id]]];

        $processor = new Substack_Sync_Processor();

        $this->assertSame(1, $processor->repair_video_featured_images());
        $this->assertSame(900, $_wp_thumbnails[$post_id], 'The video frame must replace the body photo');
        $this->assertTrue((bool) get_option('substack_sync_video_thumbnail_repaired'), 'Repair must set its done flag');

        // Idempotent: a second run is a no-op once the flag is set.
        $this->assertSame(0, $processor->repair_video_featured_images());
    }

    public function test_repair_leaves_a_post_whose_body_photo_leads(): void
    {
        global $_wp_get_results_rows, $_wp_post_meta, $_wp_thumbnails;

        $_wp_post_meta[900] = ['_substack_sync_source_url' => 'https://img.youtube.com/vi/KNFJSIj6xfQ/maxresdefault.jpg'];

        // Photo first, video below: process_post_images() would have chosen the
        // photo, so the repair must not promote the video frame past it.
        $post_id = wp_insert_post([
            'post_title' => 'Photo first',
            'post_content' => '<img src="https://files.example.com/lede.jpg"><figure class="substack-video-embed">'
                . '<a href="https://www.youtube.com/watch?v=KNFJSIj6xfQ"><img src="https://files.example.com/hqdefault.jpg"></a></figure>',
        ]);
        set_post_thumbnail($post_id, 902);

        $_wp_get_results_rows = ['SELECT DISTINCT post_id' => [['post_id' => $post_id]]];

        $this->assertSame(0, (new Substack_Sync_Processor())->repair_video_featured_images());
        $this->assertSame(902, $_wp_thumbnails[$post_id], 'A legitimately leading photo keeps the featured slot');
    }

    public function test_repair_does_not_set_its_flag_on_a_query_error(): void
    {
        global $_wp_get_results_rows;

        $_wp_get_results_rows = ['SELECT DISTINCT post_id' => null];

        $this->assertSame(0, (new Substack_Sync_Processor())->repair_video_featured_images());
        $this->assertFalse(
            (bool) get_option('substack_sync_video_thumbnail_repaired'),
            'A transient DB failure must retry on the next sync, not be skipped forever'
        );
    }

    public function test_repair_waits_for_a_frame_that_is_not_in_the_library_yet(): void
    {
        global $_wp_get_results_rows, $_wp_post_meta, $_wp_thumbnails;

        // Sideloading is bounded per run and can fail on a transient network
        // error, so a video post can lead with a frame whose attachment does not
        // exist yet. Flagging the pass done there forfeits that post forever.
        $post_id = $this->insertVideoLeadingPost();
        set_post_thumbnail($post_id, 901);
        $_wp_post_meta[901] = ['_substack_sync_source_url' => 'https://cdn.example.com/later-photo.jpg'];
        $_wp_get_results_rows = ['SELECT DISTINCT post_id' => [['post_id' => $post_id]]];

        $this->assertSame(0, (new Substack_Sync_Processor())->repair_video_featured_images());
        $this->assertFalse(
            (bool) get_option('substack_sync_video_thumbnail_repaired'),
            'Outstanding work must not be mistaken for absent work'
        );
        $this->assertSame(901, $_wp_thumbnails[$post_id]);

        // Once the frame lands, the retry repairs it and only then flags done.
        $_wp_post_meta[900] = ['_substack_sync_source_url' => 'https://img.youtube.com/vi/KNFJSIj6xfQ/maxresdefault.jpg'];

        $this->assertSame(1, (new Substack_Sync_Processor())->repair_video_featured_images());
        $this->assertSame(900, $_wp_thumbnails[$post_id]);
        $this->assertTrue((bool) get_option('substack_sync_video_thumbnail_repaired'));
    }

    public function test_repair_leaves_a_featured_image_a_human_chose(): void
    {
        global $_wp_get_results_rows, $_wp_post_meta, $_wp_thumbnails;

        $_wp_post_meta[900] = ['_substack_sync_source_url' => 'https://img.youtube.com/vi/KNFJSIj6xfQ/maxresdefault.jpg'];

        // 950 carries no source URL, so nothing this plugin sideloaded put it
        // there. Ordinary syncs never override a featured image for that reason
        // (! has_post_thumbnail()) and the repair runs without that gate.
        $post_id = $this->insertVideoLeadingPost();
        set_post_thumbnail($post_id, 950);
        $_wp_get_results_rows = ['SELECT DISTINCT post_id' => [['post_id' => $post_id]]];

        $this->assertSame(0, (new Substack_Sync_Processor())->repair_video_featured_images());
        $this->assertSame(950, $_wp_thumbnails[$post_id], "An editor's own episode card must survive the repair");
        $this->assertTrue(
            (bool) get_option('substack_sync_video_thumbnail_repaired'),
            'A deliberate skip is not outstanding work: the pass is still done'
        );
    }

    public function test_repair_waits_for_a_post_no_sync_has_rewritten_yet(): void
    {
        global $_wp_get_results_rows, $_wp_post_meta, $_wp_thumbnails;

        // The frame is in the library, so nothing is outstanding on the sideload
        // side. What is outstanding is the post itself: an item skipped for max
        // retries, or one that threw mid-loop, never had its content rewritten,
        // and used to be indistinguishable from a post that never had a video.
        $_wp_post_meta[900] = ['_substack_sync_source_url' => 'https://img.youtube.com/vi/KNFJSIj6xfQ/maxresdefault.jpg'];

        $post_id = $this->insertUnrewrittenVideoPost();
        set_post_thumbnail($post_id, 901);
        $_wp_post_meta[901] = ['_substack_sync_source_url' => 'https://cdn.example.com/later-photo.jpg'];
        $_wp_get_results_rows = ['SELECT DISTINCT post_id' => [['post_id' => $post_id]]];

        $this->assertSame(0, (new Substack_Sync_Processor())->repair_video_featured_images());
        $this->assertFalse(
            (bool) get_option('substack_sync_video_thumbnail_repaired'),
            'A video post the sync loop never reached is outstanding work, not absent work'
        );
        $this->assertSame(901, $_wp_thumbnails[$post_id]);
    }

    public function test_repair_stops_after_a_bounded_number_of_syncs(): void
    {
        global $_wp_get_results_rows, $_wp_thumbnails;

        // A deleted video's frame 404s on every sync and a post aged out of the
        // feed is never rewritten, so "wait until nothing is outstanding" is a
        // wait that can never end. Five syncs, then stop.
        $post_id = $this->insertDeferrableVideoPost();
        $_wp_get_results_rows = ['SELECT DISTINCT post_id' => [['post_id' => $post_id]]];

        for ($sync = 1; $sync <= 4; $sync++) {
            $this->runRepairOnNextHour(new Substack_Sync_Processor());
            $this->assertFalse(
                (bool) get_option('substack_sync_video_thumbnail_repaired'),
                "Sync {$sync} must still be trying"
            );
            $this->assertSame($sync, (int) get_option('substack_sync_video_thumbnail_repair_attempts'));
        }

        $this->runRepairOnNextHour(new Substack_Sync_Processor());

        $this->assertTrue(
            (bool) get_option('substack_sync_video_thumbnail_repaired'),
            'The pass must give up rather than rescan the log table for the life of the site'
        );
        $this->assertFalse(
            get_option('substack_sync_video_thumbnail_repair_attempts'),
            'The counter is scaffolding for an unfinished pass and must not outlive it'
        );
        $this->assertSame(901, $_wp_thumbnails[$post_id], 'Giving up changes nothing about the post');
    }

    public function test_repair_attempt_counter_does_not_survive_a_completed_pass(): void
    {
        global $_wp_get_results_rows, $_wp_post_meta, $_wp_thumbnails;

        $post_id = $this->insertVideoLeadingPost();
        set_post_thumbnail($post_id, 901);
        $_wp_post_meta[901] = ['_substack_sync_source_url' => 'https://cdn.example.com/later-photo.jpg'];
        $_wp_get_results_rows = ['SELECT DISTINCT post_id' => [['post_id' => $post_id]]];

        // Frame not in the library yet: one inconclusive sync, counter at 1.
        $this->assertSame(0, (new Substack_Sync_Processor())->repair_video_featured_images());
        $this->assertSame(1, (int) get_option('substack_sync_video_thumbnail_repair_attempts'));

        // It lands, so the next sync finishes the work and the counter goes.
        $_wp_post_meta[900] = ['_substack_sync_source_url' => 'https://img.youtube.com/vi/KNFJSIj6xfQ/maxresdefault.jpg'];

        $this->assertSame(1, (new Substack_Sync_Processor())->repair_video_featured_images());
        $this->assertSame(900, $_wp_thumbnails[$post_id]);
        $this->assertTrue((bool) get_option('substack_sync_video_thumbnail_repaired'));
        $this->assertFalse(get_option('substack_sync_video_thumbnail_repair_attempts'));
    }

    public function test_repair_waits_for_a_wrapper_shape_it_does_not_recognize(): void
    {
        global $_wp_get_results_rows, $_wp_thumbnails, $_wp_post_meta;

        // The class and the id prefix are the two signals that have changed
        // shape across Substack editor versions, and a post archive spans
        // however many versions the site imported through. Missing a legacy
        // post forfeits it, so the payload kses left behind answers too.
        $post_id = wp_insert_post([
            'post_title' => 'Clash episode, wrapper from some other editor',
            'post_content' => '<div class="embed-wrap" data-component-name="Youtube2ToDOM"'
                . ' data-attrs="{&quot;videoId&quot;:&quot;KNFJSIj6xfQ&quot;}"></div>'
                . '<p>Body</p><img src="https://files.example.com/later-photo.jpg">',
        ]);
        set_post_thumbnail($post_id, 901);
        $_wp_post_meta[901] = ['_substack_sync_source_url' => 'https://cdn.example.com/later-photo.jpg'];
        $_wp_get_results_rows = ['SELECT DISTINCT post_id' => [['post_id' => $post_id]]];

        $this->assertSame(0, (new Substack_Sync_Processor())->repair_video_featured_images());
        $this->assertFalse(
            (bool) get_option('substack_sync_video_thumbnail_repaired'),
            'A legacy video post is outstanding work whichever editor wrote its wrapper'
        );
        $this->assertSame(901, $_wp_thumbnails[$post_id]);
    }

    public function test_repair_does_not_read_an_unrelated_data_id_as_a_video_wrapper(): void
    {
        global $_wp_get_results_rows;

        // "\bid=" also matches the tail of data-id=, so an unrelated element
        // carrying one would hold the flag open for five syncs over nothing.
        $post_id = wp_insert_post([
            'post_title' => 'No video here',
            'post_content' => '<div data-id="youtube1-KNFJSIj6xfQ">A link roundup</div>'
                . '<img src="https://files.example.com/photo.jpg">',
        ]);
        $_wp_get_results_rows = ['SELECT DISTINCT post_id' => [['post_id' => $post_id]]];

        (new Substack_Sync_Processor())->repair_video_featured_images();

        $this->assertTrue(
            (bool) get_option('substack_sync_video_thumbnail_repaired'),
            'A post with no video must not be counted as outstanding work'
        );
    }

    public function test_repair_does_not_read_an_unrelated_class_substring_as_a_video_wrapper(): void
    {
        global $_wp_get_results_rows;

        // "youtube-wrap" is a prefix of any number of unrelated class names, and
        // an unanchored match on one holds the pass open over a post it can never
        // rewrite: nothing here will ever grow a substack-video-embed figure, so
        // the pass can only ever end by spending its whole attempt budget.
        $post_id = wp_insert_post([
            'post_title' => 'No video here',
            'post_content' => '<div class="my-youtube-wrapper">A clip worth watching</div>'
                . '<p>Body</p>',
        ]);
        $_wp_get_results_rows = ['SELECT DISTINCT post_id' => [['post_id' => $post_id]]];

        (new Substack_Sync_Processor())->repair_video_featured_images();

        $this->assertTrue(
            (bool) get_option('substack_sync_video_thumbnail_repaired'),
            'A post with no video must not be counted as outstanding work'
        );
        $this->assertSame([], (new Substack_Sync_Processor())->get_unrepaired_video_posts());
    }

    public function test_repair_still_reads_the_wrapper_class_on_its_own(): void
    {
        global $_wp_get_results_rows, $_wp_post_meta;

        // Anchoring the class test must not cost the signal itself: this wrapper
        // carries no id and no data-attrs, so the class is all there is to go on.
        $post_id = wp_insert_post([
            'post_title' => 'Clash episode, class only',
            'post_content' => '<div class="pencraft youtube-wrap"></div>'
                . '<p>Body</p><img src="https://files.example.com/later-photo.jpg">',
        ]);
        set_post_thumbnail($post_id, 901);
        $_wp_post_meta[901] = ['_substack_sync_source_url' => 'https://cdn.example.com/later-photo.jpg'];
        $_wp_get_results_rows = ['SELECT DISTINCT post_id' => [['post_id' => $post_id]]];

        $this->assertSame(0, (new Substack_Sync_Processor())->repair_video_featured_images());
        $this->assertFalse(
            (bool) get_option('substack_sync_video_thumbnail_repaired'),
            'A legacy video post whose only surviving signal is the class is still outstanding work'
        );
    }

    public function test_giving_up_records_which_posts_it_left(): void
    {
        global $_wp_get_results_rows;

        // A count tells the site owner something is wrong and gives them no way
        // to find it. The give-up report is what the settings screen reads back.
        $post_id = $this->insertDeferrableVideoPost();
        $_wp_get_results_rows = ['SELECT DISTINCT post_id' => [['post_id' => $post_id]]];

        $processor = new Substack_Sync_Processor();
        $this->runRepairUntilItGivesUp($processor);

        $this->assertTrue((bool) get_option('substack_sync_video_thumbnail_repaired'));
        $this->assertSame(
            [$post_id],
            $processor->get_unrepaired_video_posts(),
            'Giving up has to name the posts, or the outcome is invisible to a person'
        );
    }

    public function test_a_finished_pass_leaves_no_give_up_report(): void
    {
        global $_wp_get_results_rows, $_wp_post_meta;

        // A post the report can actually name. An ID with no post behind it is
        // dropped by the report's own staleness filter, so seeding one reads as
        // an empty report before the pass has done anything, and the assertion
        // below then holds whether or not a finished pass clears the option.
        $stale = $this->insertDeferrableVideoPost();
        update_option(
            'substack_sync_video_thumbnail_repair_unrepaired',
            ['count' => 1, 'ids' => [$stale]]
        );

        $post_id = $this->insertVideoLeadingPost();
        set_post_thumbnail($post_id, 901);
        $_wp_post_meta[901] = ['_substack_sync_source_url' => 'https://cdn.example.com/later-photo.jpg'];
        $_wp_post_meta[900] = ['_substack_sync_source_url' => 'https://img.youtube.com/vi/KNFJSIj6xfQ/maxresdefault.jpg'];
        $_wp_get_results_rows = ['SELECT DISTINCT post_id' => [['post_id' => $post_id]]];

        $processor = new Substack_Sync_Processor();

        $this->assertSame(1, $processor->repair_video_featured_images());
        $this->assertSame(
            [],
            $processor->get_unrepaired_video_posts(),
            'A stale report would keep naming posts that are fine now'
        );
    }

    public function test_restarting_the_repair_puts_it_back_in_play(): void
    {
        update_option('substack_sync_video_thumbnail_repaired', true);
        update_option('substack_sync_video_thumbnail_repair_attempts', 5);

        $processor = new Substack_Sync_Processor();

        $this->assertTrue($processor->restart_video_thumbnail_repair(), 'A finished pass was reset');
        $this->assertFalse(get_option('substack_sync_video_thumbnail_repaired'));
        $this->assertFalse(get_option('substack_sync_video_thumbnail_repair_attempts'));

        $this->assertFalse(
            $processor->restart_video_thumbnail_repair(),
            'Nothing was finished, so the button has nothing to report'
        );
    }

    public function test_retry_reset_reaches_posts_past_the_retry_ceiling(): void
    {
        global $_wp_query_calls, $_wp_query_result;

        // should_skip_post() stops retrying at retry_count >= the ceiling, so a
        // "retry_count < ceiling" filter here reset the rows that were going to
        // be retried anyway and skipped the only ones a person can be asking
        // about. Those posts were reachable by no path at all.
        $_wp_query_result = 2;

        $this->assertSame(2, (new Substack_Sync_Processor())->reset_failed_posts());
        $this->assertNotEmpty($_wp_query_calls, 'The reset must actually issue its UPDATE');
        $this->assertStringNotContainsString(
            'retry_count',
            $this->whereClauseOf($_wp_query_calls[0]),
            'The reset must not exclude the rows that need it most'
        );
    }

    public function test_a_reset_the_database_rejects_reports_nothing_reset(): void
    {
        global $_wp_query_result;

        // $wpdb::query() answers false on error, which is not 0 rows. Collapsing
        // the two told an owner "No failed posts to retry" on the one occasion
        // the query never ran, so the error gets its own answer.
        update_option('substack_sync_video_thumbnail_repaired', true);
        $_wp_query_result = false;

        $this->assertNull((new Substack_Sync_Processor())->reset_failed_posts());
        $this->assertTrue(
            (bool) get_option('substack_sync_video_thumbnail_repaired'),
            'A failed reset must not re-arm the repair pass'
        );
    }

    public function test_retry_reset_lets_the_repair_look_again(): void
    {
        global $_wp_query_result;

        // A reset puts posts back in reach of the sync loop, which is the only
        // thing that turns a post the repair wrote off into one it can fix.
        update_option('substack_sync_video_thumbnail_repaired', true);
        update_option('substack_sync_video_thumbnail_repair_attempts', 5);
        $_wp_query_result = 1;

        (new Substack_Sync_Processor())->reset_failed_posts();

        $this->assertFalse(get_option('substack_sync_video_thumbnail_repaired'));
        $this->assertFalse(get_option('substack_sync_video_thumbnail_repair_attempts'));
    }

    public function test_retry_reset_that_changes_nothing_leaves_the_repair_alone(): void
    {
        global $_wp_query_result;

        update_option('substack_sync_video_thumbnail_repaired', true);
        $_wp_query_result = 0;

        (new Substack_Sync_Processor())->reset_failed_posts();

        $this->assertTrue(
            (bool) get_option('substack_sync_video_thumbnail_repaired'),
            'A reset with no rows to reset is not a reason to rerun a finished pass'
        );
    }

    public function test_progress_restarts_the_repair_attempt_clock(): void
    {
        global $_wp_get_results_rows, $_wp_post_meta, $_wp_thumbnails;

        // One post the pass can fix and one it never will. Counting every
        // outstanding sync let the permanent one spend the whole budget, so the
        // pass gave up on posts it was still working through: the cap has to
        // measure stalling, not elapsed syncs.
        $repairable = $this->insertVideoLeadingPost();
        set_post_thumbnail($repairable, 901);
        $_wp_post_meta[901] = ['_substack_sync_source_url' => 'https://cdn.example.com/later-photo.jpg'];
        $_wp_post_meta[900] = ['_substack_sync_source_url' => 'https://img.youtube.com/vi/KNFJSIj6xfQ/maxresdefault.jpg'];

        $stuck = $this->insertUnrewrittenVideoPost();
        set_post_thumbnail($stuck, 902);
        $_wp_post_meta[902] = ['_substack_sync_source_url' => 'https://cdn.example.com/later-photo.jpg'];

        $_wp_get_results_rows = ['SELECT DISTINCT post_id' => [
            ['post_id' => $repairable],
            ['post_id' => $stuck],
        ]];

        // One sync short of the cap, so counting this one would end the pass.
        update_option('substack_sync_video_thumbnail_repair_attempts', 4);

        $this->assertSame(1, (new Substack_Sync_Processor())->repair_video_featured_images());
        $this->assertSame(900, $_wp_thumbnails[$repairable]);
        $this->assertFalse(
            (bool) get_option('substack_sync_video_thumbnail_repaired'),
            'A pass that repaired something this sync is converging and must not be cut off'
        );
        $this->assertFalse(
            get_option('substack_sync_video_thumbnail_repair_attempts'),
            'Progress restarts the clock, so the cap bounds stalling rather than elapsed syncs'
        );
    }

    public function test_the_give_up_report_keeps_an_exact_count_past_the_list_cap(): void
    {
        global $_wp_get_results_rows;

        // The list is capped at 50 because it is a worklist for a person, but
        // rendering that cap as a total would tell the owner their backlog is
        // smaller than it is.
        $rows = [];
        for ($post = 0; $post < 51; $post++) {
            $rows[] = ['post_id' => $this->insertDeferrableVideoPost()];
        }
        $_wp_get_results_rows = ['SELECT DISTINCT post_id' => $rows];

        $processor = new Substack_Sync_Processor();
        $this->runRepairUntilItGivesUp($processor);

        $this->assertTrue((bool) get_option('substack_sync_video_thumbnail_repaired'));
        $this->assertCount(50, $processor->get_unrepaired_video_posts(), 'The named list stays bounded');
        $this->assertSame(
            51,
            $processor->get_unrepaired_video_count(),
            'The count is exact, or the admin screen understates the backlog'
        );
    }

    public function test_retry_reset_keeps_the_give_up_report_it_did_not_ask_about(): void
    {
        global $_wp_query_result;

        // Retry Failed Posts lives on another tab and gets pressed about
        // failures with nothing to do with video. Dropping the report would take
        // the owner's worklist, and the button offering to rerun the pass, off
        // the screen for as long as the pass needs to rebuild them.
        $first = $this->insertDeferrableVideoPost();
        $second = $this->insertDeferrableVideoPost();
        update_option('substack_sync_video_thumbnail_repaired', true);
        update_option('substack_sync_video_thumbnail_repair_attempts', 5);
        update_option(
            'substack_sync_video_thumbnail_repair_unrepaired',
            ['count' => 7, 'ids' => [$first, $second]]
        );
        $_wp_query_result = 1;

        $processor = new Substack_Sync_Processor();
        $processor->reset_failed_posts();

        $this->assertFalse(get_option('substack_sync_video_thumbnail_repaired'), 'The pass is re-armed');
        $this->assertFalse(get_option('substack_sync_video_thumbnail_repair_attempts'));
        $this->assertSame([$first, $second], $processor->get_unrepaired_video_posts(), 'The worklist survives');
        $this->assertSame(7, $processor->get_unrepaired_video_count());
    }

    public function test_failed_post_list_shows_posts_past_the_retry_ceiling(): void
    {
        global $_wp_get_results_calls, $_wp_get_results_rows;

        // Driven through the harness rather than asserted against the method's
        // source text: "retry_count<3" with no space reintroduces the bug and
        // reads nothing like the string a source-text assertion looks for.
        $_wp_get_results_rows = ['SELECT substack_guid' => [
            ['substack_guid' => 'g1', 'substack_title' => 'Dead', 'retry_count' => 3, 'error_message' => 'boom'],
        ]];

        $failed = (new Substack_Sync_Processor())->get_failed_posts();

        $this->assertCount(1, $failed);
        $this->assertSame(3, $failed[0]['retry_count'], 'A row at the ceiling is the one a person is asking about');

        $sql = $_wp_get_results_calls[0] ?? '';
        $this->assertStringContainsString("status = 'error'", $sql);
        $this->assertStringNotContainsString(
            'retry_count',
            $this->whereClauseOf($sql),
            'Hiding the rows no sync will pick up again hides exactly the ones needing a person'
        );
    }

    public function test_failed_post_list_shows_the_newest_failures_first(): void
    {
        global $_wp_get_results_calls;

        // Nothing filters this query any more, so oldest-first would fill the
        // 200-row window with the permanently exhausted backlog and push out
        // the recent failures somebody is actually diagnosing.
        (new Substack_Sync_Processor())->get_failed_posts();

        $this->assertStringContainsString('ORDER BY sync_date DESC', $_wp_get_results_calls[0] ?? '');
    }

    public function test_sync_now_cannot_spend_the_whole_budget_in_a_minute(): void
    {
        global $_wp_get_results_rows;

        // The budget is counted in syncs and Sync Now drives one on demand, so
        // an owner clicking it while diagnosing something would otherwise end
        // the pass before a stalled sideload had any chance to retry.
        $_wp_get_results_rows = ['SELECT DISTINCT post_id' => [
            ['post_id' => $this->insertDeferrableVideoPost()],
        ]];

        $processor = new Substack_Sync_Processor();
        for ($click = 1; $click <= 6; $click++) {
            $processor->repair_video_featured_images();
        }

        $this->assertSame(
            1,
            (int) get_option('substack_sync_video_thumbnail_repair_attempts'),
            'Six syncs inside one second are one hour of evidence, not six'
        );
        $this->assertFalse(
            (bool) get_option('substack_sync_video_thumbnail_repaired'),
            'Clicking Sync Now must not be able to make the pass give up'
        );
    }

    public function test_repair_does_not_defer_a_legacy_post_whose_video_trails_a_photo(): void
    {
        global $_wp_get_results_rows, $_wp_post_meta;

        // The pass only ever promotes a frame that holds the post's first image,
        // so a legacy post whose embed trails a body photo is one it would skip
        // even once rewritten. Deferring it put a post that is behaving as
        // designed on a worklist telling its owner to go fix it.
        $post_id = wp_insert_post([
            'post_title' => 'Photo first, clip further down',
            'post_content' => '<img src="https://files.example.com/lede.jpg">'
                . '<p>Body</p><div id="youtube2-KNFJSIj6xfQ" class="youtube-wrap"'
                . ' data-attrs="{&quot;videoId&quot;:&quot;KNFJSIj6xfQ&quot;}"></div>',
        ]);
        set_post_thumbnail($post_id, 901);
        $_wp_post_meta[901] = ['_substack_sync_source_url' => 'https://files.example.com/lede.jpg'];
        $_wp_get_results_rows = ['SELECT DISTINCT post_id' => [['post_id' => $post_id]]];

        $this->assertSame(0, (new Substack_Sync_Processor())->repair_video_featured_images());
        $this->assertTrue(
            (bool) get_option('substack_sync_video_thumbnail_repaired'),
            'A post the pass would skip by design is not outstanding work'
        );
    }

    public function test_repair_does_not_defer_a_legacy_post_whose_image_an_editor_chose(): void
    {
        global $_wp_get_results_rows;

        // The thumbnail gate would skip this post once it was rewritten, so
        // naming it in the give-up report asked the owner to overwrite exactly
        // the choice that gate exists to protect.
        $post_id = $this->insertUnrewrittenVideoPost();
        set_post_thumbnail($post_id, 950);

        $_wp_get_results_rows = ['SELECT DISTINCT post_id' => [['post_id' => $post_id]]];

        $this->assertSame(0, (new Substack_Sync_Processor())->repair_video_featured_images());
        $this->assertTrue(
            (bool) get_option('substack_sync_video_thumbnail_repaired'),
            "An editor's own featured image is a decision, not outstanding work"
        );
        $this->assertSame([], (new Substack_Sync_Processor())->get_unrepaired_video_posts());
    }

    public function test_the_worklist_drops_a_post_somebody_has_since_fixed(): void
    {
        global $_wp_get_results_rows, $_wp_thumbnails;

        // The report is a snapshot, so it goes stale the moment a person acts on
        // an entry. A fixed post that keeps being listed sends them back to it.
        $fixed = $this->insertDeferrableVideoPost();
        $outstanding = $this->insertDeferrableVideoPost();
        $_wp_get_results_rows = ['SELECT DISTINCT post_id' => [
            ['post_id' => $fixed],
            ['post_id' => $outstanding],
        ]];

        $processor = new Substack_Sync_Processor();
        $this->runRepairUntilItGivesUp($processor);

        $this->assertSame([$fixed, $outstanding], $processor->get_unrepaired_video_posts());
        $this->assertSame(2, $processor->get_unrepaired_video_count());

        // The owner sets their own image on one of them, which is the pass's own
        // signal that the post is no longer any of its business.
        $_wp_thumbnails[$fixed] = 950;

        $this->assertSame(
            [$outstanding],
            $processor->get_unrepaired_video_posts(),
            'A post whose featured image a person chose has left the worklist'
        );
        $this->assertSame(
            1,
            $processor->get_unrepaired_video_count(),
            'The total comes down with the list, or it renders as "1 of 2" forever'
        );
    }

    public function test_the_worklist_drops_a_post_that_no_longer_exists(): void
    {
        global $_wp_get_results_rows, $_wp_posts;

        $post_id = $this->insertDeferrableVideoPost();
        $_wp_get_results_rows = ['SELECT DISTINCT post_id' => [['post_id' => $post_id]]];

        $processor = new Substack_Sync_Processor();
        $this->runRepairUntilItGivesUp($processor);
        $this->assertSame([$post_id], $processor->get_unrepaired_video_posts());

        unset($_wp_posts[$post_id]);

        $this->assertSame([], $processor->get_unrepaired_video_posts(), 'A deleted post is not a worklist item');
        $this->assertSame(0, $processor->get_unrepaired_video_count());
    }

    public function test_restarting_after_a_retry_reset_does_not_silently_drop_the_worklist(): void
    {
        global $_wp_query_result;

        // reset_failed_posts() re-arms the pass and leaves the report standing on
        // purpose, so the button is still on screen with the flag already clear.
        // Reading the flag alone made that click delete the owner's only record
        // of which posts need a person while answering that nothing happened.
        $post_id = $this->insertDeferrableVideoPost();
        update_option('substack_sync_video_thumbnail_repaired', true);
        update_option('substack_sync_video_thumbnail_repair_unrepaired', ['count' => 1, 'ids' => [$post_id]]);
        $_wp_query_result = 1;

        $processor = new Substack_Sync_Processor();
        $processor->reset_failed_posts();

        $this->assertFalse(get_option('substack_sync_video_thumbnail_repaired'), 'The pass is armed again');
        $this->assertSame([$post_id], $processor->get_unrepaired_video_posts(), 'and the report is still up');

        $this->assertTrue(
            $processor->restart_video_thumbnail_repair(),
            'Dropping the worklist is something happening, whatever the flag said'
        );
        $this->assertSame([], $processor->get_unrepaired_video_posts());
    }

    public function test_restarting_with_nothing_to_restart_reports_nothing(): void
    {
        $processor = new Substack_Sync_Processor();

        $this->assertFalse(
            $processor->restart_video_thumbnail_repair(),
            'No flag and no report is the one case where the button really is a no-op'
        );
    }

    public function test_empty_feed_does_not_burn_the_repair_flag_on_the_cron_path(): void
    {
        global $_wp_feed_items;

        // The cron calls run_sync() with no status, which used to fall past the
        // zero-item return, run the repair against pre-rewrite content, find
        // nothing, and set the one-time flag for good.
        update_option('substack_sync_settings', ['feed_url' => 'https://example.substack.com/feed']);
        $_wp_feed_items = [];

        (new Substack_Sync_Processor())->run_sync();

        $this->assertFalse(
            (bool) get_option('substack_sync_video_thumbnail_repaired'),
            'A feed that rewrote nothing must leave the repair for a later sync'
        );
    }

    public function test_final_batch_runs_the_repair(): void
    {
        global $_wp_feed_items, $_wp_get_results_rows, $_wp_post_meta, $_wp_thumbnails;

        // The admin's Sync Now button drives run_batch_sync(), not run_sync(),
        // so a repair wired only into the latter can never be triggered by hand.
        update_option('substack_sync_settings', ['feed_url' => 'https://example.substack.com/feed']);
        $_wp_post_meta[900] = ['_substack_sync_source_url' => 'https://img.youtube.com/vi/KNFJSIj6xfQ/maxresdefault.jpg'];

        $post_id = $this->insertVideoLeadingPost();
        set_post_thumbnail($post_id, 901);
        $_wp_post_meta[901] = ['_substack_sync_source_url' => 'https://cdn.example.com/later-photo.jpg'];
        $_wp_get_results_rows = ['SELECT DISTINCT post_id' => [['post_id' => $post_id]]];
        $_wp_feed_items = [new Stub_Feed_Item('guid-1')];

        $result = (new Substack_Sync_Processor())->run_batch_sync(1, 0);

        $this->assertFalse($result['has_more'], 'Fixture must be the last batch');
        $this->assertSame(900, $_wp_thumbnails[$post_id]);
    }

    public function test_batch_with_more_to_come_defers_the_repair(): void
    {
        global $_wp_feed_items, $_wp_get_results_rows, $_wp_post_meta, $_wp_thumbnails;

        // Mid-run the loop has not rewritten the remaining posts yet, so the
        // repair would flag itself done having seen only part of the archive.
        update_option('substack_sync_settings', ['feed_url' => 'https://example.substack.com/feed']);
        $_wp_post_meta[900] = ['_substack_sync_source_url' => 'https://img.youtube.com/vi/KNFJSIj6xfQ/maxresdefault.jpg'];

        $post_id = $this->insertVideoLeadingPost();
        set_post_thumbnail($post_id, 901);
        $_wp_post_meta[901] = ['_substack_sync_source_url' => 'https://cdn.example.com/later-photo.jpg'];
        $_wp_get_results_rows = ['SELECT DISTINCT post_id' => [['post_id' => $post_id]]];
        $_wp_feed_items = [new Stub_Feed_Item('guid-1'), new Stub_Feed_Item('guid-2')];

        $result = (new Substack_Sync_Processor())->run_batch_sync(1, 0);

        $this->assertTrue($result['has_more'], 'Fixture must not be the last batch');
        $this->assertSame(901, $_wp_thumbnails[$post_id]);
        $this->assertFalse((bool) get_option('substack_sync_video_thumbnail_repaired'));
    }

    public function test_content_beside_the_embed_survives_the_rewrite(): void
    {
        update_option('substack_sync_settings', ['feed_url' => 'https://example.substack.com/feed']);

        // The replacement climbs to the outermost element that wraps the embed
        // and nothing else. Climbing on a "youtube" class instead would take
        // this whole section, caption included, and say nothing about it.
        $output = $this->invokeProcessContent(
            '<div class="youtube-section"><p>Caption above the video</p>'
            . '<div id="youtube2-KNFJSIj6xfQ" class="youtube-wrap">'
            . '<iframe src="https://www.youtube-nocookie.com/embed/KNFJSIj6xfQ"></iframe></div>'
            . '<figcaption>Episode 12</figcaption></div>'
        );

        $this->assertStringContainsString('Caption above the video', $output);
        $this->assertStringContainsString('<figcaption>Episode 12</figcaption>', $output);
        $this->assertStringContainsString('vi/KNFJSIj6xfQ/maxresdefault.jpg', $output);
        $this->assertStringNotContainsString('youtube-wrap', $output);
        $this->assertStringNotContainsString('<iframe', $output);
    }

    public function test_wrapper_is_replaced_through_a_plain_paragraph(): void
    {
        update_option('substack_sync_settings', ['feed_url' => 'https://example.substack.com/feed']);

        // A <p> between wrapper and iframe is still a pure wrapper, so the climb
        // continues through it. Stopping there would leave the aspect-ratio box
        // standing empty and nest a <figure> inside a <p>.
        $output = $this->invokeProcessContent(
            '<div class="youtube-wrap" style="padding-bottom:56.25%"><p>'
            . '<iframe src="https://www.youtube-nocookie.com/embed/KNFJSIj6xfQ"></iframe></p></div>'
        );

        $this->assertStringContainsString('vi/KNFJSIj6xfQ/maxresdefault.jpg', $output);
        $this->assertStringNotContainsString('padding-bottom', $output);
        $this->assertStringNotContainsString('<p>', $output);
    }

    public function test_unrelated_wrapper_id_is_not_read_as_a_video_id(): void
    {
        update_option('substack_sync_settings', ['feed_url' => 'https://example.substack.com/feed']);

        // "abcdefghijk" is 11 characters of the ID charset on an element the
        // climb now reaches, so the id is only a candidate with the Substack
        // prefix; the real ID is in the src.
        $output = $this->invokeProcessContent(
            '<div id="abcdefghijk"><iframe src="https://www.youtube.com/embed/KNFJSIj6xfQ"></iframe></div>'
        );

        $this->assertStringContainsString('vi/KNFJSIj6xfQ/maxresdefault.jpg', $output);
        $this->assertStringNotContainsString('abcdefghijk', $output);
    }

    public function test_youtube_frames_are_named_by_video_id_in_the_media_library(): void
    {
        // Every frame URL ends in the same /maxresdefault.jpg, so naming from
        // the basename gives one maxresdefault-N.jpg per video post.
        $processor = new Substack_Sync_Processor();
        $method = new ReflectionMethod($processor, 'filename_for_sideload');

        $this->assertSame(
            'youtube-KNFJSIj6xfQ.jpg',
            $method->invoke($processor, 'https://img.youtube.com/vi/KNFJSIj6xfQ/maxresdefault.jpg', '/nonexistent')
        );

        // Everything else still gets its name from the URL basename.
        $this->assertSame(
            'later-photo.jpg',
            $method->invoke($processor, 'https://cdn.example.com/later-photo.jpg', '/nonexistent')
        );
    }

    public function test_missing_maxres_frame_falls_back_to_hqdefault(): void
    {
        global $_wp_sideload_calls, $_wp_sideload_fail, $_wp_thumbnails, $_wp_post_meta;

        // maxres exists only for videos uploaded above 720p; YouTube says so by
        // 404ing. Without the retry those posts sideload nothing and keep the
        // body photo the whole rewrite exists to displace.
        $_wp_sideload_fail = ['maxresdefault.jpg'];

        $content = wp_kses_post($this->invokeProcessContent(
            '<div id="youtube2-KNFJSIj6xfQ" class="youtube-wrap">'
            . '<iframe src="https://www.youtube-nocookie.com/embed/KNFJSIj6xfQ"></iframe></div>'
            . '<p>Body</p><img src="https://cdn.example.com/later-photo.jpg">'
        ));

        $post_id = wp_insert_post(['post_title' => 'Video post', 'post_content' => $content, 'post_status' => 'publish']);
        $this->invokeProcessPostImages($post_id, $content);

        $this->assertSame(
            [
                'https://img.youtube.com/vi/KNFJSIj6xfQ/maxresdefault.jpg',
                'https://img.youtube.com/vi/KNFJSIj6xfQ/hqdefault.jpg',
                'https://cdn.example.com/later-photo.jpg',
            ],
            $_wp_sideload_calls,
            'The 404 must be retried once at hqdefault, and must not consume the failure budget the body photo needs'
        );

        $this->assertArrayHasKey($post_id, $_wp_thumbnails, 'A video with no maxres frame still gets a featured image');
        $this->assertSame(
            'https://img.youtube.com/vi/KNFJSIj6xfQ/maxresdefault.jpg',
            $_wp_post_meta[$_wp_thumbnails[$post_id]]['_substack_sync_source_url'] ?? null,
            'The source URL records the frame asked for, so dedupe and the repair lookup still resolve'
        );
    }

    public function test_a_failing_body_image_is_not_retried(): void
    {
        global $_wp_sideload_calls, $_wp_sideload_fail;

        // The retry is for one expected answer from one host, not a general
        // second attempt at every download.
        $_wp_sideload_fail = ['cdn.example.com'];
        $post_id = wp_insert_post(['post_title' => 'x', 'post_content' => 'p', 'post_status' => 'publish']);

        $this->invokeProcessPostImages($post_id, '<p><img src="https://cdn.example.com/photo.jpg"></p>');

        $this->assertSame(['https://cdn.example.com/photo.jpg'], $_wp_sideload_calls);
    }

    public function test_only_this_class_own_maxres_frame_urls_get_a_fallback(): void
    {
        $processor = new Substack_Sync_Processor();
        $method = new ReflectionMethod($processor, 'youtube_thumbnail_fallback_for');

        $this->assertSame(
            'https://img.youtube.com/vi/KNFJSIj6xfQ/hqdefault.jpg',
            $method->invoke($processor, 'https://img.youtube.com/vi/KNFJSIj6xfQ/maxresdefault.jpg')
        );

        foreach ([
            'a lookalike host' => 'https://evilimg.youtube.com/vi/KNFJSIj6xfQ/maxresdefault.jpg',
            'an unrelated host' => 'https://cdn.example.com/vi/KNFJSIj6xfQ/maxresdefault.jpg',
            'a frame already at the fallback size' => 'https://img.youtube.com/vi/KNFJSIj6xfQ/hqdefault.jpg',
            'an ID that is not 11 characters' => 'https://img.youtube.com/vi/short/maxresdefault.jpg',
            'a path with something appended' => 'https://img.youtube.com/vi/KNFJSIj6xfQ/maxresdefault.jpg/x',
        ] as $why => $url) {
            $this->assertSame('', $method->invoke($processor, $url), "No fallback for {$why}");
        }
    }

    public function test_the_thumbnail_asserts_no_dimensions_it_cannot_guarantee(): void
    {
        // The markup is written before anything is fetched, and the frame is
        // 1280x720 or, on the fallback, 480x360. Either hardcoded pair stretches
        // the other.
        $output = $this->invokeProcessContent(
            '<div class="youtube-wrap"><iframe src="https://www.youtube-nocookie.com/embed/KNFJSIj6xfQ"></iframe></div>'
        );

        $this->assertStringContainsString('vi/KNFJSIj6xfQ/maxresdefault.jpg', $output);
        $this->assertStringNotContainsString('width=', $output);
        $this->assertStringNotContainsString('height=', $output);
    }

    /**
     * Everything from WHERE onward, so a retry_count assertion reads the filter
     * rather than the SELECT list or the SET clause that legitimately name it.
     */
    private function whereClauseOf(string $sql): string
    {
        $where = stripos($sql, 'WHERE');

        return $where === false ? '' : substr($sql, $where);
    }

    /**
     * A legacy video post the pass would genuinely repair once it is rewritten:
     * unrewritten wrapper leading the body photo, and a featured image the
     * plugin sideloaded, so the pass is allowed to replace it. Both conditions
     * matter, since the pass defers only what it could actually fix.
     */
    private function insertDeferrableVideoPost(): int
    {
        global $_wp_post_meta;

        $post_id = $this->insertUnrewrittenVideoPost();
        set_post_thumbnail($post_id, 901);
        $_wp_post_meta[901] = ['_substack_sync_source_url' => 'https://cdn.example.com/later-photo.jpg'];

        return $post_id;
    }

    /**
     * One repair pass, an hour after the last one. The no-progress counter
     * advances at most hourly so an admin clicking Sync Now cannot spend the
     * budget in a minute, which means a loop inside one second has to move the
     * clock the way real time would have.
     */
    private function runRepairOnNextHour(Substack_Sync_Processor $processor): int
    {
        $advanced_at = get_option('substack_sync_video_thumbnail_repair_advanced_at');

        if ($advanced_at !== false) {
            update_option(
                'substack_sync_video_thumbnail_repair_advanced_at',
                (int) $advanced_at - HOUR_IN_SECONDS
            );
        }

        return $processor->repair_video_featured_images();
    }

    /**
     * Hourly passes until the pass gives up, capped so a regression that stops
     * it giving up fails the caller's assertion rather than hanging.
     */
    private function runRepairUntilItGivesUp(Substack_Sync_Processor $processor): void
    {
        for ($sync = 1; $sync <= 10; $sync++) {
            if (get_option('substack_sync_video_thumbnail_repaired')) {
                return;
            }

            $this->runRepairOnNextHour($processor);
        }
    }

    /**
     * A video post as an older sync stored it: wp_kses_post() ate the <iframe>
     * and left Substack's wrapper standing, id and data-attrs intact, with the
     * featured image taken from the body photo further down.
     */
    private function insertUnrewrittenVideoPost(): int
    {
        return wp_insert_post([
            'post_title' => 'Clash episode, pre-rewrite',
            'post_content' => '<div id="youtube2-KNFJSIj6xfQ" class="youtube-wrap"'
                . ' data-attrs="{&quot;videoId&quot;:&quot;KNFJSIj6xfQ&quot;}"></div>'
                . '<p>Body</p><img src="https://files.example.com/later-photo.jpg">',
        ]);
    }

    /**
     * A post whose content leads with the video figure, as a sync rewrites it:
     * localized <img> src, watch href carrying the ID verbatim, body photo below.
     */
    private function insertVideoLeadingPost(): int
    {
        return wp_insert_post([
            'post_title' => 'Clash episode',
            'post_content' => '<figure class="substack-video-embed">'
                . '<a href="https://www.youtube.com/watch?v=KNFJSIj6xfQ">'
                . '<img src="https://files.example.com/youtube-KNFJSIj6xfQ.jpg"></a>'
                . '</figure><p>Body</p><img src="https://files.example.com/later-photo.jpg">',
        ]);
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

    public function test_extensionless_image_url_is_sideloaded_and_set_as_thumbnail(): void
    {
        global $_wp_sideload_calls, $_wp_thumbnails;

        $post_id = wp_insert_post(['post_title' => 'x', 'post_content' => 'p', 'post_status' => 'publish']);

        // Substack hotlinks images from Unsplash-style CDNs whose path carries
        // no extension before the query string. media_sideload_image() rejects
        // these; the sideload must still succeed by sniffing the downloaded type.
        $src = 'https://images.unsplash.com/photo-1611463537830-69624771b809?fm=jpg&w=1080';
        $this->invokeProcessPostImages($post_id, '<p><img src="' . $src . '"></p>');

        $this->assertCount(1, $_wp_sideload_calls, 'The extension-less remote image must be fetched');
        $this->assertArrayHasKey($post_id, $_wp_thumbnails, 'The sideloaded image must become the featured image');

        $saved = get_post($post_id)->post_content;
        $this->assertStringNotContainsString('images.unsplash.com', $saved, 'Content must be rewritten to the local copy');
        $this->assertStringContainsString('myblog.example.com/wp-content/uploads/', $saved);
    }

    public function test_url_extension_fallback_when_bytes_are_unsniffable(): void
    {
        global $_wp_download_bytes, $_wp_thumbnails;

        // Downloaded bytes getimagesize() cannot identify (e.g. a truncated
        // file): the extension must fall back to the one in the URL path.
        $_wp_download_bytes = 'not a valid image';
        $post_id = wp_insert_post(['post_title' => 'x', 'post_content' => 'p', 'post_status' => 'publish']);

        $localized = $this->invokeProcessPostImages($post_id, '<p><img src="http://8.8.8.8/cover.jpg"></p>');

        $this->assertNotNull($localized, 'A URL-extension fallback must still localize the image');
        $this->assertArrayHasKey($post_id, $_wp_thumbnails, 'The fallback-typed image must become the featured image');
        $this->assertStringContainsString('myblog.example.com/wp-content/uploads/', get_post($post_id)->post_content);
    }

    public function test_unrecognized_image_is_skipped_without_a_thumbnail(): void
    {
        global $_wp_download_bytes, $_wp_sideload_calls, $_wp_thumbnails;

        // Non-image bytes AND an extension-less URL: no type is derivable, so
        // the sideload must fail gracefully, not store a bogus attachment.
        $_wp_download_bytes = 'not a valid image';
        $post_id = wp_insert_post(['post_title' => 'x', 'post_content' => 'p', 'post_status' => 'publish']);

        $localized = $this->invokeProcessPostImages($post_id, '<p><img src="https://images.unsplash.com/photo-abc?fm=jpg&w=1080"></p>');

        $this->assertNull($localized, 'An unrecognized download must not rewrite content');
        $this->assertArrayNotHasKey($post_id, $_wp_thumbnails, 'No featured image for an unrecognized download');
        $this->assertCount(1, $_wp_sideload_calls, 'The download was still attempted');
    }

    public function test_media_handle_sideload_failure_is_handled(): void
    {
        global $_wp_media_handle_fail, $_wp_sideload_calls, $_wp_thumbnails;

        $_wp_media_handle_fail = true;
        $post_id = wp_insert_post(['post_title' => 'x', 'post_content' => 'p', 'post_status' => 'publish']);

        $localized = $this->invokeProcessPostImages($post_id, '<p><img src="http://8.8.8.8/a.png"></p>');

        $this->assertNull($localized, 'A media_handle_sideload() failure must not rewrite content');
        $this->assertArrayNotHasKey($post_id, $_wp_thumbnails, 'No featured image when the attachment could not be created');
        $this->assertCount(1, $_wp_sideload_calls, 'The download was attempted before the sideload failed');
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

    // Regression: the sync-UI bootstrap (`new SubstackSyncProgress()` /
    // `new SubstackAdminManager()`) used to run at script-parse time, before the
    // later inline <script> block that defines SubstackSyncProgress had been
    // evaluated, so the settings page threw "SubstackSyncProgress is not
    // defined" and the controls never initialized. Both instantiations must sit
    // inside a DOMContentLoaded handler, which fires only after every inline
    // script (and the target button) has parsed.
    public function test_sync_ui_bootstrap_is_deferred_to_domcontentloaded(): void
    {
        $deferred = $this->extractDomContentLoadedBodies(self::$adminSource);

        foreach (['new SubstackSyncProgress()', 'new SubstackAdminManager()'] as $call) {
            $total = substr_count(self::$adminSource, $call);
            $this->assertGreaterThan(0, $total, "{$call} should be present");
            $this->assertSame(
                $total,
                substr_count($deferred, $call),
                "{$call} must run only inside a DOMContentLoaded handler, never at script-parse time"
            );
        }
    }

    // ---------------------------------------------------------------
    // Substack source-URL post meta: imported/updated posts must record
    // their canonical Substack URL as public post meta so a front-end
    // template (e.g. an Elementor Loop Grid) can link back to the original.
    // ---------------------------------------------------------------

    public function test_import_records_substack_source_url_meta(): void
    {
        global $_wp_post_meta;

        $item = new SimplePie_Item('Hello', 'body', 'guid-hello', 'https://example.substack.com/p/hello');

        $processor = new Substack_Sync_Processor();
        $method = new ReflectionMethod($processor, 'import_post');
        $result = $method->invoke($processor, $item, true);

        $this->assertTrue($result['success']);
        $this->assertSame(
            'https://example.substack.com/p/hello',
            $_wp_post_meta[$result['post_id']]['substack_source_url'] ?? null,
            'A freshly imported post must record its Substack URL as substack_source_url meta'
        );
    }

    public function test_update_records_substack_source_url_meta(): void
    {
        global $_wp_post_meta;

        $post_id = wp_insert_post(['post_title' => 'x', 'post_content' => 'p', 'post_status' => 'publish']);
        $item = new SimplePie_Item('Hello', 'body', 'guid-hello', 'https://example.substack.com/p/updated');

        $processor = new Substack_Sync_Processor();
        $method = new ReflectionMethod($processor, 'update_post');
        $result = $method->invoke($processor, $item, ['post_id' => $post_id], true);

        $this->assertTrue($result['success']);
        $this->assertSame(
            'https://example.substack.com/p/updated',
            $_wp_post_meta[$post_id]['substack_source_url'] ?? null,
            'An updated post must (back)fill its Substack URL meta on every sync'
        );
    }

    public function test_empty_permalink_does_not_clobber_stored_source_url(): void
    {
        global $_wp_post_meta;

        $post_id = wp_insert_post(['post_title' => 'x', 'post_content' => 'p', 'post_status' => 'publish']);
        $_wp_post_meta[$post_id] = ['substack_source_url' => 'https://example.substack.com/p/kept'];

        // A link-less feed item (get_permalink() === '') must not overwrite the
        // previously-recorded URL with an empty string.
        $item = new SimplePie_Item('Hello', 'body', 'guid-hello', ' ');

        $processor = new Substack_Sync_Processor();
        $method = new ReflectionMethod($processor, 'store_source_url');
        $method->invoke($processor, $post_id, $item);

        $this->assertSame(
            'https://example.substack.com/p/kept',
            $_wp_post_meta[$post_id]['substack_source_url']
        );
    }

    public function test_backfill_mirrors_log_table_guid_into_source_url_meta(): void
    {
        global $_wp_get_results_rows, $_wp_post_meta;

        $_wp_post_meta[502] = ['substack_source_url' => 'https://example.substack.com/p/already'];
        $_wp_get_results_rows = [
            'SELECT post_id, substack_guid' => [
                ['post_id' => 501, 'substack_guid' => 'https://example.substack.com/p/a'],
                ['post_id' => 502, 'substack_guid' => 'https://example.substack.com/p/b'],
                ['post_id' => 503, 'substack_guid' => 'not-a-url-guid'],
                ['post_id' => 504, 'substack_guid' => 'https://example.substack.com/p/d'],
            ],
        ];

        $processor = new Substack_Sync_Processor();
        $count = $processor->backfill_source_urls();

        $this->assertSame(2, $count, 'Only the two URL-guid posts missing meta should be backfilled');
        $this->assertSame('https://example.substack.com/p/a', $_wp_post_meta[501]['substack_source_url']);
        $this->assertSame('https://example.substack.com/p/d', $_wp_post_meta[504]['substack_source_url']);
        $this->assertSame(
            'https://example.substack.com/p/already',
            $_wp_post_meta[502]['substack_source_url'],
            'A post that already has the meta must not be overwritten'
        );
        $this->assertArrayNotHasKey(
            503,
            $_wp_post_meta,
            'A non-URL guid must be skipped, not stored as a bogus link'
        );
        $this->assertTrue((bool) get_option('substack_sync_source_url_backfilled'), 'Backfill must set its done flag');

        // Idempotent: a second run is a no-op once the flag is set.
        $this->assertSame(0, $processor->backfill_source_urls());
    }

    public function test_backfill_is_a_noop_when_already_flagged(): void
    {
        global $_wp_get_results_rows, $_wp_post_meta;

        update_option('substack_sync_source_url_backfilled', true);
        $_wp_get_results_rows = [
            'SELECT post_id, substack_guid' => [
                ['post_id' => 601, 'substack_guid' => 'https://example.substack.com/p/x'],
            ],
        ];

        $count = (new Substack_Sync_Processor())->backfill_source_urls();

        $this->assertSame(0, $count);
        $this->assertArrayNotHasKey(601, $_wp_post_meta, 'A flagged backfill must not touch post meta');
    }

    public function test_malformed_permalink_does_not_clobber_stored_source_url(): void
    {
        global $_wp_post_meta;

        $post_id = wp_insert_post(['post_title' => 'x', 'post_content' => 'p', 'post_status' => 'publish']);
        $_wp_post_meta[$post_id] = ['substack_source_url' => 'https://example.substack.com/p/kept'];

        // A non-empty link that esc_url_raw() rejects (reduces to '') must not
        // overwrite the previously-recorded URL: the empty guard runs on the
        // sanitized value, not the raw one.
        $item = new SimplePie_Item('Hello', 'body', 'guid-hello', 'not a real url');

        $processor = new Substack_Sync_Processor();
        $method = new ReflectionMethod($processor, 'store_source_url');
        $method->invoke($processor, $post_id, $item);

        $this->assertSame(
            'https://example.substack.com/p/kept',
            $_wp_post_meta[$post_id]['substack_source_url']
        );
    }

    public function test_backfill_does_not_flag_done_when_query_fails(): void
    {
        global $_wp_get_results_rows, $_wp_post_meta;

        // Simulate a DB error: $wpdb->get_results() returns null, not [].
        $_wp_get_results_rows = ['SELECT post_id, substack_guid' => null];

        $processor = new Substack_Sync_Processor();

        $this->assertSame(0, $processor->backfill_source_urls());
        $this->assertFalse(
            (bool) get_option('substack_sync_source_url_backfilled'),
            'A failed query must not permanently mark the backfill done'
        );

        // Once the DB recovers, the same processor actually backfills.
        $_wp_get_results_rows = [
            'SELECT post_id, substack_guid' => [
                ['post_id' => 701, 'substack_guid' => 'https://example.substack.com/p/recovered'],
            ],
        ];

        $this->assertSame(1, $processor->backfill_source_urls());
        $this->assertSame(
            'https://example.substack.com/p/recovered',
            $_wp_post_meta[701]['substack_source_url']
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

    /**
     * Concatenate the bodies of every `addEventListener('DOMContentLoaded', ...)`
     * callback in the source, brace-matched from the callback's opening `{`.
     *
     * Same naive brace counting as extractPhpMethod(); fine here because the
     * handler bodies in this file contain no `{`/`}` inside strings.
     */
    private function extractDomContentLoadedBodies(string $source): string
    {
        $needle = "addEventListener('DOMContentLoaded'";
        $bodies = '';
        $offset = 0;
        $len = strlen($source);

        while (($pos = strpos($source, $needle, $offset)) !== false) {
            $brace = strpos($source, '{', $pos);
            if ($brace === false) {
                break;
            }

            $depth = 0;
            for ($i = $brace; $i < $len; $i++) {
                if ($source[$i] === '{') {
                    $depth++;
                } elseif ($source[$i] === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $bodies .= substr($source, $brace, $i - $brace + 1);
                        break;
                    }
                }
            }

            // Advance past this match even if unbalanced, so we never loop.
            $offset = $pos + strlen($needle);
        }

        return $bodies;
    }
}
