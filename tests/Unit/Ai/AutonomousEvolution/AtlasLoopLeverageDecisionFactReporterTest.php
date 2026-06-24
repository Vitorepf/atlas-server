<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopLeverageDecisionFactReporter;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use Tests\TestCase;

/**
 * Proves the DECIDIR-ALAVANCAGEM fact reporter: byte-stable fact envelope (no score/rank scalar, no wall-clock),
 * fail-closed on any ungrounded citation (writer≠judge, writes nothing), flag-OFF null no-op, and idempotent
 * ndjson append.
 */
final class AtlasLoopLeverageDecisionFactReporterTest extends TestCase
{
    private string $decisionsPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->decisionsPath = sys_get_temp_dir().'/atlas-decisions-'.bin2hex(random_bytes(6)).'/leverage-decisions.ndjson';
    }

    protected function tearDown(): void
    {
        @unlink($this->decisionsPath);
        @rmdir(dirname($this->decisionsPath));
        parent::tearDown();
    }

    private function reporter(): AtlasLoopLeverageDecisionFactReporter
    {
        return new AtlasLoopLeverageDecisionFactReporter(null, $this->decisionsPath);
    }

    private function model(): AtlasLoopScopeComprehensionModel
    {
        return new AtlasLoopScopeComprehensionModel(
            inventory: [
                ['fqcn' => 'App\\Foo\\Bar', 'rel_path' => 'app/Foo/Bar.php', 'public_methods' => [], 'is_orphan' => false, 'is_forbidden' => false, 'clone_cluster_id' => null],
                ['fqcn' => 'App\\Foo\\Baz', 'rel_path' => 'app/Foo/Baz.php', 'public_methods' => [], 'is_orphan' => false, 'is_forbidden' => false, 'clone_cluster_id' => null],
            ],
            edges: [], orphans: [], cloneClusters: [], forbidden: [], docPurposes: [], docStatedGaps: [], snapshotId: 'snap',
        );
    }

    /** @return list<array<string,mixed>> */
    private function groundedCandidates(): array
    {
        return [
            ['objective' => 'wire App\\Foo\\Bar into its caller', 'cited_symbols' => ['App\\Foo\\Bar'], 'summary' => 'wire Bar'],
            ['objective' => 'tidy App\\Foo\\Baz', 'cited_symbols' => ['App\\Foo\\Baz'], 'summary' => 'baz'],
        ];
    }

    public function test_flag_off_is_null_no_op(): void
    {
        config(['atlas.loop.leverage_decision_reporter_enabled' => false]);

        $this->assertNull($this->reporter()->report($this->groundedCandidates(), 0, $this->model()));
        $this->assertFileDoesNotExist($this->decisionsPath);
    }

    public function test_grounded_pick_emits_scoreless_envelope_byte_stable_and_idempotent(): void
    {
        config(['atlas.loop.leverage_decision_reporter_enabled' => true]);

        $run1 = $this->reporter()->report($this->groundedCandidates(), 0, $this->model());
        $run2 = $this->reporter()->report($this->groundedCandidates(), 0, $this->model());

        $this->assertNotNull($run1);
        $this->assertSame('atlas.loop.leverage_decision.v1', $run1['schema_version']);
        $this->assertTrue($run1['reported']);
        $this->assertSame(0, $run1['selected_index']);
        $this->assertSame('wire App\\Foo\\Bar into its caller', $run1['selected_objective']);
        $this->assertNull($run1['refusal_reason_or_null']);

        // anti-Goodhart: NO numeric score / rank / leverage scalar anywhere.
        foreach (['score', 'rank', 'leverage_value', 'leverage_rank'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $run1, "no {$forbidden} key");
        }

        $this->assertSame(json_encode($run1), json_encode($run2), 'byte-identical across calls (no wall-clock)');

        // idempotent by content: two identical reports ⇒ exactly ONE ndjson line.
        $lines = array_filter(explode("\n", trim((string) file_get_contents($this->decisionsPath))), static fn (string $l): bool => $l !== '');
        $this->assertCount(1, $lines);
    }

    public function test_ungrounded_citation_fails_closed_and_writes_nothing(): void
    {
        config(['atlas.loop.leverage_decision_reporter_enabled' => true]);

        $candidates = [['objective' => 'wire a ghost', 'cited_symbols' => ['App\\Ghost\\Phantom'], 'summary' => 'ghost']];
        $result = $this->reporter()->report($candidates, 0, $this->model());

        $this->assertFalse($result['reported']);
        $this->assertSame('ungrounded_citation', $result['reason']);
        $this->assertContains('App\\Ghost\\Phantom', $result['refuted']);
        $this->assertFileDoesNotExist($this->decisionsPath, 'a refuted citation writes NOTHING');
    }
}
