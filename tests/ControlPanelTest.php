<?php

use AltDesign\Altimiser\ControlPanel;

class ControlPanelTest extends WP_UnitTestCase
{
    /** @var array<int, array{url: string, args: array<string, mixed>}> */
    private array $requests = [];

    private function panel(): ControlPanel
    {
        return new ControlPanel('https://altimiser.example.com', 'https://example.com', 'shared-secret');
    }

    /**
     * @param  array<string, mixed>|WP_Error  $response
     */
    private function altimiserAnswers(array|WP_Error $response): void
    {
        add_filter('pre_http_request', function ($preempt, array $args, string $url) use ($response) {
            $this->requests[] = ['url' => $url, 'args' => $args];

            return $response;
        }, 10, 3);
    }

    /**
     * @return array<string, mixed>
     */
    private function answer(int $status, string $body): array
    {
        return ['headers' => [], 'body' => $body, 'response' => ['code' => $status, 'message' => ''], 'cookies' => [], 'filename' => null];
    }

    /**
     * @return array{connection: string, token: string}
     */
    private function connection(): array
    {
        return ['connection' => '01jcz6r4s8x2m0v3q9k7t5w1yd', 'token' => str_repeat('ab', 32)];
    }

    public function test_signs_the_worked_review_link_in_the_protocol_document(): void
    {
        $this->assertSame(
            'https://altimiser.example.com/review?connection=01jcz6r4s8x2m0v3q9k7t5w1yd&embedded=1&expires=1769558400&site=https%3A%2F%2Fexample.com&user=editor%40example.com'
            .'&signature=d747d7461ab0bd482ca2c63dbfdba805272879161a55b85294b1bfbc254d670f'
            .'&connection_signature=05f7d157967aa7d78326438cc32082256bd12016a9f6eb3de2145b6e931d7cba',
            $this->panel()->reviewUrl('editor@example.com', 1769558400, $this->connection()),
        );
    }

    public function test_signs_the_worked_connect_link_in_the_protocol_document(): void
    {
        $this->assertSame(
            'https://altimiser.example.com/connect?expires=1769558400&redirect_uri=https%3A%2F%2Fexample.com%2Fcp%2Faltimiser%2Fconnect%2Fcallback&site=https%3A%2F%2Fexample.com&state=s7Kq2wX9pL4mN8vR3tY6uZ1aB5cD0eFg&user=editor%40example.com'
            .'&signature=43e86c386c1c7fd4f94ffcd47fc0ea4ca48e14631ab991cf3f6d10bbd7cbc271',
            $this->panel()->connectUrl('s7Kq2wX9pL4mN8vR3tY6uZ1aB5cD0eFg', 'https://example.com/cp/altimiser/connect/callback', 'editor@example.com', 1769558400),
        );
    }

    public function test_leaves_out_a_user_with_no_email(): void
    {
        $this->assertStringNotContainsString('user=', $this->panel()->reviewUrl('', 1769558400, $this->connection()));
    }

    public function test_exchanges_the_code_with_a_request_signed_over_its_body(): void
    {
        $this->altimiserAnswers($this->answer(201, '{"connection":"01jcz6r4s8x2m0v3q9k7t5w1yd","token":"'.str_repeat('ab', 32).'","expires_at":"2026-11-12T10:00:00+00:00","account":{"name":"Sam Example","email":"sam@agency.example"}}'));

        $connection = $this->panel()->exchange('the-code', 'https://example.com/wp-admin/admin-post.php?action=altimiser_connected');

        $this->assertSame([
            'connection' => '01jcz6r4s8x2m0v3q9k7t5w1yd',
            'token' => str_repeat('ab', 32),
            'expires_at' => 1794477600,
            'account_email' => 'sam@agency.example',
        ], $connection);

        ['url' => $url, 'args' => $args] = $this->requests[0];
        $timestamp = $args['headers']['X-Altimiser-Timestamp'];

        $this->assertSame('https://altimiser.example.com/api/connections', $url);
        $this->assertSame('POST', $args['method']);
        $this->assertSame('{"site":"https://example.com","code":"the-code","redirect_uri":"https://example.com/wp-admin/admin-post.php?action=altimiser_connected"}', $args['body']);
        $this->assertSame('sha256='.hash_hmac('sha256', "{$timestamp}\n{$args['body']}", 'shared-secret'), $args['headers']['X-Altimiser-Signature']);
        $this->assertSame(0, $args['redirection']);
    }

    /**
     * @dataProvider failedExchanges
     *
     * @param  array<string, mixed>|WP_Error  $response
     */
    public function test_says_why_a_code_could_not_be_exchanged(array|WP_Error $response, string $reason): void
    {
        $this->altimiserAnswers($response);

        $connection = $this->panel()->exchange('the-code', 'https://example.com/wp-admin/admin-post.php');

        $this->assertInstanceOf(WP_Error::class, $connection);
        $this->assertSame($reason, $connection->get_error_message());
    }

    /**
     * @return array<string, array{array<string, mixed>|WP_Error, string}>
     */
    public static function failedExchanges(): array
    {
        $answer = static fn (int $status, string $body): array => ['headers' => [], 'body' => $body, 'response' => ['code' => $status, 'message' => ''], 'cookies' => [], 'filename' => null];

        return [
            'unreachable' => [new WP_Error('http_request_failed', 'Connection timed out'), 'Altimiser could not be reached.'],
            'a used code' => [$answer(422, '{"message":"That code has already been used."}'), 'That code has already been used.'],
            'a server error' => [$answer(500, '<html>Server error</html>'), 'Altimiser refused the connection.'],
            'no token' => [$answer(201, '{"connection":"01jcz6r4s8x2m0v3q9k7t5w1yd","expires_at":"2026-11-12T10:00:00+00:00"}'), 'Altimiser sent an answer that could not be read.'],
            'no expiry' => [$answer(201, '{"connection":"01jcz6r4s8x2m0v3q9k7t5w1yd","token":"abc"}'), 'Altimiser sent an answer that could not be read.'],
        ];
    }

    /**
     * @dataProvider origins
     */
    public function test_knows_whether_an_address_is_on_the_sites_own_origin(string $address, bool $same): void
    {
        $this->assertSame($same, $this->panel()->isOnSiteOrigin($address));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function origins(): array
    {
        return [
            'wp-admin on the same host' => ['https://example.com/wp-admin/admin-post.php?action=altimiser_connected', true],
            'the default port written out' => ['https://EXAMPLE.com:443/wp-admin/', true],
            'another host' => ['https://admin.example.com/wp-admin/', false],
            'plain http' => ['http://example.com/wp-admin/', false],
            'another port' => ['https://example.com:8443/wp-admin/', false],
        ];
    }

    public function test_expires_a_link_after_the_configured_minutes(): void
    {
        $panel = new ControlPanel('https://altimiser.example.com', 'https://example.com', 'shared-secret', 10);

        $this->assertEqualsWithDelta(time() + 600, $panel->linkExpiry(), 2);
    }

    public function test_reads_the_count_with_a_request_signed_over_the_empty_body(): void
    {
        $this->altimiserAnswers($this->answer(200, '{"awaiting_review":12,"pending":3}'));

        $this->assertSame(12, $this->panel()->awaitingReview());

        ['url' => $url, 'args' => $args] = $this->requests[0];
        $timestamp = $args['headers']['X-Altimiser-Timestamp'];

        $this->assertSame('https://altimiser.example.com/api/summary?site=https%3A%2F%2Fexample.com', $url);
        $this->assertSame('sha256='.hash_hmac('sha256', "{$timestamp}\n", 'shared-secret'), $args['headers']['X-Altimiser-Signature']);
        $this->assertEqualsWithDelta(time(), (int) $timestamp, 2);
        $this->assertSame(2, $args['timeout']);
        $this->assertSame(0, $args['redirection']);
    }

    public function test_keeps_the_count_rather_than_asking_on_every_page(): void
    {
        $this->altimiserAnswers($this->answer(200, '{"awaiting_review":0}'));

        $this->assertSame(0, $this->panel()->awaitingReview());
        $this->assertSame(0, $this->panel()->awaitingReview());
        $this->assertCount(1, $this->requests);
    }

    /**
     * @dataProvider unusableAnswers
     *
     * @param  array<string, mixed>|WP_Error  $response
     */
    public function test_has_no_number_when_altimiser_gives_no_usable_answer(array|WP_Error $response): void
    {
        $this->altimiserAnswers($response);

        $this->assertNull($this->panel()->awaitingReview());
    }

    /**
     * @return array<string, array{array<string, mixed>|WP_Error}>
     */
    public static function unusableAnswers(): array
    {
        $answer = static fn (int $status, string $body): array => ['headers' => [], 'body' => $body, 'response' => ['code' => $status, 'message' => ''], 'cookies' => [], 'filename' => null];

        return [
            'unreachable' => [new WP_Error('http_request_failed', 'Connection timed out')],
            'refused' => [$answer(401, '{"message":"Signature does not match."}')],
            'a server error' => [$answer(500, 'Server error')],
            'not json' => [$answer(200, '<html>A login page</html>')],
            'json that is not an object' => [$answer(200, '12')],
            'no count in it' => [$answer(200, '{"pending":3}')],
            'a count that is not a number' => [$answer(200, '{"awaiting_review":"twelve"}')],
        ];
    }

    public function test_remembers_altimiser_being_unreachable_so_only_one_page_waits(): void
    {
        $this->altimiserAnswers(new WP_Error('http_request_failed', 'Connection timed out'));

        $this->panel()->awaitingReview();
        $this->panel()->awaitingReview();

        $this->assertCount(1, $this->requests);
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    public function test_needs_altimisers_address_before_it_can_link_to_it(): void
    {
        define('ALTIMISER_SECRET', 'a-shared-secret-of-at-least-32-characters');

        $this->assertNull(ControlPanel::configured());
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    public function test_needs_the_secret_before_it_can_sign_anything(): void
    {
        define('ALTIMISER_URL', 'https://altimiser.example.com');

        $this->assertNull(ControlPanel::configured());
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    public function test_names_the_site_by_its_own_address_with_no_trailing_slash(): void
    {
        define('ALTIMISER_URL', 'https://altimiser.example.com/');
        define('ALTIMISER_SECRET', 'a-shared-secret-of-at-least-32-characters');
        add_filter('home_url', static fn (): string => 'https://client.example.com/');

        $url = ControlPanel::configured()->reviewUrl('', 1769558400, ['connection' => 'c', 'token' => 't']);

        $this->assertStringStartsWith('https://altimiser.example.com/review?', $url);
        $this->assertStringContainsString('site=https%3A%2F%2Fclient.example.com&', $url);
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    public function test_takes_the_sites_address_from_a_constant_where_the_two_disagree(): void
    {
        define('ALTIMISER_URL', 'https://altimiser.example.com');
        define('ALTIMISER_SECRET', 'a-shared-secret-of-at-least-32-characters');
        define('ALTIMISER_SITE_URL', 'https://www.client.example.com/');
        define('ALTIMISER_LINK_MINUTES', 10);

        $panel = ControlPanel::configured();

        $this->assertStringContainsString('site=https%3A%2F%2Fwww.client.example.com&', $panel->reviewUrl('', 1769558400, ['connection' => 'c', 'token' => 't']));
        $this->assertEqualsWithDelta(time() + 600, $panel->linkExpiry(), 2);
    }
}
