<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel\Spec;

use App\Services\Ai\EngineeringKernel\Spec\AdvisorWitnessResolver;
use App\Services\Ai\EngineeringKernel\Spec\DivergenceStatus;
use App\Services\Ai\EngineeringKernel\Spec\IntentEnvelope;
use App\Services\Ai\EngineeringKernel\Spec\OracleReport;
use App\Services\Ai\EngineeringKernel\Spec\SovereignSpecFloor;
use App\Services\Ai\EngineeringKernel\Spec\SpecDraft;
use App\Services\Ai\EngineeringKernel\Spec\SpecOracle;
use App\Services\Ai\EngineeringKernel\Spec\SpecShadowProvider;
use App\Services\Ai\EngineeringKernel\Spec\SpecVerdict;
use App\Services\Ai\EngineeringKernel\Spec\UnavailableSpecShadowProvider;
use App\Services\Ai\EngineeringKernel\TrustLevel;
use PHPUnit\Framework\TestCase;

/**
 * Slice 5 — the cross-family shadow advisor: three-state, agreement grants zero credit, and a down
 * 2nd family HOLDS (never fail-open). Wiper-safe: shadow provider + oracle faked, zero DB.
 */
final class SpecShadowAdvisorTest extends TestCase
{
    private const GOOD_AC = [
        ['id' => 'ac_behavior_add', 'description' => 'rejects an invalid email address', 'verification' => 'test', 'verification_ref' => 't1', 'case_class' => 'happy'],
    ];

    private function floor(SpecShadowProvider $shadow): SovereignSpecFloor
    {
        return new SovereignSpecFloor(
            $this->discriminatingOracle(),
            new AdvisorWitnessResolver($shadow),
        );
    }

    private function shadow(DivergenceStatus $status): SpecShadowProvider
    {
        return new class($status) implements SpecShadowProvider
        {
            public function __construct(private DivergenceStatus $status) {}

            public function compare(SpecDraft $draft, IntentEnvelope $intent): DivergenceStatus
            {
                return $this->status;
            }
        };
    }

    private function draft(): SpecDraft
    {
        return SpecDraft::fromArray(['intent_text' => 'adicionar validação em EmailValidator.php', 'acceptance_criteria' => self::GOOD_AC]);
    }

    private function intent(): IntentEnvelope
    {
        return IntentEnvelope::fromArray(['raw_goal' => 'adicionar validação em EmailValidator.php', 'recognized_verbs' => ['adicionar']]);
    }

    public function test_a_down_second_family_holds_the_autonomous_lane_never_fails_open(): void
    {
        // the exact operator reality: no reachable 2nd family
        $verdict = $this->floor(new UnavailableSpecShadowProvider)->contest($this->draft(), $this->intent(), TrustLevel::Autonomos);

        self::assertSame(SpecVerdict::HOLD, $verdict->status);
        self::assertContains('spec_source_independence', $verdict->gaps);
        self::assertSame('unavailable', $verdict->provenance->divergenceStatus->value);
    }

    public function test_shadow_divergence_routes_to_revise(): void
    {
        $verdict = $this->floor($this->shadow(DivergenceStatus::Diverged))->contest($this->draft(), $this->intent(), TrustLevel::Dev);

        self::assertSame(SpecVerdict::REVISE, $verdict->status);
        self::assertContains('intent_divergence', $verdict->gaps);
    }

    public function test_shadow_agreement_grants_zero_credit_autonomous_still_holds(): void
    {
        // even though the 2nd family AGREED, the autonomous lane still HOLDS: model agreement never
        // upgrades source-independence.
        $verdict = $this->floor($this->shadow(DivergenceStatus::Agreed))->contest($this->draft(), $this->intent(), TrustLevel::Autonomos);

        self::assertSame(SpecVerdict::HOLD, $verdict->status);
        self::assertContains('spec_source_independence', $verdict->gaps);
    }

    public function test_dev_lane_freezes_when_the_human_is_the_witness(): void
    {
        // in an interactive lane the human is the independent source; agreement/unavailable both freeze
        $agreed = $this->floor($this->shadow(DivergenceStatus::Agreed))->contest($this->draft(), $this->intent(), TrustLevel::Dev);
        $unavailable = $this->floor(new UnavailableSpecShadowProvider)->contest($this->draft(), $this->intent(), TrustLevel::Dev);

        self::assertSame(SpecVerdict::FREEZE, $agreed->status, 'gaps: '.implode(',', $agreed->gaps));
        self::assertSame(SpecVerdict::FREEZE, $unavailable->status, 'gaps: '.implode(',', $unavailable->gaps));
    }

    public function test_unavailable_provider_never_fabricates_agreed(): void
    {
        self::assertSame(DivergenceStatus::Unavailable, (new UnavailableSpecShadowProvider)->compare($this->draft(), $this->intent()));
    }

    private function discriminatingOracle(): SpecOracle
    {
        return new class implements SpecOracle
        {
            public function probe(SpecDraft $draft): OracleReport
            {
                return OracleReport::executional(['ac_behavior_add']);
            }
        };
    }
}
