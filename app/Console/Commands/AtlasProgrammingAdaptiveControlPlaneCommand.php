<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Governance\ProgrammingAdaptiveHierarchicalControlPlaneService;
use App\Services\Ai\Programming\Governance\ProgrammingGovernanceService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Adaptive Hierarchical Control Plane CLI.
 *
 * @see docs/engineering-knowledge-base/atlas-adaptive-hierarchical-control-plane.md
 */
class AtlasProgrammingAdaptiveControlPlaneCommand extends Command
{
    protected $signature = 'atlas:programming:adaptive-control-plane
        {work_item : Work item code or UUID}
        {--level=all : all|v2|v3|v4|v5}
        {--allow-serialize : Allow Forge to serialize overlapping packets instead of blocking}
        {--persist-event : Persist this control-plane snapshot into the Programming Evidence Ledger}
        {--emit-learning : Queue v4/v5 learning candidates into the governed review queue}
        {--strict : Exit non-zero when the requested level reports blocked/not-ready}
        {--json : Print machine-readable JSON}';

    protected $description = 'Evaluate AHCL v2/v3/v4/v5 adaptive control for a programming work item.';

    public function handle(
        ProgrammingGovernanceService $governance,
        ProgrammingAdaptiveHierarchicalControlPlaneService $controlPlane,
    ): int {
        try {
            $workItem = $governance->find((string) $this->argument('work_item'));
            $payload = $controlPlane->snapshot($workItem, [
                'allow_serialize' => (bool) $this->option('allow-serialize'),
            ]);
            if ((bool) $this->option('persist-event')) {
                $payload['control_event_persistence'] = $controlPlane->persistControlEvent($workItem, $payload);
            }
            if ((bool) $this->option('emit-learning')) {
                $payload['learning_emission'] = $controlPlane->emitLearningCandidates($workItem->refresh(), $payload);
            }
            $payload = $this->selectLevel($payload, (string) $this->option('level'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return $this->resolveExit($payload, (bool) $this->option('strict'));
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Adaptive HCP</>', (string) data_get($payload, 'work_item_code', '-'));
        $this->components->twoColumnDetail('V2 action', (string) data_get($payload, 'live_session_control_v2.next_tick.action', data_get($payload, 'next_tick.action', '-')));
        $this->components->twoColumnDetail('V3 status', (string) data_get($payload, 'forge_multi_agent_control_v3.status', data_get($payload, 'status', '-')));
        $this->components->twoColumnDetail('V4 path', (string) data_get($payload, 'predictive_replay_learning_v4.recommended_path.path', data_get($payload, 'recommended_path.path', '-')));
        $this->components->twoColumnDetail('V5 twin', (string) data_get($payload, 'optimization_control_twin_v5.status', data_get($payload, 'optimization_decision.action', '-')));
        $this->components->twoColumnDetail('Plane hash', (string) data_get($payload, 'plane_hash', '-'));

        return $this->resolveExit($payload, (bool) $this->option('strict'));
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function selectLevel(array $payload, string $level): array
    {
        return match ($level) {
            'v2' => array_merge([
                'work_item_id' => $payload['work_item_id'] ?? null,
                'work_item_code' => $payload['work_item_code'] ?? null,
            ], (array) ($payload['live_session_control_v2'] ?? [])),
            'v3' => array_merge([
                'work_item_id' => $payload['work_item_id'] ?? null,
                'work_item_code' => $payload['work_item_code'] ?? null,
            ], (array) ($payload['forge_multi_agent_control_v3'] ?? [])),
            'v4' => array_merge([
                'work_item_id' => $payload['work_item_id'] ?? null,
                'work_item_code' => $payload['work_item_code'] ?? null,
            ], (array) ($payload['predictive_replay_learning_v4'] ?? [])),
            'v5' => array_merge([
                'work_item_id' => $payload['work_item_id'] ?? null,
                'work_item_code' => $payload['work_item_code'] ?? null,
            ], (array) ($payload['optimization_control_twin_v5'] ?? [])),
            'all' => $payload,
            default => throw new \InvalidArgumentException('invalid_level:'.$level),
        };
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function resolveExit(array $payload, bool $strict): int
    {
        if (! $strict) {
            return self::SUCCESS;
        }

        $statuses = [
            (string) data_get($payload, 'live_session_control_v2.status', data_get($payload, 'status', '')),
            (string) data_get($payload, 'forge_multi_agent_control_v3.status', ''),
            (string) data_get($payload, 'predictive_replay_learning_v4.status', data_get($payload, 'recommended_path.status', '')),
        ];

        foreach ($statuses as $status) {
            if (in_array($status, ['blocked', 'not_ready', 'operator_decision_required', 'planning_required'], true)) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function encode(array $payload): string
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? '{}' : $json;
    }
}
