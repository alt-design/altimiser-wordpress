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
