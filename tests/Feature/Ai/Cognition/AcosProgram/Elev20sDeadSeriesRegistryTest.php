<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Cognition\AcosProgram;

use Carbon\CarbonImmutable;
use Tests\TestCase;

final class Elev20sDeadSeriesRegistryTest extends TestCase
{
    public function test_dead_series_watchdog_alerts_when_last_append_exceeds_frozen_ttl(): void
    {
        $registryClass = 'App\\Services\\Ai\\AcosMax\\AcosMaxMeasureSeriesRegistry';
        $checkClass = 'App\\Services\\Ai\\Cognition\\Watchdog\\Checks\\AcosDeadSeriesWatchdogCheck';

        $this->assertTrue(class_exists($registryClass), 'ELEV-20s registry class must exist.');
        $this->assertTrue(class_exists($checkClass), 'ELEV-20s WDG plugin must exist.');

        $tmpDir = sys_get_temp_dir().'/atlas-elev20s-'.bin2hex(random_bytes(4));
        @mkdir($tmpDir, 0775, true);
        $seriesPath = $tmpDir.'/dead-series.jsonl';
        file_put_contents($seriesPath, json_encode([
            'recorded_at' => '2026-07-01T00:00:00+00:00',
            'value' => 1,
        ], JSON_THROW_ON_ERROR).PHP_EOL);

        try {
            $registry = new $registryClass([
                [
                    'slice' => 'TEST-01',
                    'series' => 'fixture.dead_series.v1',
                    'path' => $seriesPath,
                    'ttl_days' => 3,
                    'timestamp_field' => 'recorded_at',
                ],
            ]);
            $check = new $checkClass($registry, CarbonImmutable::parse('2026-07-12T00:00:00+00:00'));

            $result = $check->run()->toArray();

            $this->assertSame('alert', $result['status']);
            $this->assertSame('acos_dead_series_stale', $result['alert']['code'] ?? null);
            $this->assertSame('atlas.acos.dead_series_watchdog.v1', $result['evidence']['schema_version'] ?? null);
            $this->assertSame('fixture.dead_series.v1', $result['evidence']['series'][0]['series'] ?? null);
            $this->assertSame(11, $result['evidence']['series'][0]['age_days'] ?? null);
            $this->assertSame(3, $result['evidence']['series'][0]['ttl_days'] ?? null);
        } finally {
            @unlink($seriesPath);
            @rmdir($tmpDir);
        }
    }

    public function test_every_landed_acos_max_medidor_slice_is_registered(): void
    {
        $registryClass = 'App\\Services\\Ai\\AcosMax\\AcosMaxMeasureSeriesRegistry';

        $this->assertTrue(class_exists($registryClass), 'ELEV-20s registry class must exist.');

        $registry = app($registryClass);
        $registeredSlices = $registry->sliceIds();
        $registeredSeries = $registry->seriesIds();

        $landedMedidorSlices = $this->landedMedidorSlices();
        $this->assertNotSame([], $landedMedidorSlices, 'The architecture guard must detect landed [MEDIDOR] slices.');

        $missingSlices = array_values(array_diff($landedMedidorSlices, $registeredSlices));
        $this->assertSame([], $missingSlices, 'Every landed [MEDIDOR] slice in the Max scoreboard must register its live series.');

        foreach ([
            'aobg.latency_ledger.v1',
            'asi.metric.m.v1',
            'acos.verified_share.v1',
            'acos.asi05.ledger_cleanup.v1',
            'acos.operator_review_debt.v1',
            'atlas.capture.cognitive_immune_audit.v2',
            'atlas.immune.calibration.v1',
        ] as $expectedSeries) {
            $this->assertContains($expectedSeries, $registeredSeries);
        }
    }

    /** @return list<string> */
    private function landedMedidorSlices(): array
    {
        $plan = (string) file_get_contents(base_path('docs/engineering-knowledge-base/atlas-acos-max-frontier-plan-v1.md'));
        $scoreboard = (string) file_get_contents(base_path('docs/engineering-knowledge-base/atlas-acos-max-execution-scoreboard-v1.md'));

        preg_match_all('/^\*\*(?<slice>[A-Z]+-\d+s?)\b.*?\[MEDIDOR\]/m', $plan, $planMatches);
        $medidorSlices = array_fill_keys($planMatches['slice'] ?? [], true);

        preg_match_all('/^- \[x\] (?<slice>[A-Z]+-\d+s?)\b/m', $scoreboard, $scoreboardMatches);

        $landed = [];
        foreach ($scoreboardMatches['slice'] ?? [] as $slice) {
            if (isset($medidorSlices[$slice])) {
                $landed[] = $slice;
            }
        }

        sort($landed);

        return array_values(array_unique($landed));
    }
}
