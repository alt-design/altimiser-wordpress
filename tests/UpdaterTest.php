<?php

class UpdaterTest extends WP_UnitTestCase
{
    private static string $releaseAssets = 'https://github.com/alt-design/altimiser-wordpress/releases/download/v0.2.0/';

    private string $pluginFile;

    public function set_up(): void
    {
        parent::set_up();

        $this->pluginFile = plugin_basename(dirname(__DIR__).'/altimiser.php');
    }

    /**
     * @param  array<string, mixed>|WP_Error  $response
     */
    private function gitHubAnswers(array|WP_Error $response): void
    {
        add_filter('pre_http_request', static fn () => $response);
    }

    /**
     * @param  array<string, mixed>  $release
     * @return array<string, mixed>
     */
    private function answer(array $release, int $status = 200): array
    {
        return ['headers' => [], 'body' => wp_json_encode($release), 'response' => ['code' => $status, 'message' => ''], 'cookies' => [], 'filename' => null];
    }

    /**
     * @return array<string, mixed>|false
     */
    private function offer(?string $pluginFile = null): array|false
    {
        return apply_filters('update_plugins_github.com', false, ['Version' => '0.1.0'], $pluginFile ?? $this->pluginFile, []);
    }

    public function test_offers_the_zip_from_the_latest_release(): void
    {
        $this->gitHubAnswers($this->answer([
            'tag_name' => 'v0.2.0',
            'assets' => [
                ['name' => 'notes.txt', 'browser_download_url' => self::$releaseAssets.'notes.txt'],
                ['name' => 'altimiser.zip', 'browser_download_url' => self::$releaseAssets.'altimiser.zip'],
            ],
        ]));

        $offer = $this->offer();

        $this->assertSame('0.2.0', $offer['version']);
        $this->assertSame(self::$releaseAssets.'altimiser.zip', $offer['package']);
        $this->assertSame('altimiser', $offer['slug']);
    }

    public function test_answers_for_no_other_plugin_on_github(): void
    {
        $this->gitHubAnswers($this->answer(['tag_name' => 'v0.2.0', 'assets' => [['name' => 'altimiser.zip', 'browser_download_url' => self::$releaseAssets.'altimiser.zip']]]));

        $this->assertFalse($this->offer('another/another.php'));
    }

    public function test_leaves_an_answer_from_an_earlier_filter_alone_even_if_it_is_not_an_array(): void
    {
        $this->assertNull(apply_filters('update_plugins_github.com', null, ['Version' => '0.1.0'], 'another/another.php', []));
    }

    /**
     * @dataProvider unusableReleases
     *
     * @param  array<string, mixed>|WP_Error  $response
     */
    public function test_offers_nothing_without_a_release_it_can_install(array|WP_Error $response): void
    {
        $this->gitHubAnswers($response);

        $this->assertFalse($this->offer());
    }

    /**
     * @return array<string, array{array<string, mixed>|WP_Error}>
     */
    public static function unusableReleases(): array
    {
        $answer = static fn (array $release, int $status = 200): array => ['headers' => [], 'body' => wp_json_encode($release), 'response' => ['code' => $status, 'message' => ''], 'cookies' => [], 'filename' => null];

        return [
            'github unreachable' => [new WP_Error('http_request_failed', 'Connection timed out')],
            'rate limited' => [$answer(['message' => 'API rate limit exceeded'], 403)],
            'no release yet' => [$answer(['message' => 'Not Found'], 404)],
            'a release with no zip' => [$answer(['tag_name' => 'v0.2.0', 'assets' => []])],
            'a zip with another name' => [$answer(['tag_name' => 'v0.2.0', 'assets' => [['name' => 'source.zip', 'browser_download_url' => self::$releaseAssets.'source.zip']]])],
            'no tag' => [$answer(['assets' => [['name' => 'altimiser.zip', 'browser_download_url' => self::$releaseAssets.'altimiser.zip']]])],
            'a zip from somewhere else' => [$answer(['tag_name' => 'v0.2.0', 'assets' => [['name' => 'altimiser.zip', 'browser_download_url' => 'https://example.com/altimiser.zip']]])],
            'a zip over plain http' => [$answer(['tag_name' => 'v0.2.0', 'assets' => [['name' => 'altimiser.zip', 'browser_download_url' => 'http://github.com/alt-design/altimiser-wordpress/releases/download/v0.2.0/altimiser.zip']]])],
            'a zip from another repository' => [$answer(['tag_name' => 'v0.2.0', 'assets' => [['name' => 'altimiser.zip', 'browser_download_url' => 'https://github.com/alt-design/another/releases/download/v0.2.0/altimiser.zip']]])],
            'assets that are not a list' => [$answer(['tag_name' => 'v0.2.0', 'assets' => 'altimiser.zip'])],
            'a download address that is not text' => [$answer(['tag_name' => 'v0.2.0', 'assets' => [['name' => 'altimiser.zip', 'browser_download_url' => 12]]])],
            'an answer that is not an object' => [array_merge($answer([]), ['body' => '"a string"'])],
        ];
    }

    public function test_declares_the_host_the_updater_answers_for(): void
    {
        $headers = get_file_data(dirname(__DIR__).'/altimiser.php', ['updateUri' => 'Update URI']);

        $this->assertSame('github.com', wp_parse_url($headers['updateUri'], PHP_URL_HOST));
    }
}
