<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Leasing;

use App\Services\Ai\SelfConstruction\Leasing\AgentControlPlaneLeaseEnvelopeFactory;
use PHPUnit\Framework\TestCase;

/**
 * ITEM8 — proves the cohesive pure-formatting concern extracted from AgentControlPlaneClaimLeaseRepository
 * into AgentControlPlaneLeaseEnvelopeFactory. Five methods migrated verbatim:
 *
 *  - buildReceipt: stamps kind + ISO recorded_at + the no-execution guarantees, merges data, then
 *    SHA-256 hashes the (kind, data) tuple into a `receipt_hash` for audit traceability.
 *  - envelopeOk: the success envelope (status='ok', lease, runtime guarantees).
 *  - envelopeError: the blocked envelope (status='blocked', reason, task_packet_id + agent_id).
 *  - lockContentionEnvelope: the FAIL-CLOSED result when the exclusive flock cannot be acquired.
 *  - encode: pretty canonical JSON.
 *
 * Pure / stateless / zero Laravel surface (CarbonImmutable provides the timestamp) — pure PHPUnit
 * suffices.
 */
final class AgentControlPlaneLeaseEnvelopeFactoryTest extends TestCase
{
    private AgentControlPlaneLeaseEnvelopeFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = new AgentControlPlaneLeaseEnvelopeFactory;
    }

    public function test_schema_version_is_stable_and_known(): void
    {
        // The byte-identical contract: the factory's SCHEMA_VERSION must equal the original lease
        // repo's SCHEMA_VERSION, so envelopes written by either path parse as the same schema.
        $this->assertSame(
            'atlas.self_construction.agent_control_plane_claim_lease_runtime.v1',
            AgentControlPlaneLeaseEnvelopeFactory::SCHEMA_VERSION,
        );
    }

    // --- buildReceipt ------------------------------------------------------

    public function test_build_receipt_stamps_kind_recorded_at_and_no_execution_guarantees(): void
    {
        $r = $this->factory->buildReceipt('lease_claimed', ['lease_id' => 'L1']);

        $this->assertSame('lease_claimed', $r['receipt_kind']);
        $this->assertArrayHasKey('recorded_at', $r);
        $this->assertIsString($r['recorded_at']);
        // Recorded_at is an ISO-8601 string; we don't pin to a specific instant, but the shape is stable.
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $r['recorded_at']);
        $this->assertFalse($r['runtime_execution_allowed']);
        $this->assertFalse($r['ledger_write_allowed']);
    }

    public function test_build_receipt_merges_data_and_emits_receipt_hash(): void
    {
        $r = $this->factory->buildReceipt('lease_claimed', ['lease_id' => 'L1', 'agent_id' => 'A1']);

        $this->assertSame('L1', $r['lease_id']);
        $this->assertSame('A1', $r['agent_id']);
        $this->assertArrayHasKey('receipt_hash', $r);
        $this->assertIsString($r['receipt_hash']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $r['receipt_hash'], 'SHA-256 hex');
    }

    public function test_build_receipt_hash_is_deterministic_for_same_kind_and_data(): void
    {
        $a = $this->factory->buildReceipt('lease_claimed', ['lease_id' => 'L1', 'agent_id' => 'A1']);
        $b = $this->factory->buildReceipt('lease_claimed', ['lease_id' => 'L1', 'agent_id' => 'A1']);

        // recorded_at is set by CarbonImmutable::now() — under high-frequency calls these MAY match
        // (same second) but the receipt_hash is computed from (kind, data) ONLY, so it must match
        // regardless of when the calls happened.
        $this->assertSame($a['receipt_hash'], $b['receipt_hash'], 'receipt_hash is a pure function of (kind, data)');
    }

    public function test_build_receipt_hash_differs_when_kind_or_data_differs(): void
    {
        $base = $this->factory->buildReceipt('lease_claimed', ['lease_id' => 'L1']);
        $diffKind = $this->factory->buildReceipt('lease_renewed', ['lease_id' => 'L1']);
        $diffData = $this->factory->buildReceipt('lease_claimed', ['lease_id' => 'L2']);

        $this->assertNotSame($base['receipt_hash'], $diffKind['receipt_hash']);
        $this->assertNotSame($base['receipt_hash'], $diffData['receipt_hash']);
    }

    public function test_build_receipt_caller_data_overrides_stamps_via_array_merge(): void
    {
        // array_merge semantics: caller-provided keys in $data OVERRIDE the factory's stamps when
        // keys collide. This is the byte-identical contract of the original `array_merge([stamps], $data)`
        // call in the god-class — a forged `receipt_kind` in $data wins. The factory preserves this
        // semantics verbatim; any hardening of the receipt identity would belong in the god-class's
        // buildReceipt caller, not in this extractor.
        $r = $this->factory->buildReceipt('lease_claimed', [
            'receipt_kind' => 'forged',
            'recorded_at' => 'forged-timestamp',
        ]);

        $this->assertSame('forged', $r['receipt_kind']);
        $this->assertSame('forged-timestamp', $r['recorded_at']);
    }

    // --- envelopeOk -------------------------------------------------------

    public function test_envelope_ok_has_status_ok_event_lease_and_no_execution_guarantees(): void
    {
        $lease = [
            'lease_id' => 'L1',
            'task_packet_id' => 'TP1',
            'lease_status' => 'active',
            'foo' => 'bar',
        ];

        $env = $this->factory->envelopeOk('lease_claimed', $lease);

        $this->assertSame('atlas.self_construction.agent_control_plane_claim_lease_runtime.v1', $env['schema_version']);
        $this->assertSame('ok', $env['status']);
        $this->assertSame('lease_claimed', $env['event']);
        $this->assertSame('L1', $env['lease_id']);
        $this->assertSame('TP1', $env['task_packet_id']);
        $this->assertSame('active', $env['lease_status']);
        $this->assertSame($lease, $env['lease'], 'lease payload echoed verbatim');
        $this->assertFalse($env['runtime_execution_allowed']);
        $this->assertFalse($env['dispatch_allowed']);
        $this->assertFalse($env['ledger_write_allowed']);
    }

    public function test_envelope_ok_merges_extra_keys(): void
    {
        $lease = ['lease_id' => 'L1', 'task_packet_id' => 'TP1', 'lease_status' => 'active'];
        $env = $this->factory->envelopeOk('lease_claimed', $lease, ['claim_token' => 'T1', 'score' => 42]);

        $this->assertSame('T1', $env['claim_token']);
        $this->assertSame(42, $env['score']);
        // Existing key is NOT clobbered by extra.
        $this->assertSame('ok', $env['status']);
    }

    public function test_envelope_ok_handles_missing_lease_fields_with_empty_strings(): void
    {
        $env = $this->factory->envelopeOk('lease_claimed', []);

        $this->assertSame('', $env['lease_id']);
        $this->assertSame('', $env['task_packet_id']);
        $this->assertSame('', $env['lease_status']);
        $this->assertSame([], $env['lease']);
    }

    // --- envelopeError ----------------------------------------------------

    public function test_envelope_error_has_status_blocked_reason_ids_and_no_execution_guarantees(): void
    {
        $env = $this->factory->envelopeError('lease_not_found', 'TP1', 'A1');

        $this->assertSame('atlas.self_construction.agent_control_plane_claim_lease_runtime.v1', $env['schema_version']);
        $this->assertSame('blocked', $env['status']);
        $this->assertSame('blocked', $env['event']);
        $this->assertSame('lease_not_found', $env['reason']);
        $this->assertSame('TP1', $env['task_packet_id']);
        $this->assertSame('A1', $env['agent_id']);
        $this->assertFalse($env['runtime_execution_allowed']);
        $this->assertFalse($env['dispatch_allowed']);
        $this->assertFalse($env['ledger_write_allowed']);
    }

    public function test_envelope_error_merges_extra_keys(): void
    {
        $env = $this->factory->envelopeError('lease_not_found', 'TP1', 'A1', [
            'expected_lease_status' => 'active',
            'actual_lease_status' => 'released',
        ]);

        $this->assertSame('active', $env['expected_lease_status']);
        $this->assertSame('released', $env['actual_lease_status']);
    }

    // --- lockContentionEnvelope ------------------------------------------

    public function test_lock_contention_envelope_emits_blocked_status_no_ids(): void
    {
        $env = $this->factory->lockContentionEnvelope('lock_timeout');

        $this->assertSame('atlas.self_construction.agent_control_plane_claim_lease_runtime.v1', $env['schema_version']);
        $this->assertSame('blocked', $env['status']);
        $this->assertSame('blocked', $env['event']);
        $this->assertSame('lock_timeout', $env['reason']);
        $this->assertFalse($env['runtime_execution_allowed']);
        $this->assertFalse($env['dispatch_allowed']);
        $this->assertFalse($env['ledger_write_allowed']);
        $this->assertArrayNotHasKey('lease_id', $env);
        $this->assertArrayNotHasKey('task_packet_id', $env);
        $this->assertArrayNotHasKey('agent_id', $env);
    }

    public function test_lock_contention_envelope_emits_arbitrary_reason_verbatim(): void
    {
        $env = $this->factory->lockContentionEnvelope('lock_open_failed');
        $this->assertSame('lock_open_failed', $env['reason']);
    }

    // --- encode -----------------------------------------------------------

    public function test_encode_produces_pretty_canonical_json(): void
    {
        $out = $this->factory->encode(['a' => 1, 'b' => 2]);

        $this->assertStringContainsString("\n", $out, 'pretty JSON has newlines');
        $this->assertStringContainsString('"a": 1', $out);
        $this->assertSame(['a' => 1, 'b' => 2], json_decode($out, true), 'round-trips');
    }

    public function test_encode_does_not_escape_slashes_or_unicode(): void
    {
        $out = $this->factory->encode(['url' => 'https://example.com/x', 'greet' => 'olá']);

        $this->assertStringContainsString('https://example.com/x', $out);
        $this->assertStringContainsString('olá', $out);
    }

    public function test_encode_throws_on_non_encodable_input(): void
    {
        $this->expectException(\JsonException::class);
        // PHP cannot encode resources — proven encoding-failure input.
        $this->factory->encode(['r' => fopen('php://memory', 'r')]);
    }

    // --- composition: SCHEMA_VERSION consistency between envelopes --------

    public function test_all_three_envelope_helpers_emit_same_schema_version(): void
    {
        $lease = ['lease_id' => 'L1', 'task_packet_id' => 'TP1', 'lease_status' => 'active'];
        $sv = 'atlas.self_construction.agent_control_plane_claim_lease_runtime.v1';

        $this->assertSame($sv, $this->factory->envelopeOk('lease_claimed', $lease)['schema_version']);
        $this->assertSame($sv, $this->factory->envelopeError('lease_not_found', 'TP1', 'A1')['schema_version']);
        $this->assertSame($sv, $this->factory->lockContentionEnvelope('lock_timeout')['schema_version']);
    }
}
