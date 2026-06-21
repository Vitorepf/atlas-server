<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopComprehensionOriginationCandidates;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModelBuilder;
use Tests\TestCase;

/**
 * §5.6 · LAYER 2 — the grounded origination candidates must be net-new (orphan-wiring / clone-unification /
 * doc-gap), grounded BY CONSTRUCTION (every candidate cites a real model fact), petreo-excluded, and carry
 * NO score (the leverage judgment is the frontier model's, never a scalar here).
 */
final class AtlasLoopComprehensionOriginationCandidatesTest extends TestCase
{
    private function producer(): AtlasLoopComprehensionOriginationCandidates
    {
        return new AtlasLoopComprehensionOriginationCandidates;
    }

    /**
     * @param  list<array<string,mixed>>  $inventory
     * @param  list<string>  $orphans
     * @param  list<array<string,mixed>>  $clones
     * @param  list<string>  $forbidden
     * @param  list<string>  $gaps
     */
    private function model(array $inventory, array $orphans = [], array $clones = [], array $forbidden = [], array $gaps = []): AtlasLoopScopeComprehensionModel
    {
        return new AtlasLoopScopeComprehensionModel(
            inventory: $inventory,
            edges: [],
            orphans: $orphans,
            cloneClusters: $clones,
            forbidden: $forbidden,
            docPurposes: [],
            docStatedGaps: $gaps,
            snapshotId: 'snap-test',
        );
    }

    private function inv(string $rel, string $fqcn, bool $orphan = false, bool $forbidden = false): array
    {
        return ['rel_path' => $rel, 'fqcn' => $fqcn, 'public_methods' => [], 'is_orphan' => $orphan, 'is_forbidden' => $forbidden, 'clone_cluster_id' => null];
    }

    public function test_orphan_wiring_candidate_cites_the_real_unwired_symbol(): void
    {
        $m = $this->model(
            inventory: [$this->inv('app/X/Foo.php', 'App\\X\\Foo', orphan: true)],
            orphans: ['App\\X\\Foo'],
        );
        $c = $this->producer()->forModel($m);
        $this->assertCount(1, $c);
        $this->assertSame(AtlasLoopComprehensionOriginationCandidates::KIND_ORPHAN_WIRING, $c[0]['kind']);
        $this->assertSame('App\\X\\Foo', $c[0]['target_fqcn']);
        $this->assertSame('app/X/Foo.php', $c[0]['target_path']);
        $this->assertTrue($c[0]['evidence']['is_orphan']);
    }

    public function test_clone_unification_candidate_cites_all_members(): void
    {
        $cluster = ['cluster_id' => 'clone:abc', 'clone_hash' => 'abc123', 'members' => [
            ['path' => 'app/X/A.php', 'symbol' => 'A::run'],
            ['path' => 'app/X/B.php', 'symbol' => 'B::run'],
        ]];
        $m = $this->model(
            inventory: [$this->inv('app/X/A.php', 'App\\X\\A'), $this->inv('app/X/B.php', 'App\\X\\B')],
            clones: [$cluster],
        );
        $c = $this->producer()->forModel($m);
        $this->assertCount(1, $c);
        $this->assertSame(AtlasLoopComprehensionOriginationCandidates::KIND_CLONE_UNIFICATION, $c[0]['kind']);
        $this->assertSame('clone:abc', $c[0]['evidence']['clone_cluster_id']);
        $this->assertSame(['app/X/A.php', 'app/X/B.php'], array_column($c[0]['members'], 'path'));
    }

    public function test_doc_gap_candidate_names_the_missing_capability(): void
    {
        $m = $this->model(inventory: [], gaps: ['App\\X\\MissingThing']);
        $c = $this->producer()->forModel($m);
        $this->assertCount(1, $c);
        $this->assertSame(AtlasLoopComprehensionOriginationCandidates::KIND_DOC_GAP_CAPABILITY, $c[0]['kind']);
        $this->assertSame('App\\X\\MissingThing', $c[0]['capability']);
    }

    public function test_a_forbidden_petreo_orphan_is_never_originated_on(): void
    {
        // The judge/merge organs are pétreo — even if unwired they must NEVER become an origination target.
        $m = $this->model(
            inventory: [$this->inv('app/Judge.php', 'App\\Judge', orphan: true, forbidden: true)],
            orphans: ['App\\Judge'],
            forbidden: ['app/Judge.php'],
        );
        $this->assertSame([], $this->producer()->forModel($m), 'a forbidden orphan yields no origination candidate');
    }

    public function test_a_clone_cluster_reduced_below_two_by_the_forbidden_filter_is_dropped(): void
    {
        $cluster = ['cluster_id' => 'clone:z', 'clone_hash' => 'z', 'members' => [
            ['path' => 'app/Judge.php', 'symbol' => 'Judge::x'],
            ['path' => 'app/X/B.php', 'symbol' => 'B::x'],
        ]];
        $m = $this->model(
            inventory: [$this->inv('app/Judge.php', 'App\\Judge', forbidden: true), $this->inv('app/X/B.php', 'App\\X\\B')],
            clones: [$cluster],
            forbidden: ['app/Judge.php'],
        );
        // Only one non-forbidden member remains ⇒ not a unification ⇒ no candidate.
        $this->assertSame([], $this->producer()->forModel($m));
    }

    public function test_candidates_carry_no_score_or_rank_field(): void
    {
        // ANTI-GOODHART: the producer describes; it never ranks. No scalar to climb.
        $m = $this->model(
            inventory: [$this->inv('app/X/Foo.php', 'App\\X\\Foo', orphan: true)],
            orphans: ['App\\X\\Foo'],
            gaps: ['App\\X\\Missing'],
        );
        foreach ($this->producer()->forModel($m) as $cand) {
            foreach (['score', 'leverage', 'rank', 'priority', 'weight'] as $banned) {
                $this->assertArrayNotHasKey($banned, $cand, "a candidate must carry no '{$banned}' scalar");
                $this->assertArrayNotHasKey($banned, $cand['evidence'], "evidence must carry no '{$banned}' scalar");
            }
        }
    }

    public function test_end_to_end_on_the_real_fixture_model(): void
    {
        $model = (new AtlasLoopScopeComprehensionModelBuilder)->build(
            base_path('tests/Fixtures/loop-comprehension-scope'),
            'app/Scope',
            ['docs_roots' => ['docs']],
        );
        $byKind = [];
        foreach ($this->producer()->forModel($model) as $c) {
            $byKind[$c['kind']][] = $c;
        }
        $this->assertCount(1, $byKind[AtlasLoopComprehensionOriginationCandidates::KIND_ORPHAN_WIRING] ?? []);
        $this->assertSame('App\\Scope\\Orphan', $byKind[AtlasLoopComprehensionOriginationCandidates::KIND_ORPHAN_WIRING][0]['target_fqcn']);
        $this->assertCount(1, $byKind[AtlasLoopComprehensionOriginationCandidates::KIND_CLONE_UNIFICATION] ?? []);
        $this->assertContains('App\\Scope\\MissingCapability', array_column($byKind[AtlasLoopComprehensionOriginationCandidates::KIND_DOC_GAP_CAPABILITY] ?? [], 'capability'));
    }
}
