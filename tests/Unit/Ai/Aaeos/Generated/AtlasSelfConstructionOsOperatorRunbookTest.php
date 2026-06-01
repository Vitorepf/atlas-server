<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasSelfConstructionOsOperatorRunbookService;
use Tests\TestCase;

final class AtlasSelfConstructionOsOperatorRunbookTest extends TestCase
{
    private AtlasSelfConstructionOsOperatorRunbookService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasSelfConstructionOsOperatorRunbookService();
    }

    /** Section 5: any red (or missing) pre-sprint gate blocks the sprint; all green is the only "may start". */
    public function testPreSprintAnyRedGateBlocksAndOpensRemediationSlice(): void
    {
        $allGreen = array_fill_keys(AtlasSelfConstructionOsOperatorRunbookService::PRE_SPRINT_GATES, true);

        $ok = $this->service->evaluatePreSprint($allGreen);
        $this->assertTrue($ok['may_start']);
        $this->assertSame('may_start', $ok['verdict']);
        $this->assertSame('sprint_may_open_single_axis', $ok['directive']);

        // One red gate: runtime_safety_all_false is the safety floor.
        $oneRed = $allGreen;
        $oneRed['runtime_safety_all_false'] = false;
        $blocked = $this->service->evaluatePreSprint($oneRed);
        $this->assertFalse($blocked['may_start']);
        $this->assertSame('blocked', $blocked['verdict']);
        $this->assertSame(['runtime_safety_all_false'], $blocked['red_gates']);
        $this->assertSame('open_remediation_slice', $blocked['directive']);

        // A missing fact cannot be asserted green -> it blocks too ("no looks fine as evidence").
        $missing = $allGreen;
        unset($missing['docs_health_ok']);
        $blockedMissing = $this->service->evaluatePreSprint($missing);
        $this->assertFalse($blockedMissing['may_start']);
        $this->assertSame(['docs_health_ok'], $blockedMissing['missing_gates']);
    }

    /**
     * Section 7: next_required_slice must point to exactly one slice.
     * Empty => stop. >1 => fan-out. Changed without evidence delta => drift.
     */
    public function testNextRequiredSliceReadingRules(): void
    {
        $single = $this->service->readNextRequiredSlice(['promote_scope_lock_planner']);
        $this->assertTrue($single['actionable']);
        $this->assertSame('single_slice', $single['status']);
        $this->assertSame('promote_scope_lock_planner', $single['slice']);

        // Empty is a STOP, not a green light.
        $empty = $this->service->readNextRequiredSlice([]);
        $this->assertFalse($empty['actionable']);
        $this->assertSame('empty_stop', $empty['status']);
        $this->assertNull($empty['slice']);

        // Two slices is a fan-out that must be collapsed before work.
        $fanout = $this->service->readNextRequiredSlice(['slice_a', 'slice_b']);
        $this->assertFalse($fanout['actionable']);
        $this->assertSame('fan_out', $fanout['status']);
        $this->assertSame(2, $fanout['count']);

        // Single slice but changed with no evidence delta => drift, do not act.
        $drift = $this->service->readNextRequiredSlice(['slice_x'], changedSinceLast: true, evidenceDelta: false);
        $this->assertFalse($drift['actionable']);
        $this->assertSame('drift', $drift['status']);
        $this->assertSame('treat_as_drift_do_not_act_until_evidence_supports', $drift['directive']);
    }

    /** Section 8: while the flag is true, a runtime-suggesting output is the wrong one — trust the flag. */
    public function testRuntimeSafetyFlagBeatsConflictingOutput(): void
    {
        $conflict = $this->service->readRuntimeSafetyAllFalse(flagTrue: true, outputSuggestsRuntime: true);
        $this->assertFalse($conflict['provider_dispatch_enabled']);
        $this->assertTrue($conflict['output_suggests_runtime_conflict']);
        $this->assertSame('runtime_safety_all_false_flag', $conflict['trusted_source']);
        $this->assertSame('output_is_wrong_trust_the_flag_keep_dispatch_disabled', $conflict['directive']);

        // Flipping the flag is its own macro-sprint, never a feature side effect.
        $flipped = $this->service->readRuntimeSafetyAllFalse(flagTrue: false);
        $this->assertFalse($flipped['provider_dispatch_enabled']);
        $this->assertSame('flip_requires_separate_macro_sprint_with_signed_receipt_and_human_review', $flipped['directive']);
    }

    /** Section 9: warning past deadline graduates to a violation; unowned warning blocks sprint closure. */
    public function testWarningGraduatesPastDeadlineAndUnownedWarningBlocksClose(): void
    {
        // A clean warning (owned, within deadline) does not block and does not graduate.
        $clean = $this->service->classifyViolationsAndWarnings(
            violations: [],
            warnings: [['id' => 'w1', 'owner' => 'atlas-ai', 'age_days' => 2, 'deadline_days' => 7]],
        );
        $this->assertFalse($clean['blocks_promotion']);
        $this->assertSame([], $clean['graduated_warnings']);
        $this->assertTrue($clean['may_close_sprint']);
        $this->assertSame('warnings_tracked_sprint_may_close', $clean['directive']);

        // Past-deadline warning graduates to an effective violation and blocks promotion.
        $stale = $this->service->classifyViolationsAndWarnings(
            violations: [],
            warnings: [['id' => 'w2', 'owner' => 'atlas-ai', 'age_days' => 10, 'deadline_days' => 7]],
        );
        $this->assertSame(['w2'], $stale['graduated_warnings']);
        $this->assertSame(['w2'], $stale['effective_violations']);
        $this->assertTrue($stale['blocks_promotion']);
        $this->assertTrue($stale['blocks_scope_lock_release']);
        $this->assertFalse($stale['may_close_sprint']);

        // Unowned warning (within deadline) does not graduate but blocks sprint closure.
        $unowned = $this->service->classifyViolationsAndWarnings(
            violations: [],
            warnings: [['id' => 'w3', 'age_days' => 1, 'deadline_days' => 7]],
        );
        $this->assertFalse($unowned['blocks_promotion']);
        $this->assertSame(['w3'], $unowned['unowned_warnings']);
        $this->assertFalse($unowned['may_close_sprint']);
        $this->assertSame('assign_owner_target_slice_and_deadline_before_close', $unowned['directive']);
    }

    /** Section 10: any stop condition forces an unconditional stop plus a human review packet. */
    public function testStopConditionForcesHumanReviewPacket(): void
    {
        $none = $this->service->evaluateStopConditions([]);
        $this->assertFalse($none['must_stop']);
        $this->assertSame([], $none['human_review_packet_fields']);

        $stop = $this->service->evaluateStopConditions([
            'runtime_safety_all_false_flipped' => true,
        ]);
        $this->assertTrue($stop['must_stop']);
        $this->assertSame('stop_unconditional', $stop['verdict']);
        $this->assertSame(['runtime_safety_all_false_flipped'], $stop['triggered']);
        $this->assertTrue($stop['requires_human_review']);
        // Packet must carry the eight documented fields.
        $this->assertSame([
            'snapshot_id',
            'evidence_ledger_ref',
            'replay_diff_ref',
            'certification_batch_id',
            'violations',
            'warnings',
            'requested_decision',
            'rollback_plan',
        ], $stop['human_review_packet_fields']);
    }

    /** Section 11: one red pre-runtime gate keeps runtime disabled, and there is no fast path. */
    public function testPreRuntimeGateHasNoFastPath(): void
    {
        $allGreen = array_fill_keys(AtlasSelfConstructionOsOperatorRunbookService::PRE_RUNTIME_GATES, true);

        $green = $this->service->evaluatePreRuntimeGate($allGreen);
        $this->assertTrue($green['may_recommend_runtime_flip']);
        $this->assertFalse($green['fast_path_available']);

        $oneRed = $allGreen;
        $oneRed['human_review_packet_signed'] = false;
        $blocked = $this->service->evaluatePreRuntimeGate($oneRed);
        $this->assertFalse($blocked['may_recommend_runtime_flip']);
        $this->assertSame('runtime_stays_disabled', $blocked['verdict']);
        $this->assertSame(['human_review_packet_signed'], $blocked['red_gates']);
        $this->assertFalse($blocked['fast_path_available']);
        $this->assertSame('runtime_disabled_close_remaining_gates_no_fast_path', $blocked['directive']);
    }

    /** Section 3 + global invariant: snapshot never authorizes runtime and keeps dispatch disabled. */
    public function testSnapshotNeverAuthorizesRuntimeAndSurfacesAreNotInterchangeable(): void
    {
        $snap = $this->service->snapshot();
        $this->assertFalse($snap['authorizes_runtime']);
        $this->assertFalse($snap['provider_dispatch_enabled']);
        $this->assertTrue($snap['runtime_safety_all_false_expected']);
        $this->assertSame(
            ['certification', 'replay', 'workbench', 'observatory', 'runtime_pilot'],
            array_keys($snap['surfaces']),
        );

        // An unknown surface must not be relabelled; the operator stops.
        $bad = $this->service->resolveSurface('production');
        $this->assertFalse($bad['resolved']);
        $this->assertSame('unknown_surface_do_not_relabel_stop', $bad['directive']);
    }
}
