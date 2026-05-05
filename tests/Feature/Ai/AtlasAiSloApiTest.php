<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasAiSloApiTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_slo_api_returns_filtered_window_summary(): void
    {
        $this->recordSlo('01HSLOAPI000000000000000000001', 'env_programming', 'programming', 'atlas_cli_dev', 'codex_cli', 'warning', true, 120000);
        $this->recordSlo('01HSLOAPI000000000000000000002', 'env_finance', 'finance', 'atlas_api', 'claude_cli', 'breach', false, 999000);

        $response = $this->getJson('/ai/slo?hours=24&domain=programming&provider=codex_cli', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('filters.domain', 'programming')
            ->assertJsonPath('filters.provider', 'codex_cli')
            ->assertJsonPath('kernel_slo.available', true)
            ->assertJsonPath('kernel_slo.filters.domain', 'programming')
            ->assertJsonPath('kernel_slo.filters.provider', 'codex_cli')
            ->assertJsonPath('kernel_slo.observation_count', 1)
            ->assertJsonPath('kernel_slo.envelope_count', 1)
            ->assertJsonPath('kernel_slo.worst_status', 'warning')
            ->assertJsonPath('kernel_slo.dimensions.domain.programming', 1)
            ->assertJsonPath('kernel_slo.dimensions.provider.codex_cli', 1);

        $this->assertSame(120000, $response->json('kernel_slo.stages')['runtime.execute']['p95_ms']);
        $this->assertSame(['latency_above_p95'], $response->json('kernel_slo.stages')['runtime.execute']['violations']);
    }

    public function test_slo_api_requires_atlas_token(): void
    {
        $this->getJson('/ai/slo')
            ->assertUnauthorized();
    }

    public function test_slo_api_returns_service_unavailable_when_ledger_table_is_missing(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        $this->getJson('/ai/slo', $this->headers)
            ->assertStatus(503)
            ->assertJsonPath('status', 'ledger_unavailable')
            ->assertJsonPath('kernel_slo.available', false);
    }

    private function recordSlo(
        string $eventId,
        string $envelopeId,
        string $domain,
        string $surface,
        string $provider,
        string $status,
        bool $success,
        int $durationMs,
    ): void {
        AtlasLedgerEvent::query()->create([
            'event_id' => $eventId,
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'tenant_slo_api',
            'operator_id' => 'operator_slo_api',
            'envelope_id' => $envelopeId,
            'receipt_id' => null,
            'trace_id' => null,
            'correlation_id' => $envelopeId,
            'causation_id' => null,
            'event_type' => LedgerEventType::SloObserved->value,
            'emitter_stage' => 'atlas.slo',
            'emitter_version' => 'atlas.slo.v1',
            'payload' => [
                'stage' => 'runtime.execute',
                'status' => $status,
                'severity' => 'high',
                'violations' => $success ? ['latency_above_p95'] : ['stage_failed'],
                'dimensions' => [
                    'domain' => $domain,
                    'surface_id' => $surface,
                    'provider' => $provider,
                    'model' => $provider === 'codex_cli' ? 'gpt-5.2' : 'claude-sonnet',
                ],
                'slo' => [
                    'stage' => 'runtime.execute',
                    'duration_ms' => $durationMs,
                    'success' => $success,
                    'status' => $status,
                    'severity' => 'high',
                    'violations' => $success ? ['latency_above_p95'] : ['stage_failed'],
                ],
            ],
            'payload_hash' => hash('sha256', $eventId),
            'occurred_at' => now(),
        ]);
    }
}
