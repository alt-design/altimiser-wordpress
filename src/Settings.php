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

        return $url === null ? null : rtrim($url, '/');
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
