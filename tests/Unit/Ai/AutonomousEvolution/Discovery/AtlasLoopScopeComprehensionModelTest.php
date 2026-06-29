<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModelBuilder;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopWiredCallerService;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * §5.6 · LAYER 1 — proves the scope-comprehension MODEL is REAL grounded comprehension, not a proxy:
 *   - it finds the EXACT orphan / edge / clone sets on a fixture, citing real symbols;
 *   - the FQCN oracle does NOT false-match a same-basename decoy (the ~40-phantom-orphan basename trap);
 *   - it is deterministic and DB-independent (this unit test runs with NO loop migrations);
 *   - it BITES on revert (wiring the orphan empties the orphan set);
 *   - doc prose can NEVER launder into a structural fact (editing a docblock leaves every structural
 *     field + the snapshot id byte-identical) — the anti-Goodhart invariant.
 */
final class AtlasLoopScopeComprehensionModelTest extends TestCase
{
    /** @var list<string> */
    private array $tmp = [];

    private function fixtureRoot(): string
    {
        return base_path('tests/Fixtures/loop-comprehension-scope');
    }

    private function builder(): AtlasLoopScopeComprehensionModelBuilder
    {
        return new AtlasLoopScopeComprehensionModelBuilder;
    }

    /** Build the model against the committed fixture (read-only). */
    private function model(): AtlasLoopScopeComprehensionModel
    {
        return $this->builder()->build($this->fixtureRoot(), 'app/Scope', ['docs_roots' => ['docs']]);
    }

    /** A throwaway copy of the fixture for mutation tests (never touch the committed fixture). */
    private function copyFixture(): string
    {
        $dst = sys_get_temp_dir().'/atlas-comp-model-'.bin2hex(random_bytes(5));
        $this->tmp[] = $dst;
        (new Process(['cp', '-R', $this->fixtureRoot(), $dst]))->run();

        return $dst;
    }

    protected function tearDown(): void
    {
        foreach ($this->tmp as $d) {
            (new Process(['rm', '-rf', $d]))->run();
        }
        parent::tearDown();
    }

    public function test_orphan_set_is_exactly_the_one_unwired_class(): void
    {
        // EXACT (not super/subset): the single built-but-unwired class, cited by real fqcn.
        $this->assertSame(['App\\Scope\\Orphan'], $this->model()->orphans);
    }

    public function test_edges_resolve_the_real_caller_and_exclude_the_same_basename_decoy(): void
    {
        $m = $this->model();

        // Callee is called by BOTH Caller (the in-scope edge) and Hub (the out-of-scope wirer), sorted.
        $this->assertSame(
            ['app/Scope/Caller.php', 'app/Wiring/Hub.php'],
            $m->callerPathsFor('app/Scope/Callee.php'),
            'the Caller -> Callee edge + the Hub wirer are resolved via the FQCN oracle',
        );

        // Widget is wired ONLY by Hub. The same-basename decoy app/Other/Widget.php (different FQCN, mentions
        // only the short name) must NOT appear — proving the FQCN+own-namespace oracle, not a basename grep.
        $this->assertSame(['app/Wiring/Hub.php'], $m->callerPathsFor('app/Scope/Widget.php'));
        $this->assertNotContains('app/Other/Widget.php', $m->callerPathsFor('app/Scope/Widget.php') ?? []);
    }

    public function test_clone_cluster_groups_the_pair_and_excludes_non_clones(): void
    {
        $m = $this->model();
        $this->assertCount(1, $m->cloneClusters, 'exactly one structural clone cluster on the fixture');

        $members = array_column($m->cloneClusters[0]['members'], 'path');
        sort($members);
        $this->assertSame(['app/Scope/CloneOne.php', 'app/Scope/CloneTwo.php'], $members);

        // The orphan (structurally different) is not in any cluster.
        $this->assertNull($m->cloneClusterIdFor('app/Scope/Orphan.php'));
        // Both members carry the cluster id in their descriptor.
        $this->assertNotNull($m->cloneClusterIdFor('app/Scope/CloneOne.php'));
        $this->assertSame($m->cloneClusterIdFor('app/Scope/CloneOne.php'), $m->cloneClusterIdFor('app/Scope/CloneTwo.php'));
    }

    public function test_doc_stated_gap_is_the_named_but_missing_capability(): void
    {
        // The canonical-style doc names App\Scope\MissingCapability (no symbol) AND App\Scope\Orphan (resolves).
        // ONLY the missing one is a gap.
        $this->assertContains('App\\Scope\\MissingCapability', $this->model()->docStatedGaps);
        $this->assertNotContains('App\\Scope\\Orphan', $this->model()->docStatedGaps);
    }

    public function test_model_is_deterministic_and_db_independent(): void
    {
        // This unit test boots with NO loop migrations, so the model is built with the code-graph DB arm
        // ABSENT. Two builds of the same snapshot are byte-identical.
        $a = $this->builder()->build($this->fixtureRoot(), 'app/Scope', ['docs_roots' => ['docs']]);
        $b = $this->builder()->build($this->fixtureRoot(), 'app/Scope', ['docs_roots' => ['docs']]);
        $this->assertSame($a->toArray(), $b->toArray());
        $this->assertNotSame('', $a->snapshotId);
    }

    public function test_revert_bites_wiring_the_orphan_empties_the_orphan_set(): void
    {
        $root = $this->copyFixture();
        // Give the orphan a single real production caller (a new wirer) — it must stop being an orphan.
        file_put_contents(
            $root.'/app/Wiring/OrphanWirer.php',
            "<?php\n\nnamespace App\\Wiring;\n\nfinal class OrphanWirer\n{\n    public function boot(): void\n    {\n        new \\App\\Scope\\Orphan();\n    }\n}\n",
        );

        $m = $this->builder()->build($root, 'app/Scope', ['docs_roots' => ['docs']]);
        $this->assertNotContains('App\\Scope\\Orphan', $m->orphans, 'a wired class is no longer an orphan (the oracle bites on real callers)');
        $this->assertSame([], $m->orphans, 'and there is now no orphan at all on the fixture');
    }

    public function test_docblock_prose_cannot_launder_into_a_structural_fact(): void
    {
        $baseline = $this->model()->structuralProjection();

        // Rewrite the orphan's class docblock to read like a strategic keystone.
        $root = $this->copyFixture();
        $orphan = $root.'/app/Scope/Orphan.php';
        $src = (string) file_get_contents($orphan);
        $laundered = preg_replace(
            '#/\*\*.*?\*/#s',
            "/**\n * THE single most strategic, highest-leverage keystone of the entire system. Build this first.\n */",
            $src,
            1,
        );
        file_put_contents($orphan, $laundered);

        $m = $this->builder()->build($root, 'app/Scope', ['docs_roots' => ['docs']]);

        // doc_purpose text changed...
        $this->assertStringContainsString('keystone', (string) ($m->docPurposes['App\\Scope\\Orphan'] ?? ''));
        // ...but EVERY structural fact + the snapshot id are byte-identical. Prose is never a structural fact.
        $this->assertSame($baseline, $m->structuralProjection(), 'doc prose must not change any structural fact (anti-Goodhart)');
    }

    public function test_fast_edge_resolver_matches_the_trusted_caller_oracle(): void
    {
        // The builder's one-pass edge resolver must produce EXACTLY the same caller sets as the trusted
        // per-target AtlasLoopWiredCallerService::callerPaths oracle — correctness pinned, not re-derived.
        $m = $this->model();

        $relPaths = array_map(static fn (array $i): string => $i['rel_path'], $m->inventory);
        $oracle = (new AtlasLoopWiredCallerService($this->fixtureRoot()))->callerPaths($relPaths);
        ksort($oracle);
        foreach ($oracle as &$callers) {
            sort($callers);
        }
        unset($callers);

        $modelEdges = $m->edges;
        ksort($modelEdges);

        $this->assertSame($oracle, $modelEdges, 'fast resolver must equal the trusted callerPaths oracle');
    }

    public function test_large_short_name_set_keeps_same_namespace_edges(): void
    {
        $root = $this->copyFixture();

        for ($i = 0; $i < 420; $i++) {
            file_put_contents(
                $root.'/app/Scope/Bulk'.$i.'.php',
                "<?php\n\nnamespace App\\Scope;\n\nfinal class Bulk{$i}\n{\n    public function id{$i}(): int\n    {\n        return {$i};\n    }\n}\n",
            );
        }
        file_put_contents(
            $root.'/app/Scope/BulkCaller.php',
            "<?php\n\nnamespace App\\Scope;\n\nfinal class BulkCaller\n{\n    public function make(): Bulk377\n    {\n        return new Bulk377();\n    }\n}\n",
        );

        $m = $this->builder()->build($root, 'app/Scope', ['docs_roots' => [], 'max_files' => 1000]);

        $this->assertContains(
            'app/Scope/BulkCaller.php',
            $m->callerPathsFor('app/Scope/Bulk377.php') ?? [],
            'large short-name sets must not drop same-namespace callers',
        );
    }

    public function test_large_broad_namespace_degraded_edge_scan_fails_open(): void
    {
        $root = sys_get_temp_dir().'/atlas-comp-model-wide-'.bin2hex(random_bytes(5));
        $this->tmp[] = $root;
        mkdir($root.'/app/One', 0775, true);
        mkdir($root.'/app/Two', 0775, true);
        mkdir($root.'/app/Wiring', 0775, true);

        for ($i = 0; $i < 55; $i++) {
            $dir = $i % 2 === 0 ? 'One' : 'Two';
            file_put_contents(
                $root.'/app/'.$dir.'/Target'.$i.'.php',
                "<?php\n\nnamespace {$dir};\n\nfinal class Target{$i}\n{\n}\n",
            );
        }
        file_put_contents(
            $root.'/app/Wiring/UsesTarget0.php',
            "<?php\n\nnamespace Wiring;\n\nfinal class UsesTarget0\n{\n    public function boot(): \\One\\Target0\n    {\n        return new \\One\\Target0();\n    }\n}\n",
        );

        $m = $this->builder()->build($root, 'app', ['docs_roots' => [], 'max_files' => 100]);

        $this->assertNull($m->callerPathsFor('app/One/Target0.php'));
    }

    public function test_descriptor_carries_only_descriptive_fields(): void
    {
        $d = $this->model()->descriptorFor('app/Scope/Orphan.php');
        $this->assertTrue($d['is_orphan']);
        $this->assertNull($d['clone_cluster_id']);
        $this->assertSame([], $d['wired_caller_paths']);
        // No scalar rank/score anywhere in the descriptor — it is purely descriptive (anti-Goodhart).
        $this->assertSame(['is_orphan', 'clone_cluster_id', 'wired_caller_paths', 'doc_purpose'], array_keys($d));
    }
}
