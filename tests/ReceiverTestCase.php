<?php

/**
 * Requests as docs/receiver-protocol.md in alt-design/altimiser describes them.
 *
 * The signature is computed here rather than taken from the plugin or from
 * Altimiser, so the plugin is held to the written contract and not to one
 * implementation of the other end.
 */
abstract class ReceiverTestCase extends WP_UnitTestCase
{
    protected static string $secret = 'shared-secret';

    protected static function configureSecret(): void
    {
        if (! defined('ALTIMISER_SECRET')) {
            define('ALTIMISER_SECRET', self::$secret);
        }
    }

    protected function protocolSignature(string $body, int $timestamp, string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', "{$timestamp}\n{$body}", $secret);
    }

    protected function request(string $body = '', ?string $timestamp = null, ?string $signature = null): WP_REST_Request
    {
        $request = new WP_REST_Request('GET', '/altimiser/v1/health');

        $request->set_body($body);

        if ($timestamp !== null) {
            $request->set_header('X-Altimiser-Timestamp', $timestamp);
        }

        if ($signature !== null) {
            $request->set_header('X-Altimiser-Signature', $signature);
        }

        return $request;
    }

    protected function signedRequest(string $body = '', ?int $timestamp = null, ?string $secret = null): WP_REST_Request
    {
        $timestamp ??= time();

        return $this->request(
            $body,
            (string) $timestamp,
            $this->protocolSignature($body, $timestamp, $secret ?? self::$secret),
        );
    }

    protected function signedPost(string $body): WP_REST_Request
    {
        $timestamp = time();
        $request = new WP_REST_Request('POST', '/altimiser/v1/changes');

        $request->set_body($body);
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('X-Altimiser-Timestamp', (string) $timestamp);
        $request->set_header('X-Altimiser-Signature', $this->protocolSignature($body, $timestamp, self::$secret));

        return $request;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function change(array $overrides = []): array
    {
        return [
            'id' => 'fingerprint-abc',
            'check' => 'meta_description.too_short',
            'url' => home_url('/about/'),
            'target' => ['selector' => 'meta[name="description"]', 'attribute' => 'content'],
            'current_value' => 'About us.',
            'suggested_value' => 'How our studio works, and who we build for.',
            ...$overrides,
        ];
    }

    /**
     * @param  array<int, mixed>  $changes
     * @return array<int, array<string, mixed>>
     */
    protected function send(array $changes, bool $dryRun = false): array
    {
        $body = json_encode(['dry_run' => $dryRun, 'changes' => $changes]);

        return $this->dispatch($this->signedPost($body))->get_data()['results'];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function sendOne(array $overrides = [], bool $dryRun = false): array
    {
        return $this->send([$this->change($overrides)], $dryRun)[0];
    }

    protected function dispatch(WP_REST_Request $request): WP_REST_Response
    {
        return rest_get_server()->dispatch($request);
    }

    protected function assertRefused(WP_REST_Response $response, string $message): void
    {
        $this->assertSame(401, $response->get_status());
        $this->assertSame($message, $response->get_data()['message']);
    }
}
