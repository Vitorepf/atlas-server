<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Adaptive;

use Closure;
use Throwable;

final class AtlasMaestroWorkerBehaviorLedger
{
    public const SCHEMA = 'atlas.maestro.adaptive.worker_behavior_ledger.v1';

    private const EVENTS = ['served', 'success', 'give_back', 'lease_expired', 'gate_rejected'];

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
        $facts = $this->recall($clientId, $taskClass);
        if ($event !== '') {
            $facts[$event]++;
        }
        $facts['last_seen_at'] = $this->now();
        $facts['last_event'] = $event;

        $this->append($facts);

        return $facts;
    }

    /**
     * @return array<string,mixed>
     */
    public function recall(string $clientId, string $taskClass): array
    {
        if (! (bool) config('atlas.maestro.adaptive.behavior_ledger_enabled', false)) {
            return $this->emptyFacts($clientId, $taskClass);
        }

        $clientId = $this->normalizeKey($clientId);
        $taskClass = $this->normalizeKey($taskClass);
        $latest = null;
        foreach ($this->rows() as $row) {
            if (($row['client_id'] ?? null) === $clientId && ($row['task_class'] ?? null) === $taskClass) {
                $latest = $row;
            }
        }

        return $latest ?? $this->emptyFacts($clientId, $taskClass);
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
