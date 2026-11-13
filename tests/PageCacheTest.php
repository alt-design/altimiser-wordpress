<?php

class PageCacheTest extends ReceiverTestCase
{
    private int $pageId;

    public static function set_up_before_class(): void
    {
        parent::set_up_before_class();

        self::configureSecret();
    }

    public function set_up(): void
    {
        parent::set_up();

        $this->set_permalink_structure('/%postname%/');

        $this->pageId = self::factory()->post->create(['post_type' => 'page', 'post_name' => 'about', 'post_title' => 'About']);

        update_post_meta($this->pageId, '_yoast_wpseo_metadesc', 'About us.');
    }

    private function purges(callable $request): int
    {
        $before = did_action('altimiser_purge_urls');

        $request();

        return did_action('altimiser_purge_urls') - $before;
    }

    private function descriptionYoastPrints(): ?string
    {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare(
            "SELECT description FROM {$wpdb->prefix}yoast_indexable WHERE object_type = 'post' AND object_id = %d",
            $this->pageId,
        ));
    }

    public function test_purges_once_yoast_has_rebuilt_what_the_page_prints(): void
    {
        $printedAtPurge = null;

        $recordWhatIsPrinted = function () use (&$printedAtPurge): void {
            $printedAtPurge = $this->descriptionYoastPrints();
        };

        add_action('litespeed_purge_url', $recordWhatIsPrinted);

        $this->sendOne();

        remove_action('litespeed_purge_url', $recordWhatIsPrinted);

        $this->assertSame('How our studio works, and who we build for.', $printedAtPurge);
    }

    public function test_rebuilds_what_the_page_prints_when_a_value_is_cleared_too(): void
    {
        $this->sendOne();

        (new AltDesign\Altimiser\Yoast())->clear(get_post($this->pageId), 'metadesc');

        $this->assertNull($this->descriptionYoastPrints());
    }

    public function test_purges_during_the_request_because_w3_total_cache_flushes_on_a_shutdown_hook_of_its_own(): void
    {
        $this->assertSame(1, $this->purges(fn () => $this->sendOne()));
    }

    public function test_purges_once_for_a_request_that_applied_several_changes(): void
    {
        $purges = $this->purges(fn () => $this->send([
            $this->change(),
            $this->change(['check' => 'title.missing', 'current_value' => null, 'suggested_value' => 'About our studio']),
        ]));

        $this->assertSame(1, $purges);
    }

    public function test_leaves_the_cache_alone_on_a_dry_run(): void
    {
        $this->assertSame(0, $this->purges(fn () => $this->sendOne(dryRun: true)));
    }

    public function test_leaves_the_cache_alone_when_nothing_landed(): void
    {
        $this->assertSame(0, $this->purges(fn () => $this->sendOne(['current_value' => 'Not what is there'])));
    }

    public function test_purges_when_the_fix_was_already_in_place_because_the_request_that_wrote_it_may_have_died_before_its_purge(): void
    {
        $this->sendOne();

        $this->assertSame(1, $this->purges(fn () => $this->sendOne()));
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    public function test_reaches_each_plugin_that_is_active_by_address(): void
    {
        require __DIR__.'/fixtures/page-cache-plugins.php';

        $page = home_url('/about/');
        $beforeBreeze = did_action('breeze_clear_all_cache');
        $beforeLiteSpeed = did_action('litespeed_purge_url');

        (new AltDesign\Altimiser\PageCache())->purge([$page, $page]);

        $this->assertSame([['rocket', [$page]], ['w3tc', $page], ['super-cache', $page]], $GLOBALS['altimiser_purged']);
        $this->assertSame($beforeLiteSpeed + 1, did_action('litespeed_purge_url'));
        $this->assertSame($beforeBreeze + 1, did_action('breeze_clear_all_cache'));
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    public function test_clears_wp_super_cache_whole_for_the_home_page_it_will_not_delete_by_address(): void
    {
        require __DIR__.'/fixtures/page-cache-plugins.php';

        (new AltDesign\Altimiser\PageCache())->purge([home_url('/')]);

        $this->assertContains('super-cache', $GLOBALS['altimiser_purged']);
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    public function test_clears_everything_on_request(): void
    {
        require __DIR__.'/fixtures/page-cache-plugins.php';

        $beforeLiteSpeed = did_action('litespeed_purge_all');

        (new AltDesign\Altimiser\PageCache())->purgeEverything();

        $this->assertSame(['rocket', 'w3tc', 'super-cache'], $GLOBALS['altimiser_purged']);
        $this->assertSame($beforeLiteSpeed + 1, did_action('litespeed_purge_all'));
    }

    public function test_purges_only_the_page_the_change_was_made_for(): void
    {
        $purged = [];

        $record = static function (array $urls, bool $everything) use (&$purged): void {
            $purged[] = [$urls, $everything];
        };

        add_action('altimiser_purge_urls', $record, 10, 2);

        $this->sendOne();

        remove_action('altimiser_purge_urls', $record);

        $this->assertSame([[[home_url('/about/')], false]], $purged);
    }

    public function test_reports_success_even_when_the_purge_fails_because_the_changes_have_landed(): void
    {
        $failing = static function (): void {
            throw new RuntimeException('The cache directory is read only.');
        };

        add_action('litespeed_purge_url', $failing);

        $logged = ini_set('error_log', '/dev/null');
        $result = $this->sendOne();

        ini_set('error_log', (string) $logged);
        remove_action('litespeed_purge_url', $failing);

        $this->assertSame('applied', $result['status']);
    }

    public function test_still_reaches_the_other_caches_when_one_of_them_throws(): void
    {
        $failing = static function (): void {
            throw new RuntimeException('The cache directory is read only.');
        };

        add_action('litespeed_purge_url', $failing);
        add_action('litespeed_purge_all', $failing);

        $logged = ini_set('error_log', '/dev/null');
        $beforeBreeze = did_action('breeze_clear_all_cache');
        $beforeOwn = did_action('altimiser_purge_urls');

        (new AltDesign\Altimiser\PageCache())->purge([home_url('/about/')]);
        (new AltDesign\Altimiser\PageCache())->purgeEverything();

        ini_set('error_log', (string) $logged);
        remove_action('litespeed_purge_url', $failing);
        remove_action('litespeed_purge_all', $failing);

        $this->assertSame($beforeBreeze + 2, did_action('breeze_clear_all_cache'));
        $this->assertSame($beforeOwn + 2, did_action('altimiser_purge_urls'));
    }
}
