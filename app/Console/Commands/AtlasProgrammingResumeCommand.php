<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\ProgrammingResumeService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use App\Support\YesNo;

class AtlasProgrammingResumeCommand extends Command
{
    protected $signature = 'atlas:programming:resume
        {parent_plan_id : Parent programming plan id to resume from}
        {--plan-id= : Optional child plan id}
        {--json : Print machine-readable JSON}';

    protected $description = 'Build a programming resume state from persisted stage receipts.';

    public function handle(ProgrammingResumeService $resume): int
    {
        $parentPlanId = trim((string) $this->argument('parent_plan_id'));
        $planId = trim((string) ($this->option('plan-id') ?: Str::orderedUuid()));

        if ($parentPlanId === '') {
            $payload = [
                'ok' => false,
                'error' => 'parent_plan_id_required',
            ];

            return $this->render($payload, self::FAILURE);
        }

        $state = $resume->state($planId, $parentPlanId);
        $payload = [
            'ok' => (bool) ($state['resume_allowed'] ?? false),
            'resume_state' => $state,
            'next_action' => (bool) ($state['resume_allowed'] ?? false)
                ? 'continue_from_latest_stage'
                : 'human_review_invalid_receipt_timeline',
        ];

        return $this->render($payload, $payload['ok'] ? self::SUCCESS : self::FAILURE);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function render(array $payload, int $exitCode): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return $exitCode;
        }

        $this->components->twoColumnDetail('ok', YesNo::format($payload['ok'] ?? false));
        $this->components->twoColumnDetail('next action', (string) ($payload['next_action'] ?? '-'));

        return $exitCode;
    }
}
