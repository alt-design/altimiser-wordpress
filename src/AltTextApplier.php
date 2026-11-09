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

        if ($suggested === '') {
            return ChangeResult::skipped($change->id, 'no_suggestion', 'There is no alt text to write.');
        }

        $source = (string) ($change->target['match'] ?? '');
        $attachmentId = $this->attachment($source);

        if ($attachmentId === 0) {
            return ChangeResult::skipped(
                $change->id,
                'no_match',
                "No image in the media library matches {$source}, so there is nowhere to keep its alt text. It may come from the theme or another site.",
            );
        }

        $current = trim($change->currentValue ?? '');
        $stored = trim((string) get_post_meta($attachmentId, $this->metaKey, true));
        $post = $this->editablePost($change->url);
        $copy = $post === null
            ? ['html' => '', 'changed' => false, 'already' => false]
            : $this->rewrite($post->post_content, $attachmentId, $source, $current, $suggested);
        $writesLibrary = $stored === $current;

        if (! $writesLibrary && ! $copy['changed']) {
            return $stored === $suggested || $copy['already']
                ? ChangeResult::alreadyApplied($change->id, $this->libraryLocation($attachmentId), 'This image already has this alt text.')
                : ChangeResult::skipped(
                    $change->id,
                    'value_changed',
                    "Neither the media library nor this page's copy of the image holds the alt text the scan saw, so somebody has changed it since.",
                );
        }

        $location = $this->location($attachmentId, $writesLibrary, $copy['changed'] ? $post : null);

        if ($dryRun) {
            return ChangeResult::applied($change->id, $location, $change->currentValue, $suggested);
        }

        if ($writesLibrary && ! update_post_meta($attachmentId, $this->metaKey, wp_slash($suggested))) {
            return ChangeResult::failed($change->id, 'write_failed', 'WordPress did not save the alt text in the media library.');
        }

        if ($copy['changed'] && ! $this->writer->updatePost($post->ID, ['post_content' => $copy['html']])) {
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
     * @return array{html: string, changed: bool, already: bool}
     */
    private function rewrite(string $html, int $attachmentId, string $source, string $current, string $suggested): array
    {
        $tags = new WP_HTML_Tag_Processor($html);
        $sourcePath = $this->path($source);
        $changed = false;
        $already = false;

        while ($tags->next_tag('img')) {
            if (! $tags->has_class("wp-image-{$attachmentId}") && $this->path((string) $tags->get_attribute('src')) !== $sourcePath) {
                continue;
            }

            $alt = $tags->get_attribute('alt');
            $alt = is_string($alt) ? trim($alt) : '';
            $already = $already || $alt === $suggested;

            if ($alt === $current) {
                $tags->set_attribute('alt', $suggested);
                $changed = true;
            }
        }

        $html = preg_replace_callback(
            '/<!--\s+wp:cover\s+(\{.*?\})\s+(\/?)-->/s',
            static function (array $comment) use ($attachmentId, $current, $suggested, &$changed): string {
                $attributes = json_decode($comment[1], true);

                if (! is_array($attributes) || ($attributes['id'] ?? null) !== $attachmentId || trim((string) ($attributes['alt'] ?? '')) !== $current) {
                    return $comment[0];
                }

                $attributes['alt'] = $suggested;
                $changed = true;

                return '<!-- wp:cover '.serialize_block_attributes($attributes)." {$comment[2]}-->";
            },
            $tags->get_updated_html(),
        ) ?? $html;

        return ['html' => $html, 'changed' => $changed, 'already' => $already];
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
