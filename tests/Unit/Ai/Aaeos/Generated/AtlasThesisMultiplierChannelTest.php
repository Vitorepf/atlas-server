<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasThesisMultiplierChannelService;
use InvalidArgumentException;
use Tests\TestCase;

final class AtlasThesisMultiplierChannelTest extends TestCase
{
    private AtlasThesisMultiplierChannelService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasThesisMultiplierChannelService();
    }

    public function testFormulaMultipliesProviderOutputByTheEcosystemMultiplier(): void
    {
        // Doc "Formula": Output_Atlas = Output_Provider x Multiplicador_Ecossistema.
        // Two components at 2.0 and 1.5 => ecosystem multiplier 3.0 => 10 * 3 = 30.
        $result = $this->service->multiplyOutput(10.0, [
            'canonical_vitor_memory' => 2.0,
            'multi_provider_routing' => 1.5,
        ]);

        $this->assertSame(3.0, $result['ecosystem_multiplier']);
        $this->assertSame(30.0, $result['atlas_output']);
        $this->assertSame(['canonical_vitor_memory', 'multi_provider_routing'], $result['enabled_components']);
        $this->assertFalse($result['is_pure_passthrough']);
    }

    public function testWithNoComponentsAtlasIsAPurePassthroughMultiplierOfOne(): void
    {
        // Doc thesis: Atlas multiplies; with nothing enabled the multiplier
        // collapses to exactly 1.0 (pass-through, the failure state).
        $result = $this->service->multiplyOutput(42.0, []);

        $this->assertSame(1.0, $result['ecosystem_multiplier']);
        $this->assertSame(42.0, $result['atlas_output']);
        $this->assertTrue($result['is_pure_passthrough']);
        $this->assertSame([], $result['enabled_components']);
    }

    public function testCanalUnicoVirtuousLoopVersusDeathLoop(): void
    {
        // Doc "Canal Unico": through Atlas => evidence => virtuous loop.
        $through = $this->service->classifyChannel(true);
        $this->assertSame(AtlasThesisMultiplierChannelService::LOOP_VIRTUOUS, $through['loop']);
        $this->assertTrue($through['produces_evidence']);
        $this->assertTrue($through['strengthens_multiplier']);

        // Doc "Canal Unico": direct provider use => no evidence => death loop.
        $direct = $this->service->classifyChannel(false);
        $this->assertSame(AtlasThesisMultiplierChannelService::LOOP_DEATH, $direct['loop']);
        $this->assertFalse($direct['produces_evidence']);
        $this->assertFalse($direct['strengthens_multiplier']);
    }

    public function testFeatureFilterMapsTheTwoQuestionsToTheFourDocumentedDecisions(): void
    {
        // Doc "Feature Filter": multiplies and increases gravity => build.
        $build = $this->service->filterFeature(true, false);
        $this->assertSame(AtlasThesisMultiplierChannelService::DECISION_BUILD, $build['decision']);
        $this->assertTrue($build['should_build']);

        // Doc: competes with providers => reject or turn into adapter.
        $compete = $this->service->filterFeature(false, false);
        $this->assertSame(AtlasThesisMultiplierChannelService::DECISION_CONVERT_TO_ADAPTER, $compete['decision']);
        $this->assertFalse($compete['should_build']);

        // Doc: creates friction that makes direct provider use easier => fix
        // before shipping. This gate wins even when the feature multiplies.
        $friction = $this->service->filterFeature(true, true);
        $this->assertSame(AtlasThesisMultiplierChannelService::DECISION_FIX_BEFORE_SHIPPING, $friction['decision']);
        $this->assertFalse($friction['should_build']);
        $this->assertFalse($friction['keeps_gravity']);
    }

    public function testNeutralOverheadIsGatedOnMeasurementNotBuilt(): void
    {
        // Doc "Decision": neutral overhead => measure before expanding.
        $neutral = $this->service->filterNeutralOverhead();
        $this->assertSame(AtlasThesisMultiplierChannelService::DECISION_MEASURE_BEFORE_EXPANDING, $neutral['decision']);
        $this->assertFalse($neutral['should_build']);
        $this->assertTrue($neutral['requires_measurement_gate']);
    }

    public function testAllowedAndBlockedCategoriesAndUnlistedIsNotAutoApproved(): void
    {
        // Doc "Allowed": provider drivers and model routing are allowed.
        $allowed = $this->service->classifyCategory('provider_drivers_and_model_routing');
        $this->assertSame('allowed', $allowed['status']);
        $this->assertTrue($allowed['allowed']);
        $this->assertTrue($allowed['auto_approved']);

        // Doc "Blocked": building a model as a frontier-provider replacement is blocked.
        $blocked = $this->service->classifyCategory('model_as_frontier_provider_replacement');
        $this->assertSame('blocked', $blocked['status']);
        $this->assertTrue($blocked['blocked']);
        $this->assertFalse($blocked['auto_approved']);

        // Anything unlisted is not auto-approved; it must still pass the filter.
        $unlisted = $this->service->classifyCategory('some_unlisted_idea');
        $this->assertSame('unlisted', $unlisted['status']);
        $this->assertFalse($unlisted['allowed']);
        $this->assertFalse($unlisted['blocked']);
        $this->assertFalse($unlisted['auto_approved']);
    }

    public function testRejectsNegativeProviderOutput(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->multiplyOutput(-1.0, []);
    }
}
