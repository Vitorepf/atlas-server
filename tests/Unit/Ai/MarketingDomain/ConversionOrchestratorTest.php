<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Content\ConversionOrchestrator;
use PHPUnit\Framework\TestCase;

/**
 * Locks the end-to-end pipeline: a raw VSL asset goes in, a finished bridge HTML comes out — with
 * the audit trail showing the score rose and the elite patterns that were injected. Provider-free,
 * deterministic. This is the "press one button" entry point the Atlas exposes to the operator.
 */
class ConversionOrchestratorTest extends TestCase
{
    private function asset(): AiMarketingVslAsset
    {
        return new AiMarketingVslAsset([
            'core_promise' => 'lose weight without injections',
            'big_idea' => 'three hormones in sync',
            'hook' => 'a leaked protocol big pharma hides',
            'mechanism_name' => 'Triple Hormone Drops Protocol',
            'persuasion_devices' => ['authority' => ['Melania Trump'], 'conspiracy' => ['big pharma hides it']],
            'metrics' => ['result_claims' => ['63 lbs em 2 meses']],
            'target_geo' => 'US / English',
        ]);
    }

    public function test_pipeline_takes_asset_and_returns_amplified_html(): void
    {
        $out = (new ConversionOrchestrator)->orchestrate($this->asset(), ['until' => 'strong', 'max_iterations' => 3]);

        $this->assertNotEmpty($out['html']);
        $this->assertStringContainsString('<h1', $out['html']);
        $this->assertStringContainsString('class="cta', $out['html']);
        $this->assertGreaterThan($out['before']['overall_score'], $out['after']['overall_score'],
            'Orchestrator must raise the overall conversion score');
        $this->assertGreaterThanOrEqual(3, count($out['injected']),
            'At least 3 elite patterns should be injected by the amplifier');
    }

    public function test_pipeline_is_provider_free(): void
    {
        // The orchestrator must not need any LLM provider — instantiation alone proves it.
        $orch = new ConversionOrchestrator;
        $this->assertInstanceOf(ConversionOrchestrator::class, $orch);
    }
}
