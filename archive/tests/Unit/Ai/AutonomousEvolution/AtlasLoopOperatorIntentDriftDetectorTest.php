<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Quaternity\LoopIntentDrift\AtlasLoopOperatorIntentDriftDetector;
use App\Services\Ai\AutonomousEvolution\Quaternity\LoopIntentDrift\AtlasLoopOperatorIntentDriftFact;
use Tests\TestCase;

final class AtlasLoopOperatorIntentDriftDetectorTest extends TestCase
{
    public function test_last_ambition_change_changes_axis_direction_magnitude_and_dominant_axis(): void
    {
        $detector = new AtlasLoopOperatorIntentDriftDetector;

        $upward = $detector->detect($this->intentWindow(0.95, ['scope_pressure' => 0.40]));
        $downward = $detector->detect($this->intentWindow(0.20, ['scope_pressure' => 0.92]));

        $this->assertSame(1, $upward->axisDirections['ambition_target']);
        $this->assertSame(-1, $downward->axisDirections['ambition_target']);
        $this->assertGreaterThan($downward->axisMagnitudes['ambition_target'], $upward->axisMagnitudes['ambition_target']);
        $this->assertSame('ambition_target', $upward->dominantAxis);
        $this->assertSame('scope_pressure', $downward->dominantAxis);
    }

    public function test_identical_inputs_produce_byte_identical_fact_serializations(): void
    {
        $detector = new AtlasLoopOperatorIntentDriftDetector;
        $window = $this->intentWindow(0.75, ['scope_pressure' => 0.70]);

        $first = json_encode($detector->detect($window), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $second = json_encode($detector->detect($window), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $this->assertSame($first, $second);
    }

    public function test_fact_never_exposes_quality_or_score_scalar_and_uses_configured_window_max(): void
    {
        config(['atlas.loop.quaternity.intent_drift.window_max' => 12]);

        $detector = new AtlasLoopOperatorIntentDriftDetector;
        $fact = $detector->detect($this->manyIntents(50));
        $payload = $fact->toArray();

        $this->assertSame(12, $payload['window_size']);
        $this->assertArrayNotHasKey('score', $payload);
        $this->assertArrayNotHasKey('quality', $payload);
    }

    public function test_detector_is_a_pure_function_without_clock_or_database_calls(): void
    {
        $detectorSource = file_get_contents(base_path('app/Services/Ai/AutonomousEvolution/Quaternity/LoopIntentDrift/AtlasLoopOperatorIntentDriftDetector.php'));
        $factSource = file_get_contents(base_path('app/Services/Ai/AutonomousEvolution/Quaternity/LoopIntentDrift/AtlasLoopOperatorIntentDriftFact.php'));

        $this->assertStringNotContainsString('DB::', (string) $detectorSource);
        $this->assertStringNotContainsString('now(', (string) $detectorSource);
        $this->assertStringNotContainsString('Carbon', (string) $detectorSource);
        $this->assertStringNotContainsString('random', strtolower((string) $detectorSource));
        $this->assertStringNotContainsString('score', strtolower((string) $factSource));
        $this->assertStringNotContainsString('quality', strtolower((string) $factSource));
    }

    /**
     * @param  array<string,float>  $lastAxes
     * @return list<array<string,mixed>>
     */
    private function intentWindow(float $lastAmbition, array $lastAxes): array
    {
        return [
            $this->intent('intent-1', '2026-06-24T10:00:00Z', 'loop', 0.50, ['scope_pressure' => 0.40, 'novelty' => 0.20]),
            $this->intent('intent-2', '2026-06-24T10:01:00Z', 'loop', 0.55, ['scope_pressure' => 0.42, 'novelty' => 0.20]),
            $this->intent('intent-3', '2026-06-24T10:02:00Z', 'loop', 0.60, ['scope_pressure' => 0.44, 'novelty' => 0.20]),
            $this->intent('intent-4', '2026-06-24T10:03:00Z', 'loop', $lastAmbition, $lastAxes + ['novelty' => 0.20]),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function manyIntents(int $count): array
    {
        $intents = [];
        for ($i = 1; $i <= $count; $i++) {
            $intents[] = $this->intent(
                intentId: 'intent-'.$i,
                capturedAt: sprintf('2026-06-24T10:%02d:00Z', $i % 60),
                scope: 'loop',
                ambitionTarget: $i / 100,
                axes: ['scope_pressure' => $i / 200],
            );
        }

        return $intents;
    }

    /**
     * @param  array<string,float>  $axes
     * @return array{intent_id:string,captured_at:string,scope:string,ambition_target:float,axes:array<string,float>}
     */
    private function intent(string $intentId, string $capturedAt, string $scope, float $ambitionTarget, array $axes): array
    {
        return [
            'intent_id' => $intentId,
            'captured_at' => $capturedAt,
            'scope' => $scope,
            'ambition_target' => $ambitionTarget,
            'axes' => $axes,
        ];
    }
}
