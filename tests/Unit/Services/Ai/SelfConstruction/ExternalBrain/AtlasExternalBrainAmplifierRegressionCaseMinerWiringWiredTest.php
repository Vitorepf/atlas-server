<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasExternalBrainAmplifierRegressionCaseMinerWiringWiredTest extends TestCase
{
    private string $inputPath = '';

    protected function tearDown(): void
    {
        if ($this->inputPath !== '' && is_file($this->inputPath)) {
            @unlink($this->inputPath);
        }
        parent::tearDown();
    }

    public function test_regression_repair_command_includes_amplifier_regression_cases(): void
    {
        $payload = $this->runCommand([
            'amplifier_failures' => [
                ['failure_id' => 'f1', 'failure_type' => 'poison', 'trigger_shape' => 'shapeA', 'severity' => 'critical'],
            ],
        ]);

        self::assertArrayHasKey('amplifier_regression_cases', $payload);
        self::assertCount(1, $payload['amplifier_regression_cases']['promoted_cases']);
        self::assertSame('f1', $payload['amplifier_regression_cases']['promoted_cases'][0]['failure_id']);
    }

    public function test_empty_amplifier_failures_produces_clean_report(): void
    {
        $payload = $this->runCommand([]);

        self::assertSame([], $payload['amplifier_regression_cases']['promoted_cases']);
        self::assertSame([], $payload['amplifier_regression_cases']['rejected_candidates']);
    }

    private function runCommand(array $decoded): array
    {
        $this->inputPath = sys_get_temp_dir().'/amplifier-regression-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($this->inputPath, json_encode($decoded, JSON_THROW_ON_ERROR));

        Artisan::call('atlas:external-brain:regression-repair', ['--input' => $this->inputPath]);

        return json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
    }
}
