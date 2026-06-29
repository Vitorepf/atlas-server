<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\Maestro\AtlasMaestroProjectLaneScheduler;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasMaestroProjectLaneScheduler::plan()} at the operator surface: previews how a
 * global worker budget is allocated across project lanes given per-lane demand and caps, emitting the
 * per-lane allocation plan (with denied lanes, unallocated budget and trim reasons) as deterministic facts.
 * Pure and read-only — it only computes the plan; it starts no workers.
 *
 * --demand / --caps accept inline JSON or a path to a JSON file.
 */
final class AtlasLoopLaneScheduleCommand extends Command
{
    protected $signature = 'atlas:loop:lane-schedule {--budget=0} {--demand=} {--caps=} {--json}';

    protected $description = 'Read-only: preview worker-budget allocation across project lanes.';

    public function handle(AtlasMaestroProjectLaneScheduler $scheduler): int
    {
        $this->line((string) json_encode(
            $scheduler->plan(
                max(0, (int) $this->option('budget')),
                $this->jsonOption('demand'),
                $this->jsonOption('caps'),
            ),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonOption(string $name): array
    {
        $value = $this->option($name);
        if ($value === null || trim((string) $value) === '') {
            return [];
        }
        $raw = is_file((string) $value) ? (string) file_get_contents((string) $value) : (string) $value;
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
