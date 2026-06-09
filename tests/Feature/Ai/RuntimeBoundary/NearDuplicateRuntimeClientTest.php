<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\RuntimeBoundary;

use App\Services\Ai\RuntimeBoundary\NearDuplicateRuntimeClient;
use RuntimeException;
use Tests\TestCase;

/**
 * Proves the PHP kernel really invokes the Python near-duplicate runtime and gets
 * REAL numpy clustering back through the boundary — not a PHP hand-rolled stand-in.
 * Gated on the runtime being set up (scripts/setup-near-duplicate-runtime.sh); when
 * absent the e2e test skips honestly and the anti-fallback test asserts an explicit
 * failure (the boundary R7.4 enforced: no silent PHP near-duplicate math).
 *
 * The e2e test also pins the OLD-vs-NEW equivalence: the known-answer values below
 * are exactly what the removed hand-rolled PHP MemoryNearDuplicateDetector produced
 * on the same fixtures (captured before removal), so this is the boundary-level
 * old-vs-new proof — the Python output must be behaviour-identical to the deleted
 * PHP, not merely "some clustering".
 */
final class NearDuplicateRuntimeClientTest extends TestCase
{
    public function test_php_invokes_real_python_near_duplicate_end_to_end(): void
    {
        $client = new NearDuplicateRuntimeClient;
        if (! $client->available()) {
            $this->markTestSkipped('near_duplicate runtime not set up — honest skip (not a fake).');
        }

        // Transitive-closure chain A~B~C (A~C below threshold). Known answer the old
        // PHP produced: one cluster {A,B,C}, canonical A, pairs A-B & B-C only.
        $result = $client->detect([
            ['id' => 'A', 'tokens' => ['a', 'b', 'c', 'd'], 'memory_type' => 'decision', 'scope' => 'global', 'priority' => 1, 'importance' => 1, 'recency' => 1],
            ['id' => 'B', 'tokens' => ['a', 'b', 'c', 'x'], 'memory_type' => 'decision', 'scope' => 'global', 'priority' => 1, 'importance' => 1, 'recency' => 1],
            ['id' => 'C', 'tokens' => ['y', 'b', 'c', 'x'], 'memory_type' => 'decision', 'scope' => 'global', 'priority' => 1, 'importance' => 1, 'recency' => 1],
        ], 0.5);

        $this->assertSame('atlas.memory_governance.near_duplicate.v1', $result['schema_version']);
        $this->assertSame(1, $result['cluster_count']);
        $this->assertSame(['A', 'B', 'C'], $result['clusters'][0]['member_ids']);
        $this->assertSame('A', $result['clusters'][0]['canonical_id']);
        $this->assertSame(0.5, $result['clusters'][0]['max_similarity']);
        $this->assertCount(2, $result['clusters'][0]['pairs']);

        // Identical rows -> Jaccard exactly 1.0 (a hash/stub of distinct ids would
        // never emit 1.0 across distinct ids); proves real set-Jaccard.
        $identical = $client->detect([
            ['id' => 'x', 'tokens' => ['p', 'q', 'r', 's'], 'memory_type' => 'd', 'scope' => 'g', 'priority' => 1, 'importance' => 1, 'recency' => 1],
            ['id' => 'y', 'tokens' => ['p', 'q', 'r', 's'], 'memory_type' => 'd', 'scope' => 'g', 'priority' => 1, 'importance' => 1, 'recency' => 1],
        ], 0.5);
        $this->assertSame(1.0, $identical['clusters'][0]['max_similarity']);
        $this->assertIsFloat($identical['clusters'][0]['max_similarity']);

        // The boundary receipt is verified + stripped by the client.
        $this->assertArrayNotHasKey('boundary', $result);
    }

    public function test_result_omits_below_threshold_and_isolates_scope(): void
    {
        $client = new NearDuplicateRuntimeClient;
        if (! $client->available()) {
            $this->markTestSkipped('near_duplicate runtime not set up — honest skip (not a fake).');
        }

        // Identical token lists but different (memory_type, scope) must NOT group;
        // empty/blank token rows are skipped (evaluated counts only the real rows).
        $result = $client->detect([
            ['id' => 'x1', 'tokens' => ['a', 'b', 'c', 'd', 'e'], 'memory_type' => 'decision', 'scope' => 'global', 'priority' => 1, 'importance' => 1, 'recency' => 1],
            ['id' => 'x2', 'tokens' => ['a', 'b', 'c', 'd', 'e'], 'memory_type' => 'learning', 'scope' => 'global', 'priority' => 1, 'importance' => 1, 'recency' => 1],
            ['id' => 'blank', 'tokens' => ['   ', ''], 'memory_type' => 'decision', 'scope' => 'global', 'priority' => 1, 'importance' => 1, 'recency' => 1],
        ], 0.5);

        $this->assertSame(2, $result['evaluated']);
        $this->assertSame(0, $result['cluster_count']);
        $this->assertSame(0, $result['duplicate_count']);
    }

    public function test_runtime_absence_fails_explicitly_never_a_silent_fake(): void
    {
        $client = new NearDuplicateRuntimeClient;
        if ($client->available()) {
            // Runtime present: the e2e test covers the anti-fake boundary guard.
            $this->assertTrue(true);

            return;
        }
        $this->expectException(RuntimeException::class);
        $client->detect([
            ['id' => 'a', 'tokens' => ['x', 'y'], 'memory_type' => 'd', 'scope' => 'g'],
        ], 0.5);
    }
}
