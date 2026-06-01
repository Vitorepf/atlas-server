<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCognitiveRuntimeRunbookService;
use Tests\TestCase;

/**
 * Pins the documented Atlas AI Cognitive Runtime Runbook operational rules.
 *
 * @see docs/engineering-knowledge-base/cognitive-runtime/runbook.md
 * @see docs/engineering-knowledge-base/cognitive-runtime/schemas-and-packets.md
 */
class AtlasCognitiveRuntimeRunbookTest extends TestCase
{
    private function service(): AtlasCognitiveRuntimeRunbookService
    {
        return new AtlasCognitiveRuntimeRunbookService();
    }

    /**
     * Helper: a fully preserved, evidence-bearing, privacy-redacted compaction.
     *
     * @return array<string,mixed>
     */
    private function cleanCompaction(): array
    {
        return [
            'preserved' => array_fill_keys(
                AtlasCognitiveRuntimeRunbookService::REQUIRED_PRESERVED_FIELDS,
                true,
            ),
            'evidence_refs' => ['ledger/EV-1'],
            'needs_raw_chat' => false,
            'policy_ambiguous' => false,
            'privacy_redacted' => true,
            'canonical_conflict' => false,
        ];
    }

    /**
     * "Compaction Review" lists exactly ten fields that must be preserved, and
     * the seven Stop-Criteria triggers are catalogued.
     */
    public function test_preserved_fields_and_stop_triggers_taxonomy(): void
    {
        $this->assertCount(10, AtlasCognitiveRuntimeRunbookService::REQUIRED_PRESERVED_FIELDS);
        $this->assertContains('rejected_alternatives', AtlasCognitiveRuntimeRunbookService::REQUIRED_PRESERVED_FIELDS);
        $this->assertContains('forbidden_actions', AtlasCognitiveRuntimeRunbookService::REQUIRED_PRESERVED_FIELDS);

        $this->assertCount(7, AtlasCognitiveRuntimeRunbookService::STOP_TRIGGERS);
        $this->assertCount(5, AtlasCognitiveRuntimeRunbookService::PROMOTION_GATES);
    }

    /**
     * A clean compaction is accepted; dropping a single required preserved field
     * blocks it as hot-files/ownership ambiguous (and never silently accepts).
     */
    public function test_compaction_accepted_only_when_all_fields_preserved(): void
    {
        $svc = $this->service();

        $clean = $svc->reviewCompaction($this->cleanCompaction());
        $this->assertTrue($clean['accepted']);
        $this->assertSame(AtlasCognitiveRuntimeRunbookService::COMPACTION_READY, $clean['status']);
        $this->assertSame(10, $clean['preserved_count']);

        $dropped = $this->cleanCompaction();
        $dropped['preserved']['hot_files'] = false;
        $result = $svc->reviewCompaction($dropped);
        $this->assertFalse($result['accepted']);
        $this->assertSame(
            AtlasCognitiveRuntimeRunbookService::COMPACTION_BLOCKED_HOT_FILES_AMBIGUOUS,
            $result['status'],
        );
        $this->assertSame(['hot_files'], $result['missing_preserved_fields']);
    }

    /**
     * The five documented block conditions each map to the canonical
     * compaction-packet status: missing evidence, privacy not redacted, and a
     * canonical-doc conflict are all hard blocks with their own status code.
     */
    public function test_compaction_block_conditions_map_to_packet_status(): void
    {
        $svc = $this->service();

        $noEvidence = $this->cleanCompaction();
        $noEvidence['evidence_refs'] = [];
        $this->assertSame(
            AtlasCognitiveRuntimeRunbookService::COMPACTION_BLOCKED_MISSING_EVIDENCE,
            $svc->reviewCompaction($noEvidence)['status'],
        );

        $privacy = $this->cleanCompaction();
        $privacy['privacy_redacted'] = false;
        $privacyResult = $svc->reviewCompaction($privacy);
        $this->assertSame(
            AtlasCognitiveRuntimeRunbookService::COMPACTION_BLOCKED_PRIVACY_GAP,
            $privacyResult['status'],
        );
        $this->assertContains('privacy_not_redacted', $privacyResult['blocked_reasons']);

        $canonical = $this->cleanCompaction();
        $canonical['canonical_conflict'] = true;
        $this->assertSame(
            AtlasCognitiveRuntimeRunbookService::COMPACTION_BLOCKED_CANONICAL_CONFLICT,
            $svc->reviewCompaction($canonical)['status'],
        );

        // A summary that would force the next executor back to raw chat is a
        // policy/continuity gap.
        $rawChat = $this->cleanCompaction();
        $rawChat['needs_raw_chat'] = true;
        $rawResult = $svc->reviewCompaction($rawChat);
        $this->assertFalse($rawResult['accepted']);
        $this->assertContains('next_executor_needs_raw_chat', $rawResult['blocked_reasons']);
    }

    /**
     * "Stop Criteria": objective drift stops the session only on its SECOND
     * appearance — one drift is tolerated (continue), two flips to watch.
     */
    public function test_objective_drift_stops_only_on_second_appearance(): void
    {
        $svc = $this->service();

        $oneDrift = $svc->evaluateStopCriteria(['objective_drift_count' => 1]);
        $this->assertSame(AtlasCognitiveRuntimeRunbookService::SESSION_CONTINUE, $oneDrift['verdict']);
        $this->assertFalse($oneDrift['should_stop']);

        $twoDrift = $svc->evaluateStopCriteria(['objective_drift_count' => 2]);
        $this->assertSame(AtlasCognitiveRuntimeRunbookService::SESSION_WATCH, $twoDrift['verdict']);
        $this->assertTrue($twoDrift['should_stop']);
        $this->assertContains('objective_drift', $twoDrift['triggered']);
        $this->assertSame(2, AtlasCognitiveRuntimeRunbookService::DRIFT_STOP_THRESHOLD);
    }

    /**
     * A hot file edited by mistake or unsafe provider context makes continuity
     * unsafe -> hard `blocked`; a cost-without-gain trigger only degrades to
     * `watch`.
     */
    public function test_unsafe_triggers_block_soft_triggers_only_watch(): void
    {
        $svc = $this->service();

        $hotFile = $svc->evaluateStopCriteria(['hot_file_edited_by_mistake' => true]);
        $this->assertSame(AtlasCognitiveRuntimeRunbookService::SESSION_BLOCKED, $hotFile['verdict']);
        $this->assertContains('hot_file_edited_by_mistake', $hotFile['blocking_triggers']);

        $unsafeProvider = $svc->evaluateStopCriteria(['unsafe_provider_context' => true]);
        $this->assertSame(AtlasCognitiveRuntimeRunbookService::SESSION_BLOCKED, $unsafeProvider['verdict']);

        $cost = $svc->evaluateStopCriteria(['cost_up_without_gain' => true]);
        $this->assertSame(AtlasCognitiveRuntimeRunbookService::SESSION_WATCH, $cost['verdict']);
        $this->assertSame([], $cost['blocking_triggers']);
    }

    /**
     * Post-Session Audit packet: no harm => ready; a hard harm (lost decision)
     * => critical; a soft harm (stale context) => watch. net_value = gain - harm.
     */
    public function test_audit_packet_status_and_net_value(): void
    {
        $svc = $this->service();

        $clean = $svc->evaluateAudit([
            'gain' => ['useful_context' => 5, 'decision_reuse' => 2],
            'harm' => [],
        ]);
        $this->assertSame(AtlasCognitiveRuntimeRunbookService::AUDIT_READY, $clean['status']);
        $this->assertSame(7, $clean['net_value']);
        $this->assertTrue($clean['read_only']);

        $critical = $svc->evaluateAudit([
            'gain' => ['useful_context' => 4],
            'harm' => ['lost_decision' => 1],
        ]);
        $this->assertSame(AtlasCognitiveRuntimeRunbookService::AUDIT_CRITICAL, $critical['status']);
        $this->assertSame(3, $critical['net_value']);
        $this->assertContains('lost_decision', $critical['critical_harms']);

        $watch = $svc->evaluateAudit([
            'gain' => ['useful_context' => 2],
            'harm' => ['stale_context' => 1],
        ]);
        $this->assertSame(AtlasCognitiveRuntimeRunbookService::AUDIT_WATCH, $watch['status']);
    }

    /**
     * "Promotion": all five gates must hold. A watch audit only satisfies the
     * audit gate when the operator explicitly accepts the risks; a single unmet
     * gate blocks promotion and is named.
     */
    public function test_promotion_requires_all_five_gates(): void
    {
        $svc = $this->service();

        $base = [
            'snapshot_complete' => true,
            'compaction_status' => AtlasCognitiveRuntimeRunbookService::COMPACTION_READY,
            'audit_status' => AtlasCognitiveRuntimeRunbookService::AUDIT_READY,
            'validations_passed' => true,
            'replay_reconstructs' => true,
        ];

        $full = $svc->evaluatePromotion($base);
        $this->assertTrue($full['promote']);
        $this->assertSame(5, $full['gates_met']);
        $this->assertSame([], $full['unmet_gates']);

        // Replay cannot rebuild state without raw chat => promotion blocked.
        $noReplay = $base;
        $noReplay['replay_reconstructs'] = false;
        $blocked = $svc->evaluatePromotion($noReplay);
        $this->assertFalse($blocked['promote']);
        $this->assertContains('replay_reconstructs_without_raw_chat', $blocked['unmet_gates']);

        // A watch audit without accepted risks fails the audit gate; with
        // operator acceptance it passes.
        $watchAudit = $base;
        $watchAudit['audit_status'] = AtlasCognitiveRuntimeRunbookService::AUDIT_WATCH;
        $this->assertFalse($svc->mayPromote($watchAudit));

        $watchAudit['audit_risks_accepted'] = true;
        $this->assertTrue($svc->mayPromote($watchAudit));
    }
}
