<?php

namespace AltDesign\Altimiser;

use WP_Error;

class ControlPanel
{
    public function __construct(
        private string $altimiserUrl,
        private string $siteUrl,
        private string $secret,
        private int $linkMinutes = 30,
    ) {}

    public static function configured(): ?self
    {
        $altimiserUrl = Settings::altimiserUrl();
        $secret = Settings::secret();

        if ($altimiserUrl === null || $secret === null) {
            return null;
        }

        if (! Settings::secretIsLongEnough($secret)) {
            return null;
        }

        return new self(
            $altimiserUrl,
            rtrim(defined('ALTIMISER_SITE_URL') ? (string) constant('ALTIMISER_SITE_URL') : home_url(), '/'),
            $secret,
            defined('ALTIMISER_LINK_MINUTES') ? (int) constant('ALTIMISER_LINK_MINUTES') : 30,
        );
    }

    public function linkExpiry(): int
    {
        return time() + $this->linkMinutes * MINUTE_IN_SECONDS;
    }

    /**
     * Signed twice: with the site secret, which proves the site, and with the editor's
     * own token, which proves their Altimiser account.
     *
     * @param  array{connection: string, token: string}  $connection
     */
    public function reviewUrl(string $user, int $expires, array $connection): string
    {
        $url = $this->unsignedUrl('/review', [
            'connection' => $connection['connection'],
            'embedded' => '1',
            'expires' => $expires,
            'site' => $this->siteUrl,
            'user' => $user,
        ]);

        return "{$url}&signature=".hash_hmac('sha256', $url, $this->secret)
            .'&connection_signature='.hash_hmac('sha256', $url, $connection['token']);
    }

    public function connectUrl(string $state, string $redirectUri, string $user, int $expires): string
    {
        $url = $this->unsignedUrl('/connect', [
            'expires' => $expires,
            'redirect_uri' => $redirectUri,
            'site' => $this->siteUrl,
            'state' => $state,
            'user' => $user,
        ]);

        return "{$url}&signature=".hash_hmac('sha256', $url, $this->secret);
    }

    /**
     * Trades the code Altimiser sent the editor back with for their connection. Redirects
     * are not followed, because the signature would be sent on to wherever they lead.
     *
     * @return array{connection: string, token: string, expires_at: int, account_email: string}|WP_Error
     */
    public function exchange(string $code, string $redirectUri): array|WP_Error
    {
        $body = (string) wp_json_encode(['site' => $this->siteUrl, 'code' => $code, 'redirect_uri' => $redirectUri], JSON_UNESCAPED_SLASHES);
        $timestamp = time();

        $response = wp_remote_post("{$this->altimiserUrl}/api/connections", [
            'timeout' => 15,
            'redirection' => 0,
            'headers' => [
                'X-Altimiser-Timestamp' => (string) $timestamp,
                'X-Altimiser-Signature' => 'sha256='.hash_hmac('sha256', "{$timestamp}\n{$body}", $this->secret),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => $body,
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('unreachable', __('Altimiser could not be reached.', 'altimiser'));
        }

        $answer = json_decode(wp_remote_retrieve_body($response), true);

        if (wp_remote_retrieve_response_code($response) !== 201) {
            return new WP_Error('refused', is_string($answer['message'] ?? null) ? $answer['message'] : __('Altimiser refused the connection.', 'altimiser'));
        }

        $expiresAt = strtotime((string) ($answer['expires_at'] ?? ''));

        if (! is_string($answer['connection'] ?? null) || ! is_string($answer['token'] ?? null) || $expiresAt === false) {
            return new WP_Error('unreadable', __('Altimiser sent an answer that could not be read.', 'altimiser'));
        }

        return [
            'connection' => $answer['connection'],
            'token' => $answer['token'],
            'expires_at' => $expiresAt,
            'account_email' => (string) ($answer['account']['email'] ?? ''),
        ];
    }

    public function isOnSiteOrigin(string $url): bool
    {
        return self::origin($url) === self::origin($this->siteUrl);
    }

    private static function origin(string $url): string
    {
        $parts = (array) parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        return "{$scheme}://".strtolower($parts['host'] ?? '').":{$port}";
    }

    /**
     * @param  array<string, int|string>  $query
     */
    private function unsignedUrl(string $path, array $query): string
    {
        $query = array_filter($query);

        ksort($query);

        return "{$this->altimiserUrl}{$path}?".http_build_query($query);
    }

    public function awaitingReview(): ?int
    {
        return $this->summary()['awaiting_review'] ?? null;
    }

    /**
     * Read on every admin page, so an unreachable Altimiser is remembered as well.
     * Asking again each time would put the timeout in front of every screen, and the
     * lock keeps the screens opened while one request waits from asking as well.
     *
     * @return ?array<string, int>
     */
    public function summary(): ?array
    {
        $cached = get_transient('altimiser_summary');

        if (is_array($cached)) {
            return $cached['summary'];
        }

        if (get_transient('altimiser_summary_lock') !== false) {
            return null;
        }

        set_transient('altimiser_summary_lock', true, 30);

        $summary = $this->fetchSummary();

        set_transient('altimiser_summary', ['summary' => $summary], 5 * MINUTE_IN_SECONDS);
        delete_transient('altimiser_summary_lock');

        return $summary;
    }

    /**
     * Redirects are not followed, because the signature would be sent on to wherever
     * they lead. Only whole numbers are kept, and the badge's count has to be one of them.
     *
     * @return ?array<string, int>
     */
    private function fetchSummary(): ?array
    {
        $timestamp = time();

        $response = wp_remote_get("{$this->altimiserUrl}/api/summary?".http_build_query(['site' => $this->siteUrl]), [
            'timeout' => 2,
            'redirection' => 0,
            'headers' => [
                'X-Altimiser-Timestamp' => (string) $timestamp,
                'X-Altimiser-Signature' => 'sha256='.hash_hmac('sha256', "{$timestamp}\n", $this->secret),
                'Accept' => 'application/json',
            ],
        ]);

        if (wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (! is_int($body['awaiting_review'] ?? null)) {
            return null;
        }

        return array_filter($body, 'is_int');
    }
}
