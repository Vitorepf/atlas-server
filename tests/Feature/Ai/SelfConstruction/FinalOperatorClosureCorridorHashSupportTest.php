<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\FinalOperatorClosureCorridorHashSupport;
use PHPUnit\Framework\TestCase;

/**
 * ITEM8 — proves the cohesive canonicalization/hashing utility cluster extracted from
 * {@see \App\Services\Ai\SelfConstruction\AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorService}
 * into FinalOperatorClosureCorridorHashSupport. Three methods migrated verbatim:
 *  - stableHash: SHA-256 of the canonical JSON after stripVolatileKeys + in-method unset +
 *    ksortRecursive.
 *  - stripVolatileKeys: recursively remove the eight volatile identity keys (7 listed + the
 *    `operator_submission_envelopes_hash` self-hash).
 *  - ksortRecursive: deep ksort that preserves list vs assoc shape.
 *
 * Pure / stateless / zero Laravel surface — pure PHPUnit suffices.
 */
final class FinalOperatorClosureCorridorHashSupportTest extends TestCase
{
    private FinalOperatorClosureCorridorHashSupport $hashSupport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hashSupport = new FinalOperatorClosureCorridorHashSupport;
    }

    // --- stripVolatileKeys ---------------------------------------------

    public function test_strip_volatile_keys_removes_eight_volatile_keys_at_top_level(): void
    {
        $payload = [
            'generated_at' => '2026-06-26T12:00:00+00:00',
            'audited_at' => '2026-06-26T12:00:01+00:00',
            'verified_at' => '2026-06-26T12:00:02+00:00',
            'certified_at' => '2026-06-26T12:00:03+00:00',
            'persisted_at' => '2026-06-26T12:00:04+00:00',
            'assessed_at' => '2026-06-26T12:00:05+00:00',
            'closure_corridor_hash' => 'corridor-hash',
            'operator_submission_envelopes_hash' => 'envelopes-hash',
            'kept' => 'kept-value',
        ];

        $out = $this->hashSupport->stripVolatileKeys($payload);

        $this->assertArrayNotHasKey('generated_at', $out);
        $this->assertArrayNotHasKey('audited_at', $out);
        $this->assertArrayNotHasKey('verified_at', $out);
        $this->assertArrayNotHasKey('certified_at', $out);
        $this->assertArrayNotHasKey('persisted_at', $out);
        $this->assertArrayNotHasKey('assessed_at', $out);
        $this->assertArrayNotHasKey('closure_corridor_hash', $out);
        $this->assertArrayNotHasKey('operator_submission_envelopes_hash', $out);
        $this->assertSame(['kept' => 'kept-value'], $out);
    }

    public function test_strip_volatile_keys_recurses_into_nested_arrays(): void
    {
        $payload = [
            'level_1' => [
                'generated_at' => 'should-be-stripped',
                'kept' => 'kept-1',
                'level_2' => [
                    'assessed_at' => 'should-be-stripped',
                    'kept' => 'kept-2',
                ],
            ],
        ];

        $out = $this->hashSupport->stripVolatileKeys($payload);

        $this->assertArrayNotHasKey('generated_at', $out['level_1']);
        $this->assertSame('kept-1', $out['level_1']['kept']);
        $this->assertArrayNotHasKey('assessed_at', $out['level_1']['level_2']);
        $this->assertSame('kept-2', $out['level_1']['level_2']['kept']);
    }

    public function test_strip_volatile_keys_does_not_mutate_input_array(): void
    {
        $payload = [
            'generated_at' => 'should-be-stripped',
            'kept' => 'kept-value',
        ];

        $out = $this->hashSupport->stripVolatileKeys($payload);

        $this->assertArrayHasKey('generated_at', $payload, 'input payload must NOT be mutated');
        $this->assertArrayNotHasKey('generated_at', $out, 'output must strip the volatile key');
    }

    public function test_strip_volatile_keys_returns_empty_array_for_empty_input(): void
    {
        $this->assertSame([], $this->hashSupport->stripVolatileKeys([]));
    }

    public function test_strip_volatile_keys_keeps_non_volatile_keys_intact(): void
    {
        $payload = [
            'generated_at' => 'stripped',
            'schema_version' => 'v1',
            'mode' => 'read_only',
            'status' => 'available',
            'count' => 42,
        ];

        $out = $this->hashSupport->stripVolatileKeys($payload);

        $this->assertSame('v1', $out['schema_version']);
        $this->assertSame('read_only', $out['mode']);
        $this->assertSame('available', $out['status']);
        $this->assertSame(42, $out['count']);
    }

    // --- ksortRecursive ------------------------------------------------

    public function test_ksort_recursive_sorts_associative_arrays_by_key(): void
    {
        $out = $this->hashSupport->ksortRecursive(['b' => 1, 'a' => 2]);

        $this->assertSame(['a' => 2, 'b' => 1], $out);
    }

    public function test_ksort_recursive_preserves_list_order(): void
    {
        $out = $this->hashSupport->ksortRecursive(['x', 'y', 'z']);

        $this->assertSame(['x', 'y', 'z'], $out, 'lists must NOT be ksort-ed (order is semantic)');
    }

    public function test_ksort_recursive_descends_into_nested_assocs(): void
    {
        $out = $this->hashSupport->ksortRecursive([
            'outer_b' => 1,
            'outer_a' => ['inner_b' => 1, 'inner_a' => 2],
        ]);

        $this->assertSame(['outer_a' => ['inner_a' => 2, 'inner_b' => 1], 'outer_b' => 1], $out);
    }

    public function test_ksort_recursive_descends_into_nested_lists(): void
    {
        $out = $this->hashSupport->ksortRecursive([
            'outer_a' => [['k' => 2], ['k' => 1]],
        ]);

        $this->assertSame(['outer_a' => [['k' => 2], ['k' => 1]]], $out);
    }

    public function test_ksort_recursive_handles_empty_array(): void
    {
        $this->assertSame([], $this->hashSupport->ksortRecursive([]));
    }

    public function test_ksort_recursive_is_stable_for_key_swapped_assocs(): void
    {
        $a = $this->hashSupport->ksortRecursive(['a' => 1, 'b' => 2, 'c' => 3]);
        $b = $this->hashSupport->ksortRecursive(['c' => 3, 'a' => 1, 'b' => 2]);

        $this->assertSame($a, $b, 'ksort result is order-independent of the input');
    }

    // --- stableHash -----------------------------------------------------

    public function test_stable_hash_is_64_hex_characters(): void
    {
        $hash = $this->hashSupport->stableHash(['x' => 1]);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash, 'SHA-256 hex digest');
    }

    public function test_stable_hash_is_deterministic_for_same_input(): void
    {
        $payload = ['a' => 1, 'b' => 2, 'c' => 3];

        $a = $this->hashSupport->stableHash($payload);
        $b = $this->hashSupport->stableHash($payload);

        $this->assertSame($a, $b);
    }

    public function test_stable_hash_ignores_volatile_keys_at_top_level(): void
    {
        // The two payloads differ ONLY in volatile keys (generated_at + closure_corridor_hash +
        // operator_submission_envelopes_hash) — the hash must be identical.
        $a = ['generated_at' => 'T1', 'closure_corridor_hash' => 'H1', 'operator_submission_envelopes_hash' => 'E1', 'kept' => 'x'];
        $b = ['generated_at' => 'T2', 'closure_corridor_hash' => 'H2', 'operator_submission_envelopes_hash' => 'E2', 'kept' => 'x'];

        $this->assertSame(
            $this->hashSupport->stableHash($a),
            $this->hashSupport->stableHash($b),
        );
    }

    public function test_stable_hash_ignores_volatile_keys_in_nested_arrays(): void
    {
        $a = [
            'nested' => [
                'verified_at' => 'T1',
                'kept' => 'x',
            ],
        ];
        $b = [
            'nested' => [
                'verified_at' => 'T2',
                'kept' => 'x',
            ],
        ];

        $this->assertSame(
            $this->hashSupport->stableHash($a),
            $this->hashSupport->stableHash($b),
        );
    }

    public function test_stable_hash_is_order_independent_for_key_swapped_assocs(): void
    {
        $h1 = $this->hashSupport->stableHash(['a' => 1, 'b' => 2]);
        $h2 = $this->hashSupport->stableHash(['b' => 2, 'a' => 1]);

        $this->assertSame($h1, $h2);
    }

    public function test_stable_hash_preserves_list_order_semantically(): void
    {
        $h1 = $this->hashSupport->stableHash(['x', 'y', 'z']);
        $h2 = $this->hashSupport->stableHash(['z', 'y', 'x']);

        $this->assertNotSame($h1, $h2, 'list order must affect the hash');
    }

    public function test_stable_hash_differs_for_different_payloads(): void
    {
        $h1 = $this->hashSupport->stableHash(['a' => 1]);
        $h2 = $this->hashSupport->stableHash(['a' => 2]);

        $this->assertNotSame($h1, $h2);
    }

    public function test_stable_hash_is_sha256_of_canonical_json(): void
    {
        // The hash should match the SHA-256 of `json_encode(stripVolatileKeys(ksortRecursive($payload)))`.
        $payload = ['a' => 1, 'b' => 2, 'generated_at' => 'T1'];
        $canonical = $this->hashSupport->ksortRecursive($this->hashSupport->stripVolatileKeys($payload));
        $expected = hash('sha256', (string) json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->assertSame($expected, $this->hashSupport->stableHash($payload));
    }

    public function test_stable_hash_handles_empty_payload(): void
    {
        // An empty payload is a valid (though degenerate) input — the hash must be a 64-hex string.
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/',
            $this->hashSupport->stableHash([]),
        );
    }
}
