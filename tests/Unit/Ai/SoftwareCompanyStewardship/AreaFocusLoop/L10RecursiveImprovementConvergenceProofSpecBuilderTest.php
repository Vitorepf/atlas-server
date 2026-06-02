<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L10RecursiveImprovementConvergenceProofSpecBuilder;
use PHPUnit\Framework\TestCase;

final class L10RecursiveImprovementConvergenceProofSpecBuilderTest extends TestCase
{
    private L10RecursiveImprovementConvergenceProofSpecBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new L10RecursiveImprovementConvergenceProofSpecBuilder();
    }

    /**
     * A well-formed precondition payload: P5 and Q2 both certified, plus two
     * recursion classes whose convergence the bound must prove.
     *
     * @return array<string, mixed>
     */
    private function readyPreconditions(): array
    {
        return [
            'p5' => ['p5_certified' => true],
            'q2' => ['q2_certified' => true],
            'requested_max_depth' => 4,
            'recursion_classes' => [
                ['class_id' => 'gate_self_improvement', 'statement' => 'Improving the gate that approves improvements'],
                ['class_id' => 'planner_self_improvement'],
            ],
        ];
    }

    public function testBuildReturnsTheoremsDepthModelConvergenceMetricAssumptionsObligationsAndNotVerifiedStatus(): void
    {
        $spec = $this->builder->build($this->readyPreconditions());

        // schema_version byte-for-byte canonical.
        $this->assertSame(
            'atlas.loop.l10_recursive_improvement_convergence_proof_spec.v1',
            $spec['schema_version'],
        );

        // Aceite: theorem_ids — the two core convergence theorems first, then one per
        // recursion class, all computed from the inputs.
        $this->assertSame(
            [
                'theorem.recursion_converges_within_bound',
                'theorem.recursion_preserves_invariants',
                'theorem.gate_self_improvement',
                'theorem.planner_self_improvement',
            ],
            $spec['theorem_ids'],
        );
        $this->assertSame(4, $spec['theorem_count']);
        $this->assertSame(['gate_self_improvement', 'planner_self_improvement'], $spec['recursion_class_ids']);
        $this->assertSame(2, $spec['recursion_class_count']);

        // Aceite: recursion_depth_model — request echoed, proven/allowed depth pinned
        // to 0 (nothing proven at design time).
        $this->assertSame(4, $spec['recursion_depth_model']['requested_max_depth']);
        $this->assertSame(0, $spec['recursion_depth_model']['proven_max_depth']);
        $this->assertSame(0, $spec['recursion_depth_model']['allowed_max_depth']);
        $this->assertSame('bounded_contraction_over_recursion_depth', $spec['recursion_depth_model']['model']);
        $this->assertTrue($spec['recursion_depth_model']['depth_zero_is_no_op']);

        // Aceite: convergence_metric — a unit-interval contraction measure with the
        // predicate a future proof must establish; not yet measured.
        $this->assertSame('metric.recursive_improvement_contraction_factor', $spec['convergence_metric']['metric_id']);
        $this->assertSame('recursive_improvement_contraction_factor', $spec['convergence_metric']['name']);
        $this->assertSame('non_increasing', $spec['convergence_metric']['direction']);
        $this->assertSame(
            'contraction_factor_below_one_at_every_proven_depth',
            $spec['convergence_metric']['convergence_predicate'],
        );
        $this->assertSame(0.0, $spec['convergence_metric']['lower_bound']);
        $this->assertSame(1.0, $spec['convergence_metric']['upper_bound']);
        $this->assertFalse($spec['convergence_metric']['measured']);

        // Aceite: assumptions — the bound's declared axioms, including the honesty
        // anchor that no convergence proof is attached yet.
        $this->assertSame(
            [
                'operator_is_sole_source_of_engineering_ends',
                'p5_self_deception_immunity_holds',
                'q2_proven_invariants_hold',
                'recursion_runs_only_up_to_proven_depth',
                'divergence_or_gaming_forces_hard_stop',
                'no_convergence_proof_attached_yet',
            ],
            $spec['assumptions'],
        );

        // Aceite: proof_obligations — one per theorem, each binding a theorem to a
        // target and undischarged at design time.
        $this->assertSame(4, $spec['proof_obligation_count']);
        $this->assertCount(4, $spec['proof_obligations']);
        $this->assertSame('obligation.recursion_converges_within_bound', $spec['proof_obligations'][0]['obligation_id']);
        $this->assertSame('theorem.recursion_converges_within_bound', $spec['proof_obligations'][0]['theorem_id']);
        $this->assertSame(
            'Prove the self-improvement recursion converges within a finite proven depth bound',
            $spec['proof_obligations'][0]['statement'],
        );
        $this->assertSame(
            'Prove the self-improvement recursion never violates a Q2 invariant at any proven depth',
            $spec['proof_obligations'][1]['statement'],
        );
        // The statement-bearing recursion class folds its own claim into the obligation.
        $this->assertSame('theorem.gate_self_improvement', $spec['proof_obligations'][2]['theorem_id']);
        $this->assertSame('gate_self_improvement', $spec['proof_obligations'][2]['target']);
        $this->assertSame(
            'Prove convergence and invariant-safety for recursion class: Improving the gate that approves improvements',
            $spec['proof_obligations'][2]['statement'],
        );
        // The bare recursion class falls back to its id.
        $this->assertSame(
            'Prove convergence and invariant-safety for recursion class planner_self_improvement',
            $spec['proof_obligations'][3]['statement'],
        );
        foreach ($spec['proof_obligations'] as $obligation) {
            $this->assertFalse($obligation['discharged']);
        }

        // Aceite: proof_status=not_verified (byte-for-byte).
        $this->assertSame('not_verified', $spec['proof_status']);

        // A complete precondition payload yields a ready design spec with no blockers.
        $this->assertSame('design_spec_ready', $spec['status']);
        $this->assertSame([], $spec['blockers']);

        // verifier_requirements + computed coverage the future verifier must reach.
        $this->assertSame(
            [
                'artifact_hash_required',
                'verifier_identity_required',
                'reproducibility_required',
                'full_theorem_coverage_required',
                'proven_recursion_depth_required',
            ],
            $spec['verifier_requirements'],
        );
        $this->assertSame(4, $spec['required_theorem_coverage']);
    }

    public function testMissingP5EvidenceBlocks(): void
    {
        // Aceite: missing P5 evidence blocks. Q2 present, P5 absent.
        $spec = $this->builder->build([
            'q2' => ['q2_certified' => true],
            'recursion_classes' => [['class_id' => 'gate_self_improvement']],
        ]);

        $this->assertSame('blocked', $spec['status']);
        $this->assertContains('missing_p5_evidence', $spec['blockers']);
        $this->assertNotContains('missing_q2_evidence', $spec['blockers']);

        // Blocked still designs the spec honestly: depth proven 0, recursion closed.
        $this->assertSame('not_verified', $spec['proof_status']);
        $this->assertFalse($spec['recursion_allowed']);
        $this->assertSame(0, $spec['recursion_depth_model']['proven_max_depth']);

        // An explicit p5=false is also missing (fail-closed).
        $explicitFalse = $this->builder->build([
            'p5' => false,
            'q2' => true,
        ]);
        $this->assertContains('missing_p5_evidence', $explicitFalse['blockers']);
        $this->assertSame('blocked', $explicitFalse['status']);
    }

    public function testMissingQ2EvidenceBlocks(): void
    {
        // Aceite: missing Q2 evidence blocks. P5 present, Q2 absent.
        $spec = $this->builder->build([
            'p5' => ['p5_certified' => true],
            'recursion_classes' => [['class_id' => 'planner_self_improvement']],
        ]);

        $this->assertSame('blocked', $spec['status']);
        $this->assertContains('missing_q2_evidence', $spec['blockers']);
        $this->assertNotContains('missing_p5_evidence', $spec['blockers']);

        $this->assertSame('not_verified', $spec['proof_status']);
        $this->assertFalse($spec['recursion_allowed']);

        // A Q2 array asserting certified=false is missing (fail-closed).
        $explicitFalse = $this->builder->build([
            'p5' => true,
            'q2' => ['certified' => false],
        ]);
        $this->assertContains('missing_q2_evidence', $explicitFalse['blockers']);
        $this->assertSame('blocked', $explicitFalse['status']);
    }

    public function testBothPillarsMissingProduceBothBlockers(): void
    {
        // Neither pillar supplied -> both blockers fire, deterministically ordered.
        $spec = $this->builder->build([]);

        $this->assertSame(['missing_p5_evidence', 'missing_q2_evidence'], $spec['blockers']);
        $this->assertSame('blocked', $spec['status']);

        // Even with no classes, the two core theorems are always designed.
        $this->assertSame(
            [
                'theorem.recursion_converges_within_bound',
                'theorem.recursion_preserves_invariants',
            ],
            $spec['theorem_ids'],
        );
        $this->assertSame(2, $spec['theorem_count']);
        $this->assertSame([], $spec['recursion_class_ids']);
        $this->assertSame(0, $spec['recursion_class_count']);
    }

    public function testSpecNeverSetsRecursionAllowedTrueAcrossReadyBlockedAndOtherPayloads(): void
    {
        // Aceite/DoD: the spec NEVER opens recursion and never claims convergence is
        // proved, for every input shape — including a payload that asks for deep
        // recursion and asserts every pillar passed.
        $ready = $this->builder->build($this->readyPreconditions());
        $blocked = $this->builder->build([]);
        $aggressive = $this->builder->build([
            'p5' => true,
            'q2' => true,
            'requested_max_depth' => 1000,
            'recursion_allowed' => true,            // ignored: cannot be coerced on
            'proven_max_depth' => 999,              // ignored: never trusted
            'recursion_classes' => [['class_id' => 'ledger_self_improvement']],
        ]);

        foreach ([$ready, $blocked, $aggressive] as $spec) {
            // Aceite: spec never sets recursion_allowed=true.
            $this->assertFalse($spec['recursion_allowed']);
            $this->assertSame('not_verified', $spec['proof_status']);
            $this->assertFalse($spec['proven']);
            $this->assertFalse($spec['claims_convergence_proof']);
            // DoD: design the bound BEFORE any recursion expands — proven and allowed
            // depth stay 0 regardless of what the caller requests or asserts.
            $this->assertSame(0, $spec['recursion_depth_model']['proven_max_depth']);
            $this->assertSame(0, $spec['recursion_depth_model']['allowed_max_depth']);
        }

        // The aggressive request is echoed only as the request, never as proof.
        $this->assertSame(1000, $aggressive['recursion_depth_model']['requested_max_depth']);
    }

    public function testConvergenceMetricRespectsUnitIntervalBoundForEveryPayload(): void
    {
        // The convergence metric is bounded to [0,1]; the upper bound must NEVER
        // exceed 1.0, no matter what the caller supplies.
        foreach ([
            $this->readyPreconditions(),
            [],
            ['p5' => true, 'q2' => true, 'requested_max_depth' => -50],
            ['p5' => true, 'q2' => true, 'recursion_classes' => [['class_id' => 'x']]],
        ] as $payload) {
            $metric = $this->builder->build($payload)['convergence_metric'];

            $this->assertGreaterThanOrEqual(0.0, $metric['lower_bound']);
            $this->assertLessThanOrEqual(1.0, $metric['upper_bound']);
            $this->assertSame(0.0, $metric['lower_bound']);
            $this->assertSame(1.0, $metric['upper_bound']);
            $this->assertLessThanOrEqual($metric['upper_bound'], $metric['lower_bound']);
        }
    }

    public function testRequestedDepthIsClampedNonNegativeAndCoercedFromNumericForms(): void
    {
        // Negative request floors to 0 (a recursion depth is never below zero).
        $negative = $this->builder->build(['p5' => true, 'q2' => true, 'requested_max_depth' => -7]);
        $this->assertSame(0, $negative['recursion_depth_model']['requested_max_depth']);

        // A finite float request floors to int.
        $float = $this->builder->build(['p5' => true, 'q2' => true, 'requested_max_depth' => 3.9]);
        $this->assertSame(3, $float['recursion_depth_model']['requested_max_depth']);

        // A numeric string request is coerced.
        $string = $this->builder->build(['p5' => true, 'q2' => true, 'requested_max_depth' => '5']);
        $this->assertSame(5, $string['recursion_depth_model']['requested_max_depth']);

        // A non-numeric / absent request defaults to 0.
        $absent = $this->builder->build(['p5' => true, 'q2' => true]);
        $this->assertSame(0, $absent['recursion_depth_model']['requested_max_depth']);

        // Whatever the request, proven/allowed depth stay pinned to 0.
        foreach ([$negative, $float, $string, $absent] as $spec) {
            $this->assertSame(0, $spec['recursion_depth_model']['proven_max_depth']);
            $this->assertSame(0, $spec['recursion_depth_model']['allowed_max_depth']);
        }
    }

    public function testTheoremsGeneraliseToADifferentRecursionClassSet(): void
    {
        // A wholly different class set the ready fixture never contains: outputs must
        // track the inputs, proving the builder computes rather than canning. Plain
        // string classes are accepted alongside arrays.
        $spec = $this->builder->build([
            'p5' => true,
            'q2' => true,
            'recursion_classes' => [
                'judge_self_improvement',
                ['class_id' => 'ledger_self_improvement', 'statement' => 'Improving the value ledger'],
            ],
        ]);

        $this->assertSame(
            [
                'theorem.recursion_converges_within_bound',
                'theorem.recursion_preserves_invariants',
                'theorem.judge_self_improvement',
                'theorem.ledger_self_improvement',
            ],
            $spec['theorem_ids'],
        );
        $this->assertSame(['judge_self_improvement', 'ledger_self_improvement'], $spec['recursion_class_ids']);
        $this->assertSame(4, $spec['theorem_count']);
        $this->assertSame(4, $spec['required_theorem_coverage']);
        $this->assertSame(
            'Prove convergence and invariant-safety for recursion class: Improving the value ledger',
            $spec['proof_obligations'][3]['statement'],
        );
        $this->assertSame('design_spec_ready', $spec['status']);
    }

    public function testRecursionClassIdsAreNormalisedDeduplicatedAndPlainStringsAccepted(): void
    {
        $spec = $this->builder->build([
            'p5' => true,
            'q2' => true,
            'recursion_classes' => [
                ['class_id' => 'Gate Self-Improvement!!'],   // normalises to gate_self_improvement
                'planner_self_improvement',                  // plain string entry
                ['class_id' => 'gate_self_improvement'],      // duplicate of the first -> dropped
                ['statement' => 'no id at all'],              // no id -> dropped
            ],
        ]);

        $this->assertSame(
            [
                'theorem.recursion_converges_within_bound',
                'theorem.recursion_preserves_invariants',
                'theorem.gate_self_improvement',
                'theorem.planner_self_improvement',
            ],
            $spec['theorem_ids'],
        );
        $this->assertSame(4, $spec['theorem_count']);
        // The first-seen original id is preserved (not the later duplicate's spelling).
        $this->assertSame('Gate Self-Improvement!!', $spec['proof_obligations'][2]['target']);
        $this->assertSame(['Gate Self-Improvement!!', 'planner_self_improvement'], $spec['recursion_class_ids']);
    }

    public function testTheoremIdsHonourListOfStringContractEvenForNumericClassIds(): void
    {
        // A numeric-looking class id would coerce to an int array key internally; the
        // contract must still come back as a sequential list<string>.
        $spec = $this->builder->build([
            'p5' => true,
            'q2' => true,
            'recursion_classes' => [
                ['class_id' => '7'],
                ['class_id' => 'gate_self_improvement'],
            ],
        ]);

        $this->assertTrue(array_is_list($spec['theorem_ids']));
        $this->assertTrue(array_is_list($spec['recursion_class_ids']));
        foreach ($spec['theorem_ids'] as $theoremId) {
            $this->assertIsString($theoremId);
        }
        foreach ($spec['recursion_class_ids'] as $classId) {
            $this->assertIsString($classId);
        }
        $this->assertSame(
            [
                'theorem.recursion_converges_within_bound',
                'theorem.recursion_preserves_invariants',
                'theorem.7',
                'theorem.gate_self_improvement',
            ],
            $spec['theorem_ids'],
        );
        $this->assertSame(['7', 'gate_self_improvement'], $spec['recursion_class_ids']);
    }

    public function testRecursionClassCollidingWithACoreTheoremStaysInLockstep(): void
    {
        // A supplied recursion class whose slug collides with one of the two core
        // theorems must NOT also be counted as a distinct recursion class: the core
        // theorem already covers it. recursion_class_ids and theorem_ids must stay
        // in lockstep (theorem_count === recursion_class_count + 2 core theorems),
        // so the future full-theorem-coverage verifier never sees a reported class
        // with no covering theorem.
        $spec = $this->builder->build([
            'p5' => true,
            'q2' => true,
            'recursion_classes' => [
                ['class_id' => 'recursion_converges_within_bound'], // collides with core
                ['class_id' => 'Recursion Preserves Invariants'],   // slug collides with core
                ['class_id' => 'gate_self_improvement'],            // genuinely new
            ],
        ]);

        // Only the genuinely new class survives as a recursion class + theorem.
        $this->assertSame(['gate_self_improvement'], $spec['recursion_class_ids']);
        $this->assertSame(1, $spec['recursion_class_count']);
        $this->assertSame(
            [
                'theorem.recursion_converges_within_bound',
                'theorem.recursion_preserves_invariants',
                'theorem.gate_self_improvement',
            ],
            $spec['theorem_ids'],
        );
        $this->assertSame(3, $spec['theorem_count']);
        // The load-bearing lockstep invariant: every reported recursion class has a
        // theorem, and the only non-class theorems are the two core ones.
        $this->assertSame($spec['recursion_class_count'] + 2, $spec['theorem_count']);
        $this->assertSame($spec['theorem_count'], $spec['proof_obligation_count']);
        $this->assertSame($spec['theorem_count'], $spec['required_theorem_coverage']);

        // The obligation carrying the colliding target is the CORE obligation, not a
        // per-class one (its statement is the core convergence statement).
        $this->assertSame(
            'Prove the self-improvement recursion converges within a finite proven depth bound',
            $spec['proof_obligations'][0]['statement'],
        );
    }

    public function testTopLevelPillarFlagsAndBoolPillarsAreAccepted(): void
    {
        // Pillars supplied as top-level *_certified flags count as present.
        $topLevel = $this->builder->build([
            'p5_certified' => true,
            'q2_certified' => true,
        ]);
        $this->assertSame([], $topLevel['blockers']);
        $this->assertSame('design_spec_ready', $topLevel['status']);

        // Pillars supplied as plain bool true count as present.
        $boolForm = $this->builder->build([
            'p5' => true,
            'q2' => true,
        ]);
        $this->assertSame([], $boolForm['blockers']);
        $this->assertSame('design_spec_ready', $boolForm['status']);

        // A 'passed' array flag also counts.
        $passedForm = $this->builder->build([
            'p5' => ['passed' => true],
            'q2' => ['passed' => true],
        ]);
        $this->assertSame([], $passedForm['blockers']);
    }

    public function testSpecIdIsDeterministicContentAddressedAndClassOrderIndependent(): void
    {
        $preconditions = $this->readyPreconditions();

        $first = $this->builder->build($preconditions);
        $second = $this->builder->build($preconditions);

        // Same input -> same spec_id (pure, no clock/randomness).
        $this->assertSame($first['spec_id'], $second['spec_id']);
        $this->assertSame('l10conv_', substr($first['spec_id'], 0, 8));
        $this->assertSame(24, strlen($first['spec_id']));

        // Reordering the recursion classes yields the same id (order-independent).
        $reordered = $preconditions;
        $reordered['recursion_classes'] = [
            $preconditions['recursion_classes'][1],
            $preconditions['recursion_classes'][0],
        ];
        $this->assertSame($first['spec_id'], $this->builder->build($reordered)['spec_id']);

        // A different class set yields a different id (generalises, not canned).
        $different = $this->builder->build([
            'p5' => true,
            'q2' => true,
            'recursion_classes' => [['class_id' => 'judge_self_improvement']],
        ]);
        $this->assertNotSame($first['spec_id'], $different['spec_id']);
    }
}
