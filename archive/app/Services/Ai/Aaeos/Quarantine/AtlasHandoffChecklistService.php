<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Legacy Cleanup Handoff Checklist decider.
 *
 * Pure, deterministic gate that decides whether one Atlas legacy-cleanup
 * session / PR may emit its final handoff message, or must keep working, or
 * must stop and escalate. It turns the documented checklist into a contract:
 * a cleanup is only "ready" when every Before-Final-Message item is satisfied
 * AND every Required Validation command reported ok AND no Stop Condition is
 * present. Stop Conditions are absolute — they override an otherwise complete
 * checklist so a session never hands off over a sensitive source, a live
 * delete reference, an authority conflict, or an unrelated validation break.
 *
 * Contract (doc "Before Final Message"): the next AI must be handed
 *   - the cleanup wave that was executed,
 *   - the active docs changed,
 *   - the archived source material created/used,
 *   - the redirects / canonical replacements added,
 *   - a runtime-scope statement (no runtime touched, or an explicit reason),
 *   - a no-vault-promotion-without-review confirmation.
 *
 * Contract (doc "Required Validation"): five commands must each report ok:
 *   docs-health, architecture-validate, git diff --check (docs),
 *   sync --prune, index-code --prune. A missing or failing command blocks.
 *
 * Contract (doc "Stop Conditions"): stop and report (do NOT hand off as done)
 * if any of these is true:
 *   - canonical authority conflicts with another doc,
 *   - a source contains sensitive personal material,
 *   - a delete candidate still has live references,
 *   - validation fails in a way unrelated to the cleanup.
 *
 * Output: a single verdict — ready | incomplete | stop — plus the per-item
 * checklist breakdown, the per-command validation breakdown, the triggered
 * stop conditions, the remaining `split_required` count for the Final Report
 * Shape, and an audit receipt. The service NEVER runs a command, reads a doc,
 * or touches git; it consumes already-normalized results and emits the verdict.
 *
 * @see docs/engineering-knowledge-base/legacy-cleanup/handoff-checklist.md
 */
final class AtlasHandoffChecklistService
{
    /** Stable receipt schema id for the verdict this service emits. */
    public const RECEIPT_SCHEMA = 'atlas.legacy_cleanup.handoff_checklist.v1';

    /** Closed set of verdicts. */
    public const VERDICT_READY = 'ready';
    public const VERDICT_INCOMPLETE = 'incomplete';
    public const VERDICT_STOP = 'stop';

    /** Required next action per verdict (what the session must do). */
    public const NEXT_EMIT_FINAL_REPORT = 'emit_final_report';
    public const NEXT_COMPLETE_CHECKLIST = 'complete_checklist';
    public const NEXT_STOP_AND_REPORT = 'stop_and_report';

    /**
     * The six "Before Final Message" checklist items, in doc order. Each maps
     * to a boolean flag the caller asserts true once satisfied.
     *
     * @var array<string,string>
     */
    private const CHECKLIST_ITEMS = [
        'cleanup_wave_stated' => 'State the cleanup wave executed.',
        'active_docs_listed' => 'List active docs changed.',
        'archived_sources_listed' => 'List archived source material created or used.',
        'redirects_listed' => 'List redirects or canonical replacements added.',
        'runtime_scope_confirmed' => 'Confirm no runtime files were changed, or explain why runtime was in scope.',
        'no_vault_promotion_unreviewed' => 'Confirm no personal/vault material was promoted without review.',
    ];

    /**
     * The five "Required Validation" commands, in doc order. Each must report
     * an ok result before a handoff is allowed.
     *
     * @var array<string,string>
     */
    private const VALIDATION_COMMANDS = [
        'docs_health' => 'atlas engineering knowledge docs-health --json',
        'architecture_validate' => 'php artisan atlas:ai:architecture-validate --json',
        'diff_check' => 'git diff --check -- docs/engineering-knowledge-base',
        'sync' => 'atlas engineering knowledge sync --prune --json',
        'index_code' => 'atlas engineering knowledge index-code --prune --workspace=... --json',
    ];

    /**
     * The four "Stop Conditions", in doc order. Each maps to a boolean flag the
     * caller raises when the condition is observed.
     *
     * @var array<string,string>
     */
    private const STOP_CONDITIONS = [
        'authority_conflict' => 'canonical authority conflicts with another doc',
        'sensitive_personal_material' => 'a source contains sensitive personal material',
        'live_reference_to_delete_candidate' => 'a delete candidate still has live references',
        'unrelated_validation_failure' => 'validation fails in a way unrelated to the cleanup',
    ];

    /**
     * Evaluate one legacy-cleanup handoff and return the single verdict.
     *
     * @param array<string,mixed> $handoff
     *   checklist           : array<string,bool> the six Before-Final-Message
     *                         flags (see CHECKLIST_ITEMS keys); missing => false.
     *   validation          : array<string,mixed> per-command result keyed by
     *                         VALIDATION_COMMANDS keys. Each value may be a bool
     *                         (true=ok) or an array { status:string,
     *                         related_to_cleanup:bool? }. A status other than
     *                         "ok"/"pass" is a failure; missing => failure.
     *   stop_conditions     : array<string,bool> the four Stop-Condition flags
     *                         (see STOP_CONDITIONS keys); missing => false.
     *   split_required_count: int  docs still marked split_required (Final
     *                         Report "Restante"); informational, does not block.
     *
     * @return array<string,mixed> verdict + breakdowns + receipt
     */
    public function evaluate(array $handoff): array
    {
        $checklist = $this->evaluateChecklist($handoff['checklist'] ?? []);
        $validation = $this->evaluateValidation($handoff['validation'] ?? []);
        $stop = $this->evaluateStopConditions($handoff['validation'] ?? [], $handoff['stop_conditions'] ?? []);

        $splitRequired = $this->normalizeCount($handoff['split_required_count'] ?? 0);

        $reasons = [];
        $verdict = null;

        // --- Rule 1: Stop Conditions are absolute and override everything. ---
        // "Stop and report if: ... a source contains sensitive personal
        // material; a delete candidate still has live references; ...".
        if ($stop['triggered'] !== []) {
            $verdict = self::VERDICT_STOP;
            foreach ($stop['triggered'] as $key) {
                $reasons[] = 'stop:' . $key;
            }
        }

        // --- Rule 2: every Required Validation command must report ok. ---
        // A failure that is itself a stop condition was already caught above;
        // a cleanup-related failure here simply means the work is not finished.
        if ($verdict === null && ! $validation['all_ok']) {
            $verdict = self::VERDICT_INCOMPLETE;
            foreach ($validation['failing'] as $key) {
                $reasons[] = 'validation_not_ok:' . $key;
            }
        }

        // --- Rule 3: every Before-Final-Message item must be satisfied. ---
        if ($verdict === null && ! $checklist['all_done']) {
            $verdict = self::VERDICT_INCOMPLETE;
            foreach ($checklist['missing'] as $key) {
                $reasons[] = 'checklist_missing:' . $key;
            }
        }

        // --- Rule 4: checklist complete + validation green + no stop => ready. ---
        if ($verdict === null) {
            $verdict = self::VERDICT_READY;
            $reasons[] = 'handoff_ready';
        }

        $requiredNextAction = $this->requiredNextAction($verdict);

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'verdict' => $verdict,
            'required_next_action' => $requiredNextAction,
            'may_emit_final_report' => $verdict === self::VERDICT_READY,
            'checklist' => $checklist,
            'validation' => $validation,
            'stop_conditions' => $stop,
            'split_required_count' => $splitRequired,
            'cleanup_complete' => $verdict === self::VERDICT_READY && $splitRequired === 0,
            'auditable' => true,
            'reasons' => $reasons,
        ];
    }

    /**
     * Convenience predicate for the handoff driver: may this session emit its
     * final "done" report? Only a fully ready handoff may.
     *
     * @param array<string,mixed> $handoff
     */
    public function mayEmitFinalReport(array $handoff): bool
    {
        return $this->evaluate($handoff)['verdict'] === self::VERDICT_READY;
    }

    /**
     * Build the documented "Final Report Shape" string from a ready handoff
     * summary. Mirrors the doc's md template (Concluido / Validacao / Restante).
     *
     * @param array<string,mixed> $summary
     *   compacted:string, preserved:string, child_docs:int,
     *   split_required_count:int
     * @return array<string,mixed>
     */
    public function finalReportShape(array $summary): array
    {
        $childDocs = $this->normalizeCount($summary['child_docs'] ?? 0);
        $splitRequired = $this->normalizeCount($summary['split_required_count'] ?? 0);

        return [
            'concluido' => [
                'compactei ' . trim((string) ($summary['compacted'] ?? '')),
                'preservei ' . trim((string) ($summary['preserved'] ?? '')),
                'criei ' . $childDocs . ' child docs',
            ],
            'validacao' => [
                'docs-health: ok',
                'architecture-validate: ok',
                'diff check: ok',
                'sync/index: ok',
            ],
            'restante' => [
                $splitRequired . ' docs split_required',
            ],
        ];
    }

    /**
     * @param mixed $checklist
     * @return array<string,mixed>
     */
    private function evaluateChecklist(mixed $checklist): array
    {
        $checklist = is_array($checklist) ? $checklist : [];
        $items = [];
        $missing = [];

        foreach (array_keys(self::CHECKLIST_ITEMS) as $key) {
            $done = (bool) ($checklist[$key] ?? false);
            $items[$key] = $done;
            if (! $done) {
                $missing[] = $key;
            }
        }

        return [
            'items' => $items,
            'total' => count(self::CHECKLIST_ITEMS),
            'done_count' => count(self::CHECKLIST_ITEMS) - count($missing),
            'missing' => array_values($missing),
            'all_done' => $missing === [],
        ];
    }

    /**
     * @param mixed $validation
     * @return array<string,mixed>
     */
    private function evaluateValidation(mixed $validation): array
    {
        $validation = is_array($validation) ? $validation : [];
        $results = [];
        $failing = [];

        foreach (array_keys(self::VALIDATION_COMMANDS) as $key) {
            $ok = $this->commandReportedOk($validation[$key] ?? null);
            $results[$key] = $ok;
            if (! $ok) {
                $failing[] = $key;
            }
        }

        return [
            'commands' => $results,
            'total' => count(self::VALIDATION_COMMANDS),
            'ok_count' => count(self::VALIDATION_COMMANDS) - count($failing),
            'failing' => array_values($failing),
            'all_ok' => $failing === [],
        ];
    }

    /**
     * Resolve the Stop Conditions. The explicit boolean flags are honoured, and
     * the "validation fails in a way unrelated to the cleanup" condition is also
     * derived automatically from any validation entry that reports not-ok AND is
     * marked related_to_cleanup=false.
     *
     * @param mixed $validation
     * @param mixed $stopFlags
     * @return array<string,mixed>
     */
    private function evaluateStopConditions(mixed $validation, mixed $stopFlags): array
    {
        $stopFlags = is_array($stopFlags) ? $stopFlags : [];
        $triggered = [];

        foreach (array_keys(self::STOP_CONDITIONS) as $key) {
            if ((bool) ($stopFlags[$key] ?? false)) {
                $triggered[] = $key;
            }
        }

        // Derive "unrelated_validation_failure" from validation results: a
        // command that failed and is explicitly flagged as NOT related to the
        // cleanup is, by the doc, a hard stop ("validation fails in a way
        // unrelated to the cleanup").
        if (! in_array('unrelated_validation_failure', $triggered, true)
            && $this->hasUnrelatedValidationFailure($validation)) {
            $triggered[] = 'unrelated_validation_failure';
        }

        return [
            'definitions' => self::STOP_CONDITIONS,
            'triggered' => array_values($triggered),
            'any' => $triggered !== [],
        ];
    }

    /**
     * A validation entry counts as ok only when it is boolean true, or an array
     * whose status is "ok"/"pass". Anything missing, false, or any other status
     * is a failure.
     *
     * @param mixed $entry
     */
    private function commandReportedOk(mixed $entry): bool
    {
        if (is_bool($entry)) {
            return $entry;
        }
        if (is_array($entry)) {
            $status = strtolower((string) ($entry['status'] ?? ''));

            return in_array($status, ['ok', 'pass', 'passed', 'green'], true);
        }
        if (is_string($entry)) {
            return in_array(strtolower($entry), ['ok', 'pass', 'passed', 'green'], true);
        }

        // null / missing / numeric => not proven ok.
        return false;
    }

    /**
     * @param mixed $validation
     */
    private function hasUnrelatedValidationFailure(mixed $validation): bool
    {
        if (! is_array($validation)) {
            return false;
        }

        foreach ($validation as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            if ($this->commandReportedOk($entry)) {
                continue;
            }
            // Failed command. If the caller explicitly says it is NOT related to
            // the cleanup, it is the unrelated-validation-failure stop condition.
            if (array_key_exists('related_to_cleanup', $entry)
                && ! (bool) $entry['related_to_cleanup']) {
                return true;
            }
        }

        return false;
    }

    private function requiredNextAction(string $verdict): string
    {
        return match ($verdict) {
            self::VERDICT_READY => self::NEXT_EMIT_FINAL_REPORT,
            self::VERDICT_STOP => self::NEXT_STOP_AND_REPORT,
            default => self::NEXT_COMPLETE_CHECKLIST,
        };
    }

    private function normalizeCount(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }
        if (is_string($value) && is_numeric($value)) {
            return max(0, (int) $value);
        }

        return 0;
    }
}
