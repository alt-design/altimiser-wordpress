<?php

use AltDesign\Altimiser\ControlPanel;
use AltDesign\Altimiser\ReviewQueuePage;

class ReviewQueuePageTest extends WP_UnitTestCase
{
    private ReviewQueuePage $page;

    private int $requests = 0;

    public function set_up(): void
    {
        parent::set_up();

        global $submenu, $menu, $_wp_submenu_nopriv, $_registered_pages, $wp_meta_boxes;

        $submenu = $menu = $_wp_submenu_nopriv = $_registered_pages = $wp_meta_boxes = [];

        require_once ABSPATH.'wp-admin/includes/dashboard.php';
        require_once ABSPATH.'wp-admin/includes/template.php';
        require_once ABSPATH.'wp-admin/includes/plugin.php';

        set_current_screen('dashboard');

        $this->page = new ReviewQueuePage(new ControlPanel('https://altimiser.example.com', 'https://example.com', 'shared-secret'));
    }

    public function tear_down(): void
    {
        set_current_screen('front');

        parent::tear_down();
    }

    private function signInAs(string $role): WP_User
    {
        $user = self::factory()->user->create_and_get(['role' => $role, 'user_email' => "{$role}@example.com"]);

        wp_set_current_user($user->ID);

        return $user;
    }

    private function altimiserCounts(int|WP_Error $count): void
    {
        add_filter('pre_http_request', function () use ($count) {
            $this->requests++;

            return $count instanceof WP_Error
                ? $count
                : ['headers' => [], 'body' => wp_json_encode(['awaiting_review' => $count]), 'response' => ['code' => 200, 'message' => ''], 'cookies' => [], 'filename' => null];
        });
    }

    /**
     * @return array<int, string>|null
     */
    private function menuItem(): ?array
    {
        global $menu;

        foreach ($menu as $item) {
            if ($item[2] === 'altimiser') {
                return $item;
            }
        }

        return null;
    }

    private function menuLabel(): ?string
    {
        return $this->menuItem()[0] ?? null;
    }

    private function renderedPage(): string
    {
        ob_start();
        $this->page->render();

        return (string) ob_get_clean();
    }

    private function renderedWidget(): string
    {
        ob_start();
        $this->page->renderWidget();

        return (string) ob_get_clean();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rolesWithAccess(): array
    {
        return ['editor' => ['editor'], 'administrator' => ['administrator']];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rolesWithoutAccess(): array
    {
        return ['author' => ['author'], 'contributor' => ['contributor'], 'subscriber' => ['subscriber']];
    }

    /**
     * @dataProvider rolesWithAccess
     */
    public function test_lists_the_page_at_the_top_level_for_someone_who_reviews(string $role): void
    {
        global $submenu;

        $this->altimiserCounts(0);
        $this->signInAs($role);

        $this->page->addMenuItem();

        $this->assertSame('Altimiser', $this->menuLabel());
        $this->assertEmpty($submenu);
    }

    public function test_gives_the_menu_item_an_icon_wordpress_can_recolour(): void
    {
        $this->altimiserCounts(0);
        $this->signInAs('editor');

        $this->page->addMenuItem();

        $prefix = 'data:image/svg+xml;base64,';
        $icon = $this->menuItem()[6];

        $this->assertStringStartsWith($prefix, $icon);

        $svg = base64_decode(substr($icon, strlen($prefix)));

        $this->assertNotFalse(simplexml_load_string($svg));
        $this->assertStringContainsString('<rect width="20" height="20" fill="#a7aaad" mask="url(#rocket)"/>', $svg);
        $this->assertSame(1, substr_count($svg, 'fill='), 'WordPress overwrites every fill, and one on the lines would fill the rocket in.');
    }

    /**
     * @dataProvider rolesWithoutAccess
     */
    public function test_shows_nothing_to_someone_who_does_not_and_spends_no_request_on_them(string $role): void
    {
        $this->altimiserCounts(3);
        $this->signInAs($role);

        $this->page->addMenuItem();
        $this->page->addDashboardWidget();

        global $wp_meta_boxes;

        $this->assertNull($this->menuLabel());
        $this->assertEmpty($wp_meta_boxes);
        $this->assertSame(0, $this->requests);
    }

    public function test_shows_the_count_on_the_menu_item(): void
    {
        $this->altimiserCounts(12);
        $this->signInAs('editor');

        $this->page->addMenuItem();

        $this->assertStringContainsString('<span aria-hidden="true">12</span>', $this->menuLabel());
        $this->assertStringContainsString('12 waiting for review', $this->menuLabel());
    }

    public function test_keeps_the_page_and_loses_the_number_when_altimiser_is_unreachable(): void
    {
        $this->altimiserCounts(new WP_Error('http_request_failed', 'Connection timed out'));
        $this->signInAs('editor');

        $this->page->addMenuItem();

        $this->assertSame('Altimiser', $this->menuLabel());
    }

    public function test_frames_a_link_signed_for_whoever_is_signed_in(): void
    {
        $this->signInAs('editor');

        $html = html_entity_decode($this->renderedPage(), ENT_QUOTES);

        preg_match('/<iframe[^>]* src="([^"]+)"/', $html, $frame);

        $this->assertStringContainsString('user=editor%40example.com', $frame[1]);
        $this->assertStringContainsString('embedded=1', $frame[1]);
        $this->assertSignedWithTheSharedSecret($frame[1]);
    }

    public function test_shows_only_the_frame(): void
    {
        $this->signInAs('editor');

        $html = $this->renderedPage();

        $this->assertStringNotContainsString('<h1', $html);
        $this->assertStringNotContainsString('<a ', $html);
    }

    private function assertSignedWithTheSharedSecret(string $link): void
    {
        [$signed, $signature] = explode('&signature=', $link);

        $this->assertSame(hash_hmac('sha256', $signed, 'shared-secret'), $signature);
    }

    public function test_sends_the_browser_nothing_that_could_sign_another_link(): void
    {
        $this->signInAs('editor');

        $this->assertStringNotContainsString('shared-secret', $this->renderedPage());
    }

    public function test_adds_a_dashboard_widget_for_someone_who_reviews(): void
    {
        $this->altimiserCounts(1);
        $this->signInAs('editor');

        $this->page->addDashboardWidget();

        global $wp_meta_boxes;

        $this->assertArrayHasKey('altimiser', $wp_meta_boxes['dashboard']['normal']['core']);
    }

    /**
     * @dataProvider widgetCounts
     */
    public function test_says_how_much_is_waiting_in_the_widget(int|WP_Error $count, string $expected): void
    {
        $this->altimiserCounts($count);
        $this->signInAs('editor');

        $widget = $this->renderedWidget();

        $this->assertStringContainsString($expected, $widget);
        $this->assertStringContainsString('admin.php?page=altimiser', $widget);
    }

    /**
     * @return array<string, array{int|WP_Error, string}>
     */
    public static function widgetCounts(): array
    {
        return [
            'none' => [0, 'Nothing is waiting for review.'],
            'one' => [1, '1 change is waiting for review.'],
            'several' => [12, '12 changes are waiting for review.'],
            'unreachable' => [new WP_Error('http_request_failed', 'Connection timed out'), 'The count is not available right now'],
        ];
    }

    public function test_says_what_is_approved_and_what_altimiser_can_fix_in_the_widget(): void
    {
        add_filter('pre_http_request', static fn (): array => [
            'headers' => [],
            'body' => wp_json_encode(['outstanding' => 42, 'handled' => 30, 'pending' => 3, 'awaiting_review' => 12, 'last_scanned_at' => '2026-11-02T03:00:12.000000Z']),
            'response' => ['code' => 200, 'message' => ''],
            'cookies' => [],
            'filename' => null,
        ]);
        $this->signInAs('editor');

        $widget = $this->renderedWidget();

        $this->assertStringContainsString('3 approved changes are waiting to be applied.', $widget);
        $this->assertStringContainsString('Altimiser can apply fixes for 30 of the 42 open findings on this site.', $widget);
    }

    public function test_leaves_out_counts_that_are_zero(): void
    {
        $this->altimiserCounts(0);
        $this->signInAs('editor');

        $this->assertStringNotContainsString('<ul>', $this->renderedWidget());
    }

    public function test_styles_only_its_own_page(): void
    {
        $this->page->addStyles('index.php');

        $this->assertFalse(wp_style_is('altimiser-review-queue'));

        $this->page->addStyles('toplevel_page_altimiser');

        $this->assertTrue(wp_style_is('altimiser-review-queue'));
        $this->assertStringContainsString('.altimiser-review-queue{', implode('', wp_styles()->get_data('altimiser-review-queue', 'after')));
    }
}
