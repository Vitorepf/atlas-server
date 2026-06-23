<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * PART 2 — the REAL-concurrency proof of conflict-free serving. Unlike the A7 contract test (two sequential
 * `next` calls in one process), this drives the `atlas:task:swarm-proof` harness, which spawns N independent
 * `php artisan` worker processes that all fire `next` at one wall-clock barrier against ONE isolated storage
 * root — genuine OS-level flock contention, the production shape (N AIs pulling at once).
 *
 * If A1 (flock) / A2 (CAS) / the lease conflict-rejection were wrong, this would surface a double-claim or a
 * held-overlap. The frozen X-ray asserts none occur and the conflict rejection actually fires.
 */
final class AtlasTaskSwarmProofConcurrencyTest extends TestCase
{
    public function test_conflict_free_under_real_concurrency_mixed_scenario(): void
    {
        // 6 concurrent client processes × 2 rounds, with a write-set-colliding pair to stress conflict rejection.
        $exit = Artisan::call('atlas:task:swarm-proof', [
            '--clients' => 6,
            '--rounds' => 2,
            '--scenario' => 'mixed',
            '--lead' => 2.0,
            '--json' => true,
        ]);

        $xray = json_decode(Artisan::output(), true);
        $this->assertIsArray($xray, 'the harness emits a JSON X-ray');

        // Clean up the artifact the coordinator persisted.
        if (is_string($xray['artifact'] ?? null) && is_file($xray['artifact'])) {
            @unlink($xray['artifact']);
        }

        $this->assertSame(0, $exit, 'the swarm proof PASSES (exit 0) — conflict-free under real concurrency');
        $this->assertTrue($xray['passed']);
        $this->assertTrue($xray['conflict_free']);
        $this->assertSame([], $xray['double_claims'], 'no packet is ever served to two clients (A1/A2 hold under contention)');
        $this->assertSame([], $xray['held_overlaps'], 'two concurrently-held packets never have overlapping write-sets');
        $this->assertSame([], $xray['r2_breaches'], 'every client process delivers an honest envelope (R2)');
        $this->assertSame([], $xray['phantom_serves']);

        // mixed: 3 disjoint + exactly ONE of the colliding pair = 4 served per round. The colliding pair being
        // capped at one proves the lease conflict-rejection actually fired under contention (else it'd be 5).
        $this->assertSame(8, $xray['totals']['served'], '4 served/round × 2 rounds — the colliding pair was serialized');
        $this->assertGreaterThanOrEqual(12, $xray['totals']['observations'], '6 clients × 2 rounds of real processes ran');
    }
}
