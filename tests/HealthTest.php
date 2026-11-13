<?php

class HealthTest extends ReceiverTestCase
{
    public static function set_up_before_class(): void
    {
        parent::set_up_before_class();

        self::configureSecret();
    }

    public function test_identifies_the_receiver_and_the_site(): void
    {
        $health = $this->dispatch($this->signedRequest())->get_data();

        $this->assertSame('wordpress', $health['receiver']);
        $this->assertSame(get_bloginfo('version'), $health['cms_version']);
        $this->assertSame(home_url(), $health['site_url']);
    }

    public function test_reads_its_version_from_the_plugin_header(): void
    {
        preg_match('/^ \* Version: (.+)$/m', file_get_contents(dirname(__DIR__).'/altimiser.php'), $header);

        $health = $this->dispatch($this->signedRequest())->get_data();

        $this->assertSame($header[1], $health['receiver_version']);
    }

    public function test_advertises_what_it_can_write_through_yoast(): void
    {
        $health = $this->dispatch($this->signedRequest())->get_data();

        $this->assertEqualsCanonicalizing([
            'title.missing',
            'title.too_short',
            'title.too_long',
            'title.irrelevant',
            'title.duplicate',
            'meta_description.missing',
            'meta_description.too_short',
            'meta_description.too_long',
            'meta_description.irrelevant',
            'meta_description.duplicate',
            'open_graph.missing',
            'image.alt_missing',
            'image.alt_empty',
            'image.alt_too_long',
            'link.opportunity',
            'site.robots_missing',
            'sitemap.not_declared_in_robots',
        ], $health['checks']);
        $this->assertSame(['content.update', 'content.link'], $health['capabilities']);
        $this->assertSame(['installed' => true, 'version' => WPSEO_VERSION], $health['integrations']['yoast']);
    }

    public function test_withholds_social_tags_when_yoast_is_set_not_to_print_them(): void
    {
        WPSEO_Options::set('opengraph', false);

        $checks = $this->dispatch($this->signedRequest())->get_data()['checks'];

        WPSEO_Options::set('opengraph', true);

        $this->assertNotContains('open_graph.missing', $checks);
        $this->assertContains('title.too_long', $checks);
    }

    public function test_withholds_what_yoast_would_write_without_a_supported_seo_plugin(): void
    {
        $receiver = new AltDesign\Altimiser\Receiver(dirname(__DIR__).'/altimiser.php', null);

        $health = $receiver->health()->get_data();

        $this->assertEqualsCanonicalizing([
            'image.alt_missing',
            'image.alt_empty',
            'image.alt_too_long',
            'link.opportunity',
            'site.robots_missing',
            'sitemap.not_declared_in_robots',
        ], $health['checks']);
        $this->assertSame(['installed' => false], $health['integrations']['yoast']);
    }

    public function test_fails_a_change_without_a_supported_seo_plugin_so_its_approval_is_kept(): void
    {
        $receiver = new AltDesign\Altimiser\Receiver(dirname(__DIR__).'/altimiser.php', null);

        $response = $receiver->changes($this->signedPost((string) json_encode(['changes' => [$this->change()]])));

        $this->assertSame('failed', $response->get_data()['results'][0]['status']);
        $this->assertSame('seo_plugin_inactive', $response->get_data()['results'][0]['reason']);
    }

    /**
     * Breeze 2.1.11 keeps REST responses and has no other way to opt out.
     *
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    public function test_marks_the_response_so_older_breeze_will_not_keep_it(): void
    {
        $pageId = self::factory()->post->create();

        $this->assertFalse(post_password_required($pageId));

        $this->dispatch($this->signedRequest());
        $this->shutDown();

        $this->assertTrue(post_password_required($pageId));
    }

    private function shutDown(): void
    {
        remove_action('shutdown', 'wp_ob_end_flush_all', 1);

        do_action('shutdown');
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    public function test_leaves_page_caches_alone_for_a_refused_request(): void
    {
        $pageId = self::factory()->post->create();

        $this->dispatch($this->request());
        $this->shutDown();

        $this->assertFalse(defined('DONOTCACHEPAGE'));
        $this->assertFalse(post_password_required($pageId));
    }

    public function test_cannot_be_kept_by_a_cache_and_served_to_an_unsigned_request(): void
    {
        $headers = $this->dispatch($this->signedRequest())->get_headers();

        $this->assertStringContainsString('no-store', $headers['Cache-Control']);
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    public function test_tells_page_cache_plugins_not_to_keep_it(): void
    {
        $this->assertFalse(defined('DONOTCACHEPAGE'));

        $this->dispatch($this->signedRequest());

        $this->assertTrue(defined('DONOTCACHEPAGE'));
    }

    public function test_sends_no_header_without_a_value(): void
    {
        $this->assertNotContains(false, $this->dispatch($this->signedRequest())->get_headers());
    }

    public function test_reports_settings_that_stop_fixes_from_counting(): void
    {
        update_option('blog_public', '0');
        $this->set_permalink_structure('');

        $setup = $this->dispatch($this->signedRequest())->get_data()['setup'];

        update_option('blog_public', '1');

        $this->assertSame(['search_engines_discouraged' => true, 'plain_permalinks' => true], $setup);
    }

    public function test_reports_a_site_set_up_to_be_found(): void
    {
        $this->set_permalink_structure('/%postname%/');

        $setup = $this->dispatch($this->signedRequest())->get_data()['setup'];

        $this->assertSame(['search_engines_discouraged' => false, 'plain_permalinks' => false], $setup);
    }

    public function test_still_answers_when_yoast_throws_and_leaves_out_only_its_checks(): void
    {
        $failing = static function (): never {
            throw new RuntimeException('Yoast could not read its options.');
        };

        add_filter('pre_option_wpseo_social', $failing);
        WPSEO_Options::clear_cache();

        $logged = ini_set('error_log', '/dev/null');
        $response = $this->dispatch($this->signedRequest());

        ini_set('error_log', (string) $logged);
        remove_filter('pre_option_wpseo_social', $failing);
        WPSEO_Options::clear_cache();

        $this->assertSame(200, $response->get_status());
        $this->assertNotContains('title.too_long', $response->get_data()['checks']);
    }

    public function test_says_nothing_about_the_site_to_an_unsigned_request(): void
    {
        $response = $this->dispatch($this->request());

        $this->assertSame(401, $response->get_status());
        $this->assertArrayNotHasKey('receiver', $response->get_data());
    }
}
