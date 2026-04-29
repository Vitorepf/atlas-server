<?php

namespace App\Http\Controllers;

use App\Http\Resources\AiProviderHealthResource;
use App\Http\Resources\AiWorkerEventResource;
use App\Models\AiJob;
use App\Models\AiProviderHealthSnapshot;
use App\Models\AiWorkerEvent;
use App\Services\Ai\AiProviderHealthService;
use Illuminate\Http\JsonResponse;

class AiProviderController extends Controller
{
    public function status(): JsonResponse
    {
        $latest = AiProviderHealthSnapshot::query()
            ->whereIn('id', function ($query): void {
                $query->selectRaw('DISTINCT ON (provider) id')
                    ->from('ai_provider_health_snapshots')
                    ->orderBy('provider')
                    ->orderByDesc('checked_at');
            })
            ->orderBy('provider')
            ->get();
        $recentEvents = AiWorkerEvent::query()->orderByDesc('occurred_at')->limit(10)->get();

        return response()->json([
            'queue' => [
                'queued' => AiJob::query()->where('status', 'queued')->count(),
                'processing' => AiJob::query()->where('status', 'processing')->count(),
                'failed' => AiJob::query()->where('status', 'failed')->count(),
            ],
            'providers' => $this->providerRows($latest),
            'recent_events' => AiWorkerEventResource::collection($recentEvents)->resolve(),
        ]);
    }

    public function check(AiProviderHealthService $health): JsonResponse
    {
        return response()->json([
            'providers' => AiProviderHealthResource::collection($health->checkAll())->resolve(),
        ]);
    }

    private function providerRows($latest): array
    {
        $rowsByProvider = collect(AiProviderHealthResource::collection($latest)->resolve())
            ->keyBy('provider');
        $workerEventsByProvider = $this->latestWorkerEventsByProvider();
        $providerKeys = collect(array_keys((array) config('atlas.ai.providers', [])))
            ->merge($rowsByProvider->keys())
            ->unique()
            ->values();

        return $providerKeys
            ->map(function (string $provider) use ($rowsByProvider, $workerEventsByProvider): array {
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

                return $this->applyWorkerSignal($row, $workerEventsByProvider[$provider] ?? null);
            })
            ->all();
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

        $ageSeconds = $event->occurred_at->diffInSeconds(now(), true);
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
