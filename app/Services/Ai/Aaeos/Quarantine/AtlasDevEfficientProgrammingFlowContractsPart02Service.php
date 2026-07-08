<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas Dev Efficient Programming Flow Contracts v1 · Parte 2 — invariant decider.
 *
 * Pure, deterministic runtime for the two intake contracts the doc declares in
 * sections 4.1 and 4.2: the Atlas Dev OperationEnvelope
 * (`atlas.dev.operation_envelope.v1`) and the CompactSDD
 * (`atlas.dev.compact_sdd.v1`). The corresponding DTO holders already exist
 * (App\Services\Ai\Programming\AtlasDev\Schemas\{AtlasDevOperationEnvelope,
 * CompactSdd}) but they are *data carriers* — they can be constructed with field
 * combinations the doc forbids. This service is the missing piece: it enforces
 * the numbered Invariants verbatim and returns the exact violations.
 *
 * Anti-duplication boundary (doc 4.1, lines 117-123): this is the Atlas Dev
 * envelope `atlas.dev.operation_envelope.v1`, NOT the Kernel envelope
 * `atlas.envelope.v1` (which {@see AtlasOperationEnvelopeService} /
 * {@see AtlasKernelContractsService} govern). The two contracts are distinct;
 * this service never treats one as the other.
 *
 * Rules enforced (the doc's "#### Invariants" blocks):
 *
 *  OperationEnvelope (4.1):
 *   - I1  run_id is a UUID v7 (version nibble == 7).
 *   - I2  workspace is an absolute path.
 *   - I5  intent_clarity_level = blocking cannot advance (blocked).
 *   - I6  write_allowed=true requires permission_mode in (write,danger); mode
 *         danger additionally requires operator_explicit=true.
 *   - I7  surface_id=atlas_desktop_ai requires product_surface=atlas_ai_desktop_mac.
 *   - I9  flow_id is always atlas_dev.
 *   - I11 flow_origin=atlas_ai_router with a routed slash command requires
 *         command_intent != null.
 *   - I12 command_intent (when set) must be in the canonical set.
 *
 *  CompactSDD (4.2):
 *   - I1  risk_level R4|R5 forces mode = escalate_preview (never patch).
 *   - I2  task_kind = question forces mode = read_only.
 *   - I3  task_kind = risky forces risk_level >= R4.
 *   - I5  verification_profile is required when task_kind in (patch,repair,frontend).
 *   - I7  compact_sdd_hash identity excludes mini_spec_hash + task_contract_hash
 *         (monotonic-fill fields), so hashExcludedFields() pins that set.
 *
 * The service NEVER executes, routes, builds a plan, calls a provider or touches
 * a database. It only decides whether a candidate envelope / CompactSDD payload
 * is invariant-legal and what blocked it. Callers enforce.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1-part-02.md
 */
final class AtlasDevEfficientProgrammingFlowContractsPart02Service
{
    /** Stable schema id for the verdict this decider emits. */
    public const SCHEMA = 'atlas.dev.flow_contracts_part02_gate.v1';

    /** Contract ids governed here (doc 4.1 / 4.2 schema_version). */
    public const ENVELOPE_CONTRACT = 'atlas.dev.operation_envelope.v1';
    public const COMPACT_SDD_CONTRACT = 'atlas.dev.compact_sdd.v1';

    /** Doc 4.1 invariant 9: flow_id is always exactly this value. */
    public const ENVELOPE_FLOW_ID = 'atlas_dev';

    /** Doc 4.1: flow_origin enumeration. */
    public const FLOW_ORIGIN_ROUTER = 'atlas_ai_router';
    public const FLOW_ORIGIN_DIRECT = 'direct';

    /**
     * Doc 4.1 invariant 12: the canonical command_intent set. Anything outside
     * this set rejects the envelope (or routes to another flow).
     *
     * @var list<string>
     */
    public const COMMAND_INTENT_SET = [
        'fix', 'explain', 'research', 'test', 'refactor',
        'review', 'debug', 'plan', 'conversation',
    ];

    /**
     * Doc 4.1 invariant 7: surface_id -> required product_surface coupling.
     *
     * @var array<string,string>
     */
    private const SURFACE_PRODUCT_SURFACE = [
        'atlas_desktop_ai' => 'atlas_ai_desktop_mac',
    ];

    /** Doc 4.1 invariant 6: permission modes under which a write may be allowed. */
    private const WRITE_PERMISSION_MODES = ['write', 'danger'];

    /** Doc 4.2: CompactSDD task_kind enumeration. */
    public const TASK_KINDS = ['question', 'patch', 'repair', 'review', 'frontend', 'risky'];

    /** Doc 4.2: risk_level enumeration (ordered, so R4 == index 4). */
    public const RISK_LEVELS = ['R0', 'R1', 'R2', 'R3', 'R4', 'R5'];

    /** Doc 4.2: mode enumeration. */
    public const MODES = ['read_only', 'plan_only', 'patch', 'repair', 'escalate_preview'];

    /** Doc 4.2 invariant 1: at/above this risk index, escalate_preview is forced. */
    private const ESCALATE_RISK_INDEX = 4; // R4

    /** Doc 4.2 invariant 1: the only legal mode for R4|R5. */
    public const ESCALATE_MODE = 'escalate_preview';

    /** Doc 4.2 invariant 2: the only legal mode for task_kind=question. */
    public const QUESTION_MODE = 'read_only';

    /** Doc 4.2 invariant 5: task_kinds that require a verification_profile. */
    private const PROFILE_REQUIRED_KINDS = ['patch', 'repair', 'frontend'];

    /**
     * Doc 4.2 invariant 7: fields excluded from compact_sdd_hash identity. They
     * are filled monotonically *after* classification, so the classification
     * hash must not depend on them.
     *
     * @var list<string>
     */
    private const HASH_EXCLUDED_FIELDS = ['compact_sdd_hash', 'mini_spec_hash', 'task_contract_hash'];

    // ---------------------------------------------------------------------
    // 4.1 OperationEnvelope
    // ---------------------------------------------------------------------

    /**
     * Validate a candidate Atlas Dev OperationEnvelope against doc 4.1 invariants.
     *
     * @param  array<string,mixed>  $envelope
     * @return array{
     *     schema:string,
     *     contract:string,
     *     valid:bool,
     *     blocked:bool,
     *     can_advance:bool,
     *     violations:list<array{invariant:string,rule:string}>,
     *     violated_invariants:list<string>
     * }
     */
    public function validateEnvelope(array $envelope): array
    {
        $violations = [];

        // I1: run_id is a UUID v7.
        if (! $this->isUuidV7((string) ($envelope['run_id'] ?? ''))) {
            $violations[] = ['invariant' => 'OE-1', 'rule' => 'run_id_must_be_uuid_v7'];
        }

        // I2: workspace is an absolute path.
        if (! $this->isAbsolutePath((string) ($envelope['workspace'] ?? ''))) {
            $violations[] = ['invariant' => 'OE-2', 'rule' => 'workspace_must_be_absolute_path'];
        }

        // I5: intent_clarity_level = blocking cannot advance.
        $clarity = (string) ($envelope['intent_clarity_level'] ?? '');
        if ($clarity === 'blocking') {
            $violations[] = ['invariant' => 'OE-5', 'rule' => 'blocking_clarity_cannot_advance'];
        }

        // I6: write gating.
        $violations = [...$violations, ...$this->envelopeWriteViolations($envelope)];

        // I7: surface_id -> product_surface coupling.
        $surfaceId = (string) ($envelope['surface_id'] ?? '');
        $productSurface = (string) data_get($envelope, 'surface_context.product_surface', '');
        if (isset(self::SURFACE_PRODUCT_SURFACE[$surfaceId])
            && $productSurface !== self::SURFACE_PRODUCT_SURFACE[$surfaceId]
        ) {
            $violations[] = ['invariant' => 'OE-7', 'rule' => 'surface_id_requires_matching_product_surface'];
        }

        // I9: flow_id is always atlas_dev.
        if ((string) ($envelope['flow_id'] ?? self::ENVELOPE_FLOW_ID) !== self::ENVELOPE_FLOW_ID) {
            $violations[] = ['invariant' => 'OE-9', 'rule' => 'flow_id_must_be_atlas_dev'];
        }

        // I11: router origin + routed slash command requires command_intent.
        $flowOrigin = (string) ($envelope['flow_origin'] ?? self::FLOW_ORIGIN_DIRECT);
        $commandIntent = $envelope['command_intent'] ?? null;
        $routedSlashCommand = (bool) ($envelope['routed_slash_command'] ?? false);
        if ($flowOrigin === self::FLOW_ORIGIN_ROUTER && $routedSlashCommand
            && ($commandIntent === null || $commandIntent === '')
        ) {
            $violations[] = ['invariant' => 'OE-11', 'rule' => 'router_routed_command_requires_command_intent'];
        }

        // I12: command_intent (when set) is in the canonical set.
        if (is_string($commandIntent) && $commandIntent !== ''
            && ! in_array($commandIntent, self::COMMAND_INTENT_SET, true)
        ) {
            $violations[] = ['invariant' => 'OE-12', 'rule' => 'command_intent_outside_canonical_set'];
        }

        $valid = $violations === [];
        // A blocking clarity level is the doc's explicit "cannot advance" stop;
        // any invariant failure also bars advance.
        $blocked = ! $valid;

        return [
            'schema' => self::SCHEMA,
            'contract' => self::ENVELOPE_CONTRACT,
            'valid' => $valid,
            'blocked' => $blocked,
            'can_advance' => $valid,
            'violations' => array_values($violations),
            'violated_invariants' => array_values(array_map(
                static fn (array $v): string => $v['invariant'],
                $violations,
            )),
        ];
    }

    /**
     * Doc 4.1 invariant 6: write_allowed=true requires permission_mode in
     * (write,danger); permission_mode=danger additionally requires
     * operator_explicit=true.
     *
     * @param  array<string,mixed>  $envelope
     * @return list<array{invariant:string,rule:string}>
     */
    private function envelopeWriteViolations(array $envelope): array
    {
        $writeAllowed = (bool) data_get($envelope, 'preflight.write_allowed', false);
        if (! $writeAllowed) {
            return [];
        }

        $permissionMode = (string) data_get($envelope, 'preflight.permission_mode', '');
        $operatorExplicit = (bool) data_get($envelope, 'preflight.operator_explicit', false);

        $violations = [];
        if (! in_array($permissionMode, self::WRITE_PERMISSION_MODES, true)) {
            $violations[] = ['invariant' => 'OE-6', 'rule' => 'write_allowed_requires_write_or_danger_permission'];
        }
        if ($permissionMode === 'danger' && ! $operatorExplicit) {
            $violations[] = ['invariant' => 'OE-6', 'rule' => 'danger_write_requires_operator_explicit'];
        }

        return $violations;
    }

    // ---------------------------------------------------------------------
    // 4.2 CompactSDD
    // ---------------------------------------------------------------------

    /**
     * Validate a candidate CompactSDD against doc 4.2 invariants.
     *
     * @param  array<string,mixed>  $sdd
     * @return array{
     *     schema:string,
     *     contract:string,
     *     valid:bool,
     *     blocked:bool,
     *     violations:list<array{invariant:string,rule:string}>,
     *     violated_invariants:list<string>,
     *     forced_mode:?string
     * }
     */
    public function validateCompactSdd(array $sdd): array
    {
        $violations = [];

        $taskKind = (string) ($sdd['task_kind'] ?? '');
        $riskLevel = (string) ($sdd['risk_level'] ?? '');
        $mode = (string) ($sdd['mode'] ?? '');
        $verificationProfile = $sdd['verification_profile'] ?? null;

        $forcedMode = $this->forcedMode($taskKind, $riskLevel);

        // I1: R4|R5 forces escalate_preview (never patch direct).
        if ($this->riskIndex($riskLevel) >= self::ESCALATE_RISK_INDEX && $mode !== self::ESCALATE_MODE) {
            $violations[] = ['invariant' => 'SDD-1', 'rule' => 'high_risk_forces_escalate_preview'];
        }

        // I2: task_kind = question forces read_only.
        if ($taskKind === 'question' && $mode !== self::QUESTION_MODE) {
            $violations[] = ['invariant' => 'SDD-2', 'rule' => 'question_forces_read_only'];
        }

        // I3: task_kind = risky forces risk_level >= R4.
        if ($taskKind === 'risky' && $this->riskIndex($riskLevel) < self::ESCALATE_RISK_INDEX) {
            $violations[] = ['invariant' => 'SDD-3', 'rule' => 'risky_task_forces_risk_R4_or_higher'];
        }

        // I5: verification_profile required for patch|repair|frontend.
        if (in_array($taskKind, self::PROFILE_REQUIRED_KINDS, true)
            && ($verificationProfile === null || $verificationProfile === '')
        ) {
            $violations[] = ['invariant' => 'SDD-5', 'rule' => 'task_kind_requires_verification_profile'];
        }

        $valid = $violations === [];

        return [
            'schema' => self::SCHEMA,
            'contract' => self::COMPACT_SDD_CONTRACT,
            'valid' => $valid,
            'blocked' => ! $valid,
            'violations' => array_values($violations),
            'violated_invariants' => array_values(array_map(
                static fn (array $v): string => $v['invariant'],
                $violations,
            )),
            'forced_mode' => $forcedMode,
        ];
    }

    /**
     * The mode the doc *forces* given (task_kind, risk_level), independent of
     * the candidate's declared mode. High risk (4.2 I1) dominates the question
     * read-only rule (4.2 I2); otherwise null means "no forced mode".
     */
    public function forcedMode(string $taskKind, string $riskLevel): ?string
    {
        if ($this->riskIndex($riskLevel) >= self::ESCALATE_RISK_INDEX) {
            return self::ESCALATE_MODE;
        }
        if ($taskKind === 'question') {
            return self::QUESTION_MODE;
        }

        return null;
    }

    /**
     * Doc 4.2 invariant 7: the fields excluded from compact_sdd_hash identity.
     *
     * @return list<string>
     */
    public function hashExcludedFields(): array
    {
        return self::HASH_EXCLUDED_FIELDS;
    }

    /**
     * Stable contract manifest for callers/tests: the enumerations and forcing
     * rules this decider pins from doc 4.1 and 4.2.
     *
     * @return array{
     *     schema:string,
     *     envelope_contract:string,
     *     compact_sdd_contract:string,
     *     envelope_flow_id:string,
     *     command_intent_set:list<string>,
     *     task_kinds:list<string>,
     *     risk_levels:list<string>,
     *     modes:list<string>,
     *     escalate_mode:string,
     *     question_mode:string,
     *     profile_required_task_kinds:list<string>,
     *     hash_excluded_fields:list<string>
     * }
     */
    public function manifest(): array
    {
        return [
            'schema' => self::SCHEMA,
            'envelope_contract' => self::ENVELOPE_CONTRACT,
            'compact_sdd_contract' => self::COMPACT_SDD_CONTRACT,
            'envelope_flow_id' => self::ENVELOPE_FLOW_ID,
            'command_intent_set' => self::COMMAND_INTENT_SET,
            'task_kinds' => self::TASK_KINDS,
            'risk_levels' => self::RISK_LEVELS,
            'modes' => self::MODES,
            'escalate_mode' => self::ESCALATE_MODE,
            'question_mode' => self::QUESTION_MODE,
            'profile_required_task_kinds' => self::PROFILE_REQUIRED_KINDS,
            'hash_excluded_fields' => self::HASH_EXCLUDED_FIELDS,
        ];
    }

    // ---------------------------------------------------------------------
    // primitives
    // ---------------------------------------------------------------------

    /**
     * Ordered risk index (R0..R5 -> 0..5); -1 for an unknown level so unknown
     * never trips the >= R4 forcing rule by accident.
     */
    private function riskIndex(string $riskLevel): int
    {
        $index = array_search($riskLevel, self::RISK_LEVELS, true);

        return $index === false ? -1 : $index;
    }

    /**
     * UUID v7 check: canonical 8-4-4-4-12 hex with version nibble 7 and an
     * RFC 4122 variant nibble (8|9|a|b).
     */
    private function isUuidV7(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value,
        );
    }

    /**
     * Absolute path: POSIX root ("/...") — the doc's workspace is always a real
     * absolute filesystem path (the desktop slug is resolved upstream).
     */
    private function isAbsolutePath(string $value): bool
    {
        return $value !== '' && str_starts_with($value, '/');
    }
}
