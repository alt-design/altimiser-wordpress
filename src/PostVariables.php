<?php

namespace AltDesign\Altimiser;

use WP_Post;

/**
 * The variables a structured data block may use, and their values for one post.
 *
 * Reported on /health so Altimiser names these to its model, and not the ones
 * the Statamic addon renders.
 */
class PostVariables
{
    public function __construct(private ?Yoast $yoast) {}

    /**
     * @return array<string, string>
     */
    public function descriptions(): array
    {
        return [
            'title' => "The post's title",
            'meta_description' => "The post's meta description, from Yoast SEO where it is active, otherwise its excerpt",
            'permalink' => "The post's full address",
            'excerpt' => "The post's excerpt, or the start of its text where it has none",
            'featured_image' => "The address of the post's featured image, empty where it has none",
            'date_published' => 'When the post was published, as an ISO 8601 date and time',
            'date_modified' => 'When the post was last modified, as an ISO 8601 date and time',
            'author_name' => "The post's author's display name",
            'site_name' => "The site's name",
            'site_url' => "The site's home address",
        ];
    }

    /**
     * @return array<string, string>
     */
    public function for(WP_Post $post): array
    {
        return [
            'title' => $this->plain($post->post_title),
            'meta_description' => $this->metaDescription($post),
            'permalink' => (string) get_permalink($post),
            'excerpt' => $this->excerpt($post),
            'featured_image' => (string) get_the_post_thumbnail_url($post, 'full'),
            'date_published' => (string) get_post_time('c', false, $post),
            'date_modified' => (string) get_post_modified_time('c', false, $post),
            'author_name' => (string) get_the_author_meta('display_name', (int) $post->post_author),
            'site_name' => get_bloginfo('name'),
            'site_url' => home_url(),
        ];
    }

    private function metaDescription(WP_Post $post): string
    {
        $fromYoast = $this->yoast === null ? '' : $this->yoastDescription($this->yoast, $post);

        return $fromYoast === '' ? $this->excerpt($post) : $fromYoast;
    }

    private function yoastDescription(Yoast $yoast, WP_Post $post): string
    {
        $stored = $yoast->stored($post, 'metadesc');
        $template = $stored === '' ? $yoast->template($post, 'metadesc') : $stored;

        return $this->plain($yoast->render($template, $post));
    }

    private function excerpt(WP_Post $post): string
    {
        $text = $post->post_excerpt !== ''
            ? $post->post_excerpt
            : wp_trim_words(strip_shortcodes(wp_strip_all_tags($post->post_content)), 55, '');

        return $this->plain($text);
    }

    private function plain(string $value): string
    {
        return trim(html_entity_decode(wp_strip_all_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
