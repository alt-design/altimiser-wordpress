<?php

class StructuredDataTest extends ReceiverTestCase
{
    private string $block = <<<'JSON'
{
  "@context": "https://schema.org",
  "@type": "Article",
  "headline": "{{ title }}",
  "description": "{{ meta_description }}",
  "url": "{{ permalink }}",
  "datePublished": "{{ date_published }}",
  "author": {"@type": "Person", "name": "{{ author_name }}"}
}
JSON;

    public static function set_up_before_class(): void
    {
        parent::set_up_before_class();

        self::configureSecret();
    }

    public function set_up(): void
    {
        parent::set_up();

        $this->set_permalink_structure('/%postname%/');

        register_post_type('team', ['public' => true, 'label' => 'Team', 'rewrite' => ['slug' => 'team']]);
        create_initial_taxonomies();
        flush_rewrite_rules();
    }

    public function tear_down(): void
    {
        _unregister_post_type('team');
        WPSEO_Options::clear_cache();

        parent::tear_down();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createPost(array $overrides = []): int
    {
        return self::factory()->post->create(wp_slash(['post_status' => 'publish', ...$overrides]));
    }

    /**
     * @return array<string, mixed>
     */
    private function applyBlock(int $postId, ?string $block = null, bool $dryRun = false): array
    {
        return $this->sendOne([
            'check' => 'structured_data.missing',
            'url' => get_permalink($postId),
            'target' => ['selector' => null, 'attribute' => null],
            'current_value' => null,
            'suggested_value' => $block ?? $this->block,
        ], $dryRun);
    }

    /**
     * @return array<int, mixed>
     */
    private function printedBlocks(int $postId): array
    {
        $this->go_to(get_permalink($postId));

        $head = get_echo('wp_head');
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $head, $matches);

        return $matches[1];
    }

    /**
     * @return array<string, mixed>
     */
    private function printed(int $postId): array
    {
        $blocks = $this->printedBlocks($postId);

        $this->assertCount(1, $blocks);

        return json_decode($blocks[0], true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_advertises_the_check_and_stores_a_page_block_on_the_page(): void
    {
        $pageId = $this->createPost(['post_type' => 'page', 'post_title' => 'About']);

        $result = $this->applyBlock($pageId);

        $this->assertSame('applied', $result['status']);
        $this->assertSame($this->block, $result['to']);
        $this->assertSame(['type' => 'post', 'id' => $pageId, 'collection' => 'page', 'field' => '_altimiser_structured_data'], $result['location']);
        $this->assertSame($this->block, get_post_meta($pageId, '_altimiser_structured_data', true));
        $this->assertContains('structured_data.missing', $this->dispatch($this->signedRequest())->get_data()['checks']);
    }

    public function test_keeps_a_page_block_per_page_and_not_as_a_default(): void
    {
        $first = $this->createPost(['post_type' => 'page']);
        $second = $this->createPost(['post_type' => 'page']);

        $this->applyBlock($first);
        $result = $this->applyBlock($second);

        $this->assertSame('applied', $result['status']);
        $this->assertSame($this->block, get_post_meta($second, '_altimiser_structured_data', true));
        $this->assertFalse(get_option('altimiser_structured_data_defaults'));
    }

    public function test_leaves_a_page_with_markup_of_its_own_alone(): void
    {
        $pageId = $this->createPost(['post_type' => 'page']);
        update_post_meta($pageId, '_altimiser_structured_data', '{"@type": "WebPage"}');

        $result = $this->applyBlock($pageId);

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('value_changed', $result['reason']);
        $this->assertSame('{"@type": "WebPage"}', get_post_meta($pageId, '_altimiser_structured_data', true));
    }

    public function test_reports_a_page_already_holding_the_block_as_already_applied(): void
    {
        $pageId = $this->createPost(['post_type' => 'page']);
        $this->applyBlock($pageId);

        $this->assertSame('already_applied', $this->applyBlock($pageId)['status']);
    }

    public function test_stores_a_block_once_as_the_default_for_a_post_type(): void
    {
        $postId = $this->createPost();

        $result = $this->applyBlock($postId);

        $this->assertSame('applied', $result['status']);
        $this->assertSame(['type' => 'collection', 'collection' => 'post', 'field' => 'altimiser_structured_data_defaults'], $result['location']);
        $this->assertSame(['post' => $this->block], get_option('altimiser_structured_data_defaults'));
        $this->assertSame('', (string) get_post_meta($postId, '_altimiser_structured_data', true));
    }

    public function test_clears_the_whole_page_cache_for_a_default_shared_by_every_post_of_a_type(): void
    {
        $purged = [];
        $record = static function (array $urls, bool $everything) use (&$purged): void {
            $purged[] = $everything;
        };
        $postId = $this->createPost();

        add_action('altimiser_purge_urls', $record, 10, 2);
        $this->applyBlock($postId);
        remove_action('altimiser_purge_urls', $record);

        $this->assertSame([true], $purged);
    }

    public function test_reports_later_posts_of_the_type_as_already_applied(): void
    {
        $this->applyBlock($this->createPost());

        $result = $this->applyBlock($this->createPost());

        $this->assertSame('already_applied', $result['status']);
        $this->assertSame('collection', $result['location']['type']);
    }

    public function test_leaves_an_existing_default_in_place(): void
    {
        $this->applyBlock($this->createPost());

        $result = $this->applyBlock($this->createPost(), '{"@type": "NewsArticle", "headline": "{{ title }}"}');

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('default_present', $result['reason']);
        $this->assertSame(['post' => $this->block], get_option('altimiser_structured_data_defaults'));
    }

    public function test_writes_nothing_on_a_dry_run_but_reports_what_would_happen(): void
    {
        $postId = $this->createPost();
        $pageId = $this->createPost(['post_type' => 'page']);

        $postResult = $this->applyBlock($postId, dryRun: true);
        $pageResult = $this->applyBlock($pageId, dryRun: true);

        $this->assertSame('applied', $postResult['status']);
        $this->assertSame('applied', $pageResult['status']);
        $this->assertFalse(get_option('altimiser_structured_data_defaults'));
        $this->assertSame('', (string) get_post_meta($pageId, '_altimiser_structured_data', true));
    }

    public function test_keeps_backslashes_in_the_stored_block(): void
    {
        $block = '{"@type": "Article", "headline": "{{ title }}", "alternativeHeadline": "Line\\\\nbreak \\"quoted\\""}';
        $pageId = $this->createPost(['post_type' => 'page']);

        $this->applyBlock($pageId, $block);

        $this->assertSame($block, get_post_meta($pageId, '_altimiser_structured_data', true));
    }

    public function test_skips_a_block_that_does_not_render_to_json_for_the_page(): void
    {
        $postId = $this->createPost();

        $conditional = $this->applyBlock($postId, '{"headline": "{{ title }}"{{ if x }}, "a": 1{{ /if }}}');
        $unknown = $this->applyBlock($postId, '{"headline": "{{ alt_seo_meta_description }}"}');
        $broken = $this->applyBlock($postId, '{"headline": "{{ title }}"');

        foreach ([$conditional, $unknown, $broken] as $result) {
            $this->assertSame('skipped', $result['status']);
            $this->assertSame('invalid_markup', $result['reason']);
        }

        $this->assertFalse(get_option('altimiser_structured_data_defaults'));
    }

    public function test_skips_a_change_with_no_markup_or_no_page(): void
    {
        $this->assertSame('no_suggestion', $this->sendOne(['check' => 'structured_data.missing', 'suggested_value' => null])['reason']);
        $this->assertSame('not_found', $this->sendOne([
            'check' => 'structured_data.missing',
            'url' => home_url('/nowhere/'),
            'suggested_value' => $this->block,
        ])['reason']);
    }

    public function test_skips_an_unpublished_page(): void
    {
        $draftId = $this->createPost(['post_status' => 'draft', 'post_name' => 'hidden']);

        $this->assertSame('not_found', $this->sendOne([
            'check' => 'structured_data.missing',
            'url' => home_url('/hidden/'),
            'suggested_value' => $this->block,
        ])['reason']);
        $this->assertSame('', (string) get_post_meta($draftId, '_altimiser_structured_data', true));
    }

    public function test_renders_a_default_with_each_posts_own_values_including_quotes_and_backslashes(): void
    {
        $first = $this->createPost(['post_title' => 'He said "hello" \\ goodbye', 'post_name' => 'first']);
        $second = $this->createPost(['post_title' => 'Plain title', 'post_name' => 'second']);
        $this->applyBlock($first);

        $firstPrinted = $this->printed($first);
        $secondPrinted = $this->printed($second);

        $this->assertSame('He said "hello" \\ goodbye', $firstPrinted['headline']);
        $this->assertSame(get_permalink($first), $firstPrinted['url']);
        $this->assertSame('Plain title', $secondPrinted['headline']);
        $this->assertSame(get_permalink($second), $secondPrinted['url']);
        $this->assertSame('Article', $secondPrinted['@type']);
    }

    public function test_a_post_with_its_own_block_keeps_it_over_the_default(): void
    {
        $post = $this->createPost(['post_title' => 'Own']);
        $other = $this->createPost(['post_title' => 'Other']);
        $this->applyBlock($other);
        update_post_meta($post, '_altimiser_structured_data', '{"@type": "NewsArticle", "headline": "{{ title }} (own)"}');

        $printed = $this->printed($post);

        $this->assertSame('NewsArticle', $printed['@type']);
        $this->assertSame('Own (own)', $printed['headline']);
    }

    public function test_renders_the_date_author_and_site_variables(): void
    {
        $author = self::factory()->user->create(['display_name' => 'Rachel Adams']);
        $postId = $this->createPost(['post_author' => $author, 'post_date' => '2026-03-04 09:30:00', 'post_title' => 'Dated']);
        $this->applyBlock($postId, '{"datePublished": "{{ date_published }}", "dateModified": "{{ date_modified }}", "author": "{{ author_name }}", "image": "{{ featured_image }}", "site": "{{ site_name }} {{ site_url }}", "summary": "{{ excerpt }}"}');

        $printed = $this->printed($postId);

        $this->assertSame(get_post_time('c', false, $postId), $printed['datePublished']);
        $this->assertStringStartsWith('2026-03-04T09:30:00', $printed['datePublished']);
        $this->assertSame(get_post_modified_time('c', false, $postId), $printed['dateModified']);
        $this->assertSame('Rachel Adams', $printed['author']);
        $this->assertSame('', $printed['image']);
        $this->assertSame(get_bloginfo('name').' '.home_url(), $printed['site']);
    }

    public function test_takes_the_meta_description_from_yoast_and_falls_back_to_the_excerpt(): void
    {
        $withYoast = $this->createPost(['post_excerpt' => 'The excerpt.']);
        update_post_meta($withYoast, '_yoast_wpseo_metadesc', 'Written for search engines.');
        $without = $this->createPost(['post_excerpt' => 'The excerpt.']);
        $this->applyBlock($withYoast);

        $this->assertSame('Written for search engines.', $this->printed($withYoast)['description']);
        $this->assertSame('The excerpt.', $this->printed($without)['description']);
    }

    public function test_keeps_the_text_of_a_password_protected_post_out_of_its_markup(): void
    {
        $postId = $this->createPost(['post_password' => 'members', 'post_excerpt' => 'Only for members.', 'post_content' => 'The terms we agreed.']);
        $this->applyBlock($postId, '{"description": "{{ meta_description }}", "excerpt": "{{ excerpt }}"}');

        $this->assertSame(['description' => '', 'excerpt' => ''], $this->printed($postId));
    }

    public function test_cannot_close_the_script_tag_it_is_printed_in(): void
    {
        global $wpdb;
        $author = self::factory()->user->create();
        $wpdb->update($wpdb->users, ['display_name' => '</script><script>alert(1)</script> <!-- &'], ['ID' => $author]);
        clean_user_cache($author);
        $postId = $this->createPost(['post_author' => $author]);
        $this->applyBlock($postId, '{"headline": "{{ author_name }}", "note": "</script><img src=x>"}');

        $this->go_to(get_permalink($postId));
        $head = get_echo('wp_head');
        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $head, $match);

        $this->assertStringNotContainsString('</script', $match[1]);
        $this->assertStringNotContainsString('<script', $match[1]);
        $this->assertSame('</script><script>alert(1)</script> <!-- &', json_decode($match[1], true)['headline']);
        $this->assertSame('</script><img src=x>', json_decode($match[1], true)['note']);
    }

    public function test_does_not_run_a_value_that_looks_like_a_tag(): void
    {
        $postId = $this->createPost(['post_title' => '{{ site_name }}']);
        $this->applyBlock($postId, '{"headline": "{{ title }}"}');

        $this->assertSame('{{ site_name }}', $this->printed($postId)['headline']);
    }

    public function test_prints_nothing_for_a_block_that_is_not_json_once_rendered(): void
    {
        $postId = $this->createPost();

        foreach (['{"headline": "{{ title }}"', '{"headline": {{ title }}}', '"just a string"', '{"headline": "{{ nothing_like_this }}"}', '{{ if x }}{}{{ /if }}'] as $block) {
            update_post_meta($postId, '_altimiser_structured_data', wp_slash($block));

            $this->assertSame([], $this->printedBlocks($postId), $block);
        }
    }

    public function test_prints_nothing_where_there_is_no_block_or_the_page_is_not_a_single_post(): void
    {
        $postId = $this->createPost();
        $this->assertSame([], $this->printedBlocks($postId));

        $this->applyBlock($postId);
        $this->go_to(home_url('/'));

        $this->assertStringNotContainsString('ld+json">{', get_echo('wp_head'));
    }

    public function test_keeps_yoasts_own_graph_beside_the_block(): void
    {
        $postId = $this->createPost();
        $this->applyBlock($postId);
        $this->go_to(get_permalink($postId));

        $this->assertStringContainsString('yoast-schema-graph', get_echo('wp_head'));
    }

    public function test_renders_every_published_post_of_a_type_in_a_list_pages_loop(): void
    {
        foreach (['Ada', 'Grace', 'Edith'] as $name) {
            $this->createPost(['post_type' => 'team', 'post_title' => $name, 'post_name' => strtolower($name)]);
        }

        $this->createPost(['post_type' => 'team', 'post_title' => 'Unpublished', 'post_status' => 'draft']);
        $this->createPost(['post_type' => 'team', 'post_title' => 'Quoted "name" \\', 'post_name' => 'quoted']);
        $listPage = $this->createPost(['post_type' => 'page', 'post_title' => 'Our team', 'post_name' => 'our-team']);

        $this->applyBlock($listPage, <<<'JSON'
{
  "@type": "CollectionPage",
  "name": "{{ title }}",
  "mainEntity": {
    "@type": "ItemList",
    "itemListElement": [
      {{ collection:team }}
      {"@type": "ListItem", "position": {{ count }}, "url": "{{ permalink }}", "name": "{{ title }}"}{{ unless last }},{{ /unless }}
      {{ /collection:team }}
    ]
  }
}
JSON);

        $items = $this->printed($listPage)['mainEntity']['itemListElement'];
        $names = array_column($items, 'name');

        $this->assertCount(4, $items);
        $this->assertEqualsCanonicalizing(['Ada', 'Grace', 'Edith', 'Quoted "name" \\'], $names);
        $this->assertSame([1, 2, 3, 4], array_column($items, 'position'));
        $this->assertSame('Our team', $this->printed($listPage)['name']);

        foreach ($items as $item) {
            $this->assertStringStartsWith(home_url('/team/'), $item['url']);
        }
    }

    public function test_renders_an_empty_list_for_a_loop_over_a_type_with_no_posts_or_that_does_not_exist(): void
    {
        $listPage = $this->createPost(['post_type' => 'page', 'post_name' => 'lists']);
        $loop = '{"items": [{{ collection:HANDLE }}{"name": "{{ title }}"}{{ unless last }},{{ /unless }}{{ /collection:HANDLE }}]}';

        foreach (['team', 'nothing', 'attachment'] as $handle) {
            update_post_meta($listPage, '_altimiser_structured_data', wp_slash(str_replace('HANDLE', $handle, $loop)));

            $this->assertSame([], $this->printed($listPage)['items'], $handle);
        }
    }

    public function test_a_loop_picks_up_a_post_published_after_the_block_was_stored(): void
    {
        $listPage = $this->createPost(['post_type' => 'page', 'post_name' => 'blog-index']);
        $this->createPost(['post_title' => 'Earlier']);
        $this->applyBlock($listPage, '{"items": [{{ collection:post }}"{{ title }}"{{ unless last }},{{ /unless }}{{ /collection:post }}]}');
        $before = count($this->printed($listPage)['items']);

        $this->createPost(['post_title' => 'Later']);

        $this->assertSame($before + 1, count($this->printed($listPage)['items']));
    }

    public function test_a_loop_takes_the_first_fifty_posts_of_a_large_type(): void
    {
        $listPage = $this->createPost(['post_type' => 'page', 'post_name' => 'blog-index']);
        self::factory()->post->create_many(55);

        $this->applyBlock($listPage, '{"items": [{{ collection:post }}"{{ title }}"{{ unless last }},{{ /unless }}{{ /collection:post }}]}');

        $this->assertCount(50, $this->printed($listPage)['items']);
    }

    public function test_refuses_a_loop_inside_a_loop(): void
    {
        $listPage = $this->createPost(['post_type' => 'page', 'post_name' => 'nested']);
        $this->createPost(['post_title' => 'One']);

        $result = $this->applyBlock($listPage, '{"items": [{{ collection:post }}{"n": [{{ collection:post }}1{{ /collection:post }}]}{{ /collection:post }}]}');

        $this->assertSame('invalid_markup', $result['reason']);
    }
}
