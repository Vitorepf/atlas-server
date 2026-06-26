<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\MultiAgentLoopCertification;

use App\Services\Ai\SelfConstruction\MultiAgentLoopCertification\AgentControlPlaneMultiAgentLoopCertificationHashCanonicalizer;
use Tests\TestCase;

class AgentControlPlaneMultiAgentLoopCertificationHashCanonicalizerTest extends TestCase
{
    public function test_load_json_returns_null_for_missing_file(): void
    {
        $disk = new class
        {
            public function exists(string $path): bool
            {
                return false;
            }
        };

        self::assertNull(AgentControlPlaneMultiAgentLoopCertificationHashCanonicalizer::loadJson($disk, 'missing.json'));
    }

    public function test_load_json_returns_decoded_array(): void
    {
        $disk = new class
        {
            public function exists(string $path): bool
            {
                return true;
            }

            public function get(string $path): string
            {
                return '{"key": "value", "num": 42}';
            }
        };

        $result = AgentControlPlaneMultiAgentLoopCertificationHashCanonicalizer::loadJson($disk, 'exists.json');

        self::assertSame(['key' => 'value', 'num' => 42], $result);
    }

    public function test_load_json_returns_null_for_invalid_json(): void
    {
        $disk = new class
        {
            public function exists(string $path): bool
            {
                return true;
            }

            public function get(string $path): string
            {
                return '{invalid json';
            }
        };

        self::assertNull(AgentControlPlaneMultiAgentLoopCertificationHashCanonicalizer::loadJson($disk, 'broken.json'));
    }

    public function test_load_json_returns_null_for_non_array_json(): void
    {
        $disk = new class
        {
            public function exists(string $path): bool
            {
                return true;
            }

            public function get(string $path): string
            {
                return '"just a string"';
            }
        };

        self::assertNull(AgentControlPlaneMultiAgentLoopCertificationHashCanonicalizer::loadJson($disk, 'scalar.json'));
    }

    public function test_encode_json_produces_pretty_printed_output(): void
    {
        $encoded = AgentControlPlaneMultiAgentLoopCertificationHashCanonicalizer::encodeJson(['key' => 'value']);

        self::assertStringContainsString('{', $encoded);
        self::assertStringContainsString("\n", $encoded);
        self::assertStringContainsString('"key"', $encoded);
        self::assertStringContainsString('"value"', $encoded);
    }

    public function test_encode_json_does_not_escape_slashes(): void
    {
        $encoded = AgentControlPlaneMultiAgentLoopCertificationHashCanonicalizer::encodeJson(['path' => '/some/path']);

        self::assertStringContainsString('/some/path', $encoded);
        self::assertStringNotContainsString('\/', $encoded);
    }

    public function test_recursively_ksort_sorts_associative_keys(): void
    {
        $input = ['c' => 1, 'a' => 2, 'b' => 3];
        $result = AgentControlPlaneMultiAgentLoopCertificationHashCanonicalizer::recursivelyKsort($input);

        self::assertSame(['a' => 2, 'b' => 3, 'c' => 1], $result);
    }

    public function test_recursively_ksort_does_not_sort_sequential_arrays(): void
    {
        $input = [3, 1, 2];
        $result = AgentControlPlaneMultiAgentLoopCertificationHashCanonicalizer::recursivelyKsort($input);

        self::assertSame([3, 1, 2], $result);
    }

    public function test_recursively_ksort_sorts_nested_arrays(): void
    {
        $input = ['outer' => ['z' => 1, 'a' => 2]];
        $result = AgentControlPlaneMultiAgentLoopCertificationHashCanonicalizer::recursivelyKsort($input);

        self::assertSame(['outer' => ['a' => 2, 'z' => 1]], $result);
    }

    public function test_stable_hash_is_deterministic(): void
    {
        $payload = ['b' => 2, 'a' => 1];
        $hash1 = AgentControlPlaneMultiAgentLoopCertificationHashCanonicalizer::stableHash($payload);
        $hash2 = AgentControlPlaneMultiAgentLoopCertificationHashCanonicalizer::stableHash($payload);

        self::assertSame($hash1, $hash2);
    }

    public function test_stable_hash_is_order_independent(): void
    {
        $hash1 = AgentControlPlaneMultiAgentLoopCertificationHashCanonicalizer::stableHash(['b' => 2, 'a' => 1]);
        $hash2 = AgentControlPlaneMultiAgentLoopCertificationHashCanonicalizer::stableHash(['a' => 1, 'b' => 2]);

        self::assertSame($hash1, $hash2);
    }

    public function test_stable_hash_returns_sha256_hex(): void
    {
        $hash = AgentControlPlaneMultiAgentLoopCertificationHashCanonicalizer::stableHash(['test' => true]);

        self::assertEquals(64, strlen($hash));
        self::assertTrue(ctype_xdigit($hash));
    }

    public function test_normalize_for_hash_strips_volatile_top_level_keys(): void
    {
        $payload = [
            'certification_id' => 'abc-123',
            'generated_at' => '2026-06-26T00:00:00Z',
            'certification_hash' => 'oldhash',
            'human_summary' => 'Some summary',
            'stable_field' => 'preserved',
        ];

        $result = AgentControlPlaneMultiAgentLoopCertificationHashCanonicalizer::normalizeForHash($payload);

        self::assertArrayNotHasKey('certification_id', $result);
        self::assertArrayNotHasKey('generated_at', $result);
        self::assertArrayNotHasKey('certification_hash', $result);
        self::assertArrayNotHasKey('human_summary', $result);
        self::assertSame('preserved', $result['stable_field']);
    }

    public function test_normalize_for_hash_strips_cycle_evidence_volatile_ids(): void
    {
        $payload = [
            'cycle_evidence' => [
                [
                    'seeded_packet_ids' => ['task-1', 'task-2'],
                    'agents' => [
                        ['agent_id' => 'a1', 'task_packet_id' => 'tp1', 'lease_id' => 'l1', 'role' => 'worker'],
                    ],
                    'recovery' => [
                        'orphan_lease_id' => 'ol1',
                        'expired_lease_id' => 'el1',
                        'expiration_result' => 'expired',
                    ],
                    'continuation_hashes' => ['h1', 'h2'],
                    'stable_cycle_field' => true,
                ],
            ],
        ];

        $result = AgentControlPlaneMultiAgentLoopCertificationHashCanonicalizer::normalizeForHash($payload);
        $cycle = $result['cycle_evidence'][0];

        self::assertArrayNotHasKey('seeded_packet_ids', $cycle);
        self::assertArrayNotHasKey('continuation_hashes', $cycle);
        self::assertArrayNotHasKey('agent_id', $cycle['agents'][0]);
        self::assertArrayNotHasKey('task_packet_id', $cycle['agents'][0]);
        self::assertArrayNotHasKey('lease_id', $cycle['agents'][0]);
        self::assertArrayNotHasKey('orphan_lease_id', $cycle['recovery']);
        self::assertArrayNotHasKey('expired_lease_id', $cycle['recovery']);
        self::assertArrayNotHasKey('expiration_result', $cycle['recovery']);
        self::assertSame('worker', $cycle['agents'][0]['role']);
        self::assertTrue($cycle['stable_cycle_field']);
    }

    public function test_normalize_for_hash_strips_fleet_probe_volatile_keys(): void
    {
        $payload = [
            'terminal_loop_fleet_launch_plan_probe' => [
                'queue_tag' => 'qt',
                'seeded_packet_ids' => ['p1'],
                'fleet_launch_plan_hash' => 'hash',
                'stable' => true,
            ],
            'terminal_loop_fleet_resume_rollup_probe' => [
                'queue_tag' => 'qt',
                'task_packet_id' => 'tp',
                'lease_id' => 'l',
                'fleet_resume_rollup_hash' => 'h',
            ],
        ];

        $result = AgentControlPlaneMultiAgentLoopCertificationHashCanonicalizer::normalizeForHash($payload);

        self::assertArrayNotHasKey('queue_tag', $result['terminal_loop_fleet_launch_plan_probe']);
        self::assertArrayNotHasKey('seeded_packet_ids', $result['terminal_loop_fleet_launch_plan_probe']);
        self::assertArrayNotHasKey('fleet_launch_plan_hash', $result['terminal_loop_fleet_launch_plan_probe']);
        self::assertTrue($result['terminal_loop_fleet_launch_plan_probe']['stable']);
        self::assertArrayNotHasKey('queue_tag', $result['terminal_loop_fleet_resume_rollup_probe']);
    }

    public function test_normalize_for_hash_strips_queue_and_lease_summary_counts(): void
    {
        $payload = [
            'queue_summary' => [
                'entry_count' => 5,
                'total_count' => 10,
                'status_counts' => ['pending' => 3],
                'corrupt' => false,
                'queue_name' => 'default',
            ],
            'lease_summary' => [
                'active_lease_count' => 3,
                'max_leases' => 10,
            ],
        ];

        $result = AgentControlPlaneMultiAgentLoopCertificationHashCanonicalizer::normalizeForHash($payload);

        self::assertArrayNotHasKey('entry_count', $result['queue_summary']);
        self::assertArrayNotHasKey('total_count', $result['queue_summary']);
        self::assertArrayNotHasKey('status_counts', $result['queue_summary']);
        self::assertArrayNotHasKey('corrupt', $result['queue_summary']);
        self::assertSame('default', $result['queue_summary']['queue_name']);
        self::assertArrayNotHasKey('active_lease_count', $result['lease_summary']);
        self::assertSame(10, $result['lease_summary']['max_leases']);
    }
}
