<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Content\ProofForge;
use PHPUnit\Framework\TestCase;

/**
 * Locks the proof forge: real names + real BELIEVABLE numbers (30–90 band, not the fake-sounding 200)
 * + an elite human testimonial voice + real study stats — and, for an English market on a
 * Portuguese-dissected VSL, no PT fragments leaking into the copy. Deterministic.
 */
class ProofForgeTest extends TestCase
{
    private function asset(): AiMarketingVslAsset
    {
        return new AiMarketingVslAsset([
            'target_geo' => 'US / English',
            'transcript' => 'no needle, unlike Ozempic and Mounjaro',
            'metrics' => [
                'result_claims' => ['63 lbs em 2 meses', '90 lbs em 3 meses', '200 lbs em 6 meses', '56 lbs em quase 2 meses'],
                'study_claims' => ['11.850 voluntários', '96% perderam mais de 35 lbs em 8 semanas', 'média de 44 lbs perdidas'],
            ],
            'avatar' => ['desejos' => ['voltar a usar roupas justas']],
            'persuasion_devices' => [
                'social_proof' => ['Melissa McCarthy', 'Amy de Naperville, Illinois', 'Jennifer (Jenny)', 'depoente anônima'],
            ],
        ]);
    }

    public function test_uses_real_names_and_believable_numbers_in_a_human_voice(): void
    {
        $p = (new ProofForge)->forge($this->asset());
        $names = array_column($p['testimonials'], 'name');
        $results = implode(' ', array_column($p['testimonials'], 'result'));

        $this->assertGreaterThanOrEqual(3, count($p['testimonials']));
        $this->assertContains('Melissa McCarthy', $names);
        $this->assertContains('Amy', $names);                 // city stripped, no "de"
        $this->assertNotContains('depoente anônima', $names); // anonymous skipped
        $this->assertStringContainsString('-90 lbs', $results);
        $this->assertStringNotContainsString('-200', $results); // fake-sounding number rejected
    }

    public function test_no_portuguese_leaks_into_english_copy(): void
    {
        $p = (new ProofForge)->forge($this->asset());
        $blob = json_encode($p, JSON_UNESCAPED_UNICODE);

        $this->assertDoesNotMatchRegularExpression('/\b(meses|semanas|voluntários|perderam|roupas|vergonha)\b/u', $blob);
        $this->assertStringContainsString('11,850 volunteers', $blob);     // thousands separator fixed
        $this->assertStringContainsString('in 8 weeks', $blob);            // "em" → "in"
    }

    public function test_portuguese_market_keeps_portuguese(): void
    {
        $asset = $this->asset();
        $asset->target_geo = 'BR';
        $asset->language = 'pt';
        $p = (new ProofForge)->forge($asset);

        $this->assertStringContainsString('meses', json_encode($p, JSON_UNESCAPED_UNICODE));
    }
}
