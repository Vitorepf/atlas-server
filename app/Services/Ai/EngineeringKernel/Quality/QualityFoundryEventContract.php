<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Quality;

use App\Services\Ai\EngineeringKernel\CanonicalKernelPayload;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use InvalidArgumentException;

/** Canonical validation/replay contract for constitutional event projections. */
final class QualityFoundryEventContract
{
    public const SCHEMA = 'atlas.quality_foundry.event_contract.v1';

    public const EVENTS = [
        'experiment.preregistered', 'unit.frozen', 'execution.started',
        'operator.interval.closed', 'role.disposition.recorded', 'acceptance.adjudicated',
        'release.authorized', 'release.landed', 'release.reverted', 'outcome.observed',
        'learning.adjudicated', 'claim.evaluated', 'claim.issued', 'claim.revoked',
    ];

    /** @return array<string,mixed> */
    public function validate(array $event): array
    {
        foreach (['schema', 'event_name', 'run_id', 'delivery_id', 'occurred_at', 'provenance', 'idempotency_key'] as $key) {
            if (! is_string($event[$key] ?? null) || trim($event[$key]) === '') {
                throw new InvalidArgumentException('quality_foundry_event_'.$key.'_required');
            }
        }
        if ($event['schema'] !== self::SCHEMA || ! in_array($event['event_name'], self::EVENTS, true)) {
            throw new InvalidArgumentException('quality_foundry_event_name_or_schema_invalid');
        }
        if (! is_array($event['correlated_hashes'] ?? null) || $event['correlated_hashes'] === []) {
            throw new InvalidArgumentException('quality_foundry_event_correlated_hashes_required');
        }
        if (date_create_immutable($event['occurred_at']) === false) {
            throw new InvalidArgumentException('quality_foundry_event_timestamp_invalid');
        }

        return $event + ['event_hash' => $this->hash($event)];
    }

    /** @param list<array<string,mixed>> $events @return array<string,mixed> */
    public function replay(array $events): array
    {
        $seen = [];
        $previousPhase = -1;
        $accepted = [];
        foreach ($events as $event) {
            $validated = $this->validate($event);
            $key = $validated['idempotency_key'];
            $hash = $validated['event_hash'];
            if (isset($seen[$key])) {
                if ($seen[$key] !== $hash) {
                    throw new InvalidArgumentException('quality_foundry_event_idempotency_conflict');
                }

                continue;
            }
            $phase = array_search($validated['event_name'], self::EVENTS, true);
            if ($phase < $previousPhase) {
                throw new InvalidArgumentException('quality_foundry_event_order_invalid');
            }
            $previousPhase = $phase;
            $seen[$key] = $hash;
            $accepted[] = $validated;
        }

        return [
            'schema' => self::SCHEMA,
            'events' => $accepted,
            'event_count' => count($accepted),
            'replay_hash' => CanonicalKernelPayload::hash($accepted),
        ];
    }

    public function legacyStatus(array $row): string
    {
        return empty($row['provenance']) || empty($row['evidence_refs']) ? 'legacy_unproven' : 'proven';
    }

    public function observationStatus(?array $observation): string
    {
        return $observation === null ? 'unknown' : (string) ($observation['status'] ?? 'unknown');
    }

    /** Replay only contract-bearing events from the existing canonical ledger. */
    public function replayLedger(AtlasEvidenceLedger $ledger, string $scopeType, string $scopeId): array
    {
        $contractEvents = [];
        $legacyCount = 0;
        foreach ($ledger->eventsForScope($scopeType, $scopeId) as $row) {
            $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
            if (! is_string($payload['event_name'] ?? null) || trim($payload['event_name']) === '') {
                $legacyCount++;

                continue;
            }
            $contractEvents[] = [
                'schema' => $payload['schema'] ?? self::SCHEMA,
                'event_name' => $payload['event_name'],
                'run_id' => $payload['run_id'] ?? ($row['envelope_id'] ?? ''),
                'delivery_id' => $payload['delivery_id'] ?? ($row['correlation_id'] ?? ''),
                'occurred_at' => $row['occurred_at'] ?? ($payload['occurred_at'] ?? ''),
                'provenance' => $payload['provenance'] ?? ($row['emitter_stage'] ?? ''),
                'idempotency_key' => $payload['idempotency_key'] ?? ($row['event_id'] ?? ''),
                'correlated_hashes' => $payload['correlated_hashes'] ?? ($payload['hashes'] ?? []),
            ];
        }

        return $this->replay($contractEvents) + ['legacy_unproven_count' => $legacyCount];
    }

    /** @param array<string,mixed> $replayed @param array<string,mixed> $live */
    public function compareProjectionHashes(array $replayed, array $live): array
    {
        $replayedHash = CanonicalKernelPayload::hash($replayed);
        $liveHash = CanonicalKernelPayload::hash($live);

        return [
            'status' => hash_equals($replayedHash, $liveHash) ? 'match' : 'drift',
            'replayed_hash' => $replayedHash,
            'live_hash' => $liveHash,
        ];
    }

    private function hash(array $event): string
    {
        unset($event['event_hash']);

        return CanonicalKernelPayload::hash($event);
    }
}
