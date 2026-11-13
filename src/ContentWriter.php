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
     * ACF unslashes on save as WordPress does, expecting what a form posts. It answers
     * false for a group or flexible content field whose sub fields were saved, because
     * the parent's own stored value did not change, so what was written is read back.
     */
    public function updateField(string $fieldKey, mixed $value, int $postId): bool
    {
        return update_field($fieldKey, wp_slash($value), $postId)
            || $this->leaves(get_field($fieldKey, $postId, false)) === $this->leaves($value);
    }

    /**
     * Rows read back may be keyed by field key where they were written by name, so only
     * the values are compared.
     *
     * @return array<int, string>
     */
    private function leaves(mixed $value): array
    {
        $leaves = [];
        $value = is_array($value) ? $value : [$value];

        array_walk_recursive($value, static function (mixed $leaf) use (&$leaves): void {
            $leaves[] = (string) $leaf;
        });

        sort($leaves);

        return $leaves;
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
