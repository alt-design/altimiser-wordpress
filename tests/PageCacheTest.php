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
        $before = did_action('litespeed_purge_all');

        $request();

        return did_action('litespeed_purge_all') - $before;
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

        add_action('litespeed_purge_all', $recordWhatIsPrinted);

        $this->sendOne();

        remove_action('litespeed_purge_all', $recordWhatIsPrinted);

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
    public function test_reaches_each_plugin_that_is_active(): void
    {
        require __DIR__.'/fixtures/page-cache-plugins.php';

        $beforeBreeze = did_action('breeze_clear_all_cache');

        (new AltDesign\Altimiser\PageCache())->purge();

        $this->assertSame(['rocket', 'w3tc', 'super-cache'], $GLOBALS['altimiser_purged']);
        $this->assertSame($beforeBreeze + 1, did_action('breeze_clear_all_cache'));
    }

    public function test_reports_success_even_when_the_purge_fails_because_the_changes_have_landed(): void
    {
        $failing = static function (): void {
            throw new RuntimeException('The cache directory is read only.');
        };

        add_action('litespeed_purge_all', $failing);

        $logged = ini_set('error_log', '/dev/null');
        $result = $this->sendOne();

        ini_set('error_log', (string) $logged);
        remove_action('litespeed_purge_all', $failing);

        $this->assertSame('applied', $result['status']);
    }
}
