<?php

class DraftsTest extends ReceiverTestCase
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

        register_post_type('team', ['public' => true, 'label' => 'Team', 'rewrite' => ['slug' => 'team'], 'supports' => ['title', 'editor']]);
        register_post_type('snippet', ['public' => true, 'label' => 'Snippets', 'rewrite' => ['slug' => 'snippet'], 'supports' => ['title']]);
        create_initial_taxonomies();
        flush_rewrite_rules();
    }

    public function tear_down(): void
    {
        _unregister_post_type('team');
        _unregister_post_type('snippet');
        WPSEO_Options::clear_cache();

        parent::tear_down();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function draft(array $overrides = []): array
    {
        return [
            'reference' => 'idea-42',
            'title' => 'Loft conversion costs',
            'meta_description' => 'What a loft conversion costs and why.',
            'body' => "Most cost between [GAP: typical range].\n\n## How long it takes\n\n[GAP: typical duration]",
            'collection' => 'post',
            ...$overrides,
        ];
    }

    private function signedDraft(string $body, ?int $timestamp = null): WP_REST_Request
    {
        $timestamp ??= time();
        $request = new WP_REST_Request('POST', '/altimiser/v1/drafts');

        $request->set_body($body);
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('X-Altimiser-Timestamp', (string) $timestamp);
        $request->set_header('X-Altimiser-Signature', $this->protocolSignature($body, $timestamp, self::$secret));

        return $request;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function sendDraft(array $overrides = []): WP_REST_Response
    {
        return $this->dispatch($this->signedDraft((string) json_encode($this->draft($overrides))));
    }

    private function postFor(string $reference): ?WP_Post
    {
        return get_posts([
            'post_type' => 'any',
            'post_status' => 'any',
            'meta_key' => '_altimiser_draft_reference',
            'meta_value' => $reference,
        ])[0] ?? null;
    }

    public function test_saves_the_draft_unpublished_in_the_named_post_type(): void
    {
        $response = $this->sendDraft(['collection' => 'team']);
        $post = $this->postFor('idea-42');

        $this->assertSame(200, $response->get_status());
        $this->assertSame('created', $response->get_data()['status']);
        $this->assertSame((string) $post->ID, $response->get_data()['id']);
        $this->assertSame('team', $response->get_data()['collection']);
        $this->assertSame('draft', $post->post_status);
        $this->assertSame('team', $post->post_type);
        $this->assertSame('Loft conversion costs', $post->post_title);
    }

    public function test_builds_the_edit_address_from_the_admin_address(): void
    {
        $response = $this->sendDraft();
        $id = $response->get_data()['id'];

        $this->assertSame(admin_url("post.php?post={$id}&action=edit"), $response->get_data()['edit_url']);
    }

    public function test_writes_the_body_as_blocks_with_every_gap_kept(): void
    {
        $this->sendDraft();
        $blocks = parse_blocks($this->postFor('idea-42')->post_content);

        $this->assertSame(['core/paragraph', 'core/heading', 'core/paragraph'], array_column(array_filter($blocks, fn (array $block): bool => $block['blockName'] !== null), 'blockName'));
        $this->assertStringContainsString('[GAP: typical range]', $this->postFor('idea-42')->post_content);
        $this->assertStringContainsString('[GAP: typical duration]', $this->postFor('idea-42')->post_content);
    }

    public function test_puts_the_meta_description_in_yoasts_field(): void
    {
        $this->sendDraft();

        $this->assertSame(
            'What a loft conversion costs and why.',
            get_post_meta($this->postFor('idea-42')->ID, '_yoast_wpseo_metadesc', true),
        );
    }

    public function test_keeps_backslashes_in_the_title_and_body(): void
    {
        $this->sendDraft(['title' => 'Files in C:\temp', 'body' => 'Saved in C:\temp\new.']);
        $post = $this->postFor('idea-42');

        $this->assertSame('Files in C:\temp', $post->post_title);
        $this->assertStringContainsString('C:\temp\new.', $post->post_content);
    }

    public function test_sending_the_same_reference_again_rewrites_the_same_post(): void
    {
        $first = $this->sendDraft()->get_data();
        $second = $this->sendDraft(['title' => 'Loft conversion prices', 'body' => 'A new body.'])->get_data();
        $post = $this->postFor('idea-42');

        $this->assertSame('created', $first['status']);
        $this->assertSame('updated', $second['status']);
        $this->assertSame($first['id'], $second['id']);
        $this->assertSame('Loft conversion prices', $post->post_title);
        $this->assertStringContainsString('A new body.', $post->post_content);
        $this->assertCount(1, get_posts(['post_type' => 'post', 'post_status' => 'any', 'meta_key' => '_altimiser_draft_reference']));
    }

    public function test_leaves_a_draft_an_editor_has_worked_on_alone_and_says_so(): void
    {
        $id = (int) $this->sendDraft()->get_data()['id'];
        wp_update_post(['ID' => $id, 'post_content' => 'Most cost between 30,000 and 60,000 pounds.']);

        $response = $this->sendDraft(['body' => 'Overwritten body.']);

        $this->assertSame('refused', $response->get_data()['status']);
        $this->assertSame('edited', $response->get_data()['reason']);
        $this->assertSame('Most cost between 30,000 and 60,000 pounds.', get_post($id)->post_content);
    }

    public function test_leaves_a_published_draft_alone_and_says_so(): void
    {
        $id = (int) $this->sendDraft()->get_data()['id'];
        wp_update_post(['ID' => $id, 'post_status' => 'publish', 'post_title' => 'Edited by an editor']);

        $response = $this->sendDraft(['title' => 'Overwritten', 'body' => 'Overwritten body.']);
        $post = get_post($id);

        $this->assertSame('refused', $response->get_data()['status']);
        $this->assertSame('already_published', $response->get_data()['reason']);
        $this->assertNotSame('', $response->get_data()['message']);
        $this->assertSame('Edited by an editor', $post->post_title);
        $this->assertStringNotContainsString('Overwritten', $post->post_content);
        $this->assertSame('publish', $post->post_status);
    }

    public function test_saves_beside_the_page_it_expands_with_a_draft_slug_and_the_same_parent(): void
    {
        $parent = self::factory()->post->create(['post_type' => 'page', 'post_name' => 'services']);
        $page = self::factory()->post->create(['post_type' => 'page', 'post_name' => 'lofts', 'post_parent' => $parent]);

        $response = $this->sendDraft(['collection' => null, 'expands' => get_permalink($page)]);
        $draft = $this->postFor('idea-42');

        $this->assertSame('created', $response->get_data()['status']);
        $this->assertSame('page', $draft->post_type);
        $this->assertSame($parent, $draft->post_parent);
        $this->assertSame('lofts-draft', $draft->post_name);
        $this->assertSame('draft', $draft->post_status);
        $this->assertSame('publish', get_post($page)->post_status);
    }

    public function test_refuses_when_nothing_lives_at_the_page_to_expand(): void
    {
        $response = $this->sendDraft(['collection' => null, 'expands' => home_url('/nowhere/')]);

        $this->assertSame('refused', $response->get_data()['status']);
        $this->assertSame('page_not_found', $response->get_data()['reason']);
        $this->assertNull($this->postFor('idea-42'));
    }

    public function test_refuses_a_collection_the_site_does_not_have(): void
    {
        $response = $this->sendDraft(['collection' => 'recipes']);

        $this->assertSame('collection_not_found', $response->get_data()['reason']);
        $this->assertNull($this->postFor('idea-42'));
    }

    public function test_refuses_a_post_type_that_is_not_a_destination(): void
    {
        $this->assertSame('collection_not_found', $this->sendDraft(['collection' => 'attachment'])->get_data()['reason']);
        $this->assertSame('collection_not_found', $this->sendDraft(['collection' => 'revision'])->get_data()['reason']);
    }

    public function test_refuses_a_post_type_with_no_editor_for_the_body(): void
    {
        $response = $this->sendDraft(['collection' => 'snippet']);

        $this->assertSame('refused', $response->get_data()['status']);
        $this->assertSame('no_body_field', $response->get_data()['reason']);
        $this->assertNull($this->postFor('idea-42'));
    }

    public function test_refuses_an_empty_body(): void
    {
        $response = $this->dispatch($this->signedDraft(''));

        $this->assertSame(422, $response->get_status());
        $this->assertSame('altimiser_unreadable', $response->get_data()['code']);
    }

    public function test_refuses_an_empty_object(): void
    {
        $this->assertSame(422, $this->dispatch($this->signedDraft('{}'))->get_status());
    }

    /**
     * @dataProvider invalidDrafts
     *
     * @param  array<string, mixed>  $overrides
     */
    public function test_refuses_a_draft_outside_the_limits_of_the_protocol(array $overrides): void
    {
        $response = $this->sendDraft($overrides);

        $this->assertSame(422, $response->get_status());
        $this->assertNull($this->postFor('idea-42'));
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function invalidDrafts(): array
    {
        return [
            'no reference' => [['reference' => null]],
            'reference too long' => [['reference' => str_repeat('a', 65)]],
            'no title' => [['title' => '']],
            'title too long' => [['title' => str_repeat('a', 201)]],
            'meta description too long' => [['meta_description' => str_repeat('a', 501)]],
            'no body' => [['body' => null]],
            'body too long' => [['body' => str_repeat('a', 100001)]],
            'no collection and no page' => [['collection' => null]],
            'collection too long' => [['collection' => str_repeat('a', 101)]],
            'expands too long' => [['expands' => str_repeat('a', 2001)]],
            'title not text' => [['title' => ['a']]],
        ];
    }

    public function test_accepts_the_limits_of_the_protocol(): void
    {
        $response = $this->sendDraft([
            'reference' => str_repeat('a', 64),
            'title' => str_repeat('a', 200),
            'meta_description' => str_repeat('a', 500),
            'body' => str_repeat('a', 100000),
        ]);

        $this->assertSame('created', $response->get_data()['status']);
    }

    public function test_accepts_a_draft_without_a_meta_description(): void
    {
        $response = $this->sendDraft(['meta_description' => null]);

        $this->assertSame('created', $response->get_data()['status']);
        $this->assertSame('', get_post_meta((int) $response->get_data()['id'], '_yoast_wpseo_metadesc', true));
    }

    public function test_refuses_an_unsigned_request(): void
    {
        $request = new WP_REST_Request('POST', '/altimiser/v1/drafts');
        $request->set_body((string) json_encode($this->draft()));

        $this->assertSame(401, $this->dispatch($request)->get_status());
        $this->assertNull($this->postFor('idea-42'));
    }

    public function test_leaves_content_filtering_as_it_found_it(): void
    {
        kses_init_filters();

        $this->sendDraft();

        $this->assertNotFalse(has_filter('content_save_pre', 'wp_filter_post_kses'));

        kses_remove_filters();

        $this->sendDraft(['reference' => 'idea-43']);

        $this->assertFalse(has_filter('content_save_pre', 'wp_filter_post_kses'));
    }

    public function test_keeps_the_draft_through_content_filtering_for_a_user_without_unfiltered_html(): void
    {
        kses_init_filters();

        $this->sendDraft(['body' => "[Read more](https://example.com/more?a=1&b=2) in the guide.\n\n---"]);
        $content = $this->postFor('idea-42')->post_content;

        $this->assertStringContainsString('<a href="https://example.com/more?a=1&#038;b=2">Read more</a>', $content);
        $this->assertStringContainsString('has-alpha-channel-opacity"/>', $content);
    }

    public function test_advertises_the_draft_capability(): void
    {
        $this->assertContains('content.draft', $this->dispatch($this->signedRequest())->get_data()['capabilities']);
    }

    public function test_saves_without_a_meta_description_field_when_yoast_is_not_active(): void
    {
        $receiver = new AltDesign\Altimiser\Receiver(dirname(__DIR__).'/altimiser.php', null);

        $response = $receiver->drafts($this->signedDraft((string) json_encode($this->draft())));

        $this->assertSame('created', $response->get_data()['status']);
        $this->assertSame('', get_post_meta((int) $response->get_data()['id'], '_yoast_wpseo_metadesc', true));
    }
}
