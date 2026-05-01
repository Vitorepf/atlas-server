<?php

namespace App\Http\Controllers;

use App\Http\Resources\AiOutcomeLinkResource;
use App\Http\Resources\AiProviderCostRateResource;
use App\Http\Resources\AiTraceMetricSummaryResource;
use App\Models\AiOutcomeLink;
use App\Models\AiProviderCostRate;
use App\Models\AiTraceMetricSummary;
use App\Services\Ai\Telemetry\AiOutcomeAttributionService;
use App\Services\Ai\Telemetry\AiProviderCostRateService;
use App\Services\Ai\Telemetry\AiTelemetryHealthService;
use App\Services\Ai\Telemetry\AiTelemetryScorecardService;
use App\Services\Ai\Telemetry\AiTraceMetricAggregator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class AiTelemetryMetricsController extends Controller
{
    public function scorecard(
        Request $request,
        AiTelemetryScorecardService $scorecards,
        AiTraceMetricAggregator $aggregator,
        AiTelemetryHealthService $health,
    ): JsonResponse {
        $data = $request->validate([
            'hours' => ['nullable', 'integer', 'between:1,720'],
            'recompute' => ['nullable', 'boolean'],
        ]);

        $hours = (int) ($data['hours'] ?? 24);
        $since = now()->subHours($hours);
        $recomputed = null;

        if ((bool) ($data['recompute'] ?? false) && Schema::hasTable('ai_trace_metric_summaries')) {
            $recomputed = $aggregator->recomputeWindow($since);
        }

        return response()->json([
            'scorecard' => $scorecards->build($since),
            'health' => $health->evaluate($since),
            'recomputed' => $recomputed,
        ]);
    }

    public function health(
        Request $request,
        AiTelemetryHealthService $health,
        AiTraceMetricAggregator $aggregator,
    ): JsonResponse {
        $data = $request->validate([
            'hours' => ['nullable', 'integer', 'between:1,720'],
            'recompute' => ['nullable', 'boolean'],
            'emit' => ['nullable', 'boolean'],
        ]);

        $hours = (int) ($data['hours'] ?? 24);
        $since = now()->subHours($hours);
        $recomputed = null;

        if ((bool) ($data['recompute'] ?? false) && Schema::hasTable('ai_trace_metric_summaries')) {
            $recomputed = $aggregator->recomputeWindow($since);
        }

        $evaluation = $health->evaluate($since);
        $insight = $health->emitInsight($evaluation, ! (bool) ($data['emit'] ?? false));

        return response()->json([
            'health' => $evaluation,
            'insight' => $insight,
            'recomputed' => $recomputed,
        ]);
    }

    public function summaries(Request $request): JsonResponse
    {
        if (! Schema::hasTable('ai_trace_metric_summaries')) {
            return response()->json([
                'summaries' => [],
                'available' => false,
            ]);
        }

        $data = $request->validate([
            'surface' => ['nullable', 'string', 'max:32'],
            'provider' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', 'string', 'max:32'],
            'thread_id' => ['nullable', 'uuid'],
            'trace_id' => ['nullable', 'uuid'],
            'limit' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $query = AiTraceMetricSummary::query()->latest('computed_at');
        foreach (['surface', 'provider', 'status', 'thread_id', 'trace_id'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }

        return response()->json([
            'available' => true,
            'summaries' => AiTraceMetricSummaryResource::collection(
                $query->limit((int) ($data['limit'] ?? 25))->get(),
            )->resolve(),
        ]);
    }

    public function costRates(Request $request, AiProviderCostRateService $rates): JsonResponse
    {
        if (! Schema::hasTable('ai_provider_cost_rates')) {
            return response()->json([
                'rates' => [],
                'available' => false,
            ]);
        }

        $data = $request->validate([
            'provider' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:120'],
            'active' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'between:1,200'],
        ]);

        $query = (bool) ($data['active'] ?? true)
            ? $rates->queryActive($data['provider'] ?? null, $data['model'] ?? null)
            : AiProviderCostRate::query()->latest('effective_from');

        foreach (['provider', 'model'] as $field) {
            if (! ($data['active'] ?? true) && isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }

        return response()->json([
            'available' => true,
            'rates' => AiProviderCostRateResource::collection(
                $query->limit((int) ($data['limit'] ?? 100))->get(),
            )->resolve(),
        ]);
    }

    public function missingCostRates(Request $request, AiProviderCostRateService $rates): JsonResponse
    {
        if (! Schema::hasTable('ai_trace_metric_summaries') || ! Schema::hasTable('ai_provider_cost_rates')) {
            return response()->json([
                'available' => false,
                'missing_rates' => [],
            ]);
        }

        $data = $request->validate([
            'hours' => ['nullable', 'integer', 'between:1,720'],
            'limit' => ['nullable', 'integer', 'between:1,200'],
        ]);

        return response()->json([
            'available' => true,
            'missing_rates' => $rates->missingRates(
                now()->subHours((int) ($data['hours'] ?? 168)),
                now(),
                (int) ($data['limit'] ?? 100),
            ),
        ]);
    }

    public function upsertCostRate(Request $request, AiProviderCostRateService $rates): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'string', 'max:80'],
            'model' => ['required', 'string', 'max:120'],
            'input_microusd_per_1k' => ['required', 'integer', 'min:0'],
            'output_microusd_per_1k' => ['required', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'max:8'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
            'metadata' => ['nullable', 'array'],
        ]);

        $rate = $rates->upsert($rates->withMetadataSource($data, 'api'));

        return response()->json([
            'rate' => (new AiProviderCostRateResource($rate))->resolve(),
        ], 201);
    }

    public function importCostRates(Request $request, AiProviderCostRateService $rates): JsonResponse
    {
        $data = $request->validate([
            'rates' => ['required', 'array', 'min:1', 'max:100'],
            'rates.*' => ['required', 'array'],
        ]);

        $result = $rates->upsertMany($data['rates'], 'api_import');
        $status = $result['errors'] === [] ? 201 : ($result['upserted'] === [] ? 422 : 207);

        return response()->json([
            'upserted' => AiProviderCostRateResource::collection(collect($result['upserted']))->resolve(),
            'errors' => $result['errors'],
        ], $status);
    }

    public function outcomes(Request $request): JsonResponse
    {
        if (! Schema::hasTable('ai_outcome_links')) {
            return response()->json([
                'outcomes' => [],
                'available' => false,
            ]);
        }

        $data = $request->validate([
            'trace_id' => ['nullable', 'uuid'],
            'thread_id' => ['nullable', 'uuid'],
            'outcome_type' => ['nullable', 'string', 'max:80'],
            'limit' => ['nullable', 'integer', 'between:1,200'],
        ]);

        $query = AiOutcomeLink::query()->latest('occurred_at');
        foreach (['trace_id', 'thread_id', 'outcome_type'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }

        return response()->json([
            'available' => true,
            'outcomes' => AiOutcomeLinkResource::collection(
                $query->limit((int) ($data['limit'] ?? 50))->get(),
            )->resolve(),
        ]);
    }

    public function recordOutcome(
        Request $request,
        AiOutcomeAttributionService $outcomes,
        AiTraceMetricAggregator $aggregator,
    ): JsonResponse {
        $data = $request->validate([
            'trace_id' => ['nullable', 'uuid'],
            'thread_id' => ['nullable', 'uuid'],
            'session_id' => ['nullable', 'uuid'],
            'outcome_type' => ['required', 'string', 'max:80'],
            'target_type' => ['nullable', 'string', 'max:80'],
            'target_id' => ['nullable', 'uuid'],
            'value_score' => ['nullable', 'integer', 'between:0,100'],
            'confidence' => ['nullable', 'numeric', 'between:0,1'],
            'source' => ['nullable', 'string', 'max:40'],
            'occurred_at' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array'],
        ]);

        $outcome = $outcomes->record($data + [
            'source' => 'api',
        ]);

        if ($outcome?->trace_id && Schema::hasTable('ai_trace_metric_summaries')) {
            $aggregator->recomputeTrace($outcome->trace_id);
        }

        return response()->json([
            'outcome' => $outcome ? (new AiOutcomeLinkResource($outcome))->resolve() : null,
        ], 201);
    }
}
