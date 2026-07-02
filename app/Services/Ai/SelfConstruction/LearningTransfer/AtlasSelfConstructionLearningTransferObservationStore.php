<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\LearningTransfer;

use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;

/**
 * Append-only observation accumulator for the lesson-admission circuit.
 *
 * The candidate gate demands >=3 INDEPENDENT sources, but each live bridge
 * call carries exactly one observation and admit() was stateless — the
 * threshold was unreachable by construction. This store persists every
 * incoming observation so admissions can aggregate real cross-packet history.
 * Recording a fact is not fabricating a signal: every row is a real bridge
 * outcome that already happened.
 *
 * Path is derived from the admission ledger path (`.jsonl` suffix swapped to
 * `.observations.jsonl`), so the phpunit env pin covers this store with zero
 * new config.
 */
final class AtlasSelfConstructionLearningTransferObservationStore
{
    public const SCHEMA = 'atlas.learning_transfer.observation.v1';

    public function __construct(private readonly string $path) {}

    public static function defaultPath(): string
    {
        $ledger = AtlasSelfConstructionLearningTransferAdmissionLedger::defaultPath();

        return (string) preg_replace('/\.jsonl$/', '.observations.jsonl', $ledger);
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Record one observation. Deduped on (lesson_key, task_packet_id, agent_id,
     * outcome): a replayed give_back for the same packet+agent never counts
     * twice. Retirement markers are rows with `retired_lesson_key`.
     *
     * @param  array{lesson_key:string, class:string, scope_dirs?:list<string>, task_packet_id:string, agent_id:string, outcome:string, evidence_refs?:list<string>, blocking_facts?:list<string>}  $observation
     * @return array{status:string}
     */
    public function record(array $observation): array
    {
        $lessonKey = trim((string) ($observation['lesson_key'] ?? ''));
        $packetId = trim((string) ($observation['task_packet_id'] ?? ''));
        $outcome = trim((string) ($observation['outcome'] ?? ''));
        if ($lessonKey === '' || $packetId === '' || $outcome === '') {
            return ['status' => 'skipped_incomplete_observation'];
        }

        $row = [
            'schema_version' => self::SCHEMA,
            'recorded_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'lesson_key' => $lessonKey,
            'class' => (string) ($observation['class'] ?? ''),
            'scope_dirs' => array_values(array_map('strval', (array) ($observation['scope_dirs'] ?? []))),
            'task_packet_id' => $packetId,
            'agent_id' => (string) ($observation['agent_id'] ?? ''),
            'outcome' => $outcome,
            'evidence_refs' => array_values(array_map('strval', (array) ($observation['evidence_refs'] ?? []))),
            'blocking_facts' => array_values(array_map('strval', (array) ($observation['blocking_facts'] ?? []))),
        ];
        ksort($row, SORT_STRING);

        $dedupeKey = $this->dedupeKeyOf($row);
        $written = (new JsonlReceiptStore($this->path))->appendWith(
            fn (?string $lastLine): ?array => $this->hasDedupeKey($dedupeKey) ? null : $row,
        );

        return ['status' => $written === null ? 'already_recorded' : 'recorded'];
    }

    /**
     * Accumulated live observations for a lesson key, oldest first, capped.
     * A retired lesson key always yields [] (its history never re-admits).
     *
     * @return list<array<string,mixed>>
     */
    public function observationsFor(string $lessonKey, int $maxAgeDays = 90, int $cap = 50): array
    {
        [$rows, $retiredKeys] = $this->liveRows($maxAgeDays);
        if (isset($retiredKeys[$lessonKey])) {
            return [];
        }

        $matching = array_values(array_filter(
            $rows,
            static fn (array $row): bool => (string) ($row['lesson_key'] ?? '') === $lessonKey,
        ));

        return array_slice($matching, -$cap);
    }

    /**
     * Give-back class histogram for observations whose scope_dirs intersect
     * the given dirs — the class-adoption input for classless success facts.
     *
     * @param  list<string>  $scopeDirs
     * @return array<string,int> class => live give_back observation count, desc
     */
    public function giveBackClassesForScope(array $scopeDirs, int $maxAgeDays = 90): array
    {
        $scopeDirs = array_values(array_filter(array_map('strval', $scopeDirs)));
        if ($scopeDirs === []) {
            return [];
        }

        [$rows] = $this->liveRows($maxAgeDays);
        $counts = [];
        foreach ($rows as $row) {
            if ((string) ($row['outcome'] ?? '') !== 'give_back') {
                continue;
            }
            $class = (string) ($row['class'] ?? '');
            if ($class === '' || $class === 'unknown') {
                continue;
            }
            $rowDirs = array_map('strval', (array) ($row['scope_dirs'] ?? []));
            if (array_intersect($scopeDirs, $rowDirs) === []) {
                continue;
            }
            $counts[$class] = ($counts[$class] ?? 0) + 1;
        }
        arsort($counts);

        return $counts;
    }

    /**
     * Recent outcome rows for a lesson class — feeds the orchestrator's
     * family-recurrence suppression signal ({outcome} rows).
     *
     * @return list<array{outcome:string}>
     */
    public function outcomesForClass(string $class, int $maxAgeDays = 90, int $cap = 50): array
    {
        if ($class === '') {
            return [];
        }

        [$rows] = $this->liveRows($maxAgeDays);
        $outcomes = [];
        foreach ($rows as $row) {
            if ((string) ($row['class'] ?? '') !== $class) {
                continue;
            }
            $outcomes[] = ['outcome' => (string) ($row['outcome'] ?? '')];
        }

        return array_slice($outcomes, -$cap);
    }

    /**
     * Retire a lesson key after its lesson is admitted to the ledger, so the
     * same accumulated history never re-admits or re-conflicts.
     *
     * @return array{status:string}
     */
    public function retire(string $lessonKey): array
    {
        $row = [
            'schema_version' => self::SCHEMA,
            'recorded_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'retired_lesson_key' => $lessonKey,
        ];
        (new JsonlReceiptStore($this->path))->appendWith(static fn (?string $lastLine): ?array => $row);

        return ['status' => 'retired'];
    }

    /**
     * @return array{0: list<array<string,mixed>>, 1: array<string,true>} [live observation rows oldest-first, retired key set]
     */
    private function liveRows(int $maxAgeDays): array
    {
        $cutoff = gmdate('Y-m-d\TH:i:s\Z', time() - ($maxAgeDays * 86400));
        $retired = [];
        $observations = [];
        foreach ($this->replay() as $row) {
            $retiredKey = (string) ($row['retired_lesson_key'] ?? '');
            if ($retiredKey !== '') {
                $retired[$retiredKey] = true;

                continue;
            }
            if ((string) ($row['recorded_at'] ?? '') < $cutoff) {
                continue;
            }
            $observations[] = $row;
        }

        // ponytail: full-file replay per read; index if this passes ~5k rows.
        $live = array_values(array_filter(
            $observations,
            static fn (array $row): bool => ! isset($retired[(string) ($row['lesson_key'] ?? '')]),
        ));

        return [$live, $retired];
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function dedupeKeyOf(array $row): string
    {
        return ((string) ($row['lesson_key'] ?? ''))
            .'|'.((string) ($row['task_packet_id'] ?? ''))
            .'|'.((string) ($row['agent_id'] ?? ''))
            .'|'.((string) ($row['outcome'] ?? ''));
    }

    private function hasDedupeKey(string $dedupeKey): bool
    {
        foreach ($this->replay() as $row) {
            if ($this->dedupeKeyOf($row) === $dedupeKey) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function replay(): array
    {
        try {
            return (new JsonlReceiptStore($this->path))->replay();
        } catch (\Throwable) {
            return [];
        }
    }
}
