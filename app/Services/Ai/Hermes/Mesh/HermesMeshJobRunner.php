<?php

namespace App\Services\Ai\Hermes\Mesh;

use App\Models\AiJob;
use App\Services\Ai\AiProviderResult;
use App\Services\Ai\HermesCliProvider;

/**
 * Executes an AtlasDecide-routed mesh job (kind='mesh') as a governed Hermes
 * executive-mesh fan-out, returning an {@see AiProviderResult} the worker writes
 * to the trace exactly like a single-provider result.
 *
 * Mirrors the {@see HermesCliProvider} ACP pattern: this is a
 * TRY — it returns null whenever the job is not a mesh route, the operator has
 * not opted into auto-routing, there is no decomposition, or the mesh fan-out
 * dispatched nothing — so the worker transparently falls back to the normal
 * single-provider path and the user always gets an answer.
 *
 * Triple fail-closed (in addition to the advisor that decided the route): it
 * re-checks `mesh.policy=atlas_adapter` AND `mesh.auto_route=true` here, and the
 * underlying {@see HermesExecutiveMeshService} independently refuses to dispatch
 * unless its own policy gate is satisfied. So a stale-config or mis-enqueued
 * mesh job degrades safely to a single-provider run, never an ungoverned fleet.
 */
class HermesMeshJobRunner
{
    public function __construct(
        private readonly HermesExecutiveMeshService $mesh,
        private readonly HermesMeshProcessWorkerFactory $workers,
    ) {}

    /**
     * Run the job as a mesh fan-out, or return null to fall back to a single
     * provider (not a mesh route / not opted-in / no subtasks / nothing dispatched).
     */
    public function run(AiJob $job): ?AiProviderResult
    {
        if (($job->kind ?? null) !== 'mesh' || ! $this->autoRouteAllowed()) {
            return null;
        }

        $subtasks = $this->subtasks($job);
        if ($subtasks === []) {
            return null;
        }

        $mission = $this->parentMission($job, $subtasks);
        $objectivesByIndex = [];
        foreach ($subtasks as $i => $subtask) {
            $objectivesByIndex[$i] = (string) ($subtask['objective'] ?? '');
        }

        $startedAt = microtime(true);
        $result = $this->mesh->run(
            $job,
            $mission,
            $subtasks,
            $this->workers->workerFor($objectivesByIndex, $this->workdir($job)),
            ['enabled' => true], // policy already re-checked above; the service re-gates too
        );

        $run = is_array($result['run'] ?? null) ? $result['run'] : [];
        $reconciliation = is_array($result['reconciliation'] ?? null) ? $result['reconciliation'] : [];
        $dispatched = (int) ($run['dispatched_count'] ?? 0);
        if ($dispatched === 0) {
            return null; // fail-closed: the service launched nothing → single-provider fallback
        }

        $aggregate = (string) ($reconciliation['aggregate_status'] ?? 'empty');
        $ok = $aggregate === 'all_completed';
        $summary = sprintf(
            'Hermes executive mesh: %s — %d child task%s dispatched.',
            $aggregate,
            $dispatched,
            $dispatched === 1 ? '' : 's',
        );

        return new AiProviderResult(
            ok: $ok,
            output: $summary,
            command: ['hermes', 'mesh', 'dispatch'],
            exitCode: $ok ? 0 : 1,
            durationMs: (int) round((microtime(true) - $startedAt) * 1000),
            stdout: $summary,
            stderr: '',
            errorCode: $ok ? null : 'mesh_'.$aggregate,
            errorMessage: null,
            metadata: [
                'hermes_transport' => 'mesh',
                'hermes_mesh_run' => $run,
                'hermes_mesh_reconciliation' => $reconciliation,
                'hermes_mesh_checkpoint_actions' => is_array($result['checkpoint_actions'] ?? null) ? $result['checkpoint_actions'] : [],
            ],
        );
    }

    /**
     * Both consents required: mesh dispatch ALLOWED (policy) AND Decide may
     * AUTO-route (auto_route). Either off → null → single-provider fallback.
     */
    private function autoRouteAllowed(): bool
    {
        return config('atlas.ai.providers.hermes_cli.mesh.policy') === 'atlas_adapter'
            && (bool) config('atlas.ai.providers.hermes_cli.mesh.auto_route', false);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function subtasks(AiJob $job): array
    {
        $subtasks = data_get($job->payload, 'hermes.mesh.subtasks');

        return is_array($subtasks) ? array_values(array_filter($subtasks, 'is_array')) : [];
    }

    /**
     * Minimal parent mission (the mesh planner only needs the permission scope) —
     * objective text lives transiently in the per-child objectives, never sealed.
     *
     * @param  array<int,array<string,mixed>>  $subtasks
     * @return array<string,mixed>
     */
    private function parentMission(AiJob $job, array $subtasks): array
    {
        $mode = data_get($job->payload, 'hermes.mesh.permission_mode');
        $mode = is_string($mode) && in_array($mode, ['read', 'write', 'danger'], true) ? $mode : 'read';

        return [
            'schema_version' => 'atlas.hermes.executive_mission.v1',
            'mission_id' => 'mesh-job-'.substr(hash('sha256', (string) $job->id.'|'.json_encode($subtasks)), 0, 16),
            'scope' => ['permission_mode' => $mode],
        ];
    }

    private function workdir(AiJob $job): ?string
    {
        $workspace = data_get($job->payload, 'workspace');

        return is_string($workspace) && trim($workspace) !== '' ? $workspace : null;
    }
}
