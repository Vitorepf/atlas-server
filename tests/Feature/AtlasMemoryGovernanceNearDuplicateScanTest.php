<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\MemoryGovernance\AtlasMemoryGovernanceService;
use App\Services\Ai\RuntimeBoundary\NearDuplicateRuntimeClient;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * WIRE-OBSERVE (Obra #7): proves AtlasMemoryGovernanceService::scan() now
 * surfaces the near-duplicate Jaccard clusters in the new `near_duplicates`
 * envelope field — real path (sqlite :memory: entries → scan() → detector →
 * Python runtime), not a mock. The paired rows are NEAR duplicates (one token
 * differs), so the pre-existing exact-hash `duplicates` detector must stay
 * empty while the new observe field catches the cluster — the exact gap the
 * wiring fills. Honest skip when the Python runtime is not set up (no PHP
 * fallback math exists by design).
 */
final class AtlasMemoryGovernanceNearDuplicateScanTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! (new NearDuplicateRuntimeClient)->available()) {
            $this->markTestSkipped('near_duplicate runtime not set up — honest skip (math is Python-only, no PHP fallback).');
        }

        Schema::dropIfExists('atlas_memory_entries');
        $migration = require database_path('migrations/2026_05_02_000000_create_atlas_memory_entries_table.php');
        $migration->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_memory_entries');

        parent::tearDown();
    }

    public function test_scan_reports_near_duplicates_observe_field_without_touching_exact_duplicates(): void
    {
        $sharedBody = 'atlas memory governance dedupes rows using token shingle jaccard similarity '
            .'computed inside the governed python runtime with deterministic canonical selection per cluster';

        $canonical = AtlasMemoryEntry::query()->create([
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'title' => 'Near duplicate canonical',
            'body' => $sharedBody.' always',
            'priority' => 90,
            'importance' => 5,
            'source_type' => 'manual',
        ]);
        $near = AtlasMemoryEntry::query()->create([
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'title' => 'Near duplicate variant',
            'body' => $sharedBody.' forever',
            'priority' => 50,
            'importance' => 3,
            'source_type' => 'manual',
        ]);
        AtlasMemoryEntry::query()->create([
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'title' => 'Unrelated memory',
            'body' => 'completely different topic about provider topology routing and forge governance receipts',
            'priority' => 40,
            'importance' => 2,
            'source_type' => 'manual',
        ]);

        $result = app(AtlasMemoryGovernanceService::class)->scan(['scope_type' => 'global'], dryRun: true);

        $this->assertSame(3, $result['scanned']);
        // The pair is NEAR, not exact — the pre-existing exact-hash detector must not fire.
        $this->assertSame([], $result['duplicates']);

        $nearDuplicates = $result['near_duplicates'];
        $this->assertIsArray($nearDuplicates);
        $this->assertSame('atlas.memory_governance.near_duplicate.v1', $nearDuplicates['schema_version']);
        $this->assertSame(3, $nearDuplicates['evaluated']);
        $this->assertSame(1, $nearDuplicates['cluster_count']);
        $this->assertSame(1, $nearDuplicates['duplicate_count']);

        $cluster = $nearDuplicates['clusters'][0];
        $this->assertEqualsCanonicalizing([$canonical->id, $near->id], $cluster['member_ids']);
        // Deterministic canonical: highest priority wins.
        $this->assertSame($canonical->id, $cluster['canonical_id']);
        $this->assertSame([$near->id], $cluster['merge_candidate_ids']);
        // Property band: near (>= default threshold 0.82) but strictly below exact.
        $this->assertGreaterThanOrEqual(0.82, $cluster['max_similarity']);
        $this->assertLessThan(1.0, $cluster['max_similarity']);
    }

    public function test_scan_near_duplicates_is_null_when_table_missing(): void
    {
        Schema::dropIfExists('atlas_memory_entries');

        $result = app(AtlasMemoryGovernanceService::class)->scan();

        $this->assertSame(0, $result['scanned']);
        $this->assertNull($result['near_duplicates']);
    }
}
