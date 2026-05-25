<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendRepairPlannerService;
use Illuminate\Console\Command;

class AtlasFrontendRepairPlanCommand extends Command
{
    protected $signature = 'atlas:frontend:repair-plan
        {--task= : Frontend task or brief}
        {--task-spec-hash= : Optional canonical task spec hash}
        {--blocker=* : Gate blocker id}
        {--warning=* : Gate warning id}
        {--failed-gate=* : Failed gate id}
        {--dimension-gap=* : Competitive dimension gap as dimension:points_to_match:delta_vs_best_rival:best_rival_score[:case_id[:best_rival_system[:atlas_score[:dimension_weight]]]]}
        {--json : Emit canonical JSON payload}
        {--strict : Exit non-zero when no repair signal is provided}';

    protected $description = 'Compile a deterministic Atlas Frontend repair plan from failed gates and blockers.';

    public function handle(AtlasFrontendRepairPlannerService $planner): int
    {
        $payload = $planner->plan([
            'task' => (string) ($this->option('task') ?: ''),
            'task_spec_hash' => (string) ($this->option('task-spec-hash') ?: ''),
            'blockers' => (array) $this->option('blocker'),
            'warnings' => (array) $this->option('warning'),
            'failed_gates' => (array) $this->option('failed-gate'),
            'dimension_gaps' => $this->dimensionGaps((array) $this->option('dimension-gap')),
        ]);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('Atlas Frontend Repair Plan: '.$payload['status']);
        }

        return (bool) $this->option('strict') && ($payload['status'] ?? null) === 'blocked'
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * @param  array<int,mixed>  $values
     * @return array<int,array<string,mixed>>
     */
    private function dimensionGaps(array $values): array
    {
        return collect($values)
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(function (string $value): array {
                $parts = array_map('trim', explode(':', $value));

                return [
                    'dimension' => $parts[0] ?? '',
                    'points_to_match' => (int) ($parts[1] ?? 0),
                    'delta_vs_best_rival' => (int) ($parts[2] ?? 0),
                    'best_rival_score' => (int) ($parts[3] ?? 0),
                    'case_id' => $parts[4] ?? null,
                    'best_rival_system' => $parts[5] ?? null,
                    'atlas_score' => (int) ($parts[6] ?? 0),
                    'dimension_weight' => (int) ($parts[7] ?? 0),
                ];
            })
            ->values()
            ->all();
    }
}
