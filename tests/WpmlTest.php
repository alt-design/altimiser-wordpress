<?php

use AltDesign\Altimiser\Applier;
use AltDesign\Altimiser\ChangeDispatcher;
use AltDesign\Altimiser\ChangeRequest;
use AltDesign\Altimiser\ChangeResult;
use AltDesign\Altimiser\ContentResolver;
use AltDesign\Altimiser\Languages;
use AltDesign\Altimiser\PageCache;

class WpmlTest extends ReceiverTestCase
{
    private string $language = 'en';

    /**
     * @var array<int, string>
     */
    private array $stack = [];

    /**
     * @var array<int, string>
     */
    private array $switchEvents = [];

    /**
     * @var array<int, string>
     */
    private array $active = ['en', 'zh-hans'];

    /**
     * @var array<int, string>
     */
    private array $postLanguage = [];

    /**
     * @var array<string, int>
     */
    private array $frontPages = [];

    private int $activeLanguageReads = 0;

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

        $this->translationId = $this->page('yacht management', 'zh-hans');
        wp_update_post(['ID' => $this->translationId, 'post_name' => rawurlencode('游艇管理')]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function page(string $slug, string $language, array $overrides = []): int
    {
        $id = self::factory()->post->create(['post_type' => 'page', 'post_name' => $slug, ...$overrides]);

        $this->postLanguage[$id] = $language;

        return $id;
    }

    private function sameSlug(int $id, string $slug): void
    {
        global $wpdb;

        $wpdb->update($wpdb->posts, ['post_name' => $slug], ['ID' => $id]);
        clean_post_cache($id);
    }

    private function activateWpml(): void
    {
        add_filter('wpml_active_languages', function () {
            $this->activeLanguageReads++;

            return array_fill_keys($this->active, []);
        }, 10, 2);
        add_filter('wpml_current_language', fn () => $this->language);
        add_filter('wpml_default_language', static fn () => 'en');
        add_action('wpml_switch_language', [$this, 'switchLanguage']);
        add_action('wpml_language_has_switched', function (string $code): void {
            $this->switchEvents[] = $code;
        });
        add_filter('wpml_permalink', fn (string $url) => $this->withoutPrefix($url), 10, 2);
        add_filter('page_link', fn (string $link) => $this->prefixed($link));
        add_filter('post_link', fn (string $link) => $this->prefixed($link));
        add_filter('term_link', fn (string $link) => $this->prefixed($link));
        add_filter('posts_where', fn (string $where) => $where.$this->inCurrentLanguage());
        add_filter('option_page_on_front', fn ($id) => $this->frontPages[$this->language] ?? $id);
    }

    public function switchLanguage(?string $code): void
    {
        if ($code === null) {
            $this->language = array_pop($this->stack) ?? $this->language;

            return;
        }

        $this->stack[] = $this->language;
        $this->language = $code;

        do_action('wpml_language_has_switched', $code);
    }

    private function inCurrentLanguage(): string
    {
        global $wpdb;

        $ids = array_keys(array_filter($this->postLanguage, fn (string $language) => $language === $this->language));

        return " AND {$wpdb->posts}.ID IN (".implode(',', $ids ?: [0]).')';
    }

    private function prefixed(string $link): string
    {
        return $this->language === 'en' ? $link : home_url("/{$this->language}".substr($link, strlen(home_url())));
    }

    private function withoutPrefix(string $url): string
    {
        $others = implode('|', array_map('preg_quote', array_diff($this->active, ['en'])));

        return (string) preg_replace('~^('.preg_quote(home_url(), '~')."/)(?:{$others})(?:/|$)~", '$1', $url);
    }

    private function missingDescription(string $url, bool $dryRun = false): array
    {
        return $this->sendOne(['url' => $url, 'check' => 'meta_description.missing', 'current_value' => null], $dryRun);
    }

    public function test_finds_a_translation_at_its_translated_address(): void
    {
        $this->activateWpml();

        $result = $this->missingDescription(home_url('/zh-hans/'.rawurlencode('游艇管理').'/'));

        $this->assertSame('applied', $result['status']);
        $this->assertSame($this->translationId, $result['location']['id']);
        $this->assertSame('How our studio works, and who we build for.', get_post_meta($this->translationId, '_yoast_wpseo_metadesc', true));
    }

    public function test_finds_it_whether_the_address_is_percent_encoded_or_written_out(): void
    {
        $this->activateWpml();

        $encoded = $this->missingDescription(home_url('/zh-hans/'.strtoupper(rawurlencode('游艇管理')).'/'), true);
        $written = $this->missingDescription(home_url('/zh-hans/游艇管理/'), true);

        $this->assertSame($this->translationId, $encoded['location']['id']);
        $this->assertSame($this->translationId, $written['location']['id']);
    }

    public function test_finds_a_translated_category_by_its_own_slug(): void
    {
        $this->activateWpml();
        $term = self::factory()->category->create_and_get(['name' => 'News', 'slug' => rawurlencode('新闻')]);

        $this->language = 'zh-hans';
        $url = get_term_link($term);
        $this->language = 'en';

        $result = $this->missingDescription($url, true);

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

    public function test_leaves_wpml_in_the_language_of_the_record_until_the_change_is_done(): void
    {
        $this->activateWpml();
        $languages = new Languages();

        $found = (new ContentResolver($languages))->find(home_url('/zh-hans/'.rawurlencode('游艇管理').'/'));

        $this->assertSame($this->translationId, $found->ID);
        $this->assertSame('zh-hans', $this->language);

        $languages->restore();

        $this->assertSame('en', $this->language);
        $this->assertSame([], $this->stack);
    }

    public function test_takes_every_switch_off_the_stack_when_nothing_is_found(): void
    {
        $this->activateWpml();
        $this->active = ['en', 'zh-hans', 'fr'];

        $this->assertNull((new ContentResolver(new Languages()))->find(home_url('/zh-hans/nothing-here/')));

        $this->assertSame('en', $this->language);
        $this->assertSame([], $this->stack);
    }

    public function test_restores_the_language_and_the_stack_when_a_lookup_throws_part_way(): void
    {
        $this->activateWpml();
        $this->active = ['en', 'fr', 'zh-hans'];
        add_filter('url_to_postid', function (string $url): string {
            if ($this->language === 'zh-hans') {
                throw new RuntimeException('WPML broke.');
            }

            return $url;
        });

        try {
            (new ContentResolver(new Languages()))->find(home_url('/zh-hans/nothing-here/'));
            $this->fail('The exception was swallowed.');
        } catch (RuntimeException) {
            $this->assertSame('en', $this->language);
            $this->assertSame([], $this->stack);
        }
    }

    public function test_resolves_the_same_slug_in_two_languages_to_the_right_record(): void
    {
        $this->activateWpml();
        $english = $this->page('charter', 'en', ['post_type' => 'post']);
        $chinese = $this->page('charter-zh', 'zh-hans', ['post_type' => 'post']);
        $this->sameSlug($chinese, 'charter');

        $inEnglish = $this->missingDescription(home_url('/charter/'), true);
        $inChinese = $this->missingDescription(home_url('/zh-hans/charter/'), true);

        $this->assertSame($english, $inEnglish['location']['id']);
        $this->assertSame($chinese, $inChinese['location']['id']);
    }

    public function test_finds_a_translation_on_a_site_with_three_languages(): void
    {
        $this->activateWpml();
        $this->active = ['en', 'fr', 'zh-hans'];
        $french = $this->page('croisiere', 'fr');

        $inFrench = $this->missingDescription(home_url('/fr/croisiere/'), true);
        $inChinese = $this->missingDescription(home_url('/zh-hans/'.rawurlencode('游艇管理').'/'), true);

        $this->assertSame($french, $inFrench['location']['id']);
        $this->assertSame($this->translationId, $inChinese['location']['id']);
        $this->assertSame([], $this->stack);
        $this->assertSame('en', $this->language);
    }

    public function test_finds_a_default_language_address_without_switching(): void
    {
        $this->activateWpml();
        $english = $this->page('charter', 'en');

        $result = $this->missingDescription(home_url('/charter/'), true);

        $this->assertSame($english, $result['location']['id']);
        $this->assertSame([], $this->switchEvents);
    }

    public function test_finds_a_translated_static_front_page(): void
    {
        $this->activateWpml();
        $english = $this->page('home', 'en');
        $chinese = $this->page('home-zh', 'zh-hans');
        update_option('show_on_front', 'page');
        update_option('page_on_front', $english);
        $this->frontPages['zh-hans'] = $chinese;

        $inChinese = $this->missingDescription(home_url('/zh-hans/'), true);
        $inEnglish = $this->missingDescription(home_url('/'), true);

        $this->assertSame($chinese, $inChinese['location']['id']);
        $this->assertSame($english, $inEnglish['location']['id']);
    }

    public function test_starts_each_change_in_a_batch_in_the_original_language(): void
    {
        $this->activateWpml();
        $languages = new Languages();
        $resolver = new ContentResolver($languages);
        $probe = new class($resolver) implements Applier
        {
            public array $startedIn = [];

            public array $workedIn = [];

            public function __construct(private ContentResolver $resolver) {}

            public function checks(): array
            {
                return ['meta_description.missing'];
            }

            public function supports(string $check): bool
            {
                return true;
            }

            public function apply(ChangeRequest $change, bool $dryRun): array
            {
                $this->startedIn[] = apply_filters('wpml_current_language', null);
                $this->resolver->find($change->url);
                $this->workedIn[] = apply_filters('wpml_current_language', null);

                return ChangeResult::applied($change->id, []);
            }
        };
        $changes = array_map(fn (string $url) => ChangeRequest::fromPayload($this->change(['url' => $url])), [
            home_url('/zh-hans/'.rawurlencode('游艇管理').'/'),
            home_url('/zh-hans/'.rawurlencode('游艇管理').'/'),
            home_url('/nothing-here/'),
        ]);

        (new ChangeDispatcher([$probe], new PageCache(), $languages))->dispatch($changes, true);

        $this->assertSame(['en', 'en', 'en'], $probe->startedIn);
        $this->assertSame(['zh-hans', 'zh-hans', 'en'], $probe->workedIn);
        $this->assertSame([], $this->stack);
    }

    public function test_does_not_repeat_the_language_cycle_for_an_address_it_has_already_looked_up(): void
    {
        $this->activateWpml();
        $resolver = new ContentResolver(new Languages());
        $url = home_url('/zh-hans/nothing-here/');

        $resolver->find($url);
        $resolver->find($url);
        $resolver->findArchive($url);
        $resolver->findArchive($url);

        $this->assertSame(1, $this->activeLanguageReads);
    }

    public function test_goes_straight_to_the_language_an_address_was_found_in_the_second_time(): void
    {
        $this->activateWpml();
        $languages = new Languages();
        $resolver = new ContentResolver($languages);
        $url = home_url('/zh-hans/'.rawurlencode('游艇管理').'/');

        $resolver->find($url);
        $languages->restore();
        $this->switchEvents = [];
        $found = $resolver->find($url);

        $this->assertSame($this->translationId, $found->ID);
        $this->assertSame(['zh-hans'], $this->switchEvents);
    }
}
