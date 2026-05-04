<?php

namespace App\Jobs;

use App\Models\AiScheduledTask;
use App\Models\AiTrace;
use App\Services\Ai\AiGatewayService;
use App\Services\Ai\AiWorker;
use App\Support\AtlasSecurity;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

class RunScheduledTaskJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(
        public readonly string $scheduledTaskId,
    ) {}

    /**
     * @return array<int,object>
     */
    public function middleware(): array
    {
        $task = AiScheduledTask::query()->find($this->scheduledTaskId);
        $key = $task?->workspace ?: $this->scheduledTaskId;

        return [
            (new WithoutOverlapping('atlas-scheduled-task:'.hash('sha256', $key)))->expireAfter($this->timeout),
        ];
    }

    public function handle(AiGatewayService $gateway, AiWorker $worker): void
    {
        $task = AiScheduledTask::query()->find($this->scheduledTaskId);
        if (! $task) {
            return;
        }

        $started = hrtime(true);
        $trace = null;
        $status = 'failure';
        $output = '';
        $error = null;

        try {
            $trace = $gateway->enqueueInteraction($this->guardedPrompt($task), $this->gatewayOptions($task));
            $trace = $this->waitForTrace($trace, $worker, $this->timeoutSeconds($task));
            $status = $this->scheduledRunStatus($trace);
            $output = match ($status) {
                'success' => (string) $trace->response_text,
                'deferred' => $this->deferredOutput($trace),
                default => $this->failureOutput($trace),
            };
        } catch (Throwable $exception) {
            $error = $exception;
            $status = 'failure';
            $output = "Scheduled task failed before provider completion.\n\nError: ".$exception->getMessage();
            report($exception);
        }

        $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);
        $silent = $status === 'success' && Str::startsWith(ltrim($output), '[SILENT]');
        $path = $this->writeOutput($task, $status, $output, $durationMs, $trace, $error);

        $task->update([
            'last_run_at' => now(),
            'last_status' => $status,
            'last_output_path' => $path,
            'metadata' => array_merge($task->metadata ?? [], [
                'last_trace_id' => $trace?->id,
                'last_duration_ms' => $durationMs,
                'last_delivery_suppressed' => $silent,
                'last_delivery_status' => $this->deliveryStatus($task, $status, $silent),
                'last_error' => $error ? Str::limit($error->getMessage(), 500, '') : null,
            ]),
        ]);
    }

    private function waitForTrace(AiTrace $trace, AiWorker $worker, int $timeoutSeconds): AiTrace
    {
        $deadline = now()->addSeconds($timeoutSeconds);
        $workerId = 'atlas-scheduled-'.getmypid();

        while (now()->lessThanOrEqualTo($deadline)) {
            $trace = $trace->fresh(['jobs']) ?: $trace;
            if (in_array($trace->status, ['succeeded', 'failed', 'cancelled'], true)) {
                return $trace;
            }

            $providerOverride = $trace->provider === 'claude_codex' ? null : $trace->provider;
            $job = $worker->runNextForTrace($trace->id, $providerOverride, $workerId);
            $trace = $trace->fresh(['jobs']) ?: $trace;

            if (in_array($trace->status, ['succeeded', 'failed', 'cancelled'], true)) {
                return $trace;
            }

            if (! $job && $this->hasDelayedRetry($trace)) {
                return $trace;
            }

            if (! $job) {
                sleep(1);
            }
        }

        $trace->update([
            'status' => 'failed',
            'completed_at' => now(),
            'metadata' => array_merge($trace->metadata ?? [], [
                'scheduled_task_timeout' => true,
                'scheduled_task_timeout_seconds' => $timeoutSeconds,
            ]),
        ]);

        return $trace->refresh();
    }

    private function hasDelayedRetry(AiTrace $trace): bool
    {
        return $trace->jobs()
            ->where('status', 'queued')
            ->where('available_at', '>', now())
            ->exists();
    }

    private function scheduledRunStatus(AiTrace $trace): string
    {
        if ($trace->status === 'succeeded') {
            return 'success';
        }

        if ($trace->status === 'queued' && $this->hasDelayedRetry($trace)) {
            return 'deferred';
        }

        return 'failure';
    }

    /**
     * @return array<string,mixed>
     */
    private function gatewayOptions(AiScheduledTask $task): array
    {
        $workspace = $task->workspace ?: (string) config('atlas.ai.workdir', dirname(base_path()));

        return [
            'source_type' => 'scheduled',
            'source_id' => $task->id,
            'agent_slug' => 'orquestrador',
            'new_thread' => true,
            'priority' => 30,
            'timeout_seconds' => $this->timeoutSeconds($task),
            'payload' => [
                'app_surface' => 'atlas_cli_schedule',
                'atlas_workflow_mode' => 'scheduled',
                'workspace' => $workspace,
                'activated_skills' => $task->skill_ids ?? [],
                'context_from_task_ids' => $task->context_from_task_ids ?? [],
                'scheduled_task' => [
                    'id' => $task->id,
                    'title' => $task->title,
                    'schedule' => $task->schedule,
                    'kind' => $task->kind,
                    'target_platform' => $task->target_platform,
                    'anti_recursion' => [
                        'blocked_commands' => [
                            'atlas schedule',
                            'atlas cron',
                            'php artisan atlas:cli:schedule',
                            'php artisan atlas:scheduler:tick',
                        ],
                    ],
                ],
                'tool_permissions' => [
                    'mode' => 'read',
                    'workspace' => $workspace,
                    'confirmed' => false,
                ],
            ],
        ];
    }

    private function guardedPrompt(AiScheduledTask $task): string
    {
        return trim(<<<TXT
# Atlas Scheduled Task

Title: {$task->title}
Schedule: {$task->schedule}
Target: {$task->target_platform}

# Anti-recursion guard

Esta execucao foi iniciada pelo scheduler do Atlas. Nao crie, edite, pause, remova ou execute tarefas agendadas durante esta resposta.
Comandos bloqueados neste contexto: atlas schedule, atlas cron, php artisan atlas:cli:schedule, php artisan atlas:scheduler:tick.

# Pedido agendado

{$task->prompt}
TXT);
    }

    private function failureOutput(AiTrace $trace): string
    {
        $job = $trace->jobs()->latest('updated_at')->first();
        $parts = [
            'Scheduled task failed.',
            '',
            'Trace: '.$trace->id,
            'Status: '.$trace->status,
        ];

        if ($job?->error_code || $job?->error_message) {
            $parts[] = 'Error code: '.($job->error_code ?: 'unknown');
            $parts[] = 'Error message: '.($job->error_message ?: 'unknown');
        }

        $response = trim((string) $trace->response_text);
        if ($response !== '') {
            $parts[] = '';
            $parts[] = $response;
        }

        return implode("\n", $parts);
    }

    private function deferredOutput(AiTrace $trace): string
    {
        $job = $trace->jobs()->where('status', 'queued')->latest('available_at')->first();
        $readiness = data_get($job?->metadata, 'mac_background_readiness')
            ?: data_get($trace->metadata, 'mac_background_readiness', []);
        $retryAt = $job?->available_at?->toJSON();
        $blockers = collect((array) data_get($readiness, 'readiness.blockers', []))
            ->map(fn (mixed $item): string => '- '.(string) data_get($item, 'code', 'unknown').': '.(string) data_get($item, 'message', ''))
            ->implode("\n");

        $parts = [
            'Scheduled task deferred.',
            '',
            'Trace: '.$trace->id,
            'Reason: '.((string) data_get($readiness, 'reason') ?: 'delayed_retry'),
            'Retry at: '.($retryAt ?: 'pending'),
        ];

        if ($blockers !== '') {
            $parts[] = '';
            $parts[] = 'Mac readiness blockers:';
            $parts[] = $blockers;
        }

        return implode("\n", $parts);
    }

    private function writeOutput(AiScheduledTask $task, string $status, string $output, int $durationMs, ?AiTrace $trace, ?Throwable $error): string
    {
        $timestamp = now()->format('Ymd_His');
        $directory = storage_path('app/atlas/scheduled/'.$task->id);
        $path = $directory.'/'.$timestamp.'.md';
        File::ensureDirectoryExists($directory);
        File::put($path, AtlasSecurity::redactString($this->renderOutput($task, $status, $output, $durationMs, $trace, $error)));

        return $path;
    }

    private function renderOutput(AiScheduledTask $task, string $status, string $output, int $durationMs, ?AiTrace $trace, ?Throwable $error): string
    {
        if (! $task->wrap_response) {
            return $output;
        }

        $skills = implode(', ', $task->skill_ids ?? []);
        $traceLine = $trace ? "\nTrace: {$trace->id}" : '';
        $errorLine = $error ? "\nError: ".Str::limit($error->getMessage(), 500, '') : '';
        $runAt = now()->toJSON();

        return trim(<<<MD
# Atlas - Scheduled Task: {$task->title}

Run at: {$runAt}
Status: {$status}
Duration: {$durationMs}ms
Skills used: {$skills}{$traceLine}{$errorLine}

---

{$output}
MD)."\n";
    }

    private function timeoutSeconds(AiScheduledTask $task): int
    {
        $configured = data_get($task->metadata, 'timeout_seconds');
        if (is_numeric($configured)) {
            return max(15, min(3600, (int) $configured));
        }

        return max(15, min(3600, (int) config('atlas.ai.scheduled_tasks.timeout_seconds', 600)));
    }

    private function deliveryStatus(AiScheduledTask $task, string $status, bool $silent): string
    {
        if ($status === 'deferred') {
            return 'deferred_until_ready';
        }

        if ($status === 'failure') {
            return $task->target_platform === 'local' ? 'saved_failure' : 'pending_p6_failure_delivery';
        }

        if ($silent) {
            return 'suppressed_by_silent_marker';
        }

        return $task->target_platform === 'local' ? 'saved_local' : 'pending_p6_delivery';
    }
}
