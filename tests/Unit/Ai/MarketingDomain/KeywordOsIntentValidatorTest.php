<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Campaign\KeywordOsIntentValidator;
use PHPUnit\Framework\TestCase;

/**
 * Locks the honest, non-circular intelligence proof: text-classified high intent must convert MORE than
 * low intent on real outcome data. The intent label comes from TEXT, the CVR from SALES — if they agree,
 * the classifier genuinely separates buyers from the curious (assertividade provada, não proxy).
 */
class KeywordOsIntentValidatorTest extends TestCase
{
    public function test_high_intent_converts_more_than_low_intent(): void
    {
        $r = (new KeywordOsIntentValidator)->validate([
            ['term' => 'blue salt trick', 'clicks' => 1000, 'conversions' => 50],   // T4 buyer
            ['term' => 'gelatin weight loss trick', 'clicks' => 1000, 'conversions' => 40], // T4 buyer
            ['term' => 'what is gelatin', 'clicks' => 1000, 'conversions' => 2],     // T0 curious
            ['term' => 'what causes tinnitus', 'clicks' => 1000, 'conversions' => 1], // T0 curious
        ]);

        $this->assertGreaterThan($r['low_cvr'], $r['high_cvr'], 'comprador converte mais que curioso');
        $this->assertNotNull($r['lift']);
        $this->assertGreaterThan(1.0, $r['lift'], 'lift > 1 = a intenção classificada prediz a venda real');
    }

    public function test_cvr_is_null_below_min_clicks(): void
    {
        $r = (new KeywordOsIntentValidator)->validate([
            ['term' => 'blue salt trick', 'clicks' => 5, 'conversions' => 1],
        ], 30);
        $this->assertNull($r['by_tier']['T4']['cvr'], 'amostra pequena → CVR honesta = null');
    }

    public function test_deterministic(): void
    {
        $rows = [
            ['term' => 'blue salt trick', 'clicks' => 100, 'conversions' => 5],
            ['term' => 'what is gelatin', 'clicks' => 100, 'conversions' => 0],
        ];
        $a = (new KeywordOsIntentValidator)->validate($rows);
        $b = (new KeywordOsIntentValidator)->validate($rows);
        $this->assertSame($a, $b);
    }
}
