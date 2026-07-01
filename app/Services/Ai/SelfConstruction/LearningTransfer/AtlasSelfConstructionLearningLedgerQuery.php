<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\LearningTransfer;

/**
 * Read-only query interface over AtlasSelfConstructionLearningLedger. No in-memory cache —
 * every call re-reads the JSONL so the operator sees the latest durable state.
 */
final class AtlasSelfConstructionLearningLedgerQuery
{
    public function __construct(private readonly AtlasSelfConstructionLearningLedger $ledger) {}

    /**
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        return $this->ledger->all();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function byClass(string $class): array
    {
        return array_values(array_filter(
            $this->ledger->all(),
            static fn (array $row): bool => (string) ($row['lesson']['class'] ?? '') === $class,
        ));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function byDecision(string $decision): array
    {
        return array_values(array_filter(
            $this->ledger->all(),
            static fn (array $row): bool => (string) ($row['lesson']['decision'] ?? '') === $decision,
        ));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function byFamily(string $family): array
    {
        return array_values(array_filter(
            $this->ledger->all(),
            static fn (array $row): bool => (string) ($row['lesson']['family'] ?? '') === $family,
        ));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function bySourceProject(string $sourceProject): array
    {
        return array_values(array_filter(
            $this->ledger->all(),
            static fn (array $row): bool => (string) ($row['lesson']['source_project'] ?? '') === $sourceProject,
        ));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function byTargetProject(string $targetProject): array
    {
        return array_values(array_filter(
            $this->ledger->all(),
            static fn (array $row): bool => (string) ($row['lesson']['target_project'] ?? '') === $targetProject,
        ));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function byFailureMode(string $failureMode): array
    {
        return array_values(array_filter(
            $this->ledger->all(),
            static fn (array $row): bool => (string) ($row['lesson']['failure_mode'] ?? '') === $failureMode,
        ));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listChronological(): array
    {
        $rows = $this->ledger->all();
        usort($rows, static function (array $a, array $b): int {
            $ta = (string) ($a['lesson']['observation_ts'] ?? '');
            $tb = (string) ($b['lesson']['observation_ts'] ?? '');

            return strcmp($ta, $tb);
        });

        return array_values($rows);
    }

    /**
     * Deterministic combined filter across every sliceable dimension at once —
     * task family, provider/model class, failure mode, source/target project and
     * a [since, until) observation_ts window. Every filter is AND-ed; an omitted
     * filter matches everything.
     *
     * @param  array{task_family?:string, provider_class?:string, model?:string,
     *                failure_mode?:string, source_project?:string, target_project?:string,
     *                since?:string, until?:string}  $filters
     * @return list<array<string,mixed>>
     */
    public function search(array $filters): array
    {
        return array_values(array_filter(
            $this->ledger->all(),
            fn (array $row): bool => $this->matchesFilters((array) ($row['lesson'] ?? []), $filters),
        ));
    }

    /**
     * @param  array<string,mixed>  $lesson
     * @param  array<string,mixed>  $filters
     */
    private function matchesFilters(array $lesson, array $filters): bool
    {
        $fieldByFilter = [
            'task_family' => 'family',
            'provider_class' => 'provider_class',
            'model' => 'model',
            'failure_mode' => 'failure_mode',
            'source_project' => 'source_project',
            'target_project' => 'target_project',
        ];
        foreach ($fieldByFilter as $filterKey => $lessonField) {
            if (isset($filters[$filterKey]) && (string) ($lesson[$lessonField] ?? '') !== (string) $filters[$filterKey]) {
                return false;
            }
        }

        $observationTs = (string) ($lesson['observation_ts'] ?? '');
        if (isset($filters['since']) && strcmp($observationTs, (string) $filters['since']) < 0) {
            return false;
        }
        if (isset($filters['until']) && strcmp($observationTs, (string) $filters['until']) > 0) {
            return false;
        }

        return true;
    }

    /**
     * Counts, a bounded set of representative lessons and the latest evidence refs for a
     * filtered slice — never the raw ledger row (which could carry unbounded reasons/evidence
     * lists), always a curated projection safe to hand to a provider prompt.
     *
     * @param  array<string,mixed>  $filters
     * @return array{count:int, representative_lessons:list<array{lesson_id:string,class:string,decision:string,observation_ts:string}>, latest_evidence:list<string>}
     */
    public function summarize(array $filters = [], int $representativeLimit = 3): array
    {
        $rows = $this->search($filters);
        usort($rows, static fn (array $a, array $b): int => strcmp(
            (string) ($b['lesson']['observation_ts'] ?? ''),
            (string) ($a['lesson']['observation_ts'] ?? ''),
        ));

        $representative = array_map(
            static fn (array $r): array => [
                'lesson_id' => (string) ($r['lesson']['lesson_id'] ?? ''),
                'class' => (string) ($r['lesson']['class'] ?? ''),
                'decision' => (string) ($r['lesson']['decision'] ?? ''),
                'observation_ts' => (string) ($r['lesson']['observation_ts'] ?? ''),
            ],
            array_slice($rows, 0, max(0, $representativeLimit)),
        );

        $latestEvidence = $rows !== []
            ? array_values(array_map('strval', (array) ($rows[0]['lesson']['evidence_refs'] ?? [])))
            : [];

        return [
            'count' => count($rows),
            'representative_lessons' => $representative,
            'latest_evidence' => $latestEvidence,
        ];
    }

    /**
     * Lessons relevant to the NEXT task batch — matches lessons touching any of the batch's
     * families, target projects or failure modes, most-recent-first, capped at $limit. An empty
     * batch context returns nothing: this is never a whole-ledger dump.
     *
     * @param  array{task_families?:list<string>, target_projects?:list<string>, failure_modes?:list<string>}  $batchContext
     * @return list<array<string,mixed>>
     */
    public function relevantToNextBatch(array $batchContext, int $limit = 10): array
    {
        $families = array_map('strval', (array) ($batchContext['task_families'] ?? []));
        $targets = array_map('strval', (array) ($batchContext['target_projects'] ?? []));
        $failureModes = array_map('strval', (array) ($batchContext['failure_modes'] ?? []));

        if ($families === [] && $targets === [] && $failureModes === []) {
            return [];
        }

        $rows = array_values(array_filter(
            $this->ledger->all(),
            static function (array $row) use ($families, $targets, $failureModes): bool {
                $lesson = (array) ($row['lesson'] ?? []);

                return ($families !== [] && in_array((string) ($lesson['family'] ?? ''), $families, true))
                    || ($targets !== [] && in_array((string) ($lesson['target_project'] ?? ''), $targets, true))
                    || ($failureModes !== [] && in_array((string) ($lesson['failure_mode'] ?? ''), $failureModes, true));
            },
        ));

        usort($rows, static fn (array $a, array $b): int => strcmp(
            (string) ($b['lesson']['observation_ts'] ?? ''),
            (string) ($a['lesson']['observation_ts'] ?? ''),
        ));

        return array_slice($rows, 0, max(0, $limit));
    }
}
