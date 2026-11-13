<?php

namespace AltDesign\Altimiser;

/**
 * Connects an editor's Altimiser account, through the authorisation code flow the
 * protocol describes. Each step answers with the address to send the browser to next.
 */
class ConnectionFlow
{
    public function __construct(private ControlPanel $panel) {}

    public function register(): void
    {
        foreach (['connect' => 'start', 'connected' => 'finish', 'disconnect' => 'disconnect'] as $action => $step) {
            add_action("admin_post_altimiser_{$action}", function () use ($step): void {
                wp_redirect($this->{$step}());
                exit;
            });
        }
    }

    public function start(): string
    {
        if (! $this->allowed('altimiser_connect')) {
            return self::back('error', __('That link has expired. Try connecting again.', 'altimiser'));
        }

        if (! $this->panel->isOnSiteOrigin(self::callbackUrl())) {
            return self::back('error', __('Connecting needs wp-admin to be on the same address as the site itself. Open wp-admin at the site\'s own address and try again.', 'altimiser'));
        }

        $state = wp_generate_password(40, false);

        set_transient(self::stateKey(), $state, 10 * MINUTE_IN_SECONDS);

        return $this->panel->connectUrl($state, self::callbackUrl(), wp_get_current_user()->user_email, time() + 5 * MINUTE_IN_SECONDS);
    }

    public function finish(): string
    {
        $expected = get_transient(self::stateKey());
        $state = self::query('state');

        delete_transient(self::stateKey());

        if (! current_user_can(ReviewQueuePage::$capability) || ! is_string($expected) || $state === null || ! hash_equals($expected, $state)) {
            return self::back('error', __('That connection attempt could not be checked. Try connecting again.', 'altimiser'));
        }

        if (self::query('error') === 'access_denied') {
            return self::back('error', __('Your Altimiser account was not connected. Either it was declined, or your account has not been given this site. Ask your agency if you expected it to be.', 'altimiser'));
        }

        $code = self::query('code');

        if ($code === null) {
            return self::back('error', __('Altimiser did not send back a code. Try connecting again.', 'altimiser'));
        }

        $connection = $this->panel->exchange($code, self::callbackUrl());

        if (is_wp_error($connection)) {
            /* translators: %s: Altimiser's reason, in English */
            return self::back('error', sprintf(__('Your Altimiser account could not be connected: %s', 'altimiser'), $connection->get_error_message()));
        }

        Connections::put(get_current_user_id(), $connection);

        return self::back('success', __('Your Altimiser account is connected.', 'altimiser'));
    }

    public function disconnect(): string
    {
        if (! $this->allowed('altimiser_disconnect')) {
            return self::back('error', __('That link has expired. Try again.', 'altimiser'));
        }

        Connections::forget(get_current_user_id());

        return self::back('success', __('Your Altimiser account is disconnected.', 'altimiser'));
    }

    /**
     * The message the last step left for the review queue page, read once.
     *
     * @return ?array{level: string, message: string}
     */
    public static function takeNotice(): ?array
    {
        $key = 'altimiser_notice_'.get_current_user_id();
        $notice = get_transient($key);

        delete_transient($key);

        return is_array($notice) ? $notice : null;
    }

    private function allowed(string $action): bool
    {
        return current_user_can(ReviewQueuePage::$capability)
            && wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'] ?? '')), $action) !== false;
    }

    private static function back(string $level, string $message): string
    {
        set_transient('altimiser_notice_'.get_current_user_id(), ['level' => $level, 'message' => $message], MINUTE_IN_SECONDS);

        return admin_url('admin.php?page=altimiser');
    }

    private static function callbackUrl(): string
    {
        return admin_url('admin-post.php?action=altimiser_connected');
    }

    private static function stateKey(): string
    {
        return 'altimiser_connect_state_'.get_current_user_id();
    }

    private static function query(string $key): ?string
    {
        $value = $_GET[$key] ?? null;

        return is_string($value) && $value !== '' ? sanitize_text_field(wp_unslash($value)) : null;
    }
}
