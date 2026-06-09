<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * AP-810 / LHL-15 — Self-Healing Maintenance Windows (AP-809).
 *
 * Periodic maintenance window the long-horizon loop runs BETWEEN cycles to keep
 * itself healthy enough to survive 24h / 3d / 7d unattended runs. It answers:
 *
 *   > What maintenance does this loop need right now, and is it safe to plan it?
 *
 * This service is read-only / deterministic / input-seam driven. It PLANS
 * maintenance — it NEVER executes destructive work. It NEVER invokes a provider,
 * NEVER runs the loop, NEVER merges, NEVER deletes a branch/worktree, NEVER
 * mutates code or ledgers. Every destructive task (clean_worktrees_and_branches,
 * compact_ledger, archive_evidence) is emitted as `action=plan` only; actual
 * deletion is a separate, operator-gated execution phase.
 *
 * Honesty rules (operator does not accept false claims):
 *   - destructive tasks are PLAN-only here — a plan is NEVER dressed as `done`;
 *   - evidence MUST be preserved (evidence_preserved=true) BEFORE any cleanup task
 *     is allowed to plan; if evidence cannot be preserved the cleanup tasks are
 *     held back and the window is `blocked`;
 *   - `ok` ONLY when the window planned cleanly with evidence preserved and no
 *     blockers; otherwise `blocked` — never a synthetic success.
 *
 * Profiles select which tasks run:
 *   - lightweight : fast hygiene subset (verify locks, refresh provider health,
 *                   recalculate backlog depth, emit operator summary).
 *   - full        : lightweight + compact ledger, archive evidence, clean
 *                   worktrees/branches, replay sample cycles. Gates 24h/3d promotion.
 *   - deep        : full + mini chaos suite. Gates 7d promotion.
 *
 * Contract: AP-809; AP-810 build contract slice LHL-15.
 * Reference shape: LoopPreflightCycleFirewallService (report shape + seams).
 */
final class SelfHealingMaintenanceWindowService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.loop_maintenance_window.v1';

    public const STATUS_OK = 'ok';

    public const STATUS_BLOCKED = 'blocked';

    /** Maintenance profiles. */
    public const PROFILE_LIGHTWEIGHT = 'lightweight';

    public const PROFILE_FULL = 'full';

    public const PROFILE_DEEP = 'deep';

    /** Task action taxonomy. A destructive plan is NEVER `done` here. */
    public const ACTION_PLAN = 'plan';

    public const ACTION_DONE = 'done';

    public const ACTION_SKIPPED = 'skipped';

    /** Canonical task ids (stable order — drives the deterministic hash). */
    public const TASK_VERIFY_LOCKS = 'verify_locks';

    public const TASK_REFRESH_PROVIDER_HEALTH = 'refresh_provider_health';

    public const TASK_RECALCULATE_BACKLOG_DEPTH = 'recalculate_backlog_depth';

    public const TASK_ARCHIVE_EVIDENCE = 'archive_evidence';

    public const TASK_COMPACT_LEDGER = 'compact_ledger';

    public const TASK_CLEAN_WORKTREES_AND_BRANCHES = 'clean_worktrees_and_branches';

    public const TASK_REPLAY_SAMPLE_CYCLES = 'replay_sample_cycles';

    public const TASK_RUN_MINI_CHAOS_SUITE = 'run_mini_chaos_suite';

    public const TASK_EMIT_OPERATOR_SUMMARY = 'emit_operator_summary';

    /**
     * Tasks that touch / remove artifacts. These may ONLY be planned (never `done`)
     * and require evidence to be preserved first.
     */
    private const DESTRUCTIVE_TASKS = [
        self::TASK_ARCHIVE_EVIDENCE,
        self::TASK_COMPACT_LEDGER,
        self::TASK_CLEAN_WORKTREES_AND_BRANCHES,
    ];

    /**
     * Read-only / non-destructive diagnostic tasks. These may report `done` because
     * they only inspect state — they never mutate anything.
     */
    private const READ_ONLY_TASKS = [
        self::TASK_VERIFY_LOCKS,
        self::TASK_REFRESH_PROVIDER_HEALTH,
        self::TASK_RECALCULATE_BACKLOG_DEPTH,
        self::TASK_REPLAY_SAMPLE_CYCLES,
        self::TASK_RUN_MINI_CHAOS_SUITE,
        self::TASK_EMIT_OPERATOR_SUMMARY,
    ];

    /**
     * Which canonical tasks each profile runs (in stable order). Tasks not listed
     * for a profile are reported as `skipped`.
     */
    private const PROFILE_TASKS = [
        self::PROFILE_LIGHTWEIGHT => [
            self::TASK_VERIFY_LOCKS,
            self::TASK_REFRESH_PROVIDER_HEALTH,
            self::TASK_RECALCULATE_BACKLOG_DEPTH,
            self::TASK_EMIT_OPERATOR_SUMMARY,
        ],
        self::PROFILE_FULL => [
            self::TASK_VERIFY_LOCKS,
            self::TASK_REFRESH_PROVIDER_HEALTH,
            self::TASK_RECALCULATE_BACKLOG_DEPTH,
            self::TASK_ARCHIVE_EVIDENCE,
            self::TASK_COMPACT_LEDGER,
            self::TASK_CLEAN_WORKTREES_AND_BRANCHES,
            self::TASK_REPLAY_SAMPLE_CYCLES,
            self::TASK_EMIT_OPERATOR_SUMMARY,
        ],
        self::PROFILE_DEEP => [
            self::TASK_VERIFY_LOCKS,
            self::TASK_REFRESH_PROVIDER_HEALTH,
            self::TASK_RECALCULATE_BACKLOG_DEPTH,
            self::TASK_ARCHIVE_EVIDENCE,
            self::TASK_COMPACT_LEDGER,
            self::TASK_CLEAN_WORKTREES_AND_BRANCHES,
            self::TASK_REPLAY_SAMPLE_CYCLES,
            self::TASK_RUN_MINI_CHAOS_SUITE,
            self::TASK_EMIT_OPERATOR_SUMMARY,
        ],
    ];

    /** Full/deep windows gate progressively longer unattended promotion horizons. */
    private const PROFILE_PROMOTION_GATE = [
        self::PROFILE_LIGHTWEIGHT => null,
        self::PROFILE_FULL => '3d',
        self::PROFILE_DEEP => '7d',
    ];

    /** Stable order over EVERY canonical task — drives the report table + hash. */
    private const ALL_TASKS_ORDER = [
        self::TASK_VERIFY_LOCKS,
        self::TASK_REFRESH_PROVIDER_HEALTH,
        self::TASK_RECALCULATE_BACKLOG_DEPTH,
        self::TASK_ARCHIVE_EVIDENCE,
        self::TASK_COMPACT_LEDGER,
        self::TASK_CLEAN_WORKTREES_AND_BRANCHES,
        self::TASK_REPLAY_SAMPLE_CYCLES,
        self::TASK_RUN_MINI_CHAOS_SUITE,
        self::TASK_EMIT_OPERATOR_SUMMARY,
    ];

    /**
     * Single entrypoint. Every key is optional; the diagnostic default plans a
     * lightweight window against a clean/empty state and never crashes.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function run(array $input = []): array
    {
        // A wiring-phase `fixture` (from --fixture-file) may carry a whole window
        // request; merge it under the explicit input so direct keys still win.
        $input = $this->mergeFixture($input);

        $area = trim((string) ($input['area'] ?? 'agentic_engineering_os')) ?: 'agentic_engineering_os';
        $focus = trim((string) ($input['focus'] ?? 'dev_forge')) ?: 'dev_forge';
        $windowId = trim((string) ($input['window_id'] ?? ($input['run_id'] ?? '')));
        $profile = $this->normalizeProfile((string) ($input['profile'] ?? self::PROFILE_LIGHTWEIGHT));

        $blockers = [];
        $warnings = [];

        // HARD INVARIANT: evidence MUST be preserved before ANY cleanup task is
        // allowed to plan. The seam reports whether the evidence archive is intact.
        // Default true (clean state) — a fixture may set it false to force a hold.
        $evidencePreserved = array_key_exists('evidence_preserved', $input)
            ? (bool) $input['evidence_preserved']
            : true;
        if (! $evidencePreserved) {
            $warnings[] = 'evidence_not_preserved_cleanup_held';
        }

        $selected = self::PROFILE_TASKS[$profile];

        /** @var list<array<string,mixed>> $tasks */
        $tasks = [];
        foreach (self::ALL_TASKS_ORDER as $taskId) {
            $tasks[] = $this->planTask($taskId, $selected, $evidencePreserved, $input, $blockers, $warnings);
        }

        $status = $blockers === [] ? self::STATUS_OK : self::STATUS_BLOCKED;

        $operatorSummary = $this->buildOperatorSummary($profile, $tasks, $evidencePreserved, $status, $blockers, $warnings);

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-809',
            'slice_id' => 'LHL-15',
            'status' => $status,
            'window_id' => 'shmw_'.substr(MissionCanonicalHash::sha256([
                $area,
                $focus,
                $windowId,
                $profile,
            ]), 0, 16),
            'area' => $area,
            'focus' => $focus,
            'checked_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'profile' => $profile,
            'promotion_gate' => self::PROFILE_PROMOTION_GATE[$profile],
            'evidence_preserved' => $evidencePreserved,
            'tasks' => $tasks,
            'operator_summary' => $operatorSummary,
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
            'next_action' => $status === self::STATUS_OK ? 'execute_planned_window' : 'preserve_evidence_then_replan',
            'claim_policy' => [
                'read_only' => true,
                'runs_provider' => false,
                'runs_loop' => false,
                'runs_merge' => false,
                'deletes_branches' => false,
                'destructive_tasks_plan_only' => true,
                'blocked_never_dressed_as_ready' => true,
            ],
        ];

        $payload['report_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->withoutVolatile($payload));

        return $payload;
    }

    // ---------------------------------------------------------------- task planning

    /**
     * Plan a single canonical task for this profile.
     *
     * - A task not selected for the profile => action `skipped`.
     * - A DESTRUCTIVE task is ALWAYS `plan` (never `done`) AND requires evidence
     *   preserved first; if evidence is not preserved the task is held back as
     *   `skipped` and a hard blocker is raised so the window cannot claim `ok`.
     * - A READ-ONLY diagnostic task reports `done` (it only inspects state).
     *
     * @param  list<string>  $selected
     * @param  array<string,mixed>  $input
     * @param  list<string>  $blockers
     * @param  list<string>  $warnings
     * @return array<string,mixed>
     */
    private function planTask(string $taskId, array $selected, bool $evidencePreserved, array $input, array &$blockers, array &$warnings): array
    {
        $destructive = in_array($taskId, self::DESTRUCTIVE_TASKS, true);

        if (! in_array($taskId, $selected, true)) {
            return [
                'task' => $taskId,
                'action' => self::ACTION_SKIPPED,
                'destructive' => $destructive,
                'evidence_preserved' => $evidencePreserved,
                'reason' => 'not_in_profile',
            ];
        }

        if ($destructive) {
            // Evidence is the precondition for ANY cleanup. Without it the task is
            // held back (skipped) and the whole window is blocked — never a success.
            if (! $evidencePreserved) {
                $blockers[] = 'cleanup_task_held_evidence_not_preserved:'.$taskId;

                return [
                    'task' => $taskId,
                    'action' => self::ACTION_SKIPPED,
                    'destructive' => true,
                    'evidence_preserved' => false,
                    'reason' => 'evidence_not_preserved',
                ];
            }

            // Destructive work is PLAN-only here. We surface the planned candidates
            // (from the seam) but NEVER delete; execution is a separate gated phase.
            return [
                'task' => $taskId,
                'action' => self::ACTION_PLAN,
                'destructive' => true,
                'evidence_preserved' => true,
                'planned_targets' => $this->plannedTargetsFor($taskId, $input),
                'reason' => 'destructive_plan_only',
            ];
        }

        // Read-only diagnostic — inspect the seam and report `done` honestly.
        return [
            'task' => $taskId,
            'action' => self::ACTION_DONE,
            'destructive' => false,
            'evidence_preserved' => $evidencePreserved,
            'observation' => $this->observationFor($taskId, $input, $warnings),
            'reason' => 'read_only_diagnostic',
        ];
    }

    /**
     * Planned (NOT executed) destructive targets for a cleanup task, taken from the
     * input seam. Empty when the seam is absent — we never invent deletions.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function plannedTargetsFor(string $taskId, array $input): array
    {
        return match ($taskId) {
            self::TASK_ARCHIVE_EVIDENCE => [
                'evidence_packs_to_archive' => AreaFocusStringListNormalizer::preserveStrings($input['evidence_packs_to_archive'] ?? []),
            ],
            self::TASK_COMPACT_LEDGER => [
                'ledger_lines' => max(0, (int) ($input['ledger_line_count'] ?? 0)),
                'compact_after_lines' => max(0, (int) ($input['ledger_compact_after_lines'] ?? 0)),
            ],
            self::TASK_CLEAN_WORKTREES_AND_BRANCHES => [
                'stale_worktrees' => AreaFocusStringListNormalizer::preserveStrings($input['stale_worktrees'] ?? []),
                'merged_branches' => AreaFocusStringListNormalizer::preserveStrings($input['merged_branches'] ?? []),
            ],
            default => [],
        };
    }

    /**
     * Read-only observation for a diagnostic task, taken from the input seam.
     *
     * @param  array<string,mixed>  $input
     * @param  list<string>  $warnings
     * @return array<string,mixed>
     */
    private function observationFor(string $taskId, array $input, array &$warnings): array
    {
        return match ($taskId) {
            self::TASK_VERIFY_LOCKS => $this->observeLocks($input, $warnings),
            self::TASK_REFRESH_PROVIDER_HEALTH => [
                'provider_health' => trim((string) ($input['provider_health'] ?? 'unknown')) ?: 'unknown',
            ],
            self::TASK_RECALCULATE_BACKLOG_DEPTH => [
                'backlog_depth' => max(0, (int) ($input['backlog_depth'] ?? 0)),
            ],
            self::TASK_REPLAY_SAMPLE_CYCLES => [
                'sample_cycles' => max(0, (int) ($input['replay_sample_cycles'] ?? 0)),
                'replay_consistent' => array_key_exists('replay_consistent', $input)
                    ? (bool) $input['replay_consistent']
                    : true,
            ],
            self::TASK_RUN_MINI_CHAOS_SUITE => [
                'chaos_scenarios' => max(0, (int) ($input['chaos_scenarios'] ?? 0)),
                'chaos_survived' => array_key_exists('chaos_survived', $input)
                    ? (bool) $input['chaos_survived']
                    : true,
            ],
            self::TASK_EMIT_OPERATOR_SUMMARY => [
                'channel' => 'morning_inbox',
            ],
            default => [],
        };
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  list<string>  $warnings
     * @return array<string,mixed>
     */
    private function observeLocks(array $input, array &$warnings): array
    {
        $stale = (bool) ($input['stale_lock'] ?? false);
        $held = (bool) ($input['loop_lock_held'] ?? false);
        if ($stale) {
            $warnings[] = 'stale_loop_lock_observed';
        }

        return [
            'stale_lock' => $stale,
            'loop_lock_held' => $held,
        ];
    }

    /**
     * Plain operator-facing summary of what the window planned.
     *
     * @param  list<array<string,mixed>>  $tasks
     * @param  list<string>  $blockers
     * @param  list<string>  $warnings
     */
    private function buildOperatorSummary(string $profile, array $tasks, bool $evidencePreserved, string $status, array $blockers, array $warnings): string
    {
        $planned = 0;
        $done = 0;
        $skipped = 0;
        foreach ($tasks as $task) {
            $action = (string) ($task['action'] ?? '');
            if ($action === self::ACTION_PLAN) {
                $planned++;
            } elseif ($action === self::ACTION_DONE) {
                $done++;
            } elseif ($action === self::ACTION_SKIPPED) {
                $skipped++;
            }
        }

        $head = $status === self::STATUS_OK
            ? "Maintenance window ({$profile}) planned cleanly"
            : "Maintenance window ({$profile}) BLOCKED";

        $evidenceNote = $evidencePreserved
            ? 'evidence preserved before cleanup'
            : 'evidence NOT preserved — cleanup held';

        return sprintf(
            '%s: %d diagnostics done, %d destructive tasks planned (not executed), %d skipped; %s; %d blocker(s), %d warning(s).',
            $head,
            $done,
            $planned,
            $skipped,
            $evidenceNote,
            count(array_unique($blockers)),
            count(array_unique($warnings)),
        );
    }

    // ---------------------------------------------------------------- helpers

    private function normalizeProfile(string $value): string
    {
        $value = strtolower(trim($value));

        return in_array($value, [self::PROFILE_LIGHTWEIGHT, self::PROFILE_FULL, self::PROFILE_DEEP], true)
            ? $value
            : self::PROFILE_LIGHTWEIGHT;
    }

    /**
     * A wiring-phase `fixture` may be a single window request; fold it under the
     * explicit input so direct keys still take precedence (input-seam composition).
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function mergeFixture(array $input): array
    {
        $fixture = $input['fixture'] ?? null;
        if (! is_array($fixture) || $fixture === []) {
            return $input;
        }
        unset($input['fixture']);

        return array_merge($fixture, $input);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function withoutVolatile(array $payload): array
    {
        unset($payload['checked_at'], $payload['report_hash']);

        return $payload;
    }
}
