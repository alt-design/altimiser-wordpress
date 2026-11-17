<?php

namespace AltDesign\Altimiser;

use WP_Post;

/**
 * Stores the structured data block Altimiser writes for a page.
 *
 * A post type shares one block, kept once as a default, so the next post published
 * gets it. A later page of that type reports the block as already in place, and a
 * different default is left alone. Pages keep a block each.
 */
class StructuredDataApplier implements Applier
{
    public function __construct(
        private StructuredDataStore $store,
        private StructuredDataRenderer $renderer,
        private ContentResolver $content,
    ) {}

    public function checks(): array
    {
        return ['structured_data.missing'];
    }

    public function supports(string $check): bool
    {
        return $check === 'structured_data.missing';
    }

    public function apply(ChangeRequest $change, bool $dryRun): array
    {
        $block = trim($change->suggestedValue ?? '');

        if ($block === '') {
            return ChangeResult::skipped(
                $change->id,
                'no_suggestion',
                'There is no replacement value for this check, and this receiver cannot work one out for itself.',
            );
        }

        $record = $this->content->find($change->url);

        if ($record === null) {
            return ChangeResult::skipped($change->id, 'not_found', "Nothing editable answers to {$change->url}.");
        }

        if (! $record instanceof WP_Post) {
            return ChangeResult::skipped($change->id, 'no_field', 'Structured data is kept on posts and pages, and this address is a term.');
        }

        if ($this->renderer->render($block, $record) === null) {
            return ChangeResult::skipped(
                $change->id,
                'invalid_markup',
                'This markup does not render to valid JSON for this page, so nothing was stored.',
            );
        }

        return $this->store->sharesDefault($record->post_type)
            ? $this->applyAsDefault($change, $record, $block, $dryRun)
            : $this->applyToPost($change, $record, $block, $dryRun);
    }

    /**
     * @return array<string, mixed>
     */
    private function applyAsDefault(ChangeRequest $change, WP_Post $post, string $block, bool $dryRun): array
    {
        $current = $this->store->defaultFor($post->post_type);
        $location = [
            'type' => 'collection',
            'collection' => $post->post_type,
            'field' => $this->store->defaultsOption(),
        ];

        if ($current === $block) {
            return ChangeResult::alreadyApplied($change->id, $location, 'This post type already defaults to this markup.');
        }

        if ($current !== '') {
            return ChangeResult::skipped(
                $change->id,
                'default_present',
                "The {$post->post_type} post type already has default schema markup, which was left in place.",
            );
        }

        if ($dryRun) {
            return ChangeResult::applied($change->id, $location, null, $block);
        }

        $this->store->writeDefault($post->post_type, $block);

        if ($this->store->defaultFor($post->post_type) !== $block) {
            return ChangeResult::failed($change->id, 'write_failed', "Nothing new was stored in {$location['field']}.");
        }

        return ChangeResult::applied($change->id, $location, null, $block);
    }

    /**
     * @return array<string, mixed>
     */
    private function applyToPost(ChangeRequest $change, WP_Post $post, string $block, bool $dryRun): array
    {
        $current = $this->store->own($post);
        $location = [
            'type' => 'post',
            'id' => $post->ID,
            'collection' => $post->post_type,
            'field' => $this->store->metaKey(),
        ];

        if ($current === $block) {
            return ChangeResult::alreadyApplied($change->id, $location, 'This page already has this markup.');
        }

        if ($current !== '') {
            return ChangeResult::skipped($change->id, 'value_changed', 'This page has structured data of its own, which was left alone.');
        }

        if ($dryRun) {
            return ChangeResult::applied($change->id, $location, null, $block);
        }

        $this->store->writeOwn($post, $block);

        if ($this->store->own($post) !== $block) {
            return ChangeResult::failed($change->id, 'write_failed', "Nothing new was stored in {$location['field']}.");
        }

        return ChangeResult::applied($change->id, $location, null, $block);
    }
}
