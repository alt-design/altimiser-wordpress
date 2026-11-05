<?php

namespace AltDesign\Altimiser;

class ChangeRequest
{
    /**
     * @param  array<string, mixed>  $target
     */
    private function __construct(
        public readonly string $id,
        public readonly string $check,
        public readonly string $url,
        public readonly array $target,
        public readonly ?string $currentValue,
        public readonly ?string $suggestedValue,
    ) {}

    /**
     * Null when the change is malformed, which is reported for that change alone.
     */
    public static function fromPayload(mixed $change): ?self
    {
        if (! is_array($change)) {
            return null;
        }

        foreach (['id', 'check', 'url'] as $required) {
            if (! is_string($change[$required] ?? null) || $change[$required] === '') {
                return null;
            }
        }

        foreach (['current_value', 'suggested_value'] as $optional) {
            if (! is_string($change[$optional] ?? '')) {
                return null;
            }
        }

        if (! is_array($change['target'] ?? [])) {
            return null;
        }

        return new self(
            $change['id'],
            $change['check'],
            $change['url'],
            $change['target'] ?? [],
            $change['current_value'] ?? null,
            $change['suggested_value'] ?? null,
        );
    }
}
