<?php

class YoastFieldsTest extends ReceiverTestCase
{
    private int $pageId;

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

        $this->pageId = self::factory()->post->create([
            'post_type' => 'page',
            'post_name' => 'about',
            'post_title' => 'About',
        ]);
    }

    public function tear_down(): void
    {
        WPSEO_Options::clear_cache();

        parent::tear_down();
    }

    private function storedDescription(): string
    {
        return get_post_meta($this->pageId, '_yoast_wpseo_metadesc', true);
    }

    private function storeDescription(string $description): void
    {
        update_post_meta($this->pageId, '_yoast_wpseo_metadesc', $description);
    }

    public function test_writes_a_description_to_yoasts_field_and_reports_both_values(): void
    {
        $this->storeDescription('About us.');

        $result = $this->sendOne();

        $this->assertSame('applied', $result['status']);
        $this->assertSame('About us.', $result['from']);
        $this->assertSame('How our studio works, and who we build for.', $result['to']);
        $this->assertSame('post', $result['location']['type']);
        $this->assertSame($this->pageId, $result['location']['id']);
        $this->assertSame('page', $result['location']['collection']);
        $this->assertSame('_yoast_wpseo_metadesc', $result['location']['field']);
        $this->assertSame('How our studio works, and who we build for.', $this->storedDescription());
    }

    public function test_writes_a_meta_title_to_yoasts_field_and_never_to_the_page_title(): void
    {
        update_post_meta($this->pageId, '_yoast_wpseo_title', 'About');

        $result = $this->sendOne([
            'check' => 'title.too_short',
            'current_value' => 'About',
            'suggested_value' => 'About our studio',
        ]);

        $this->assertSame('applied', $result['status']);
        $this->assertSame('About our studio', get_post_meta($this->pageId, '_yoast_wpseo_title', true));
        $this->assertSame('About', get_post($this->pageId)->post_title);
    }

    public function test_writes_nothing_on_a_dry_run_but_reports_what_would_happen(): void
    {
        $this->storeDescription('About us.');

        $result = $this->sendOne(dryRun: true);

        $this->assertSame('applied', $result['status']);
        $this->assertSame('About us.', $result['from']);
        $this->assertSame('How our studio works, and who we build for.', $result['to']);
        $this->assertSame('About us.', $this->storedDescription());
    }

    public function test_still_runs_the_guards_on_a_dry_run(): void
    {
        $this->storeDescription('An editor wrote this.');

        $this->assertSame('value_changed', $this->sendOne(dryRun: true)['reason']);
    }

    public function test_leaves_a_field_an_editor_has_changed_since_the_scan(): void
    {
        $this->storeDescription('An editor wrote this.');

        $result = $this->sendOne();

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('value_changed', $result['reason']);
        $this->assertSame('An editor wrote this.', $this->storedDescription());
    }

    public function test_leaves_a_field_filled_in_since_a_scan_that_found_it_empty(): void
    {
        $this->storeDescription('An editor wrote this.');

        $result = $this->sendOne(['check' => 'meta_description.missing', 'current_value' => null]);

        $this->assertSame('value_changed', $result['reason']);
        $this->assertSame('An editor wrote this.', $this->storedDescription());
    }

    public function test_fills_a_field_the_scan_found_empty(): void
    {
        $result = $this->sendOne(['check' => 'meta_description.missing', 'current_value' => null]);

        $this->assertSame('applied', $result['status']);
        $this->assertArrayNotHasKey('from', $result);
        $this->assertFalse($result['location']['inherited']);
        $this->assertSame('How our studio works, and who we build for.', $this->storedDescription());
    }

    public function test_compares_ignoring_differences_in_whitespace_and_entities(): void
    {
        $this->storeDescription('Fish &amp;  chips');

        $this->assertSame('applied', $this->sendOne(['current_value' => 'Fish & chips'])['status']);
    }

    public function test_overrides_a_shared_template_for_this_page_and_leaves_the_template_alone(): void
    {
        WPSEO_Options::set('metadesc-page', '%%title%% from %%sitename%%');

        $result = $this->sendOne(['current_value' => 'About from '.get_bloginfo('name')]);

        $this->assertSame('applied', $result['status']);
        $this->assertTrue($result['location']['inherited']);
        $this->assertFalse($result['location']['flattened_template']);
        $this->assertSame('How our studio works, and who we build for.', $this->storedDescription());
        $this->assertSame('%%title%% from %%sitename%%', WPSEO_Options::get('metadesc-page'));
    }

    public function test_falls_back_to_yoasts_default_title_template_as_the_page_does(): void
    {
        WPSEO_Options::set('title-page', '');

        $result = $this->sendOne([
            'check' => 'title.too_short',
            'current_value' => 'About - '.get_bloginfo('name'),
            'suggested_value' => 'About our studio',
        ]);

        $this->assertSame('applied', $result['status']);
        $this->assertTrue($result['location']['inherited']);
    }

    public function test_decodes_a_separator_stored_as_an_entity_before_comparing(): void
    {
        WPSEO_Options::set('separator', 'sc-ndash');

        $result = $this->sendOne([
            'check' => 'title.too_short',
            'current_value' => 'About – '.get_bloginfo('name'),
            'suggested_value' => 'About our studio',
        ]);

        $this->assertSame('applied', $result['status']);
    }

    public function test_refuses_to_guess_when_the_template_does_not_produce_what_the_scan_saw(): void
    {
        WPSEO_Options::set('metadesc-page', '%%title%% from %%sitename%%');

        $result = $this->sendOne(['current_value' => 'Printed by something else']);

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('ambiguous', $result['reason']);
        $this->assertSame('', $this->storedDescription());
    }

    public function test_refuses_to_guess_when_yoast_would_print_nothing_at_all(): void
    {
        $this->assertSame('ambiguous', $this->sendOne()['reason']);
    }

    public function test_says_so_when_writing_a_literal_replaces_a_page_level_pattern(): void
    {
        $this->storeDescription('%%title%% from us');

        $result = $this->sendOne(['current_value' => 'About from us']);

        $this->assertSame('applied', $result['status']);
        $this->assertTrue($result['location']['flattened_template']);
        $this->assertFalse($result['location']['inherited']);
        $this->assertSame('%%title%% from us', $result['from']);
    }

    public function test_keeps_backslashes_that_saving_would_strip(): void
    {
        $this->storeDescription('About us.');

        $this->sendOne(['suggested_value' => 'Path C:\\sites\\about']);

        $this->assertSame('Path C:\\sites\\about', $this->storedDescription());
    }

    public function test_reports_what_yoast_actually_stored_after_sanitising(): void
    {
        $this->storeDescription('About us.');

        $result = $this->sendOne(['suggested_value' => '<b>Bold</b> words']);

        $this->assertSame('Bold words', $result['to']);
    }

    public function test_leaves_the_field_alone_when_the_suggestion_is_only_markup(): void
    {
        $this->storeDescription('About us.');

        $this->assertSame('no_suggestion', $this->sendOne(['suggested_value' => '<script></script>'])['reason']);
        $this->assertSame('About us.', $this->storedDescription());
    }

    public function test_reports_a_value_that_came_out_empty_as_failed(): void
    {
        add_filter('sanitize_post_meta__yoast_wpseo_metadesc', '__return_empty_string', 20);

        $result = $this->sendOne(['check' => 'meta_description.missing', 'current_value' => null]);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('write_failed', $result['reason']);
    }

    public function test_answers_a_resend_as_already_applied(): void
    {
        $this->storeDescription('About us.');

        $this->sendOne();

        $result = $this->sendOne();

        $this->assertSame('already_applied', $result['status']);
        $this->assertSame('_yoast_wpseo_metadesc', $result['location']['field']);
    }

    public function test_answers_as_already_applied_when_the_template_now_produces_the_value(): void
    {
        WPSEO_Options::set('metadesc-page', 'How our %%title%%');

        $this->assertSame('already_applied', $this->sendOne(['suggested_value' => 'How our About'])['status']);
    }

    public function test_skips_a_change_with_nothing_to_write(): void
    {
        $this->storeDescription('About us.');

        foreach ([null, '   '] as $nothing) {
            $result = $this->sendOne(['suggested_value' => $nothing]);

            $this->assertSame('skipped', $result['status']);
            $this->assertSame('no_suggestion', $result['reason']);
        }

        $this->assertSame('About us.', $this->storedDescription());
    }

    public function test_puts_a_literal_value_back_on_a_revert(): void
    {
        $this->storeDescription('About us.');

        $applied = $this->sendOne();

        $reverted = $this->sendOne([
            'current_value' => $applied['to'],
            'suggested_value' => $applied['from'],
        ]);

        $this->assertSame('applied', $reverted['status']);
        $this->assertSame('About us.', $this->storedDescription());
    }

    public function test_refuses_a_revert_once_an_editor_has_moved_on(): void
    {
        $this->storeDescription('An editor wrote this.');

        $result = $this->sendOne([
            'current_value' => 'How our studio works, and who we build for.',
            'suggested_value' => 'About us.',
        ]);

        $this->assertSame('value_changed', $result['reason']);
        $this->assertSame('An editor wrote this.', $this->storedDescription());
    }

    public function test_removes_the_override_on_a_revert_so_the_page_inherits_again(): void
    {
        WPSEO_Options::set('metadesc-page', '%%title%% from %%sitename%%');

        $scanned = 'About from '.get_bloginfo('name');

        $this->sendOne(['current_value' => $scanned]);

        $reverted = $this->sendOne([
            'current_value' => 'How our studio works, and who we build for.',
            'suggested_value' => $scanned,
        ]);

        $this->assertSame('applied', $reverted['status']);
        $this->assertSame($scanned, $reverted['to']);
        $this->assertFalse(metadata_exists('post', $this->pageId, '_yoast_wpseo_metadesc'));
    }

    public function test_restores_a_page_level_pattern_on_a_revert(): void
    {
        $this->storeDescription('%%title%% from us');

        $applied = $this->sendOne(['current_value' => 'About from us']);

        $this->sendOne(['current_value' => $applied['to'], 'suggested_value' => $applied['from']]);

        $this->assertSame('%%title%% from us', $this->storedDescription());
    }

    public function test_reverts_a_value_it_wrote_with_variables_in_it(): void
    {
        $this->storeDescription('About us.');

        $applied = $this->sendOne(['suggested_value' => '%%title%% from us']);

        $reverted = $this->sendOne(['current_value' => $applied['to'], 'suggested_value' => $applied['from']]);

        $this->assertSame('applied', $reverted['status']);
        $this->assertSame('About us.', $this->storedDescription());
    }

    public function test_leaves_a_missing_canonical_to_a_person(): void
    {
        $result = $this->sendOne([
            'check' => 'canonical.missing',
            'target' => ['selector' => 'link[rel="canonical"]', 'attribute' => 'href'],
            'current_value' => null,
            'suggested_value' => home_url('/about/'),
        ]);

        $this->assertSame('unsupported_check', $result['reason']);
        $this->assertSame('', get_post_meta($this->pageId, '_yoast_wpseo_canonical', true));
    }

    public function test_writes_each_social_tag_to_its_own_field(): void
    {
        $this->sendOne([
            'check' => 'open_graph.missing',
            'target' => ['selector' => 'meta[property="og:title"]', 'attribute' => 'content'],
            'current_value' => null,
            'suggested_value' => 'Social title',
        ]);

        $this->sendOne([
            'check' => 'open_graph.missing',
            'target' => ['selector' => 'meta[property="og:description"]', 'attribute' => 'content'],
            'current_value' => null,
            'suggested_value' => 'Social description',
        ]);

        $this->assertSame('Social title', get_post_meta($this->pageId, '_yoast_wpseo_opengraph-title', true));
        $this->assertSame('Social description', get_post_meta($this->pageId, '_yoast_wpseo_opengraph-description', true));
    }

    public function test_has_nothing_to_offer_for_a_social_image(): void
    {
        $change = [
            'check' => 'open_graph.missing',
            'target' => ['selector' => 'meta[property="og:image"]', 'attribute' => 'content'],
            'current_value' => null,
        ];

        $this->assertSame('no_suggestion', $this->sendOne([...$change, 'suggested_value' => null])['reason']);
        $this->assertSame('no_field', $this->sendOne([...$change, 'suggested_value' => 'https://example.org/a.png'])['reason']);
    }

    public function test_resolves_the_front_page(): void
    {
        update_option('show_on_front', 'page');
        update_option('page_on_front', $this->pageId);

        $this->storeDescription('About us.');

        $result = $this->sendOne(['url' => home_url('/')]);

        $this->assertSame($this->pageId, $result['location']['id']);
    }

    public function test_resolves_a_custom_post_type(): void
    {
        register_post_type('service', ['public' => true, 'rewrite' => ['slug' => 'services']]);

        flush_rewrite_rules();

        $serviceId = self::factory()->post->create(['post_type' => 'service', 'post_name' => 'audit', 'post_title' => 'Audit']);

        update_post_meta($serviceId, '_yoast_wpseo_metadesc', 'About us.');

        $result = $this->sendOne(['url' => get_permalink($serviceId)]);

        _unregister_post_type('service');

        $this->assertSame($serviceId, $result['location']['id']);
        $this->assertSame('service', $result['location']['collection']);
    }

    public function test_writes_a_term_value_to_yoasts_option_without_disturbing_its_other_fields(): void
    {
        $term = self::factory()->category->create_and_get(['name' => 'News', 'slug' => 'news']);

        WPSEO_Taxonomy_Meta::set_values($term->term_id, 'category', [
            'wpseo_desc' => 'About us.',
            'wpseo_title' => 'Kept title',
            'wpseo_canonical' => 'https://example.org/kept',
            'wpseo_opengraph-title' => 'Kept social title',
            'wpseo_noindex' => 'noindex',
        ]);

        $result = $this->sendOne(['url' => get_term_link($term)]);

        $this->assertSame('applied', $result['status']);
        $this->assertSame('term', $result['location']['type']);
        $this->assertSame($term->term_id, $result['location']['id']);
        $this->assertSame('category', $result['location']['taxonomy']);
        $this->assertSame('wpseo_desc', $result['location']['field']);
        $this->assertSame('How our studio works, and who we build for.', WPSEO_Taxonomy_Meta::get_term_meta($term, 'category', 'desc'));
        $this->assertSame('Kept title', WPSEO_Taxonomy_Meta::get_term_meta($term, 'category', 'title'));
        $this->assertSame('https://example.org/kept', WPSEO_Taxonomy_Meta::get_term_meta($term, 'category', 'canonical'));
        $this->assertSame('Kept social title', WPSEO_Taxonomy_Meta::get_term_meta($term, 'category', 'opengraph-title'));
        $this->assertSame('noindex', WPSEO_Taxonomy_Meta::get_term_meta($term, 'category', 'noindex'));
    }

    public function test_keeps_a_terms_other_fields_when_removing_an_override(): void
    {
        $term = self::factory()->category->create_and_get(['name' => 'News', 'slug' => 'news']);

        WPSEO_Options::set('metadesc-tax-category', '%%term_title%% from %%sitename%%');
        WPSEO_Taxonomy_Meta::set_values($term->term_id, 'category', [
            'wpseo_desc' => 'Written by hand',
            'wpseo_title' => 'Kept title',
        ]);

        $result = $this->sendOne([
            'url' => get_term_link($term),
            'current_value' => 'Written by hand',
            'suggested_value' => 'News from '.get_bloginfo('name'),
        ]);

        $this->assertSame('applied', $result['status']);
        $this->assertSame('', WPSEO_Taxonomy_Meta::get_term_meta($term, 'category', 'desc'));
        $this->assertSame('Kept title', WPSEO_Taxonomy_Meta::get_term_meta($term, 'category', 'title'));
    }

    public function test_rebuilds_what_a_terms_page_prints(): void
    {
        global $wpdb;

        $term = self::factory()->category->create_and_get(['name' => 'News', 'slug' => 'news']);

        WPSEO_Taxonomy_Meta::set_values($term->term_id, 'category', ['wpseo_desc' => 'About us.']);

        $this->sendOne(['url' => get_term_link($term)]);

        $this->assertSame('How our studio works, and who we build for.', $wpdb->get_var($wpdb->prepare(
            "SELECT description FROM {$wpdb->prefix}yoast_indexable WHERE object_type = 'term' AND object_id = %d",
            $term->term_id,
        )));
    }

    public function test_does_not_tell_other_plugins_a_term_has_been_edited(): void
    {
        $term = self::factory()->category->create_and_get(['name' => 'News', 'slug' => 'news']);

        WPSEO_Taxonomy_Meta::set_values($term->term_id, 'category', ['wpseo_desc' => 'About us.']);

        $editedBefore = did_action('edited_term');

        $this->sendOne(['url' => get_term_link($term)]);

        $this->assertSame($editedBefore, did_action('edited_term'));
    }

    public function test_overrides_a_term_template_as_it_does_for_a_page(): void
    {
        $term = self::factory()->category->create_and_get(['name' => 'News', 'slug' => 'news']);

        WPSEO_Options::set('metadesc-tax-category', '%%term_title%% from %%sitename%%');

        $result = $this->sendOne([
            'url' => get_term_link($term),
            'current_value' => 'News from '.get_bloginfo('name'),
        ]);

        $this->assertTrue($result['location']['inherited']);
        $this->assertSame('How our studio works, and who we build for.', WPSEO_Taxonomy_Meta::get_term_meta($term, 'category', 'desc'));
    }

    public function test_guards_a_term_value_an_editor_has_changed(): void
    {
        $term = self::factory()->category->create_and_get(['name' => 'News', 'slug' => 'news']);

        WPSEO_Taxonomy_Meta::set_values($term->term_id, 'category', ['wpseo_desc' => 'An editor wrote this.']);

        $this->assertSame('value_changed', $this->sendOne(['url' => get_term_link($term)])['reason']);
    }

    public function test_keeps_backslashes_in_a_term_value(): void
    {
        $term = self::factory()->category->create_and_get(['name' => 'News', 'slug' => 'news']);

        WPSEO_Taxonomy_Meta::set_values($term->term_id, 'category', ['wpseo_desc' => 'About us.']);

        $this->sendOne(['url' => get_term_link($term), 'suggested_value' => 'Path C:\\sites\\news']);

        $this->assertSame('Path C:\\sites\\news', WPSEO_Taxonomy_Meta::get_term_meta($term, 'category', 'desc'));
    }

    public function test_does_not_mistake_a_slug_in_another_place_for_a_term(): void
    {
        self::factory()->category->create(['name' => 'News', 'slug' => 'news']);

        $this->assertSame('not_found', $this->sendOne(['url' => home_url('/somewhere/else/news/')])['reason']);
    }

    public function test_leaves_markup_in_a_terms_own_description_alone(): void
    {
        global $wpdb;

        $term = self::factory()->category->create_and_get(['name' => 'News', 'slug' => 'news']);
        $description = '<iframe src="https://example.org/map"></iframe><p>Where we work.</p>';

        $wpdb->update($wpdb->term_taxonomy, ['description' => $description], ['term_id' => $term->term_id]);
        clean_term_cache($term->term_id, 'category');
        WPSEO_Taxonomy_Meta::set_values($term->term_id, 'category', ['wpseo_desc' => 'About us.']);

        $this->assertSame('applied', $this->sendOne(['url' => get_term_link($term)])['status']);
        $this->assertSame($description, get_term($term->term_id)->description);
    }

    public function test_reports_a_write_something_else_blocked_as_failed(): void
    {
        $this->storeDescription('About us.');

        add_filter('update_post_metadata', '__return_true');

        $result = $this->sendOne();

        $this->assertSame('failed', $result['status']);
        $this->assertSame('write_failed', $result['reason']);
    }

    /**
     * @dataProvider statusesAScanCannotSee
     */
    public function test_finds_nothing_for_a_page_a_scan_could_not_have_seen(string $status): void
    {
        $hiddenId = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => $status,
            'post_name' => 'hidden',
            'post_title' => 'Hidden',
            'post_date' => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS),
        ]);

        update_post_meta($hiddenId, '_yoast_wpseo_metadesc', 'About us.');

        $this->assertSame('not_found', $this->sendOne(['url' => home_url("/?page_id={$hiddenId}")])['reason']);
        $this->assertSame('not_found', $this->sendOne(['url' => home_url('/hidden/')])['reason']);
        $this->assertSame('About us.', get_post_meta($hiddenId, '_yoast_wpseo_metadesc', true));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function statusesAScanCannotSee(): array
    {
        return ['draft' => ['draft'], 'pending' => ['pending'], 'private' => ['private'], 'future' => ['future'], 'trash' => ['trash']];
    }

    public function test_finds_nothing_for_a_revision(): void
    {
        wp_update_post(['ID' => $this->pageId, 'post_content' => 'First version.']);
        wp_update_post(['ID' => $this->pageId, 'post_content' => 'Second version.']);

        $revisions = wp_get_post_revisions($this->pageId);

        $this->assertNotEmpty($revisions);
        $this->assertSame('not_found', $this->sendOne(['url' => home_url('/?p='.array_key_first($revisions))])['reason']);
    }

    /**
     * @dataProvider addressesThatAreNotThePageItself
     */
    public function test_does_not_write_to_a_page_for_an_address_beneath_it(string $suffix): void
    {
        $this->storeDescription('About us.');

        $this->assertSame('not_found', $this->sendOne(['url' => home_url("/about/{$suffix}")])['reason']);
        $this->assertSame('About us.', $this->storedDescription());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function addressesThatAreNotThePageItself(): array
    {
        return ['a later page' => ['2/'], 'its feed' => ['feed/'], 'its embed' => ['embed/']];
    }

    public function test_resolves_a_page_whichever_way_its_address_is_written(): void
    {
        $pageId = self::factory()->post->create(['post_type' => 'page', 'post_name' => 'новости', 'post_title' => 'Новости']);
        $encoded = rawurlencode('новости');

        update_post_meta($pageId, '_yoast_wpseo_metadesc', 'About us.');

        foreach (['/новости/', "/{$encoded}/", '/'.strtolower($encoded).'/', '/'.strtolower($encoded)] as $address) {
            $result = $this->sendOne(['url' => home_url($address)], dryRun: true);

            $this->assertSame($pageId, $result['location']['id'] ?? null, $address);
        }
    }

    public function test_resolves_a_term_whichever_way_its_address_is_written(): void
    {
        $term = self::factory()->category->create_and_get(['name' => 'Новости', 'slug' => 'новости']);
        $encoded = rawurlencode('новости');

        WPSEO_Taxonomy_Meta::set_values($term->term_id, 'category', ['wpseo_desc' => 'About us.']);

        foreach (['/category/новости/', "/category/{$encoded}/", '/category/'.strtolower($encoded).'/'] as $address) {
            $result = $this->sendOne(['url' => home_url($address)], dryRun: true);

            $this->assertSame($term->term_id, $result['location']['id'] ?? null, $address);
        }
    }

    public function test_resolves_a_child_term_by_its_full_path(): void
    {
        $parentId = self::factory()->category->create(['name' => 'News', 'slug' => 'news']);
        $child = self::factory()->category->create_and_get(['name' => 'Local', 'slug' => 'local', 'parent' => $parentId]);

        WPSEO_Taxonomy_Meta::set_values($child->term_id, 'category', ['wpseo_desc' => 'About us.']);

        $this->assertSame($child->term_id, $this->sendOne(['url' => home_url('/category/news/local/')], dryRun: true)['location']['id'] ?? null);
        $this->assertSame('not_found', $this->sendOne(['url' => home_url('/category/local/')], dryRun: true)['reason']);
    }

    public function test_resolves_a_post_on_plain_permalinks(): void
    {
        $this->set_permalink_structure('');
        $this->storeDescription('About us.');

        $result = $this->sendOne(['url' => get_permalink($this->pageId)], dryRun: true);

        $this->assertSame($this->pageId, $result['location']['id'] ?? null);
    }

    public function test_does_not_write_to_a_page_for_its_address_with_a_query_on_the_end(): void
    {
        $this->storeDescription('About us.');

        $this->assertSame('not_found', $this->sendOne(['url' => home_url('/about/?ref=newsletter')])['reason']);
        $this->assertSame('About us.', $this->storedDescription());
    }

    /**
     * @dataProvider queriesThatAnswerAsAStaticHomePage
     */
    public function test_does_not_write_to_a_static_home_page_for_an_address_that_only_answers_as_it(string $query): void
    {
        update_option('show_on_front', 'page');
        update_option('page_on_front', $this->pageId);

        $result = $this->sendOne(['check' => 'meta_description.missing', 'current_value' => null, 'url' => home_url("/{$query}")]);

        $this->assertSame('not_found', $result['reason']);
        $this->assertSame('', $this->storedDescription());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function queriesThatAnswerAsAStaticHomePage(): array
    {
        return ['a search' => ['?s=anything'], 'a later page' => ['?paged=2']];
    }

    public function test_does_not_write_to_a_term_for_its_address_with_a_query_on_the_end(): void
    {
        $term = self::factory()->category->create_and_get(['name' => 'News', 'slug' => 'news']);

        WPSEO_Taxonomy_Meta::set_values($term->term_id, 'category', ['wpseo_desc' => 'About us.']);

        $this->assertSame('not_found', $this->sendOne(['url' => get_term_link($term).'?paged=2'])['reason']);
        $this->assertSame('About us.', WPSEO_Taxonomy_Meta::get_term_meta($term, 'category', 'desc'));
    }
}
