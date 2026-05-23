<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceExecutionGateService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceRuntimeService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceSnapshotRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AtlasWorkspaceIntelligenceController extends Controller
{
    public function show(
        Request $request,
        AtlasWorkspaceIntelligenceRuntimeService $runtime,
        AtlasWorkspaceIntelligenceSnapshotRepository $snapshots,
    ): JsonResponse {
        if ($request->boolean('latest')) {
            $latest = $snapshots->latest($this->stringQuery($request, 'workspace') ?? 'atlas');
            if ($latest !== null) {
                return response()->json($latest->payload);
            }
        }

        $report = $runtime->certify(
            workspace: $this->stringQuery($request, 'workspace'),
            task: $this->stringQuery($request, 'task') ?? '',
            conversationTexts: [],
        );
        if ($request->boolean('persist')) {
            $snapshot = $snapshots->persist($report);
            $report['persisted_snapshot_id'] = $snapshot?->id;
        }

        return response()->json($report, $report['status'] === 'blocked' ? 422 : 200);
    }

    public function artifacts(
        Request $request,
        AtlasWorkspaceIntelligenceRuntimeService $runtime,
        AtlasWorkspaceIntelligenceSnapshotRepository $snapshots,
    ): JsonResponse {
        if ($request->boolean('latest')) {
            $latest = $snapshots->latest($this->stringQuery($request, 'workspace') ?? 'atlas');
            if ($latest !== null) {
                return response()->json(data_get($latest->payload, 'awaf', []));
            }
        }

        $report = $runtime->certify(
            workspace: $this->stringQuery($request, 'workspace'),
            task: $this->stringQuery($request, 'task') ?? '',
            conversationTexts: [],
        );

        return response()->json($report['awaf'] ?? [], $report['status'] === 'blocked' ? 422 : 200);
    }

    public function gate(
        Request $request,
        AtlasWorkspaceIntelligenceExecutionGateService $gate,
    ): JsonResponse {
        $report = $gate->gate(
            workspace: $this->stringQuery($request, 'workspace'),
            mode: $this->stringQuery($request, 'mode') ?? 'conversation',
            task: $this->stringQuery($request, 'task') ?? '',
            conversationTexts: [],
        );

        return response()->json($report, ($report['allowed'] ?? false) === true ? 200 : 422);
    }

    private function stringQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
