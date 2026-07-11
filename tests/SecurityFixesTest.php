<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests confirming that each patched vulnerability was real and is now fixed.
 *
 * Each test documents the pre-fix behavior, then asserts the fix is in place.
 * If any of these tests fail, a regression has been introduced.
 */
class SecurityFixesTest extends TestCase
{
    private static string $processorSource;
    private static string $adminSource;

    public static function setUpBeforeClass(): void
    {
        self::$processorSource = file_get_contents(
            dirname(__DIR__) . '/includes/class-substack-sync-processor.php'
        );
        self::$adminSource = file_get_contents(
            dirname(__DIR__) . '/admin/class-substack-sync-admin.php'
        );
    }

    // ---------------------------------------------------------------
    // 1. Draft-revert bug
    //
    // VULNERABILITY: update_post() hardcoded post_status to 'draft',
    // so every hourly cron cycle reverted published posts to draft.
    //
    // FIX: unset post_status in update_post() so existing status is
    // preserved by wp_update_post().
    // ---------------------------------------------------------------

    public function test_update_post_does_not_hardcode_draft_status(): void
    {
        $this->assertStringNotContainsString(
            "'post_status' => 'draft'",
            $this->getUpdatePostMethod(),
            'update_post() must not hardcode post_status to draft'
        );
    }

    public function test_update_post_unsets_post_status(): void
    {
        $this->assertStringContainsString(
            "unset(\$post_data['post_status'])",
            $this->getUpdatePostMethod(),
            'update_post() must unset post_status so wp_update_post preserves the existing value'
        );
    }

    public function test_published_post_stays_published_after_update(): void
    {
        global $_wp_posts, $_wp_post_id_counter;
        $_wp_posts = [];
        $_wp_post_id_counter = 200;

        $post_id = wp_insert_post([
            'post_title' => 'Test Post',
            'post_content' => 'Content',
            'post_status' => 'publish',
        ]);

        $this->assertEquals('publish', get_post($post_id)->post_status);

        $update_data = [
            'ID' => $post_id,
            'post_title' => 'Updated Title',
            'post_content' => 'Updated content',
        ];
        wp_update_post($update_data);

        $this->assertEquals(
            'publish',
            get_post($post_id)->post_status,
            'Post status must remain "publish" when update_data omits post_status'
        );
    }

    // ---------------------------------------------------------------
    // 2. Stored XSS in admin log viewer
    //
    // VULNERABILITY: refreshLogs() used innerHTML to render
    // substack_title, allowing script injection via crafted titles.
    //
    // FIX: switched to textContent + createElement.
    // ---------------------------------------------------------------

    public function test_refresh_logs_does_not_use_innerHTML_for_titles(): void
    {
        $refreshLogsMethod = $this->extractJsFunction('refreshLogs');

        $this->assertStringNotContainsString(
            'innerHTML',
            $refreshLogsMethod,
            'refreshLogs() must not use innerHTML (XSS vector for substack_title)'
        );
    }

    public function test_refresh_logs_uses_textContent(): void
    {
        $refreshLogsMethod = $this->extractJsFunction('refreshLogs');

        $this->assertStringContainsString(
            'textContent',
            $refreshLogsMethod,
            'refreshLogs() must use textContent to safely render post titles'
        );
    }

    public function test_refresh_logs_uses_createElement(): void
    {
        $refreshLogsMethod = $this->extractJsFunction('refreshLogs');

        $this->assertStringContainsString(
            'createElement',
            $refreshLogsMethod,
            'refreshLogs() must use createElement for safe DOM construction'
        );
    }

    // ---------------------------------------------------------------
    // 3. Unsanitized RSS content inserted into database
    //
    // VULNERABILITY: prepare_post_data() passed raw feed content and
    // titles to wp_insert_post() without sanitization.
    //
    // FIX: content passes through wp_kses_post(), titles through
    // sanitize_text_field().
    // ---------------------------------------------------------------

    public function test_prepare_post_data_sanitizes_content(): void
    {
        $method = $this->getPreparePostDataMethod();

        $this->assertStringContainsString(
            'wp_kses_post',
            $method,
            'prepare_post_data() must run content through wp_kses_post()'
        );
    }

    public function test_prepare_post_data_sanitizes_title(): void
    {
        $method = $this->getPreparePostDataMethod();

        $this->assertStringContainsString(
            'sanitize_text_field',
            $method,
            'prepare_post_data() must run title through sanitize_text_field()'
        );
    }

    public function test_script_tags_stripped_from_content(): void
    {
        $malicious = '<p>Hello</p><script>alert("xss")</script><p>World</p>';
        $sanitized = wp_kses_post($malicious);

        $this->assertStringNotContainsString(
            '<script>',
            $sanitized,
            'wp_kses_post must strip script tags from feed content'
        );
        $this->assertStringContainsString('<p>Hello</p>', $sanitized);
        $this->assertStringContainsString('<p>World</p>', $sanitized);
    }

    public function test_html_stripped_from_title(): void
    {
        $malicious = 'Post Title<img src=x onerror=alert(1)>';
        $sanitized = sanitize_text_field($malicious);

        $this->assertStringNotContainsString('<img', $sanitized);
        $this->assertStringNotContainsString('onerror', $sanitized);
        $this->assertStringContainsString('Post Title', $sanitized);
    }

    // ---------------------------------------------------------------
    // 4. No sanitize_callback on register_setting
    //
    // VULNERABILITY: settings were stored without any server-side
    // validation. feed_url could contain non-URL values, post_status
    // could be arbitrary strings, author could be non-integer.
    //
    // FIX: added sanitize_callback that validates each field.
    // ---------------------------------------------------------------

    public function test_register_setting_has_sanitize_callback(): void
    {
        $this->assertStringContainsString(
            'sanitize_callback',
            self::$adminSource,
            'register_setting() must include a sanitize_callback'
        );
    }

    public function test_sanitize_settings_validates_feed_url(): void
    {
        $admin = new Substack_Sync_Admin();

        $result = $admin->sanitize_settings([
            'feed_url' => 'not-a-url',
        ]);
        $this->assertEmpty(
            $result['feed_url'],
            'Invalid URLs must be rejected'
        );

        $result = $admin->sanitize_settings([
            'feed_url' => 'https://example.substack.com/feed',
        ]);
        $this->assertEquals(
            'https://example.substack.com/feed',
            $result['feed_url'],
            'Valid URLs must be preserved'
        );
    }

    public function test_sanitize_settings_rejects_invalid_post_status(): void
    {
        $admin = new Substack_Sync_Admin();

        $result = $admin->sanitize_settings([
            'default_post_status' => 'private',
        ]);
        $this->assertEquals(
            'draft',
            $result['default_post_status'],
            'Invalid post status must fall back to draft'
        );
    }

    public function test_sanitize_settings_casts_author_to_int(): void
    {
        $admin = new Substack_Sync_Admin();

        $result = $admin->sanitize_settings([
            'default_author' => '42abc',
        ]);
        $this->assertIsInt($result['default_author']);
        $this->assertEquals(42, $result['default_author']);
    }

    public function test_sanitize_settings_strips_html_from_keywords(): void
    {
        $admin = new Substack_Sync_Admin();

        $result = $admin->sanitize_settings([
            'category_mapping' => [
                ['keyword' => '<script>alert(1)</script>marketing', 'category' => '5'],
            ],
        ]);

        $this->assertCount(1, $result['category_mapping']);
        $this->assertStringNotContainsString('<script>', $result['category_mapping'][0]['keyword']);
        $this->assertStringContainsString('marketing', $result['category_mapping'][0]['keyword']);
    }

    public function test_sanitize_settings_drops_empty_mappings(): void
    {
        $admin = new Substack_Sync_Admin();

        $result = $admin->sanitize_settings([
            'category_mapping' => [
                ['keyword' => '', 'category' => '5'],
                ['keyword' => 'valid', 'category' => '0'],
                ['keyword' => 'good', 'category' => '3'],
            ],
        ]);

        $this->assertCount(1, $result['category_mapping']);
        $this->assertEquals('good', $result['category_mapping'][0]['keyword']);
    }

    // ---------------------------------------------------------------
    // 5. Triple SubstackSyncProgress instantiation
    //
    // VULNERABILITY: SubstackSyncProgress was instantiated 3 times
    // (inline, DOMContentLoaded listener, readyState fallback), each
    // attaching a click listener. Clicking Sync fired 2-3 concurrent
    // batch sync chains causing duplicate imports.
    //
    // FIX: removed the DOMContentLoaded and readyState initializations,
    // keeping only the single inline initialization that checks for the
    // button's existence.
    // ---------------------------------------------------------------

    public function test_single_sync_progress_instantiation(): void
    {
        $count = substr_count(self::$adminSource, 'new SubstackSyncProgress()');

        $this->assertEquals(
            1,
            $count,
            "SubstackSyncProgress must be instantiated exactly once, found {$count} instances"
        );
    }

    public function test_no_domcontentloaded_sync_init(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/DOMContentLoaded.*SubstackSyncProgress/s',
            self::$adminSource,
            'SubstackSyncProgress must not be initialized inside a DOMContentLoaded listener'
        );
    }

    // ---------------------------------------------------------------
    // 6. Server path disclosure in error responses
    //
    // VULNERABILITY: AJAX error response included debug_info with
    // $e->getFile() and $e->getLine(), exposing server filesystem
    // paths to the admin UI.
    //
    // FIX: removed debug_info from the error response.
    // ---------------------------------------------------------------

    public function test_no_debug_info_in_ajax_error_response(): void
    {
        $batchSyncHandler = $this->extractPhpMethod('handle_batch_sync');

        $this->assertStringNotContainsString(
            'debug_info',
            $batchSyncHandler,
            'AJAX error responses must not include debug_info'
        );
    }

    public function test_no_getfile_in_ajax_responses(): void
    {
        $batchSyncHandler = $this->extractPhpMethod('handle_batch_sync');

        $this->assertStringNotContainsString(
            'getFile()',
            $batchSyncHandler,
            'AJAX error responses must not expose server file paths via getFile()'
        );
    }

    public function test_no_getline_in_ajax_responses(): void
    {
        $batchSyncHandler = $this->extractPhpMethod('handle_batch_sync');

        $this->assertStringNotContainsString(
            'getLine()',
            $batchSyncHandler,
            'AJAX error responses must not expose line numbers via getLine()'
        );
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function getUpdatePostMethod(): string
    {
        return $this->extractPhpMethodFromSource(self::$processorSource, 'update_post');
    }

    private function getPreparePostDataMethod(): string
    {
        return $this->extractPhpMethodFromSource(self::$processorSource, 'prepare_post_data');
    }

    private function extractPhpMethod(string $methodName): string
    {
        return $this->extractPhpMethodFromSource(self::$adminSource, $methodName);
    }

    private function extractPhpMethodFromSource(string $source, string $methodName): string
    {
        $pattern = '/function\s+' . preg_quote($methodName) . '\s*\([^)]*\)[^{]*\{/';
        if (! preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE)) {
            $this->fail("Could not find method {$methodName} in source");
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

        $this->fail("Could not extract method {$methodName}: unbalanced braces");
    }

    private function extractJsFunction(string $funcName): string
    {
        $pattern = '/' . preg_quote($funcName) . '\s*\(\s*\)\s*\{/';
        if (! preg_match($pattern, self::$adminSource, $match, PREG_OFFSET_CAPTURE)) {
            $this->fail("Could not find JS function {$funcName} in admin source");
        }

        $start = $match[0][1];
        $braceCount = 0;
        $len = strlen(self::$adminSource);
        $inFunc = false;

        for ($i = $start; $i < $len; $i++) {
            if (self::$adminSource[$i] === '{') {
                $braceCount++;
                $inFunc = true;
            } elseif (self::$adminSource[$i] === '}') {
                $braceCount--;
                if ($inFunc && $braceCount === 0) {
                    return substr(self::$adminSource, $start, $i - $start + 1);
                }
            }
        }

        $this->fail("Could not extract JS function {$funcName}: unbalanced braces");
    }
}
