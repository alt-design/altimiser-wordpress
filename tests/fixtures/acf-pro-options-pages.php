<?php

/**
 * Stands in for the options pages of ACF PRO, which the tests cannot install. The free
 * plugin, loaded by the bootstrap, provides the field groups, fields and values.
 */
function acf_get_options_pages(): array
{
    return [
        'site-settings' => ['menu_slug' => 'site-settings', 'page_title' => 'Site settings', 'post_id' => 'options'],
        'empty-page' => ['menu_slug' => 'empty-page', 'page_title' => 'Empty', 'post_id' => 'options'],
    ];
}

add_filter(
    'acf/location/match_rule/type=options_page',
    static fn (bool $result, array $rule, array $screen): bool => ($screen['options_page'] ?? null) === $rule['value'],
    10,
    3,
);

/**
 * @param  array<string, string>  $values  field name to value, each a text field unless named address
 */
function altimiser_test_site_settings(array $values): void
{
    $fields = [];

    foreach (array_keys($values) as $name) {
        $fields[] = ['key' => "field_{$name}", 'name' => $name, 'label' => ucfirst($name), 'type' => $name === 'address' ? 'textarea' : 'text'];
    }

    acf_add_local_field_group([
        'key' => 'group_site_settings',
        'title' => 'Site settings',
        'fields' => $fields,
        'location' => [[['param' => 'options_page', 'operator' => '==', 'value' => 'site-settings']]],
    ]);

    foreach ($values as $name => $value) {
        update_field("field_{$name}", wp_slash($value), 'options');
    }
}
