<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Cognition\AcosProgram;

use App\Services\Ai\Cognition\AcosProgram\AcosMaxLote2MeasureService;
use App\Services\Ai\Cognition\AcosProgram\AcosMaxMeasureSeriesRegistry;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class Lote2FreezeReadersTest extends TestCase
{
    public function test_lote2_readers_report_honest_empty_windows(): void
    {
        $commands = [
            'atlas:acos:delta-attribution' => ['slice' => 'MAXL-06', 'status' => 'pending_window'],
            'atlas:brain:predicted-impact' => ['slice' => 'MULTN17-04', 'status' => 'insufficient_signal'],
            'atlas:flywheel:loops' => ['slice' => 'MULTX-01', 'status' => 'insufficient_signal'],
            'atlas:flywheel:learning-latency' => ['slice' => 'MULTX-06', 'status' => 'insufficient_signal'],
            'atlas:ai:lesson-half-life' => ['slice' => 'MULTJ-01', 'status' => 'insufficient_signal'],
            'atlas:ai:lesson-dedup-calibration' => ['slice' => 'MULTJ-02', 'status' => 'pending_window'],
            'atlas:ai:counterfactual-lift' => ['slice' => 'MULTJ-03', 'status' => 'insufficient_signal'],
            'atlas:ai:procedural-skill-promoter' => ['slice' => 'MULTJ-04', 'status' => 'pending_window'],
            'atlas:mission:e2e' => ['slice' => 'TETO-02', 'status' => 'insufficient_signal'],
        ];

        foreach ($commands as $command => $expected) {
            $exit = Artisan::call($command, ['--json' => true]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(0, $exit, $command);
            $this->assertSame($expected['slice'], $payload['slice'], $command);
            $this->assertSame($expected['status'], $payload['status'], $command);
            $this->assertNotSame(
                data_get($payload, 'freeze.author_engine_id'),
                data_get($payload, 'freeze.judge_engine_id'),
                $command,
            );
        }
    }

    public function test_new_lote2_medidor_series_are_registered_for_elev20s(): void
    {
        $registry = new AcosMaxMeasureSeriesRegistry;
        $entries = collect($registry->entries())->keyBy('slice');

        foreach ([
            'MAXL-06' => AcosMaxLote2MeasureService::MAXL06_MEASURE_ID,
            'MULTN17-04' => AcosMaxLote2MeasureService::MULTN1704_MEASURE_ID,
            'MULTX-01' => AcosMaxLote2MeasureService::MULTX01_MEASURE_ID,
            'MULTX-06' => AcosMaxLote2MeasureService::MULTX06_MEASURE_ID,
            'MULTJ-01' => AcosMaxLote2MeasureService::MULTJ01_MEASURE_ID,
            'MULTJ-03' => AcosMaxLote2MeasureService::MULTJ03_MEASURE_ID,
            'MULTJ-04' => AcosMaxLote2MeasureService::MULTJ04_MEASURE_ID,
            'TETO-02' => AcosMaxLote2MeasureService::TETO02_MEASURE_ID,
        ] as $slice => $series) {
            $this->assertSame($series, data_get($entries->get($slice), 'series'), $slice);
            $this->assertSame('command', data_get($entries->get($slice), 'source_type'), $slice);
        }
    }
}
