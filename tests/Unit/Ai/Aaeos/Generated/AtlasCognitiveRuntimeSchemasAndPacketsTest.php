<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCognitiveRuntimeSchemasAndPacketsService;
use Tests\TestCase;

/**
 * Pins the documented Atlas AI Cognitive Runtime schema & packet contracts.
 *
 * @see docs/engineering-knowledge-base/cognitive-runtime/schemas-and-packets.md
 */
class AtlasCognitiveRuntimeSchemasAndPacketsTest extends TestCase
{
    private function service(): AtlasCognitiveRuntimeSchemasAndPacketsService
    {
        return new AtlasCognitiveRuntimeSchemasAndPacketsService();
    }

    /**
     * Packet Rules: a clean read-only packet may feed Self-Improvement
     * proposal-only, but the three mutate capabilities are ALWAYS denied — even
     * when the packet tries to claim them.
     */
    public function test_packet_rules_deny_all_mutate_capabilities_even_when_claimed(): void
    {
        $svc = $this->service();

        $verdict = $svc->evaluatePacketRules([
            'provider_safe' => true,
            'carries_raw_chat' => false,
            'read_only' => true,
            // A hostile packet asserting it may mutate Atlas — must be ignored.
            'promote_memory' => true,
            'alter_policy' => true,
            'apply_patch' => true,
        ]);

        $this->assertTrue($verdict['compliant']);
        $this->assertTrue($verdict['capabilities'][AtlasCognitiveRuntimeSchemasAndPacketsService::CAP_FEED_PROPOSAL_ONLY]);
        $this->assertFalse($verdict['capabilities'][AtlasCognitiveRuntimeSchemasAndPacketsService::CAP_PROMOTE_MEMORY]);
        $this->assertFalse($verdict['capabilities'][AtlasCognitiveRuntimeSchemasAndPacketsService::CAP_ALTER_POLICY]);
        $this->assertFalse($verdict['capabilities'][AtlasCognitiveRuntimeSchemasAndPacketsService::CAP_APPLY_PATCH]);
        $this->assertFalse($svc->packetMayPerform([], AtlasCognitiveRuntimeSchemasAndPacketsService::CAP_PROMOTE_MEMORY));
    }

    /**
     * Packet Rules: a packet carrying raw chat (not provider-safe) is non-compliant
     * and loses even the proposal-only capability — packets "nao carregam chat bruto".
     */
    public function test_packet_carrying_raw_chat_is_non_compliant_and_loses_proposal_capability(): void
    {
        $verdict = $this->service()->evaluatePacketRules([
            'provider_safe' => false,
            'carries_raw_chat' => true,
            'read_only' => true,
        ]);

        $this->assertFalse($verdict['compliant']);
        $this->assertContains('packet_not_provider_safe', $verdict['violations']);
        $this->assertContains('packet_carries_raw_chat', $verdict['violations']);
        $this->assertFalse($verdict['capabilities'][AtlasCognitiveRuntimeSchemasAndPacketsService::CAP_FEED_PROPOSAL_ONLY]);
    }

    /**
     * Long Session Snapshot: a status outside the closed set is rejected, and an
     * incomplete quality_metrics block is reported field-by-field.
     */
    public function test_snapshot_rejects_bad_status_and_incomplete_quality_metrics(): void
    {
        $svc = $this->service();

        $bad = $svc->validateSnapshot([
            'schema_version' => AtlasCognitiveRuntimeSchemasAndPacketsService::SCHEMA_SNAPSHOT,
            'status' => 'archived', // not in active|paused|compacted|handoff|closed|blocked
            'current_phase' => 'implementation',
            'quality_metrics' => ['decision_quality' => 0.0], // 8 keys missing
        ]);

        $this->assertFalse($bad['valid']);
        $this->assertContains('status_out_of_enum', $bad['errors']);
        $this->assertContains('quality_metrics_incomplete', $bad['errors']);
        $this->assertContains('drift_rate', $bad['missing_quality_metrics']);
        $this->assertCount(8, $bad['missing_quality_metrics']);

        $good = $svc->validateSnapshot([
            'schema_version' => AtlasCognitiveRuntimeSchemasAndPacketsService::SCHEMA_SNAPSHOT,
            'status' => 'compacted',
            'current_phase' => 'review',
            'quality_metrics' => array_fill_keys(
                AtlasCognitiveRuntimeSchemasAndPacketsService::SNAPSHOT_QUALITY_METRIC_KEYS,
                0,
            ),
        ]);
        $this->assertTrue($good['valid']);
    }

    /**
     * Compaction Packet cross-field rules: a `blocked_*` status with no
     * blocked_reasons is invalid ("Non-empty when continuity is unsafe"), and a
     * `ready` status with no next_action is invalid ("Concrete next step if
     * status is ready").
     */
    public function test_compaction_packet_blocked_requires_reasons_and_ready_requires_next_action(): void
    {
        $svc = $this->service();
        $base = [
            'schema_version' => AtlasCognitiveRuntimeSchemasAndPacketsService::SCHEMA_COMPACTION,
            'source_session_id' => 's1',
            'summary_hash' => 'sha256:x',
            'preserved_decisions' => ['d'],
            'preserved_invariants' => ['i'],
            'evidence_refs' => ['ledger/EV-1'],
        ];

        $blockedNoReasons = $svc->validateCompactionPacket($base + [
            'status' => 'blocked_missing_evidence',
            'blocked_reasons' => [],
            'next_action' => null,
        ]);
        $this->assertFalse($blockedNoReasons['valid']);
        $this->assertContains('blocked_status_requires_blocked_reasons', $blockedNoReasons['errors']);

        $readyNoAction = $svc->validateCompactionPacket($base + [
            'status' => 'ready',
            'blocked_reasons' => [],
            'next_action' => '',
        ]);
        $this->assertFalse($readyNoAction['valid']);
        $this->assertContains('ready_status_requires_next_action', $readyNoAction['errors']);

        $readyOk = $svc->validateCompactionPacket($base + [
            'status' => 'ready',
            'blocked_reasons' => [],
            'next_action' => 'run validation suite',
        ]);
        $this->assertTrue($readyOk['valid']);
    }

    /**
     * Cognitive Audit Packet: net_value must equal sum(gain) - sum(harm); an
     * inconsistent declared net_value is flagged.
     */
    public function test_audit_packet_enforces_net_value_equals_gain_minus_harm(): void
    {
        $svc = $this->service();
        $base = [
            'schema_version' => AtlasCognitiveRuntimeSchemasAndPacketsService::SCHEMA_AUDIT,
            'status' => 'watch',
            'gain' => ['useful_context' => 10, 'repeated_work_avoided' => 4, 'decision_reuse' => 1], // sum 15
            'harm' => ['wrong_context' => 1, 'stale_context' => 2, 'context_contamination' => 0, 'lost_decision' => 0, 'policy_violation' => 0], // sum 3
        ];

        $wrong = $svc->validateAuditPacket($base + ['net_value' => 99]);
        $this->assertFalse($wrong['valid']);
        $this->assertContains('net_value_inconsistent', $wrong['errors']);
        $this->assertSame(12.0, $wrong['expected_net_value']); // 15 - 3

        $right = $svc->validateAuditPacket($base + ['net_value' => 12]);
        $this->assertTrue($right['valid']);
        $this->assertTrue($right['net_value_consistent']);
    }

    /**
     * Retrieval Evaluation Packet: all eight required measures must be present;
     * the missing ones are reported.
     */
    public function test_retrieval_evaluation_requires_all_eight_measures(): void
    {
        $svc = $this->service();

        $partial = $svc->validateRetrievalEvaluation([
            'precision_at_3' => 0.8,
            'precision_at_5' => 0.7,
            // remaining six measures missing
        ]);
        $this->assertFalse($partial['valid']);
        $this->assertSame(8, $partial['measures_total']);
        $this->assertSame(2, $partial['measures_present']);
        $this->assertContains('reason_coverage', $partial['missing_measures']);
        $this->assertContains('provider_safe_violation_count', $partial['missing_measures']);

        $full = $svc->validateRetrievalEvaluation(array_fill_keys(
            AtlasCognitiveRuntimeSchemasAndPacketsService::RETRIEVAL_REQUIRED_MEASURES,
            0,
        ));
        $this->assertTrue($full['valid']);
    }

    /**
     * Promotion Rule: maturity promotion needs snapshot + compaction + audit each
     * `ready`, OR `watch` with operator-accepted risks. A `watch` without accepted
     * risks fails; the same `watch` with accepted risks passes. Promotion gating
     * never blocks the human conversation.
     */
    public function test_promotion_rule_over_three_packet_statuses(): void
    {
        $svc = $this->service();

        // All ready -> promote, and conversation is never blocked.
        $allReady = $svc->evaluatePromotion([
            'snapshot_status' => 'ready',
            'compaction_status' => 'ready',
            'audit_status' => 'ready',
            'risks_accepted_by_operator' => false,
        ]);
        $this->assertTrue($allReady['may_promote_to_maturity_evidence']);
        $this->assertFalse($allReady['blocks_human_conversation']);
        $this->assertTrue($svc->mayPromoteToMaturity([
            'snapshot_status' => 'ready',
            'compaction_status' => 'ready',
            'audit_status' => 'ready',
        ]));

        // audit = watch, risks NOT accepted -> blocked at audit.
        $watchUnaccepted = $svc->evaluatePromotion([
            'snapshot_status' => 'ready',
            'compaction_status' => 'ready',
            'audit_status' => 'watch',
            'risks_accepted_by_operator' => false,
        ]);
        $this->assertFalse($watchUnaccepted['may_promote_to_maturity_evidence']);
        $this->assertContains('audit', $watchUnaccepted['unmet_gates']);

        // Same, but risks accepted -> promote.
        $watchAccepted = $svc->evaluatePromotion([
            'snapshot_status' => 'ready',
            'compaction_status' => 'ready',
            'audit_status' => 'watch',
            'risks_accepted_by_operator' => true,
        ]);
        $this->assertTrue($watchAccepted['may_promote_to_maturity_evidence']);

        // A critical audit can never be promoted, even with risks accepted.
        $critical = $svc->evaluatePromotion([
            'snapshot_status' => 'ready',
            'compaction_status' => 'ready',
            'audit_status' => 'critical',
            'risks_accepted_by_operator' => true,
        ]);
        $this->assertFalse($critical['may_promote_to_maturity_evidence']);
        $this->assertContains('audit', $critical['unmet_gates']);
    }
}
