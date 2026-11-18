<?php

namespace AltDesign\Altimiser;

/**
 * The languages of a site that WPML makes multilingual, read and switched through
 * WPML's own filters and action, so that nothing here depends on how its addresses
 * are laid out. Every method is inert where WPML is not active.
 */
class Languages
{
    public function multilingual(): bool
    {
        return has_filter('wpml_active_languages') !== false
            && has_filter('wpml_current_language') !== false
            && has_action('wpml_switch_language') !== false;
    }

    public function current(): ?string
    {
        $current = apply_filters('wpml_current_language', null);

        return is_string($current) && $current !== '' ? $current : null;
    }

    /**
     * @return array<int, string>
     */
    public function codes(): array
    {
        $languages = apply_filters('wpml_active_languages', null, ['skip_missing' => 0]);

        return is_array($languages) ? array_map('strval', array_keys($languages)) : [];
    }

    /**
     * REST requests are not run through WPML's own address handling, so an address in
     * another language is put into the default language's form before it is looked up.
     */
    public function withoutLanguage(string $url): string
    {
        $default = apply_filters('wpml_default_language', null);

        if (! $this->multilingual() || ! is_string($default) || $default === '') {
            return $url;
        }

        $converted = apply_filters('wpml_permalink', $url, $default);

        return is_string($converted) && $converted !== '' ? $converted : $url;
    }

    public function switchTo(?string $code): void
    {
        if ($code !== null) {
            do_action('wpml_switch_language', $code);
        }
    }
}
