<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasTeosExistingCodeMapPart02Service;
use Tests\TestCase;

/**
 * Pins the anti-duplication contract from "Atlas TEOS Existing Code Map · Parte
 * 2": the 10 rules block recreating existing components (and accept the
 * prescribed defer verdict); banned naming tokens are blocked outright; the
 * four Quality Gates are hard (must_keep_coverage==1.0, readiness_eval_not_run,
 * completed-requires-cert, operator-review for obra|long_horizon); the recovery
 * loop caps at 3 then escalates; the slice inventory is fixed at 7 greenfield /
 * 6 extend / 1 doc-only. Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/atlas-teos-existing-code-map-part-02.md
 */
class AtlasTeosExistingCodeMapPart02Test extends TestCase
{
    private function service(): AtlasTeosExistingCodeMapPart02Service
    {
        return new AtlasTeosExistingCodeMapPart02Service;
    }

    public function test_recreating_compaction_engine_is_blocked_and_defers_to_extend_existing_service(): void
    {
        // AntiDup §2: LongHorizonCompactionEngine is forbidden -> extend
        // AiCompactionService::compactForScope.
        $result = $this->service()->decideComponent([
            'name' => 'LongHorizonCompactionEngine',
            'intended_verdict' => 'greenfield',
            'is_new_class' => true,
        ]);

        $this->assertSame('blocked', $result['verdict']);
        $this->assertTrue($result['blocked']);
        $this->assertSame(2, $result['rule']);
        $this->assertSame('AiCompactionService', $result['existing']);
        $this->assertSame('extend', $result['defer_verdict']);
        $this->assertFalse($result['runtime_authorized']);
    }

    public function test_banned_target_accepted_when_resubmitted_under_prescribed_extend_verdict(): void
    {
        // The same banned target (rule 2) is accepted once the proposer asks for
        // the prescribed extend verdict — i.e. re-submitted as the doc instructs
        // ("estender AiCompactionService::compactForScope ... Re-submit como S5").
        $result = $this->service()->decideComponent([
            'name' => 'LongHorizonCompactionEngine',
            'intended_verdict' => 'extend',
        ]);

        $this->assertFalse($result['blocked']);
        $this->assertSame('extend', $result['verdict']);
        $this->assertSame(2, $result['rule']);
        $this->assertSame('AiCompactionService', $result['existing']);
    }

    public function test_banned_naming_token_is_blocked_regardless_of_intended_verdict(): void
    {
        // Rule 10 / anti-pattern: "TemporalState" et al are naming proliferation.
        foreach (['TemporalStateService', 'ContinuityEngine', 'WorkstreamMachine'] as $name) {
            $result = $this->service()->decideComponent([
                'name' => $name,
                'intended_verdict' => 'extend', // even claiming extend cannot rescue it
            ]);

            $this->assertSame('blocked', $result['verdict'], $name);
            $this->assertSame(10, $result['rule'], $name);
        }
    }

    public function test_temporal_migration_outside_long_horizon_family_is_blocked_but_inside_is_allowed(): void
    {
        $service = $this->service();

        $outside = $service->decideComponent([
            'name' => '2026_07_01_create_temporal_snapshots_table',
            'intended_verdict' => 'greenfield',
            'is_new_migration' => true,
        ]);
        $this->assertSame('blocked', $outside['verdict']);

        // A genuine greenfield migration inside the declared family passes (no
        // banned token, not a forbidden temporal_* table outside the family).
        $inside = $service->decideComponent([
            'name' => '2026_07_01_create_ai_long_horizon_continuation_packs_table',
            'intended_verdict' => 'greenfield',
            'is_new_migration' => true,
        ]);
        $this->assertFalse($inside['blocked']);
        $this->assertSame('greenfield', $inside['verdict']);
    }

    public function test_quality_gate_rejects_compaction_receipt_below_full_coverage(): void
    {
        $service = $this->service();

        $bad = $service->evaluateQualityGates([
            'must_keep_coverage' => 0.99,
            'readiness_eval_not_run' => true,
        ]);
        $this->assertFalse($bad['passed']);
        $this->assertContains('must_keep_coverage', $bad['failed_gates']);

        $good = $service->evaluateQualityGates([
            'must_keep_coverage' => 1.0,
            'readiness_eval_not_run' => true,
        ]);
        $this->assertTrue($good['passed']);
    }

    public function test_quality_gate_blocks_declared_readiness_eval_run_and_requires_operator_review(): void
    {
        $service = $this->service();

        // Declaring a readiness-harness run is forbidden by harness contract.
        $running = $service->evaluateQualityGates([
            'must_keep_coverage' => 1.0,
            'readiness_eval_status' => 'running',
        ]);
        $this->assertFalse($running['passed']);
        $this->assertContains('readiness_eval_not_run', $running['failed_gates']);

        // Promotion of scope long_horizon without operator review is refused.
        $unreviewed = $service->evaluateQualityGates([
            'must_keep_coverage' => 1.0,
            'readiness_eval_not_run' => true,
            'promotion_scope' => 'obra',
            'operator_reviewed' => false,
        ]);
        $this->assertFalse($unreviewed['passed']);
        $this->assertContains('operator_review_required', $unreviewed['failed_gates']);
    }

    public function test_recovery_loop_caps_at_three_then_escalates_to_operator(): void
    {
        $service = $this->service();

        $this->assertTrue($service->planRecovery(2)['may_retry']);
        $this->assertFalse($service->planRecovery(2)['escalate_to_operator']);

        $capped = $service->planRecovery(3);
        $this->assertFalse($capped['may_retry']);
        $this->assertTrue($capped['escalate_to_operator']);
        $this->assertSame('escalate_to_operator', $capped['action']);
        $this->assertSame(3, AtlasTeosExistingCodeMapPart02Service::MAX_RECOVERY_ATTEMPTS);
    }

    public function test_slice_inventory_is_fixed_at_seven_greenfield_six_extend_one_doc_only(): void
    {
        $inv = $this->service()->sliceInventory();

        $this->assertSame(14, $inv['total']);
        $this->assertSame(7, $inv['greenfield']);
        $this->assertSame(6, $inv['extend']);
        $this->assertSame(1, $inv['doc_only']);
        $this->assertCount(10, $this->service()->antiDuplicationRules());
    }
}
