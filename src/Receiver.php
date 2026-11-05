<?php

namespace AltDesign\Altimiser;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class Receiver
{
    private int $toleranceInSeconds = 300;

    public function __construct(private string $pluginFile, private ?Yoast $yoast) {}

    public function registerRoutes(): void
    {
        register_rest_route('altimiser/v1', '/health', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'health'],
            'permission_callback' => [$this, 'verifySignature'],
        ]);

        register_rest_route('altimiser/v1', '/changes', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'changes'],
            'permission_callback' => [$this, 'verifySignature'],
        ]);
    }

    public function verifySignature(WP_REST_Request $request): bool|WP_Error
    {
        $secret = defined('ALTIMISER_SECRET') ? constant('ALTIMISER_SECRET') : null;

        if (! is_string($secret) || trim($secret) === '') {
            return $this->refuse('Altimiser is not configured on this site.');
        }

        $timestamp = (int) $request->get_header('X-Altimiser-Timestamp');

        if (abs(time() - $timestamp) > $this->toleranceInSeconds) {
            return $this->refuse('Signature timestamp is outside the accepted window.');
        }

        $expected = 'sha256='.hash_hmac('sha256', "{$timestamp}\n{$request->get_body()}", $secret);

        if (! hash_equals($expected, (string) $request->get_header('X-Altimiser-Signature'))) {
            return $this->refuse('Signature does not match.');
        }

        return true;
    }

    public function health(): WP_REST_Response
    {
        $checks = array_merge([], ...array_map(
            static fn (YoastApplier $applier): array => $applier->checks(),
            $this->appliers(),
        ));

        return $this->uncached([
            'receiver' => 'wordpress',
            'receiver_version' => get_file_data($this->pluginFile, ['version' => 'Version'])['version'],
            'cms_version' => get_bloginfo('version'),
            'capabilities' => $checks === [] ? [] : ['content.update'],
            'checks' => $checks,
            'integrations' => ['yoast' => $this->yoastState()],
            'site_url' => home_url(),
        ]);
    }

    public function changes(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $payload = json_decode($request->get_body(), true);

        if (! is_array($payload) || ! is_array($payload['changes'] ?? null) || ! is_bool($payload['dry_run'] ?? false)) {
            return new WP_Error(
                'altimiser_unreadable',
                'The body needs a changes array, and dry_run has to be true or false where it is sent.',
                ['status' => 422],
            );
        }

        $changes = [];
        $rejected = [];

        foreach ($payload['changes'] as $index => $change) {
            $parsed = ChangeRequest::fromPayload($change);

            if ($parsed === null) {
                $id = is_array($change) && is_string($change['id'] ?? null) ? $change['id'] : "index-{$index}";
                $rejected[] = ChangeResult::failed($id, 'invalid_change', 'This change is malformed and could not be read.');

                continue;
            }

            $changes[] = $parsed;
        }

        $dispatcher = new ChangeDispatcher($this->appliers(), new PageCache());

        return new WP_REST_Response([
            'results' => [...$rejected, ...$dispatcher->dispatch($changes, $payload['dry_run'] ?? false)],
        ]);
    }

    /**
     * @return array<int, YoastApplier>
     */
    private function appliers(): array
    {
        return $this->yoast === null ? [] : [new YoastApplier($this->yoast, new ContentResolver())];
    }

    /**
     * @return array<string, mixed>
     */
    private function yoastState(): array
    {
        return $this->yoast === null
            ? ['installed' => false]
            : ['installed' => true, 'version' => $this->yoast->version()];
    }

    /**
     * WordPress only sends no-cache headers to logged in users, and this caller
     * never is one. A page cache that kept this response would serve it to later
     * requests without running the signature check.
     *
     * Core marks Last-Modified for removal with false, which a REST response would
     * send as an empty header, so falsy values are filtered out.
     *
     * Breeze 2.1.11 keeps REST responses of 255 bytes or more, ignoring both the
     * constant and the headers. Its one exception that a request can reach is
     * post_password_required() being true when its buffer callback runs at shutdown.
     *
     * @param  array<string, mixed>  $body
     */
    private function uncached(array $body): WP_REST_Response
    {
        if (! defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }

        add_action('shutdown', static fn () => add_filter('post_password_required', '__return_true'), 0);

        return new WP_REST_Response($body, 200, array_filter(wp_get_nocache_headers()));
    }

    private function refuse(string $message): WP_Error
    {
        return new WP_Error('altimiser_refused', $message, ['status' => 401]);
    }
}
