<?php

/**
 * A constant cannot be undefined or redefined, so each test needs a process of its own.
 *
 * @runTestsInSeparateProcesses
 *
 * @preserveGlobalState disabled
 */
class NoSecretTest extends ReceiverTestCase
{
    public function test_refuses_a_correctly_signed_request_when_no_secret_is_configured(): void
    {
        $this->assertRefused(
            $this->dispatch($this->signedRequest()),
            'Altimiser is not configured on this site.',
        );
    }

    /**
     * @dataProvider guessableSecrets
     */
    public function test_refuses_a_request_signed_with_a_secret_anyone_could_guess(mixed $configured, string $signedWith): void
    {
        define('ALTIMISER_SECRET', $configured);

        $this->assertRefused(
            $this->dispatch($this->signedRequest(secret: $signedWith)),
            'Altimiser is not configured on this site.',
        );
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function guessableSecrets(): array
    {
        return [
            'empty' => ['', ''],
            'whitespace' => ['   ', '   '],
            'true' => [true, '1'],
            'a number' => [12345, '12345'],
        ];
    }
}
