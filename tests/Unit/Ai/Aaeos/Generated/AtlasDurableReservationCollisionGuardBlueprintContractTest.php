<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDurableReservationCollisionGuardBlueprintContractService;
use Tests\TestCase;

/**
 * Pins the documented Durable Reservation Collision Guard BLUEPRINT contract:
 * the eight Required Inputs, the six Required Blockers, the FOUR Decision States
 * (including the `allow_claim` state absent from the sibling guard), the seven
 * Required Outputs and the documented Required Tests (hot scope blocked, active
 * file overlap blocked, stale hash blocked, incomplete dependency blocked, clean
 * disjoint packet claimable, guard explains conflicting ids and paths), plus the
 * read-only non-execution guarantee.
 *
 * Pure in-memory decision logic — no database.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-collision-guard-blueprint-contract.md
 */
class AtlasDurableReservationCollisionGuardBlueprintContractTest extends TestCase
{
    private function service(): AtlasDurableReservationCollisionGuardBlueprintContractService
    {
        return new AtlasDurableReservationCollisionGuardBlueprintContractService();
    }

    /**
     * Doc shape: the blueprint emits exactly the eight Required Inputs, six
     * Required Blockers, four Decision States (allow_claim included) and seven
     * Required Outputs, verbatim and in documented order.
     */
    public function test_blueprint_emits_documented_inputs_blockers_states_outputs(): void
    {
        $blueprint = $this->service()->blueprint();

        $this->assertSame([
            'candidate_packet_id',
            'candidate_packet_hash',
            'candidate_allowed_files',
            'current_active_reservations',
            'current_changed_files',
            'hot_forbidden_scopes',
            'packet_dependency_status',
            'packet_completion_gate_status',
        ], $blueprint['required_inputs']);

        $this->assertSame([
            'hot_scope_forbidden',
            'active_file_overlap',
            'packet_hash_stale',
            'dependency_incomplete',
            'completion_gate_blocked',
            'owner_conflict',
        ], $blueprint['required_blockers']);

        // The four documented decision states — allow_claim is the differentiator.
        $this->assertSame(
            ['allow_preview', 'allow_claim', 'block_claim', 'require_human_review'],
            $blueprint['decision_states'],
        );
        $this->assertContains('allow_claim', $blueprint['decision_states']);

        $this->assertSame([
            'decision',
            'blocker_code',
            'reason',
            'conflicting_reservation_ids',
            'conflicting_file_paths',
            'packet_hash_used',
            'guard_hash',
        ], $blueprint['required_outputs']);

        // Blueprint is read-only and deterministically fingerprinted.
        $this->assertNotEmpty($blueprint['blueprint_hash']);
        $this->assertSame($blueprint, $this->service()->blueprint());
    }

    /**
     * Doc Required Test "hot Voice/Kernel scope is blocked": a candidate file in
     * the hot forbidden scope => block_claim with the hot_scope_forbidden code.
     */
    public function test_hot_voice_kernel_scope_is_blocked(): void
    {
        $result = $this->service()->evaluate([
            'candidate_packet_id' => 'PKT-1',
            'candidate_packet_hash' => 'h1',
            'assigned_packet_hash' => 'h1',
            'candidate_allowed_files' => ['app/Voice/Kernel.php'],
            'hot_forbidden_scopes' => ['app/Voice/Kernel.php'],
            'packet_dependency_status' => 'complete',
            'packet_completion_gate_status' => 'green',
        ]);

        $this->assertSame('block_claim', $result['decision']);
        $this->assertSame('hot_scope_forbidden', $result['blocker_code']);
        $this->assertContains('hot_scope_forbidden', $result['blocking_reasons']);
    }

    /**
     * Doc Required Tests: "overlapping active file scope is blocked" AND "guard
     * explains conflicting reservation ids and file paths". An overlapping file
     * owned by another session => block_claim, and the output names both the
     * conflicting reservation id and the conflicting file path.
     */
    public function test_active_file_overlap_is_blocked_and_explained(): void
    {
        $result = $this->service()->evaluate([
            'candidate_packet_id' => 'PKT-2',
            'candidate_packet_hash' => 'h2',
            'assigned_packet_hash' => 'h2',
            'candidate_allowed_files' => ['app/Foo.php', 'app/Bar.php'],
            'current_session_id' => 'sess-A',
            'active_reservations' => [
                [
                    'reservation_id' => 'RES-9',
                    'owner_session_id' => 'sess-B',
                    'changed_files' => ['app/Bar.php'],
                ],
            ],
            'packet_dependency_status' => 'complete',
            'packet_completion_gate_status' => 'green',
        ]);

        $this->assertSame('block_claim', $result['decision']);
        $this->assertSame('active_file_overlap', $result['blocker_code']);
        // Guard EXPLAINS the conflict: ids and paths are surfaced.
        $this->assertSame(['RES-9'], $result['conflicting_reservation_ids']);
        $this->assertSame(['app/Bar.php'], $result['conflicting_file_paths']);
        // Disjoint candidate file is not reported as conflicting.
        $this->assertNotContains('app/Foo.php', $result['conflicting_file_paths']);
    }

    /**
     * Doc Required Test "stale packet hash is blocked": candidate hash differs
     * from the assigned hash => block_claim with packet_hash_stale, and the
     * Required Output `packet_hash_used` reports the hash actually decided on.
     */
    public function test_stale_packet_hash_is_blocked(): void
    {
        $result = $this->service()->evaluate([
            'candidate_packet_id' => 'PKT-3',
            'candidate_packet_hash' => 'NEW-hash',
            'assigned_packet_hash' => 'OLD-hash',
            'candidate_allowed_files' => ['app/Clean.php'],
            'packet_dependency_status' => 'complete',
            'packet_completion_gate_status' => 'green',
        ]);

        $this->assertSame('block_claim', $result['decision']);
        $this->assertSame('packet_hash_stale', $result['blocker_code']);
        $this->assertSame('NEW-hash', $result['packet_hash_used']);
    }

    /**
     * Doc Required Test "incomplete dependency is blocked": dependency status is
     * not `complete` => block_claim with dependency_incomplete. A non-green
     * completion gate alone (soft) routes to require_human_review instead.
     */
    public function test_incomplete_dependency_blocks_but_gate_alone_needs_review(): void
    {
        $service = $this->service();

        $depBlocked = $service->evaluate([
            'candidate_packet_id' => 'PKT-4',
            'candidate_packet_hash' => 'h4',
            'assigned_packet_hash' => 'h4',
            'candidate_allowed_files' => ['app/Clean.php'],
            'packet_dependency_status' => 'pending',
            'packet_completion_gate_status' => 'green',
        ]);
        $this->assertSame('block_claim', $depBlocked['decision']);
        $this->assertSame('dependency_incomplete', $depBlocked['blocker_code']);

        // Soft-only: deps complete, no overlap, but the completion gate is not
        // green => human review (not an outright block).
        $gateOnly = $service->evaluate([
            'candidate_packet_id' => 'PKT-4b',
            'candidate_packet_hash' => 'h4',
            'assigned_packet_hash' => 'h4',
            'candidate_allowed_files' => ['app/Clean.php'],
            'packet_dependency_status' => 'complete',
            'packet_completion_gate_status' => 'red',
        ]);
        $this->assertSame('require_human_review', $gateOnly['decision']);
        $this->assertSame('completion_gate_blocked', $gateOnly['blocker_code']);
    }

    /**
     * Doc Required Test "clean disjoint packet can be claimable": no blocker AND
     * complete positive evidence (fresh hash, deps complete, green gate) =>
     * allow_claim with no blocker. A disjoint packet whose assigned hash is
     * unknown stays at allow_preview (cannot prove claimability yet).
     */
    public function test_clean_disjoint_packet_is_claimable_else_preview(): void
    {
        $service = $this->service();

        $claimable = $service->evaluate([
            'candidate_packet_id' => 'PKT-5',
            'candidate_packet_hash' => 'h5',
            'assigned_packet_hash' => 'h5',
            'candidate_allowed_files' => ['app/Solo.php'],
            'current_session_id' => 'sess-A',
            'active_reservations' => [
                ['reservation_id' => 'RES-1', 'owner_session_id' => 'sess-A', 'changed_files' => ['app/Other.php']],
            ],
            'hot_forbidden_scopes' => ['app/Voice/Kernel.php'],
            'packet_dependency_status' => 'complete',
            'packet_completion_gate_status' => 'green',
        ]);
        $this->assertSame('allow_claim', $claimable['decision']);
        $this->assertNull($claimable['blocker_code']);
        $this->assertSame([], $claimable['conflicting_file_paths']);

        // Same clean packet but the assigned hash is unknown: not provably
        // claimable -> preview only (no blocker fired, but no positive proof).
        $preview = $service->evaluate([
            'candidate_packet_id' => 'PKT-5',
            'candidate_packet_hash' => 'h5',
            // no assigned_packet_hash
            'candidate_allowed_files' => ['app/Solo.php'],
            'packet_dependency_status' => 'complete',
            'packet_completion_gate_status' => 'green',
        ]);
        $this->assertSame('allow_preview', $preview['decision']);
        $this->assertNull($preview['blocker_code']);
    }

    /**
     * Hard blocker dominates a soft one: an owner conflict (soft) co-firing with
     * an active file overlap (hard) must resolve to block_claim, never review.
     * The guard_hash is present and deterministic for identical inputs.
     */
    public function test_hard_blocker_dominates_soft_and_guard_hash_is_deterministic(): void
    {
        $input = [
            'candidate_packet_id' => 'PKT-6',
            'candidate_packet_hash' => 'h6',
            'assigned_packet_hash' => 'h6',
            'candidate_allowed_files' => ['app/Shared.php'],
            'current_session_id' => 'sess-A',
            'active_reservations' => [
                ['reservation_id' => 'RES-7', 'owner_session_id' => 'sess-B', 'changed_files' => ['app/Shared.php']],
            ],
            'packet_dependency_status' => 'complete',
            'packet_completion_gate_status' => 'green',
        ];

        $result = $this->service()->evaluate($input);

        // Both owner_conflict (soft) and active_file_overlap (hard) fire; hard wins.
        $this->assertSame('block_claim', $result['decision']);
        $this->assertContains('active_file_overlap', $result['blocking_reasons']);
        $this->assertContains('owner_conflict', $result['blocking_reasons']);
        $this->assertSame('active_file_overlap', $result['blocker_code']);

        // guard_hash is present, non-empty and stable for identical inputs.
        $this->assertNotEmpty($result['guard_hash']);
        $this->assertSame($result['guard_hash'], $this->service()->evaluate($input)['guard_hash']);
    }

    /**
     * Doc: blueprint generation and evaluation are READ-ONLY and "cannot create
     * runtime files, write storage, persist claims or dispatch work". Every
     * result keeps all guarantee keys false and assertGuaranteeHeld finds no
     * violation across blueprint and decision results.
     */
    public function test_read_only_non_execution_guarantee_holds(): void
    {
        $service = $this->service();

        $results = [
            $service->blueprint(),
            $service->evaluate(['candidate_allowed_files' => ['app/X.php']]),
            $service->evaluate([
                'candidate_packet_id' => 'PKT-7',
                'candidate_packet_hash' => 'h7',
                'assigned_packet_hash' => 'h7',
                'candidate_allowed_files' => ['app/Y.php'],
                'packet_dependency_status' => 'complete',
                'packet_completion_gate_status' => 'green',
            ]),
        ];

        $this->assertSame([], $service->assertGuaranteeHeld($results));

        foreach ($results as $result) {
            $this->assertFalse($result['claim_persisted']);
            $this->assertFalse($result['is_execution']);
            foreach ($result['guarantee'] as $value) {
                $this->assertFalse($value);
            }
        }
    }
}
