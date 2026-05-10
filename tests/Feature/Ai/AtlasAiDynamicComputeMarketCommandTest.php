<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Kernel\Evidence\ProviderUsagePayload;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AtlasAiDynamicComputeMarketCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_command_explains_dynamic_compute_market_as_json_without_routing_change(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->recordProviderReturned('codex_cli', 160.0, 1000);
            $this->recordProviderReturned('claude_cli', 45.0, 700);
        }

        $exit = Artisan::call('atlas:ai:dynamic-compute-market', [
            '--provider' => 'codex_cli',
            '--model' => 'gpt-test',
            '--domain' => 'programming',
            '--flow' => 'programming.dev',
            '--task-type' => 'feature',
            '--specialist-profile' => 'programming.frontend',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.dynamic_compute_market_report.v1', data_get($payload, 'schema_version'));
        $this->assertSame('ok', data_get($payload, 'status'));
        $this->assertSame('report_only', data_get($payload, 'mode'));
        $this->assertSame('read_only_no_routing_change', data_get($payload, 'authority'));
        $this->assertSame('codex_cli', data_get($payload, 'input.provider'));
        $this->assertSame('atlas.dynamic_compute_market.v1', data_get($payload, 'dynamic_compute_market.schema_version'));
        $this->assertSame('shadow_advisory', data_get($payload, 'dynamic_compute_market.mode'));
        $this->assertSame('benchmark_lower_latency_alternative', data_get($payload, 'dynamic_compute_market.recommendation'));
        $this->assertSame('run_controlled_provider_benchmark_before_policy_change', data_get($payload, 'dynamic_compute_market.recommended_next_action'));
        $this->assertFalse((bool) data_get($payload, 'dynamic_compute_market.routing_control.changes_provider'));
        $this->assertSame('atlas.dynamic_compute_market.proposal_gate.v1', data_get($payload, 'dynamic_compute_market.proposal_gate.schema_version'));
        $this->assertSame('proposal_only', data_get($payload, 'dynamic_compute_market.proposal_gate.mode'));
        $this->assertTrue(data_get($payload, 'dynamic_compute_market.proposal_gate.can_open_proposal'));
        $this->assertFalse(data_get($payload, 'dynamic_compute_market.proposal_gate.can_change_provider'));
        $this->assertFalse(data_get($payload, 'dynamic_compute_market.proposal_gate.can_change_policy'));
        $this->assertTrue(data_get($payload, 'dynamic_compute_market.proposal_gate.requires_human_review'));
        $this->assertTrue(data_get($payload, 'dynamic_compute_market.proposal_gate.requires_benchmark'));
        $this->assertSame('atlas.dynamic_compute_market.proposal_evidence.v1', data_get($payload, 'dynamic_compute_market.proposal_gate.proposal_evidence_contract.schema_version'));
        $this->assertSame('ap99_provider_usage_projection', data_get($payload, 'dynamic_compute_market.proposal_gate.proposal_evidence_contract.source'));
        $this->assertTrue(data_get($payload, 'dynamic_compute_market.proposal_gate.proposal_evidence_contract.replay_required'));
        $this->assertTrue(data_get($payload, 'dynamic_compute_market.proposal_gate.proposal_evidence_contract.benchmark_required_before_policy_patch'));
        $this->assertContains('controlled_provider_benchmark', data_get($payload, 'dynamic_compute_market.proposal_gate.proposal_evidence_contract.required_artifacts'));
        $this->assertSame('atlas.dynamic_compute_market.proposal_review_packet.v1', data_get($payload, 'dynamic_compute_market.proposal_gate.review_packet.schema_version'));
        $this->assertSame('blocked_until_benchmark_human_review_and_new_receipt', data_get($payload, 'dynamic_compute_market.proposal_gate.review_packet.status'));
        $this->assertSame('approve_or_reject_dynamic_compute_market_policy_change', data_get($payload, 'dynamic_compute_market.proposal_gate.review_packet.required_human_decision'));
        $this->assertTrue(data_get($payload, 'dynamic_compute_market.proposal_gate.review_packet.required_decision_receipt'));
        $this->assertTrue(data_get($payload, 'dynamic_compute_market.proposal_gate.review_packet.rollback_plan_required'));
        $this->assertTrue(data_get($payload, 'dynamic_compute_market.proposal_gate.review_packet.policy_patch_review_required'));
        $this->assertContains('controlled_provider_benchmark', data_get($payload, 'dynamic_compute_market.proposal_gate.review_packet.evidence_required'));
        $this->assertContains('revert_provider_routing_policy_patch', data_get($payload, 'dynamic_compute_market.proposal_gate.review_packet.rollback_required'));
        $this->assertContains('bypass_atlas_decide_authority', data_get($payload, 'dynamic_compute_market.proposal_gate.review_packet.forbidden_until_review'));
        $this->assertContains('provider_routing_change', data_get($payload, 'dynamic_compute_market.proposal_gate.prohibited_actions'));
        $this->assertSame('claude_cli', data_get($payload, 'dynamic_compute_market.benchmark_candidate.provider'));
        $this->assertSame('sufficient', data_get($payload, 'dynamic_compute_market.benchmark_candidate.sample_status'));
    }

    public function test_command_reports_invalid_input_when_provider_is_missing(): void
    {
        $exit = Artisan::call('atlas:ai:dynamic-compute-market', [
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('invalid_input', data_get($payload, 'status'));
        $this->assertSame('provider is required.', data_get($payload, 'error'));
    }

    public function test_command_reports_unavailable_without_ap99_ledger_projection(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        $exit = Artisan::call('atlas:ai:dynamic-compute-market', [
            '--provider' => 'codex_cli',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('atlas.dynamic_compute_market_report.v1', data_get($payload, 'schema_version'));
        $this->assertSame('ledger_unavailable', data_get($payload, 'status'));
        $this->assertSame('report_only', data_get($payload, 'mode'));
        $this->assertSame('read_only_no_routing_change', data_get($payload, 'authority'));
        $this->assertSame('collect_ap99_evidence', data_get($payload, 'dynamic_compute_market.recommendation'));
        $this->assertFalse((bool) data_get($payload, 'dynamic_compute_market.routing_control.changes_provider'));
        $this->assertFalse((bool) data_get($payload, 'dynamic_compute_market.ap99.available'));
        $this->assertFalse((bool) data_get($payload, 'dynamic_compute_market.proposal_gate.can_open_proposal'));
        $this->assertSame(['continue_monitoring'], data_get($payload, 'dynamic_compute_market.proposal_gate.allowed_actions'));
        $this->assertSame('draft_only_until_benchmark_and_review', data_get($payload, 'dynamic_compute_market.proposal_gate.proposal_evidence_contract.policy_patch_status'));
        $this->assertSame('monitor_only_no_policy_change', data_get($payload, 'dynamic_compute_market.proposal_gate.review_packet.status'));
        $this->assertSame(['continue AP-99 monitoring'], data_get($payload, 'dynamic_compute_market.proposal_gate.review_packet.evidence_required'));
    }

    private function recordProviderReturned(string $provider, float $latency, int $costMicrousd): void
    {
        AtlasLedgerEvent::query()->create([
            'event_id' => (string) Str::ulid(),
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'tenant_dynamic_compute_market',
            'operator_id' => 'operator_dynamic_compute_market',
            'envelope_id' => 'env_'.Str::random(8),
            'receipt_id' => 'rcpt_'.Str::random(8),
            'trace_id' => 'trace_'.Str::random(8),
            'correlation_id' => 'corr_'.Str::random(8),
            'causation_id' => null,
            'event_type' => LedgerEventType::ProviderReturned->value,
            'emitter_stage' => 'test',
            'emitter_version' => 'test',
            'payload' => [
                'schema_version' => ProviderUsagePayload::SCHEMA_VERSION,
                'phase' => 'returned',
                'provider_cli' => $provider,
                'domain' => 'programming',
                'flow' => 'programming.dev',
                'task_type' => 'feature',
                'specialist_profile' => 'programming.frontend',
                'risk' => 'medium',
                'exit_status' => 'succeeded',
                'latency_seconds' => $latency,
                'repair_count' => 0,
                'selection_mode' => 'auto',
                'prompt_tokens' => 50,
                'completion_tokens' => 50,
                'total_tokens' => 100,
                'estimated_tokens' => 100,
                'token_source' => 'estimated_chars',
                'cost_microusd' => $costMicrousd,
                'cost_confidence' => 'estimated',
                'cost_source' => 'estimated_chars_rate',
                'cost_mode' => 'operational_estimate',
            ],
            'payload_hash' => hash('sha256', $provider.$latency.$costMicrousd),
            'occurred_at' => now(),
        ]);
    }
}
