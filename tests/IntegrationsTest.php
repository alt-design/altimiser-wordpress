<?php

class IntegrationsTest extends ReceiverTestCase
{
    private string $pluginFolder = '';

    public static function set_up_before_class(): void
    {
        parent::set_up_before_class();

        self::configureSecret();
    }

    public function tear_down(): void
    {
        if ($this->pluginFolder !== '') {
            unlink("{$this->pluginFolder}/wp-super-cache/wp-cache.php");
            rmdir("{$this->pluginFolder}/wp-super-cache");
            rmdir($this->pluginFolder);
        }

        parent::tear_down();
    }

    public function test_lists_only_yoast_when_no_cache_or_translation_plugin_is_active(): void
    {
        $integrations = $this->dispatch($this->signedRequest())->get_data()['integrations'];

        $this->assertSame(['yoast'], array_keys($integrations));
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    public function test_names_each_plugin_that_has_defined_its_version(): void
    {
        $versions = [
            'WP_ROCKET_VERSION' => '3.17.0',
            'LSCWP_V' => '6.5.1',
            'WPHB_VERSION' => '3.9.0',
            'W3TC_VERSION' => '2.7.7',
            'BREEZE_VERSION' => '2.1.11',
            'ICL_SITEPRESS_VERSION' => '4.6.13',
            'POLYLANG_VERSION' => '3.6.4',
            'WEGLOT_VERSION' => '4.2.8',
        ];

        foreach ($versions as $constant => $version) {
            define($constant, $version);
        }

        $integrations = $this->dispatch($this->signedRequest())->get_data()['integrations'];

        $this->assertSame(['installed' => true, 'version' => WPSEO_VERSION], $integrations['yoast']);
        $this->assertSame(['installed' => true, 'version' => '3.17.0'], $integrations['wp_rocket']);
        $this->assertSame(['installed' => true, 'version' => '6.5.1'], $integrations['litespeed_cache']);
        $this->assertSame(['installed' => true, 'version' => '3.9.0'], $integrations['hummingbird']);
        $this->assertSame(['installed' => true, 'version' => '2.7.7'], $integrations['w3_total_cache']);
        $this->assertSame(['installed' => true, 'version' => '2.1.11'], $integrations['breeze']);
        $this->assertSame(['installed' => true, 'version' => '4.6.13'], $integrations['wpml']);
        $this->assertSame(['installed' => true, 'version' => '3.6.4'], $integrations['polylang']);
        $this->assertSame(['installed' => true, 'version' => '4.2.8'], $integrations['weglot']);
        $this->assertArrayNotHasKey('wp_super_cache', $integrations);
    }

    public function test_reads_the_version_of_a_plugin_that_is_listed_active_from_its_header(): void
    {
        update_option('active_plugins', ['wp-super-cache/wp-cache.php']);

        $integrations = (new AltDesign\Altimiser\Integrations($this->pluginDirectory()))->active();

        $this->assertSame(['installed' => true, 'version' => '3.0.1'], $integrations['wp_super_cache']);
    }

    public function test_reads_a_network_activated_plugin_too(): void
    {
        update_site_option('active_sitewide_plugins', ['wp-super-cache/wp-cache.php' => time()]);

        $integrations = (new AltDesign\Altimiser\Integrations($this->pluginDirectory()))->active();

        $this->assertSame('3.0.1', $integrations['wp_super_cache']['version']);
    }

    public function test_leaves_out_a_plugin_that_is_installed_but_not_active(): void
    {
        $integrations = (new AltDesign\Altimiser\Integrations($this->pluginDirectory()))->active();

        $this->assertArrayNotHasKey('wp_super_cache', $integrations);
    }

    public function test_finds_the_pro_build_of_hummingbird_in_its_own_folder(): void
    {
        update_option('active_plugins', ['wp-hummingbird/wp-hummingbird.php']);
        $directory = $this->pluginDirectory();
        mkdir("{$directory}/wp-hummingbird");
        file_put_contents("{$directory}/wp-hummingbird/wp-hummingbird.php", "<?php\n/*\nPlugin Name: Test speed\nVersion: 3.9.1\n*/\n");

        $integrations = (new AltDesign\Altimiser\Integrations($directory))->active();

        unlink("{$directory}/wp-hummingbird/wp-hummingbird.php");
        rmdir("{$directory}/wp-hummingbird");

        $this->assertSame(['installed' => true, 'version' => '3.9.1'], $integrations['hummingbird']);
    }

    private function pluginDirectory(): string
    {
        $this->pluginFolder = sys_get_temp_dir().'/altimiser-plugins-'.uniqid();
        mkdir("{$this->pluginFolder}/wp-super-cache", 0777, true);
        file_put_contents("{$this->pluginFolder}/wp-super-cache/wp-cache.php", "<?php\n/*\nPlugin Name: Test cache\nVersion: 3.0.1\n*/\n");

        return $this->pluginFolder;
    }
}
