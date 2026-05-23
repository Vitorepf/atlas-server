<?php

declare(strict_types=1);

namespace App\Services\Ai\WorkspaceIntelligence;

use App\Models\AtlasWorkspaceIntelligenceSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

final class AtlasWorkspaceIntelligenceSnapshotRepository
{
    public function persist(array $report): ?AtlasWorkspaceIntelligenceSnapshot
    {
        if (! Schema::hasTable('atlas_workspace_intelligence_snapshots')) {
            return null;
        }

        return AtlasWorkspaceIntelligenceSnapshot::query()->updateOrCreate(
            ['runtime_hash' => (string) ($report['runtime_hash'] ?? '')],
            [
                'schema_version' => (string) ($report['schema_version'] ?? AtlasWorkspaceIntelligenceRuntimeService::SCHEMA_VERSION),
                'workspace_id' => (string) data_get($report, 'workspace.workspace_id', 'unknown'),
                'workspace_hash' => data_get($report, 'workspace.workspace_hash'),
                'status' => (string) ($report['status'] ?? 'blocked'),
                'checks_total' => (int) data_get($report, 'summary.total', 0),
                'checks_passed' => (int) data_get($report, 'summary.passed', 0),
                'checks_failed' => (int) data_get($report, 'summary.failed', 0),
                'artifacts_count' => (int) data_get($report, 'awaf.artifact_count', 0),
                'family_status' => $this->familyStatus($report),
                'payload' => $report,
                'captured_at' => Carbon::now(),
            ],
        );
    }

    public function latest(string $workspaceId): ?AtlasWorkspaceIntelligenceSnapshot
    {
        if (! Schema::hasTable('atlas_workspace_intelligence_snapshots')) {
            return null;
        }

        return AtlasWorkspaceIntelligenceSnapshot::query()
            ->where('workspace_id', $workspaceId)
            ->latest('captured_at')
            ->first();
    }

    /**
     * @return array<string,string>
     */
    private function familyStatus(array $report): array
    {
        return [
            'AWIS' => (string) data_get($report, 'workspace.status', 'unknown'),
            'AWTR' => (string) data_get($report, 'awtr.status', 'unknown'),
            'ACIOS' => (string) data_get($report, 'acios.status', 'unknown'),
            'AWAF' => (string) data_get($report, 'awaf.status', 'unknown'),
            'AWAIR' => (string) data_get($report, 'awair.status', 'unknown'),
            'AWCO' => (string) data_get($report, 'awco.status', 'unknown'),
            'AWEF' => (string) data_get($report, 'awef.status', 'unknown'),
        ];
    }
}
