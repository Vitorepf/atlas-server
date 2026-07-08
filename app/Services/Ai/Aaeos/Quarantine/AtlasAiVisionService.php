<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas AI Vision runtime.
 *
 * Turns the founding vision doc into deterministic, pure decision logic. The
 * doc is short but states several decidable contracts; rather than restating
 * prose, this service enforces exactly the rules the doc declares:
 *
 *  - Mother structure (doc "Estrutura Mae"): Atlas AI is composed of exactly
 *    Core / Domains / Pipeline / Surfaces / Curation.
 *
 *  - Canonical pipeline (doc "Pipeline"): every relevant work passes the fixed
 *    ordered sequence input -> intent -> domain -> context -> policy ->
 *    executor -> gate -> repair_escalation -> evidence -> learning -> output.
 *    validatePipeline() rejects unknown stages, out-of-order stages, and (doc:
 *    "nao deve pular etapas sem justificativa auditavel") any skipped stage
 *    that lacks an auditable justification.
 *
 *  - Placement law (doc "Regra Final"):
 *      * "Nada nasce em uma surface se pode nascer no Core." A capability that
 *        serves more than one surface OR more than one domain belongs to Core,
 *        never to a surface (doc "Core": "Se uma capacidade serve para mais de
 *        uma surface ou mais de um domain, ela vive no Core.").
 *      * "Nada vira domain se ainda e capability horizontal." A horizontal
 *        capability may not be promoted to a domain.
 *      * "Nada declara sucesso sem evidence." A success claim without evidence
 *        is rejected. classifyPlacement() / canDeclareSuccess() enforce these.
 *
 *  - Surface constraint (doc "Surfaces"): a surface only collects input, passes
 *    hints and calls the pipeline; it must NOT own Core/Domain business logic.
 *    auditSurface() flags a surface that carries business logic.
 *
 *  - Single sovereign channel (doc "Tese Central" / "Canal unico"): interaction
 *    with provider IA must route through Atlas; direct provider use breaks the
 *    Evidence -> Curator -> Multiplicador cycle. auditChannel() rejects a direct
 *    provider call that bypasses Atlas.
 *
 * Stateless and DB-free: every method is a pure function of its arguments.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-vision.md
 */
final class AtlasAiVisionService
{
    public const SCHEMA_VERSION = 'atlas.aaeos.ai_vision.v1';

    /**
     * The mother structure of Atlas AI (doc "Estrutura Mae"), verbatim.
     *
     * @var list<string>
     */
    public const MOTHER_STRUCTURE = [
        'core',
        'domains',
        'pipeline',
        'surfaces',
        'curation',
    ];

    /**
     * The canonical pipeline stage order (doc "Pipeline" diagram), verbatim and
     * in sequence. Every relevant work passes this; stages may be customized in
     * content but not skipped without auditable justification.
     *
     * @var list<string>
     */
    public const CANONICAL_PIPELINE = [
        'input',
        'intent',
        'domain',
        'context',
        'policy',
        'executor',
        'gate',
        'repair_escalation',
        'evidence',
        'learning',
        'output',
    ];

    /**
     * Core capabilities that may exist only once (doc "Core"). These are
     * horizontal by definition and live in Core, never duplicated in a surface
     * or domain.
     *
     * @var list<string>
     */
    public const CORE_CAPABILITIES = [
        'input_multimodal',
        'intent',
        'context',
        'memory',
        'policy',
        'providers',
        'executors',
        'gates',
        'tools',
        'evidence',
        'learning',
    ];

    /**
     * The surfaces the doc enumerates (doc "Surfaces"): ports only.
     *
     * @var list<string>
     */
    public const SURFACES = [
        'cli',
        'app',
        'api',
        'workers',
        'ide_mcp',
        'automations',
    ];

    /**
     * Return the founding structure + canonical pipeline as a read model
     * (doc "Estrutura Mae" + "Pipeline").
     *
     * @return array{
     *   schema_version:string,
     *   mother_structure:list<string>,
     *   pipeline:list<string>,
     *   pipeline_stage_count:int,
     *   core_capabilities:list<string>,
     *   surfaces:list<string>
     * }
     */
    public function vision(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mother_structure' => self::MOTHER_STRUCTURE,
            'pipeline' => self::CANONICAL_PIPELINE,
            'pipeline_stage_count' => count(self::CANONICAL_PIPELINE),
            'core_capabilities' => self::CORE_CAPABILITIES,
            'surfaces' => self::SURFACES,
        ];
    }

    /**
     * Validate a proposed pipeline plan against the canon (doc "Pipeline":
     * fixed order; "Domain pode customizar o conteudo de cada etapa, mas nao
     * deve pular etapas sem justificativa auditavel.").
     *
     * Rules enforced:
     *  - Unknown stage (not in CANONICAL_PIPELINE) => violation.
     *  - Out-of-order stage (canon members not in canon relative order) =>
     *    violation.
     *  - A canonical stage that is absent from the plan is a "skip". A skip is
     *    a violation UNLESS that stage id is listed in `justified_skips` (the
     *    doc's "justificativa auditavel" escape hatch).
     *
     * @param  array{stages?:list<string>,justified_skips?:list<string>}  $plan
     * @return array{
     *   schema_version:string,
     *   unknown_stages:list<string>,
     *   order_valid:bool,
     *   skipped_stages:list<string>,
     *   unjustified_skips:list<string>,
     *   valid:bool,
     *   reason:string
     * }
     */
    public function validatePipeline(array $plan): array
    {
        $stages = array_values(array_map(
            static fn ($s): string => self::normalize((string) $s),
            $plan['stages'] ?? [],
        ));
        $justified = array_values(array_map(
            static fn ($s): string => self::normalize((string) $s),
            $plan['justified_skips'] ?? [],
        ));

        $unknown = array_values(array_filter(
            $stages,
            static fn (string $s): bool => ! in_array($s, self::CANONICAL_PIPELINE, true),
        ));

        // Order check: declared stages restricted to canon members must appear
        // in the same relative order as the canon (no reordering).
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

        // Skipped canonical stages.
        $skipped = array_values(array_filter(
            self::CANONICAL_PIPELINE,
            static fn (string $s): bool => ! in_array($s, $stages, true),
        ));
        // A skip is allowed only with an auditable justification.
        $unjustified = array_values(array_filter(
            $skipped,
            static fn (string $s): bool => ! in_array($s, $justified, true),
        ));

        $valid = $unknown === [] && $orderValid && $unjustified === [];

        $reason = match (true) {
            $unknown !== [] => 'pipeline_contains_non_canonical_stages',
            ! $orderValid => 'pipeline_order_diverges_from_canon',
            $unjustified !== [] => 'pipeline_skips_stage_without_auditable_justification',
            default => 'pipeline_plan_valid',
        };

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'unknown_stages' => $unknown,
            'order_valid' => $orderValid,
            'skipped_stages' => $skipped,
            'unjustified_skips' => $unjustified,
            'valid' => $valid,
            'reason' => $reason,
        ];
    }

    /**
     * Decide where a capability must live, per the placement law (doc "Core" +
     * "Regra Final").
     *
     * Decision order:
     *  1. A capability that serves more than one surface OR more than one domain
     *     is horizontal => it MUST live in Core (doc: "Se uma capacidade serve
     *     para mais de uma surface ou mais de um domain, ela vive no Core.").
     *     It may never be born in a surface and may never become a domain.
     *  2. A known Core capability (CORE_CAPABILITIES) => Core, regardless of the
     *     requested target.
     *  3. A capability serving exactly one domain (and not horizontal) may live
     *     in that domain.
     *  4. Otherwise it is a single-surface concern and may live in a surface.
     *
     * Any attempt to place a horizontal capability in a surface or to promote it
     * to a domain is reported as a violation of the doc's final rule.
     *
     * @param  array{
     *   capability?:string,
     *   surfaces_served?:list<string>,
     *   domains_served?:list<string>,
     *   requested_placement?:string
     * }  $input
     * @return array{
     *   schema_version:string,
     *   capability:string,
     *   horizontal:bool,
     *   required_placement:string,
     *   requested_placement:?string,
     *   placement_ok:bool,
     *   violated_rule:?string,
     *   reason:string
     * }
     */
    public function classifyPlacement(array $input): array
    {
        $capability = self::normalize((string) ($input['capability'] ?? ''));
        $surfaces = array_values(array_unique(array_map(
            static fn ($s): string => self::normalize((string) $s),
            $input['surfaces_served'] ?? [],
        )));
        $domains = array_values(array_unique(array_map(
            static fn ($s): string => self::normalize((string) $s),
            $input['domains_served'] ?? [],
        )));
        $requested = isset($input['requested_placement'])
            ? self::normalize((string) $input['requested_placement'])
            : null;

        $isKnownCore = in_array($capability, self::CORE_CAPABILITIES, true);
        $horizontal = $isKnownCore || count($surfaces) > 1 || count($domains) > 1;

        if ($horizontal) {
            $required = 'core';
        } elseif (count($domains) === 1) {
            $required = 'domain';
        } else {
            $required = 'surface';
        }

        // Evaluate the requested placement against the doc's final rule.
        $violated = null;
        if ($requested !== null && $requested !== $required) {
            if ($horizontal && $requested === 'surface') {
                // "Nada nasce em uma surface se pode nascer no Core."
                $violated = 'nothing_born_in_surface_if_it_can_be_core';
            } elseif ($horizontal && $requested === 'domain') {
                // "Nada vira domain se ainda e capability horizontal."
                $violated = 'nothing_becomes_domain_while_still_horizontal';
            } else {
                $violated = 'requested_placement_diverges_from_required';
            }
        }

        $placementOk = $violated === null;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'capability' => $capability,
            'horizontal' => $horizontal,
            'required_placement' => $required,
            'requested_placement' => $requested,
            'placement_ok' => $placementOk,
            'violated_rule' => $violated,
            'reason' => $placementOk
                ? 'placement_matches_required'
                : $violated,
        ];
    }

    /**
     * Audit a surface against the doc's surface constraint (doc "Surfaces":
     * "Surfaces nao devem implementar logica de negocio que pertence ao Core ou
     * a um Domain. Uma surface coleta input, passa hints e chama o pipeline.").
     *
     * A surface is compliant only when it does NOT own business logic. Owning
     * Core/Domain business logic is a violation regardless of any other signal.
     * An unknown surface name is reported (never silently accepted).
     *
     * @param  array{surface?:string,owns_business_logic?:bool}  $input
     * @return array{
     *   schema_version:string,
     *   surface:string,
     *   known_surface:bool,
     *   owns_business_logic:bool,
     *   compliant:bool,
     *   reason:string
     * }
     */
    public function auditSurface(array $input): array
    {
        $surface = self::normalize((string) ($input['surface'] ?? ''));
        $ownsLogic = (bool) ($input['owns_business_logic'] ?? false);
        $known = in_array($surface, self::SURFACES, true);

        $compliant = $known && ! $ownsLogic;

        $reason = match (true) {
            ! $known => 'unknown_surface',
            $ownsLogic => 'surface_owns_business_logic_belonging_to_core_or_domain',
            default => 'surface_only_collects_input_and_calls_pipeline',
        };

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'surface' => $surface,
            'known_surface' => $known,
            'owns_business_logic' => $ownsLogic,
            'compliant' => $compliant,
            'reason' => $reason,
        ];
    }

    /**
     * Enforce the final rule "Nada declara sucesso sem evidence." A success
     * claim is accepted only when it carries at least one piece of evidence.
     *
     * @param  array{claims_success?:bool,evidence?:list<mixed>}  $input
     * @return array{
     *   schema_version:string,
     *   claims_success:bool,
     *   evidence_count:int,
     *   may_declare_success:bool,
     *   reason:string
     * }
     */
    public function canDeclareSuccess(array $input): array
    {
        $claims = (bool) ($input['claims_success'] ?? false);
        $evidence = array_values(array_filter(
            $input['evidence'] ?? [],
            static fn ($e): bool => $e !== null && $e !== '',
        ));
        $count = count($evidence);

        if (! $claims) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'claims_success' => false,
                'evidence_count' => $count,
                'may_declare_success' => true,
                'reason' => 'no_success_claim_to_gate',
            ];
        }

        $may = $count > 0;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'claims_success' => true,
            'evidence_count' => $count,
            'may_declare_success' => $may,
            'reason' => $may
                ? 'success_claim_backed_by_evidence'
                : 'nothing_declares_success_without_evidence',
        ];
    }

    /**
     * Audit a provider interaction against the single-sovereign-channel rule
     * (doc "Tese Central": "Atlas precisa ser a UNICA via de interacao com IA";
     * direct provider use "quebra o ciclo virtuoso"). A provider call is
     * compliant only when routed through Atlas.
     *
     * @param  array{provider?:string,routed_through_atlas?:bool}  $input
     * @return array{
     *   schema_version:string,
     *   provider:string,
     *   routed_through_atlas:bool,
     *   compliant:bool,
     *   breaks_multiplier_cycle:bool,
     *   reason:string
     * }
     */
    public function auditChannel(array $input): array
    {
        $provider = self::normalize((string) ($input['provider'] ?? ''));
        $routed = (bool) ($input['routed_through_atlas'] ?? false);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'provider' => $provider,
            'routed_through_atlas' => $routed,
            'compliant' => $routed,
            'breaks_multiplier_cycle' => ! $routed,
            'reason' => $routed
                ? 'interaction_routed_through_atlas_single_channel'
                : 'direct_provider_use_breaks_evidence_curator_multiplier_cycle',
        ];
    }

    private static function normalize(string $value): string
    {
        return strtolower(trim($value));
    }
}
