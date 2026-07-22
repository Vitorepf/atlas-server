<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\V2;

use App\Services\Ai\AutonomousEvolution\V2\AtlasLoopV2ScopeManifestRegistry;
use DomainException;
use Tests\TestCase;

final class AtlasLoopV2ScopeManifestRegistryTest extends TestCase
{
    public function test_loads_valid_manifest_from_config_and_finds_by_id(): void
    {
        $manifest = [$this->scope('home')];
        config()->set('atlas.ai.loop.v2.scopes', $manifest);

        $registry = new AtlasLoopV2ScopeManifestRegistry;

        $this->assertSame($manifest, $registry->all());
        $this->assertSame($manifest[0], $registry->findById('home'));
        $this->assertNull($registry->findById('missing'));
    }

    public function test_duplicate_id_throws(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('home');
        $this->expectExceptionMessage('id');

        new AtlasLoopV2ScopeManifestRegistry([
            $this->scope('home'),
            $this->scope('home', '/tmp/atlas/other'),
        ]);
    }

    public function test_missing_field_throws_with_field_and_id(): void
    {
        $scope = $this->scope('home');
        unset($scope['discovery_roots']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('home');
        $this->expectExceptionMessage('discovery_roots');

        new AtlasLoopV2ScopeManifestRegistry([$scope]);
    }

    public function test_invalid_risk_tier_throws_with_field_and_id(): void
    {
        $scope = $this->scope('home');
        $scope['risk_tier'] = 'extreme';

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('home');
        $this->expectExceptionMessage('risk_tier');

        new AtlasLoopV2ScopeManifestRegistry([$scope]);
    }

    public function test_total_parallel_budget_sums_scope_worker_budgets(): void
    {
        $registry = new AtlasLoopV2ScopeManifestRegistry([
            $this->scope('home', maxParallelWorkers: 2),
            $this->scope('tools', '/tmp/atlas/tools', maxParallelWorkers: 5),
        ]);

        $this->assertSame(7, $registry->totalParallelBudget());
    }

    public function test_tiered_scopes_filters_by_risk_tier(): void
    {
        $low = $this->scope('home', riskTier: 'low');
        $high = $this->scope('kernel', '/tmp/atlas/kernel', riskTier: 'high');
        $registry = new AtlasLoopV2ScopeManifestRegistry([$low, $high]);

        $this->assertSame([$high], $registry->tieredScopes('high'));
        $this->assertSame([$low], $registry->tieredScopes('low'));
        $this->assertSame([], $registry->tieredScopes('medium'));
    }

    public function test_assert_home_repo_present_compares_canonical_paths(): void
    {
        $registry = new AtlasLoopV2ScopeManifestRegistry([
            $this->scope('home', '/tmp/atlas/home/'),
        ]);

        $registry->assertHomeRepoPresent('/tmp/atlas/./home');
        $this->addToAssertionCount(1);

        try {
            $registry->assertHomeRepoPresent('/tmp/atlas/missing');
            $this->fail('Expected missing home repo to throw.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('/tmp/atlas/missing', $e->getMessage());
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function scope(
        string $id,
        string $repoRoot = '/tmp/atlas/home',
        string $riskTier = 'low',
        int $maxParallelWorkers = 1,
    ): array {
        return [
            'id' => $id,
            'repo_root_absolute' => $repoRoot,
            'discovery_roots' => ['app', 'tests'],
            'frozen_safety_files' => ['config/atlas.php'],
            'territory_name' => 'Atlas '.$id,
            'max_parallel_workers' => $maxParallelWorkers,
            'risk_tier' => $riskTier,
            'promotion_required_certified_leaps' => 1,
        ];
    }
}
