<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel\Spec;

use App\Services\Ai\EngineeringKernel\Spec\DivergenceStatus;
use App\Services\Ai\EngineeringKernel\Spec\IntentEnvelope;
use App\Services\Ai\EngineeringKernel\Spec\OracleReport;
use App\Services\Ai\EngineeringKernel\Spec\SovereignSpecFloor;
use App\Services\Ai\EngineeringKernel\Spec\SpecDraft;
use App\Services\Ai\EngineeringKernel\Spec\SpecOracle;
use App\Services\Ai\EngineeringKernel\Spec\SpecSourceIndependence;
use App\Services\Ai\EngineeringKernel\Spec\SpecVerdict;
use App\Services\Ai\EngineeringKernel\Spec\WitnessContext;
use App\Services\Ai\EngineeringKernel\Spec\WitnessResolver;
use App\Services\Ai\EngineeringKernel\TrustLevel;
use PHPUnit\Framework\TestCase;

/**
 * Slice 2 — the deterministic spec floor: the no-op-impl oracle (dente anti-tautologia) + verb
 * fidelity + ambiguity HOLD + witness/lane + one-directional divergence. Wiper-safe: pure floor,
 * ports faked, zero DB.
 */
final class SovereignSpecFloorTest extends TestCase
{
    private function oracle(OracleReport $report): SpecOracle
    {
        return new class($report) implements SpecOracle
        {
            public function __construct(private OracleReport $report) {}

            public function probe(SpecDraft $draft): OracleReport
            {
                return $this->report;
            }
        };
    }

    private function witness(SpecSourceIndependence $src, DivergenceStatus $div): WitnessResolver
    {
        return new class(new WitnessContext($src, $div)) implements WitnessResolver
        {
            public function __construct(private WitnessContext $ctx) {}

            public function resolve(SpecDraft $draft, IntentEnvelope $intent, TrustLevel $lane): WitnessContext
            {
                return $this->ctx;
            }
        };
    }

    private function draft(array $criteria): SpecDraft
    {
        return SpecDraft::fromArray([
            'intent_text' => 'adicionar validação em EmailValidator.php',
            'acceptance_criteria' => $criteria,
        ]);
    }

    private const GOOD_AC = [
        ['id' => 'ac_behavior_add', 'description' => 'rejects an invalid email address', 'verification' => 'test', 'verification_ref' => 't1', 'case_class' => 'happy'],
    ];

    private function intent(): IntentEnvelope
    {
        return IntentEnvelope::fromArray(['raw_goal' => 'adicionar validação em EmailValidator.php', 'recognized_verbs' => ['adicionar']]);
    }

    public function test_a_discriminating_witnessed_spec_freezes(): void
    {
        $floor = new SovereignSpecFloor(
            $this->oracle(OracleReport::executional(['ac_behavior_add'])),
            $this->witness(SpecSourceIndependence::CrossFamilyWitnessed, DivergenceStatus::Agreed),
        );

        $verdict = $floor->contest($this->draft(self::GOOD_AC), $this->intent(), TrustLevel::Autonomos);

        self::assertSame(SpecVerdict::FREEZE, $verdict->status, 'gaps: '.implode(',', $verdict->gaps));
        self::assertSame(64, strlen($verdict->provenance->frozenHash));
    }

    public function test_a_tautological_spec_green_on_noop_is_refused(): void
    {
        // the frozen tests stayed GREEN against a no-op wrong impl => they discriminate nothing
        $floor = new SovereignSpecFloor(
            $this->oracle(OracleReport::executional([])), // zero criteria went red
            $this->witness(SpecSourceIndependence::CrossFamilyWitnessed, DivergenceStatus::Agreed),
        );

        $verdict = $floor->contest($this->draft(self::GOOD_AC), $this->intent(), TrustLevel::Autonomos);

        self::assertSame(SpecVerdict::REFUSE, $verdict->status);
        self::assertContains('oracle_adequacy', $verdict->gaps);
    }

    public function test_model_agreement_cannot_rescue_a_tautological_spec(): void
    {
        // even with a cross-family witness that AGREED, a green-on-noop spec still REFUSES:
        // the model advisor grants zero freeze credit.
        $floor = new SovereignSpecFloor(
            $this->oracle(OracleReport::executional([])),
            $this->witness(SpecSourceIndependence::CrossFamilyWitnessed, DivergenceStatus::Agreed),
        );

        $verdict = $floor->contest($this->draft(self::GOOD_AC), $this->intent(), TrustLevel::Dev);

        self::assertSame(SpecVerdict::REFUSE, $verdict->status);
    }

    public function test_verb_with_no_behavioral_criteria_is_refused_no_silent_e2_off_escape(): void
    {
        $backstopOnly = [
            ['id' => 'ac_cmd', 'description' => 'command exits 0', 'verification' => 'command', 'verification_ref' => 'c', 'is_backstop' => true],
        ];
        $floor = new SovereignSpecFloor(
            $this->oracle(OracleReport::executional([])),
            $this->witness(SpecSourceIndependence::CrossFamilyWitnessed, DivergenceStatus::Agreed),
        );

        $verdict = $floor->contest($this->draft($backstopOnly), $this->intent(), TrustLevel::Dev);

        self::assertSame(SpecVerdict::REFUSE, $verdict->status);
        self::assertContains('verb_fidelity', $verdict->gaps);
    }

    public function test_unmeasured_oracle_holds_never_fabricates_a_pass(): void
    {
        $floor = new SovereignSpecFloor(
            $this->oracle(OracleReport::unmeasured()),
            $this->witness(SpecSourceIndependence::CrossFamilyWitnessed, DivergenceStatus::NotRequired),
        );

        $verdict = $floor->contest($this->draft(self::GOOD_AC), $this->intent(), TrustLevel::Dev);

        self::assertSame(SpecVerdict::HOLD, $verdict->status);
        self::assertContains('oracle_adequacy', $verdict->gaps);
    }

    public function test_self_composed_unwitnessed_holds_in_autonomous_lane(): void
    {
        $floor = new SovereignSpecFloor(
            $this->oracle(OracleReport::executional(['ac_behavior_add'])),
            $this->witness(SpecSourceIndependence::SelfComposedUnwitnessed, DivergenceStatus::Unavailable),
        );

        $auto = $floor->contest($this->draft(self::GOOD_AC), $this->intent(), TrustLevel::Autonomos);
        $dev = $floor->contest($this->draft(self::GOOD_AC), $this->intent(), TrustLevel::Dev);

        self::assertSame(SpecVerdict::HOLD, $auto->status, 'autonomous has no independent witness');
        self::assertContains('spec_source_independence', $auto->gaps);
        self::assertSame(SpecVerdict::FREEZE, $dev->status, 'dev human IS the independent witness');
    }

    public function test_unresolved_ambiguity_holds_for_operator(): void
    {
        // a vacuous criterion (too-short description) produces an ambiguity finding
        $vacuous = [
            ['id' => 'ac_behavior_add', 'description' => 'ok', 'verification' => 'test', 'verification_ref' => 't1', 'case_class' => 'happy'],
        ];
        $floor = new SovereignSpecFloor(
            $this->oracle(OracleReport::executional(['ac_behavior_add'])),
            $this->witness(SpecSourceIndependence::CrossFamilyWitnessed, DivergenceStatus::Agreed),
        );

        $verdict = $floor->contest($this->draft($vacuous), $this->intent(), TrustLevel::Dev);

        self::assertSame(SpecVerdict::HOLD, $verdict->status);
        self::assertContains('ambiguity_resolved', $verdict->gaps);
    }

    public function test_shadow_divergence_routes_to_revise(): void
    {
        $floor = new SovereignSpecFloor(
            $this->oracle(OracleReport::executional(['ac_behavior_add'])),
            $this->witness(SpecSourceIndependence::CrossFamilyWitnessed, DivergenceStatus::Diverged),
        );

        $verdict = $floor->contest($this->draft(self::GOOD_AC), $this->intent(), TrustLevel::Dev);

        self::assertSame(SpecVerdict::REVISE, $verdict->status);
        self::assertContains('intent_divergence', $verdict->gaps);
    }
}
