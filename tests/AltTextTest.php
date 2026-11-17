<?php

class AltTextTest extends ReceiverTestCase
{
    private int $imageId;

    private int $pageId;

    private string $uploads;

    public static function set_up_before_class(): void
    {
        parent::set_up_before_class();

        self::configureSecret();
    }

    public function set_up(): void
    {
        parent::set_up();

        $this->set_permalink_structure('/%postname%/');

        $this->uploads = wp_get_upload_dir()['baseurl'];
        $this->imageId = $this->image('2026/11/harbour.jpg');
        $this->pageId = self::factory()->post->create([
            'post_type' => 'page',
            'post_name' => 'moorings',
            'post_content' => $this->imageBlock($this->imageId, "{$this->uploads}/2026/11/harbour-300x200.jpg", ''),
        ]);
    }

    public function test_writes_alt_text_to_the_media_library_and_to_the_page(): void
    {
        $result = $this->sendAlt(['target' => $this->target('https://cdn.example.net/wp-content/uploads/2026/11/harbour-300x200.jpg')]);

        $this->assertSame('applied', $result['status']);
        $this->assertSame(['type' => 'asset', 'asset' => $this->imageId, 'field' => '_wp_attachment_image_alt', 'id' => $this->pageId, 'collection' => 'page'], $result['location']);
        $this->assertSame('Boats moored in the harbour at dusk', get_post_meta($this->imageId, '_wp_attachment_image_alt', true));
        $this->assertStringContainsString('alt="Boats moored in the harbour at dusk"', get_post($this->pageId)->post_content);
    }

    public function test_clears_every_cached_page_when_the_media_library_is_written(): void
    {
        $purged = [];
        $record = static function (array $urls, bool $everything) use (&$purged): void {
            $purged[] = $everything;
        };

        add_action('altimiser_purge_urls', $record, 10, 2);
        $this->sendAlt(['target' => $this->target("{$this->uploads}/2026/11/harbour-300x200.jpg")]);
        remove_action('altimiser_purge_urls', $record);

        $this->assertSame([true], $purged);
    }

    public function test_finds_an_image_wordpress_scaled_down_on_upload(): void
    {
        $scaledId = $this->image('2026/11/estuary-scaled.jpg');

        $result = $this->sendAlt(['target' => $this->target("{$this->uploads}/2026/11/estuary-1024x683.jpg")]);

        $this->assertSame('applied', $result['status']);
        $this->assertSame('Boats moored in the harbour at dusk', get_post_meta($scaledId, '_wp_attachment_image_alt', true));
    }

    public function test_writes_only_the_page_where_the_media_library_has_its_own_wording(): void
    {
        update_post_meta($this->imageId, '_wp_attachment_image_alt', 'The harbour');

        $result = $this->sendAlt();

        $this->assertSame('applied', $result['status']);
        $this->assertSame(['type' => 'post', 'id' => $this->pageId, 'collection' => 'page', 'field' => 'post_content'], $result['location']);
        $this->assertSame('The harbour', get_post_meta($this->imageId, '_wp_attachment_image_alt', true));
    }

    public function test_leaves_an_editors_own_wording_alone(): void
    {
        update_post_meta($this->imageId, '_wp_attachment_image_alt', 'The harbour');
        $this->setContent($this->imageBlock($this->imageId, "{$this->uploads}/2026/11/harbour.jpg", 'The harbour in winter'));

        $result = $this->sendAlt();

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('value_changed', $result['reason']);
        $this->assertStringContainsString('alt="The harbour in winter"', get_post($this->pageId)->post_content);
    }

    public function test_counts_alt_text_already_in_place_as_done(): void
    {
        update_post_meta($this->imageId, '_wp_attachment_image_alt', 'Boats moored in the harbour at dusk');
        $this->setContent($this->imageBlock($this->imageId, "{$this->uploads}/2026/11/harbour.jpg", 'Boats moored in the harbour at dusk'));

        $this->assertSame('already_applied', $this->sendAlt()['status']);
    }

    public function test_counts_a_change_to_the_wording_already_there_as_done(): void
    {
        update_post_meta($this->imageId, '_wp_attachment_image_alt', 'The harbour');
        $this->setContent($this->imageBlock($this->imageId, "{$this->uploads}/2026/11/harbour.jpg", 'The harbour'));

        $this->assertSame('already_applied', $this->sendAlt(['check' => 'image.alt_too_long', 'current_value' => 'The harbour', 'suggested_value' => 'The harbour'])['status']);
    }

    public function test_keeps_a_cover_block_valid_by_writing_its_attribute_as_well(): void
    {
        $url = "{$this->uploads}/2026/11/harbour.jpg";
        $this->setContent(
            "<!-- wp:cover {\"url\":\"{$url}\",\"id\":{$this->imageId},\"alt\":\"\"} -->\n"
            ."<div class=\"wp-block-cover\"><img class=\"wp-block-cover__image-background wp-image-{$this->imageId}\" alt=\"\" src=\"{$url}\" data-object-fit=\"cover\"/>"
            ."<div class=\"wp-block-cover__inner-container\"></div></div>\n<!-- /wp:cover -->",
        );

        $result = $this->sendAlt();
        $content = get_post($this->pageId)->post_content;

        $this->assertSame('applied', $result['status']);
        $this->assertSame('Boats moored in the harbour at dusk', parse_blocks($content)[0]['attrs']['alt']);
        $this->assertStringContainsString('alt="Boats moored in the harbour at dusk" src=', $content);
    }

    public function test_puts_the_earlier_wording_back_on_a_revert(): void
    {
        $this->sendAlt(['check' => 'image.alt_too_long', 'current_value' => '', 'suggested_value' => 'Boats moored in the harbour at dusk']);

        $result = $this->sendAlt(['check' => 'image.alt_too_long', 'current_value' => 'Boats moored in the harbour at dusk', 'suggested_value' => 'The harbour']);

        $this->assertSame('applied', $result['status']);
        $this->assertSame('The harbour', get_post_meta($this->imageId, '_wp_attachment_image_alt', true));
        $this->assertStringContainsString('alt="The harbour"', get_post($this->pageId)->post_content);
    }

    public function test_keeps_backslashes_and_quotes_in_the_alt_text(): void
    {
        $this->sendAlt(['suggested_value' => 'The "Sea\\Wolf" at her mooring']);

        $this->assertSame('The "Sea\\Wolf" at her mooring', get_post_meta($this->imageId, '_wp_attachment_image_alt', true));
        $this->assertStringContainsString('alt="The &quot;Sea\\Wolf&quot; at her mooring"', get_post($this->pageId)->post_content);
    }

    public function test_skips_an_image_that_is_not_in_the_media_library(): void
    {
        $result = $this->sendAlt(['target' => $this->target(home_url('/wp-content/themes/harbour/logo.png'))]);

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('no_match', $result['reason']);
    }

    public function test_skips_a_change_with_no_alt_text_to_write(): void
    {
        $this->assertSame('no_suggestion', $this->sendAlt(['suggested_value' => null])['reason']);
    }

    public function test_writes_nothing_on_a_dry_run(): void
    {
        $result = $this->send([$this->altChange()], true)[0];

        $this->assertSame('applied', $result['status']);
        $this->assertSame('', get_post_meta($this->imageId, '_wp_attachment_image_alt', true));
        $this->assertStringContainsString('alt=""', get_post($this->pageId)->post_content);
    }

    public function test_writes_only_the_media_library_on_a_page_built_with_elementor(): void
    {
        update_post_meta($this->pageId, '_elementor_edit_mode', 'builder');

        $result = $this->sendAlt();

        $this->assertSame('applied', $result['status']);
        $this->assertSame(['type' => 'asset', 'asset' => $this->imageId, 'field' => '_wp_attachment_image_alt'], $result['location']);
        $this->assertStringContainsString('alt=""', get_post($this->pageId)->post_content);
    }

    private function image(string $file): int
    {
        return self::factory()->attachment->create_object(['file' => $file, 'post_mime_type' => 'image/jpeg']);
    }

    private function imageBlock(int $imageId, string $src, string $alt): string
    {
        return "<!-- wp:image {\"id\":{$imageId}} -->\n<figure class=\"wp-block-image\"><img src=\"{$src}\" alt=\"{$alt}\" class=\"wp-image-{$imageId}\"/></figure>\n<!-- /wp:image -->";
    }

    private function setContent(string $content): void
    {
        wp_update_post(wp_slash(['ID' => $this->pageId, 'post_content' => $content]));
    }

    /**
     * @return array<string, string>
     */
    private function target(string $match): array
    {
        return ['selector' => 'img', 'attribute' => 'alt', 'match' => $match];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function altChange(array $overrides = []): array
    {
        return $this->change([
            'check' => 'image.alt_missing',
            'url' => home_url('/moorings/'),
            'target' => $this->target("{$this->uploads}/2026/11/harbour-300x200.jpg"),
            'current_value' => '',
            'suggested_value' => 'Boats moored in the harbour at dusk',
            ...$overrides,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function sendAlt(array $overrides = []): array
    {
        return $this->send([$this->altChange($overrides)])[0];
    }
}
