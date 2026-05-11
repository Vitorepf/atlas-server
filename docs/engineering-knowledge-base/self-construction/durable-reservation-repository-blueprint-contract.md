---
id: atlas-ai-self-construction-durable-reservation-repository-blueprint-contract
type: engineering_knowledge
title: Atlas Self-Construction Durable Reservation Repository Blueprint Contract
status: active
category: architecture
priority: 100
summary: Read-only repository implementation blueprint for future durable reservation services, DTOs, errors, transactions and tests.
tags:
  - atlas-ai
  - self-construction
  - repository-blueprint
  - durable-reservation
capabilities:
  - self_construction_os
  - reservation_ledger
  - repository_blueprint
decisions:
  - Durable reservation runtime code must be generated from a blueprint with fixed classes, methods, DTOs, errors and tests.
  - Repository writes must append events before updating projections and must never bypass the collision guard.
  - Repository blueprint generation is read-only and cannot create PHP runtime files, write storage, persist claims or dispatch work.
maintenance:
  - Update before changing durable reservation repository class names, DTO names, method contracts, exception names or transaction rules.
related_paths:
  - docs/engineering-knowledge-base/self-construction/durable-reservation-repository-contract.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-migration-blueprint-contract.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-collision-guard-contract.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-collision-guard-blueprint-contract.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
---

# Atlas Self-Construction Durable Reservation Repository Blueprint Contract

This contract defines the future repository implementation shape for durable
packet reservations. It is not runtime code and must not create PHP files.

## Future Classes

- `App\Services\Ai\SelfConstruction\Reservations\DurableReservationRepository`;
- `App\Services\Ai\SelfConstruction\Reservations\DurableReservationCollisionGuard`;
- `App\Services\Ai\SelfConstruction\Reservations\ReservationEventHasher`;
- `App\Services\Ai\SelfConstruction\Reservations\ReservationProjectionBuilder`;
- `App\Services\Ai\SelfConstruction\Reservations\Data\ReservationClaimRequest`;
- `App\Services\Ai\SelfConstruction\Reservations\Data\ReservationClaimResult`;
- `App\Services\Ai\SelfConstruction\Reservations\Exceptions\ReservationRejectedException`.

## Required Repository Methods

- `preview(ReservationClaimRequest $request): ReservationClaimResult`;
- `claim(ReservationClaimRequest $request): ReservationClaimResult`;
- `renew(string $reservationId, string $actorId, DateTimeInterface $leaseExpiresAt): ReservationClaimResult`;
- `release(string $reservationId, string $actorId, string $reason): ReservationClaimResult`;
- `expire(DateTimeInterface $now): int`;
- `complete(string $reservationId, string $actorId, array $evidence): ReservationClaimResult`;
- `current(string $packetId): ?ReservationClaimResult`;
- `activeCollisions(array $allowedFiles): array`;
- `rebuildProjection(string $reservationId): ReservationClaimResult`;

## Transaction Rules

- Claim, renew, release, expire and complete run inside database transactions.
- Claim must lock packet scope before appending the event.
- Event hash must include previous event hash, actor, packet hash and payload.
- Projection updates only happen after accepted events.
- Completion requires packet completion gate evidence.
- Repository methods never dispatch work.

## Required Tests

- preview reports rejection without writing events;
- claim appends event and updates projection atomically;
- duplicate active packet claim is rejected;
- overlapping active file scope is rejected;
- stale packet hash is rejected;
- non-owner cannot renew, release or complete;
- event chain mismatch blocks projection update;
- repository blueprint command does not create PHP files or write storage.

## Completion Criteria

This contract is complete when Atlas emits a deterministic read-only repository
blueprint that future implementation can convert into PHP services and tests
without guessing class names, methods, errors or transaction boundaries.
