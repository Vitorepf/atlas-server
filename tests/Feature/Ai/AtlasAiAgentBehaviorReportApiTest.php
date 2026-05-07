<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AtlasAiAgentBehaviorReportApiTest extends TestCase
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

    public function test_agent_behavior_report_api_returns_filtered_window_summary(): void
    {
        $this->recordAgentBehavior('env_agent_behavior_api_a', 'codex_cli', 'agent.verification_missing', 72);
        $this->recordAgentBehavior('env_agent_behavior_api_b', 'codex_cli', 'agent.verification_missing', 68);
        $this->recordAgentBehavior('env_agent_behavior_api_c', 'claude_cli', 'agent.unsurgical_diff', 80);

        $response = $this->getJson('/ai/agent-behavior/report?hours=24&provider=codex_cli&finding_code=agent.verification_missing', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('hours', 24)
            ->assertJsonPath('filters.provider', 'codex_cli')
            ->assertJsonPath('filters.finding_code', 'agent.verification_missing')
            ->assertJsonPath('agent_behavior.available', true)
            ->assertJsonPath('agent_behavior.agent_behavior_event_count', 2)
            ->assertJsonPath('agent_behavior.finding_count', 2)
            ->assertJsonPath('agent_behavior.provider_counts.codex_cli', 2)
            ->assertJsonPath('agent_behavior.review_signal.status', 'warning')
            ->assertJsonPath('agent_behavior.review_signal.recommended_action', 'open_reviewable_agent_behavior_quality_proposal');

        $this->assertSame(2, $response->json('agent_behavior.finding_code_counts')['agent.verification_missing'] ?? null);
        $this->assertSame('env_agent_behavior_api_b', $response->json('agent_behavior.recent_events.0.envelope_id'));
    }

    public function test_agent_behavior_report_api_requires_atlas_token(): void
    {
        $this->getJson('/ai/agent-behavior/report')
            ->assertUnauthorized();
    }

    public function test_agent_behavior_report_api_returns_service_unavailable_when_ledger_table_is_missing(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        $this->getJson('/ai/agent-behavior/report', $this->headers)
            ->assertStatus(503)
            ->assertJsonPath('status', 'ledger_unavailable')
            ->assertJsonPath('agent_behavior.available', false)
            ->assertJsonPath('agent_behavior.review_signal.status', 'unknown')
            ->assertJsonPath('agent_behavior.review_signal.recommended_action', 'wait_for_agent_behavior_evidence');
    }

    private function recordAgentBehavior(string $envelopeId, string $provider, string $findingCode, int $score): void
    {
        AtlasLedgerEvent::query()->create([
            'event_id' => (string) Str::ulid(),
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'tenant_agent_behavior_api',
            'operator_id' => 'operator_agent_behavior_api',
            'envelope_id' => $envelopeId,
            'receipt_id' => null,
            'trace_id' => 'trace_'.$envelopeId,
            'correlation_id' => 'trace_'.$envelopeId,
            'causation_id' => null,
            'event_type' => LedgerEventType::GateEvaluated->value,
            'emitter_stage' => 'atlas.agent_behavior_quality_gate',
            'emitter_version' => 'atlas.agent_behavior_quality_gate.v1',
            'payload' => [
                'schema_version' => 'atlas.agent_behavior.gate_evaluation.v1',
                'gate_id' => 'atlas.agent_behavior',
                'status' => 'needs_review',
                'score' => $score,
                'provider' => $provider,
                'model' => 'test-model',
                'agent_slug' => 'programming_agent',
                'contract_id' => 'atlas-ai.agent-behavior.v1',
                'contract_hash' => 'contract-hash-test',
                'agent_behavior_findings' => [[
                    'code' => $findingCode,
                    'severity' => 'p2',
                    'metadata' => ['contract_id' => 'atlas-ai.agent-behavior.v1'],
                    'evidence' => ['principle' => 'Verifiable Goal Loop'],
                ]],
            ],
            'payload_hash' => hash('sha256', $envelopeId.$provider.$findingCode),
            'occurred_at' => now()->subHour(),
        ]);
    }
}
