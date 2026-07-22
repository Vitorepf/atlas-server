<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\LiveCycle\Integration;

/**
 * Operator-facing audit view of the live cycle. FACT-only, anti-Goodhart: NEVER recomputes from
 * source code — every datum traces back to a `cycle.*` event emitted by the orchestrator, the
 * receipt composer, or the resume manager.
 *
 * Expected FACT names (read-only consumer):
 *   - cycle.started       { cycle_id, started_at, ... }
 *   - cycle.phase.completed { cycle_id, phase_index, receipt_hash, prev_receipt_hash, sub_ledger_links }
 *   - cycle.receipt.composed { cycle_id, root_hash, phase_receipt_hashes, sub_ledger_links }
 *   - cycle.resume.planned { cycle_id, from_phase, verified_chain }
 *   - cycle.completed     { cycle_id, completed_at }
 *   - cycle.failed        { cycle_id, failed_at, reason }
 *
 * Provider-safe: surfaces only fact contents. NO internal traces/prompts/provider names.
 * Refuses to invent data — a cycle with zero FACTs returns `{empty: true, reason: 'no_facts_found'}`.
 */
final class AtlasLoopLiveCycleAuditTrail
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_RESUMED = 'resumed';

    /** @var object — duck-typed: must expose facts(string $pattern): list<array{name:string, payload:array}> */
    private object $factStore;

    public function __construct(object $factStore)
    {
        $this->factStore = $factStore;
    }

    /**
     * @param  array<string,mixed>  $filter optional: { status?: string, since?: int }
     * @return list<array<string,mixed>>
     */
    public function listCycles(array $filter = []): array
    {
        $byCycle = $this->groupFactsByCycle();
        $rows = [];
        foreach ($byCycle as $cycleId => $facts) {
            $row = $this->summariseCycle($cycleId, $facts);
            if (isset($filter['status']) && $row['status'] !== $filter['status']) {
                continue;
            }
            if (isset($filter['since']) && (int) $row['started_at'] < (int) $filter['since']) {
                continue;
            }
            $rows[] = $row;
        }
        usort($rows, static fn (array $a, array $b): int => (int) $a['started_at'] <=> (int) $b['started_at']);

        return $rows;
    }

    /**
     * @return array<string,mixed>
     */
    public function describe(string $cycleId): array
    {
        $facts = $this->factsForCycle($cycleId);
        if ($facts === []) {
            return [
                'cycle_id' => $cycleId,
                'empty' => true,
                'reason' => 'no_facts_found',
                'phases' => [],
            ];
        }

        $summary = $this->summariseCycle($cycleId, $facts);
        $phases = [];
        foreach ($facts as $f) {
            if (($f['name'] ?? '') !== 'cycle.phase.completed') {
                continue;
            }
            $p = (array) ($f['payload'] ?? []);
            $phases[] = [
                'phase_index' => (int) ($p['phase_index'] ?? 0),
                'receipt_hash' => (string) ($p['receipt_hash'] ?? ''),
                'prev_receipt_hash' => (string) ($p['prev_receipt_hash'] ?? ''),
                'sub_ledger_links' => (array) ($p['sub_ledger_links'] ?? []),
                'completed_at' => (int) ($p['completed_at'] ?? 0),
            ];
        }
        usort($phases, static fn (array $a, array $b): int => $a['phase_index'] <=> $b['phase_index']);

        $composed = $this->lastFact($facts, 'cycle.receipt.composed');
        $orderedHashes = array_map(static fn (array $p): string => $p['receipt_hash'], $phases);

        return [
            'cycle_id' => $cycleId,
            'empty' => false,
            'status' => $summary['status'],
            'started_at' => $summary['started_at'],
            'completed_at' => $summary['completed_at'],
            'root_hash' => $summary['root_hash'],
            'phase_count_completed' => count($phases),
            'phases' => $phases,
            'ordered_phase_receipt_hashes' => $orderedHashes,
            'sub_ledger_links' => is_array($composed['payload']['sub_ledger_links'] ?? null) ? $composed['payload']['sub_ledger_links'] : [],
        ];
    }

    /**
     * @return array<string, list<array<string,mixed>>>
     */
    private function groupFactsByCycle(): array
    {
        $facts = $this->fetchFacts();
        $grouped = [];
        foreach ($facts as $f) {
            $cycleId = (string) ($f['payload']['cycle_id'] ?? '');
            if ($cycleId === '') {
                continue;
            }
            $grouped[$cycleId] ??= [];
            $grouped[$cycleId][] = $f;
        }

        return $grouped;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function factsForCycle(string $cycleId): array
    {
        $facts = $this->fetchFacts();

        return array_values(array_filter(
            $facts,
            static fn (array $f): bool => (string) ($f['payload']['cycle_id'] ?? '') === $cycleId,
        ));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchFacts(): array
    {
        $raw = $this->factStore->facts('cycle.*');
        $out = [];
        foreach ((array) $raw as $f) {
            if (! is_array($f)) {
                continue;
            }
            $out[] = [
                'name' => (string) ($f['name'] ?? ''),
                'payload' => is_array($f['payload'] ?? null) ? $f['payload'] : [],
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string,mixed>>  $facts
     * @return array<string,mixed>
     */
    private function summariseCycle(string $cycleId, array $facts): array
    {
        $started = $this->lastFact($facts, 'cycle.started');
        $completed = $this->lastFact($facts, 'cycle.completed');
        $failed = $this->lastFact($facts, 'cycle.failed');
        $resumed = $this->lastFact($facts, 'cycle.resume.planned');
        $composed = $this->lastFact($facts, 'cycle.receipt.composed');
        $phaseCompletedCount = 0;
        foreach ($facts as $f) {
            if (($f['name'] ?? '') === 'cycle.phase.completed') {
                $phaseCompletedCount++;
            }
        }

        $status = self::STATUS_RUNNING;
        if ($failed !== null) {
            $status = self::STATUS_FAILED;
        } elseif ($completed !== null) {
            $status = $resumed !== null ? self::STATUS_RESUMED : self::STATUS_COMPLETED;
        }

        return [
            'cycle_id' => $cycleId,
            'started_at' => (int) ($started['payload']['started_at'] ?? 0),
            'completed_at' => (int) ($completed['payload']['completed_at'] ?? 0),
            'status' => $status,
            'root_hash' => (string) ($composed['payload']['root_hash'] ?? ''),
            'phase_count_completed' => $phaseCompletedCount,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $facts
     * @return array<string,mixed>|null
     */
    private function lastFact(array $facts, string $name): ?array
    {
        $found = null;
        foreach ($facts as $f) {
            if (($f['name'] ?? '') === $name) {
                $found = $f;
            }
        }

        return $found;
    }
}
