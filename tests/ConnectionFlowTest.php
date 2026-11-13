<?php

use AltDesign\Altimiser\ConnectionFlow;
use AltDesign\Altimiser\Connections;
use AltDesign\Altimiser\ControlPanel;

class ConnectionFlowTest extends WP_UnitTestCase
{
    /** @var array<int, array{url: string, args: array<string, mixed>}> */
    private array $requests = [];

    private WP_User $editor;

    public function set_up(): void
    {
        parent::set_up();

        $this->editor = self::factory()->user->create_and_get(['role' => 'editor', 'user_email' => 'editor@example.com']);

        wp_set_current_user($this->editor->ID);
    }

    public function tear_down(): void
    {
        $_GET = $_REQUEST = [];

        parent::tear_down();
    }

    private function flow(string $siteUrl = ''): ConnectionFlow
    {
        return new ConnectionFlow(new ControlPanel('https://altimiser.example.com', $siteUrl ?: home_url(), 'shared-secret'));
    }

    private function altimiserAnswers(int $status, string $body): void
    {
        add_filter('pre_http_request', function ($preempt, array $args, string $url) use ($status, $body) {
            $this->requests[] = ['url' => $url, 'args' => $args];

            return ['headers' => [], 'body' => $body, 'response' => ['code' => $status, 'message' => ''], 'cookies' => [], 'filename' => null];
        }, 10, 3);
    }

    /**
     * @return array<string, string>
     */
    private function queryOf(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    private function startConnecting(): string
    {
        $_GET['_wpnonce'] = $_REQUEST['_wpnonce'] = wp_create_nonce('altimiser_connect');

        return $this->flow()->start();
    }

    /**
     * @param  array<string, string>  $query
     */
    private function comeBackWith(array $query): string
    {
        $_GET = $_REQUEST = $query;

        return $this->flow()->finish();
    }

    public function test_sends_the_editor_to_a_signed_connect_link_that_returns_to_wp_admin(): void
    {
        $url = $this->startConnecting();
        $query = $this->queryOf($url);

        $this->assertStringStartsWith('https://altimiser.example.com/connect?', $url);
        $this->assertSame(admin_url('admin-post.php?action=altimiser_connected'), $query['redirect_uri']);
        $this->assertSame('editor@example.com', $query['user']);
        $this->assertSame(40, strlen($query['state']));
        $this->assertEqualsWithDelta(time() + 300, (int) $query['expires'], 2);

        [$signed, $signature] = explode('&signature=', $url);

        $this->assertSame(hash_hmac('sha256', $signed, 'shared-secret'), $signature);
    }

    public function test_will_not_start_without_the_nonce_from_its_own_page(): void
    {
        $url = $this->flow()->start();

        $this->assertSame(admin_url('admin.php?page=altimiser'), $url);
        $this->assertSame('error', ConnectionFlow::takeNotice()['level']);
    }

    public function test_will_not_start_for_someone_who_cannot_review(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'author']));

        $this->assertSame(admin_url('admin.php?page=altimiser'), $this->startConnecting());
    }

    public function test_says_why_when_wp_admin_is_on_another_address_from_the_site(): void
    {
        $_GET['_wpnonce'] = $_REQUEST['_wpnonce'] = wp_create_nonce('altimiser_connect');

        $url = $this->flow('https://www.client.example.com')->start();

        $this->assertSame(admin_url('admin.php?page=altimiser'), $url);
        $this->assertStringContainsString('same address as the site itself', ConnectionFlow::takeNotice()['message']);
    }

    public function test_keeps_the_connection_altimiser_hands_back(): void
    {
        $state = $this->queryOf($this->startConnecting())['state'];
        $this->altimiserAnswers(201, '{"connection":"01jcz6r4s8x2m0v3q9k7t5w1yd","token":"'.str_repeat('ab', 32).'","expires_at":"'.gmdate('c', time() + HOUR_IN_SECONDS).'","account":{"email":"sam@agency.example"}}');

        $url = $this->comeBackWith(['action' => 'altimiser_connected', 'code' => 'the-code', 'state' => $state]);

        $this->assertSame(admin_url('admin.php?page=altimiser'), $url);
        $this->assertSame(['level' => 'success', 'message' => 'Your Altimiser account is connected.'], ConnectionFlow::takeNotice());
        $this->assertSame(str_repeat('ab', 32), Connections::live($this->editor->ID)['token']);
        $this->assertStringContainsString('"code":"the-code"', $this->requests[0]['args']['body']);
        $this->assertStringContainsString('"redirect_uri":"'.admin_url('admin-post.php?action=altimiser_connected').'"', $this->requests[0]['args']['body']);
    }

    public function test_keeps_the_token_encrypted_and_against_this_site_only(): void
    {
        global $wpdb;

        Connections::put($this->editor->ID, ['connection' => 'c', 'token' => str_repeat('ab', 32), 'expires_at' => time() + 60, 'account_email' => '']);

        $stored = get_user_meta($this->editor->ID, $wpdb->get_blog_prefix().Connections::$option, true);

        $this->assertIsArray($stored);
        $this->assertStringNotContainsString(str_repeat('ab', 32), (string) wp_json_encode($stored));
        $this->assertSame('', get_user_meta($this->editor->ID, Connections::$option, true));
    }

    public function test_refuses_a_return_whose_state_does_not_match_and_asks_altimiser_nothing(): void
    {
        $this->startConnecting();
        $this->altimiserAnswers(201, '{}');

        $this->comeBackWith(['code' => 'the-code', 'state' => str_repeat('x', 40)]);

        $this->assertStringContainsString('could not be checked', ConnectionFlow::takeNotice()['message']);
        $this->assertSame([], $this->requests);
        $this->assertNull(Connections::live($this->editor->ID));
    }

    public function test_uses_a_state_once(): void
    {
        $state = $this->queryOf($this->startConnecting())['state'];
        $this->altimiserAnswers(422, '{"message":"That code has already been used."}');

        $this->comeBackWith(['code' => 'the-code', 'state' => $state]);
        $this->comeBackWith(['code' => 'the-code', 'state' => $state]);

        $this->assertCount(1, $this->requests);
    }

    public function test_explains_a_declined_connection_without_blaming_the_editor(): void
    {
        $state = $this->queryOf($this->startConnecting())['state'];

        $this->comeBackWith(['error' => 'access_denied', 'state' => $state]);

        $this->assertStringContainsString('Either it was declined, or your account has not been given this site.', ConnectionFlow::takeNotice()['message']);
    }

    public function test_passes_on_altimisers_reason_for_refusing_a_code(): void
    {
        $state = $this->queryOf($this->startConnecting())['state'];
        $this->altimiserAnswers(422, '{"message":"That code has expired."}');

        $this->comeBackWith(['code' => 'the-code', 'state' => $state]);

        $this->assertSame(['level' => 'error', 'message' => 'Your Altimiser account could not be connected: That code has expired.'], ConnectionFlow::takeNotice());
    }

    public function test_forgets_the_connection_on_disconnecting(): void
    {
        Connections::put($this->editor->ID, ['connection' => 'c', 'token' => 't', 'expires_at' => time() + 60, 'account_email' => '']);
        $_REQUEST['_wpnonce'] = wp_create_nonce('altimiser_disconnect');

        $this->flow()->disconnect();

        $this->assertNull(Connections::live($this->editor->ID));
        $this->assertSame('success', ConnectionFlow::takeNotice()['level']);
    }

    public function test_keeps_the_connection_when_disconnecting_without_the_nonce(): void
    {
        Connections::put($this->editor->ID, ['connection' => 'c', 'token' => 't', 'expires_at' => time() + 60, 'account_email' => '']);

        $this->flow()->disconnect();

        $this->assertNotNull(Connections::live($this->editor->ID));
    }
}
