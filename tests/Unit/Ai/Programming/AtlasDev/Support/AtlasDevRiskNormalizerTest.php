<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Support;

use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevRiskNormalizer;
use PHPUnit\Framework\TestCase;

final class AtlasDevRiskNormalizerTest extends TestCase
{
    public function test_risk_level_code_accepts_r0_to_r5_and_defaults_to_r2(): void
    {
        $this->assertSame('R4', AtlasDevRiskNormalizer::riskLevelCode(' r4 '));
        $this->assertSame('R2', AtlasDevRiskNormalizer::riskLevelCode('unknown'));
    }

    public function test_risk_word_for_level_matches_dev_repair_contract(): void
    {
        $this->assertSame('low', AtlasDevRiskNormalizer::riskWordForLevel('R1'));
        $this->assertSame('critical', AtlasDevRiskNormalizer::riskWordForLevel('R5'));
        $this->assertSame('medium', AtlasDevRiskNormalizer::riskWordForLevel('invalid'));
    }

    public function test_runtime_risk_band_preserves_existing_alias_contract(): void
    {
        $this->assertSame('critical', AtlasDevRiskNormalizer::runtimeRiskBand('p0'));
        $this->assertSame('high', AtlasDevRiskNormalizer::runtimeRiskBand('major'));
        $this->assertSame('medium', AtlasDevRiskNormalizer::runtimeRiskBand('unknown'));
    }

    public function test_plan_visible_risk_band_collapses_critical_to_high(): void
    {
        $this->assertSame('high', AtlasDevRiskNormalizer::planVisibleRiskBand('critical'));
        $this->assertSame('low', AtlasDevRiskNormalizer::planVisibleRiskBand(' LOW '));
        $this->assertSame('medium', AtlasDevRiskNormalizer::planVisibleRiskBand('unknown'));
    }
}
