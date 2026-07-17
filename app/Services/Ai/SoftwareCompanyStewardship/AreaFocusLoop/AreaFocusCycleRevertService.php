<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Jobs\SoftwareCompanyLoopCycleRevertJob;
use App\Services\Ai\NightShift\AtlasNightShiftAreaFocusContractRegistry;

final class AreaFocusCycleRevertService
{
    public const RESPONSE_SCHEMA = 'atlas.software_company_stewardship.loop_cycle_revert.v1';

    public const RECEIPT_SCHEMA = 'atlas.software_company_stewardship.loop_cycle_revert_receipt.v1';

    public function __construct(
        private readonly Reliable24hLoopRunnerService $loopRunner,
        private readonly AtlasNightShiftAreaFocusContractRegistry $areaRegistry,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array{0:int,1:array<string,mixed>}
     */
    public function enqueue(string $area, string $cycle, array $input): array
    {
        $actor = $this->trimmed($input['operator_actor'] ?? $input['operator'] ?? $input['actor'] ?? '');
        $reason = $this->trimmed($input['reason'] ?? $input['motivo'] ?? $input['operator_reason'] ?? '');
        $focus = $this->focusFrom($input['focus'] ?? null);

        if ($actor === '') {
            return [422, $this->blocked('operator_actor_required', 'operator_actor is required (a revert must be operator-owned).', $area, $focus)];
        }
        if ($reason === '') {
            return [422, $this->blocked('operator_reason_required', 'reason is required so the revert request is auditable.', $area, $focus)];
        }
        if (mb_strlen($reason) > 1000) {
            return [422, $this->blocked('operator_reason_too_long', 'reason may not exceed 1000 characters.', $area, $focus)];
        }

        if (! $this->areaRegistry->isRegistered($area)) {
            return [404, [
                'schema_version' => self::RESPONSE_SCHEMA,
                'status' => 'blocked',
                'reason' => 'unknown_area',
                'area_id' => $area,
                'focus' => $focus,
                'error' => [
                    'code' => 'unknown_area',
                    'message' => "Area '{$area}' is not registered for the loop.",
                    'supported_areas' => array_values($this->areaRegistry->registeredAreas()),
                ],
            ]];
        }

        $record = $this->findOriginalCycle($area, $focus, $cycle);
        if ($record === null) {
            return [404, [
                'schema_version' => self::RESPONSE_SCHEMA,
                'status' => 'blocked',
                'reason' => 'unknown_cycle',
                'area_id' => $area,
                'focus' => $focus,
                'cycle' => $cycle,
                'detail' => 'No original AP-790 cycle receipt matched the requested cycle index or cycle_id.',
            ]];
        }

        $mergeHash = $this->trimmed($record['merge_hash'] ?? data_get($record, 'loop_receipt.merge_hash', ''));
        if ($mergeHash === '') {
            return [422, [
                'schema_version' => self::RESPONSE_SCHEMA,
                'status' => 'blocked',
                'reason' => 'cycle_without_merge_hash',
                'area_id' => $area,
                'focus' => $focus,
                'cycle' => $cycle,
                'detail' => 'The requested cycle has no merge_hash; M08 never fabricates a git revert target.',
            ]];
        }

        $cycleIndex = (int) ($record['cycle_index'] ?? 0);
        $cycleId = $this->trimmed($record['cycle_id'] ?? '');
        $recordedAt = AreaFocusUtcClock::atomNow();
        $receiptId = 'm08rev_'.substr(hash('sha256', implode('|', [
            $area,
            $focus,
            (string) $cycleIndex,
            $cycleId,
            $mergeHash,
            $actor,
            $reason,
            $recordedAt,
        ])), 0, 24);

        $revertOf = [
            'cycle_index' => $cycleIndex,
            'cycle_id' => $cycleId,
            'merge_hash' => $mergeHash,
        ];
        $mission = [
            'type' => 'governed_git_revert',
            'status' => 'enqueued',
            'target_merge_hash' => $mergeHash,
            'command_intent' => 'git revert '.$mergeHash,
            'worker_implemented' => false,
            'worker_gap' => 'M08 records and enqueues the governed revert request; the actual git revert executor is not implemented here.',
        ];

        $receipt = [
            'schema_version' => Reliable24hLoopRunnerService::LEDGER_SCHEMA,
            'receipt_schema_version' => self::RECEIPT_SCHEMA,
            'receipt_id' => $receiptId,
            'receipt_type' => 'cycle_revert_request',
            'run_id' => 'm08_cycle_revert',
            'cycle_index' => $cycleIndex,
            'cycle_id' => $cycleId,
            'finding_key' => $this->trimmed($record['finding_key'] ?? ''),
            'finding_keys' => array_values((array) ($record['finding_keys'] ?? [])),
            'outcome' => 'revert_enqueued',
            'work_class' => $this->trimmed($record['work_class'] ?? ''),
            'session_status' => 'enqueued',
            'cycle_final_status' => 'revert_enqueued',
            'blockers' => [],
            'merge_performed' => false,
            'merge_hash' => $mergeHash,
            'revert_status' => 'enqueued',
            'revert_of' => $revertOf,
            'operator_actor' => $actor,
            'operator_reason' => $reason,
            'queue' => SoftwareCompanyLoopCycleRevertJob::QUEUE,
            'mission' => $mission,
            'git_revert_performed' => false,
            'worker_implemented' => false,
            'recorded_at' => $recordedAt,
        ];

        AreaFocusAppendOnlyJsonlRecorder::append($this->loopRunner->ledgerPath($area, $focus), $receipt);

        SoftwareCompanyLoopCycleRevertJob::dispatch(
            $area,
            $focus,
            $cycleIndex,
            $cycleId,
            $mergeHash,
            $actor,
            $reason,
            $receiptId,
            $mission,
        );

        return [202, [
            'schema_version' => self::RESPONSE_SCHEMA,
            'status' => 'enqueued',
            'area_id' => $area,
            'focus' => $focus,
            'cycle_index' => $cycleIndex,
            'cycle_id' => $cycleId,
            'merge_hash' => $mergeHash,
            'revert_of' => $revertOf,
            'receipt_id' => $receiptId,
            'queue' => SoftwareCompanyLoopCycleRevertJob::QUEUE,
            'operator_actor' => $actor,
            'git_revert_performed' => false,
            'worker_implemented' => false,
            'mission' => $mission,
            'note' => 'Revert request is queued and recorded append-only. The original cycle receipt remains in the ledger; no git undo is claimed by this endpoint.',
        ]];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findOriginalCycle(string $area, string $focus, string $cycle): ?array
    {
        $cycle = trim($cycle);
        $records = array_reverse($this->loopRunner->readLedgerRecords($area, $focus));
        foreach ($records as $record) {
            if ($this->trimmed($record['record_type'] ?? '') !== '') {
                continue;
            }
            if (array_key_exists('revert_of', $record) || (string) ($record['receipt_type'] ?? '') === 'cycle_revert_request') {
                continue;
            }
            if ($this->trimmed($record['outcome'] ?? '') === '') {
                continue;
            }
            if ((string) ($record['cycle_index'] ?? '') === $cycle || (string) ($record['cycle_id'] ?? '') === $cycle) {
                return $record;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function blocked(string $reason, string $detail, string $area, string $focus): array
    {
        return [
            'schema_version' => self::RESPONSE_SCHEMA,
            'status' => 'blocked',
            'reason' => $reason,
            'area_id' => $area,
            'focus' => $focus,
            'detail' => $detail,
        ];
    }

    private function focusFrom(mixed $value): string
    {
        $focus = $this->trimmed($value);

        return $focus !== '' ? $focus : 'dev_forge';
    }

    private function trimmed(mixed $value): string
    {
        return trim((string) $value);
    }
}
