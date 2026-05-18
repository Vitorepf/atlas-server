<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Console\ProgrammingConsoleCanon;
use App\Services\Ai\Programming\Console\ProgrammingConsoleService;
use Illuminate\Console\Command;

/**
 * Atlas Programming Console — single CLI surface over the existing read
 * models for Dev / Forge / Telemetry / Control Plane, plus two safe write
 * paths (dev:plan = preview only, forge:intake = canonical Forge intake row).
 *
 * Hard contract:
 *  - never invokes a provider;
 *  - never runs a benchmark;
 *  - emits a single canonical envelope shape per action.
 */
class AtlasProgrammingConsoleCommand extends Command
{
    protected $signature = 'atlas:programming:console
        {action=status : status|dev:plan|dev:summary|forge:intake|forge:summary|blockers|next-actions|evidence|certification|telemetry|smoke}
        {--prompt= : Prompt for dev:plan or forge:intake}
        {--flow=atlas_dev : Flow id hint for dev:plan}
        {--workspace-slug= : Optional workspace slug for forge:intake}
        {--dry-run : forge:intake only: skip the DB write and return a preview}
        {--json : print JSON only (compact for piping)}';

    protected $description = 'Atlas Programming Console — operate Dev/Forge runtime via a unified canonical JSON envelope. Read-only and safe-write actions only. Never invokes a provider, never runs a benchmark.';

    public function handle(ProgrammingConsoleService $service): int
    {
        $action = (string) $this->argument('action');
        if (! in_array($action, ProgrammingConsoleCanon::ACTIONS, true)) {
            $this->error('invalid action ['.$action.']; supported: '.implode(', ', ProgrammingConsoleCanon::ACTIONS));

            return Command::FAILURE;
        }

        $payload = $this->dispatch($service, $action);

        $this->printPayload($payload);

        return match ($payload['status']) {
            ProgrammingConsoleCanon::STATUS_BLOCKED => Command::FAILURE,
            default => Command::SUCCESS,
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function dispatch(ProgrammingConsoleService $service, string $action): array
    {
        $prompt = (string) ($this->option('prompt') ?? '');
        $flow = (string) ($this->option('flow') ?? 'atlas_dev');

        return match ($action) {
            ProgrammingConsoleCanon::ACTION_STATUS => $service->status(),
            ProgrammingConsoleCanon::ACTION_DEV_PLAN => $service->devPlan($prompt, $flow),
            ProgrammingConsoleCanon::ACTION_DEV_SUMMARY => $service->devSummary(),
            ProgrammingConsoleCanon::ACTION_FORGE_INTAKE => $service->forgeIntake($prompt, [
                'workspace_slug' => $this->option('workspace-slug'),
                'dry_run' => (bool) $this->option('dry-run'),
            ]),
            ProgrammingConsoleCanon::ACTION_FORGE_SUMMARY => $service->forgeSummary(),
            ProgrammingConsoleCanon::ACTION_BLOCKERS => $service->blockers(),
            ProgrammingConsoleCanon::ACTION_NEXT_ACTIONS => $service->nextActions(),
            ProgrammingConsoleCanon::ACTION_EVIDENCE => $service->evidence(),
            ProgrammingConsoleCanon::ACTION_CERTIFICATION => $service->certification(),
            ProgrammingConsoleCanon::ACTION_TELEMETRY => $service->telemetrySummary(),
            ProgrammingConsoleCanon::ACTION_SMOKE => $service->smoke(),
        };
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function printPayload(array $payload): void
    {
        $flags = $this->option('json')
            ? JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            : JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        $this->line(json_encode($payload, $flags) ?: '{}');
    }
}
