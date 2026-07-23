<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas AI OS - Domain Pipelines runtime.
 *
 * Turns the domain-pipelines doc into deterministic, pure decision logic.
 * The doc states one canonical pipeline shape that every operational domain
 * follows, an invariant that no mature domain may skip policy/evidence/gates
 * for operational work, and per-domain autonomy limits. Rather than restating
 * prose, this service enforces exactly the decidable contracts the doc states:
 *
 *  - Canonical pipeline (doc "Canonical Pipeline"): the fixed ordered stage
 *    sequence input -> domain -> intent -> profile -> flow -> context -> policy
 *    -> decide -> executor -> execution -> gates -> repair/escalation ->
 *    evidence -> learning -> output. canonicalPipeline() returns it verbatim.
 *
 *  - Mandatory-stage invariant (doc: "No mature domain may skip policy,
 *    evidence or gates for operational work."). validatePipeline() rejects a
 *    plan that — when mature AND operational — omits any of policy / gates /
 *    evidence, and also rejects any stage order that diverges from canon.
 *
 *  - Per-domain autonomy limits (doc sections Programming / Personal
 *    Development / Finance / Self-Improvement). Each domain carries its
 *    documented forbidden actions. evaluateAction() blocks a proposed action
 *    that matches a forbidden capability for that domain, regardless of any
 *    other signal:
 *      * finance: review-only — no market orders, broker execution, rebalance
 *        or transfer payloads.
 *      * personal_development: private — no diagnosis, no medical treatment,
 *        no automatic mutation of calendar / tasks / external systems.
 *      * self_improvement (curator): may propose/prepare, but a HIGH-RISK
 *        change requires gates + human review before it can apply.
 *      * programming: full executor range — no doc-level capability ban.
 *
 *  - Domain resolution (doc: `atlas dev`/`forge`/`fix`/`continue` are
 *    intensities/aliases of the same Programming domain). resolveDomain()
 *    maps an alias/intensity to its canonical domain id.
 *
 * Stateless and DB-free: every method is a pure function of its arguments.
 *
 * @see docs/engineering-knowledge-base/operating-system/domain-pipelines.md
 */
final class AtlasDomainPipelinesService
{
    public const SCHEMA_VERSION = 'atlas.domain_pipelines.v1';

    /**
     * The canonical pipeline stage order (doc "Canonical Pipeline" diagram),
     * verbatim and in sequence. This is the law every operational domain follows.
     *
     * @var list<string>
     */
    public const CANONICAL_PIPELINE = [
        'input',
        'domain',
        'intent',
        'profile',
        'flow',
        'context',
        'policy',
        'decide',
        'executor',
        'execution',
        'gates',
        'repair_escalation',
        'evidence',
        'learning',
        'output',
    ];

    /**
     * Stages a mature domain may NEVER skip for operational work
     * (doc: "No mature domain may skip policy, evidence or gates for
     * operational work.").
     *
     * @var list<string>
     */
    public const MANDATORY_OPERATIONAL_STAGES = [
        'policy',
        'gates',
        'evidence',
    ];

    /**
     * Canonical operational domains the doc enumerates.
     *
     * @var list<string>
     */
    public const DOMAINS = [
        'programming',
        'personal_development',
        'finance',
        'self_improvement',
    ];

    /**
     * Programming aliases / intensities (doc: "`atlas dev`, `atlas forge`,
     * `atlas fix` and `atlas continue` are intensities/aliases of this same
     * domain."). Maps each to the canonical programming domain.
     *
     * @var array<string,string>
     */
    public const DOMAIN_ALIASES = [
        'dev' => 'programming',
        'forge' => 'programming',
        'fix' => 'programming',
        'continue' => 'programming',
        'review' => 'programming',
        'refactor' => 'programming',
        'qa' => 'programming',
        'curator' => 'self_improvement',
        'self-improvement' => 'self_improvement',
        'self_improvement' => 'self_improvement',
        'personal-development' => 'personal_development',
        'personal_development' => 'personal_development',
    ];

    /**
     * Per-domain forbidden capabilities (doc "Limits" lines per domain). An
     * action whose capability is listed here is blocked for that domain.
     * Programming has no doc-level capability ban (full executor range).
     *
     * @var array<string,list<string>>
     */
    public const FORBIDDEN_CAPABILITIES = [
        // Finance "Limits": review-only, low autonomy by default, no market
        // orders, no broker execution, no rebalance/transfer payloads.
        'finance' => [
            'market_order',
            'broker_execution',
            'rebalance',
            'transfer',
        ],
        // Personal Development "Limits": private by default, non-clinical
        // language, no diagnosis, no medical treatment, no automatic mutation
        // of calendar/tasks/external systems.
        'personal_development' => [
            'diagnosis',
            'medical_treatment',
            'mutate_calendar',
            'mutate_tasks',
            'mutate_external_system',
        ],
        // Programming: full executor range — nothing doc-forbidden.
        'programming' => [],
        // Self-Improvement: may propose/prepare; no capability is outright
        // banned, but high-risk application is gated (see evaluateAction()).
        'self_improvement' => [],
    ];

    /**
     * Return the canonical pipeline shape (doc "Canonical Pipeline").
     *
     * @return array{
     *   schema_version:string,
     *   stages:list<string>,
     *   count:int,
     *   mandatory_operational_stages:list<string>
     * }
     */
    public function canonicalPipeline(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'stages' => self::CANONICAL_PIPELINE,
            'count' => count(self::CANONICAL_PIPELINE),
            'mandatory_operational_stages' => self::MANDATORY_OPERATIONAL_STAGES,
        ];
    }

    /**
     * Resolve an alias / intensity to its canonical domain id (doc: aliases of
     * the Programming domain, Curator == Self-Improvement). An already-canonical
     * domain passes through; an unknown token is reported, never guessed.
     *
     * @return array{
     *   schema_version:string,
     *   input:string,
     *   domain:?string,
     *   known:bool,
     *   reason:string
     * }
     */
    public function resolveDomain(string $token): array
    {
        $t = strtolower(trim($token));

        if (in_array($t, self::DOMAINS, true)) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'input' => $t,
                'domain' => $t,
                'known' => true,
                'reason' => 'already_canonical_domain',
            ];
        }

        if (array_key_exists($t, self::DOMAIN_ALIASES)) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'input' => $t,
                'domain' => self::DOMAIN_ALIASES[$t],
                'known' => true,
                'reason' => 'alias_resolved_to_canonical_domain',
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'input' => $t,
            'domain' => null,
            'known' => false,
            'reason' => 'unknown_domain_token',
        ];
    }

    /**
     * Validate a proposed pipeline plan against the canon and the mandatory-stage
     * invariant (doc "Canonical Pipeline" + "No mature domain may skip policy,
     * evidence or gates for operational work.").
     *
     * Rules enforced:
     *  - Stage order must match CANONICAL_PIPELINE exactly when the full set is
     *    declared; any unknown stage or out-of-order divergence is a violation.
     *  - When the plan is BOTH mature AND operational, every MANDATORY_OPERATIONAL
     *    stage (policy, gates, evidence) must be present. Missing any => violation.
     *  - A non-mature OR non-operational plan is exempt from the mandatory-stage
     *    rule (the doc scopes the rule to mature + operational work) but is still
     *    checked for stage-order validity.
     *
     * @param  array{stages?:list<string>,mature?:bool,operational?:bool}  $plan
     * @return array{
     *   schema_version:string,
     *   mature:bool,
     *   operational:bool,
     *   unknown_stages:list<string>,
     *   order_valid:bool,
     *   missing_mandatory:list<string>,
     *   valid:bool,
     *   reason:string
     * }
     */
    public function validatePipeline(array $plan): array
    {
        $stages = array_values(array_map(
            static fn ($s): string => strtolower(trim((string) $s)),
            $plan['stages'] ?? [],
        ));
        $mature = (bool) ($plan['mature'] ?? false);
        $operational = (bool) ($plan['operational'] ?? false);

        // Stages not in the canon at all.
        $unknown = array_values(array_filter(
            $stages,
            static fn (string $s): bool => ! in_array($s, self::CANONICAL_PIPELINE, true),
        ));

        // Order check: the declared stages, restricted to canon members, must
        // appear in the same relative order as the canon (no reordering).
        $canonIndex = array_flip(self::CANONICAL_PIPELINE);
        $orderValid = true;
        $lastIdx = -1;
        foreach ($stages as $stage) {
            if (! isset($canonIndex[$stage])) {
                continue; // unknown handled separately
            }
            $idx = $canonIndex[$stage];
            if ($idx <= $lastIdx) {
                $orderValid = false;
                break;
            }
            $lastIdx = $idx;
        }

        // Mandatory-stage invariant applies only to mature + operational work.
        $missing = [];
        if ($mature && $operational) {
            foreach (self::MANDATORY_OPERATIONAL_STAGES as $required) {
                if (! in_array($required, $stages, true)) {
                    $missing[] = $required;
                }
            }
        }

        $valid = $unknown === [] && $orderValid && $missing === [];

        $reason = match (true) {
            $unknown !== [] => 'plan_contains_non_canonical_stages',
            ! $orderValid => 'stage_order_diverges_from_canon',
            $missing !== [] => 'mature_operational_plan_skips_mandatory_stage',
            default => 'pipeline_plan_valid',
        };

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mature' => $mature,
            'operational' => $operational,
            'unknown_stages' => $unknown,
            'order_valid' => $orderValid,
            'missing_mandatory' => $missing,
            'valid' => $valid,
            'reason' => $reason,
        ];
    }

    /**
     * Evaluate a proposed action against a domain's documented autonomy limits
     * (doc per-domain "Limits"). Returns whether the action is allowed, and — when
     * blocked or gated — why.
     *
     * Decision order:
     *  1. Unknown domain => not allowed (cannot reason about limits).
     *  2. Capability in the domain's FORBIDDEN list => blocked outright
     *     (finance market_order/broker/rebalance/transfer;
     *      personal_development diagnosis/treatment/mutate_*).
     *  3. self_improvement high-risk change => allowed ONLY when both gates_passed
     *     AND human_review are true (doc: "High-risk changes require gates and
     *     human review before critical behavior changes."). Otherwise gated.
     *  4. Anything else => allowed.
     *
     * @param  array{
     *   domain?:string,
     *   capability?:string,
     *   high_risk?:bool,
     *   gates_passed?:bool,
     *   human_review?:bool
     * }  $action
     * @return array{
     *   schema_version:string,
     *   domain:?string,
     *   capability:string,
     *   allowed:bool,
     *   requires_human_review:bool,
     *   reason:string
     * }
     */
    public function evaluateAction(array $action): array
    {
        $resolved = $this->resolveDomain((string) ($action['domain'] ?? ''));
        $domain = $resolved['domain'];
        $capability = strtolower(trim((string) ($action['capability'] ?? '')));

        if ($domain === null) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'domain' => null,
                'capability' => $capability,
                'allowed' => false,
                'requires_human_review' => false,
                'reason' => 'unknown_domain_cannot_evaluate_limits',
            ];
        }

        $forbidden = self::FORBIDDEN_CAPABILITIES[$domain] ?? [];
        if (in_array($capability, $forbidden, true)) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'domain' => $domain,
                'capability' => $capability,
                'allowed' => false,
                'requires_human_review' => false,
                'reason' => $domain.'_domain_forbids_capability',
            ];
        }

        if ($domain === 'self_improvement' && (bool) ($action['high_risk'] ?? false)) {
            $gatesPassed = (bool) ($action['gates_passed'] ?? false);
            $humanReview = (bool) ($action['human_review'] ?? false);
            $cleared = $gatesPassed && $humanReview;

            return [
                'schema_version' => self::SCHEMA_VERSION,
                'domain' => $domain,
                'capability' => $capability,
                'allowed' => $cleared,
                'requires_human_review' => true,
                'reason' => $cleared
                    ? 'high_risk_change_cleared_gates_and_human_review'
                    : 'high_risk_change_requires_gates_and_human_review',
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'domain' => $domain,
            'capability' => $capability,
            'allowed' => true,
            'requires_human_review' => false,
            'reason' => 'within_domain_autonomy_limits',
        ];
    }

    /**
     * Read-model snapshot of the domain-pipelines contract for the CLI / inspection.
     *
     * @return array{
     *   schema_version:string,
     *   pipeline_stage_count:int,
     *   mandatory_operational_stages:list<string>,
     *   domains:list<string>,
     *   forbidden_capabilities:array<string,list<string>>
     * }
     */
    public function snapshot(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'pipeline_stage_count' => count(self::CANONICAL_PIPELINE),
            'mandatory_operational_stages' => self::MANDATORY_OPERATIONAL_STAGES,
            'domains' => self::DOMAINS,
            'forbidden_capabilities' => self::FORBIDDEN_CAPABILITIES,
        ];
    }
}
