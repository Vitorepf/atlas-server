<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Campaign\KeywordOsRunner;
use PHPUnit\Framework\TestCase;

/**
 * Prova SYSTEM-LEVEL: o OS inteiro compõe como UM sistema coerente e DETERMINÍSTICO (repetibilidade bit-a-bit
 * — o "100% CERTO" do mandato). assemble() é puro (feeds injetados, sem DB), então isto roda offline e trava a
 * integração de TODOS os subsistemas wired nesta campanha: regimes → cross-negativos → blueprint deployável,
 * revenue_ranking → vital_few (Marshall) + value_lever (Hormozi) → budget_portfolio, sophistication (Schwartz).
 */
class KeywordOsRunnerIntegrationTest extends TestCase
{
    private function asset(): AiMarketingVslAsset
    {
        return new AiMarketingVslAsset([
            'mechanism_name' => 'gelatin trick',
            'trick' => 'pink gelatin protocol',
            'niche' => 'weight loss',
            'offer' => ['product_name' => 'X'],
        ]);
    }

    /** @return array<string,mixed> */
    private function feeds(): array
    {
        return [
            'discovered_terms' => ['gelatin trick', 'gelatin trick recipe', 'weight loss treatment', 'orivelle pen'],
            'mined_negatives' => ['free'],
            'volume_map' => ['gelatin trick' => 9000, 'orivelle pen' => 900, 'weight loss treatment' => 40000],
            'cvr_map' => ['gelatin trick' => 0.02, 'orivelle pen' => 0.30, 'weight loss treatment' => 0.013],
            'budget' => 5000.0,
        ];
    }

    public function test_the_whole_os_composes_coherently_in_one_run(): void
    {
        $run = (new KeywordOsRunner)->assemble($this->asset(), ['payout' => 120, 'refund' => 0.1], $this->feeds());

        // a espinha inteira presente e coerente num só dossiê
        $this->assertNotEmpty($run['run_hash'] ?? null, 'run_hash (proveniência)');
        $this->assertGreaterThan(0, $run['revenue_ranking']['total_expected_profit'] ?? 0);
        $this->assertNotEmpty($run['revenue_ranking']['ranked'] ?? [], 'ranking de receita');
        $this->assertArrayHasKey('value_lever', $run['revenue_ranking']['ranked'][0], 'Hormozi wired por keyword');
        $this->assertGreaterThan(0, $run['revenue_ranking']['vital_few']['vital_count'] ?? 0, 'Marshall vital few');
        $this->assertGreaterThan(0, $run['budget_portfolio']['total_profit'] ?? 0, 'portfólio sob budget');
        $this->assertSame('coined_mechanism_mandatory', $run['sophistication']['strategy'] ?? null, 'Schwartz: weight loss é jaded');
        $this->assertArrayHasKey('cross_negatives', $run['regimes'] ?? [], 'negativos cruzados (anti-canibalização)');
        $this->assertNotEmpty($run['campaign_blueprint']['campaigns'] ?? [], 'blueprint deployável');
    }

    public function test_bit_identical_reproducibility(): void
    {
        $runner = new KeywordOsRunner;
        $a = $runner->assemble($this->asset(), ['payout' => 120, 'refund' => 0.1], $this->feeds());
        $b = $runner->assemble($this->asset(), ['payout' => 120, 'refund' => 0.1], $this->feeds());
        $this->assertSame($a['run_hash'], $b['run_hash'], 'mesmo input → mesmo run_hash');
        $this->assertEquals($a, $b, 'saída bit-a-bit idêntica (determinismo total)');
    }
}
