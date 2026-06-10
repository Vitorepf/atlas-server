<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AgentExecution;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipStringListNormalizer;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * AP-798 · Multi-Agent Integration Judge.
 *
 * The composition-and-judgement layer between multi-agent lane execution and the
 * AP-769 merge governor. It receives lane outputs, the validation result, a diff
 * summary and evidence refs, scores seven safety dimensions and emits a
 * deterministic verdict that routes the work to one of five statuses.
 *
 * It is a pure rules engine: it never merges, never mutates the repo, never
 * invokes a provider and never uses an LLM. On acceptance it only hands off to
 * the merge governor; it never performs the merge itself.
 */
final class MultiAgentIntegrationJudgeService
{
    public const SCHEMA = 'atlas.agent_execution.integration_judgement.v1';

    public const STATUS_ACCEPTED = 'accepted_for_merge_governor';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_REPAIR_REQUIRED = 'repair_required';

    public const STATUS_OPERATOR_REVIEW_REQUIRED = 'operator_review_required';

    public const STATUS_BLOCKED_MISSING_EVIDENCE = 'blocked_missing_evidence';

    // AP-803 · Normalized, auditable judge decision enum. It is derived from
    // `status` + the decision reason so downstream gating (repair plan vs repair
    // execution, certification, Product Mode) keys off one stable vocabulary and a
    // scope violation or security blocker can never be confused with a repairable
    // failure.
    public const DECISION_ACCEPT = 'accept';

    public const DECISION_REPAIR_REQUIRED = 'repair_required';

    public const DECISION_BLOCKED_MISSING_EVIDENCE = 'blocked_missing_evidence';

    public const DECISION_BLOCKED_SCOPE_VIOLATION = 'blocked_scope_violation';

    public const DECISION_BLOCKED_SECURITY = 'blocked_security';

    public const DECISION_OPERATOR_REVIEW = 'operator_review';

    public const DECISION_REJECT = 'reject';

    /** Decisions that permit a bounded repair *execution* attempt. */
    public const REPAIR_EXECUTABLE_DECISIONS = [self::DECISION_REPAIR_REQUIRED];

    /** Decisions where repair may only *plan*, never execute (hard safety). */
    public const REPAIR_PLAN_ONLY_DECISIONS = [self::DECISION_BLOCKED_SCOPE_VIOLATION, self::DECISION_BLOCKED_SECURITY];

    public const DEFAULT_AREA_ID = 'agentic_engineering_os';

    /** @var list<string> */
    private const DEFAULT_FORBIDDEN_ACTIONS = [
        'merge',
        'merge_to_base',
        'deploy',
        'push',
        'force_push',
        'rebase',
        'secrets_access',
        'main_mutation',
        'provider_bypass',
    ];

    /** @var list<string> */
    private const SECURITY_SAFETY_BLOCKER_KINDS = [
        'security',
        'safety',
        'vulnerability',
        'injection',
        'secret_leak',
        'secrets',
        'data_loss',
        'destructive',
        'rce',
        'auth_bypass',
    ];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function judge(array $input): array
    {
        $lanePlan = is_array($input['lane_plan'] ?? null) ? $input['lane_plan'] : [];
        $laneResults = $this->laneResults($input['lane_results'] ?? []);
        $validationResult = is_array($input['validation_result'] ?? null) ? $input['validation_result'] : [];
        $diffSummary = is_array($input['diff_summary'] ?? null) ? $input['diff_summary'] : [];
        $evidenceRefs = is_array($input['evidence_refs'] ?? null) ? array_values($input['evidence_refs']) : [];

        $areaId = $this->slug((string) ($lanePlan['area_id'] ?? $input['area_id'] ?? self::DEFAULT_AREA_ID));
        $taskId = trim((string) ($lanePlan['task_id'] ?? $lanePlan['intent_id'] ?? ''));
        $sliceId = trim((string) ($lanePlan['slice_id'] ?? ''));
        $owner = trim((string) ($lanePlan['owner'] ?? '')) ?: 'unknown';
        $riskLevel = strtolower(trim((string) ($lanePlan['risk_level'] ?? 'medium'))) ?: 'medium';

        $changedFiles = $this->stringList($diffSummary['changed_files'] ?? []);
        $reviewer = $this->reviewerResult($laneResults);
        $repairPolicy = $this->repairPolicy($lanePlan['repair_policy'] ?? []);

        $scoring = [
            'validation_passed' => $this->scoreValidation($lanePlan, $validationResult),
            'evidence_complete' => $this->scoreEvidence($lanePlan, $evidenceRefs),
            'scope_respected' => $this->scoreScope($lanePlan, $changedFiles),
            'reviewer_approved' => $this->scoreReviewer($reviewer),
            'no_forbidden_actions' => $this->scoreForbiddenActions($lanePlan, $laneResults),
            'expected_diff_shape_matched' => $this->scoreDiffShape($lanePlan, $diffSummary),
            'risk_policy_satisfied' => $this->scoreRiskPolicy($lanePlan, $riskLevel),
        ];
        $allGatesPassed = array_reduce(
            $scoring,
            static fn (bool $carry, array $dim): bool => $carry && ($dim['passed'] === true),
            true,
        );

        $security = $this->securityBlockerSignal($reviewer);
        $decision = $this->decide(
            $lanePlan,
            $taskId,
            $changedFiles,
            $scoring,
            $reviewer,
            $security,
            $repairPolicy,
            $allGatesPassed,
        );

        $status = $decision['status'];
        $normalizedDecision = $this->normalizeDecision($status, $decision['reason']);
        $score = $this->buildScore($scoring, $security, $changedFiles, $allGatesPassed);

        $payload = [
            'schema_version' => self::SCHEMA,
            'ap_contract' => 'AP-798',
            'status' => $status,
            'decision' => $normalizedDecision,
            'decision_family' => $this->decisionFamily($normalizedDecision),
            'repair_eligible' => in_array($normalizedDecision, self::REPAIR_EXECUTABLE_DECISIONS, true),
            'repair_plan_only' => in_array($normalizedDecision, self::REPAIR_PLAN_ONLY_DECISIONS, true),
            'task_id' => $taskId,
            'slice_id' => $sliceId,
            'owner' => $owner,
            'risk_level' => $riskLevel,
            'area_id' => $areaId,
            'stack' => 'Atlas Software Company Stewardship Stack',
            'source_ap_contracts' => ['AP-769', 'AP-782', 'AP-793', 'AP-794', 'AP-798', 'AP-803'],
            'decision_reason' => $decision['reason'],
            'decision_detail' => $decision['detail'],
            'blockers' => $decision['blockers'],
            'scoring' => $scoring,
            'score' => $score,
            'all_gates_passed' => $allGatesPassed,
            'lane_summary' => $this->laneSummary($lanePlan, $laneResults),
            'reviewer_findings' => $this->reviewerFindings($reviewer),
            'merge_governor_handoff' => $this->mergeGovernorHandoff($status, $lanePlan, $areaId),
            'repair_handoff' => $this->repairHandoff($status, $decision, $repairPolicy),
            'operator_review' => $this->operatorReview($status, $decision),
            'next_actions' => $this->nextActions($status, $decision),
            'claim_policy' => $this->claimPolicy($status),
            'judged_at' => $this->now(),
        ];

        $payload['judgement_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($payload));
        $payload['judgement_id'] = 'maij_'.substr(MissionCanonicalHash::sha256($this->identity($payload)), 0, 18);

        return $payload;
    }

    /**
     * The fixed, first-match-wins decision order. Hard, unsafe and security
     * conditions are evaluated before recoverable ones so a scope violation or a
     * performed forbidden action can never be downgraded into a repair.
     *
     * @param  array<string,mixed>  $lanePlan
     * @param  list<string>  $changedFiles
     * @param  array<string,array<string,mixed>>  $scoring
     * @param  array<string,mixed>|null  $reviewer
     * @param  array<string,mixed>  $security
     * @param  array<string,mixed>  $repairPolicy
     * @return array{status:string,reason:string,detail:string,blockers:list<string>}
     */
    private function decide(
        array $lanePlan,
        string $taskId,
        array $changedFiles,
        array $scoring,
        ?array $reviewer,
        array $security,
        array $repairPolicy,
        bool $allGatesPassed,
    ): array {
        if ($taskId === '' || $lanePlan === []) {
            return $this->verdict(self::STATUS_BLOCKED_MISSING_EVIDENCE, 'lane_plan_required', 'A lane_plan with a task_id/intent_id is required to judge the run.');
        }

        if ($changedFiles === []) {
            return $this->verdict(self::STATUS_BLOCKED_MISSING_EVIDENCE, 'no_changed_files_to_judge', 'No changed files were reported; there is nothing to integrate.');
        }

        if ($scoring['scope_respected']['passed'] !== true) {
            return $this->verdict(self::STATUS_REJECTED, 'scope_violation', 'The diff touched files outside allowed_files or inside forbidden_files.');
        }

        if ($scoring['no_forbidden_actions']['passed'] !== true) {
            return $this->verdict(self::STATUS_REJECTED, 'forbidden_action_performed', 'A lane performed a forbidden action (merge/deploy/secrets/push/etc.).');
        }

        if ($security['present']) {
            if ($security['hard_reject']) {
                return $this->verdict(self::STATUS_REJECTED, 'reviewer_security_reject', 'A reviewer raised a critical security/safety blocker or rejected the change.');
            }

            return $this->verdict(self::STATUS_OPERATOR_REVIEW_REQUIRED, 'security_safety_review_required', 'A reviewer flagged a security/safety concern; a human must decide.');
        }

        if ($scoring['evidence_complete']['passed'] !== true) {
            return $this->verdict(self::STATUS_BLOCKED_MISSING_EVIDENCE, 'evidence_obligations_missing', 'Required evidence obligations were not satisfied; the judgement cannot be trusted.');
        }

        if ($scoring['validation_passed']['passed'] !== true) {
            if ($repairPolicy['has_attempts']) {
                return $this->verdict(self::STATUS_REPAIR_REQUIRED, 'validation_failed_repair_allowed', 'Validation failed and repair policy still has attempts remaining.');
            }

            return $this->verdict(self::STATUS_REJECTED, 'validation_failed_repair_exhausted', 'Validation failed and no repair attempts remain.');
        }

        if ($scoring['reviewer_approved']['passed'] !== true) {
            $decisionKind = (string) ($reviewer['decision'] ?? '');
            if ($decisionKind === 'reject') {
                return $this->verdict(self::STATUS_REJECTED, 'reviewer_rejected', 'The reviewer lane rejected the change.');
            }
            if ($decisionKind === 'request_changes') {
                if ($repairPolicy['has_attempts']) {
                    return $this->verdict(self::STATUS_REPAIR_REQUIRED, 'reviewer_requested_changes_repair_allowed', 'The reviewer requested changes and repair policy still has attempts.');
                }

                return $this->verdict(self::STATUS_OPERATOR_REVIEW_REQUIRED, 'reviewer_requested_changes_no_repair', 'The reviewer requested changes but no repair attempts remain.');
            }

            return $this->verdict(self::STATUS_OPERATOR_REVIEW_REQUIRED, 'reviewer_approval_missing', 'No reviewer lane approved the change.');
        }

        if ($scoring['expected_diff_shape_matched']['passed'] !== true) {
            return $this->verdict(self::STATUS_OPERATOR_REVIEW_REQUIRED, 'diff_shape_mismatch', 'The observed diff shape diverged from the plan; a human must confirm.');
        }

        if ($scoring['risk_policy_satisfied']['passed'] !== true) {
            return $this->verdict(self::STATUS_OPERATOR_REVIEW_REQUIRED, 'risk_policy_requires_review', 'The merge policy is not consistent with the declared risk level.');
        }

        if (! $allGatesPassed) {
            return $this->verdict(self::STATUS_OPERATOR_REVIEW_REQUIRED, 'residual_gate_failure', 'A scoring gate failed without a more specific route; a human must decide.');
        }

        return $this->verdict(self::STATUS_ACCEPTED, 'all_gates_passed', 'Every gate passed; forwarding to the AP-769 merge governor without merging.');
    }

    /**
     * @return array{status:string,reason:string,detail:string,blockers:list<string>}
     */
    private function verdict(string $status, string $reason, string $detail): array
    {
        return [
            'status' => $status,
            'reason' => $reason,
            'detail' => $detail,
            'blockers' => $status === self::STATUS_ACCEPTED ? [] : [$reason],
        ];
    }

    /**
     * AP-803 · Collapse the detailed (status, reason) verdict into one stable,
     * auditable decision enum. Scope violations and security/safety blockers get
     * their own decisions so repair gating and certification never treat them as a
     * generic, repairable rejection.
     */
    private function normalizeDecision(string $status, string $reason): string
    {
        return match ($status) {
            self::STATUS_ACCEPTED => self::DECISION_ACCEPT,
            self::STATUS_REPAIR_REQUIRED => self::DECISION_REPAIR_REQUIRED,
            self::STATUS_BLOCKED_MISSING_EVIDENCE => self::DECISION_BLOCKED_MISSING_EVIDENCE,
            self::STATUS_REJECTED => match ($reason) {
                'scope_violation' => self::DECISION_BLOCKED_SCOPE_VIOLATION,
                'reviewer_security_reject' => self::DECISION_BLOCKED_SECURITY,
                default => self::DECISION_REJECT,
            },
            self::STATUS_OPERATOR_REVIEW_REQUIRED => $reason === 'security_safety_review_required'
                ? self::DECISION_BLOCKED_SECURITY
                : self::DECISION_OPERATOR_REVIEW,
            default => self::DECISION_REJECT,
        };
    }

    private function decisionFamily(string $decision): string
    {
        return match ($decision) {
            self::DECISION_ACCEPT => 'accept',
            self::DECISION_REPAIR_REQUIRED => 'repair',
            self::DECISION_BLOCKED_MISSING_EVIDENCE,
            self::DECISION_BLOCKED_SCOPE_VIOLATION,
            self::DECISION_BLOCKED_SECURITY => 'blocked',
            self::DECISION_OPERATOR_REVIEW => 'operator_review',
            default => 'reject',
        };
    }

    /**
     * AP-803 · The seven-dimension score the operator reviews. Each dimension is a
     * named projection over the detailed gate scoring plus the security signal, so
     * the human sees scope/tests/evidence/safety/maintainability/value/merge in one
     * shape. The detailed `scoring` block is preserved unchanged for audit.
     *
     * @param  array<string,array<string,mixed>>  $scoring
     * @param  array{present:bool,hard_reject:bool,blockers:list<array<string,mixed>>}  $security
     * @param  list<string>  $changedFiles
     * @return array<string,array<string,mixed>>
     */
    private function buildScore(array $scoring, array $security, array $changedFiles, bool $allGatesPassed): array
    {
        $scopeOk = ($scoring['scope_respected']['passed'] ?? false) === true;
        $testsOk = ($scoring['validation_passed']['passed'] ?? false) === true;
        $evidenceOk = ($scoring['evidence_complete']['passed'] ?? false) === true;
        $forbiddenOk = ($scoring['no_forbidden_actions']['passed'] ?? false) === true;
        $shapeOk = ($scoring['expected_diff_shape_matched']['passed'] ?? false) === true;
        $riskOk = ($scoring['risk_policy_satisfied']['passed'] ?? false) === true;
        $safetyOk = $forbiddenOk && ! $security['present'];
        // "value" is a proxy for "this cycle produced real, in-scope, validated work"
        // — not churn and not an empty diff.
        $valueOk = $changedFiles !== [] && $scopeOk && $testsOk;

        return [
            'scope' => $this->scoreDim($scopeOk, 'scope_respected', $scoring['scope_respected']['reason'] ?? null),
            'tests' => $this->scoreDim($testsOk, 'validation_passed', $scoring['validation_passed']['reason'] ?? null),
            'evidence' => $this->scoreDim($evidenceOk, 'evidence_complete', $scoring['evidence_complete']['reason'] ?? null),
            'safety' => $this->scoreDim($safetyOk, 'no_forbidden_actions_and_no_security_blocker', $security['present'] ? 'security_or_safety_blocker_present' : ($scoring['no_forbidden_actions']['reason'] ?? null)),
            'maintainability' => $this->scoreDim($shapeOk, 'expected_diff_shape_matched', $scoring['expected_diff_shape_matched']['reason'] ?? null),
            'value' => $this->scoreDim($valueOk, 'in_scope_validated_change', $valueOk ? 'delivered_in_scope_validated_change' : 'no_validated_in_scope_change'),
            'merge_readiness' => $this->scoreDim($allGatesPassed && $riskOk, 'all_gates_and_risk_policy', $allGatesPassed && $riskOk ? 'ready_for_merge_governor' : 'not_ready_for_merge_governor'),
        ];
    }

    /**
     * @return array{passed:bool,source:string,signal:string|null}
     */
    private function scoreDim(bool $passed, string $source, ?string $signal): array
    {
        return ['passed' => $passed, 'source' => $source, 'signal' => $signal];
    }

    /**
     * @param  array<string,mixed>  $lanePlan
     * @param  array<string,mixed>  $validationResult
     * @return array<string,mixed>
     */
    private function scoreValidation(array $lanePlan, array $validationResult): array
    {
        $requiredCommands = $this->stringList($lanePlan['validation_commands'] ?? []);
        $ran = (bool) ($validationResult['ran'] ?? false);
        $passed = $validationResult['passed'] ?? null;

        $ok = $passed === true;

        return [
            'passed' => $ok,
            'validation_ran' => $ran,
            'validation_passed_flag' => $passed,
            'required_command_count' => count($requiredCommands),
            'reason' => $ok
                ? 'validation_passed'
                : ($passed === null ? 'validation_not_run_or_unknown' : 'validation_failed'),
        ];
    }

    /**
     * @param  array<string,mixed>  $lanePlan
     * @param  list<mixed>  $evidenceRefs
     * @return array<string,mixed>
     */
    private function scoreEvidence(array $lanePlan, array $evidenceRefs): array
    {
        $obligations = $this->stringList($lanePlan['evidence_obligations'] ?? []);
        $providedKinds = $this->evidenceKinds($evidenceRefs);

        $missing = array_values(array_filter(
            $obligations,
            static fn (string $kind): bool => ! in_array($kind, $providedKinds, true),
        ));

        if ($obligations === []) {
            // No explicit obligations: require at least one evidence ref so the
            // judgement is never made on zero evidence.
            $ok = $evidenceRefs !== [];
        } else {
            $ok = $missing === [];
        }

        return [
            'passed' => $ok,
            'obligation_count' => count($obligations),
            'provided_kinds' => $providedKinds,
            'missing_obligations' => $missing,
            'evidence_ref_count' => count($evidenceRefs),
            'reason' => $ok ? 'evidence_complete' : ($obligations === [] ? 'no_evidence_provided' : 'missing_required_evidence'),
        ];
    }

    /**
     * @param  array<string,mixed>  $lanePlan
     * @param  list<string>  $changedFiles
     * @return array<string,mixed>
     */
    private function scoreScope(array $lanePlan, array $changedFiles): array
    {
        $allowed = $this->stringList($lanePlan['allowed_files'] ?? []);
        $forbidden = $this->stringList($lanePlan['forbidden_files'] ?? []);

        if ($allowed === []) {
            return [
                'passed' => false,
                'allowed_files_declared' => false,
                'out_of_scope_files' => $changedFiles,
                'forbidden_files_touched' => [],
                'reason' => 'allowed_files_not_declared',
            ];
        }

        $outOfScope = [];
        $forbiddenTouched = [];
        foreach ($changedFiles as $file) {
            if ($this->matchesAny($file, $forbidden)) {
                $forbiddenTouched[] = $file;

                continue;
            }
            if (! $this->matchesAny($file, $allowed)) {
                $outOfScope[] = $file;
            }
        }

        $ok = $outOfScope === [] && $forbiddenTouched === [];

        return [
            'passed' => $ok,
            'allowed_files_declared' => true,
            'out_of_scope_files' => $outOfScope,
            'forbidden_files_touched' => $forbiddenTouched,
            'reason' => $ok ? 'scope_respected' : ($forbiddenTouched !== [] ? 'forbidden_files_touched' : 'files_outside_allowed_scope'),
        ];
    }

    /**
     * @param  array<string,mixed>|null  $reviewer
     * @return array<string,mixed>
     */
    private function scoreReviewer(?array $reviewer): array
    {
        if ($reviewer === null) {
            return [
                'passed' => false,
                'reviewer_present' => false,
                'reviewer_decision' => null,
                'reason' => 'reviewer_lane_missing',
            ];
        }

        $decision = (string) ($reviewer['decision'] ?? '');
        $ok = $decision === 'approve';

        return [
            'passed' => $ok,
            'reviewer_present' => true,
            'reviewer_decision' => $decision !== '' ? $decision : null,
            'reason' => $ok ? 'reviewer_approved' : 'reviewer_not_approved',
        ];
    }

    /**
     * @param  array<string,mixed>  $lanePlan
     * @param  list<array<string,mixed>>  $laneResults
     * @return array<string,mixed>
     */
    private function scoreForbiddenActions(array $lanePlan, array $laneResults): array
    {
        $forbidden = $this->stringList($lanePlan['forbidden_actions'] ?? []);
        $forbidden = $forbidden === []
            ? self::DEFAULT_FORBIDDEN_ACTIONS
            : StewardshipStringListNormalizer::uniqueMergedStrings($forbidden, self::DEFAULT_FORBIDDEN_ACTIONS);

        $violations = [];
        foreach ($laneResults as $result) {
            $lane = (string) ($result['lane'] ?? $result['role'] ?? 'unknown');
            foreach ($this->stringList($result['performed_actions'] ?? []) as $action) {
                if (in_array($action, $forbidden, true)) {
                    $violations[] = ['lane' => $lane, 'action' => $action];
                }
            }
        }

        $ok = $violations === [];

        return [
            'passed' => $ok,
            'forbidden_action_count' => count($forbidden),
            'violations' => $violations,
            'reason' => $ok ? 'no_forbidden_actions' : 'forbidden_action_performed',
        ];
    }

    /**
     * @param  array<string,mixed>  $lanePlan
     * @param  array<string,mixed>  $diffSummary
     * @return array<string,mixed>
     */
    private function scoreDiffShape(array $lanePlan, array $diffSummary): array
    {
        $expected = $this->normalizeShape((string) ($lanePlan['expected_diff_shape'] ?? ''));
        $observed = $this->normalizeShape((string) ($diffSummary['diff_shape'] ?? ''));

        if ($expected === '') {
            // Plan declared no expectation: cannot fail a shape that was never set.
            $ok = true;
            $reason = 'no_expected_diff_shape_declared';
        } elseif ($observed === '') {
            $ok = false;
            $reason = 'observed_diff_shape_missing';
        } else {
            $ok = $expected === $observed;
            $reason = $ok ? 'diff_shape_matched' : 'diff_shape_mismatch';
        }

        return [
            'passed' => $ok,
            'expected' => $expected,
            'observed' => $observed,
            'reason' => $reason,
        ];
    }

    /**
     * @param  array<string,mixed>  $lanePlan
     * @return array<string,mixed>
     */
    private function scoreRiskPolicy(array $lanePlan, string $riskLevel): array
    {
        $mergePolicy = strtolower(trim((string) ($lanePlan['merge_policy'] ?? '')));
        $highRisk = in_array($riskLevel, ['high', 'critical'], true);

        // High/critical risk may never declare itself auto-merge eligible; it
        // must route through review.
        $ok = ! ($highRisk && $mergePolicy === 'auto_merge_eligible');

        return [
            'passed' => $ok,
            'risk_level' => $riskLevel,
            'merge_policy' => $mergePolicy !== '' ? $mergePolicy : null,
            'high_risk' => $highRisk,
            'reason' => $ok ? 'risk_policy_satisfied' : 'high_risk_cannot_auto_merge',
        ];
    }

    /**
     * @param  array<string,mixed>|null  $reviewer
     * @return array{present:bool,hard_reject:bool,blockers:list<array<string,mixed>>}
     */
    private function securityBlockerSignal(?array $reviewer): array
    {
        if ($reviewer === null) {
            return ['present' => false, 'hard_reject' => false, 'blockers' => []];
        }

        $decision = (string) ($reviewer['decision'] ?? '');
        $securityBlockers = [];
        $hardReject = false;
        foreach ($this->blockerList($reviewer['blockers'] ?? []) as $blocker) {
            $kind = strtolower((string) ($blocker['kind'] ?? ''));
            if (! in_array($kind, self::SECURITY_SAFETY_BLOCKER_KINDS, true)) {
                continue;
            }
            $severity = strtolower((string) ($blocker['severity'] ?? ''));
            $securityBlockers[] = $blocker;
            if ($severity === 'critical' || $decision === 'reject') {
                $hardReject = true;
            }
        }

        return [
            'present' => $securityBlockers !== [],
            'hard_reject' => $hardReject,
            'blockers' => $securityBlockers,
        ];
    }

    /**
     * @param  array<string,mixed>  $raw
     * @return array{allowed:bool,max_attempts:int,attempts_used:int,remaining:int,has_attempts:bool}
     */
    private function repairPolicy(array $raw): array
    {
        $allowed = (bool) ($raw['allowed'] ?? false);
        $maxAttempts = max(0, (int) ($raw['max_attempts'] ?? 0));
        $attemptsUsed = max(0, (int) ($raw['attempts_used'] ?? 0));
        $remaining = max(0, $maxAttempts - $attemptsUsed);

        return [
            'allowed' => $allowed,
            'max_attempts' => $maxAttempts,
            'attempts_used' => $attemptsUsed,
            'remaining' => $remaining,
            'has_attempts' => $allowed && $remaining > 0,
        ];
    }

    /**
     * @param  array<string,mixed>  $lanePlan
     * @return array<string,mixed>|null
     */
    private function mergeGovernorHandoff(string $status, array $lanePlan, string $areaId): ?array
    {
        if ($status !== self::STATUS_ACCEPTED) {
            return null;
        }

        return [
            'eligible' => true,
            'forwarded_to' => 'AP-769 StewardshipBranchMergeGovernorService',
            'area_id' => $areaId,
            'branch_ref' => trim((string) ($lanePlan['branch_ref'] ?? '')) ?: null,
            'base_ref' => trim((string) ($lanePlan['base_ref'] ?? 'main')) ?: 'main',
            'recommended_merge_policy' => strtolower(trim((string) ($lanePlan['merge_policy'] ?? 'review_required'))) ?: 'review_required',
            'merge_performed_here' => false,
            'note' => 'The judge does not merge. The merge governor evaluates and may merge under AP-769/AP-782 policy.',
        ];
    }

    /**
     * @param  array{status:string,reason:string,detail:string,blockers:list<string>}  $decision
     * @param  array{allowed:bool,max_attempts:int,attempts_used:int,remaining:int,has_attempts:bool}  $repairPolicy
     * @return array<string,mixed>|null
     */
    private function repairHandoff(string $status, array $decision, array $repairPolicy): ?array
    {
        if ($status !== self::STATUS_REPAIR_REQUIRED) {
            return null;
        }

        return [
            'required' => true,
            'reason' => $decision['reason'],
            'attempts_used' => $repairPolicy['attempts_used'],
            'max_attempts' => $repairPolicy['max_attempts'],
            'remaining_attempts' => $repairPolicy['remaining'],
            'note' => 'Routed to the repair service (separate AP). The judge does not repair.',
        ];
    }

    /**
     * @param  array{status:string,reason:string,detail:string,blockers:list<string>}  $decision
     * @return array<string,mixed>|null
     */
    private function operatorReview(string $status, array $decision): ?array
    {
        if ($status !== self::STATUS_OPERATOR_REVIEW_REQUIRED) {
            return null;
        }

        return [
            'required' => true,
            'reason' => $decision['reason'],
            'detail' => $decision['detail'],
        ];
    }

    /**
     * @param  array{status:string,reason:string,detail:string,blockers:list<string>}  $decision
     * @return list<string>
     */
    private function nextActions(string $status, array $decision): array
    {
        return match ($status) {
            self::STATUS_ACCEPTED => ['Forward the composed branch to the AP-769 merge governor; it decides and performs any merge.'],
            self::STATUS_REPAIR_REQUIRED => ['Route to the repair lane within repair policy, then re-judge.'],
            self::STATUS_OPERATOR_REVIEW_REQUIRED => ['Escalate to the operator for review: '.$decision['detail']],
            self::STATUS_BLOCKED_MISSING_EVIDENCE => ['Provide the missing inputs/evidence and re-run the judge.'],
            default => ['Reject and do not merge: '.$decision['detail']],
        };
    }

    /**
     * @param  array<string,mixed>  $lanePlan
     * @param  list<array<string,mixed>>  $laneResults
     * @return array<string,mixed>
     */
    private function laneSummary(array $lanePlan, array $laneResults): array
    {
        $expected = $this->stringList($lanePlan['expected_lanes'] ?? []);
        $observed = [];
        $failed = [];
        foreach ($laneResults as $result) {
            $lane = (string) ($result['lane'] ?? $result['role'] ?? 'unknown');
            $observed[] = $lane;
            if (in_array((string) ($result['status'] ?? ''), ['failed', 'blocked'], true)) {
                $failed[] = $lane;
            }
        }
        $observed = StewardshipStringListNormalizer::uniqueStrings($observed);
        $missing = array_values(array_filter(
            $expected,
            static fn (string $lane): bool => ! in_array($lane, $observed, true),
        ));

        return [
            'expected_lanes' => $expected,
            'observed_lanes' => $observed,
            'missing_lanes' => $missing,
            'failed_lanes' => StewardshipStringListNormalizer::uniqueStrings($failed),
            'lane_result_count' => count($laneResults),
        ];
    }

    /**
     * @param  array<string,mixed>|null  $reviewer
     * @return list<array<string,mixed>>
     */
    private function reviewerFindings(?array $reviewer): array
    {
        if ($reviewer === null) {
            return [];
        }

        $findings = [];
        foreach ($this->blockerList($reviewer['blockers'] ?? []) as $blocker) {
            $kind = strtolower((string) ($blocker['kind'] ?? ''));
            $findings[] = [
                'kind' => $kind !== '' ? $kind : 'unspecified',
                'severity' => strtolower((string) ($blocker['severity'] ?? 'unspecified')) ?: 'unspecified',
                'detail' => (string) ($blocker['detail'] ?? ''),
                'security_or_safety' => in_array($kind, self::SECURITY_SAFETY_BLOCKER_KINDS, true),
            ];
        }

        return $findings;
    }

    /**
     * @return array<string,bool>
     */
    private function claimPolicy(string $status): array
    {
        return [
            'merge_performed' => false,
            'merges' => false,
            'forwards_to_merge_governor' => $status === self::STATUS_ACCEPTED,
            'provider_invoked' => false,
            'uses_llm' => false,
            'mutates_repo' => false,
            'creates_branch' => false,
            'pushes' => false,
            'rebases' => false,
            'touches_secrets' => false,
            'deterministic' => true,
            'rules_engine' => true,
        ];
    }

    /**
     * @param  mixed  $raw
     * @return list<array<string,mixed>>
     */
    private function laneResults(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($item) => is_array($item) ? $item : null, $raw),
            static fn ($item): bool => $item !== null,
        ));
    }

    /**
     * @param  list<array<string,mixed>>  $laneResults
     * @return array<string,mixed>|null
     */
    private function reviewerResult(array $laneResults): ?array
    {
        foreach ($laneResults as $result) {
            $lane = (string) ($result['lane'] ?? $result['role'] ?? '');
            if ($lane !== 'reviewer') {
                continue;
            }
            $review = is_array($result['review'] ?? null) ? $result['review'] : [];

            return $review !== [] ? $review : ['decision' => (string) ($result['decision'] ?? '')];
        }

        return null;
    }

    /**
     * @param  mixed  $raw
     * @return list<array<string,mixed>>
     */
    private function blockerList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($item) => is_array($item) ? $item : null, $raw),
            static fn ($item): bool => $item !== null,
        ));
    }

    /**
     * @param  list<mixed>  $evidenceRefs
     * @return list<string>
     */
    private function evidenceKinds(array $evidenceRefs): array
    {
        $kinds = [];
        foreach ($evidenceRefs as $ref) {
            if (is_string($ref)) {
                $kind = trim($ref);
            } elseif (is_array($ref)) {
                $kind = trim((string) ($ref['kind'] ?? ''));
            } else {
                $kind = '';
            }
            if ($kind !== '') {
                $kinds[] = $kind;
            }
        }

        return StewardshipStringListNormalizer::uniqueStrings($kinds);
    }

    private function matchesAny(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($this->matchesPattern($path, (string) $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function matchesPattern(string $path, string $pattern): bool
    {
        $pattern = trim($pattern);
        if ($pattern === '') {
            return false;
        }
        if ($pattern === $path) {
            return true;
        }
        // Directory prefix: "app/Services/" matches everything under it.
        if (str_ends_with($pattern, '/') && str_starts_with($path, $pattern)) {
            return true;
        }
        // Glob: support ** (any depth) and * (single segment) and ?.
        if (str_contains($pattern, '*') || str_contains($pattern, '?')) {
            return preg_match($this->globToRegex($pattern), $path) === 1;
        }

        return false;
    }

    private function globToRegex(string $glob): string
    {
        $regex = '';
        $length = strlen($glob);
        for ($i = 0; $i < $length; $i++) {
            $char = $glob[$i];
            if ($char === '*') {
                if (($glob[$i + 1] ?? '') === '*') {
                    $regex .= '.*';
                    $i++;
                } else {
                    $regex .= '[^/]*';
                }

                continue;
            }
            if ($char === '?') {
                $regex .= '[^/]';

                continue;
            }
            $regex .= preg_quote($char, '#');
        }

        return '#^'.$regex.'$#';
    }

    private function normalizeShape(string $shape): string
    {
        return strtolower(trim(str_replace(['-', ' '], '_', $shape)));
    }

    /**
     * @param  mixed  $raw
     * @return list<string>
     */
    private function stringList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($item): string => is_string($item) ? trim($item) : '', $raw),
            static fn (string $item): bool => $item !== '',
        ));
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function identity(array $payload): array
    {
        $copy = $payload;
        unset($copy['judged_at'], $copy['judgement_hash'], $copy['judgement_id']);

        return $copy;
    }

    private function slug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9_\-]+/', '_', $slug) ?: self::DEFAULT_AREA_ID;

        return trim($slug, '_-') ?: self::DEFAULT_AREA_ID;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}
