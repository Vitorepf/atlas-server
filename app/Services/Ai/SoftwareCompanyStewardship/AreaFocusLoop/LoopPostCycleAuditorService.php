<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * AP-810 / LHL-02 — Post-Cycle Auditor (AP-807 Part 2).
 *
 * The FIRST gate AFTER a cycle ran. It answers exactly one question:
 *
 *   > Did this cycle really do what it claims, and does it count?
 *
 * It is read-only / deterministic / input-seam driven. It NEVER invokes a
 * provider, NEVER runs the loop, NEVER merges, NEVER deletes a branch/worktree,
 * NEVER mutates code or git. It only JUDGES a cycle record (passed in as input
 * fixtures) and refuses to let a false claim count as success.
 *
 * It implements AP-807 Part 2 Audits A-F exactly:
 *   A Execution Truth   — preflight block => no provider was invoked; a provider
 *                         invocation => preflight allow; provider work belongs to
 *                         the selected packet; blocked is NEVER success; a
 *                         no-provider cycle is NEVER real implementation.
 *   B Judge/Repair Truth — a merge may happen ONLY if the judge accepted;
 *                         repair_required / rejected / operator_review / blocked
 *                         must block merge; repair is tied to the same candidate;
 *                         judge evidence carries validation + changed files +
 *                         scope + verdict.
 *   C Merge Truth        — merge_performed => a real hash AND the target advanced;
 *                         main target => main_before != main_after; lane target =>
 *                         lane advanced AND main unchanged; none => nothing
 *                         advanced; a sandbox commit is NOT a merge; a plan-only
 *                         forge path is NOT implementation.
 *   D Evidence Truth     — an AP-791 receipt exists + is linked + references the
 *                         candidate; changed files; required tests pass/fail/block;
 *                         lane/main hashes; operator-visible evidence.
 *   E Cleanup Truth      — worktree removed OR retained-with-reason; sandbox
 *                         branches cleaned; lock released/renewed; no orphan
 *                         provider process; kill switch respected; dirty state
 *                         absent or classified.
 *   F Progression Truth  — a packet is complete ONLY if merged; a blocked packet
 *                         records blocker + retry; the next packet depends on the
 *                         lane head; the same blocked packet is not selected
 *                         forever; backlog feedback updated; backlog exhaustion is
 *                         honest, never dressed as recovery.
 *
 * Honesty rules (operator does not accept false claims):
 *   - status=valid_success ONLY when the cycle really merged real work behind an
 *     accepting judge with linked evidence and clean cleanup;
 *   - blocked is reported as valid_block (an honest, allowed outcome) — NEVER as
 *     success, and counts_as_success is false;
 *   - a sandbox commit is not a merge; a plan-only Forge path is not implementation;
 *   - a provider call without a preflight allow is a critical_violation;
 *   - backlog exhaustion is a valid_block, never a failure or a recovery filler.
 *
 * Contract: AP-807 Part 2; AP-810 build contract slice LHL-02.
 * Reference: AutonomousLoopReceiptIntegrityService (AP-791 receipts).
 */
final class LoopPostCycleAuditorService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.loop_post_cycle_audit.v1';

    /** A real cycle that produced real merged work behind an accepting judge. */
    public const STATUS_VALID_SUCCESS = 'valid_success';

    /** An honest blocked/no-merge cycle (e.g. backlog exhausted, judge repair). */
    public const STATUS_VALID_BLOCK = 'valid_block';

    /** A cycle whose claim does not match reality (e.g. merge w/o judge accept). */
    public const STATUS_INVALID = 'invalid_cycle';

    /** A safety/authority breach (e.g. provider call without a preflight allow). */
    public const STATUS_CRITICAL = 'critical_violation';

    /** The six Part-2 audits (all must pass for a claim to stand). */
    private const AUDIT_EXECUTION = 'execution_truth';

    private const AUDIT_JUDGE = 'judge_repair_truth';

    private const AUDIT_MERGE = 'merge_truth';

    private const AUDIT_EVIDENCE = 'evidence_truth';

    private const AUDIT_CLEANUP = 'cleanup_truth';

    private const AUDIT_PROGRESSION = 'progression_truth';

    /** Judge verdicts that ACCEPT (a merge is allowed only behind one of these). */
    private const JUDGE_ACCEPTING = ['accepted', 'approved', 'pass', 'passed'];

    /** Judge verdicts that must BLOCK a merge. */
    private const JUDGE_BLOCKING = ['repair_required', 'rejected', 'operator_review', 'blocked', 'failed'];

    /**
     * Single entrypoint. Every key is optional; the diagnostic default audits a
     * clean/empty cycle (no provider, no merge => honest valid_block) and never
     * crashes.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function audit(array $input = []): array
    {
        // A wiring-phase `fixture` (from --fixture-file) may carry a whole cycle
        // record; merge it under the explicit input so direct keys still win.
        $input = $this->mergeFixture($input);

        $area = trim((string) ($input['area'] ?? 'agentic_engineering_os')) ?: 'agentic_engineering_os';
        $focus = trim((string) ($input['focus'] ?? 'dev_forge')) ?: 'dev_forge';
        $runId = trim((string) ($input['run_id'] ?? ''));
        $cycleIndex = (int) ($input['cycle_index'] ?? 0);

        $candidate = is_array($input['candidate'] ?? null) ? $input['candidate'] : [];
        $packet = is_array($input['packet'] ?? null)
            ? $input['packet']
            : (is_array($candidate['self_construction_packet'] ?? null) ? $candidate['self_construction_packet'] : []);

        // Preflight verdict from LHL-01 (input seam, never re-run here).
        $preflightStatus = strtolower(trim((string) ($input['preflight_status'] ?? '')));
        $preflightAllowed = array_key_exists('preflight_allowed', $input)
            ? (bool) $input['preflight_allowed']
            : $preflightStatus === 'allow';

        $providerInvoked = (bool) ($input['provider_invoked'] ?? false);
        $mergeTarget = $this->normalizeMergeTarget((string) ($input['merge_target'] ?? 'none'));
        $mergePerformed = (bool) ($input['merge_performed'] ?? false);
        $judgeStatus = strtolower(trim((string) ($input['judge_status'] ?? ($input['judge_verdict'] ?? ''))));

        $mainBefore = $this->nullableString($input['main_before'] ?? null);
        $mainAfter = $this->nullableString($input['main_after'] ?? null);
        $laneBefore = $this->nullableString($input['lane_before'] ?? null);
        $laneAfter = $this->nullableString($input['lane_after'] ?? null);

        /** @var list<array<string,mixed>> $violations */
        $violations = [];
        $warnings = [];
        $auditResults = [];

        $execution = $this->auditExecution($input, $candidate, $packet, $preflightAllowed, $providerInvoked, $mergePerformed, $violations, $warnings);
        $auditResults[self::AUDIT_EXECUTION] = $execution;

        $judge = $this->auditJudge($input, $candidate, $judgeStatus, $mergePerformed, $violations, $warnings);
        $auditResults[self::AUDIT_JUDGE] = $judge;

        $merge = $this->auditMerge($input, $mergeTarget, $mergePerformed, $mainBefore, $mainAfter, $laneBefore, $laneAfter, $violations, $warnings);
        $auditResults[self::AUDIT_MERGE] = $merge;

        $evidence = $this->auditEvidence($input, $candidate, $mergePerformed, $violations, $warnings);
        $auditResults[self::AUDIT_EVIDENCE] = $evidence;

        $cleanup = $this->auditCleanup($input, $violations, $warnings);
        $auditResults[self::AUDIT_CLEANUP] = $cleanup;

        $progression = $this->auditProgression($input, $candidate, $packet, $mergePerformed, $violations, $warnings);
        $auditResults[self::AUDIT_PROGRESSION] = $progression;

        // Did the cycle CLAIM productive work (a merge / completion)?
        $claimsSuccess = $mergePerformed
            || (bool) ($input['claims_success'] ?? false)
            || (bool) ($input['packet_marked_complete'] ?? false);

        $hasCritical = $this->hasCritical($violations);
        $hasInvalid = $violations !== [];
        $backlogExhausted = (bool) ($input['backlog_exhausted'] ?? false)
            || strtolower(trim((string) ($input['cycle_outcome'] ?? ''))) === 'backlog_exhausted'
            || $preflightStatus === 'block' && strtolower(trim((string) ($input['preflight_block_status'] ?? ''))) === 'backlog_exhausted';

        $status = $this->resolveStatus($hasCritical, $hasInvalid, $mergePerformed, $claimsSuccess);

        // counts_as_real_cycle: a cycle only counts as REAL when a provider really
        // produced work behind an allowed preflight, OR it is an honest block.
        // It NEVER counts when there is a critical violation. A no-provider cycle
        // is not a real implementation cycle (it may still be an honest block).
        $countsAsRealCycle = ! $hasCritical && ! $hasInvalid && $providerInvoked && $preflightAllowed;

        // counts_as_success: ONLY a clean valid_success counts. Blocked, invalid,
        // critical, sandbox-only, plan-only forge, recovery — none count.
        $countsAsSuccess = $status === self::STATUS_VALID_SUCCESS;

        $nextAction = $this->resolveNextAction($status, $judgeStatus, $backlogExhausted);

        $candidateOut = [
            'finding_id' => trim((string) ($candidate['finding_id'] ?? '')),
            'packet_id' => $this->nullableString($packet['packet_id'] ?? ($candidate['packet_id'] ?? null)),
            'active_slice_id' => $this->nullableString($candidate['active_slice_id'] ?? ($packet['active_slice_id'] ?? null)),
            'source' => $this->normalizeSource((string) ($candidate['source'] ?? ($candidate['origin_type'] ?? ''))),
        ];

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-807',
            'slice_id' => 'LHL-02',
            'status' => $status,
            'audit_id' => 'lpca_'.substr(MissionCanonicalHash::sha256([
                $area,
                $focus,
                $runId,
                $cycleIndex,
                $candidateOut['finding_id'],
                $candidateOut['packet_id'] ?? '',
                $mergeTarget,
            ]), 0, 16),
            'run_id' => $runId,
            'cycle_index' => $cycleIndex,
            'area' => $area,
            'focus' => $focus,
            'checked_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'counts_as_real_cycle' => $countsAsRealCycle,
            'counts_as_success' => $countsAsSuccess,
            'provider_invoked' => $providerInvoked,
            'preflight_allowed' => $preflightAllowed,
            'merge_performed' => $mergePerformed,
            'merge_target' => $mergeTarget,
            'main_before' => $mainBefore,
            'main_after' => $mainAfter,
            'lane_before' => $laneBefore,
            'lane_after' => $laneAfter,
            'judge_status' => $judgeStatus !== '' ? $judgeStatus : null,
            'candidate' => $candidateOut,
            'audits' => [
                self::AUDIT_EXECUTION => $execution ? 'passed' : 'violated',
                self::AUDIT_JUDGE => $judge ? 'passed' : 'violated',
                self::AUDIT_MERGE => $merge ? 'passed' : 'violated',
                self::AUDIT_EVIDENCE => $evidence ? 'passed' : 'violated',
                self::AUDIT_CLEANUP => $cleanup ? 'passed' : 'violated',
                self::AUDIT_PROGRESSION => $progression ? 'passed' : 'violated',
            ],
            'cleanup' => $this->cleanupSnapshot($input),
            'violations' => array_values($violations),
            'warnings' => array_values(array_unique($warnings)),
            'next_action' => $nextAction,
            'claim_policy' => [
                'read_only' => true,
                'runs_provider' => false,
                'runs_loop' => false,
                'runs_merge' => false,
                'deletes_branches' => false,
                'blocked_never_dressed_as_ready' => true,
            ],
        ];

        $payload['report_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->withoutVolatile($payload));

        return $payload;
    }

    /**
     * Provider-spent-without-merge waste signal entry (step 2/3).
     * Validates input shape; empty input returns the step-1 default contract.
     * No post-cycle transformation wiring yet.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function providerSpentWithoutMerge(array $input = []): array
    {
        $validated = $this->validateProviderSpentWithoutMergeInput($input);

        if ($validated === []) {
            return ProviderSpentWithoutMergeContract::defaults()->toArray();
        }

        // Step 2: validated non-empty input still yields the default contract.
        return ProviderSpentWithoutMergeContract::defaults()->toArray();
    }

    // ---------------------------------------------------------------- Audit A

    /**
     * Audit A — Execution Truth. A provider call without a preflight allow is a
     * CRITICAL violation. A blocked cycle is never a success; a no-provider cycle
     * is never a real implementation.
     *
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $candidate
     * @param  array<string,mixed>  $packet
     * @param  list<array<string,mixed>>  $violations
     * @param  list<string>  $warnings
     * @return bool true=passed
     */
    private function auditExecution(array $input, array $candidate, array $packet, bool $preflightAllowed, bool $providerInvoked, bool $mergePerformed, array &$violations, array &$warnings): bool
    {
        $ok = true;

        // CRITICAL: a provider was invoked without an explicit preflight allow.
        if ($providerInvoked && ! $preflightAllowed) {
            $violations[] = $this->violation('provider_invoked_without_preflight_allow', self::AUDIT_EXECUTION, 'critical');
            $ok = false;
        }

        // A preflight block must mean NO provider was invoked.
        $preflightStatus = strtolower(trim((string) ($input['preflight_status'] ?? '')));
        if ($preflightStatus === 'block' && $providerInvoked) {
            $violations[] = $this->violation('preflight_block_but_provider_invoked', self::AUDIT_EXECUTION, 'critical');
            $ok = false;
        }

        // The provider work must belong to the SELECTED packet/candidate.
        if ($providerInvoked) {
            $providerPacketId = $this->nullableString($input['provider_packet_id'] ?? null);
            $selectedPacketId = $this->nullableString($packet['packet_id'] ?? ($candidate['packet_id'] ?? null));
            if ($providerPacketId !== null && $selectedPacketId !== null && $providerPacketId !== $selectedPacketId) {
                $violations[] = $this->violation('provider_work_not_for_selected_packet', self::AUDIT_EXECUTION, 'invalid');
                $ok = false;
            }

            $providerCandidateId = $this->nullableString($input['provider_candidate_id'] ?? null);
            $selectedCandidateId = $this->nullableString($candidate['finding_id'] ?? null);
            if ($providerCandidateId !== null && $selectedCandidateId !== null && $providerCandidateId !== $selectedCandidateId) {
                $violations[] = $this->violation('provider_work_not_for_selected_candidate', self::AUDIT_EXECUTION, 'invalid');
                $ok = false;
            }
        }

        // NEGATIVE INVARIANT: a blocked cycle dressed as success.
        $cycleOutcome = strtolower(trim((string) ($input['cycle_outcome'] ?? '')));
        $claimsSuccess = (bool) ($input['claims_success'] ?? false) || $mergePerformed;
        if (in_array($cycleOutcome, ['blocked', 'block'], true) && $claimsSuccess) {
            $violations[] = $this->violation('blocked_cycle_claimed_as_success', self::AUDIT_EXECUTION, 'invalid');
            $ok = false;
        }

        // NEGATIVE INVARIANT: a no-provider cycle counted as real implementation.
        $countedAsImplementation = (bool) ($input['counted_as_implementation'] ?? false)
            || (bool) ($input['packet_marked_complete'] ?? false);
        if (! $providerInvoked && $countedAsImplementation && ! $mergePerformed) {
            $violations[] = $this->violation('no_provider_cycle_counted_as_implementation', self::AUDIT_EXECUTION, 'invalid');
            $ok = false;
        }

        return $ok;
    }

    // ---------------------------------------------------------------- Audit B

    /**
     * Audit B — Judge/Repair Truth. A merge may happen ONLY behind an accepting
     * judge. A repair is tied to the same candidate. Judge evidence is complete.
     *
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $candidate
     * @param  list<array<string,mixed>>  $violations
     * @param  list<string>  $warnings
     */
    private function auditJudge(array $input, array $candidate, string $judgeStatus, bool $mergePerformed, array &$violations, array &$warnings): bool
    {
        $ok = true;

        if ($mergePerformed) {
            // CRITICAL: merge happened but judge did not accept.
            if ($judgeStatus === '') {
                $violations[] = $this->violation('merge_without_judge_verdict', self::AUDIT_JUDGE, 'critical');
                $ok = false;
            } elseif (in_array($judgeStatus, self::JUDGE_BLOCKING, true)) {
                $violations[] = $this->violation('merge_with_blocking_judge_verdict:'.$judgeStatus, self::AUDIT_JUDGE, 'critical');
                $ok = false;
            } elseif (! in_array($judgeStatus, self::JUDGE_ACCEPTING, true)) {
                $violations[] = $this->violation('merge_without_accepting_judge_verdict:'.$judgeStatus, self::AUDIT_JUDGE, 'invalid');
                $ok = false;
            }
        }

        // A repair_required path must be tied to the SAME candidate (no swap).
        $repairRequired = $judgeStatus === 'repair_required' || (bool) ($input['repair_required'] ?? false);
        if ($repairRequired) {
            $repairCandidateId = $this->nullableString($input['repair_candidate_id'] ?? null);
            $selectedCandidateId = $this->nullableString($candidate['finding_id'] ?? null);
            if ($repairCandidateId !== null && $selectedCandidateId !== null && $repairCandidateId !== $selectedCandidateId) {
                $violations[] = $this->violation('repair_not_tied_to_same_candidate', self::AUDIT_JUDGE, 'invalid');
                $ok = false;
            }
        }

        // When a judge ran, its evidence must carry validation + changed files +
        // scope + verdict. (Only enforced when a judge actually produced a verdict.)
        if ($judgeStatus !== '') {
            $evidence = is_array($input['judge_evidence'] ?? null) ? $input['judge_evidence'] : [];
            $missing = [];
            $hasValidation = ($evidence['validation'] ?? null) !== null
                || $this->stringList($evidence['validation_commands'] ?? []) !== []
                || array_key_exists('validation', $input) || array_key_exists('validation_commands', $input);
            if (! $hasValidation) {
                $missing[] = 'validation';
            }
            $hasChangedFiles = $this->stringList($evidence['changed_files'] ?? []) !== []
                || $this->stringList($input['changed_files'] ?? []) !== [];
            // Changed files are required for an ACCEPTING verdict (a real change);
            // a blocking verdict may legitimately have none.
            if (! $hasChangedFiles && in_array($judgeStatus, self::JUDGE_ACCEPTING, true)) {
                $missing[] = 'changed_files';
            }
            $hasScope = ($evidence['scope'] ?? null) !== null || array_key_exists('scope', $evidence);
            if (! $hasScope) {
                $missing[] = 'scope';
            }
            $hasVerdict = trim((string) ($evidence['verdict'] ?? '')) !== '' || $judgeStatus !== '';
            if (! $hasVerdict) {
                $missing[] = 'verdict';
            }
            if ($missing !== []) {
                $violations[] = $this->violation('judge_evidence_incomplete:'.implode(',', $missing), self::AUDIT_JUDGE, 'invalid');
                $ok = false;
            }
        }

        return $ok;
    }

    // ---------------------------------------------------------------- Audit C

    /**
     * Audit C — Merge Truth. A claimed merge must be backed by a real hash and a
     * real target advance. A sandbox commit is NOT a merge; a plan-only forge path
     * is NOT implementation.
     *
     * @param  array<string,mixed>  $input
     * @param  list<array<string,mixed>>  $violations
     * @param  list<string>  $warnings
     */
    private function auditMerge(array $input, string $mergeTarget, bool $mergePerformed, ?string $mainBefore, ?string $mainAfter, ?string $laneBefore, ?string $laneAfter, array &$violations, array &$warnings): bool
    {
        $ok = true;

        // NEGATIVE INVARIANT: a sandbox commit dressed as a merge.
        $sandboxCommitOnly = (bool) ($input['sandbox_commit_only'] ?? false)
            || (bool) ($input['sandbox_commit_as_merge'] ?? false);
        if ($mergePerformed && $sandboxCommitOnly) {
            $violations[] = $this->violation('sandbox_commit_claimed_as_merge', self::AUDIT_MERGE, 'invalid');
            $ok = false;
        }

        // NEGATIVE INVARIANT: a plan-only forge path dressed as implementation.
        $forgePlanOnly = (bool) ($input['forge_plan_only'] ?? false)
            || in_array(strtolower(trim((string) ($input['forge_dispatch_mode'] ?? ''))), ['plan_only', 'plan-only', 'fixture', 'runtime-dispatch', 'runtime_dispatch'], true);
        if ($mergePerformed && $forgePlanOnly) {
            $violations[] = $this->violation('forge_plan_only_claimed_as_implementation', self::AUDIT_MERGE, 'invalid');
            $ok = false;
        }

        if ($mergePerformed) {
            // A real merge needs a real commit hash.
            $mergeCommit = $this->nullableString($input['merge_commit'] ?? ($input['merge_hash'] ?? null));
            if ($mergeCommit === null) {
                $violations[] = $this->violation('merge_performed_without_real_hash', self::AUDIT_MERGE, 'invalid');
                $ok = false;
            }

            if ($mergeTarget === 'main') {
                // main target => main must have advanced.
                if ($mainBefore === null || $mainAfter === null || $mainBefore === $mainAfter) {
                    $violations[] = $this->violation('main_merge_claimed_but_main_not_advanced', self::AUDIT_MERGE, 'invalid');
                    $ok = false;
                }
            } elseif ($mergeTarget === 'integration_lane') {
                // lane target => lane advanced AND main unchanged.
                if ($laneBefore === null || $laneAfter === null || $laneBefore === $laneAfter) {
                    $violations[] = $this->violation('lane_merge_claimed_but_lane_not_advanced', self::AUDIT_MERGE, 'invalid');
                    $ok = false;
                }
                if ($mainBefore !== null && $mainAfter !== null && $mainBefore !== $mainAfter) {
                    $violations[] = $this->violation('lane_merge_changed_main', self::AUDIT_MERGE, 'critical');
                    $ok = false;
                }
            } else {
                // merge_target=none but merge claimed => contradiction.
                $violations[] = $this->violation('merge_performed_with_no_target', self::AUDIT_MERGE, 'invalid');
                $ok = false;
            }
        } else {
            // No merge => nothing must have advanced.
            $mainAdvanced = $mainBefore !== null && $mainAfter !== null && $mainBefore !== $mainAfter;
            $laneAdvanced = $laneBefore !== null && $laneAfter !== null && $laneBefore !== $laneAfter;
            if ($mainAdvanced) {
                $violations[] = $this->violation('main_advanced_without_merge', self::AUDIT_MERGE, 'critical');
                $ok = false;
            }
            if ($laneAdvanced) {
                $violations[] = $this->violation('lane_advanced_without_merge', self::AUDIT_MERGE, 'invalid');
                $ok = false;
            }
        }

        return $ok;
    }

    // ---------------------------------------------------------------- Audit D

    /**
     * Audit D — Evidence Truth. A successful (merged) cycle must carry an AP-791
     * receipt that is linked and references the candidate, plus changed files,
     * required-test outcomes, lane/main hashes and operator-visible evidence.
     *
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $candidate
     * @param  list<array<string,mixed>>  $violations
     * @param  list<string>  $warnings
     */
    private function auditEvidence(array $input, array $candidate, bool $mergePerformed, array &$violations, array &$warnings): bool
    {
        // Evidence is strictly required for a merged cycle. A blocked cycle keeps a
        // lighter evidence footprint (blocker + retry, checked in progression).
        if (! $mergePerformed) {
            return true;
        }

        $ok = true;
        $receipt = is_array($input['receipt'] ?? null) ? $input['receipt'] : [];

        // AP-791 receipt must EXIST.
        $receiptId = $this->nullableString($input['receipt_id'] ?? ($receipt['receipt_id'] ?? null));
        $receiptExists = $receiptId !== null
            || (bool) ($input['receipt_exists'] ?? false)
            || $receipt !== [];
        if (! $receiptExists) {
            $violations[] = $this->violation('ap791_receipt_missing', self::AUDIT_EVIDENCE, 'invalid');
            $ok = false;
        }

        // Receipt must be LINKED to this cycle/run.
        $receiptLinked = array_key_exists('receipt_linked', $input)
            ? (bool) $input['receipt_linked']
            : ($this->nullableString($receipt['run_id'] ?? null) !== null || $this->nullableString($receipt['cycle_id'] ?? ($receipt['cycle_index'] ?? null)) !== null);
        if ($receiptExists && ! $receiptLinked) {
            $violations[] = $this->violation('ap791_receipt_not_linked', self::AUDIT_EVIDENCE, 'invalid');
            $ok = false;
        }

        // Receipt must REFERENCE the candidate.
        $receiptCandidateId = $this->nullableString($receipt['finding_id'] ?? ($input['receipt_candidate_id'] ?? null));
        $selectedCandidateId = $this->nullableString($candidate['finding_id'] ?? null);
        if ($receiptExists && $selectedCandidateId !== null) {
            $referencesCandidate = array_key_exists('receipt_references_candidate', $input)
                ? (bool) $input['receipt_references_candidate']
                : ($receiptCandidateId !== null && $receiptCandidateId === $selectedCandidateId);
            if (! $referencesCandidate) {
                $violations[] = $this->violation('ap791_receipt_does_not_reference_candidate', self::AUDIT_EVIDENCE, 'invalid');
                $ok = false;
            }
        }

        // Changed files must be recorded for a merge.
        $changedFiles = $this->stringList($input['changed_files'] ?? ($receipt['changed_files'] ?? []));
        if ($changedFiles === []) {
            $violations[] = $this->violation('evidence_missing_changed_files', self::AUDIT_EVIDENCE, 'invalid');
            $ok = false;
        }

        // Required-test outcomes (pass/fail/block) must be recorded.
        $testsResult = strtolower(trim((string) ($input['required_tests_result'] ?? ($receipt['required_tests_result'] ?? ''))));
        if ($testsResult === '') {
            $violations[] = $this->violation('evidence_missing_required_tests_result', self::AUDIT_EVIDENCE, 'invalid');
            $ok = false;
        } elseif (! in_array($testsResult, ['pass', 'passed', 'fail', 'failed', 'block', 'blocked'], true)) {
            $warnings[] = 'required_tests_result_unrecognized';
        }

        // Lane/main hashes must be present in the evidence.
        $hasHashes = $this->nullableString($input['main_after'] ?? ($receipt['main_after'] ?? null)) !== null
            || $this->nullableString($input['lane_after'] ?? ($receipt['lane_after'] ?? null)) !== null;
        if (! $hasHashes) {
            $violations[] = $this->violation('evidence_missing_lane_main_hashes', self::AUDIT_EVIDENCE, 'invalid');
            $ok = false;
        }

        // Operator-visible evidence (inbox/portfolio/digest) must be present.
        $operatorVisible = array_key_exists('operator_visible_evidence', $input)
            ? (bool) $input['operator_visible_evidence']
            : ($this->nullableString($input['evidence_pack_path'] ?? ($receipt['evidence_pack_path'] ?? null)) !== null
                || (bool) ($input['inbox_item_created'] ?? false));
        if (! $operatorVisible) {
            $violations[] = $this->violation('evidence_not_operator_visible', self::AUDIT_EVIDENCE, 'invalid');
            $ok = false;
        }

        return $ok;
    }

    // ---------------------------------------------------------------- Audit E

    /**
     * Audit E — Cleanup Truth. The host must be left safe for the next cycle.
     *
     * @param  array<string,mixed>  $input
     * @param  list<array<string,mixed>>  $violations
     * @param  list<string>  $warnings
     */
    private function auditCleanup(array $input, array &$violations, array &$warnings): bool
    {
        $ok = true;

        // Worktree must be removed OR retained with an explicit reason.
        $worktreeRemoved = (bool) ($input['worktree_removed'] ?? true);
        if (! $worktreeRemoved) {
            $retainReason = trim((string) ($input['worktree_retained_reason'] ?? ''));
            if ($retainReason === '') {
                $violations[] = $this->violation('orphan_worktree_without_retention_reason', self::AUDIT_CLEANUP, 'invalid');
                $ok = false;
            } else {
                $warnings[] = 'worktree_retained_with_reason';
            }
        }

        // Sandbox branches must be cleaned.
        $sandboxBranchesRemaining = (int) ($input['sandbox_branches_remaining'] ?? 0);
        if ($sandboxBranchesRemaining > 0) {
            $violations[] = $this->violation('orphan_sandbox_branches_remaining', self::AUDIT_CLEANUP, 'invalid');
            $ok = false;
        }

        // Lock must be released or renewed (not stranded held).
        $lockState = strtolower(trim((string) ($input['lock_state'] ?? 'released')));
        if (! in_array($lockState, ['released', 'renewed', 'none'], true)) {
            $violations[] = $this->violation('loop_lock_not_released_or_renewed', self::AUDIT_CLEANUP, 'invalid');
            $ok = false;
        }

        // No orphan provider process.
        $providerProcessesRemaining = (int) ($input['provider_processes_remaining'] ?? 0);
        if ($providerProcessesRemaining > 0) {
            $violations[] = $this->violation('orphan_provider_process_remaining', self::AUDIT_CLEANUP, 'critical');
            $ok = false;
        }

        // Kill switch must be respected (if it fired, the cycle must not have run a provider).
        if ((bool) ($input['kill_switch_active'] ?? false) && (bool) ($input['provider_invoked'] ?? false)) {
            $violations[] = $this->violation('kill_switch_not_respected', self::AUDIT_CLEANUP, 'critical');
            $ok = false;
        }

        // Dirty state must be absent or classified.
        $dirtyUnrelated = $this->stringList($input['dirty_unrelated_paths'] ?? []);
        if ($dirtyUnrelated !== []) {
            $violations[] = $this->violation('dirty_unrelated_state_after_cycle', self::AUDIT_CLEANUP, 'invalid');
            $ok = false;
        }
        if ($this->stringList($input['dirty_classified_paths'] ?? []) !== []) {
            $warnings[] = 'dirty_state_classified_present';
        }

        return $ok;
    }

    // ---------------------------------------------------------------- Audit F

    /**
     * Audit F — Progression Truth. A packet is complete ONLY if it merged; a
     * blocked packet records a blocker + retry; the same blocked packet is not spun
     * forever; backlog exhaustion is honest, never recovery filler.
     *
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $candidate
     * @param  array<string,mixed>  $packet
     * @param  list<array<string,mixed>>  $violations
     * @param  list<string>  $warnings
     */
    private function auditProgression(array $input, array $candidate, array $packet, bool $mergePerformed, array &$violations, array &$warnings): bool
    {
        $ok = true;

        // NEGATIVE INVARIANT: a packet marked complete without a real merge.
        $packetComplete = (bool) ($input['packet_marked_complete'] ?? false)
            || strtolower(trim((string) ($packet['status'] ?? ($candidate['status'] ?? '')))) === 'complete';
        if ($packetComplete && ! $mergePerformed) {
            $violations[] = $this->violation('packet_marked_complete_without_merge', self::AUDIT_PROGRESSION, 'invalid');
            $ok = false;
        }

        // A blocked packet must record a blocker + retry policy.
        $cycleOutcome = strtolower(trim((string) ($input['cycle_outcome'] ?? '')));
        $isBlocked = in_array($cycleOutcome, ['blocked', 'block'], true) || (bool) ($input['packet_blocked'] ?? false);
        if ($isBlocked) {
            $blockerReason = trim((string) ($input['blocker_reason'] ?? ($input['blocker'] ?? '')));
            if ($blockerReason === '') {
                $violations[] = $this->violation('blocked_packet_without_blocker_reason', self::AUDIT_PROGRESSION, 'invalid');
                $ok = false;
            }
            $hasRetryPolicy = array_key_exists('retry_policy', $input)
                || array_key_exists('retry_after', $input)
                || (bool) ($input['retry_recorded'] ?? false);
            if (! $hasRetryPolicy) {
                $violations[] = $this->violation('blocked_packet_without_retry_policy', self::AUDIT_PROGRESSION, 'invalid');
                $ok = false;
            }
        }

        // The next packet must depend on the current lane head (no stale base).
        $nextPacketBase = $this->nullableString($input['next_packet_base'] ?? null);
        $laneAfter = $this->nullableString($input['lane_after'] ?? null);
        if ($nextPacketBase !== null && $laneAfter !== null && $nextPacketBase !== $laneAfter) {
            $violations[] = $this->violation('next_packet_not_based_on_lane_head', self::AUDIT_PROGRESSION, 'invalid');
            $ok = false;
        }

        // NEGATIVE INVARIANT: the same blocked packet selected forever.
        $sameBlockedSpins = (int) ($input['same_blocked_packet_spins'] ?? 0);
        $maxSpins = (int) ($input['max_same_packet_spins'] ?? 3);
        if ($maxSpins > 0 && $sameBlockedSpins >= $maxSpins) {
            $violations[] = $this->violation('same_blocked_packet_selected_forever', self::AUDIT_PROGRESSION, 'invalid');
            $ok = false;
        }

        // Backlog feedback must be updated (the loop learns from the outcome).
        $backlogFeedbackUpdated = array_key_exists('backlog_feedback_updated', $input)
            ? (bool) $input['backlog_feedback_updated']
            : true;
        if (! $backlogFeedbackUpdated) {
            $violations[] = $this->violation('backlog_feedback_not_updated', self::AUDIT_PROGRESSION, 'invalid');
            $ok = false;
        }

        // NEGATIVE INVARIANT: backlog exhaustion masked as a synthetic recovery.
        $backlogExhausted = (bool) ($input['backlog_exhausted'] ?? false) || $cycleOutcome === 'backlog_exhausted';
        $syntheticRecovery = (bool) ($input['synthetic_recovery_used'] ?? false)
            || (bool) ($candidate['is_starvation_recovery'] ?? false)
            || in_array(strtolower((string) ($candidate['origin_type'] ?? '')), ['starvation_recovery', 'recovery', 'filler'], true);
        if ($backlogExhausted && $syntheticRecovery) {
            $violations[] = $this->violation('backlog_exhaustion_dressed_as_recovery', self::AUDIT_PROGRESSION, 'invalid');
            $ok = false;
        }

        return $ok;
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Resolve the cycle status from the violation set. Critical beats invalid;
     * a clean cycle is valid_success only when it really merged; otherwise an
     * honest valid_block. A claim of success without a merge is invalid.
     *
     * @return self::STATUS_*
     */
    private function resolveStatus(bool $hasCritical, bool $hasInvalid, bool $mergePerformed, bool $claimsSuccess): string
    {
        if ($hasCritical) {
            return self::STATUS_CRITICAL;
        }
        if ($hasInvalid) {
            return self::STATUS_INVALID;
        }
        // No violations. A real merge is a valid success.
        if ($mergePerformed) {
            return self::STATUS_VALID_SUCCESS;
        }
        // No merge but the cycle CLAIMS success => invalid (false success).
        if ($claimsSuccess) {
            return self::STATUS_INVALID;
        }

        // No merge, no false claim => an honest block (e.g. backlog exhausted).
        return self::STATUS_VALID_BLOCK;
    }

    private function resolveNextAction(string $status, string $judgeStatus, bool $backlogExhausted): string
    {
        if ($status === self::STATUS_CRITICAL) {
            return 'stop_critical_violation';
        }
        if ($status === self::STATUS_INVALID) {
            return $judgeStatus === 'repair_required' ? 'repair' : 'stop_critical_violation';
        }
        if ($status === self::STATUS_VALID_BLOCK) {
            if ($judgeStatus === 'repair_required') {
                return 'repair';
            }

            return $backlogExhausted ? 'stop_backlog_exhausted' : 'continue';
        }

        // valid_success.
        return 'continue';
    }

    /**
     * @param  list<array<string,mixed>>  $violations
     */
    private function hasCritical(array $violations): bool
    {
        foreach ($violations as $violation) {
            if (($violation['severity'] ?? '') === 'critical') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{code:string,audit:string,severity:string}
     */
    private function violation(string $code, string $audit, string $severity): array
    {
        return [
            'code' => $code,
            'audit' => $audit,
            'severity' => $severity === 'critical' ? 'critical' : 'invalid',
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function cleanupSnapshot(array $input): array
    {
        return [
            'worktree_removed' => (bool) ($input['worktree_removed'] ?? true),
            'worktree_retained_reason' => $this->nullableString($input['worktree_retained_reason'] ?? null),
            'sandbox_branches_remaining' => (int) ($input['sandbox_branches_remaining'] ?? 0),
            'lock_state' => strtolower(trim((string) ($input['lock_state'] ?? 'released'))) ?: 'released',
            'provider_processes_remaining' => (int) ($input['provider_processes_remaining'] ?? 0),
            'kill_switch_active' => (bool) ($input['kill_switch_active'] ?? false),
            'dirty_unrelated' => $this->stringList($input['dirty_unrelated_paths'] ?? []) !== [],
        ];
    }

    private function normalizeMergeTarget(string $value): string
    {
        $value = strtolower(trim($value));

        return in_array($value, ['integration_lane', 'main', 'none'], true) ? $value : 'none';
    }

    private function normalizeSource(string $value): string
    {
        $value = strtolower(trim($value));

        return match (true) {
            $value === 'canonical_backlog' => 'canonical_backlog',
            str_contains($value, 'self_construction') => 'self_construction_packet',
            $value === 'scanner' => 'scanner',
            $value === '' => 'canonical_backlog',
            default => $value,
        };
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
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
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function validateProviderSpentWithoutMergeInput(array $input): array
    {
        $input = $this->mergeFixture($input);

        $normalized = [];

        if (array_key_exists('area', $input) || array_key_exists('area_id', $input)) {
            $normalized['area'] = trim((string) ($input['area_id'] ?? $input['area'] ?? ''));
        }
        if (array_key_exists('focus', $input)) {
            $normalized['focus'] = trim((string) $input['focus']);
        }
        if (array_key_exists('run_id', $input)) {
            $normalized['run_id'] = trim((string) $input['run_id']);
        }
        if (array_key_exists('cycle_index', $input)) {
            if (! is_int($input['cycle_index']) && ! is_string($input['cycle_index']) && ! is_float($input['cycle_index'])) {
                return [];
            }
            $normalized['cycle_index'] = max(0, (int) $input['cycle_index']);
        }
        if (array_key_exists('provider_invoked', $input)) {
            if (! is_bool($input['provider_invoked']) && ! is_int($input['provider_invoked'])) {
                return [];
            }
            $normalized['provider_invoked'] = (bool) $input['provider_invoked'];
        }
        if (array_key_exists('merge_performed', $input)) {
            if (! is_bool($input['merge_performed']) && ! is_int($input['merge_performed'])) {
                return [];
            }
            $normalized['merge_performed'] = (bool) $input['merge_performed'];
        }

        return $normalized;
    }

    /**
     * A wiring-phase `fixture` may be a single cycle record; fold it under the
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
