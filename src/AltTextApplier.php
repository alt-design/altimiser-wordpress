<?php

namespace AltDesign\Altimiser;

use WP_HTML_Tag_Processor;
use WP_Post;

/**
 * Alt text lives in two places in WordPress. The media library holds the image's own,
 * which featured images, most themes and page builders print, and which the editor
 * copies into a post when the image is inserted. From then on the post's copy is what
 * that post shows. Each is written where it still holds what the scan saw, so an
 * editor's own wording in either place is left alone.
 */
class AltTextApplier implements Applier
{
    private string $metaKey = '_wp_attachment_image_alt';

    /**
     * @var array<int, string>
     */
    private array $writtenThisRequest = [];

    public function __construct(private ContentResolver $content, private ContentWriter $writer) {}

    public function checks(): array
    {
        return ['image.alt_missing', 'image.alt_empty', 'image.alt_too_long'];
    }

    public function supports(string $check): bool
    {
        return in_array($check, $this->checks(), true);
    }

    public function apply(ChangeRequest $change, bool $dryRun): array
    {
        $suggested = trim($change->suggestedValue ?? '');

        if ($suggested === '' && ! $change->revert) {
            return ChangeResult::skipped($change->id, 'no_suggestion', 'There is no alt text to write.');
        }

        $source = (string) ($change->target['match'] ?? '');
        $attachmentId = $this->attachment($source);

        if ($attachmentId === 0) {
            return $this->applyToContentOnly($change, $source, $suggested, $dryRun);
        }

        $current = trim($change->currentValue ?? '');
        $stored = trim((string) get_post_meta($attachmentId, $this->metaKey, true));
        $post = $this->editablePost($change->url);
        $copy = $post === null
            ? ['html' => '', 'changed' => false, 'already' => false, 'found' => false]
            : $this->rewrite($post->post_content, $attachmentId, $source, $current, $this->replacement($change, $suggested));
        $writesLibrary = $stored === $current && $stored !== $suggested;

        if (! $writesLibrary && ! $copy['changed']) {
            return $this->unchangeable($change, $attachmentId, $stored, $suggested, $copy);
        }

        $location = $this->location($attachmentId, $writesLibrary, $copy['changed'] ? $post : null);

        if ($dryRun) {
            return ChangeResult::applied($change->id, $location, $change->currentValue, $suggested);
        }

        if ($writesLibrary && ! $this->writeLibrary($attachmentId, $suggested)) {
            return ChangeResult::failed($change->id, 'write_failed', 'WordPress did not save the alt text in the media library.');
        }

        if ($copy['changed'] && ! $this->writer->updatePost($post->ID, ['post_content' => $copy['html']])) {
            return ChangeResult::failed($change->id, 'write_failed', "WordPress did not save this page's copy of the image's alt text.");
        }

        return ChangeResult::applied($change->id, $location, $change->currentValue, $suggested);
    }

    private function writeLibrary(int $attachmentId, string $value): bool
    {
        if ($value === '') {
            unset($this->writtenThisRequest[$attachmentId]);

            return delete_post_meta($attachmentId, $this->metaKey);
        }

        $this->writtenThisRequest[$attachmentId] = $value;

        return (bool) update_post_meta($attachmentId, $this->metaKey, wp_slash($value));
    }

    /**
     * Reverting a change that filled an empty alt puts back what was there before. The
     * request does not say which that was, so the check it was raised for decides:
     * a missing alt attribute is removed again and an empty one is emptied.
     */
    private function replacement(ChangeRequest $change, string $suggested): ?string
    {
        return $suggested === '' && $change->check === 'image.alt_missing' ? null : $suggested;
    }

    /**
     * Says why nothing could be written. One image on several pages is raised once
     * per page, so the library may hold the wording an earlier change in this batch
     * wrote, and a page whose template leaves out the library's alt text has no copy
     * of its own to write to.
     *
     * @param  array{html: string, changed: bool, already: bool, found: bool}  $copy
     * @return array<string, mixed>
     */
    private function unchangeable(ChangeRequest $change, int $attachmentId, string $stored, string $suggested, array $copy): array
    {
        $writtenHere = $stored !== '' && ($this->writtenThisRequest[$attachmentId] ?? null) === $stored;

        if ($stored === $suggested || $copy['already']) {
            return ChangeResult::alreadyApplied(
                $change->id,
                $this->libraryLocation($attachmentId),
                $writtenHere ? 'Another change in this batch has already written this alt text for the image.' : 'This image already has this alt text.',
            );
        }

        if ($writtenHere) {
            return ChangeResult::skipped(
                $change->id,
                'applied_by_other_change',
                'Another change in this batch has already written different alt text for this image in the media library, which every page that prints it now shows.',
            );
        }

        if ($stored !== '' && $suggested !== '' && trim($change->currentValue ?? '') === '' && ! $copy['found']) {
            return ChangeResult::skipped(
                $change->id,
                'template_omits_alt',
                'The media library already holds alt text for this image, but this page does not print it, so its template leaves the alt text out. This needs a template fix and not a change to the alt text.',
            );
        }

        return ChangeResult::skipped(
            $change->id,
            'value_changed',
            "Neither the media library nor this page's copy of the image holds the alt text the scan saw, so somebody has changed it since.",
        );
    }

    /**
     * An image that is not in the media library, such as one copied in from another
     * site, has only the tag in the page to write to.
     *
     * @return array<string, mixed>
     */
    private function applyToContentOnly(ChangeRequest $change, string $source, string $suggested, bool $dryRun): array
    {
        $post = $this->editablePost($change->url);
        $copy = $post === null
            ? ['html' => '', 'changed' => false, 'already' => false, 'found' => false]
            : $this->rewrite($post->post_content, 0, $source, trim($change->currentValue ?? ''), $this->replacement($change, $suggested));

        if (! $copy['found']) {
            return ChangeResult::skipped(
                $change->id,
                'no_match',
                "No image in the media library matches {$source}, and this page's content has no image tag for it, so there is nowhere to keep its alt text. It may come from the theme or a page builder.",
            );
        }

        $location = $this->location(0, false, $post);

        if (! $copy['changed']) {
            return $copy['already']
                ? ChangeResult::alreadyApplied($change->id, $location, 'This image already has this alt text.')
                : ChangeResult::skipped($change->id, 'value_changed', "This page's copy of the image does not hold the alt text the scan saw, so somebody has changed it since.");
        }

        if ($dryRun) {
            return ChangeResult::applied($change->id, $location, $change->currentValue, $suggested);
        }

        if (! $this->writer->updatePost($post->ID, ['post_content' => $copy['html']])) {
            return ChangeResult::failed($change->id, 'write_failed', "WordPress did not save this page's copy of the image's alt text.");
        }

        return ChangeResult::applied($change->id, $location, $change->currentValue, $suggested);
    }

    private function editablePost(string $url): ?WP_Post
    {
        $post = $this->content->find($url);

        if (! $post instanceof WP_Post) {
            return null;
        }

        return $this->writer->pageBuilder($post->ID) === null ? $post : null;
    }

    /**
     * An image block's alt is read from its markup, but a cover block keeps its own
     * in the block's attributes as well, and the editor flags the block as broken when
     * the two disagree.
     *
     * @return array{html: string, changed: bool, already: bool, found: bool}
     */
    private function rewrite(string $html, int $attachmentId, string $source, string $current, ?string $replacement): array
    {
        $suggested = $replacement ?? '';
        $tags = new WP_HTML_Tag_Processor($html);
        $sourcePath = $this->path($source);
        $changed = false;
        $already = false;
        $found = false;

        while ($tags->next_tag('img')) {
            if (! $tags->has_class("wp-image-{$attachmentId}") && $this->path((string) $tags->get_attribute('src')) !== $sourcePath) {
                continue;
            }

            $found = true;
            $alt = $tags->get_attribute('alt');
            $alt = is_string($alt) ? trim($alt) : '';
            $already = $already || $alt === $suggested;

            if ($alt === $current && $alt !== $suggested) {
                if ($replacement === null) {
                    $tags->remove_attribute('alt');
                } else {
                    $tags->set_attribute('alt', $suggested);
                }

                $changed = true;
            }
        }

        $html = preg_replace_callback(
            '/<!--\s+wp:cover\s+(\{.*?\})\s+(\/?)-->/s',
            function (array $comment) use ($attachmentId, $sourcePath, $current, $suggested, &$changed): string {
                $attributes = json_decode($comment[1], true);

                if (! is_array($attributes) || ! $this->coverShows($attributes, $attachmentId, $sourcePath) || trim((string) ($attributes['alt'] ?? '')) !== $current || $current === $suggested) {
                    return $comment[0];
                }

                $attributes['alt'] = $suggested;
                $changed = true;

                return '<!-- wp:cover '.serialize_block_attributes($attributes)." {$comment[2]}-->";
            },
            $tags->get_updated_html(),
        ) ?? $html;

        return ['html' => $html, 'changed' => $changed, 'already' => $already, 'found' => $found];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function coverShows(array $attributes, int $attachmentId, string $sourcePath): bool
    {
        if ($attachmentId !== 0 && ($attributes['id'] ?? null) === $attachmentId) {
            return true;
        }

        return $attachmentId === 0 && $this->path((string) ($attributes['url'] ?? '')) === $sourcePath;
    }

    /**
     * attachment_url_to_postid() only knows an image by its original file on this
     * site's uploads address. The scan sees a resized copy, often on a CDN, and an
     * image WordPress scaled down on upload is filed under its -scaled name while its
     * sizes are named after the original.
     */
    private function attachment(string $source): int
    {
        $uploads = wp_get_upload_dir();
        $root = parse_url($uploads['baseurl'], PHP_URL_PATH).'/';
        $path = rawurldecode((string) parse_url($source, PHP_URL_PATH));
        $at = strpos($path, $root);

        if ($source === '' || $at === false) {
            return 0;
        }

        $file = substr($path, $at + strlen($root));
        $original = (string) preg_replace('/-\d+x\d+(?=\.\w+$)/', '', $file);
        $unscaled = (string) preg_replace('/-scaled(?=\.\w+$)/', '', $original);
        $scaled = (string) preg_replace('/(?=\.\w+$)/', '-scaled', $unscaled, 1);

        foreach (array_unique([$file, $original, $unscaled, $scaled]) as $candidate) {
            $attachmentId = attachment_url_to_postid("{$uploads['baseurl']}/{$candidate}");

            if ($attachmentId !== 0) {
                return $attachmentId;
            }
        }

        return 0;
    }

    private function path(string $url): string
    {
        return rawurldecode((string) parse_url($url, PHP_URL_PATH));
    }

    /**
     * @return array<string, mixed>
     */
    private function location(int $attachmentId, bool $writesLibrary, ?WP_Post $post): array
    {
        $page = $post === null ? [] : ['id' => $post->ID, 'collection' => $post->post_type];

        return $writesLibrary
            ? [...$this->libraryLocation($attachmentId), ...$page]
            : ['type' => 'post', ...$page, 'field' => 'post_content'];
    }

    /**
     * @return array<string, mixed>
     */
    private function libraryLocation(int $attachmentId): array
    {
        return ['type' => 'asset', 'asset' => $attachmentId, 'field' => $this->metaKey];
    }
}
