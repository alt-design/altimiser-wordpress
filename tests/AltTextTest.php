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

    public function test_writes_alt_text_into_the_page_for_an_image_outside_the_media_library(): void
    {
        $source = 'https://other-site.example.net/wp-content/uploads/2020/05/quay.jpg';
        $this->setContent("<!-- wp:image -->\n<figure class=\"wp-block-image\"><img src=\"{$source}\" alt=\"\" class=\"wp-image-98765\"/></figure>\n<!-- /wp:image -->");

        $result = $this->sendAlt(['target' => $this->target($source)]);

        $this->assertSame('applied', $result['status']);
        $this->assertSame(['type' => 'post', 'id' => $this->pageId, 'collection' => 'page', 'field' => 'post_content'], $result['location']);
        $this->assertStringContainsString('alt="Boats moored in the harbour at dusk"', get_post($this->pageId)->post_content);
    }

    public function test_adds_the_alt_attribute_to_an_outside_image_that_has_none(): void
    {
        $source = 'https://other-site.example.net/wp-content/uploads/2020/05/quay.jpg';
        $this->setContent("<p><img src=\"{$source}\"></p>");

        $result = $this->sendAlt(['target' => $this->target($source)]);

        $this->assertSame('applied', $result['status']);
        $this->assertStringContainsString('alt="Boats moored in the harbour at dusk"', get_post($this->pageId)->post_content);
    }

    public function test_reverts_and_resends_for_an_outside_image(): void
    {
        $source = 'https://other-site.example.net/wp-content/uploads/2020/05/quay.jpg';
        $this->setContent("<p><img src=\"{$source}\" alt=\"\"></p>");
        $this->sendAlt(['target' => $this->target($source)]);

        $again = $this->sendAlt(['target' => $this->target($source)]);
        $revert = $this->sendAlt([
            'target' => $this->target($source),
            'current_value' => 'Boats moored in the harbour at dusk',
            'suggested_value' => 'Old quay',
        ]);

        $this->assertSame('already_applied', $again['status']);
        $this->assertSame('applied', $revert['status']);
        $this->assertStringContainsString('alt="Old quay"', get_post($this->pageId)->post_content);
    }

    public function test_leaves_an_outside_images_own_wording_alone(): void
    {
        $source = 'https://other-site.example.net/wp-content/uploads/2020/05/quay.jpg';
        $this->setContent("<p><img src=\"{$source}\" alt=\"The quay\"></p>");

        $result = $this->sendAlt(['target' => $this->target($source)]);

        $this->assertSame('value_changed', $result['reason']);
        $this->assertStringContainsString('alt="The quay"', get_post($this->pageId)->post_content);
    }

    public function test_writes_nothing_to_an_outside_image_on_a_dry_run(): void
    {
        $source = 'https://other-site.example.net/wp-content/uploads/2020/05/quay.jpg';
        $this->setContent("<p><img src=\"{$source}\" alt=\"\"></p>");

        $result = $this->send([$this->altChange(['target' => $this->target($source)])], true)[0];

        $this->assertSame('applied', $result['status']);
        $this->assertStringContainsString('alt=""', get_post($this->pageId)->post_content);
    }

    public function test_writes_the_alt_attribute_of_an_outside_cover_block_as_well(): void
    {
        $source = 'https://other-site.example.net/wp-content/uploads/2020/05/quay.jpg';
        $this->setContent(
            "<!-- wp:cover {\"url\":\"{$source}\",\"id\":98765,\"alt\":\"\"} -->\n"
            ."<div class=\"wp-block-cover\"><img class=\"wp-block-cover__image-background wp-image-98765\" alt=\"\" src=\"{$source}\"/>"
            ."<div class=\"wp-block-cover__inner-container\"></div></div>\n<!-- /wp:cover -->",
        );

        $this->sendAlt(['target' => $this->target($source)]);

        $this->assertSame('Boats moored in the harbour at dusk', parse_blocks(get_post($this->pageId)->post_content)[0]['attrs']['alt']);
    }

    public function test_says_a_later_change_in_the_batch_was_already_applied_by_the_first(): void
    {
        $other = $this->secondPage();

        $results = $this->send([
            $this->altChange(['id' => 'first']),
            $this->altChange(['id' => 'second', 'url' => get_permalink($other)]),
        ]);

        $this->assertSame('applied', $results[0]['status']);
        $this->assertSame('already_applied', $results[1]['status']);
        $this->assertStringContainsString('in this batch', $results[1]['message']);
    }

    public function test_says_a_later_change_with_other_wording_was_overtaken_by_the_first_in_the_batch(): void
    {
        $other = $this->secondPage();

        $results = $this->send([
            $this->altChange(['id' => 'first']),
            $this->altChange(['id' => 'second', 'url' => get_permalink($other), 'suggested_value' => 'Moorings at low tide']),
        ]);

        $this->assertSame('skipped', $results[1]['status']);
        $this->assertSame('applied_by_other_change', $results[1]['reason']);
        $this->assertStringNotContainsString('somebody has changed', $results[1]['message']);
        $this->assertSame('Boats moored in the harbour at dusk', get_post_meta($this->imageId, '_wp_attachment_image_alt', true));
    }

    public function test_still_calls_a_value_from_an_earlier_request_somebody_elses_change(): void
    {
        $other = $this->secondPage();
        update_post_meta($this->imageId, '_wp_attachment_image_alt', 'The harbour');

        $result = $this->sendAlt(['url' => get_permalink($other), 'current_value' => 'Old wording']);

        $this->assertSame('value_changed', $result['reason']);
    }

    public function test_says_the_template_leaves_out_alt_text_the_media_library_holds(): void
    {
        update_post_meta($this->imageId, '_wp_attachment_image_alt', 'The harbour');
        $this->setContent('<p>No image tag for it here.</p>');

        $result = $this->sendAlt();

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('template_omits_alt', $result['reason']);
        $this->assertStringContainsString('template', $result['message']);
        $this->assertStringNotContainsString('somebody has changed', $result['message']);
    }

    private function secondPage(): int
    {
        return self::factory()->post->create([
            'post_type' => 'page',
            'post_name' => 'berths',
            'post_content' => '<p>Berths.</p>',
        ]);
    }

    public function test_clears_the_media_library_and_empties_the_page_on_a_revert(): void
    {
        $this->sendAlt();

        $result = $this->sendAlt($this->clearing(['check' => 'image.alt_empty', 'current_value' => 'Boats moored in the harbour at dusk']));

        $this->assertSame('applied', $result['status']);
        $this->assertFalse(metadata_exists('post', $this->imageId, '_wp_attachment_image_alt'));
        $this->assertStringContainsString('alt=""', get_post($this->pageId)->post_content);
        $this->assertStringNotContainsString('moored', get_post($this->pageId)->post_content);
    }

    public function test_removes_the_alt_attribute_again_when_the_image_had_none(): void
    {
        $this->setContent("<p><img src=\"{$this->uploads}/2026/11/harbour.jpg\" class=\"wp-image-{$this->imageId}\"></p>");
        $this->sendAlt();

        $result = $this->sendAlt($this->clearing(['current_value' => 'Boats moored in the harbour at dusk']));

        $this->assertSame('applied', $result['status']);
        $this->assertStringNotContainsString('alt=', get_post($this->pageId)->post_content);
        $this->assertFalse(metadata_exists('post', $this->imageId, '_wp_attachment_image_alt'));
    }

    public function test_refuses_to_clear_alt_text_that_has_moved_on(): void
    {
        update_post_meta($this->imageId, '_wp_attachment_image_alt', 'The harbour');
        $this->setContent($this->imageBlock($this->imageId, "{$this->uploads}/2026/11/harbour.jpg", 'The harbour in winter'));

        $result = $this->sendAlt($this->clearing(['current_value' => 'Boats moored in the harbour at dusk']));

        $this->assertSame('value_changed', $result['reason']);
        $this->assertSame('The harbour', get_post_meta($this->imageId, '_wp_attachment_image_alt', true));
        $this->assertStringContainsString('alt="The harbour in winter"', get_post($this->pageId)->post_content);
    }

    public function test_clears_only_the_page_where_the_media_library_has_other_wording(): void
    {
        update_post_meta($this->imageId, '_wp_attachment_image_alt', 'The harbour');
        $this->setContent($this->imageBlock($this->imageId, "{$this->uploads}/2026/11/harbour.jpg", 'Boats moored in the harbour at dusk'));

        $result = $this->sendAlt($this->clearing(['check' => 'image.alt_empty', 'current_value' => 'Boats moored in the harbour at dusk']));

        $this->assertSame('applied', $result['status']);
        $this->assertSame('The harbour', get_post_meta($this->imageId, '_wp_attachment_image_alt', true));
        $this->assertStringContainsString('alt=""', get_post($this->pageId)->post_content);
    }

    public function test_clears_alt_text_written_into_an_image_outside_the_media_library(): void
    {
        $source = 'https://other-site.example.net/wp-content/uploads/2020/05/quay.jpg';
        $this->setContent("<p><img src=\"{$source}\"></p>");
        $this->sendAlt(['target' => $this->target($source)]);

        $result = $this->sendAlt($this->clearing(['target' => $this->target($source), 'current_value' => 'Boats moored in the harbour at dusk']));

        $this->assertSame('applied', $result['status']);
        $this->assertStringNotContainsString('alt=', get_post($this->pageId)->post_content);
    }

    public function test_says_a_revert_for_a_theme_image_cannot_be_cleared(): void
    {
        $result = $this->sendAlt($this->clearing([
            'target' => $this->target(home_url('/wp-content/themes/harbour/logo.png')),
            'current_value' => 'Harbour logo',
        ]));

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('not_clearable', $result['reason']);
    }

    public function test_takes_the_normal_path_for_a_revert_with_no_or_blank_value(): void
    {
        $this->sendAlt();

        foreach ([null, '   '] as $suggested) {
            $result = $this->sendAlt(['suggested_value' => $suggested, 'revert' => true, 'current_value' => 'Boats moored in the harbour at dusk']);

            $this->assertSame('no_suggestion', $result['reason']);
        }

        $this->assertSame('Boats moored in the harbour at dusk', get_post_meta($this->imageId, '_wp_attachment_image_alt', true));
    }

    public function test_clears_nothing_on_a_dry_run_revert(): void
    {
        $this->sendAlt();

        $result = $this->send([$this->altChange($this->clearing(['current_value' => 'Boats moored in the harbour at dusk']))], true)[0];

        $this->assertSame('applied', $result['status']);
        $this->assertSame('Boats moored in the harbour at dusk', get_post_meta($this->imageId, '_wp_attachment_image_alt', true));
    }

    public function test_still_refuses_an_empty_suggestion_that_is_not_a_revert(): void
    {
        $this->sendAlt();

        $result = $this->sendAlt(['current_value' => 'Boats moored in the harbour at dusk', 'suggested_value' => '']);

        $this->assertSame('no_suggestion', $result['reason']);
        $this->assertSame('Boats moored in the harbour at dusk', get_post_meta($this->imageId, '_wp_attachment_image_alt', true));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function clearing(array $overrides = []): array
    {
        return ['suggested_value' => '', 'revert' => true, ...$overrides];
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
