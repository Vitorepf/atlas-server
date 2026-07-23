<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas AI Cognitive Plane — Capabilities Core runtime.
 *
 * Turns the cognitive capabilities-core doc into deterministic, pure decision
 * logic. The doc is the exhaustive design map of the ~32 horizontal cognitive
 * capabilities that live in the Core (serving learning + research + writing +
 * self_improvement). Rather than restating the prose, this service enforces
 * exactly the decidable contracts the doc states:
 *
 *  - Evidence levels (doc frontmatter decision + table column "Evidence"): every
 *    capability declares one of consensus|emerging|contested|speculative per C15.
 *    classifyCapability() resolves a capability to its documented role, runtime
 *    and evidence level; isDefaultable() refuses to let `contested`/`speculative`
 *    capabilities ever become a default.
 *  - Anti-Duplication Core vs Domain (doc "Anti-Duplicacao Core vs Domain"): a NEW
 *    capability enters the Core iff it serves more than one surface OR more than
 *    one domain; otherwise it becomes a specialist_profile inside `learning`, a
 *    domain feature or a surface feature; a cardinal Atlas-unique capability is
 *    routed to multiplier-edge, never here. placeCapability() decides this.
 *  - Operational restrictions (doc "Restricoes operacionais especiais"): hard
 *    runtime guards — Multi-Provider Debate is NEVER default and only runs on
 *    heavy decisions with an audited selection_mode; Confidence Calibration runs
 *    only on a limited cadence and is forbidden in continuous flow; Identity
 *    Tracker is read-only and forbids affirmative push; Dual N-Back is opt-in and
 *    never default; TMR is future / hardware-gated. guardRestricted() blocks any
 *    invocation that violates the documented restriction for that capability.
 *  - Worked-Example process fading (doc Worked Example Engine row): the fade
 *    scheduler removes steps as the dreyfus_stage advances — full explicit
 *    step-by-step for a novice, a partial case for competent, a raw case for
 *    proficient and above. resolveProcessFading() maps a dreyfus_stage to its
 *    documented fade level.
 *  - SRS default (doc decision "SRS default e FSRS; SM-2 deprecado"):
 *    resolveSrsAlgorithm() always returns FSRS and rejects SM-2 as deprecated.
 *
 * Stateless and DB-free: every method is a pure function of its arguments.
 *
 * @see docs/engineering-knowledge-base/cognitive/capabilities-core.md
 */
final class AtlasCapabilitiesCoreService
{
    public const SCHEMA_VERSION = 'atlas.cognitive.capabilities_core.v1';

    /**
     * The four evidence levels the doc permits per capability (frontmatter
     * decision + table column "Evidence"), strongest first. Index 0 is the most
     * trustworthy; higher index == weaker / more provisional evidence.
     *
     * @var list<string>
     */
    public const EVIDENCE_LEVELS = [
        'consensus',
        'emerging',
        'contested',
        'speculative',
    ];

    /**
     * Evidence levels weak enough that the doc forbids ever making the capability
     * a default. `contested` (e.g. Dual N-Back) and `speculative`/`future`
     * (e.g. TMR) may exist as opt-in experiments but never as a default path.
     *
     * @var list<string>
     */
    public const NON_DEFAULTABLE_EVIDENCE = [
        'contested',
        'speculative',
    ];

    /**
     * Documented capability catalog (doc "Catalogo" table). Each entry carries the
     * capability's evidence level, its runtime home and whether the doc marks it as
     * an implemented operational read-model. Only the catalogued, decidable fields
     * are encoded — the prose role descriptions stay in the doc.
     *
     * `default_ok` is false for capabilities the doc explicitly restricts from ever
     * being a default (Multi-Provider Debate, Confidence Calibration, Identity
     * Tracker, Dual N-Back, TMR); true otherwise.
     *
     * @var array<string,array{evidence:string,runtime:string,implemented:bool,default_ok:bool}>
     */
    public const CAPABILITY_CATALOG = [
        'spaced_repetition_engine' => ['evidence' => 'consensus', 'runtime' => 'python_ai_data', 'implemented' => false, 'default_ok' => true],
        'active_recall_generator' => ['evidence' => 'consensus', 'runtime' => 'python_ai_data', 'implemented' => false, 'default_ok' => true],
        'generation_engine_pretest' => ['evidence' => 'consensus', 'runtime' => 'provider_laravel', 'implemented' => false, 'default_ok' => true],
        'feynman_validator' => ['evidence' => 'consensus', 'runtime' => 'laravel_provider', 'implemented' => false, 'default_ok' => true],
        'knowledge_graph_builder' => ['evidence' => 'consensus', 'runtime' => 'python_ai_data', 'implemented' => false, 'default_ok' => true],
        'curriculum_engine' => ['evidence' => 'consensus', 'runtime' => 'laravel_kernel', 'implemented' => false, 'default_ok' => true],
        'pareto_discovery_engine' => ['evidence' => 'emerging', 'runtime' => 'laravel_python_research', 'implemented' => false, 'default_ok' => true],
        'predictive_curriculum_advisor' => ['evidence' => 'emerging', 'runtime' => 'laravel_python', 'implemented' => false, 'default_ok' => true],
        'interleaving_scheduler' => ['evidence' => 'consensus', 'runtime' => 'laravel', 'implemented' => false, 'default_ok' => true],
        'micro_skill_isolator' => ['evidence' => 'consensus', 'runtime' => 'laravel_provider', 'implemented' => false, 'default_ok' => true],
        'multi_perspective_case_generator' => ['evidence' => 'consensus', 'runtime' => 'provider_harness', 'implemented' => false, 'default_ok' => true],
        'deep_transfer_probe' => ['evidence' => 'consensus', 'runtime' => 'provider_validator', 'implemented' => false, 'default_ok' => true],
        'cognitive_load_classifier' => ['evidence' => 'consensus', 'runtime' => 'go_edge_laravel', 'implemented' => false, 'default_ok' => true],
        'cognitive_load_monitor' => ['evidence' => 'consensus', 'runtime' => 'go_edge_laravel', 'implemented' => false, 'default_ok' => true],
        'flow_trigger_engine' => ['evidence' => 'consensus', 'runtime' => 'laravel', 'implemented' => false, 'default_ok' => true],
        'dmn_oscillator' => ['evidence' => 'consensus', 'runtime' => 'laravel_scheduler', 'implemented' => false, 'default_ok' => true],
        'nsdr_engine' => ['evidence' => 'consensus', 'runtime' => 'laravel_app_voice', 'implemented' => false, 'default_ok' => true],
        'hemingway_checkpoint' => ['evidence' => 'emerging', 'runtime' => 'laravel_study_session', 'implemented' => false, 'default_ok' => true],
        'incremental_reading_engine' => ['evidence' => 'emerging', 'runtime' => 'python_ai_data', 'implemented' => false, 'default_ok' => true],
        'perceptual_drill_engine' => ['evidence' => 'consensus', 'runtime' => 'provider_python', 'implemented' => false, 'default_ok' => true],
        'adversarial_validator_red_team' => ['evidence' => 'consensus', 'runtime' => 'provider_laravel', 'implemented' => false, 'default_ok' => true],
        'first_principles_probe' => ['evidence' => 'consensus', 'runtime' => 'provider', 'implemented' => false, 'default_ok' => true],
        'multi_provider_debate_engine' => ['evidence' => 'emerging', 'runtime' => 'laravel_provider_registry', 'implemented' => false, 'default_ok' => false],
        'confidence_calibration_drill' => ['evidence' => 'consensus', 'runtime' => 'laravel_provider', 'implemented' => false, 'default_ok' => false],
        'identity_tracker' => ['evidence' => 'emerging', 'runtime' => 'laravel_projection', 'implemented' => false, 'default_ok' => false],
        'dual_n_back_drill' => ['evidence' => 'contested', 'runtime' => 'python', 'implemented' => false, 'default_ok' => false],
        'game_case_engine' => ['evidence' => 'consensus', 'runtime' => 'harness', 'implemented' => false, 'default_ok' => true],
        'cognitive_forge_harness' => ['evidence' => 'consensus', 'runtime' => 'harness', 'implemented' => false, 'default_ok' => true],
        'tmr' => ['evidence' => 'speculative', 'runtime' => 'future_hardware', 'implemented' => false, 'default_ok' => false],
        'worked_example_engine' => ['evidence' => 'consensus', 'runtime' => 'laravel', 'implemented' => true, 'default_ok' => true],
        'process_pattern_catalog' => ['evidence' => 'consensus', 'runtime' => 'laravel', 'implemented' => true, 'default_ok' => true],
        'failure_signature_classifier' => ['evidence' => 'emerging', 'runtime' => 'laravel', 'implemented' => true, 'default_ok' => true],
        'self_explanation_generator' => ['evidence' => 'consensus', 'runtime' => 'provider_laravel', 'implemented' => false, 'default_ok' => true],
        'self_regulated_learning_orchestrator' => ['evidence' => 'consensus', 'runtime' => 'laravel', 'implemented' => true, 'default_ok' => true],
        'multimedia_composer' => ['evidence' => 'consensus', 'runtime' => 'laravel_provider', 'implemented' => false, 'default_ok' => true],
    ];

    /**
     * The five capabilities the doc carries an explicit operational restriction
     * for (doc "Restricoes operacionais especiais"). Each restriction is a hard
     * runtime guard, not a hint.
     *
     *  - never_default        : may never be the default path.
     *  - heavy_decision_only  : only runs on heavy/audited decisions.
     *  - cadence_limited      : only runs on the documented limited cadence.
     *  - forbidden_in_flow    : forbidden during continuous flow.
     *  - read_only            : observational only; no writes / mutations.
     *  - no_affirmative_push  : may not emit affirmative push / motivational copy.
     *  - hardware_gated       : depends on external hardware; not runnable now.
     *  - future               : product is future / not runtime.
     *
     * @var array<string,list<string>>
     */
    public const RESTRICTIONS = [
        'multi_provider_debate_engine' => ['never_default', 'heavy_decision_only'],
        'confidence_calibration_drill' => ['cadence_limited', 'forbidden_in_flow'],
        'identity_tracker' => ['read_only', 'no_affirmative_push'],
        'dual_n_back_drill' => ['never_default'],
        'tmr' => ['future', 'hardware_gated'],
    ];

    /**
     * Allowed cadence contexts for the Confidence Calibration drill (doc table:
     * "somente em mastery_review (mensal), transfer_test (quinzenal) ou
     * first_principles_decompose"). Any other context is rejected.
     *
     * @var list<string>
     */
    public const CONFIDENCE_CALIBRATION_CONTEXTS = [
        'mastery_review',
        'transfer_test',
        'first_principles_decompose',
    ];

    /**
     * Worked-Example process-fading ladder (doc Worked Example Engine row:
     * "explicit step-by-step pra novato, caso parcial pra competente, caso cru pra
     * proficiente+"). Maps a Dreyfus stage to the documented fade level.
     *
     * @var array<string,string>
     */
    public const FADE_BY_STAGE = [
        'novice' => 'full_step_by_step',
        'advanced_beginner' => 'full_step_by_step',
        'competent' => 'partial_case',
        'proficient' => 'raw_case',
        'expert' => 'raw_case',
        'master' => 'raw_case',
    ];

    /**
     * Classify a catalogued capability: its evidence level, runtime home, whether
     * the doc marks it implemented, and whether it may ever be a default. Unknown
     * capabilities are reported, never guessed.
     *
     * @return array{
     *   schema_version:string,
     *   capability:string,
     *   known:bool,
     *   evidence:?string,
     *   runtime:?string,
     *   implemented:bool,
     *   default_ok:bool,
     *   reason:string
     * }
     */
    public function classifyCapability(string $capability): array
    {
        $key = $this->normalizeCapability($capability);

        if (! array_key_exists($key, self::CAPABILITY_CATALOG)) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'capability' => $key,
                'known' => false,
                'evidence' => null,
                'runtime' => null,
                'implemented' => false,
                'default_ok' => false,
                'reason' => 'capability_not_in_catalog',
            ];
        }

        $entry = self::CAPABILITY_CATALOG[$key];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'capability' => $key,
            'known' => true,
            'evidence' => $entry['evidence'],
            'runtime' => $entry['runtime'],
            'implemented' => $entry['implemented'],
            'default_ok' => $entry['default_ok'],
            'reason' => 'capability_resolved_from_catalog',
        ];
    }

    /**
     * Defaultability rule (doc decision "C15 evidence_level" + restrictions table).
     * A capability may become a default ONLY when its evidence level is strong
     * enough (not contested/speculative) AND it carries no explicit never_default
     * restriction. Unknown capabilities are never defaultable.
     *
     * @return array{
     *   schema_version:string,
     *   capability:string,
     *   evidence:?string,
     *   default_ok:bool,
     *   reason:string
     * }
     */
    public function isDefaultable(string $capability): array
    {
        $key = $this->normalizeCapability($capability);

        if (! array_key_exists($key, self::CAPABILITY_CATALOG)) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'capability' => $key,
                'evidence' => null,
                'default_ok' => false,
                'reason' => 'unknown_capability_never_default',
            ];
        }

        $entry = self::CAPABILITY_CATALOG[$key];
        $restrictions = self::RESTRICTIONS[$key] ?? [];

        if (in_array($entry['evidence'], self::NON_DEFAULTABLE_EVIDENCE, true)) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'capability' => $key,
                'evidence' => $entry['evidence'],
                'default_ok' => false,
                'reason' => 'evidence_too_weak_for_default',
            ];
        }

        if (in_array('never_default', $restrictions, true)) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'capability' => $key,
                'evidence' => $entry['evidence'],
                'default_ok' => false,
                'reason' => 'restricted_never_default',
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'capability' => $key,
            'evidence' => $entry['evidence'],
            'default_ok' => true,
            'reason' => 'evidence_strong_and_unrestricted',
        ];
    }

    /**
     * Anti-Duplication Core vs Domain placement (doc "Anti-Duplicacao Core vs
     * Domain"). A new capability enters the Core iff it serves >1 surface OR >1
     * domain. A cardinal Atlas-unique capability is routed to multiplier-edge.
     * Otherwise it becomes a specialist_profile inside `learning` (the doc's first
     * fallback), a domain feature or a surface feature.
     *
     * @return array{
     *   schema_version:string,
     *   capability:string,
     *   placement:string,
     *   serves_multiple_surfaces:bool,
     *   serves_multiple_domains:bool,
     *   cardinal:bool,
     *   reason:string
     * }
     */
    public function placeCapability(
        string $capability,
        int $surfaceCount,
        int $domainCount,
        bool $cardinal = false
    ): array {
        $name = trim($capability);
        $surfaces = max(0, $surfaceCount);
        $domains = max(0, $domainCount);
        $multiSurface = $surfaces > 1;
        $multiDomain = $domains > 1;

        // Cardinal Atlas-unique capability goes to multiplier-edge, not Core.
        if ($cardinal) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'capability' => $name,
                'placement' => 'multiplier_edge',
                'serves_multiple_surfaces' => $multiSurface,
                'serves_multiple_domains' => $multiDomain,
                'cardinal' => true,
                'reason' => 'cardinal_atlas_unique_routes_to_multiplier_edge',
            ];
        }

        if ($multiSurface || $multiDomain) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'capability' => $name,
                'placement' => 'core',
                'serves_multiple_surfaces' => $multiSurface,
                'serves_multiple_domains' => $multiDomain,
                'cardinal' => false,
                'reason' => 'serves_more_than_one_surface_or_domain',
            ];
        }

        // Single-scope -> the doc's first fallback is a specialist_profile in learning.
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'capability' => $name,
            'placement' => 'learning_specialist_profile',
            'serves_multiple_surfaces' => false,
            'serves_multiple_domains' => false,
            'cardinal' => false,
            'reason' => 'single_surface_and_domain_not_core',
        ];
    }

    /**
     * Operational-restriction guard (doc "Restricoes operacionais especiais").
     * Decides whether a restricted capability may run under the given invocation
     * context. Capabilities with no restriction are always allowed. The context
     * keys honored:
     *
     *  - is_default (bool)        : is this being invoked as the default path?
     *  - heavy_decision (bool)    : is this a heavy/audited decision?
     *  - audited_selection (bool) : is selection_mode audited (multi-provider)?
     *  - continuous_flow (bool)   : is the operator currently in continuous flow?
     *  - cadence_context (string) : the cadence context (for calibration drill).
     *  - affirmative_push (bool)  : would this emit affirmative push / ego copy?
     *  - mutates (bool)           : would this write / mutate (vs read-only)?
     *  - hardware_present (bool)  : is the required hardware present?
     *
     * @param  array<string,mixed>  $context
     * @return array{
     *   schema_version:string,
     *   capability:string,
     *   restricted:bool,
     *   restrictions:list<string>,
     *   allowed:bool,
     *   violations:list<string>,
     *   reason:string
     * }
     */
    public function guardRestricted(string $capability, array $context = []): array
    {
        $key = $this->normalizeCapability($capability);
        $restrictions = self::RESTRICTIONS[$key] ?? [];

        if ($restrictions === []) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'capability' => $key,
                'restricted' => false,
                'restrictions' => [],
                'allowed' => true,
                'violations' => [],
                'reason' => 'no_operational_restriction',
            ];
        }

        $violations = [];

        if (in_array('never_default', $restrictions, true) && ($context['is_default'] ?? false) === true) {
            $violations[] = 'invoked_as_default';
        }

        if (in_array('heavy_decision_only', $restrictions, true)) {
            $heavy = ($context['heavy_decision'] ?? false) === true;
            $audited = ($context['audited_selection'] ?? false) === true;
            if (! $heavy || ! $audited) {
                $violations[] = 'not_heavy_audited_decision';
            }
        }

        if (in_array('forbidden_in_flow', $restrictions, true) && ($context['continuous_flow'] ?? false) === true) {
            $violations[] = 'invoked_in_continuous_flow';
        }

        if (in_array('cadence_limited', $restrictions, true)) {
            $ctx = strtolower(trim((string) ($context['cadence_context'] ?? '')));
            if (! in_array($ctx, self::CONFIDENCE_CALIBRATION_CONTEXTS, true)) {
                $violations[] = 'cadence_context_not_allowed';
            }
        }

        if (in_array('no_affirmative_push', $restrictions, true) && ($context['affirmative_push'] ?? false) === true) {
            $violations[] = 'affirmative_push_forbidden';
        }

        if (in_array('read_only', $restrictions, true) && ($context['mutates'] ?? false) === true) {
            $violations[] = 'mutation_forbidden_read_only';
        }

        if (in_array('hardware_gated', $restrictions, true) && ($context['hardware_present'] ?? false) !== true) {
            $violations[] = 'required_hardware_absent';
        }

        if (in_array('future', $restrictions, true)) {
            $violations[] = 'capability_is_future_not_runtime';
        }

        $allowed = $violations === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'capability' => $key,
            'restricted' => true,
            'restrictions' => array_values($restrictions),
            'allowed' => $allowed,
            'violations' => $violations,
            'reason' => $allowed
                ? 'restriction_satisfied'
                : 'operational_restriction_violated',
        ];
    }

    /**
     * Worked-Example process-fading resolution (doc Worked Example Engine row).
     * Maps a Dreyfus stage to the documented fade level: full step-by-step for a
     * novice, a partial case for competent, a raw case for proficient and above.
     * Unknown stages fall back to the safest (most explicit) fade.
     *
     * @return array{
     *   schema_version:string,
     *   dreyfus_stage:string,
     *   fade_level:string,
     *   steps_removed:bool,
     *   reason:string
     * }
     */
    public function resolveProcessFading(string $dreyfusStage): array
    {
        $stage = strtolower(trim($dreyfusStage));
        $fade = self::FADE_BY_STAGE[$stage] ?? 'full_step_by_step';
        $known = array_key_exists($stage, self::FADE_BY_STAGE);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'dreyfus_stage' => $stage,
            'fade_level' => $fade,
            'steps_removed' => $fade !== 'full_step_by_step',
            'reason' => $known
                ? 'fade_resolved_from_dreyfus_stage'
                : 'unknown_stage_defaults_to_full_explicit',
        ];
    }

    /**
     * SRS default (doc decision "SRS default e FSRS (consensus); SM-2 deprecado").
     * Always resolves to FSRS; an explicit SM-2 request is rejected as deprecated.
     *
     * @return array{
     *   schema_version:string,
     *   requested:?string,
     *   algorithm:string,
     *   deprecated_rejected:bool,
     *   reason:string
     * }
     */
    public function resolveSrsAlgorithm(?string $requested = null): array
    {
        $req = $requested === null ? null : strtolower(trim($requested));
        $smTwoRequested = $req !== null && (str_contains($req, 'sm-2') || str_contains($req, 'sm2') || str_contains($req, 'supermemo'));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'requested' => $req,
            'algorithm' => 'fsrs',
            'deprecated_rejected' => $smTwoRequested,
            'reason' => $smTwoRequested
                ? 'sm2_deprecated_forced_fsrs'
                : 'fsrs_is_consensus_default',
        ];
    }

    /**
     * Read-model snapshot of the capabilities-core contract for the CLI.
     *
     * @return array{
     *   schema_version:string,
     *   evidence_levels:list<string>,
     *   capability_count:int,
     *   implemented_capabilities:list<string>,
     *   non_default_capabilities:list<string>,
     *   restricted_capabilities:list<string>
     * }
     */
    public function snapshot(): array
    {
        $implemented = [];
        $nonDefault = [];

        foreach (self::CAPABILITY_CATALOG as $name => $entry) {
            if ($entry['implemented']) {
                $implemented[] = $name;
            }
            if (! $entry['default_ok']) {
                $nonDefault[] = $name;
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'evidence_levels' => self::EVIDENCE_LEVELS,
            'capability_count' => count(self::CAPABILITY_CATALOG),
            'implemented_capabilities' => $implemented,
            'non_default_capabilities' => $nonDefault,
            'restricted_capabilities' => array_keys(self::RESTRICTIONS),
        ];
    }

    /**
     * Normalize a free-form capability name to a catalog key (lowercase, non-alnum
     * collapsed to underscores, "engine"/"drill" suffixes tolerated via aliases).
     */
    private function normalizeCapability(string $capability): string
    {
        $s = strtolower(trim($capability));
        $s = preg_replace('/[^a-z0-9]+/', '_', $s) ?? $s;
        $s = trim($s, '_');

        return match (true) {
            str_contains($s, 'multi_provider_debate') => 'multi_provider_debate_engine',
            str_contains($s, 'confidence_calibration') => 'confidence_calibration_drill',
            str_contains($s, 'identity_tracker') => 'identity_tracker',
            str_contains($s, 'dual_n_back') => 'dual_n_back_drill',
            $s === 'tmr' || str_contains($s, 'targeted_memory_reactivation') => 'tmr',
            str_contains($s, 'worked_example') => 'worked_example_engine',
            str_contains($s, 'first_principles') => 'first_principles_probe',
            str_contains($s, 'adversarial') || str_contains($s, 'red_team') => 'adversarial_validator_red_team',
            default => $s,
        };
    }
}
