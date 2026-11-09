<?php

class StructuredDataGlobalsTest extends ReceiverTestCase
{
    public static function set_up_before_class(): void
    {
        parent::set_up_before_class();

        self::configureSecret();
    }

    public function set_up(): void
    {
        parent::set_up();

        $this->set_permalink_structure('/%postname%/');
        create_initial_taxonomies();
        flush_rewrite_rules();
    }

    private function applyBlock(int $postId, string $block): array
    {
        return $this->sendOne([
            'check' => 'structured_data.missing',
            'url' => get_permalink($postId),
            'suggested_value' => $block,
        ]);
    }

    public function test_skips_a_block_naming_a_global_when_acf_is_absent(): void
    {
        $pageId = self::factory()->post->create(['post_type' => 'page']);

        $result = $this->applyBlock($pageId, '{"telephone": "{{ site-settings:telephone }}"}');

        $this->assertSame('invalid_markup', $result['reason']);
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    public function test_renders_a_global_from_an_acf_options_page_escaped_for_json(): void
    {
        eval(<<<'PHP'
        function acf_get_options_pages(): array
        {
            return ['site-settings' => ['menu_slug' => 'site-settings', 'page_title' => 'Site settings', 'post_id' => 'options']];
        }

        function acf_get_field_groups(array $filter): array
        {
            return [['key' => 'group_contact']];
        }

        function acf_get_fields(array $group): array
        {
            return [['name' => 'telephone', 'label' => 'Telephone', 'type' => 'text']];
        }

        function get_field(string $name, mixed $postId): string
        {
            return 'Call "us" on \\ 020 7946 0000';
        }
        PHP);

        $pageId = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);
        $applied = $this->applyBlock($pageId, '{"telephone": "{{ site-settings:telephone }}"}');
        $unknown = $this->applyBlock($pageId, '{"fax": "{{ site-settings:fax }}"}');

        $this->go_to(get_permalink($pageId));
        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', get_echo('wp_head'), $match);

        $this->assertSame('applied', $applied['status']);
        $this->assertSame('invalid_markup', $unknown['reason']);
        $this->assertSame('Call "us" on \\ 020 7946 0000', json_decode($match[1], true)['telephone']);
    }
}
