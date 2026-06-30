<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\LiveCycle\Nesting\AtlasLoopSubCycleResultMerger;
use App\Services\Ai\AutonomousEvolution\LiveCycle\Nesting\AtlasLoopSubCycleSpawner;
use App\Services\Ai\AutonomousEvolution\LiveCycle\Nesting\ParentMergeRecord;
use App\Services\Ai\AutonomousEvolution\LiveCycle\Nesting\RejectionRecord;
use App\Services\Ai\AutonomousEvolution\LiveCycle\Nesting\SubCycleSpawnRecord;
use Tests\TestCase;

// PSR-4 one-class-per-file: force load of files that declare multiple classes.
\class_exists(AtlasLoopSubCycleSpawner::class);
\class_exists(AtlasLoopSubCycleResultMerger::class);

class AtlasLoopSubCycleResultMergerTest extends TestCase
{
    private function parent(): SubCycleSpawnRecord
    {
        return SubCycleSpawnRecord::granted(
            parentCycleId: 'cyc-A',
            parentPhase: 'ARCHITECT',
            childCycleId: 'cyc-A|ARCHITECT|0',
            depth: 1,
            spawnedAt: '2026-06-25T00:00:00Z',
            scopeDigest: 'sha256:deadbeef',
        );
    }

    public function test_happy_path_returns_parent_merge_record_with_sorted_keys(): void
    {
        $merger = new AtlasLoopSubCycleResultMerger(clock: fn (): string => '2026-06-25T00:00:00Z');
        $result = $merger->merge($this->parent(), [
            'gate_g_failed_for' => ['alternative-Z'],
            'alternative_X_cost' => 13,
            'observed_outcome' => 'baseline-preserved',
        ]);

        self::assertInstanceOf(ParentMergeRecord::class, $result);
        self::assertSame(3, $result->factCount);
        self::assertSame(['alternative_X_cost', 'gate_g_failed_for', 'observed_outcome'], $result->factKeysSorted);
        self::assertSame('cyc-A', $result->parentCycleId);
        self::assertSame('cyc-A|ARCHITECT|0', $result->childCycleId);
    }

    public function test_forbidden_scalar_keys_are_rejected_fail_closed(): void
    {
        $merger = new AtlasLoopSubCycleResultMerger();
        $result = $merger->merge($this->parent(), [
            'alternative_X_cost' => 13,
            'score' => 0.9,
            'grade' => 'A',
        ]);

        self::assertInstanceOf(RejectionRecord::class, $result);
        self::assertSame(['grade', 'score'], $result->forbiddenKeys);
        self::assertSame('forbidden_scalar_keys_present', $result->reason);
    }

    public function test_each_forbidden_key_individually_triggers_rejection(): void
    {
        $merger = new AtlasLoopSubCycleResultMerger();
        foreach (AtlasLoopSubCycleResultMerger::FORBIDDEN_SCALAR_KEYS as $forbidden) {
            $result = $merger->merge($this->parent(), [$forbidden => 'whatever']);
            self::assertInstanceOf(RejectionRecord::class, $result, "expected rejection for '$forbidden'");
            self::assertContains($forbidden, $result->forbiddenKeys);
        }
    }

    public function test_forbidden_keys_const_exists_and_matches_acceptance_list(): void
    {
        $expected = ['score', 'grade', 'rank', 'rating', 'quality_score'];
        foreach ($expected as $k) {
            self::assertContains($k, AtlasLoopSubCycleResultMerger::FORBIDDEN_SCALAR_KEYS);
        }
    }

    public function test_nested_forbidden_scalar_is_rejected(): void
    {
        // 'score' is nested one level deep — the floor must catch it recursively
        $merger = new AtlasLoopSubCycleResultMerger();
        $result = $merger->merge($this->parent(), ['metrics' => ['score' => 9.5, 'count' => 3]]);

        self::assertInstanceOf(RejectionRecord::class, $result);
        self::assertSame('forbidden_scalar_keys_present', $result->reason);
        self::assertContains('score', $result->forbiddenKeys);
    }

    public function test_idempotent_merge_returns_byte_identical_record(): void
    {
        $merger = new AtlasLoopSubCycleResultMerger(clock: fn (): string => '2026-06-25T00:00:00Z');
        $outcome = ['alpha' => 1, 'beta' => ['x', 'y']];

        $a = $merger->merge($this->parent(), $outcome);
        $b = $merger->merge($this->parent(), $outcome);

        self::assertInstanceOf(ParentMergeRecord::class, $a);
        self::assertInstanceOf(ParentMergeRecord::class, $b);
        self::assertSame(json_encode($a->toArray()), json_encode($b->toArray()));
    }

    public function test_no_ledger_dependency_unit_test_runs_pure(): void
    {
        // The merger's constructor takes only an optional clock; no I/O, no ledger.
        $merger = new AtlasLoopSubCycleResultMerger();
        $result = $merger->merge($this->parent(), ['ok' => true]);
        self::assertInstanceOf(ParentMergeRecord::class, $result);
    }

    public function test_container_resolves_merger_as_singleton(): void
    {
        $a = app(AtlasLoopSubCycleResultMerger::class);
        $b = app(AtlasLoopSubCycleResultMerger::class);
        self::assertSame($a, $b);
    }
}
