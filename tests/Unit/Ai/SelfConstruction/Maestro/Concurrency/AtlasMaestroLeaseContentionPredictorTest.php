<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Concurrency;

use App\Services\Ai\SelfConstruction\Maestro\Concurrency\AtlasMaestroLeaseContentionPredictor;
use PHPUnit\Framework\TestCase;

final class AtlasMaestroLeaseContentionPredictorTest extends TestCase
{
    private function predictor(): AtlasMaestroLeaseContentionPredictor
    {
        return new AtlasMaestroLeaseContentionPredictor;
    }

    private function worker(string $id, array $writeSet = []): array
    {
        return ['id' => $id, 'write_set' => $writeSet];
    }

    private function failure(string $path, string $worker = 'w1'): array
    {
        return ['path' => $path, 'worker_id' => $worker, 'reason' => 'lock_conflict'];
    }

    // ── AC1: output shape ─────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->predictor()->predict([]);
        $this->assertSame(AtlasMaestroLeaseContentionPredictor::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('contention_risk', $r);
        $this->assertArrayHasKey('most_contended_paths', $r);
        $this->assertArrayHasKey('mitigation_hints', $r);
        $this->assertArrayHasKey('is_healthy_parallelism', $r);
        $this->assertArrayHasKey('diagnostics', $r);
    }

    public function test_empty_input_emits_low_risk(): void
    {
        $r = $this->predictor()->predict([]);
        $this->assertSame('low', $r['contention_risk']);
        $this->assertEmpty($r['most_contended_paths']);
        $this->assertEmpty($r['mitigation_hints']);
    }

    public function test_diagnostics_carries_worker_count_and_overlap(): void
    {
        $r = $this->predictor()->predict([
            'worker_snapshot' => [
                $this->worker('w1', ['app/Foo.php']),
                $this->worker('w2', ['app/Bar.php']),
            ],
        ]);

        $this->assertSame(2, $r['diagnostics']['active_workers']);
        $this->assertSame(1, $r['diagnostics']['max_path_overlap']);
    }

    // ── AC1 + AC2: contention risk tiers ─────────────────────────────────────

    public function test_no_overlap_is_low_risk(): void
    {
        $r = $this->predictor()->predict([
            'worker_snapshot' => [
                $this->worker('w1', ['app/A.php']),
                $this->worker('w2', ['app/B.php']),
                $this->worker('w3', ['app/C.php']),
            ],
        ]);

        $this->assertSame('low', $r['contention_risk']);
    }

    public function test_two_workers_same_path_is_medium_risk(): void
    {
        $r = $this->predictor()->predict([
            'worker_snapshot' => [
                $this->worker('w1', ['app/Shared.php']),
                $this->worker('w2', ['app/Shared.php']),
            ],
        ]);

        $this->assertSame('medium', $r['contention_risk']);
        $this->assertContains('app/Shared.php', $r['most_contended_paths']);
    }

    public function test_three_workers_same_path_is_high_risk(): void
    {
        $r = $this->predictor()->predict([
            'worker_snapshot' => [
                $this->worker('w1', ['app/Hot.php']),
                $this->worker('w2', ['app/Hot.php']),
                $this->worker('w3', ['app/Hot.php']),
            ],
        ]);

        $this->assertSame('high', $r['contention_risk']);
        $this->assertContains('serialize_access_to_hot_paths', $r['mitigation_hints']);
    }

    public function test_high_commit_failure_rate_escalates_to_high_risk(): void
    {
        // 2 workers, 1 failure → rate = 0.5 → high risk
        $r = $this->predictor()->predict([
            'worker_snapshot'         => [$this->worker('w1'), $this->worker('w2')],
            'commit_failure_snapshot' => [$this->failure('app/Foo.php')],
        ]);

        $this->assertSame('high', $r['contention_risk']);
    }

    public function test_medium_commit_failure_rate_is_medium_risk(): void
    {
        // 5 workers, 1 failure → rate = 0.2 → medium risk
        $workers = array_map(fn (int $i) => $this->worker("w$i"), range(1, 5));
        $r       = $this->predictor()->predict([
            'worker_snapshot'         => $workers,
            'commit_failure_snapshot' => [$this->failure('app/Foo.php')],
        ]);

        $this->assertSame('medium', $r['contention_risk']);
    }

    // ── AC2: healthy parallelism vs risky contention ──────────────────────────

    public function test_many_workers_no_overlap_is_healthy_parallelism(): void
    {
        $workers = array_map(fn (int $i) => $this->worker("w$i", ["app/File{$i}.php"]), range(1, 5));
        $r       = $this->predictor()->predict(['worker_snapshot' => $workers]);

        $this->assertTrue($r['is_healthy_parallelism']);
        $this->assertSame('low', $r['contention_risk']);
    }

    public function test_overlapping_write_sets_not_healthy_parallelism(): void
    {
        $r = $this->predictor()->predict([
            'worker_snapshot' => [
                $this->worker('w1', ['app/Shared.php']),
                $this->worker('w2', ['app/Shared.php']),
            ],
        ]);

        $this->assertFalse($r['is_healthy_parallelism']);
    }

    public function test_most_contended_paths_sorted_by_overlap_desc(): void
    {
        $r = $this->predictor()->predict([
            'worker_snapshot' => [
                $this->worker('w1', ['app/Hot.php', 'app/Warm.php']),
                $this->worker('w2', ['app/Hot.php', 'app/Warm.php']),
                $this->worker('w3', ['app/Hot.php']),
            ],
        ]);

        $paths = $r['most_contended_paths'];
        // app/Hot.php has 3 workers; app/Warm.php has 2 → Hot first.
        $this->assertSame('app/Hot.php', $paths[0]);
    }

    public function test_commit_failures_add_retry_hint_at_medium_risk(): void
    {
        // 3 workers, 2 share app/X.php (overlap=2 → medium), 1 failure → rate=0.33 (medium).
        $r = $this->predictor()->predict([
            'worker_snapshot'         => [
                $this->worker('w1', ['app/X.php']),
                $this->worker('w2', ['app/X.php']),
                $this->worker('w3', ['app/Unique.php']),
            ],
            'commit_failure_snapshot' => [$this->failure('app/X.php', 'w2')],
        ]);

        $this->assertSame('medium', $r['contention_risk']);
        // No serialize hint at medium, but retry hint present.
        $this->assertNotContains('serialize_access_to_hot_paths', $r['mitigation_hints']);
        $this->assertContains('add_exponential_retry_on_lock_conflict', $r['mitigation_hints']);
    }

    public function test_output_is_deterministic(): void
    {
        $facts = [
            'worker_snapshot' => [
                $this->worker('w1', ['app/A.php', 'app/B.php']),
                $this->worker('w2', ['app/B.php', 'app/C.php']),
            ],
            'commit_failure_snapshot' => [$this->failure('app/B.php', 'w2')],
        ];
        $a = $this->predictor()->predict($facts);
        $b = $this->predictor()->predict($facts);
        $this->assertSame(json_encode($a), json_encode($b));
    }
}
