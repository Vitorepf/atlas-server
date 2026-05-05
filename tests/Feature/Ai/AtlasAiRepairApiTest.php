<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasAiRepairApiTest extends TestCase
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

    public function test_repair_api_returns_scaffold_plan_without_execution(): void
    {
        $response = $this->postJson('/ai/repair', [
            'envelope_id' => 'env_repair_api',
            'receipt_id' => 'receipt_repair_api',
            'failure_domain' => 'provider.timeout',
            'source' => 'feature_test',
            'signals' => ['provider timed out'],
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'planned_scaffold')
            ->assertJsonPath('request.envelope_id', 'env_repair_api')
            ->assertJsonPath('request.failure_classification.failure_domain', 'provider.timeout')
            ->assertJsonPath('repair.status', 'repair_allowed')
            ->assertJsonPath('repair.strategy', 'retry_provider')
            ->assertJsonPath('repair.reasons.0', 'repair_planned')
            ->assertJsonPath('repair.metadata.executes_repair', false)
            ->assertJsonPath('evidence_ledger.recorded', true)
            ->assertJsonPath('evidence_ledger.event_type', LedgerEventType::RepairInitiated->value)
            ->assertJsonPath('evidence_ledger.emitter_stage', 'atlas_api.repair')
            ->assertJsonPath('compliance.execution_enabled', false)
            ->assertJsonPath('compliance.ok', true);

        $this->assertIsString($response->json('repair.evidence_payload.decision_hash'));
        $this->assertFalse($response->json('repair.evidence_payload.repair_executed'));
        $this->assertDatabaseHas('atlas_ledger_events', [
            'envelope_id' => 'env_repair_api',
            'receipt_id' => 'receipt_repair_api',
            'event_type' => LedgerEventType::RepairInitiated->value,
            'emitter_stage' => 'atlas_api.repair',
        ]);
    }

    public function test_repair_api_attempt_is_scaffolded_and_blocked_by_dry_run(): void
    {
        $this->postJson('/ai/repair', [
            'envelope_id' => 'env_repair_api_attempt',
            'failure_domain' => 'provider.timeout',
            'attempt' => true,
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'attempted_scaffold')
            ->assertJsonPath('repair.decision.status', 'repair_allowed')
            ->assertJsonPath('repair.executed', false)
            ->assertJsonPath('repair.attempt.executed', false)
            ->assertJsonPath('repair.attempt.dry_run', true)
            ->assertJsonPath('repair.attempt.reasons.0', 'execution_blocked_by_dry_run')
            ->assertJsonPath('evidence_ledger.recorded', true)
            ->assertJsonPath('evidence_ledger.event_type', LedgerEventType::RepairInitiated->value)
            ->assertJsonPath('evidence_ledger.completed.recorded', true)
            ->assertJsonPath('evidence_ledger.completed.event_type', LedgerEventType::RepairCompleted->value)
            ->assertJsonPath('evidence_ledger.completed.emitter_stage', 'atlas_api.repair')
            ->assertJsonPath('compliance.execution_enabled', false);

        $this->assertDatabaseHas('atlas_ledger_events', [
            'envelope_id' => 'env_repair_api_attempt',
            'event_type' => LedgerEventType::RepairInitiated->value,
            'emitter_stage' => 'atlas_api.repair',
        ]);
        $this->assertDatabaseHas('atlas_ledger_events', [
            'envelope_id' => 'env_repair_api_attempt',
            'event_type' => LedgerEventType::RepairCompleted->value,
            'emitter_stage' => 'atlas_api.repair',
        ]);
    }

    public function test_repair_api_blocks_heavy_repair_without_evidence(): void
    {
        $this->postJson('/ai/repair', [
            'envelope_id' => 'env_repair_api_heavy',
            'failure_domain' => 'harness.failed',
            'policy' => [
                'allowed_strategies' => ['rerun_harness'],
            ],
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('repair.status', 'repair_blocked')
            ->assertJsonPath('repair.strategy', 'rerun_harness')
            ->assertJsonPath('repair.reasons.0', 'heavy_repair_requires_evidence_refs');
    }

    public function test_repair_api_routes_compliance_violations_to_human_review(): void
    {
        $this->postJson('/ai/repair', [
            'envelope_id' => 'env_repair_api_compliance',
            'failure_domain' => 'compliance.violation',
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('repair.status', 'needs_human_review')
            ->assertJsonPath('repair.strategy', 'human_review')
            ->assertJsonPath('repair.reasons.0', 'failure_domain_requires_human_review');
    }

    public function test_repair_api_requires_atlas_token(): void
    {
        $this->postJson('/ai/repair', [
            'envelope_id' => 'env_without_token',
            'failure_domain' => 'provider.timeout',
        ])->assertUnauthorized();
    }

    public function test_repair_api_validates_required_envelope(): void
    {
        $this->postJson('/ai/repair', [
            'failure_domain' => 'provider.timeout',
        ], $this->headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['envelope_id']);
    }
}
