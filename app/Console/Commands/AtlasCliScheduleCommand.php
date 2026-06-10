<?php

namespace App\Console\Commands;

use App\Models\AiScheduledTask;
use App\Services\Ai\Scheduling\AtlasCliSchedulerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Str;
use Throwable;

class AtlasCliScheduleCommand extends Command
{
    protected $signature = 'atlas:cli:schedule
        {action=list : list, add, show, remove, pause, resume, run-now}
        {id? : task id, or title when action=add}
        {--prompt= : Prompt to execute for add}
        {--schedule= : Schedule expression: 30m, every 2h, cron or ISO timestamp}
        {--skill=* : Skill bundle names to activate}
        {--target=local : local|mobile|mobile_push}
        {--target-device-id= : Optional mobile device id for P6 delivery}
        {--severity=info : info|warning|critical}
        {--workspace= : Workspace path. Defaults to current directory}
        {--repeat= : Number of executions before disabling}
        {--context-from=* : Scheduled task ids used as prior context}
        {--no-wrap : Store raw provider output}
        {--force : Hard delete on remove}
        {--queue : Enqueue run-now instead of waiting synchronously}
        {--limit=50 : List limit}
        {--json : Print machine-readable JSON}';

    protected $description = 'Manage Atlas scheduled AI tasks.';

    public function handle(AtlasCliSchedulerService $scheduler): int
    {
        if (! DatabaseTableAvailability::has('ai_scheduled_tasks')) {
            return $this->printPayload([
                'ok' => false,
                'error' => 'Tabela ai_scheduled_tasks ainda nao existe. Rode migrations.',
            ], self::FAILURE);
        }

        $action = Str::of((string) $this->argument('action'))->lower()->trim()->value();

        try {
            return match ($action) {
                'list' => $this->list($scheduler),
                'add' => $this->add($scheduler),
                'show' => $this->show($scheduler),
                'remove', 'delete', 'rm' => $this->remove($scheduler),
                'pause' => $this->mutate($scheduler, 'pause'),
                'resume' => $this->mutate($scheduler, 'resume'),
                'run-now', 'run' => $this->runNow($scheduler),
                default => $this->printPayload([
                    'ok' => false,
                    'error' => "Acao invalida: {$action}. Use list, add, show, remove, pause, resume ou run-now.",
                ], self::FAILURE),
            };
        } catch (Throwable $exception) {
            return $this->printPayload([
                'ok' => false,
                'error' => $exception->getMessage(),
            ], self::FAILURE);
        }
    }

    private function list(AtlasCliSchedulerService $scheduler): int
    {
        $limit = max(1, min(200, (int) $this->option('limit')));
        $tasks = AiScheduledTask::query()
            ->orderByDesc('enabled')
            ->orderBy('next_run_at')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (AiScheduledTask $task): array => $scheduler->taskPayload($task))
            ->all();

        return $this->printPayload([
            'ok' => true,
            'scheduled_tasks' => $tasks,
        ]);
    }

    private function add(AtlasCliSchedulerService $scheduler): int
    {
        $prompt = (string) $this->option('prompt');
        $schedule = (string) $this->option('schedule');
        if (trim($prompt) === '' || trim($schedule) === '') {
            return $this->printPayload([
                'ok' => false,
                'error' => 'add exige --prompt e --schedule.',
            ], self::FAILURE);
        }

        $title = (string) ($this->argument('id') ?: Str::limit(trim(strtok($prompt, "\n") ?: $prompt), 80, ''));
        $task = $scheduler->addTask(
            title: $title,
            prompt: $prompt,
            schedule: $schedule,
            skillIds: $this->skillOptions(),
            targetPlatform: (string) $this->option('target'),
            targetDeviceId: is_string($this->option('target-device-id')) ? $this->option('target-device-id') : null,
            workspace: $this->workspaceOption(),
            repeatRemaining: $this->repeatOption(),
            contextFromTaskIds: $this->contextFromOptions(),
            wrapResponse: ! (bool) $this->option('no-wrap'),
            metadata: [
                'severity' => $this->severity(),
            ],
        );

        return $this->printPayload([
            'ok' => true,
            'scheduled_task' => $scheduler->taskPayload($task),
        ]);
    }

    private function show(AtlasCliSchedulerService $scheduler): int
    {
        $task = $scheduler->findTask($this->idArgument());
        $payload = $scheduler->taskPayload($task);
        $payload['last_output_tail'] = $this->outputTail($task->last_output_path);

        return $this->printPayload([
            'ok' => true,
            'scheduled_task' => $payload,
        ]);
    }

    private function remove(AtlasCliSchedulerService $scheduler): int
    {
        $task = $scheduler->removeTask($this->idArgument(), (bool) $this->option('force'));

        return $this->printPayload([
            'ok' => true,
            'removed' => $task === null,
            'scheduled_task' => $task ? $scheduler->taskPayload($task) : null,
        ]);
    }

    private function mutate(AtlasCliSchedulerService $scheduler, string $action): int
    {
        $task = $action === 'pause'
            ? $scheduler->pauseTask($this->idArgument())
            : $scheduler->resumeTask($this->idArgument());

        return $this->printPayload([
            'ok' => true,
            'scheduled_task' => $scheduler->taskPayload($task),
        ]);
    }

    private function runNow(AtlasCliSchedulerService $scheduler): int
    {
        $task = $scheduler->runNow($this->idArgument(), sync: ! (bool) $this->option('queue'));

        return $this->printPayload([
            'ok' => true,
            'queued' => (bool) $this->option('queue'),
            'scheduled_task' => $scheduler->taskPayload($task),
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function printPayload(array $payload, int $exitCode = self::SUCCESS): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $exitCode;
        }

        if (($payload['ok'] ?? false) === false) {
            $this->error((string) ($payload['error'] ?? 'Erro desconhecido.'));

            return $exitCode;
        }

        if (isset($payload['scheduled_tasks']) && is_array($payload['scheduled_tasks'])) {
            $this->table(['id', 'title', 'enabled', 'schedule', 'next_run_at', 'last_status'], collect($payload['scheduled_tasks'])->map(fn (array $task): array => [
                Str::limit((string) $task['id'], 8, ''),
                $task['title'],
                $task['enabled'] ? 'yes' : 'no',
                $task['schedule'],
                $task['next_run_at'] ?? '-',
                $task['last_status'] ?? '-',
            ])->all());

            return $exitCode;
        }

        if (isset($payload['scheduled_task']) && is_array($payload['scheduled_task'])) {
            $task = $payload['scheduled_task'];
            $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Scheduled Task</>', (string) $task['title']);
            $this->components->twoColumnDetail('ID', (string) $task['id']);
            $this->components->twoColumnDetail('Schedule', (string) $task['schedule']);
            $this->components->twoColumnDetail('Enabled', $task['enabled'] ? 'yes' : 'no');
            $this->components->twoColumnDetail('Next run', (string) ($task['next_run_at'] ?? '-'));
            $this->components->twoColumnDetail('Last status', (string) ($task['last_status'] ?? '-'));
            if (($task['last_output_tail'] ?? '') !== '') {
                $this->newLine();
                $this->line((string) $task['last_output_tail']);
            }
        }

        return $exitCode;
    }

    private function idArgument(): string
    {
        $id = $this->argument('id');
        if (! is_string($id) || trim($id) === '') {
            throw new \InvalidArgumentException('Informe o id da scheduled task.');
        }

        return trim($id);
    }

    /**
     * @return array<int,string>
     */
    private function skillOptions(): array
    {
        return collect((array) $this->option('skill'))
            ->filter(fn (mixed $skill): bool => is_string($skill) && trim($skill) !== '')
            ->map(fn (mixed $skill): string => trim((string) $skill))
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function contextFromOptions(): array
    {
        return collect((array) $this->option('context-from'))
            ->filter(fn (mixed $id): bool => is_string($id) && trim($id) !== '')
            ->map(fn (mixed $id): string => trim((string) $id))
            ->values()
            ->all();
    }

    private function workspaceOption(): ?string
    {
        $workspace = $this->option('workspace');
        if (! is_string($workspace) || trim($workspace) === '') {
            $workspace = getcwd() ?: null;
        }

        return $workspace;
    }

    private function repeatOption(): ?int
    {
        $repeat = $this->option('repeat');
        if (! is_numeric($repeat)) {
            return null;
        }

        return (int) $repeat;
    }

    private function severity(): string
    {
        $severity = Str::of((string) $this->option('severity'))->lower()->trim()->value();

        return in_array($severity, ['info', 'warning', 'critical'], true) ? $severity : 'info';
    }

    private function outputTail(?string $path): ?string
    {
        if (! is_string($path) || $path === '' || ! File::isFile($path)) {
            return null;
        }

        return Str::limit(File::get($path), 4000, "\n...[truncated]");
    }
}
