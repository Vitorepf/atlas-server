<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Leasing;

use App\Services\Ai\SelfConstruction\Leasing\AgentControlPlaneLeaseEnvelopeFactory;
use Tests\TestCase;

final class AgentControlPlaneLeaseEnvelopeFactoryTest extends TestCase
{
    private AgentControlPlaneLeaseEnvelopeFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = new AgentControlPlaneLeaseEnvelopeFactory();
    }

    private function lease(array $overrides = []): array
    {
        return array_merge([
            'lease_id' => 'lease-001',
            'task_packet_id' => 'task-001',
            'client_id' => 'client-001',
            'lease_status' => 'active',
            'expires_at_unix' => time() + 3600,
            'allowed_files' => ['app/Foo.php', 'tests/FooTest.php'],
        ], $overrides);
    }

    // ── Schema version ───────────────────────────────────────────────────────────

    public function test_schema_version_constant(): void
    {
        $this->assertSame('atlas.self_construction.agent_control_plane_claim_lease_runtime.v1', AgentControlPlaneLeaseEnvelopeFactory::SCHEMA_VERSION);
    }

    // ── Event constants ──────────────────────────────────────────────────────────

    public function test_event_constants(): void
    {
        $this->assertSame('acquired', AgentControlPlaneLeaseEnvelopeFactory::EVENT_ACQUIRED);
        $this->assertSame('renewed', AgentControlPlaneLeaseEnvelopeFactory::EVENT_RENEWED);
        $this->assertSame('released', AgentControlPlaneLeaseEnvelopeFactory::EVENT_RELEASED);
        $this->assertSame('expired', AgentControlPlaneLeaseEnvelopeFactory::EVENT_EXPIRED);
        $this->assertSame('contention', AgentControlPlaneLeaseEnvelopeFactory::EVENT_CONTENTION);
        $this->assertSame('error', AgentControlPlaneLeaseEnvelopeFactory::EVENT_ERROR);
    }

    // ── buildReceipt ─────────────────────────────────────────────────────────────

    public function test_build_receipt_contains_kind_and_hash(): void
    {
        $receipt = $this->factory->buildReceipt('test_kind', ['foo' => 'bar']);
        $this->assertSame('test_kind', $receipt['receipt_kind']);
        $this->assertArrayHasKey('recorded_at', $receipt);
        $this->assertArrayHasKey('receipt_hash', $receipt);
        $this->assertFalse($receipt['runtime_execution_allowed']);
        $this->assertFalse($receipt['ledger_write_allowed']);
    }

    public function test_build_receipt_hash_is_deterministic(): void
    {
        $r1 = $this->factory->buildReceipt('kind', ['a' => 1]);
        $r2 = $this->factory->buildReceipt('kind', ['a' => 1]);
        $this->assertSame($r1['receipt_hash'], $r2['receipt_hash']);
    }

    public function test_build_receipt_hash_changes_with_different_data(): void
    {
        $r1 = $this->factory->buildReceipt('kind', ['a' => 1]);
        $r2 = $this->factory->buildReceipt('kind', ['a' => 2]);
        $this->assertNotSame($r1['receipt_hash'], $r2['receipt_hash']);
    }

    // ── lockContentionEnvelope ───────────────────────────────────────────────────

    public function test_lock_contention_envelope_is_blocked(): void
    {
        $env = $this->factory->lockContentionEnvelope('lock unavailable');
        $this->assertSame('blocked', $env['status']);
        $this->assertSame('blocked', $env['event']);
        $this->assertSame('lock unavailable', $env['reason']);
        $this->assertFalse($env['runtime_execution_allowed']);
        $this->assertFalse($env['dispatch_allowed']);
        $this->assertFalse($env['ledger_write_allowed']);
    }

    // ── encode ───────────────────────────────────────────────────────────────────

    public function test_encode_produces_valid_json(): void
    {
        $json = $this->factory->encode(['key' => 'value']);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertSame('value', $decoded['key']);
    }

    public function test_encode_is_pretty_printed(): void
    {
        $json = $this->factory->encode(['key' => 'value']);
        $this->assertStringContainsString("\n", $json);
    }

    // ── envelopeOk ───────────────────────────────────────────────────────────────

    public function test_envelope_ok_has_status_ok(): void
    {
        $env = $this->factory->envelopeOk('acquired', $this->lease());
        $this->assertSame('ok', $env['status']);
        $this->assertSame('acquired', $env['event']);
        $this->assertSame('lease-001', $env['lease_id']);
        $this->assertSame('task-001', $env['task_packet_id']);
        $this->assertArrayHasKey('lease_integrity_hash', $env);
        $this->assertFalse($env['runtime_execution_allowed']);
        $this->assertFalse($env['dispatch_allowed']);
    }

    public function test_envelope_ok_includes_full_lease(): void
    {
        $lease = $this->lease();
        $env = $this->factory->envelopeOk('acquired', $lease);
        $this->assertSame($lease, $env['lease']);
    }

    public function test_envelope_ok_exposes_authority_nonce_and_revocation_state(): void
    {
        $env = $this->factory->envelopeOk('acquired', $this->lease([
            'authority_nonce' => 'authority-nonce-001',
            'authority_revoked' => false,
        ]));

        $this->assertSame('authority-nonce-001', $env['authority_nonce']);
        $this->assertFalse($env['authority_revoked']);
    }

    public function test_lease_integrity_hash_changes_when_authority_nonce_changes(): void
    {
        $first = $this->factory->envelopeOk('acquired', $this->lease(['authority_nonce' => 'nonce-a']));
        $second = $this->factory->envelopeOk('acquired', $this->lease(['authority_nonce' => 'nonce-b']));

        $this->assertNotSame($first['lease_integrity_hash'], $second['lease_integrity_hash']);
    }

    public function test_envelope_ok_extra_merges(): void
    {
        $env = $this->factory->envelopeOk('acquired', $this->lease(), ['custom' => 'value']);
        $this->assertSame('value', $env['custom']);
    }

    // ── envelopeError ────────────────────────────────────────────────────────────

    public function test_envelope_error_is_blocked(): void
    {
        $env = $this->factory->envelopeError('reason', 'task-001', 'agent-001');
        $this->assertSame('blocked', $env['status']);
        $this->assertSame('blocked', $env['event']);
        $this->assertSame('reason', $env['reason']);
        $this->assertSame('task-001', $env['task_packet_id']);
        $this->assertSame('agent-001', $env['agent_id']);
    }

    public function test_envelope_error_extra_merges(): void
    {
        $env = $this->factory->envelopeError('reason', 'task-001', 'agent-001', ['detail' => 'x']);
        $this->assertSame('x', $env['detail']);
    }

    // ── Lease integrity hash ─────────────────────────────────────────────────────

    public function test_lease_integrity_hash_is_deterministic(): void
    {
        $env1 = $this->factory->envelopeOk('acquired', $this->lease());
        $env2 = $this->factory->envelopeOk('acquired', $this->lease());
        $this->assertSame($env1['lease_integrity_hash'], $env2['lease_integrity_hash']);
    }

    public function test_lease_integrity_hash_changes_with_different_allowed_files(): void
    {
        $env1 = $this->factory->envelopeOk('acquired', $this->lease(['allowed_files' => ['a.php']]));
        $env2 = $this->factory->envelopeOk('acquired', $this->lease(['allowed_files' => ['b.php']]));
        $this->assertNotSame($env1['lease_integrity_hash'], $env2['lease_integrity_hash']);
    }

    public function test_lease_integrity_hash_changes_with_different_client(): void
    {
        $env1 = $this->factory->envelopeOk('acquired', $this->lease(['client_id' => 'c1']));
        $env2 = $this->factory->envelopeOk('acquired', $this->lease(['client_id' => 'c2']));
        $this->assertNotSame($env1['lease_integrity_hash'], $env2['lease_integrity_hash']);
    }

    // ── buildLeaseEventReceipt ───────────────────────────────────────────────────

    public function test_build_lease_event_receipt_for_acquired(): void
    {
        $receipt = $this->factory->buildLeaseEventReceipt('acquired', 'task-001', 'agent-001', 'lease-001');
        $this->assertSame('lease_acquired', $receipt['receipt_kind']);
        $this->assertSame('task-001', $receipt['task_packet_id']);
        $this->assertSame('agent-001', $receipt['agent_id']);
        $this->assertSame('lease-001', $receipt['lease_id']);
        $this->assertSame('acquired', $receipt['event']);
    }

    public function test_build_lease_event_receipt_for_contention_without_lease_id(): void
    {
        $receipt = $this->factory->buildLeaseEventReceipt('contention', 'task-001', 'agent-001');
        $this->assertSame('lease_contention', $receipt['receipt_kind']);
        $this->assertSame('', $receipt['lease_id']);
    }

    public function test_build_lease_event_receipt_for_error_without_lease_id(): void
    {
        $receipt = $this->factory->buildLeaseEventReceipt('error', 'task-001', 'agent-001');
        $this->assertSame('lease_error', $receipt['receipt_kind']);
        $this->assertSame('', $receipt['lease_id']);
    }

    public function test_build_lease_event_receipt_throws_for_unknown_event(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->factory->buildLeaseEventReceipt('unknown', 'task-001', 'agent-001');
    }

    public function test_build_lease_event_receipt_throws_for_empty_task_packet_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->factory->buildLeaseEventReceipt('acquired', '', 'agent-001');
    }

    public function test_build_lease_event_receipt_throws_for_empty_agent_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->factory->buildLeaseEventReceipt('acquired', 'task-001', '');
    }

    public function test_build_lease_event_receipt_throws_for_renewed_without_lease_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->factory->buildLeaseEventReceipt('renewed', 'task-001', 'agent-001');
    }

    public function test_build_lease_event_receipt_extra_merges(): void
    {
        $receipt = $this->factory->buildLeaseEventReceipt('acquired', 'task-001', 'agent-001', 'lease-001', '', ['custom' => 'val']);
        $this->assertSame('val', $receipt['custom']);
    }

    // ── All six canonical events ─────────────────────────────────────────────────

    public function test_all_six_events_produce_receipts(): void
    {
        foreach (['acquired', 'renewed', 'released', 'expired', 'contention', 'error'] as $event) {
            $receipt = $this->factory->buildLeaseEventReceipt($event, 'task-001', 'agent-001', 'lease-001');
            $this->assertSame("lease_{$event}", $receipt['receipt_kind']);
        }
    }

    // ── Determinism ──────────────────────────────────────────────────────────────

    public function test_envelope_ok_is_deterministic_for_same_lease(): void
    {
        $lease = $this->lease();
        $e1 = $this->factory->envelopeOk('acquired', $lease);
        $e2 = $this->factory->envelopeOk('acquired', $lease);
        $this->assertSame($e1, $e2);
    }
}
