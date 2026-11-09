<?php

use AltDesign\Altimiser\ChangeRequest;
use AltDesign\Altimiser\RobotsApplier;

class RobotsTest extends ReceiverTestCase
{
    private string $root;

    public function set_up(): void
    {
        parent::set_up();

        $this->set_permalink_structure('/%postname%/');
        $this->root = sys_get_temp_dir().'/altimiser-robots-'.uniqid();

        mkdir($this->root);
    }

    public function tear_down(): void
    {
        WPSEO_Options::set('enable_xml_sitemap', true);
        @unlink("{$this->root}/robots.txt");
        rmdir($this->root);

        parent::tear_down();
    }

    public function test_creates_a_permissive_robots_txt_naming_the_sites_sitemap(): void
    {
        $result = $this->apply('site.robots_missing', null);

        $this->assertSame('applied', $result['status']);
        $this->assertSame(['type' => 'file', 'file' => 'robots.txt'], $result['location']);
        $this->assertArrayNotHasKey('from', $result);
        $this->assertSame("User-agent: *\nDisallow:\n\nSitemap: ".home_url('/sitemap_index.xml')."\n", $this->robots());
    }

    public function test_leaves_a_robots_txt_that_is_already_there(): void
    {
        file_put_contents("{$this->root}/robots.txt", "User-agent: *\nDisallow: /private/\n");

        $this->assertSame('already_applied', $this->apply('site.robots_missing', null)['status']);
        $this->assertSame("User-agent: *\nDisallow: /private/\n", $this->robots());
    }

    public function test_adds_the_sitemap_to_a_robots_txt_file_keeping_its_rules(): void
    {
        file_put_contents("{$this->root}/robots.txt", "User-agent: *\nDisallow: /private/\n");

        $result = $this->apply('sitemap.not_declared_in_robots', 'Sitemap: https://example.org/sitemap.xml');

        $this->assertSame('applied', $result['status']);
        $this->assertSame("User-agent: *\nDisallow: /private/\n\nSitemap: https://example.org/sitemap.xml\n", $this->robots());
    }

    public function test_counts_a_file_that_declares_a_sitemap_as_done(): void
    {
        file_put_contents("{$this->root}/robots.txt", "Sitemap: https://example.org/other.xml\n");

        $this->assertSame('already_applied', $this->apply('sitemap.not_declared_in_robots', 'Sitemap: https://example.org/sitemap.xml')['status']);
    }

    public function test_adds_the_sitemap_to_what_wordpress_prints_when_there_is_no_file(): void
    {
        $this->printNoSitemap();

        $result = $this->apply('sitemap.not_declared_in_robots', 'Sitemap: https://example.org/sitemap.xml');

        $this->assertSame('applied', $result['status']);
        $this->assertSame(['type' => 'setting', 'field' => 'altimiser_robots_sitemap'], $result['location']);
        $this->assertFileDoesNotExist("{$this->root}/robots.txt");
        $this->assertStringContainsString("Disallow: /wp-admin/\n\nSitemap: https://example.org/sitemap.xml\n", apply_filters('robots_txt', "User-agent: *\nDisallow: /wp-admin/\n", true));
        $this->assertSame('already_applied', $this->apply('sitemap.not_declared_in_robots', 'Sitemap: https://example.org/sitemap.xml')['status']);
    }

    public function test_writes_nothing_on_a_dry_run(): void
    {
        $this->printNoSitemap();

        $this->assertSame('applied', $this->apply('site.robots_missing', null, true)['status']);
        $this->assertSame('applied', $this->apply('sitemap.not_declared_in_robots', 'Sitemap: https://example.org/sitemap.xml', true)['status']);
        $this->assertFileDoesNotExist("{$this->root}/robots.txt");
        $this->assertFalse(get_option('altimiser_robots_sitemap'));
    }

    public function test_refuses_a_sitemap_value_that_adds_lines_or_names_another_site(): void
    {
        file_put_contents("{$this->root}/robots.txt", "User-agent: *\nDisallow: /private/\n");

        foreach (["Sitemap: https://example.org/sitemap.xml\nDisallow: /", 'Sitemap: https://other.example/sitemap.xml', 'Sitemap: javascript:alert(1)'] as $suggested) {
            $this->assertSame('invalid_change', $this->apply('sitemap.not_declared_in_robots', $suggested)['reason'], $suggested);
        }

        $this->assertSame("User-agent: *\nDisallow: /private/\n", $this->robots());
    }

    public function test_accepts_the_sitemap_on_the_www_form_of_the_sites_host(): void
    {
        file_put_contents("{$this->root}/robots.txt", "User-agent: *\n");

        $this->assertSame('applied', $this->apply('sitemap.not_declared_in_robots', 'Sitemap: https://www.example.org/sitemap.xml')['status']);
    }

    public function test_writes_no_file_letting_crawlers_in_on_a_site_that_discourages_them(): void
    {
        update_option('blog_public', '0');

        $result = $this->apply('site.robots_missing', null);

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('search_discouraged', $result['reason']);
        $this->assertFileDoesNotExist("{$this->root}/robots.txt");
    }

    public function test_skips_a_site_that_is_not_at_the_root_of_its_domain(): void
    {
        update_option('home', 'http://example.org/blog');

        $result = $this->apply('site.robots_missing', null);

        $this->assertSame('skipped', $result['status']);
        $this->assertFileDoesNotExist("{$this->root}/robots.txt");
    }

    private function printNoSitemap(): void
    {
        WPSEO_Options::set('enable_xml_sitemap', false);
        add_filter('wp_sitemaps_enabled', '__return_false');
    }

    /**
     * @return array<string, mixed>
     */
    private function apply(string $check, ?string $suggested, bool $dryRun = false): array
    {
        $change = ChangeRequest::fromPayload($this->change([
            'check' => $check,
            'url' => home_url('/'),
            'target' => ['selector' => null, 'attribute' => null],
            'current_value' => null,
            'suggested_value' => $suggested,
        ]));

        return (new RobotsApplier($this->root))->apply($change, $dryRun);
    }

    private function robots(): string
    {
        return (string) file_get_contents("{$this->root}/robots.txt");
    }
}
