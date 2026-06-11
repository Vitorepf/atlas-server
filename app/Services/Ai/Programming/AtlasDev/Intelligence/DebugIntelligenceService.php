<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Intelligence;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\DebugRepairCandidate;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\DebugSuspectedCause;
use App\Services\Ai\Programming\AtlasDev\Schemas\DebugReceipt;
use App\Services\Ai\Programming\AtlasDev\Schemas\FastPathErrorLedgerEntry;
use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevStringListNormalizer;
use Illuminate\Support\Str;

/**
 * Atlas Dev Debug Intelligence — first-version-strong implementation.
 *
 * Replaces "try random repair prompts" with a structured pipeline:
 *
 *   classify failure → fingerprint → reproduction plan →
 *   suspected causes (hypotheses) → repair candidates →
 *   stop conditions → confidence → DebugReceipt
 *
 * The service is stateless and pure: it consumes an input map describing the
 * failure surface (gate, primary_error_excerpt, failing_test, command,
 * exit_code, changed_files, prior_failure_signatures, attempt_index,
 * evidence_refs, validation_commands) and produces ONE `DebugReceipt`.
 *
 * It never calls a provider, never executes shell commands, never reads
 * external systems. Real execution is delegated to the existing
 * `ProgrammingRepairExecutor` / `RepairOrchestrator`. Debug Intelligence is
 * the *thinking layer*: it provides the structured artefact those executors
 * can act on without re-deriving causes from raw error text.
 *
 * INSUFFICIENT CONTEXT → BLOCKER. When the input lacks the minimum signal
 * needed to diagnose honestly (no error excerpt, no gate, no validation
 * command), the receipt is sealed with
 * `status=blocked_insufficient_context` + `stop_conditions=[insufficient_context]`
 * + `blocker_reasons[]` — never a silent low-confidence guess.
 */
final class DebugIntelligenceService
{
    /** Maximum repair candidates emitted per receipt — keeps the artefact actionable. */
    public const MAX_REPAIR_CANDIDATES = 4;

    /** Confidence floor below which `diagnosed` is downgraded to `inconclusive`. */
    public const DIAGNOSIS_CONFIDENCE_FLOOR = 0.40;

    /**
     * @param  array<string,mixed>  $input
     */
    public function analyse(array $input): DebugReceipt
    {
        $runId = $this->stringOrThrow($input, 'run_id', allowEmpty: false);
        $gate = trim((string) ($input['gate'] ?? ''));
        $primaryError = trim((string) ($input['primary_error_excerpt'] ?? ''));
        $failingTest = $this->nullableTrim($input, 'failing_test');
        $command = $this->nullableTrim($input, 'command');
        $exitCode = isset($input['exit_code']) ? (int) $input['exit_code'] : null;
        $changedFiles = AtlasDevStringListNormalizer::trimmedStrings($input['changed_files'] ?? []);
        $priorFailureSignatures = AtlasDevStringListNormalizer::trimmedStrings($input['prior_failure_signatures'] ?? []);
        $attemptIndex = isset($input['attempt_index']) ? max(0, (int) $input['attempt_index']) : 0;
        $attemptBudget = isset($input['attempt_budget']) ? max(1, (int) $input['attempt_budget']) : 3;
        $diffSizeLines = isset($input['diff_size_lines']) ? max(0, (int) $input['diff_size_lines']) : 0;
        $priorDiffSizeLines = isset($input['prior_diff_size_lines']) ? max(0, (int) $input['prior_diff_size_lines']) : 0;
        $evidenceRefs = AtlasDevStringListNormalizer::trimmedStrings($input['evidence_refs'] ?? []);
        $validationCommands = AtlasDevStringListNormalizer::trimmedStrings($input['validation_commands'] ?? []);
        $createdAt = (string) ($input['created_at'] ?? now()->toIso8601String());
        $receiptId = (string) ($input['receipt_id'] ?? 'dbg_'.Str::uuid());

        // --- 1. Insufficient context gate ----------------------------------
        $blockerReasons = $this->detectInsufficientContext($gate, $primaryError, $command, $failingTest);
        if ($blockerReasons !== []) {
            return DebugReceipt::issue(
                receiptId: $receiptId,
                runId: $runId,
                status: DebugReceipt::STATUS_BLOCKED_INSUFFICIENT_CONTEXT,
                failureFingerprint: DebugReceipt::fingerprintOf($gate ?: 'unknown', $primaryError ?: '(no error excerpt)'),
                failureClassification: FastPathErrorLedgerEntry::FAILURE_MODE_OTHER,
                primaryErrorExcerpt: $primaryError,
                reproductionPlan: [],
                suspectedCauses: [],
                repairCandidates: [],
                confidence: 0.0,
                stopConditions: [DebugReceipt::STOP_INSUFFICIENT_CONTEXT],
                evidenceRefs: $evidenceRefs,
                blockerReasons: $blockerReasons,
                createdAt: $createdAt,
            );
        }

        // --- 2. Fingerprint + classification ------------------------------
        $fingerprint = DebugReceipt::fingerprintOf($gate, $primaryError);
        $classification = $this->classify($primaryError, $failingTest, $exitCode, $changedFiles);

        // --- 3. Reproduction plan -----------------------------------------
        $reproductionPlan = $this->buildReproductionPlan($validationCommands, $command, $failingTest, $changedFiles);

        // --- 4. Suspected causes ------------------------------------------
        $causes = $this->generateSuspectedCauses($classification, $primaryError, $failingTest, $changedFiles);

        // --- 5. Repair candidates -----------------------------------------
        $candidates = $this->generateRepairCandidates($classification, $causes, $changedFiles, $validationCommands);

        // --- 6. Stop conditions -------------------------------------------
        $stopConditions = $this->detectStopConditions(
            $fingerprint,
            $priorFailureSignatures,
            $attemptIndex,
            $attemptBudget,
            $diffSizeLines,
            $priorDiffSizeLines,
            $causes,
            (bool) ($input['human_review_requested'] ?? false),
        );

        // --- 7. Confidence ------------------------------------------------
        $confidence = $this->computeConfidence($causes, $candidates, $stopConditions, $classification);

        // --- 8. Status decision -------------------------------------------
        $status = $this->decideStatus($candidates, $causes, $stopConditions, $confidence);

        return DebugReceipt::issue(
            receiptId: $receiptId,
            runId: $runId,
            status: $status,
            failureFingerprint: $fingerprint,
            failureClassification: $classification,
            primaryErrorExcerpt: $primaryError,
            reproductionPlan: $reproductionPlan,
            suspectedCauses: $causes,
            repairCandidates: $candidates,
            confidence: $confidence,
            stopConditions: $stopConditions,
            evidenceRefs: $evidenceRefs,
            blockerReasons: [],
            createdAt: $createdAt,
        );
    }

    /**
     * @return list<string>
     */
    private function detectInsufficientContext(string $gate, string $primaryError, ?string $command, ?string $failingTest): array
    {
        $reasons = [];
        if ($gate === '') {
            $reasons[] = 'missing_gate';
        }
        if ($primaryError === '') {
            $reasons[] = 'missing_primary_error_excerpt';
        }
        if ($command === null && $failingTest === null) {
            $reasons[] = 'missing_command_or_failing_test';
        }

        return $reasons;
    }

    /**
     * Maps the failure surface into the canonical `FAILURE_MODE_*` taxonomy.
     * Reuses keyword heuristics aligned with FailureModeClassifier so
     * Atlas Dev's repair loop and Debug Intelligence agree on the label.
     *
     * @param  list<string>  $changedFiles
     */
    public function classify(string $primaryError, ?string $failingTest, ?int $exitCode, array $changedFiles): string
    {
        $err = strtolower($primaryError);

        if ($err === '') {
            return FastPathErrorLedgerEntry::FAILURE_MODE_OTHER;
        }
        if (str_contains($err, 'undefined variable') || str_contains($err, 'undefined property')
            || str_contains($err, 'undefined method') || str_contains($err, 'undefined index')
            || str_contains($err, 'null pointer') || str_contains($err, 'cannot read property')
        ) {
            return FastPathErrorLedgerEntry::FAILURE_MODE_BAD_REPAIR;
        }
        if (str_contains($err, 'syntax error') || str_contains($err, 'parse error')
            || str_contains($err, 'unexpected token')
        ) {
            return FastPathErrorLedgerEntry::FAILURE_MODE_BAD_REPAIR;
        }
        if (str_contains($err, 'class not found') || str_contains($err, 'no such file')
            || str_contains($err, 'cannot find module') || str_contains($err, 'modulenotfounderror')
        ) {
            return FastPathErrorLedgerEntry::FAILURE_MODE_CONTEXT_ERROR;
        }
        if (str_contains($err, 'type error') || str_contains($err, 'typeerror')
            || str_contains($err, 'expected type') || str_contains($err, 'type mismatch')
        ) {
            return FastPathErrorLedgerEntry::FAILURE_MODE_BAD_REPAIR;
        }
        if (str_contains($err, 'permission denied') || str_contains($err, 'unauthorized')
            || str_contains($err, '403') || str_contains($err, '401')
        ) {
            return FastPathErrorLedgerEntry::FAILURE_MODE_WRONG_SCOPE;
        }
        if ($failingTest !== null) {
            // If we have a failing test but the error is generic, it's most
            // likely a missed/changed assertion or scope.
            return $changedFiles === []
                ? FastPathErrorLedgerEntry::FAILURE_MODE_MISSED_TEST
                : FastPathErrorLedgerEntry::FAILURE_MODE_WRONG_FILE;
        }
        if ($exitCode !== null && $exitCode !== 0 && $changedFiles === []) {
            return FastPathErrorLedgerEntry::FAILURE_MODE_MISSED_TEST;
        }

        return FastPathErrorLedgerEntry::FAILURE_MODE_OTHER;
    }

    /**
     * @param  list<string>  $validationCommands
     * @param  list<string>  $changedFiles
     * @return list<string>
     */
    private function buildReproductionPlan(array $validationCommands, ?string $command, ?string $failingTest, array $changedFiles): array
    {
        $plan = [];
        if ($command !== null && $command !== '') {
            $plan[] = "1. Re-run the originating command: `{$command}`";
        }
        foreach ($validationCommands as $i => $cmd) {
            $plan[] = sprintf('%d. Re-run validation command: `%s`', count($plan) + 1, $cmd);
        }
        if ($failingTest !== null && $failingTest !== '') {
            $plan[] = sprintf('%d. Isolate the failing test: `%s`', count($plan) + 1, $failingTest);
        }
        if ($changedFiles !== []) {
            $sample = array_slice($changedFiles, 0, 3);
            $plan[] = sprintf(
                '%d. Inspect changed files involved in the failure: %s',
                count($plan) + 1,
                implode(', ', array_map(static fn (string $f): string => "`{$f}`", $sample)),
            );
        }
        if ($plan === []) {
            $plan[] = '1. Re-run the originating gate to capture a richer error trace.';
        }

        return $plan;
    }

    /**
     * @param  list<string>  $changedFiles
     * @return list<DebugSuspectedCause>
     */
    private function generateSuspectedCauses(string $classification, string $primaryError, ?string $failingTest, array $changedFiles): array
    {
        $err = strtolower($primaryError);
        $causes = [];

        // Primary hypothesis derived from classification.
        $causes[] = new DebugSuspectedCause(
            category: $this->classificationToCauseCategory($classification),
            why: "Failure mode {$classification} matches the error signature; primary suspect for this surface.",
            confidence: 0.70,
            fileHint: $changedFiles[0] ?? null,
            lineHint: $this->extractLineHint($primaryError),
            symbolHint: $this->extractSymbolHint($primaryError),
            evidenceRefKinds: ['test_log'],
        );

        // Secondary hypotheses keyed on common keyword shapes.
        if (str_contains($err, 'null') || str_contains($err, 'undefined')) {
            $causes[] = new DebugSuspectedCause(
                category: DebugSuspectedCause::CATEGORY_NULL_OR_MISSING,
                why: 'Error excerpt mentions null/undefined reference; likely missing initialisation or guard.',
                confidence: 0.55,
                evidenceRefKinds: ['test_log'],
            );
        }
        if (str_contains($err, 'type')) {
            $causes[] = new DebugSuspectedCause(
                category: DebugSuspectedCause::CATEGORY_TYPE_MISMATCH,
                why: 'Error excerpt mentions type mismatch; check parameter and return types.',
                confidence: 0.45,
                evidenceRefKinds: ['test_log'],
            );
        }
        if (str_contains($err, 'permission') || str_contains($err, 'unauthor')
            || str_contains($err, '401') || str_contains($err, '403')
        ) {
            $causes[] = new DebugSuspectedCause(
                category: DebugSuspectedCause::CATEGORY_AUTH_PERMISSION,
                why: 'Error excerpt mentions permission/auth; check policy gate or token scope.',
                confidence: 0.50,
                evidenceRefKinds: ['test_log'],
            );
        }
        if (str_contains($err, 'config') || str_contains($err, 'env')
            || str_contains($err, 'not found in configuration')
        ) {
            $causes[] = new DebugSuspectedCause(
                category: DebugSuspectedCause::CATEGORY_CONFIG,
                why: 'Error excerpt mentions configuration/env; check missing keys.',
                confidence: 0.45,
                evidenceRefKinds: ['test_log'],
            );
        }
        if ($failingTest !== null && $changedFiles === []) {
            $causes[] = new DebugSuspectedCause(
                category: DebugSuspectedCause::CATEGORY_MISSED_TEST,
                why: 'Test failure with no diff suggests the previous attempt did not produce a patch.',
                confidence: 0.50,
                evidenceRefKinds: ['test_log'],
            );
        }

        // Cap to a workable set, ordered by confidence descending.
        usort($causes, static fn (DebugSuspectedCause $a, DebugSuspectedCause $b): int => $b->confidence <=> $a->confidence);

        return array_slice($causes, 0, 5);
    }

    /**
     * @param  list<DebugSuspectedCause>  $causes
     * @param  list<string>  $changedFiles
     * @param  list<string>  $validationCommands
     * @return list<DebugRepairCandidate>
     */
    private function generateRepairCandidates(string $classification, array $causes, array $changedFiles, array $validationCommands): array
    {
        $defaultValidation = $validationCommands === [] ? ['php artisan test'] : $validationCommands;
        $candidates = [];
        $seenStrategies = [];

        foreach ($causes as $cause) {
            $strategy = $this->causeToStrategy($cause->category);
            if (isset($seenStrategies[$strategy])) {
                continue;
            }
            $seenStrategies[$strategy] = true;
            $confidence = max(0.20, min(0.95, $cause->confidence + 0.10));

            $candidates[] = new DebugRepairCandidate(
                strategy: $strategy,
                summary: $this->strategySummary($strategy, $cause),
                targetFiles: $this->targetFilesFor($strategy, $cause, $changedFiles),
                expectedOutcome: $this->expectedOutcomeFor($strategy),
                validationCommands: $this->validationCommandsFor($strategy, $defaultValidation),
                confidence: $confidence,
                linkedCauseCategory: $cause->category,
            );

            if (count($candidates) >= self::MAX_REPAIR_CANDIDATES) {
                break;
            }
        }

        if ($candidates === []) {
            // Fallback minimal candidate so a `diagnosed` receipt is never
            // emitted without a path forward.
            $candidates[] = new DebugRepairCandidate(
                strategy: DebugRepairCandidate::STRATEGY_AWAIT_INPUT,
                summary: 'No actionable repair candidate could be derived; operator input required.',
                targetFiles: [],
                expectedOutcome: 'Operator clarifies scope or provides richer error context.',
                validationCommands: [],
                confidence: 0.20,
                linkedCauseCategory: null,
            );
        }

        return $candidates;
    }

    /**
     * @param  list<string>  $priorFailureSignatures
     * @param  list<DebugSuspectedCause>  $causes
     * @return list<string>
     */
    private function detectStopConditions(
        string $fingerprint,
        array $priorFailureSignatures,
        int $attemptIndex,
        int $attemptBudget,
        int $diffSizeLines,
        int $priorDiffSizeLines,
        array $causes,
        bool $humanReviewRequested,
    ): array {
        $stops = [];

        if (in_array($fingerprint, $priorFailureSignatures, true)) {
            $stops[] = DebugReceipt::STOP_SAME_SIGNATURE_TWICE;
        }
        if ($attemptIndex >= $attemptBudget) {
            $stops[] = DebugReceipt::STOP_ATTEMPT_BUDGET_EXCEEDED;
        }
        // Diff growing 1.5x without progress is a strong signal that the
        // model is over-editing instead of converging.
        if ($priorDiffSizeLines > 0 && $diffSizeLines > (int) ($priorDiffSizeLines * 1.5)) {
            $stops[] = DebugReceipt::STOP_DIFF_GROWTH_WITHOUT_PROGRESS;
        }
        if ($humanReviewRequested) {
            $stops[] = DebugReceipt::STOP_HUMAN_REVIEW_REQUESTED;
        }
        // Ambiguity: multiple causes within 0.05 confidence of the top.
        if (count($causes) >= 2 && abs($causes[0]->confidence - $causes[1]->confidence) < 0.05) {
            $stops[] = DebugReceipt::STOP_AMBIGUOUS_CAUSES;
        }

        return AtlasDevStringListNormalizer::uniqueTrimmedStrings($stops);
    }

    /**
     * @param  list<DebugSuspectedCause>  $causes
     * @param  list<DebugRepairCandidate>  $candidates
     * @param  list<string>  $stopConditions
     */
    private function computeConfidence(array $causes, array $candidates, array $stopConditions, string $classification): float
    {
        $base = $causes === [] ? 0.10 : $causes[0]->confidence;
        if (in_array($classification, [FastPathErrorLedgerEntry::FAILURE_MODE_OTHER], true)) {
            $base -= 0.10;
        }
        if (count($causes) >= 2) {
            $base += 0.05;
        }
        if ($candidates !== [] && $candidates[0]->strategy !== DebugRepairCandidate::STRATEGY_AWAIT_INPUT) {
            $base += 0.05;
        }
        if (in_array(DebugReceipt::STOP_AMBIGUOUS_CAUSES, $stopConditions, true)) {
            $base -= 0.10;
        }
        if (in_array(DebugReceipt::STOP_SAME_SIGNATURE_TWICE, $stopConditions, true)) {
            $base -= 0.15;
        }

        return max(0.0, min(1.0, round($base, 4)));
    }

    /**
     * @param  list<DebugRepairCandidate>  $candidates
     * @param  list<DebugSuspectedCause>  $causes
     * @param  list<string>  $stopConditions
     */
    private function decideStatus(array $candidates, array $causes, array $stopConditions, float $confidence): string
    {
        if (in_array(DebugReceipt::STOP_SAME_SIGNATURE_TWICE, $stopConditions, true)
            || in_array(DebugReceipt::STOP_ATTEMPT_BUDGET_EXCEEDED, $stopConditions, true)
            || in_array(DebugReceipt::STOP_HUMAN_REVIEW_REQUESTED, $stopConditions, true)
            || in_array(DebugReceipt::STOP_DIFF_GROWTH_WITHOUT_PROGRESS, $stopConditions, true)
        ) {
            return DebugReceipt::STATUS_ESCALATE;
        }
        if ($causes === []) {
            return DebugReceipt::STATUS_INCONCLUSIVE;
        }
        if (count($candidates) === 1 && $candidates[0]->strategy === DebugRepairCandidate::STRATEGY_AWAIT_INPUT) {
            return DebugReceipt::STATUS_INCONCLUSIVE;
        }
        if ($confidence < self::DIAGNOSIS_CONFIDENCE_FLOOR) {
            return DebugReceipt::STATUS_INCONCLUSIVE;
        }

        return DebugReceipt::STATUS_DIAGNOSED;
    }

    private function classificationToCauseCategory(string $classification): string
    {
        return match ($classification) {
            FastPathErrorLedgerEntry::FAILURE_MODE_WRONG_FILE => DebugSuspectedCause::CATEGORY_WRONG_FILE,
            FastPathErrorLedgerEntry::FAILURE_MODE_WRONG_SCOPE => DebugSuspectedCause::CATEGORY_WRONG_SCOPE,
            FastPathErrorLedgerEntry::FAILURE_MODE_MISSED_TEST => DebugSuspectedCause::CATEGORY_MISSED_TEST,
            FastPathErrorLedgerEntry::FAILURE_MODE_BAD_REPAIR => DebugSuspectedCause::CATEGORY_BAD_REPAIR,
            FastPathErrorLedgerEntry::FAILURE_MODE_PROMPT_PROJECTION_ERROR => DebugSuspectedCause::CATEGORY_PROMPT_PROJECTION_ERROR,
            FastPathErrorLedgerEntry::FAILURE_MODE_CONTEXT_ERROR => DebugSuspectedCause::CATEGORY_CONTEXT_ERROR,
            default => DebugSuspectedCause::CATEGORY_OTHER,
        };
    }

    private function causeToStrategy(string $causeCategory): string
    {
        return match ($causeCategory) {
            DebugSuspectedCause::CATEGORY_MISSED_TEST => DebugRepairCandidate::STRATEGY_ADD_TEST,
            DebugSuspectedCause::CATEGORY_BAD_REPAIR => DebugRepairCandidate::STRATEGY_REVERT,
            DebugSuspectedCause::CATEGORY_WRONG_SCOPE => DebugRepairCandidate::STRATEGY_RESCOPE,
            DebugSuspectedCause::CATEGORY_FALSE_ESCALATION,
            DebugSuspectedCause::CATEGORY_MISSED_ESCALATION => DebugRepairCandidate::STRATEGY_ESCALATE,
            DebugSuspectedCause::CATEGORY_PROMPT_PROJECTION_ERROR,
            DebugSuspectedCause::CATEGORY_CONTEXT_ERROR => DebugRepairCandidate::STRATEGY_AWAIT_INPUT,
            default => DebugRepairCandidate::STRATEGY_FIX_AND_TEST,
        };
    }

    private function strategySummary(string $strategy, DebugSuspectedCause $cause): string
    {
        return match ($strategy) {
            DebugRepairCandidate::STRATEGY_APPLY_PATCH => "Apply targeted patch addressing {$cause->category}.",
            DebugRepairCandidate::STRATEGY_ADD_TEST => "Add a focused test that reproduces the {$cause->category} surface before patching.",
            DebugRepairCandidate::STRATEGY_FIX_AND_TEST => "Patch the suspect surface ({$cause->category}) and add a regression test.",
            DebugRepairCandidate::STRATEGY_REVERT => 'Revert the last failed patch and restart from a clean baseline.',
            DebugRepairCandidate::STRATEGY_RESCOPE => 'Re-scope the task: current scope diverges from the failure surface.',
            DebugRepairCandidate::STRATEGY_ESCALATE => 'Escalate to operator: signals suggest scope outgrew Atlas Dev.',
            DebugRepairCandidate::STRATEGY_AWAIT_INPUT => 'Await operator input — context insufficient or projection error.',
            DebugRepairCandidate::STRATEGY_NO_PATCH_NEEDED => 'No patch needed; failure surface unrelated to changed code.',
            default => 'Investigate further.',
        };
    }

    /**
     * @param  list<string>  $changedFiles
     * @return list<string>
     */
    private function targetFilesFor(string $strategy, DebugSuspectedCause $cause, array $changedFiles): array
    {
        if ($cause->fileHint !== null) {
            return [$cause->fileHint];
        }
        if ($strategy === DebugRepairCandidate::STRATEGY_REVERT
            || $strategy === DebugRepairCandidate::STRATEGY_FIX_AND_TEST
            || $strategy === DebugRepairCandidate::STRATEGY_APPLY_PATCH
        ) {
            return $changedFiles;
        }

        return [];
    }

    private function expectedOutcomeFor(string $strategy): string
    {
        return match ($strategy) {
            DebugRepairCandidate::STRATEGY_APPLY_PATCH => 'Patch applies cleanly, validation commands pass, test suite remains green.',
            DebugRepairCandidate::STRATEGY_ADD_TEST => 'New test reproduces the failure on baseline and passes after the fix.',
            DebugRepairCandidate::STRATEGY_FIX_AND_TEST => 'Patch closes the failure mode and the new test fails before, passes after.',
            DebugRepairCandidate::STRATEGY_REVERT => 'Workspace returns to baseline; failure no longer reproduces.',
            DebugRepairCandidate::STRATEGY_RESCOPE => 'Operator confirms new scope; original failure becomes out-of-scope.',
            DebugRepairCandidate::STRATEGY_ESCALATE => 'Forge intake accepts the handoff with reproduction plan.',
            DebugRepairCandidate::STRATEGY_AWAIT_INPUT => 'Operator supplies additional context (error log, intended scope).',
            DebugRepairCandidate::STRATEGY_NO_PATCH_NEEDED => 'Failure attributed to external factor; no code change applied.',
            default => 'Failure resolved or escalation produced.',
        };
    }

    /**
     * @param  list<string>  $defaultValidation
     * @return list<string>
     */
    private function validationCommandsFor(string $strategy, array $defaultValidation): array
    {
        // Strategies that don't mutate the workspace don't need validation.
        if (in_array($strategy, [
            DebugRepairCandidate::STRATEGY_ESCALATE,
            DebugRepairCandidate::STRATEGY_AWAIT_INPUT,
            DebugRepairCandidate::STRATEGY_NO_PATCH_NEEDED,
            DebugRepairCandidate::STRATEGY_RESCOPE,
        ], true)) {
            return [];
        }

        return $defaultValidation;
    }

    private function extractLineHint(string $primaryError): ?int
    {
        if (preg_match('/(?:line|on line|:)(\s*)(\d+)/i', $primaryError, $m) === 1) {
            $candidate = (int) $m[2];

            return $candidate >= 1 ? $candidate : null;
        }

        return null;
    }

    private function extractSymbolHint(string $primaryError): ?string
    {
        if (preg_match('/(?:method|function|class|property|variable)\s+["\']?([A-Za-z_][A-Za-z0-9_\\\\]+)["\']?/i', $primaryError, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function stringOrThrow(array $input, string $key, bool $allowEmpty = true): string
    {
        if (! array_key_exists($key, $input)) {
            throw new \InvalidArgumentException("DebugIntelligenceService: missing required field '{$key}'.");
        }
        $value = $input[$key];
        if (! is_string($value)) {
            throw new \InvalidArgumentException("DebugIntelligenceService: field '{$key}' must be a string.");
        }
        if (! $allowEmpty && trim($value) === '') {
            throw new \InvalidArgumentException("DebugIntelligenceService: field '{$key}' must not be empty.");
        }

        return $value;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function nullableTrim(array $input, string $key): ?string
    {
        if (! array_key_exists($key, $input) || $input[$key] === null) {
            return null;
        }
        $value = (string) $input[$key];
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

}
