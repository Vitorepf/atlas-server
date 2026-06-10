<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Compounding repair-learning substrate for the 24h area/focus loop.
 *
 * Every BLOCKED cycle is episode-isolated by default: the loop forgets *why* a
 * task class failed the moment the cycle ends, so it can re-select the same
 * class and walk into the same wall. This registry makes failure compound into
 * memory. It is an append-only JSONL ledger keyed by
 * (area, focus, task_class, blocker_signature) that records every blocked
 * occurrence and exposes a deterministic ranked recall: "task class T has hit
 * blocker B N times, most recently at TS". The loop reads this BEFORE the next
 * owner-flow cycle of the same class and carries the learned prior forward as a
 * repair hint, so the system self-repairs faster instead of rediscovering the
 * same failure from scratch.
 *
 * Pure + deterministic: no provider, no clock dependence beyond an injectable
 * "now" used only for last_seen. Recall ranking is a stable sort by
 * (occurrences desc, blocker asc) so the same ledger always yields the same
 * order. This service NEVER mutates findings, never decides merges, never
 * weakens a gate — it only records and recalls.
 */
final class RepairLearningRegistryService
{
    public const SCHEMA = 'atlas.software_company_stewardship.repair_learning_registry.v1';

    public const DEFAULT_TASK_CLASS = 'general';

    private ?string $storageRootOverride = null;

    private ?DateTimeImmutable $nowOverride = null;

    public function setStorageRootForTesting(?string $dir): void
    {
        $this->storageRootOverride = $dir;
    }

    public function setNowForTesting(?DateTimeImmutable $now): void
    {
        $this->nowOverride = $now;
    }

    public function storageDir(): string
    {
        if ($this->storageRootOverride !== null) {
            return $this->storageRootOverride;
        }

        return function_exists('storage_path')
            ? storage_path('atlas/software_company_stewardship/repair_learning_registry')
            : sys_get_temp_dir().'/atlas/software_company_stewardship/repair_learning_registry';
    }

    public function ledgerPath(string $areaId, string $focus): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.$this->key($areaId, $focus).'.jsonl';
    }

    /**
     * Record one blocked cycle. Each blocker becomes its own append-only row so
     * the recall aggregation is a pure fold over the ledger (no in-place edits,
     * fully crash-safe and replayable). Returns the rows written for audit.
     *
     * @param  list<string>  $blockers
     * @param  array<string,mixed>  $context
     * @return list<array<string,mixed>>
     */
    public function recordBlockedCycle(string $areaId, string $focus, string $taskClass, array $blockers, array $context = []): array
    {
        $taskClass = $this->normalizeTaskClass($taskClass);
        $now = $this->now()->format(DateTimeInterface::ATOM);
        $written = [];

        foreach (AreaFocusStringListNormalizer::trimmedUniqueStrings($blockers) as $blocker) {
            $entry = [
                'schema_version' => self::SCHEMA,
                'recorded_at' => $now,
                'area_id' => $areaId,
                'focus' => $focus,
                'task_class' => $taskClass,
                'blocker' => $blocker,
                'finding_id' => (string) ($context['finding_id'] ?? ''),
                'cycle_id' => (string) ($context['cycle_id'] ?? ''),
            ];
            $entry['entry_hash'] = 'sha256:'.MissionCanonicalHash::sha256($entry);
            $this->append($areaId, $focus, $entry);
            $written[] = $entry;
        }

        return $written;
    }

    /**
     * Deterministic recall for a task class. Folds the ledger into ranked
     * blocker stats so the loop can pre-load the most-frequent prior failures
     * for this class before the next attempt. Empty list when nothing learned.
     *
     * @return array{
     *   task_class: string,
     *   total_blocked_occurrences: int,
     *   blockers: list<array{blocker: string, occurrences: int, last_seen: string, last_finding_id: string}>
     * }
     */
    public function recallForTaskClass(string $areaId, string $focus, string $taskClass): array
    {
        $taskClass = $this->normalizeTaskClass($taskClass);
        $stats = [];
        $total = 0;

        foreach ($this->readEntries($areaId, $focus) as $entry) {
            if ((string) ($entry['task_class'] ?? '') !== $taskClass) {
                continue;
            }
            $blocker = (string) ($entry['blocker'] ?? '');
            if ($blocker === '') {
                continue;
            }
            $total++;
            $recordedAt = (string) ($entry['recorded_at'] ?? '');
            if (! isset($stats[$blocker])) {
                $stats[$blocker] = [
                    'blocker' => $blocker,
                    'occurrences' => 0,
                    'last_seen' => $recordedAt,
                    'last_finding_id' => (string) ($entry['finding_id'] ?? ''),
                ];
            }
            $stats[$blocker]['occurrences']++;
            if ($recordedAt >= (string) $stats[$blocker]['last_seen']) {
                $stats[$blocker]['last_seen'] = $recordedAt;
                $stats[$blocker]['last_finding_id'] = (string) ($entry['finding_id'] ?? '');
            }
        }

        $blockers = array_values($stats);
        usort($blockers, static function (array $a, array $b): int {
            return $b['occurrences'] <=> $a['occurrences']
                ?: strcmp((string) $a['blocker'], (string) $b['blocker']);
        });

        return [
            'task_class' => $taskClass,
            'total_blocked_occurrences' => $total,
            'blockers' => $blockers,
        ];
    }

    /**
     * Compact, finding-injectable repair hint derived from the recall. Returns
     * null when nothing has been learned for this class so callers can attach
     * it conditionally without polluting clean cycles.
     *
     * @return array{
     *   schema_version: string,
     *   task_class: string,
     *   prior_blocked_occurrences: int,
     *   all_prior_blockers: list<string>,
     *   top_prior_blockers: list<string>,
     *   detail: list<array{blocker: string, occurrences: int, last_seen: string, last_finding_id: string}>
     * }|null
     */
    public function repairHintForTaskClass(string $areaId, string $focus, string $taskClass, int $topN = 3): ?array
    {
        $recall = $this->recallForTaskClass($areaId, $focus, $taskClass);
        if ($recall['blockers'] === []) {
            return null;
        }

        $top = array_slice($recall['blockers'], 0, max(1, $topN));

        return [
            'schema_version' => self::SCHEMA,
            'task_class' => $recall['task_class'],
            'prior_blocked_occurrences' => $recall['total_blocked_occurrences'],
            'all_prior_blockers' => array_values(array_map(
                static fn (array $row): string => (string) $row['blocker'],
                $recall['blockers'],
            )),
            'top_prior_blockers' => array_values(array_map(
                static fn (array $row): string => (string) $row['blocker'],
                $top,
            )),
            'detail' => $top,
        ];
    }

    public function append(string $areaId, string $focus, array $entry): void
    {
        $path = $this->ledgerPath($areaId, $focus);
        AreaFocusAppendOnlyJsonlRecorder::append($path, $entry);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function readEntries(string $areaId, string $focus): array
    {
        return AreaFocusJsonlReader::rowsWithSchemaVersion($this->ledgerPath($areaId, $focus), self::SCHEMA);
    }

    public function normalizeTaskClass(string $taskClass): string
    {
        $taskClass = strtolower(trim($taskClass));

        return $taskClass !== '' ? $taskClass : self::DEFAULT_TASK_CLASS;
    }

    private function key(string $areaId, string $focus): string
    {
        return AreaFocusSlugNormalizer::lowerSnakeTokenPreservingBoundary($areaId, 'default')
            .'__'.AreaFocusSlugNormalizer::lowerSnakeTokenPreservingBoundary($focus, 'default');
    }

    private function now(): DateTimeImmutable
    {
        return $this->nowOverride ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
