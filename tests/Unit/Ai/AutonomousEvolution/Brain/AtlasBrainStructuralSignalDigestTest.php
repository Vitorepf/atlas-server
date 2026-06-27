<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainStructuralSignalDigest;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use Tests\TestCase;

/**
 * Comprehension-deepening substrate — proves the digest is pure, deterministic, top-K bounded, fact-only
 * (no scalar / no leverage score persisted), and that the organ itself is pétreo (the brain never edits the
 * structural perception it consumes — same principle as the comprehension model + Reflexion stream).
 */
final class AtlasBrainStructuralSignalDigestTest extends TestCase
{
    private function model(): AtlasLoopScopeComprehensionModel
    {
        return AtlasLoopScopeComprehensionModel::fromArray([
            'snapshot_id' => 'snap_digest',
            'inventory' => [],
            'edges' => [],
            // Unsorted on purpose — the digest must sort deterministically.
            'orphans' => ['App\\ZOrphan', 'App\\AOrphan', 'App\\MOrphan', 'App\\BOrphan', 'App\\COrphan', 'App\\DOrphan'],
            // Canonical model shape: list<{cluster_id, clone_hash, members}>.
            'clone_clusters' => [
                ['cluster_id' => 'cluster-b', 'clone_hash' => 'hb', 'members' => []],
                ['cluster_id' => 'cluster-a', 'clone_hash' => 'ha', 'members' => []],
                ['cluster_id' => 'cluster-c', 'clone_hash' => 'hc', 'members' => []],
            ],
            'forbidden' => [],
            'doc_purposes' => [],
            'doc_stated_gaps' => ['the orphan wiring layer is unfinished', 'auth has no integration test'],
        ]);
    }

    public function test_digest_is_top_k_bounded_and_sorted_deterministically(): void
    {
        $digest = (new AtlasBrainStructuralSignalDigest)->digest($this->model(), 3);

        self::assertSame(['App\\AOrphan', 'App\\BOrphan', 'App\\COrphan'], $digest['orphans'], 'top-3 alphabetic');
        self::assertSame(['cluster-a', 'cluster-b', 'cluster-c'], $digest['clone_clusters']);
        self::assertSame(['auth has no integration test', 'the orphan wiring layer is unfinished'], $digest['doc_stated_gaps']);
        self::assertSame(3, $digest['k']);
    }

    public function test_digest_k_zero_returns_empty(): void
    {
        $digest = (new AtlasBrainStructuralSignalDigest)->digest($this->model(), 0);

        self::assertSame([], $digest['orphans']);
        self::assertSame([], $digest['clone_clusters']);
        self::assertSame([], $digest['doc_stated_gaps']);
    }

    public function test_digest_is_no_scalar_no_leverage_score(): void
    {
        $digest = (new AtlasBrainStructuralSignalDigest)->digest($this->model(), 5);

        foreach (['score', 'rank', 'leverage', 'quality', 'weight', 'importance', 'recorded_at'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $digest, "digest must not store a learning scalar ({$forbidden}) — facts only");
        }
    }

    public function test_digest_organ_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit(
            'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainStructuralSignalDigest.php',
            true // even with meta_harness ON, the brain's perception is pétreo
        );

        self::assertSame('forbidden', $verdict);
    }
}
