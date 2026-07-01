<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasExternalBrainCognitionCascadeControllerWiringWiredTest extends TestCase
{
    private function writeInput(array $input): string
    {
        $path = tempnam(sys_get_temp_dir(), 'originator_quality_');
        file_put_contents($path, json_encode($input, JSON_THROW_ON_ERROR));

        return $path;
    }

    public function test_missing_cognition_cascade_section_is_absent_from_payload(): void
    {
        $path = $this->writeInput(['opportunities' => []]);

        Artisan::call('atlas:external-brain:originator-quality', ['--input' => $path]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        unlink($path);

        self::assertArrayNotHasKey('cognition_cascade', $payload);
    }

    public function test_control_selects_scaffolded_path_for_high_risk_input(): void
    {
        $path = $this->writeInput([
            'opportunities' => [],
            'cognition_cascade' => [
                'control' => ['risk_score' => 0.9],
            ],
        ]);

        Artisan::call('atlas:external-brain:originator-quality', ['--input' => $path]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        unlink($path);

        self::assertArrayHasKey('cognition_cascade', $payload);
        self::assertSame(
            'atlas.external_brain.cognition_cascade_controller.v1',
            $payload['cognition_cascade']['control']['schema'],
        );
        self::assertContains('scaffolded_small_model', $payload['cognition_cascade']['control']['selected_path']);
        self::assertContains('critique_quorum', $payload['cognition_cascade']['control']['selected_path']);
    }

    public function test_cascade_plan_blocks_high_impact_task_missing_critique(): void
    {
        $path = $this->writeInput([
            'opportunities' => [],
            'cognition_cascade' => [
                'cascade_plan' => [
                    'is_high_impact' => true,
                    'state_read_done' => true,
                    'understanding_done' => true,
                    'candidates_proposed' => true,
                    'critique_done' => false,
                ],
            ],
        ]);

        Artisan::call('atlas:external-brain:originator-quality', ['--input' => $path]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        unlink($path);

        self::assertFalse($payload['cognition_cascade']['cascade_plan']['admitted']);
        self::assertSame('critique', $payload['cognition_cascade']['cascade_plan']['blocked_stage']);
    }
}
