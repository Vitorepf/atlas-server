<?php
declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Ap786RealCycleCertificationService;

final class PlanDeliveryCertificationServiceSupport
{
    public const SLICE_BLOCKER_NO_PROVIDER_PROOF = 'no_provider_proof';

    public const SLICE_BLOCKER_ACCEPTANCE_NOT_MET = 'acceptance_not_met';

    public const SLICE_BLOCKER_NO_CERTIFIED_REAL_CYCLE = 'no_certified_real_cycle';

    /**
     * @param  list<string>  $sessionIds
     * @return array<string,bool>
     */
    public function certifiedFindingIds(array $sessionIds, object $realCycleCert): array
    {
        $findingIds = [];
        $certifiedStatus = Ap786RealCycleCertificationService::STATUS_CERTIFIED;

        foreach ($sessionIds as $sessionId) {
            $report = $realCycleCert->certify(['session_id' => $sessionId]);
            if (! is_array($report)) {
                continue;
            }

            $cycles = is_array($report['cycles'] ?? null) ? $report['cycles'] : [];
            foreach ($cycles as $cycle) {
                if (! is_array($cycle)) {
                    continue;
                }
                if ((string) ($cycle['status'] ?? '') !== $certifiedStatus) {
                    continue;
                }

                $finding = is_array($cycle['selected_finding'] ?? null) ? $cycle['selected_finding'] : [];
                $findingId = trim((string) ($finding['finding_id'] ?? ''));
                if ($findingId !== '') {
                    $findingIds[$findingId] = true;
                }
            }
        }

        return $findingIds;
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $sliceStates
     * @param  array<string,bool>  $certifiedFindingIds
     * @return array{
     *     slices: list<array<string,mixed>>,
     *     per_slice: list<array<string,mixed>>,
     *     blockers: list<string>,
     *     delivered_slices: int,
     *     all_merged_with_proof: bool,
     *     all_acceptance_met: bool
     * }
     */
    public function perSliceCertification(
        array $plan,
        array $sliceStates,
        array $certifiedFindingIds,
        int $totalSlices,
    ): array {
        $slices = PlanSliceReadModel::orderedSlices($plan);
        $perSlice = [];
        $blockers = [];
        $deliveredSlices = 0;
        $allMergedWithProof = $totalSlices > 0;
        $allAcceptanceMet = $totalSlices > 0;

        foreach ($slices as $slice) {
            $verdict = $this->judgeSlice($slice, $sliceStates, $certifiedFindingIds);
            $perSlice[] = $verdict['row'];

            if ($verdict['truly_delivered']) {
                $deliveredSlices++;
            } else {
                $allMergedWithProof = false;
                $allAcceptanceMet = false;
            }

            foreach ($verdict['slice_blockers'] as $blocker) {
                $blockers[] = $verdict['slice_id'].':'.$blocker;
            }
        }

        return [
            'slices' => $slices,
            'per_slice' => $perSlice,
            'blockers' => $blockers,
            'delivered_slices' => $deliveredSlices,
            'all_merged_with_proof' => $allMergedWithProof,
            'all_acceptance_met' => $allAcceptanceMet,
        ];
    }

    /**
     * @param  array<string,mixed>  $slice
     * @param  array<string,mixed>  $sliceStates
     * @param  array<string,bool>  $certifiedFindingIds
     * @return array{
     *     slice_id: string,
     *     row: array<string,mixed>,
     *     slice_blockers: list<string>,
     *     truly_delivered: bool
     * }
     */
    private function judgeSlice(array $slice, array $sliceStates, array $certifiedFindingIds): array
    {
        $sliceId = (string) ($slice['slice_id'] ?? '');
        $state = is_array($sliceStates[$sliceId] ?? null) ? $sliceStates[$sliceId] : [];
        $sliceState = (string) ($state['state'] ?? '');
        $mergeHash = $state['merge_hash'] ?? null;
        $hasMergeHash = is_string($mergeHash) && $mergeHash !== '';
        $providerProof = (bool) ($state['provider_proof'] ?? false);
        $acceptanceMet = (bool) ($state['acceptance_met'] ?? false);
        $findingId = (string) ($state['finding_id'] ?? $sliceId);

        $realCycleCertified = $sliceState === PlanSliceReadModel::STATE_DELIVERED
            && isset($certifiedFindingIds[$findingId]);

        $trulyDelivered = $sliceState === PlanSliceReadModel::STATE_DELIVERED
            && $providerProof
            && $hasMergeHash
            && $acceptanceMet;

        $sliceBlockers = $this->sliceBlockers(
            $sliceState === PlanSliceReadModel::STATE_DELIVERED,
            $providerProof,
            $hasMergeHash,
            $acceptanceMet,
            $realCycleCertified,
        );

        $row = [
            'slice_id' => $sliceId,
            'state' => $sliceState,
            'merge_hash' => $hasMergeHash ? $mergeHash : null,
            'provider_proof' => $providerProof,
            'acceptance_met' => $acceptanceMet,
            'finding_id' => $findingId,
            'real_cycle_certified' => $realCycleCertified,
            'blockers' => $sliceBlockers,
        ];

        return [
            'slice_id' => $sliceId,
            'row' => $row,
            'slice_blockers' => $sliceBlockers,
            'truly_delivered' => $trulyDelivered,
        ];
    }

    /**
     * @return list<string>
     */
    private function sliceBlockers(
        bool $delivered,
        bool $providerProof,
        bool $hasMergeHash,
        bool $acceptanceMet,
        bool $realCycleCertified,
    ): array {
        $blockers = [];
        if ($delivered && (! $providerProof || ! $hasMergeHash)) {
            $blockers[] = self::SLICE_BLOCKER_NO_PROVIDER_PROOF;
        }
        if ($delivered && ! $acceptanceMet) {
            $blockers[] = self::SLICE_BLOCKER_ACCEPTANCE_NOT_MET;
        }
        if ($delivered && ! $realCycleCertified) {
            $blockers[] = self::SLICE_BLOCKER_NO_CERTIFIED_REAL_CYCLE;
        }
        return $blockers;
    }

    /**
     * @param  list<array<string,mixed>>  $perSlice
     */
    public function everyRealCycleCertified(array $perSlice): bool
    {
        foreach ($perSlice as $entry) {
            if (! is_array($entry)) {
                return false;
            }
            if (! (bool) ($entry['real_cycle_certified'] ?? false)) {
                return false;
            }
        }

        return true;
    }

    public function isComplete(
        int $totalSlices,
        int $deliveredSlices,
        bool $allMergedWithProof,
        bool $allAcceptanceMet,
        bool $dependencyOrderPreserved,
        bool $integrationGreen,
        string $operationalGate,
        string $operationalStatusOperational,
        array $perSlice,
    ): bool {
        return $totalSlices > 0
            && $deliveredSlices === $totalSlices
            && $allMergedWithProof
            && $allAcceptanceMet
            && $dependencyOrderPreserved
            && $integrationGreen
            && $operationalGate === $operationalStatusOperational
            && $this->everyRealCycleCertified($perSlice);
    }

    /**
     * @param  list<string>  $blockers
     * @return array{status:string, blockers:list<string>}
     */
    public function resolveStatus(
        bool $complete,
        int $totalSlices,
        array $blockers,
        string $statusComplete,
        string $statusBlocked,
        string $statusPartial,
    ): array {
        if ($complete) {
            return ['status' => $statusComplete, 'blockers' => $blockers];
        }
        if ($totalSlices === 0) {
            $blockers[] = 'zero_slices';

            return ['status' => $statusBlocked, 'blockers' => $blockers];
        }

        return ['status' => $statusPartial, 'blockers' => $blockers];
    }

    public function mergeAndAcceptanceGated(int $totalSlices, bool $signal): bool
    {
        return $totalSlices > 0 && $signal;
    }

    /**
     * @param  list<string>  $blockers
     * @return list<string>
     */
    public function collectExternalBlockers(
        array $blockers,
        bool $dependencyOrderPreserved,
        bool $integrationGreen,
    ): array {
        if (! $dependencyOrderPreserved) {
            $blockers[] = 'dependency_order_not_preserved';
        }
        if (! $integrationGreen) {
            $blockers[] = 'integration_not_green';
        }

        return $blockers;
    }
}
