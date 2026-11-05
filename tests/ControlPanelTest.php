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

    public function test_signs_the_worked_example_in_the_protocol_document(): void
    {
        $this->assertSame(
            'https://altimiser.example.com/review?embedded=1&expires=1769558400&site=https%3A%2F%2Fexample.com&user=editor%40example.com&signature=56424ced69568825952d43fb40de622e730610712898c7ecc35cd1b0d1dd2347',
            $this->panel()->reviewUrl('editor@example.com', 1769558400),
        );
    }

    public function test_leaves_out_a_user_with_no_email(): void
    {
        $this->assertStringNotContainsString('user=', $this->panel()->reviewUrl('', 1769558400));
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
        $this->assertSame(5, $args['timeout']);
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
        define('ALTIMISER_SECRET', 'shared-secret');

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
        define('ALTIMISER_SECRET', 'shared-secret');
        add_filter('home_url', static fn (): string => 'https://client.example.com/');

        $url = ControlPanel::configured()->reviewUrl('', 1769558400);

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
        define('ALTIMISER_SECRET', 'shared-secret');
        define('ALTIMISER_SITE_URL', 'https://www.client.example.com/');
        define('ALTIMISER_LINK_MINUTES', 10);

        $panel = ControlPanel::configured();

        $this->assertStringContainsString('site=https%3A%2F%2Fwww.client.example.com&', $panel->reviewUrl('', 1769558400));
        $this->assertEqualsWithDelta(time() + 600, $panel->linkExpiry(), 2);
    }
}
