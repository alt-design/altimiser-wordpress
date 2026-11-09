<?php

class HealthListsTest extends ReceiverTestCase
{
    public static function set_up_before_class(): void
    {
        parent::set_up_before_class();

        self::configureSecret();
    }

    public function tear_down(): void
    {
        _unregister_post_type('team');
        _unregister_post_type('hidden');

        parent::tear_down();
    }

    private function health(): array
    {
        return $this->dispatch($this->signedRequest())->get_data();
    }

    private function usePrettyPermalinks(): void
    {
        $this->set_permalink_structure('/%postname%/');

        register_post_type('team', ['public' => true, 'label' => 'Team', 'rewrite' => ['slug' => 'team']]);
        register_post_type('hidden', ['public' => false]);
        flush_rewrite_rules();
    }

    private function collection(string $handle): ?array
    {
        foreach ($this->health()['collections'] as $collection) {
            if ($collection['handle'] === $handle) {
                return $collection;
            }
        }

        return null;
    }

    public function test_lists_public_post_types_with_the_route_their_posts_live_at(): void
    {
        $this->usePrettyPermalinks();

        $this->assertSame(
            ['handle' => 'team', 'title' => 'Team', 'route' => '/team/{ slug }', 'loopable' => true],
            $this->collection('team'),
        );
        $this->assertSame('/{ slug }', $this->collection('page')['route']);
        $this->assertSame('/{ slug }', $this->collection('post')['route']);
    }

    public function test_shows_a_dated_permalink_structure_with_its_variables(): void
    {
        $this->set_permalink_structure('/news/%year%/%postname%/');

        $this->assertSame('/news/{ year }/{ slug }', $this->collection('post')['route']);
    }

    public function test_leaves_out_attachments_and_types_without_public_pages(): void
    {
        $this->usePrettyPermalinks();

        $handles = array_column($this->health()['collections'], 'handle');

        $this->assertNotContains('attachment', $handles);
        $this->assertNotContains('hidden', $handles);
    }

    public function test_lists_no_collections_while_the_site_uses_plain_permalinks(): void
    {
        $this->set_permalink_structure('');

        $this->assertSame([], $this->health()['collections']);
    }

    public function test_lists_no_globals_without_acf_options_pages(): void
    {
        $this->assertFalse(function_exists('acf_get_options_pages'));
        $this->assertSame([], $this->health()['globals']);
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    public function test_lists_single_line_text_fields_from_acf_options_pages(): void
    {
        require __DIR__.'/fixtures/acf-pro-options-pages.php';

        altimiser_test_site_settings([
            'telephone' => ' +44 20 7946 0000 ',
            'address' => "1 High Street\nLondon",
            'fax' => '',
            'tagline' => "Two\nlines",
        ]);

        $this->assertSame([
            [
                'handle' => 'site-settings',
                'title' => 'Site settings',
                'fields' => [
                    ['handle' => 'telephone', 'display' => 'Telephone', 'value' => '+44 20 7946 0000'],
                ],
            ],
        ], $this->health()['globals']);
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    public function test_answers_with_an_empty_list_when_reading_acf_fails(): void
    {
        $errorLog = tempnam(sys_get_temp_dir(), 'altimiser');
        ini_set('error_log', $errorLog);

        function acf_get_options_pages(): array
        {
            throw new RuntimeException('ACF is not ready.');
        }

        $response = $this->dispatch($this->signedRequest());

        $this->assertSame(200, $response->get_status());
        $this->assertSame([], $response->get_data()['globals']);
        $this->assertStringContainsString('ACF is not ready.', (string) file_get_contents($errorLog));
        unlink($errorLog);
    }


    public function test_describes_each_variable_the_renderer_understands(): void
    {
        $variables = $this->health()['variables'];

        foreach (['title', 'meta_description', 'permalink', 'excerpt', 'featured_image', 'date_published', 'date_modified', 'author_name'] as $name) {
            $this->assertArrayHasKey($name, $variables);
            $this->assertNotSame('', $variables[$name]);
        }
    }

    public function test_keeps_health_uncached(): void
    {
        $this->assertStringContainsString('no-store', $this->dispatch($this->signedRequest())->get_headers()['Cache-Control']);
    }
}
