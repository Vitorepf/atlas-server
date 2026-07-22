<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Cognition\AcosProgram;

use App\Console\Commands\AtlasAcosDeltaSeriesV2Command;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * MAXL-04 — Série v2 por-área do scorecard ACOS.
 *
 * Prova:
 * - a linha v2 carrega `by_area` com ≥14 áreas + agregado no MESMO ladder da v1;
 * - o produtor v2 nunca escreve na v1 (byte-identity: v1 preservada mesmo com v2 rodando);
 * - append-only por data: re-rodar o mesmo dia substitui a linha, nunca duplica;
 * - --date retroativo é recusado (herdada da v1 / EVI-04), salvo `--allow-past-date=1`;
 * - anti-backfill efetivo: sem opt-in, dia perdido fica perdido.
 */
final class Maxl04PerAreaDeltaSeriesV2Test extends TestCase
{
    private string $seriesV1;

    private string $seriesV2;

    protected function setUp(): void
    {
        parent::setUp();
        $tag = bin2hex(random_bytes(4));
        $this->seriesV1 = sys_get_temp_dir().'/acos-delta-series-v1-'.$tag.'.jsonl';
        $this->seriesV2 = sys_get_temp_dir().'/acos-delta-series-v2-'.$tag.'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->seriesV1);
        @unlink($this->seriesV2);
        parent::tearDown();
    }

    public function test_v2_line_carries_by_area_breakdown_and_aggregate(): void
    {
        $today = date('Y-m-d');
        $exit = $this->snap($today);

        $this->assertSame(0, $exit);
        $this->assertFileExists($this->seriesV2);

        $rows = $this->readSeries($this->seriesV2);
        $this->assertCount(1, $rows);

        $row = $rows[0];
        $this->assertSame(AtlasAcosDeltaSeriesV2Command::SCHEMA_VERSION, $row['schema_version']);
        $this->assertSame($today, $row['date']);
        $this->assertSame('resolved-evidence', $row['provenance']);
        $this->assertStringStartsWith($today, (string) $row['recorded_at'], 'recorded_at.date == snapshot.date');

        $this->assertArrayHasKey('aggregate', $row);
        foreach (['overall', 'code', 'doc', 'pipeline'] as $k) {
            $this->assertArrayHasKey($k, $row['aggregate']);
            $this->assertIsNumeric($row['aggregate'][$k]);
        }

        $this->assertArrayHasKey('by_area', $row);
        $this->assertGreaterThanOrEqual(
            14,
            count($row['by_area']),
            'MAXL-04 exige ≥14 áreas trackeadas no breakdown (Cognitive Immune / Memory Core / … / Evidence).',
        );

        foreach ($row['by_area'] as $area => $scores) {
            $this->assertIsString($area);
            foreach (['overall', 'code', 'doc', 'pipeline', 'subsystem_count'] as $k) {
                $this->assertArrayHasKey($k, $scores, 'área '.$area.' precisa expor '.$k);
            }
            $this->assertGreaterThan(0, $scores['subsystem_count']);
        }
    }

    public function test_producer_never_writes_to_v1_series_file(): void
    {
        // Seed a v1 file with a known payload; the v2 producer must not touch it.
        $v1Payload = ['date' => '2026-06-12', 'metrics' => ['scorecard_overall' => 5.0]];
        file_put_contents($this->seriesV1, json_encode($v1Payload)."\n");
        $before = hash_file('sha256', $this->seriesV1);

        $exit = $this->snap(date('Y-m-d'));
        $this->assertSame(0, $exit);

        $after = hash_file('sha256', $this->seriesV1);
        $this->assertSame($before, $after, 'v1 file must remain byte-identical after v2 producer runs');
    }

    public function test_running_twice_for_the_same_date_does_not_duplicate(): void
    {
        $today = date('Y-m-d');
        $this->snap($today);
        $this->snap($today);

        $rows = $this->readSeries($this->seriesV2);
        $this->assertCount(1, $rows, 'idempotente por data — re-medir o mesmo dia substitui, não duplica');
    }

    public function test_past_date_is_refused_without_opt_in(): void
    {
        // Yesterday, no --allow-past-date=1: must NOT append.
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        $buffer = new BufferedOutput;
        $exit = Artisan::call(
            'atlas:acos:delta-series-v2',
            [
                '--series' => $this->seriesV2,
                '--date' => $yesterday,
                '--json' => true,
            ],
            $buffer,
        );

        $this->assertSame(0, $exit, 'fail-safe: retorna 0 mas não appenda');
        $out = json_decode(trim($buffer->fetch()), true);
        $this->assertIsArray($out);
        $this->assertSame('blocked', $out['status'] ?? null);
        $this->assertSame('past_date_refused', $out['reason'] ?? null);
        $this->assertFalse(is_file($this->seriesV2), 'sem opt-in, nada é gravado para dia passado');
    }

    public function test_past_date_with_opt_in_appends_line(): void
    {
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        $buffer = new BufferedOutput;
        $exit = Artisan::call(
            'atlas:acos:delta-series-v2',
            [
                '--series' => $this->seriesV2,
                '--date' => $yesterday,
                '--allow-past-date' => '1',
                '--json' => true,
            ],
            $buffer,
        );
        $this->assertSame(0, $exit);

        $rows = $this->readSeries($this->seriesV2);
        $this->assertCount(1, $rows);
        $this->assertSame($yesterday, $rows[0]['date']);
    }

    private function snap(string $date): int
    {
        return Artisan::call('atlas:acos:delta-series-v2', [
            '--series' => $this->seriesV2,
            '--date' => $date,
            '--json' => true,
        ]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readSeries(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $rows = [];
        foreach (preg_split('/\r?\n/', (string) file_get_contents($path)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }
}
