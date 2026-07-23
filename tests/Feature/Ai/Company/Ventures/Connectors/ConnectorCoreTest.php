<?php

namespace Tests\Feature\Ai\Company\Ventures\Connectors;

use App\Services\Ai\Company\Ventures\Connectors\FeedLivenessGate;
use App\Services\Ai\Company\Ventures\Connectors\WebhookSignatureVerifier;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ConnectorCoreTest extends TestCase
{
    public function test_never_seen_feed_freezes_consumers(): void
    {
        $gate = new FeedLivenessGate;
        $this->assertSame(FeedLivenessGate::NEVER, $gate->status(null, 300));
        $this->assertTrue($gate->shouldFreezeConsumers(null, 300), 'no event ever != real zero');
    }

    public function test_live_feed_does_not_freeze(): void
    {
        $gate = new FeedLivenessGate;
        $now = Carbon::parse('2026-06-17T12:00:00Z');
        $this->assertSame(FeedLivenessGate::LIVE, $gate->status($now->copy()->subSeconds(60), 300, $now));
        $this->assertFalse($gate->shouldFreezeConsumers($now->copy()->subSeconds(60), 300, $now));
    }

    public function test_stale_feed_freezes(): void
    {
        $gate = new FeedLivenessGate;
        $now = Carbon::parse('2026-06-17T12:00:00Z');
        $this->assertSame(FeedLivenessGate::STALE, $gate->status($now->copy()->subSeconds(900), 300, $now));
        $this->assertTrue($gate->shouldFreezeConsumers($now->copy()->subSeconds(900), 300, $now), 'outage must not read as zero');
    }

    public function test_valid_signature_yields_source(): void
    {
        $v = new WebhookSignatureVerifier;
        $payload = '{"id":"evt_1","amount":1000}';
        $secret = 'whsec_test';
        $sig = hash_hmac('sha256', $payload, $secret);

        $this->assertTrue($v->verify($payload, $sig, $secret));
        $this->assertSame('payment_processor', $v->verifiedSource($payload, $sig, $secret, 'payment_processor'));
    }

    public function test_bad_signature_yields_null_source(): void
    {
        $v = new WebhookSignatureVerifier;
        $payload = '{"id":"evt_1"}';
        $this->assertFalse($v->verify($payload, 'deadbeef', 'whsec_test'));
        $this->assertNull($v->verifiedSource($payload, 'deadbeef', 'whsec_test', 'payment_processor'), 'externally-POSTed webhook cannot mint a verified source');
    }

    public function test_empty_secret_fails_closed(): void
    {
        $v = new WebhookSignatureVerifier;
        $this->assertFalse($v->verify('x', 'y', ''));
    }
}
