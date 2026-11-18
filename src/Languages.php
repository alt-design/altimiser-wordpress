<?php

namespace AltDesign\Altimiser;

/**
 * The languages of a site that WPML makes multilingual, read and switched through
 * WPML's own filters and action, so that nothing here depends on how its addresses
 * are laid out. Every method is inert where WPML is not active.
 */
class Languages
{
    private int $switches = 0;

    /**
     * @var ?array<int, string>
     */
    private ?array $codes = null;

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
        if ($this->codes !== null) {
            return $this->codes;
        }

        $languages = apply_filters('wpml_active_languages', null, ['skip_missing' => 0]);

        return $this->codes = is_array($languages) ? array_map('strval', array_keys($languages)) : [];
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

    /**
     * WPML keeps the languages it has been switched to on a stack, which only a switch
     * to null takes one off, so each switch made here is counted to be taken off again.
     */
    public function switchTo(string $code): void
    {
        do_action('wpml_switch_language', $code);

        $this->switches++;
    }

    public function switchBack(): void
    {
        if ($this->switches === 0) {
            return;
        }

        do_action('wpml_switch_language', null);

        $this->switches--;
    }

    public function restore(): void
    {
        while ($this->switches > 0) {
            $this->switchBack();
        }
    }
}
