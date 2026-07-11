<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Each test loads BOTH the upstream (cspenn) and patched source, asserts the
 * vulnerability is present in the original, then asserts it is gone in the fix.
 *
 * This structure proves the bugs were real, not hypothetical.
 */
class SecurityFixesTest extends TestCase
{
    private static string $upstreamProcessor;
    private static string $upstreamAdmin;
    private static string $patchedProcessor;
    private static string $patchedAdmin;

    public static function setUpBeforeClass(): void
    {
        $upstreamDir = dirname(__DIR__) . '/tests/fixtures/upstream';
        if (! is_dir($upstreamDir)) {
            self::fail(
                "Upstream fixtures missing. Run:\n" .
                "  git show main:includes/class-substack-sync-processor.php > tests/fixtures/upstream/class-substack-sync-processor.php\n" .
                "  git show main:admin/class-substack-sync-admin.php > tests/fixtures/upstream/class-substack-sync-admin.php"
            );
        }

        self::$upstreamProcessor = file_get_contents($upstreamDir . '/class-substack-sync-processor.php');
        self::$upstreamAdmin = file_get_contents($upstreamDir . '/class-substack-sync-admin.php');
        self::$patchedProcessor = file_get_contents(dirname(__DIR__) . '/includes/class-substack-sync-processor.php');
        self::$patchedAdmin = file_get_contents(dirname(__DIR__) . '/admin/class-substack-sync-admin.php');
    }

    // ---------------------------------------------------------------
    // 1. Draft-revert bug
    //
    // update_post() hardcoded post_status to 'draft', so every hourly
    // cron cycle reverted published posts back to draft.
    // ---------------------------------------------------------------

    public function test_upstream_update_post_hardcodes_draft(): void
    {
        $method = self::extractPhpMethod(self::$upstreamProcessor, 'update_post');

        $this->assertMatchesRegularExpression(
            '/post_status.*=.*[\'"]draft[\'"]/',
            $method,
            'UPSTREAM: update_post() should hardcode draft (proving the bug exists)'
        );
    }

    public function test_patched_update_post_does_not_hardcode_draft(): void
    {
        $method = self::extractPhpMethod(self::$patchedProcessor, 'update_post');

        $this->assertStringNotContainsString(
            "'post_status' => 'draft'",
            $method,
            'PATCHED: update_post() must not hardcode draft'
        );

        $this->assertStringContainsString(
            "unset(\$post_data['post_status'])",
            $method,
            'PATCHED: update_post() must unset post_status to preserve existing value'
        );
    }

    public function test_wp_update_post_preserves_status_when_omitted(): void
    {
        global $_wp_posts, $_wp_post_id_counter;
        $_wp_posts = [];
        $_wp_post_id_counter = 200;

        $post_id = wp_insert_post([
            'post_title' => 'Test Post',
            'post_content' => 'Content',
            'post_status' => 'publish',
        ]);

        // Simulate the patched update_post: omit post_status
        wp_update_post([
            'ID' => $post_id,
            'post_title' => 'Updated Title',
            'post_content' => 'Updated content',
        ]);

        $this->assertEquals(
            'publish',
            get_post($post_id)->post_status,
            'Omitting post_status from wp_update_post must preserve the existing value'
        );

        // Simulate the ORIGINAL bug: explicitly set draft
        wp_update_post([
            'ID' => $post_id,
            'post_title' => 'Updated Again',
            'post_status' => 'draft',
        ]);

        $this->assertEquals(
            'draft',
            get_post($post_id)->post_status,
            'Explicitly setting post_status to draft overwrites the value (this is the bug)'
        );
    }

    // ---------------------------------------------------------------
    // 2. Stored XSS in admin log viewer
    //
    // refreshLogs() used innerHTML to render substack_title, allowing
    // script injection via crafted post titles.
    // ---------------------------------------------------------------

    public function test_upstream_refresh_logs_uses_innerHTML(): void
    {
        $method = self::extractJsFunction(self::$upstreamAdmin, 'refreshLogs');

        $this->assertStringContainsString(
            'innerHTML',
            $method,
            'UPSTREAM: refreshLogs() should use innerHTML (proving the XSS vector exists)'
        );

        $this->assertMatchesRegularExpression(
            '/\$\{.*substack_title\}/',
            $method,
            'UPSTREAM: substack_title should be interpolated into the innerHTML template'
        );
    }

    public function test_patched_refresh_logs_uses_safe_dom(): void
    {
        $method = self::extractJsFunction(self::$patchedAdmin, 'refreshLogs');

        $this->assertStringNotContainsString(
            'innerHTML',
            $method,
            'PATCHED: refreshLogs() must not use innerHTML'
        );

        $this->assertStringContainsString(
            'textContent',
            $method,
            'PATCHED: refreshLogs() must use textContent'
        );

        $this->assertStringContainsString(
            'createElement',
            $method,
            'PATCHED: refreshLogs() must use createElement'
        );
    }

    // ---------------------------------------------------------------
    // 3. Unsanitized RSS content inserted into database
    //
    // prepare_post_data() passed raw feed content and titles to
    // wp_insert_post() without sanitization.
    // ---------------------------------------------------------------

    public function test_upstream_prepare_post_data_has_no_sanitization(): void
    {
        $method = self::extractPhpMethod(self::$upstreamProcessor, 'prepare_post_data');

        $this->assertStringNotContainsString(
            'wp_kses_post',
            $method,
            'UPSTREAM: prepare_post_data() should lack wp_kses_post (proving content is unsanitized)'
        );

        $this->assertStringNotContainsString(
            'sanitize_text_field',
            $method,
            'UPSTREAM: prepare_post_data() should lack sanitize_text_field (proving title is unsanitized)'
        );
    }

    public function test_patched_prepare_post_data_sanitizes(): void
    {
        $method = self::extractPhpMethod(self::$patchedProcessor, 'prepare_post_data');

        $this->assertStringContainsString(
            'wp_kses_post',
            $method,
            'PATCHED: prepare_post_data() must sanitize content with wp_kses_post()'
        );

        $this->assertStringContainsString(
            'sanitize_text_field',
            $method,
            'PATCHED: prepare_post_data() must sanitize title with sanitize_text_field()'
        );
    }

    public function test_sanitization_functions_actually_strip_attacks(): void
    {
        $xssContent = '<p>Hello</p><script>alert("xss")</script><p>World</p>';
        $sanitized = wp_kses_post($xssContent);
        $this->assertStringNotContainsString('<script>', $sanitized);
        $this->assertStringContainsString('<p>Hello</p>', $sanitized);

        // Attribute-level vectors on tags that survive the tag allowlist: real
        // wp_kses_post strips inline event handlers and script: URIs. strip_tags
        // alone would keep these, so this asserts the stub (and prod) go further.
        $eventHandler = '<a href="#" onclick="alert(1)">click</a>';
        $sanitized = wp_kses_post($eventHandler);
        $this->assertStringNotContainsString('onclick', $sanitized, 'Inline event handlers must be stripped from allowed tags');
        $this->assertStringContainsString('click', $sanitized, 'Link text must survive');

        $imgHandler = '<img src="x" onerror="alert(1)">';
        $sanitized = wp_kses_post($imgHandler);
        $this->assertStringNotContainsString('onerror', $sanitized, 'onerror must be stripped from <img>');

        $jsUri = '<a href="javascript:alert(1)">x</a>';
        $sanitized = wp_kses_post($jsUri);
        $this->assertStringNotContainsString('javascript:', $sanitized, 'javascript: URIs must be neutralized on allowed tags');

        $xssTitle = 'Post Title<img src=x onerror=alert(1)>';
        $sanitized = sanitize_text_field($xssTitle);
        $this->assertStringNotContainsString('<img', $sanitized);
        $this->assertStringContainsString('Post Title', $sanitized);
    }

    // ---------------------------------------------------------------
    // 4. No sanitize_callback on register_setting
    //
    // Settings were stored without server-side validation.
    // ---------------------------------------------------------------

    public function test_upstream_register_setting_has_no_sanitize_callback(): void
    {
        $this->assertStringNotContainsString(
            'sanitize_callback',
            self::$upstreamAdmin,
            'UPSTREAM: register_setting() should lack sanitize_callback (proving settings are unvalidated)'
        );
    }

    public function test_patched_register_setting_has_sanitize_callback(): void
    {
        $this->assertStringContainsString(
            'sanitize_callback',
            self::$patchedAdmin,
            'PATCHED: register_setting() must include sanitize_callback'
        );
    }

    public function test_sanitize_settings_rejects_bad_input(): void
    {
        $admin = new Substack_Sync_Admin();

        $result = $admin->sanitize_settings([
            'feed_url' => 'javascript:alert(1)',
            'default_author' => 'DROP TABLE',
            'default_post_status' => 'evil',
            'category_mapping' => [
                ['keyword' => '<script>xss</script>', 'category' => 'abc'],
                ['keyword' => '', 'category' => '5'],
                ['keyword' => 'valid', 'category' => '3'],
            ],
        ]);

        $this->assertEmpty($result['feed_url'], 'javascript: URLs must be rejected');
        $this->assertEquals(1, $result['default_author'], 'Non-numeric/non-positive author must fall back to the default user (1), never 0');
        $this->assertEquals('draft', $result['default_post_status'], 'Invalid status must fall back to draft');
        $this->assertCount(1, $result['category_mapping'], 'Only the valid mapping should survive');
        $this->assertEquals('valid', $result['category_mapping'][0]['keyword']);
        $this->assertEquals(3, $result['category_mapping'][0]['category']);
    }

    public function test_sanitize_settings_preserves_good_input(): void
    {
        $admin = new Substack_Sync_Admin();

        $result = $admin->sanitize_settings([
            'feed_url' => 'https://sovereigngrace.substack.com/feed',
            'default_author' => '42',
            'default_post_status' => 'publish',
            'delete_data_on_uninstall' => '1',
            'category_mapping' => [
                ['keyword' => 'theology', 'category' => '7'],
            ],
        ]);

        $this->assertEquals('https://sovereigngrace.substack.com/feed', $result['feed_url']);
        $this->assertEquals(42, $result['default_author']);
        $this->assertEquals('publish', $result['default_post_status']);
        $this->assertTrue($result['delete_data_on_uninstall']);
        $this->assertCount(1, $result['category_mapping']);
        $this->assertEquals('theology', $result['category_mapping'][0]['keyword']);
    }

    public function test_sanitize_settings_tolerates_non_array_input(): void
    {
        $admin = new Substack_Sync_Admin();

        // WordPress invokes the sanitize_callback on every update_option for the
        // option, and options.php passes null when the key is absent from $_POST.
        // A strict array type hint would fatal here; the callback must coerce.
        foreach ([null, '', 'unexpected string', 42, false] as $bad) {
            $result = $admin->sanitize_settings($bad);
            $this->assertIsArray($result, 'Non-array input must yield a sanitized array, not a fatal');
            $this->assertSame('', $result['feed_url']);
            $this->assertSame(1, $result['default_author']);
            $this->assertSame('draft', $result['default_post_status']);
            $this->assertSame([], $result['category_mapping']);
        }
    }

    public function test_sanitize_settings_tolerates_array_valued_fields(): void
    {
        $admin = new Substack_Sync_Admin();

        // Crafted POST bodies can send scalars as arrays (feed_url[]=x). These
        // must not reach esc_url_raw()/sanitize_text_field() as arrays (a fatal).
        $result = $admin->sanitize_settings([
            'feed_url' => ['https://evil.example/feed'],
            'default_author' => ['3'],
            'category_mapping' => [
                ['keyword' => ['array'], 'category' => ['5']],
                'not-an-array',
                ['keyword' => 'valid', 'category' => '3'],
            ],
        ]);

        $this->assertSame('', $result['feed_url'], 'Array feed_url must be rejected, not fatal');
        $this->assertSame(1, $result['default_author'], 'Array author must fall back to default');
        $this->assertCount(1, $result['category_mapping'], 'Only the well-formed mapping survives');
        $this->assertSame('valid', $result['category_mapping'][0]['keyword']);
    }

    // ---------------------------------------------------------------
    // 5. Triple SubstackSyncProgress instantiation
    //
    // Three code paths each created new SubstackSyncProgress(), so
    // clicking Sync fired 2-3 concurrent batch chains.
    // ---------------------------------------------------------------

    public function test_upstream_has_multiple_sync_progress_instantiations(): void
    {
        $count = substr_count(self::$upstreamAdmin, 'new SubstackSyncProgress()');

        $this->assertGreaterThan(
            1,
            $count,
            "UPSTREAM: should have multiple SubstackSyncProgress() instantiations (found {$count})"
        );
    }

    public function test_patched_has_exactly_one_sync_progress_instantiation(): void
    {
        // The instantiation count is the reliable signal for this fix. The
        // patched code still (correctly) creates SubstackSyncProgress inside the
        // main DOMContentLoaded handler, gated on #sync-now-btn; only the two
        // redundant, ungated inits were removed. A regex asserting "no
        // DOMContentLoaded creates it" would misrepresent the code (and pass only
        // by accident of intervening braces), so we assert the count instead.
        $count = substr_count(self::$patchedAdmin, 'new SubstackSyncProgress()');

        $this->assertEquals(
            1,
            $count,
            "PATCHED: must have exactly one SubstackSyncProgress() instantiation (found {$count})"
        );
    }

    // ---------------------------------------------------------------
    // 6. Server path disclosure in error responses
    //
    // handle_batch_sync() included debug_info with getFile() and
    // getLine() in AJAX error responses.
    // ---------------------------------------------------------------

    public function test_upstream_batch_sync_exposes_debug_info(): void
    {
        $method = self::extractPhpMethod(self::$upstreamAdmin, 'handle_batch_sync');

        $this->assertStringContainsString(
            'debug_info',
            $method,
            'UPSTREAM: handle_batch_sync() should include debug_info (proving path disclosure exists)'
        );

        $this->assertStringContainsString(
            'getFile()',
            $method,
            'UPSTREAM: should expose server file paths'
        );

        $this->assertStringContainsString(
            'getLine()',
            $method,
            'UPSTREAM: should expose line numbers'
        );
    }

    public function test_patched_batch_sync_hides_debug_info(): void
    {
        $method = self::extractPhpMethod(self::$patchedAdmin, 'handle_batch_sync');

        $this->assertStringNotContainsString(
            'debug_info',
            $method,
            'PATCHED: must not include debug_info in error responses'
        );

        $this->assertStringNotContainsString(
            'getFile()',
            $method,
            'PATCHED: must not expose server file paths'
        );

        $this->assertStringNotContainsString(
            'getLine()',
            $method,
            'PATCHED: must not expose line numbers'
        );
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private static function extractPhpMethod(string $source, string $methodName): string
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

    private static function extractJsFunction(string $source, string $funcName): string
    {
        $pattern = '/' . preg_quote($funcName) . '\s*\(\s*\)\s*\{/';
        if (! preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE)) {
            self::fail("Could not find JS function {$funcName} in source");
        }

        $start = $match[0][1];
        $braceCount = 0;
        $len = strlen($source);
        $inFunc = false;

        for ($i = $start; $i < $len; $i++) {
            if ($source[$i] === '{') {
                $braceCount++;
                $inFunc = true;
            } elseif ($source[$i] === '}') {
                $braceCount--;
                if ($inFunc && $braceCount === 0) {
                    return substr($source, $start, $i - $start + 1);
                }
            }
        }

        self::fail("Could not extract JS function {$funcName}: unbalanced braces");
    }
}
