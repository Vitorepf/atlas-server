<?php

namespace Tests\Feature\Ai\RouterRuntime;

use App\Http\Resources\AiTraceResource;
use App\Models\AiTrace;
use App\Services\Ai\RouterRuntime\AtlasHyperflowEntryService;
use App\Services\Ai\RouterRuntime\RouterRuntimeCanon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Anti-regression suite for the Atlas AI Hyperflow audit contract surfaced
 * via {@see AiTraceResource}. Locks down 4 invariants for every flow
 * (`research`, `finance`, `marketing`, `strategy`, `cyber`, `automation`,
 * `personal_development`, `conversation`, `programming/*`):
 *
 *   1. **Receipt hash exposed.** The decision_receipt_id +
 *      decision_receipt_hash MUST surface so Desktop can prove a trace
 *      passed through the Router/Dispatch pipeline.
 *   2. **Policy / Evidence refs explicit.** Missing required gate flips
 *      `flow_status` from `success` to `partial_no_policy`,
 *      `partial_no_evidence` or `partial_no_gates` — no flow can look
 *      successful while missing a declared gate.
 *   3. **Fallback never silent.** Degraded envelopes + dispatch blockers
 *      + manual override surface as `fallback_reason`.
 *   4. **Handoff to Dev/Forge carries reason.** Programming hand-off
 *      always exposes `handoff_target` + `handoff_reason`.
 *
 * All tests build the trace in-memory (forceFill) — no DB writes,
 * no provider call, no benchmark.
 */
class HyperflowReceiptsEvidenceFallbackTest extends TestCase
{
    public function test_research_with_evidence_gate_present_yields_flow_status_success(): void
    {
        $trace = $this->traceWithEnvelope($this->researchEnvelope(['required_gates' => ['evidence.gate']]));

        $hyperflow = $this->toHyperflow($trace);
        $this->assertSame('success', $hyperflow['flow_status']);
        $this->assertSame(['evidence.gate'], $hyperflow['evidence_refs']);
        $this->assertNull($hyperflow['fallback_reason']);
    }

    public function test_research_missing_evidence_ref_flips_flow_status_to_partial_no_evidence(): void
    {
        // evidence_required=true but required_gates declares NO evidence.gate.
        $trace = $this->traceWithEnvelope($this->researchEnvelope([
            'required_gates' => [],
            'evidence_required' => true,
        ]));

        $hyperflow = $this->toHyperflow($trace);
        $this->assertSame('partial_no_evidence', $hyperflow['flow_status']);
        $this->assertSame([], $hyperflow['evidence_refs']);
    }

    public function test_finance_missing_policy_ref_flips_flow_status_to_partial_no_policy(): void
    {
        $trace = $this->traceWithEnvelope($this->financeEnvelope([
            'required_gates' => ['evidence.gate'],     // evidence ok
            'policy_required' => true,
            'evidence_required' => true,
        ]));

        $hyperflow = $this->toHyperflow($trace);
        $this->assertSame('partial_no_policy', $hyperflow['flow_status']);
        $this->assertSame([], $hyperflow['policy_refs']);
        $this->assertSame(['evidence.gate'], $hyperflow['evidence_refs']);
    }

    public function test_cyber_with_policy_and_evidence_refs_yields_success(): void
    {
        $trace = $this->traceWithEnvelope($this->cyberEnvelope([
            'required_gates' => ['policy.gate', 'evidence.gate'],
        ]));

        $hyperflow = $this->toHyperflow($trace);
        $this->assertSame('success', $hyperflow['flow_status']);
        $this->assertSame(['policy.gate'], $hyperflow['policy_refs']);
        $this->assertSame(['evidence.gate'], $hyperflow['evidence_refs']);
    }

    public function test_marketing_strategy_secondary_domains_surface_decision_basis(): void
    {
        // Marketing prompt with finance/research as secondary domains —
        // proves the rich envelope keeps assumption/decision_basis signals
        // available for Desktop to render.
        $trace = $this->traceWithEnvelope($this->marketingEnvelope([
            'secondary_domains' => ['research', 'finance'],
            'required_gates' => ['policy.gate'],
        ]));

        $hyperflow = $this->toHyperflow($trace);
        $this->assertSame('atlas_marketing', $hyperflow['flow_id']);
        $this->assertSame(['policy.gate'], $hyperflow['policy_refs']);
        $rich = $this->toResource($trace)['hyperflow_runtime'];
        $this->assertSame(['research', 'finance'], $rich['secondary_domains']);
    }

    public function test_degraded_envelope_surfaces_fallback_reason_and_flow_status_degraded(): void
    {
        $trace = $this->traceWithEnvelope([
            'schema_version' => AtlasHyperflowEntryService::SCHEMA_VERSION,
            'status' => 'degraded',
            'error' => 'router_runtime_tables_missing',
        ]);

        $hyperflow = $this->toHyperflow($trace);
        $this->assertSame('degraded', $hyperflow['flow_status']);
        $this->assertSame('degraded_envelope:router_runtime_tables_missing', $hyperflow['fallback_reason']);
        $this->assertNull($hyperflow['flow_id']);
        $this->assertSame([], $hyperflow['policy_refs']);
        $this->assertSame([], $hyperflow['evidence_refs']);
    }

    public function test_dispatch_blockers_become_explicit_fallback_reason(): void
    {
        $envelope = $this->researchEnvelope([
            'required_gates' => ['evidence.gate'],
            'dispatch' => [
                'uuid' => 'd-uuid',
                'id' => 'd-id',
                'dispatch_target' => 'atlas_research',
                'dispatch_status' => RouterRuntimeCanon::DISPATCH_BLOCKED,
                'blockers' => [['kind' => 'policy', 'reason' => 'tool_runtime_missing']],
                'receipt_hash' => str_repeat('b', 64),
            ],
            'dispatch_status' => RouterRuntimeCanon::DISPATCH_BLOCKED,
        ]);
        $trace = $this->traceWithEnvelope($envelope);

        $hyperflow = $this->toHyperflow($trace);
        $this->assertSame('dispatch_blocker:tool_runtime_missing', $hyperflow['fallback_reason']);
        $this->assertSame(RouterRuntimeCanon::DISPATCH_BLOCKED, $hyperflow['dispatch_status']);
    }

    public function test_fallback_flows_surface_in_flat_projection(): void
    {
        $trace = $this->traceWithEnvelope($this->researchEnvelope([
            'required_gates' => ['evidence.gate'],
            'fallback_flows' => ['atlas_explain', 'atlas_conversation'],
        ]));

        $hyperflow = $this->toHyperflow($trace);
        $this->assertSame(['atlas_explain', 'atlas_conversation'], $hyperflow['fallback_flows']);
    }

    public function test_programming_handoff_carries_target_and_reason(): void
    {
        $trace = $this->traceWithEnvelope([
            'schema_version' => AtlasHyperflowEntryService::SCHEMA_VERSION,
            'status' => 'ready',
            'intent' => ['type' => RouterRuntimeCanon::INTENT_PROGRAMMING, 'confidence' => 0.8],
            'primary_domain' => 'programming',
            'flow_id' => 'atlas_dev',
            'runtime_mode' => RouterRuntimeCanon::MODE_STANDARD,
            'routing_confidence' => 0.8,
            'policy_required' => false,
            'evidence_required' => true,
            'required_gates' => ['evidence.gate'],
            'router_decision' => ['receipt_hash' => str_repeat('a', 64), 'reason' => ['reasons' => []]],
            'dispatch' => ['receipt_hash' => str_repeat('b', 64), 'dispatch_status' => RouterRuntimeCanon::DISPATCH_SIMULATED],
            'dispatch_status' => RouterRuntimeCanon::DISPATCH_SIMULATED,
            'handoff_target' => [
                'kind' => 'atlas_dev',
                'flow_id' => 'atlas_dev',
                'reason' => 'hyperflow_programming_flow_handoff',
                'routing_mode' => RouterRuntimeCanon::MODE_STANDARD,
            ],
            'decision_receipt' => [
                'router_decision_receipt' => ['id' => 'rr', 'receipt_hash' => str_repeat('c', 64)],
                'runtime_dispatch_receipt' => ['id' => 'dr', 'receipt_hash' => str_repeat('d', 64)],
            ],
        ]);

        $hyperflow = $this->toHyperflow($trace);
        $this->assertSame('atlas_dev', $hyperflow['handoff_target']);
        $this->assertSame('hyperflow_programming_flow_handoff', $hyperflow['handoff_reason']);
        $this->assertSame('dr', $hyperflow['decision_receipt_id']);
        $this->assertSame(str_repeat('d', 64), $hyperflow['decision_receipt_hash']);
    }

    public function test_forge_handoff_carries_target_and_reason(): void
    {
        $trace = $this->traceWithEnvelope([
            'schema_version' => AtlasHyperflowEntryService::SCHEMA_VERSION,
            'status' => 'ready',
            'intent' => ['type' => RouterRuntimeCanon::INTENT_PROGRAMMING, 'confidence' => 0.9],
            'primary_domain' => 'programming',
            'flow_id' => 'atlas_forge',
            'runtime_mode' => RouterRuntimeCanon::MODE_DEEP,
            'routing_confidence' => 0.9,
            'policy_required' => true,
            'evidence_required' => true,
            'required_gates' => ['policy.gate', 'evidence.gate'],
            'router_decision' => ['receipt_hash' => str_repeat('a', 64), 'reason' => ['reasons' => []]],
            'dispatch' => ['receipt_hash' => str_repeat('b', 64), 'dispatch_status' => RouterRuntimeCanon::DISPATCH_PLANNED],
            'dispatch_status' => RouterRuntimeCanon::DISPATCH_PLANNED,
            'handoff_target' => [
                'kind' => 'atlas_forge',
                'flow_id' => 'atlas_forge',
                'reason' => 'hyperflow_obra_promotion',
                'routing_mode' => RouterRuntimeCanon::MODE_DEEP,
            ],
            'decision_receipt' => [
                'router_decision_receipt' => ['id' => 'rr', 'receipt_hash' => str_repeat('c', 64)],
                'runtime_dispatch_receipt' => ['id' => 'dr', 'receipt_hash' => str_repeat('d', 64)],
            ],
        ]);

        $hyperflow = $this->toHyperflow($trace);
        $this->assertSame('atlas_forge', $hyperflow['handoff_target']);
        $this->assertSame('hyperflow_obra_promotion', $hyperflow['handoff_reason']);
        $this->assertSame('success', $hyperflow['flow_status']);
    }

    public function test_conversation_fallback_keeps_flow_status_success_when_no_gates_required(): void
    {
        $trace = $this->traceWithEnvelope([
            'schema_version' => AtlasHyperflowEntryService::SCHEMA_VERSION,
            'status' => 'ready',
            'intent' => ['type' => RouterRuntimeCanon::INTENT_CONVERSATION, 'confidence' => 0.4],
            'primary_domain' => 'conversation',
            'flow_id' => 'atlas_conversation',
            'runtime_mode' => RouterRuntimeCanon::MODE_LIGHTWEIGHT,
            'routing_confidence' => 0.4,
            'policy_required' => false,
            'evidence_required' => false,
            'required_gates' => [],
            'router_decision' => ['receipt_hash' => str_repeat('a', 64), 'reason' => ['reasons' => ['intent_type:conversation']]],
            'dispatch' => ['receipt_hash' => str_repeat('b', 64), 'dispatch_status' => RouterRuntimeCanon::DISPATCH_PLANNED],
            'dispatch_status' => RouterRuntimeCanon::DISPATCH_PLANNED,
            'decision_receipt' => [
                'router_decision_receipt' => ['id' => 'rr', 'receipt_hash' => str_repeat('c', 64)],
                'runtime_dispatch_receipt' => ['id' => 'dr', 'receipt_hash' => str_repeat('d', 64)],
            ],
        ]);

        $hyperflow = $this->toHyperflow($trace);
        $this->assertSame('success', $hyperflow['flow_status']);
        $this->assertContains('intent_type:conversation', $hyperflow['reasons']);
    }

    public function test_partial_no_gates_when_both_required_and_both_missing(): void
    {
        $trace = $this->traceWithEnvelope($this->cyberEnvelope([
            'required_gates' => [],
            'policy_required' => true,
            'evidence_required' => true,
        ]));

        $hyperflow = $this->toHyperflow($trace);
        $this->assertSame('partial_no_gates', $hyperflow['flow_status']);
    }

    public function test_canon_emits_dedicated_flow_per_domain_no_legacy_atlas_plan_fallback(): void
    {
        // Per the multi-domain refactor: finance/marketing/strategy/cyber/
        // personal_development/automation each get a dedicated `atlas_*`
        // flow, NOT the legacy `atlas_plan` catch-all.
        foreach ([
            RouterRuntimeCanon::INTENT_FINANCE => RouterRuntimeCanon::FLOW_FINANCE,
            RouterRuntimeCanon::INTENT_MARKETING => RouterRuntimeCanon::FLOW_MARKETING,
            RouterRuntimeCanon::INTENT_STRATEGY => RouterRuntimeCanon::FLOW_STRATEGY,
            RouterRuntimeCanon::INTENT_CYBER => RouterRuntimeCanon::FLOW_CYBER,
            RouterRuntimeCanon::INTENT_PERSONAL_DEVELOPMENT => RouterRuntimeCanon::FLOW_PERSONAL_DEVELOPMENT,
            RouterRuntimeCanon::INTENT_AUTOMATION => RouterRuntimeCanon::FLOW_AUTOMATION,
        ] as $intent => $expectedFlow) {
            $this->assertSame(
                $expectedFlow,
                RouterRuntimeCanon::INTENT_TO_FLOW[$intent] ?? null,
                "intent {$intent} must map to dedicated specialist flow",
            );
            $this->assertNotSame(
                'atlas_plan',
                $expectedFlow,
                "intent {$intent} must NOT fallback to legacy atlas_plan",
            );
        }
    }

    public function test_hyperflow_flat_returns_null_when_no_envelope(): void
    {
        $trace = (new AiTrace)->forceFill([
            'id' => (string) Str::uuid(),
            'trace_key' => 'trace_no_envelope',
            'status' => 'queued',
            'operator_input' => 'no envelope here',
            'agent_slug' => 'orquestrador',
            'provider' => 'codex_cli',
            'skill_versions' => [],
            'context_refs' => [],
            'metadata' => [],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = $this->toResource($trace);
        $this->assertNull($payload['hyperflow']);
        $this->assertNull($payload['hyperflow_runtime']);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function researchEnvelope(array $overrides = []): array
    {
        return array_replace([
            'schema_version' => AtlasHyperflowEntryService::SCHEMA_VERSION,
            'status' => 'ready',
            'intent' => ['type' => RouterRuntimeCanon::INTENT_RESEARCH, 'confidence' => 0.72],
            'primary_domain' => 'research',
            'secondary_domains' => [],
            'flow_id' => RouterRuntimeCanon::FLOW_RESEARCH,
            'runtime_mode' => RouterRuntimeCanon::MODE_DEEP,
            'routing_confidence' => 0.72,
            'policy_required' => false,
            'evidence_required' => true,
            'required_gates' => ['evidence.gate'],
            'router_decision' => ['receipt_hash' => str_repeat('a', 64), 'reason' => ['reasons' => ['intent_type:research']]],
            'dispatch' => [
                'uuid' => 'd-uuid',
                'id' => 'd-id',
                'dispatch_target' => RouterRuntimeCanon::FLOW_RESEARCH,
                'dispatch_status' => RouterRuntimeCanon::DISPATCH_PLANNED,
                'blockers' => [],
                'receipt_hash' => str_repeat('b', 64),
            ],
            'dispatch_status' => RouterRuntimeCanon::DISPATCH_PLANNED,
            'decision_receipt' => [
                'router_decision_receipt' => ['id' => 'rr', 'receipt_hash' => str_repeat('c', 64)],
                'runtime_dispatch_receipt' => ['id' => 'dr', 'receipt_hash' => str_repeat('d', 64)],
            ],
        ], $overrides);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function financeEnvelope(array $overrides = []): array
    {
        return array_replace([
            'schema_version' => AtlasHyperflowEntryService::SCHEMA_VERSION,
            'status' => 'ready',
            'intent' => ['type' => RouterRuntimeCanon::INTENT_FINANCE, 'confidence' => 0.8],
            'primary_domain' => 'finance',
            'flow_id' => RouterRuntimeCanon::FLOW_FINANCE,
            'runtime_mode' => RouterRuntimeCanon::MODE_DEEP,
            'routing_confidence' => 0.8,
            'policy_required' => true,
            'evidence_required' => true,
            'required_gates' => ['policy.gate', 'evidence.gate'],
            'router_decision' => ['receipt_hash' => str_repeat('a', 64), 'reason' => ['reasons' => []]],
            'dispatch' => ['receipt_hash' => str_repeat('b', 64), 'dispatch_status' => RouterRuntimeCanon::DISPATCH_PLANNED, 'blockers' => []],
            'dispatch_status' => RouterRuntimeCanon::DISPATCH_PLANNED,
            'decision_receipt' => [
                'router_decision_receipt' => ['id' => 'rr', 'receipt_hash' => str_repeat('c', 64)],
                'runtime_dispatch_receipt' => ['id' => 'dr', 'receipt_hash' => str_repeat('d', 64)],
            ],
        ], $overrides);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function marketingEnvelope(array $overrides = []): array
    {
        return array_replace([
            'schema_version' => AtlasHyperflowEntryService::SCHEMA_VERSION,
            'status' => 'ready',
            'intent' => ['type' => RouterRuntimeCanon::INTENT_MARKETING, 'confidence' => 0.7],
            'primary_domain' => 'marketing',
            'secondary_domains' => [],
            'flow_id' => RouterRuntimeCanon::FLOW_MARKETING,
            'runtime_mode' => RouterRuntimeCanon::MODE_DEEP,
            'routing_confidence' => 0.7,
            'policy_required' => true,
            'evidence_required' => false,
            'required_gates' => ['policy.gate'],
            'router_decision' => ['receipt_hash' => str_repeat('a', 64), 'reason' => ['reasons' => []]],
            'dispatch' => ['receipt_hash' => str_repeat('b', 64), 'dispatch_status' => RouterRuntimeCanon::DISPATCH_PLANNED, 'blockers' => []],
            'dispatch_status' => RouterRuntimeCanon::DISPATCH_PLANNED,
            'decision_receipt' => [
                'router_decision_receipt' => ['id' => 'rr', 'receipt_hash' => str_repeat('c', 64)],
                'runtime_dispatch_receipt' => ['id' => 'dr', 'receipt_hash' => str_repeat('d', 64)],
            ],
        ], $overrides);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function cyberEnvelope(array $overrides = []): array
    {
        return array_replace([
            'schema_version' => AtlasHyperflowEntryService::SCHEMA_VERSION,
            'status' => 'ready',
            'intent' => ['type' => RouterRuntimeCanon::INTENT_CYBER, 'confidence' => 0.85],
            'primary_domain' => 'cyber',
            'flow_id' => RouterRuntimeCanon::FLOW_CYBER,
            'runtime_mode' => RouterRuntimeCanon::MODE_DEEP,
            'routing_confidence' => 0.85,
            'policy_required' => true,
            'evidence_required' => true,
            'required_gates' => ['policy.gate', 'evidence.gate'],
            'router_decision' => ['receipt_hash' => str_repeat('a', 64), 'reason' => ['reasons' => []]],
            'dispatch' => ['receipt_hash' => str_repeat('b', 64), 'dispatch_status' => RouterRuntimeCanon::DISPATCH_PLANNED, 'blockers' => []],
            'dispatch_status' => RouterRuntimeCanon::DISPATCH_PLANNED,
            'decision_receipt' => [
                'router_decision_receipt' => ['id' => 'rr', 'receipt_hash' => str_repeat('c', 64)],
                'runtime_dispatch_receipt' => ['id' => 'dr', 'receipt_hash' => str_repeat('d', 64)],
            ],
        ], $overrides);
    }

    /**
     * @param  array<string,mixed>  $envelope
     */
    private function traceWithEnvelope(array $envelope): AiTrace
    {
        return (new AiTrace)->forceFill([
            'id' => (string) Str::uuid(),
            'trace_key' => 'trace_'.bin2hex(random_bytes(4)),
            'status' => 'queued',
            'operator_input' => 'anti-regression input',
            'agent_slug' => 'orquestrador',
            'provider' => 'codex_cli',
            'skill_versions' => [],
            'context_refs' => [],
            'metadata' => ['hyperflow_runtime' => $envelope],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function toHyperflow(AiTrace $trace): array
    {
        $payload = $this->toResource($trace);
        $this->assertIsArray($payload['hyperflow'], 'flat hyperflow projection must always be present when envelope exists');

        return $payload['hyperflow'];
    }

    /**
     * @return array<string,mixed>
     */
    private function toResource(AiTrace $trace): array
    {
        return (new AiTraceResource($trace))->toArray(request());
    }
}
