<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Runtime for the Business Context kernel step.
 *
 * Turns the doc's contract into pure, deterministic decision logic (no IO, no
 * clock, no DB). Same intent + envelope -> same business context. The doc is the
 * authoring boundary; this step attaches *where the task lives as a business*
 * (Obra, workspace, project, product, environment) and never decides a provider,
 * never picks the cognitive domain, and never changes policy.
 *
 * It sits between `intent-routing` (depends_on) and `domain-profile-flow`
 * (flows_to / unlocks): it consumes a routed intent and emits the business
 * context that orients the downstream domain + execution profile.
 *
 * Load-bearing contracts pinned here:
 *
 *   1. Contracts. Input = intent + envelope. Output = project, product,
 *      environment, sensitivity, priority. Invariant: "nao decide provider"
 *      -> every decision asserts provider_selected = false. -> resolve()
 *
 *   2. Regras para IA. "IA deve diferenciar 'para qual negocio' de 'qual dominio
 *      cognitivo resolve'." Business Context is NOT the cognitive domain. The
 *      decider classifies the *business* (which Obra/product/project) and refuses
 *      to emit a cognitive domain: cognitive_domain is always null and the
 *      business/domain separation is asserted explicitly. -> resolve()
 *
 *   3. Escopo de Implementacao / forbidden_changes. Permitted: Obra, workspace,
 *      product context. Forbidden: "alterar policy sem passar por Policy Profile"
 *      and "confundir contexto de negocio com dominio cognitivo". The decider
 *      exposes the forbidden actions and never returns a policy mutation or a
 *      domain. -> forbiddenAtThisStage()
 *
 *   4. Fluxo. "Intent encontra Obra, workspace e contexto de negocio. O resultado
 *      orienta dominio e perfil de execucao." -> resolve() emits next_node =
 *      domain-profile-flow only once the business context is anchored.
 *
 *   5. Riscos (risk_level: high). Two documented dangers:
 *        (a) "Contexto obsoleto guiar implementacao errada" -> a context whose
 *            envelope is unanchored (no Obra, no workspace, no product) is
 *            flagged stale/unanchored: it cannot hand off and forces a refresh.
 *        (b) "Business Context conter segredo sem policy adequada" -> a context
 *            carrying secret-class material that has NOT cleared Policy Profile is
 *            blocked (policy_cleared=false). High sensitivity always lifts risk.
 *      -> resolve()
 *
 * @see docs/engineering-knowledge-base/system-graph/business-context.md
 */
final class AtlasSystemGraphBusinessContextService
{
    /** Stable evidence schema id this runtime emits. */
    public const SCHEMA_VERSION = 'atlas.system_graph.business_context.v1';

    /** This step's node id (doc graph_id). */
    public const STAGE = 'business-context';

    /** Upstream node (doc depends_on / front-matter). */
    public const PREV_NODE = 'intent-routing';

    /** Graph successor (doc front-matter: flows_to / unlocks: domain-profile-flow). */
    public const NEXT_NODE = 'domain-profile-flow';

    /** Sensitivity bands. The doc itself carries risk_level: high. */
    public const SENSITIVITY_PUBLIC = 'public';
    public const SENSITIVITY_INTERNAL = 'internal';
    public const SENSITIVITY_SENSITIVE = 'sensitive';
    public const SENSITIVITY_SECRET = 'secret';

    /** Business priority bands the context can assign. */
    public const PRIORITY_LOW = 'low';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_HIGH = 'high';

    /** Risk bands this step reports (doc risk_level: high is the ceiling). */
    public const RISK_LOW = 'low';
    public const RISK_MEDIUM = 'medium';
    public const RISK_HIGH = 'high';

    /** Hand-off readiness states. */
    public const READY = 'ready';
    public const BLOCKED_STALE = 'blocked_stale';
    public const BLOCKED_POLICY = 'blocked_policy';

    /**
     * Ordered sensitivity ladder. A higher index is more sensitive. Used to lift
     * priority/risk and to decide whether Policy Profile clearance is mandatory.
     *
     * @var array<int, string>
     */
    private const SENSITIVITY_LADDER = [
        self::SENSITIVITY_PUBLIC,
        self::SENSITIVITY_INTERNAL,
        self::SENSITIVITY_SENSITIVE,
        self::SENSITIVITY_SECRET,
    ];

    /**
     * The sensitivity classes that may NOT hand off until Policy Profile has
     * cleared them (Riscos: "Business Context conter segredo sem policy adequada"
     * and Escopo: never alter policy outside Policy Profile). sensitive + secret
     * require an explicit clearance flag from upstream.
     *
     * @var array<int, string>
     */
    private const REQUIRES_POLICY_CLEARANCE = [
        self::SENSITIVITY_SENSITIVE,
        self::SENSITIVITY_SECRET,
    ];

    /** Actions the doc forbids at this stage (forbidden_changes / Escopo). */
    private const FORBIDDEN_AT_THIS_STAGE = [
        'select_provider_or_model',
        'choose_cognitive_domain',
        'mutate_policy_outside_policy_profile',
    ];

    /**
     * Resolve the business context for a routed intent. Anchors the task to its
     * Obra / workspace / project / product / environment, assigns sensitivity and
     * priority, and decides whether it may hand off to domain-profile-flow.
     *
     * Provider is never chosen here, the cognitive domain is never chosen here,
     * and policy is never mutated here.
     *
     * Accepted envelope keys (all optional; absence is what drives the
     * stale/unanchored danger):
     *   - obra|obra_id (string): the Obra (work) the task belongs to
     *   - workspace|workspace_id (string): the certified workspace
     *   - project (string): project name
     *   - product (string): product name
     *   - environment (string): e.g. dev|staging|prod (default: unknown)
     *   - sensitivity (string): one of the four bands (default: internal)
     *   - policy_profile_cleared (bool): Policy Profile has cleared the context
     *   - business_critical (bool): operator-flagged high business priority
     *
     * @param  array<string, mixed>  $envelope
     * @return array{
     *     schema_version: string,
     *     stage: string,
     *     obra: string|null,
     *     workspace: string|null,
     *     project: string|null,
     *     product: string|null,
     *     environment: string,
     *     sensitivity: string,
     *     priority: string,
     *     risk: string,
     *     anchored: bool,
     *     missing_anchors: list<string>,
     *     requires_policy_clearance: bool,
     *     policy_cleared: bool,
     *     readiness: string,
     *     can_hand_off: bool,
     *     provider_selected: bool,
     *     cognitive_domain: null,
     *     business_vs_domain: string,
     *     next_node: string,
     *     forbidden_at_this_stage: list<string>,
     *     reasons: list<string>
     * }
     */
    public function resolve(array $envelope): array
    {
        $obra = $this->stringKey($envelope, 'obra') ?? $this->stringKey($envelope, 'obra_id');
        $workspace = $this->stringKey($envelope, 'workspace') ?? $this->stringKey($envelope, 'workspace_id');
        $project = $this->stringKey($envelope, 'project');
        $product = $this->stringKey($envelope, 'product');
        $environment = $this->stringKey($envelope, 'environment') ?? 'unknown';

        $sensitivity = $this->normalizeSensitivity($this->stringKey($envelope, 'sensitivity'));

        // Fluxo: the task must find an Obra, a workspace and a business context.
        // An unanchored envelope is the documented "contexto obsoleto" danger:
        // without at least one of Obra / workspace / product there is nothing real
        // to orient the downstream domain, so it cannot hand off.
        $missingAnchors = $this->missingAnchors($obra, $workspace, $project, $product);
        $anchored = ! in_array('obra', $missingAnchors, true)
            || ! in_array('workspace', $missingAnchors, true)
            || ! in_array('product', $missingAnchors, true);

        // Riscos / Escopo: secret + sensitive classes must clear Policy Profile
        // before this step can pass them on. Clearance is an explicit upstream
        // flag; this step never grants it itself (never mutates policy).
        $requiresClearance = in_array($sensitivity, self::REQUIRES_POLICY_CLEARANCE, true);
        $policyCleared = ! $requiresClearance || $this->boolKey($envelope, 'policy_profile_cleared');

        $priority = $this->derivePriority($envelope, $sensitivity, $anchored);
        $risk = $this->deriveRisk($sensitivity, $anchored, $policyCleared);

        $reasons = [];
        if (! $anchored) {
            $reasons[] = 'business context unanchored (no Obra/workspace/product): refresh before handing off';
        }
        if ($requiresClearance && ! $policyCleared) {
            $reasons[] = sprintf(
                '%s-class context not cleared by Policy Profile: cannot hand off',
                $sensitivity
            );
        }

        // Readiness precedence: a stale/unanchored context is the first blocker;
        // an uncleared secret is the second. Only a fully anchored, cleared
        // context is ready to orient the domain profile.
        if (! $anchored) {
            $readiness = self::BLOCKED_STALE;
        } elseif ($requiresClearance && ! $policyCleared) {
            $readiness = self::BLOCKED_POLICY;
        } else {
            $readiness = self::READY;
        }

        $canHandOff = $readiness === self::READY;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'stage' => self::STAGE,
            'obra' => $obra,
            'workspace' => $workspace,
            'project' => $project,
            'product' => $product,
            'environment' => $environment,
            'sensitivity' => $sensitivity,
            'priority' => $priority,
            'risk' => $risk,
            'anchored' => $anchored,
            'missing_anchors' => $missingAnchors,
            'requires_policy_clearance' => $requiresClearance,
            'policy_cleared' => $policyCleared,
            'readiness' => $readiness,
            'can_hand_off' => $canHandOff,
            // Contracts invariant: provider is NOT chosen at this stage.
            'provider_selected' => false,
            // Regras para IA: this step never resolves the cognitive domain.
            'cognitive_domain' => null,
            'business_vs_domain' => 'business context describes which business the task serves; it is not the cognitive domain that solves it',
            // Flow: hand off to domain-profile-flow once anchored + cleared.
            'next_node' => self::NEXT_NODE,
            'forbidden_at_this_stage' => self::FORBIDDEN_AT_THIS_STAGE,
            'reasons' => array_values($reasons),
        ];
    }

    /**
     * The actions the doc forbids in the Business Context step. Exposed so callers
     * can assert the boundary instead of re-deriving it.
     *
     * @return array{schema_version: string, stage: string, forbidden: list<string>, invariants: list<string>}
     */
    public function forbiddenAtThisStage(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'stage' => self::STAGE,
            'forbidden' => self::FORBIDDEN_AT_THIS_STAGE,
            'invariants' => [
                'provider is not chosen at this stage',
                'cognitive domain is not chosen at this stage',
                'policy is only changed through Policy Profile',
            ],
        ];
    }

    /**
     * The closed sensitivity vocabulary this step can assign, least to most
     * sensitive.
     *
     * @return list<string>
     */
    public function sensitivityLadder(): array
    {
        return array_values(self::SENSITIVITY_LADDER);
    }

    /**
     * Which business anchors are missing from the envelope, in stable order.
     *
     * @return list<string>
     */
    private function missingAnchors(?string $obra, ?string $workspace, ?string $project, ?string $product): array
    {
        $missing = [];
        if ($obra === null) {
            $missing[] = 'obra';
        }
        if ($workspace === null) {
            $missing[] = 'workspace';
        }
        if ($project === null) {
            $missing[] = 'project';
        }
        if ($product === null) {
            $missing[] = 'product';
        }

        return $missing;
    }

    /**
     * Business priority. High sensitivity or an explicit business-critical flag
     * lifts priority; an unanchored context cannot be trusted as high.
     *
     * @param  array<string, mixed>  $envelope
     */
    private function derivePriority(array $envelope, string $sensitivity, bool $anchored): string
    {
        if (! $anchored) {
            return self::PRIORITY_LOW;
        }

        if ($this->boolKey($envelope, 'business_critical')
            || $this->sensitivityIndex($sensitivity) >= $this->sensitivityIndex(self::SENSITIVITY_SENSITIVE)) {
            return self::PRIORITY_HIGH;
        }

        return self::PRIORITY_NORMAL;
    }

    /**
     * Risk band for the step. The doc is risk_level: high, so any unmet danger
     * (unanchored context, or an uncleared sensitive/secret class) is high. A
     * clean, anchored, low-sensitivity context is low.
     */
    private function deriveRisk(string $sensitivity, bool $anchored, bool $policyCleared): string
    {
        if (! $anchored || ! $policyCleared) {
            return self::RISK_HIGH;
        }

        $index = $this->sensitivityIndex($sensitivity);
        if ($index >= $this->sensitivityIndex(self::SENSITIVITY_SENSITIVE)) {
            return self::RISK_HIGH;
        }
        if ($index === $this->sensitivityIndex(self::SENSITIVITY_INTERNAL)) {
            return self::RISK_MEDIUM;
        }

        return self::RISK_LOW;
    }

    private function normalizeSensitivity(?string $value): string
    {
        if ($value === null) {
            return self::SENSITIVITY_INTERNAL;
        }

        $lower = mb_strtolower(trim($value));

        return in_array($lower, self::SENSITIVITY_LADDER, true)
            ? $lower
            : self::SENSITIVITY_INTERNAL;
    }

    private function sensitivityIndex(string $sensitivity): int
    {
        $index = array_search($sensitivity, self::SENSITIVITY_LADDER, true);

        return $index === false ? 0 : $index;
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function stringKey(array $envelope, string $key): ?string
    {
        $value = $envelope[$key] ?? null;
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function boolKey(array $envelope, string $key): bool
    {
        return array_key_exists($key, $envelope) && $envelope[$key] === true;
    }
}
