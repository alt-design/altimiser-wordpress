<?php

namespace AltDesign\Altimiser;

use WP_Post;

/**
 * Links a page that already names another page to it, using the words that are
 * already there. The change carries the words in target.anchor and the destination in
 * target.href, which has to be a path or an http address on this site. A revert arrives as the same change with its values swapped, so a
 * suggested value that is the anchor itself means take the link off again.
 *
 * The post's own content is read first, then its ACF editor fields in the order ACF
 * holds them, inside groups, repeaters and flexible content too. ACF text areas are
 * left alone, because themes commonly escape them and a link would show as markup.
 */
class LinkApplier implements Applier
{
    public function __construct(
        private ContentResolver $content,
        private MentionLinker $linker,
        private ContentWriter $writer,
    ) {}

    public function checks(): array
    {
        return ['link.opportunity'];
    }

    public function supports(string $check): bool
    {
        return $check === 'link.opportunity';
    }

    public function apply(ChangeRequest $change, bool $dryRun): array
    {
        $anchor = trim((string) ($change->target['anchor'] ?? ''));
        $href = trim((string) ($change->target['href'] ?? ''));

        if ($anchor === '' || $href === '') {
            return ChangeResult::skipped(
                $change->id,
                'invalid_change',
                'A link change needs the words to link and the page they link to. Scan the site again and send it once more.',
            );
        }

        if (! $this->onThisSite($href)) {
            return ChangeResult::skipped($change->id, 'invalid_change', 'A link change can only point to a page on this site.');
        }

        $post = $this->content->find($change->url);

        if (! $post instanceof WP_Post) {
            return ChangeResult::skipped($change->id, 'not_found', "No post or page answers to {$change->url}, and only their content is linked.");
        }

        $builder = $this->writer->pageBuilder($post->ID);

        if ($builder !== null) {
            return ChangeResult::skipped($change->id, 'page_builder', "This page is built with {$builder}, which keeps its content where this plugin does not write.");
        }

        $removing = trim((string) $change->suggestedValue) === $anchor;
        $rewrite = fn (string $html): array => $removing
            ? $this->linker->unlink($html, $anchor, $href)
            : $this->linker->link($html, $anchor, $href);
        $edit = $this->edit($post, $rewrite);
        $location = ['type' => 'post', 'id' => $post->ID, 'collection' => $post->post_type, 'field' => $edit['field'] ?? 'post_content'];

        if ($edit['outcome'] !== 'changed') {
            return $this->unchanged($edit['outcome'], $change, $location, $anchor, $href, $removing);
        }

        if ($dryRun) {
            return ChangeResult::applied($change->id, $location, $change->currentValue, $change->suggestedValue);
        }

        if (! $edit['save']()) {
            return ChangeResult::failed($change->id, 'write_failed', "WordPress did not save {$location['field']} on this page.");
        }

        return ChangeResult::applied($change->id, $location, $change->currentValue, $change->suggestedValue);
    }

    /**
     * A path from the root, or an http address on this site's host. A leading "//" or
     * "/\" is another host to a browser, so a backslash or whitespace anywhere is
     * refused rather than guessed at.
     */
    private function onThisSite(string $href): bool
    {
        if (preg_match('/[\s\\\\\x00-\x1f\x7f]/', $href) === 1) {
            return false;
        }

        if (preg_match('#^/(?!/)#', $href) === 1) {
            return true;
        }

        if (! in_array(strtolower((string) parse_url($href, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            return false;
        }

        return strtolower((string) parse_url($href, PHP_URL_HOST)) === strtolower((string) parse_url(home_url(), PHP_URL_HOST));
    }

    /**
     * @param  array<string, mixed>  $location
     * @return array<string, mixed>
     */
    private function unchanged(string $outcome, ChangeRequest $change, array $location, string $anchor, string $href, bool $removing): array
    {
        if ($removing) {
            return ChangeResult::alreadyApplied($change->id, $location, "There is no link to {$href} on \"{$anchor}\" on this page, so there was nothing to take off.");
        }

        return match ($outcome) {
            'already' => ChangeResult::alreadyApplied($change->id, $location, "The first mention of \"{$anchor}\" already links to {$href}."),
            'inside_link' => ChangeResult::skipped(
                $change->id,
                'already_linked',
                "The first mention of \"{$anchor}\" already links somewhere else. Somebody chose that link, so it was left alone.",
            ),
            default => ChangeResult::skipped(
                $change->id,
                'mention_not_found',
                "\"{$anchor}\" is not in this page's content as written, so there is nothing to link. It may come from the theme, a widget or another post shown on the page.",
            ),
        };
    }

    /**
     * The first field that answers, with a way to save what changed in it.
     *
     * @param  callable(string): array{outcome: string, html: string}  $rewrite
     * @return array{outcome: string, field?: string, save?: callable(): bool}
     */
    private function edit(WP_Post $post, callable $rewrite): array
    {
        $found = $rewrite($post->post_content);

        if ($found['outcome'] !== 'not_found') {
            return [
                'outcome' => $found['outcome'],
                'field' => 'post_content',
                'save' => fn (): bool => $this->writer->updatePost($post->ID, ['post_content' => $found['html']]),
            ];
        }

        foreach ($this->acfFields($post->ID) as $field) {
            $value = $field['value'];
            $outcome = $this->visit($value, $field, $rewrite);

            if ($outcome !== null) {
                return [
                    'outcome' => $outcome,
                    'field' => $field['name'],
                    'save' => fn (): bool => $this->writer->updateField($field['key'], $value, $post->ID),
                ];
            }
        }

        return ['outcome' => 'not_found'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function acfFields(int $postId): array
    {
        if (! function_exists('get_field_objects')) {
            return [];
        }

        return array_values(get_field_objects($postId, false) ?: []);
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  callable(string): array{outcome: string, html: string}  $rewrite
     */
    private function visit(mixed &$value, array $field, callable $rewrite): ?string
    {
        $type = $field['type'] ?? null;

        if ($type === 'wysiwyg') {
            return is_string($value) ? $this->rewriteProse($value, $rewrite) : null;
        }

        if (! is_array($value)) {
            return null;
        }

        if ($type === 'group') {
            return $this->visitRow($value, $field['sub_fields'] ?? [], $rewrite);
        }

        if (! in_array($type, ['repeater', 'flexible_content'], true)) {
            return null;
        }

        foreach (array_keys($value) as $index) {
            if (! is_array($value[$index])) {
                continue;
            }

            $subFields = $type === 'repeater' ? ($field['sub_fields'] ?? []) : $this->layoutFields($field, $value[$index]);
            $outcome = $this->visitRow($value[$index], $subFields, $rewrite);

            if ($outcome !== null) {
                return $outcome;
            }
        }

        return null;
    }

    /**
     * @param  callable(string): array{outcome: string, html: string}  $rewrite
     */
    private function rewriteProse(string &$value, callable $rewrite): ?string
    {
        $found = $rewrite($value);

        if ($found['outcome'] === 'not_found') {
            return null;
        }

        $value = $found['html'];

        return $found['outcome'];
    }

    /**
     * Unformatted rows are keyed by field key, but a row saved by code may be keyed by
     * name, so a sub field is looked for under both.
     *
     * @param  array<string, mixed>  $row
     * @param  array<int, array<string, mixed>>  $subFields
     * @param  callable(string): array{outcome: string, html: string}  $rewrite
     */
    private function visitRow(array &$row, array $subFields, callable $rewrite): ?string
    {
        foreach ($subFields as $subField) {
            $key = array_key_exists($subField['key'] ?? '', $row) ? $subField['key'] : ($subField['name'] ?? '');

            if (! array_key_exists($key, $row)) {
                continue;
            }

            $outcome = $this->visit($row[$key], $subField, $rewrite);

            if ($outcome !== null) {
                return $outcome;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $row
     * @return array<int, array<string, mixed>>
     */
    private function layoutFields(array $field, array $row): array
    {
        foreach ($field['layouts'] ?? [] as $layout) {
            if (($layout['name'] ?? null) === ($row['acf_fc_layout'] ?? false)) {
                return $layout['sub_fields'] ?? [];
            }
        }

        return [];
    }
}
