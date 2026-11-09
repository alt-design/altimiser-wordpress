<?php

class ChangeRequestHandlingTest extends ReceiverTestCase
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

        $pageId = self::factory()->post->create(['post_type' => 'page', 'post_name' => 'about', 'post_title' => 'About']);

        update_post_meta($pageId, '_yoast_wpseo_metadesc', 'About us.');
    }

    public function test_refuses_an_unsigned_request(): void
    {
        $request = new WP_REST_Request('POST', '/altimiser/v1/changes');

        $request->set_body('{"changes":[]}');

        $this->assertSame(401, $this->dispatch($request)->get_status());
    }

    public function test_refuses_a_body_altered_after_signing(): void
    {
        $request = $this->signedPost('{"dry_run":false,"changes":[]}');

        $request->set_body('{"dry_run":false,"changes":[{}]}');

        $this->assertRefused($this->dispatch($request), 'Signature does not match.');
    }

    public function test_takes_the_worked_example_in_the_protocol_document(): void
    {
        $response = $this->dispatch($this->signedPost('{"dry_run":false,"changes":[]}'));

        $this->assertSame(200, $response->get_status());
        $this->assertSame([], $response->get_data()['results']);
    }

    /**
     * @dataProvider unreadableBodies
     */
    public function test_answers_a_body_it_cannot_read_with_a_422(string $body): void
    {
        $response = $this->dispatch($this->signedPost($body));

        $this->assertSame(422, $response->get_status());
        $this->assertArrayHasKey('message', $response->get_data());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unreadableBodies(): array
    {
        return [
            'empty' => [''],
            'no changes' => ['{"dry_run":true}'],
            'changes that are not a list' => ['{"changes":"all of them"}'],
            'a dry run that is not a boolean' => ['{"dry_run":"no","changes":[]}'],
        ];
    }

    public function test_leaves_wordpress_to_refuse_a_body_that_is_not_json(): void
    {
        $this->assertSame(400, $this->dispatch($this->signedPost('changes'))->get_status());
    }

    public function test_answers_every_change_by_the_id_it_was_given(): void
    {
        $results = $this->send([$this->change(['id' => 'first']), $this->change(['id' => 'second'])]);

        $this->assertSame(['first', 'second'], array_column($results, 'id'));
    }

    /**
     * @dataProvider malformedChanges
     */
    public function test_fails_a_malformed_change_and_processes_the_rest(mixed $malformed, string $expectedId): void
    {
        $results = $this->send([$malformed, $this->change(['id' => 'good'])]);

        $this->assertSame($expectedId, $results[0]['id']);
        $this->assertSame('failed', $results[0]['status']);
        $this->assertSame('invalid_change', $results[0]['reason']);
        $this->assertSame('good', $results[1]['id']);
        $this->assertSame('applied', $results[1]['status']);
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function malformedChanges(): array
    {
        return [
            'not an object' => ['nonsense', 'index-0'],
            'no id' => [['check' => 'title.too_long', 'url' => 'https://example.org/'], 'index-0'],
            'no check' => [['id' => 'bad', 'url' => 'https://example.org/'], 'bad'],
            'no url' => [['id' => 'bad', 'check' => 'title.too_long'], 'bad'],
            'a target that is not an object' => [['id' => 'bad', 'check' => 'title.too_long', 'url' => 'https://example.org/', 'target' => 'head'], 'bad'],
            'a value that is not text' => [['id' => 'bad', 'check' => 'title.too_long', 'url' => 'https://example.org/', 'current_value' => 12], 'bad'],
        ];
    }

    public function test_skips_a_check_it_has_no_transform_for(): void
    {
        $result = $this->sendOne(['check' => 'something.we.do.not.do']);

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('unsupported_check', $result['reason']);
    }

    public function test_skips_a_url_that_maps_to_nothing_editable(): void
    {
        $result = $this->sendOne(['url' => home_url('/no-such-page/')]);

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('not_found', $result['reason']);
    }

    public function test_skips_a_published_post_of_a_type_with_no_page_of_its_own(): void
    {
        $this->set_permalink_structure('');

        $menuItem = self::factory()->post->create(['post_type' => 'nav_menu_item', 'post_status' => 'publish']);

        $result = $this->sendOne(['url' => home_url("/?p={$menuItem}")]);

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('not_found', $result['reason']);
        $this->assertSame('', get_post_meta($menuItem, '_yoast_wpseo_metadesc', true));
    }

    public function test_reports_a_write_that_threw_as_failed_and_carries_on(): void
    {
        $failOnce = static function (): void {
            throw new RuntimeException('The database went away.');
        };

        add_action('update_post_metadata', $failOnce);

        [$first] = $this->send([$this->change()]);

        remove_action('update_post_metadata', $failOnce);

        $this->assertSame('failed', $first['status']);
        $this->assertSame('write_failed', $first['reason']);
    }

    public function test_attempts_ten_changes_and_defers_the_rest(): void
    {
        $changes = array_map(
            fn (int $index): array => $this->change(['id' => "change-{$index}"]),
            range(1, 15),
        );

        $results = $this->send($changes);

        $this->assertCount(15, $results);
        $this->assertSame(range(1, 15), array_map(static fn (array $result): int => (int) substr($result['id'], 7), $results));
        $this->assertCount(5, array_filter($results, static fn (array $result): bool => $result['status'] === 'deferred'));
    }

    public function test_takes_its_batch_size_from_a_filter(): void
    {
        add_filter('altimiser_batch_size', static fn (): int => 2);

        $results = $this->send([$this->change(), $this->change(), $this->change()]);

        $this->assertSame('deferred', $results[2]['status']);
        $this->assertNotSame('deferred', $results[1]['status']);
    }

    public function test_stops_starting_changes_once_the_time_budget_is_spent(): void
    {
        add_filter('altimiser_time_budget', static fn (): int => -1);

        $results = $this->send([$this->change(), $this->change(), $this->change()]);

        $this->assertNotSame('deferred', $results[0]['status']);
        $this->assertSame('deferred', $results[1]['status']);
        $this->assertSame('deferred', $results[2]['status']);
    }

    public function test_always_attempts_one_change_so_a_batch_cannot_stall(): void
    {
        add_filter('altimiser_batch_size', static fn (): int => 0);

        $this->assertNotSame('deferred', $this->sendOne()['status']);
    }

    public function test_keeps_its_time_budget_below_the_execution_limit(): void
    {
        $original = ini_get('max_execution_time');

        ini_set('max_execution_time', '30');

        $budget = (new AltDesign\Altimiser\ChangeDispatcher([], new AltDesign\Altimiser\PageCache()))->timeBudget();

        ini_set('max_execution_time', $original);

        $this->assertSame(15.0, $budget);
    }
}
