<?php

use AltDesign\Altimiser\Settings;
use AltDesign\Altimiser\SettingsPage;

/**
 * Whether a constant is defined changes what the page shows, and one cannot be
 * undefined, so each test needs a process of its own.
 *
 * @runTestsInSeparateProcesses
 *
 * @preserveGlobalState disabled
 */
class SettingsPageTest extends WP_UnitTestCase
{
    private string $longSecret = 'a-shared-secret-of-at-least-32-characters';

    private function rendered(): string
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        ob_start();
        (new SettingsPage())->render();

        return (string) ob_get_clean();
    }

    public function test_saves_a_secret_long_enough_and_the_address(): void
    {
        $saved = (new SettingsPage())->sanitise(['altimiser_url' => ' https://altimiser.example.com ', 'secret' => " {$this->longSecret} "]);

        $this->assertSame(['altimiser_url' => 'https://altimiser.example.com', 'secret' => $this->longSecret], $saved);
    }

    public function test_turns_away_a_plain_http_address_except_for_a_development_machine(): void
    {
        $refused = (new SettingsPage())->sanitise(['altimiser_url' => 'http://altimiser.example.com']);

        $this->assertArrayNotHasKey('altimiser_url', $refused);
        $this->assertSame('altimiser_url_not_https', get_settings_errors(Settings::$option)[0]['code']);
        $this->assertSame(['altimiser_url' => 'http://altimiser.test'], (new SettingsPage())->sanitise(['altimiser_url' => 'http://altimiser.test']));
    }

    public function test_ignores_a_plain_http_address_set_in_wp_config(): void
    {
        define('ALTIMISER_URL', 'http://altimiser.example.com');

        $this->assertNull(Settings::altimiserUrl());
    }

    public function test_keeps_the_saved_secret_when_the_field_is_left_blank(): void
    {
        update_option(Settings::$option, ['secret' => $this->longSecret]);

        $saved = (new SettingsPage())->sanitise(['altimiser_url' => '', 'secret' => '']);

        $this->assertSame(['secret' => $this->longSecret], $saved);
    }

    public function test_turns_away_a_short_secret_and_keeps_the_saved_one(): void
    {
        update_option(Settings::$option, ['secret' => $this->longSecret]);

        $saved = (new SettingsPage())->sanitise(['secret' => 'too-short']);

        $this->assertSame(['secret' => $this->longSecret], $saved);
        $this->assertSame('altimiser_secret_too_short', get_settings_errors(Settings::$option)[0]['code']);
    }

    public function test_keeps_the_settings_out_of_the_options_loaded_on_every_request(): void
    {
        global $wpdb;

        $wpdb->insert($wpdb->options, ['option_name' => Settings::$option, 'option_value' => serialize(['secret' => $this->longSecret]), 'autoload' => 'on']);
        wp_cache_delete('alloptions', 'options');

        update_option(Settings::$option, ['secret' => "{$this->longSecret}-changed"]);

        $this->assertArrayNotHasKey(Settings::$option, wp_load_alloptions(true));

        delete_option(Settings::$option);
        update_option(Settings::$option, ['secret' => $this->longSecret]);

        $this->assertArrayNotHasKey(Settings::$option, wp_load_alloptions(true));
        $this->assertSame($this->longSecret, Settings::secret());
    }

    public function test_never_shows_the_saved_secret(): void
    {
        update_option(Settings::$option, ['secret' => $this->longSecret]);

        $page = $this->rendered();

        $this->assertStringNotContainsString($this->longSecret, $page);
        $this->assertStringContainsString('A secret is saved. Leave blank to keep it.', $page);
        $this->assertStringContainsString('this site accepts signed requests from Altimiser', $page);
    }

    public function test_shows_a_secret_set_in_wp_config_as_set_there_rather_than_offering_a_field(): void
    {
        define('ALTIMISER_SECRET', 'shared-secret');

        $page = $this->rendered();

        $this->assertStringContainsString('Set by ALTIMISER_SECRET in wp-config.php or the server environment.', $page);
        $this->assertStringNotContainsString('name="altimiser_settings[secret]"', $page);
        $this->assertStringContainsString('shorter than 32 characters', $page);
    }

    public function test_gives_the_receiver_address_to_copy_into_altimiser(): void
    {
        $this->assertStringContainsString(esc_html(rest_url('altimiser/v1')), $this->rendered());
    }

    public function test_uninstalling_removes_the_settings_and_the_kept_count(): void
    {
        update_option(Settings::$option, ['secret' => $this->longSecret]);
        set_transient('altimiser_summary', ['summary' => ['awaiting_review' => 1]], 300);
        update_option('altimiser_robots_sitemap', 'https://example.org/sitemap.xml');

        define('WP_UNINSTALL_PLUGIN', 'altimiser/altimiser.php');
        require dirname(__DIR__).'/uninstall.php';

        $this->assertFalse(get_option(Settings::$option));
        $this->assertFalse(get_transient('altimiser_summary'));
        $this->assertFalse(get_option('altimiser_robots_sitemap'));
    }

    public function test_shows_nothing_to_someone_who_cannot_manage_options(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        ob_start();
        (new SettingsPage())->render();

        $this->assertSame('', ob_get_clean());
    }
}
