<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Gate;

use App\Services\Ai\Programming\AtlasDev\AtlasDevScopeGuardService;
use Throwable;

/**
 * Amplifies the Dev quality gates beyond bare scope enforcement: a proposed diff that stays
 * inside the workcell's allowed_files can still be a BAD diff — bloated far past the objective,
 * touching a public method's known callers without updating or testing them, colliding with
 * another run's uncommitted WIP, or smuggling in speculative abstractions. This gate is a pure,
 * deterministic post-diff check composed into the fast-path flow right after a diff exists; it
 * records an additive verdict rather than mutating the diff.
 *
 * CHECKS (each finding carries a stable id):
 *   scope_respect        (block) — composes {@see AtlasDevScopeGuardService} verbatim; any file
 *                                   outside the workcell's allowed_files is denied.
 *   wip_protection        (block) — a hunk overlaps uncommitted changes this run did not author.
 *   diff_minimality        (warn) — changed lines far exceed the objective's line budget.
 *   caller_coverage        (warn) — a changed public method has a known caller (from the
 *                                   manifest's likely_callers) that this diff neither touches
 *                                   nor exercises with a test.
 *   test_relevance          (warn) — the diff changes production symbols but includes no test
 *                                   exercising any of them.
 *   overengineering_smell  (warn) — a new interface with a single implementation, or a new
 *                                   config key pinned to a fixed value.
 *
 * VERDICT: any block finding => block (stops before apply). Else any warn finding => warn (rides
 * to the reviewer). Else pass. Deterministic; an internal error fails OPEN to warn, never block,
 * with an internal_error finding naming what broke.
 */
final class DevDiffQualityAmplifierGate
{
    public const SCHEMA = 'atlas.dev.diff_quality_amplifier_gate.v1';

    public const VERDICT_PASS = 'pass';

    public const VERDICT_WARN = 'warn';

    public const VERDICT_BLOCK = 'block';

    public const FINDING_SCOPE_RESPECT = 'scope_respect';

    public const FINDING_WIP_PROTECTION = 'wip_protection';

    public const FINDING_DIFF_MINIMALITY = 'diff_minimality';

    public const FINDING_CALLER_COVERAGE = 'caller_coverage';

    public const FINDING_TEST_RELEVANCE = 'test_relevance';

    public const FINDING_OVERENGINEERING_SMELL = 'overengineering_smell';

    public const FINDING_INTERNAL_ERROR = 'internal_error';

    private const DEFAULT_LINE_BUDGET = 10;

    private const MINIMALITY_MULTIPLIER = 3;

    public function __construct(private readonly ?AtlasDevScopeGuardService $scopeGuard = null) {}

    /**
     * @param  array<string,mixed>  $proposedDiff
     * @param  array<string,mixed>  $workcell
     * @param  array<string,mixed>  $manifest
     * @return array{schema:string, verdict:string, findings:list<array<string,mixed>>}
     */
    public function evaluate(array $proposedDiff, array $workcell, array $manifest): array
    {
        try {
            return $this->evaluateInternal($proposedDiff, $workcell, $manifest);
        } catch (Throwable $e) {
            return [
                'schema' => self::SCHEMA,
                'verdict' => self::VERDICT_WARN,
                'findings' => [[
                    'id' => self::FINDING_INTERNAL_ERROR,
                    'message' => $e->getMessage(),
                ]],
            ];
        }
    }

    /**
     * @param  array<string,mixed>  $proposedDiff
     * @param  array<string,mixed>  $workcell
     * @param  array<string,mixed>  $manifest
     * @return array{schema:string, verdict:string, findings:list<array<string,mixed>>}
     */
    private function evaluateInternal(array $proposedDiff, array $workcell, array $manifest): array
    {
        $files = is_array($proposedDiff['files'] ?? null) ? $proposedDiff['files'] : [];
        $filePaths = array_values(array_filter(array_map(
            static fn (mixed $f): string => (string) (is_array($f) ? ($f['path'] ?? '') : ''),
            $files,
        ), static fn (string $p): bool => $p !== ''));

        $allowedFiles = array_values(array_map('strval', (array) ($workcell['allowed_files'] ?? [])));
        $forbiddenFiles = array_values(array_map('strval', (array) ($workcell['forbidden_files'] ?? [])));

        $findings = [];

        // ── scope_respect (block) — composed verbatim, never reimplemented. ──
        $guard = $this->scopeGuard ?? new AtlasDevScopeGuardService;
        $scopeVerdict = $guard->evaluate($filePaths, $allowedFiles, $forbiddenFiles);
        if (($scopeVerdict['decision'] ?? '') === AtlasDevScopeGuardService::DECISION_DENY) {
            $findings[] = [
                'id' => self::FINDING_SCOPE_RESPECT,
                'severity' => self::VERDICT_BLOCK,
                'denied_writes' => (array) ($scopeVerdict['denied_writes'] ?? []),
                'reason' => 'proposed diff touches file(s) outside the workcell scope',
            ];
        }

        // ── wip_protection (block) — a hunk overlaps foreign uncommitted work. ──
        $wipOverlaps = [];
        foreach ($files as $file) {
            if (! is_array($file)) {
                continue;
            }
            $path = (string) ($file['path'] ?? '');
            foreach ((array) ($file['hunks'] ?? []) as $hunk) {
                if (is_array($hunk) && (bool) ($hunk['overlaps_foreign_uncommitted'] ?? false)) {
                    $wipOverlaps[] = $path;
                }
            }
        }
        if ($wipOverlaps !== []) {
            $findings[] = [
                'id' => self::FINDING_WIP_PROTECTION,
                'severity' => self::VERDICT_BLOCK,
                'files' => array_values(array_unique($wipOverlaps)),
                'reason' => 'diff overlaps uncommitted changes this run did not author',
            ];
        }

        // ── diff_minimality (warn) — changed lines far beyond the objective's budget. ──
        $totalChangedLines = 0;
        foreach ($files as $file) {
            if (! is_array($file)) {
                continue;
            }
            foreach ((array) ($file['hunks'] ?? []) as $hunk) {
                $totalChangedLines += max(0, (int) (is_array($hunk) ? ($hunk['changed_lines'] ?? 0) : 0));
            }
        }
        $lineBudget = max(1, (int) ($workcell['objective_line_budget'] ?? self::DEFAULT_LINE_BUDGET));
        if ($totalChangedLines > $lineBudget * self::MINIMALITY_MULTIPLIER) {
            $findings[] = [
                'id' => self::FINDING_DIFF_MINIMALITY,
                'severity' => self::VERDICT_WARN,
                'total_changed_lines' => $totalChangedLines,
                'objective_line_budget' => $lineBudget,
                'reason' => 'changed lines far exceed the objective slice',
            ];
        }

        // ── changed public methods + test coverage of this diff (shared by caller_coverage/test_relevance). ──
        $changedPublicMethods = [];
        $exercisedSymbols = [];
        $hasTestFile = false;
        foreach ($files as $file) {
            if (! is_array($file)) {
                continue;
            }
            if ((bool) ($file['is_test'] ?? false)) {
                $hasTestFile = true;
                foreach ((array) ($file['exercises_symbols'] ?? []) as $sym) {
                    $exercisedSymbols[] = (string) $sym;
                }

                continue;
            }
            foreach ((array) ($file['changed_public_methods'] ?? []) as $method) {
                $changedPublicMethods[] = (string) $method;
            }
        }
        $changedPublicMethods = array_values(array_unique(array_filter($changedPublicMethods)));
        $exercisedSymbols = array_values(array_unique(array_filter($exercisedSymbols)));

        // ── caller_coverage (warn) — a known caller of a changed method is neither touched nor tested. ──
        $likelyCallerRefs = [];
        foreach ((array) ($manifest['likely_callers'] ?? []) as $caller) {
            $likelyCallerRefs[] = (string) (is_array($caller) ? ($caller['ref'] ?? '') : '');
        }
        $likelyCallerRefs = array_values(array_filter($likelyCallerRefs));

        $uncoveredCallers = [];
        foreach ($changedPublicMethods as $method) {
            foreach ($likelyCallerRefs as $callerRef) {
                if (! str_contains($callerRef, $method)) {
                    continue;
                }
                $touchedByDiff = in_array($callerRef, $filePaths, true);
                $testedByDiff = in_array($method, $exercisedSymbols, true) || in_array($callerRef, $exercisedSymbols, true);
                if (! $touchedByDiff && ! $testedByDiff) {
                    $uncoveredCallers[] = ['method' => $method, 'caller' => $callerRef];
                }
            }
        }
        if ($uncoveredCallers !== []) {
            $findings[] = [
                'id' => self::FINDING_CALLER_COVERAGE,
                'severity' => self::VERDICT_WARN,
                'uncovered_callers' => $uncoveredCallers,
                'reason' => 'a changed public method has a known caller left untouched and untested',
            ];
        }

        // ── test_relevance (warn) — production symbols changed, no test exercises them. ──
        if ($changedPublicMethods !== [] && (! $hasTestFile || array_intersect($changedPublicMethods, $exercisedSymbols) === [])) {
            $findings[] = [
                'id' => self::FINDING_TEST_RELEVANCE,
                'severity' => self::VERDICT_WARN,
                'reason' => 'the diff changes production symbols with no test exercising them',
            ];
        }

        // ── overengineering_smell (warn) — speculative interface/config additions. ──
        $smells = [];
        foreach ($files as $file) {
            if (! is_array($file)) {
                continue;
            }
            foreach ((array) ($file['new_interfaces'] ?? []) as $iface) {
                if (is_array($iface) && (int) ($iface['implementation_count'] ?? 0) <= 1) {
                    $smells[] = 'new_interface:'.(string) ($iface['name'] ?? '');
                }
            }
            foreach ((array) ($file['new_config_keys'] ?? []) as $cfg) {
                if (is_array($cfg) && (bool) ($cfg['fixed_value'] ?? false)) {
                    $smells[] = 'new_config_key:'.(string) ($cfg['key'] ?? '');
                }
            }
        }
        if ($smells !== []) {
            $findings[] = [
                'id' => self::FINDING_OVERENGINEERING_SMELL,
                'severity' => self::VERDICT_WARN,
                'smells' => $smells,
                'reason' => 'speculative abstraction with no second consumer',
            ];
        }

        $verdict = self::VERDICT_PASS;
        foreach ($findings as $finding) {
            if (($finding['severity'] ?? '') === self::VERDICT_BLOCK) {
                $verdict = self::VERDICT_BLOCK;

                break;
            }
            if (($finding['severity'] ?? '') === self::VERDICT_WARN) {
                $verdict = self::VERDICT_WARN;
            }
        }

        return [
            'schema' => self::SCHEMA,
            'verdict' => $verdict,
            'findings' => $findings,
        ];
    }
}
