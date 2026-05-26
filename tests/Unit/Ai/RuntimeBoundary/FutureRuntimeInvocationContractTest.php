<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\RuntimeBoundary;

use App\Services\Ai\RuntimeBoundary\Contracts\CognitivePlaneUxContract;
use App\Services\Ai\RuntimeBoundary\Contracts\ConstelacaoContract;
use App\Services\Ai\RuntimeBoundary\Contracts\ExternalGraphHarnessContract;
use App\Services\Ai\RuntimeBoundary\Contracts\HotContextCacheContract;
use App\Services\Ai\RuntimeBoundary\Contracts\LocalGraphRagContract;
use App\Services\Ai\RuntimeBoundary\Contracts\MacAgentNativeContract;
use App\Services\Ai\RuntimeBoundary\Contracts\OpenBrainGraphRagContract;
use App\Services\Ai\RuntimeBoundary\Contracts\ProviderReleaseCrawlerContract;
use App\Services\Ai\RuntimeBoundary\Contracts\SearchGraphRagContract;
use App\Services\Ai\RuntimeBoundary\Contracts\SemanticNotesPythonContract;
use App\Services\Ai\RuntimeBoundary\Contracts\VoiceRealtimeContract;
use Tests\TestCase;

final class FutureRuntimeInvocationContractTest extends TestCase
{
    private function baseRequest(array $payload = []): array
    {
        return [
            'invocation_id' => 'inv-'.bin2hex(random_bytes(4)),
            'payload' => $payload,
            'decision_receipt_ref' => 'rcpt:test',
            'evidence_sink_ref' => 'evidence:test',
            'rollback_plan_ref' => 'rollback:test',
        ];
    }

    public function test_voice_realtime_ready_with_valid_payload(): void
    {
        $r = (new VoiceRealtimeContract)->declare($this->baseRequest([
            'room' => 'atlas-voice-room-abc',
            'session_lease' => [
                'required_room_prefix' => 'atlas-voice-',
                'participant_namespace_source' => 'client_surface',
            ],
            'runtime_failed_callback_required' => true,
            'client_surface' => 'mobile',
        ]));
        $this->assertSame('invocation_contract_ready', $r['contract_status']);
        $this->assertTrue($r['runtime_invocation_allowed']);
        $this->assertSame('voice_realtime', $r['block_id']);
        $this->assertSame('livekit_agents_python', $r['target_runtime']);
    }

    public function test_voice_realtime_blocks_invalid_room_prefix(): void
    {
        $r = (new VoiceRealtimeContract)->declare($this->baseRequest([
            'room' => 'wrong-prefix',
            'session_lease' => [
                'required_room_prefix' => 'atlas-voice-',
                'participant_namespace_source' => 'client_surface',
            ],
            'runtime_failed_callback_required' => true,
            'client_surface' => 'mobile',
        ]));
        $this->assertSame('blocked_invalid_invocation', $r['contract_status']);
    }

    public function test_constelacao_ready_lens_1(): void
    {
        $r = (new ConstelacaoContract)->declare($this->baseRequest([
            'lens' => 'lens_1',
            'proposal_only' => true,
        ]));
        $this->assertTrue($r['runtime_invocation_allowed']);
    }

    public function test_constelacao_blocks_lens_2_without_maturity_gate(): void
    {
        $r = (new ConstelacaoContract)->declare($this->baseRequest([
            'lens' => 'lens_2',
            'proposal_only' => true,
        ]));
        $this->assertFalse($r['runtime_invocation_allowed']);
    }

    public function test_provider_release_crawler_requires_proposal_only(): void
    {
        $r = (new ProviderReleaseCrawlerContract)->declare($this->baseRequest([
            'proposal_only' => false,
            'source_ids' => ['s1'],
            'respects_robots_txt' => true,
        ]));
        $this->assertFalse($r['runtime_invocation_allowed']);
    }

    public function test_open_brain_graph_rag_ready_with_invariants(): void
    {
        $r = (new OpenBrainGraphRagContract)->declare($this->baseRequest([
            'proposal_only' => true,
            'freshness_ms_max' => 60000,
            'privacy_gate_passed' => true,
            'parallel_kernel_brain_forbidden' => true,
        ]));
        $this->assertTrue($r['runtime_invocation_allowed']);
    }

    public function test_mac_agent_native_blocks_voice_first(): void
    {
        $r = (new MacAgentNativeContract)->declare($this->baseRequest([
            'capability' => 'voice_edge',
            'governed_by_decision_receipt' => true,
            'voice_runtime_first_surface' => true,
        ]));
        $this->assertFalse($r['runtime_invocation_allowed']);
    }

    public function test_search_graph_rag_ready(): void
    {
        $r = (new SearchGraphRagContract)->declare($this->baseRequest([
            'review_only' => true,
            'query_hash' => hash('sha256', 'q'),
            'rollback_to_keyword_search_available' => true,
        ]));
        $this->assertTrue($r['runtime_invocation_allowed']);
    }

    public function test_semantic_notes_python_blocks_without_ap(): void
    {
        $r = (new SemanticNotesPythonContract)->declare($this->baseRequest([
            'python_ai_data_ap_approved' => false,
            'php_adapter_only' => true,
            'embeddings_engine_in_python' => true,
        ]));
        $this->assertFalse($r['runtime_invocation_allowed']);
    }

    public function test_cognitive_plane_ux_ready(): void
    {
        $r = (new CognitivePlaneUxContract)->declare($this->baseRequest([
            'ap' => 'AP-168',
            'surface' => 'mobile',
            'daily_plan_bootstrap_present' => true,
        ]));
        $this->assertTrue($r['runtime_invocation_allowed']);
    }

    public function test_cognitive_plane_blocks_tutor_final_claim(): void
    {
        $r = (new CognitivePlaneUxContract)->declare($this->baseRequest([
            'ap' => 'AP-168',
            'surface' => 'mobile',
            'daily_plan_bootstrap_present' => true,
            'sells_as_tutor_final' => true,
        ]));
        $this->assertFalse($r['runtime_invocation_allowed']);
    }

    public function test_local_graph_rag_requires_ap683_and_receipt(): void
    {
        $r = (new LocalGraphRagContract)->declare($this->baseRequest([
            'ap683_review_passed' => true,
            'local_rag_graph_promotion_blocked_flag' => false,
            'operator_decision_receipt_ref' => 'rcpt:override',
        ]));
        $this->assertTrue($r['runtime_invocation_allowed']);
    }

    public function test_hot_context_cache_blocks_without_freshness(): void
    {
        $r = (new HotContextCacheContract)->declare($this->baseRequest([
            'content_hashes' => ['h1'],
            'privacy_gate_passed' => true,
        ]));
        $this->assertFalse($r['runtime_invocation_allowed']);
    }

    public function test_external_graph_harness_ready_with_constraints(): void
    {
        $r = (new ExternalGraphHarnessContract)->declare($this->baseRequest([
            'review_only_constraints_acknowledged' => true,
            'private_docs_excluded' => true,
            'graph_promotion_to_context_blocked' => true,
        ]));
        $this->assertTrue($r['runtime_invocation_allowed']);
    }

    public function test_all_contracts_require_decision_receipt_ref(): void
    {
        $contracts = [
            new VoiceRealtimeContract,
            new ConstelacaoContract,
            new ProviderReleaseCrawlerContract,
            new OpenBrainGraphRagContract,
            new MacAgentNativeContract,
            new SearchGraphRagContract,
            new SemanticNotesPythonContract,
            new CognitivePlaneUxContract,
            new LocalGraphRagContract,
            new HotContextCacheContract,
            new ExternalGraphHarnessContract,
        ];
        $this->assertCount(11, $contracts);
        foreach ($contracts as $contract) {
            // Empty request → missing all AP-201 invariants
            $r = $contract->declare([]);
            $this->assertFalse($r['runtime_invocation_allowed']);
            $this->assertContains('decision_receipt_ref required (AP-201 canonical invariant)', $r['validation_errors']);
            $this->assertContains('evidence_sink_ref required (AP-201 canonical invariant)', $r['validation_errors']);
            $this->assertContains('rollback_plan_ref required (AP-201 canonical invariant)', $r['validation_errors']);
        }
    }

    public function test_envelope_shape_is_stable(): void
    {
        $r = (new VoiceRealtimeContract)->declare($this->baseRequest());
        $this->assertSame([
            'schema_version', 'block_id', 'target_runtime', 'invocation_id',
            'contract_status', 'validation_errors', 'declared_at',
            'evidence_refs', 'runtime_invocation_allowed', 'detail',
        ], array_keys($r));
        $this->assertSame('atlas.runtime_invocation_contract.v1', $r['schema_version']);
    }
}
