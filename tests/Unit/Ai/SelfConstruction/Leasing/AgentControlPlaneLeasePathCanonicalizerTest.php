<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Leasing;

use App\Services\Ai\SelfConstruction\Leasing\AgentControlPlaneLeasePathCanonicalizer;
use PHPUnit\Framework\TestCase;

final class AgentControlPlaneLeasePathCanonicalizerTest extends TestCase
{
    private AgentControlPlaneLeasePathCanonicalizer $canonicalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->canonicalizer = new AgentControlPlaneLeasePathCanonicalizer();
    }

    public function test_canonicalize_lease_id(): void
    {
        $this->assertSame('lease_abc-123', $this->canonicalizer->canonicalizeLeaseId('Lease_ABC-123'));
    }

    public function test_canonicalize_agent_id(): void
    {
        $this->assertSame('worker-1', $this->canonicalizer->canonicalizeAgentId(' Worker-1 '));
    }

    public function test_canonicalize_task_prefix(): void
    {
        $this->assertSame('brain/foo/', $this->canonicalizer->canonicalizeTaskPrefix('Brain/Foo'));
    }

    public function test_canonicalize_path(): void
    {
        $this->assertSame('app/Services/Foo', $this->canonicalizer->canonicalizePath('app\\Services\\Foo/'));
        $this->assertSame('app/Services/Foo', $this->canonicalizer->canonicalizePath('app//Services//Foo'));
    }

    // AC: safe match
    public function test_safe_match_exact(): void
    {
        $this->assertTrue($this->canonicalizer->safeMatch('app/Services/X.php', 'app/Services/X.php'));
    }

    public function test_safe_match_prefix(): void
    {
        $this->assertTrue($this->canonicalizer->safeMatch('app/Services', 'app/Services/Foo.php'));
    }

    // AC: unrelated mismatch
    public function test_safe_match_unrelated(): void
    {
        $this->assertFalse($this->canonicalizer->safeMatch('app/Services', 'app/Console/Foo.php'));
    }

    public function test_safe_match_partial_segment_does_not_match(): void
    {
        // "app/Ser" should NOT match "app/Services" (partial segment boundary)
        $this->assertFalse($this->canonicalizer->safeMatch('app/Ser', 'app/Services/Foo.php'));
    }

    // AC: broad-empty rejection
    public function test_safe_match_empty_filter_rejected(): void
    {
        $this->assertFalse($this->canonicalizer->safeMatch('', 'app/Services/Foo.php'));
    }

    public function test_safe_match_root_filter_rejected(): void
    {
        $this->assertFalse($this->canonicalizer->safeMatch('/', 'app/Services/Foo.php'));
    }

    // AC: pruneFilter
    public function test_prune_filter_matches_only_relevant(): void
    {
        $matched = $this->canonicalizer->pruneFilter('app/Services', [
            'app/Services/A.php',
            'app/Console/B.php',
            'app/Services/C.php',
            'tests/D.php',
        ]);

        $this->assertCount(2, $matched);
        $this->assertContains('app/Services/A.php', $matched);
        $this->assertContains('app/Services/C.php', $matched);
    }

    public function test_prune_filter_empty_filter_returns_empty(): void
    {
        $matched = $this->canonicalizer->pruneFilter('', ['app/X.php']);

        $this->assertEmpty($matched);
    }

    // AC: stable lease path
    public function test_lease_path_is_stable_for_equivalent_ids(): void
    {
        $this->assertSame(
            $this->canonicalizer->leasePath('Lease_ABC-123'),
            $this->canonicalizer->leasePath(' lease_abc-123 '),
        );
        // Single-source invariant: leasePath MUST live under the repository's STORAGE_PREFIX —
        // a divergent dir here silently breaks registry rebuild and prune (conflicts lost).
        $this->assertSame(
            \App\Services\Ai\SelfConstruction\AgentControlPlaneClaimLeaseRepository::STORAGE_PREFIX.'/lease_abc-123.json',
            $this->canonicalizer->leasePath('Lease_ABC-123'),
        );
    }

    public function test_lease_entry_matches_prune_filters_safe_match(): void
    {
        $entry = ['lease_id' => 'lease_abc-1', 'agent_id' => 'worker-1', 'task_prefix' => 'brain/foo'];

        $this->assertTrue($this->canonicalizer->leaseEntryMatchesPruneFilters($entry, ['brain/foo'], [], []));
    }

    public function test_lease_entry_matches_prune_filters_unrelated_mismatch(): void
    {
        $entry = ['lease_id' => 'lease_abc-1', 'agent_id' => 'worker-1', 'task_prefix' => 'brain/foo'];

        $this->assertFalse($this->canonicalizer->leaseEntryMatchesPruneFilters($entry, ['brain/bar'], ['worker-2'], ['lease_xyz']));
    }

    public function test_lease_entry_matches_prune_filters_broad_empty_rejected(): void
    {
        $entry = ['lease_id' => 'lease_abc-1', 'agent_id' => 'worker-1', 'task_prefix' => 'brain/foo'];

        $this->assertFalse($this->canonicalizer->leaseEntryMatchesPruneFilters($entry, [''], [''], ['']));
    }
}
