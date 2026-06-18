<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Intelligence;

use App\Services\Ai\Programming\AtlasDev\Probe\IntentFalsificationProbe;
use App\Services\Ai\Programming\AtlasDev\Provider\DiffParseResult;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ProviderLock;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\RepairPolicy;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ReviewFinding;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\ReviewReceipt;
use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevStringListNormalizer;
use Illuminate\Support\Str;

/**
 * Atlas Dev Review Intelligence — first-version-strong implementation.
 *
 * Replaces "loose code review" with a structured pipeline that:
 *
 *   ingest diff/changed_files/test_paths/risk_rules
 *     → detect missing_tests
 *     → detect security/regression/performance/data-loss risks via heuristics
 *     → detect scope_violation against allowed_files
 *     → detect secret leaks
 *     → emit findings (severity × risk_type × file/line × remediation × confidence)
 *     → sort by severity ascending (blocker first)
 *     → emit ReviewReceipt
 *
 * The service is stateless and pure. It does NOT execute provider calls, run
 * tests, or apply patches. It only produces the structured ReviewReceipt the
 * operator (and downstream loops) consume.
 *
 * INSUFFICIENT CONTEXT → BLOCKER. When the input lacks `changed_files` AND
 * `diff_chunks` AND `test_paths`, the receipt is sealed with
 * `status=blocked_insufficient_context` + `blocker_reasons[]` — never a
 * silent "no concerns" verdict.
 */
final class ReviewIntelligenceService
{
    /** Maximum findings emitted per receipt — keeps the artefact reviewable. */
    public const MAX_FINDINGS = 20;

    /** Heuristic keywords that escalate severity when present in a hunk. */
    public const SECURITY_KEYWORDS = [
        'password', 'token', 'secret', 'apikey', 'api_key', 'private_key',
        'authorization', 'jwt', 'sha256(',
    ];

    public const DATA_LOSS_KEYWORDS = [
        'drop table', 'delete from', 'truncate', 'rm -rf', 'unlink(',
        'destroy()', 'forcedelete', 'force_delete',
    ];

    public const PERFORMANCE_KEYWORDS = [
        'while (true)', 'while(true)', 'sleep(', 'select * from',
        'foreach ($', 'n+1',
    ];

    public const SECRET_LEAK_FILE_PATTERNS = [
        '.env', 'id_rsa', 'id_dsa', 'credentials.json', 'service_account',
    ];

    /**
     * @param  array<string,mixed>  $input
     * @param  array{llm_judge?: bool, judge?: ?callable}  $options  E1
     *                                                               semantic-critic options. `llm_judge` enables the optional
     *                                                               LLM-as-judge adversarial sub-layer (default off); `judge` is the
     *                                                               callable invoked when the sub-flag is on. The judge receives an
     *                                                               array context and MUST return an IntentJudgeOutcome (or null,
     *                                                               treated as silent). The judge can only ADD doubt/escalate — it
     *                                                               can never remove the deterministic probe's finding or downgrade
     *                                                               severity (VAL-E1-011).
     */
    public function analyse(array $input, array $options = []): ReviewReceipt
    {
        $runId = $this->stringOrThrow($input, 'run_id');
        $changedFiles = AtlasDevStringListNormalizer::trimmedStrings($input['changed_files'] ?? []);
        $diffChunks = $this->normaliseDiffChunks($input['diff_chunks'] ?? []);
        $testPaths = AtlasDevStringListNormalizer::trimmedStrings($input['test_paths'] ?? []);
        $allowedFiles = AtlasDevStringListNormalizer::trimmedStrings($input['allowed_files'] ?? []);
        $forbiddenFiles = AtlasDevStringListNormalizer::trimmedStrings($input['forbidden_files'] ?? []);
        $riskRules = $this->normaliseRiskRules($input['risk_rules'] ?? []);
        $evidenceRefs = AtlasDevStringListNormalizer::trimmedStrings($input['evidence_refs'] ?? []);
        $createdAt = (string) ($input['created_at'] ?? now()->toIso8601String());
        $receiptId = (string) ($input['receipt_id'] ?? 'rev_'.Str::uuid());

        // --- 1. Insufficient context gate ----------------------------------
        $blockerReasons = $this->detectInsufficientContext($changedFiles, $diffChunks, $testPaths);
        if ($blockerReasons !== []) {
            return ReviewReceipt::issue(
                receiptId: $receiptId,
                runId: $runId,
                status: ReviewReceipt::STATUS_BLOCKED_INSUFFICIENT_CONTEXT,
                reviewedFiles: $changedFiles,
                findings: [],
                missingTestsCount: 0,
                confidence: 0.0,
                evidenceRefs: $evidenceRefs,
                blockerReasons: $blockerReasons,
                createdAt: $createdAt,
            );
        }

        // --- 2. Gather findings -------------------------------------------
        $findings = [];

        // 2a. Missing tests — every changed code file without a matching test
        // surfaces a test_gap finding (severity high if security-sensitive,
        // else medium).
        [$missingTestsCount, $missingTestFindings] = $this->detectMissingTests($changedFiles, $testPaths, $diffChunks);
        $findings = array_merge($findings, $missingTestFindings);

        // 2b. Secret/leak surface — flag any committed file that looks like
        // a secret artefact, plus any hunk that embeds a sensitive keyword.
        $findings = array_merge($findings, $this->detectSecretLeaks($changedFiles, $diffChunks));

        // 2c. Security/data-loss/performance/regression heuristics over hunks.
        $findings = array_merge($findings, $this->detectRiskKeywords($diffChunks));

        // 2d. Scope violation — any changed file outside `allowed_files` OR
        // intersecting `forbidden_files`.
        $findings = array_merge($findings, $this->detectScopeViolations($changedFiles, $allowedFiles, $forbiddenFiles));

        // 2e. Risk-rule findings: operator-supplied rules expressed as
        // {risk_type, severity, file_glob, message}. Matches go through
        // verbatim with the supplied severity.
        $findings = array_merge($findings, $this->applyRiskRules($changedFiles, $diffChunks, $riskRules));

        // 2f. E1 — Intent-falsification detector (semantic critic). Strictly
        // adversarial: it may emit a finding, escalate, or stay silent — it
        // NEVER clears an existing flag (the honesty-flag channel lives on
        // VerificationGateResult, which the critic has no reference to) and
        // it NEVER upgrades completion to passed (the critic returns a
        // ReviewReceipt; "passed"/"approved" are not valid receipt statuses).
        // VAL-E1-009: emits a finding (riskType bug/reliability) referencing
        // the unaddressed intent. VAL-E1-010: doubt-additive only.
        // VAL-E1-011: the optional LLM-judge sub-layer (sub-flag) can only
        // add doubt/escalate; APPROVE/DOWNGRADE outcomes are ignored.
        $findings = array_merge(
            $findings,
            $this->detectIntentFalsification($input, $options, $diffChunks),
        );

        // --- 3. Cap + sort by severity ascending ---------------------------
        usort($findings, static fn (ReviewFinding $a, ReviewFinding $b): int => $a->severityRank() <=> $b->severityRank());
        $findings = array_slice($findings, 0, self::MAX_FINDINGS);

        // --- 4. Status ----------------------------------------------------
        $status = $this->decideStatus($findings, $changedFiles);

        // --- 5. Confidence -------------------------------------------------
        $confidence = $this->computeConfidence($findings, $changedFiles, $diffChunks, $testPaths);

        return ReviewReceipt::issue(
            receiptId: $receiptId,
            runId: $runId,
            status: $status,
            reviewedFiles: $changedFiles,
            findings: $findings,
            missingTestsCount: $missingTestsCount,
            confidence: $confidence,
            evidenceRefs: $evidenceRefs,
            blockerReasons: [],
            createdAt: $createdAt,
        );
    }

    /**
     * @param  list<string>  $changedFiles
     * @param  list<array<string,mixed>>  $diffChunks
     * @param  list<string>  $testPaths
     * @return list<string>
     */
    private function detectInsufficientContext(array $changedFiles, array $diffChunks, array $testPaths): array
    {
        $reasons = [];
        if ($changedFiles === [] && $diffChunks === []) {
            $reasons[] = 'missing_changed_files_and_diff';
        }

        return $reasons;
    }

    /**
     * @param  list<string>  $changedFiles
     * @param  list<string>  $testPaths
     * @param  list<array<string,mixed>>  $diffChunks
     * @return array{0:int,1:list<ReviewFinding>}
     */
    private function detectMissingTests(array $changedFiles, array $testPaths, array $diffChunks): array
    {
        $codeFiles = array_values(array_filter(
            $changedFiles,
            static fn (string $f): bool => ! self::looksLikeTestFile($f) && self::looksLikeCodeFile($f),
        ));
        $tests = AtlasDevStringListNormalizer::uniqueMergedStrings(
            $testPaths,
            array_filter($changedFiles, self::looksLikeTestFile(...)),
        );
        $missing = [];
        foreach ($codeFiles as $file) {
            if (! self::hasMatchingTest($file, $tests)) {
                $missing[] = $file;
            }
        }

        $findings = [];
        foreach ($missing as $i => $file) {
            $hunkMentionsSecurity = $this->fileTouchesKeywords($file, $diffChunks, self::SECURITY_KEYWORDS);
            $severity = $hunkMentionsSecurity ? ReviewFinding::SEVERITY_HIGH : ReviewFinding::SEVERITY_MEDIUM;
            $findings[] = new ReviewFinding(
                findingId: 'test_gap_'.($i + 1),
                title: "Missing test for {$file}",
                severity: $severity,
                riskType: ReviewFinding::RISK_TEST_GAP,
                description: "Changed file `{$file}` has no matching test path in this diff or test_paths.",
                remediation: 'Add a focused test exercising the changed behaviour before merging.',
                confidence: 0.75,
                file: $file,
                testGap: true,
                evidenceRefKinds: ['diff'],
            );
        }

        return [count($missing), $findings];
    }

    /**
     * @param  list<string>  $changedFiles
     * @param  list<array<string,mixed>>  $diffChunks
     * @return list<ReviewFinding>
     */
    private function detectSecretLeaks(array $changedFiles, array $diffChunks): array
    {
        $findings = [];
        $counter = 0;
        foreach ($changedFiles as $file) {
            $lc = strtolower($file);
            foreach (self::SECRET_LEAK_FILE_PATTERNS as $pattern) {
                if (str_contains($lc, $pattern)) {
                    $counter++;
                    $findings[] = new ReviewFinding(
                        findingId: 'secret_leak_'.$counter,
                        title: "Possible secret file committed: {$file}",
                        severity: ReviewFinding::SEVERITY_BLOCKER,
                        riskType: ReviewFinding::RISK_SECRET_LEAK,
                        description: "File `{$file}` matches a known secret artefact pattern (`{$pattern}`). Committing it can leak credentials.",
                        remediation: 'Remove the file from the commit, rotate the credential if real, and add the pattern to .gitignore.',
                        confidence: 0.90,
                        file: $file,
                        evidenceRefKinds: ['diff'],
                    );
                    break;
                }
            }
        }
        foreach ($diffChunks as $hunk) {
            $body = strtolower((string) ($hunk['body'] ?? ''));
            foreach (['"password":', "'password' =>", 'aws_secret', 'private_key='] as $needle) {
                if (str_contains($body, $needle)) {
                    $counter++;
                    $findings[] = new ReviewFinding(
                        findingId: 'secret_leak_'.$counter,
                        title: 'Hardcoded secret-like literal in diff',
                        severity: ReviewFinding::SEVERITY_CRITICAL,
                        riskType: ReviewFinding::RISK_SECRET_LEAK,
                        description: "Diff hunk in `{$hunk['file']}` contains a literal matching `{$needle}`.",
                        remediation: 'Replace the literal with a config/env reference and rotate the credential.',
                        confidence: 0.65,
                        file: (string) ($hunk['file'] ?? null) ?: null,
                        line: isset($hunk['line']) && is_int($hunk['line']) ? $hunk['line'] : null,
                        evidenceRefKinds: ['diff'],
                    );
                    break;
                }
            }
        }

        return $findings;
    }

    /**
     * @param  list<array<string,mixed>>  $diffChunks
     * @return list<ReviewFinding>
     */
    private function detectRiskKeywords(array $diffChunks): array
    {
        $findings = [];
        $counter = 0;
        foreach ($diffChunks as $hunk) {
            $body = strtolower((string) ($hunk['body'] ?? ''));
            $file = (string) ($hunk['file'] ?? '');
            $line = isset($hunk['line']) && is_int($hunk['line']) ? $hunk['line'] : null;

            foreach (self::DATA_LOSS_KEYWORDS as $needle) {
                if (str_contains($body, $needle)) {
                    $counter++;
                    $findings[] = new ReviewFinding(
                        findingId: 'data_loss_'.$counter,
                        title: "Potential data-loss operation: `{$needle}`",
                        severity: ReviewFinding::SEVERITY_CRITICAL,
                        riskType: ReviewFinding::RISK_DATA_LOSS,
                        description: "Diff hunk introduces `{$needle}` which can destroy data when applied.",
                        remediation: 'Wrap the operation behind an explicit confirmation gate or migration with a documented rollback.',
                        confidence: 0.70,
                        file: $file !== '' ? $file : null,
                        line: $line,
                        evidenceRefKinds: ['diff'],
                    );
                    break;
                }
            }
            foreach (self::PERFORMANCE_KEYWORDS as $needle) {
                if (str_contains($body, $needle)) {
                    $counter++;
                    $findings[] = new ReviewFinding(
                        findingId: 'perf_'.$counter,
                        title: "Potential performance concern: `{$needle}`",
                        severity: ReviewFinding::SEVERITY_MEDIUM,
                        riskType: ReviewFinding::RISK_PERFORMANCE,
                        description: "Diff hunk introduces `{$needle}` which can degrade latency/throughput.",
                        remediation: 'Bound the loop, add pagination/index, or switch to a batched query.',
                        confidence: 0.55,
                        file: $file !== '' ? $file : null,
                        line: $line,
                        evidenceRefKinds: ['diff'],
                    );
                    break;
                }
            }
            foreach (self::SECURITY_KEYWORDS as $needle) {
                if (str_contains($body, $needle) && (str_contains($body, 'public ') || str_contains($body, 'return '))) {
                    $counter++;
                    $findings[] = new ReviewFinding(
                        findingId: 'sec_'.$counter,
                        title: "Security-sensitive symbol exposed: `{$needle}`",
                        severity: ReviewFinding::SEVERITY_HIGH,
                        riskType: ReviewFinding::RISK_SECURITY,
                        description: "Diff hunk touches `{$needle}` near a `public`/`return` clause. Validate the symbol is not leaked through API or logs.",
                        remediation: 'Mask the symbol in logs, restrict the endpoint, or add a policy gate.',
                        confidence: 0.45,
                        file: $file !== '' ? $file : null,
                        line: $line,
                        evidenceRefKinds: ['diff'],
                    );
                    break;
                }
            }
        }

        return $findings;
    }

    /**
     * @param  list<string>  $changedFiles
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $forbiddenFiles
     * @return list<ReviewFinding>
     */
    private function detectScopeViolations(array $changedFiles, array $allowedFiles, array $forbiddenFiles): array
    {
        $findings = [];
        $counter = 0;
        foreach ($changedFiles as $file) {
            if ($forbiddenFiles !== [] && $this->matchesAny($file, $forbiddenFiles)) {
                $counter++;
                $findings[] = new ReviewFinding(
                    findingId: 'scope_violation_'.$counter,
                    title: "Forbidden file modified: {$file}",
                    severity: ReviewFinding::SEVERITY_BLOCKER,
                    riskType: ReviewFinding::RISK_SCOPE_VIOLATION,
                    description: "Diff touches `{$file}` which is in `forbidden_files`.",
                    remediation: 'Remove the change from this commit; the file is outside the task contract.',
                    confidence: 0.95,
                    file: $file,
                    evidenceRefKinds: ['diff'],
                );

                continue;
            }
            if ($allowedFiles !== [] && ! $this->matchesAny($file, $allowedFiles)) {
                $counter++;
                $findings[] = new ReviewFinding(
                    findingId: 'scope_drift_'.$counter,
                    title: "Out-of-scope file modified: {$file}",
                    severity: ReviewFinding::SEVERITY_HIGH,
                    riskType: ReviewFinding::RISK_SCOPE_VIOLATION,
                    description: "Diff touches `{$file}` which is not in the task contract's `allowed_files`.",
                    remediation: 'Either widen the task contract via an operator decision, or remove the change.',
                    confidence: 0.85,
                    file: $file,
                    evidenceRefKinds: ['diff'],
                );
            }
        }

        return $findings;
    }

    /**
     * @param  list<string>  $changedFiles
     * @param  list<array<string,mixed>>  $diffChunks
     * @param  list<array<string,mixed>>  $riskRules
     * @return list<ReviewFinding>
     */
    private function applyRiskRules(array $changedFiles, array $diffChunks, array $riskRules): array
    {
        $findings = [];
        $counter = 0;
        foreach ($riskRules as $rule) {
            $riskType = (string) ($rule['risk_type'] ?? '');
            $severity = (string) ($rule['severity'] ?? '');
            $glob = (string) ($rule['file_glob'] ?? '');
            $message = (string) ($rule['message'] ?? '');
            if ($riskType === '' || $severity === '' || $message === '') {
                continue;
            }
            foreach ($changedFiles as $file) {
                if ($glob !== '' && ! fnmatch($glob, $file)) {
                    continue;
                }
                $counter++;
                $findings[] = new ReviewFinding(
                    findingId: 'rule_'.$counter,
                    title: 'Operator risk rule matched: '.$file,
                    severity: $severity,
                    riskType: $riskType,
                    description: $message.' (matched `'.$file.'`)',
                    remediation: (string) ($rule['remediation'] ?? 'Resolve per operator risk-rule guidance.'),
                    confidence: 0.80,
                    file: $file,
                    evidenceRefKinds: ['diff'],
                );
            }
        }

        return $findings;
    }

    /**
     * E1 — Intent-falsification semantic-critic detector.
     *
     * Strictly adversarial (VAL-E1-010): it may emit a finding, escalate, or
     * stay silent — it NEVER clears an existing flag and NEVER upgrades a
     * completion to passed. By construction it only RETURNS findings (which
     * the caller merges into the receipt's findings[]); the caller's receipt
     * status is decided by {@see decideStatus()} from the (possibly now-
     * augmented) findings, so the detector can only escalate STATUS_* upward
     * (no_concerns -> reviewed -> escalate), never the reverse.
     *
     * VAL-E1-009: a green-gate-but-misses-intent diff yields a finding with
     * riskType in {bug, reliability} and a non-empty description referencing
     * the unaddressed intent.
     *
     * The detector reuses {@see IntentFalsificationProbe} as the single
     * source of truth for the verb-matching heuristic so the critic and the
     * post-gate probe never disagree on what "implements the intent" means.
     * The basis is sourced from the optional `intent_basis` payload key
     * ({intent_verbs, intent_text, diff}) the executor passes via
     * buildCriticInput(). When the basis is absent the detector stays silent
     * (nothing to probe; byte-identical to pre-E1 critic for read-only paths).
     *
     * VAL-E1-011: the optional LLM-as-judge sub-layer (sub-flag
     * `atlas_dev.elevations.e1.llm_judge`) runs AFTER the deterministic
     * verdict. It can ONLY add doubt/escalate:
     *   - DOUBT     => records an additional judge-attributed finding.
     *   - ESCALATE  => escalates the deterministic finding's severity (or
     *                  records a critical/blocker judge finding).
     *   - APPROVE / DOWNGRADE / SILENT => IGNORED. The judge has no power to
     *                  clear the deterministic probe's flag or downgrade its
     *                  severity. A fake judge screaming APPROVE cannot remove
     *                  the finding.
     *
     * @param  array<string,mixed>  $input
     * @param  array{llm_judge?: bool, judge?: ?callable}  $options
     * @param  list<array<string,mixed>>  $diffChunks
     * @return list<ReviewFinding>
     */
    private function detectIntentFalsification(array $input, array $options, array $diffChunks): array
    {
        // Read the E2-established intent basis carried by the executor.
        // Absent basis => nothing to probe => stay silent (byte-identical to
        // pre-E1 critic for read-only / non-write paths).
        $basis = $input['intent_basis'] ?? null;
        if (! is_array($basis)) {
            return [];
        }

        $intentVerbs = isset($basis['intent_verbs']) && is_array($basis['intent_verbs'])
            ? array_values(array_filter(
                array_map('strval', $basis['intent_verbs']),
                static fn (string $v): bool => trim($v) !== '',
            ))
            : [];
        $intentText = isset($basis['intent_text']) && is_string($basis['intent_text'])
            ? $basis['intent_text']
            : '';
        // No recognized verb => nothing to assert (the probe only fires for
        // write tasks carrying a recognized verb).
        if ($intentVerbs === []) {
            return [];
        }

        $diffResult = $this->resolveBasisDiffResult($basis, $diffChunks);

        // Build a throwaway contract carrying the E2 basis so the deterministic
        // probe (single source of truth) decides. The contract's structural
        // fields are not used by the probe; only intentVerbs/intentText are.
        $basisContract = new LightTaskContract(
            runId: (string) ($input['run_id'] ?? 'critic-e1'),
            taskId: 'critic-e1-intent-basis',
            specHash: '',
            allowedTools: [],
            blockedActions: [],
            allowedFiles: [],
            watchedFiles: [],
            forbiddenFiles: [],
            maxFilesChanged: 0,
            validationCommands: [],
            evidenceRequired: [],
            repairPolicy: new RepairPolicy(
                maxAttempts: 0,
                sameProvider: false,
                requiresFailedGateOutput: false,
                abortOnSameSignatureTwice: false,
            ),
            escalationOn: [],
            providerLock: new ProviderLock(
                provider: '',
                modelFamily: '',
                fallbackAllowed: false,
            ),
            taskContractHash: '',
            noTestReason: null,
            intentText: $intentText,
            intentVerbs: $intentVerbs,
        );

        $probe = new IntentFalsificationProbe;
        $intentMissing = $probe->isIntentLikelyNotAddressed($basisContract, $diffResult);

        $findings = [];
        if ($intentMissing) {
            // VAL-E1-009: emit a finding with riskType bug/reliability and a
            // non-empty description referencing the unaddressed intent.
            $verbList = implode(', ', $intentVerbs);
            $intentExcerpt = $this->excerptIntent($intentText);
            $findings[] = new ReviewFinding(
                findingId: 'intent_falsification_1',
                title: 'Intent likely not addressed by the diff',
                severity: ReviewFinding::SEVERITY_HIGH,
                riskType: ReviewFinding::RISK_BUG,
                description: $this->buildIntentFalsificationDescription($verbList, $intentExcerpt),
                remediation: 'Regenerate the diff so at least one added line implements the declared intent verb(s) ('.$verbList.'); the green gate does not prove the intent was addressed.',
                confidence: 0.70,
                evidenceRefKinds: ['diff', 'intent_basis'],
            );
        }

        // Optional LLM-as-judge sub-layer (sub-flag). Strictly doubt-additive.
        $judgeFindings = $this->applyIntentJudge(
            $options,
            intentVerbs: $intentVerbs,
            intentText: $intentText,
            deterministicMissing: $intentMissing,
            existingFindings: $findings,
        );

        return array_merge($findings, $judgeFindings);
    }

    /**
     * Resolve the DiffParseResult for the basis: prefer an explicit `diff`
     * string carried in the intent_basis (the executor threads the parsed
     * diff), else synthesize a patch from the diff_chunks so the probe still
     * scans real added lines. Returns a no-patch result when no diff is
     * available at all (the probe conservatively treats this as not-addressed
     * for a write task — VAL-E1-014).
     *
     * @param  array<string,mixed>  $basis
     * @param  list<array<string,mixed>>  $diffChunks
     */
    private function resolveBasisDiffResult(array $basis, array $diffChunks): ?DiffParseResult
    {
        $diff = isset($basis['diff']) && is_string($basis['diff']) ? $basis['diff'] : '';
        if ($diff !== '') {
            $changedFiles = array_values(array_filter(array_map(
                static fn (array $c): string => isset($c['file']) && is_string($c['file']) ? $c['file'] : '',
                $diffChunks,
            ), static fn (string $f): bool => $f !== ''));

            return DiffParseResult::patch($diff, $changedFiles !== [] ? $changedFiles : ['unknown']);
        }
        // No diff string — synthesize from diff_chunks if any carry bodies.
        $synthetic = '';
        foreach ($diffChunks as $chunk) {
            $file = isset($chunk['file']) && is_string($chunk['file']) ? $chunk['file'] : 'unknown';
            $body = isset($chunk['body']) && is_string($chunk['body']) ? $chunk['body'] : '';
            if ($body === '') {
                continue;
            }
            $synthetic .= "--- a/{$file}\n+++ b/{$file}\n";
            foreach (preg_split('/\r\n|\n|\r/', $body) ?: [] as $line) {
                if ($line === '') {
                    continue;
                }
                $marker = str_starts_with($line, '+') || str_starts_with($line, '-')
                    ? $line
                    : '+'.$line;
                $synthetic .= $marker."\n";
            }
        }
        if ($synthetic === '') {
            return null;
        }
        $changedFiles = array_values(array_unique(array_filter(array_map(
            static fn (array $c): string => isset($c['file']) && is_string($c['file']) ? $c['file'] : '',
            $diffChunks,
        ), static fn (string $f): bool => $f !== '')));

        return DiffParseResult::patch($synthetic, $changedFiles !== [] ? $changedFiles : ['unknown']);
    }

    /**
     * Build the human-readable description for the intent-falsification
     * finding, referencing the unaddressed intent verb(s) and an excerpt of
     * the intent text (VAL-E1-009: "referencing the unaddressed intent").
     */
    private function buildIntentFalsificationDescription(string $verbList, string $intentExcerpt): string
    {
        $core = "The diff does not traceably implement the declared intent verb(s) ({$verbList}); the green gate does not prove the intent was addressed.";
        if ($intentExcerpt !== '') {
            $core .= " Intent: \"{$intentExcerpt}\".";
        }

        return $core.' intent_likely_not_addressed.';
    }

    /**
     * Truncate the intent text to a reviewable excerpt for the finding
     * description (keeps the receipt readable when the intent is long).
     */
    private function excerptIntent(string $intentText, int $max = 160): string
    {
        $trimmed = trim($intentText);
        if ($trimmed === '') {
            return '';
        }
        if (mb_strlen($trimmed) <= $max) {
            return $trimmed;
        }

        return mb_substr($trimmed, 0, $max - 1).'…';
    }

    /**
     * E1 — Apply the optional LLM-as-judge adversarial sub-layer.
     *
     * VAL-E1-011: the judge can ONLY add doubt/escalate. It returns an
     * IntentJudgeOutcome; APPROVE / DOWNGRADE / SILENT outcomes are IGNORED
     * (the deterministic probe's verdict is the immovable floor — a fake
     * judge screaming APPROVE cannot clear it).
     *
     * When the judge returns DOUBT, an additional judge-attributed finding is
     * recorded. When the judge returns ESCALATE and the deterministic finding
     * is present, the deterministic finding's severity is RAISED (never
     * lowered) by emitting a new finding at the escalated severity; the
     * caller's sort by severityRank places the escalated finding first.
     *
     * @param  array{llm_judge?: bool, judge?: ?callable}  $options
     * @param  list<string>  $intentVerbs
     * @param  list<ReviewFinding>  $existingFindings
     * @return list<ReviewFinding>
     */
    private function applyIntentJudge(
        array $options,
        array $intentVerbs,
        string $intentText,
        bool $deterministicMissing,
        array $existingFindings,
    ): array {
        $enabled = isset($options['llm_judge']) && (bool) $options['llm_judge'];
        if (! $enabled) {
            return [];
        }
        $judge = $options['judge'] ?? null;
        if (! is_callable($judge)) {
            return [];
        }

        $context = [
            'intent_verbs' => array_values($intentVerbs),
            'intent_text' => $intentText,
            'deterministic_missing' => $deterministicMissing,
        ];

        try {
            $outcomeRaw = $judge($context);
        } catch (\Throwable $e) {
            $outcomeRaw = null;
        }
        if (! $outcomeRaw instanceof IntentJudgeOutcome) {
            return [];
        }

        // VAL-E1-011: only DOUBT/ESCALATE mutate the receipt. APPROVE /
        // DOWNGRADE / SILENT are ignored (deterministic verdict is the floor).
        if (! $outcomeRaw->isDoubtAdditive()) {
            return [];
        }

        $verbList = implode(', ', $intentVerbs);
        $note = $outcomeRaw->note !== null && trim($outcomeRaw->note) !== ''
            ? trim($outcomeRaw->note)
            : 'judge-attributed doubt';

        if ($outcomeRaw->kind === IntentJudgeOutcome::ESCALATE && $deterministicMissing) {
            // Escalate the deterministic finding to the judge-requested
            // severity (validated against ALLOWED_SEVERITIES; default critical).
            $severity = $this->resolveJudgeSeverity($outcomeRaw->escalateSeverity);

            return [
                new ReviewFinding(
                    findingId: 'intent_falsification_judge_escalate',
                    title: 'Judge escalated intent-falsification severity',
                    severity: $severity,
                    riskType: ReviewFinding::RISK_RELIABILITY,
                    description: "LLM-judge escalated the intent-falsification finding (verbs: {$verbList}); the diff does not traceably implement the intent. Judge note: {$note}",
                    remediation: 'Regenerate the diff so at least one added line implements the declared intent verb(s) ('.$verbList.'); treat the judge escalation as a strong signal the intent is unaddressed.',
                    confidence: 0.80,
                    evidenceRefKinds: ['diff', 'intent_basis', 'llm_judge'],
                ),
            ];
        }

        // DOUBT (or ESCALATE without a deterministic miss): record an
        // additional judge-attributed finding that adds doubt without clearing
        // the deterministic verdict.
        return [
            new ReviewFinding(
                findingId: 'intent_falsification_judge_doubt',
                title: 'Judge-attributed intent doubt',
                severity: ReviewFinding::SEVERITY_HIGH,
                riskType: ReviewFinding::RISK_RELIABILITY,
                description: "LLM-judge adds doubt over the intent coverage (verbs: {$verbList}); the diff may not fully implement the intent. Judge note: {$note}",
                remediation: 'Review the diff against the declared intent verb(s) ('.$verbList.') and address any uncovered aspect.',
                confidence: 0.65,
                evidenceRefKinds: ['diff', 'intent_basis', 'llm_judge'],
            ),
        ];
    }

    /**
     * Resolve the judge-requested escalate severity, defaulting to critical
     * and validating against ReviewFinding::ALLOWED_SEVERITIES. An invalid
     * severity collapses to critical (the safe default for an escalation).
     */
    private function resolveJudgeSeverity(?string $severity): string
    {
        if ($severity !== null && in_array($severity, ReviewFinding::ALLOWED_SEVERITIES, true)) {
            return $severity;
        }

        return ReviewFinding::SEVERITY_CRITICAL;
    }

    /**
     * @param  list<ReviewFinding>  $findings
     * @param  list<string>  $changedFiles
     */
    private function decideStatus(array $findings, array $changedFiles): string
    {
        if ($findings === []) {
            return ReviewReceipt::STATUS_NO_CONCERNS;
        }
        $highest = $findings[0]->severityRank();
        if ($highest <= ReviewFinding::SEVERITY_RANK[ReviewFinding::SEVERITY_CRITICAL]) {
            return ReviewReceipt::STATUS_ESCALATE;
        }

        return ReviewReceipt::STATUS_REVIEWED;
    }

    /**
     * @param  list<ReviewFinding>  $findings
     * @param  list<string>  $changedFiles
     * @param  list<array<string,mixed>>  $diffChunks
     * @param  list<string>  $testPaths
     */
    private function computeConfidence(array $findings, array $changedFiles, array $diffChunks, array $testPaths): float
    {
        $base = 0.55;
        if ($diffChunks !== []) {
            $base += 0.15;
        }
        if ($testPaths !== []) {
            $base += 0.05;
        }
        if (count($findings) >= 3) {
            $base += 0.05;
        }
        if (count($changedFiles) > 12) {
            $base -= 0.10; // large diff lowers reviewer confidence
        }

        return max(0.0, min(1.0, round($base, 4)));
    }

    /* ---------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------- */

    private static function looksLikeTestFile(string $file): bool
    {
        $lc = strtolower($file);

        return str_contains($lc, '/tests/')
            || str_starts_with($lc, 'tests/')
            || str_ends_with($lc, 'test.php')
            || str_ends_with($lc, '_test.go')
            || str_ends_with($lc, '.test.ts')
            || str_ends_with($lc, '.spec.ts');
    }

    private static function looksLikeCodeFile(string $file): bool
    {
        foreach (['.php', '.ts', '.tsx', '.js', '.jsx', '.go', '.py', '.rs', '.rb'] as $ext) {
            if (str_ends_with(strtolower($file), $ext)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $tests
     */
    private static function hasMatchingTest(string $file, array $tests): bool
    {
        $base = pathinfo($file, PATHINFO_FILENAME);
        $token = strtolower($base);
        foreach ($tests as $t) {
            if (str_contains(strtolower($t), $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string,mixed>>  $diffChunks
     * @param  list<string>  $keywords
     */
    private function fileTouchesKeywords(string $file, array $diffChunks, array $keywords): bool
    {
        foreach ($diffChunks as $hunk) {
            if (($hunk['file'] ?? null) !== $file) {
                continue;
            }
            $body = strtolower((string) ($hunk['body'] ?? ''));
            foreach ($keywords as $k) {
                if (str_contains($body, $k)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $patterns
     */
    private function matchesAny(string $file, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $file) || $pattern === $file) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function stringOrThrow(array $input, string $key): string
    {
        if (! array_key_exists($key, $input) || ! is_string($input[$key]) || trim((string) $input[$key]) === '') {
            throw new \InvalidArgumentException("ReviewIntelligenceService: required field '{$key}' missing or empty.");
        }

        return $input[$key];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function normaliseDiffChunks(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (! is_array($item)) {
                continue;
            }
            if (! isset($item['file']) || ! is_string($item['file']) || trim($item['file']) === '') {
                continue;
            }
            $out[] = [
                'file' => trim($item['file']),
                'body' => isset($item['body']) ? (string) $item['body'] : '',
                'line' => isset($item['line']) && is_int($item['line']) ? $item['line'] : null,
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function normaliseRiskRules(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $rule) {
            if (! is_array($rule)) {
                continue;
            }
            $out[] = $rule;
        }

        return $out;
    }
}
