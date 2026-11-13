<?php

namespace AltDesign\Altimiser;

/**
 * Each editor's Altimiser connection, kept against them on this site only. The token is
 * encrypted with a key drawn from WordPress's salts, so a copy of the database on its
 * own cannot sign a review link.
 */
class Connections
{
    public static string $option = 'altimiser_connection';

    /**
     * @param  array{connection: string, token: string, expires_at: int, account_email: string}  $connection
     */
    public static function put(int $userId, array $connection): void
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        update_user_option($userId, self::$option, [
            ...$connection,
            'token' => base64_encode($nonce.sodium_crypto_secretbox($connection['token'], $nonce, self::key())),
        ]);
    }

    /**
     * The editor's connection, or null when there is none, it has expired, or its token
     * can no longer be read.
     *
     * @return ?array{connection: string, token: string, expires_at: int, account_email: string}
     */
    public static function live(int $userId): ?array
    {
        $stored = get_user_option(self::$option, $userId);

        if (! is_array($stored) || ($stored['expires_at'] ?? 0) <= time()) {
            return null;
        }

        $token = self::open((string) ($stored['token'] ?? ''));

        return $token === null ? null : [...$stored, 'token' => $token];
    }

    public static function forget(int $userId): void
    {
        delete_user_option($userId, self::$option);
    }

    private static function open(string $sealed): ?string
    {
        $sealed = (string) base64_decode($sealed, true);

        if (strlen($sealed) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $token = sodium_crypto_secretbox_open(
            substr($sealed, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($sealed, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            self::key(),
        );

        return $token === false ? null : $token;
    }

    private static function key(): string
    {
        return hash_hmac('sha256', 'altimiser connection', wp_salt('auth'), true);
    }
}
