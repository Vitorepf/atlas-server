<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Readiness;

use App\Services\Ai\SelfConstruction\Readiness\ReadinessTerminalLoopProofResolver;
use Tests\TestCase;

class ReadinessTerminalLoopProofResolverTest extends TestCase
{
    public function test_payload_from_json_returns_empty_for_non_array(): void
    {
        self::assertSame([], ReadinessTerminalLoopProofResolver::payloadFromJson(null));
        self::assertSame([], ReadinessTerminalLoopProofResolver::payloadFromJson('string'));
        self::assertSame([], ReadinessTerminalLoopProofResolver::payloadFromJson(42));
    }

    public function test_payload_from_json_extracts_root_proof_payload(): void
    {
        $proof = ['proof_payload' => ['key' => 'value']];

        self::assertSame(['key' => 'value'], ReadinessTerminalLoopProofResolver::payloadFromJson($proof));
    }

    public function test_payload_from_json_extracts_nested_completion_audit_path(): void
    {
        $proof = [
            'completion_audit_binding_packet' => [
                'proof_payload' => ['nested' => true],
            ],
        ];

        self::assertSame(['nested' => true], ReadinessTerminalLoopProofResolver::payloadFromJson($proof));
    }

    public function test_payload_from_json_extracts_deep_agent_control_plane_path(): void
    {
        $proof = [
            'agent_control_plane_terminal_loop_operational_proof' => [
                'completion_audit_binding_packet' => [
                    'proof_payload' => ['deep' => 'yes'],
                ],
            ],
        ];

        self::assertSame(['deep' => 'yes'], ReadinessTerminalLoopProofResolver::payloadFromJson($proof));
    }

    public function test_payload_from_json_falls_back_to_root_array(): void
    {
        $proof = ['fallback' => 'root'];

        self::assertSame(['fallback' => 'root'], ReadinessTerminalLoopProofResolver::payloadFromJson($proof));
    }

    public function test_payload_from_json_returns_empty_array_for_empty_input(): void
    {
        self::assertSame([], ReadinessTerminalLoopProofResolver::payloadFromJson([]));
    }

    public function test_with_payload_passes_through_when_explicit_payload_exists(): void
    {
        $options = [
            'agent_control_plane_terminal_loop_operational_proof' => ['already' => 'present'],
            'other' => 'data',
        ];

        $result = ReadinessTerminalLoopProofResolver::withPayload($options, 'some/path.json');

        self::assertSame(['already' => 'present'], $result['agent_control_plane_terminal_loop_operational_proof']);
        self::assertSame('data', $result['other']);
        self::assertSame('explicit_array', $result['agent_control_plane_terminal_loop_operational_proof_source']);
    }

    public function test_with_payload_preserves_source_metadata_for_explicit_array_source(): void
    {
        $options = ['agent_control_plane_terminal_loop_operational_proof' => ['a' => 1]];

        $result = ReadinessTerminalLoopProofResolver::withPayload($options, 'nonexistent/path.json');

        self::assertSame('explicit_array', $result['agent_control_plane_terminal_loop_operational_proof_source']);
    }

    public function test_with_payload_preserves_source_metadata_for_json_option_source(): void
    {
        $options = ['agent_control_plane_terminal_loop_operational_proof_json' => '{"proof_payload":{"from":"json"}}'];

        $result = ReadinessTerminalLoopProofResolver::withPayload($options, 'nonexistent/path.json');

        self::assertSame('json_option', $result['agent_control_plane_terminal_loop_operational_proof_source']);
    }

    public function test_with_payload_resolves_from_json_option_string(): void
    {
        $json = '{"proof_payload": {"from": "json"}}';
        $options = ['agent_control_plane_terminal_loop_operational_proof_json' => $json];

        $result = ReadinessTerminalLoopProofResolver::withPayload($options, 'some/path.json');

        self::assertArrayHasKey('agent_control_plane_terminal_loop_operational_proof', $result);
        self::assertSame(['from' => 'json'], $result['agent_control_plane_terminal_loop_operational_proof']);
    }

    public function test_with_payload_returns_options_unchanged_when_no_source_available(): void
    {
        $options = ['unrelated' => 'value'];

        $result = ReadinessTerminalLoopProofResolver::withPayload($options, 'nonexistent/path-that-does-not-exist.json');

        self::assertArrayNotHasKey('agent_control_plane_terminal_loop_operational_proof', $result);
        self::assertSame($options, $result);
    }

    public function test_with_payload_loads_from_canonical_file_when_present(): void
    {
        $canonicalPath = 'atlas/self-construction/operator-submissions/test-terminal-loop-proof.json';
        $absolutePath = storage_path('app/private/'.$canonicalPath);
        @mkdir(dirname($absolutePath), 0777, true);
        file_put_contents($absolutePath, json_encode([
            'proof_payload' => ['canonical' => true],
        ]));

        try {
            $options = [];
            $result = ReadinessTerminalLoopProofResolver::withPayload($options, $canonicalPath);

            self::assertArrayHasKey('agent_control_plane_terminal_loop_operational_proof', $result);
            self::assertSame(['canonical' => true], $result['agent_control_plane_terminal_loop_operational_proof']);
            self::assertSame('canonical_operator_submission', $result['agent_control_plane_terminal_loop_operational_proof_source']);
            self::assertStringContainsString($canonicalPath, $result['agent_control_plane_terminal_loop_operational_proof_canonical_path']);
        } finally {
            @unlink($absolutePath);
        }
    }

    public function test_resolve_audit_explicit_array_present(): void
    {
        $options = ['agent_control_plane_terminal_loop_operational_proof' => ['key' => 'value']];
        $audit = ReadinessTerminalLoopProofResolver::resolveAudit($options, 'nonexistent.json');

        self::assertSame('explicit_array', $audit['source']);
        self::assertTrue($audit['payload_present']);
        self::assertSame([], $audit['blockers']);
    }

    public function test_resolve_audit_explicit_empty_array_has_blocker(): void
    {
        $options = ['agent_control_plane_terminal_loop_operational_proof' => []];
        $audit = ReadinessTerminalLoopProofResolver::resolveAudit($options, 'nonexistent.json');

        self::assertSame('explicit_array', $audit['source']);
        self::assertFalse($audit['payload_present']);
        self::assertContains('explicit_array_proof_is_empty', $audit['blockers']);
    }

    public function test_resolve_audit_json_option(): void
    {
        $options = ['agent_control_plane_terminal_loop_operational_proof_json' => '{"proof_payload":{"from":"json"}}'];
        $audit = ReadinessTerminalLoopProofResolver::resolveAudit($options, 'nonexistent.json');

        self::assertSame('json_option', $audit['source']);
        self::assertTrue($audit['payload_present']);
        self::assertSame([], $audit['blockers']);
    }

    public function test_resolve_audit_missing_returns_blocker(): void
    {
        $audit = ReadinessTerminalLoopProofResolver::resolveAudit([], 'nonexistent-path-that-does-not-exist.json');

        self::assertSame('missing', $audit['source']);
        self::assertFalse($audit['payload_present']);
        self::assertContains('no_proof_source_available', $audit['blockers']);
    }

    public function test_resolve_audit_canonical_file(): void
    {
        $canonicalPath = 'atlas/self-construction/operator-submissions/test-audit-resolve.json';
        $absolutePath = storage_path('app/private/'.$canonicalPath);
        @mkdir(dirname($absolutePath), 0777, true);
        file_put_contents($absolutePath, json_encode(['proof_payload' => ['canonical' => true]]));

        try {
            $audit = ReadinessTerminalLoopProofResolver::resolveAudit([], $canonicalPath);

            self::assertSame('canonical_file', $audit['source']);
            self::assertTrue($audit['payload_present']);
            self::assertSame([], $audit['blockers']);
            self::assertStringContainsString($canonicalPath, $audit['canonical_path']);
        } finally {
            @unlink($absolutePath);
        }
    }

    // ── freshness policy ───────────────────────────────────────────────────────

    public function test_resolve_audit_reports_stale_when_generated_at_older_than_max_age(): void
    {
        $options = [
            'agent_control_plane_terminal_loop_operational_proof' => [
                'ok' => true,
                'generated_at' => time() - 7200,
            ],
        ];
        $audit = ReadinessTerminalLoopProofResolver::resolveAudit($options, 'nonexistent.json', ['max_age_seconds' => 3600]);

        self::assertSame('stale', $audit['freshness_status']);
        self::assertContains('stale_proof_payload', $audit['blockers']);
        self::assertTrue($audit['payload_present']);
    }

    public function test_resolve_audit_reports_fresh_when_generated_at_within_max_age(): void
    {
        $options = [
            'agent_control_plane_terminal_loop_operational_proof' => [
                'ok' => true,
                'generated_at' => time() - 10,
            ],
        ];
        $audit = ReadinessTerminalLoopProofResolver::resolveAudit($options, 'nonexistent.json', ['max_age_seconds' => 3600]);

        self::assertSame('fresh', $audit['freshness_status']);
        self::assertNotContains('stale_proof_payload', $audit['blockers']);
    }

    public function test_resolve_audit_freshness_unknown_without_policy(): void
    {
        $options = [
            'agent_control_plane_terminal_loop_operational_proof' => [
                'ok' => true,
                'generated_at' => time() - 999999,
            ],
        ];
        $audit = ReadinessTerminalLoopProofResolver::resolveAudit($options, 'nonexistent.json');

        self::assertSame('unknown', $audit['freshness_status']);
        self::assertNotContains('stale_proof_payload', $audit['blockers']);
    }

    // ── source ambiguity ─────────────────────────────────────────────────────────

    public function test_resolve_audit_reports_source_ambiguity_when_multiple_sources_supplied(): void
    {
        $options = [
            'agent_control_plane_terminal_loop_operational_proof' => ['a' => 1],
            'agent_control_plane_terminal_loop_operational_proof_json' => '{"proof_payload":{"b":2}}',
        ];
        $audit = ReadinessTerminalLoopProofResolver::resolveAudit($options, 'nonexistent.json');

        self::assertTrue($audit['source_ambiguity']);
        // Deterministic precedence: explicit_array still wins.
        self::assertSame('explicit_array', $audit['source']);
        self::assertTrue(
            (bool) array_filter($audit['blockers'], static fn (string $b): bool => str_starts_with($b, 'multiple_proof_sources_supplied')),
        );
    }

    public function test_resolve_audit_no_source_ambiguity_when_only_one_source_supplied(): void
    {
        $options = ['agent_control_plane_terminal_loop_operational_proof' => ['a' => 1]];
        $audit = ReadinessTerminalLoopProofResolver::resolveAudit($options, 'nonexistent.json');

        self::assertFalse($audit['source_ambiguity']);
    }

    // ── invariant: payload_present=true never appears without blockers when weak ──

    public function test_stale_payload_present_true_still_carries_blocker(): void
    {
        $options = [
            'agent_control_plane_terminal_loop_operational_proof' => [
                'ok' => true,
                'generated_at' => time() - 7200,
            ],
        ];
        $audit = ReadinessTerminalLoopProofResolver::resolveAudit($options, 'nonexistent.json', ['max_age_seconds' => 3600]);

        self::assertTrue($audit['payload_present']);
        self::assertNotEmpty($audit['blockers']);
    }

    public function test_ambiguous_payload_present_true_still_carries_blocker(): void
    {
        $options = [
            'agent_control_plane_terminal_loop_operational_proof' => ['a' => 1],
            'agent_control_plane_terminal_loop_operational_proof_json' => '{"proof_payload":{"b":2}}',
        ];
        $audit = ReadinessTerminalLoopProofResolver::resolveAudit($options, 'nonexistent.json');

        self::assertTrue($audit['payload_present']);
        self::assertNotEmpty($audit['blockers']);
    }

    public function test_clean_single_source_payload_present_true_has_no_blockers(): void
    {
        $options = ['agent_control_plane_terminal_loop_operational_proof' => ['a' => 1]];
        $audit = ReadinessTerminalLoopProofResolver::resolveAudit($options, 'nonexistent.json');

        self::assertTrue($audit['payload_present']);
        self::assertSame([], $audit['blockers']);
    }

    // ── Five-proof resolve() ──────────────────────────────────────────────────────

    public function test_all_five_proofs_ready_returns_ready_true(): void
    {
        $facts = [
            'launch_proof' => ['status' => 'passed', 'hash' => str_repeat('a', 64)],
            'replenishment_proof' => ['status' => 'passed', 'hash' => str_repeat('b', 64)],
            'evidence_proof' => ['status' => 'passed', 'hash' => str_repeat('c', 64)],
            'lane_isolation_proof' => ['status' => 'passed', 'hash' => str_repeat('d', 64)],
            'cycle_supervisor_proof' => ['status' => 'passed', 'hash' => str_repeat('e', 64)],
        ];

        $result = ReadinessTerminalLoopProofResolver::resolve($facts);

        self::assertTrue($result['ready']);
        self::assertSame([], $result['blockers']);
        self::assertSame('', $result['next_proof_action']);
    }

    public function test_all_five_proofs_ready_with_freshness_policy(): void
    {
        $facts = [
            'launch_proof' => ['status' => 'passed', 'hash' => str_repeat('a', 64), 'generated_at' => time() - 10],
            'replenishment_proof' => ['status' => 'passed', 'hash' => str_repeat('b', 64), 'generated_at' => time() - 20],
            'evidence_proof' => ['status' => 'passed', 'hash' => str_repeat('c', 64), 'generated_at' => time() - 30],
            'lane_isolation_proof' => ['status' => 'passed', 'hash' => str_repeat('d', 64), 'generated_at' => time() - 40],
            'cycle_supervisor_proof' => ['status' => 'passed', 'hash' => str_repeat('e', 64), 'generated_at' => time() - 50],
        ];

        $result = ReadinessTerminalLoopProofResolver::resolve($facts, ['max_age_seconds' => 3600]);

        self::assertTrue($result['ready']);
    }

    public function test_proof_refs_contains_all_five_keys_with_correct_states(): void
    {
        $facts = [
            'launch_proof' => ['status' => 'passed', 'hash' => str_repeat('a', 64)],
            'evidence_proof' => ['status' => 'blocked', 'hash' => str_repeat('c', 64)],
        ];

        $result = ReadinessTerminalLoopProofResolver::resolve($facts);

        self::assertSame('ready', $result['proof_refs']['launch_proof']);
        self::assertSame('missing', $result['proof_refs']['replenishment_proof']);
        self::assertSame('contradictory', $result['proof_refs']['evidence_proof']);
        self::assertSame('missing', $result['proof_refs']['lane_isolation_proof']);
        self::assertSame('missing', $result['proof_refs']['cycle_supervisor_proof']);
        self::assertFalse($result['ready']);
    }

    public function test_all_missing_proofs_reports_blockers_and_first_action(): void
    {
        $result = ReadinessTerminalLoopProofResolver::resolve([]);

        self::assertFalse($result['ready']);
        self::assertCount(5, $result['blockers']);
        self::assertContains('missing_launch_proof', $result['blockers']);
        self::assertContains('missing_cycle_supervisor_proof', $result['blockers']);
        self::assertSame('provide_launch_proof', $result['next_proof_action']);
    }

    public function test_stale_proof_triggers_stale_state_and_action(): void
    {
        $facts = [
            'launch_proof' => ['status' => 'passed', 'hash' => str_repeat('a', 64), 'generated_at' => time() - 7200],
            'replenishment_proof' => ['status' => 'passed', 'hash' => str_repeat('b', 64)],
            'evidence_proof' => ['status' => 'passed', 'hash' => str_repeat('c', 64)],
            'lane_isolation_proof' => ['status' => 'passed', 'hash' => str_repeat('d', 64)],
            'cycle_supervisor_proof' => ['status' => 'passed', 'hash' => str_repeat('e', 64)],
        ];

        $result = ReadinessTerminalLoopProofResolver::resolve($facts, ['max_age_seconds' => 3600]);

        self::assertFalse($result['ready']);
        self::assertSame('stale', $result['proof_refs']['launch_proof']);
        self::assertContains('launch_proof_stale', $result['blockers']);
        self::assertSame('refresh_launch_proof', $result['next_proof_action']);
    }

    public function test_contradictory_blocked_proof_returns_correct_state(): void
    {
        $facts = ['launch_proof' => ['status' => 'blocked', 'hash' => str_repeat('a', 64)]];

        $result = ReadinessTerminalLoopProofResolver::resolve($facts);

        self::assertSame('contradictory', $result['proof_refs']['launch_proof']);
        self::assertContains('launch_proof_status_blocked', $result['blockers']);
        self::assertSame('investigate_launch_proof_blocked', $result['next_proof_action']);
    }

    public function test_contradictory_passed_but_empty_hash(): void
    {
        $facts = ['replenishment_proof' => ['status' => 'passed', 'hash' => '']];

        $result = ReadinessTerminalLoopProofResolver::resolve($facts);

        self::assertSame('contradictory', $result['proof_refs']['replenishment_proof']);
        self::assertContains('replenishment_proof_hash_invalid_or_empty', $result['blockers']);
    }

    public function test_contradictory_malformed_fact_missing_status(): void
    {
        $facts = ['evidence_proof' => ['hash' => str_repeat('c', 64)]];

        $result = ReadinessTerminalLoopProofResolver::resolve($facts);

        self::assertSame('contradictory', $result['proof_refs']['evidence_proof']);
        self::assertContains('evidence_proof_missing_status', $result['blockers']);
    }

    public function test_ready_requires_sha256_hash_first_action_skips_ready(): void
    {
        $facts = [
            'launch_proof' => ['status' => 'passed', 'hash' => str_repeat('a', 64)],
            'replenishment_proof' => ['status' => 'passed', 'hash' => 'not-a-valid-sha256-hash'],
        ];

        $result = ReadinessTerminalLoopProofResolver::resolve($facts);

        // replenishment is first failing — next_proof_action points at it
        self::assertSame('contradictory', $result['proof_refs']['replenishment_proof']);
        self::assertContains('replenishment_proof_hash_invalid_or_empty', $result['blockers']);
        self::assertSame('investigate_replenishment_proof_hash_inconsistency', $result['next_proof_action']);
    }

    public function test_null_fact_treated_as_missing(): void
    {
        $facts = [
            'launch_proof' => null,
            'replenishment_proof' => ['status' => 'passed', 'hash' => str_repeat('b', 64)],
            'evidence_proof' => ['status' => 'passed', 'hash' => str_repeat('c', 64)],
            'lane_isolation_proof' => ['status' => 'passed', 'hash' => str_repeat('d', 64)],
            'cycle_supervisor_proof' => ['status' => 'passed', 'hash' => str_repeat('e', 64)],
        ];

        $result = ReadinessTerminalLoopProofResolver::resolve($facts);

        self::assertFalse($result['ready']);
        self::assertSame('missing', $result['proof_refs']['launch_proof']);
        self::assertContains('missing_launch_proof', $result['blockers']);
    }

    public function test_empty_array_fact_treated_as_missing(): void
    {
        $facts = ['launch_proof' => []];

        $result = ReadinessTerminalLoopProofResolver::resolve($facts);

        self::assertSame('missing', $result['proof_refs']['launch_proof']);
        self::assertContains('missing_launch_proof', $result['blockers']);
    }

    public function test_stale_priority_over_missing_first_action(): void
    {
        $facts = [
            'launch_proof' => ['status' => 'passed', 'hash' => str_repeat('a', 64)],
            'replenishment_proof' => ['status' => 'passed', 'hash' => str_repeat('b', 64), 'generated_at' => time() - 7200],
            'evidence_proof' => ['status' => 'passed', 'hash' => str_repeat('c', 64)],
            'lane_isolation_proof' => ['status' => 'passed', 'hash' => str_repeat('d', 64)],
            'cycle_supervisor_proof' => null,
        ];

        $result = ReadinessTerminalLoopProofResolver::resolve($facts, ['max_age_seconds' => 3600]);

        // launch is ready, replenishment is stale — that gets the first action
        self::assertSame('refresh_replenishment_proof', $result['next_proof_action']);
    }

    public function test_contradictory_priority_over_stale_first_action(): void
    {
        $facts = [
            'launch_proof' => ['status' => 'passed', 'hash' => str_repeat('a', 64)],
            'replenishment_proof' => ['status' => 'blocked', 'hash' => str_repeat('b', 64), 'generated_at' => time() - 7200],
            'evidence_proof' => ['status' => 'passed', 'hash' => str_repeat('c', 64)],
            'lane_isolation_proof' => ['status' => 'passed', 'hash' => str_repeat('d', 64)],
            'cycle_supervisor_proof' => ['status' => 'passed', 'hash' => str_repeat('e', 64)],
        ];

        $result = ReadinessTerminalLoopProofResolver::resolve($facts, ['max_age_seconds' => 3600]);

        // replenishment is blocked — first non-ready in order
        self::assertSame('investigate_replenishment_proof_blocked', $result['next_proof_action']);
    }

    public function test_with_payload_does_not_overwrite_when_canonical_payload_empty(): void
    {
        $canonicalPath = 'atlas/self-construction/operator-submissions/test-empty-proof.json';
        $absolutePath = storage_path('app/private/'.$canonicalPath);
        @mkdir(dirname($absolutePath), 0777, true);
        file_put_contents($absolutePath, json_encode([]));

        try {
            $options = [];
            $result = ReadinessTerminalLoopProofResolver::withPayload($options, $canonicalPath);

            self::assertArrayNotHasKey('agent_control_plane_terminal_loop_operational_proof', $result);
        } finally {
            @unlink($absolutePath);
        }
    }
}
