<?php

namespace App\Http\Controllers;

use App\Models\AtlasProgrammingWorkItem;
use App\Services\Ai\Programming\Governance\ProgrammingAdaptiveHierarchicalControlPlaneService;
use App\Services\Ai\Programming\Governance\ProgrammingGovernanceService;
use App\Services\Ai\Programming\Governance\ProgrammingSpecCompiler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

/**
 * REST surface that the future Atlas Code SCOR-1 cockpit (and any other
 * consumer) reads from to render WorkItem, Spec, Plan, Tasks, Gate Runs,
 * Reviews and Evidence as live objects — not chat artifacts.
 *
 * @see docs/engineering-knowledge-base/atlas-code-long-session-programming-cockpit.md
 * @see docs/engineering-knowledge-base/atlas-programming-governance-system.md
 */
class AtlasProgrammingGovernanceController extends Controller
{
    public function __construct(
        private readonly ProgrammingGovernanceService $governance,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'max:32'],
            'intent_type' => ['nullable', 'string', 'max:40'],
            'scope_mode' => ['nullable', Rule::in(['compact', 'structural'])],
            'owner' => ['nullable', 'string', 'max:80'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = AtlasProgrammingWorkItem::query()->latest('created_at');
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (! empty($data['intent_type'])) {
            $query->where('intent_type', $data['intent_type']);
        }
        if (! empty($data['scope_mode'])) {
            $query->where('scope_mode', $data['scope_mode']);
        }
        if (! empty($data['owner'])) {
            $query->where('owner', $data['owner']);
        }
        $items = $query->limit((int) ($data['limit'] ?? 30))->get();

        return response()->json([
            'schema_version' => 'atlas.programming.work_item_index.v1',
            'count' => $items->count(),
            'work_items' => $items->map(function (AtlasProgrammingWorkItem $item): array {
                return [
                    'code' => $item->code,
                    'id' => $item->id,
                    'intent_text' => $item->intent_text,
                    'intent_type' => $item->intent_type,
                    'scope_mode' => $item->scope_mode,
                    'risk_level' => $item->risk_level,
                    'status' => $item->status,
                    'current_stage' => $item->current_stage,
                    'owner' => $item->owner,
                    'created_at' => $item->created_at?->toJSON(),
                    'updated_at' => $item->updated_at?->toJSON(),
                    'closed_at' => $item->closed_at?->toJSON(),
                ];
            })->all(),
        ]);
    }

    public function show(string $codeOrId): JsonResponse
    {
        try {
            $item = $this->governance->find($codeOrId);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        }

        return response()->json($this->fullSnapshot($item));
    }

    public function gateRuns(string $codeOrId): JsonResponse
    {
        try {
            $item = $this->governance->find($codeOrId);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        }

        $runs = $item->gateRuns()
            ->orderBy('created_at')
            ->get()
            ->map(static fn ($run): array => [
                'id' => $run->id,
                'gate_name' => $run->gate_name,
                'status' => $run->status,
                'blocking' => (bool) $run->blocking,
                'reason' => $run->reason,
                'waiver_reason' => $run->waiver_reason,
                'payload' => $run->payload_json,
                'created_at' => $run->created_at?->toJSON(),
                'decided_at' => $run->decided_at?->toJSON(),
            ])
            ->all();

        return response()->json([
            'schema_version' => 'atlas.programming.gate_runs_index.v1',
            'work_item' => $item->code,
            'count' => count($runs),
            'gate_runs' => $runs,
        ]);
    }

    public function adaptiveControlPlane(
        string $codeOrId,
        Request $request,
        ProgrammingAdaptiveHierarchicalControlPlaneService $controlPlane,
    ): JsonResponse {
        try {
            $item = $this->governance->find($codeOrId);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        }

        $data = $request->validate([
            'level' => ['nullable', Rule::in(['all', 'v2', 'v3', 'v4', 'v5'])],
            'allow_serialize' => ['nullable', 'boolean'],
        ]);

        $snapshot = $controlPlane->snapshot($item, [
            'allow_serialize' => (bool) ($data['allow_serialize'] ?? false),
        ]);

        return response()->json([
            'schema_version' => 'atlas.programming.adaptive_control_plane_response.v1',
            'work_item' => $item->code,
            'level' => (string) ($data['level'] ?? 'all'),
            'adaptive_control_plane' => $this->selectAdaptiveLevel($snapshot, (string) ($data['level'] ?? 'all')),
        ]);
    }

    public function compileSpec(string $codeOrId, ProgrammingSpecCompiler $compiler): JsonResponse
    {
        try {
            $item = $this->governance->find($codeOrId);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        }

        try {
            $compiled = $compiler->compile($item);
            $critique = $compiler->critique($compiled);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }

        return response()->json([
            'schema_version' => 'atlas.programming.spec_compile_response.v1',
            'work_item' => $item->code,
            'compiled' => $compiled,
            'critique' => $critique,
        'route_decision' => \App\Services\Ai\DualCore\CanonicalRouteDecisionEnvelope::emit(route: 'programming', reason: 'http_atlas_programming_governance_controller'),
    ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function fullSnapshot(AtlasProgrammingWorkItem $item): array
    {
        return array_merge($this->governance->snapshot($item), [
            'gate_runs' => $item->gateRuns()
                ->orderBy('created_at')
                ->get()
                ->map(static fn ($run): array => [
                    'id' => $run->id,
                    'gate_name' => $run->gate_name,
                    'status' => $run->status,
                    'blocking' => (bool) $run->blocking,
                    'reason' => $run->reason,
                    'created_at' => $run->created_at?->toJSON(),
                ])
                ->all(),
            'reviews' => $item->reviews()
                ->orderBy('created_at')
                ->get()
                ->map(static fn ($review): array => [
                    'id' => $review->id,
                    'result' => $review->result,
                    'summary' => $review->summary,
                    'risk_notes' => $review->risk_notes,
                    'decided_by' => $review->decided_by,
                    'created_at' => $review->created_at?->toJSON(),
                ])
            ->all(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return array<string,mixed>
     */
    private function selectAdaptiveLevel(array $snapshot, string $level): array
    {
        return match ($level) {
            'v2' => (array) ($snapshot['live_session_control_v2'] ?? []),
            'v3' => (array) ($snapshot['forge_multi_agent_control_v3'] ?? []),
            'v4' => (array) ($snapshot['predictive_replay_learning_v4'] ?? []),
            'v5' => (array) ($snapshot['optimization_control_twin_v5'] ?? []),
            default => $snapshot,
        };
    }
}
