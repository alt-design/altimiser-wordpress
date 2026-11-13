<?php

namespace AltDesign\Altimiser;

use Throwable;
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

    /**
     * A refused request that carried a signature is logged, because it is most likely
     * Altimiser with the wrong secret or a clock out of step. Ones without are left out,
     * and at most one a minute is logged, since anything can send the header and a
     * caller sending many would otherwise fill the log.
     */
    public function verifySignature(WP_REST_Request $request): bool|WP_Error
    {
        $refusal = $this->refusal($request);

        if ($refusal === null) {
            return true;
        }

        if ($request->get_header('X-Altimiser-Signature') !== null) {
            $this->logRefusal("Altimiser refused a signed request to {$request->get_route()}: {$refusal}");
        }

        return new WP_Error('altimiser_refused', $refusal, ['status' => 401]);
    }

    private function logRefusal(string $message): void
    {
        if (get_transient('altimiser_refusal_logged') !== false) {
            return;
        }

        set_transient('altimiser_refusal_logged', 1, MINUTE_IN_SECONDS);
        error_log($message);
    }

    private function refusal(WP_REST_Request $request): ?string
    {
        $secret = Settings::secret();

        if ($secret === null) {
            return 'Altimiser is not configured on this site.';
        }

        if (! Settings::secretIsLongEnough($secret)) {
            return 'The Altimiser secret on this site is shorter than 32 characters, so requests are refused until it is replaced with a longer one.';
        }

        $timestamp = (int) $request->get_header('X-Altimiser-Timestamp');

        if (abs(time() - $timestamp) > $this->toleranceInSeconds) {
            return 'Signature timestamp is outside the accepted window.';
        }

        $expected = 'sha256='.hash_hmac('sha256', "{$timestamp}\n{$request->get_body()}", $secret);

        if (! hash_equals($expected, (string) $request->get_header('X-Altimiser-Signature'))) {
            return 'Signature does not match.';
        }

        return null;
    }

    public function health(): WP_REST_Response
    {
        $checks = $this->advertisedChecks();

        return $this->uncached([
            'receiver' => 'wordpress',
            'receiver_version' => get_file_data($this->pluginFile, ['version' => 'Version'])['version'],
            'cms_version' => get_bloginfo('version'),
            'capabilities' => $this->capabilities($checks),
            'checks' => $checks,
            'integrations' => ['yoast' => $this->yoastState()],
            'setup' => $this->setup(),
            'site_url' => home_url(),
        ]);
    }

    /**
     * An applier that throws is left out rather than stopping the answer, so a fault
     * in Yoast costs its own checks and not the connection.
     *
     * @return array<int, string>
     */
    private function advertisedChecks(): array
    {
        $checks = [];

        foreach ($this->appliers() as $applier) {
            try {
                $checks = [...$checks, ...$applier->checks()];
            } catch (Throwable $exception) {
                error_log("Altimiser left out the checks of one applier: {$exception->getMessage()}");
            }
        }

        return array_values(array_unique($checks));
    }

    /**
     * @param  array<int, string>  $checks
     * @return array<int, string>
     */
    private function capabilities(array $checks): array
    {
        return array_keys(array_filter([
            'content.update' => $checks !== [],
            'content.link' => in_array('link.opportunity', $checks, true),
        ]));
    }

    /**
     * Settings that stop fixes from counting, which Altimiser reports as findings.
     *
     * @return array<string, bool>
     */
    private function setup(): array
    {
        return [
            'search_engines_discouraged' => (string) get_option('blog_public', '1') === '0',
            'plain_permalinks' => (string) get_option('permalink_structure') === '',
        ];
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
     * @return array<int, Applier>
     */
    private function appliers(): array
    {
        $content = new ContentResolver();
        $writer = new ContentWriter();

        return [
            $this->yoast === null ? new InactiveSeoPlugin() : new YoastApplier($this->yoast, $content),
            new AltTextApplier($content, $writer),
            new LinkApplier($content, new MentionLinker(), $writer),
            new RobotsApplier(),
        ];
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
}
