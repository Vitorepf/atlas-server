<?php

namespace App\Services\Ai\Programming\Forge;

use App\Models\AiForgeIntake;
use App\Models\AiForgeLongHorizonState;
use App\Models\AiForgeMilestone;

/**
 * Evaluates the 5 canonical milestone gates for an Atlas Forge Obra against
 * the current long-horizon state. Pure function over (milestone, state):
 *
 *   - evidence-driven gates pass when the mapped evidence_kind is present in
 *     `state.evidence_refs` (see {@see ForgeLongHorizonStateCanon::gateToEvidenceKindMap});
 *   - structural gates (`work_packets_scoped`, `no_unresolved_blockers`)
 *     consult the state's work packet projection and blockers list;
 *   - any unmapped gate that the planner declares is treated as `pending`
 *     until an explicit assertion arrives via cycle input.
 *
 * No persistence, no side effects: the caller composes the runner result
 * into the state via {@see ForgeLongHorizonStateService}.
 *
 * Architectural invariants:
 *  - the runner never decides milestone status — it returns evidence and
 *    reasons; the service decides transitions and blockers;
 *  - the runner never invokes providers or tools;
 *  - "passed" is binary: a gate is passed iff its rule is satisfied right
 *    now. There is no partial credit.
 */
final class ForgeMilestoneGateRunner
{
    /**
     * Evaluate every required gate for a milestone against an intake +
     * long-horizon state pair.
     *
     * @return array<string,mixed> canonical GateRunResult shape:
     *                             {
     *                             'milestone_id': string,
     *                             'gates': list<{gate_id:string,status:string,reason:string|null}>,
     *                             'evidence_present': list<string>,
     *                             'evidence_missing': list<string>,
     *                             'all_passed': bool,
     *                             'failure_reasons': list<string>,
     *                             }
     */
    public function evaluate(
        AiForgeIntake $intake,
        AiForgeMilestone $milestone,
        AiForgeLongHorizonState $state,
    ): array {
        $requiredGates = array_values((array) $milestone->required_gates);
        $requiredEvidence = array_values((array) $milestone->required_evidence);
        $evidenceKindsPresent = $this->evidenceKindsPresent($state);
        $evidenceMap = ForgeLongHorizonStateCanon::gateToEvidenceKindMap();
        $structural = ForgeLongHorizonStateCanon::structuralGates();

        $gates = [];
        $failureReasons = [];
        $packetCount = $intake->workPackets()->count();

        foreach ($requiredGates as $gateId) {
            $status = ForgeLongHorizonStateCanon::GATE_STATUS_PENDING;
            $reason = null;

            if (in_array($gateId, $structural, true)) {
                [$status, $reason] = $this->evaluateStructuralGate($gateId, $state, $milestone, $packetCount);
            } elseif (isset($evidenceMap[$gateId])) {
                $kind = $evidenceMap[$gateId];
                if (in_array($kind, $evidenceKindsPresent, true)) {
                    $status = ForgeLongHorizonStateCanon::GATE_STATUS_PASSED;
                } else {
                    $status = ForgeLongHorizonStateCanon::GATE_STATUS_FAILED;
                    $reason = 'missing_evidence:'.$kind;
                }
            } else {
                // Unmapped gate -> pending. Caller must assert via cycle input.
                $reason = 'unmapped_gate_awaiting_assertion';
            }

            $gates[] = [
                'gate_id' => $gateId,
                'status' => $status,
                'reason' => $reason,
            ];

            if ($status === ForgeLongHorizonStateCanon::GATE_STATUS_FAILED) {
                $failureReasons[] = $gateId.($reason !== null ? ':'.$reason : '');
            }
        }

        $evidenceMissing = array_values(array_diff($requiredEvidence, $evidenceKindsPresent));
        foreach ($evidenceMissing as $missingKind) {
            $failureReasons[] = 'missing_required_evidence:'.$missingKind;
        }

        $allPassed = $gates !== []
            && array_reduce(
                $gates,
                fn (bool $carry, array $g): bool => $carry && $g['status'] === ForgeLongHorizonStateCanon::GATE_STATUS_PASSED,
                true,
            )
            && $evidenceMissing === [];

        return [
            'milestone_id' => $milestone->milestone_id,
            'gates' => $gates,
            'evidence_present' => array_values(array_intersect($requiredEvidence, $evidenceKindsPresent)),
            'evidence_missing' => $evidenceMissing,
            'all_passed' => $allPassed,
            'failure_reasons' => array_values(array_unique($failureReasons)),
        ];
    }

    /**
     * @return array{0:string,1:string|null}
     */
    private function evaluateStructuralGate(
        string $gateId,
        AiForgeLongHorizonState $state,
        AiForgeMilestone $milestone,
        int $packetCount,
    ): array {
        if ($gateId === 'work_packets_scoped') {
            if ($packetCount >= 1) {
                return [ForgeLongHorizonStateCanon::GATE_STATUS_PASSED, null];
            }

            return [ForgeLongHorizonStateCanon::GATE_STATUS_FAILED, 'no_work_packets'];
        }

        if ($gateId === 'no_unresolved_blockers') {
            $blockers = array_values(array_filter(
                (array) ($state->blockers ?? []),
                static fn ($entry): bool => is_array($entry)
                    && ($entry['target'] ?? null) === $milestone->milestone_id
                    && ($entry['resolved'] ?? false) !== true,
            ));
            if ($blockers === []) {
                return [ForgeLongHorizonStateCanon::GATE_STATUS_PASSED, null];
            }

            return [ForgeLongHorizonStateCanon::GATE_STATUS_FAILED, 'unresolved_blockers:'.count($blockers)];
        }

        return [ForgeLongHorizonStateCanon::GATE_STATUS_PENDING, 'unknown_structural_gate'];
    }

    /**
     * @return array<int,string>
     */
    private function evidenceKindsPresent(AiForgeLongHorizonState $state): array
    {
        $kinds = [];
        foreach ((array) ($state->evidence_refs ?? []) as $ref) {
            if (! is_array($ref)) {
                continue;
            }
            $kind = $ref['kind'] ?? null;
            if (is_string($kind) && $kind !== '') {
                $kinds[] = $kind;
            }
        }

        return array_values(array_unique($kinds));
    }
}
