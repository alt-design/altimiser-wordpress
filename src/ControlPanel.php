<?php

namespace AltDesign\Altimiser;

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
        $altimiserUrl = defined('ALTIMISER_URL') ? constant('ALTIMISER_URL') : null;
        $secret = defined('ALTIMISER_SECRET') ? constant('ALTIMISER_SECRET') : null;

        if (! is_string($altimiserUrl) || trim($altimiserUrl) === '') {
            return null;
        }

        if (! is_string($secret) || trim($secret) === '') {
            return null;
        }

        return new self(
            rtrim($altimiserUrl, '/'),
            rtrim(defined('ALTIMISER_SITE_URL') ? (string) constant('ALTIMISER_SITE_URL') : home_url(), '/'),
            $secret,
            defined('ALTIMISER_LINK_MINUTES') ? (int) constant('ALTIMISER_LINK_MINUTES') : 30,
        );
    }

    public function linkExpiry(): int
    {
        return time() + $this->linkMinutes * MINUTE_IN_SECONDS;
    }

    public function reviewUrl(string $user, int $expires): string
    {
        $url = "{$this->altimiserUrl}/review?".http_build_query(array_filter([
            'embedded' => '1',
            'expires' => $expires,
            'site' => $this->siteUrl,
            'user' => $user,
        ]));

        return "{$url}&signature=".hash_hmac('sha256', $url, $this->secret);
    }

    /**
     * Read on every admin page, so an unreachable Altimiser is remembered as well.
     * Asking again each time would put the timeout in front of every screen.
     */
    public function awaitingReview(): ?int
    {
        $cached = get_transient('altimiser_awaiting_review');

        if (is_array($cached)) {
            return $cached['count'];
        }

        $count = $this->fetchAwaitingReview();

        set_transient('altimiser_awaiting_review', ['count' => $count], 5 * MINUTE_IN_SECONDS);

        return $count;
    }

    /**
     * Redirects are not followed, because the signature would be sent on to wherever
     * they lead.
     */
    private function fetchAwaitingReview(): ?int
    {
        $timestamp = time();

        $response = wp_remote_get("{$this->altimiserUrl}/api/summary?".http_build_query(['site' => $this->siteUrl]), [
            'timeout' => 5,
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

        $count = json_decode(wp_remote_retrieve_body($response), true)['awaiting_review'] ?? null;

        return is_int($count) ? $count : null;
    }
}
