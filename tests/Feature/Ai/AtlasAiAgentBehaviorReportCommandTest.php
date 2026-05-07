<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AtlasAiAgentBehaviorReportCommandTest extends TestCase
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

    public function test_command_summarizes_agent_behavior_report_as_json(): void
    {
        $this->recordAgentBehavior('env_agent_behavior_cmd_a', 'codex_cli', 'agent.verification_missing', 72);
        $this->recordAgentBehavior('env_agent_behavior_cmd_b', 'claude_cli', 'agent.verification_missing', 68);

        $exit = Artisan::call('atlas:ai:agent-behavior-report', [
            '--hours' => 24,
            '--finding-code' => 'agent.verification_missing',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame(24, $payload['hours']);
        $this->assertSame(['finding_code' => 'agent.verification_missing'], $payload['filters']);
        $this->assertSame(2, data_get($payload, 'agent_behavior.agent_behavior_event_count'));
        $this->assertSame(['agent.verification_missing' => 2], data_get($payload, 'agent_behavior.finding_code_counts'));
        $this->assertSame('warning', data_get($payload, 'agent_behavior.review_signal.status'));
        $this->assertSame('open_reviewable_agent_behavior_quality_proposal', data_get($payload, 'agent_behavior.review_signal.recommended_action'));
    }

    public function test_command_returns_ledger_unavailable_when_table_is_missing(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        $exit = Artisan::call('atlas:ai:agent-behavior-report', [
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('ledger_unavailable', $payload['status']);
        $this->assertFalse((bool) data_get($payload, 'agent_behavior.available'));
        $this->assertSame('wait_for_agent_behavior_evidence', data_get($payload, 'agent_behavior.review_signal.recommended_action'));
    }

    private function recordAgentBehavior(string $envelopeId, string $provider, string $findingCode, int $score): void
    {
        AtlasLedgerEvent::query()->create([
            'event_id' => (string) Str::ulid(),
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'tenant_agent_behavior_command',
            'operator_id' => 'operator_agent_behavior_command',
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
