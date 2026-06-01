<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDurableReservationRepositoryBlueprintContractService;
use Tests\TestCase;

/**
 * Pins the documented Durable Reservation Repository blueprint: the seven Future
 * Classes, the nine Required Repository Methods, the Transaction Rules (which
 * methods open a transaction, claim locks packet scope first, the four event-hash
 * inputs, projection-after-accepted-event ordering, completion gate evidence,
 * never dispatch work) and the read-only non-execution guarantee.
 *
 * Pure in-memory decision logic — no database.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-repository-blueprint-contract.md
 */
class AtlasDurableReservationRepositoryBlueprintContractTest extends TestCase
{
    private function service(): AtlasDurableReservationRepositoryBlueprintContractService
    {
        return new AtlasDurableReservationRepositoryBlueprintContractService();
    }

    /**
     * Doc "Future Classes" + "Required Repository Methods": the blueprint names
     * the exact seven classes and nine methods future runtime must build.
     */
    public function test_blueprint_declares_future_classes_and_required_methods(): void
    {
        $blueprint = $this->service()->blueprint();

        // Seven future classes with their documented FQCNs.
        $this->assertSame(
            'App\\Services\\Ai\\SelfConstruction\\Reservations\\DurableReservationRepository',
            $blueprint['future_classes']['repository'],
        );
        $this->assertSame(
            'App\\Services\\Ai\\SelfConstruction\\Reservations\\Exceptions\\ReservationRejectedException',
            $blueprint['future_classes']['rejected_exception'],
        );
        $this->assertCount(7, $blueprint['future_classes']);

        // Nine required methods, exactly the documented set.
        $this->assertSame(
            ['preview', 'claim', 'renew', 'release', 'expire', 'complete', 'current', 'activeCollisions', 'rebuildProjection'],
            array_keys($blueprint['required_methods']),
        );
        // Signatures carry the documented return types.
        $this->assertSame(
            'expire(DateTimeInterface $now): int',
            $blueprint['required_methods']['expire'],
        );
        $this->assertSame(
            'current(string $packetId): ?ReservationClaimResult',
            $blueprint['required_methods']['current'],
        );
    }

    /**
     * Doc Transaction Rule 1: "Claim, renew, release, expire and complete run
     * inside database transactions." Exactly those five; the four read-only
     * methods do not.
     */
    public function test_only_the_five_write_methods_require_a_transaction(): void
    {
        $service = $this->service();

        foreach (['claim', 'renew', 'release', 'expire', 'complete'] as $method) {
            $this->assertTrue($service->requiresTransaction($method), "{$method} must be transactional");
        }

        foreach (['preview', 'current', 'activeCollisions', 'rebuildProjection'] as $method) {
            $this->assertFalse($service->requiresTransaction($method), "{$method} must be read-only");
        }

        // An unknown method is not transactional.
        $this->assertFalse($service->requiresTransaction('drop'));
    }

    /**
     * Doc Transaction Rule 2: "Claim must lock packet scope before appending the
     * event." Only claim acquires the packet-scope lock.
     */
    public function test_only_claim_requires_packet_scope_lock(): void
    {
        $service = $this->service();

        $this->assertTrue($service->requiresPacketLock('claim'));

        foreach (['renew', 'release', 'expire', 'complete', 'preview'] as $method) {
            $this->assertFalse($service->requiresPacketLock($method), "{$method} must not lock packet scope");
        }

        // A claim write that skips the lock is rejected, even when everything
        // else is correct.
        $unlocked = $service->validateWriteOrder([
            'method' => 'claim',
            'transaction_opened' => true,
            'collision_guard_ran' => true,
            'packet_scope_locked' => false,
            'event_accepted' => true,
            'event_appended' => true,
            'projection_updated' => true,
        ]);
        $this->assertSame('reject', $unlocked['verdict']);
        $this->assertContains('packet_scope_not_locked_before_event', $unlocked['reasons']);
    }

    /**
     * Doc Transaction Rule 3: "Event hash must include previous event hash,
     * actor, packet hash and payload." All four are required; a missing one
     * fails closed.
     */
    public function test_event_hash_requires_all_four_documented_inputs(): void
    {
        $service = $this->service();

        $this->assertSame(
            ['previous_event_hash', 'actor', 'packet_hash', 'payload'],
            $service->eventHashInputs(),
        );

        // Complete set => valid (no missing components).
        $this->assertSame([], $service->validateEventHashInputs([
            'previous_event_hash' => 'sha256:prev',
            'actor' => 'session-owner',
            'packet_hash' => 'sha256:packet',
            'payload' => ['k' => 'v'],
        ]));

        // Dropping the previous hash and the actor reports exactly those two.
        $missing = $service->validateEventHashInputs([
            'packet_hash' => 'sha256:packet',
            'payload' => ['k' => 'v'],
            'actor' => '',
        ]);
        $this->assertSame(['previous_event_hash', 'actor'], $missing);
    }

    /**
     * Doc invariant: "Repository writes must append events before updating
     * projections and must never bypass the collision guard" + "Projection
     * updates only happen after accepted events." A correct claim step is
     * allowed; a step that updates the projection before the event is appended,
     * or on a rejected event, or with the guard bypassed, is rejected.
     */
    public function test_write_order_enforces_event_before_projection_and_collision_guard(): void
    {
        $service = $this->service();

        // Happy path: tx open, guard ran, scope locked, event accepted+appended,
        // then projection updated => allow.
        $ok = $service->validateWriteOrder([
            'method' => 'claim',
            'transaction_opened' => true,
            'collision_guard_ran' => true,
            'packet_scope_locked' => true,
            'event_accepted' => true,
            'event_appended' => true,
            'projection_updated' => true,
        ]);
        $this->assertSame('allow', $ok['verdict']);
        $this->assertSame([], $ok['reasons']);

        // Projection updated before the event was appended => reject.
        $beforeEvent = $service->validateWriteOrder([
            'method' => 'renew',
            'transaction_opened' => true,
            'collision_guard_ran' => true,
            'event_accepted' => true,
            'event_appended' => false,
            'projection_updated' => true,
        ]);
        $this->assertSame('reject', $beforeEvent['verdict']);
        $this->assertContains('projection_updated_before_event_appended', $beforeEvent['reasons']);

        // Projection updated on a rejected (not accepted) event => reject.
        $rejectedEvent = $service->validateWriteOrder([
            'method' => 'release',
            'transaction_opened' => true,
            'collision_guard_ran' => true,
            'event_accepted' => false,
            'event_appended' => true,
            'projection_updated' => true,
        ]);
        $this->assertSame('reject', $rejectedEvent['verdict']);
        $this->assertContains('projection_updated_on_rejected_event', $rejectedEvent['reasons']);

        // Collision guard bypassed => reject.
        $bypass = $service->validateWriteOrder([
            'method' => 'claim',
            'transaction_opened' => true,
            'collision_guard_ran' => false,
            'packet_scope_locked' => true,
            'event_accepted' => true,
            'event_appended' => true,
            'projection_updated' => true,
        ]);
        $this->assertSame('reject', $bypass['verdict']);
        $this->assertContains('collision_guard_bypassed', $bypass['reasons']);
    }

    /**
     * Doc Transaction Rules tail: "Completion requires packet completion gate
     * evidence" and "Repository methods never dispatch work". A complete step
     * with no gate evidence, and any step that dispatches work, are rejected.
     */
    public function test_completion_needs_gate_evidence_and_methods_never_dispatch(): void
    {
        $service = $this->service();

        // complete with no completion-gate evidence => reject.
        $noGate = $service->validateWriteOrder([
            'method' => 'complete',
            'transaction_opened' => true,
            'collision_guard_ran' => true,
            'event_accepted' => true,
            'event_appended' => true,
            'projection_updated' => true,
            'completion_gate_evidence' => false,
        ]);
        $this->assertSame('reject', $noGate['verdict']);
        $this->assertContains('completion_gate_evidence_missing', $noGate['reasons']);

        // complete WITH gate evidence => allow.
        $withGate = $service->validateWriteOrder([
            'method' => 'complete',
            'transaction_opened' => true,
            'collision_guard_ran' => true,
            'event_accepted' => true,
            'event_appended' => true,
            'projection_updated' => true,
            'completion_gate_evidence' => true,
        ]);
        $this->assertSame('allow', $withGate['verdict']);

        // Any write that dispatches work => reject.
        $dispatch = $service->validateWriteOrder([
            'method' => 'expire',
            'transaction_opened' => true,
            'collision_guard_ran' => true,
            'event_accepted' => true,
            'event_appended' => true,
            'projection_updated' => true,
            'work_dispatched' => true,
        ]);
        $this->assertSame('reject', $dispatch['verdict']);
        $this->assertContains('repository_method_dispatched_work', $dispatch['reasons']);
    }

    /**
     * Read-only methods (preview/current/activeCollisions/rebuildProjection)
     * write nothing, so a step naming one is allowed with no ordering burden;
     * and the non-execution guarantee holds on every path (allow, reject,
     * blueprint).
     */
    public function test_read_only_methods_allowed_and_guarantee_holds_everywhere(): void
    {
        $service = $this->service();

        // preview reports without writing events => allowed even with nothing set.
        $preview = $service->validateWriteOrder(['method' => 'preview']);
        $this->assertSame('allow', $preview['verdict']);
        $this->assertFalse($preview['requires_transaction']);

        // Unknown method => reject.
        $unknown = $service->validateWriteOrder(['method' => 'truncate']);
        $this->assertSame('reject', $unknown['verdict']);
        $this->assertContains('unknown_method', $unknown['reasons']);

        $reject = $service->validateWriteOrder([
            'method' => 'claim',
            'transaction_opened' => false,
        ]);
        $blueprint = $service->blueprint();

        foreach ([$preview, $unknown, $reject, $blueprint] as $packet) {
            $this->assertFalse($packet['claim_persisted']);
            $this->assertFalse($packet['is_execution']);
            $this->assertSame([
                'runtime_files_created' => false,
                'storage_writes_performed' => false,
                'claims_persisted' => false,
                'work_dispatched' => false,
            ], $packet['guarantee']);
        }

        $this->assertSame([], $service->assertGuaranteeHeld([$preview, $unknown, $reject, $blueprint]));
    }
}
