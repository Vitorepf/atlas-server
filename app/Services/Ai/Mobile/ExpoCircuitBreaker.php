<?php

namespace App\Services\Ai\Mobile;

use App\Services\AuditLogService;
use Illuminate\Support\Facades\Cache;

class ExpoCircuitBreaker
{
    private const STATE_KEY = 'atlas:mobile:expo:circuit:state';
    private const FAILURES_KEY = 'atlas:mobile:expo:circuit:failures';

    public function __construct(private readonly AuditLogService $audit)
    {
    }

    public function isOpen(): bool
    {
        return $this->state()['state'] === 'open';
    }

    public function canAttempt(): bool
    {
        return ! $this->isOpen();
    }

    public function recordSuccess(): void
    {
        $previous = $this->stateRaw();

        Cache::forget(self::FAILURES_KEY);
        Cache::forget(self::STATE_KEY);

        if (($previous['state'] ?? 'closed') === 'open') {
            $this->audit->record('mobile.expo.circuit.closed', [
                'subject_type' => 'mobile_expo_circuit',
                'subject_id' => null,
                'actor_type' => 'system',
                'actor_id' => null,
                'severity' => 'info',
                'summary' => 'Expo circuit breaker fechado apos sucesso.',
                'evidence' => ['previous' => $previous],
                'privacy' => ['sensitivity' => 'private'],
            ]);
        }
    }

    public function recordFailure(): void
    {
        $threshold = max(1, (int) config('atlas.mobile.circuit.failure_threshold', 5));
        $windowSeconds = max(10, (int) config('atlas.mobile.circuit.failure_window_seconds', 60));
        $openSeconds = max(30, (int) config('atlas.mobile.circuit.open_seconds', 300));

        $count = $this->incrementFailures($windowSeconds);

        if ($count >= $threshold && ! $this->isOpen()) {
            Cache::put(self::STATE_KEY, [
                'state' => 'open',
                'opened_at' => now()->toJSON(),
                'opens_until' => now()->addSeconds($openSeconds)->toJSON(),
                'trigger_count' => $count,
            ], $openSeconds);

            $this->audit->record('mobile.expo.circuit.opened', [
                'subject_type' => 'mobile_expo_circuit',
                'subject_id' => null,
                'actor_type' => 'system',
                'actor_id' => null,
                'severity' => 'warning',
                'summary' => "Expo circuit breaker aberto apos {$count} falhas em {$windowSeconds}s.",
                'evidence' => [
                    'failure_count' => $count,
                    'threshold' => $threshold,
                    'window_seconds' => $windowSeconds,
                    'open_seconds' => $openSeconds,
                ],
                'privacy' => ['sensitivity' => 'private'],
            ]);
        }
    }

    /**
     * @return array{state:string,opened_at:?string,opens_until:?string,failure_count:int,threshold:int,window_seconds:int}
     */
    public function state(): array
    {
        $raw = $this->stateRaw();
        $threshold = max(1, (int) config('atlas.mobile.circuit.failure_threshold', 5));
        $windowSeconds = max(10, (int) config('atlas.mobile.circuit.failure_window_seconds', 60));

        return [
            'state' => $raw['state'] ?? 'closed',
            'opened_at' => $raw['opened_at'] ?? null,
            'opens_until' => $raw['opens_until'] ?? null,
            'failure_count' => (int) (Cache::get(self::FAILURES_KEY)['count'] ?? 0),
            'threshold' => $threshold,
            'window_seconds' => $windowSeconds,
        ];
    }

    public function reset(): void
    {
        Cache::forget(self::FAILURES_KEY);
        Cache::forget(self::STATE_KEY);
    }

    private function incrementFailures(int $windowSeconds): int
    {
        $now = time();
        $entry = Cache::get(self::FAILURES_KEY);

        if (! is_array($entry) || ! isset($entry['window_started_at']) || ($now - (int) $entry['window_started_at']) > $windowSeconds) {
            $entry = ['window_started_at' => $now, 'count' => 0];
        }

        $entry['count'] = ((int) ($entry['count'] ?? 0)) + 1;

        Cache::put(self::FAILURES_KEY, $entry, $windowSeconds);

        return (int) $entry['count'];
    }

    /**
     * @return array<string,mixed>
     */
    private function stateRaw(): array
    {
        $value = Cache::get(self::STATE_KEY);

        return is_array($value) ? $value : ['state' => 'closed'];
    }
}
