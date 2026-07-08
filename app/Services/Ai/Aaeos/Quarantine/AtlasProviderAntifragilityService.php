<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

use InvalidArgumentException;

/**
 * Pure, deterministic decider for the Atlas Provider Antifragility thesis.
 *
 * This is NOT a provider client and it never spends tokens. It encodes the
 * concrete decision rules the thesis doc states over plain typed arrays — no
 * database, no models, no side effects — so the thesis contract can be pinned
 * and reused independently of the live ingestion pipeline.
 *
 * Rules implemented (mapped to the doc sections):
 *
 *   - "Principle" (threat determination): provider improvement is an INPUT, not
 *     a product threat — UNLESS Atlas is acting like a wrapper for the attacked
 *     surface. The same release is an input when Atlas owns a structural moat
 *     above the provider, and a threat only when Atlas is wrapper-positioned.
 *
 *   - "Provider Release Ingestion": every major release flows through exactly
 *     five ordered steps (catalog -> compare -> position -> measure -> absorb).
 *     `position` selects exactly one of the documented positioning options.
 *     The obsolescence rule: if a provider feature makes an Atlas component
 *     obsolete, the component is converted to adapter/evaluation or removed —
 *     it is never kept as dead duplicate.
 *
 *   - "Structural Moats": the eight moats where providers are structurally weak.
 *     A release that attacks a structural moat is structurally defensible
 *     (providers are weak there because of incentives, not intelligence). A
 *     release that attacks raw intelligence (a non-moat) must be absorbed, not
 *     defended against.
 *
 *   - "Residual Threats": the three threats the thesis explicitly does NOT
 *     eliminate. Each carries a documented mitigation and can only ever reach
 *     `mitigated` status — never `eliminated`.
 *
 * Stateless and DB-free: every method is a pure function of its arguments.
 *
 * @see docs/engineering-knowledge-base/thesis/provider-antifragility.md
 */
final class AtlasProviderAntifragilityService
{
    public const SCHEMA_VERSION = 'atlas.thesis.provider_antifragility.v1';

    /**
     * The five ordered Provider Release Ingestion steps, in the doc's order.
     *
     * @var list<string>
     */
    public const INGESTION_STEPS = [
        'catalog',  // catalog capability/domain/surface/runtime impact
        'compare',  // compare against Atlas and direct-provider baseline
        'position', // position as driver/skill_pack/connector/ap/.../rejected
        'measure',  // measure with a relevant evaluation suite
        'absorb',   // absorb without hardcoding provider lock-in
    ];

    /**
     * The exhaustive positioning options for step 3 of ingestion. The doc's
     * measurement-suite target is represented by the neutral `evaluation`
     * option here.
     *
     * @var list<string>
     */
    public const POSITIONING_OPTIONS = [
        'driver',
        'skill_pack',
        'connector',
        'ap',
        'evaluation',
        'policy_signal',
        'runtime_option',
        'rejected_backlog',
    ];

    /**
     * The eight Structural Moats, in the doc's enumerated order. Providers are
     * structurally weak at every one of these.
     *
     * @var list<string>
     */
    public const STRUCTURAL_MOATS = [
        'sovereign_user_owned_memory',
        'neutral_multi_provider_routing',
        'continuity_across_providers',
        'immutable_user_constitution',
        'deterministic_audit_replay',
        'decade_long_personal_evidence_ledger',
        'local_personal_model_trained_on_owner_evidence',
        'business_personal_cognitive_context_only_atlas_owns',
    ];

    /**
     * The three Residual Threats the thesis does NOT eliminate, each mapped to
     * its documented mitigation surface.
     *
     * @var array<string,string>
     */
    public const RESIDUAL_THREATS = [
        'os_vendor_embedded_assistant' => 'power_user_workflows_os_assistants_cannot_personalize_as_deeply',
        'regulation_on_local_models_or_ledger_retention' => 'privacy_governance_and_hardware_sovereignty',
        'closed_future_surfaces' => 'mobile_voice_presence_surfaces',
    ];

    /**
     * Threat determination for a provider release.
     *
     * Doc Principle: "Provider improvement is an input. It is not a product
     * threat unless Atlas is acting like a wrapper." So a release is classified
     * as a real threat ONLY when Atlas is wrapper-positioned for the attacked
     * surface. When Atlas owns a structural moat above the provider, the very
     * same release is an absorbable input/driver.
     *
     * @return array{
     *   schema_version:string,
     *   atlas_is_wrapper_positioned:bool,
     *   classification:string,
     *   is_product_threat:bool,
     *   absorb_as_input:bool,
     *   reason:string
     * }
     */
    public function classifyRelease(bool $atlasIsWrapperPositioned): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'atlas_is_wrapper_positioned' => $atlasIsWrapperPositioned,
            'classification' => $atlasIsWrapperPositioned ? 'product_threat' : 'absorbable_input',
            'is_product_threat' => $atlasIsWrapperPositioned,
            // An input is absorbed; a wrapper-threat is NOT just absorbed, it forces repositioning above the provider.
            'absorb_as_input' => ! $atlasIsWrapperPositioned,
            'reason' => $atlasIsWrapperPositioned
                ? 'Atlas is wrapper-positioned for the attacked surface: the release is a product threat until Atlas moves above the provider.'
                : 'Atlas owns a moat above the provider: the release is an input absorbed into the ecosystem.',
        ];
    }

    /**
     * Position a release at ingestion step 3. Validates against the exhaustive
     * option set and reports whether the option keeps the capability behind an
     * Atlas-governed boundary (everything except an outright rejection does).
     *
     * @return array{
     *   schema_version:string,
     *   positioning:string,
     *   valid:bool,
     *   absorbed:bool,
     *   keeps_provider_behind_atlas:bool,
     *   hardcodes_lock_in:bool,
     *   reason:string
     * }
     */
    public function positionRelease(string $positioning): array
    {
        if (! in_array($positioning, self::POSITIONING_OPTIONS, true)) {
            throw new InvalidArgumentException("Unknown positioning option: {$positioning}");
        }

        $absorbed = $positioning !== 'rejected_backlog';

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'positioning' => $positioning,
            'valid' => true,
            'absorbed' => $absorbed,
            // Doc: absorb without hardcoding provider lock-in — every governed option keeps the provider behind Atlas.
            'keeps_provider_behind_atlas' => $absorbed,
            'hardcodes_lock_in' => false,
            'reason' => $absorbed
                ? "Release positioned as '{$positioning}': absorbed behind Atlas without hardcoding provider lock-in."
                : 'Release rejected to backlog: not absorbed; no Atlas component is created for it.',
        ];
    }

    /**
     * Drive a release through the five-step ingestion pipeline, in order.
     *
     * The steps MUST run in the documented order; a caller may run a prefix of
     * them (e.g. stop after `compare`). The result reports which steps ran, the
     * next required step, and whether ingestion is complete (reached `absorb`).
     *
     * @param  list<string>  $completedSteps
     * @return array{
     *   schema_version:string,
     *   ordered_steps:list<string>,
     *   completed:list<string>,
     *   valid_order:bool,
     *   complete:bool,
     *   next_step:?string,
     *   reason:string
     * }
     */
    public function ingestionProgress(array $completedSteps): array
    {
        $expectedPrefix = array_slice(self::INGESTION_STEPS, 0, count($completedSteps));
        $validOrder = array_values($completedSteps) === $expectedPrefix;

        $complete = $validOrder && count($completedSteps) === count(self::INGESTION_STEPS);
        $nextStep = $validOrder && ! $complete
            ? self::INGESTION_STEPS[count($completedSteps)]
            : null;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ordered_steps' => self::INGESTION_STEPS,
            'completed' => array_values($completedSteps),
            'valid_order' => $validOrder,
            'complete' => $complete,
            'next_step' => $nextStep,
            'reason' => match (true) {
                ! $validOrder => 'Ingestion steps must run in order: catalog -> compare -> position -> measure -> absorb.',
                $complete => 'Ingestion complete: release absorbed without hardcoding provider lock-in.',
                default => "Ingestion in progress: next required step is '{$nextStep}'.",
            },
        ];
    }

    /**
     * Obsolescence rule: if a provider feature makes an Atlas component obsolete,
     * convert that component to adapter/evaluation or remove it. The component is
     * NEVER kept as a dead duplicate competing with the provider feature.
     *
     * @return array{
     *   schema_version:string,
     *   component:string,
     *   made_obsolete:bool,
     *   has_residual_governance_value:bool,
     *   disposition:string,
     *   keep_as_duplicate:bool,
     *   reason:string
     * }
     */
    public function resolveObsoleteComponent(string $component, bool $madeObsolete, bool $hasResidualGovernanceValue): array
    {
        $name = trim($component);
        if ($name === '') {
            throw new InvalidArgumentException('Component name must not be empty.');
        }

        $disposition = match (true) {
            ! $madeObsolete => 'keep',
            $hasResidualGovernanceValue => 'convert_to_adapter_or_evaluation',
            default => 'remove',
        };

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'component' => $name,
            'made_obsolete' => $madeObsolete,
            'has_residual_governance_value' => $hasResidualGovernanceValue,
            'disposition' => $disposition,
            // Doc forbids keeping an obsolete component as a competing duplicate.
            'keep_as_duplicate' => false,
            'reason' => match ($disposition) {
                'keep' => "Component '{$name}' is not obsolete: kept as-is.",
                'convert_to_adapter_or_evaluation' => "Component '{$name}' is obsolete but retains governance value: convert to adapter/evaluation.",
                default => "Component '{$name}' is obsolete with no residual value: remove it.",
            },
        ];
    }

    /**
     * Structural-moat assessment for a release.
     *
     * Doc "Structural Moats": providers are structurally weak at the eight named
     * moats because their incentives push toward lock-in, generic scale and
     * provider-owned context — NOT because they are unintelligent. A release that
     * attacks one of these moats is structurally defensible. A release that
     * attacks raw intelligence (any non-moat surface) must be absorbed through
     * Constructive Market Parasitism, not defended against.
     *
     * @return array{
     *   schema_version:string,
     *   attacked_surface:string,
     *   is_structural_moat:bool,
     *   structurally_defensible:bool,
     *   recommended_response:string,
     *   weakness_is_incentive_not_intelligence:bool,
     *   reason:string
     * }
     */
    public function assessMoat(string $attackedSurface): array
    {
        $surface = trim($attackedSurface);
        if ($surface === '') {
            throw new InvalidArgumentException('Attacked surface must not be empty.');
        }

        $isMoat = in_array($surface, self::STRUCTURAL_MOATS, true);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'attacked_surface' => $surface,
            'is_structural_moat' => $isMoat,
            'structurally_defensible' => $isMoat,
            'recommended_response' => $isMoat ? 'hold_moat_above_provider' : 'absorb_raw_intelligence_through_governed_drivers',
            // The doc is explicit: the moat exists because of provider incentives, not lack of intelligence.
            'weakness_is_incentive_not_intelligence' => $isMoat,
            'reason' => $isMoat
                ? "Surface '{$surface}' is a structural moat: providers are weak here by incentive, so hold the moat above the provider."
                : "Surface '{$surface}' is raw intelligence, not a moat: absorb it through governed drivers instead of defending.",
        ];
    }

    /**
     * Residual-threat handling.
     *
     * Doc "Residual Threats": the thesis does NOT eliminate three named threats.
     * Each one is paired with a documented mitigation surface. A residual threat
     * can therefore only ever reach `mitigated` status — claiming `eliminated`
     * is forbidden, because the thesis explicitly does not remove these.
     *
     * @return array{
     *   schema_version:string,
     *   threat:string,
     *   recognized:bool,
     *   eliminated:bool,
     *   status:string,
     *   mitigation:?string,
     *   reason:string
     * }
     */
    public function handleResidualThreat(string $threat): array
    {
        $key = trim($threat);
        if ($key === '') {
            throw new InvalidArgumentException('Threat key must not be empty.');
        }

        $recognized = array_key_exists($key, self::RESIDUAL_THREATS);
        $mitigation = $recognized ? self::RESIDUAL_THREATS[$key] : null;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'threat' => $key,
            'recognized' => $recognized,
            // The thesis explicitly does not eliminate residual threats — only mitigate.
            'eliminated' => false,
            'status' => $recognized ? 'mitigated' : 'unrecognized',
            'mitigation' => $mitigation,
            'reason' => $recognized
                ? "Residual threat '{$key}' is not eliminated by the thesis; mitigated via {$mitigation}."
                : "Threat '{$key}' is not in the documented residual-threat set; escalate to thesis review before claiming any status.",
        ];
    }
}
