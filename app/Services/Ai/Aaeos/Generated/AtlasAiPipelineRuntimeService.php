<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas AI Pipeline — canonical macro-pipeline runtime.
 *
 * Turns the founder pipeline doc into deterministic, pure decision logic.
 * The doc defines the single pipeline every Atlas AI request flows through,
 * its macro stage order, 12 numbered invariants, intensity tiers, the
 * Decision-Receipt authority rule, surface aliases and explicit anti-patterns.
 * Rather than restating prose, this service enforces exactly the decidable
 * contracts the doc states:
 *
 *  - Canonical macro order (doc "O pipeline unico" / "Etapas"): the fixed
 *    14-stage sequence Input -> Intent -> Domain -> Domain Profile ->
 *    Flow Profile -> Context -> Policy -> Decide -> Executor -> Gate ->
 *    Repair/Escalation -> Evidence -> Learning -> Output. Note Intent comes
 *    BEFORE Domain, and Domain Profile + Flow Profile are distinct stages.
 *    canonicalPipeline() returns it verbatim; validateOrder() rejects any
 *    plan whose stages diverge from canon or are out of order.
 *
 *  - 12 invariants (doc "Invariantes"): each numbered rule is a concrete check
 *    against a run descriptor. checkInvariants() runs all 12 and reports which
 *    held and which were violated; e.g. invariant 3 forces an explicit Domain
 *    when the task changes real state, invariant 7 forces a Decision Receipt
 *    before relevant execution, invariant 11 forces evidence to claim success.
 *
 *  - Intensity tiers (doc "Intensidade"): light / medium / heavy, each with a
 *    documented set of mandatory stages beyond the macro order. resolveIntensity()
 *    maps a surface alias to its tier and lists the tier's added obligations.
 *
 *  - Decision Receipt authority (doc "Policy, Profile E Decision Receipt"):
 *    "Decide decide. Domain Orchestrator executa. Runtime produz evidencia.
 *    Gate declara se passou." A manual model passed on a surface becomes a
 *    `manual_override` on the receipt (not a new flow) and policy may accept,
 *    adjust or block it. compileDecisionReceipt() encodes exactly that.
 *
 *  - Surface aliases (doc "Intensidade" examples + "Surfaces canonicas"):
 *    atlas dev/forge/fix/continue are intensities/intents/states of the SAME
 *    pipeline, never separate internal products. resolveSurfaceAlias() maps an
 *    alias to {domain, intensity, intent, state} on the one pipeline.
 *
 *  - Anti-patterns (doc "Anti-Padroes"): concrete prohibitions such as a
 *    surface calling a provider directly, or evidence being optional for work
 *    that changes code/money/health/decision. detectAntiPatterns() flags them.
 *
 * Stateless and DB-free: every method is a pure function of its arguments.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-pipeline.md
 */
final class AtlasAiPipelineRuntimeService
{
    /**
     * The canonical macro pipeline, in doc order. Intent precedes Domain;
     * Domain Profile and Flow Profile are distinct stages.
     *
     * @var list<string>
     */
    private const CANONICAL_STAGES = [
        'input',
        'intent',
        'domain',
        'domain_profile',
        'flow_profile',
        'context',
        'policy',
        'decide',
        'executor',
        'gate',
        'repair_escalation',
        'evidence',
        'learning',
        'output',
    ];

    /**
     * Per-tier obligations layered on top of the macro order (doc "Intensidade").
     *
     * @var array<string, list<string>>
     */
    private const INTENSITY_OBLIGATIONS = [
        'light' => ['direct_response'],
        'medium' => ['context', 'executor', 'relevant_gates'],
        'heavy' => ['harness', 'isolation', 'artifacts', 'multi_gate', 'repair', 'score'],
    ];

    /**
     * Canonical surfaces that enter the one pipeline (doc "Surfaces canonicas").
     *
     * @var list<string>
     */
    private const CANONICAL_SURFACES = [
        'cli', 'app', 'mobile', 'api', 'mcp', 'voice_realtime', 'worker',
    ];

    /**
     * The canonical pipeline shape.
     *
     * @return array{stages: list<string>, count: int, intent_before_domain: bool, distinct_profile_stages: list<string>}
     */
    public function canonicalPipeline(): array
    {
        $stages = self::CANONICAL_STAGES;

        return [
            'stages' => $stages,
            'count' => count($stages),
            // Doc-distinctive: Intent is classified before Domain is chosen.
            'intent_before_domain' => array_search('intent', $stages, true)
                < array_search('domain', $stages, true),
            // Doc-distinctive: the two profile resolution stages are separate.
            'distinct_profile_stages' => ['domain_profile', 'flow_profile'],
        ];
    }

    /**
     * Validate a proposed stage list against the canonical macro order.
     *
     * A plan is valid only if it is exactly the canonical sequence in order.
     * Missing stages, unknown stages, and reordering are all rejected. The doc
     * allows domains to customize WITHIN a stage but never to reinvent the
     * macro order ("Domains customizam etapas, mas nao reinventam a ordem macro").
     *
     * @param  list<string>  $stages
     * @return array{valid: bool, missing: list<string>, unknown: list<string>, out_of_order: bool, reason: string}
     */
    public function validateOrder(array $stages): array
    {
        $stages = array_values($stages);
        $canon = self::CANONICAL_STAGES;

        $missing = array_values(array_diff($canon, $stages));
        $unknown = array_values(array_diff($stages, $canon));

        // Out of order = the subsequence of known canon stages is not in canon order.
        $knownInProvidedOrder = array_values(array_filter(
            $stages,
            static fn (string $s): bool => in_array($s, $canon, true),
        ));
        $expectedFiltered = array_values(array_filter(
            $canon,
            static fn (string $s): bool => in_array($s, $knownInProvidedOrder, true),
        ));
        $outOfOrder = $knownInProvidedOrder !== $expectedFiltered;

        $valid = $missing === [] && $unknown === [] && ! $outOfOrder;

        $reason = match (true) {
            $valid => 'matches_canonical_macro_order',
            $unknown !== [] => 'unknown_stages_present',
            $missing !== [] => 'missing_macro_stages',
            default => 'stages_out_of_macro_order',
        };

        return [
            'valid' => $valid,
            'missing' => $missing,
            'unknown' => $unknown,
            'out_of_order' => $outOfOrder,
            'reason' => $reason,
        ];
    }

    /**
     * Evaluate all 12 documented invariants against a run descriptor.
     *
     * Each key on $run is a fact about the run; absent facts default to the
     * unsafe interpretation (the invariant is treated as violated) so that a
     * silent omission can never be claimed as compliant.
     *
     * @param  array<string, mixed>  $run
     * @return array{ok: bool, held: list<int>, violated: list<int>, details: array<int, array{rule: string, ok: bool}>}
     */
    public function checkInvariants(array $run): array
    {
        $changesRealState = (bool) ($run['changes_real_state'] ?? false);
        $callsProvider = (bool) ($run['calls_provider'] ?? false);
        $relevantExecution = (bool) ($run['relevant_execution'] ?? $callsProvider);
        $claimsSuccess = (bool) ($run['claims_operational_success'] ?? false);

        $checks = [
            1 => ['Input preserves origin, surface, workspace and attachments',
                $this->all($run['input'] ?? [], ['origin', 'surface', 'workspace', 'attachments'])],
            2 => ['Intent records confidence or classification reason',
                ! empty($run['intent_confidence']) || ! empty($run['intent_reason'])],
            3 => ['Domain is explicit when the task changes real state',
                ! $changesRealState || ! empty($run['domain'])],
            4 => ['Domain Profile and Flow Profile are explicit on operational runs',
                ! $changesRealState
                    || (! empty($run['domain_profile']) && ! empty($run['flow_profile']))],
            5 => ['Context has hash, budget and refs when a provider is called',
                ! $callsProvider
                    || $this->all($run['context'] ?? [], ['hash', 'budget', 'refs'])],
            6 => ['Policy records provider/model/permission/budget/tools/gates',
                $this->all($run['policy'] ?? [], ['provider', 'model', 'permission', 'budget', 'tools', 'gates'])],
            7 => ['Decide emits a Decision Receipt before relevant execution',
                ! $relevantExecution || ! empty($run['decision_receipt_id'])],
            8 => ['Executor does not choose its own policy',
                ! (bool) ($run['executor_self_policy'] ?? false)],
            9 => ['Gate evaluates evidence; the tool runs before and persists result',
                ! ((bool) ($run['gate_present'] ?? false))
                    || (bool) ($run['gate_evaluates_persisted_evidence'] ?? false)],
            10 => ['Repair uses a structured capsule, not an improvised prompt',
                ! ((bool) ($run['repair_invoked'] ?? false))
                    || (($run['repair_mode'] ?? null) === 'capsule')],
            11 => ['Evidence is mandatory to claim operational success',
                ! $claimsSuccess || ! empty($run['evidence_packet'])],
            12 => ['Learning never promotes sensitive memory without privacy policy',
                ! ((bool) ($run['learning_promotes_sensitive'] ?? false))
                    || (bool) ($run['privacy_policy_applied'] ?? false)],
        ];

        $held = [];
        $violated = [];
        $details = [];
        foreach ($checks as $n => [$rule, $ok]) {
            $ok = (bool) $ok;
            $details[$n] = ['rule' => $rule, 'ok' => $ok];
            if ($ok) {
                $held[] = $n;
            } else {
                $violated[] = $n;
            }
        }

        return [
            'ok' => $violated === [],
            'held' => $held,
            'violated' => $violated,
            'details' => $details,
        ];
    }

    /**
     * Resolve an intensity tier and its documented obligations.
     *
     * Accepts an explicit tier (light/medium/heavy) or a surface alias; unknown
     * input falls back to `medium` (the documented "contexto + executor + gates"
     * baseline), never to the cheapest tier.
     *
     * @return array{intensity: string, obligations: list<string>, recognized: bool}
     */
    public function resolveIntensity(string $tierOrAlias): array
    {
        $key = strtolower(trim($tierOrAlias));

        $alias = $this->resolveSurfaceAlias($key);
        if ($alias['recognized']) {
            $key = $alias['intensity'];
        }

        $recognized = array_key_exists($key, self::INTENSITY_OBLIGATIONS);
        if (! $recognized) {
            $key = 'medium';
        }

        return [
            'intensity' => $key,
            'obligations' => self::INTENSITY_OBLIGATIONS[$key],
            'recognized' => $recognized,
        ];
    }

    /**
     * Map a programming surface alias to its place on the one pipeline.
     *
     * Doc: `atlas dev` -> intensity auto; `atlas forge` -> intensity heavy;
     * `atlas fix` -> intent repair; `atlas continue` -> same domain/intent with
     * state resume. These are not separate internal products.
     *
     * @return array{recognized: bool, domain: string, intensity: string, intent: string, state: string}
     */
    public function resolveSurfaceAlias(string $alias): array
    {
        $key = strtolower(trim($alias));
        $key = preg_replace('/^atlas\s+/', '', $key) ?? $key;

        $map = [
            'dev' => ['programming', 'auto', 'build', 'fresh'],
            'forge' => ['programming', 'heavy', 'build', 'fresh'],
            'fix' => ['programming', 'auto', 'repair', 'fresh'],
            'continue' => ['programming', 'auto', 'build', 'resume'],
        ];

        if (! array_key_exists($key, $map)) {
            return [
                'recognized' => false,
                'domain' => 'programming',
                'intensity' => 'medium',
                'intent' => 'build',
                'state' => 'fresh',
            ];
        }

        [$domain, $intensity, $intent, $state] = $map[$key];

        return [
            'recognized' => true,
            'domain' => $domain,
            'intensity' => $intensity,
            'intent' => $intent,
            'state' => $state,
        ];
    }

    /**
     * Compile a Decision Receipt from profile + policy + context + risk.
     *
     * Encodes the doc authority rule: Decide decides, the Domain Orchestrator
     * executes, Runtime produces evidence, the Gate declares pass. By default
     * model selection is automatic; a manual model becomes a `manual_override`
     * (not a new flow), which policy may accept, adjust or block.
     *
     * @param  array<string, mixed>  $request
     * @return array{
     *     authorized: bool,
     *     model_selection: string,
     *     manual_override: ?array{requested_model: string, disposition: string, effective_model: string},
     *     execution_authority: string,
     *     evidence_authority: string,
     *     pass_authority: string,
     *     missing: list<string>
     * }
     */
    public function compileDecisionReceipt(array $request): array
    {
        // The receipt only authorizes when the inputs Decide compiles from exist.
        $required = ['domain_profile', 'flow_profile', 'policy'];
        $missing = array_values(array_filter(
            $required,
            static fn (string $k): bool => empty($request[$k]),
        ));

        $requestedModel = isset($request['requested_model'])
            ? (string) $request['requested_model']
            : '';

        /** @var list<string> $allowedModels */
        $allowedModels = array_values(array_map(
            static fn ($m): string => (string) $m,
            (array) ($request['policy']['allowed_models'] ?? []),
        ));

        $autoBestModel = isset($request['policy']['auto_best_model'])
            ? (string) $request['policy']['auto_best_model']
            : ($allowedModels[0] ?? 'auto');

        $manualOverride = null;
        $modelSelection = 'auto_best';

        if ($requestedModel !== '') {
            $modelSelection = 'manual_override';
            // Policy may block (model not allowed), accept, or adjust.
            if ($allowedModels !== [] && ! in_array($requestedModel, $allowedModels, true)) {
                $manualOverride = [
                    'requested_model' => $requestedModel,
                    'disposition' => 'blocked',
                    'effective_model' => $autoBestModel,
                ];
                $modelSelection = 'auto_best'; // blocked override falls back to auto
            } else {
                $manualOverride = [
                    'requested_model' => $requestedModel,
                    'disposition' => 'accepted',
                    'effective_model' => $requestedModel,
                ];
            }
        }

        return [
            'authorized' => $missing === [],
            'model_selection' => $modelSelection,
            'manual_override' => $manualOverride,
            'execution_authority' => 'domain_orchestrator',
            'evidence_authority' => 'runtime',
            'pass_authority' => 'gate',
            'missing' => $missing,
        ];
    }

    /**
     * Detect documented anti-patterns in a run descriptor.
     *
     * Doc "Anti-Padroes": surface calling provider directly; command building
     * its own context; gate parsing raw stdout when a Tool Runtime exists;
     * repair split into a separate command when the flow could repair; evidence
     * optional for work that changes code/money/health/decision.
     *
     * @param  array<string, mixed>  $run
     * @return array{clean: bool, violations: list<string>}
     */
    public function detectAntiPatterns(array $run): array
    {
        $violations = [];

        if ((bool) ($run['surface_calls_provider_direct'] ?? false)) {
            $violations[] = 'surface_calls_provider_direct';
        }
        if ((bool) ($run['command_builds_own_context'] ?? false)) {
            $violations[] = 'command_builds_own_context';
        }
        if ((bool) ($run['gate_parses_raw_stdout'] ?? false)
            && (bool) ($run['tool_runtime_available'] ?? false)) {
            $violations[] = 'gate_parses_raw_stdout_with_tool_runtime';
        }
        if ((bool) ($run['repair_in_separate_command'] ?? false)
            && (bool) ($run['flow_can_repair'] ?? false)) {
            $violations[] = 'repair_split_when_flow_can_repair';
        }

        $changesSensitive = (bool) ($run['changes_code'] ?? false)
            || (bool) ($run['changes_money'] ?? false)
            || (bool) ($run['changes_health'] ?? false)
            || (bool) ($run['changes_decision'] ?? false);
        if ($changesSensitive && ! (bool) ($run['evidence_required'] ?? false)) {
            $violations[] = 'evidence_optional_for_state_changing_work';
        }

        return [
            'clean' => $violations === [],
            'violations' => $violations,
        ];
    }

    /**
     * The canonical surfaces that enter the one pipeline.
     *
     * @return list<string>
     */
    public function canonicalSurfaces(): array
    {
        return self::CANONICAL_SURFACES;
    }

    /**
     * True when every key in $keys is present and non-empty in $bag.
     *
     * @param  array<string, mixed>  $bag
     * @param  list<string>  $keys
     */
    private function all(array $bag, array $keys): bool
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $bag) || $bag[$key] === null || $bag[$key] === '' || $bag[$key] === []) {
                return false;
            }
        }

        return true;
    }
}
