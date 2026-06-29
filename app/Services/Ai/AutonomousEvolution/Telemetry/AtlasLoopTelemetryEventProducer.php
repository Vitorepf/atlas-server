<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Telemetry;

use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use Closure;
use Throwable;

/**
 * Produces recent task-lifecycle telemetry facts ({kind: claim|lease|serve, cycle_id, occurred_at_iso}) the
 * {@see AtlasLoopTelemetryStarvationDetector} consumes. The source is injectable (closure / repository object /
 * explicit array) so tests pin controlled events; the default reads claim/serve lifecycle from the serving store
 * via {@see AtlasTaskServingStack::queueRepo()} and is fail-open (a queue-read error yields []).
 */
final class AtlasLoopTelemetryEventProducer
{
    public function __construct(private readonly object|array|null $source = null) {}

    /**
     * @return list<array{kind:string, cycle_id:string, occurred_at_iso:string}>
     */
    public function events(int $windowMinutes): array
    {
        $events = [];
        foreach ($this->fromSource(max(0, $windowMinutes)) as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $kind = trim((string) ($entry['kind'] ?? ''));
            $cycleId = trim((string) ($entry['cycle_id'] ?? ''));
            $occurredAtIso = trim((string) ($entry['occurred_at_iso'] ?? ''));
            if ($kind === '' || $cycleId === '' || $occurredAtIso === '') {
                continue;
            }
            $events[] = ['kind' => $kind, 'cycle_id' => $cycleId, 'occurred_at_iso' => $occurredAtIso];
        }

        return $events;
    }

    /**
     * @return list<mixed>
     */
    private function fromSource(int $windowMinutes): array
    {
        if (is_array($this->source)) {
            return array_values($this->source);
        }
        if ($this->source instanceof Closure) {
            $result = ($this->source)($windowMinutes);

            return is_array($result) ? array_values($result) : [];
        }
        if (is_object($this->source)) {
            foreach (['events', 'recentEvents', 'read'] as $method) {
                if (method_exists($this->source, $method)) {
                    $result = $this->source->{$method}($windowMinutes);

                    return is_array($result) ? array_values($result) : [];
                }
            }

            return [];
        }

        return $this->fromServingStore();
    }

    /**
     * Best-effort projection of the serving store's claim/serve lifecycle into telemetry facts. Fail-open.
     *
     * @return list<array{kind:string, cycle_id:string, occurred_at_iso:string}>
     */
    private function fromServingStore(): array
    {
        try {
            $events = [];
            foreach (['claimable', 'claimed', 'queued', 'released', 'blocked', 'lease_expired'] as $status) {
                foreach (AtlasTaskServingStack::queueRepo()->list(['status' => $status]) as $row) {
                    $cycleId = (string) (data_get($row, 'task_packet_id') ?? data_get($row, 'task_packet.task_packet_id') ?? '');
                    if ($cycleId === '') {
                        continue;
                    }
                    $claimedAt = (string) (data_get($row, 'lease.acquired_at') ?? data_get($row, 'claimed_at') ?? '');
                    if ($claimedAt !== '') {
                        $events[] = ['kind' => 'claim', 'cycle_id' => $cycleId, 'occurred_at_iso' => $claimedAt];
                    }
                    $servedAt = (string) (data_get($row, 'served_at') ?? data_get($row, 'serve.occurred_at') ?? '');
                    if ($servedAt !== '') {
                        $events[] = ['kind' => 'serve', 'cycle_id' => $cycleId, 'occurred_at_iso' => $servedAt];
                    }
                }
            }

            return $events;
        } catch (Throwable) {
            return [];
        }
    }
}
