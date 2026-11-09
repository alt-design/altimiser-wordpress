<?php

namespace AltDesign\Altimiser;

use RuntimeException;
use WP_Post;

/**
 * A content draft from Altimiser, saved as an unpublished post for an editor to
 * finish.
 *
 * Unpublished is the whole point: the draft is written by a model from the
 * business's answers and carries its gaps marked in the text, so nothing reaches
 * the site until somebody publishes it. The idea it came from is kept on the post,
 * so sending the same idea again rewrites its draft, but only while the draft is
 * unpublished. Once somebody has published it, it is theirs.
 */
class DraftPosts
{
    private string $referenceKey = '_altimiser_draft_reference';

    public function __construct(
        private ContentResolver $content,
        private Collections $collections,
        private MarkdownBlocks $markdown,
        private ?Yoast $yoast,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws RuntimeException when WordPress cannot save the post
     */
    public function store(DraftRequest $draft): array
    {
        $beside = null;

        if ($draft->expands !== null) {
            $beside = $this->content->find($draft->expands);

            if (! $beside instanceof WP_Post) {
                return $this->refused('page_not_found', "Nothing lives at {$draft->expands}, so there is nothing to put the draft beside.");
            }
        }

        $postType = $beside?->post_type ?? (string) $draft->collection;

        if ($beside === null && ! $this->collections->exists($postType)) {
            return $this->refused('collection_not_found', "There is no collection called \"{$postType}\" on this site.");
        }

        $existing = $this->existing($draft->reference);

        if ($existing !== null && ! $this->isUnpublished($existing)) {
            return $this->refused('already_published', "The draft for this idea was published as \"{$existing->post_title}\", so it is left alone.");
        }

        if (! post_type_supports($postType, 'editor')) {
            return $this->refused('no_body_field', "The {$postType} post type has no editor to write the draft into.");
        }

        $id = $existing === null
            ? $this->create($draft, $postType, $beside)
            : $this->rewrite($draft, $existing);

        $this->storeDescription($id, $draft);

        return [
            'status' => $existing === null ? 'created' : 'updated',
            'id' => (string) $id,
            'collection' => $postType,
            'edit_url' => admin_url("post.php?post={$id}&action=edit"),
        ];
    }

    private function existing(string $reference): ?WP_Post
    {
        $found = get_posts([
            'post_type' => array_values(get_post_types()),
            'post_status' => 'any',
            'meta_key' => $this->referenceKey,
            'meta_value' => $reference,
            'numberposts' => 1,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);

        return $found[0] ?? null;
    }

    private function isUnpublished(WP_Post $post): bool
    {
        return in_array($post->post_status, ['draft', 'pending', 'auto-draft'], true);
    }

    private function create(DraftRequest $draft, string $postType, ?WP_Post $beside): int
    {
        return $this->save('wp_insert_post', [
            'post_type' => $postType,
            'post_status' => 'draft',
            'post_title' => $this->title($draft),
            'post_content' => $this->markdown->convert($draft->body),
            'post_name' => $beside === null ? sanitize_title($draft->title) : "{$beside->post_name}-draft",
            'post_parent' => $beside?->post_parent ?? 0,
            'meta_input' => [$this->referenceKey => $draft->reference],
        ]);
    }

    private function rewrite(DraftRequest $draft, WP_Post $existing): int
    {
        return $this->save('wp_update_post', [
            'ID' => $existing->ID,
            'post_title' => $this->title($draft),
            'post_content' => $this->markdown->convert($draft->body),
        ]);
    }

    private function title(DraftRequest $draft): string
    {
        return trim(wp_strip_all_tags($draft->title));
    }

    /**
     * Kses is taken off for the write because a signed request has no user, so
     * WordPress would filter the content as it would a contributor's. It goes back
     * on afterwards only if it was on.
     *
     * @param  array<string, mixed>  $post
     */
    private function save(callable $write, array $post): int
    {
        $filtered = has_filter('content_save_pre', 'wp_filter_post_kses') !== false;

        kses_remove_filters();

        try {
            $id = $write(wp_slash($post), true);
        } finally {
            if ($filtered) {
                kses_init_filters();
            }
        }

        if (is_wp_error($id)) {
            throw new RuntimeException($id->get_error_message());
        }

        return (int) $id;
    }

    private function storeDescription(int $id, DraftRequest $draft): void
    {
        $post = get_post($id);

        if ($this->yoast === null || $draft->metaDescription === null || ! $post instanceof WP_Post) {
            return;
        }

        $this->yoast->write($post, 'metadesc', sanitize_text_field($draft->metaDescription));
    }

    /**
     * @return array{status: string, reason: string, message: string}
     */
    private function refused(string $reason, string $message): array
    {
        return ['status' => 'refused', 'reason' => $reason, 'message' => $message];
    }
}
