<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas Personal Longitudinal Intelligence Roadmap — runtime.
 *
 * Turns the documented personal-memory governance into deterministic, pure
 * decision logic. The doc defines (1) a Memory Classes table where every class
 * carries a privacy default, (2) the rule that sensitive raw data is never
 * persisted raw and personal memory is redacted before reaching providers, and
 * (3) a Curator role that may *propose* a fixed set of items but may never
 * auto-change calendar, health plan, identity documents or active curriculum
 * without policy and human review. This service makes those rules enforceable.
 *
 * Concrete contract enforced (from the doc):
 *
 *  - Memory Classes: the five documented classes with their exact default
 *    handling. `sensitive_raw` -> do_not_persist_raw; `health_signal` ->
 *    explicit_opt_in; `personal_values` -> human_reviewed; work/cognitive ->
 *    local_projection.
 *
 *  - Persistence gate: a `sensitive_raw` payload may NEVER be persisted raw —
 *    persistence is allowed only when the payload is redacted/derived. A
 *    `health_signal` requires an explicit opt-in. `personal_values` requires a
 *    human review before it becomes durable.
 *
 *  - Provider egress gate ("Do not send raw personal memory to providers
 *    without redaction"): any personal class is blocked from leaving the local
 *    boundary toward a provider unless redacted; `sensitive_raw` is blocked even
 *    when "redacted" is merely claimed — raw never leaves.
 *
 *  - Curator authority: the five allowed proposal kinds are advisory only. A
 *    Curator action that targets calendar, health_plan, identity_document or
 *    active_curriculum is FORCED to `propose` (human review) and can never be
 *    `auto_apply`, regardless of requested mode.
 *
 * Stateless and DB-free: every method is a pure function of its arguments.
 *
 * @see docs/engineering-knowledge-base/evolution/personal-longitudinal-roadmap.md
 */
final class AtlasPersonalLongitudinalRoadmapService
{
    public const SCHEMA_VERSION = 'atlas.personal.longitudinal_roadmap.v1';

    /** Documented default-handling tokens for the Memory Classes table. */
    public const HANDLING_LOCAL_PROJECTION = 'local_projection';
    public const HANDLING_EXPLICIT_OPT_IN = 'explicit_opt_in';
    public const HANDLING_HUMAN_REVIEWED = 'human_reviewed';
    public const HANDLING_DO_NOT_PERSIST_RAW = 'do_not_persist_raw';

    /** Curator action modes. */
    public const MODE_PROPOSE = 'propose';
    public const MODE_AUTO_APPLY = 'auto_apply';

    /**
     * The Memory Classes table, transcribed from the doc. Each entry carries the
     * documented examples and its default handling token.
     *
     * @var array<string,array{label:string,examples:list<string>,default_handling:string}>
     */
    private const MEMORY_CLASSES = [
        'work_pattern' => [
            'label' => 'Work pattern',
            'examples' => ['productive hours', 'recurring blockers'],
            'default_handling' => self::HANDLING_LOCAL_PROJECTION,
        ],
        'cognitive_pattern' => [
            'label' => 'Cognitive pattern',
            'examples' => ['learning friction', 'failure signatures'],
            'default_handling' => self::HANDLING_LOCAL_PROJECTION,
        ],
        'health_signal' => [
            'label' => 'Health signal',
            'examples' => ['sleep', 'NSDR', 'recovery'],
            'default_handling' => self::HANDLING_EXPLICIT_OPT_IN,
        ],
        'personal_values' => [
            'label' => 'Personal values',
            'examples' => ['goals', 'identity', 'boundaries'],
            'default_handling' => self::HANDLING_HUMAN_REVIEWED,
        ],
        'sensitive_raw' => [
            'label' => 'Sensitive raw data',
            'examples' => ['audio', 'private notes', 'biometric data'],
            'default_handling' => self::HANDLING_DO_NOT_PERSIST_RAW,
        ],
    ];

    /**
     * The five proposal kinds the Curator MAY raise (doc "Curator Role").
     *
     * @var list<string>
     */
    public const CURATOR_PROPOSAL_KINDS = [
        'repeated_life_pattern',
        'schedule_recovery_adjustment',
        'cognitive_development_gap',
        'knowledge_decay',
        'high_leverage_review_item',
    ];

    /**
     * Targets the Curator may NEVER auto-change "without policy and human
     * review" (doc "Curator Role"). Any action touching these is forced to
     * `propose`.
     *
     * @var list<string>
     */
    public const CURATOR_PROTECTED_TARGETS = [
        'calendar',
        'health_plan',
        'identity_document',
        'active_curriculum',
    ];

    /** @return list<string> the memory class ids in documented order. */
    public function memoryClassIds(): array
    {
        return array_keys(self::MEMORY_CLASSES);
    }

    /**
     * Return one Memory Class with its default handling. Unknown ids are
     * reported as unresolved rather than guessed.
     *
     * @return array<string,mixed>
     */
    public function memoryClass(string $classId): array
    {
        $id = $this->normalize($classId);
        $entry = self::MEMORY_CLASSES[$id] ?? null;

        if ($entry === null) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'class_id' => $id,
                'known' => false,
                'reason' => 'unknown_memory_class',
            ];
        }

        return array_merge(
            ['schema_version' => self::SCHEMA_VERSION, 'class_id' => $id, 'known' => true],
            $entry,
        );
    }

    /**
     * Persistence gate. Decide whether a payload of a given memory class may be
     * persisted, given whether it has been redacted/derived, whether the
     * operator gave an explicit opt-in, and whether a human review happened.
     *
     *  - sensitive_raw: persistence allowed ONLY when redacted (raw is refused).
     *  - health_signal: requires explicit opt-in.
     *  - personal_values: requires human review.
     *  - work/cognitive pattern: local projection allowed by default.
     *
     * @return array{
     *   schema_version:string,
     *   class_id:string,
     *   known:bool,
     *   default_handling:string,
     *   persist:bool,
     *   persist_as:string,
     *   reasons:list<string>
     * }
     */
    public function persistenceDecision(
        string $classId,
        bool $redacted = false,
        bool $explicitOptIn = false,
        bool $humanReviewed = false,
    ): array {
        $id = $this->normalize($classId);
        $entry = self::MEMORY_CLASSES[$id] ?? null;

        if ($entry === null) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'class_id' => $id,
                'known' => false,
                'default_handling' => 'unknown',
                'persist' => false,
                'persist_as' => 'none',
                'reasons' => ['unknown_memory_class'],
            ];
        }

        $handling = $entry['default_handling'];
        $reasons = [];
        $persist = true;
        $persistAs = 'projection';

        switch ($handling) {
            case self::HANDLING_DO_NOT_PERSIST_RAW:
                // Raw sensitive data is never persisted; only a redacted/derived
                // form may be stored.
                if (! $redacted) {
                    $persist = false;
                    $persistAs = 'none';
                    $reasons[] = 'sensitive_raw_must_not_persist_raw';
                } else {
                    $persistAs = 'redacted_derived';
                    $reasons[] = 'sensitive_raw_persisted_only_as_redacted_derived';
                }
                break;

            case self::HANDLING_EXPLICIT_OPT_IN:
                if (! $explicitOptIn) {
                    $persist = false;
                    $persistAs = 'none';
                    $reasons[] = 'health_signal_requires_explicit_opt_in';
                } else {
                    $reasons[] = 'health_signal_opt_in_granted';
                }
                break;

            case self::HANDLING_HUMAN_REVIEWED:
                if (! $humanReviewed) {
                    $persist = false;
                    $persistAs = 'pending_human_review';
                    $reasons[] = 'personal_values_requires_human_review';
                } else {
                    $reasons[] = 'personal_values_human_reviewed';
                }
                break;

            case self::HANDLING_LOCAL_PROJECTION:
            default:
                $reasons[] = 'local_projection_default';
                break;
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'class_id' => $id,
            'known' => true,
            'default_handling' => $handling,
            'persist' => $persist,
            'persist_as' => $persistAs,
            'reasons' => array_values($reasons),
        ];
    }

    /**
     * Provider-egress gate ("Do not send raw personal memory to providers
     * without redaction"). A personal class may leave the local boundary toward
     * an external provider only when redacted. sensitive_raw is refused even
     * when redaction is merely claimed — raw audio/biometric never leaves the
     * machine, so only a derived projection (not the raw payload) may egress.
     *
     * @return array{
     *   schema_version:string,
     *   class_id:string,
     *   allow_provider_egress:bool,
     *   reasons:list<string>
     * }
     */
    public function providerEgressDecision(
        string $classId,
        bool $redacted = false,
        bool $isRawPayload = false,
    ): array {
        $id = $this->normalize($classId);
        $entry = self::MEMORY_CLASSES[$id] ?? null;

        if ($entry === null) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'class_id' => $id,
                'allow_provider_egress' => false,
                'reasons' => ['unknown_memory_class'],
            ];
        }

        $reasons = [];
        $allow = true;

        // The raw sensitive payload itself never leaves toward a provider.
        if ($entry['default_handling'] === self::HANDLING_DO_NOT_PERSIST_RAW && $isRawPayload) {
            $allow = false;
            $reasons[] = 'raw_sensitive_payload_never_egresses_to_provider';
        }

        // All personal classes require redaction before provider egress.
        if (! $redacted) {
            $allow = false;
            $reasons[] = 'personal_memory_requires_redaction_before_provider';
        }

        if ($allow) {
            $reasons[] = 'redacted_projection_provider_safe';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'class_id' => $id,
            'allow_provider_egress' => $allow,
            'reasons' => array_values($reasons),
        ];
    }

    /**
     * Curator authority gate. The Curator may *propose* the five documented
     * kinds, but may never auto-change calendar, health plan, identity documents
     * or active curriculum "without policy and human review". Any action that
     * targets a protected surface is FORCED to `propose` (auto_apply is denied),
     * regardless of the requested mode.
     *
     * @return array{
     *   schema_version:string,
     *   proposal_kind:string,
     *   kind_allowed:bool,
     *   target:string,
     *   target_protected:bool,
     *   requested_mode:string,
     *   effective_mode:string,
     *   auto_apply_allowed:bool,
     *   requires_human_review:bool,
     *   reasons:list<string>
     * }
     */
    public function curatorAuthority(
        string $proposalKind,
        string $target = '',
        string $requestedMode = self::MODE_PROPOSE,
    ): array {
        $kind = $this->normalize($proposalKind);
        $tgt = $this->normalize($target);
        $requested = $this->normalizeMode($requestedMode);

        $kindAllowed = in_array($kind, self::CURATOR_PROPOSAL_KINDS, true);
        $targetProtected = in_array($tgt, self::CURATOR_PROTECTED_TARGETS, true);

        $reasons = [];

        if (! $kindAllowed) {
            $reasons[] = 'proposal_kind_not_in_curator_scope';
        }

        // Protected targets can NEVER be auto-applied by the Curator.
        $autoApplyAllowed = $requested === self::MODE_AUTO_APPLY && ! $targetProtected && $kindAllowed;
        $effectiveMode = $autoApplyAllowed ? self::MODE_AUTO_APPLY : self::MODE_PROPOSE;

        if ($targetProtected) {
            $reasons[] = 'protected_target_forced_to_propose_human_review';
        }
        if ($requested === self::MODE_AUTO_APPLY && $targetProtected) {
            $reasons[] = 'curator_may_not_auto_change_protected_surface';
        }
        if ($effectiveMode === self::MODE_PROPOSE && $reasons === []) {
            $reasons[] = 'curator_proposes_for_human_review';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'proposal_kind' => $kind,
            'kind_allowed' => $kindAllowed,
            'target' => $tgt,
            'target_protected' => $targetProtected,
            'requested_mode' => $requested,
            'effective_mode' => $effectiveMode,
            'auto_apply_allowed' => $autoApplyAllowed,
            'requires_human_review' => $effectiveMode === self::MODE_PROPOSE,
            'reasons' => array_values($reasons),
        ];
    }

    /**
     * Primary entry point: the full roadmap snapshot used by the command and as
     * a single source of the doc's contract. It proves the table invariants
     * (sensitive raw never persists raw; every protected target is non-auto).
     *
     * @return array{
     *   schema_version:string,
     *   memory_class_count:int,
     *   memory_classes:array<string,array<string,mixed>>,
     *   curator_proposal_kinds:list<string>,
     *   curator_protected_targets:list<string>,
     *   default_persistence:array<string,array<string,mixed>>,
     *   sensitive_raw_never_persists_raw:bool,
     *   all_protected_targets_non_auto:bool
     * }
     */
    public function roadmap(): array
    {
        $classes = [];
        $defaultPersistence = [];

        foreach ($this->memoryClassIds() as $classId) {
            $classes[$classId] = $this->memoryClass($classId);
            // Default posture: nothing redacted, no opt-in, no human review yet.
            $defaultPersistence[$classId] = $this->persistenceDecision($classId);
        }

        // Invariant 1: sensitive_raw with no redaction must refuse persistence.
        $sensitiveRawNeverRaw = $defaultPersistence['sensitive_raw']['persist'] === false;

        // Invariant 2: every protected target denies auto_apply even when asked.
        $allProtectedNonAuto = true;
        foreach (self::CURATOR_PROTECTED_TARGETS as $target) {
            $decision = $this->curatorAuthority(
                self::CURATOR_PROPOSAL_KINDS[0],
                $target,
                self::MODE_AUTO_APPLY,
            );
            if ($decision['auto_apply_allowed'] !== false) {
                $allProtectedNonAuto = false;
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'memory_class_count' => count($classes),
            'memory_classes' => $classes,
            'curator_proposal_kinds' => self::CURATOR_PROPOSAL_KINDS,
            'curator_protected_targets' => self::CURATOR_PROTECTED_TARGETS,
            'default_persistence' => $defaultPersistence,
            'sensitive_raw_never_persists_raw' => $sensitiveRawNeverRaw,
            'all_protected_targets_non_auto' => $allProtectedNonAuto,
        ];
    }

    private function normalize(string $value): string
    {
        return strtolower(trim($value));
    }

    private function normalizeMode(string $mode): string
    {
        $key = strtolower(trim($mode));

        return $key === self::MODE_AUTO_APPLY ? self::MODE_AUTO_APPLY : self::MODE_PROPOSE;
    }
}
