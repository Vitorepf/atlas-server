<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel\Spec;

use App\Services\Ai\EngineeringKernel\Spec\AdvisorWitnessResolver;
use App\Services\Ai\EngineeringKernel\Spec\AtlasSpecGateAdapter;
use App\Services\Ai\EngineeringKernel\Spec\DivergenceStatus;
use App\Services\Ai\EngineeringKernel\Spec\IntentEnvelope;
use App\Services\Ai\EngineeringKernel\Spec\OracleReport;
use App\Services\Ai\EngineeringKernel\Spec\SovereignSpecFloor;
use App\Services\Ai\EngineeringKernel\Spec\SpecDraft;
use App\Services\Ai\EngineeringKernel\Spec\SpecOracle;
use App\Services\Ai\EngineeringKernel\Spec\SpecShadowProvider;
use App\Services\Ai\EngineeringKernel\Spec\SpecVerdict;
use App\Services\Ai\EngineeringKernel\TrustLevel;
use PHPUnit\Framework\TestCase;

/**
 * Slice 6/8 — DOGFOOD (smoke) + the fail-closed default. The obra's own spec passes through the
 * adversary it builds. The discrimination is REAL: this obra's own Spec suite (the golden corpus +
 * the refuse/hold tests) would go RED against a no-op SovereignSpecFloor (one that always freezes
 * fails every known-bad case), so feeding the obra's criteria as discriminating is honest, not fabricated.
 * Wiper-safe: pure floor, ports faked, zero DB.
 */
final class Obra2DogfoodTest extends TestCase
{
    /** Obra #2's own acceptance criteria, as a spec draft (each backed by a real, discriminating test). */
    private const OBRA_CRITERIA = [
        ['id' => 'ac_hash_binding', 'description' => 'a fabricated frozen_hash not bound to the criteria is refused', 'verification' => 'test', 'verification_ref' => 'CriteriaHashBindingTest', 'case_class' => 'error'],
        ['id' => 'ac_oracle', 'description' => 'a green-on-noop spec is refused by oracle_adequacy', 'verification' => 'test', 'verification_ref' => 'SovereignSpecFloorTest', 'case_class' => 'error'],
        ['id' => 'ac_no_fail_open', 'description' => 'a down 2nd family holds the autonomous lane never fails open', 'verification' => 'test', 'verification_ref' => 'SpecShadowAdvisorTest', 'case_class' => 'boundary'],
        ['id' => 'ac_golden', 'description' => 'every known-bad draft is never frozen and every known-good freezes', 'verification' => 'test', 'verification_ref' => 'SpecAdversaryGoldenCorpusTest', 'case_class' => 'happy'],
    ];

    public function test_the_obra_spec_passes_through_the_adversary_it_builds(): void
    {
        // the obra's criteria genuinely discriminate (its own suite proves it)
        $oracle = new class implements SpecOracle
        {
            public function probe(SpecDraft $draft): OracleReport
            {
                return OracleReport::executional(['ac_hash_binding', 'ac_oracle', 'ac_no_fail_open', 'ac_golden']);
            }
        };
        $shadow = new class implements SpecShadowProvider
        {
            public function compare(SpecDraft $draft, IntentEnvelope $intent): DivergenceStatus
            {
                return DivergenceStatus::NotRequired;
            }
        };
        $floor = new SovereignSpecFloor($oracle, new AdvisorWitnessResolver($shadow));

        $verdict = $floor->contest(
            SpecDraft::fromArray([
                'intent_text' => 'construir o SpecAdversary em app/Services/Ai/EngineeringKernel/Spec',
                'acceptance_criteria' => self::OBRA_CRITERIA,
            ]),
            IntentEnvelope::fromArray([
                'raw_goal' => 'construir o SpecAdversary em app/Services/Ai/EngineeringKernel/Spec',
                'recognized_verbs' => ['criar'],
            ]),
            TrustLevel::Dev,
        );

        self::assertSame(SpecVerdict::FREEZE, $verdict->status, 'gaps: '.implode(',', $verdict->gaps));
        self::assertSame(64, strlen($verdict->provenance->frozenHash));
    }

    public function test_the_default_resolvable_adapter_is_fail_closed(): void
    {
        // app(AtlasSpecGateAdapter::class) resolves with the honest fail-closed default (unmeasured
        // oracle => HOLD), never freezing blind before a real executional oracle is wired.
        $adapter = new AtlasSpecGateAdapter;

        $verdict = $adapter->contestDevSpec([
            'spec' => ['intent_text' => 'construir o SpecAdversary em Spec/', 'acceptance_criteria' => self::OBRA_CRITERIA],
            'intent' => ['raw_goal' => 'construir o SpecAdversary em Spec/', 'recognized_verbs' => ['criar']],
        ], TrustLevel::Dev);

        self::assertNotSame(SpecVerdict::FREEZE, $verdict->status, 'fail-closed: no real oracle => must not freeze blind');
        self::assertContains('oracle_adequacy', $verdict->gaps);
    }
}
