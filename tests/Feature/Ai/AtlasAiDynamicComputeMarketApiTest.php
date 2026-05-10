<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Kernel\Evidence\ProviderUsagePayload;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AtlasAiDynamicComputeMarketApiTest extends TestCase
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

    public function test_api_exposes_dynamic_compute_market_report(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->recordProviderReturned('codex_cli', 160.0, 1000);
            $this->recordProviderReturned('gemini_cli', 40.0, 650);
        }

        $this->getJson('/ai/dynamic-compute-market?provider=codex_cli&model=gpt-test&domain=programming&flow=programming.dev&task_type=feature&specialist_profile=programming.frontend', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.dynamic_compute_market_report.v1')
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('mode', 'report_only')
            ->assertJsonPath('authority', 'read_only_no_routing_change')
            ->assertJsonPath('input.provider', 'codex_cli')
            ->assertJsonPath('dynamic_compute_market.schema_version', 'atlas.dynamic_compute_market.v1')
            ->assertJsonPath('dynamic_compute_market.recommendation', 'benchmark_lower_latency_alternative')
            ->assertJsonPath('dynamic_compute_market.recommended_next_action', 'run_controlled_provider_benchmark_before_policy_change')
            ->assertJsonPath('dynamic_compute_market.routing_control.changes_provider', false)
            ->assertJsonPath('dynamic_compute_market.proposal_gate.schema_version', 'atlas.dynamic_compute_market.proposal_gate.v1')
            ->assertJsonPath('dynamic_compute_market.proposal_gate.mode', 'proposal_only')
            ->assertJsonPath('dynamic_compute_market.proposal_gate.can_open_proposal', true)
            ->assertJsonPath('dynamic_compute_market.proposal_gate.can_change_provider', false)
            ->assertJsonPath('dynamic_compute_market.proposal_gate.requires_benchmark', true)
            ->assertJsonPath('dynamic_compute_market.proposal_gate.proposal_evidence_contract.schema_version', 'atlas.dynamic_compute_market.proposal_evidence.v1')
            ->assertJsonPath('dynamic_compute_market.proposal_gate.proposal_evidence_contract.source', 'ap99_provider_usage_projection')
            ->assertJsonPath('dynamic_compute_market.proposal_gate.proposal_evidence_contract.replay_required', true)
            ->assertJsonPath('dynamic_compute_market.proposal_gate.proposal_evidence_contract.policy_patch_status', 'draft_only_until_benchmark_and_review')
            ->assertJsonPath('dynamic_compute_market.proposal_gate.review_packet.schema_version', 'atlas.dynamic_compute_market.proposal_review_packet.v1')
            ->assertJsonPath('dynamic_compute_market.proposal_gate.review_packet.required_human_decision', 'approve_or_reject_dynamic_compute_market_policy_change')
            ->assertJsonPath('dynamic_compute_market.proposal_gate.review_packet.required_decision_receipt', true)
            ->assertJsonPath('dynamic_compute_market.proposal_gate.review_packet.rollback_plan_required', true)
            ->assertJsonPath('dynamic_compute_market.benchmark_candidate.provider', 'gemini_cli')
            ->assertJsonPath('dynamic_compute_market.benchmark_candidate.sample_status', 'sufficient');
    }

    public function test_api_requires_atlas_token(): void
    {
        $this->getJson('/ai/dynamic-compute-market?provider=codex_cli')
            ->assertUnauthorized();
    }

    public function test_api_requires_provider(): void
    {
        $this->getJson('/ai/dynamic-compute-market', $this->headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['provider']);
    }

    public function test_api_reports_unavailable_without_ap99_ledger_projection(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        $this->getJson('/ai/dynamic-compute-market?provider=codex_cli', $this->headers)
            ->assertStatus(503)
            ->assertJsonPath('schema_version', 'atlas.dynamic_compute_market_report.v1')
            ->assertJsonPath('status', 'ledger_unavailable')
            ->assertJsonPath('mode', 'report_only')
            ->assertJsonPath('authority', 'read_only_no_routing_change')
            ->assertJsonPath('dynamic_compute_market.recommendation', 'collect_ap99_evidence')
            ->assertJsonPath('dynamic_compute_market.routing_control.changes_provider', false)
            ->assertJsonPath('dynamic_compute_market.proposal_gate.can_open_proposal', false)
            ->assertJsonPath('dynamic_compute_market.proposal_gate.review_packet.status', 'monitor_only_no_policy_change')
            ->assertJsonPath('dynamic_compute_market.ap99.available', false);
    }

    private function recordProviderReturned(string $provider, float $latency, int $costMicrousd): void
    {
        AtlasLedgerEvent::query()->create([
            'event_id' => (string) Str::ulid(),
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'tenant_dynamic_compute_market_api',
            'operator_id' => 'operator_dynamic_compute_market_api',
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
