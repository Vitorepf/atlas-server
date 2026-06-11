<?php

namespace App\Services\Ai\Hermes;

use App\Models\AiJob;
use App\Models\AiScheduledTask;
use App\Services\Ai\Hermes\Support\HermesStringListNormalizer;
use App\Services\Ai\Scheduling\ScheduleParser;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Str;
use Throwable;

class HermesScheduleAdapter
{
    use HermesAdapterReceipt;

    public function __construct(
        private readonly ScheduleParser $parser,
    ) {}

    /**
     * @param  array<string,mixed>  $resultPacket
     * @param  array<string,mixed>  $mission
     * @param  array<string,mixed>  $invocation
     * @return array<string,mixed>
     */
    public function persistCandidates(AiJob $job, array $resultPacket, array $mission, array $invocation, string $schedulePolicy): array
    {
        $candidates = data_get($resultPacket, 'schedule_gate.candidates', []);
        $candidates = is_array($candidates) ? array_values($candidates) : [];
        $receipt = [
            'schema_version' => 'atlas.hermes.schedule_adapter_receipt.v1',
            'adapter' => 'hermes_schedule_adapter',
            'schedule_policy' => $schedulePolicy,
            'canonical_scheduler' => 'atlas',
            'atlas_schedule_gate' => 'ai_scheduled_tasks',
            'activation_allowed_now' => false,
            'candidate_count' => count($candidates),
            'eligible_candidate_count' => 0,
            'persisted_count' => 0,
            'duplicate_count' => 0,
            'skipped_count' => 0,
            'persisted_task_ids' => [],
            'duplicate_task_ids' => [],
            'skipped_candidates' => [],
            'status' => 'no_candidates',
        ];

        if ($candidates === []) {
            return $this->withReceiptHash($receipt);
        }

        if ($schedulePolicy !== 'atlas_adapter') {
            $receipt['status'] = 'skipped_by_policy';
            $receipt['skipped_count'] = count($candidates);
            $receipt['skipped_candidates'] = $this->skippedCandidates($candidates, 'schedule_policy_not_atlas_adapter');

            return $this->withReceiptHash($receipt);
        }

        if (! DatabaseTableAvailability::has('ai_scheduled_tasks')) {
            $receipt['status'] = 'schedule_gate_unavailable';
            $receipt['skipped_count'] = count($candidates);
            $receipt['skipped_candidates'] = $this->skippedCandidates($candidates, 'ai_scheduled_tasks_table_missing');

            return $this->withReceiptHash($receipt);
        }

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $candidateId = $this->string($candidate['candidate_id'] ?? null, 120) ?: 'hermes_schedule_candidate_unknown';
            $name = $this->string($candidate['name'] ?? null, 160);
            $objective = $this->string($candidate['objective'] ?? null, 700);
            if ($name === null || $objective === null) {
                $receipt['skipped_count']++;
                $receipt['skipped_candidates'][] = [
                    'candidate_id' => $candidateId,
                    'reason' => 'missing_name_or_objective',
                ];

                continue;
            }

            $receipt['eligible_candidate_count']++;
            $workspace = $this->workspace($job);
            $existing = $this->duplicateTask($candidateId, $workspace);
            if ($existing instanceof AiScheduledTask) {
                $receipt['duplicate_count']++;
                $receipt['duplicate_task_ids'][] = $existing->id;

                continue;
            }

            $task = AiScheduledTask::query()->create([
                'title' => Str::limit($name, 255, ''),
                'prompt' => $objective,
                'schedule' => $this->candidateSchedule($candidate),
                'kind' => 'candidate',
                'skill_ids' => [],
                'target_platform' => 'local',
                'target_device_id' => null,
                'workspace' => $workspace,
                'enabled' => false,
                'next_run_at' => null,
                'last_run_at' => null,
                'last_status' => null,
                'last_output_path' => null,
                'repeat_remaining' => null,
                'context_from_task_ids' => [],
                'wrap_response' => true,
                'metadata' => $this->metadata($candidate, $resultPacket, $mission, $invocation),
            ]);

            $receipt['persisted_count']++;
            $receipt['persisted_task_ids'][] = $task->id;
        }

        $receipt['status'] = $receipt['persisted_count'] > 0
            ? 'persisted_for_review'
            : ($receipt['duplicate_count'] > 0 ? 'deduplicated' : 'skipped_by_gate');

        return $this->withReceiptHash($receipt);
    }

    /**
     * @param  array<int,mixed>  $candidates
     * @return array<int,array<string,string>>
     */
    private function skippedCandidates(array $candidates, string $reason): array
    {
        return collect($candidates)
            ->filter(fn (mixed $candidate): bool => is_array($candidate))
            ->map(fn (array $candidate): array => [
                'candidate_id' => $this->string($candidate['candidate_id'] ?? null, 120) ?: 'hermes_schedule_candidate_unknown',
                'reason' => $reason,
            ])
            ->values()
            ->all();
    }

    private function duplicateTask(string $candidateId, ?string $workspace): ?AiScheduledTask
    {
        return AiScheduledTask::query()
            ->where('kind', 'candidate')
            ->where('workspace', $workspace)
            ->get()
            ->first(fn (AiScheduledTask $task): bool => data_get($task->metadata, 'hermes_schedule_candidate.candidate_id') === $candidateId);
    }

    /**
     * @param  array<string,mixed>  $candidate
     */
    private function candidateSchedule(array $candidate): string
    {
        $trigger = $this->trigger($candidate);
        $cadence = $this->string($candidate['cadence'] ?? null, 160);
        $schedule = 'candidate:'.$trigger.($cadence !== null ? ':'.$cadence : '');

        return Str::limit($schedule, 255, '');
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @param  array<string,mixed>  $resultPacket
     * @param  array<string,mixed>  $mission
     * @param  array<string,mixed>  $invocation
     * @return array<string,mixed>
     */
    private function metadata(array $candidate, array $resultPacket, array $mission, array $invocation): array
    {
        $candidateId = $this->string($candidate['candidate_id'] ?? null, 120) ?: 'hermes_schedule_candidate_unknown';
        $trigger = $this->trigger($candidate);
        $cadence = $this->string($candidate['cadence'] ?? null, 160);

        return [
            'schema_version' => 'atlas.hermes.schedule_candidate_task.v1',
            'created_by' => 'hermes_schedule_adapter',
            'review_status' => 'pending',
            'activation_allowed_now' => false,
            'activation_requires_atlas_schedule_gate' => true,
            'activation_requires_operator_confirmation' => true,
            'scheduler_authority' => 'atlas',
            'stop_condition_gate' => 'ScheduledJobStopConditionGate',
            'idempotency_required' => (bool) ($candidate['idempotency_required'] ?? true),
            'evidence_required' => (bool) ($candidate['evidence_required'] ?? true),
            'direct_resume_blocked_by_candidate_schedule' => true,
            'hermes_schedule_candidate' => [
                'candidate_id' => $candidateId,
                'name' => $this->string($candidate['name'] ?? null, 160),
                'trigger' => $trigger,
                'cadence' => $cadence,
                'objective' => $this->string($candidate['objective'] ?? null, 700),
                'stop_conditions' => HermesStringListNormalizer::bounded($candidate['stop_conditions'] ?? null, 8, 500),
                'source' => $this->string($candidate['source'] ?? null, 80) ?: 'hermes_session',
                'gate_status' => 'persisted_for_atlas_schedule_review',
            ],
            'schedule_parse' => $this->scheduleParse($trigger, $cadence),
            'hermes_result_packet' => [
                'result_id' => $resultPacket['result_id'] ?? null,
                'result_hash' => $resultPacket['result_hash'] ?? null,
                'mission_id' => $mission['mission_id'] ?? null,
                'mission_hash' => $mission['mission_hash'] ?? null,
                'cli_invocation_hash' => $this->hashValue($invocation),
                'activation_allowed_now' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function scheduleParse(string $trigger, ?string $cadence): array
    {
        if ($trigger !== 'cron' || $cadence === null) {
            return [
                'status' => 'not_parsed',
                'reason' => 'candidate_not_directly_activatable',
            ];
        }

        try {
            $parsed = $this->parser->parse($cadence);

            return [
                'status' => 'valid_for_review',
                'kind' => $parsed['kind'] ?? null,
                'schedule' => $parsed['schedule'] ?? null,
                'next_run_at_if_approved' => data_get($parsed, 'next_run_at')?->toJSON(),
            ];
        } catch (Throwable $exception) {
            return [
                'status' => 'invalid_for_review',
                'error' => Str::limit($exception->getMessage(), 300, ''),
            ];
        }
    }

    /**
     * @param  array<string,mixed>  $candidate
     */
    private function trigger(array $candidate): string
    {
        $trigger = $this->string($candidate['trigger'] ?? null, 40) ?: 'manual';

        return in_array($trigger, ['cron', 'webhook', 'manual'], true) ? $trigger : 'manual';
    }

    private function workspace(AiJob $job): ?string
    {
        $workspace = data_get($job->payload, 'workspace')
            ?: data_get($job->payload, 'tool_permissions.workspace')
            ?: data_get($job->metadata, 'workspace');

        if (! is_string($workspace) || trim($workspace) === '') {
            return null;
        }

        $workspace = trim($workspace);

        return realpath($workspace) ?: $workspace;
    }

    private function string(mixed $value, int $limit): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $limit, '');
    }
}
