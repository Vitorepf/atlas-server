<?php

namespace App\Http\Controllers;

use App\Models\AiJob;
use App\Models\AiQualityAction;
use App\Models\AiQualityEvaluation;
use App\Models\AiThread;
use App\Models\AiTrace;
use App\Services\Ai\Telemetry\AiTelemetryHealthService;
use App\Services\Ai\Telemetry\AiTelemetryScorecardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AiObservabilityController extends Controller
{
    public function __invoke(
        Request $request,
        AiTelemetryScorecardService $scorecards,
        AiTelemetryHealthService $health,
    ): JsonResponse
    {
        $data = $request->validate([
            'hours' => ['nullable', 'integer', 'between:1,720'],
        ]);

        $since = now()->subHours((int) ($data['hours'] ?? 24));

        return response()->json([
            'window' => [
                'since' => $since->toJSON(),
                'until' => now()->toJSON(),
            ],
            'threads' => [
                'active' => AiThread::query()->where('status', 'active')->count(),
                'atlas_cli' => AiThread::query()->where('surface', 'atlas_cli')->count(),
            ],
            'traces' => [
                'total' => AiTrace::query()->where('created_at', '>=', $since)->count(),
                'by_status' => $this->countsBy(AiTrace::query()->where('created_at', '>=', $since), 'status'),
                'by_provider' => $this->countsBy(AiTrace::query()->where('created_at', '>=', $since), 'provider'),
            ],
            'jobs' => [
                'queued' => AiJob::query()->where('status', 'queued')->count(),
                'processing' => AiJob::query()->where('status', 'processing')->count(),
                'failed_24h' => AiJob::query()->where('status', 'failed')->where('updated_at', '>=', now()->subDay())->count(),
            ],
            'quality' => $this->quality($since),
            'metrics' => $scorecards->build($since),
            'metrics_health' => $health->evaluate($since),
            'actions' => $this->actions(),
        ]);
    }

    private function quality($since): array
    {
        if (! Schema::hasTable('ai_quality_evaluations')) {
            return ['available' => false];
        }

        $averageScore = AiQualityEvaluation::query()
            ->where('created_at', '>=', $since)
            ->avg('score');

        return [
            'available' => true,
            'total' => AiQualityEvaluation::query()->where('created_at', '>=', $since)->count(),
            'average_score' => $averageScore === null ? null : round((float) $averageScore, 2),
            'by_status' => $this->countsBy(AiQualityEvaluation::query()->where('created_at', '>=', $since), 'status'),
            'recent_needs_review' => AiQualityEvaluation::query()
                ->whereIn('status', ['needs_review', 'failed'])
                ->latest('created_at')
                ->limit(10)
                ->get(['id', 'trace_id', 'thread_id', 'provider', 'score', 'status', 'flags', 'created_at'])
                ->map(fn (AiQualityEvaluation $evaluation): array => [
                    'id' => $evaluation->id,
                    'trace_id' => $evaluation->trace_id,
                    'thread_id' => $evaluation->thread_id,
                    'provider' => $evaluation->provider,
                    'score' => $evaluation->score,
                    'status' => $evaluation->status,
                    'flags' => collect($evaluation->flags)->pluck('code')->values()->all(),
                    'created_at' => $evaluation->created_at?->toJSON(),
                ])
                ->values()
                ->all(),
        ];
    }

    private function actions(): array
    {
        if (! Schema::hasTable('ai_quality_actions')) {
            return ['available' => false];
        }

        return [
            'available' => true,
            'by_status' => $this->countsBy(AiQualityAction::query(), 'status'),
            'open' => AiQualityAction::query()->whereIn('status', ['queued', 'running', 'blocked', 'failed'])->count(),
            'recent' => AiQualityAction::query()
                ->latest('created_at')
                ->limit(10)
                ->get(['id', 'trace_id', 'remediation_trace_id', 'action_type', 'status', 'priority', 'reason', 'created_at'])
                ->map(fn (AiQualityAction $action): array => [
                    'id' => $action->id,
                    'trace_id' => $action->trace_id,
                    'remediation_trace_id' => $action->remediation_trace_id,
                    'action_type' => $action->action_type,
                    'status' => $action->status,
                    'priority' => $action->priority,
                    'reason' => $action->reason,
                    'created_at' => $action->created_at?->toJSON(),
                ])
                ->values()
                ->all(),
        ];
    }

    private function countsBy($query, string $column): array
    {
        return $query
            ->select($column, DB::raw('COUNT(*) as aggregate'))
            ->groupBy($column)
            ->orderByDesc('aggregate')
            ->get()
            ->mapWithKeys(fn ($row): array => [($row->{$column} ?: 'unknown') => (int) $row->aggregate])
            ->all();
    }
}
