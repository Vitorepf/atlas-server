<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Fanout;

use App\Models\AiJob;
use App\Services\Ai\AgenticWorkcell\Contracts\WorkcellAdapter;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopObraDecompositionPlanner;

/**
 * ATLAS REDONDO SLICE 3 — the Loop-invoked FAN-OUT PRODUCER.
 *
 * Two organs already exist but were never wired together:
 *  - the fan-out MECHANISM: {@see WorkcellAdapter} (HermesWorkcellAdapter →
 *    HermesMeshProcessWorkerFactory) already spawns isolated per-worktree
 *    workers, default-off by sovereignty (workcell.policy=atlas_adapter);
 *  - the Loop's obra DECOMPOSITION: {@see AtlasLoopObraDecompositionPlanner}
 *    already turns a big obra into a ready DAG of nodes.
 *
 * This is the missing bridge: it turns a READY obra-decomposition (N DAG
 * nodes) into N parallel worktree cells and composes the governed fan-out plan
 * through the SAME atlas_adapter policy gate that guards live dispatch. The
 * concrete runtime (Workcell Adapter → Hermes) is an implementation detail
 * behind the contract; this producer names no provider.
 *
 * Sovereignty (pétreo): PURE — composes a plan, launches nothing. Under
 * workcell.policy=off (default) the composed plan is dispatch_allowed_now=false
 * ⇒ nothing runs. Only when the operator sets workcell.policy=atlas_adapter
 * does the plan become dispatch-ready; the actual spawn stays behind the
 * existing `atlas:hermes:workcell dispatch` gate (policy + --confirm). This
 * producer NEVER auto-activates the fleet.
 */
final class AtlasLoopFanOutProducer
{
    public const SCHEMA = 'atlas.loop.fanout.v1';

    public function __construct(private readonly WorkcellAdapter $workcell) {}

    /**
     * Compose a governed fan-out plan from a ready obra-decomposition envelope.
     *
     * @param  array<string,mixed>  $decomposition  the AtlasLoopObraDecompositionPlanner::plan() envelope
     * @return array<string,mixed>
     */
    public function planFanOut(string $goal, array $decomposition, string $permissionMode = 'write'): array
    {
        $goal = trim($goal);
        $ready = ($decomposition['ready'] ?? null) === true;
        $nodes = is_array($decomposition['plan']['nodes'] ?? null)
            ? array_values(array_filter((array) $decomposition['plan']['nodes'], 'is_array'))
            : [];

        if (! $ready || $nodes === []) {
            return [
                'schema_version' => self::SCHEMA,
                'goal_hash' => $this->hash($goal),
                'fanned_out' => false,
                'reason' => ! $ready ? 'decomposition_not_ready' : 'decomposition_has_no_nodes',
                'cell_count' => 0,
                'policy_enabled' => $this->policyEnabled(),
                'plan' => null,
            ];
        }

        $subtasks = $this->cellsFromNodes($nodes, $permissionMode);
        $mission = [
            'schema_version' => 'atlas.hermes.executive_mission.v1',
            'mission_id' => 'loop-fanout-'.$this->hash($goal),
            'scope' => ['permission_mode' => $permissionMode],
        ];
        $policy = ['enabled' => $this->policyEnabled(), 'policy' => $this->policyValue()];

        $plan = $this->workcell->plan($this->transientJob($goal), $mission, $subtasks, $policy);
        $dispatchable = ($plan['dispatch_allowed_now'] ?? false) === true;

        return [
            'schema_version' => self::SCHEMA,
            'goal_hash' => $this->hash($goal),
            'fanned_out' => $dispatchable,
            'reason' => $dispatchable ? null : ($plan['blocked_reason'] ?? 'policy_off'),
            'cell_count' => count($subtasks),
            'policy_enabled' => $this->policyEnabled(),
            'plan' => $plan,
        ];
    }

    /**
     * Map each ready DAG node to an isolated worktree cell.
     *
     * @param  array<int,array<string,mixed>>  $nodes
     * @return array<int,array<string,mixed>>
     */
    private function cellsFromNodes(array $nodes, string $permissionMode): array
    {
        $write = $permissionMode === 'write';

        return array_values(array_map(static function (array $node) use ($write): array {
            $allowed = $node['allowed_files'] ?? $node['target_area'] ?? [];

            return [
                'objective' => (string) ($node['request'] ?? $node['objective'] ?? ''),
                'role' => is_string($node['role'] ?? null) && $node['role'] !== '' ? (string) $node['role'] : 'coder',
                'toolsets' => $write ? ['file'] : ['read'],
                'worktree' => true, // isolated per cell — the whole point of the fleet
                'allowed_files' => is_array($allowed) ? array_values($allowed) : [$allowed],
                'depends_on' => is_array($node['depends_on'] ?? null) ? array_values($node['depends_on']) : [],
            ];
        }, $nodes));
    }

    private function policyEnabled(): bool
    {
        return $this->policyValue() === 'atlas_adapter';
    }

    private function policyValue(): string
    {
        return (string) config('atlas.ai.providers.hermes_cli.workcell.policy', 'off');
    }

    private function transientJob(string $goal): AiJob
    {
        return new AiJob([
            'trace_id' => 'loop-fanout-'.$this->hash($goal),
            'payload' => ['hermes' => []],
        ]);
    }

    private function hash(string $goal): string
    {
        return substr(hash('sha256', $goal), 0, 16);
    }
}
