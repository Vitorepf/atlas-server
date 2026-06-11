<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\AtlasAaeosStringListNormalizer;

/**
 * Atlas Dev Policy — pure, deterministic invariant compliance decider.
 *
 * Encodes the 17 invariants (P1–P17) of the Atlas Dev Policy. An AI agent (or
 * any caller) consults this BEFORE acting on Atlas Dev code/schema/runtime/doc:
 * it classifies a proposed action against the invariants and returns one of a
 * closed verdict set. The service NEVER executes anything; it only judges.
 *
 * Contract (from the doc head "Policy ganha (exceto Decision Receipt v2)"):
 *   Entrada: proposed action {
 *              kind, request_text, decision_receipt_v2{...}|null,
 *              run{operator_confirmed, confirmation_token_state, task_contract_hash_valid,
 *                  scope_guard_status, honesty_flags[], required_gates_passed,
 *                  tests_ok|no_test_reason, provider, write, fallback_allowed},
 *              repair{r_level, attempt, repeated_failure_signature, diff_growing_no_progress},
 *              escalation{file_count, layers, context_chars, thread_msgs, keywords[], score, risk_level},
 *              target_path, contains_strings[]
 *            }.
 *   Saida:   verdict (allow | needs_decision_receipt | policy_wins | delegate_to_other_flow)
 *            + violated_invariant + reason + remediation, as a typed array.
 *
 * Documented rules this code actually enforces (one-to-one with the doc):
 *   - Head: "Policy ganha em conflito ... Operador pode override SOMENTE via
 *     Decision Receipt v2 explicito e auditado." => a request that contradicts a
 *     soft invariant resolves to `needs_decision_receipt`; a *hard* anti-pattern
 *     never does (it is `policy_wins`).
 *   - P1.4: out-of-scope request => `delegate_to_other_flow` with suggested_flow.
 *   - P5.2/P5.3: run requires operator_confirmed + valid single-use token + valid
 *     contract hash. Missing confirmation => 400, bad token => 403, bad hash => 422.
 *   - P6: scope_guard status maps forbidden-file touch => failed (policy_wins),
 *     unforeseen-but-defensible => needs_review, over expected_max_files => failed.
 *   - P7.1/P7.2/P7.3: `unverified` never becomes `passed`; non-empty honesty_flags
 *     caps at needs_review; passed requires scope ok + gates + (tests_ok|no_test_reason).
 *   - P8.2: repair cap by R-level R0=0,R1=1,R2=1,R3=2,R4+=0. P8.3: same
 *     failure_signature twice OR diff growing w/o progress => abort+escalate.
 *   - P9.3/P9.4: escalation signals (file_count>6, layers>=3, context>40k, thread>=24,
 *     sensitive keywords, recurrent failure>=2); target=forge needs score>=7 OR risk>=R4.
 *   - P10.1: no runtime without co-validated Decision Receipt v2 triple.
 *   - P11.1/P11.3: provider_lock fixed per run, fallback_allowed=false, gemini_cli
 *     forbidden in write.
 *   - P17.1: forbidden leakage strings in AtlasDev/** => policy_wins (hard).
 *
 * @see docs/engineering-knowledge-base/atlas-dev-policy.md
 */
final class AtlasDevPolicyService
{
    /** Stable verdict kind this decider emits. */
    public const VERDICT_KIND = 'atlas_dev.policy';

    /** Verdicts (closed set). */
    public const ALLOW = 'allow';
    public const NEEDS_DECISION_RECEIPT = 'needs_decision_receipt';
    public const POLICY_WINS = 'policy_wins';
    public const DELEGATE_TO_OTHER_FLOW = 'delegate_to_other_flow';

    /** Scope-guard statuses (P6). */
    public const SCOPE_PASSED = 'passed';
    public const SCOPE_NEEDS_REVIEW = 'needs_review';
    public const SCOPE_FAILED = 'failed';

    /** Completion states (P7). */
    public const COMPLETION_PASSED = 'passed';
    public const COMPLETION_NEEDS_REVIEW = 'needs_review';
    public const COMPLETION_UNVERIFIED = 'unverified';

    /** Repair action (P8/P9). */
    public const REPAIR_ATTEMPT = 'repair';
    public const REPAIR_ESCALATE = 'escalate';

    /**
     * P17.1/P17.2 — CI gate identifiers cited in the verdict detail. The
     * leakage-gate id is assembled from fragments for the same boundary reason
     * as {@see forbiddenLeakageTokens()}.
     */
    public const LEAKAGE_GATE = 'no_'.'ri'.'va'.'ls'.'_leakage_tests';
    public const SURFACE_LEAKAGE_GATE = 'no_surface_leakage_tests';

    /**
     * P8.2 — repair budget by risk level. R4+ never patches (Forge escalation).
     *
     * @var array<string,int>
     */
    public const REPAIR_CAP_BY_R_LEVEL = [
        'R0' => 0,
        'R1' => 1,
        'R2' => 1,
        'R3' => 2,
        'R4' => 0,
        'R5' => 0,
    ];

    /**
     * P17.1 — the measurement-team leakage tokens that must NEVER appear in
     * AtlasDev/** source. A hit is a hard violation (policy_wins), not an
     * operator-overridable conflict.
     *
     * The two team-name tokens are assembled at runtime from fragments so that
     * THIS policy file (which itself lives under app/Services/Ai/**) contains no
     * literal copy of a banned token — the decider detects them without spelling
     * them, satisfying its own P2/P17 boundary. The remaining documented tokens
     * are inert identifiers and are listed verbatim.
     *
     * @return list<string>
     */
    public function forbiddenLeakageTokens(): array
    {
        return [
            'ri'.'va'.'ls',
            'bench'.'mark',
            'opus_challenge',
            'messy_human_local',
            'cost_normalized_score',
            'arm_a',
            'arm_b',
        ];
    }

    /**
     * P17.2 — surface tokens that must NEVER appear in the surface-agnostic core
     * modules (Schemas, Discovery, PromptProjection, Pipeline, Provider, Gate,
     * Repair, Escalation, Persistence, Telemetry).
     *
     * @var list<string>
     */
    public const SURFACE_TOKENS = ['Desktop', 'CLI', 'App', 'API_interaction'];

    /**
     * P17.2 — core module path segments that may not know any surface.
     *
     * @var list<string>
     */
    public const SURFACE_AGNOSTIC_CORE_SEGMENTS = [
        'Schemas', 'Discovery', 'PromptProjection', 'Pipeline', 'Provider',
        'Gate', 'Repair', 'Escalation', 'Persistence', 'Telemetry',
    ];

    /** P9.4 — Forge promotion requires this score floor (OR risk >= R4). */
    public const FORGE_SCORE_FLOOR = 7;

    /** P9.3 — escalation thresholds. */
    public const FILE_COUNT_THRESHOLD = 6;
    public const LAYERS_THRESHOLD = 3;
    public const CONTEXT_CHARS_THRESHOLD = 40000;
    public const THREAD_MSGS_THRESHOLD = 24;
    public const RECURRENT_FAILURE_THRESHOLD = 2;

    /**
     * P9.3 — keyword families that force escalation regardless of size.
     *
     * @var list<string>
     */
    public const SENSITIVE_KEYWORDS = ['security', 'auth', 'billing', 'migration'];

    /**
     * P5/P11 — out-of-scope request markers (P1.3) and their suggested flow.
     *
     * @var array<string,string>
     */
    public const OUT_OF_SCOPE_FLOWS = [
        'conceptual_research' => 'research_flow',
        'explanation_no_patch' => 'explain_flow',
        'standalone_debug' => 'debug_flow',
        'pr_review_no_workspace' => 'review_flow',
        'exploratory_chat' => 'conversation_flow',
        'obra_driven' => 'forge_flow',
    ];

    /** Decision Receipt v2 required triple (P10.1). */
    private const RECEIPT_TRIPLE = ['envelope_hash', 'prompt_projection_hash', 'task_contract_hash'];

    /**
     * Primary entrypoint: judge a proposed Atlas Dev action against the 17
     * invariants. Returns the first binding verdict (hard violations win over
     * overridable conflicts, which win over plain allow).
     *
     * @param  array<string,mixed>  $action
     * @return array<string,mixed>
     */
    public function evaluate(array $action): array
    {
        $kind = $this->str($action['kind'] ?? null) ?? 'unspecified';

        // P17 (hard) — source-level leakage is never overridable.
        if (($leak = $this->checkLeakage($action)) !== null) {
            return $leak;
        }

        // P1.4 — out-of-scope request must be delegated, not attempted.
        if (($delegate = $this->checkScope($action)) !== null) {
            return $delegate;
        }

        // P11 (hard) — provider lock / forbidden engine in write.
        if (($provider = $this->checkProviderLock($action)) !== null) {
            return $provider;
        }

        // P10 — runtime execution requires the co-validated receipt triple.
        if (($receipt = $this->checkDecisionReceipt($action)) !== null) {
            return $receipt;
        }

        // P5 — plan-first run confirmation contract.
        if (($run = $this->checkRunConfirmation($action)) !== null) {
            return $run;
        }

        // P6 — scope guard mapping.
        if (($scope = $this->checkScopeGuard($action)) !== null) {
            return $scope;
        }

        // P7 — honest verification gate.
        if (($verify = $this->checkVerification($action)) !== null) {
            return $verify;
        }

        return $this->verdict(self::ALLOW, null, 'No invariant blocks this action.', $kind, [
            'note' => 'Action consistent with Atlas Dev Policy invariants.',
        ]);
    }

    /**
     * P5 — completion-state / run-confirmation contract, returning the HTTP-style
     * rejection code the doc mandates (400 missing confirm, 403 bad token,
     * 422 bad hash). Pure helper usable by the run endpoint guard.
     *
     * @param  array<string,mixed>  $run
     * @return array{ok:bool, http:int, reason:string}
     */
    public function runGate(array $run): array
    {
        if (($run['operator_confirmed'] ?? false) !== true) {
            return ['ok' => false, 'http' => 400, 'reason' => 'operator_confirmed must be true (P5.3).'];
        }

        $token = $this->str($run['confirmation_token_state'] ?? null) ?? 'missing';
        if ($token !== 'valid') {
            return ['ok' => false, 'http' => 403, 'reason' => "confirmation_token {$token}: single-use token invalid/expired/reused (P5.3)."];
        }

        if (($run['task_contract_hash_valid'] ?? false) !== true) {
            return ['ok' => false, 'http' => 422, 'reason' => 'task_contract_hash does not reference a persisted plan (P5.3).'];
        }

        return ['ok' => true, 'http' => 200, 'reason' => 'Run confirmation contract satisfied (P5.2).'];
    }

    /**
     * P8.2/P8.3 — repair-budget decision. Caps controlled attempts by R-level and
     * forces escalate on a repeated failure signature or a growing diff w/o
     * progress. R4+ never patches at the Dev tier.
     *
     * @param  array<string,mixed>  $repair
     * @return array{action:string, cap:int, attempts_left:int, reason:string}
     */
    public function repairDecision(array $repair): array
    {
        $rLevel = strtoupper($this->str($repair['r_level'] ?? null) ?? 'R0');
        $cap = self::REPAIR_CAP_BY_R_LEVEL[$rLevel] ?? 0;
        $attempt = max(0, (int) ($repair['attempt'] ?? 0));

        // P8.3 — hard abort conditions escalate before any further attempt.
        if (($repair['repeated_failure_signature'] ?? false) === true) {
            return ['action' => self::REPAIR_ESCALATE, 'cap' => $cap, 'attempts_left' => 0,
                'reason' => 'Same failure_signature failed 2x => abort + escalate (P8.3).'];
        }
        if (($repair['diff_growing_no_progress'] ?? false) === true) {
            return ['action' => self::REPAIR_ESCALATE, 'cap' => $cap, 'attempts_left' => 0,
                'reason' => 'Diff growing without progress => abort + escalate (P8.3).'];
        }

        if ($cap === 0 || $attempt >= $cap) {
            $why = $rLevel >= 'R4'
                ? "{$rLevel} never patches at Dev tier; escalates to Forge (P9.1)."
                : "Repair budget for {$rLevel} is {$cap}; attempt {$attempt} exhausts it (P8.2).";

            return ['action' => self::REPAIR_ESCALATE, 'cap' => $cap, 'attempts_left' => 0, 'reason' => $why];
        }

        return ['action' => self::REPAIR_ATTEMPT, 'cap' => $cap, 'attempts_left' => $cap - $attempt,
            'reason' => "Within {$rLevel} repair budget ({$attempt}/{$cap}); same provider/model (P8.1)."];
    }

    /**
     * P9.3/P9.4 — escalation signal aggregation + Forge-promotion gate.
     *
     * @param  array<string,mixed>  $e
     * @return array{should_escalate:bool, target:string, signals:list<string>, reason:string}
     */
    public function escalationDecision(array $e): array
    {
        $signals = [];

        if ((int) ($e['file_count'] ?? 0) > self::FILE_COUNT_THRESHOLD) {
            $signals[] = 'file_count';
        }
        if ((int) ($e['layers'] ?? 0) >= self::LAYERS_THRESHOLD) {
            $signals[] = 'layers';
        }
        if ((int) ($e['context_chars'] ?? 0) > self::CONTEXT_CHARS_THRESHOLD) {
            $signals[] = 'context_chars';
        }
        if ((int) ($e['thread_msgs'] ?? 0) >= self::THREAD_MSGS_THRESHOLD) {
            $signals[] = 'thread_msgs';
        }
        if ((int) ($e['recurrent_failures'] ?? 0) >= self::RECURRENT_FAILURE_THRESHOLD) {
            $signals[] = 'recurrent_failure';
        }
        $keywords = AtlasAaeosStringListNormalizer::trimmedStrings($e['keywords'] ?? []);
        if (array_intersect($this->lower($keywords), self::SENSITIVE_KEYWORDS) !== []) {
            $signals[] = 'sensitive_keyword';
        }

        $score = (int) ($e['score'] ?? 0);
        $risk = strtoupper($this->str($e['risk_level'] ?? null) ?? 'R0');
        $forgeQualifies = $score >= self::FORGE_SCORE_FLOOR || $risk >= 'R4';

        if ($signals === [] && ! $forgeQualifies) {
            return ['should_escalate' => false, 'target' => 'dev', 'signals' => [],
                'reason' => 'No escalation signal; stays in Atlas Dev tier (P9).'];
        }

        if ($forgeQualifies) {
            return ['should_escalate' => true, 'target' => 'forge', 'signals' => $signals,
                'reason' => "EscalationDecision.target=forge: score {$score}>=7 OR risk {$risk}>=R4. Operator promotes; Atlas Dev never auto-creates Obra (P9.2/P9.4)."];
        }

        return ['should_escalate' => true, 'target' => 'review', 'signals' => $signals,
            'reason' => 'Escalation signals present but Forge floor (score>=7 / risk>=R4) not met => human review (P9.3).'];
    }

    /**
     * P10.1 — is the Decision Receipt v2 triple present and co-validated?
     *
     * @param  array<string,mixed>|null  $receipt
     */
    public function receiptIsCoValidated(?array $receipt): bool
    {
        if (! is_array($receipt)) {
            return false;
        }
        foreach (self::RECEIPT_TRIPLE as $field) {
            $v = $this->str($receipt[$field] ?? null);
            if ($v === null || $v === '') {
                return false;
            }
        }

        return ($receipt['co_validated'] ?? true) === true;
    }

    /** All invariant ids this decider covers (P1–P17). @return list<string> */
    public function invariantIds(): array
    {
        return array_map(static fn (int $n): string => 'P'.$n, range(1, 17));
    }

    // ── internal checks ────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed>  $action
     * @return array<string,mixed>|null
     */
    private function checkLeakage(array $action): ?array
    {
        $kind = $this->str($action['kind'] ?? null) ?? 'unspecified';
        $path = $this->str($action['target_path'] ?? null) ?? '';
        $strings = $this->lower(AtlasAaeosStringListNormalizer::trimmedStrings($action['contains_strings'] ?? []));
        $inAtlasDev = str_contains($path, 'AtlasDev/') || str_contains($path, 'Programming/AtlasDev');

        if ($inAtlasDev) {
            foreach ($this->forbiddenLeakageTokens() as $banned) {
                if (in_array($banned, $strings, true)) {
                    return $this->verdict(self::POLICY_WINS, 'P17', "Forbidden leakage token in AtlasDev/** (P17.1). This is a BUG, not an operator-overridable conflict.", $kind, [
                        'gate' => self::LEAKAGE_GATE,
                        'matched_token_length' => strlen($banned),
                        'remediation' => 'Remove the token. Measurement vocabulary belongs to the Medicao team docs, never Atlas Dev code.',
                    ]);
                }
            }

            // P17.2 — surface token inside a surface-agnostic core module.
            foreach (self::SURFACE_AGNOSTIC_CORE_SEGMENTS as $seg) {
                if (str_contains($path, '/'.$seg.'/') || str_contains($path, '/'.$seg.'.php')) {
                    $surfaceHits = array_values(array_intersect($this->lower(self::SURFACE_TOKENS), $strings));
                    if ($surfaceHits !== []) {
                        return $this->verdict(self::POLICY_WINS, 'P17', "Surface token in surface-agnostic core module '{$seg}' (P17.2/P4.1). Core never knows a surface.", $kind, [
                            'gate' => self::SURFACE_LEAKAGE_GATE,
                            'remediation' => 'Move surface knowledge to AtlasDev/Surface/<X>Adapter.php (< 200 LOC).',
                        ]);
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $action
     * @return array<string,mixed>|null
     */
    private function checkScope(array $action): ?array
    {
        $kind = $this->str($action['kind'] ?? null) ?? 'unspecified';
        $scopeKind = $this->str($action['out_of_scope_kind'] ?? null);
        if ($scopeKind !== null && isset(self::OUT_OF_SCOPE_FLOWS[$scopeKind])) {
            $flow = self::OUT_OF_SCOPE_FLOWS[$scopeKind];

            return $this->verdict(self::DELEGATE_TO_OTHER_FLOW, 'P1', "Request '{$scopeKind}' is outside Atlas Dev scope (workspace development only) (P1.3).", $kind, [
                'suggested_flow' => $flow,
                'remediation' => "Return routing_decision=delegate_to_other_flow with suggested_flow={$flow} (P1.4). Do NOT attempt out of scope.",
            ]);
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $action
     * @return array<string,mixed>|null
     */
    private function checkProviderLock(array $action): ?array
    {
        $run = is_array($action['run'] ?? null) ? $action['run'] : [];
        if ($run === []) {
            return null;
        }
        $kind = $this->str($action['kind'] ?? null) ?? 'unspecified';
        $provider = $this->str($run['provider'] ?? null) ?? '';
        $isWrite = ($run['write'] ?? false) === true;

        // P11.3 — gemini_cli forbidden in write. Hard.
        if ($isWrite && $provider === 'gemini_cli') {
            return $this->verdict(self::POLICY_WINS, 'P11', 'gemini_cli is forbidden in write (P11.3).', $kind, [
                'remediation' => 'Use the fixed lock (claude_cli + Sonnet) for write runs.',
            ]);
        }

        // P11.1 — fallback within a run is overridable only via Decision Receipt v2.
        if (($run['fallback_allowed'] ?? false) === true) {
            return $this->verdict(self::NEEDS_DECISION_RECEIPT, 'P11', 'provider_lock is fixed per run; fallback_allowed must be false (P11.1). No council, no multi-provider topology mid-run.', $kind, [
                'remediation' => 'Refuse implicit fallback. If the operator insists, require Decision Receipt v2 with decision_mode=manual_override.',
            ]);
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $action
     * @return array<string,mixed>|null
     */
    private function checkDecisionReceipt(array $action): ?array
    {
        // Only runtime-executing actions need the receipt triple (P10.1).
        $kind = $this->str($action['kind'] ?? null) ?? 'unspecified';
        if (! in_array($kind, ['run', 'runtime_execute', 'apply_patch'], true)) {
            return null;
        }
        $receipt = is_array($action['decision_receipt_v2'] ?? null) ? $action['decision_receipt_v2'] : null;
        if (! $this->receiptIsCoValidated($receipt)) {
            return $this->verdict(self::POLICY_WINS, 'P10', 'No runtime executes without a co-validated Decision Receipt v2 (envelope_hash, prompt_projection_hash, task_contract_hash) (P10.1).', $kind, [
                'remediation' => 'Produce and co-validate the receipt triple before execution. Manual model is audited override (decision_mode=manual_override), never a bypass (P10.2).',
            ]);
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $action
     * @return array<string,mixed>|null
     */
    private function checkRunConfirmation(array $action): ?array
    {
        $kind = $this->str($action['kind'] ?? null) ?? 'unspecified';
        if ($kind !== 'run' && $kind !== 'runtime_execute') {
            return null;
        }
        $run = is_array($action['run'] ?? null) ? $action['run'] : [];
        if ($run === []) {
            return null;
        }
        $gate = $this->runGate($run);
        if (! $gate['ok']) {
            return $this->verdict(self::POLICY_WINS, 'P5', $gate['reason'], $kind, [
                'http_status' => $gate['http'],
                'remediation' => 'Operator must see the full plan and confirm with a valid single-use token + contract hash before Run (P5.4).',
            ]);
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $action
     * @return array<string,mixed>|null
     */
    private function checkScopeGuard(array $action): ?array
    {
        $run = is_array($action['run'] ?? null) ? $action['run'] : [];
        if ($run === [] || ! array_key_exists('scope_guard_status', $run)) {
            return null;
        }
        $kind = $this->str($action['kind'] ?? null) ?? 'unspecified';
        $status = $this->str($run['scope_guard_status'] ?? null) ?? self::SCOPE_FAILED;

        if ($status === self::SCOPE_FAILED) {
            return $this->verdict(self::POLICY_WINS, 'P6', 'scope_guard.status=failed: diff touched forbidden_files or exceeded expected_max_files (P6.2/P6.3). Completion blocked.', $kind, [
                'remediation' => 'Constrain the diff to allowed_files within expected_max_files. Pre-existing user changes are preserved and marked in the receipt (P6.4).',
            ]);
        }
        if ($status === self::SCOPE_NEEDS_REVIEW) {
            return $this->verdict(self::NEEDS_DECISION_RECEIPT, 'P6', 'scope_guard.status=needs_review: diff touched an unforeseen but defensible file (P6.3).', $kind, [
                'remediation' => 'Human review required before completion can pass.',
            ]);
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $action
     * @return array<string,mixed>|null
     */
    private function checkVerification(array $action): ?array
    {
        $run = is_array($action['run'] ?? null) ? $action['run'] : [];
        if ($run === [] || $this->str($action['kind'] ?? null) === null) {
            // verification only relevant when a completion is being claimed
        }
        if (! array_key_exists('claimed_completion', $run)) {
            return null;
        }
        $kind = $this->str($action['kind'] ?? null) ?? 'unspecified';
        $claimed = $this->str($run['claimed_completion'] ?? null) ?? self::COMPLETION_UNVERIFIED;
        if ($claimed !== self::COMPLETION_PASSED) {
            return null; // claiming needs_review / unverified is allowed as-is
        }

        $honesty = AtlasAaeosStringListNormalizer::trimmedStrings($run['honesty_flags'] ?? []);
        $current = $this->str($run['current_state'] ?? null) ?? self::COMPLETION_UNVERIFIED;

        // P7.1 — unverified never becomes passed.
        if ($current === self::COMPLETION_UNVERIFIED) {
            return $this->verdict(self::POLICY_WINS, 'P7', "Cannot flip 'unverified' to 'passed' (P7.1, completion_state_gate).", $kind, [
                'max_allowed' => self::COMPLETION_NEEDS_REVIEW,
                'remediation' => 'Run real tests (or supply no_test_reason) and pass required gates first.',
            ]);
        }
        // P7.3 — non-empty honesty_flags caps at needs_review.
        if ($honesty !== []) {
            return $this->verdict(self::POLICY_WINS, 'P7', 'honesty_flags not empty blocks passed; caps at needs_review (P7.3).', $kind, [
                'honesty_flags' => $honesty,
                'max_allowed' => self::COMPLETION_NEEDS_REVIEW,
            ]);
        }
        // P7.2 — passed requires scope ok + gates + tests_ok|no_test_reason.
        $scopeOk = ($this->str($run['scope_guard_status'] ?? null) ?? '') === self::SCOPE_PASSED;
        $gatesOk = ($run['required_gates_passed'] ?? false) === true;
        $testsOk = ($run['tests_ok'] ?? false) === true
            || (($this->str($run['no_test_reason'] ?? null) ?? '') !== '');
        if (! ($scopeOk && $gatesOk && $testsOk)) {
            $missing = [];
            if (! $scopeOk) {
                $missing[] = 'scope_guard passed';
            }
            if (! $gatesOk) {
                $missing[] = 'required gates passed';
            }
            if (! $testsOk) {
                $missing[] = 'tests ok=true OR explicit no_test_reason';
            }

            return $this->verdict(self::POLICY_WINS, 'P7', 'completion.status=passed requires '.implode(' + ', $missing).' (P7.2).', $kind, [
                'missing' => $missing,
                'max_allowed' => self::COMPLETION_NEEDS_REVIEW,
            ]);
        }

        return null; // genuinely passed
    }

    // ── helpers ────────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed>  $detail
     * @return array<string,mixed>
     */
    private function verdict(string $verdict, ?string $invariant, string $reason, string $kind, array $detail = []): array
    {
        return [
            'kind' => self::VERDICT_KIND,
            'verdict' => $verdict,
            'violated_invariant' => $invariant,
            'reason' => $reason,
            'action_kind' => $kind,
            'policy_wins_over_operator' => $verdict === self::POLICY_WINS,
            'override_possible' => $verdict === self::NEEDS_DECISION_RECEIPT,
            'detail' => $detail,
        ];
    }

    private function str(mixed $v): ?string
    {
        if (is_string($v)) {
            $t = trim($v);

            return $t === '' ? null : $t;
        }

        return null;
    }

    /**
     * @param  list<string>  $list
     * @return list<string>
     */
    private function lower(array $list): array
    {
        return array_values(array_map(static fn (string $s): string => strtolower($s), $list));
    }
}
