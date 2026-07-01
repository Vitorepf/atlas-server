<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasExternalBrainCostQualityParetoFrontWiringWiredTest extends TestCase
{
    private function writeInput(array $input): string
    {
        $path = tempnam(sys_get_temp_dir(), 'originator_quality_');
        file_put_contents($path, json_encode($input, JSON_THROW_ON_ERROR));

        return $path;
    }

    public function test_missing_cost_quality_pareto_section_is_absent_from_payload(): void
    {
        $path = $this->writeInput(['opportunities' => []]);

        Artisan::call('atlas:external-brain:originator-quality', ['--input' => $path]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        unlink($path);

        self::assertArrayNotHasKey('cost_quality_pareto', $payload);
    }

    public function test_cheaper_equal_quality_option_dominates_and_is_recommended(): void
    {
        $path = $this->writeInput([
            'opportunities' => [],
            'cost_quality_pareto' => [
                'options' => [
                    ['option_id' => 'cheap', 'quality' => 0.8, 'cost' => 1.0],
                    ['option_id' => 'expensive', 'quality' => 0.8, 'cost' => 5.0],
                ],
            ],
        ]);

        Artisan::call('atlas:external-brain:originator-quality', ['--input' => $path]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        unlink($path);

        self::assertArrayHasKey('cost_quality_pareto', $payload);
        self::assertSame('atlas.external_brain.cost_quality_pareto_front.v1', $payload['cost_quality_pareto']['schema']);
        self::assertSame('cheap', $payload['cost_quality_pareto']['recommended_option']);
        self::assertNotEmpty($payload['cost_quality_pareto']['dominated_options']);
    }
}
