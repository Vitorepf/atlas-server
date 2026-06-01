<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDurableReservationCollisionGuardContractService;
use Tests\TestCase;

/**
 * Pins the documented Durable Reservation Collision Guard contract: the six
 * Blocking Decisions, the deterministic decision routing
 * (allow_preview | block_claim | require_human_review), the Required Outputs and
 * the read-only non-execution guarantee.
 *
 * Pure in-memory decision logic — no database.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-collision-guard-contract.md
 */
class AtlasDurableReservationCollisionGuardContractTest extends TestCase
{
    private function service(): AtlasDurableReservationCollisionGuardContractService
    {
        return new AtlasDurableReservationCollisionGuardContractService();
    }

    /**
     * A fully-clean candidate disjoint from all active reservations, with a
     * matching hash, all dependencies complete and a green completion gate.
     * Individual tests then break exactly one dimension.
     *
     * @return array<string,mixed>
     */
    private function cleanInput(): array
    {
        return [
            'candidate_packet_id' => 'pkt-clean',
            'candidate_allowed_files' => ['app/Services/Reservation/Foo.php'],
            'candidate_forbidden_files' => [],
            'candidate_packet_hash' => 'hash-a',
            'assigned_packet_hash' => 'hash-a',
            'candidate_dependency_ids' => ['dep-1'],
            'completed_dependency_ids' => ['dep-1', 'dep-2'],
            'active_reservations' => [
                [
                    'reservation_id' => 'res-other',
                    'owner_session_id' => 'session-other',
                    'changed_files' => ['app/Services/Other/Bar.php'],
                ],
            ],
            'hot_forbidden_scope' => ['app/Services/Ai/Voice', 'app/Services/Ai/Kernel'],
            'current_session_id' => 'session-me',
            'completion_gate_status' => 'green',
        ];
    }

    /** Required Test: clean disjoint packet remains preview-allowable. */
    public function test_clean_disjoint_packet_is_preview_allowable(): void
    {
        $result = $this->service()->evaluate($this->cleanInput());

        $this->assertSame('allow_preview', $result['decision']);
        $this->assertSame([], $result['blocking_reasons']);
        $this->assertSame([], $result['overlapping_files']);
        $this->assertSame([], $result['hot_scope_matches']);
        $this->assertSame([], $result['dependency_blockers']);
        // allow_preview routes forward to the post-approval preflight.
        $this->assertSame('atlas:aaeos:durable-reservation-post-approval-preflight', $result['required_next_command']);
    }

    /** Required Test: hot Voice/Kernel scope is blocked (hard => block_claim). */
    public function test_hot_voice_kernel_scope_is_blocked(): void
    {
        $input = $this->cleanInput();
        $input['candidate_allowed_files'] = ['app/Services/Ai/Voice'];

        $result = $this->service()->evaluate($input);

        $this->assertSame('block_claim', $result['decision']);
        $this->assertContains('hot_scope_forbidden', $result['blocking_reasons']);
        $this->assertSame(['app/Services/Ai/Voice'], $result['hot_scope_matches']);
    }

    /** Required Test: overlapping active file scope is blocked (hard). */
    public function test_overlapping_active_file_scope_is_blocked(): void
    {
        $input = $this->cleanInput();
        // Same session owns the colliding lease, so this is overlap WITHOUT an
        // owner conflict — isolating active_file_overlap as the hard blocker.
        $input['active_reservations'] = [[
            'reservation_id' => 'res-mine',
            'owner_session_id' => 'session-me',
            'changed_files' => ['app/Services/Reservation/Foo.php'],
        ]];

        $result = $this->service()->evaluate($input);

        $this->assertSame('block_claim', $result['decision']);
        $this->assertContains('active_file_overlap', $result['blocking_reasons']);
        $this->assertSame(['app/Services/Reservation/Foo.php'], $result['overlapping_files']);
        $this->assertSame(['res-mine'], $result['active_reservation_ids']);
        // Same owner => no owner_conflict blocker.
        $this->assertNotContains('owner_conflict', $result['blocking_reasons']);
    }

    /** Required Test: stale packet hash is blocked (hard). */
    public function test_stale_packet_hash_is_blocked(): void
    {
        $input = $this->cleanInput();
        $input['candidate_packet_hash'] = 'hash-changed';

        $result = $this->service()->evaluate($input);

        $this->assertSame('block_claim', $result['decision']);
        $this->assertContains('packet_hash_stale', $result['blocking_reasons']);
        $this->assertSame(
            [['assigned' => 'hash-a', 'candidate' => 'hash-changed']],
            $result['stale_hashes'],
        );
    }

    /** Required Test: incomplete dependency is blocked (hard). */
    public function test_incomplete_dependency_is_blocked(): void
    {
        $input = $this->cleanInput();
        $input['candidate_dependency_ids'] = ['dep-1', 'dep-missing'];

        $result = $this->service()->evaluate($input);

        $this->assertSame('block_claim', $result['decision']);
        $this->assertContains('dependency_incomplete', $result['blocking_reasons']);
        $this->assertSame(['dep-missing'], $result['dependency_blockers']);
    }

    /**
     * Decision routing: when ONLY soft blockers fire (owner conflict and/or a
     * non-green completion gate) with no hard blocker, the guard routes to
     * require_human_review rather than a flat block.
     */
    public function test_soft_blockers_alone_require_human_review(): void
    {
        $input = $this->cleanInput();
        // Non-green completion gate => completion_gate_blocked (soft) only.
        $input['completion_gate_status'] = 'pending';

        $result = $this->service()->evaluate($input);

        $this->assertSame('require_human_review', $result['decision']);
        $this->assertSame(['completion_gate_blocked'], $result['blocking_reasons']);
        $this->assertSame('atlas:aaeos:durable-reservation-approval-request', $result['required_next_command']);
    }

    /**
     * A hard blocker always dominates a co-occurring soft blocker: an
     * overlapping lease owned by another session fires BOTH active_file_overlap
     * (hard) and owner_conflict (soft), and the decision is block_claim.
     */
    public function test_hard_blocker_dominates_co_occurring_soft_blocker(): void
    {
        $input = $this->cleanInput();
        $input['active_reservations'] = [[
            'reservation_id' => 'res-other',
            'owner_session_id' => 'session-other',
            'changed_files' => ['app/Services/Reservation/Foo.php'],
        ]];

        $result = $this->service()->evaluate($input);

        $this->assertSame('block_claim', $result['decision']);
        $this->assertContains('active_file_overlap', $result['blocking_reasons']);
        $this->assertContains('owner_conflict', $result['blocking_reasons']);
    }

    /**
     * Required Test: collision guard command does not persist claims or write
     * storage — the non-execution guarantee holds on every decision path.
     */
    public function test_guarantee_holds_on_every_path(): void
    {
        $service = $this->service();

        $allow = $service->evaluate($this->cleanInput());
        $blocked = $service->evaluate(array_merge($this->cleanInput(), ['candidate_packet_hash' => 'x']));
        $review = $service->evaluate(array_merge($this->cleanInput(), ['completion_gate_status' => 'pending']));

        foreach ([$allow, $blocked, $review] as $packet) {
            $this->assertFalse($packet['claim_persisted']);
            $this->assertFalse($packet['is_execution']);
            $this->assertSame([
                'claims_persisted' => false,
                'storage_writes_performed' => false,
                'migrations_created' => false,
                'dispatch_enabled' => false,
            ], $packet['guarantee']);
        }

        $this->assertSame([], $service->assertGuaranteeHeld([$allow, $blocked, $review]));
    }
}
