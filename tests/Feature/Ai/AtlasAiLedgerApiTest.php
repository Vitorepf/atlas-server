<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\KernelLedgerEnvelopeInput;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasAiLedgerApiTest extends TestCase
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

    public function test_ledger_api_replays_envelope_and_includes_slo_summary(): void
    {
        $this->recordEvent('01HLEDGERAPI000000000000001', 'env_api', LedgerEventType::ExecutionStarted, [
            'job_id' => 'job-api',
        ]);
        $this->recordEvent('01HLEDGERAPI000000000000002', 'env_api', LedgerEventType::SloObserved, [
            'stage' => 'runtime.execute',
            'status' => 'warning',
            'severity' => 'high',
            'violations' => ['p95_budget_exceeded'],
            'slo' => [
                'stage' => 'runtime.execute',
                'duration_ms' => 650,
                'success' => true,
                'status' => 'warning',
                'severity' => 'high',
                'violations' => ['p95_budget_exceeded'],
            ],
        ]);

        $response = $this->getJson('/ai/ledger/env_api?slo=1&limit=10', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('envelope_id', 'env_api')
            ->assertJsonPath('event_count', 2)
            ->assertJsonPath('filters.limit', 10)
            ->assertJsonPath('filters.slo', true)
            ->assertJsonPath('events.0.event_type', LedgerEventType::ExecutionStarted->value)
            ->assertJsonPath('slo.observation_count', 1)
            ->assertJsonPath('slo.worst_status', 'warning');

        $this->assertSame(650, $response->json('slo.stages')['runtime.execute']['p95_ms']);
        $this->assertSame(['p95_budget_exceeded'], $response->json('slo.stages')['runtime.execute']['violations']);
    }

    public function test_ledger_api_can_include_repair_summary(): void
    {
        $this->recordEvent('01HLEDGERAPIREPAIR0000000001', 'env_api_repair', LedgerEventType::RepairInitiated, [
            'failure_classification' => [
                'failure_domain' => 'provider.timeout',
            ],
            'decision' => [
                'status' => 'repair_allowed',
                'strategy' => 'retry_provider',
                'reasons' => ['repair_planned'],
            ],
            'repair_executed' => false,
            'decision_hash' => 'decision-hash-api',
        ]);
        $this->recordEvent('01HLEDGERAPIREPAIR0000000002', 'env_api_repair', LedgerEventType::RepairCompleted, [
            'failure_classification' => [
                'failure_domain' => 'provider.timeout',
            ],
            'decision' => [
                'status' => 'repair_allowed',
                'strategy' => 'retry_provider',
                'reasons' => ['execution_blocked_by_dry_run'],
            ],
            'repair_executed' => false,
            'decision_hash' => 'decision-hash-api',
            'result_hash' => 'result-hash-api',
        ], causationId: 'decision-hash-api');

        $response = $this->getJson('/ai/ledger/env_api_repair?repair=1&limit=10', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('filters.repair', true)
            ->assertJsonPath('repair.repair_event_count', 2)
            ->assertJsonPath('repair.initiated_count', 1)
            ->assertJsonPath('repair.completed_count', 1)
            ->assertJsonPath('repair.latest_status', 'repair_allowed')
            ->assertJsonPath('repair.latest_strategy', 'retry_provider')
            ->assertJsonPath('repair.events.1.causation_id', 'decision-hash-api');

        $this->assertSame(['retry_provider' => 2], $response->json('repair.strategy_counts'));
    }

    public function test_ledger_api_uses_canonical_event_limit_contract(): void
    {
        foreach (range(1, 3) as $index) {
            $this->recordEvent('01HLEDGERAPILIMIT0000000'.$index, 'env_api_limit', LedgerEventType::ExecutionStarted, [
                'index' => $index,
            ]);
        }

        $this->getJson('/ai/ledger/env_api_limit?limit='.KernelLedgerEnvelopeInput::MAX_EVENT_LIMIT, $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('filters.limit', KernelLedgerEnvelopeInput::MAX_EVENT_LIMIT)
            ->assertJsonPath('event_count', 3);
    }

    public function test_ledger_api_can_include_kernel_pipeline_summary(): void
    {
        $this->recordEvent('01HLEDGERAPIKERNEL00000001', 'env_api_kernel', LedgerEventType::KernelPipelineAccepted, [
            'status' => 'accepted',
            'violations' => [],
            'pipeline' => [
                'pipeline_id' => 'pipe_api_kernel',
                'schema_version' => 'atlas.kernel.pipeline.scaffold.v1',
                'stage_count' => 14,
                'provider_execution_allowed' => false,
                'runtime_execution_allowed' => false,
            ],
            'surface' => [
                'surface_id' => 'atlas_cli_dev',
                'input_mode' => 'one_shot',
            ],
            'routing' => [
                'domain' => 'programming',
                'flow' => 'programming.dev',
            ],
        ]);

        $this->getJson('/ai/ledger/env_api_kernel?kernel=1&limit=10', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('filters.kernel', true)
            ->assertJsonPath('kernel_pipeline.kernel_pipeline_event_count', 1)
            ->assertJsonPath('kernel_pipeline.accepted_count', 1)
            ->assertJsonPath('kernel_pipeline.rejected_count', 0)
            ->assertJsonPath('kernel_pipeline.has_rejections', false)
            ->assertJsonPath('kernel_pipeline.health.status', 'ok')
            ->assertJsonPath('kernel_pipeline.health.rejection_rate', 0)
            ->assertJsonPath('kernel_pipeline.health.review_required', false)
            ->assertJsonPath('kernel_pipeline.events.0.flow', 'programming.dev');
    }

    public function test_ledger_api_requires_atlas_token(): void
    {
        $this->getJson('/ai/ledger/env_api')
            ->assertUnauthorized();
    }

    public function test_ledger_api_returns_service_unavailable_when_table_is_missing(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        $this->getJson('/ai/ledger/env_api', $this->headers)
            ->assertStatus(503)
            ->assertJsonPath('status', 'ledger_table_missing')
            ->assertJsonPath('event_count', 0);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function recordEvent(string $eventId, string $envelopeId, LedgerEventType $type, array $payload, ?string $causationId = null): void
    {
        AtlasLedgerEvent::query()->create([
            'event_id' => $eventId,
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'tenant_api',
            'operator_id' => 'operator_api',
            'envelope_id' => $envelopeId,
            'receipt_id' => null,
            'trace_id' => null,
            'correlation_id' => $envelopeId,
            'causation_id' => $causationId,
            'event_type' => $type->value,
            'emitter_stage' => match ($type) {
                LedgerEventType::SloObserved => 'atlas.slo',
                LedgerEventType::RepairInitiated, LedgerEventType::RepairCompleted => 'atlas.repair',
                default => 'ai.worker',
            },
            'emitter_version' => 'test.v1',
            'payload' => $payload,
            'payload_hash' => hash('sha256', $eventId),
            'occurred_at' => now(),
        ]);
    }
}
