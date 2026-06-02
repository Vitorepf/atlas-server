<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Gate;

use App\Services\Ai\Programming\AtlasDev\Gate\ScopeContractFeasibilityClassifier;
use Tests\TestCase;

final class ScopeContractFeasibilityClassifierTest extends TestCase
{
    public function test_glob_forbidden_pattern_swallowing_only_target_is_unsatisfiable(): void
    {
        $allowed = ['app/Services/Foo.php'];
        $forbidden = ['app/Services/*'];

        $result = (new ScopeContractFeasibilityClassifier())->classify($allowed, $forbidden, 0);

        // A naive array_intersect would NOT catch this (literal strings differ);
        // the glob via fnmatch does, proving the false-negative is closed.
        self::assertSame([], array_values(array_intersect($allowed, $forbidden)));
        self::assertSame('unsatisfiable', $result['verdict']);
        self::assertSame(['app/Services/Foo.php'], $result['dead_allowed_files']);
        self::assertSame(0, $result['satisfiable_allowed_count']);
        self::assertSame('atlas.programming.scope_contract_feasibility.v1', $result['schema_version']);
    }

    public function test_prefix_forbidden_catching_some_targets_is_degraded(): void
    {
        $allowed = ['app/Services/Foo.php', 'app/Models/Bar.php'];
        $forbidden = ['app/Services/'];

        $result = (new ScopeContractFeasibilityClassifier())->classify($allowed, $forbidden, 0);

        // array_intersect misses the prefix rule; str_starts_with catches it.
        self::assertSame([], array_values(array_intersect($allowed, $forbidden)));
        self::assertSame('degraded', $result['verdict']);
        self::assertSame(['app/Services/Foo.php'], $result['dead_allowed_files']);
        self::assertSame(1, $result['satisfiable_allowed_count']);
    }

    public function test_satisfiable_count_above_cap_is_degraded(): void
    {
        $allowed = ['a.php', 'b.php', 'c.php'];

        $result = (new ScopeContractFeasibilityClassifier())->classify($allowed, [], 2);

        self::assertSame('degraded', $result['verdict']);
        self::assertSame(3, $result['satisfiable_allowed_count']);
        self::assertSame([], $result['dead_allowed_files']);
    }

    public function test_non_overlapping_forbidden_keeps_contract_feasible(): void
    {
        $result = (new ScopeContractFeasibilityClassifier())->classify(['app/X.php'], ['app/Other.php'], 0);

        self::assertSame('feasible', $result['verdict']);
        self::assertSame([], $result['dead_allowed_files']);
        self::assertSame(1, $result['satisfiable_allowed_count']);
    }

    public function test_no_allowed_target_is_unsatisfiable(): void
    {
        $result = (new ScopeContractFeasibilityClassifier())->classify([], ['x'], 0);

        self::assertSame('unsatisfiable', $result['verdict']);
        self::assertSame([], $result['dead_allowed_files']);
        self::assertSame(0, $result['satisfiable_allowed_count']);
    }

    public function test_dead_allowed_files_sorted_as_strings_not_numerically(): void
    {
        // Numeric-looking file paths all caught by the '*' glob. Under SORT_REGULAR
        // these would collapse to numeric order ['2','9','10','app/Foo.php']; the
        // declared list<string> "sorted asc" contract requires lexicographic order.
        $allowed = ['10', '9', '2', 'app/Foo.php'];

        $result = (new ScopeContractFeasibilityClassifier())->classify($allowed, ['*'], 0);

        self::assertSame('unsatisfiable', $result['verdict']);
        self::assertSame(['10', '2', '9', 'app/Foo.php'], $result['dead_allowed_files']);
        self::assertSame([0, 1, 2, 3], array_keys($result['dead_allowed_files']));
        foreach ($result['dead_allowed_files'] as $file) {
            self::assertIsString($file);
        }
    }
}
