<?php

use AltDesign\Altimiser\Settings;

/**
 * A constant cannot be undefined or redefined, so each test needs a process of its own.
 *
 * @runTestsInSeparateProcesses
 *
 * @preserveGlobalState disabled
 */
class SecretSourcesTest extends ReceiverTestCase
{
    private string $longSecret = 'a-shared-secret-of-at-least-32-characters';

    public function tear_down(): void
    {
        putenv('ALTIMISER_SECRET');

        parent::tear_down();
    }

    public function test_refuses_every_request_while_the_secret_is_shorter_than_32_characters(): void
    {
        define('ALTIMISER_SECRET', 'shared-secret');

        $this->assertRefused(
            $this->dispatch($this->signedRequest(secret: 'shared-secret')),
            'The Altimiser secret on this site is shorter than 32 characters, so requests are refused until it is replaced with a longer one.',
        );
    }

    public function test_accepts_a_secret_of_exactly_32_characters(): void
    {
        $secret = str_repeat('s', 32);

        define('ALTIMISER_SECRET', $secret);

        $this->assertSame(200, $this->dispatch($this->signedRequest(secret: $secret))->get_status());
    }

    public function test_reads_the_secret_from_the_environment(): void
    {
        putenv("ALTIMISER_SECRET={$this->longSecret}");

        $this->assertSame(200, $this->dispatch($this->signedRequest(secret: $this->longSecret))->get_status());
    }

    public function test_reads_the_secret_from_the_settings_page(): void
    {
        update_option(Settings::$option, ['secret' => $this->longSecret]);

        $this->assertSame(200, $this->dispatch($this->signedRequest(secret: $this->longSecret))->get_status());
    }

    public function test_prefers_the_constant_to_the_settings_page(): void
    {
        define('ALTIMISER_SECRET', $this->longSecret);
        update_option(Settings::$option, ['secret' => str_repeat('o', 40)]);

        $this->assertSame(200, $this->dispatch($this->signedRequest(secret: $this->longSecret))->get_status());
        $this->assertSame(401, $this->dispatch($this->signedRequest(secret: str_repeat('o', 40)))->get_status());
    }
}
