<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsProviderArenaCorpusService;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use App\Services\Ai\Support\JsonFileStore;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Atlas Forge Rivals · Battery State Service (multi-case runner v1).
 *
 * Owns the battery-level catalogue file `runs/<run_id>/battery.json` and the
 * append-only `runs/<run_id>/battery.jsonl` stream. It is the single source
 * of truth for:
 *
 *   - which cases belong to the battery (case_count, cases[])
 *   - per-case canonical state {pending, running, completed, failed,
 *     invalid, skipped} with started_at / finished_at timestamps
 *   - per-case difficulty (corpus bucket) and difficulty_level (L1-L5
 *     canon ladder) — preserved so adjudicator/report can weight scoring
 *     and so resume preserves intent
 *   - per-case category / task_category / case_set
 *   - battery-level status {pending, running, paused, completed, blocked,
 *     stalled} and the aggregate verdict (worst-of)
 *   - resume safety: re-running the runner with the same run_id reads this
 *     file, advances only cases whose state is `pending`, and never
 *     overwrites cases that already reached a terminal state
 *
 * Schema: atlas.forge.rivals.battery.v1
 *
 * This service never invokes a provider, never decides a winner, never
 * unlocks external_rivals_certification. It is a state ledger.
 */
final class AtlasForgeRivalsBatteryStateService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.battery.v1';

    /** Per-case canonical states. */
    public const CASE_STATE_PENDING = 'pending';

    public const CASE_STATE_RUNNING = 'running';

    public const CASE_STATE_COMPLETED = 'completed';

    public const CASE_STATE_FAILED = 'failed';

    public const CASE_STATE_INVALID = 'invalid';

    public const CASE_STATE_SKIPPED = 'skipped';

    /** @var list<string> Canonical case states, advisory order from start to terminal. */
    public const CASE_STATES = [
        self::CASE_STATE_PENDING,
        self::CASE_STATE_RUNNING,
        self::CASE_STATE_COMPLETED,
        self::CASE_STATE_FAILED,
        self::CASE_STATE_INVALID,
        self::CASE_STATE_SKIPPED,
    ];

    /** @var list<string> Terminal states — a case in any of these never re-runs on resume. */
    public const CASE_STATES_TERMINAL = [
        self::CASE_STATE_COMPLETED,
        self::CASE_STATE_FAILED,
        self::CASE_STATE_INVALID,
        self::CASE_STATE_SKIPPED,
    ];

    /** Battery-level canonical states. */
    public const BATTERY_STATE_PENDING = 'pending';

    public const BATTERY_STATE_RUNNING = 'running';

    public const BATTERY_STATE_PAUSED = 'paused';

    public const BATTERY_STATE_COMPLETED = 'completed';

    public const BATTERY_STATE_BLOCKED = 'blocked';

    public const BATTERY_STATE_STALLED = 'stalled';

    /**
     * Translate a per-case verdict (produced by the runner) into the
     * canonical case state. Unknown/unhandled verdicts default to `failed`
     * so they remain visible without faking `completed`.
     */
    public static function verdictToState(string $verdict): string
    {
        return match (strtolower(trim($verdict))) {
            'comparable' => self::CASE_STATE_COMPLETED,
            'invalid_workspace_after_run', 'invalid_fixture_blocked' => self::CASE_STATE_INVALID,
            'invalid_scope_violation', 'invalid_tests_failed', 'invalid_no_patch_diff', 'invalid_provider_timeout', 'inconclusive' => self::CASE_STATE_FAILED,
            'skipped' => self::CASE_STATE_SKIPPED,
            default => self::CASE_STATE_FAILED,
        };
    }

    public function __construct(
        private readonly AtlasForgeRivalsRunPathResolver $paths,
    ) {}

    /**
     * Resolve the on-disk path to battery.json for a run_id.
     */
    public function batteryJsonPath(string $runId): string
    {
        $paths = $this->paths->paths($runId);

        return $paths['base'].'/battery.json';
    }

    /**
     * Resolve the on-disk path to the battery-level JSONL stream.
     */
    public function batteryJsonlPath(string $runId): string
    {
        $paths = $this->paths->paths($runId);

        return $paths['base'].'/battery.jsonl';
    }

    /**
     * Load the battery state for a run_id, or return null when no battery
     * has been initialised yet. Never throws on missing files — callers
     * decide whether a fresh init is appropriate.
     *
     * @return array<string,mixed>|null
     */
    public function load(string $runId): ?array
    {
        return JsonFileStore::readArray($this->batteryJsonPath($runId));
    }

    /**
     * Initialise battery.json for a fresh run. If the file already exists
     * (resume) we keep the existing payload and only refresh the
     * `updated_at` timestamp + battery_status — never overwrite per-case
     * state. Returns the resulting battery payload.
     *
     * @param  array<string,mixed>  $context  run-level metadata (preset, mode, models, case_set, …)
     * @param  list<array<string,mixed>>  $cases  adapted cases from RunRealService::resolveCaseContext
     * @return array<string,mixed>
     */
    public function initialize(string $runId, array $context, array $cases): array
    {
        $existing = $this->load($runId);
        $now = $this->nowIso();
        if ($existing !== null) {
            $existing['updated_at'] = $now;
            $existing['battery_status'] = self::BATTERY_STATE_RUNNING;
            $existing['resume_count'] = ((int) ($existing['resume_count'] ?? 0)) + 1;
            $this->persist($runId, $existing);
            $this->recordEvent($runId, 'battery_resumed', [
                'run_id' => $runId,
                'resume_count' => $existing['resume_count'],
                'pending_case_count' => count($this->pendingCases($existing)),
            ]);

            return $existing;
        }

        $caseEntries = array_values(array_map(
            static function (array $c, int $i): array {
                $level = (string) ($c['difficulty_level']
                    ?? AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_LEVEL_L3);
                $weight = (float) ($c['difficulty_weight']
                    ?? AtlasForgeRivalsProviderArenaCorpusService::difficultyLevelWeight($level));
                // The 8x5 matrix corpus declares dedicated planning/execution
                // weights plus a difficulty_score. We persist whatever the
                // adapter forwarded and fall back to difficulty_weight when
                // the corpus has not (yet) declared a specific value, so
                // the battery report always has a number to aggregate.
                $difficultyScore = is_numeric($c['difficulty_score'] ?? null)
                    ? (float) $c['difficulty_score']
                    : $weight;
                $planningWeight = is_numeric($c['planning_weight'] ?? null)
                    ? (float) $c['planning_weight']
                    : $weight;
                $executionWeight = is_numeric($c['execution_weight'] ?? null)
                    ? (float) $c['execution_weight']
                    : $weight;

                return [
                    'case_id' => (string) ($c['id'] ?? ''),
                    'case_index' => $i,
                    'case_source' => (string) ($c['case_source'] ?? 'legacy'),
                    'case_set' => $c['case_set'] ?? null,
                    'task_category' => $c['task_category'] ?? null,
                    'category' => $c['category'] ?? null,
                    'difficulty' => (string) ($c['difficulty'] ?? ''),
                    'difficulty_level' => $level,
                    'difficulty_weight' => $weight,
                    'difficulty_score' => $difficultyScore,
                    'planning_weight' => $planningWeight,
                    'execution_weight' => $executionWeight,
                    'state' => self::CASE_STATE_PENDING,
                    'verdict' => null,
                    'started_at' => null,
                    'finished_at' => null,
                    'attempts' => 0,
                    'evidence_dir' => 'cases/'.self::safeCaseDir((string) ($c['id'] ?? '')),
                    'last_blockers' => [],
                ];
            },
            $cases,
            array_keys($cases),
        ));

        $battery = [
            'schema_version' => self::SCHEMA_VERSION,
            'run_id' => $runId,
            'preset' => $context['preset'] ?? null,
            'case_set' => $context['case_set'] ?? null,
            'mode' => $context['mode'] ?? null,
            'atlas_model' => $context['atlas_model'] ?? null,
            'rival_model' => $context['rival_model'] ?? null,
            'started_at' => $now,
            'updated_at' => $now,
            'finished_at' => null,
            'resume_count' => 0,
            'case_count' => count($caseEntries),
            'cases' => $caseEntries,
            'battery_status' => self::BATTERY_STATE_RUNNING,
            'aggregate_verdict' => null,
            'claim_ready' => false,
            'external_provider_call' => (bool) ($context['external_provider_call'] ?? false),
            'provider_tokens_spent' => (bool) ($context['provider_tokens_spent'] ?? false),
            'separated_from_external_rivals_certification' => true,
        ];
        $this->persist($runId, $battery);
        $this->recordEvent($runId, 'battery_started', [
            'run_id' => $runId,
            'case_count' => $battery['case_count'],
            'preset' => $battery['preset'],
            'mode' => $battery['mode'],
            'atlas_model' => $battery['atlas_model'],
            'rival_model' => $battery['rival_model'],
            'difficulty_breakdown' => $this->difficultyBreakdown($battery['cases']),
            'category_breakdown' => $this->categoryBreakdown($battery['cases']),
        ]);

        return $battery;
    }

    /**
     * Mark a case as `running`. Increments attempts. Idempotent: if already
     * running, just refreshes started_at. Never overwrites a terminal state.
     *
     * @return array<string,mixed>|null  the updated battery, or null when no battery exists
     */
    public function markCaseRunning(string $runId, string $caseId): ?array
    {
        return $this->mutateCase($runId, $caseId, function (array $caseRow) use ($runId, $caseId): array {
            if (in_array($caseRow['state'] ?? '', self::CASE_STATES_TERMINAL, true)) {
                $this->recordEvent($runId, 'case_skipped_already_terminal', [
                    'case_id' => $caseId,
                    'state' => $caseRow['state'],
                ]);

                return $caseRow;
            }
            $caseRow['state'] = self::CASE_STATE_RUNNING;
            $caseRow['started_at'] = $this->nowIso();
            $caseRow['attempts'] = (int) ($caseRow['attempts'] ?? 0) + 1;
            $caseRow['last_blockers'] = [];
            $this->recordEvent($runId, 'case_state_changed', [
                'case_id' => $caseId,
                'state' => self::CASE_STATE_RUNNING,
                'attempts' => $caseRow['attempts'],
            ]);

            return $caseRow;
        });
    }

    /**
     * Mark a case as finished using the verdict produced by the runner.
     * Verdict is translated to the canonical state ladder via
     * verdictToState(). Receipt summary is stored as an evidence pointer
     * for resume / report consumption.
     *
     * @param  array<string,mixed>  $summary  optional per-case summary (verdict, exit_code etc.)
     * @return array<string,mixed>|null
     */
    public function markCaseFinished(string $runId, string $caseId, string $verdict, array $summary = []): ?array
    {
        return $this->mutateCase($runId, $caseId, function (array $caseRow) use ($runId, $caseId, $verdict, $summary): array {
            $state = self::verdictToState($verdict);
            $caseRow['state'] = $state;
            $caseRow['verdict'] = $verdict;
            $caseRow['finished_at'] = $this->nowIso();
            $caseRow['summary'] = $summary;
            $caseRow['last_blockers'] = (array) ($summary['blockers'] ?? []);
            $this->recordEvent($runId, 'case_finished', [
                'case_id' => $caseId,
                'state' => $state,
                'verdict' => $verdict,
                'difficulty_level' => $caseRow['difficulty_level'] ?? null,
                'task_category' => $caseRow['task_category'] ?? null,
            ]);

            return $caseRow;
        });
    }

    /**
     * Mark a case as explicitly skipped by the operator (resume context).
     * Skipped cases never re-run automatically.
     */
    public function markCaseSkipped(string $runId, string $caseId, string $reason): ?array
    {
        return $this->mutateCase($runId, $caseId, function (array $caseRow) use ($runId, $caseId, $reason): array {
            $caseRow['state'] = self::CASE_STATE_SKIPPED;
            $caseRow['verdict'] = 'skipped';
            $caseRow['finished_at'] = $this->nowIso();
            $caseRow['skip_reason'] = $reason;
            $caseRow['last_blockers'] = [$reason];
            $this->recordEvent($runId, 'case_skipped', [
                'case_id' => $caseId,
                'reason' => $reason,
            ]);

            return $caseRow;
        });
    }

    /**
     * Finalise the battery: refresh aggregate_verdict / claim_ready /
     * finished_at and battery_status. Called once the runner finishes the
     * full iteration (or fails fast at a fatal blocker).
     *
     * @param  array<string,mixed>  $aggregate
     * @return array<string,mixed>|null
     */
    public function finalize(string $runId, array $aggregate): ?array
    {
        $battery = $this->load($runId);
        if ($battery === null) {
            return null;
        }
        $now = $this->nowIso();
        $battery['updated_at'] = $now;
        $battery['finished_at'] = $now;
        $battery['aggregate_verdict'] = $aggregate['aggregate_verdict'] ?? null;
        $battery['claim_ready'] = (bool) ($aggregate['claim_ready'] ?? false);
        $battery['external_provider_call'] = (bool) ($aggregate['external_provider_call'] ?? false);
        $battery['provider_tokens_spent'] = (bool) ($aggregate['provider_tokens_spent'] ?? false);

        $pending = $this->pendingCases($battery);
        $statusKey = self::BATTERY_STATE_COMPLETED;
        if ($pending !== []) {
            $statusKey = self::BATTERY_STATE_PAUSED;
        }
        if (($aggregate['blocked'] ?? false) === true) {
            $statusKey = self::BATTERY_STATE_BLOCKED;
        }
        if (($aggregate['stalled'] ?? false) === true) {
            $statusKey = self::BATTERY_STATE_STALLED;
        }
        $battery['battery_status'] = $statusKey;
        $this->persist($runId, $battery);
        $this->recordEvent($runId, 'battery_finished', [
            'battery_status' => $statusKey,
            'aggregate_verdict' => $battery['aggregate_verdict'],
            'claim_ready' => $battery['claim_ready'],
            'pending_case_count' => count($pending),
        ]);

        return $battery;
    }

    /**
     * Append a battery-level event to battery.jsonl. Used by the runner to
     * surface coarse-grained progress (per-case, the existing events.jsonl
     * still carries the fine-grained stream). Caller passes a payload
     * dictionary that gets timestamped + wrapped.
     *
     * @param  array<string,mixed>  $payload
     */
    public function recordEvent(string $runId, string $kind, array $payload): void
    {
        $path = $this->batteryJsonlPath($runId);
        $this->ensureBaseDir($runId);
        $record = [
            'ts' => $this->nowIso(),
            'kind' => $kind,
            'payload' => $payload,
        ];
        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        AppendOnlyJsonlStore::appendEncodedLineSilently($path, $line, FILE_APPEND | LOCK_EX, 0o755);
    }

    /**
     * Return case_ids that are still pending (never executed, or
     * explicitly re-queued). Cases in any terminal state are excluded.
     *
     * @param  array<string,mixed>|null  $battery  optional pre-loaded payload
     * @return list<string>
     */
    public function pendingCases($battery): array
    {
        if (is_string($battery)) {
            $battery = $this->load($battery);
        }
        if (! is_array($battery)) {
            return [];
        }
        $out = [];
        foreach ((array) ($battery['cases'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $state = (string) ($row['state'] ?? self::CASE_STATE_PENDING);
            if ($state === self::CASE_STATE_PENDING || $state === self::CASE_STATE_RUNNING) {
                $out[] = (string) ($row['case_id'] ?? '');
            }
        }

        return array_values(array_filter($out, static fn (string $id): bool => $id !== ''));
    }

    /**
     * Return the first pending case_id (preserving declared order), or
     * null when the battery is fully resolved.
     */
    public function nextPendingCase(string $runId): ?string
    {
        $pending = $this->pendingCases($runId);

        return $pending[0] ?? null;
    }

    /**
     * Read-only snapshot of the battery state, augmented with derived
     * counters so the `status` CLI never needs to recompute.
     *
     * @return array<string,mixed>
     */
    public function snapshot(string $runId): array
    {
        $battery = $this->load($runId);
        if ($battery === null) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'run_id' => $runId,
                'exists' => false,
                'battery_status' => null,
                'case_count' => 0,
                'pending_case_count' => 0,
                'cases' => [],
            ];
        }

        $cases = (array) ($battery['cases'] ?? []);
        $stateCounts = array_fill_keys(self::CASE_STATES, 0);
        $difficultyCounts = [];
        $categoryCounts = [];
        foreach ($cases as $row) {
            if (! is_array($row)) {
                continue;
            }
            $state = (string) ($row['state'] ?? self::CASE_STATE_PENDING);
            $stateCounts[$state] = ($stateCounts[$state] ?? 0) + 1;
            $level = (string) ($row['difficulty_level'] ?? '');
            if ($level !== '') {
                $difficultyCounts[$level] = ($difficultyCounts[$level] ?? 0) + 1;
            }
            $category = (string) ($row['task_category'] ?? '');
            if ($category !== '') {
                $categoryCounts[$category] = ($categoryCounts[$category] ?? 0) + 1;
            }
        }

        $battery['exists'] = true;
        $battery['state_counts'] = $stateCounts;
        $battery['difficulty_counts'] = $difficultyCounts;
        $battery['category_counts'] = $categoryCounts;
        $battery['pending_case_count'] = $stateCounts[self::CASE_STATE_PENDING] + $stateCounts[self::CASE_STATE_RUNNING];
        $battery['terminal_case_count'] = $stateCounts[self::CASE_STATE_COMPLETED]
            + $stateCounts[self::CASE_STATE_FAILED]
            + $stateCounts[self::CASE_STATE_INVALID]
            + $stateCounts[self::CASE_STATE_SKIPPED];
        $battery['next_case_id'] = $this->nextPendingCase($runId);

        return $battery;
    }

    /**
     * Persist the battery payload. Called by initialize/mutate/finalize;
     * not part of the public mutation surface.
     *
     * @param  array<string,mixed>  $battery
     */
    private function persist(string $runId, array $battery): void
    {
        $this->ensureBaseDir($runId);
        JsonFileStore::writeAtomic(
            $this->batteryJsonPath($runId),
            $battery,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * Apply a mutation function to a specific case row, persist the
     * resulting battery, and return it. Returns null when battery is not
     * initialised — caller decides whether that is an error.
     *
     * @param  callable(array<string,mixed>):array<string,mixed>  $mutator
     * @return array<string,mixed>|null
     */
    private function mutateCase(string $runId, string $caseId, callable $mutator): ?array
    {
        $battery = $this->load($runId);
        if ($battery === null) {
            return null;
        }
        $cases = (array) ($battery['cases'] ?? []);
        $found = false;
        foreach ($cases as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            if ((string) ($row['case_id'] ?? '') !== $caseId) {
                continue;
            }
            $cases[$i] = $mutator($row);
            $found = true;
            break;
        }
        if (! $found) {
            $this->recordEvent($runId, 'case_not_in_battery', ['case_id' => $caseId]);

            return $battery;
        }
        $battery['cases'] = array_values($cases);
        $battery['updated_at'] = $this->nowIso();
        $this->persist($runId, $battery);

        return $battery;
    }

    private function ensureBaseDir(string $runId): void
    {
        $paths = $this->paths->paths($runId);
        if (! is_dir($paths['base'])) {
            @mkdir($paths['base'], 0o755, true);
        }
    }

    private function nowIso(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }

    /**
     * @param  list<array<string,mixed>>  $cases
     * @return array<string,int>
     */
    private function difficultyBreakdown(array $cases): array
    {
        $out = [];
        foreach ($cases as $row) {
            $level = (string) ($row['difficulty_level'] ?? '');
            if ($level === '') {
                continue;
            }
            $out[$level] = ($out[$level] ?? 0) + 1;
        }

        return $out;
    }

    /**
     * @param  list<array<string,mixed>>  $cases
     * @return array<string,int>
     */
    private function categoryBreakdown(array $cases): array
    {
        $out = [];
        foreach ($cases as $row) {
            $category = (string) ($row['task_category'] ?? '');
            if ($category === '') {
                continue;
            }
            $out[$category] = ($out[$category] ?? 0) + 1;
        }

        return $out;
    }

    /**
     * Sanitise a case id into a filesystem-safe directory name. Kept here
     * (mirrored from RunRealService) so battery.json paths can be computed
     * without depending on the runner.
     */
    public static function safeCaseDir(string $caseId): string
    {
        $trimmed = trim($caseId);
        if ($trimmed === '') {
            return 'case-unknown';
        }
        if (preg_match('/^[A-Za-z0-9_.\-]{1,96}$/', $trimmed) === 1) {
            return $trimmed;
        }

        return 'case-'.substr(hash('sha256', $trimmed), 0, 12);
    }
}
