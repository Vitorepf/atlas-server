<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Quality;

use App\Services\Ai\EngineeringKernel\CanonicalKernelPayload;
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

    private function hash(array $event): string
    {
        unset($event['event_hash']);

        return CanonicalKernelPayload::hash($event);
    }
}
