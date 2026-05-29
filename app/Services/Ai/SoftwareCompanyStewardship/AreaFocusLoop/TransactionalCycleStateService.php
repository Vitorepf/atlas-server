<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * AP-810 / LHL-07 — Transactional Cycle State (AP-808/809).
 *
 * A durable, append-only state machine for ONE long-horizon loop cycle. It is the
 * crash-safe spine the loop uses to know exactly where a cycle is, what the only
 * safe place to resume from is, and which next state advances are legal.
 *
 * It answers exactly three questions, all read-only / deterministic / input-seam
 * driven (the only side effect is appending a local JSONL row):
 *
 *   - `transition()` — append-only advance to the next state. An illegal skip (e.g.
 *     planned->validated, or skipping `preflighted` which AP-807 forbids) and an
 *     ambiguous/unknown current state both FAIL CLOSED (no advance, no append).
 *   - `load()` — the current durable state for a (run_id, cycle_index), derived
 *     purely from the append-only ledger (last legal row wins).
 *   - `resumePoint()` — the ONLY safe state to resume from: the last durably
 *     recorded state. A crash mid-`executing` resumes from `executing`, never from
 *     a state that was never durably written.
 *
 * Legal order (no skips):
 *   planned -> preflighted -> executing -> validated -> judged
 *           -> merged_or_blocked -> audited -> cleaned
 *
 * Honesty rules (operator does not accept false claims):
 *   - This service NEVER invokes a provider, NEVER runs the loop, NEVER merges,
 *     NEVER deletes a branch/worktree, NEVER mutates code. The sole side effect is
 *     appending the cycle-state JSONL under storage/.
 *   - `merged_or_blocked` is a STATE, not a success: a blocked cycle reaches it the
 *     same way a merged one does. Only `cleaned` is the terminal completion state,
 *     and a `merged_or_blocked` outcome of `blocked` is reported as `blocked`,
 *     never dressed as merged.
 *   - An illegal transition or ambiguous current state is fail_closed — the loop
 *     must stop, never silently "advance" past a state it cannot prove.
 *
 * Contract: AP-808/AP-809; AP-810 build contract slice LHL-07.
 * Reference shape: AreaFocusCycleRecorderService (append-only JSONL).
 */
final class TransactionalCycleStateService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.loop_cycle_state.v1';

    /** Transition result statuses (NEVER `success`; a blocked outcome is still a legal advance). */
    public const STATUS_OK = 'ok';

    public const STATUS_FAIL_CLOSED = 'fail_closed';

    /** The durable cycle states, in legal order. */
    public const STATE_PLANNED = 'planned';

    public const STATE_PREFLIGHTED = 'preflighted';

    public const STATE_EXECUTING = 'executing';

    public const STATE_VALIDATED = 'validated';

    public const STATE_JUDGED = 'judged';

    public const STATE_MERGED_OR_BLOCKED = 'merged_or_blocked';

    public const STATE_AUDITED = 'audited';

    public const STATE_CLEANED = 'cleaned';

    /** The legal state order (index position = advance step). No skips allowed. */
    private const STATE_ORDER = [
        self::STATE_PLANNED,
        self::STATE_PREFLIGHTED,
        self::STATE_EXECUTING,
        self::STATE_VALIDATED,
        self::STATE_JUDGED,
        self::STATE_MERGED_OR_BLOCKED,
        self::STATE_AUDITED,
        self::STATE_CLEANED,
    ];

    /** The first state: a cycle with no durable rows is implicitly here. */
    private const INITIAL_STATE = self::STATE_PLANNED;

    /** The terminal completion state. Reaching this is the only "cycle done". */
    private const TERMINAL_STATE = self::STATE_CLEANED;

    private ?string $storageRootOverride = null;

    public function setStorageRootForTesting(?string $dir): void
    {
        $this->storageRootOverride = $dir;
    }

    public function storageDir(): string
    {
        if ($this->storageRootOverride !== null) {
            return $this->storageRootOverride;
        }

        return function_exists('storage_path')
            ? storage_path('atlas/software_company_stewardship/long_horizon_loop')
            : sys_get_temp_dir().'/atlas/software_company_stewardship/long_horizon_loop';
    }

    /**
     * Path of the append-only JSONL ledger for one (area, focus, run, cycle).
     */
    public function recordPath(string $area, string $focus, string $runId, int $cycleIndex): string
    {
        return $this->storageDir()
            .DIRECTORY_SEPARATOR.$this->slug($area)
            .DIRECTORY_SEPARATOR.$this->slug($focus)
            .DIRECTORY_SEPARATOR.'cycle_state'
            .DIRECTORY_SEPARATOR.$this->slug($runId !== '' ? $runId : 'run').'.cycle'.$cycleIndex.'.jsonl';
    }

    /**
     * Append-only advance to the next state.
     *
     * Reads the current durable state from the ledger (or `$input['current_state']`
     * seam in tests), then attempts to advance to `$input['target_state']` (or the
     * next state in legal order when no explicit target is given). An illegal skip
     * or an ambiguous/unknown current state FAILS CLOSED (no advance, no append).
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function transition(array $input = []): array
    {
        [$area, $focus, $runId, $cycleIndex] = $this->identity($input);

        $blockers = [];
        $warnings = [];

        // Resolve the current durable state via the input seam first, ledger second.
        [$currentState, $currentAmbiguous, $rowCount] = $this->currentState($input, $area, $focus, $runId, $cycleIndex);

        // Resolve the requested target: explicit, else the single next legal state.
        $target = $this->normalizeState($input['target_state'] ?? ($input['to_state'] ?? null));
        $resolvedTarget = $target ?? $this->nextLegalState($currentState);

        // The outcome carried by a `merged_or_blocked` transition (merged|blocked).
        // NEVER let `blocked` be reported as merged.
        $outcome = $this->normalizeOutcome($input['outcome'] ?? null);

        $status = self::STATUS_OK;
        $applied = false;
        $nextState = $currentState;

        if ($currentAmbiguous) {
            // Ambiguous / unknown current state => fail closed (cannot prove a base).
            $blockers[] = 'ambiguous_current_state_fail_closed';
            $status = self::STATUS_FAIL_CLOSED;
        } elseif ($currentState === self::TERMINAL_STATE) {
            // Cycle already terminal; advancing past `cleaned` is illegal (checked
            // before the null-target branch, since terminal has no next legal state).
            $blockers[] = 'cycle_already_terminal_no_advance';
            $status = self::STATUS_FAIL_CLOSED;
        } elseif ($resolvedTarget === null) {
            $blockers[] = 'unknown_target_state_fail_closed';
            $status = self::STATUS_FAIL_CLOSED;
        } elseif (! $this->isLegalAdvance($currentState, $resolvedTarget)) {
            // Any non-+1 step (skip, repeat, or backward) is illegal.
            $blockers[] = $this->illegalReason($currentState, $resolvedTarget);
            $status = self::STATUS_FAIL_CLOSED;
        } else {
            // Legal single-step advance — append a durable row.
            $applied = true;
            $nextState = $resolvedTarget;
            if ($nextState === self::STATE_MERGED_OR_BLOCKED && $outcome === 'blocked') {
                $warnings[] = 'merged_or_blocked_outcome_is_blocked_not_merged';
            }
        }

        $stateOutcome = $this->stateOutcome($nextState, $outcome);

        if ($applied) {
            $this->appendLedgerRow(
                $this->recordPath($area, $focus, $runId, $cycleIndex),
                $this->ledgerRow($area, $focus, $runId, $cycleIndex, $currentState, $nextState, $stateOutcome, $rowCount + 1)
            );
            $rowCount++;
        }

        return $this->report([
            'method' => 'transition',
            'status' => $status,
            'area' => $area,
            'focus' => $focus,
            'run_id' => $runId,
            'cycle_index' => $cycleIndex,
            'from_state' => $currentState,
            'requested_target_state' => $resolvedTarget,
            'state' => $nextState,
            'applied' => $applied,
            'outcome' => $stateOutcome,
            'is_terminal' => $nextState === self::TERMINAL_STATE && ! $currentAmbiguous,
            'durable_row_count' => $rowCount,
            'resume_point' => $currentAmbiguous ? null : $nextState,
            'legal_order' => self::STATE_ORDER,
            'blockers' => $blockers,
            'warnings' => $warnings,
        ]);
    }

    /**
     * The current durable state for a cycle, derived purely from the ledger
     * (or the `current_state` / `ledger` seams in tests).
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function load(array $input = []): array
    {
        [$area, $focus, $runId, $cycleIndex] = $this->identity($input);

        [$currentState, $ambiguous, $rowCount] = $this->currentState($input, $area, $focus, $runId, $cycleIndex);
        $outcome = $this->lastOutcome($input, $area, $focus, $runId, $cycleIndex);

        $blockers = [];
        $status = self::STATUS_OK;
        if ($ambiguous) {
            $blockers[] = 'ambiguous_current_state_fail_closed';
            $status = self::STATUS_FAIL_CLOSED;
        }

        return $this->report([
            'method' => 'load',
            'status' => $status,
            'area' => $area,
            'focus' => $focus,
            'run_id' => $runId,
            'cycle_index' => $cycleIndex,
            'state' => $ambiguous ? null : $currentState,
            'outcome' => $ambiguous ? null : $this->stateOutcome($currentState, $outcome),
            'is_terminal' => ! $ambiguous && $currentState === self::TERMINAL_STATE,
            'durable_row_count' => $rowCount,
            'resume_point' => $ambiguous ? null : $currentState,
            'legal_order' => self::STATE_ORDER,
            'blockers' => $blockers,
            'warnings' => [],
        ]);
    }

    /**
     * The ONLY safe state to resume from: the last durably recorded state. A crash
     * before any durable row resumes from `planned` (the initial state). An
     * ambiguous ledger fails closed (no safe resume point).
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function resumePoint(array $input = []): array
    {
        [$area, $focus, $runId, $cycleIndex] = $this->identity($input);

        [$currentState, $ambiguous, $rowCount] = $this->currentState($input, $area, $focus, $runId, $cycleIndex);

        $blockers = [];
        $warnings = [];
        $status = self::STATUS_OK;
        $resume = $currentState;
        $next = null;

        if ($ambiguous) {
            $blockers[] = 'ambiguous_current_state_fail_closed';
            $status = self::STATUS_FAIL_CLOSED;
            $resume = null;
        } else {
            $next = $this->nextLegalState($currentState);
            if ($currentState === self::TERMINAL_STATE) {
                $warnings[] = 'cycle_terminal_nothing_to_resume';
            }
        }

        return $this->report([
            'method' => 'resume_point',
            'status' => $status,
            'area' => $area,
            'focus' => $focus,
            'run_id' => $runId,
            'cycle_index' => $cycleIndex,
            'state' => $resume,
            'resume_point' => $resume,
            'next_legal_state' => $next,
            'is_terminal' => ! $ambiguous && $currentState === self::TERMINAL_STATE,
            'durable_row_count' => $rowCount,
            'legal_order' => self::STATE_ORDER,
            'blockers' => $blockers,
            'warnings' => $warnings,
        ]);
    }

    // ---------------------------------------------------------------- state resolution

    /**
     * Resolve the current durable state. Order of precedence:
     *   1. explicit `current_state` seam (tests),
     *   2. the `ledger` rows seam (tests),
     *   3. the durable JSONL ledger on disk.
     *
     * Returns [state, ambiguous, durableRowCount]. `ambiguous` is true when an
     * explicit current_state is unrecognized OR a ledger contains an illegal /
     * unknown sequence — both fail closed.
     *
     * @param  array<string,mixed>  $input
     * @return array{0:string,1:bool,2:int}
     */
    private function currentState(array $input, string $area, string $focus, string $runId, int $cycleIndex): array
    {
        if (array_key_exists('current_state', $input)) {
            $raw = $input['current_state'];
            if ($raw === null || $raw === '') {
                return [self::INITIAL_STATE, false, 0];
            }
            $state = $this->normalizeState($raw);
            if ($state === null) {
                // An unknown explicit current state is ambiguous => fail closed.
                return [self::INITIAL_STATE, true, 0];
            }

            return [$state, false, $this->stateIndex($state) + 1];
        }

        $rows = $this->ledgerRows($input, $area, $focus, $runId, $cycleIndex);
        if ($rows === []) {
            // No durable rows yet: implicitly at the initial state, unambiguous.
            return [self::INITIAL_STATE, false, 0];
        }

        // Replay rows: each row's `state` must be the +1 legal advance of the prior.
        $prev = self::INITIAL_STATE;
        $seenAny = false;
        foreach ($rows as $row) {
            $state = $this->normalizeState($row['state'] ?? null);
            if ($state === null) {
                return [self::INITIAL_STATE, true, count($rows)]; // unknown state => ambiguous
            }
            if (! $seenAny) {
                // The first durable row must be the advance out of `planned`.
                if (! $this->isLegalAdvance(self::INITIAL_STATE, $state) && $state !== self::INITIAL_STATE) {
                    return [self::INITIAL_STATE, true, count($rows)];
                }
                $prev = $state;
                $seenAny = true;

                continue;
            }
            if (! $this->isLegalAdvance($prev, $state)) {
                return [self::INITIAL_STATE, true, count($rows)]; // illegal sequence => ambiguous
            }
            $prev = $state;
        }

        return [$prev, false, count($rows)];
    }

    /**
     * The outcome carried by the most recent durable row (merged|blocked|none).
     *
     * @param  array<string,mixed>  $input
     */
    private function lastOutcome(array $input, string $area, string $focus, string $runId, int $cycleIndex): ?string
    {
        if (array_key_exists('outcome', $input)) {
            return $this->normalizeOutcome($input['outcome']);
        }
        $rows = $this->ledgerRows($input, $area, $focus, $runId, $cycleIndex);
        for ($i = count($rows) - 1; $i >= 0; $i--) {
            $o = $this->normalizeOutcome($rows[$i]['outcome'] ?? null);
            if ($o !== null) {
                return $o;
            }
        }

        return null;
    }

    /**
     * The state rows for a cycle, from the `ledger` seam (tests) or disk.
     *
     * @param  array<string,mixed>  $input
     * @return list<array<string,mixed>>
     */
    private function ledgerRows(array $input, string $area, string $focus, string $runId, int $cycleIndex): array
    {
        if (array_key_exists('ledger', $input) && is_array($input['ledger'])) {
            return array_values(array_filter(
                $input['ledger'],
                static fn ($row): bool => is_array($row),
            ));
        }

        return $this->readLedger($this->recordPath($area, $focus, $runId, $cycleIndex));
    }

    private function nextLegalState(string $state): ?string
    {
        $idx = $this->stateIndex($state);
        if ($idx < 0 || $idx >= count(self::STATE_ORDER) - 1) {
            return null;
        }

        return self::STATE_ORDER[$idx + 1];
    }

    private function isLegalAdvance(string $from, string $to): bool
    {
        $fromIdx = $this->stateIndex($from);
        $toIdx = $this->stateIndex($to);
        if ($fromIdx < 0 || $toIdx < 0) {
            return false;
        }

        // Legal only as the single next step. Skips, repeats and backward are illegal.
        return $toIdx === $fromIdx + 1;
    }

    private function illegalReason(string $from, string $to): string
    {
        $fromIdx = $this->stateIndex($from);
        $toIdx = $this->stateIndex($to);

        if ($from === self::STATE_PLANNED && $to !== self::STATE_PREFLIGHTED && $toIdx > $fromIdx) {
            // AP-807: a cycle can NEVER skip preflighted.
            return 'cannot_skip_preflighted';
        }
        if ($toIdx <= $fromIdx) {
            return 'illegal_backward_or_repeat_transition';
        }

        return 'illegal_skip_transition';
    }

    private function stateIndex(string $state): int
    {
        $idx = array_search($state, self::STATE_ORDER, true);

        return $idx === false ? -1 : $idx;
    }

    private function normalizeState(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = strtolower(trim((string) $value));

        return in_array($value, self::STATE_ORDER, true) ? $value : null;
    }

    private function normalizeOutcome(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = strtolower(trim((string) $value));

        return in_array($value, ['merged', 'blocked'], true) ? $value : null;
    }

    /**
     * The honest outcome for a state. Only `merged_or_blocked` carries merged|blocked;
     * a `cleaned` state preserves it. Other states have no merge outcome.
     */
    private function stateOutcome(string $state, ?string $outcome): string
    {
        if (in_array($state, [self::STATE_MERGED_OR_BLOCKED, self::STATE_AUDITED, self::STATE_CLEANED], true)) {
            return $outcome ?? 'unknown';
        }

        return 'pending';
    }

    // ---------------------------------------------------------------- report

    /**
     * @param  array<string,mixed>  $core
     * @return array<string,mixed>
     */
    private function report(array $core): array
    {
        $area = (string) ($core['area'] ?? '');
        $focus = (string) ($core['focus'] ?? '');
        $runId = (string) ($core['run_id'] ?? '');
        $cycleIndex = (int) ($core['cycle_index'] ?? 0);

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-808',
            'slice_id' => 'LHL-07',
            'method' => (string) ($core['method'] ?? ''),
            'status' => (string) ($core['status'] ?? self::STATUS_OK),
            'cycle_state_id' => 'lcs_'.substr(MissionCanonicalHash::sha256([
                $area,
                $focus,
                $runId,
                $cycleIndex,
            ]), 0, 16),
            'run_id' => $runId,
            'cycle_index' => $cycleIndex,
            'area' => $area,
            'focus' => $focus,
            'checked_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'state' => $core['state'] ?? null,
            'outcome' => $core['outcome'] ?? null,
            'is_terminal' => (bool) ($core['is_terminal'] ?? false),
            'durable_row_count' => (int) ($core['durable_row_count'] ?? 0),
            'resume_point' => $core['resume_point'] ?? null,
            'legal_order' => $core['legal_order'] ?? self::STATE_ORDER,
            'blockers' => array_values(array_unique($this->stringList($core['blockers'] ?? []))),
            'warnings' => array_values(array_unique($this->stringList($core['warnings'] ?? []))),
            'next_action' => ($core['status'] ?? self::STATUS_OK) === self::STATUS_OK ? 'continue' : 'stop_fail_closed',
            'claim_policy' => [
                'read_only' => true,
                'runs_provider' => false,
                'runs_loop' => false,
                'runs_merge' => false,
                'deletes_branches' => false,
                'persistence' => 'jsonl_append_only',
                'blocked_never_dressed_as_ready' => true,
            ],
        ];

        // Method-specific extra keys (kept stable for the hash).
        foreach (['from_state', 'requested_target_state', 'applied', 'next_legal_state'] as $extra) {
            if (array_key_exists($extra, $core)) {
                $payload[$extra] = $core[$extra];
            }
        }

        $payload['report_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->withoutVolatile($payload));

        return $payload;
    }

    // ---------------------------------------------------------------- ledger io

    /**
     * @return array<string,mixed>
     */
    private function ledgerRow(string $area, string $focus, string $runId, int $cycleIndex, string $fromState, string $toState, string $outcome, int $sequence): array
    {
        $core = [
            'schema_version' => self::REPORT_SCHEMA,
            'kind' => 'cycle_state_transition',
            'area' => $area,
            'focus' => $focus,
            'run_id' => $runId,
            'cycle_index' => $cycleIndex,
            'sequence' => $sequence,
            'from_state' => $fromState,
            'state' => $toState,
            'outcome' => $outcome,
        ];
        $core['transition_hash'] = 'sha256:'.MissionCanonicalHash::sha256($core);

        $row = $core;
        $row['recorded_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

        return $row;
    }

    /**
     * Read durable rows from the JSONL ledger, skipping malformed lines and rows
     * without a `state`. Never throws on corruption.
     *
     * @return list<array<string,mixed>>
     */
    private function readLedger(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $rows = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && isset($decoded['state']) && is_string($decoded['state'])) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function appendLedgerRow(string $path, array $payload): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $fp = fopen($path, 'ab');
        if ($fp === false) {
            throw new \RuntimeException("Could not open {$path} for writing.");
        }
        try {
            if (flock($fp, LOCK_EX)) {
                fwrite($fp, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
                fflush($fp);
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param  array<string,mixed>  $input
     * @return array{0:string,1:string,2:string,3:int}
     */
    private function identity(array $input): array
    {
        $area = trim((string) ($input['area'] ?? 'agentic_engineering_os')) ?: 'agentic_engineering_os';
        $focus = trim((string) ($input['focus'] ?? 'dev_forge')) ?: 'dev_forge';
        $runId = trim((string) ($input['run_id'] ?? ''));
        $cycleIndex = (int) ($input['cycle_index'] ?? 0);

        return [$area, $focus, $runId, $cycleIndex];
    }

    private function slug(string $value): string
    {
        $slug = preg_replace('/[^a-z0-9_.-]+/', '_', strtolower($value)) ?? '';

        return $slug !== '' ? $slug : 'unknown';
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function stringList($value): array
    {
        return array_values(array_filter(array_map(
            static fn ($item): string => is_string($item) ? $item : '',
            is_array($value) ? $value : [],
        ), static fn (string $item): bool => $item !== ''));
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
