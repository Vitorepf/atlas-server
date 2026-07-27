<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

use App\Services\Ai\AtlasAobgBlackboardService;
use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPatternLearningLedger;
use App\Services\Ai\AutonomousEvolution\Constitution\AtlasLoopMergeActuator;
use App\Services\Ai\Cognition\AtlasCognitionRemintTouchedQueue;
use App\Services\Ai\EngineeringKernel\Adapters\AtlasDevGateAdapter;
use App\Services\Ai\EngineeringKernel\CertVerdict;
use App\Services\Ai\EngineeringKernel\CriteriaCanonicalizer;
use App\Services\Ai\EngineeringKernel\EliteExecutorKernel;
use App\Services\Ai\EngineeringKernel\OutcomeProofGate;
use App\Services\Ai\SelfConstruction\GovernedTargets\AtlasTaskPropertyGatedTargetPolicy;
use App\Services\Ai\SelfConstruction\NativeWorker\AutonomosExecutionOrderBinding;
use App\Services\Engineering\EngineeringQualityScanService;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * PART 2 · the SHARED-MAIN scoped committer — the safe "resolve" for N AIs working the SAME local main branch.
 *
 * THE MODEL (operator): every AI edits the SAME working tree on `main`. With 5 AIs each touching 2 files, the
 * tree holds 10 changed files at once — but each AI must commit ONLY its OWN files. This is safe BY
 * CONSTRUCTION because the serving stack hands out tasks whose `allowed_files` are pairwise DISJOINT
 * (conflict-free claim, prefix-aware) — so no two AIs ever touch the same file, and each can commit its scope
 * without clobbering or grabbing another AI's uncommitted work.
 *
 * This committer makes that foolproof: given a task's `allowed_files`, it stages and commits EXACTLY those
 * paths (`git commit -- <paths>`, the partial-commit form, so other AIs' staged/unstaged changes are NEVER
 * swept in — the `git add -A` foot-gun is impossible), under a single exclusive flock so concurrent resolves
 * serialize, refusing any path on the pétreo forbidden list. The AI never runs raw git.
 *
 * ENG-04: when server-side verification evidence is supplied, the unified autonomosGate certify runs HERE —
 * between verify and commit — fail-closed on false claims and task_tests_proven non-promotion; docs-only
 * boot_proven landings proceed with an honest receipt.
 *
 * ENG-05: scoped commits acquire the SAME path-stable main-merge lock as every other main writer
 * ({@see AtlasLoopMergeActuator::LOCK_BASENAME}) — one authority, no divergent flock files.
 */
final class AtlasTaskScopedCommitter
{
    /**
     * @deprecated ENG-05 — unified onto {@see AtlasLoopMergeActuator::LOCK_BASENAME}.
     *               Kept so legacy call-sites probe the same lock path.
     */
    public const LOCK_REL = '.git/'.AtlasLoopMergeActuator::LOCK_BASENAME;

    public const LOCK_TIMEOUT_SECONDS = 15.0;

    public function __construct(
        private readonly ?AtlasLoopHarnessGuard $guard = null,
        private readonly ?string $repoRootOverride = null,
        private readonly ?EliteExecutorKernel $eliteKernel = null,
        private readonly ?AtlasArtisanBootSmokeGate $bootSmokeGate = null,
        private readonly ?AtlasLoopMergeActuator $mergeActuator = null,
        private readonly ?AtlasCognitionRemintTouchedQueue $remintTouchedQueue = null,
        private readonly ?AtlasAobgBlackboardService $blackboard = null,
        private readonly ?AtlasAutonomousLandVerificationGate $verificationSeamGate = null,
    ) {}

    /**
     * Stage + commit EXACTLY $allowedFiles as one scoped commit. Never touches paths outside the scope.
     *
     * @param  list<string>  $allowedFiles
     * @param  array<string,mixed>|null  $verification  server-side verify result (ENG-04 certify seam)
     * @param  string  $commitAuthority  'autonomous' (default — constitution-gated self-edit) | 'operator' (atlas:land)
     * @param  array{token?:string,battery_root?:string,nonce?:string,consumed_nonces?:list<string>}|null  $constitution
     *                                                                                                                    Constitution PASS-token bind for a property_gated (self-edit) scope — verdict de MÁQUINA.
     * @return array<string, mixed>
     */
    public function commitScope(
        array $allowedFiles,
        string $taskPacketId,
        string $clientId,
        string $objective = '',
        ?array $verification = null,
        bool $governedLockAlreadyHeld = false,
        ?callable $preEffectGuard = null,
        string $commitAuthority = 'autonomous',
        ?array $constitution = null,
    ): array {
        $files = $this->normalizeFiles($allowedFiles);
        if ($files === []) {
            return $this->result(false, 'empty_scope', taskPacketId: $taskPacketId);
        }
        $constitution ??= [];

        // PÉTREO: never commit a forbidden self-target (the loop's own judge/guard/master switch).
        $guard = $this->guard ?? new AtlasLoopHarnessGuard;
        foreach ($files as $file) {
            if ($guard->isForbiddenSelfTarget($file)) {
                return $this->result(false, 'forbidden_self_target', taskPacketId: $taskPacketId, extra: ['path' => $file]);
            }
        }

        // SEV-1 08/07 — CONSTITUTION GATE (verdict de MÁQUINA, fail-closed). The pétreo denylist above
        // fails-OPEN for files it never listed; the property_gated zone (AutonomousEvolution/ — the loop's
        // own organs, NEW files included by prefix) is the SELF-EDIT surface. An AUTONOMOUS commit touching
        // it lands ONLY with a Constitution PASS-token ({@see AtlasLoopConstitutionGateService}) re-verified
        // against the post-apply tree under the lock — never human approval, never a silent unlock. The
        // operator port (atlas:land) is exempt; kill-switch: ATLAS_CONSTITUTION_GATE_ENABLED=false.
        $selfEditPaths = [];
        if ($commitAuthority !== 'operator' && (bool) config('atlas.loop.constitution_gate_enabled', true)) {
            $policy = new AtlasTaskPropertyGatedTargetPolicy;
            $selfEditPaths = array_values(array_filter(
                $files,
                static fn (string $f): bool => $policy->classify($f) === AtlasTaskPropertyGatedTargetPolicy::CLASSIFICATION_PROPERTY_GATED,
            ));
        }
        if ($selfEditPaths !== [] && trim((string) ($constitution['token'] ?? '')) === '') {
            return $this->result(false, 'constitution_gate_blocked_self_edit_no_token', taskPacketId: $taskPacketId, extra: [
                'self_edit_paths' => $selfEditPaths,
            ]);
        }

        // MULTV-10 — enforce SEAM for verified autonomous landings. Default-OFF; the
        // ONLY flip belongs to ASI-10 via ELEV-26s (1 flip per family per window).
        // This slice NEVER flips: it only builds the check the flip lights up. The
        // operator port (atlas:land, commitAuthority=operator) is EXEMPT so hand-
        // driven landings continue to work with the flag ON.
        $verificationSeamGate = $this->verificationSeamGate ?? app(AtlasAutonomousLandVerificationGate::class);
        $seamVerdict = $verificationSeamGate->evaluate($commitAuthority, $verification);
        if (($seamVerdict['allowed'] ?? false) !== true) {
            return $this->result(false, (string) ($seamVerdict['reason'] ?? AtlasAutonomousLandVerificationGate::REASON_MISSING), taskPacketId: $taskPacketId, extra: array_filter([
                'verification_seam' => $seamVerdict,
                'required_tier' => $seamVerdict['required_tier'] ?? null,
                'receipt_tier' => $seamVerdict['receipt_tier'] ?? null,
            ], static fn (mixed $v): bool => $v !== null));
        }

        $certify = null;
        if ($verification !== null) {
            $certify = $this->certifyLanding($files, $taskPacketId, $verification);
            if (($certify['allowed'] ?? false) !== true) {
                return $this->result(false, 'landing_certify_refused', taskPacketId: $taskPacketId, extra: [
                    'landing_certify' => $certify,
                ]);
            }
        }

        $repo = $this->repoRoot();
        if (! is_dir($repo.'/.git')) {
            return $this->result(false, 'not_a_git_repo', taskPacketId: $taskPacketId);
        }

        $bootSmoke = ($this->bootSmokeGate ?? app(AtlasArtisanBootSmokeGate::class))
            ->differentialForScopedCommit($repo, $files);
        if (($bootSmoke['introduced_failure'] ?? false) === true) {
            return $this->result(false, 'boot_smoke_introduced_failure', taskPacketId: $taskPacketId, extra: [
                'give_back' => true,
                'boot_smoke' => $bootSmoke,
                'stderr' => (string) data_get($bootSmoke, 'snapshot.stderr_tail', ''),
            ]);
        }

        $effect = function () use ($repo, $files, $taskPacketId, $clientId, $objective, $verification, $certify, $bootSmoke, $preEffectGuard, $selfEditPaths, $constitution): array {
            // STATUS-FIRST: `git status` on the scope never errors on a path that does not exist; `git add` of a
            // non-existent pathspec DOES error. So discover which scoped paths actually changed, and act only on
            // those. Empty ⇒ the AI made no edits ⇒ honest no-op (keep the lease).
            // `-uall` lists each untracked FILE (not a collapsed parent dir), so a brand-new file in a brand-new
            // directory is committed as the exact file path, never the whole dir.
            $status = $this->git($repo, array_merge(['status', '--porcelain', '-z', '--untracked-files=all', '--'], $files));
            $changed = $this->changedPaths((string) $status['out']);
            if ($changed === []) {
                return $this->result(false, 'nothing_to_commit_in_scope', taskPacketId: $taskPacketId);
            }

            // Final sovereign replay at the mutation boundary. The Governor supplies this
            // while holding the same merge lock, closing lease/base/tree takeover races.
            if ($preEffectGuard !== null && $preEffectGuard() !== true) {
                return $this->result(false, 'governed_pre_effect_revalidation_failed', taskPacketId: $taskPacketId);
            }

            $message = $this->commitMessage($taskPacketId, $clientId, $objective);

            if ($selfEditPaths !== []) {
                $constitutionCommit = ($this->mergeActuator ?? app(AtlasLoopMergeActuator::class))
                    ->commitWithConstitutionToken(
                        $repo,
                        $changed,
                        $message,
                        (string) ($constitution['token'] ?? ''),
                        (string) ($constitution['battery_root'] ?? ''),
                        (string) ($constitution['nonce'] ?? ''),
                        array_values(array_map('strval', (array) ($constitution['consumed_nonces'] ?? []))),
                        lockAlreadyHeld: true,
                        commitAfterTokenVerified: function (string $postApplyTreeSha) use ($repo, $changed, $taskPacketId, $clientId, $files, $certify, $bootSmoke, $verification, $objective): array {
                            // Partial commit: `-- <paths>` commits ONLY these paths regardless of what else is staged.
                            $commit = $this->git($repo, array_merge(['commit', '-m', $this->commitMessage($taskPacketId, $clientId, $objective), '--'], $changed));
                            if ($commit['code'] !== 0) {
                                return $this->result(false, 'git_commit_failed', taskPacketId: $taskPacketId, extra: ['stderr' => $commit['err']]);
                            }

                            $sha = trim((string) $this->git($repo, ['rev-parse', 'HEAD'])['out']);
                            $blackboardClaimRelease = $this->releaseCommittedBlackboardClaims($clientId, $changed);
                            $remintTouchedQueue = $this->enqueueRemintTouchedForLanding($files, $taskPacketId, [
                                'commit_sha' => $sha,
                                'client_id' => $clientId,
                            ]);
                            $liveOutcomeFeedback = $this->recordLiveOutcomeFeedback(
                                taskPacketId: $taskPacketId,
                                clientId: $clientId,
                                objective: $objective,
                                verification: $verification,
                                committed: true,
                            );

                            return $this->result(true, 'committed', taskPacketId: $taskPacketId, extra: array_filter([
                                'commit_sha' => $sha,
                                'files_committed' => $files,
                                'client_id' => $clientId,
                                'landing_certify' => $certify,
                                'boot_smoke' => ($bootSmoke['warning'] ?? null) !== null ? $bootSmoke : null,
                                'blackboard_claim_release' => $blackboardClaimRelease,
                                'remint_touched_queue' => $remintTouchedQueue,
                                'live_outcome_feedback' => $liveOutcomeFeedback,
                            ], static fn (mixed $v): bool => $v !== null));
                        },
                    );

                if (($constitutionCommit['committed'] ?? false) !== true && ! array_key_exists('task_packet_id', $constitutionCommit)) {
                    return $this->result(false, (string) ($constitutionCommit['reason'] ?? 'constitution_commit_refused'), taskPacketId: $taskPacketId, extra: array_filter([
                        'self_edit_paths' => $selfEditPaths,
                        'tree_sha' => $constitutionCommit['tree_sha'] ?? null,
                        'stderr' => $constitutionCommit['stderr'] ?? null,
                    ], static fn (mixed $v): bool => $v !== null));
                }

                return $constitutionCommit;
            }

            // Stage ONLY the changed scoped paths.
            $add = $this->git($repo, array_merge(['add', '--'], $changed));
            if ($add['code'] !== 0) {
                return $this->result(false, 'git_add_failed', taskPacketId: $taskPacketId, extra: ['stderr' => $add['err']]);
            }

            // Partial commit: `-- <paths>` commits ONLY these paths regardless of what else is staged.
            $commit = $this->git($repo, array_merge(['commit', '-m', $message, '--'], $changed));
            if ($commit['code'] !== 0) {
                return $this->result(false, 'git_commit_failed', taskPacketId: $taskPacketId, extra: ['stderr' => $commit['err']]);
            }

            $sha = trim((string) $this->git($repo, ['rev-parse', 'HEAD'])['out']);
            $blackboardClaimRelease = $this->releaseCommittedBlackboardClaims($clientId, $changed);
            $remintTouchedQueue = $this->enqueueRemintTouchedForLanding($files, $taskPacketId, [
                'commit_sha' => $sha,
                'client_id' => $clientId,
            ]);
            $liveOutcomeFeedback = $this->recordLiveOutcomeFeedback(
                taskPacketId: $taskPacketId,
                clientId: $clientId,
                objective: $objective,
                verification: $verification,
                committed: true,
            );

            // ASI-08 — one pattern-learning row per real landing, server-side.
            // Fail-open: ledger write never blocks the receipt.
            $patternLedgerRow = null;
            try {
                $patternLedgerRow = (new AtlasBrainPatternLearningLedger)
                    ->append([
                        'scope' => $commitAuthority === 'operator' ? 'operator_land' : 'autonomos_land',
                        'task_id' => $taskPacketId,
                        'action_hint' => 'compound',
                        'result_kind' => AtlasBrainPatternLearningLedger::RESULT_ACCEPTED,
                        'evidence_refs' => array_map(
                            static fn (string $file): string => 'file:'.$file,
                            $files,
                        ),
                    ]);
            } catch (Throwable) {
                $patternLedgerRow = null;
            }

            return $this->result(true, 'committed', taskPacketId: $taskPacketId, extra: array_filter([
                'commit_sha' => $sha,
                'files_committed' => $files,
                'client_id' => $clientId,
                'landing_certify' => $certify,
                'boot_smoke' => ($bootSmoke['warning'] ?? null) !== null ? $bootSmoke : null,
                'blackboard_claim_release' => $blackboardClaimRelease,
                'remint_touched_queue' => $remintTouchedQueue,
                'live_outcome_feedback' => $liveOutcomeFeedback,
                'pattern_learning_ledger' => $patternLedgerRow,
            ], static fn (mixed $v): bool => $v !== null));
        };

        return $governedLockAlreadyHeld ? $effect() : $this->withCommitLock($repo, $effect);
    }

    /** @param callable():array<string,mixed> $callback @return array<string,mixed> */
    public function withGovernedCommitLock(callable $callback): array
    {
        return $this->withCommitLock($this->repoRoot(), $callback);
    }

    /**
     * PIP-04: queue touched-path re-minting outside the commit path. Fail-open:
     * queue errors are reported in the commit receipt but never block landing.
     *
     * @param  list<string>  $paths
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>
     */
    public function enqueueRemintTouchedForLanding(array $paths, string $taskPacketId, array $metadata = []): array
    {
        try {
            return ($this->remintTouchedQueue ?? app(AtlasCognitionRemintTouchedQueue::class))
                ->enqueue($paths, $taskPacketId, $metadata);
        } catch (Throwable $e) {
            return [
                'queued' => false,
                'reason' => 'queue_error_fail_open',
                'mode' => 'deferred_disk_queue',
                'error' => mb_substr($e->getMessage(), 0, 200),
            ];
        }
    }

    /**
     * ENG-04 — unified kernel certify on the primary Autônomos landing seam.
     *
     * @param  list<string>  $allowedFiles
     * @param  array<string,mixed>  $verification
     * @return array{allowed:bool,reason?:string,promoted?:bool,proof_strength?:string,verdict?:array<string,mixed>}
     */
    private function certifyLanding(array $allowedFiles, string $taskPacketId, array $verification): array
    {
        $kernel = $this->eliteKernel ?? app(EliteExecutorKernel::class);
        $execution = (array) ($verification['execution_evidence'] ?? []);
        $proofStrength = (string) ($verification['proof_strength'] ?? 'boot_proven');
        $hasDeclaredTests = $this->scopeDeclaresTests($allowedFiles);
        $attestation = is_array($verification['test_attestation'] ?? null)
            ? (array) $verification['test_attestation']
            : (is_array($execution['test_attestation'] ?? null) ? (array) $execution['test_attestation'] : []);
        if ($attestation !== []) {
            $attestationVerdict = (new AtlasTestAttestationService)->validate(
                $attestation,
                (new AtlasTestAttestationService)->stateHash($this->repoRoot(), $allowedFiles),
            );
            $hardAttestationBlockers = array_values(array_intersect(
                $attestationVerdict['blockers'],
                ['attestation_stale', 'runner_unrecognized'],
            ));
            if ($hardAttestationBlockers !== []) {
                return [
                    'allowed' => false,
                    'reason' => in_array('attestation_stale', $attestationVerdict['blockers'], true)
                        ? 'attestation_stale'
                        : 'test_attestation_invalid',
                    'proof_strength' => $proofStrength,
                    'test_attestation' => $attestation,
                    'attestation_verdict' => $attestationVerdict,
                ];
            }
        }

        try {
            $criteria = ['task_packet_id' => $taskPacketId, 'allowed_files' => $allowedFiles];
            $executionOrderBinding = AutonomosExecutionOrderBinding::fromPayload($verification);
            if ($executionOrderBinding !== null) {
                $criteria['execution_order_hash'] = $executionOrderBinding['order_hash'];
            }
            $criteriaHash = CriteriaCanonicalizer::hash($criteria);
            $verdict = $kernel->autonomosGate()->certifyAutonomosDelivery([
                'criteria_hash' => $criteriaHash,
                'frozen_hash' => $criteriaHash,
                'changed_files' => $allowedFiles,
                'changed_public_symbols' => [],
                'execution' => [
                    'commands' => array_values(array_map('strval', (array) ($execution['commands'] ?? []))),
                    'claimed_status' => (string) ($execution['claimed_status'] ?? ($hasDeclaredTests ? 'passed' : 'boot_verified')),
                    'tests_run' => (int) ($execution['tests_run'] ?? 0),
                    'assertions_executed' => (int) ($execution['assertions_executed'] ?? 0),
                    'selected_tests' => array_values(array_map('strval', (array) ($execution['selected_tests'] ?? []))),
                    'artifacts' => [],
                    'execution_order_hash' => $executionOrderBinding['order_hash'] ?? null,
                ],
                'mutation_report' => ['decision_surface_added' => false],
                // These four values used to be literals, and they were the sovereign
                // floor's ONLY view of security on every autonomous landing. The floor
                // does enforce them — SovereignHonestyFloor::securityFree() fails on
                // security_scan_did_not_run, on secret_free !== true, and on
                // critical_sast > 0 — so hardcoding them satisfied the invariant by
                // assertion. No scanner had ever run on this path.
                //
                // The Dev path already derives this from a real scan; use the same
                // producer rather than inventing a second answer.
                //
                // Fail-closed: securityFromScan requires at least one SECURITY-category
                // tool to have actually executed, so an unavailable scanner yields
                // ran=false and the floor blocks with security_scan_did_not_run. That
                // requirement had to be ADDED — the aggregate scan status alone is not
                // proof of a run, because a missing binary is recorded as 'skipped' and
                // skipped tools are not counted when the status is computed. None of
                // gitleaks / semgrep / trivy / osv-scanner / grype is installed on this
                // machine, so today this path blocks instead of green-lighting.
                'security_scan' => AtlasDevGateAdapter::securityFromScan(
                    app(EngineeringQualityScanService::class)->scan($this->repoRoot(), [
                        'profile' => 'auto',
                        'changed_only' => true,
                        'include_categories' => ['security'],
                        'run_context_type' => 'autonomos_landing',
                        'run_context_id' => $taskPacketId,
                    ]),
                ),
                'context_sufficiency' => 85,
                'judges' => [
                    ['name' => 'task-verify-gate', 'provider_family' => 'atlas_harness', 'approved' => true],
                    ['name' => 'task-landing-certify', 'provider_family' => 'atlas_verify', 'approved' => true],
                ],
            ]);
        } catch (Throwable $e) {
            return [
                'allowed' => false,
                'reason' => 'landing_certify_error',
                'proof_strength' => $proofStrength,
                'error' => mb_substr($e->getMessage(), 0, 200),
            ];
        }

        $verdictArray = $verdict->toArray();
        $allowed = $this->landingCertifyAllowsCommit(
            $verdict,
            $verification,
            $hasDeclaredTests,
            (bool) ($execution['counts_parseable'] ?? false),
        );

        return [
            'allowed' => $allowed,
            'reason' => $allowed ? 'landing_certify_admitted' : 'landing_certify_refused',
            'promoted' => $verdict->promoted(),
            'proof_strength' => $proofStrength,
            'verdict' => $verdictArray,
            'receipt_ref' => $verdict->receiptRef,
        ];
    }

    /**
     * @param  list<string>  $allowedFiles
     */
    private function scopeDeclaresTests(array $allowedFiles): bool
    {
        foreach ($allowedFiles as $path) {
            $p = ltrim(str_replace('\\', '/', trim((string) $path)), '/');
            if (str_contains($p, '/tests/') || str_starts_with($p, 'tests/') || str_ends_with($p, 'Test.php')) {
                return true;
            }
        }

        return false;
    }

    private function landingCertifyAllowsCommit(
        CertVerdict $verdict,
        array $verification,
        bool $hasDeclaredTests,
        bool $countsParseable,
    ): bool {
        if (in_array('false_claim_blocked', $verdict->blockers, true)) {
            return false;
        }

        $proofStrength = (string) ($verification['proof_strength'] ?? '');
        $taskTestsCheck = (string) (($verification['checks'] ?? [])['task_tests'] ?? '');

        // Malformed phpunit output with declared tests: refuse (give_back), never poison main.
        if ($hasDeclaredTests && $taskTestsCheck === 'pass' && ! $countsParseable) {
            return false;
        }

        if ($proofStrength === 'task_tests_proven') {
            return $verdict->promoted();
        }

        // Docs-only / boot-only: preserve live landings with an honest unproven/boot_proven receipt.
        if (in_array($proofStrength, ['boot_proven', 'syntax_only', 'fail_open_runner_error', 'fail_open_unattributed'], true)) {
            return true;
        }

        return $verdict->promoted();
    }

    private function commitMessage(string $taskPacketId, string $clientId, string $objective): string
    {
        $summary = $objective !== '' ? $objective : 'resolve task';
        $summary = trim(str_replace(["\n", "\r"], ' ', $summary));
        if (mb_strlen($summary) > 72) {
            $summary = mb_substr($summary, 0, 69).'...';
        }

        return "atlas-task {$taskPacketId}: {$summary}\n\nAtlas-Task: {$taskPacketId}\nResolved-by: {$clientId}";
    }

    /**
     * @param  callable():array<string,mixed>  $callback
     * @return array<string, mixed>
     */
    private function withCommitLock(string $repo, callable $callback): array
    {
        $actuator = $this->mergeActuator ?? app(AtlasLoopMergeActuator::class);
        $locked = $actuator->withMainMergeLock(
            $repo,
            fn (): array => $callback(),
            self::LOCK_TIMEOUT_SECONDS,
        );

        if (($locked['acquired'] ?? false) !== true) {
            return $this->result(false, 'commit_lock_contended'); // FAIL-CLOSED — never commit unlocked
        }

        /** @var array<string,mixed> $result */
        $result = $locked['result'];

        return $result;
    }

    /**
     * @param  list<string>  $args
     * @return array{code:int, out:string, err:string}
     */
    private function git(string $repo, array $args): array
    {
        $process = new Process(array_merge(['git'], $args), $repo);
        $process->setTimeout(60);
        try {
            $process->run();
        } catch (Throwable $e) {
            return ['code' => 1, 'out' => '', 'err' => $e->getMessage()];
        }

        return ['code' => (int) $process->getExitCode(), 'out' => $process->getOutput(), 'err' => $process->getErrorOutput()];
    }

    /**
     * Parse `git status --porcelain -z` (NUL-terminated, raw paths) into the list of changed file paths.
     * With -z, git never quotes/octal-escapes paths, so space and non-ASCII filenames are safe.
     * Rename format: `XY new_path\0old_path\0` — we keep the new path and skip the old.
     *
     * @return list<string>
     */
    private function changedPaths(string $porcelain): array
    {
        $out = [];
        $entries = explode("\0", $porcelain);
        $skipNext = false;
        foreach ($entries as $entry) {
            if ($skipNext) {
                $skipNext = false;

                continue;
            }
            if (strlen($entry) < 3) {
                continue;
            }
            $xy = substr($entry, 0, 2);
            $path = substr($entry, 3);
            // Rename/copy: the next NUL-terminated entry is the old name; skip it.
            if (str_contains($xy, 'R') || str_contains($xy, 'C')) {
                $skipNext = true;
            }
            if ($path !== '') {
                $out[$path] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * @param  list<string>  $files
     * @return list<string>
     */
    private function normalizeFiles(array $files): array
    {
        $out = [];
        foreach ($files as $f) {
            $p = ltrim(trim(str_replace('\\', '/', (string) $f)), '/');
            if ($p !== '' && ! str_contains($p, '..')) {
                $out[$p] = true;
            }
        }

        return array_keys($out);
    }

    private function repoRoot(): string
    {
        return $this->repoRootOverride ?? base_path();
    }

    /**
     * Release only the active blackboard claims owned by this committer client for the
     * paths that actually landed. Fail-open: blackboard cleanup is coordination
     * hygiene, never a reason to roll back a successful git commit.
     *
     * @param  list<string>  $changed
     * @return array<string,mixed>
     */
    private function releaseCommittedBlackboardClaims(string $clientId, array $changed): array
    {
        try {
            return ($this->blackboard ?? app(AtlasAobgBlackboardService::class))
                ->releaseActiveForTargets($clientId, 'file', $changed);
        } catch (Throwable $e) {
            return [
                'schema' => AtlasAobgBlackboardService::SCHEMA,
                'ok' => true,
                'released_count' => 0,
                'claim_ids' => [],
                'provider_bound' => true,
                'reason' => 'release_failed_open',
                'error' => mb_substr($e->getMessage(), 0, 200),
            ];
        }
    }

    /**
     * @param  array<string,mixed>|null  $verification
     * @return array<string,mixed>|null
     */
    private function recordLiveOutcomeFeedback(
        string $taskPacketId,
        string $clientId,
        string $objective,
        ?array $verification,
        bool $committed,
    ): ?array {
        if (function_exists('app') && app()->runningUnitTests() && ! app()->bound(AtlasDecideLiveOutcomeFeedbackService::class)) {
            return null;
        }

        try {
            $execution = (array) ($verification['execution_evidence'] ?? []);
            $proof = (new OutcomeProofGate)->assess($committed ? 'success' : 'failed', $execution);

            return app(AtlasDecideLiveOutcomeFeedbackService::class)->record([
                'task_category' => 'programming',
                'role' => 'autonomos_landing',
                'provider' => $clientId !== '' ? $clientId : 'atlas_autonomos',
                'model' => 'n/a',
                'result' => $committed
                    ? AtlasDecideLiveOutcomeFeedbackService::RESULT_SUCCESS
                    : AtlasDecideLiveOutcomeFeedbackService::RESULT_FAILURE,
                'proven_real' => $proof['proven_real'] === true,
                'verified_basis' => $proof['proven_real'] === true
                    ? AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_SERVER_VERIFIED
                    : AtlasDecideLiveOutcomeFeedbackService::VERIFIED_BASIS_CLAIMED,
                'certified_receipt_id' => $proof['proven_real'] === true
                    ? (string) data_get($verification, 'landing_certify.receipt_ref', $taskPacketId)
                    : null,
                'quality_score' => $proof['proven_real'] === true ? 1.0 : (($proof['fake_green'] ?? false) === true ? 0.0 : 0.5),
                'actor' => 'atlas_autonomos_landing',
                'language' => 'php',
                'risk_level' => 'medium',
                'tool_profile' => 'workspace_write',
                'context_mode' => 'autonomos',
                'repair_count' => 0,
                'context_tokens' => max(1, (int) ceil(mb_strlen($objective !== '' ? $objective : $taskPacketId) / 4)),
            ]);
        } catch (Throwable $e) {
            return [
                'recorded' => false,
                'reason' => 'live_outcome_feedback_failed_open',
                'error' => mb_substr($e->getMessage(), 0, 200),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function result(bool $committed, string $reason, string $taskPacketId = '', array $extra = []): array
    {
        return array_merge([
            'schema' => 'atlas.task_serving.scoped_commit.v1',
            'committed' => $committed,
            'reason' => $reason,
            'task_packet_id' => $taskPacketId,
        ], $extra);
    }
}
