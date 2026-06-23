<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\VslIntelligenceExtractorService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Locks the contract that the dissection is stored in FIRST-CLASS fields: the lead, hook, angle,
 * trick, metrics and (separated) persuasion devices — and that the extractor has a dedicated 'lead'
 * pass with its own prompt/schema. Deterministic; no LLM call.
 */
class VslLeadFieldsTest extends TestCase
{
    public function test_asset_stores_lead_and_analysis_fields_as_typed_columns(): void
    {
        $asset = new AiMarketingVslAsset([
            'lead' => ['type' => 'proclamation', 'format' => 'fake-news interview', 'opening_text' => '...'],
            'hook' => 'a frase de abertura',
            'angle' => 'mecanismo único + promessa',
            'trick' => 'at-home retatrutide protocol',
            'metrics' => ['price' => '6x $49', 'guarantee_days' => 60],
            'persuasion_devices' => ['authority' => ['FDA', 'Melania'], 'conspiracy' => 'big pharma'],
        ]);

        // json-cast columns round-trip as arrays
        $this->assertSame('proclamation', $asset->lead['type']);
        $this->assertSame(60, $asset->metrics['guarantee_days']);
        $this->assertContains('FDA', $asset->persuasion_devices['authority']);
        // text columns are plain strings
        $this->assertSame('at-home retatrutide protocol', $asset->trick);
        $this->assertIsString($asset->hook);
    }

    public function test_extractor_has_a_dedicated_lead_pass_with_prompt_and_schema(): void
    {
        $ref = new ReflectionClass(VslIntelligenceExtractorService::class);
        $passes = $ref->getConstant('PASSES');
        $this->assertContains('lead', $passes);

        $systemFor = $ref->getMethod('systemFor');
        $schemaFor = $ref->getMethod('schemaFor');
        $systemFor->setAccessible(true);
        $schemaFor->setAccessible(true);
        $svc = $ref->newInstanceWithoutConstructor();

        $prompt = $systemFor->invoke($svc, 'lead');
        $schema = $schemaFor->invoke($svc, 'lead');

        $this->assertSame('atlas.vsl.lead.v1', $schema);
        // the prompt must carry the device≠trick rule that fixes the root error
        $this->assertStringContainsString('DISPOSITIVOS DE PERSUASÃO', $prompt);
        $this->assertStringContainsString('persuasion_devices', $prompt);
        $this->assertStringContainsString('trick', $prompt);
    }
}
