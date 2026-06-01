<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDurableReservationImplementationPacketService;
use Tests\TestCase;

/**
 * Pins the documented Durable Reservation Implementation Packet contract: the six
 * ordered build steps, the seven required gates, the four hard limits and the
 * Completion Criterion that the packet stays BLOCKED until approval and preflight
 * pass.
 *
 * Pure in-memory decision logic — no database.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-implementation-packet.md
 */
class AtlasDurableReservationImplementationPacketTest extends TestCase
{
    private function service(): AtlasDurableReservationImplementationPacketService
    {
        return new AtlasDurableReservationImplementationPacketService();
    }

    /** A state where approval + preflight hold and the build is fully done. */
    private function completedState(): array
    {
        return [
            'approval_signed' => true,
            'preflight_passed' => true,
            'completed_steps' => AtlasDurableReservationImplementationPacketService::PACKET_ORDER,
            'gate_results' => array_fill_keys(
                AtlasDurableReservationImplementationPacketService::REQUIRED_GATES,
                true
            ),
        ];
    }

    /**
     * Doc "Packet Order" / "Required Gates" / "Hard Limits": the three closed sets
     * have exactly the documented cardinalities and the order begins with the
     * storage contract step.
     */
    public function test_packet_pins_six_steps_seven_gates_four_limits_in_order(): void
    {
        $svc = $this->service();
        $packet = $svc->packet();

        $this->assertCount(6, $packet['packet_order']);
        $this->assertCount(7, $packet['required_gates']);
        $this->assertCount(4, $packet['hard_limits']);

        // Documented Packet Order: storage is step 1, multi-session is step 6.
        $this->assertSame(
            AtlasDurableReservationImplementationPacketService::STEP_STORAGE,
            $packet['packet_order'][0]
        );
        $this->assertSame(
            AtlasDurableReservationImplementationPacketService::STEP_MULTI_SESSION,
            $packet['packet_order'][5]
        );

        $this->assertTrue($packet['ready']);
    }

    /**
     * Doc "Completion Criteria": the read-only default is BLOCKED. With neither
     * approval nor preflight, evaluate() must block and name the missing approval
     * first.
     */
    public function test_default_state_is_blocked_until_approval_and_preflight(): void
    {
        $svc = $this->service();

        $blocked = $svc->evaluate([]);
        $this->assertSame('blocked', $blocked['status']);
        $this->assertTrue($blocked['blocked']);
        $this->assertSame('approval_not_signed', $blocked['blocking_reason']);
        $this->assertFalse($blocked['completion_criteria_met']);

        // Approval signed but preflight still missing => still blocked, now on
        // preflight.
        $halfway = $svc->evaluate(['approval_signed' => true]);
        $this->assertSame('blocked', $halfway['status']);
        $this->assertSame('preflight_not_passed', $halfway['blocking_reason']);
    }

    /**
     * Doc "Completion Criteria": only when approval + preflight hold AND every gate
     * passed AND every step is complete is the packet ready_to_complete. If a gate
     * is still open the prerequisites hold but the status is in_progress, never
     * complete.
     */
    public function test_ready_to_complete_only_when_prereqs_gates_and_steps_all_green(): void
    {
        $svc = $this->service();

        $done = $svc->evaluate($this->completedState());
        $this->assertSame('ready_to_complete', $done['status']);
        $this->assertFalse($done['blocked']);
        $this->assertTrue($done['completion_criteria_met']);
        $this->assertNull($done['next_step']);

        // Drop one gate: prerequisites still hold, but it must fall back to
        // in_progress (not ready, not blocked).
        $state = $this->completedState();
        unset($state['gate_results'][AtlasDurableReservationImplementationPacketService::GATE_DOCS_HEALTH]);
        $oneGateOpen = $svc->evaluate($state);
        $this->assertSame('in_progress', $oneGateOpen['status']);
        $this->assertFalse($oneGateOpen['completion_criteria_met']);
        $this->assertContains(
            AtlasDurableReservationImplementationPacketService::GATE_DOCS_HEALTH,
            $oneGateOpen['gates']['missing']
        );
    }

    /**
     * Doc "Packet Order": nextStep() returns the EARLIEST incomplete step in
     * documented order, and flags later steps marked done while an earlier one is
     * still open as out_of_order (the build cannot skip ahead).
     */
    public function test_next_step_is_earliest_open_and_flags_out_of_order(): void
    {
        $svc = $this->service();

        // Only step 1 done => next is the event repository (step 2).
        $afterStorage = $svc->nextStep([
            AtlasDurableReservationImplementationPacketService::STEP_STORAGE,
        ]);
        $this->assertSame(
            AtlasDurableReservationImplementationPacketService::STEP_EVENT_REPOSITORY,
            $afterStorage['next_step']
        );
        $this->assertFalse($afterStorage['order_complete']);
        $this->assertSame([], $afterStorage['out_of_order']);

        // Step 1 + step 3 done but step 2 skipped => next is still step 2, and the
        // jumped-ahead step 3 is reported out of order.
        $skipped = $svc->nextStep([
            AtlasDurableReservationImplementationPacketService::STEP_STORAGE,
            AtlasDurableReservationImplementationPacketService::STEP_PROJECTION,
        ]);
        $this->assertSame(
            AtlasDurableReservationImplementationPacketService::STEP_EVENT_REPOSITORY,
            $skipped['next_step']
        );
        $this->assertContains(
            AtlasDurableReservationImplementationPacketService::STEP_PROJECTION,
            $skipped['out_of_order']
        );
        $this->assertFalse($skipped['order_complete']);
    }

    /**
     * Doc "Hard Limits": each of the four prohibitions fires on its trigger, in
     * order, and stops at the earliest violation.
     */
    public function test_hard_limits_fire_on_each_documented_prohibition(): void
    {
        $svc = $this->service();

        // 1. No dispatch implementation.
        $dispatch = $svc->checkHardLimits(['wants_dispatch' => true]);
        $this->assertFalse($dispatch['ok']);
        $this->assertSame(
            AtlasDurableReservationImplementationPacketService::LIMIT_NO_DISPATCH,
            $dispatch['violated_limit']
        );

        // 2. No Voice/Kernel file edits.
        $voice = $svc->checkHardLimits([
            'touched_files' => ['app/Services/Ai/Voice/SomeRealtime.php'],
        ]);
        $this->assertFalse($voice['ok']);
        $this->assertSame(
            AtlasDurableReservationImplementationPacketService::LIMIT_NO_VOICE_KERNEL_EDITS,
            $voice['violated_limit']
        );
        $this->assertContains('app/Services/Ai/Voice/SomeRealtime.php', $voice['hot_scope_matches']);

        // 3. No claim completion without the packet completion gate.
        $completion = $svc->checkHardLimits([
            'wants_completion' => true,
            'packet_completion_gate_passed' => false,
        ]);
        $this->assertFalse($completion['ok']);
        $this->assertSame(
            AtlasDurableReservationImplementationPacketService::LIMIT_NO_COMPLETION_WITHOUT_GATE,
            $completion['violated_limit']
        );

        // 4. No migration/storage without signed approval AND passed preflight.
        $storage = $svc->checkHardLimits([
            'wants_storage_or_migration' => true,
            'approval_signed' => true,
            'preflight_passed' => false,
        ]);
        $this->assertFalse($storage['ok']);
        $this->assertSame(
            AtlasDurableReservationImplementationPacketService::LIMIT_NO_STORAGE_WITHOUT_APPROVAL,
            $storage['violated_limit']
        );

        // Same storage intent WITH both approval + preflight => allowed.
        $allowedStorage = $svc->checkHardLimits([
            'wants_storage_or_migration' => true,
            'approval_signed' => true,
            'preflight_passed' => true,
        ]);
        $this->assertTrue($allowedStorage['ok']);
        $this->assertNull($allowedStorage['violated_limit']);
    }

    /**
     * Doc Hard Limit #4 interaction with Completion Criteria: even with approval +
     * preflight signed, asking for storage/migration when preflight is NOT passed
     * is impossible (limit covers it); but a fired limit while prereqs hold keeps
     * the packet blocked rather than in_progress. Here we trip the dispatch limit
     * after prereqs are satisfied.
     */
    public function test_fired_hard_limit_blocks_even_when_prereqs_hold(): void
    {
        $svc = $this->service();

        $state = $this->completedState();
        $state['wants_dispatch'] = true; // forbidden

        $decision = $svc->evaluate($state);
        $this->assertSame('blocked', $decision['status']);
        $this->assertTrue($decision['blocked']);
        $this->assertSame(
            AtlasDurableReservationImplementationPacketService::LIMIT_NO_DISPATCH,
            $decision['blocking_reason']
        );
        $this->assertFalse($decision['completion_criteria_met']);
    }

    /**
     * The read-only Non Goal guarantee holds across every emitted surface: no
     * result ever flips dispatch, migration, storage, claim completion, hot-scope
     * edit or execution to true.
     */
    public function test_read_only_non_goal_guarantee_holds(): void
    {
        $svc = $this->service();

        $results = [
            $svc->packet(),
            $svc->evaluate([]),
            $svc->evaluate($this->completedState()),
            $svc->nextStep([]),
            $svc->gatesSatisfied([]),
            $svc->checkHardLimits(['wants_dispatch' => true]),
        ];

        $this->assertSame([], $svc->assertGuaranteeHeld($results));

        foreach ($results as $result) {
            $this->assertFalse($result['non_goals']['grants_dispatch']);
            $this->assertFalse($result['non_goals']['creates_migration']);
            $this->assertFalse($result['non_goals']['writes_storage']);
            $this->assertFalse($result['non_goals']['completes_claim']);
        }
    }
}
