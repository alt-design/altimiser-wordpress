<?php

namespace AltDesign\Altimiser;

/**
 * Page cache and translation plugins that change what a visitor is served, so
 * Altimiser can say why a fix that landed is not showing, or that a site has
 * translated copies of its pages. A plugin is active when it has defined its
 * version constant, or when WordPress lists its file as active.
 */
class Integrations
{
    /**
     * @var array<string, array{constant: string, files: array<int, string>}>
     */
    private array $known = [
        'wp_rocket' => ['constant' => 'WP_ROCKET_VERSION', 'files' => ['wp-rocket/wp-rocket.php']],
        'litespeed_cache' => ['constant' => 'LSCWP_V', 'files' => ['litespeed-cache/litespeed-cache.php']],
        'hummingbird' => ['constant' => 'WPHB_VERSION', 'files' => ['hummingbird-performance/wp-hummingbird.php']],
        'w3_total_cache' => ['constant' => 'W3TC_VERSION', 'files' => ['w3-total-cache/w3-total-cache.php']],
        'wp_super_cache' => ['constant' => '', 'files' => ['wp-super-cache/wp-cache.php']],
        'breeze' => ['constant' => 'BREEZE_VERSION', 'files' => ['breeze/breeze.php']],
        'wpml' => ['constant' => 'ICL_SITEPRESS_VERSION', 'files' => ['sitepress-multilingual-cms/sitepress.php']],
        'polylang' => ['constant' => 'POLYLANG_VERSION', 'files' => ['polylang/polylang.php', 'polylang-pro/polylang.php']],
        'weglot' => ['constant' => 'WEGLOT_VERSION', 'files' => ['weglot/weglot.php']],
    ];

    public function __construct(private string $pluginDirectory = WP_PLUGIN_DIR) {}

    /**
     * @return array<string, array{installed: true, version: string}>
     */
    public function active(): array
    {
        $active = [];

        foreach ($this->known as $name => $plugin) {
            $version = $this->version($plugin['constant'], $plugin['files']);

            if ($version !== null) {
                $active[$name] = ['installed' => true, 'version' => $version];
            }
        }

        return $active;
    }

    /**
     * @param  array<int, string>  $files
     */
    private function version(string $constant, array $files): ?string
    {
        if ($constant !== '' && defined($constant)) {
            return (string) constant($constant);
        }

        foreach ($files as $file) {
            if ($this->isListedActive($file)) {
                return $this->headerVersion($file);
            }
        }

        return null;
    }

    private function isListedActive(string $file): bool
    {
        $network = array_keys((array) get_site_option('active_sitewide_plugins', []));

        return in_array($file, (array) get_option('active_plugins', []), true) || in_array($file, $network, true);
    }

    private function headerVersion(string $file): string
    {
        $path = "{$this->pluginDirectory}/{$file}";

        return is_readable($path) ? (string) get_file_data($path, ['version' => 'Version'])['version'] : '';
    }
}
