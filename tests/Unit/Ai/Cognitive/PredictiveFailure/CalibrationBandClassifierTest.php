<?php

namespace Tests\Unit\Ai\Cognitive\PredictiveFailure;

use App\Services\Ai\Cognitive\PredictiveFailure\CalibrationBandClassifier;
use Tests\TestCase;

class CalibrationBandClassifierTest extends TestCase
{
    public function test_classifier_marks_sweet_band_only_between_seventy_and_eighty_five_percent(): void
    {
        $classifier = app(CalibrationBandClassifier::class);

        $this->assertSame('low', $classifier->classify(0.69)['band']);
        $this->assertSame('sweet', $classifier->classify(0.70)['band']);
        $this->assertSame('sweet', $classifier->classify(0.85)['band']);
        $this->assertSame('high', $classifier->classify(0.86)['band']);
    }
}
