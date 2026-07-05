<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ControlPlane;

use App\Services\Ai\SelfConstruction\ControlPlane\TerminalLoopHealthDigestPayloadNormalizer;
use Tests\TestCase;

class TerminalLoopHealthDigestPayloadNormalizerTest extends TestCase
{
    private function normalizer(): TerminalLoopHealthDigestPayloadNormalizer
    {
        return new TerminalLoopHealthDigestPayloadNormalizer;
    }

    public function test_string_option_returns_value_when_set(): void
    {
        self::assertSame('hello', $this->normalizer()->stringOption(['key' => 'hello'], 'key', 'default'));
    }

    public function test_string_option_returns_trimmed_value(): void
    {
        self::assertSame('hello', $this->normalizer()->stringOption(['key' => '  hello  '], 'key', 'default'));
    }

    public function test_string_option_returns_default_when_missing(): void
    {
        self::assertSame('default', $this->normalizer()->stringOption([], 'missing', 'default'));
    }

    public function test_string_option_returns_default_when_empty(): void
    {
        self::assertSame('default', $this->normalizer()->stringOption(['key' => '   '], 'key', 'default'));
        self::assertSame('default', $this->normalizer()->stringOption(['key' => ''], 'key', 'default'));
    }

    public function test_string_list_normalizes(): void
    {
        $result = $this->normalizer()->stringList(['  a  ', 'b', '', '  ', 'c']);
        self::assertSame(['a', 'b', 'c'], $result);
    }

    public function test_string_list_empty(): void
    {
        self::assertSame([], $this->normalizer()->stringList([]));
    }

    public function test_string_list_handles_non_strings(): void
    {
        $result = $this->normalizer()->stringList([42, 'x', 0, '', 'y']);
        self::assertSame(['0', '42', 'x', 'y'], $result);
    }

    public function test_string_list_deduplicates(): void
    {
        $result = $this->normalizer()->stringList(['a', 'b', 'a', 'c', 'b']);
        self::assertSame(['a', 'b', 'c'], $result);
    }

    public function test_string_list_flattens_nested_arrays(): void
    {
        $result = $this->normalizer()->stringList([['a', 'b'], 'c']);
        self::assertSame(['a', 'b', 'c'], $result);
    }

    public function test_hash_payload_deterministic(): void
    {
        $payload = ['a' => 1, 'b' => 2, 'c' => 3];
        $h1 = $this->normalizer()->hashPayload($payload);
        $h2 = $this->normalizer()->hashPayload($payload);

        self::assertSame($h1, $h2);
        self::assertSame(64, strlen($h1));
    }

    public function test_hash_payload_strips_excluded_keys(): void
    {
        $payload = ['a' => 1, 'digest_id' => 'should_be_stripped'];
        $hash = $this->normalizer()->hashPayload($payload);

        $withDifferentId = $this->normalizer()->hashPayload(['a' => 1, 'digest_id' => 'different_id']);

        self::assertSame($hash, $withDifferentId);
    }

    public function test_hash_payload_strips_generated_at(): void
    {
        $base = ['a' => 1];
        $withTs = ['a' => 1, 'generated_at' => 1234567890];

        self::assertSame($this->normalizer()->hashPayload($base), $this->normalizer()->hashPayload($withTs));
    }

    public function test_hash_payload_strips_all_known_excluded_keys(): void
    {
        $excluded = [
            'digest_id', 'generated_at',
            'terminal_loop_health_digest_hash',
            'terminal_loop_fleet_launch_plan_hash',
            'terminal_loop_fleet_replenishment_plan_hash',
            'terminal_loop_fleet_resume_rollup_hash',
            'terminal_loop_fleet_evidence_rollup_hash',
            'terminal_loop_fleet_operator_handoff_hash',
            'terminal_loop_fleet_lane_isolation_hash',
            'terminal_loop_cycle_supervisor_hash',
            'terminal_loop_fleet_launch_runbook_hash',
            'terminal_loop_end_to_end_contract_hash',
        ];

        $base = ['a' => 1, 'b' => 2];
        $withExcluded = array_merge($base, array_fill_keys($excluded, 'should_be_stripped'));

        self::assertSame($this->normalizer()->hashPayload($base), $this->normalizer()->hashPayload($withExcluded));
    }

    public function test_hash_payload_changes_when_data_changes(): void
    {
        $a = $this->normalizer()->hashPayload(['x' => 1]);
        $b = $this->normalizer()->hashPayload(['x' => 2]);

        self::assertNotSame($a, $b);
    }

    // ── AC hardening: stringOption, stringList, hashPayload ──────────────────

    public function test_string_option_returns_default_for_non_string_value(): void
    {
        self::assertSame('default', $this->normalizer()->stringOption(['key' => 42], 'key', 'default'));
        self::assertSame('default', $this->normalizer()->stringOption(['key' => ['nested']], 'key', 'default'));
        self::assertSame('default', $this->normalizer()->stringOption(['key' => null], 'key', 'default'));
    }

    public function test_string_option_returns_default_for_boolean_value(): void
    {
        self::assertSame('default', $this->normalizer()->stringOption(['key' => true], 'key', 'default'));
        self::assertSame('default', $this->normalizer()->stringOption(['key' => false], 'key', 'default'));
    }

    public function test_string_list_sorts_output_deterministically(): void
    {
        $a = $this->normalizer()->stringList(['c', 'a', 'b']);
        $b = $this->normalizer()->stringList(['a', 'b', 'c']);
        self::assertSame($a, $b);
        self::assertSame(['a', 'b', 'c'], $a);
    }

    public function test_string_list_deduplicates_case_sensitively(): void
    {
        $result = $this->normalizer()->stringList(['A', 'a', 'A', 'a', 'B']);
        self::assertSame(['A', 'B', 'a'], $result);
    }

    public function test_string_list_flattens_nested_arrays_with_blanks(): void
    {
        $result = $this->normalizer()->stringList([['a', '', 'b'], '', 'c', [' ', 'd']]);
        self::assertSame(['a', 'b', 'c', 'd'], $result);
    }

    public function test_string_list_coerces_floats_and_ints(): void
    {
        $result = $this->normalizer()->stringList([3.14, 42, 0, -1]);
        self::assertSame(['-1', '0', '3.14', '42'], $result);
    }

    public function test_hash_payload_stable_across_key_order(): void
    {
        $a = $this->normalizer()->hashPayload(['b' => 2, 'a' => 1, 'c' => 3]);
        $b = $this->normalizer()->hashPayload(['c' => 3, 'a' => 1, 'b' => 2]);
        self::assertSame($a, $b);
    }

    public function test_hash_payload_strips_nonce_timestamp_csp_nonce_token_updated_at(): void
    {
        $base = ['queue_health' => 'green', 'lane_count' => 5];
        $withVolatile = $base + [
            'nonce' => 'abc',
            'timestamp' => 1234567890,
            'csp_nonce' => 'def',
            '_token' => 'csrf-xxx',
            'updated_at' => '2026-07-05T00:00:00Z',
        ];
        self::assertSame(
            $this->normalizer()->hashPayload($base),
            $this->normalizer()->hashPayload($withVolatile),
        );
    }

    public function test_hash_payload_changes_when_queue_health_changes(): void
    {
        $a = $this->normalizer()->hashPayload(['queue_health' => 'green', 'lane_count' => 5]);
        $b = $this->normalizer()->hashPayload(['queue_health' => 'red', 'lane_count' => 5]);
        self::assertNotSame($a, $b);
    }

    public function test_hash_payload_changes_when_lane_count_changes(): void
    {
        $a = $this->normalizer()->hashPayload(['queue_health' => 'green', 'lane_count' => 5]);
        $b = $this->normalizer()->hashPayload(['queue_health' => 'green', 'lane_count' => 6]);
        self::assertNotSame($a, $b);
    }

    public function test_hash_payload_changes_when_cycle_id_changes(): void
    {
        $a = $this->normalizer()->hashPayload(['cycle_id' => 'cycle-001', 'status' => 'running']);
        $b = $this->normalizer()->hashPayload(['cycle_id' => 'cycle-002', 'status' => 'running']);
        self::assertNotSame($a, $b);
    }

    public function test_hash_payload_stable_with_nested_unordered_keys(): void
    {
        $a = $this->normalizer()->hashPayload([
            'fleet' => ['lane_3' => 'active', 'lane_1' => 'idle', 'lane_2' => 'active'],
        ]);
        $b = $this->normalizer()->hashPayload([
            'fleet' => ['lane_2' => 'active', 'lane_1' => 'idle', 'lane_3' => 'active'],
        ]);
        self::assertSame($a, $b);
    }
}