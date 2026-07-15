<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Quality;

use App\Services\Ai\EngineeringKernel\CanonicalKernelPayload;
use App\Services\Ai\EngineeringKernel\EngineeringOutcome;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;

/**
 * Rebuilds temporal outcome truth from append-only ledger events.
 *
 * This projection never infers an absent observation and never lets a later
 * observation erase an earlier one. A contradictory window is quarantined.
 */
final class QualityFoundryTemporalProjectionRebuilder
{
    public const SCHEMA = 'atlas.quality_foundry.temporal_projection.v1';

    /**
     * @param  list<array<string,mixed>>  $events
     * @return array<string,mixed>
     */
    public function rebuild(array $events): array
    {
        usort($events, static function (array $left, array $right): int {
            $occurred = strcmp((string) ($left['occurred_at'] ?? ''), (string) ($right['occurred_at'] ?? ''));

            return $occurred !== 0 ? $occurred : strcmp((string) ($left['event_id'] ?? ''), (string) ($right['event_id'] ?? ''));
        });

        $outcome = null;
        $windows = [];
        foreach (EngineeringOutcome::WINDOWS as $window) {
            $windows[$window] = [
                'state' => 'unknown',
                'observed_at' => null,
                'observations' => [],
            ];
        }
        $blockers = [];

        foreach ($events as $event) {
            $payload = $event['payload'] ?? null;
            if (! is_array($payload)) {
                continue;
            }
            $eventName = (string) ($payload['event_name'] ?? '');
            if ($eventName === 'engineering.outcome.recorded' && is_array($payload['outcome'] ?? null)) {
                $outcome = $this->outcomeProjection($payload['outcome']);

                continue;
            }
            if ($eventName !== 'outcome.observed' || ! is_array($payload['observation'] ?? null)) {
                continue;
            }

            $observation = $payload['observation'];
            $window = (string) ($observation['window'] ?? '');
            if (! isset($windows[$window])) {
                $blockers[] = 'temporal_window_invalid:'.$window;

                continue;
            }
            $observationHash = (string) ($payload['observation_hash'] ?? '');
            if ($observationHash === '') {
                $observationHash = CanonicalKernelPayload::hash($observation);
            }
            $status = (string) data_get($observation, 'metrics.status', 'unknown');
            $entry = [
                'event_id' => (string) ($event['event_id'] ?? ''),
                'event_hash' => (string) ($event['event_hash'] ?? ''),
                'observation_hash' => $observationHash,
                'status' => $status,
                'observed_at' => $observation['observed_at'] ?? null,
                'provenance' => (array) ($observation['provenance'] ?? []),
                'run_id' => $observation['run_id'] ?? null,
                'delivery_id' => $observation['delivery_id'] ?? null,
                'outcome_hash' => $observation['outcome_hash'] ?? null,
                'release_hash' => $observation['release_hash'] ?? null,
                'order_hash' => $observation['order_hash'] ?? null,
            ];
            $prior = $windows[$window]['observations'];
            if ($prior !== [] && ! $this->containsObservationHash($prior, $observationHash)) {
                $blockers[] = 'temporal_observation_contradictory:'.$window;
            }
            if (! $this->containsObservationHash($prior, $observationHash)) {
                $windows[$window]['observations'][] = $entry;
            }
        }

        if ($outcome === null) {
            $blockers[] = 'temporal_outcome_missing';
        } else {
            foreach ($windows as $window => $projection) {
                foreach ($projection['observations'] as $observation) {
                    $this->checkContext($observation, $outcome, $window, $blockers);
                }
            }
        }

        foreach ($windows as $window => &$projection) {
            if (count($projection['observations']) === 1) {
                $projection['state'] = 'observed';
                $projection['observed_at'] = $projection['observations'][0]['observed_at'];
            } elseif (count($projection['observations']) > 1) {
                $projection['state'] = 'contradictory';
                $projection['observed_at'] = null;
            }
        }
        unset($projection);

        $legacySchedule = (array) data_get($outcome, 'observation_schedule', []);
        foreach ($windows as $window => &$projection) {
            if ($projection['state'] === 'unknown' && ($legacySchedule[$window] ?? null) === 'legacy_unproven') {
                $projection['state'] = 'legacy_unproven';
                $blockers[] = 'temporal_window_legacy_unproven:'.$window;
            }
        }
        unset($projection);

        $blockers = array_values(array_unique($blockers));
        $allObserved = array_is_list($windows)
            ? false
            : array_all($windows, static fn (array $window): bool => $window['state'] === 'observed');
        $temporalState = $blockers !== [] ? 'blocked' : ($allObserved ? 'complete' : 'pending');
        $result = [
            'schema' => self::SCHEMA,
            'outcome' => $outcome,
            'windows' => $windows,
            'temporal_state' => $temporalState,
            'blockers' => $blockers,
        ];
        $result['projection_hash'] = CanonicalKernelPayload::hash($result);

        return $result;
    }

    /** Rebuild directly from the canonical ledger read model. */
    public function rebuildFromLedger(AtlasEvidenceLedger $ledger, string $deliveryId): array
    {
        return $this->rebuild($ledger->eventsForCorrelation($deliveryId));
    }

    /** @param array<string,mixed> $outcome @return array<string,mixed> */
    private function outcomeProjection(array $outcome): array
    {
        return [
            'run_id' => $outcome['run_id'] ?? null,
            'delivery_id' => $outcome['delivery_id'] ?? null,
            'status' => $outcome['status'] ?? 'unknown',
            'outcome_hash' => $outcome['outcome_hash'] ?? null,
            'correlated_hashes' => (array) ($outcome['correlated_hashes'] ?? []),
            'observation_schedule' => (array) ($outcome['observation_schedule'] ?? []),
        ];
    }

    /** @param list<array<string,mixed>> $observations */
    private function containsObservationHash(array $observations, string $hash): bool
    {
        foreach ($observations as $observation) {
            if (($observation['observation_hash'] ?? null) === $hash) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $observation @param array<string,mixed> $outcome @param list<string> $blockers */
    private function checkContext(array $observation, array $outcome, string $window, array &$blockers): void
    {
        $checks = [
            'run_id' => $outcome['run_id'] ?? null,
            'delivery_id' => $outcome['delivery_id'] ?? null,
            'outcome_hash' => $outcome['outcome_hash'] ?? null,
            'release_hash' => data_get($outcome, 'correlated_hashes.release'),
            'order_hash' => data_get($outcome, 'correlated_hashes.order'),
            'spec_hash' => data_get($outcome, 'correlated_hashes.spec'),
            'world_hash' => data_get($outcome, 'correlated_hashes.world'),
        ];
        foreach ($checks as $key => $expected) {
            $actual = $key === 'spec_hash' || $key === 'world_hash'
                ? data_get($observation, 'provenance.'.$key)
                : ($observation[$key] ?? null);
            if ($actual !== null && $expected !== null && ! hash_equals((string) $expected, (string) $actual)) {
                $blockers[] = 'temporal_observation_context_mismatch:'.$window.':'.$key;
            }
        }
    }
}
