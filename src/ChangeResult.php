<?php

namespace AltDesign\Altimiser;

class ChangeResult
{
    /**
     * @param  array<string, mixed>  $location
     * @return array<string, mixed>
     */
    public static function applied(string $id, array $location, ?string $from, ?string $to): array
    {
        return array_filter(
            ['id' => $id, 'status' => 'applied', 'location' => $location, 'from' => $from, 'to' => $to],
            static fn (mixed $value): bool => $value !== null,
        );
    }

    /**
     * @param  array<string, mixed>  $location
     * @return array<string, mixed>
     */
    public static function alreadyApplied(string $id, array $location, string $message): array
    {
        return ['id' => $id, 'status' => 'already_applied', 'location' => $location, 'message' => $message];
    }

    /**
     * @return array<string, mixed>
     */
    public static function deferred(string $id): array
    {
        return ['id' => $id, 'status' => 'deferred', 'message' => 'Not attempted in this request. Send it again.'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function skipped(string $id, string $reason, string $message): array
    {
        return ['id' => $id, 'status' => 'skipped', 'reason' => $reason, 'message' => $message];
    }

    /**
     * @return array<string, mixed>
     */
    public static function failed(string $id, string $reason, string $message): array
    {
        return ['id' => $id, 'status' => 'failed', 'reason' => $reason, 'message' => $message];
    }
}
