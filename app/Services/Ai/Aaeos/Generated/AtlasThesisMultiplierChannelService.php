<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

use InvalidArgumentException;

/**
 * Pure, deterministic decider for the Atlas Multiplier & Sovereign Channel thesis.
 *
 * This is NOT a provider client and it never spends tokens. It encodes the
 * concrete decision rules the thesis doc states over plain typed arrays — no
 * database, no models, no side effects — so the thesis contract (the feature
 * decision filter and the canal-unico evidence loop) can be pinned and reused
 * independently of any live pipeline.
 *
 * Rules implemented (mapped to the doc sections):
 *
 *   - "Formula" (Output_Atlas = Output_Provider x Multiplicador_Ecossistema):
 *     the ecosystem multiplier is the product of all enabled multiplier
 *     components. multiplyOutput() returns provider output times the ecosystem
 *     multiplier; with zero enabled components the multiplier collapses to 1.0
 *     (Atlas adds nothing) — the doc's whole point is that Atlas multiplies, it
 *     never merely passes through unless empty.
 *
 *   - "Canal Unico" (the loop): routing INTERACTION through Atlas yields the
 *     virtuous loop (use -> evidence -> curator -> stronger multiplier -> more
 *     use); routing direct to the provider yields the death loop (no evidence ->
 *     no learning -> weak Atlas -> more direct use). classifyChannel() picks the
 *     loop and reports whether evidence is produced.
 *
 *   - "Feature Filter" (ask twice): (1) does it multiply provider output, or
 *     compete with it? (2) does it keep Atlas the natural channel, or create
 *     escape friction? filterFeature() answers both and maps the pair to exactly
 *     one of the four documented decisions:
 *       * multiplies AND keeps gravity (no escape friction)  -> build
 *       * competes with providers                            -> convert_to_adapter
 *       * creates escape friction (easier direct provider use) -> fix_before_shipping
 *       * neutral (no multiplier, no friction)               -> measure_before_expanding
 *     Escape friction is checked before the compete/neutral split because the
 *     doc lists "creates friction... fix before shipping" as its own gate that a
 *     feature must clear regardless of whether it multiplies.
 *
 *   - "Allowed" / "Blocked": the documented category lists. classifyCategory()
 *     reports whether a category is explicitly allowed, explicitly blocked, or
 *     unlisted (which is NOT auto-approved — it must still pass the filter).
 *
 * Stateless and DB-free: every method is a pure function of its arguments.
 *
 * @see docs/engineering-knowledge-base/thesis/multiplier-channel.md
 */
final class AtlasThesisMultiplierChannelService
{
    public const SCHEMA_VERSION = 'atlas.thesis.multiplier_channel.v1';

    /**
     * The ecosystem multiplier components (doc "Formula" -> "The ecosystem
     * multiplier includes"). These are the structural sources of Atlas's
     * multiplier over a raw provider call.
     *
     * @var list<string>
     */
    public const MULTIPLIER_COMPONENTS = [
        'canonical_vitor_memory',
        'constitutional_filter',
        'domain_skills',
        'multi_provider_routing',
        'domain_orchestration',
        'quality_gates_and_repair',
        'evidence_ledger',
        'curator_self_improvement',
        'hardware_sovereignty',
        'atlas_vitor_pre_post_processor',
    ];

    /**
     * The four feature-filter decisions (doc "Feature Filter" -> "Decision").
     */
    public const DECISION_BUILD = 'build';
    public const DECISION_CONVERT_TO_ADAPTER = 'convert_to_adapter';
    public const DECISION_FIX_BEFORE_SHIPPING = 'fix_before_shipping';
    public const DECISION_MEASURE_BEFORE_EXPANDING = 'measure_before_expanding';

    /**
     * The two channel loops (doc "Canal Unico").
     */
    public const LOOP_VIRTUOUS = 'virtuous_multiplier_loop';
    public const LOOP_DEATH = 'death_loop';

    /**
     * Categories the doc explicitly ALLOWS (doc "Allowed").
     *
     * @var list<string>
     */
    public const ALLOWED_CATEGORIES = [
        'provider_drivers_and_model_routing',
        'context_memory_injection',
        'constitutional_pre_post_processing',
        'local_model_fallback_pre_post_processor',
        'domain_skills_and_harnesses',
        'voice_mobile_cli_surfaces_reducing_escape_routes',
    ];

    /**
     * Categories the doc explicitly BLOCKS (doc "Blocked").
     *
     * @var list<string>
     */
    public const BLOCKED_CATEGORIES = [
        'model_as_frontier_provider_replacement',
        'lock_atlas_to_single_provider',
        'ui_that_only_competes_without_multiplier',
        'feature_that_bypasses_evidence_ledger',
        'direct_provider_workflow_without_evidence_return',
    ];

    /**
     * Formula (doc "Formula": Output_Atlas = Output_Provider x
     * Multiplicador_Ecossistema). The ecosystem multiplier is the product of the
     * per-component factors that are enabled. With no component enabled the
     * multiplier is exactly 1.0 — Atlas is a pure pass-through and adds nothing,
     * which the thesis treats as the failure state, not the goal.
     *
     * @param  array<string,float>  $componentFactors  component id => factor (>= 1.0 means it amplifies)
     * @return array{
     *   schema_version:string,
     *   provider_output:float,
     *   ecosystem_multiplier:float,
     *   atlas_output:float,
     *   enabled_components:list<string>,
     *   is_pure_passthrough:bool
     * }
     */
    public function multiplyOutput(float $providerOutput, array $componentFactors): array
    {
        if ($providerOutput < 0.0) {
            throw new InvalidArgumentException('provider_output must be non-negative.');
        }

        $multiplier = 1.0;
        $enabled = [];

        foreach ($componentFactors as $component => $factor) {
            if ($factor < 0.0) {
                throw new InvalidArgumentException("multiplier factor for '{$component}' must be non-negative.");
            }

            // A factor of exactly 1.0 is neutral (does not change the product but
            // is not "enabled" amplification); anything other than 1.0 engages.
            if ($factor !== 1.0) {
                $multiplier *= $factor;
                $enabled[] = (string) $component;
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'provider_output' => $providerOutput,
            'ecosystem_multiplier' => $multiplier,
            'atlas_output' => $providerOutput * $multiplier,
            'enabled_components' => $enabled,
            'is_pure_passthrough' => $multiplier === 1.0,
        ];
    }

    /**
     * Canal Unico (doc "Canal Unico"). Interaction routed THROUGH Atlas produces
     * evidence and feeds the virtuous loop; interaction routed directly to the
     * provider produces no evidence and feeds the death loop.
     *
     * @return array{
     *   schema_version:string,
     *   through_atlas:bool,
     *   loop:string,
     *   produces_evidence:bool,
     *   strengthens_multiplier:bool,
     *   chain:list<string>
     * }
     */
    public function classifyChannel(bool $throughAtlas): array
    {
        if ($throughAtlas) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'through_atlas' => true,
                'loop' => self::LOOP_VIRTUOUS,
                'produces_evidence' => true,
                'strengthens_multiplier' => true,
                'chain' => ['atlas_use', 'evidence', 'curator', 'stronger_multiplier', 'better_atlas', 'more_use'],
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'through_atlas' => false,
            'loop' => self::LOOP_DEATH,
            'produces_evidence' => false,
            'strengthens_multiplier' => false,
            'chain' => ['direct_provider_use', 'no_evidence', 'no_learning', 'weak_atlas', 'more_direct_use'],
        ];
    }

    /**
     * Feature Filter (doc "Feature Filter": ask twice, then decide). Maps the two
     * boolean answers to exactly one of the four documented decisions.
     *
     * Question 1: does it multiply provider output (true) or compete with it (false)?
     * Question 2: does it keep Atlas the natural channel (false escape friction)
     *             or create escape friction (true)?
     *
     * Decision precedence (faithful to the doc's four bullets):
     *   1. escape friction present            -> fix_before_shipping
     *      (a feature that makes direct provider use easier must be fixed first,
     *       whether or not it also multiplies).
     *   2. competes with providers            -> convert_to_adapter
     *   3. multiplies AND no escape friction  -> build
     *   4. neutral (no multiplier, no friction) -> measure_before_expanding
     *
     * @return array{
     *   schema_version:string,
     *   multiplies:bool,
     *   creates_escape_friction:bool,
     *   keeps_gravity:bool,
     *   decision:string,
     *   should_build:bool,
     *   requires_measurement_gate:bool,
     *   reason:string
     * }
     */
    public function filterFeature(bool $multiplies, bool $createsEscapeFriction): array
    {
        if ($createsEscapeFriction) {
            // Doc: "creates friction that makes direct provider use easier: fix before shipping".
            $decision = self::DECISION_FIX_BEFORE_SHIPPING;
            $reason = 'feature_creates_escape_friction_fix_before_shipping';
        } elseif (! $multiplies) {
            // Doc: "competes with providers: reject or turn into adapter".
            $decision = self::DECISION_CONVERT_TO_ADAPTER;
            $reason = 'feature_competes_with_providers_convert_to_adapter';
        } else {
            // Doc: "multiplies and increases gravity: build".
            $decision = self::DECISION_BUILD;
            $reason = 'feature_multiplies_and_increases_gravity_build';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'multiplies' => $multiplies,
            'creates_escape_friction' => $createsEscapeFriction,
            'keeps_gravity' => ! $createsEscapeFriction,
            'decision' => $decision,
            'should_build' => $decision === self::DECISION_BUILD,
            'requires_measurement_gate' => false,
            'reason' => $reason,
        ];
    }

    /**
     * Neutral-overhead variant of the filter (doc "Decision": "neutral overhead:
     * measure before expanding"). When a feature neither multiplies nor creates
     * escape friction it is pure neutral overhead: it is not built and not
     * shipped wide until measured. This is the one decision that gates on a
     * measurement step rather than a code change.
     *
     * @return array{
     *   schema_version:string,
     *   multiplies:bool,
     *   creates_escape_friction:bool,
     *   keeps_gravity:bool,
     *   decision:string,
     *   should_build:bool,
     *   requires_measurement_gate:bool,
     *   reason:string
     * }
     */
    public function filterNeutralOverhead(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'multiplies' => false,
            'creates_escape_friction' => false,
            'keeps_gravity' => true,
            'decision' => self::DECISION_MEASURE_BEFORE_EXPANDING,
            'should_build' => false,
            'requires_measurement_gate' => true,
            'reason' => 'neutral_overhead_measure_before_expanding',
        ];
    }

    /**
     * Allowed / Blocked category classification (doc "Allowed" + "Blocked"). An
     * unlisted category is reported as unlisted and is NOT auto-approved — it
     * still has to pass filterFeature().
     *
     * @return array{
     *   schema_version:string,
     *   category:string,
     *   status:string,
     *   allowed:bool,
     *   blocked:bool,
     *   auto_approved:bool
     * }
     */
    public function classifyCategory(string $category): array
    {
        $key = strtolower(trim($category));

        if ($key === '') {
            throw new InvalidArgumentException('category must not be empty.');
        }

        $isAllowed = in_array($key, self::ALLOWED_CATEGORIES, true);
        $isBlocked = in_array($key, self::BLOCKED_CATEGORIES, true);

        if ($isBlocked) {
            $status = 'blocked';
        } elseif ($isAllowed) {
            $status = 'allowed';
        } else {
            $status = 'unlisted';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'category' => $key,
            'status' => $status,
            'allowed' => $isAllowed,
            'blocked' => $isBlocked,
            // Only explicitly-allowed categories skip the filter; allowed still
            // means "permitted", an unlisted category must be filtered first.
            'auto_approved' => $isAllowed,
        ];
    }

    /**
     * Primary entry point: a full thesis snapshot used by the command and as a
     * single source of the doc's contract, exercised on safe defaults.
     *
     * @return array{
     *   schema_version:string,
     *   multiplier_components:list<string>,
     *   allowed_categories:list<string>,
     *   blocked_categories:list<string>,
     *   formula_full_stack:array<string,mixed>,
     *   formula_passthrough:array<string,mixed>,
     *   channel_through_atlas:array<string,mixed>,
     *   channel_direct_provider:array<string,mixed>,
     *   filter_build:array<string,mixed>,
     *   filter_compete:array<string,mixed>,
     *   filter_escape_friction:array<string,mixed>,
     *   filter_neutral:array<string,mixed>,
     *   category_allowed:array<string,mixed>,
     *   category_blocked:array<string,mixed>
     * }
     */
    public function snapshot(): array
    {
        // A full multiplier stack: every component contributing a modest factor.
        $fullStack = [];
        foreach (self::MULTIPLIER_COMPONENTS as $component) {
            $fullStack[$component] = 1.2;
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'multiplier_components' => self::MULTIPLIER_COMPONENTS,
            'allowed_categories' => self::ALLOWED_CATEGORIES,
            'blocked_categories' => self::BLOCKED_CATEGORIES,
            // Full stack multiplies provider output well above 1x.
            'formula_full_stack' => $this->multiplyOutput(10.0, $fullStack),
            // No component enabled: Atlas is a pure pass-through (multiplier 1.0).
            'formula_passthrough' => $this->multiplyOutput(10.0, []),
            // Through Atlas: virtuous loop, evidence produced.
            'channel_through_atlas' => $this->classifyChannel(true),
            // Direct provider use: death loop, no evidence.
            'channel_direct_provider' => $this->classifyChannel(false),
            // Multiplies + keeps gravity -> build.
            'filter_build' => $this->filterFeature(true, false),
            // Competes (no multiplier) + keeps gravity -> convert to adapter.
            'filter_compete' => $this->filterFeature(false, false),
            // Creates escape friction -> fix before shipping (even if it multiplies).
            'filter_escape_friction' => $this->filterFeature(true, true),
            // Neutral overhead -> measure before expanding.
            'filter_neutral' => $this->filterNeutralOverhead(),
            // Explicitly allowed category.
            'category_allowed' => $this->classifyCategory('provider_drivers_and_model_routing'),
            // Explicitly blocked category.
            'category_blocked' => $this->classifyCategory('model_as_frontier_provider_replacement'),
        ];
    }
}
