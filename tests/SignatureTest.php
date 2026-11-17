<?php

class SignatureTest extends ReceiverTestCase
{
    public static function set_up_before_class(): void
    {
        parent::set_up_before_class();

        self::configureSecret();
    }

    public function test_signs_the_worked_examples_in_the_protocol_document_as_written(): void
    {
        $this->assertSame(
            'sha256=ef07a9598c6f526653ef164bfea93355c38387922e618da567a7f81c7dc23da2',
            $this->protocolSignature('', 1769558400, 'shared-secret'),
        );

        $this->assertSame(
            'sha256=d123b015483dd751afbf1e6947eee680c5afa912923d41951d064accfd7c6861',
            $this->protocolSignature('{"dry_run":false,"changes":[]}', 1769558400, 'shared-secret'),
        );
    }

    public function test_logs_one_refused_signed_request_a_minute_and_none_without_a_signature(): void
    {
        $log = (string) tempnam(sys_get_temp_dir(), 'altimiser');
        $previous = ini_set('error_log', $log);

        $this->dispatch($this->request());
        $this->dispatch($this->signedRequest(secret: 'not-the-secret'));
        $this->dispatch($this->signedRequest(secret: 'not-the-secret-either'));

        ini_set('error_log', (string) $previous);
        $logged = (string) file_get_contents($log);
        unlink($log);

        $this->assertSame(1, substr_count($logged, 'Altimiser refused a signed request'));
        $this->assertStringContainsString('/altimiser/v1/health: Signature does not match.', $logged);
    }

    public function test_accepts_a_signed_request_with_an_empty_body(): void
    {
        $this->assertSame(200, $this->dispatch($this->signedRequest())->get_status());
    }

    public function test_accepts_a_signed_request_with_a_body(): void
    {
        $this->assertSame(200, $this->dispatch($this->signedRequest('{"changes":[]}'))->get_status());
    }

    public function test_rejects_a_body_altered_after_signing(): void
    {
        $request = $this->signedRequest('{"changes":[]}');

        $request->set_body('{"changes":["injected"]}');

        $this->assertRefused($this->dispatch($request), 'Signature does not match.');
    }

    public function test_rejects_a_timestamp_altered_after_signing(): void
    {
        $timestamp = time();

        $request = $this->request('', (string) ($timestamp + 1), $this->protocolSignature('', $timestamp, self::$secret));

        $this->assertRefused($this->dispatch($request), 'Signature does not match.');
    }

    public function test_rejects_a_signature_made_with_the_wrong_secret(): void
    {
        $this->assertRefused(
            $this->dispatch($this->signedRequest(secret: 'not-the-secret')),
            'Signature does not match.',
        );
    }

    public function test_rejects_a_signature_without_its_prefix(): void
    {
        $timestamp = time();

        $request = $this->request('', (string) $timestamp, hash_hmac('sha256', "{$timestamp}\n", self::$secret));

        $this->assertRefused($this->dispatch($request), 'Signature does not match.');
    }

    public function test_rejects_a_replayed_request_outside_the_window(): void
    {
        $this->assertRefused(
            $this->dispatch($this->signedRequest(timestamp: time() - 600)),
            'Signature timestamp is outside the accepted window.',
        );
    }

    public function test_rejects_a_request_dated_too_far_ahead(): void
    {
        $this->assertRefused(
            $this->dispatch($this->signedRequest(timestamp: time() + 600)),
            'Signature timestamp is outside the accepted window.',
        );
    }

    public function test_accepts_a_request_near_the_edge_of_the_window_on_either_side(): void
    {
        $this->assertSame(200, $this->dispatch($this->signedRequest(timestamp: time() - 290))->get_status());
        $this->assertSame(200, $this->dispatch($this->signedRequest(timestamp: time() + 290))->get_status());
    }

    public function test_rejects_an_unsigned_request(): void
    {
        $this->assertRefused(
            $this->dispatch($this->request()),
            'Signature timestamp is outside the accepted window.',
        );
    }

    public function test_rejects_a_timestamp_with_no_signature(): void
    {
        $this->assertRefused(
            $this->dispatch($this->request('', (string) time())),
            'Signature does not match.',
        );
    }
}
