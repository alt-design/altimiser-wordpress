<?php

namespace AltDesign\Altimiser;

/**
 * The single line text fields on ACF options pages: the place a firm keeps its
 * telephone number, email and address once for every page.
 *
 * Only single line text, because a line break inside a JSON string breaks the whole
 * block. The values travel so Altimiser can tell which of them a page actually shows.
 * A field named or labelled like a credential is left out, because some sites keep an
 * API key on an options page and the values go on to Altimiser's prompts. Without ACF,
 * or without options pages, there are none.
 */
class Globals
{
    /**
     * @var ?array<int, array{handle: string, title: string, fields: array<int, array{handle: string, display: string, value: string}>}>
     */
    private ?array $sets = null;

    /**
     * @return array<int, array{handle: string, title: string, fields: array<int, array{handle: string, display: string, value: string}>}>
     */
    public function all(): array
    {
        if ($this->sets !== null) {
            return $this->sets;
        }

        if (! $this->acfIsActive()) {
            return $this->sets = [];
        }

        $sets = [];

        foreach ((array) acf_get_options_pages() as $page) {
            $handle = (string) ($page['menu_slug'] ?? '');
            $fields = $this->fields($handle, $page['post_id'] ?? 'options');

            if ($handle === '' || $fields === []) {
                continue;
            }

            $sets[] = ['handle' => $handle, 'title' => (string) ($page['page_title'] ?? $handle), 'fields' => $fields];
        }

        return $this->sets = $sets;
    }

    /**
     * Null where the options page or the field is not one of those reported, so
     * that a template naming something that is not there can be told from an empty value.
     */
    public function value(string $handle, string $field): ?string
    {
        foreach ($this->all() as $set) {
            if ($set['handle'] !== $handle) {
                continue;
            }

            foreach ($set['fields'] as $candidate) {
                if ($candidate['handle'] === $field) {
                    return $candidate['value'];
                }
            }
        }

        return null;
    }

    private function acfIsActive(): bool
    {
        foreach (['acf_get_options_pages', 'acf_get_field_groups', 'acf_get_fields', 'get_field'] as $function) {
            if (! function_exists($function)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, array{handle: string, display: string, value: string}>
     */
    private function fields(string $optionsPage, mixed $postId): array
    {
        $fields = [];

        foreach ((array) acf_get_field_groups(['options_page' => $optionsPage]) as $group) {
            foreach ((array) acf_get_fields($group) as $field) {
                $value = $this->singleLine($field, $postId);

                if ($value !== null) {
                    $fields[] = ['handle' => (string) $field['name'], 'display' => (string) ($field['label'] ?? $field['name']), 'value' => $value];
                }
            }
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function singleLine(array $field, mixed $postId): ?string
    {
        if (($field['type'] ?? null) !== 'text' || ! is_string($field['name'] ?? null) || $field['name'] === '' || $this->looksLikeACredential($field)) {
            return null;
        }

        $value = get_field($field['name'], $postId);

        if (! is_string($value) || trim($value) === '' || preg_match('/[\r\n]/', trim($value)) === 1) {
            return null;
        }

        return trim($value);
    }

    /**
     * Matched on whole words, splitting camel case too, so `apiKey` and `maps_api_key`
     * are left out and `keywords` is not.
     *
     * @param  array<string, mixed>  $field
     */
    private function looksLikeACredential(array $field): bool
    {
        $words = preg_split('/[^a-z0-9]+/', strtolower((string) preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $field['name'].' '.($field['label'] ?? ''))));

        return array_intersect((array) $words, ['key', 'apikey', 'token', 'secret', 'password']) !== [];
    }
}
