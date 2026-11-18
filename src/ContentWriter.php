<?php

namespace AltDesign\Altimiser;

class ContentWriter
{
    /**
     * A signed request has no user, so saving would run the content through kses and
     * strip the iframes, scripts and forms an editor placed on the page. Saving also
     * unslashes what it is given, which would take every backslash out.
     *
     * @param  array<string, mixed>  $fields
     */
    public function updatePost(int $postId, array $fields): bool
    {
        $filtered = has_filter('content_save_pre', 'wp_filter_post_kses') !== false;

        kses_remove_filters();

        try {
            $result = wp_update_post(wp_slash(['ID' => $postId, ...$fields]), true);
        } finally {
            if ($filtered) {
                kses_init_filters();
            }
        }

        return ! is_wp_error($result) && $result === $postId;
    }

    /**
     * Saving an ACF field through update_field writes every sub field of the parent,
     * which stores an empty value for each one a row never had, so the one value that
     * changed is written to its own meta row instead. ACF keeps nested values under
     * the parent's name, the row and the sub field's name, such as sections_2_body.
     * WordPress unslashes meta on save, so the value is slashed first.
     *
     * @param  string  $fieldName  The top level ACF field the value sits in.
     */
    public function updateSubField(int $postId, string $metaKey, string $fieldName, string $value): bool
    {
        update_post_meta($postId, $metaKey, wp_slash($value));

        clean_post_cache($postId);

        if (function_exists('acf_flush_value_cache')) {
            acf_flush_value_cache($postId, $fieldName);
            acf_flush_value_cache($postId, $metaKey);
        }

        return get_post_meta($postId, $metaKey, true) === $value;
    }

    /**
     * A page builder keeps the page in its own data and leaves post_content as a copy
     * it overwrites on the next save, or keeps the page as shortcodes whose attributes
     * carry text, so an edit would show nowhere or break the layout. A builder's own
     * shortcodes in the content are taken as a sign of one whose setting is missing.
     */
    public function pageBuilder(int $postId): ?string
    {
        $meta = static fn (string $key): mixed => get_post_meta($postId, $key, true);

        return match (true) {
            $meta('_elementor_edit_mode') === 'builder' => 'Elementor',
            (bool) $meta('_fl_builder_enabled') => 'Beaver Builder',
            $meta('_et_pb_use_builder') === 'on' => 'Divi',
            $meta('_wpb_vc_js_status') === 'true' => 'WPBakery',
            $meta('fusion_builder_status') === 'active' => 'Avada',
            ! empty($meta('ct_builder_shortcodes')) || ! empty($meta('ct_builder_json')) => 'Oxygen',
            ! empty($meta('_bricks_page_content_2')) => 'Bricks',
            ! empty($meta('panels_data')) => 'SiteOrigin Page Builder',
            preg_match('/\[(?:et_pb_|vc_|fusion_)/', (string) get_post_field('post_content', $postId)) === 1 => 'a page builder',
            default => null,
        };
    }
}
