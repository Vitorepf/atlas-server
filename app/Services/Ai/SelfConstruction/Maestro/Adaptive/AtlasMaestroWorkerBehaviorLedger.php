<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Adaptive;

use Closure;
use Throwable;

final class AtlasMaestroWorkerBehaviorLedger
{
    public const SCHEMA = 'atlas.maestro.adaptive.worker_behavior_ledger.v1';

    private const EVENTS = ['served', 'success', 'give_back', 'lease_expired', 'gate_rejected'];

    private const RECENT_EVENTS_LIMIT = 20;

    /**
     * @param  Closure():int|null  $clock
     */
    public function __construct(
        private readonly ?string $path = null,
        private readonly ?Closure $clock = null,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function record(string $event, string $clientId, string $taskClass): array
    {
        if (! (bool) config('atlas.maestro.adaptive.behavior_ledger_enabled', false)) {
            return $this->emptyFacts($clientId, $taskClass);
        }

        $event = $this->normalizeEvent($event);
        $clientId = $this->normalizeKey($clientId);
        $taskClass = $this->normalizeKey($taskClass);
        $facts = $this->recallRaw($clientId, $taskClass);
        if ($event !== '') {
            $facts[$event]++;
        }
        $facts['last_seen_at'] = $this->now();
        $facts['last_event'] = $event;

        if ($event !== '') {
            $recent = $facts['recent_events'];
            $recent[] = ['event' => $event, 'at' => $facts['last_seen_at']];
            if (count($recent) > self::RECENT_EVENTS_LIMIT) {
                $recent = array_slice($recent, -self::RECENT_EVENTS_LIMIT);
            }
            $facts['recent_events'] = $recent;
        }

        $this->append($facts);

        return $this->withDerivedFacts($facts);
    }

    /**
     * @return array<string,mixed>
     */
    public function recall(string $clientId, string $taskClass): array
    {
        if (! (bool) config('atlas.maestro.adaptive.behavior_ledger_enabled', false)) {
            return $this->emptyFacts($clientId, $taskClass);
        }

        return $this->withDerivedFacts($this->recallRaw($clientId, $taskClass));
    }

    /**
     * Raw stored counters + recent_events, without the derived rate facts. Used internally so
     * record() can mutate counters before deriving rates from the post-increment state.
     *
     * @return array<string,mixed>
     */
    private function recallRaw(string $clientId, string $taskClass): array
    {
        $clientId = $this->normalizeKey($clientId);
        $taskClass = $this->normalizeKey($taskClass);
        $latest = null;
        foreach ($this->rows() as $row) {
            if (($row['client_id'] ?? null) === $clientId && ($row['task_class'] ?? null) === $taskClass) {
                $latest = $row;
            }
        }

        return $latest ?? $this->emptyRawFacts($clientId, $taskClass);
    }

    /**
     * R: reliability rates are plain FACTS derived by division over recorded event counts — not a
     * synthesized/weighted quality score. Each rate is independent and traceable back to its counter.
     *
     * @param  array<string,mixed>  $raw
     * @return array<string,mixed>
     */
    private function withDerivedFacts(array $raw): array
    {
        $totalEvents = 0;
        foreach (self::EVENTS as $candidate) {
            $totalEvents += (int) ($raw[$candidate] ?? 0);
        }

        $rate = static fn (int $count): float => $totalEvents > 0 ? round($count / $totalEvents, 4) : 0.0;

        $raw['total_events'] = $totalEvents;
        $raw['success_rate'] = $rate((int) ($raw['success'] ?? 0));
        $raw['give_back_rate'] = $rate((int) ($raw['give_back'] ?? 0));
        $raw['gate_rejected_rate'] = $rate((int) ($raw['gate_rejected'] ?? 0));
        $raw['recent_events'] = $raw['recent_events'] ?? [];

        return $raw;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function topGiveBackClasses(int $limit = 5): array
    {
        if (! (bool) config('atlas.maestro.adaptive.behavior_ledger_enabled', false)) {
            return [];
        }

        $latestByClass = [];
        foreach ($this->rows() as $row) {
            $taskClass = (string) ($row['task_class'] ?? '');
            if ($taskClass === '') {
                continue;
            }
            $latestByClass[$taskClass] = [
                'task_class' => $taskClass,
                'give_back' => (int) (($latestByClass[$taskClass]['give_back'] ?? 0) + (int) ($row['give_back_delta'] ?? 0)),
                'last_seen_at' => (int) ($row['last_seen_at'] ?? 0),
            ];
        }

        $items = array_values($latestByClass);
        usort($items, static fn (array $left, array $right): int => [-(int) $left['give_back'], (string) $left['task_class']] <=> [-(int) $right['give_back'], (string) $right['task_class']]);

        return array_slice($items, 0, max(1, $limit));
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyFacts(string $clientId, string $taskClass): array
    {
        return $this->withDerivedFacts($this->emptyRawFacts($clientId, $taskClass));
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyRawFacts(string $clientId, string $taskClass): array
    {
        return [
            'schema' => self::SCHEMA,
            'client_id' => $this->normalizeKey($clientId),
            'task_class' => $this->normalizeKey($taskClass),
            'served' => 0,
            'success' => 0,
            'give_back' => 0,
            'lease_expired' => 0,
            'gate_rejected' => 0,
            'last_seen_at' => 0,
            'last_event' => '',
            'recent_events' => [],
        ];
    }

    private function append(array $facts): void
    {
        $event = (string) ($facts['last_event'] ?? '');
        foreach (self::EVENTS as $candidate) {
            $facts[$candidate.'_delta'] = $event === $candidate ? 1 : 0;
        }

        $path = $this->ledgerPath();
        try {
            if (! is_dir(dirname($path))) {
                @mkdir(dirname($path), 0o775, true);
            }
            @file_put_contents($path, json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // best-effort FACT ledger; serving must not fail because telemetry storage failed.
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function rows(): array
    {
        $path = $this->ledgerPath();
        if (! is_file($path)) {
            return [];
        }

        $rows = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = json_decode($line, true);
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function normalizeEvent(string $event): string
    {
        $event = trim($event);

        return in_array($event, self::EVENTS, true) ? $event : '';
    }

    private function normalizeKey(string $value): string
    {
        return trim($value) !== '' ? trim($value) : 'unknown';
    }

    private function now(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : time();
    }

    private function ledgerPath(): string
    {
        return $this->path ?? storage_path('app/atlas/maestro/adaptive/worker-behavior.jsonl');
    }
}
