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
     * ACF unslashes on save as WordPress does, expecting what a form posts.
     */
    public function updateField(string $fieldKey, mixed $value, int $postId): bool
    {
        return (bool) update_field($fieldKey, wp_slash($value), $postId);
    }

    /**
     * A page builder keeps the page in its own data and leaves post_content as a copy
     * it overwrites on the next save, so an edit there would show nowhere.
     */
    public function pageBuilder(int $postId): ?string
    {
        if (get_post_meta($postId, '_elementor_edit_mode', true) === 'builder') {
            return 'Elementor';
        }

        if ((bool) get_post_meta($postId, '_fl_builder_enabled', true)) {
            return 'Beaver Builder';
        }

        return null;
    }
}
