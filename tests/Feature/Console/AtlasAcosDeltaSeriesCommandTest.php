<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopProposal;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * L3-14 / EVI-09: série temporal append-only do ACOS + relatório N×M.
 * - um snapshot acrescenta UMA linha à série (append-only);
 * - re-rodar a MESMA data NÃO duplica (idempotente por data);
 * - --report emite tendência primeiro-vs-último com delta;
 * - baseline ausente → status honesto "blocked"/"no_baseline", exit 0 (fail-safe).
 *
 * Fontes vivas (scorecard, tabela do Loop, runtime semântico) são resolvidas, nunca
 * declaradas — o teste prova a mecânica da série, não chumba números de métrica.
 */
final class AtlasAcosDeltaSeriesCommandTest extends TestCase
{
    private string $baseline;

    private string $series;

    protected function setUp(): void
    {
        parent::setUp();
        $tag = bin2hex(random_bytes(4));
        $this->baseline = sys_get_temp_dir().'/marco-zero-series-'.$tag.'.json';
        $this->series = sys_get_temp_dir().'/acos-delta-series-'.$tag.'.jsonl';

        file_put_contents($this->baseline, json_encode([
            'recorded_at' => '2026-06-11',
            'baseline' => [
                'maturity_scorecard' => ['acos_overall' => 5.0],
                'learning_capture_quality_7d' => ['gate_mode' => 'observe'],
            ],
        ]));
        $this->ensureLoopTables();
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
        $this->assertArrayHasKey('loop_impact_receipt_coverage_pct', $rows[0]['metrics']);
        $this->assertArrayHasKey('impact_receipts', $rows[0]);
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
        $exit = Artisan::call('atlas:acos:delta-series', [
            '--baseline' => $this->baseline,
            '--series' => $this->series,
            '--date' => '2026-06-13',
            '--allow-past-date' => '1',
            '--report' => true,
            '--json' => true,
        ], $out);

        $this->assertSame(0, $exit);
        $report = json_decode($out->fetch(), true);
        $this->assertSame('atlas.acos.delta_series.report.v1', $report['schema_version']);
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
        $this->assertArrayHasKey('loop_impact_receipt_coverage_pct', $trend['metrics']);
    }

    public function test_missing_baseline_is_honest_blocked_exit_zero(): void
    {
        $out = new BufferedOutput;
        $exit = Artisan::call('atlas:acos:delta-series', [
            '--baseline' => '/nonexistent/marco-zero.json',
            '--series' => $this->series,
            '--date' => '2026-06-12',
            '--allow-past-date' => '1',
            '--json' => true,
        ], $out);

        $this->assertSame(0, $exit, 'fail-safe: baseline ausente nunca crasha');
        $report = json_decode($out->fetch(), true);
        $this->assertSame('blocked', $report['status']);
        $this->assertSame('no_baseline', $report['reason']);
        $this->assertFileDoesNotExist($this->series, 'sem baseline, nada é gravado na série');
    }

    public function test_legacy_fable_alias_runs_same_command(): void
    {
        $this->seedMergedImpactReceipt();
        $out = new BufferedOutput;
        $exit = Artisan::call('atlas:fable:delta-series', [
            '--baseline' => $this->baseline,
            '--series' => $this->series,
            '--date' => '2026-06-12',
            '--allow-past-date' => '1',
            '--json' => true,
        ], $out);

        $this->assertSame(0, $exit);
        $payload = json_decode($out->fetch(), true);
        $this->assertSame('atlas.acos.delta_series.v1', $payload['schema_version']);
        $this->assertSame('ok', $payload['status']);
    }

    private function snap(string $date): int
    {
        $this->seedMergedImpactReceipt();

        return Artisan::call('atlas:acos:delta-series', [
            '--baseline' => $this->baseline,
            '--series' => $this->series,
            '--date' => $date,
            '--allow-past-date' => '1',
            '--json' => true,
        ]);
    }

    public function test_past_date_without_allow_flag_is_refused(): void
    {
        $out = new BufferedOutput;
        $exit = Artisan::call('atlas:acos:delta-series', [
            '--baseline' => $this->baseline,
            '--series' => $this->series,
            '--date' => '2026-06-27',
            '--json' => true,
        ], $out);
        $this->assertSame(0, $exit);
        $payload = json_decode($out->fetch(), true);
        $this->assertSame('blocked', $payload['status'] ?? null);
        $this->assertSame('past_date_refused', $payload['reason'] ?? null);
        $this->assertFileDoesNotExist($this->series);
    }

    public function test_catchup_when_guard_skips_when_today_row_present(): void
    {
        $today = date('Y-m-d');
        file_put_contents($this->series, json_encode([
            'date' => $today,
            'metrics' => ['scorecard_overall' => 9.9],
        ], JSON_UNESCAPED_SLASHES)."\n");
        $before = file_get_contents($this->series);
        $this->assertTrue(\App\Support\AtlasJsonlDatePresence::hasDate($this->series, $today));
        // Simulate the schedule when() closure: when today is present, catch-up must NOT run.
        $shouldRun = ! \App\Support\AtlasJsonlDatePresence::hasDate($this->series, $today);
        $this->assertFalse($shouldRun, 'closure must short-circuit when today row exists');
        $this->assertSame($before, file_get_contents($this->series), 'zero write — arquivo byte-idêntico');
    }

    public function test_catchup_when_guard_allows_when_today_row_absent(): void
    {
        $today = date('Y-m-d');
        file_put_contents($this->series, json_encode([
            'date' => '2026-07-01',
            'metrics' => ['scorecard_overall' => 9.0],
        ], JSON_UNESCAPED_SLASHES)."\n");
        $this->assertFalse(\App\Support\AtlasJsonlDatePresence::hasDate($this->series, $today));
        $shouldRun = ! \App\Support\AtlasJsonlDatePresence::hasDate($this->series, $today);
        $this->assertTrue($shouldRun);
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

    private function ensureLoopTables(): void
    {
        if (! Schema::hasTable('atlas_loop_campaigns')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }

        if (! Schema::hasColumn('atlas_loop_proposals', 'quality')) {
            (require base_path('database/migrations/2026_06_11_000100_add_quality_columns_to_atlas_loop_tables.php'))->up();
        }
    }

    private function seedMergedImpactReceipt(): void
    {
        $campaign = AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'fable series impact receipt proof',
            'config' => [],
            'max_seconds' => 60,
        ]);

        AtlasLoopProposal::$governedMergeInProgress = true;
        try {
            AtlasLoopProposal::create([
                'campaign_id' => $campaign->id,
                'schema_version' => 'atlas.loop.proposal.v1',
                'status' => AtlasLoopProposal::STATUS_CERTIFIED,
                'objective' => 'fix edge case for Fable series',
                'target_path' => 'app/Services/FableSeriesProof.php',
                'diff_text' => "diff --git a/app/Services/FableSeriesProof.php b/app/Services/FableSeriesProof.php\n+return true;\n",
                'proposal_hash' => 'fable-series-impact-'.bin2hex(random_bytes(4)),
                'quality' => [
                    '_impact_receipt' => [
                        'schema_version' => 'atlas.loop.impact_receipt.v1',
                        'category' => 'edge_case',
                        'target_kind' => 'real',
                        'real_vs_generated' => 'real',
                        'target_path' => 'app/Services/FableSeriesProof.php',
                        'size' => [
                            'files_changed' => 1,
                            'php_files_changed' => 1,
                            'added_lines' => 1,
                            'deleted_lines' => 0,
                            'touched_lines' => 1,
                            'bucket' => 'small',
                        ],
                        'impact_score' => 0.8,
                    ],
                ],
                'merged_to_main' => true,
                'reviewed_at' => now(),
            ]);
        } finally {
            AtlasLoopProposal::$governedMergeInProgress = false;
        }
    }
}
