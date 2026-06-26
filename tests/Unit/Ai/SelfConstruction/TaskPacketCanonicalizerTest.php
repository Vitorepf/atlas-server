<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskQueue;

use App\Services\Ai\SelfConstruction\TaskQueue\TaskPacketCanonicalizer;
use Tests\TestCase;

class TaskPacketCanonicalizerTest extends TestCase
{
    private function c(): TaskPacketCanonicalizer
    {
        return new TaskPacketCanonicalizer;
    }

    public function test_recursively_ksort_orders_associative(): void
    {
        $result = $this->c()->recursivelyKsort(['b' => 2, 'a' => 1]);
        self::assertSame(['a' => 1, 'b' => 2], $result);
    }

    public function test_recursively_ksort_preserves_list(): void
    {
        $result = $this->c()->recursivelyKsort(['c', 'a', 'b']);
        self::assertSame(['c', 'a', 'b'], $result);
    }

    public function test_recursively_ksort_nested(): void
    {
        $result = $this->c()->recursivelyKsort(['b' => ['y' => 1, 'x' => 2], 'a' => 0]);
        self::assertSame(['a' => 0, 'b' => ['x' => 2, 'y' => 1]], $result);
    }

    public function test_recursively_ksort_empty(): void
    {
        self::assertSame([], $this->c()->recursivelyKsort([]));
    }

    public function test_normalize_packet_for_hash_strips_packet_id(): void
    {
        $result = $this->c()->normalizePacketForHash([
            'task_packet_id' => 'tp1',
            'data' => ['b' => 1, 'a' => 2],
        ]);

        self::assertArrayNotHasKey('task_packet_id', $result);
        self::assertSame(['a' => 2, 'b' => 1], $result['data']);
    }

    public function test_contract_matches_default_when_keys_equal(): void
    {
        $default = ['status' => 'ok', 'count' => 5];
        $current = ['status' => 'ok', 'count' => 5, 'extra' => 'ignored'];

        self::assertTrue($this->c()->contractMatchesDefault($current, $default));
    }

    public function test_contract_mismatch_when_value_differs(): void
    {
        $default = ['status' => 'ok'];
        $current = ['status' => 'bad'];

        self::assertFalse($this->c()->contractMatchesDefault($current, $default));
    }

    public function test_contract_mismatch_when_default_key_missing(): void
    {
        $default = ['status' => 'ok', 'count' => 5];
        $current = ['status' => 'ok'];

        self::assertFalse($this->c()->contractMatchesDefault($current, $default));
    }

    public function test_stable_hash_returns_64_hex(): void
    {
        $hash = $this->c()->stableHash(['x' => 1]);
        self::assertSame(64, strlen($hash));
    }

    public function test_stable_hash_changes_with_data(): void
    {
        $a = $this->c()->stableHash(['x' => 1]);
        $b = $this->c()->stableHash(['x' => 2]);

        self::assertNotSame($a, $b);
    }

    public function test_encode_returns_canonical_json(): void
    {
        $encoded = $this->c()->encode(['b' => 2, 'a' => 1]);
        self::assertStringContainsString('"a": 1', $encoded);
        self::assertStringContainsString('"b": 2', $encoded);
    }

    public function test_encode_handles_empty(): void
    {
        self::assertStringContainsString('[]', $this->c()->encode([]));
    }

    public function test_string_list_trims_and_filters(): void
    {
        $result = $this->c()->stringList(['  a  ', 'b', '', '  ', 'c']);
        self::assertSame(['a', 'b', 'c'], $result);
    }

    public function test_string_list_empty(): void
    {
        self::assertSame([], $this->c()->stringList([]));
    }
}