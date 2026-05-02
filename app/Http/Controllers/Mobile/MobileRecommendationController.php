<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\AiPerformanceRecommendation;
use App\Models\AtlasMobileDevice;
use App\Services\Ai\Telemetry\Engine\RecommendationLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MobileRecommendationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $device = $this->device($request);
        $data = $request->validate([
            'state' => ['nullable', 'string', 'max:40'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = AiPerformanceRecommendation::query()
            ->where('user_id', $device->user_id)
            ->orderByDesc('priority_score')
            ->orderByDesc('created_at');

        $state = $data['state'] ?? 'open';
        if ($state === 'open') {
            $query->whereIn('state', RecommendationLifecycleService::OPEN_STATES);
        } elseif ($state !== 'all') {
            $query->where('state', $state);
        }

        $items = $query->limit((int) ($data['limit'] ?? 50))->get();

        return response()->json([
            'items' => $items->map(fn (AiPerformanceRecommendation $recommendation): array => $this->resource($recommendation))->values(),
            'generated_at' => now()->toJSON(),
        ]);
    }

    public function show(Request $request, AiPerformanceRecommendation $recommendation): JsonResponse
    {
        $this->authorizeRecommendation($request, $recommendation);

        return response()->json([
            'item' => $this->resource($recommendation),
        ]);
    }

    public function transition(Request $request, AiPerformanceRecommendation $recommendation, RecommendationLifecycleService $lifecycle): JsonResponse
    {
        $this->authorizeRecommendation($request, $recommendation);
        $data = $request->validate([
            'state' => ['required', Rule::in(['acknowledged', 'in_progress', 'applied', 'rejected', 'snoozed'])],
            'reason' => ['nullable', 'string', 'max:500'],
            'snoozed_until' => ['nullable', 'date', 'after:now'],
        ]);

        $metadata = [];
        if (isset($data['snoozed_until'])) {
            $metadata['snoozed_until'] = $data['snoozed_until'];
        }

        $updated = $lifecycle->transition(
            $recommendation,
            $data['state'],
            $data['reason'] ?? 'mobile_api_transition',
            ['source' => 'mobile_api', ...$metadata],
        );

        return response()->json([
            'ok' => true,
            'item' => $this->resource($updated),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function resource(AiPerformanceRecommendation $recommendation): array
    {
        return [
            'id' => $recommendation->id,
            'user_id' => $recommendation->user_id,
            'state' => $recommendation->state,
            'kind' => $recommendation->kind,
            'target_metric' => $recommendation->target_metric,
            'target_dimension' => $recommendation->target_dimension,
            'expected_impact' => $recommendation->expected_impact,
            'baseline_snapshot' => $recommendation->baseline_snapshot,
            'observed_impact' => $recommendation->observed_impact,
            'measurement_due_at' => $recommendation->measurement_due_at?->toJSON(),
            'measurement_window_days' => $recommendation->measurement_window_days,
            'priority_score' => $recommendation->priority_score,
            'snoozed_until' => $recommendation->snoozed_until?->toJSON(),
            'closed_at' => $recommendation->closed_at?->toJSON(),
            'closed_reason' => $recommendation->closed_reason,
            'created_at' => $recommendation->created_at?->toJSON(),
            'updated_at' => $recommendation->updated_at?->toJSON(),
        ];
    }

    private function authorizeRecommendation(Request $request, AiPerformanceRecommendation $recommendation): void
    {
        abort_unless($recommendation->user_id === $this->device($request)->user_id, 404);
    }

    private function device(Request $request): AtlasMobileDevice
    {
        /** @var AtlasMobileDevice $device */
        $device = $request->attributes->get('atlas_mobile_device');

        return $device;
    }
}
