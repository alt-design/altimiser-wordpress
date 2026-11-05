<?php

namespace AltDesign\Altimiser;

use WP_Post;
use WP_Term;
use WPSEO_Meta;
use WPSEO_Option_Titles;
use WPSEO_Options;
use WPSEO_Taxonomy_Meta;
use Yoast\WP\SEO\Integrations\Watchers\Indexable_Post_Watcher;
use Yoast\WP\SEO\Integrations\Watchers\Indexable_Term_Watcher;

class Yoast
{
    public function version(): string
    {
        return WPSEO_VERSION;
    }

    public function printsSocialTags(): bool
    {
        return WPSEO_Options::get('opengraph') === true;
    }

    public function stored(WP_Post|WP_Term $record, string $field): string
    {
        $value = $record instanceof WP_Post
            ? get_post_meta($record->ID, WPSEO_Meta::$meta_prefix.$field, true)
            : WPSEO_Taxonomy_Meta::get_term_meta($record, $record->taxonomy, $this->termField($field));

        return is_string($value) ? $value : '';
    }

    /**
     * What Yoast falls back to when the record has no value of its own, in the order
     * its presentations use: a title always has a default, a description may not.
     */
    public function template(WP_Post|WP_Term $record, string $field): string
    {
        $subject = $record instanceof WP_Post ? $record->post_type : "tax-{$record->taxonomy}";

        return match ($field) {
            'title' => WPSEO_Options::get("title-{$subject}")
                ?: (WPSEO_Option_Titles::get_instance()->get_defaults()["title-{$subject}"] ?? ''),
            'metadesc' => (string) WPSEO_Options::get("metadesc-{$subject}", ''),
            default => '',
        };
    }

    public function render(string $value, WP_Post|WP_Term $record): string
    {
        return wpseo_replace_vars($value, $record);
    }

    public function storageKey(WP_Post|WP_Term $record, string $field): string
    {
        return $record instanceof WP_Post
            ? WPSEO_Meta::$meta_prefix.$field
            : "wpseo_{$this->termField($field)}";
    }

    public function write(WP_Post|WP_Term $record, string $field, string $value): void
    {
        if ($record instanceof WP_Post) {
            WPSEO_Meta::set_value($field, $value, $record->ID);
            $this->rebuildPost($record);

            return;
        }

        $this->writeTerm($record, $field, $value);
    }

    public function clear(WP_Post|WP_Term $record, string $field): void
    {
        if ($record instanceof WP_Post) {
            delete_post_meta($record->ID, WPSEO_Meta::$meta_prefix.$field);
            $this->rebuildPost($record);

            return;
        }

        $this->writeTerm($record, $field, '');
    }

    private function termField(string $field): string
    {
        return $field === 'metadesc' ? 'desc' : $field;
    }

    /**
     * Yoast would do this itself in a shutdown function, which is after the page cache
     * has been purged, and a visitor arriving in between would put the old value straight
     * back into the cache. Left to Yoast's own timing if a later version moves the class.
     */
    private function rebuildPost(WP_Post $post): void
    {
        if (class_exists(Indexable_Post_Watcher::class)) {
            YoastSEO()->classes->get(Indexable_Post_Watcher::class)->build_indexable($post->ID);
        }
    }

    /**
     * Passes every field back, because WPSEO_Taxonomy_Meta::set_value() keeps only the
     * text fields it is given and empties the term's other title, description and
     * social values.
     */
    private function writeTerm(WP_Term $term, string $field, string $value): void
    {
        WPSEO_Taxonomy_Meta::set_values($term->term_id, $term->taxonomy, [
            ...WPSEO_Taxonomy_Meta::get_term_meta($term, $term->taxonomy),
            "wpseo_{$this->termField($field)}" => $value,
        ]);

        $this->rebuildTerm($term);
    }

    /**
     * Term values live in an option, which Yoast's watchers do not listen to, and the
     * term's page is rendered from an indexable they maintain, so without this it goes
     * on printing the old value. Firing edited_term would tell every other plugin the
     * term had been edited. wp_update_term() would fire it too, and it also runs the
     * term's own description through wp_filter_kses, whoever is signed in.
     */
    private function rebuildTerm(WP_Term $term): void
    {
        if (class_exists(Indexable_Term_Watcher::class)) {
            YoastSEO()->classes->get(Indexable_Term_Watcher::class)->build_indexable($term->term_id);
        }
    }
}
