<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasAiRuntimePacketsService;
use Tests\TestCase;

/**
 * Pins the documented runtime-packets invariants and the legacy->canonical map.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-runtime-packets.md
 */
class AtlasAiRuntimePacketsTest extends TestCase
{
    private function service(): AtlasAiRuntimePacketsService
    {
        return new AtlasAiRuntimePacketsService();
    }

    /** "Mapping Canonico": every legacy type projects onto its canonical contract. */
    public function test_legacy_types_map_to_canonical_contracts(): void
    {
        $svc = $this->service();

        $this->assertSame(
            'operation_envelope+decision_receipt+engineering_blueprint',
            $svc->resolveMapping('dev_execution')['canonical_contract'],
        );
        $this->assertSame(
            'memory_core_delta+provider_safe_review',
            $svc->resolveMapping('memory_delta')['canonical_contract'],
        );
        $this->assertSame(
            'domain_flow_selection+provider_driver_plan+decision_receipt',
            $svc->resolveMapping('ROUTER_DECISION')['canonical_contract'], // case-insensitive
        );

        $unknown = $svc->resolveMapping('legacy_ghost');
        $this->assertFalse($unknown['known']);
        $this->assertNull($unknown['canonical_contract']);
    }

    /** Invariant 1: no trace_id / envelope_id / evidence ref => reject. */
    public function test_packet_without_any_anchor_is_rejected(): void
    {
        $d = $this->service()->validate(['type' => 'dev_execution']);

        $this->assertSame(AtlasAiRuntimePacketsService::VERDICT_REJECT, $d['verdict']);
        $this->assertFalse($d['anchored']);
        $this->assertContains('missing_trace_envelope_or_evidence', $d['reasons']);

        // Any single anchor satisfies the invariant.
        $ok = $this->service()->validate(['type' => 'dev_execution', 'envelope_id' => 'env_1']);
        $this->assertTrue($ok['anchored']);
        $this->assertSame(AtlasAiRuntimePacketsService::VERDICT_ACCEPT, $ok['verdict']);
        $this->assertContains('all_invariants_satisfied', $ok['reasons']);
    }

    /** Invariant 2: a T2/T3 (or shell/file/network) op needs BOTH evidence and policy. */
    public function test_side_effecting_op_requires_evidence_and_policy(): void
    {
        // tool tier t3 with neither evidence nor policy: two reasons.
        $d = $this->service()->validate([
            'type' => 'tool_event',
            'trace_id' => 'trc_1',
            'tool_tier' => 't3',
        ]);
        $this->assertSame(AtlasAiRuntimePacketsService::VERDICT_REJECT, $d['verdict']);
        $this->assertTrue($d['side_effecting']);
        $this->assertContains('side_effect_requires_evidence', $d['reasons']);
        $this->assertContains('side_effect_requires_policy', $d['reasons']);

        // file_write with evidence AND policy: accepted.
        $ok = $this->service()->validate([
            'type' => 'tool_event',
            'trace_id' => 'trc_1',
            'operation_kind' => 'file_write',
            'evidence_refs' => ['ledger:e1'],
            'has_policy' => true,
        ]);
        $this->assertSame(AtlasAiRuntimePacketsService::VERDICT_ACCEPT, $ok['verdict']);
        $this->assertTrue($ok['side_effecting']);

        // A t1 read-tier op is not side-effecting: anchor alone suffices.
        $readOnly = $this->service()->validate([
            'type' => 'tool_event',
            'trace_id' => 'trc_1',
            'tool_tier' => 't1',
        ]);
        $this->assertFalse($readOnly['side_effecting']);
        $this->assertSame(AtlasAiRuntimePacketsService::VERDICT_ACCEPT, $readOnly['verdict']);
    }

    /** Invariant 3: a memory_delta cannot enter active memory without review + provider-safety. */
    public function test_memory_delta_needs_review_and_provider_safety(): void
    {
        $d = $this->service()->validate([
            'type' => 'memory_delta',
            'trace_id' => 'trc_1',
        ]);
        $this->assertSame(AtlasAiRuntimePacketsService::VERDICT_REJECT, $d['verdict']);
        $this->assertContains('memory_delta_needs_review', $d['reasons']);
        $this->assertContains('memory_delta_needs_provider_safety', $d['reasons']);

        // Reviewed but not provider-safe: still rejected on the remaining gate.
        $half = $this->service()->validate([
            'type' => 'memory_delta',
            'trace_id' => 'trc_1',
            'reviewed' => true,
        ]);
        $this->assertSame(AtlasAiRuntimePacketsService::VERDICT_REJECT, $half['verdict']);
        $this->assertContains('memory_delta_needs_provider_safety', $half['reasons']);
        $this->assertNotContains('memory_delta_needs_review', $half['reasons']);

        // Both gates cleared: accepted.
        $ok = $this->service()->validate([
            'type' => 'memory_delta',
            'trace_id' => 'trc_1',
            'reviewed' => true,
            'provider_safe' => true,
        ]);
        $this->assertSame(AtlasAiRuntimePacketsService::VERDICT_ACCEPT, $ok['verdict']);
        $this->assertTrue($this->service()->isAcceptable([
            'type' => 'memory_delta',
            'trace_id' => 'trc_1',
            'reviewed' => true,
            'provider_safe' => true,
        ]));
    }

    /** Invariant 4: a router_decision must distinguish domain/flow/provider/executor/safety. */
    public function test_router_decision_requires_all_routing_axes(): void
    {
        // Missing executor_preference and safety_autonomy.
        $d = $this->service()->validate([
            'type' => 'router_decision',
            'trace_id' => 'trc_1',
            'domain' => 'engineering',
            'flow' => 'build',
            'provider' => 'claude_code',
        ]);
        $this->assertSame(AtlasAiRuntimePacketsService::VERDICT_REJECT, $d['verdict']);
        $this->assertContains('router_decision_missing:executor_preference', $d['reasons']);
        $this->assertContains('router_decision_missing:safety_autonomy', $d['reasons']);

        // All five axes present: accepted.
        $ok = $this->service()->validate([
            'type' => 'router_decision',
            'trace_id' => 'trc_1',
            'domain' => 'engineering',
            'flow' => 'build',
            'provider' => 'claude_code',
            'executor_preference' => 'provider',
            'safety_autonomy' => 'guarded',
        ]);
        $this->assertSame(AtlasAiRuntimePacketsService::VERDICT_ACCEPT, $ok['verdict']);
    }

    /** Invariant 5: a completion packet does not replace a ledger replay. */
    public function test_completion_packet_does_not_replace_ledger_replay(): void
    {
        $d = $this->service()->validate([
            'type' => 'completion',
            'trace_id' => 'trc_1',
        ]);
        $this->assertSame(AtlasAiRuntimePacketsService::VERDICT_REJECT, $d['verdict']);
        $this->assertContains('completion_packet_does_not_replace_ledger_replay', $d['reasons']);

        $ok = $this->service()->validate([
            'type' => 'completion',
            'trace_id' => 'trc_1',
            'ledger_replayable' => true,
        ]);
        $this->assertSame(AtlasAiRuntimePacketsService::VERDICT_ACCEPT, $ok['verdict']);
    }

    /** Every verdict is auditable and carries the stable receipt schema. */
    public function test_verdict_is_auditable_with_stable_schema(): void
    {
        $d = $this->service()->validate(['type' => 'dev_execution', 'trace_id' => 'trc_1']);

        $this->assertSame('atlas.runtime.packets_guard.v1', $d['schema']);
        $this->assertTrue($d['auditable']);
    }
}
