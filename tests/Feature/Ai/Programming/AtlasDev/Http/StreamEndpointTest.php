<?php

namespace Tests\Feature\Ai\Programming\AtlasDev\Http;

use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;

final class StreamEndpointTest extends AtlasDevHttpTestCase
{
    public function test_stream_returns_404_when_run_not_persisted(): void
    {
        $this->withHeaders($this->headers)
            ->get('/ai/interactions/atlas-dev/runs/dev-1111111111111-deadbeef/stream')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'RUN_NOT_FOUND');
    }

    public function test_stream_emits_event_stream_with_phase_events_for_persisted_plan(): void
    {
        $plan = $this->postPlan($this->defaultRepairPayload());
        $runId = $plan['run_id'];

        $response = $this->withHeaders($this->headers)
            ->get('/ai/interactions/atlas-dev/runs/'.$runId.'/stream');

        $response->assertStatus(200);
        $this->assertStringContainsString('text/event-stream', (string) $response->headers->get('Content-Type'));
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-cache', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertSame('no', $response->headers->get('X-Accel-Buffering'));
        $this->assertSame('close', $response->headers->get('Connection'));
        $this->assertSame('snapshot-replay-then-close', $response->headers->get('X-Atlas-Stream-Mode'));

        $body = $response->streamedContent();
        $this->assertStringContainsString('event: phase', $body);
        $this->assertStringContainsString('plan_persisted', $body);
        $this->assertStringContainsString('task_contract_ready', $body);
        $this->assertStringContainsString('route_decided', $body);
        $this->assertStringContainsString('event: stream_closed', $body);
        $this->assertStringContainsString('snapshot_complete', $body);
        $this->assertStringContainsString('poll_rest_show_endpoint', $body);
    }

    public function test_rest_fallback_works_after_stream_closes(): void
    {
        // After the SSE snapshot closes the client must still be able to poll
        // REST to advance state — proving the SSE→REST handoff still works
        // end to end without any worker remaining pinned on the stream.
        $plan = $this->postPlan($this->defaultRepairPayload());
        $runId = $plan['run_id'];

        $streamBody = (string) $this->withHeaders($this->headers)
            ->get('/ai/interactions/atlas-dev/runs/'.$runId.'/stream')
            ->streamedContent();
        $this->assertStringContainsString('event: stream_closed', $streamBody);

        $this->withHeaders($this->headers)
            ->get('/ai/interactions/atlas-dev/runs/'.$runId)
            ->assertStatus(200)
            ->assertJsonPath('data.run_id', $runId);
    }

    public function test_stream_closes_promptly_without_keepalive_loop(): void
    {
        // Per F-01: stream is snapshot-replay-then-close; no keepalive loop is
        // emitted, no PHP-FPM worker is pinned. The client polls REST for
        // ongoing progress via the show endpoint.
        $plan = $this->postPlan($this->defaultRepairPayload());
        $runId = $plan['run_id'];

        $start = microtime(true);
        $response = $this->withHeaders($this->headers)
            ->get('/ai/interactions/atlas-dev/runs/'.$runId.'/stream');
        $body = (string) $response->streamedContent();
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(1.5, $elapsed, 'Stream must close promptly; no keepalive loop expected.');
        $this->assertStringNotContainsString(': keepalive', $body);
        $this->assertStringContainsString('event: stream_closed', $body);
        $this->assertSame('snapshot-replay-then-close', $response->headers->get('X-Atlas-Stream-Mode'));
    }

    public function test_stream_receipt_event_includes_full_receipt_payload(): void
    {
        $plan = $this->postPlan($this->defaultRepairPayload());
        $runId = $plan['run_id'];

        $this->app->make(ReceiptStorage::class)->writeAtomic(
            $runId,
            ArtifactNames::VERIFICATION_RECEIPT,
            [
                'completion' => [
                    'honesty_flags' => [],
                    'residual_risks' => [],
                    'status' => 'passed',
                ],
                'gates' => [
                    [
                        'evidence_ref' => null,
                        'fresh' => true,
                        'name' => 'verification_gate',
                        'required' => true,
                        'status' => 'passed',
                        'waiver_reason' => null,
                    ],
                ],
                'receipt_hash' => str_repeat('a', 64),
                'run_id' => $runId,
                'task_contract_hash' => $plan['hashes']['task_contract'],
                'tests' => [
                    [
                        'command' => 'composer test',
                        'duration_ms' => 42,
                        'exit_code' => 0,
                        'ok' => true,
                        'output_hash' => str_repeat('e', 64),
                        'output_path' => null,
                    ],
                ],
            ],
        );

        $body = (string) $this->withHeaders($this->headers)
            ->get('/ai/interactions/atlas-dev/runs/'.$runId.'/stream')
            ->streamedContent();

        $this->assertStringContainsString('event: receipt', $body);
        $this->assertStringContainsString('"receipt":{', $body);
        $this->assertStringContainsString('"tests":[', $body);
        $this->assertStringContainsString('composer test', $body);
    }
}
