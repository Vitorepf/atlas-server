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
 * L2-7: o medidor do exponencial. O delta compara HOJE vs Marco Zero com fontes VIVAS
 * (scorecard resolved-evidence, tabela do Loop, runtime semântico) — nunca números
 * declarados. Congelado: o comando resolve, o shape carrega baseline/current/delta.
 */
final class AtlasFableDeltaCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureLoopTables();
    }

    public function test_delta_resolves_against_a_baseline_with_live_sources(): void
    {
        $this->seedMergedImpactReceipt();
        $baseline = sys_get_temp_dir().'/marco-zero-test-'.bin2hex(random_bytes(4)).'.json';
        file_put_contents($baseline, json_encode([
            'recorded_at' => '2026-06-11',
            'baseline' => [
                'maturity_scorecard' => ['acos_overall' => 5.0],
                'learning_capture_quality_7d' => ['gate_mode' => 'observe'],
            ],
        ]));

        $out = new BufferedOutput;
        $exit = Artisan::call('atlas:fable:delta', ['--baseline' => $baseline, '--json' => true], $out);
        @unlink($baseline);

        $this->assertSame(0, $exit);
        $report = json_decode($out->fetch(), true);
        $this->assertSame('atlas.fable.delta.v1', $report['schema_version']);

        $m = $report['metrics']['scorecard_overall'];
        $this->assertEqualsWithDelta(5.0, $m['baseline'], 0.001);
        $this->assertIsNumeric($m['current']);
        $this->assertEqualsWithDelta($m['current'] - $m['baseline'], $m['delta'], 0.001, 'delta = current - baseline, resolvido');
        $impact = $report['metrics']['loop_impact_receipts']['current'];
        $this->assertGreaterThanOrEqual(1, $impact['receipted_merges']);
        $this->assertGreaterThanOrEqual(1, $impact['by_category']['bug'] ?? 0);
        $this->assertArrayHasKey('loop_impact_receipt_coverage_pct', $report['metrics']);
        $this->assertArrayHasKey('sources', $report, 'todas as métricas declaram a fonte viva');
        $this->assertArrayHasKey('impact_receipts', $report['sources']);
    }

    public function test_missing_baseline_fails_closed(): void
    {
        $exit = Artisan::call('atlas:fable:delta', ['--baseline' => '/nonexistent/mz.json']);
        $this->assertSame(1, $exit);
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
            'goal' => 'fable delta impact receipt proof',
            'config' => [],
            'max_seconds' => 60,
        ]);

        AtlasLoopProposal::$governedMergeInProgress = true;
        try {
            AtlasLoopProposal::create([
                'campaign_id' => $campaign->id,
                'schema_version' => 'atlas.loop.proposal.v1',
                'status' => AtlasLoopProposal::STATUS_CERTIFIED,
                'objective' => 'fix live bug for Fable delta',
                'target_path' => 'app/Services/FableProof.php',
                'diff_text' => "diff --git a/app/Services/FableProof.php b/app/Services/FableProof.php\n+return true;\n",
                'proposal_hash' => 'fable-delta-impact-'.bin2hex(random_bytes(4)),
                'quality' => [
                    '_impact_receipt' => [
                        'schema_version' => 'atlas.loop.impact_receipt.v1',
                        'category' => 'bug',
                        'target_kind' => 'real',
                        'real_vs_generated' => 'real',
                        'target_path' => 'app/Services/FableProof.php',
                        'size' => [
                            'files_changed' => 1,
                            'php_files_changed' => 1,
                            'added_lines' => 1,
                            'deleted_lines' => 0,
                            'touched_lines' => 1,
                            'bucket' => 'small',
                        ],
                        'impact_score' => 0.76,
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
