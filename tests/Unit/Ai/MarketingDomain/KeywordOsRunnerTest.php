<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Campaign\KeywordOsRunner;
use PHPUnit\Framework\TestCase;

/**
 * Locks the end-to-end capstone: assemble() runs the pipeline with all THREE real-money feeds at once —
 * per-niche flywheel calibration (precision), real discovered terms (discovery), and real-waste negatives
 * (exclusion) — so a single call yields a dossier with the real sale already baked into every side.
 */
class KeywordOsRunnerTest extends TestCase
{
    private function asset(): AiMarketingVslAsset
    {
        return new AiMarketingVslAsset([
            'mechanism_name' => 'Triple Hormone Drops Protocol',
            'trick' => 'at-home retatrutide protocol',
            'niche' => 'weight loss',
            'offer' => ['product_name' => 'Lipo Bliss'],
        ]);
    }

    public function test_assemble_wires_all_three_real_feeds(): void
    {
        $r = (new KeywordOsRunner)->assemble($this->asset(), ['payout' => 120, 'cvr' => 0.012], [
            'calibration' => ['weights' => ['bariatric gelatin trick' => 0.3]], // flywheel: precisão da venda real
            'discovered_terms' => ['bariatric gelatin trick'],                   // harvest: descoberta real
            'mined_negatives' => ['recipe weight'],                              // miner: exclusão do waste real
        ]);

        // descoberta real entrou e foi pontuada
        $row = collect($r['scored'])->firstWhere('keyword', 'bariatric gelatin trick');
        $this->assertNotNull($row, 'o termo real descoberto é pontuado');
        // precisão da venda real aplicada nele
        $this->assertSame(0.3, $row['outcome_weight'], 'o peso do flywheel entra no score do termo descoberto');
        // exclusão do waste real entrou nas negativas
        $this->assertContains('recipe weight', $r['negatives']['flat'], 'o negativo do waste real entra no dossiê');
    }

    public function test_assemble_is_deterministic(): void
    {
        $runner = new KeywordOsRunner;
        $feeds = ['discovered_terms' => ['bariatric gelatin trick'], 'mined_negatives' => ['recipe weight']];
        $a = $runner->assemble($this->asset(), ['payout' => 120, 'cvr' => 0.012], $feeds);
        $b = $runner->assemble($this->asset(), ['payout' => 120, 'cvr' => 0.012], $feeds);
        $this->assertSame($a['run_hash'], $b['run_hash']);
        $this->assertSame(array_column($a['scored'], 'keyword'), array_column($b['scored'], 'keyword'));
    }
}
