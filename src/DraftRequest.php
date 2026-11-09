<?php

namespace AltDesign\Altimiser;

use InvalidArgumentException;

class DraftRequest
{
    private function __construct(
        public readonly string $reference,
        public readonly string $title,
        public readonly ?string $metaDescription,
        public readonly string $body,
        public readonly ?string $collection,
        public readonly ?string $expands,
    ) {}

    /**
     * Limits are those the protocol gives. Altimiser leaves out a key with no value,
     * and a null is read the same way.
     *
     * @throws InvalidArgumentException with a message fit to show somebody
     */
    public static function fromPayload(mixed $payload): self
    {
        if (! is_array($payload) || $payload === []) {
            throw new InvalidArgumentException('The body needs a reference, title and body, and a collection or a page to expand.');
        }

        $reference = self::text($payload, 'reference', 64, required: true);
        $title = self::text($payload, 'title', 200, required: true);
        $body = self::text($payload, 'body', 100000, required: true);
        $metaDescription = self::text($payload, 'meta_description', 500);
        $expands = self::text($payload, 'expands', 2000);
        $collection = self::text($payload, 'collection', 100, required: $expands === null);

        return new self($reference, $title, $metaDescription, $body, $collection, $expands);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function text(array $payload, string $key, int $limit, bool $required = false): ?string
    {
        $value = $payload[$key] ?? null;

        if ($value === null || (is_string($value) && trim($value) === '')) {
            return $required ? throw new InvalidArgumentException("The {$key} is required.") : null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException("The {$key} has to be text.");
        }

        if (mb_strlen($value) > $limit) {
            throw new InvalidArgumentException("The {$key} can be at most {$limit} characters.");
        }

        return $value;
    }
}
