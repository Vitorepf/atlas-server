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
        self::assertSame(['42', 'x', '0', 'y'], $result);
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
}