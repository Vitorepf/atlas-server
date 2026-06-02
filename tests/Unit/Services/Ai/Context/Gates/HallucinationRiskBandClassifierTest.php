<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\Context\Gates;

use App\Services\Ai\Context\Gates\HallucinationRiskBandClassifier;
use PHPUnit\Framework\TestCase;

final class HallucinationRiskBandClassifierTest extends TestCase
{
    public function test_schema_version_is_stable(): void
    {
        $result = (new HallucinationRiskBandClassifier())->classify(0.01);

        $this->assertSame('atlas.context.hallucination_risk_band.v1', $result['schema_version']);
    }

    public function test_score_below_elevated_threshold_is_low_passed_and_does_not_abstain(): void
    {
        $result = (new HallucinationRiskBandClassifier())->classify(0.02);

        $this->assertSame('low', $result['band']);
        $this->assertSame('passed', $result['status']);
        $this->assertFalse($result['abstain']);
        $this->assertSame(0.02, $result['risk_score']);
    }

    public function test_score_within_elevated_window_is_elevated_and_review(): void
    {
        $result = (new HallucinationRiskBandClassifier())->classify(0.10);

        $this->assertSame('elevated', $result['band']);
        $this->assertSame('review', $result['status']);
        $this->assertFalse($result['abstain']);
    }

    public function test_score_at_or_above_high_threshold_is_high_abstained_and_abstains(): void
    {
        $result = (new HallucinationRiskBandClassifier())->classify(0.42);

        $this->assertSame('high', $result['band']);
        $this->assertSame('abstained', $result['status']);
        $this->assertTrue($result['abstain']);
    }

    public function test_exact_threshold_lands_in_the_higher_risk_band(): void
    {
        $classifier = new HallucinationRiskBandClassifier();

        $this->assertSame('elevated', $classifier->classify(0.05)['band']);
        $this->assertSame('high', $classifier->classify(0.15)['band']);
    }

    public function test_rising_score_never_moves_to_a_safer_band(): void
    {
        $classifier = new HallucinationRiskBandClassifier();
        $rank = ['low' => 0, 'elevated' => 1, 'high' => 2];

        $previousRank = -1;

        for ($score = 0.0; $score <= 1.0; $score += 0.01) {
            $band = $classifier->classify($score)['band'];
            $currentRank = $rank[$band];

            $this->assertGreaterThanOrEqual($previousRank, $currentRank);

            $previousRank = $currentRank;
        }

        $this->assertSame(2, $previousRank);
    }

    public function test_custom_thresholds_drive_the_band_boundaries(): void
    {
        $classifier = new HallucinationRiskBandClassifier();

        $this->assertSame('low', $classifier->classify(0.18, 0.20, 0.40)['band']);
        $this->assertSame('elevated', $classifier->classify(0.30, 0.20, 0.40)['band']);
        $this->assertSame('high', $classifier->classify(0.40, 0.20, 0.40)['band']);

        $echo = $classifier->classify(0.30, 0.20, 0.40);
        $this->assertSame(0.2, $echo['elevated_threshold']);
        $this->assertSame(0.4, $echo['high_threshold']);
    }

    public function test_risk_score_is_clamped_and_rounded_to_three_decimals(): void
    {
        $classifier = new HallucinationRiskBandClassifier();

        $this->assertSame(1.0, $classifier->classify(2.5)['risk_score']);
        $this->assertSame(0.0, $classifier->classify(-0.4)['risk_score']);
        $this->assertSame(0.123, $classifier->classify(0.123456)['risk_score']);
    }
}
