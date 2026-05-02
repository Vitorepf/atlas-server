<?php

namespace App\Http\Controllers;

use App\Http\Resources\AiProviderHealthResource;
use App\Http\Resources\AiWorkerEventResource;
use App\Models\AiJob;
use App\Models\AiProviderHealthSnapshot;
use App\Models\AiTraceMetricSummary;
use App\Models\AiWorkerEvent;
use App\Services\Ai\AiProviderHealthService;
use App\Services\Ai\AiProviderModelResolver;
use App\Services\Ai\AiRuntimeBudgetService;
use App\Services\Ai\AtlasAiRuntimeSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class AiProviderController extends Controller
{
    public function status(AiProviderModelResolver $models, AtlasAiRuntimeSettings $settings, AiRuntimeBudgetService $budgets): JsonResponse
    {
        $runtime = $settings->effective();
        $latest = AiProviderHealthSnapshot::query()
            ->whereIn('id', function ($query): void {
                $query->selectRaw('DISTINCT ON (provider) id')
                    ->from('ai_provider_health_snapshots')
                    ->orderBy('provider')
                    ->orderByDesc('checked_at');
            })
            ->orderBy('provider')
            ->get();
        $recentEvents = AiWorkerEvent::query()->orderByDesc('occurred_at')->limit(20)->get();
        $workerEventsByProvider = $this->latestWorkerEventsByProvider();

        return response()->json([
            'generated_at' => now()->toJSON(),
            'runtime_settings' => $runtime,
            'default_provider' => (string) ($runtime['default_provider'] ?? 'claude_cli'),
            'default_model' => $this->providerModelPolicy((string) ($runtime['default_provider'] ?? 'claude_cli'), $models, $settings),
            'model_policy' => $this->modelPolicyPayload($models, $settings),
            'budget' => $budgets->payload(),
            'queue' => $this->queuePayload(),
            'workers' => $this->workerRows($workerEventsByProvider),
            'providers' => $this->providerRows($latest, $workerEventsByProvider, $models, $settings),
            'usage_24h' => $this->usagePayload(24),
            'active_jobs' => $this->activeJobs(),
            'recent_events' => AiWorkerEventResource::collection($recentEvents)->resolve(),
        ]);
    }

    public function check(AiProviderHealthService $health): JsonResponse
    {
        return response()->json([
            'providers' => AiProviderHealthResource::collection($health->checkAll())->resolve(),
        ]);
    }

    public function updateSettings(Request $request, AtlasAiRuntimeSettings $settings, AiProviderModelResolver $models, AiRuntimeBudgetService $budgets): JsonResponse
    {
        $settings->update($request->all(), 'atlas_app');

        return $this->status($models, $settings, $budgets);
    }

    private function providerRows($latest, ?array $workerEventsByProvider = null, ?AiProviderModelResolver $models = null, ?AtlasAiRuntimeSettings $settings = null): array
    {
        $rowsByProvider = collect(AiProviderHealthResource::collection($latest)->resolve())
            ->keyBy('provider');
        $workerEventsByProvider ??= $this->latestWorkerEventsByProvider();
        $providerKeys = collect(array_keys((array) config('atlas.ai.providers', [])))
            ->merge($rowsByProvider->keys())
            ->unique()
            ->values();

        return $providerKeys
            ->map(function (string $provider) use ($rowsByProvider, $workerEventsByProvider, $models, $settings): array {
                $row = $rowsByProvider->get($provider) ?? [
                    'id' => null,
                    'provider' => $provider,
                    'status' => 'unknown',
                    'checked_at' => null,
                    'last_success_at' => null,
                    'last_failure_at' => null,
                    'total_jobs_24h' => AiJob::query()->where('provider', $provider)->where('created_at', '>=', now()->subDay())->count(),
                    'failed_jobs_24h' => AiJob::query()->where('provider', $provider)->where('status', 'failed')->where('created_at', '>=', now()->subDay())->count(),
                    'p50_latency_ms' => null,
                    'operational_pain_score' => 0,
                    'message' => 'Sem health check registrado.',
                    'metadata' => [],
                    'created_at' => null,
                ];

                if ($models && $settings) {
                    $row = array_merge($row, $this->providerModelPolicy($provider, $models, $settings));
                }

                return $this->applyWorkerSignal($row, $workerEventsByProvider[$provider] ?? null);
            })
            ->all();
    }

    private function modelPolicyPayload(AiProviderModelResolver $models, AtlasAiRuntimeSettings $settings): array
    {
        $runtime = $settings->effective();

        return [
            'source' => $runtime['source'] ?? 'config',
            'updated_at' => $runtime['updated_at'] ?? null,
            'default_tier' => (string) ($runtime['default_tier'] ?? 'daily'),
            'council_allow_auto' => (bool) ($runtime['council_allow_auto'] ?? false),
            'providers' => $this->providerKeys()
                ->map(fn (string $provider): array => $this->providerModelPolicy($provider, $models, $settings))
                ->values()
                ->all(),
        ];
    }

    private function providerModelPolicy(string $provider, AiProviderModelResolver $models, AtlasAiRuntimeSettings $settings): array
    {
        $resolution = $models->resolveWithSource($provider);
        $config = $settings->providerConfig($provider);

        return [
            'provider' => $provider,
            'model' => $resolution['model'],
            'model_label' => $resolution['model_label'] ?? $resolution['model'],
            'model_tier' => $resolution['model_tier'] ?? $settings->defaultTier(),
            'model_source' => $resolution['source'],
            'allow_auto' => (bool) ($resolution['allow_auto'] ?? true),
            'allow_manual' => (bool) ($resolution['allow_manual'] ?? true),
            'fallback_model' => $config['fallback_model'] ?? null,
            'fallback_model_label' => $config['fallback_model_label'] ?? ($config['fallback_model'] ?? null),
            'premium_model' => $config['premium_model'] ?? null,
            'premium_model_label' => $config['premium_model_label'] ?? ($config['premium_model'] ?? null),
        ];
    }

    private function queuePayload(): array
    {
        $byProvider = AiJob::query()
            ->select('provider')
            ->selectRaw("SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) AS queued")
            ->selectRaw("SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) AS processing")
            ->selectRaw("SUM(CASE WHEN status = 'awaiting_user_choice' THEN 1 ELSE 0 END) AS awaiting_user_choice")
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed")
            ->whereIn('status', ['queued', 'processing', 'awaiting_user_choice', 'failed'])
            ->groupBy('provider')
            ->orderBy('provider')
            ->get()
            ->map(fn (AiJob $row): array => [
                'provider' => $row->provider,
                'queued' => (int) $row->queued,
                'processing' => (int) $row->processing,
                'awaiting_user_choice' => (int) $row->awaiting_user_choice,
                'failed' => (int) $row->failed,
            ])
            ->values()
            ->all();

        return [
            'queued' => AiJob::query()->where('status', 'queued')->count(),
            'processing' => AiJob::query()->where('status', 'processing')->count(),
            'awaiting_user_choice' => AiJob::query()->where('status', 'awaiting_user_choice')->count(),
            'failed' => AiJob::query()->where('status', 'failed')->count(),
            'by_provider' => $byProvider,
        ];
    }

    private function workerRows(array $workerEventsByProvider): array
    {
        return $this->providerKeys()
            ->map(fn (string $provider): array => $this->workerRow($provider, $workerEventsByProvider[$provider] ?? null))
            ->values()
            ->all();
    }

    private function workerRow(string $provider, ?AiWorkerEvent $event): array
    {
        if (! $event?->occurred_at) {
            return [
                'provider' => $provider,
                'status' => 'unknown',
                'worker_id' => null,
                'event_type' => null,
                'severity' => null,
                'message' => 'Sem evento recente do worker local.',
                'occurred_at' => null,
                'age_seconds' => null,
            ];
        }

        $ageSeconds = (int) round($event->occurred_at->diffInSeconds(now(), true));

        return [
            'provider' => $provider,
            'status' => $this->workerStatus($event, $ageSeconds),
            'worker_id' => $event->worker_id,
            'event_type' => $event->event_type,
            'severity' => $event->severity,
            'message' => $event->message,
            'occurred_at' => $event->occurred_at->toJSON(),
            'age_seconds' => $ageSeconds,
        ];
    }

    private function usagePayload(int $hours): array
    {
        if (! Schema::hasTable('ai_trace_metric_summaries')) {
            return [
                'available' => false,
                'window_hours' => $hours,
                'since' => null,
                'by_provider' => [],
                'by_model' => [],
                'totals' => null,
            ];
        }

        $since = now()->subHours($hours);
        $rows = AiTraceMetricSummary::query()
            ->select('provider')
            ->selectRaw('COUNT(*) AS traces')
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_traces")
            ->selectRaw('SUM(COALESCE(prompt_tokens, 0)) AS prompt_tokens')
            ->selectRaw('SUM(COALESCE(completion_tokens, 0)) AS completion_tokens')
            ->selectRaw('SUM(COALESCE(total_tokens, 0)) AS total_tokens')
            ->selectRaw('SUM(COALESCE(estimated_tokens, 0)) AS estimated_tokens')
            ->selectRaw('SUM(COALESCE(total_tokens, estimated_tokens, 0)) AS visible_tokens')
            ->selectRaw('SUM(COALESCE(cost_microusd, 0)) AS cost_microusd')
            ->selectRaw("SUM(CASE WHEN cost_confidence = 'unknown' THEN 1 ELSE 0 END) AS unknown_cost_count")
            ->selectRaw('MAX(computed_at) AS last_computed_at')
            ->where('computed_at', '>=', $since)
            ->groupBy('provider')
            ->orderBy('provider')
            ->get()
            ->map(fn (AiTraceMetricSummary $row): array => $this->usageRow($row))
            ->values();
        $modelRows = AiTraceMetricSummary::query()
            ->select('provider', 'model')
            ->selectRaw('COUNT(*) AS traces')
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_traces")
            ->selectRaw('SUM(COALESCE(prompt_tokens, 0)) AS prompt_tokens')
            ->selectRaw('SUM(COALESCE(completion_tokens, 0)) AS completion_tokens')
            ->selectRaw('SUM(COALESCE(total_tokens, 0)) AS total_tokens')
            ->selectRaw('SUM(COALESCE(estimated_tokens, 0)) AS estimated_tokens')
            ->selectRaw('SUM(COALESCE(total_tokens, estimated_tokens, 0)) AS visible_tokens')
            ->selectRaw('SUM(COALESCE(cost_microusd, 0)) AS cost_microusd')
            ->selectRaw("SUM(CASE WHEN cost_confidence = 'unknown' THEN 1 ELSE 0 END) AS unknown_cost_count")
            ->selectRaw('MAX(computed_at) AS last_computed_at')
            ->where('computed_at', '>=', $since)
            ->groupBy('provider', 'model')
            ->orderBy('provider')
            ->orderBy('model')
            ->get()
            ->map(fn (AiTraceMetricSummary $row): array => $this->usageRow($row))
            ->values();

        return [
            'available' => true,
            'window_hours' => $hours,
            'since' => $since->toJSON(),
            'by_provider' => $rows->all(),
            'by_model' => $modelRows->all(),
            'totals' => $this->usageTotals($rows->all()),
        ];
    }

    private function activeJobs(): array
    {
        return AiJob::query()
            ->whereIn('status', ['queued', 'processing', 'awaiting_user_choice'])
            ->orderByDesc('updated_at')
            ->limit(12)
            ->get(['id', 'trace_id', 'provider', 'model', 'status', 'attempts', 'worker_id', 'payload', 'metadata', 'available_at', 'started_at', 'updated_at'])
            ->map(function (AiJob $job): array {
                $payload = is_array($job->payload) ? $job->payload : [];
                $metadata = is_array($job->metadata) ? $job->metadata : [];

                return [
                    'id' => $job->id,
                    'trace_id' => $job->trace_id,
                    'provider' => $job->provider,
                    'model' => $job->model,
                    'model_label' => data_get($metadata, 'model_label') ?: data_get($payload, 'model_label') ?: $job->model,
                    'model_tier' => data_get($metadata, 'model_tier') ?: data_get($payload, 'model_tier'),
                    'model_source' => data_get($metadata, 'model_identity_source') ?: data_get($payload, 'model_identity_source'),
                    'status' => $job->status,
                    'attempts' => $job->attempts,
                    'worker_id' => $job->worker_id,
                    'available_at' => $job->available_at?->toJSON(),
                    'started_at' => $job->started_at?->toJSON(),
                    'updated_at' => $job->updated_at?->toJSON(),
                ];
            })
            ->all();
    }

    private function providerKeys()
    {
        return collect(['claude_cli', 'codex_cli'])
            ->merge(array_keys((array) config('atlas.ai.providers', [])))
            ->unique()
            ->values();
    }

    private function workerStatus(AiWorkerEvent $event, int $ageSeconds): string
    {
        if ($event->event_type === 'worker_stopped') {
            return 'stopped';
        }

        if ($ageSeconds <= 120) {
            return 'running';
        }

        if ($ageSeconds <= 900) {
            return 'stale';
        }

        return 'unknown';
    }

    private function usageRow(AiTraceMetricSummary $row): array
    {
        $costMicrousd = (int) $row->cost_microusd;

        return [
            'provider' => $row->provider ?: 'unknown',
            'model' => $row->model ?? null,
            'traces' => (int) $row->traces,
            'failed_traces' => (int) $row->failed_traces,
            'prompt_tokens' => (int) $row->prompt_tokens,
            'completion_tokens' => (int) $row->completion_tokens,
            'total_tokens' => (int) $row->total_tokens,
            'estimated_tokens' => (int) $row->estimated_tokens,
            'visible_tokens' => (int) $row->visible_tokens,
            'cost_microusd' => $costMicrousd,
            'cost_usd_estimate' => round($costMicrousd / 1_000_000, 6),
            'unknown_cost_count' => (int) $row->unknown_cost_count,
            'last_computed_at' => $row->last_computed_at ? (string) $row->last_computed_at : null,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private function usageTotals(array $rows): array
    {
        $costMicrousd = (int) collect($rows)->sum('cost_microusd');

        return [
            'provider' => 'all',
            'model' => null,
            'traces' => (int) collect($rows)->sum('traces'),
            'failed_traces' => (int) collect($rows)->sum('failed_traces'),
            'prompt_tokens' => (int) collect($rows)->sum('prompt_tokens'),
            'completion_tokens' => (int) collect($rows)->sum('completion_tokens'),
            'total_tokens' => (int) collect($rows)->sum('total_tokens'),
            'estimated_tokens' => (int) collect($rows)->sum('estimated_tokens'),
            'visible_tokens' => (int) collect($rows)->sum('visible_tokens'),
            'cost_microusd' => $costMicrousd,
            'cost_usd_estimate' => round($costMicrousd / 1_000_000, 6),
            'unknown_cost_count' => (int) collect($rows)->sum('unknown_cost_count'),
            'last_computed_at' => collect($rows)->pluck('last_computed_at')->filter()->max(),
        ];
    }

    private function latestWorkerEventsByProvider(): array
    {
        $providers = array_keys((array) config('atlas.ai.providers', []));
        $eventsByProvider = [];

        AiWorkerEvent::query()
            ->where('occurred_at', '>=', now()->subHours(24))
            ->orderByDesc('occurred_at')
            ->limit(200)
            ->get()
            ->each(function (AiWorkerEvent $event) use (&$eventsByProvider, $providers): void {
                $provider = $event->provider ?: data_get($event->metadata, 'provider');
                if (! is_string($provider) || ! in_array($provider, $providers, true)) {
                    return;
                }

                $eventsByProvider[$provider] ??= $event;
            });

        return $eventsByProvider;
    }

    private function applyWorkerSignal(array $row, ?AiWorkerEvent $event): array
    {
        if (! $event?->occurred_at) {
            return $row;
        }

        $ageSeconds = (int) round($event->occurred_at->diffInSeconds(now(), true));
        $metadata = array_merge($row['metadata'] ?? [], [
            'worker_signal' => [
                'event_type' => $event->event_type,
                'severity' => $event->severity,
                'message' => $event->message,
                'worker_id' => $event->worker_id,
                'occurred_at' => $event->occurred_at->toJSON(),
                'age_seconds' => $ageSeconds,
            ],
        ]);

        if ($ageSeconds <= 120) {
            $row['status'] = 'online';
            $row['message'] = 'Worker local ativo no Mac.';
            $row['checked_at'] = $event->occurred_at->toJSON();
            $row['last_success_at'] = $event->occurred_at->toJSON();
            $row['metadata'] = array_merge($metadata, ['diagnostic_source' => 'worker_event']);

            return $row;
        }

        if ($ageSeconds <= 900 && $row['status'] !== 'online') {
            $row['status'] = 'degraded';
            $row['message'] = 'Worker local visto recentemente; toque em Atualizar AI se continuar parado.';
            $row['checked_at'] = $event->occurred_at->toJSON();
            $row['metadata'] = array_merge($metadata, ['diagnostic_source' => 'stale_worker_event']);

            return $row;
        }

        $row['metadata'] = $metadata;

        return $row;
    }
}
