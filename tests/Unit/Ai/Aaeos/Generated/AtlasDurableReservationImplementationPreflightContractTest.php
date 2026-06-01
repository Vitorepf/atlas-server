<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDurableReservationImplementationPreflightContractService;
use Tests\TestCase;

/**
 * Pins the documented Durable Reservation Implementation Preflight Contract:
 * the SEVEN Required Contract Hashes (presence + drift), the SEVEN Required
 * Gates, the SEVEN Blocking Conditions and the non-execution guarantee.
 *
 * Pure in-memory decision logic — no database.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-implementation-preflight-contract.md
 */
class AtlasDurableReservationImplementationPreflightContractTest extends TestCase
{
    private function service(): AtlasDurableReservationImplementationPreflightContractService
    {
        return new AtlasDurableReservationImplementationPreflightContractService();
    }

    /**
     * A fully-clean preflight state: every one of the seven contract hashes
     * present + matching, every gate green, scope within approved, no storage
     * write requested, dispatch disabled. Used as the baseline tests then break.
     *
     * @return array<string,mixed>
     */
    private function cleanState(): array
    {
        return [
            // Seven contract hashes, all present with non-empty values.
            'contract_hashes' => [
                'post_approval_preflight_hash' => 'h-pap',
                'implementation_packet_hash' => 'h-pkt',
                'storage_schema_hash' => 'h-schema',
                'repository_contract_hash' => 'h-repo',
                'collision_guard_hash' => 'h-guard',
                'lease_lifecycle_hash' => 'h-lease',
                'readiness_projection_hash' => 'h-ready',
            ],
            // Per-hash "unchanged since approval" signals, all proven.
            'post_approval_preflight_hash_matches' => true,
            'implementation_packet_hash_matches' => true,
            'storage_schema_hash_matches' => true,
            'repository_contract_hash_matches' => true,
            'collision_guard_hash_matches' => true,
            'lease_lifecycle_hash_matches' => true,
            'readiness_projection_hash_matches' => true,
            // Seven gate signals, all proven.
            'self_construction_focused_tests_pass' => true,
            'traceability_audit_clean' => true,
            'docs_health_clean' => true,
            'architecture_validate_clean' => true,
            'diff_check_clean' => true,
            'no_hot_voice_kernel_scopes_in_approved_files' => true,
            'dispatch_disabled' => true,
            // Remaining blocker-clearing signals.
            'migration_scope_within_approved' => true,
            'no_storage_writes_requested' => true,
        ];
    }

    /**
     * Doc "Required Contract Hashes" + "Required Gates" + "Blocking Conditions":
     * with nothing proven, the safe default has all seven hashes missing+drifted,
     * fails all seven gates, fires all seven blockers, status `blocked`, and no
     * packet emitted. Fail-closed by construction.
     */
    public function test_safe_default_fails_everything_and_fires_all_blockers(): void
    {
        $result = $this->service()->preflight([]);

        $this->assertSame('blocked', $result['status']);
        $this->assertFalse($result['preflight_packet_emitted']);
        $this->assertFalse($result['contract_hashes_all_present']);
        $this->assertFalse($result['contract_hashes_none_drifted']);
        $this->assertFalse($result['gates_all_passed']);

        // Seven documented hashes, all missing and drifted.
        $this->assertCount(7, $result['contract_hashes']);
        foreach ($result['contract_hashes'] as $row) {
            $this->assertFalse($row['present']);
            $this->assertTrue($row['drifted']);
        }

        // Seven documented gates, all failing.
        $this->assertCount(7, $result['gates']);
        $this->assertNotContains(true, array_values($result['gates']));

        // Seven documented blockers, all fired, in documented order.
        $this->assertSame([
            'contract_hash_missing',
            'contract_hash_drifted',
            'traceability_not_clean',
            'docs_health_or_architecture_validate_failed',
            'migration_scope_broader_than_approved',
            'storage_writes_requested_before_approval',
            'dispatch_requested',
        ], $result['active_blockers']);
    }

    /**
     * Doc "Completion Criteria": a fully-clean state emits the deterministic
     * read-only preflight packet — all seven hashes present + unchanged, all
     * seven gates green, zero blockers.
     */
    public function test_fully_clean_state_emits_preflight_packet(): void
    {
        $result = $this->service()->preflight($this->cleanState());

        $this->assertSame('preflight_packet_emitted', $result['status']);
        $this->assertTrue($result['preflight_packet_emitted']);
        $this->assertTrue($result['contract_hashes_all_present']);
        $this->assertTrue($result['contract_hashes_none_drifted']);
        $this->assertTrue($result['gates_all_passed']);
        $this->assertSame([], $result['active_blockers']);

        // Even when emitted, the non-execution guarantee holds.
        $this->assertFalse($result['implementation_allowed']);
        $this->assertFalse($result['is_execution']);
    }

    /**
     * Doc "Blocking Conditions": a present-but-DRIFTED hash (value there, match
     * signal false) fires `contract_hash_drifted` but NOT `contract_hash_missing`
     * — proving presence and drift are tracked independently.
     */
    public function test_present_but_drifted_hash_fires_only_drift_blocker(): void
    {
        $state = $this->cleanState();
        // Collision guard hash is still present, but no longer matches approval.
        $state['collision_guard_hash_matches'] = false;

        $result = $this->service()->preflight($state);

        $this->assertTrue($result['contract_hashes']['collision_guard_hash']['present']);
        $this->assertTrue($result['contract_hashes']['collision_guard_hash']['drifted']);
        $this->assertTrue($result['contract_hashes_all_present']);
        $this->assertFalse($result['contract_hashes_none_drifted']);

        $this->assertContains('contract_hash_drifted', $result['active_blockers']);
        $this->assertNotContains('contract_hash_missing', $result['active_blockers']);
        $this->assertFalse($result['preflight_packet_emitted']);
    }

    /**
     * Doc "Blocking Conditions": a missing hash fires `contract_hash_missing`.
     * Dropping a single hash value (the storage schema hash) is enough; it also
     * counts as drifted, so the packet cannot be emitted.
     */
    public function test_missing_single_hash_fires_missing_blocker(): void
    {
        $state = $this->cleanState();
        unset($state['contract_hashes']['storage_schema_hash']);

        $result = $this->service()->preflight($state);

        $this->assertFalse($result['contract_hashes']['storage_schema_hash']['present']);
        $this->assertTrue($result['contract_hashes']['storage_schema_hash']['drifted']);
        $this->assertContains('contract_hash_missing', $result['active_blockers']);
        $this->assertFalse($result['contract_hashes_all_present']);
        $this->assertFalse($result['preflight_packet_emitted']);
    }

    /**
     * Doc "Blocking Conditions": requesting a storage write before approval, and
     * requesting dispatch, each fire their own blocker independently of the
     * (otherwise clean) rest of the state.
     */
    public function test_storage_write_and_dispatch_requests_block_independently(): void
    {
        // Storage write requested before approval (clearing signal not proven).
        $storage = $this->cleanState();
        $storage['no_storage_writes_requested'] = false;
        $storageResult = $this->service()->preflight($storage);
        $this->assertContains('storage_writes_requested_before_approval', $storageResult['active_blockers']);
        $this->assertNotContains('dispatch_requested', $storageResult['active_blockers']);
        $this->assertFalse($storageResult['preflight_packet_emitted']);

        // Dispatch requested (dispatch_disabled no longer proven).
        $dispatch = $this->cleanState();
        $dispatch['dispatch_disabled'] = false;
        $dispatchResult = $this->service()->preflight($dispatch);
        $this->assertContains('dispatch_requested', $dispatchResult['active_blockers']);
        // Gate also fails since dispatch_disabled backs the gate too.
        $this->assertFalse($dispatchResult['gates']['dispatch_remains_disabled']);
        $this->assertNotContains('storage_writes_requested_before_approval', $dispatchResult['active_blockers']);
        $this->assertFalse($dispatchResult['preflight_packet_emitted']);
    }

    /**
     * Doc: "Preflight generation is read-only and cannot approve or execute
     * implementation" / "Final preflight must keep migrations, storage writes,
     * claim persistence and dispatch disabled." The non-execution guarantee holds
     * on BOTH the blocked default and the fully-emitted path — emitting a packet
     * is not execution. assertGuaranteeHeld() finds zero violations.
     */
    public function test_non_execution_guarantee_holds_on_blocked_and_emitted(): void
    {
        $service = $this->service();

        $blocked = $service->evaluate([]);
        $emitted = $service->evaluate($this->cleanState());

        $this->assertTrue($blocked['guarantee_held']);
        $this->assertSame([], $blocked['guarantee_violations']);
        $this->assertTrue($emitted['guarantee_held']);
        $this->assertSame([], $emitted['guarantee_violations']);

        // All four guarantee keys present and false on the emitted packet.
        foreach (['migrations_created', 'storage_writes_performed', 'claim_persistence_performed', 'dispatch_enabled'] as $key) {
            $this->assertFalse($emitted['preflight']['guarantee'][$key]);
        }
    }
}
