<?php

namespace App\Services\Ai;

use App\Models\AiJob;
use App\Services\AuditLogService;
use Carbon\Carbon;

class AiProviderChoiceResolver
{
    public function __construct(
        private readonly AuditLogService $audit,
    ) {}

    /**
     * @return array{option: array<string,mixed>, action: string, job: AiJob}
     */
    public function resolve(AiJob $job, string $optionId): array
    {
        if ($job->status !== 'awaiting_user_choice') {
            throw AiProviderChoiceException::notAwaitingChoice();
        }

        $options = (array) data_get($job->metadata, 'choice_options', []);
        $option = collect($options)->firstWhere('id', $optionId);

        if (! is_array($option)) {
            throw AiProviderChoiceException::optionNotFound($optionId);
        }

        $action = (string) ($option['action'] ?? '');
        $baseMetadata = array_merge($job->metadata ?? [], [
            'provider_choice_resolved_at' => now()->toIso8601String(),
            'provider_choice_resolved_option' => $optionId,
        ]);

        match ($action) {
            'switch_provider' => $this->applySwitchProvider($job, $option, array_merge($baseMetadata, ['provider_choice_state' => null])),
            'downgrade_model' => $this->applyDowngradeModel($job, $option, array_merge($baseMetadata, ['provider_choice_state' => null])),
            'wait' => $this->applyWait($job, $option, array_merge($baseMetadata, ['provider_choice_state' => null])),
            'fail' => $this->applyFail($job, $option, array_merge($baseMetadata, ['provider_choice_state' => 'resolved'])),
            'cancel' => $this->applyCancel($job, array_merge($baseMetadata, ['provider_choice_state' => 'resolved'])),
            'retry_same' => $this->applyRetrySame($job, array_merge($baseMetadata, [
                'provider_choice_state' => null,
                'provider_choice_resolved_via_retry' => true,
            ])),
            default => $job->update(['metadata' => array_merge($baseMetadata, ['provider_choice_state' => 'resolved'])]),
        };

        $privacy = data_get($job->payload, 'privacy', data_get($job->metadata, 'privacy'));

        $this->audit->record('ai_job_choice_resolved', [
            'subject_type' => 'ai_job',
            'subject_id' => $job->id,
            'actor_type' => 'operator',
            'actor_id' => 'vitor',
            'summary' => "Operador escolheu opção [{$optionId}] (action={$action}).",
            'evidence' => [
                'option_id' => $optionId,
                'action' => $action,
                'option' => $option,
            ],
            'privacy' => is_array($privacy) ? $privacy : [],
            'refs' => [
                'job_id' => $job->id,
                'trace_id' => $job->trace_id,
            ],
        ]);

        return [
            'option' => $option,
            'action' => $action,
            'job' => $job->refresh(),
        ];
    }

    private function applySwitchProvider(AiJob $job, array $option, array $metadata): void
    {
        $job->update([
            'status' => 'queued',
            'provider' => (string) ($option['provider'] ?? $job->provider),
            'model' => array_key_exists('model', $option) ? $option['model'] : $job->model,
            'available_at' => now(),
            'reserved_at' => null,
            'started_at' => null,
            'finished_at' => null,
            'worker_id' => null,
            'error_code' => null,
            'error_message' => null,
            'metadata' => $metadata,
        ]);
    }

    private function applyDowngradeModel(AiJob $job, array $option, array $metadata): void
    {
        $job->update([
            'status' => 'queued',
            'model' => (string) ($option['model'] ?? $job->model),
            'available_at' => now(),
            'reserved_at' => null,
            'started_at' => null,
            'finished_at' => null,
            'worker_id' => null,
            'error_code' => null,
            'error_message' => null,
            'metadata' => $metadata,
        ]);
    }

    private function applyWait(AiJob $job, array $option, array $metadata): void
    {
        $job->update([
            'status' => 'queued',
            'available_at' => isset($option['available_at_iso'])
                ? Carbon::parse((string) $option['available_at_iso'])
                : now()->addMinutes(15),
            'reserved_at' => null,
            'started_at' => null,
            'finished_at' => null,
            'worker_id' => null,
            'metadata' => $metadata,
        ]);
    }

    private function applyFail(AiJob $job, array $option, array $metadata): void
    {
        $job->update([
            'status' => 'failed',
            'finished_at' => now(),
            'error_code' => (string) ($option['reason'] ?? 'choice_failed'),
            'error_message' => isset($option['cli_command'])
                ? "Login required: rode `{$option['cli_command']}` no terminal e tente novamente."
                : 'Operator chose to fail this job.',
            'metadata' => $metadata,
        ]);
    }

    private function applyCancel(AiJob $job, array $metadata): void
    {
        $job->update([
            'status' => 'cancelled',
            'finished_at' => now(),
            'error_code' => 'cancelled_by_operator',
            'error_message' => 'Operador cancelou o job durante escolha de provider.',
            'metadata' => $metadata,
        ]);
        $job->trace?->update(['status' => 'cancelled', 'completed_at' => now()]);
    }

    private function applyRetrySame(AiJob $job, array $metadata): void
    {
        $job->update([
            'status' => 'queued',
            'available_at' => now(),
            'reserved_at' => null,
            'started_at' => null,
            'finished_at' => null,
            'worker_id' => null,
            'error_code' => null,
            'error_message' => null,
            'metadata' => $metadata,
        ]);
    }
}
