<?php

namespace AltDesign\Altimiser;

class SettingsPage
{
    private string $page = 'altimiser-settings';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addPage']);
        add_action('admin_init', [$this, 'registerSetting']);
    }

    public function addPage(): void
    {
        add_options_page(
            __('Altimiser', 'altimiser'),
            __('Altimiser', 'altimiser'),
            'manage_options',
            $this->page,
            [$this, 'render'],
        );
    }

    public function registerSetting(): void
    {
        register_setting($this->page, Settings::$option, [
            'type' => 'object',
            'sanitize_callback' => [$this, 'sanitise'],
            'show_in_rest' => false,
            'default' => [],
        ]);
    }

    /**
     * A blank secret keeps the one saved, because the field never shows it, and a short
     * one is turned away with the saved one kept, since saving it would stop the site
     * accepting anything from Altimiser.
     *
     * @return array<string, string>
     */
    public function sanitise(mixed $input): array
    {
        $input = is_array($input) ? $input : [];
        $saved = Settings::stored()['secret'] ?? '';
        $secret = trim((string) ($input['secret'] ?? ''));

        if ($secret !== '' && ! Settings::secretIsLongEnough($secret)) {
            add_settings_error(Settings::$option, 'altimiser_secret_too_short', sprintf(
                /* translators: %d: the minimum number of characters */
                __('The secret needs at least %d characters, so the one already saved was kept.', 'altimiser'),
                Settings::$minimumSecretLength,
            ));

            $secret = '';
        }

        $url = esc_url_raw(trim((string) ($input['altimiser_url'] ?? '')), ['https', 'http']);

        if ($url !== '' && ! Settings::isSecureAddress($url)) {
            add_settings_error(Settings::$option, 'altimiser_url_not_https', __('The Altimiser address needs to start with https://, so it was not saved.', 'altimiser'));

            $url = '';
        }

        return array_filter([
            'altimiser_url' => $url,
            'secret' => $secret === '' ? $saved : $secret,
        ]);
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $stored = Settings::stored();

        echo '<div class="wrap"><h1>'.esc_html__('Altimiser', 'altimiser').'</h1>';
        printf('<p>%s</p>', esc_html($this->status()));
        printf(
            '<p>%s <code>%s</code></p>',
            esc_html__('Give Altimiser this receiver address when adding the site:', 'altimiser'),
            esc_html(rest_url('altimiser/v1')),
        );

        echo '<form method="post" action="options.php">';
        settings_fields($this->page);
        echo '<table class="form-table" role="presentation"><tbody>';

        $this->row('altimiser_url', __('Altimiser address', 'altimiser'), 'ALTIMISER_URL', sprintf(
            '<input type="url" class="regular-text" id="altimiser_url" name="%s[altimiser_url]" value="%s">',
            esc_attr(Settings::$option),
            esc_attr($stored['altimiser_url'] ?? ''),
        ), __('Where the review queue and its count come from.', 'altimiser'));

        $this->row('altimiser_secret', __('Secret', 'altimiser'), 'ALTIMISER_SECRET', sprintf(
            '<input type="password" class="regular-text" id="altimiser_secret" name="%s[secret]" value="" autocomplete="new-password" placeholder="%s">',
            esc_attr(Settings::$option),
            esc_attr(isset($stored['secret']) ? __('A secret is saved. Leave blank to keep it.', 'altimiser') : ''),
        ), sprintf(
            /* translators: %d: the minimum number of characters */
            __('The secret Altimiser shows when the site is added, at least %d characters.', 'altimiser'),
            Settings::$minimumSecretLength,
        ));

        echo '</tbody></table>';
        submit_button();
        echo '</form></div>';
    }

    private function row(string $id, string $label, string $constant, string $field, string $description): void
    {
        $control = Settings::definedOutsideSettings($constant)
            ? '<p>'.esc_html(sprintf(
                /* translators: %s: the name of a PHP constant */
                __('Set by %s in wp-config.php or the server environment.', 'altimiser'),
                $constant,
            )).'</p>'
            : $field.'<p class="description">'.esc_html($description).'</p>';

        printf(
            '<tr><th scope="row"><label for="%s">%s</label></th><td>%s</td></tr>',
            esc_attr($id),
            esc_html($label),
            $control,
        );
    }

    private function status(): string
    {
        $secret = Settings::secret();

        if ($secret === null) {
            return __('No secret is set, so this site turns away every request from Altimiser.', 'altimiser');
        }

        if (! Settings::secretIsLongEnough($secret)) {
            return sprintf(
                /* translators: %d: the minimum number of characters */
                __('The secret is shorter than %d characters, so this site turns away every request from Altimiser until it is replaced.', 'altimiser'),
                Settings::$minimumSecretLength,
            );
        }

        return __('A secret is set, and this site accepts signed requests from Altimiser.', 'altimiser');
    }
}
