<?php

namespace AltDesign\Altimiser;

class Updater
{
    public function __construct(private string $pluginFile) {}

    public function register(): void
    {
        add_filter('update_plugins_github.com', [$this, 'offerLatestRelease'], 10, 3);
    }

    /**
     * WordPress asks this for every plugin whose Update URI is on github.com, and
     * compares the version itself. It already limits how often it checks.
     *
     * @param  mixed  $update  Whatever core or an earlier filter has answered so far.
     * @param  array<string, mixed>  $pluginData
     * @return mixed
     */
    public function offerLatestRelease(mixed $update, array $pluginData, string $pluginFile): mixed
    {
        if ($pluginFile !== plugin_basename($this->pluginFile)) {
            return $update;
        }

        $response = wp_remote_get('https://api.github.com/repos/alt-design/altimiser-wordpress/releases/latest', [
            'timeout' => 5,
            'headers' => ['Accept' => 'application/vnd.github+json'],
        ]);

        if (wp_remote_retrieve_response_code($response) !== 200) {
            return $update;
        }

        $release = json_decode(wp_remote_retrieve_body($response), true);
        $assets = $release['assets'] ?? [];
        $package = is_array($assets) ? $this->package($assets) : null;

        if ($package === null || ! is_string($release['tag_name'] ?? null)) {
            return $update;
        }

        return [
            'slug' => 'altimiser',
            'version' => ltrim($release['tag_name'], 'v'),
            'url' => 'https://github.com/alt-design/altimiser-wordpress',
            'package' => $package,
        ];
    }

    /**
     * Only the built zip will do. The archive GitHub makes of a tag unpacks into a
     * folder named after the commit, and WordPress would install that as a new plugin.
     * WordPress runs what it downloads, so it has to come from this repository's releases.
     *
     * @param  array<int, mixed>  $assets
     */
    private function package(array $assets): ?string
    {
        foreach ($assets as $asset) {
            if (($asset['name'] ?? null) !== 'altimiser.zip') {
                continue;
            }

            $address = $asset['browser_download_url'] ?? null;

            return is_string($address) && str_starts_with($address, 'https://github.com/alt-design/altimiser-wordpress/releases/download/')
                ? $address
                : null;
        }

        return null;
    }
}
