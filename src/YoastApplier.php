<?php

namespace AltDesign\Altimiser;

use WP_Post;
use WP_Term;

class YoastApplier implements Applier
{
    public function __construct(private Yoast $yoast, private ContentResolver $content) {}

    public function checks(): array
    {
        $checks = [
            'title.missing',
            'title.too_short',
            'title.too_long',
            'title.irrelevant',
            'title.duplicate',
            'meta_description.missing',
            'meta_description.too_short',
            'meta_description.too_long',
            'meta_description.irrelevant',
            'meta_description.duplicate',
        ];

        return $this->yoast->printsSocialTags() ? [...$checks, 'open_graph.missing'] : $checks;
    }

    public function supports(string $check): bool
    {
        return in_array($check, $this->checks(), true);
    }

    public function apply(ChangeRequest $change, bool $dryRun): array
    {
        if (trim($change->suggestedValue ?? '') === '') {
            return ChangeResult::skipped(
                $change->id,
                'no_suggestion',
                'There is no replacement value for this check, and this receiver cannot work one out for itself.',
            );
        }

        $field = $this->field($change);

        if ($field === null) {
            return ChangeResult::skipped($change->id, 'no_field', "Yoast has no text field for {$change->check} on this tag.");
        }

        $record = $this->content->find($change->url);

        if ($record === null) {
            return ChangeResult::skipped($change->id, 'not_found', "Nothing editable answers to {$change->url}.");
        }

        return $this->applyToRecord($change, $record, $field, $dryRun);
    }

    /**
     * @return array<string, mixed>
     */
    private function applyToRecord(ChangeRequest $change, WP_Post|WP_Term $record, string $field, bool $dryRun): array
    {
        $stored = $this->yoast->stored($record, $field);
        $inherited = $this->yoast->render($this->yoast->template($record, $field), $record);
        $rendered = $stored === '' ? $inherited : $this->yoast->render($stored, $record);
        $suggested = $this->yoast->render($change->suggestedValue, $record);
        $location = $this->describe($record, $field);

        if ($this->comparable($suggested) === '') {
            return ChangeResult::skipped($change->id, 'no_suggestion', 'The replacement value has no text in it once markup is removed.');
        }

        if ($this->same($rendered, $suggested)) {
            return ChangeResult::alreadyApplied($change->id, $location, 'This page already has this value.');
        }

        $refusal = $this->refusal($change, $stored, $rendered);

        if ($refusal !== null) {
            return $refusal;
        }

        $scanned = trim($change->currentValue ?? '');
        $location['inherited'] = $stored === '' && $scanned !== '';
        $location['flattened_template'] = str_contains($stored, '%%');
        $from = $stored === '' ? null : $stored;

        if ($dryRun) {
            return ChangeResult::applied($change->id, $location, $from, $change->suggestedValue);
        }

        if ($this->same($inherited, $suggested)) {
            $this->yoast->clear($record, $field);

            return ChangeResult::applied($change->id, $location, $from, $change->suggestedValue);
        }

        $this->yoast->write($record, $field, $change->suggestedValue);

        $written = $this->yoast->stored($record, $field);

        if ($written === '' || $written === $stored) {
            return ChangeResult::failed($change->id, 'write_failed', "Nothing new was stored in {$location['field']}.");
        }

        return ChangeResult::applied($change->id, $location, $from, $written);
    }

    /**
     * A value of the page's own that differs is somebody's work. An empty field whose
     * template does not produce what the scan saw could be a renamed page or something
     * else printing the tag, and telling those apart is not possible from here.
     *
     * @return ?array<string, mixed>
     */
    private function refusal(ChangeRequest $change, string $stored, string $rendered): ?array
    {
        if ($this->unchanged(trim($change->currentValue ?? ''), $stored, $rendered)) {
            return null;
        }

        return $stored === ''
            ? ChangeResult::skipped($change->id, 'ambiguous', 'This page has no value of its own and its template does not produce what the scan saw, so applying would be a guess.')
            : ChangeResult::skipped($change->id, 'value_changed', 'This value has changed since the scan, so it was left alone.');
    }

    /**
     * A scan sees template variables filled in. A revert sends back what was written,
     * which still has them, so either form of the value counts as the one on the page.
     */
    private function unchanged(string $scanned, string $stored, string $rendered): bool
    {
        if ($scanned === '') {
            return $stored === '';
        }

        return $this->same($rendered, $scanned) || $this->same($stored, $scanned);
    }

    private function field(ChangeRequest $change): ?string
    {
        return match (strstr($change->check, '.', true)) {
            'title' => 'title',
            'meta_description' => 'metadesc',
            default => $this->socialField((string) ($change->target['selector'] ?? '')),
        };
    }

    private function socialField(string $selector): ?string
    {
        return preg_match('/og:(title|description)/', $selector, $tag) === 1 ? "opengraph-{$tag[1]}" : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(WP_Post|WP_Term $record, string $field): array
    {
        $identity = $record instanceof WP_Post
            ? ['type' => 'post', 'id' => $record->ID, 'collection' => $record->post_type]
            : ['type' => 'term', 'id' => $record->term_id, 'taxonomy' => $record->taxonomy];

        return [...$identity, 'field' => $this->yoast->storageKey($record, $field)];
    }

    private function same(string $first, string $second): bool
    {
        return $this->comparable($first) === $this->comparable($second);
    }

    private function comparable(string $value): string
    {
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $decoded));
    }
}
