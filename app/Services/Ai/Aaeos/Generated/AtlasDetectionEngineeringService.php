<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas AI Cyber Detection Engineering — runtime.
 *
 * Turns the documented detection-as-code contract for the Cyber extension into
 * deterministic, pure decision logic. The doc defines how a Sigma detection
 * rule moves through its lifecycle, what False Positive Budget each severity
 * must respect, which Red technique every rule must be paired with, and the
 * behavioral-coverage target. This service makes those declarations enforceable
 * without any I/O.
 *
 * Concrete contract enforced (verbatim from the doc):
 *
 *  - False Positive Budget (DE2): per-severity ceilings — critical < 0.001,
 *    high < 0.005, medium < 0.02, low < 0.05, informational < 0.1. Measured in
 *    7-day windows; a `live` rule over budget violates DE2 and must tune or
 *    deprecate. evaluateFalsePositiveBudget() applies the strict `<` ceiling.
 *
 *  - Lifecycle (DE7): draft -> staged -> live -> deprecated, in that order.
 *    The only non-trivial gate is `staged -> live`, which additionally requires
 *    a passed Purple validation. evaluateTransition() rejects skips, backward
 *    moves, and a `staged -> live` without Purple validation.
 *
 *  - Red-Blue pairing (DE3): a detection is "especulativa" (an anti-pattern)
 *    without a paired Red sub-category from the canonical map and the right
 *    data source. pairing() resolves the canonical detection id + data source.
 *
 *  - Detection kinds: signature | behavioral | anomaly | ioc_matching |
 *    heuristic, each with a documented typical FP rate; anomaly is "alto se
 *    baseline instavel".
 *
 *  - DE8 Verdict-First / Anomaly-over-Confirmation: target is 30%+ of `live`
 *    rules in the `behavioral` category. behavioralCoverage() computes the
 *    ratio over live rules and flags whether the target is met.
 *
 *  - Anti-patterns: a rule with no TN cases, no TP cases, or no Red pairing is
 *    not promotable past draft; a `live` rule over its FP budget is in
 *    violation; inflated severity (alert-forcing) is flagged. assessRule()
 *    rolls these into a single verdict.
 *
 * Stateless and DB-free: every method is a pure function of its arguments.
 *
 * @see docs/engineering-knowledge-base/cyber-security/detection-engineering.md
 */
final class AtlasDetectionEngineeringService
{
    public const SCHEMA_VERSION = 'atlas.cyber.detection_engineering.v1';

    /** Lifecycle states (DE7), ordered draft -> staged -> live -> deprecated. */
    public const STATE_DRAFT = 'draft';
    public const STATE_STAGED = 'staged';
    public const STATE_LIVE = 'live';
    public const STATE_DEPRECATED = 'deprecated';

    /** Shadow-staging window the doc fixes for FP measurement (days). */
    public const STAGING_SHADOW_DAYS = 7;

    /** DE8 behavioral-coverage target over live rules (>= 30%). */
    public const BEHAVIORAL_LIVE_TARGET_RATIO = 0.30;

    /**
     * Lifecycle order. Index position is the rank; a valid forward transition
     * moves exactly one step right (no skips, no backward).
     *
     * @var list<string>
     */
    private const LIFECYCLE_ORDER = [
        self::STATE_DRAFT,
        self::STATE_STAGED,
        self::STATE_LIVE,
        self::STATE_DEPRECATED,
    ];

    /**
     * False Positive Budget per severity (DE2). The value is the *exclusive*
     * ceiling: an observed FP rate is within budget only when strictly below it.
     *
     * @var array<string,float>
     */
    private const FP_BUDGET = [
        'critical' => 0.001,
        'high' => 0.005,
        'medium' => 0.02,
        'low' => 0.05,
        'informational' => 0.1,
    ];

    /** Severity order weakest -> strongest, used to reject inflation. */
    private const SEVERITY_ORDER = ['informational', 'low', 'medium', 'high', 'critical'];

    /**
     * Detection kinds with the documented typical FP rate band.
     *
     * @var array<string,string>
     */
    private const KIND_FP_BAND = [
        'signature' => 'low',
        'behavioral' => 'medium',
        'anomaly' => 'high_if_baseline_unstable',
        'ioc_matching' => 'low',
        'heuristic' => 'medium',
    ];

    /**
     * Canonical Red-Blue pairing map (subset essencial from the doc):
     *   red sub-category key => [detection_id, data_source].
     *
     * @var array<string,array{detection:string,data_source:string}>
     */
    private const PAIRING = [
        'webapp.idor' => ['detection' => 'cyber-det-idor', 'data_source' => 'http_logs'],
        'webapp.sqli' => ['detection' => 'cyber-det-sqli', 'data_source' => 'http_logs + db_query_logs'],
        'webapp.xss' => ['detection' => 'cyber-det-xss-payload', 'data_source' => 'http_logs'],
        'webapp.ssrf' => ['detection' => 'cyber-det-ssrf', 'data_source' => 'egress_logs'],
        'webapp.rce' => ['detection' => 'cyber-det-rce', 'data_source' => 'process_exec_logs'],
        'webapp.path_traversal' => ['detection' => 'cyber-det-path-traversal', 'data_source' => 'http_logs + fs_access_logs'],
        'auth.brute_force' => ['detection' => 'cyber-det-brute-force', 'data_source' => 'auth_logs'],
        'auth.jwt_alg_none' => ['detection' => 'cyber-det-jwt-alg-none', 'data_source' => 'auth_logs'],
        'authz.vertical_privesc' => ['detection' => 'cyber-det-vertical-privesc', 'data_source' => 'auth_logs + audit_logs'],
        'api.rate_limit_exhaustion' => ['detection' => 'cyber-det-rate-limit-exhaustion', 'data_source' => 'http_logs'],
        'cloud.s3_public' => ['detection' => 'cyber-det-s3-bucket-public', 'data_source' => 'cloudtrail'],
        'cloud.iam_open_policy' => ['detection' => 'cyber-det-iam-open-policy', 'data_source' => 'cloudtrail'],
        'ad.kerberoast' => ['detection' => 'cyber-det-kerberoast', 'data_source' => 'dc_events_4769'],
        'ad.lsass_read' => ['detection' => 'cyber-det-lsass-read', 'data_source' => 'edr_sysmon_10'],
        'redteam.dns_tunnel' => ['detection' => 'cyber-det-dns-tunnel', 'data_source' => 'dns_logs'],
        'redteam.web_shell' => ['detection' => 'cyber-det-web-shell', 'data_source' => 'fim + http_logs'],
        'llm.prompt_injection' => ['detection' => 'cyber-det-prompt-injection', 'data_source' => 'prompt_logs'],
        'llm.tool_call_flood' => ['detection' => 'cyber-det-tool-call-flood', 'data_source' => 'agent_logs'],
    ];

    /** @return array<string,float> the documented FP budget table, severity => ceiling. */
    public function falsePositiveBudgets(): array
    {
        return self::FP_BUDGET;
    }

    /** @return list<string> the lifecycle order, draft -> staged -> live -> deprecated. */
    public function lifecycle(): array
    {
        return self::LIFECYCLE_ORDER;
    }

    /**
     * Evaluate an observed 7-day FP rate against the severity budget (DE2).
     * Within budget only when strictly below the ceiling.
     *
     * @return array{
     *   schema_version:string,
     *   severity:string,
     *   known_severity:bool,
     *   fp_rate:float,
     *   budget:?float,
     *   within_budget:bool,
     *   action:string
     * }
     */
    public function evaluateFalsePositiveBudget(string $severity, float $fpRate): array
    {
        $sev = strtolower(trim($severity));
        $budget = self::FP_BUDGET[$sev] ?? null;
        $known = $budget !== null;

        // Unknown severity cannot be certified within budget.
        $within = $known && $fpRate < $budget;

        // DE2: excess -> tune or deprecate. Within budget -> hold.
        $action = $within ? 'hold' : ($known ? 'tune_or_deprecate' : 'unknown_severity');

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'severity' => $sev,
            'known_severity' => $known,
            'fp_rate' => $fpRate,
            'budget' => $budget,
            'within_budget' => $within,
            'action' => $action,
        ];
    }

    /**
     * Evaluate a lifecycle transition (DE7). Only single forward steps are
     * allowed; the `staged -> live` step additionally requires a passed Purple
     * validation. Backward moves and skips are rejected.
     *
     * @return array{
     *   schema_version:string,
     *   from:string,
     *   to:string,
     *   allowed:bool,
     *   requires_purple_validation:bool,
     *   purple_validation_passed:bool,
     *   reason:string
     * }
     */
    public function evaluateTransition(string $from, string $to, bool $purpleValidationPassed = false): array
    {
        $fromState = strtolower(trim($from));
        $toState = strtolower(trim($to));

        $fromRank = array_search($fromState, self::LIFECYCLE_ORDER, true);
        $toRank = array_search($toState, self::LIFECYCLE_ORDER, true);

        $requiresPurple = $fromState === self::STATE_STAGED && $toState === self::STATE_LIVE;

        if ($fromRank === false || $toRank === false) {
            return $this->transitionResult($fromState, $toState, false, $requiresPurple, $purpleValidationPassed, 'unknown_state');
        }

        // Single forward step only — no skipping staging ("skip staging" anti-pattern).
        if ($toRank !== $fromRank + 1) {
            $reason = $toRank <= $fromRank ? 'backward_or_noop_transition' : 'cannot_skip_lifecycle_step';

            return $this->transitionResult($fromState, $toState, false, $requiresPurple, $purpleValidationPassed, $reason);
        }

        // staged -> live gate: Purple validation must have passed.
        if ($requiresPurple && ! $purpleValidationPassed) {
            return $this->transitionResult($fromState, $toState, false, $requiresPurple, $purpleValidationPassed, 'purple_validation_required');
        }

        return $this->transitionResult($fromState, $toState, true, $requiresPurple, $purpleValidationPassed, 'ok');
    }

    /**
     * Resolve the canonical detection id + data source for a Red sub-category.
     * A rule whose Red pairing is unknown is "especulativa" (anti-pattern).
     *
     * @return array{
     *   schema_version:string,
     *   red_subcategory:string,
     *   paired:bool,
     *   detection:?string,
     *   data_source:?string
     * }
     */
    public function pairing(string $redSubcategory): array
    {
        $key = $this->normalizePairingKey($redSubcategory);
        $entry = self::PAIRING[$key] ?? null;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'red_subcategory' => $key,
            'paired' => $entry !== null,
            'detection' => $entry['detection'] ?? null,
            'data_source' => $entry['data_source'] ?? null,
        ];
    }

    /**
     * Compute behavioral coverage over the supplied rule set and check DE8's
     * >= 30% target. Only `live` rules count toward the ratio (the doc fixes the
     * target on rules in the `live` state).
     *
     * @param  list<array{kind?:string,state?:string}>  $rules
     * @return array{
     *   schema_version:string,
     *   live_total:int,
     *   live_behavioral:int,
     *   ratio:float,
     *   target_ratio:float,
     *   meets_target:bool
     * }
     */
    public function behavioralCoverage(array $rules): array
    {
        $liveTotal = 0;
        $liveBehavioral = 0;

        foreach ($rules as $rule) {
            $state = strtolower(trim((string) ($rule['state'] ?? '')));
            if ($state !== self::STATE_LIVE) {
                continue;
            }
            $liveTotal++;
            if (strtolower(trim((string) ($rule['kind'] ?? ''))) === 'behavioral') {
                $liveBehavioral++;
            }
        }

        $ratio = $liveTotal > 0 ? $liveBehavioral / $liveTotal : 0.0;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'live_total' => $liveTotal,
            'live_behavioral' => $liveBehavioral,
            'ratio' => $ratio,
            'target_ratio' => self::BEHAVIORAL_LIVE_TARGET_RATIO,
            // Target met only when there is at least one live rule and ratio >= target.
            'meets_target' => $liveTotal > 0 && $ratio >= self::BEHAVIORAL_LIVE_TARGET_RATIO,
        ];
    }

    /**
     * Primary entry point: assess one detection rule against the full contract
     * and return a single verdict. Rolls up the anti-patterns:
     *   - no TP cases / no TN cases  -> not promotable (DE1).
     *   - no Red pairing             -> especulativa (DE3).
     *   - inflated severity          -> declared severity above justified one.
     *   - live & over FP budget      -> DE2 violation.
     *
     * @param  array{
     *   id?:string,
     *   kind?:string,
     *   state?:string,
     *   severity?:string,
     *   justified_severity?:string,
     *   red_subcategory?:string,
     *   tp_cases?:int,
     *   tn_cases?:int,
     *   observed_fp_rate?:float|null
     * }  $rule
     * @return array{
     *   schema_version:string,
     *   id:string,
     *   state:string,
     *   kind:string,
     *   kind_fp_band:?string,
     *   violations:list<string>,
     *   pairing:array<string,mixed>,
     *   fp_budget:?array<string,mixed>,
     *   testable:bool,
     *   promotable_past_draft:bool,
     *   verdict:string
     * }
     */
    public function assessRule(array $rule): array
    {
        $id = trim((string) ($rule['id'] ?? ''));
        $kind = strtolower(trim((string) ($rule['kind'] ?? '')));
        $state = strtolower(trim((string) ($rule['state'] ?? self::STATE_DRAFT)));
        $severity = strtolower(trim((string) ($rule['severity'] ?? '')));
        $justified = strtolower(trim((string) ($rule['justified_severity'] ?? $severity)));
        $tpCases = (int) ($rule['tp_cases'] ?? 0);
        $tnCases = (int) ($rule['tn_cases'] ?? 0);

        $violations = [];

        // DE1 — detection is a testable hypothesis: needs TP and TN cases.
        if ($tpCases < 1) {
            $violations[] = 'missing_tp_cases';
        }
        if ($tnCases < 1) {
            // Anti-pattern: "Regra sem TN cases — FP rate explode."
            $violations[] = 'missing_tn_cases';
        }
        $testable = $tpCases >= 1 && $tnCases >= 1;

        // DE3 — Red-Blue pairing mandatory; unpaired is especulativa.
        $pairing = $this->pairing((string) ($rule['red_subcategory'] ?? ''));
        if (! $pairing['paired']) {
            $violations[] = 'missing_red_pairing';
        }

        // Kind must be one of the documented detection kinds.
        $kindBand = self::KIND_FP_BAND[$kind] ?? null;
        if ($kindBand === null) {
            $violations[] = 'unknown_detection_kind';
        }

        // DE6 — severity inflation: declared severity above the justified one.
        $declaredRank = array_search($severity, self::SEVERITY_ORDER, true);
        $justifiedRank = array_search($justified, self::SEVERITY_ORDER, true);
        if ($declaredRank !== false && $justifiedRank !== false && $declaredRank > $justifiedRank) {
            $violations[] = 'severity_inflated';
        }

        // DE2 — a live rule over its FP budget is a violation.
        $fpBudget = null;
        $observed = $rule['observed_fp_rate'] ?? null;
        if ($observed !== null) {
            $fpBudget = $this->evaluateFalsePositiveBudget($severity, (float) $observed);
            if ($state === self::STATE_LIVE && ! $fpBudget['within_budget']) {
                $violations[] = 'live_over_fp_budget';
            }
        }

        // A rule may move past draft only when it is testable AND paired.
        $promotablePastDraft = $testable && $pairing['paired'] && $kindBand !== null;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'id' => $id,
            'state' => $state,
            'kind' => $kind,
            'kind_fp_band' => $kindBand,
            'violations' => array_values($violations),
            'pairing' => $pairing,
            'fp_budget' => $fpBudget,
            'testable' => $testable,
            'promotable_past_draft' => $promotablePastDraft,
            'verdict' => $violations === [] ? 'pass' : 'fail',
        ];
    }

    /**
     * Snapshot of the doc's contract plus a worked default assessment, used by
     * the command as a single source of truth.
     *
     * @return array<string,mixed>
     */
    public function manifest(): array
    {
        $sampleRule = [
            'id' => 'cyber-det-brute-force',
            'kind' => 'behavioral',
            'state' => self::STATE_LIVE,
            'severity' => 'high',
            'justified_severity' => 'high',
            'red_subcategory' => 'auth.brute_force',
            'tp_cases' => 4,
            'tn_cases' => 6,
            'observed_fp_rate' => 0.003,
        ];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'lifecycle' => self::LIFECYCLE_ORDER,
            'staging_shadow_days' => self::STAGING_SHADOW_DAYS,
            'false_positive_budgets' => self::FP_BUDGET,
            'detection_kinds' => self::KIND_FP_BAND,
            'behavioral_live_target_ratio' => self::BEHAVIORAL_LIVE_TARGET_RATIO,
            'pairing_count' => count(self::PAIRING),
            'sample_assessment' => $this->assessRule($sampleRule),
        ];
    }

    /**
     * @return array{
     *   schema_version:string,
     *   from:string,
     *   to:string,
     *   allowed:bool,
     *   requires_purple_validation:bool,
     *   purple_validation_passed:bool,
     *   reason:string
     * }
     */
    private function transitionResult(
        string $from,
        string $to,
        bool $allowed,
        bool $requiresPurple,
        bool $purplePassed,
        string $reason,
    ): array {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'from' => $from,
            'to' => $to,
            'allowed' => $allowed,
            'requires_purple_validation' => $requiresPurple,
            'purple_validation_passed' => $purplePassed,
            'reason' => $reason,
        ];
    }

    private function normalizePairingKey(string $value): string
    {
        // Canonical keys use a dot category separator (e.g. "auth.brute_force"),
        // with underscores inside a name. Accept loose spellings: a " - " or "/"
        // is treated as the category separator (-> "."), spaces inside a name
        // become "_", and runs collapse. We deliberately do NOT turn every "_"
        // into "." so "brute_force" stays intact.
        $key = strtolower(trim($value));
        $key = str_replace([' - ', ' / ', '/', ':'], '.', $key);
        $key = str_replace([' -', '- ', '-'], '.', $key);
        $key = str_replace(' ', '_', $key);
        $key = preg_replace('/\.+/', '.', $key) ?? $key;

        return preg_replace('/_+/', '_', $key) ?? $key;
    }
}
