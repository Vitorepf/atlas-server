<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskQueue;

use App\Services\Ai\SelfConstruction\TaskQueue\TaskPacketCanonicalizer;
use Tests\TestCase;

final class TaskPacketCanonicalizerTest extends TestCase
{
    private TaskPacketCanonicalizer $canon;

    protected function setUp(): void
    {
        parent::setUp();
        $this->canon = new TaskPacketCanonicalizer;
    }

    public function test_string_list_trims_and_drops_empties(): void
    {
        $result = $this->canon->stringList(['  foo  ', '', '  ', 'bar', '']);

        $this->assertSame(['foo', 'bar'], $result);
    }

    public function test_string_list_preserves_order(): void
    {
        $result = $this->canon->stringList(['z', 'a', 'b']);

        $this->assertSame(['z', 'a', 'b'], $result);
    }

    public function test_string_list_returns_indexed_array(): void
    {
        $result = $this->canon->stringList(['', 'foo', '']);

        $this->assertSame([0 => 'foo'], $result);
    }

    public function test_normalize_packet_strips_volatile_fields(): void
    {
        $packet = [
            'task_packet_id' => 'tid-123',
            'generated_at' => '2026-06-30T00:00:00Z',
            'task_packet_hash' => 'abc123',
            'human_summary' => 'some summary',
            'objective' => 'do something',
        ];

        $normalized = $this->canon->normalizePacketForHash($packet);

        $this->assertArrayNotHasKey('task_packet_id', $normalized);
        $this->assertArrayNotHasKey('generated_at', $normalized);
        $this->assertArrayNotHasKey('task_packet_hash', $normalized);
        $this->assertArrayNotHasKey('human_summary', $normalized);
        $this->assertArrayHasKey('objective', $normalized);
    }

    public function test_hash_changes_when_objective_changes(): void
    {
        $base = ['objective' => 'do A', 'allowed_files' => ['foo.php']];
        $changed = ['objective' => 'do B', 'allowed_files' => ['foo.php']];

        $this->assertNotSame(
            $this->canon->stableHash($this->canon->normalizePacketForHash($base)),
            $this->canon->stableHash($this->canon->normalizePacketForHash($changed)),
        );
    }

    public function test_hash_changes_when_allowed_files_change(): void
    {
        $base = ['objective' => 'do A', 'allowed_files' => ['foo.php']];
        $changed = ['objective' => 'do A', 'allowed_files' => ['bar.php']];

        $this->assertNotSame(
            $this->canon->stableHash($this->canon->normalizePacketForHash($base)),
            $this->canon->stableHash($this->canon->normalizePacketForHash($changed)),
        );
    }

    public function test_hash_changes_when_acceptance_criteria_change(): void
    {
        $base = ['objective' => 'x', 'acceptance_criteria' => ['passes tests']];
        $changed = ['objective' => 'x', 'acceptance_criteria' => ['passes tests', 'also does Y']];

        $this->assertNotSame(
            $this->canon->stableHash($this->canon->normalizePacketForHash($base)),
            $this->canon->stableHash($this->canon->normalizePacketForHash($changed)),
        );
    }

    public function test_hash_stable_across_volatile_field_differences(): void
    {
        $a = ['task_packet_id' => 'id-1', 'generated_at' => '2026-01-01', 'task_packet_hash' => 'h1', 'human_summary' => 's1', 'objective' => 'do A'];
        $b = ['task_packet_id' => 'id-2', 'generated_at' => '2026-06-30', 'task_packet_hash' => 'h2', 'human_summary' => 's2', 'objective' => 'do A'];

        $this->assertSame(
            $this->canon->stableHash($this->canon->normalizePacketForHash($a)),
            $this->canon->stableHash($this->canon->normalizePacketForHash($b)),
        );
    }

    public function test_stable_hash_is_hex64(): void
    {
        $hash = $this->canon->stableHash(['foo' => 'bar']);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    }

    public function test_stable_hash_is_deterministic(): void
    {
        $payload = ['objective' => 'do A', 'allowed_files' => ['foo.php']];

        $this->assertSame(
            $this->canon->stableHash($payload),
            $this->canon->stableHash($payload),
        );
    }

    public function test_recursively_ksort_sorts_assoc_not_lists(): void
    {
        $input = ['z' => 1, 'a' => ['c' => 3, 'b' => 2], 'list' => ['z', 'a', 'b']];
        $result = $this->canon->recursivelyKsort($input);

        $this->assertSame(['b', 'c'], array_keys($result['a']));
        $this->assertSame(['z', 'a', 'b'], $result['list']);
    }

    public function test_contract_matches_default_true_when_all_keys_match(): void
    {
        $current = ['a' => 1, 'b' => 2, 'extra' => 'x'];
        $default = ['a' => 1, 'b' => 2];

        $this->assertTrue($this->canon->contractMatchesDefault($current, $default));
    }

    public function test_contract_matches_default_false_when_key_missing(): void
    {
        $this->assertFalse($this->canon->contractMatchesDefault(['a' => 1], ['a' => 1, 'b' => 2]));
    }

    public function test_contract_matches_default_false_when_value_differs(): void
    {
        $this->assertFalse($this->canon->contractMatchesDefault(['a' => 2], ['a' => 1]));
    }

    // ── AC: unordered list sorting ───────────────────────────────────────────

    public function test_normalize_sorts_allowed_files_regardless_of_order(): void
    {
        $a = ['objective' => 'do X', 'allowed_files' => ['app/B.php', 'app/A.php']];
        $b = ['objective' => 'do X', 'allowed_files' => ['app/A.php', 'app/B.php']];

        $this->assertSame(
            $this->canon->stableHash($this->canon->normalizePacketForHash($a)),
            $this->canon->stableHash($this->canon->normalizePacketForHash($b)),
            'allowed_files is a set — order must not affect the hash',
        );
    }

    public function test_normalize_sorts_required_evidence_regardless_of_order(): void
    {
        $a = ['objective' => 'do X', 'required_evidence' => ['impl_notes', 'tests']];
        $b = ['objective' => 'do X', 'required_evidence' => ['tests', 'impl_notes']];

        $this->assertSame(
            $this->canon->stableHash($this->canon->normalizePacketForHash($a)),
            $this->canon->stableHash($this->canon->normalizePacketForHash($b)),
        );
    }

    public function test_normalize_preserves_acceptance_criteria_order(): void
    {
        // acceptance_criteria is ORDERED — different order means different task.
        $a = ['objective' => 'do X', 'acceptance_criteria' => ['first', 'second']];
        $b = ['objective' => 'do X', 'acceptance_criteria' => ['second', 'first']];

        $this->assertNotSame(
            $this->canon->stableHash($this->canon->normalizePacketForHash($a)),
            $this->canon->stableHash($this->canon->normalizePacketForHash($b)),
            'acceptance_criteria order is semantic — must affect the hash',
        );
    }

    public function test_normalize_preserves_dependency_order(): void
    {
        // depends_on is ORDERED — the dependency chain matters.
        $a = ['objective' => 'do X', 'depends_on' => ['dep-A', 'dep-B']];
        $b = ['objective' => 'do X', 'depends_on' => ['dep-B', 'dep-A']];

        $this->assertNotSame(
            $this->canon->stableHash($this->canon->normalizePacketForHash($a)),
            $this->canon->stableHash($this->canon->normalizePacketForHash($b)),
            'depends_on order is semantic — must affect the hash',
        );
    }

    // ── AC: extended volatile field stripping ────────────────────────────────

    public function test_normalize_strips_extended_volatile_fields(): void
    {
        $volatileFields = [
            'created_at', 'updated_at', 'resolved_at', 'lease_id', 'process_id',
            'pid', 'worker_id', 'session_id', 'attempt_count', 'retry_count',
            'sequence', 'run_id', 'trace_id', 'request_id', 'correlation_id',
        ];
        foreach ($volatileFields as $field) {
            $normalized = $this->canon->normalizePacketForHash([$field => 'volatile-value', 'kept' => 'v']);
            $this->assertArrayNotHasKey($field, $normalized, "normalizePacketForHash must strip {$field}");
        }
    }

    public function test_hash_stable_across_all_volatile_fields(): void
    {
        $base = ['objective' => 'do X', 'allowed_files' => ['app/Foo.php']];
        $withVolatile = array_merge($base, [
            'task_packet_id' => 'tp-1',
            'generated_at' => 'T1',
            'lease_id' => 'L1',
            'pid' => 12345,
            'attempt_count' => 3,
            'trace_id' => 'tr-1',
            'created_at' => 'T2',
            'worker_id' => 'w-1',
        ]);

        $this->assertSame(
            $this->canon->stableHash($this->canon->normalizePacketForHash($base)),
            $this->canon->stableHash($this->canon->normalizePacketForHash($withVolatile)),
        );
    }

    // ── AC: stableHash changes on task-shaping field changes ─────────────────

    public function test_hash_changes_when_required_evidence_changes(): void
    {
        $base = ['objective' => 'do X', 'required_evidence' => ['tests']];
        $changed = ['objective' => 'do X', 'required_evidence' => ['tests', 'impl_notes']];

        $this->assertNotSame(
            $this->canon->stableHash($this->canon->normalizePacketForHash($base)),
            $this->canon->stableHash($this->canon->normalizePacketForHash($changed)),
        );
    }

    public function test_hash_changes_when_dependencies_change(): void
    {
        $base = ['objective' => 'do X', 'depends_on' => []];
        $changed = ['objective' => 'do X', 'depends_on' => ['dep-1']];

        $this->assertNotSame(
            $this->canon->stableHash($this->canon->normalizePacketForHash($base)),
            $this->canon->stableHash($this->canon->normalizePacketForHash($changed)),
        );
    }

    public function test_hash_changes_when_scope_in_changes(): void
    {
        $base = ['objective' => 'do X', 'scope_in' => ['app/Foo.php']];
        $changed = ['objective' => 'do X', 'scope_in' => ['app/Foo.php', 'app/Bar.php']];

        $this->assertNotSame(
            $this->canon->stableHash($this->canon->normalizePacketForHash($base)),
            $this->canon->stableHash($this->canon->normalizePacketForHash($changed)),
        );
    }

    // ── AC: contractMatchesDefault distinguishes boilerplate from meaningful ─

    public function test_contract_matches_default_distinguishes_meaningful_change(): void
    {
        $default = ['risk_level' => 'low', 'auto_commit' => true];
        $meaningful = ['risk_level' => 'high', 'auto_commit' => true]; // risk changed

        $this->assertFalse($this->canon->contractMatchesDefault($meaningful, $default));
    }

    public function test_contract_matches_default_accepts_boilerplate_with_extras(): void
    {
        $default = ['risk_level' => 'low', 'auto_commit' => true];
        $boilerplate = ['risk_level' => 'low', 'auto_commit' => true, 'operator_note' => 'extra'];

        $this->assertTrue($this->canon->contractMatchesDefault($boilerplate, $default));
    }

    public function test_contract_matches_default_rejects_partial_match(): void
    {
        $default = ['a' => 1, 'b' => 2, 'c' => 3];
        $partial = ['a' => 1, 'b' => 2]; // missing c

        $this->assertFalse($this->canon->contractMatchesDefault($partial, $default));
    }

    public function test_contract_matches_default_false_when_nested_array_differs(): void
    {
        $default = ['config' => ['x' => 1]];
        $current = ['config' => ['x' => 2]];

        $this->assertFalse($this->canon->contractMatchesDefault($current, $default));
    }
}
