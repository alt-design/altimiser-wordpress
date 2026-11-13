<?php

use AltDesign\Altimiser\Connections;
use AltDesign\Altimiser\ConnectionFlow;
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

    private function signInAs(string $role, bool $connected = true): WP_User
    {
        $user = self::factory()->user->create_and_get(['role' => $role, 'user_email' => "{$role}@example.com"]);

        wp_set_current_user($user->ID);

        if ($connected) {
            $this->connect($user, time() + HOUR_IN_SECONDS);
        }

        return $user;
    }

    private function connect(WP_User $user, int $expiresAt): void
    {
        Connections::put($user->ID, [
            'connection' => '01jcz6r4s8x2m0v3q9k7t5w1yd',
            'token' => str_repeat('ab', 32),
            'expires_at' => $expiresAt,
            'account_email' => 'sam@agency.example',
        ]);
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
        $this->assertStringContainsString('connection=01jcz6r4s8x2m0v3q9k7t5w1yd', $frame[1]);
        $this->assertSignedWithTheSecretAndTheToken($frame[1]);
    }

    public function test_shows_no_heading_or_link_around_the_frame(): void
    {
        $this->signInAs('editor');

        $html = $this->renderedPage();

        $this->assertStringNotContainsString('<h1', $html);
        $this->assertStringNotContainsString('<a ', $html);
    }

    private function assertSignedWithTheSecretAndTheToken(string $link): void
    {
        preg_match('/^(.+)&signature=(\w+)&connection_signature=(\w+)$/', $link, $parts);

        $this->assertSame(hash_hmac('sha256', $parts[1], 'shared-secret'), $parts[2]);
        $this->assertSame(hash_hmac('sha256', $parts[1], str_repeat('ab', 32)), $parts[3]);
    }

    public function test_sends_the_browser_nothing_that_could_sign_another_link(): void
    {
        $this->signInAs('editor');

        $html = $this->renderedPage();

        $this->assertStringNotContainsString('shared-secret', $html);
        $this->assertStringNotContainsString(str_repeat('ab', 32), $html);
    }

    public function test_says_who_is_connected_and_offers_to_disconnect(): void
    {
        $this->signInAs('editor');

        $html = $this->renderedPage();

        $this->assertStringContainsString('Connected as sam@agency.example until', $html);
        $this->assertStringContainsString('name="action" value="altimiser_disconnect"', $html);
        $this->assertStringContainsString('name="_wpnonce"', $html);
    }

    public function test_offers_to_connect_an_editor_who_has_not_and_frames_nothing(): void
    {
        $this->signInAs('editor', connected: false);

        $html = html_entity_decode($this->renderedPage(), ENT_QUOTES);

        $this->assertStringContainsString('Connect your Altimiser account', $html);
        $this->assertMatchesRegularExpression('#href="[^"]+/wp-admin/admin-post\.php\?action=altimiser_connect&_wpnonce=\w+"#', $html);
        $this->assertStringNotContainsString('<iframe', $html);
    }

    public function test_offers_to_connect_again_once_the_connection_has_expired(): void
    {
        $user = $this->signInAs('editor', connected: false);
        $this->connect($user, time() - 1);

        $this->assertStringContainsString('Connect your Altimiser account', $this->renderedPage());
    }

    public function test_shows_what_the_last_connection_step_said_once(): void
    {
        $this->signInAs('editor');
        set_transient('altimiser_notice_'.get_current_user_id(), ['level' => 'success', 'message' => 'Your Altimiser account is connected.'], MINUTE_IN_SECONDS);

        $this->assertStringContainsString('<div class="notice notice-success inline"><p>Your Altimiser account is connected.</p></div>', $this->renderedPage());
        $this->assertNull(ConnectionFlow::takeNotice());
    }

    public function test_shows_no_count_and_asks_nothing_until_the_editor_connects(): void
    {
        $this->altimiserCounts(12);
        $this->signInAs('editor', connected: false);

        $this->page->addMenuItem();
        $widget = $this->renderedWidget();

        $this->assertSame('Altimiser', $this->menuLabel());
        $this->assertStringContainsString('Connect your Altimiser account to see what is waiting for review.', $widget);
        $this->assertStringContainsString('admin.php?page=altimiser', $widget);
        $this->assertSame(0, $this->requests);
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
