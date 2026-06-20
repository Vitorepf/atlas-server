<?php

namespace App\Services\Ai;

use App\Models\AiJob;
use App\Models\AiJobAttempt;
use App\Models\AiProviderHealthSnapshot;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Collection;

class AiProviderHealthService
{
    public function __construct(
        private readonly AiProviderManager $providers,
        private readonly AiWorkerLogger $logger,
    ) {}

    /**
     * @return Collection<int, AiProviderHealthSnapshot>
     */
    public function checkAll(): Collection
    {
        return collect($this->providers->keys())
            ->map(fn (string $provider): AiProviderHealthSnapshot => $this->check($provider));
    }

    public function check(string $provider): AiProviderHealthSnapshot
    {
        $health = $this->providers->get($provider)->health();
        $stats = $this->stats($provider);

        $snapshot = AiProviderHealthSnapshot::query()->create([
            'provider' => $provider,
            'status' => $health->status,
            'checked_at' => now(),
            'last_success_at' => $stats['last_success_at'],
            'last_failure_at' => $stats['last_failure_at'],
            'total_jobs_24h' => $stats['total_jobs_24h'],
            'failed_jobs_24h' => $stats['failed_jobs_24h'],
            'p50_latency_ms' => $stats['p50_latency_ms'],
            'operational_pain_score' => $this->painScore($health->status, $stats['total_jobs_24h'], $stats['failed_jobs_24h']),
            'message' => $health->message,
            'metadata' => $health->metadata,
            'created_at' => now(),
        ]);

        $this->logger->event(
            eventType: 'health_check',
            message: "Provider {$provider}: {$snapshot->status}.",
            severity: $snapshot->status === 'online' ? 'info' : 'warning',
            provider: $provider,
            metadata: ['message' => $health->message],
        );

        return $snapshot;
    }

    /**
     * @return Collection<int, AiProviderHealthSnapshot>
     */
    public function latest(): Collection
    {
        return collect($this->providers->keys())
            ->map(fn (string $provider): ?AiProviderHealthSnapshot => AiProviderHealthSnapshot::query()
                ->where('provider', $provider)
                ->orderByDesc('checked_at')
                ->first())
            ->filter()
            ->values();
    }

    private function stats(string $provider): array
    {
        $since = now()->subDay();
        $jobsAvailable = DatabaseTableAvailability::has('ai_jobs');
        $attemptsAvailable = DatabaseTableAvailability::has('ai_job_attempts');

        $attempts = $attemptsAvailable
            ? AiJobAttempt::query()
                ->where('provider', $provider)
                ->where('started_at', '>=', $since)
                ->get()
            : collect();

        $latencies = $attempts
            ->where('status', 'succeeded')
            ->pluck('duration_ms')
            ->filter()
            ->sort()
            ->values();

        $p50 = $latencies->isEmpty()
            ? null
            : (int) $latencies[(int) floor(($latencies->count() - 1) / 2)];

        return [
            'total_jobs_24h' => $jobsAvailable
                ? AiJob::query()
                    ->where('provider', $provider)
                    ->where('created_at', '>=', $since)
                    ->count()
                : 0,
            'failed_jobs_24h' => $jobsAvailable
                ? AiJob::query()
                    ->where('provider', $provider)
                    ->where('status', 'failed')
                    ->where('updated_at', '>=', $since)
                    ->count()
                : 0,
            'last_success_at' => $attemptsAvailable
                ? AiJobAttempt::query()
                    ->where('provider', $provider)
                    ->where('status', 'succeeded')
                    ->latest('finished_at')
                    ->value('finished_at')
                : null,
            'last_failure_at' => $attemptsAvailable
                ? AiJobAttempt::query()
                    ->where('provider', $provider)
                    ->whereIn('status', ['failed', 'timeout'])
                    ->latest('finished_at')
                    ->value('finished_at')
                : null,
            'p50_latency_ms' => $p50,
        ];
    }

    private function painScore(string $status, int $total, int $failed): int
    {
        if ($status === 'offline') {
            return 4;
        }
        if ($status === 'degraded') {
            return 3;
        }
        if ($total === 0) {
            return $status === 'online' ? 0 : 1;
        }

        $rate = $failed / max(1, $total);

        return match (true) {
            $rate >= 0.5 => 4,
            $rate >= 0.25 => 3,
            $rate >= 0.1 => 2,
            $rate > 0 => 1,
            default => 0,
        };
    }
}
