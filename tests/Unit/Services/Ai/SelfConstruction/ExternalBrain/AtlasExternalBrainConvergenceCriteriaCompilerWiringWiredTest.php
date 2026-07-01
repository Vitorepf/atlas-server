<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasExternalBrainConvergenceCriteriaCompilerWiringWiredTest extends TestCase
{
    private function writeInput(array $input): string
    {
        $path = tempnam(sys_get_temp_dir(), 'autonomy_governor_');
        file_put_contents($path, json_encode($input, JSON_THROW_ON_ERROR));

        return $path;
    }

    public function test_missing_convergence_section_defaults_to_keep_building(): void
    {
        $path = $this->writeInput([]);

        Artisan::call('atlas:external-brain:autonomy-governor', ['--input' => $path]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        unlink($path);

        self::assertArrayHasKey('convergence', $payload);
        self::assertSame('atlas.external_brain.convergence_criteria_compiler.v1', $payload['convergence']['schema']);
        self::assertSame('keep_building', $payload['convergence']['verdict']);
    }

    public function test_all_pillars_passing_yields_convergence_ready(): void
    {
        $path = $this->writeInput([
            'convergence' => [
                'final_95_readiness' => 0.97,
                'brain_health_score' => 0.80,
                'queue_health_score' => 0.85,
                'autonomy_independence_score' => 0.90,
                'learning_loop_closedness' => 0.80,
                'worker_proof_count' => 10,
                'compounding_evidence_score' => 0.65,
                'simplification_score' => 0.75,
                'integration_organs_count' => 5,
            ],
        ]);

        Artisan::call('atlas:external-brain:autonomy-governor', ['--input' => $path]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        unlink($path);

        self::assertSame('convergence_ready', $payload['convergence']['verdict']);
        self::assertSame([], $payload['convergence']['failing_pillars']);
    }
}
