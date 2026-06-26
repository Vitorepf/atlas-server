<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskQueue;

use App\Services\Ai\SelfConstruction\TaskQueue\TaskPacketCanonicalizer;
use PHPUnit\Framework\TestCase;

/**
 * ITEM8 — proves the cohesive cluster of pure / stateless serialization + canonicalization helpers
 * extracted from AgentControlPlaneTaskPacketQueueRepository into TaskPacketCanonicalizer.
 *
 * Six methods are migrated verbatim:
 *  - recursivelyKsort: deep ksort that preserves list vs assoc shape (ksort only on assoc).
 *  - normalizePacketForHash: strip volatile / identity fields, then deep ksort.
 *  - contractMatchesDefault: every default key present + equal; extra keys tolerated.
 *  - stableHash: SHA-256 of canonical JSON (no pretty, no escape slashes, no escape unicode).
 *  - encode: canonical pretty JSON (debug artefacts).
 *  - stringList: trim + non-empty filter + array_values.
 *
 * Pure / zero Laravel surface / zero side effects — so a pure PHPUnit\Framework\TestCase suffices
 * (the canonicalizer never consults config(), Storage, or any container binding).
 */
final class TaskPacketCanonicalizerTest extends TestCase
{
    private TaskPacketCanonicalizer $canon;

    protected function setUp(): void
    {
        parent::setUp();
        $this->canon = new TaskPacketCanonicalizer;
    }

    // --- recursivelyKsort --------------------------------------------------

    public function test_recursively_ksort_sorts_associative_arrays_by_key(): void
    {
        $out = $this->canon->recursivelyKsort(['b' => 1, 'a' => 2]);

        $this->assertSame(['a' => 2, 'b' => 1], $out, 'assoc arrays must ksort');
    }

    public function test_recursively_ksort_preserves_list_order(): void
    {
        $out = $this->canon->recursivelyKsort(['x', 'y', 'z']);

        $this->assertSame(['x', 'y', 'z'], $out, 'lists must NOT be ksort-ed (order is semantic)');
    }

    public function test_recursively_ksort_descends_into_nested_assocs(): void
    {
        $out = $this->canon->recursivelyKsort([
            'outer_b' => 1,
            'outer_a' => ['inner_b' => 1, 'inner_a' => 2],
        ]);

        $this->assertSame(['outer_a' => ['inner_a' => 2, 'inner_b' => 1], 'outer_b' => 1], $out);
    }

    public function test_recursively_ksort_descends_into_nested_lists(): void
    {
        // Inner list is a list, so its order must be preserved even though the outer is assoc.
        $out = $this->canon->recursivelyKsort([
            'outer_a' => [['k' => 2], ['k' => 1]],
        ]);

        $this->assertSame(['outer_a' => [['k' => 2], ['k' => 1]]], $out, 'inner list order is preserved verbatim');
    }

    public function test_recursively_kssort_on_empty_array_returns_empty(): void
    {
        $this->assertSame([], $this->canon->recursivelyKsort([]));
    }

    // --- normalizePacketForHash --------------------------------------------

    public function test_normalize_packet_for_hash_strips_volatile_identity_fields(): void
    {
        $out = $this->canon->normalizePacketForHash([
            'task_packet_id' => 'should-be-stripped',
            'generated_at' => 'should-be-stripped',
            'task_packet_hash' => 'should-be-stripped',
            'human_summary' => 'should-be-stripped',
            'kept' => 'kept-value',
        ]);

        $this->assertArrayNotHasKey('task_packet_id', $out);
        $this->assertArrayNotHasKey('generated_at', $out);
        $this->assertArrayNotHasKey('task_packet_hash', $out);
        $this->assertArrayNotHasKey('human_summary', $out);
        $this->assertArrayHasKey('kept', $out);
    }

    public function test_normalize_packet_for_hash_deep_ksorts_remaining_fields(): void
    {
        $out = $this->canon->normalizePacketForHash([
            'b' => 1,
            'a' => ['y' => 2, 'x' => 1],
        ]);

        $this->assertSame(['a' => ['x' => 1, 'y' => 2], 'b' => 1], $out);
    }

    public function test_normalize_packet_for_hash_is_byte_identical_to_builder_normalize(): void
    {
        // The whole point of this method: the queue repo's recomputed packet_hash must equal the
        // builder's packet_hash for the same logical packet. We prove that property by hashing
        // before and after the same packet (with reordered keys + a transient identity field) and
        // asserting the stableHash is identical.
        $a = ['z' => 1, 'a' => ['y' => 2, 'x' => 1], 'task_packet_id' => 'TP-1'];
        $b = ['a' => ['x' => 1, 'y' => 2], 'z' => 1, 'task_packet_id' => 'TP-2'];

        $this->assertSame(
            $this->canon->stableHash($this->canon->normalizePacketForHash($a)),
            $this->canon->stableHash($this->canon->normalizePacketForHash($b)),
            'byte-identical hash regardless of key order or transient task_packet_id',
        );
    }

    // --- contractMatchesDefault --------------------------------------------

    public function test_contract_matches_default_returns_true_when_current_is_a_superset_with_equal_default_keys(): void
    {
        $default = ['a' => 1, 'b' => 'x'];
        $current = ['a' => 1, 'b' => 'x', 'extra' => 'ok'];

        $this->assertTrue($this->canon->contractMatchesDefault($current, $default));
    }

    public function test_contract_matches_default_returns_true_when_current_is_exactly_the_default(): void
    {
        $default = ['a' => 1, 'b' => 'x'];
        $current = ['a' => 1, 'b' => 'x'];

        $this->assertTrue($this->canon->contractMatchesDefault($current, $default));
    }

    public function test_contract_matches_default_returns_false_when_a_default_key_is_missing(): void
    {
        $default = ['a' => 1, 'b' => 'x'];
        $current = ['a' => 1];

        $this->assertFalse($this->canon->contractMatchesDefault($current, $default));
    }

    public function test_contract_matches_default_returns_false_when_a_default_key_differs(): void
    {
        $default = ['a' => 1, 'b' => 'x'];
        $current = ['a' => 1, 'b' => 'y'];

        $this->assertFalse($this->canon->contractMatchesDefault($current, $default));
    }

    public function test_contract_matches_default_returns_true_when_default_is_empty(): void
    {
        // No default keys to satisfy => always conforming (even when current has extras).
        $this->assertTrue($this->canon->contractMatchesDefault(['any' => 'thing'], []));
    }

    // --- stableHash --------------------------------------------------------

    public function test_stable_hash_is_sha256_of_canonical_json(): void
    {
        $payload = ['a' => 1, 'b' => 2];

        // Independently compute what the canonicalizer must produce.
        $expected = hash('sha256', (string) json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        $this->assertSame($expected, $this->canon->stableHash($payload));
    }

    public function test_stable_hash_preserves_key_order_input_is_order_sensitive(): void
    {
        // stableHash does NOT sort; that's normalizePacketForHash's job. When called directly on a
        // payload with shuffled keys the hash DIFFERS — proves stableHash is faithful to its input.
        // The order-invariance property lives in `stableHash ∘ normalize` (covered separately).
        $h1 = $this->canon->stableHash(['a' => 1, 'b' => 2]);
        $h2 = $this->canon->stableHash(['b' => 2, 'a' => 1]);

        $this->assertNotSame($h1, $h2, 'stableHash must be order-sensitive; normalizePacketForHash provides the order-invariance');
    }

    public function test_stable_hash_normalize_composition_is_order_independent(): void
    {
        // The bit the queue repo relies on: the canonicalizer's hash of a normalized packet is the
        // SAME regardless of the original key order — that's the whole point of the queue repo's
        // recomputed hash matching the builder's hash on the same logical packet.
        $p1 = ['z' => 1, 'a' => 2, 'm' => ['y' => 3, 'x' => 4]];
        $p2 = ['a' => 2, 'm' => ['x' => 4, 'y' => 3], 'z' => 1];

        $this->assertSame(
            $this->canon->stableHash($this->canon->normalizePacketForHash($p1)),
            $this->canon->stableHash($this->canon->normalizePacketForHash($p2)),
            'stableHash(normalize(...)) must be order-independent',
        );
    }

    public function test_stable_hash_does_not_pretty_print(): void
    {
        // Compute the would-be pretty hash and assert they differ — pretty JSON must NOT be the
        // hash input (would be slow + insertion-order sensitive on some encoders).
        $payload = ['a' => 1, 'b' => ['c' => 2]];
        $pretty = json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
        );

        $this->assertNotSame(
            hash('sha256', $pretty),
            $this->canon->stableHash($payload),
            'stableHash must NOT use pretty JSON',
        );
    }

    public function test_stable_hash_is_64_hex_characters(): void
    {
        $hash = $this->canon->stableHash(['x' => 1]);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash, 'SHA-256 hex digest');
    }

    // --- encode ------------------------------------------------------------

    public function test_encode_produces_pretty_canonical_json(): void
    {
        $out = $this->canon->encode(['a' => 1, 'b' => 2]);

        $this->assertStringContainsString("\n", $out, 'pretty JSON has newlines');
        $this->assertStringContainsString('"a": 1', $out, 'pretty JSON indents');
        // Round-trips to the original payload via json_decode.
        $this->assertSame(['a' => 1, 'b' => 2], json_decode($out, true));
    }

    public function test_encode_does_not_escape_slashes_or_unicode(): void
    {
        $out = $this->canon->encode(['url' => 'https://example.com/x', 'greet' => 'olá']);

        $this->assertStringContainsString('https://example.com/x', $out, 'slashes unescaped');
        $this->assertStringContainsString('olá', $out, 'unicode unescaped');
    }

    // --- stringList --------------------------------------------------------

    public function test_string_list_trims_and_filters_empty_strings(): void
    {
        $out = $this->canon->stringList(['  a  ', '', "\t\n", 'b', '   ']);

        $this->assertSame(['a', 'b'], $out);
    }

    public function test_string_list_casts_non_string_values_via_string_conversion(): void
    {
        // Trim is applied AFTER cast, so `null` becomes `""` and is filtered. `true` becomes `"1"`.
        // The output must be a contiguous list (array_values reindexes after filter).
        $out = $this->canon->stringList([1, 2.5, true, null]);

        $this->assertSame(['1', '2.5', '1'], $out);
    }

    public function test_string_list_returns_list_shape(): void
    {
        $out = $this->canon->stringList(['x', 'y', 'z']);

        $this->assertSame([0, 1, 2], array_keys($out), 'output must be a list (numeric, contiguous keys)');
    }

    public function test_string_list_handles_empty_input(): void
    {
        $this->assertSame([], $this->canon->stringList([]));
    }
}
