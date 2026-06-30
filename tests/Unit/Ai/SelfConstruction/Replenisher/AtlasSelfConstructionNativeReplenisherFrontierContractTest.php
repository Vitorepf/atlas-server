<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Replenisher;

use App\Services\Ai\SelfConstruction\Replenisher\AtlasSelfConstructionNativeReplenisherFrontierContract;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasSelfConstructionNativeReplenisherFrontierContract: a valid frontier is accepted with
 * normalized fields; a duplicate frontier_id is rejected with duplicate_frontier_id; allowed_file_candidates
 * spanning two different /repo/<id>/... project_ids ⇒ cross_project_mixed; missing target_scope ⇒
 * missing_target_scope; proxy-only kind ⇒ proxy_only_kind.
 */
final class AtlasSelfConstructionNativeReplenisherFrontierContractTest extends TestCase
{
    private function valid(): array
    {
        return [
            'frontier_id' => 'f-1',
            'owner_organ' => 'Task Fabric',
            'target_scope' => 'app/Demo',
            'capability_gap' => 'add Foo helper',
            'allowed_file_candidates' => ['app/Demo/Foo.php', 'tests/Unit/Demo/FooTest.php'],
            'acceptance_obligations' => ['phpunit green'],
            'evidence_obligations' => ['test_run_id'],
            'risk_class' => 'standard',
            'kind' => 'structural',
        ];
    }

    public function test_valid_frontier_is_accepted_with_normalized_fields(): void
    {
        $r = (new AtlasSelfConstructionNativeReplenisherFrontierContract)->normalize([$this->valid()]);
        $this->assertCount(1, $r['accepted']);
        $this->assertSame([], $r['rejected']);
        $this->assertSame('f-1', $r['accepted'][0]['frontier_id']);
    }

    public function test_duplicate_frontier_id_is_rejected(): void
    {
        $r = (new AtlasSelfConstructionNativeReplenisherFrontierContract)->normalize([
            $this->valid(),
            array_merge($this->valid(), ['allowed_file_candidates' => ['app/Demo/Bar.php']]),
        ]);
        $this->assertCount(1, $r['accepted']);
        $this->assertCount(1, $r['rejected']);
        $this->assertContains('duplicate_frontier_id', $r['rejected'][0]['blockers']);
    }

    public function test_cross_project_mixed_paths_are_rejected(): void
    {
        $f = $this->valid();
        $f['allowed_file_candidates'] = ['/repo/lane-a/app/Foo.php', '/repo/lane-b/app/Bar.php'];
        $r = (new AtlasSelfConstructionNativeReplenisherFrontierContract)->normalize([$f]);
        $this->assertContains('cross_project_mixed', $r['rejected'][0]['blockers']);
    }

    public function test_missing_target_scope_is_rejected(): void
    {
        $f = $this->valid();
        $f['target_scope'] = '';
        $r = (new AtlasSelfConstructionNativeReplenisherFrontierContract)->normalize([$f]);
        $this->assertContains('missing_target_scope', $r['rejected'][0]['blockers']);
    }

    public function test_proxy_only_kind_is_rejected(): void
    {
        $f = $this->valid();
        $f['kind'] = 'cyclomatic_shrink';
        $r = (new AtlasSelfConstructionNativeReplenisherFrontierContract)->normalize([$f]);
        $this->assertContains('proxy_only_kind', $r['rejected'][0]['blockers']);
    }

    public function test_missing_allowed_files_is_rejected(): void
    {
        $f = $this->valid();
        $f['allowed_file_candidates'] = [];
        $r = (new AtlasSelfConstructionNativeReplenisherFrontierContract)->normalize([$f]);
        $this->assertContains('missing_allowed_files', $r['rejected'][0]['blockers']);
    }

    public function test_missing_acceptance_is_rejected(): void
    {
        $f = $this->valid();
        $f['acceptance_obligations'] = [];
        $r = (new AtlasSelfConstructionNativeReplenisherFrontierContract)->normalize([$f]);
        $this->assertContains('missing_acceptance', $r['rejected'][0]['blockers']);
    }

    public function test_accepted_and_rejected_are_sorted_by_frontier_id(): void
    {
        $r = (new AtlasSelfConstructionNativeReplenisherFrontierContract)->normalize([
            array_merge($this->valid(), ['frontier_id' => 'zeta']),
            array_merge($this->valid(), ['frontier_id' => 'alpha']),
            array_merge($this->valid(), ['frontier_id' => 'mu', 'target_scope' => '']),
        ]);
        $this->assertSame(['alpha', 'zeta'], array_column($r['accepted'], 'frontier_id'));
        $this->assertSame(['mu'], array_column($r['rejected'], 'frontier_id'));
    }
}
