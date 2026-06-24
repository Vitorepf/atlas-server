<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\RepoWide;

use App\Services\Ai\AutonomousEvolution\RepoWide\AtlasLoopRepoWideComprehensionModel;
use PHPUnit\Framework\TestCase;

/**
 * Proves the repo-wide federation: deterministic + stable model_hash, deduped symbol union, and cross-domain
 * caller resolution (an orphan-in-A that is a caller-target in B drops out of the federated orphans, while a
 * genuinely uncalled orphan stays).
 */
final class AtlasLoopRepoWideComprehensionModelTest extends TestCase
{
    private function model(): AtlasLoopRepoWideComprehensionModel
    {
        return new AtlasLoopRepoWideComprehensionModel;
    }

    /** @return list<array<string,mixed>> */
    private function scopes(): array
    {
        return [
            [
                'scope_id' => 'A',
                'inventory' => [
                    ['fqcn' => 'App\\X', 'is_orphan' => true],   // orphan within A
                    ['fqcn' => 'App\\Y', 'is_orphan' => false],
                    ['fqcn' => 'App\\Lonely', 'is_orphan' => true], // orphan everywhere
                ],
                'orphans' => ['App\\X', 'App\\Lonely'],
                'clone_clusters' => [['cluster_id' => 'c1']],
            ],
            [
                'scope_id' => 'B',
                'inventory' => [['fqcn' => 'App\\Z', 'is_orphan' => false]],
                'orphans' => [],
                'caller_targets' => ['App\\X'], // B references X ⇒ X is not a real orphan
                'clone_clusters' => [['cluster_id' => 'c1']], // duplicate cluster across scopes
            ],
        ];
    }

    public function test_is_deterministic_and_hash_stable(): void
    {
        $a = $this->model()->build($this->scopes());
        $b = $this->model()->build($this->scopes());

        $this->assertSame(json_encode($a), json_encode($b));
        $this->assertSame($a['model_hash'], $b['model_hash']);
        $this->assertSame('atlas.loop.repo_wide_comprehension.v1', $a['schema']);
        $this->assertSame(64, strlen($a['model_hash']));
    }

    public function test_cross_domain_caller_resolution_removes_resolved_orphan(): void
    {
        $model = $this->model()->build($this->scopes());

        $orphanFqcns = array_map(static fn ($o): string => (string) $o, $model['orphans']);
        $this->assertNotContains('App\\X', $orphanFqcns, 'X is referenced in B ⇒ not a federated orphan');
        $this->assertContains('App\\Lonely', $orphanFqcns, 'a genuinely uncalled orphan stays');
    }

    public function test_symbol_count_is_the_deduped_union(): void
    {
        $model = $this->model()->build($this->scopes());

        // union of inventory fqcns = {X, Y, Lonely, Z} = 4
        $this->assertSame(4, $model['symbol_count']);
        $this->assertCount(4, $model['symbols']);
        $this->assertSame(2, $model['scope_count']);
        $this->assertCount(1, $model['clones'], 'clone cluster c1 deduped across scopes');
    }

    public function test_dedup_symbol_across_scopes(): void
    {
        $scopes = [
            ['inventory' => [['fqcn' => 'App\\Dup', 'is_orphan' => false]]],
            ['inventory' => [['fqcn' => 'App\\Dup', 'is_orphan' => false]]],
        ];

        $model = $this->model()->build($scopes);

        $this->assertSame(1, $model['symbol_count'], 'same fqcn across scopes ⇒ deduped');
    }
}
