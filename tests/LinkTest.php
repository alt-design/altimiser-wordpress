<?php

class LinkTest extends ReceiverTestCase
{
    private int $pageId;

    private string $original;

    public static function set_up_before_class(): void
    {
        parent::set_up_before_class();

        self::configureSecret();
    }

    public function set_up(): void
    {
        parent::set_up();

        $this->set_permalink_structure('/%postname%/');

        $this->original = "<!-- wp:heading -->\n<h2>The restrictive covenant clause</h2>\n<!-- /wp:heading -->\n\n"
            ."<!-- wp:paragraph -->\n<p>Read the restrictive&nbsp;covenant clause before you sign.</p>\n<!-- /wp:paragraph -->\n\n"
            ."<!-- wp:html -->\n<iframe src=\"https://www.youtube-nocookie.com/embed/abc\" title=\"Talk\"></iframe>\n"
            ."<script>var folder = \"C:\\\\clauses\";</script>\n<form action=\"/search\"><input name=\"q\"></form>\n<!-- /wp:html -->";

        kses_remove_filters();

        $this->pageId = self::factory()->post->create([
            'post_type' => 'page',
            'post_name' => 'rachel-adams',
            'post_content' => wp_slash($this->original),
        ]);

        kses_init_filters();
    }

    public function test_links_the_first_mention_outside_a_heading(): void
    {
        $result = $this->sendLink();
        $content = get_post($this->pageId)->post_content;

        $this->assertSame('applied', $result['status']);
        $this->assertSame(['type' => 'post', 'id' => $this->pageId, 'collection' => 'page', 'field' => 'post_content'], $result['location']);
        $this->assertStringContainsString('<p>Read <a href="/insights/restrictive-covenant-clause">the restrictive&nbsp;covenant clause</a> before you sign.</p>', $content);
        $this->assertStringContainsString('<h2>The restrictive covenant clause</h2>', $content);
    }

    public function test_leaves_embeds_scripts_forms_and_backslashes_as_they_were(): void
    {
        $this->sendLink();

        $content = get_post($this->pageId)->post_content;

        $this->assertStringContainsString('<iframe src="https://www.youtube-nocookie.com/embed/abc" title="Talk"></iframe>', $content);
        $this->assertStringContainsString('<script>var folder = "C:\\\\clauses";</script>', $content);
        $this->assertStringContainsString('<form action="/search"><input name="q"></form>', $content);
        $this->assertCount(count(parse_blocks($this->original)), parse_blocks($content));
    }

    public function test_takes_the_link_off_again_on_a_revert(): void
    {
        $this->sendLink();

        $result = $this->sendLink([
            'current_value' => 'https://example.org/insights/restrictive-covenant-clause',
            'suggested_value' => 'The restrictive covenant clause',
        ]);

        $this->assertSame('applied', $result['status']);
        $this->assertSame($this->original, get_post($this->pageId)->post_content);
    }

    public function test_says_a_revert_to_empty_cannot_remove_a_link(): void
    {
        $this->sendLink();

        $result = $this->sendLink(['suggested_value' => '', 'revert' => true]);

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('not_clearable', $result['reason']);
        $this->assertStringContainsString('anchor text', $result['message']);
        $this->assertStringContainsString('<a href=', get_post($this->pageId)->post_content);
    }

    public function test_counts_a_revert_with_no_link_left_to_take_off_as_done(): void
    {
        $result = $this->sendLink(['suggested_value' => 'The restrictive covenant clause']);

        $this->assertSame('already_applied', $result['status']);
        $this->assertSame($this->original, get_post($this->pageId)->post_content);
    }

    public function test_counts_a_mention_already_linked_there_as_done(): void
    {
        $this->setContent('<p>Read <a href="https://example.org/insights/restrictive-covenant-clause/">the restrictive covenant clause</a>.</p>');

        $this->assertSame('already_applied', $this->sendLink()['status']);
    }

    public function test_leaves_a_mention_somebody_linked_elsewhere(): void
    {
        $this->setContent('<p>Read <a href="https://gov.example/covenants">the restrictive covenant clause</a>. The restrictive covenant clause matters.</p>');

        $result = $this->sendLink();

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('already_linked', $result['reason']);
    }

    public function test_matches_whole_words_only(): void
    {
        $this->setContent('<p>The restrictive covenant clauses in this lease.</p>');

        $this->assertSame('mention_not_found', $this->sendLink()['reason']);
    }

    public function test_reports_words_the_page_does_not_contain(): void
    {
        $this->setContent('<p>Nothing about covenants here.</p>');

        $result = $this->sendLink();

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('mention_not_found', $result['reason']);
    }

    public function test_links_a_mention_in_an_acf_editor_field_inside_a_repeater(): void
    {
        $this->setContent('<p>Nothing about covenants here.</p>');
        $this->registerSections();
        $this->storeMeta([
            'sections' => '1',
            '_sections' => 'field_altimiser_sections',
            'sections_0_body' => '<p>The restrictive covenant clause, explained.</p>',
            '_sections_0_body' => 'field_altimiser_body',
        ]);

        $result = $this->sendLink();

        $this->assertSame('applied', $result['status']);
        $this->assertSame('sections', $result['location']['field']);
        $this->assertSame(
            '<p><a href="/insights/restrictive-covenant-clause">The restrictive covenant clause</a>, explained.</p>',
            get_post_meta($this->pageId, 'sections_0_body', true),
        );
        $this->assertEqualsCanonicalizing(['sections', '_sections', 'sections_0_body', '_sections_0_body'], array_keys($this->metaRows()));
    }

    public function test_finds_a_mention_stored_with_entities_or_typed_with_straight_quotes(): void
    {
        $this->setContent("<p>Read our Terms &amp; Conditions and the children's guide.</p>");
        $mentionOf = static fn (string $anchor, string $href): array => [
            'target' => ['selector' => "a[href=\"{$href}\"]", 'attribute' => 'href', 'anchor' => $anchor, 'href' => $href],
            'current_value' => $anchor,
            'suggested_value' => home_url($href),
        ];

        $terms = $this->sendLink($mentionOf('Terms & Conditions', '/terms'));
        $guide = $this->sendLink($mentionOf('children’s guide', '/guide'));

        $this->assertSame(['applied', 'applied'], [$terms['status'], $guide['status']]);
        $this->assertSame(
            "<p>Read our <a href=\"/terms\">Terms &amp; Conditions</a> and the <a href=\"/guide\">children's guide</a>.</p>",
            get_post($this->pageId)->post_content,
        );
    }

    public function test_counts_a_link_written_inside_an_acf_group_as_written(): void
    {
        $this->setContent('<p>Nothing about covenants here.</p>');
        acf_add_local_field_group([
            'key' => 'group_altimiser_intro',
            'title' => 'Introduction',
            'fields' => [[
                'key' => 'field_altimiser_intro',
                'name' => 'intro',
                'type' => 'group',
                'sub_fields' => [['key' => 'field_altimiser_intro_text', 'name' => 'text', 'type' => 'wysiwyg']],
            ]],
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'page']]],
        ]);
        update_field('field_altimiser_intro', ['field_altimiser_intro_text' => '<p>The restrictive covenant clause, explained.</p>'], $this->pageId);

        $result = $this->sendLink();

        $this->assertSame('applied', $result['status']);
        $this->assertSame('intro', $result['location']['field']);
        $this->assertSame(
            '<p><a href="/insights/restrictive-covenant-clause">The restrictive covenant clause</a>, explained.</p>',
            get_field('intro', $this->pageId, false)['field_altimiser_intro_text'],
        );
    }

    public function test_links_one_sub_field_of_flexible_content_and_adds_no_meta(): void
    {
        $this->setContent('<p>Nothing about covenants here.</p>');
        $this->seedFlexibleContent();
        $before = $this->metaRows();
        get_field('flexible_content', $this->pageId, false);

        $result = $this->sendLink();
        $after = $this->metaRows();

        $this->assertSame('applied', $result['status']);
        $this->assertSame('flexible_content', $result['location']['field']);
        $this->assertSame(['flexible_content_1_body'], array_keys(array_diff_assoc($after, $before)));
        $this->assertSame(array_keys($before), array_keys($after));
        $this->assertSame(
            '<p>Café <a href="/insights/restrictive-covenant-clause">The restrictive covenant clause</a> résumé, 東京.</p>',
            get_post_meta($this->pageId, 'flexible_content_1_body', true),
        );
        $this->assertSame(
            '<p>Café <a href="/insights/restrictive-covenant-clause">The restrictive covenant clause</a> résumé, 東京.</p>',
            get_field('flexible_content', $this->pageId, false)[1]['field_altimiser_fc_body'],
        );
    }

    public function test_reverting_a_flexible_content_link_restores_the_meta_exactly(): void
    {
        $this->setContent('<p>Nothing about covenants here.</p>');
        $this->seedFlexibleContent();
        $before = $this->metaRows();

        $this->sendLink();
        $revert = $this->sendLink([
            'current_value' => 'https://example.org/insights/restrictive-covenant-clause',
            'suggested_value' => 'The restrictive covenant clause',
        ]);

        $this->assertSame('applied', $revert['status']);
        $this->assertSame($before, $this->metaRows());
    }

    public function test_writes_to_the_stored_row_when_an_earlier_layout_is_not_loaded(): void
    {
        $this->setContent('<p>Nothing about covenants here.</p>');
        $this->seedFlexibleContent();
        $this->storeMeta([
            'flexible_content' => ['hidden', 'text', 'text'],
            'flexible_content_0_body' => '<p>Not loaded.</p>',
            '_flexible_content_0_body' => 'field_altimiser_fc_body',
            'flexible_content_1_body' => '<p>Row one.</p>',
            'flexible_content_2_body' => '<p>The restrictive covenant clause in row two.</p>',
            '_flexible_content_2_body' => 'field_altimiser_fc_body',
            'flexible_content_2_show_table' => '0',
            '_flexible_content_2_show_table' => 'field_altimiser_fc_show',
        ]);
        $before = $this->metaRows();

        $this->assertSame('applied', $this->sendLink()['status']);
        $after = $this->metaRows();

        $this->assertSame(['flexible_content_2_body'], array_keys(array_diff_assoc($after, $before)));
        $this->assertSame('<p>Row one.</p>', get_post_meta($this->pageId, 'flexible_content_1_body', true));

        $this->sendLink([
            'current_value' => 'https://example.org/insights/restrictive-covenant-clause',
            'suggested_value' => 'The restrictive covenant clause',
        ]);

        $this->assertSame($before, $this->metaRows());
    }

    /**
     * @return array<string, mixed>
     */
    private function metaRows(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare("SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_key", $this->pageId), ARRAY_A);

        return array_column($rows, 'meta_value', 'meta_key');
    }

    private function seedFlexibleContent(): void
    {
        acf_add_local_field_group([
            'key' => 'group_altimiser_flexible',
            'title' => 'Flexible',
            'fields' => [[
                'key' => 'field_altimiser_fc',
                'name' => 'flexible_content',
                'type' => 'flexible_content',
                'layouts' => [
                    'layout_altimiser_hidden' => [
                        'key' => 'layout_altimiser_hidden',
                        'name' => 'hidden',
                        'label' => 'Hidden',
                        'sub_fields' => [],
                    ],
                    'layout_altimiser_text' => [
                        'key' => 'layout_altimiser_text',
                        'name' => 'text',
                        'label' => 'Text',
                        'sub_fields' => [
                            ['key' => 'field_altimiser_fc_show', 'name' => 'show_table', 'type' => 'true_false'],
                            ['key' => 'field_altimiser_fc_body', 'name' => 'body', 'type' => 'wysiwyg'],
                            [
                                'key' => 'field_altimiser_fc_table',
                                'name' => 'table',
                                'type' => 'wysiwyg',
                                'conditional_logic' => [[['field' => 'field_altimiser_fc_show', 'operator' => '==', 'value' => '1']]],
                            ],
                        ],
                    ],
                ],
            ]],
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'page']]],
        ]);

        $meta = [
            'flexible_content' => ['text', 'text'],
            '_flexible_content' => 'field_altimiser_fc',
            'flexible_content_0_show_table' => '0',
            '_flexible_content_0_show_table' => 'field_altimiser_fc_show',
            'flexible_content_0_body' => '<p>Intro.</p>',
            '_flexible_content_0_body' => 'field_altimiser_fc_body',
            'flexible_content_1_show_table' => '0',
            '_flexible_content_1_show_table' => 'field_altimiser_fc_show',
            'flexible_content_1_body' => '<p>Café The restrictive covenant clause résumé, 東京.</p>',
            '_flexible_content_1_body' => 'field_altimiser_fc_body',
        ];

        $this->storeMeta($meta);
    }

    /**
     * @param  array<string, string|array<int, string>>  $meta
     */
    private function storeMeta(array $meta): void
    {
        foreach ($meta as $key => $value) {
            update_post_meta($this->pageId, $key, $value);
        }

        if (! has_filter('acf/load_value/type=repeater')) {
            $this->emulateRowLoading();
        }
    }

    private function emulateRowLoading(): void
    {
        $rows = function (mixed $value, int $postId, array $field): array {
            $layouts = $field['type'] === 'flexible_content' ? $value : array_fill(0, (int) $value, null);
            $loaded = [];

            foreach ((array) $layouts as $index => $layout) {
                if ($layout === 'hidden') {
                    continue;
                }

                $subFields = $layout === null ? $field['sub_fields'] : $this->layoutSubFields($field, $layout);
                $row = $layout === null ? [] : ['acf_fc_layout' => $layout];

                foreach ($subFields as $subField) {
                    $stored = get_post_meta($postId, "{$field['name']}_{$index}_{$subField['name']}");

                    if ($stored !== []) {
                        $row[$subField['key']] = $stored[0];
                    }
                }

                $loaded[$index] = $row;
            }

            return $loaded;
        };

        add_filter('acf/load_value/type=repeater', $rows, 10, 3);
        add_filter('acf/load_value/type=flexible_content', $rows, 10, 3);
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<int, array<string, mixed>>
     */
    private function layoutSubFields(array $field, string $name): array
    {
        foreach ($field['layouts'] as $layout) {
            if ($layout['name'] === $name) {
                return $layout['sub_fields'];
            }
        }

        return [];
    }

    public function test_leaves_a_page_built_with_elementor_alone(): void
    {
        update_post_meta($this->pageId, '_elementor_edit_mode', 'builder');

        $result = $this->sendLink();

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('page_builder', $result['reason']);
        $this->assertSame($this->original, get_post($this->pageId)->post_content);
    }

    public function test_refuses_a_destination_that_is_not_a_page_on_this_site(): void
    {
        foreach (['javascript:alert(1)', '//other.example/covenants', '/\\other.example/covenants', 'https://other.example/covenants', "/insights\n/covenants"] as $href) {
            $result = $this->sendLink(['target' => [...$this->linkChange()['target'], 'href' => $href]]);

            $this->assertSame('invalid_change', $result['reason'], $href);
        }

        $this->assertSame($this->original, get_post($this->pageId)->post_content);
    }

    public function test_links_to_a_full_address_on_this_site(): void
    {
        $result = $this->sendLink(['target' => [...$this->linkChange()['target'], 'href' => home_url('/insights/restrictive-covenant-clause/')]]);

        $this->assertSame('applied', $result['status']);
        $this->assertStringContainsString('<a href="'.home_url('/insights/restrictive-covenant-clause/').'">the restrictive', get_post($this->pageId)->post_content);
    }

    public function test_passes_over_shortcodes_quoted_attributes_and_summaries(): void
    {
        $this->setContent(
            '[gallery title="The restrictive covenant clause"]'
            .'<img alt="a > b" title="The restrictive covenant clause" src="/a.jpg">'
            .'<details><summary>The restrictive covenant clause</summary></details>'
            .'<p>The restrictive covenant clause applies.</p>',
        );

        $this->assertSame('applied', $this->sendLink()['status']);
        $this->assertSame(
            '[gallery title="The restrictive covenant clause"]'
            .'<img alt="a > b" title="The restrictive covenant clause" src="/a.jpg">'
            .'<details><summary>The restrictive covenant clause</summary></details>'
            .'<p><a href="/insights/restrictive-covenant-clause">The restrictive covenant clause</a> applies.</p>',
            get_post($this->pageId)->post_content,
        );
    }

    public function test_leaves_a_page_built_with_divi_or_with_another_builders_shortcodes_alone(): void
    {
        update_post_meta($this->pageId, '_et_pb_use_builder', 'on');

        $this->assertSame('page_builder', $this->sendLink()['reason']);

        delete_post_meta($this->pageId, '_et_pb_use_builder');
        $this->setContent('[vc_row][vc_column_text]<p>The restrictive covenant clause applies.</p>[/vc_column_text][/vc_row]');

        $this->assertSame('page_builder', $this->sendLink()['reason']);
    }

    public function test_skips_a_link_change_with_no_words_to_link(): void
    {
        $result = $this->sendLink(['target' => ['selector' => 'a', 'attribute' => 'href', 'href' => '/insights/restrictive-covenant-clause']]);

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('invalid_change', $result['reason']);
    }

    public function test_writes_nothing_on_a_dry_run(): void
    {
        $result = $this->send([$this->linkChange()], true)[0];

        $this->assertSame('applied', $result['status']);
        $this->assertSame($this->original, get_post($this->pageId)->post_content);
    }

    private function setContent(string $content): void
    {
        kses_remove_filters();
        wp_update_post(wp_slash(['ID' => $this->pageId, 'post_content' => $content]));
        kses_init_filters();
    }

    private function registerSections(): void
    {
        acf_add_local_field_group([
            'key' => 'group_altimiser_sections',
            'title' => 'Sections',
            'fields' => [[
                'key' => 'field_altimiser_sections',
                'name' => 'sections',
                'type' => 'repeater',
                'sub_fields' => [[
                    'key' => 'field_altimiser_body',
                    'name' => 'body',
                    'type' => 'wysiwyg',
                ]],
            ]],
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'page']]],
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function linkChange(array $overrides = []): array
    {
        return $this->change([
            'check' => 'link.opportunity',
            'url' => home_url('/rachel-adams/'),
            'target' => [
                'selector' => 'a[href="https://example.org/insights/restrictive-covenant-clause"]',
                'attribute' => 'href',
                'anchor' => 'The restrictive covenant clause',
                'href' => '/insights/restrictive-covenant-clause',
            ],
            'current_value' => 'The restrictive covenant clause',
            'suggested_value' => 'https://example.org/insights/restrictive-covenant-clause',
            ...$overrides,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function sendLink(array $overrides = []): array
    {
        return $this->send([$this->linkChange($overrides)])[0];
    }
}
