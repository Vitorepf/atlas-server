<?php

namespace App\Console\Commands;

use App\Models\AtlasTask;
use App\Services\Engineering\EngineeringQaService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasQaCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:qa
        {--task-id=}
        {--status=needs_review}
        {--confidence=}
        {--step=*}
        {--expected=}
        {--actual=}
        {--screenshot=}
        {--console=}
        {--network=}
        {--risk-notes=}
        {--visual-required}
        {--json}';

    protected $description = 'Record rich manual QA evidence for an Atlas task.';

    public function handle(EngineeringQaService $qa): int
    {
        $task = $this->task();
        if (! $task) {
            return self::FAILURE;
        }

        $payload = $qa->record($task, [
            'status' => (string) $this->option('status'),
            'confidence' => $this->option('confidence'),
            'steps' => (array) $this->option('step'),
            'expected_result' => (string) $this->option('expected'),
            'actual_result' => (string) $this->option('actual'),
            'screenshot_url' => $this->option('screenshot'),
            'console_output' => $this->option('console'),
            'network_output' => $this->option('network'),
            'risk_notes' => $this->option('risk-notes'),
            'visual_required' => (bool) $this->option('visual-required'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));
        } else {
            $this->components->twoColumnDetail('QA', (string) data_get($payload, 'qa.status'));
        }

        return $payload['blocking'] ? self::FAILURE : self::SUCCESS;
    }

    private function task(): ?AtlasTask
    {
        $taskId = trim((string) $this->option('task-id'));
        $task = $taskId !== '' ? AtlasTask::query()->find($taskId) : null;
        if (! $task) {
            $this->error('--task-id precisa apontar para uma task existente.');
        }

        return $task;
    }
}
