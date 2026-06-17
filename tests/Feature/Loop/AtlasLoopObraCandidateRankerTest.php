<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopDecompositionOutcome;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopObraCandidateRanker;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ACDE DC7 — the de-orphaned HeavyWorkSelector ranks loop-detected obra candidates by proven-leap using
 * REAL measured leverage evidence + REAL per-class accept stats from the decomposition-outcomes ledger.
 * The highest-leverage / biggest-scope cluster is the pick; bad input fails open to an empty ranking.
 */
final class AtlasLoopObraCandidateRankerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_decomposition_outcomes')) {
            (require base_path('database/migrations/2026_06_16_000100_create_atlas_loop_decomposition_outcomes_table.php'))->up();
        }
    }

    /** A parked obra-candidate payload (the shape AtlasLoopObraClusterCandidate::toBacklogProposalPayload emits). */
    private function payload(string $hash, int $files, float $leverage, int $cyclo, int $callers): array
    {
        return ['obra_cluster_candidate' => [
            'cluster_hash' => $hash,
            'objective_kind' => 'refactor_reduce_complexity',
            'allowed_files' => array_map(static fn (int $i): string => "app/F{$hash}{$i}.php", range(1, max(1, $files))),
            'leverage_signals' => [
                'refactor_leverage' => $leverage,
                'cyclomatic_total' => $cyclo,
                'caller_count' => $callers,
            ],
        ]];
    }

    public function test_ranks_the_highest_leverage_cluster_first(): void
    {
        // Some real accept history for the class (so P(land) is a measured Laplace rate, not fabricated).
        for ($i = 0; $i < 6; $i++) {
            AtlasLoopDecompositionOutcome::create([
                'fingerprint_hash' => substr(hash('sha256', 'fp'.$i), 0, 32),
                'objective_kind' => 'refactor_reduce_complexity',
                'node_count' => 3,
                'certified' => $i < 4, // 4 certified / 2 thrashed
                'thrashed' => ! ($i < 4),
                'terminal_reason' => 'x',
                'rounds' => 1,
            ]);
        }

        $big = $this->payload('BIG', files: 5, leverage: 0.9, cyclo: 90, callers: 4);
        $small = $this->payload('SML', files: 2, leverage: 0.2, cyclo: 12, callers: 1);

        $out = (new AtlasLoopObraCandidateRanker)->rank([$small, $big]); // order-independent

        $this->assertSame('BIG', $out['pick'], 'the higher-leverage, bigger-scope cluster is the proven-leap pick');
        $this->assertNotEmpty($out['ranked']);
        $this->assertSame('BIG', $out['ranked'][0]['candidateId'], 'ranked highest-first');
        $this->assertSame('refactor_reduce_complexity', $out['ranked'][0]['class']);
        $this->assertNotSame('', $out['ranked'][0]['gate'], 'each ranked candidate carries a trust gate verdict');
    }

    public function test_empty_or_unmappable_input_fails_open_to_empty_ranking(): void
    {
        $ranker = new AtlasLoopObraCandidateRanker;

        $this->assertSame(['pick' => null, 'ranked' => []], $ranker->rank([]));
        // A payload with no obra_cluster_candidate block contributes nothing.
        $this->assertSame(['pick' => null, 'ranked' => []], $ranker->rank([['title' => 'not a cluster candidate']]));
        // A candidate block with no cluster_hash is skipped.
        $this->assertSame(['pick' => null, 'ranked' => []], $ranker->rank([['obra_cluster_candidate' => ['allowed_files' => ['a.php']]]]));
    }
}
