<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Posts edited in WordPress stay edited, the Substack cover is the featured
 * image, and bylines fill the Authors taxonomy.
 */
class EditsCoversBylinesTest extends TestCase
{
    private const FEED = 'https://example.substack.com/feed';
    private const PERMALINK = 'https://example.substack.com/p/the-clash-vs-busyness';
    private const API = 'https://example.substack.com/api/v1/posts/the-clash-vs-busyness';

    protected function setUp(): void
    {
        reset_wp_stubs();
        update_option('substack_sync_settings', ['feed_url' => self::FEED]);
    }

    // --- Edit protection ---

    public function test_a_save_outside_the_sync_marks_a_synced_post_edited(): void
    {
        $post_id = $this->syncedPost();

        Substack_Sync_Processor::record_wordpress_edit($post_id, get_post($post_id), true);

        $this->assertTrue(Substack_Sync_Processor::is_edited_in_wordpress($post_id));
    }

    public function test_only_updates_of_synced_posts_count_as_edits(): void
    {
        $synced = $this->syncedPost();
        $other = wp_insert_post(['post_title' => 'Written here', 'post_content' => 'x', 'post_status' => 'publish']);

        Substack_Sync_Processor::record_wordpress_edit($synced, get_post($synced), false);
        Substack_Sync_Processor::record_wordpress_edit($other, get_post($other), true);

        $this->assertFalse(Substack_Sync_Processor::is_edited_in_wordpress($synced), 'Creating a post is not editing it');
        $this->assertFalse(Substack_Sync_Processor::is_edited_in_wordpress($other), 'The sync never wrote this post');
    }

    public function test_the_sync_own_writes_do_not_count_as_edits(): void
    {
        global $_wp_fire_save_post;

        $this->hookEditTracking();
        $_wp_fire_save_post = true;

        // Images make the import write twice: the insert, then the localized content.
        $content = '<p><img src="http://8.8.8.8/a.png"></p><p><img src="http://8.8.4.4/b.png"></p>';
        $item = new SimplePie_Item('Hello', $content, 'guid-hello', 'https://example.substack.com/p/hello');
        $result = $this->invoke('process_feed_item', $item, true);
        $post_id = (int) $result['post_id'];

        $this->assertSame('imported', $result['action']);
        $this->assertFalse(Substack_Sync_Processor::is_edited_in_wordpress($post_id));

        wp_update_post(['ID' => $post_id, 'post_title' => 'Fixed by hand']);

        $this->assertTrue(Substack_Sync_Processor::is_edited_in_wordpress($post_id), 'Anyone else saving it does count');
    }

    public function test_the_sync_leaves_an_edited_post_alone(): void
    {
        global $_wp_http_calls;

        $post_id = $this->existingPost('Fixed by hand');
        Substack_Sync_Processor::record_wordpress_edit($post_id, get_post($post_id), true);

        $result = $this->invoke('process_feed_item', $this->busynessItem(), true);

        $this->assertSame('skipped', $result['action']);
        $this->assertSame('edited in WordPress', $result['message']);
        $this->assertSame('Fixed by hand', get_post($post_id)->post_title);
        $this->assertSame([], $_wp_http_calls, 'Nothing is fetched for a post the sync will not write');
    }

    public function test_resuming_updates_lets_the_next_sync_write_the_post(): void
    {
        $post_id = $this->existingPost('Fixed by hand');
        Substack_Sync_Processor::record_wordpress_edit($post_id, get_post($post_id), true);
        Substack_Sync_Processor::resume_substack_updates($post_id);

        $result = $this->invoke('process_feed_item', $this->busynessItem(), true);

        $this->assertSame('updated', $result['action']);
        $this->assertSame('The Clash Vs. Busyness', get_post($post_id)->post_title);
    }

    public function test_the_video_repair_skips_an_edited_post(): void
    {
        global $_wp_get_results_rows;

        $post_id = wp_insert_post([
            'post_title' => 'Video',
            'post_status' => 'publish',
            'post_content' => '<figure class="substack-video-embed"><a href="https://www.youtube.com/watch?v=KNFJSIj6xfQ">'
                . '<img src="https://myblog.example.com/wp-content/uploads/frame.jpg"></a></figure>',
        ]);
        update_post_meta(5000, '_substack_sync_source_url', 'https://img.youtube.com/vi/KNFJSIj6xfQ/maxresdefault.jpg');
        update_post_meta(5001, '_substack_sync_source_url', 'https://cdn.example.com/photo.jpg');
        set_post_thumbnail($post_id, 5001);
        update_post_meta($post_id, '_substack_sync_edited', time());
        $_wp_get_results_rows = ['SELECT DISTINCT post_id' => [['post_id' => $post_id]]];

        $this->assertSame(0, (new Substack_Sync_Processor())->repair_video_featured_images());
        $this->assertSame(5001, get_post_thumbnail_id($post_id));
    }

    public function test_the_posts_list_marks_edited_posts_and_offers_to_resume(): void
    {
        $admin = new Substack_Sync_Admin();
        $edited = $this->syncedPost();
        $untouched = $this->syncedPost();
        update_post_meta($edited, '_substack_sync_edited', time());

        $this->assertSame(['substack_sync_edited' => 'Substack updates paused'], $admin->add_edited_post_state([], get_post($edited)));
        $this->assertSame([], $admin->add_edited_post_state([], get_post($untouched)));

        $link = $admin->add_resume_row_action([], get_post($edited))['substack_sync_resume'] ?? '';
        $this->assertStringContainsString('admin-post.php?action=substack_sync_resume&post=' . $edited, $link);
        $this->assertStringContainsString('_wpnonce=nonce-for-substack_sync_resume_' . $edited, $link);
        $this->assertStringContainsString('confirm(', $link, 'Resuming discards the edits, so it asks first');
        $this->assertSame([], $admin->add_resume_row_action([], get_post($untouched)));
    }

    public function test_the_resume_handler_checks_its_nonce_and_the_capability(): void
    {
        $source = file_get_contents(SUBSTACK_SYNC_PLUGIN_DIR . 'admin/class-substack-sync-admin.php');
        preg_match('/function handle_resume_updates\(\).*?\n    }\n/s', $source, $handler);

        $this->assertStringContainsString("check_admin_referer('substack_sync_resume_' . \$post_id)", $handler[0] ?? '');
        $this->assertStringContainsString("current_user_can('edit_post', \$post_id)", $handler[0] ?? '');
    }

    public function test_the_plugin_file_wires_edit_tracking_and_the_authors_taxonomy(): void
    {
        global $_wp_registered_taxonomies;

        require_once SUBSTACK_SYNC_PLUGIN_DIR . 'substack-sync.php';

        $this->assertTrue(has_action('save_post_post', [Substack_Sync_Processor::class, 'record_wordpress_edit']));
        $this->assertTrue(has_action('init', 'substack_sync_register_byline_taxonomy'));

        substack_sync_register_byline_taxonomy();
        $taxonomy = $_wp_registered_taxonomies[Substack_Sync_Processor::BYLINE_TAXONOMY] ?? [];

        $this->assertSame('post', $taxonomy['object_type'] ?? null);
        $this->assertTrue($taxonomy['args']['show_in_rest'] ?? false, 'The block editor shows only REST-enabled taxonomies');
        $this->assertSame('authors', $taxonomy['args']['rewrite']['slug'] ?? null);
    }

    public function test_upgrading_past_the_taxonomy_release_rebuilds_rewrite_rules(): void
    {
        update_option('rewrite_rules', ['cached' => 'rules']);
        update_option('substack_sync_version', '1.3.3');

        (new Substack_Sync_Processor())->maybe_upgrade('1.4.0');
        $this->assertFalse(get_option('rewrite_rules'), 'Author archives 404 until the rules are rebuilt');

        update_option('rewrite_rules', ['cached' => 'rules']);
        (new Substack_Sync_Processor())->maybe_upgrade('1.4.1');
        $this->assertSame(['cached' => 'rules'], get_option('rewrite_rules'), 'Only the upgrade across 1.4.0 rebuilds them');
    }

    public function test_cron_publishing_a_scheduled_post_is_not_an_edit(): void
    {
        $post_id = $this->syncedPost();
        $callback = static function (int $id): void {
            // What wp_publish_post() does from inside the publish_future_post cron event.
            Substack_Sync_Processor::record_wordpress_edit($id, get_post($id), true);
        };
        add_action('publish_future_post', $callback);

        do_action('publish_future_post', $post_id);

        $this->assertFalse(Substack_Sync_Processor::is_edited_in_wordpress($post_id));
    }

    public function test_a_save_made_while_the_sync_fetches_still_wins(): void
    {
        global $_wp_on_download;

        $post_id = $this->existingPost('Fixed by hand');
        // What an editor's save in another request leaves behind mid-sync.
        $_wp_on_download = static function () use ($post_id): void {
            update_post_meta($post_id, '_substack_sync_edited', time());
        };

        $item = new SimplePie_Item('Substack title', '<p><img src="http://8.8.8.8/new.png"></p>', 'guid-busyness', self::PERMALINK);
        $result = $this->invoke('process_feed_item', $item, true);

        $this->assertSame('skipped', $result['action']);
        $this->assertSame('Fixed by hand', get_post($post_id)->post_title);
        $this->assertSame(0, get_post_thumbnail_id($post_id), 'Its featured image is left alone too');
    }

    public function test_a_save_made_while_an_import_fetches_still_wins(): void
    {
        global $_wp_on_download, $_wp_object_terms;

        // A person opening the new post while its images download.
        $_wp_on_download = static function (): void {
            foreach (array_keys($GLOBALS['_wp_posts']) as $id) {
                update_post_meta($id, '_substack_sync_edited', time());
            }
        };

        $item = new SimplePie_Item(
            'New post',
            '<p><img src="http://8.8.8.8/a.png"></p><p>Text</p>',
            'guid-new',
            self::PERMALINK,
            authors: [new SimplePie_Author('Ricky Alcantar')]
        );
        $post_id = (int) $this->invoke('process_feed_item', $item, true)['post_id'];

        $this->assertSame(0, get_post_thumbnail_id($post_id));
        $this->assertStringContainsString('http://8.8.8.8/a.png', get_post($post_id)->post_content, 'No second write');
        $this->assertArrayNotHasKey($post_id, $_wp_object_terms, 'No bylines written over theirs');
    }

    public function test_a_featured_image_set_during_the_cover_download_is_kept(): void
    {
        global $_wp_on_download;

        // Set with no save, as the classic editor's picker does: only a fresh read sees it.
        $post_id = $this->syncedPost();
        $_wp_on_download = static function () use ($post_id): void {
            set_post_thumbnail($post_id, 999);
        };

        $this->processImages($post_id, '<p>Show notes</p>', 'http://8.8.8.8/episode-art.png');

        $this->assertSame(999, get_post_thumbnail_id($post_id));
    }

    public function test_the_image_step_decides_but_writes_nothing_itself(): void
    {
        $post_id = $this->syncedPost();
        $cover = 'http://8.8.8.8/cover.jpg';

        $images = $this->invoke('process_post_images', $post_id, '<p>Intro</p>' . $this->substackImage($cover, 'Credit'), $cover);

        $this->assertSame(0, get_post_thumbnail_id($post_id));
        $this->assertGreaterThan(0, $images['thumbnail']);
        $this->assertSame([$images['thumbnail'] => 'Credit'], $images['captions']);
        $this->assertSame('', get_post_field('post_excerpt', $images['thumbnail']), 'The caption waits for write_images()');
    }

    public function test_post_dates_are_given_to_wordpress_as_gmt(): void
    {
        // America/Kentucky/Louisville in September. A bare post_date is read as
        // local time, which put every synced post four hours late.
        update_option('gmt_offset', -4);
        $item = new SimplePie_Item('x', '<p>y</p>', 'guid-x', self::PERMALINK, '2026-09-18 23:25:15');

        $post_data = $this->invoke('prepare_post_data', $item, null);

        $this->assertSame('2026-09-18 23:25:15', $post_data['post_date_gmt']);
        $this->assertSame('2026-09-18 19:25:15', $post_data['post_date']);
    }

    public function test_a_future_date_is_capped_at_now_so_the_post_is_not_scheduled(): void
    {
        $item = new SimplePie_Item('x', '<p>y</p>', 'guid-x', self::PERMALINK, '2099-01-01 00:00:00');

        $post_data = $this->invoke('prepare_post_data', $item, null);

        $this->assertLessThanOrEqual(time(), strtotime($post_data['post_date_gmt'] . ' UTC'));
    }

    // --- Bylines ---

    public function test_bylines_come_from_the_api_when_it_answers(): void
    {
        global $_wp_object_terms;

        $post_id = $this->syncedPost();

        $this->invoke('assign_bylines', $post_id, $this->busynessItem(), $this->apiPost(['Ricky Alcantar', 'Ben Kreps']));

        $this->assertSame(['Ricky Alcantar', 'Ben Kreps'], $_wp_object_terms[$post_id]['byline']);
    }

    public function test_the_feed_byline_fills_an_empty_list_but_never_replaces_one(): void
    {
        global $_wp_object_terms;

        $post_id = $this->syncedPost();

        $this->invoke('assign_bylines', $post_id, $this->busynessItem(), null);
        $this->assertSame(['Ricky Alcantar'], $_wp_object_terms[$post_id]['byline']);

        wp_set_object_terms($post_id, ['Ricky Alcantar', 'Ben Kreps'], 'byline');
        $this->invoke('assign_bylines', $post_id, $this->busynessItem(), null);
        $this->assertSame(['Ricky Alcantar', 'Ben Kreps'], $_wp_object_terms[$post_id]['byline'], 'An API outage must not drop a co-host');
    }

    public function test_a_post_published_without_a_byline_has_no_authors(): void
    {
        global $_wp_object_terms;

        $post_id = $this->syncedPost();
        wp_set_object_terms($post_id, ['Stale Name'], 'byline');

        $this->invoke('assign_bylines', $post_id, $this->busynessItem(), $this->apiPost([]));

        $this->assertSame([], $_wp_object_terms[$post_id]['byline']);
    }

    public function test_the_api_answer_is_normalized_and_cached(): void
    {
        global $_wp_http_responses, $_wp_http_calls;

        $_wp_http_responses[self::API] = $this->apiResponse([
            'publishedBylines' => [['name' => 'Ricky Alcantar'], ['name' => '<b>Ben Kreps</b>'], ['name' => 'Ricky Alcantar'], ['handle' => 'x']],
            'cover_image' => 'https://substack-video.s3.amazonaws.com/video_upload/post/1/f/transcoded-1.png',
            'video_upload_id' => 'f1ce0644',
        ]);

        $first = $this->invoke('fetch_substack_post', $this->busynessItem());
        $second = $this->invoke('fetch_substack_post', $this->busynessItem());

        $this->assertSame([
            'bylines' => ['Ricky Alcantar', 'Ben Kreps'],
            'cover_image' => 'https://substack-video.s3.amazonaws.com/video_upload/post/1/f/transcoded-1.png',
            'has_video' => true,
        ], $first);
        $this->assertSame($first, $second);
        $this->assertSame([self::API], $_wp_http_calls, 'The second read comes from the cache');
    }

    public function test_a_failed_api_call_is_cached_too(): void
    {
        global $_wp_http_calls;

        $this->assertNull($this->invoke('fetch_substack_post', $this->busynessItem()));
        $this->assertNull($this->invoke('fetch_substack_post', $this->busynessItem()));
        $this->assertCount(1, $_wp_http_calls, 'An outage costs a request an hour, not one per post per sync');
    }

    public function test_the_api_is_asked_only_about_posts_on_the_configured_publication(): void
    {
        global $_wp_http_calls;

        foreach ([
            'https://evil.example.com/p/the-clash-vs-busyness',
            'https://example.substack.com/p/../../admin',
            'https://example.substack.com/about',
        ] as $permalink) {
            $this->assertNull($this->invoke('fetch_substack_post', new SimplePie_Item('x', 'y', 'guid-x', $permalink)), $permalink);
        }

        $this->assertSame([], $_wp_http_calls);
    }

    // --- The cover as the featured image ---

    public function test_the_cover_is_featured_and_its_body_copy_goes_with_its_caption(): void
    {
        global $_wp_sideload_calls;

        $post_id = $this->syncedPost();
        $first = $this->cdnUrl('$s_!EUW5!,w_1456,c_limit', 'b5520c01_181x258.jpeg');
        $cover = $this->cdnUrl('$s_!Ab12!,w_1456,c_limit', 'a140d4b7_600x420.jpeg');
        $content = '<p>Opening</p>' . $this->substackImage($first, '') . '<p>Middle</p>'
            . $this->substackImage($cover, 'Photo by Someone on Unsplash') . '<p>End</p>';

        // The feed's enclosure: the same upload under different CDN parameters.
        $localized = $this->processImages($post_id, $content, $this->cdnUrl('$s_!jddO!,f_auto', 'a140d4b7_600x420.jpeg'));

        $this->assertSame($cover, get_post_meta(get_post_thumbnail_id($post_id), '_substack_sync_source_url', true));
        $this->assertCount(2, $_wp_sideload_calls, 'The body copy is the cover, so it is not downloaded twice');
        $this->assertStringNotContainsString('Photo by Someone', $localized);
        $this->assertSame(1, substr_count($localized, 'captioned-image-container'), 'The other image stays');
        $this->assertStringContainsString('<p>Middle</p><p>End</p>', $localized);
    }

    public function test_a_cover_replaces_the_image_the_plugin_picked_before(): void
    {
        $post_id = $this->syncedPost();
        $body = '<p><img src="http://8.8.8.8/first.png"></p><p>Text</p>';
        $this->processImages($post_id, $body);
        $earlier = get_post_thumbnail_id($post_id);

        $localized = $this->processImages($post_id, $body . '<p><img src="http://8.8.4.4/cover.png"></p>', 'http://8.8.4.4/cover.png?w=1080');

        $this->assertSame('http://8.8.4.4/cover.png', get_post_meta(get_post_thumbnail_id($post_id), '_substack_sync_source_url', true));
        $this->assertStringContainsString(wp_get_attachment_url($earlier), $localized, 'The earlier pick is an ordinary body image again');
        $this->assertStringNotContainsString(wp_get_attachment_url(get_post_thumbnail_id($post_id)), $localized);
    }

    public function test_a_featured_image_someone_chose_survives_the_cover(): void
    {
        global $_wp_sideload_calls;

        $post_id = $this->syncedPost();
        set_post_thumbnail($post_id, 999);

        $localized = $this->processImages($post_id, '<p><img src="http://8.8.8.8/body.png"></p>', 'http://8.8.4.4/cover.png');

        $this->assertSame(999, get_post_thumbnail_id($post_id));
        $this->assertSame(['http://8.8.8.8/body.png'], $_wp_sideload_calls, 'A cover that cannot be used is not downloaded');
        $this->assertStringContainsString('myblog.example.com/wp-content/uploads/', $localized, 'Nothing leaves the body');
    }

    public function test_without_a_cover_the_first_image_only_fills_an_empty_slot(): void
    {
        $post_id = $this->syncedPost();
        update_post_meta(5000, '_substack_sync_source_url', 'https://img.youtube.com/vi/KNFJSIj6xfQ/maxresdefault.jpg');
        set_post_thumbnail($post_id, 5000);

        $this->processImages($post_id, '<p><img src="http://8.8.8.8/photo.png"></p>');

        $this->assertSame(5000, get_post_thumbnail_id($post_id), 'A frame the repair set is not traded for a body photo');
    }

    public function test_a_podcast_with_no_body_images_still_gets_its_cover(): void
    {
        $post_id = $this->syncedPost();

        $this->assertNull($this->processImages($post_id, '<p>Show notes</p>', 'http://8.8.8.8/episode-art.png'));
        $this->assertSame('http://8.8.8.8/episode-art.png', get_post_meta(get_post_thumbnail_id($post_id), '_substack_sync_source_url', true));
    }

    public function test_one_picture_under_its_many_urls_has_one_identity(): void
    {
        $s3 = 'substack-post-media.s3.amazonaws.com/public/images/a140d4b7_600x420.jpeg';

        $this->assertSame($s3, $this->invoke('image_identity', $this->cdnUrl('$s_!jddO!,f_auto', 'a140d4b7_600x420.jpeg')));
        $this->assertSame($s3, $this->invoke('image_identity', 'https://substackcdn.com/image/fetch/$s_!EUW5!,w_1456/https://' . $s3));
        $this->assertSame($s3, $this->invoke('image_identity', 'https://' . $s3));
        $this->assertSame('images.unsplash.com/photo-1', $this->invoke('image_identity', 'https://images.unsplash.com/photo-1?w=1080&fm=jpg'));
        $this->assertSame('youtube:KNFJSIj6xfQ', $this->invoke('image_identity', 'https://substackcdn.com/image/youtube/w_728,c_limit/KNFJSIj6xfQ'));
        $this->assertSame('youtube:KNFJSIj6xfQ', $this->invoke('image_identity', 'https://img.youtube.com/vi/KNFJSIj6xfQ/hqdefault.jpg'));
    }

    public function test_the_cover_comes_from_the_api_then_the_image_enclosure(): void
    {
        $image = new SimplePie_Item('x', 'y', enclosure: new SimplePie_Enclosure('https://substackcdn.com/image/fetch/a/b.jpeg', 'image/jpeg'));
        $audio = new SimplePie_Item('x', 'y', enclosure: new SimplePie_Enclosure('https://api.substack.com/feed/podcast/1/x.mp3', 'audio/mpeg'));

        $this->assertSame('https://substackcdn.com/image/fetch/a/b.jpeg', $this->invoke('cover_image_url', $image, null));
        $this->assertSame('', $this->invoke('cover_image_url', $audio, null), 'An mp3 is not a cover');
        $this->assertSame('https://example.com/art.png', $this->invoke('cover_image_url', $audio, $this->apiPost([], 'https://example.com/art.png')));
    }

    public function test_a_podcast_episode_imports_with_its_audio_bylines_and_cover(): void
    {
        global $_wp_http_responses, $_wp_object_terms;

        $_wp_http_responses[self::API] = $this->apiResponse([
            'publishedBylines' => [['name' => 'Ricky Alcantar'], ['name' => 'Ben Kreps']],
            'cover_image' => 'http://8.8.8.8/transcoded-1.png',
            'video_upload_id' => 'f1ce0644',
        ]);
        $audio = 'https://api.substack.com/feed/podcast/211893209/67519b40dd741520259bbd7f32e6c5dc.mp3';

        $result = $this->invoke('process_feed_item', $this->busynessItem(new SimplePie_Enclosure($audio, 'audio/mpeg')), true);
        $post_id = (int) $result['post_id'];

        $this->assertSame('imported', $result['action']);
        $content = get_post($post_id)->post_content;
        $this->assertStringStartsWith("[audio src=\"{$audio}\"]", $content);
        $this->assertStringContainsString('href="' . self::PERMALINK . '">Watch the video on Substack</a>', $content);
        $this->assertStringEndsWith('<p>Show notes</p>', $content);
        $this->assertSame(['Ricky Alcantar', 'Ben Kreps'], $_wp_object_terms[$post_id]['byline']);
        $this->assertSame('http://8.8.8.8/transcoded-1.png', get_post_meta(get_post_thumbnail_id($post_id), '_substack_sync_source_url', true));
    }

    public function test_a_caption_paragraph_leaves_with_its_image_and_lands_on_the_attachment(): void
    {
        $post_id = $this->syncedPost();
        $cover = $this->cdnUrl('$s_!Ab12!,w_1456', 'a140d4b7_600x420.jpeg');
        // Verbatim caption shape from "The Dearest Place on Earth".
        $content = '<p>Intro</p>' . $this->substackImage($cover, '')
            . '<p style="text-align: center;"><em><span>The Metropolitan Tabernacle, London</span></em></p><p>Next</p>';

        $localized = $this->processImages($post_id, $content, $cover);

        $this->assertSame('<p>Intro</p><p>Next</p>', $localized);
        $this->assertSame('The Metropolitan Tabernacle, London', get_post_field('post_excerpt', get_post_thumbnail_id($post_id)));
    }

    public function test_an_unsplash_credit_moves_to_the_attachment_and_a_set_caption_is_kept(): void
    {
        $first = $this->syncedPost();
        $second = $this->syncedPost();
        $cover = 'http://8.8.8.8/photo-1611463537830.jpg';
        $body = '<p>Intro</p>' . $this->substackImage($cover, 'Photo by Someone on Unsplash');

        $this->processImages($first, $body, $cover);
        $attachment = get_post_thumbnail_id($first);
        $this->assertSame('Photo by Someone on Unsplash', get_post_field('post_excerpt', $attachment));

        wp_update_post(['ID' => $attachment, 'post_excerpt' => 'Set by hand']);
        $this->processImages($second, $body, $cover);
        $this->assertSame('Set by hand', get_post_field('post_excerpt', $attachment));
    }

    public function test_a_centered_paragraph_that_is_not_all_emphasis_stays(): void
    {
        $post_id = $this->syncedPost();
        $cover = 'http://8.8.8.8/cover.jpg';
        $after = '<p style="text-align: center;">A centered line with <em>some</em> emphasis</p>';

        $localized = $this->processImages($post_id, $this->substackImage($cover, '') . $after, $cover);

        $this->assertSame($after, $localized);
    }

    // --- API outages ---

    public function test_an_api_outage_reuses_the_posts_last_answer(): void
    {
        global $_wp_http_responses, $_wp_transients, $_wp_object_terms;

        $post_id = $this->existingPost('The Clash Vs. Busyness');
        $audio = 'https://api.substack.com/feed/podcast/211893209/67519b40dd741520259bbd7f32e6c5dc.mp3';
        $item = $this->busynessItem(new SimplePie_Enclosure($audio, 'audio/mpeg'));
        $_wp_http_responses[self::API] = $this->apiResponse([
            'publishedBylines' => [['name' => 'Ricky Alcantar'], ['name' => 'Ben Kreps']],
            'video_upload_id' => 'f1ce0644',
        ]);
        $this->invoke('process_feed_item', $item, true);

        // The answer's cache runs out while the API is down.
        $_wp_http_responses = [];
        $_wp_transients = [];
        $this->assertSame('updated', $this->invoke('process_feed_item', $item, true)['action']);

        $this->assertStringContainsString('Watch the video on Substack', get_post($post_id)->post_content);
        $this->assertSame(['Ricky Alcantar', 'Ben Kreps'], $_wp_object_terms[$post_id]['byline']);
    }

    public function test_a_new_post_remembers_its_answer_for_the_next_outage(): void
    {
        global $_wp_http_responses, $_wp_transients, $_wp_get_row_rows;

        $_wp_http_responses[self::API] = $this->apiResponse(['video_upload_id' => 'f1ce0644']);
        $audio = new SimplePie_Enclosure('https://api.substack.com/feed/podcast/1/episode.mp3', 'audio/mpeg');
        $post_id = (int) $this->invoke('process_feed_item', $this->busynessItem($audio), true)['post_id'];

        $_wp_http_responses = [];
        $_wp_transients = [];
        $_wp_get_row_rows = ["substack_guid = 'guid-busyness'" => ['post_id' => $post_id, 'retry_count' => 0]];
        $this->invoke('process_feed_item', $this->busynessItem($audio), true);

        $this->assertStringContainsString('Watch the video on Substack', get_post($post_id)->post_content);
    }

    public function test_the_stored_answer_keeps_its_backslashes(): void
    {
        global $_wp_http_responses, $_wp_transients, $_wp_object_terms;

        $post_id = $this->existingPost('x');
        $_wp_http_responses[self::API] = $this->apiResponse(['publishedBylines' => [['name' => 'A\B Byline']]]);
        $this->invoke('process_feed_item', $this->busynessItem(), true);

        $_wp_http_responses = [];
        $_wp_transients = [];
        wp_set_object_terms($post_id, [], 'byline');
        $this->invoke('process_feed_item', $this->busynessItem(), true);

        $this->assertSame(['A\B Byline'], $_wp_object_terms[$post_id]['byline']);
    }

    // --- Backslashes: core's writers unslash, so unslashed feed text lost them ---

    public function test_backslashes_in_a_title_and_body_survive_the_import_and_the_update(): void
    {
        global $_wp_get_row_rows;

        // Images, so the import also takes its second write, the localized content.
        $body = '<p>Match \d+ in a regex.</p><p><img src="http://8.8.8.8/a.png"></p><p><img src="http://8.8.4.4/b.png"></p>';
        $item = new SimplePie_Item('Paths like C:\Users\Jenn', $body, 'guid-busyness', self::PERMALINK);

        $post_id = (int) $this->invoke('process_feed_item', $item, true)['post_id'];
        $this->assertSame('Paths like C:\Users\Jenn', get_post($post_id)->post_title);
        $this->assertStringContainsString('myblog.example.com/wp-content/uploads/', get_post($post_id)->post_content);
        $this->assertStringStartsWith('<p>Match \d+ in a regex.</p>', get_post($post_id)->post_content);

        // The log table now knows the post, so the next sync is an update.
        $_wp_get_row_rows = ["substack_guid = 'guid-busyness'" => ['post_id' => $post_id, 'retry_count' => 0]];
        $item = new SimplePie_Item('Now C:\Temp', '<p>Type \n for a newline.</p>', 'guid-busyness', self::PERMALINK);
        $this->assertSame('updated', $this->invoke('process_feed_item', $item, true)['action']);
        $this->assertSame('Now C:\Temp', get_post($post_id)->post_title);
        $this->assertSame('<p>Type \n for a newline.</p>', get_post($post_id)->post_content);
    }

    public function test_backslashes_in_a_byline_and_a_caption_survive(): void
    {
        global $_wp_object_terms;

        $post_id = $this->syncedPost();
        $this->invoke('assign_bylines', $post_id, $this->busynessItem(), $this->apiPost(['A\B Byline']));
        $this->assertSame(['A\B Byline'], $_wp_object_terms[$post_id]['byline']);

        $cover = 'http://8.8.8.8/cover.jpg';
        $this->processImages($post_id, '<p>Intro</p>' . $this->substackImage($cover, 'Scan of C:\Archive'), $cover);
        $this->assertSame('Scan of C:\Archive', get_post_field('post_excerpt', get_post_thumbnail_id($post_id)));
    }

    // --- Helpers ---

    private function invoke(string $method, ...$args)
    {
        $processor = new Substack_Sync_Processor();

        return (new ReflectionMethod($processor, $method))->invoke($processor, ...$args);
    }

    /** process_post_images() and write_images() in the order the sync runs them. */
    private function processImages(int $post_id, string $content, string $cover_url = ''): ?string
    {
        $images = $this->invoke('process_post_images', $post_id, $content, $cover_url);
        $this->invoke('write_images', $post_id, $images);

        return $images['content'];
    }

    private function syncedPost(string $title = 'Synced'): int
    {
        $post_id = wp_insert_post(['post_title' => $title, 'post_content' => '<p>x</p>', 'post_status' => 'publish']);
        update_post_meta($post_id, 'substack_source_url', self::PERMALINK);

        return $post_id;
    }

    /** A synced post the log table knows, so process_feed_item() routes to an update. */
    private function existingPost(string $title): int
    {
        global $_wp_get_row_rows;

        $post_id = $this->syncedPost($title);
        $_wp_get_row_rows = ["substack_guid = 'guid-busyness'" => ['post_id' => $post_id, 'retry_count' => 0]];

        return $post_id;
    }

    private function busynessItem(?SimplePie_Enclosure $enclosure = null): SimplePie_Item
    {
        return new SimplePie_Item(
            'The Clash Vs. Busyness',
            '<p>Show notes</p>',
            'guid-busyness',
            self::PERMALINK,
            enclosure: $enclosure,
            authors: [new SimplePie_Author('Ricky Alcantar')]
        );
    }

    private function hookEditTracking(): void
    {
        $callback = [Substack_Sync_Processor::class, 'record_wordpress_edit'];
        if (! has_action('save_post_post', $callback)) {
            add_action('save_post_post', $callback, 10, 3);
        }
    }

    private function apiPost(array $bylines, string $cover = ''): array
    {
        return ['bylines' => $bylines, 'cover_image' => $cover, 'has_video' => false];
    }

    private function apiResponse(array $data): array
    {
        return ['response' => ['code' => 200], 'body' => json_encode($data)];
    }

    private function cdnUrl(string $transforms, string $file): string
    {
        return 'https://substackcdn.com/image/fetch/' . $transforms
            . '/https%3A%2F%2Fsubstack-post-media.s3.amazonaws.com%2Fpublic%2Fimages%2F' . $file;
    }

    private function substackImage(string $src, string $caption): string
    {
        return '<div class="captioned-image-container"><figure><a class="image-link image2" href="' . $src . '">'
            . '<div class="image2-inset"><picture><source type="image/webp" srcset="' . $src . ' 424w">'
            . '<img src="' . $src . '" class="sizing-normal"></picture></div></a>'
            . ($caption !== '' ? '<figcaption class="image-caption">' . $caption . '</figcaption>' : '')
            . '</figure></div>';
    }
}
