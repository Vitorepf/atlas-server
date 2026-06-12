<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * L3-14: a SÉRIE TEMPORAL + o relatório final N×M da campanha Fable. Congelado:
 * - um snapshot acrescenta UMA linha à série (append-only);
 * - re-rodar a MESMA data NÃO duplica (idempotente por data);
 * - --report emite tendência primeiro-vs-último com delta;
 * - baseline ausente → status honesto "blocked"/"no_baseline", exit 0 (fail-safe).
 *
 * Fontes vivas (scorecard, tabela do Loop, runtime semântico) são resolvidas, nunca
 * declaradas — o teste prova a mecânica da série, não chumba números de métrica.
 */
final class AtlasFableDeltaSeriesCommandTest extends TestCase
{
    private string $baseline;

    private string $series;

    protected function setUp(): void
    {
        parent::setUp();
        $tag = bin2hex(random_bytes(4));
        $this->baseline = sys_get_temp_dir().'/marco-zero-series-'.$tag.'.json';
        $this->series = sys_get_temp_dir().'/fable-delta-series-'.$tag.'.jsonl';

        file_put_contents($this->baseline, json_encode([
            'recorded_at' => '2026-06-11',
            'baseline' => [
                'maturity_scorecard' => ['acos_overall' => 5.0],
                'learning_capture_quality_7d' => ['gate_mode' => 'observe'],
            ],
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->baseline);
        @unlink($this->series);
        parent::tearDown();
    }

    public function test_a_snapshot_appends_a_row_to_the_series(): void
    {
        $exit = $this->snap('2026-06-12');

        $this->assertSame(0, $exit);
        $this->assertFileExists($this->series);
        $rows = $this->seriesRows();
        $this->assertCount(1, $rows);
        $this->assertSame('2026-06-12', $rows[0]['date']);
        $this->assertArrayHasKey('metrics', $rows[0]);
        $this->assertArrayHasKey('scorecard_overall', $rows[0]['metrics']);
        $this->assertArrayHasKey('sources', $rows[0], 'cada snapshot declara a fonte viva');
    }

    public function test_running_twice_for_the_same_date_does_not_duplicate(): void
    {
        $this->snap('2026-06-12');
        $this->snap('2026-06-12');

        $rows = $this->seriesRows();
        $this->assertCount(1, $rows, 'idempotente por data — re-medir o mesmo dia substitui, não duplica');

        // Uma data diferente acrescenta nova linha.
        $this->snap('2026-06-13');
        $rows = $this->seriesRows();
        $this->assertCount(2, $rows);
        $this->assertSame(['2026-06-12', '2026-06-13'], array_column($rows, 'date'), 'série ordenada por data');
    }

    public function test_report_emits_a_trend_with_first_vs_latest_deltas(): void
    {
        $this->snap('2026-06-12');
        $this->snap('2026-06-13');

        $out = new BufferedOutput;
        $exit = Artisan::call('atlas:fable:delta-series', [
            '--baseline' => $this->baseline,
            '--series' => $this->series,
            '--date' => '2026-06-13',
            '--report' => true,
            '--json' => true,
        ], $out);

        $this->assertSame(0, $exit);
        $report = json_decode($out->fetch(), true);
        $this->assertSame('atlas.fable.delta_series.report.v1', $report['schema_version']);
        $this->assertSame('ok', $report['status']);

        $trend = $report['trend'];
        $this->assertTrue($trend['available']);
        $this->assertSame('2026-06-12', $trend['first_date']);
        $this->assertSame('2026-06-13', $trend['latest_date']);

        $m = $trend['metrics']['scorecard_overall'];
        $this->assertArrayHasKey('first', $m);
        $this->assertArrayHasKey('latest', $m);
        $this->assertArrayHasKey('delta', $m);
        $this->assertArrayHasKey('direction', $m);
        $this->assertEqualsWithDelta($m['latest'] - $m['first'], $m['delta'], 0.001, 'delta = latest - first, resolvido da série');
    }

    public function test_missing_baseline_is_honest_blocked_exit_zero(): void
    {
        $out = new BufferedOutput;
        $exit = Artisan::call('atlas:fable:delta-series', [
            '--baseline' => '/nonexistent/marco-zero.json',
            '--series' => $this->series,
            '--date' => '2026-06-12',
            '--json' => true,
        ], $out);

        $this->assertSame(0, $exit, 'fail-safe: baseline ausente nunca crasha');
        $report = json_decode($out->fetch(), true);
        $this->assertSame('blocked', $report['status']);
        $this->assertSame('no_baseline', $report['reason']);
        $this->assertFileDoesNotExist($this->series, 'sem baseline, nada é gravado na série');
    }

    private function snap(string $date): int
    {
        return Artisan::call('atlas:fable:delta-series', [
            '--baseline' => $this->baseline,
            '--series' => $this->series,
            '--date' => $date,
            '--json' => true,
        ]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function seriesRows(): array
    {
        $rows = [];
        foreach (preg_split('/\r?\n/', (string) file_get_contents($this->series)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $rows[] = json_decode($line, true);
        }

        return $rows;
    }
}
