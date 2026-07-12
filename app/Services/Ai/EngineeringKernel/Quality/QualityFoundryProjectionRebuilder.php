<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Quality;

use App\Services\Ai\EngineeringKernel\CanonicalKernelPayload;

/**
 * Rebuilds the constitutional projections from the canonical event stream.
 * It is deliberately read-only: callers compare the result with a live
 * projection and decide whether drift must quarantine the delivery.
 */
final class QualityFoundryProjectionRebuilder
{
    /**
     * @param  list<array<string,mixed>>  $events
     * @return array<string,mixed>
     */
    public function rebuild(array $events): array
    {
        $replayed = (new QualityFoundryEventContract)->replay($events);
        $roles = [];
        $acceptance = [
            'status' => 'unknown',
            'hash' => null,
        ];
        $release = [
            'status' => 'not_authorized',
            'hash' => null,
        ];
        $outcome = [
            'status' => 'unknown',
            'hash' => null,
        ];
        $claim = [
            'claim_eligible' => false,
            'status' => 'ineligible',
            'hash' => null,
        ];

        foreach ($replayed['events'] as $event) {
            $name = (string) $event['event_name'];
            $hash = (string) ($event['event_hash'] ?? CanonicalKernelPayload::hash($event));

            if ($name === 'role.disposition.recorded') {
                $role = trim((string) ($event['role'] ?? ''));
                if ($role !== '') {
                    $roles[$role] = [
                        'status' => (string) ($event['status'] ?? 'block'),
                        'hash' => $hash,
                    ];
                }
            }

            if ($name === 'acceptance.adjudicated') {
                $status = (string) ($event['verdict'] ?? $event['status'] ?? 'block');
                $acceptance = [
                    'status' => in_array($status, ['pass', 'passed', 'accepted'], true) ? 'passed' : 'blocked',
                    'hash' => $hash,
                ];
            }

            if ($name === 'release.authorized') {
                $release = ['status' => 'authorized', 'hash' => $hash];
            } elseif ($name === 'release.landed') {
                $release = ['status' => 'landed', 'hash' => $hash];
            } elseif ($name === 'release.reverted') {
                $release = ['status' => 'reverted', 'hash' => $hash];
            }

            if ($name === 'outcome.observed') {
                $outcome = [
                    'status' => (string) ($event['status'] ?? 'unknown'),
                    'hash' => $hash,
                ];
            }

            if ($name === 'claim.issued') {
                $claim = ['claim_eligible' => true, 'status' => 'issued', 'hash' => $hash];
            } elseif ($name === 'claim.revoked') {
                $claim = ['claim_eligible' => false, 'status' => 'revoked', 'hash' => $hash];
            }
        }

        return [
            'schema' => 'atlas.quality_foundry.projections.v1',
            'event_replay_hash' => $replayed['replay_hash'],
            'roles' => $roles,
            'acceptance' => $acceptance,
            'release' => $release,
            'outcome' => $outcome,
            'claim_eligibility' => $claim,
            'projection_hash' => CanonicalKernelPayload::hash([
                'roles' => $roles,
                'acceptance' => $acceptance,
                'release' => $release,
                'outcome' => $outcome,
                'claim_eligibility' => $claim,
            ]),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $events
     * @param  array<string,mixed>  $live
     * @return array<string,mixed>
     */
    public function compare(array $events, array $live): array
    {
        $rebuilt = $this->rebuild($events);
        $liveHash = (string) ($live['projection_hash'] ?? CanonicalKernelPayload::hash($live));

        return [
            'status' => hash_equals((string) $rebuilt['projection_hash'], $liveHash) ? 'match' : 'drift',
            'rebuilt_hash' => $rebuilt['projection_hash'],
            'live_hash' => $liveHash,
            'rebuilt' => $rebuilt,
        ];
    }
}
