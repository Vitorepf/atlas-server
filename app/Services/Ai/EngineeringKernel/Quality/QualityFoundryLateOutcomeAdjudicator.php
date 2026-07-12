<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Quality;

use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Rivals\Core\RivalsClaimAuthority;

/** Routes late adverse evidence to hold/review owners without mutating claims. */
final class QualityFoundryLateOutcomeAdjudicator
{
    public function __construct(private readonly RivalsClaimAuthority $claims) {}

    /** @param array<string,mixed> $projection @param array<string,mixed>|null $claim @return array<string,mixed> */
    public function adjudicate(array $projection, ?array $claim = null, ?AtlasEvidenceLedger $ledger = null): array
    {
        $adverse = $this->adverseObservations($projection);
        if ($adverse === []) {
            return [
                'status' => 'no_action',
                'learning_hold' => false,
                'rivals_evaluation' => false,
                'claim' => $claim,
            ];
        }

        $projectionHash = (string) ($projection['projection_hash'] ?? hash('sha256', json_encode($projection, JSON_THROW_ON_ERROR)));
        $reason = 'late_adverse_outcome:'.implode(',', $adverse);
        $learningEvent = $this->record($ledger, LedgerEventType::LearningProposed, [
            'event_name' => 'learning.hold.requested',
            'reason' => $reason,
            'projection_hash' => $projectionHash,
            'adverse_windows' => $adverse,
        ], 'learning-hold-'.$projectionHash);
        $evaluationEvent = $this->record($ledger, LedgerEventType::LearningProposed, [
            'event_name' => 'rivals.claim.evaluation.requested',
            'reason' => $reason,
            'projection_hash' => $projectionHash,
            'adverse_windows' => $adverse,
            'claim_id' => $claim['claim_id'] ?? null,
        ], 'rivals-evaluation-'.$projectionHash);

        $revokedClaim = null;
        if (is_array($claim) && ($claim['status'] ?? null) === 'issued') {
            $revokedClaim = $this->claims->revoke($claim, $reason);
        }

        return [
            'status' => 'held',
            'learning_hold' => true,
            'rivals_evaluation' => true,
            'adverse_windows' => $adverse,
            'claim' => $revokedClaim,
            'claim_mutated_directly' => false,
            'ledger_events' => [
                'learning_hold' => $learningEvent !== null,
                'rivals_evaluation' => $evaluationEvent !== null,
            ],
        ];
    }

    /** @param array<string,mixed> $projection @return list<string> */
    private function adverseObservations(array $projection): array
    {
        $adverse = [];
        foreach ((array) ($projection['windows'] ?? []) as $window => $data) {
            if (($data['state'] ?? null) === 'contradictory') {
                $adverse[] = (string) $window;
                continue;
            }
            foreach ((array) ($data['observations'] ?? []) as $observation) {
                $status = strtolower(trim((string) ($observation['status'] ?? '')));
                if (in_array($status, ['adverse', 'failed', 'failure', 'regressed', 'rollback', 'incident'], true)) {
                    $adverse[] = (string) $window;
                }
            }
        }

        return array_values(array_unique($adverse));
    }

    /** @param array<string,mixed> $payload */
    private function record(?AtlasEvidenceLedger $ledger, LedgerEventType $type, array $payload, string $eventId): mixed
    {
        if (! $ledger instanceof AtlasEvidenceLedger) {
            return null;
        }

        return $ledger->record($type, $payload, [
            'event_id' => $eventId,
            'correlation_id' => (string) ($payload['projection_hash'] ?? $eventId),
            'scope_type' => 'engineering_temporal_outcome',
            'scope_id' => (string) ($payload['projection_hash'] ?? $eventId),
            'emitter_stage' => 'atlas.engineering_kernel.late_outcome',
        ]);
    }
}
