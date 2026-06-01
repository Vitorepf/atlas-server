<?php

namespace App\Services\Ai\Hermes;

use App\Models\AiScheduledTask;
use App\Services\Ai\Scheduling\Governance\ScheduledJobStopConditionGate;
use App\Services\Ai\Scheduling\ScheduleParser;
use Throwable;

/**
 * Operator-gated activation of a quarantined Hermes schedule candidate.
 *
 * Hermes can only persist `atlas.hermes.schedule_candidate_task.v1` rows for
 * ATLS review (see {@see HermesScheduleAdapter}); it never owns the scheduler.
 * This gate is the single Atlas-sovereign promotion path that turns one such
 * candidate into an active `ai_scheduled_tasks` row. It is fail-closed: every
 * path returns a sealed `atlas.hermes.schedule_activation_receipt.v1` receipt,
 * `activation_allowed_now` is true ONLY on a successful approved activation,
 * and the row is mutated only AFTER the success receipt_hash is computed so the
 * persisted `metadata.activation_receipt_hash` proves the activation event.
 */
class HermesScheduleActivationGate
{
    use HermesAdapterReceipt;

    public function __construct(
        private readonly ScheduleParser $parser,
    ) {}

    /**
     * @param  array<string,mixed>  $approval
     * @return array<string,mixed>
     */
    public function activate(AiScheduledTask $task, array $approval = []): array
    {
        $candidateId = $this->candidateId($task);
        $receipt = $this->baseReceipt($candidateId);

        $invalid = $this->validateCandidate($task);
        if ($invalid !== null) {
            return $this->reject($receipt, $candidateId, $invalid, 'rejected_not_a_candidate');
        }

        $receipt['eligible_candidate_count'] = 1;

        if (! $this->approvalSatisfied($task, $approval)) {
            return $this->reject($receipt, $candidateId, 'operator_confirmation_not_provided', 'rejected_activation_not_confirmed');
        }

        $stopConditions = $this->stopConditions($task);
        if ($stopConditions === []) {
            return $this->reject($receipt, $candidateId, 'stop_conditions_missing', 'rejected_missing_stop_conditions');
        }

        if (! $this->governanceFlagsSatisfied($task)) {
            return $this->reject($receipt, $candidateId, 'evidence_and_idempotency_required', 'rejected_evidence_or_idempotency_required');
        }

        $cadence = $this->cadence($task);
        if ($cadence === null || $cadence === '') {
            return $this->reject($receipt, $candidateId, 'cadence_missing', 'rejected_invalid_cadence');
        }

        try {
            $parsed = $this->parser->parse($cadence);
        } catch (Throwable $exception) {
            return $this->reject($receipt, $candidateId, 'cadence_not_parseable', 'rejected_invalid_cadence');
        }

        $kind = (string) ($parsed['kind'] ?? '');
        $schedule = (string) ($parsed['schedule'] ?? $cadence);
        $nextRunAt = $parsed['next_run_at'] ?? null;

        $receipt['activation_allowed_now'] = true;
        $receipt['activated_count'] = 1;
        $receipt['activated_task_id'] = $task->id;
        $receipt['activated_kind'] = $kind;
        $receipt['activated_schedule'] = $schedule;
        $receipt['next_run_at'] = $nextRunAt?->toJSON();
        $receipt['status'] = 'activated';

        $sealed = $this->withReceiptHash($receipt);

        $task->update([
            'kind' => $kind,
            'schedule' => $schedule,
            'enabled' => true,
            'next_run_at' => $nextRunAt,
            'repeat_remaining' => $kind === 'once' ? 1 : null,
            'metadata' => array_merge($task->metadata ?? [], [
                'review_status' => 'approved',
                'activation_allowed_now' => true,
                'activated_at' => now()->toJSON(),
                'activated_by' => $approval['activated_by'] ?? 'operator',
                'activation_receipt_hash' => $sealed['receipt_hash'],
                'activation_schema_version' => 'atlas.hermes.schedule_activation_receipt.v1',
                'hermes_schedule_candidate' => array_merge(
                    (array) data_get($task->metadata, 'hermes_schedule_candidate', []),
                    ['gate_status' => 'activated_by_atlas_schedule_gate'],
                ),
            ]),
        ]);

        return $sealed;
    }

    /**
     * @return array<string,mixed>
     */
    private function baseReceipt(?string $candidateId): array
    {
        return [
            'schema_version' => 'atlas.hermes.schedule_activation_receipt.v1',
            'gate' => 'hermes_schedule_activation_gate',
            'scheduler_authority' => 'atlas',
            'atlas_schedule_gate' => 'ai_scheduled_tasks',
            'stop_condition_gate' => ScheduledJobStopConditionGate::class,
            'activation_allowed_now' => false,
            'candidate_id' => $candidateId,
            'candidate_count' => 1,
            'eligible_candidate_count' => 0,
            'activated_count' => 0,
            'skipped_count' => 0,
            'rejected_count' => 0,
            'activated_task_id' => null,
            'skipped_candidates' => [],
            'status' => 'rejected_not_a_candidate',
        ];
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    private function reject(array $receipt, ?string $candidateId, string $reason, string $status): array
    {
        $receipt['activation_allowed_now'] = false;
        $receipt['activated_count'] = 0;
        $receipt['activated_task_id'] = null;
        $receipt['rejected_count'] = 1;
        $receipt['skipped_count'] = 1;
        $receipt['skipped_candidates'] = [
            [
                'candidate_id' => $candidateId,
                'reason' => $reason,
            ],
        ];
        $receipt['status'] = $status;

        return $this->withReceiptHash($receipt);
    }

    private function validateCandidate(AiScheduledTask $task): ?string
    {
        if (data_get($task->metadata, 'schema_version') !== 'atlas.hermes.schedule_candidate_task.v1') {
            return 'not_a_hermes_schedule_candidate';
        }

        if ($task->kind !== 'candidate') {
            return 'task_is_not_a_candidate';
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $approval
     */
    private function approvalSatisfied(AiScheduledTask $task, array $approval): bool
    {
        return data_get($task->metadata, 'hermes_schedule_candidate.review_status') === 'approved'
            || data_get($task->metadata, 'review_status') === 'approved'
            || ($approval['operator_confirmed'] ?? false) === true;
    }

    private function candidateId(AiScheduledTask $task): ?string
    {
        $candidateId = data_get($task->metadata, 'hermes_schedule_candidate.candidate_id');

        return is_string($candidateId) && trim($candidateId) !== '' ? $candidateId : null;
    }

    private function cadence(AiScheduledTask $task): ?string
    {
        $cadence = data_get($task->metadata, 'hermes_schedule_candidate.cadence');

        return is_string($cadence) && trim($cadence) !== '' ? trim($cadence) : null;
    }

    /**
     * @return array<int,string>
     */
    private function stopConditions(AiScheduledTask $task): array
    {
        $conditions = data_get($task->metadata, 'hermes_schedule_candidate.stop_conditions');
        if (! is_array($conditions)) {
            return [];
        }

        return collect($conditions)
            ->filter(fn (mixed $condition): bool => is_string($condition) && trim($condition) !== '')
            ->map(fn (mixed $condition): string => trim((string) $condition))
            ->values()
            ->all();
    }

    private function governanceFlagsSatisfied(AiScheduledTask $task): bool
    {
        return (bool) data_get($task->metadata, 'evidence_required') === true
            && (bool) data_get($task->metadata, 'idempotency_required') === true;
    }
}
