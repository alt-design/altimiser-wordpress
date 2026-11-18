<?php

class WpmlTest extends ReceiverTestCase
{
    private string $language = 'en';

    private int $translationId;

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

        $this->translationId = self::factory()->post->create([
            'post_type' => 'page',
            'post_name' => 'yacht management',
            'post_title' => 'Management',
        ]);
        wp_update_post(['ID' => $this->translationId, 'post_name' => rawurlencode('游艇管理')]);
    }

    private function activateWpml(): void
    {
        add_filter('wpml_active_languages', static fn () => ['en' => [], 'zh-hans' => []], 10, 2);
        add_filter('wpml_current_language', fn () => $this->language);
        add_filter('wpml_default_language', static fn () => 'en');
        add_action('wpml_switch_language', function (?string $code): void {
            $this->language = $code ?? 'en';
        });
        add_filter('wpml_permalink', static fn (string $url) => str_replace('/zh-hans/', '/', $url), 10, 2);
        add_filter('page_link', fn (string $link) => $this->prefixed($link), 10, 1);
        add_filter('term_link', fn (string $link) => $this->prefixed($link), 10, 1);
    }

    private function prefixed(string $link): string
    {
        return $this->language === 'zh-hans' ? home_url('/zh-hans'.substr($link, strlen(home_url()))) : $link;
    }

    public function test_finds_a_translation_at_its_translated_address(): void
    {
        $this->activateWpml();
        $url = home_url('/zh-hans/'.rawurlencode('游艇管理').'/');

        $result = $this->sendOne(['url' => $url, 'check' => 'meta_description.missing', 'current_value' => null]);

        $this->assertSame('applied', $result['status']);
        $this->assertSame($this->translationId, $result['location']['id']);
        $this->assertSame('How our studio works, and who we build for.', get_post_meta($this->translationId, '_yoast_wpseo_metadesc', true));
    }

    public function test_finds_it_whether_the_address_is_percent_encoded_or_written_out(): void
    {
        $this->activateWpml();

        $encoded = $this->sendOne(['url' => home_url('/zh-hans/'.strtoupper(rawurlencode('游艇管理')).'/'), 'check' => 'meta_description.missing', 'current_value' => null], true);
        $written = $this->sendOne(['url' => home_url('/zh-hans/游艇管理/'), 'check' => 'meta_description.missing', 'current_value' => null], true);

        $this->assertSame($this->translationId, $encoded['location']['id']);
        $this->assertSame($this->translationId, $written['location']['id']);
    }

    public function test_puts_wpml_back_to_the_language_it_was_in(): void
    {
        $this->activateWpml();

        $this->sendOne(['url' => home_url('/zh-hans/'.rawurlencode('游艇管理').'/'), 'check' => 'meta_description.missing', 'current_value' => null], true);
        $this->sendOne(['url' => home_url('/zh-hans/nothing-here/')]);

        $this->assertSame('en', $this->language);
    }

    public function test_finds_a_translated_category_by_its_own_slug(): void
    {
        $this->activateWpml();
        $term = self::factory()->category->create_and_get(['name' => 'News', 'slug' => rawurlencode('新闻')]);

        $this->language = 'zh-hans';
        $url = get_term_link($term);
        $this->language = 'en';

        $result = $this->sendOne(['url' => $url, 'check' => 'meta_description.missing', 'current_value' => null], true);

        $this->assertSame('applied', $result['status']);
        $this->assertSame($term->term_id, $result['location']['id']);
    }

    public function test_still_says_not_found_for_an_address_no_language_answers_to(): void
    {
        $this->activateWpml();

        $this->assertSame('not_found', $this->sendOne(['url' => home_url('/zh-hans/nothing-here/')])['reason']);
    }

    public function test_leaves_a_site_without_wpml_exactly_as_it_was(): void
    {
        $this->assertSame('not_found', $this->sendOne(['url' => home_url('/zh-hans/'.rawurlencode('游艇管理').'/')])['reason']);
    }
}
