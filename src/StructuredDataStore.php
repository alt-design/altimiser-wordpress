<?php

namespace AltDesign\Altimiser;

use WP_Post;

/**
 * Where structured data blocks are kept: one per post, or one per post type that
 * every post of it falls back to.
 *
 * A block written for a post type is a template, so storing it once means the next
 * post published gets it too. A post with a block of its own keeps it.
 */
class StructuredDataStore
{
    private string $metaKey = '_altimiser_structured_data';

    private string $defaultsOption = 'altimiser_structured_data_defaults';

    public function metaKey(): string
    {
        return $this->metaKey;
    }

    public function defaultsOption(): string
    {
        return $this->defaultsOption;
    }

    /**
     * Pages are left out because they differ from one another, unlike the posts of
     * a type, so a block written for one of them says nothing true about the rest.
     */
    public function sharesDefault(string $postType): bool
    {
        return ! in_array($postType, (array) apply_filters('altimiser_structured_data_default_exclusions', ['page']), true);
    }

    public function own(WP_Post $post): string
    {
        $stored = get_post_meta($post->ID, $this->metaKey, true);

        return is_string($stored) ? trim($stored) : '';
    }

    public function defaultFor(string $postType): string
    {
        $stored = $this->defaults()[$postType] ?? '';

        return is_string($stored) ? trim($stored) : '';
    }

    public function blockFor(WP_Post $post): string
    {
        $own = $this->own($post);

        return $own !== '' ? $own : $this->defaultFor($post->post_type);
    }

    public function writeOwn(WP_Post $post, string $block): void
    {
        update_post_meta($post->ID, $this->metaKey, wp_slash($block));
    }

    /**
     * Options are not unslashed on the way in, unlike post meta, so the block goes
     * in as it is.
     */
    public function writeDefault(string $postType, string $block): void
    {
        update_option($this->defaultsOption, [...$this->defaults(), $postType => $block], false);
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        $stored = get_option($this->defaultsOption, []);

        return is_array($stored) ? $stored : [];
    }
}
