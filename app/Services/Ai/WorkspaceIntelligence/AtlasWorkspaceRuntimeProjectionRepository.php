<?php

declare(strict_types=1);

namespace App\Services\Ai\WorkspaceIntelligence;

use App\Models\AtlasWorkspaceRuntimeProjectionSnapshot;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

final class AtlasWorkspaceRuntimeProjectionRepository
{
    /**
     * @return array<string,string>
     */
    public function persist(array $report): array
    {
        if (! Schema::hasTable('atlas_workspace_runtime_projection_snapshots')) {
            return [];
        }

        $runtimeHash = (string) ($report['runtime_hash'] ?? '');
        $workspaceId = (string) data_get($report, 'workspace.workspace_id', 'unknown');
        if ($runtimeHash === '' || $workspaceId === '' || $workspaceId === 'unknown') {
            return [];
        }

        $ids = [];
        $capturedAt = Carbon::now();
        foreach ([
            'AWTR' => 'awtr',
            'AWCO' => 'awco',
            'AWEF' => 'awef',
            'AWIL' => 'awis_learning_loop',
            'AWNSB' => 'workspace_next_session_brain',
        ] as $family => $key) {
            $payload = (array) ($report[$key] ?? []);
            if ($payload === []) {
                continue;
            }
            $payload['awis_projection'] = [
                'schema_version' => 'atlas.awis.runtime_projection_binding.v1',
                'workspace_id' => $workspaceId,
                'workspace_hash' => data_get($report, 'workspace.workspace_hash'),
                'runtime_hash' => $runtimeHash,
                'family' => $family,
                'stale_policy' => 'block_latest_replay_when_workspace_hash_differs_or_is_missing',
            ];

            $projectionHash = $this->projectionHash($payload);
            $payload['awis_projection']['projection_hash'] = $projectionHash;
            $snapshot = AtlasWorkspaceRuntimeProjectionSnapshot::query()->updateOrCreate(
                [
                    'runtime_hash' => $runtimeHash,
                    'family' => $family,
                ],
                [
                    'workspace_id' => $workspaceId,
                    'schema_version' => (string) ($payload['schema_version'] ?? 'unknown'),
                    'projection_hash' => $projectionHash,
                    'status' => (string) ($payload['status'] ?? 'blocked'),
                    'payload' => $payload,
                    'captured_at' => $capturedAt,
                ],
            );
            $ids[$family] = (string) $snapshot->id;
        }

        return $ids;
    }

    public function latest(string $workspaceId, string $family): ?AtlasWorkspaceRuntimeProjectionSnapshot
    {
        if (! Schema::hasTable('atlas_workspace_runtime_projection_snapshots')) {
            return null;
        }

        return AtlasWorkspaceRuntimeProjectionSnapshot::query()
            ->where('workspace_id', $workspaceId)
            ->where('family', mb_strtoupper($family))
            ->latest('captured_at')
            ->first();
    }

    private function projectionHash(array $payload): string
    {
        return MissionCanonicalHash::sha256($payload);
    }
}
