<?php

namespace AltDesign\Altimiser;

/**
 * Where the plugin's configuration comes from: a constant in wp-config.php first,
 * then the server's environment, then the settings page. Each site on a multisite
 * network has its own settings page, and so its own secret.
 */
class Settings
{
    public static int $minimumSecretLength = 32;

    public static string $option = 'altimiser_settings';

    public static function secret(): ?string
    {
        return self::read('ALTIMISER_SECRET', 'secret');
    }

    public static function altimiserUrl(): ?string
    {
        $url = self::read('ALTIMISER_URL', 'altimiser_url');

        if ($url === null || ! self::isSecureAddress($url)) {
            return null;
        }

        return rtrim($url, '/');
    }

    /**
     * The review link carries a signature and the editor's address, so plain http is
     * only accepted for a development machine.
     */
    public static function isSecureAddress(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https') {
            return true;
        }

        return in_array($host, ['localhost', '127.0.0.1'], true) || str_ends_with($host, '.test');
    }

    public static function secretIsLongEnough(string $secret): bool
    {
        return strlen($secret) >= self::$minimumSecretLength;
    }

    /**
     * Whether a value is fixed outside the settings page, which then shows it as set
     * elsewhere rather than offering a field that would be ignored.
     */
    public static function definedOutsideSettings(string $constant): bool
    {
        return self::fromCode($constant) !== null;
    }

    /**
     * @return array{secret?: string, altimiser_url?: string}
     */
    public static function stored(): array
    {
        $stored = get_option(self::$option, []);

        return is_array($stored) ? $stored : [];
    }

    /**
     * The secret is needed only when a request arrives, so it stays out of the options
     * WordPress loads and caches for every page.
     */
    public static function keepOutOfAutoload(): void
    {
        wp_set_option_autoload(self::$option, false);
    }

    private static function read(string $constant, string $key): ?string
    {
        $value = self::fromCode($constant) ?? (self::stored()[$key] ?? null);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function fromCode(string $constant): ?string
    {
        $value = defined($constant) ? constant($constant) : getenv($constant);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
