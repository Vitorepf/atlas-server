<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Replenishment;

use App\Services\Ai\SelfConstruction\Replenishment\AgentControlPlaneReplenishmentStableHasher;
use PHPUnit\Framework\TestCase;

/**
 * ITEM8 — proves the cohesive stable-hash canonicalization concern extracted from
 * AgentControlPlaneTaskAutoReplenishmentService into AgentControlPlaneReplenishmentStableHasher.
 *
 * Three methods migrated verbatim:
 *  - normalizeForHash: drop `generated_at` + `auto_replenishment_hash` (volatile identity fields),
 *    then deep ksort.
 *  - recursivelyKsort: deep ksort that preserves list vs assoc shape.
 *  - stableHash: SHA-256 over the canonical JSON.
 *
 * Pure / stateless / zero Laravel surface — pure PHPUnit suffices.
 */
final class AgentControlPlaneReplenishmentStableHasherTest extends TestCase
{
    private AgentControlPlaneReplenishmentStableHasher $hasher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hasher = new AgentControlPlaneReplenishmentStableHasher;
    }

    // --- recursivelyKsort -----------------------------------------------

    public function test_recursively_ksort_sorts_associative_arrays_by_key(): void
    {
        $out = $this->hasher->recursivelyKsort(['b' => 1, 'a' => 2]);

        $this->assertSame(['a' => 2, 'b' => 1], $out);
    }

    public function test_recursively_ksort_preserves_list_order(): void
    {
        $out = $this->hasher->recursivelyKsort(['x', 'y', 'z']);

        $this->assertSame(['x', 'y', 'z'], $out, 'lists must NOT be ksort-ed (order is semantic)');
    }

    public function test_recursively_ksort_descends_into_nested_assocs(): void
    {
        $out = $this->hasher->recursivelyKsort([
            'outer_b' => 1,
            'outer_a' => ['inner_b' => 1, 'inner_a' => 2],
        ]);

        $this->assertSame(['outer_a' => ['inner_a' => 2, 'inner_b' => 1], 'outer_b' => 1], $out);
    }

    public function test_recursively_ksort_descends_into_nested_lists(): void
    {
        // Inner list is a list, so its order is preserved verbatim even though the outer is assoc.
        $out = $this->hasher->recursivelyKsort([
            'outer_a' => [['k' => 2], ['k' => 1]],
        ]);

        $this->assertSame(['outer_a' => [['k' => 2], ['k' => 1]]], $out);
    }

    public function test_recursively_ksort_handles_empty_array(): void
    {
        $this->assertSame([], $this->hasher->recursivelyKsort([]));
    }

    // --- normalizeForHash ----------------------------------------------

    public function test_normalize_for_hash_strips_generated_at_and_auto_replenishment_hash(): void
    {
        $out = $this->hasher->normalizeForHash([
            'generated_at' => '2026-06-26T12:00:00+00:00',
            'auto_replenishment_hash' => 'abc',
            'kept' => 'kept-value',
            'b' => 1,
            'a' => 2,
        ]);

        $this->assertArrayNotHasKey('generated_at', $out);
        $this->assertArrayNotHasKey('auto_replenishment_hash', $out);
        $this->assertSame(['a' => 2, 'b' => 1, 'kept' => 'kept-value'], $out, 'remaining keys deep ksort-ed');
    }

    public function test_normalize_for_hash_is_byte_identical_to_other_normalize_for_hash(): void
    {
        // Two logically-equal payloads (different key order, different transient identity fields)
        // must produce the SAME normalized hash — that is the entire point of the helper.
        $a = $this->hasher->normalizeForHash(['z' => 1, 'a' => 2, 'generated_at' => 'T1']);
        $b = $this->hasher->normalizeForHash(['a' => 2, 'z' => 1, 'generated_at' => 'T2']);

        $this->assertSame(
            $this->hasher->stableHash($a),
            $this->hasher->stableHash($b),
            'normalizeForHash must be order-and-identity independent',
        );
    }

    // --- stableHash -------------------------------------------------------

    public function test_stable_hash_is_sha256_of_canonical_json(): void
    {
        $payload = ['a' => 1, 'b' => 2];

        $expected = hash('sha256', (string) json_encode(
            $payload,
            // recursivelyKsort is called internally so the hash matches a ksort'd JSON
            // (note: a and b are already in order here so the result is the same).
            ));

        $this->assertSame($expected, $this->hasher->stableHash($payload));
    }

    public function test_stable_hash_is_64_hex_characters(): void
    {
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/',
            $this->hasher->stableHash(['x' => 1]),
            'SHA-256 hex digest',
        );
    }

    public function test_stable_hash_produces_same_hash_for_key_swapped_assocs(): void
    {
        // After the internal recursivelyKsort, key order is irrelevant.
        $h1 = $this->hasher->stableHash(['a' => 1, 'b' => 2]);
        $h2 = $this->hasher->stableHash(['b' => 2, 'a' => 1]);

        $this->assertSame($h1, $h2);
    }

    public function test_stable_hash_differs_for_different_payloads(): void
    {
        $h1 = $this->hasher->stableHash(['a' => 1]);
        $h2 = $this->hasher->stableHash(['a' => 2]);

        $this->assertNotSame($h1, $h2);
    }

    public function test_stable_hash_preserves_list_order_semantically(): void
    {
        // Lists must NOT be ksort'd — order is part of the semantic payload.
        $h1 = $this->hasher->stableHash(['x', 'y', 'z']);
        $h2 = $this->hasher->stableHash(['z', 'y', 'x']);

        $this->assertNotSame($h1, $h2, 'list order must affect the hash');
    }

    // --- composition: normalizeForHash → stableHash is identity-independent

    public function test_normalize_then_hash_is_independent_of_transient_identity_fields(): void
    {
        $a = ['name' => 'fleet-A', 'count' => 3, 'generated_at' => 'T1', 'auto_replenishment_hash' => 'H1'];
        $b = ['name' => 'fleet-A', 'count' => 3, 'generated_at' => 'T2', 'auto_replenishment_hash' => 'H2'];

        $h1 = $this->hasher->stableHash($this->hasher->normalizeForHash($a));
        $h2 = $this->hasher->stableHash($this->hasher->normalizeForHash($b));

        $this->assertSame($h1, $h2, 'normalized hash must ignore generated_at + auto_replenishment_hash');
    }

    public function test_semantic_packet_fields_change_the_normalized_hash(): void
    {
        $base = [
            'objective' => 'implement feature X',
            'allowed_files' => ['app/Foo.php', 'app/Bar.php'],
            'acceptance_criteria' => ['tests pass', 'coverage >80'],
            'depends_on' => [],
            'generated_at' => '2026-06-30T00:00:00Z',
            'auto_replenishment_hash' => 'old-hash',
        ];

        $h0 = $this->hasher->stableHash($this->hasher->normalizeForHash($base));

        // Only volatile fields differ — hash must stay the same.
        $sameLogic = array_merge($base, ['generated_at' => 'T2', 'auto_replenishment_hash' => 'new-hash']);
        $this->assertSame($h0, $this->hasher->stableHash($this->hasher->normalizeForHash($sameLogic)));

        // Each semantic field individually changes the hash.
        foreach ([
            'objective' => 'implement feature Y',
            'allowed_files' => ['app/Foo.php'],
            'acceptance_criteria' => ['tests pass'],
            'depends_on' => ['task-1'],
        ] as $field => $changedValue) {
            $mutated = array_merge($base, [$field => $changedValue]);
            $this->assertNotSame(
                $h0,
                $this->hasher->stableHash($this->hasher->normalizeForHash($mutated)),
                "semantic field '{$field}' must change the normalized hash",
            );
        }
    }

    // ── AC: volatile field stripping extended ───────────────────────────────

    public function test_normalize_strips_timestamps(): void
    {
        foreach (['created_at', 'updated_at', 'resolved_at', 'leased_at', 'expires_at', 'ts', 'timestamp'] as $field) {
            $out = $this->hasher->normalizeForHash([$field => '2026-07-04T12:00:00Z', 'kept' => 'v']);
            $this->assertArrayNotHasKey($field, $out, "normalizeForHash must strip {$field}");
        }
    }

    public function test_normalize_strips_lease_and_process_ids(): void
    {
        foreach (['lease_id', 'process_id', 'pid', 'worker_id', 'session_id'] as $field) {
            $out = $this->hasher->normalizeForHash([$field => 'id-123', 'kept' => 'v']);
            $this->assertArrayNotHasKey($field, $out, "normalizeForHash must strip {$field}");
        }
    }

    public function test_normalize_strips_temp_paths(): void
    {
        foreach (['tmp_path', 'temp_dir', 'scratch_path'] as $field) {
            $out = $this->hasher->normalizeForHash([$field => '/tmp/foo', 'kept' => 'v']);
            $this->assertArrayNotHasKey($field, $out, "normalizeForHash must strip {$field}");
        }
    }

    public function test_normalize_strips_counters_and_runtime_ids(): void
    {
        foreach (['attempt_count', 'retry_count', 'sequence', 'run_id', 'trace_id', 'request_id', 'correlation_id'] as $field) {
            $out = $this->hasher->normalizeForHash([$field => 42, 'kept' => 'v']);
            $this->assertArrayNotHasKey($field, $out, "normalizeForHash must strip {$field}");
        }
    }

    public function test_stable_hash_ignores_all_volatile_fields(): void
    {
        $semantic = ['objective' => 'do X', 'allowed_files' => ['app/Foo.php']];
        $withVolatile = array_merge($semantic, [
            'generated_at' => 'T1',
            'created_at' => 'T2',
            'lease_id' => 'L1',
            'pid' => 12345,
            'tmp_path' => '/tmp/abc',
            'attempt_count' => 3,
            'trace_id' => 'tr-1',
        ]);

        $h1 = $this->hasher->stableHash($this->hasher->normalizeForHash($withVolatile));
        $h2 = $this->hasher->stableHash($this->hasher->normalizeForHash($semantic));

        $this->assertSame($h1, $h2, 'volatile fields must not affect the stable hash');
    }

    public function test_stable_hash_same_for_different_volatile_values(): void
    {
        $base = ['objective' => 'do X', 'allowed_files' => ['app/Foo.php']];
        $a = array_merge($base, ['lease_id' => 'L-A', 'pid' => 100, 'attempt_count' => 1]);
        $b = array_merge($base, ['lease_id' => 'L-B', 'pid' => 200, 'attempt_count' => 99]);

        $this->assertSame(
            $this->hasher->stableHash($this->hasher->normalizeForHash($a)),
            $this->hasher->stableHash($this->hasher->normalizeForHash($b)),
        );
    }

    // ── AC: task-shaping fields DO change the hash ──────────────────────────

    public function test_stable_hash_changes_when_required_evidence_changes(): void
    {
        $base = ['objective' => 'do X', 'allowed_files' => ['app/Foo.php'], 'required_evidence' => ['tests_or_gates_result']];
        $changed = array_merge($base, ['required_evidence' => ['tests_or_gates_result', 'implementation_notes']]);

        $this->assertNotSame(
            $this->hasher->stableHash($this->hasher->normalizeForHash($base)),
            $this->hasher->stableHash($this->hasher->normalizeForHash($changed)),
        );
    }

    public function test_stable_hash_changes_when_dependency_graph_changes(): void
    {
        $base = ['objective' => 'do X', 'allowed_files' => ['app/Foo.php'], 'depends_on' => []];
        $changed = array_merge($base, ['depends_on' => ['dep-1']]);

        $this->assertNotSame(
            $this->hasher->stableHash($this->hasher->normalizeForHash($base)),
            $this->hasher->stableHash($this->hasher->normalizeForHash($changed)),
        );
    }

    public function test_stable_hash_changes_when_scope_changes(): void
    {
        $base = ['objective' => 'do X', 'allowed_files' => ['app/Foo.php']];
        $changed = array_merge($base, ['allowed_files' => ['app/Foo.php', 'app/Bar.php']]);

        $this->assertNotSame(
            $this->hasher->stableHash($this->hasher->normalizeForHash($base)),
            $this->hasher->stableHash($this->hasher->normalizeForHash($changed)),
        );
    }

    public function test_stable_hash_changes_when_acceptance_changes(): void
    {
        $base = ['objective' => 'do X', 'acceptance_criteria' => ['phpunit passes']];
        $changed = array_merge($base, ['acceptance_criteria' => ['phpunit passes', 'coverage > 80%']]);

        $this->assertNotSame(
            $this->hasher->stableHash($this->hasher->normalizeForHash($base)),
            $this->hasher->stableHash($this->hasher->normalizeForHash($changed)),
        );
    }

    public function test_stable_hash_changes_when_objective_changes(): void
    {
        $base = ['objective' => 'do X', 'allowed_files' => ['app/Foo.php']];
        $changed = array_merge($base, ['objective' => 'do Y']);

        $this->assertNotSame(
            $this->hasher->stableHash($this->hasher->normalizeForHash($base)),
            $this->hasher->stableHash($this->hasher->normalizeForHash($changed)),
        );
    }

    public function test_stable_hash_preserves_evidence_in_hash(): void
    {
        // evidence is a semantic field — it must participate in the hash.
        $base = ['objective' => 'do X', 'evidence' => ['commit_sha' => 'abc']];
        $changed = array_merge($base, ['evidence' => ['commit_sha' => 'def']]);

        $this->assertNotSame(
            $this->hasher->stableHash($this->hasher->normalizeForHash($base)),
            $this->hasher->stableHash($this->hasher->normalizeForHash($changed)),
        );
    }
}
