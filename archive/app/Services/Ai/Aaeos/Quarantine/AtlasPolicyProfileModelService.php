<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas AI Resolver Corpus Policy Profile Model — runtime.
 *
 * Turns the documented compact resolver-derived model into deterministic,
 * pure decision logic. It enforces the doc's concrete contract:
 *
 *  - The fixed layering order: Atlas AI Core -> Domain Profile -> Flow Profile
 *    -> Policy/Profile -> Atlas Decide -> Domain Orchestrator -> Runtime/Executor.
 *    Model selection is owned by Atlas Decide, NOT by the surface.
 *  - Surface-verb -> flow resolution: `atlas dev` -> programming.dev,
 *    `atlas forge` -> programming.forge, `atlas fix` -> programming repair flow.
 *  - The Invariant: a manual model override is an AUDITED override. It never
 *    makes the provider/model the owner of the flow; flow ownership stays with
 *    the resolved flow profile and a Decision Receipt is required.
 *  - Finance domain policy never auto-executes trades.
 *
 * Stateless and DB-free: every method is a pure function of its arguments.
 *
 * @see docs/engineering-knowledge-base/resolver-corpus/policy-profile-model.md
 */
final class AtlasPolicyProfileModelService
{
    public const SCHEMA_VERSION = 'atlas.resolver_corpus.policy_profile_model.v1';

    /**
     * Canonical layering order from the doc. Index = depth (0 = top authority).
     * Model selection is bound to the `atlas_decide` layer.
     *
     * @var list<string>
     */
    public const LAYERS = [
        'atlas_ai_core',
        'domain_profile',
        'flow_profile',
        'policy_profile',
        'atlas_decide',
        'domain_orchestrator',
        'runtime_executor',
    ];

    /** The layer that owns model selection (never the surface). */
    public const MODEL_SELECTION_LAYER = 'atlas_decide';

    /**
     * Documented surface verb -> flow resolution map.
     *
     * @var array<string,array{flow:string,domain:string,kind:string}>
     */
    private const FLOW_MAP = [
        'dev' => ['flow' => 'programming.dev', 'domain' => 'programming', 'kind' => 'build'],
        'forge' => ['flow' => 'programming.forge', 'domain' => 'programming', 'kind' => 'build'],
        'fix' => ['flow' => 'programming.repair', 'domain' => 'programming', 'kind' => 'repair'],
    ];

    /**
     * Return the canonical layering as an ordered list with depth + the role of
     * each layer, so callers can prove model selection sits at Atlas Decide.
     *
     * @return array{schema_version:string,layers:list<array{layer:string,depth:int,owns_model_selection:bool}>,model_selection_layer:string}
     */
    public function layering(): array
    {
        $layers = [];
        foreach (self::LAYERS as $depth => $layer) {
            $layers[] = [
                'layer' => $layer,
                'depth' => $depth,
                'owns_model_selection' => $layer === self::MODEL_SELECTION_LAYER,
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'layers' => $layers,
            'model_selection_layer' => self::MODEL_SELECTION_LAYER,
        ];
    }

    /**
     * Resolve a surface verb (e.g. "dev", "forge", "fix") to its canonical flow
     * profile per the doc's Examples. Unknown verbs are reported as unresolved
     * rather than guessed.
     *
     * @return array{schema_version:string,verb:string,resolved:bool,flow:?string,domain:?string,flow_kind:?string,reason:?string}
     */
    public function resolveFlow(string $surfaceVerb): array
    {
        $verb = strtolower(trim($surfaceVerb));
        // Tolerate the "atlas dev" form by taking the last token.
        if (str_contains($verb, ' ')) {
            $parts = array_values(array_filter(explode(' ', $verb), static fn ($p) => $p !== ''));
            $verb = $parts[count($parts) - 1] ?? '';
        }

        $entry = self::FLOW_MAP[$verb] ?? null;
        if ($entry === null) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'verb' => $verb,
                'resolved' => false,
                'flow' => null,
                'domain' => null,
                'flow_kind' => null,
                'reason' => 'unknown_surface_verb',
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'verb' => $verb,
            'resolved' => true,
            'flow' => $entry['flow'],
            'domain' => $entry['domain'],
            'flow_kind' => $entry['kind'],
            'reason' => null,
        ];
    }

    /**
     * Evaluate the doc's Invariant for a (possible) manual model override.
     *
     * Rules enforced:
     *  - An override is ALLOWED, but it is an audited override.
     *  - It NEVER transfers flow ownership to the provider/model; ownership stays
     *    with the resolved flow profile.
     *  - An audited override REQUIRES a Decision Receipt; if no receipt can be
     *    issued the override is rejected (the surface cannot bypass governance).
     *
     * @return array{
     *   schema_version:string,
     *   flow:string,
     *   override_requested:bool,
     *   override_allowed:bool,
     *   audited:bool,
     *   flow_owner:string,
     *   model_owner:string,
     *   requires_decision_receipt:bool,
     *   receipt_present:bool,
     *   rejected:bool,
     *   reason:?string
     * }
     */
    public function evaluateModelOverride(
        string $flow,
        ?string $overrideModel = null,
        bool $decisionReceiptPresent = false,
    ): array {
        $flow = trim($flow);
        $requested = $overrideModel !== null && trim($overrideModel) !== '';

        // No override: the flow profile owns both flow and model selection
        // (model selection happens at Atlas Decide under the flow's policy).
        if (! $requested) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'flow' => $flow,
                'override_requested' => false,
                'override_allowed' => false,
                'audited' => false,
                'flow_owner' => 'flow_profile',
                'model_owner' => self::MODEL_SELECTION_LAYER,
                'requires_decision_receipt' => false,
                'receipt_present' => false,
                'rejected' => false,
                'reason' => null,
            ];
        }

        // Override requested: it is allowed, but ALWAYS audited and ALWAYS needs
        // a Decision Receipt. Flow ownership never moves to the provider/model.
        $rejected = ! $decisionReceiptPresent;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'flow' => $flow,
            'override_requested' => true,
            'override_allowed' => true,
            'audited' => true,
            // The doc's invariant: provider/model does NOT become flow owner.
            'flow_owner' => 'flow_profile',
            'model_owner' => 'manual_override',
            'requires_decision_receipt' => true,
            'receipt_present' => $decisionReceiptPresent,
            'rejected' => $rejected,
            'reason' => $rejected ? 'audited_override_requires_decision_receipt' : null,
        ];
    }

    /**
     * Domain auto-execution policy. The doc states Finance review never
     * auto-executes trades; this generalizes to a per-domain auto-execute gate
     * for high-risk side effects.
     *
     * @return array{schema_version:string,domain:string,action:string,auto_execute_allowed:bool,requires_human_authorization:bool,reason:?string}
     */
    public function autoExecutionPolicy(string $domain, string $action): array
    {
        $domain = strtolower(trim($domain));
        $action = strtolower(trim($action));

        // Finance must never auto-execute trades.
        $financeBlocked = $domain === 'finance'
            && in_array($action, ['trade', 'live_trade', 'execute_trade', 'order'], true);

        $allowed = ! $financeBlocked;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'domain' => $domain,
            'action' => $action,
            'auto_execute_allowed' => $allowed,
            'requires_human_authorization' => ! $allowed,
            'reason' => $allowed ? null : 'finance_trades_never_auto_execute',
        ];
    }

    /**
     * Primary entry point: produce the full policy-profile-model snapshot used
     * by the command and as a single source of the doc's contract.
     *
     * @return array{
     *   schema_version:string,
     *   layering:array<string,mixed>,
     *   flow_examples:array<string,array<string,mixed>>,
     *   override_invariant:array<string,mixed>,
     *   finance_auto_execute:array<string,mixed>
     * }
     */
    public function model(): array
    {
        $flowExamples = [];
        foreach (array_keys(self::FLOW_MAP) as $verb) {
            $flowExamples[$verb] = $this->resolveFlow($verb);
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'layering' => $this->layering(),
            'flow_examples' => $flowExamples,
            // Canonical worked example of the invariant: override without receipt
            // is rejected, proving the surface cannot seize flow ownership.
            'override_invariant' => $this->evaluateModelOverride('programming.dev', 'manual:some-model', false),
            'finance_auto_execute' => $this->autoExecutionPolicy('finance', 'trade'),
        ];
    }
}
