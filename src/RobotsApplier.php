<?php

namespace AltDesign\Altimiser;

use WPSEO_Options;

/**
 * WordPress answers /robots.txt itself unless a file of that name sits at the root of
 * the site, and only on pretty permalinks. A missing one gets a permissive file with no
 * Disallow rules, because guessing what a site wants hidden is how a page drops out of
 * search. A sitemap goes into the file where there is one, and otherwise into what
 * WordPress prints, through the robots_txt filter, which keeps the rules WordPress and
 * other plugins add there.
 *
 * Neither change reports what the site held before, so Altimiser never reverts them.
 */
class RobotsApplier implements Applier
{
    public static string $option = 'altimiser_robots_sitemap';

    private static string $sitemapLine = '/^\s*sitemap:/im';

    public function __construct(private ?string $root = null) {}

    public function checks(): array
    {
        return ['site.robots_missing', 'sitemap.not_declared_in_robots'];
    }

    public function supports(string $check): bool
    {
        return in_array($check, $this->checks(), true);
    }

    public static function declareSitemap(string $output): string
    {
        $sitemap = (string) get_option(self::$option, '');

        if ($sitemap === '' || preg_match(self::$sitemapLine, $output) === 1) {
            return $output;
        }

        return rtrim($output, "\n")."\n\nSitemap: {$sitemap}\n";
    }

    public function apply(ChangeRequest $change, bool $dryRun): array
    {
        $sitePath = trim((string) parse_url(home_url(), PHP_URL_PATH), '/');

        if ($sitePath !== '') {
            return ChangeResult::skipped(
                $change->id,
                'no_field',
                "Crawlers only read robots.txt at the root of a domain, and this site is at /{$sitePath}, so it has no robots.txt of its own.",
            );
        }

        $file = $this->root().'robots.txt';
        $existing = is_file($file) ? (string) file_get_contents($file) : null;

        return $change->check === 'site.robots_missing'
            ? $this->create($change, $file, $existing, $dryRun)
            : $this->declare($change, $file, $existing, $dryRun);
    }

    /**
     * @return array<string, mixed>
     */
    private function create(ChangeRequest $change, string $file, ?string $existing, bool $dryRun): array
    {
        if ($existing !== null) {
            return ChangeResult::alreadyApplied($change->id, $this->fileLocation(), 'This site already has a robots.txt file.');
        }

        $sitemap = $this->sitemap($change->suggestedValue);
        $contents = "User-agent: *\nDisallow:\n".($sitemap === '' ? '' : "\nSitemap: {$sitemap}\n");

        return $this->write($change, $file, $contents, $dryRun);
    }

    /**
     * @return array<string, mixed>
     */
    private function declare(ChangeRequest $change, string $file, ?string $existing, bool $dryRun): array
    {
        $sitemap = $this->sitemap($change->suggestedValue);

        if ($sitemap === '') {
            return ChangeResult::skipped($change->id, 'no_suggestion', 'There is no sitemap address to declare, and this site does not publish one of its own.');
        }

        if ($existing !== null) {
            return preg_match(self::$sitemapLine, $existing) === 1
                ? ChangeResult::alreadyApplied($change->id, $this->fileLocation(), 'robots.txt already declares a sitemap.')
                : $this->write($change, $file, rtrim($existing, "\n")."\n\nSitemap: {$sitemap}\n", $dryRun);
        }

        $location = ['type' => 'setting', 'field' => self::$option];
        $printed = (string) apply_filters('robots_txt', "User-agent: *\n", (string) get_option('blog_public', '1') !== '0');

        if (preg_match(self::$sitemapLine, $printed) === 1) {
            return ChangeResult::alreadyApplied($change->id, $location, 'The robots.txt WordPress prints already declares a sitemap.');
        }

        if (! $dryRun && ! update_option(self::$option, $sitemap, false)) {
            return ChangeResult::failed($change->id, 'write_failed', 'WordPress did not save the sitemap address.');
        }

        return ChangeResult::applied($change->id, $location, null, "Sitemap: {$sitemap}");
    }

    /**
     * @return array<string, mixed>
     */
    private function write(ChangeRequest $change, string $file, string $contents, bool $dryRun): array
    {
        if ($dryRun) {
            return ChangeResult::applied($change->id, $this->fileLocation(), null, $contents);
        }

        $writable = is_file($file) ? is_writable($file) : is_writable(dirname($file));

        if (! $writable || file_put_contents($file, $contents) === false) {
            return ChangeResult::failed($change->id, 'write_failed', 'The web server cannot write robots.txt at the root of this site.');
        }

        return ChangeResult::applied($change->id, $this->fileLocation(), null, $contents);
    }

    /**
     * Altimiser sends a whole Sitemap: line, or nothing when it found no robots.txt to
     * read. The site's own sitemap is used then, Yoast's where it makes one.
     */
    private function sitemap(?string $suggested): string
    {
        $sitemap = trim((string) preg_replace('/^\s*sitemap:\s*/i', '', (string) $suggested));

        if ($sitemap !== '') {
            return $sitemap;
        }

        if (class_exists('WPSEO_Options') && WPSEO_Options::get('enable_xml_sitemap')) {
            return home_url('/sitemap_index.xml');
        }

        return (string) get_sitemap_url('index');
    }

    private function root(): string
    {
        if ($this->root !== null) {
            return trailingslashit($this->root);
        }

        require_once ABSPATH.'wp-admin/includes/file.php';

        return get_home_path();
    }

    /**
     * @return array<string, string>
     */
    private function fileLocation(): array
    {
        return ['type' => 'file', 'file' => 'robots.txt'];
    }
}
