<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Pure, deterministic decider for the Atlas AI master "Planes and Authority"
 * contract.
 *
 * A plane is an AUTHORITY BOUNDARY, not a folder or a UI section. This service
 * answers the question the doc exists to answer: given a plane and a capability
 * (or action), does that plane have the authority to own it — and which
 * documented prohibition fires when it does not? It works over plain typed
 * arrays with no database, no models and no side effects, so the six-plane
 * separation can be pinned and reused independently of any pipeline.
 *
 * Rules implemented (mapped to the doc sections / frontmatter decisions):
 *
 *   1. Plane ownership — each plane owns exactly its documented capabilities and
 *      nothing else. The map is a CLOSED set: a capability owned by another plane
 *      is refused, never silently absorbed.
 *
 *   2. "Control Plane compiles the operation; it does not perform domain work."
 *      The Control plane normalizes/compiles (Input, Intent, Profile, Context,
 *      Policy, Decide, Receipt) and is explicitly denied semantic/domain work.
 *
 *   3. "Runtime executes; it never chooses mission or policy." + "Runtime
 *      executes a receipt." Runtime owns execution but is denied mission/policy
 *      choice, and an execution capability requires a receipt to proceed.
 *
 *   4. "Surface never decides provider, domain, tool or autonomy." The Surface
 *      plane owns entry/rendering only; any of those four decisions is refused.
 *
 *   5. Domain "cannot create a private pipeline." The Domain plane owns semantic
 *      work but is denied building a private/parallel pipeline.
 *
 *   6. Evidence is truth AFTER execution (append-only); Learning produces
 *      improvement signals/proposals — neither one chooses the allowed path,
 *      which stays a Control-plane authority.
 *
 * @see docs/engineering-knowledge-base/master-architecture/planes-and-authority.md
 */
final class AtlasPlanesAndAuthorityService
{
    /** Stable schema id this service stamps on every verdict. */
    public const RECEIPT_SCHEMA = 'atlas.master.planes_and_authority.contract.v1';

    /** The six authority planes (closed, ordered set). */
    public const PLANE_CONTROL = 'control';
    public const PLANE_DOMAIN = 'domain';
    public const PLANE_RUNTIME = 'runtime';
    public const PLANE_EVIDENCE = 'evidence';
    public const PLANE_LEARNING = 'learning';
    public const PLANE_SURFACE = 'surface';

    /** Sentinel returned when a plane name is not one of the six. */
    public const PLANE_UNKNOWN = 'unknown';

    /** Authorization verdicts. */
    public const VERDICT_OWNS = 'owns';
    public const VERDICT_DENIED = 'denied';
    public const VERDICT_REQUIRES_RECEIPT = 'requires_receipt';
    public const VERDICT_UNKNOWN_PLANE = 'unknown_plane';
    public const VERDICT_UNKNOWN_CAPABILITY = 'unknown_capability';

    /**
     * The closed set of the six planes in their documented authority order
     * (Control compiles -> Domain shapes -> Runtime executes -> Evidence proves
     * -> Learning improves; Surface is the entry boundary).
     *
     * @var list<string>
     */
    public const PLANES = [
        self::PLANE_CONTROL,
        self::PLANE_DOMAIN,
        self::PLANE_RUNTIME,
        self::PLANE_EVIDENCE,
        self::PLANE_LEARNING,
        self::PLANE_SURFACE,
    ];

    /**
     * Capability -> owning plane. Drawn verbatim from each plane's "Owns" line.
     * A capability appears under exactly one plane: ownership is exclusive, so
     * the same capability can never be claimed by two planes.
     *
     * @var array<string,string>
     */
    private const CAPABILITY_OWNER = [
        // Control Plane: normalization and compilation of the operation.
        'input' => self::PLANE_CONTROL,
        'intent' => self::PLANE_CONTROL,
        'profile' => self::PLANE_CONTROL,
        'context' => self::PLANE_CONTROL,
        'policy' => self::PLANE_CONTROL,
        'decide' => self::PLANE_CONTROL,
        'receipt' => self::PLANE_CONTROL,
        'allowed_path' => self::PLANE_CONTROL,
        'constraints' => self::PLANE_CONTROL,

        // Domain Plane: semantic work.
        'flows' => self::PLANE_DOMAIN,
        'context_needs' => self::PLANE_DOMAIN,
        'gates' => self::PLANE_DOMAIN,
        'memory_projection' => self::PLANE_DOMAIN,
        'evidence_shape' => self::PLANE_DOMAIN,
        'semantic_work' => self::PLANE_DOMAIN,

        // Runtime Plane: execution.
        'execution' => self::PLANE_RUNTIME,
        'providers' => self::PLANE_RUNTIME,
        'workers' => self::PLANE_RUNTIME,
        'harnesses' => self::PLANE_RUNTIME,
        'tools' => self::PLANE_RUNTIME,
        'execute_receipt' => self::PLANE_RUNTIME,

        // Evidence Plane: truth after execution.
        'append_only_events' => self::PLANE_EVIDENCE,
        'projections' => self::PLANE_EVIDENCE,
        'replay' => self::PLANE_EVIDENCE,
        'trace' => self::PLANE_EVIDENCE,
        'quality_packets' => self::PLANE_EVIDENCE,
        'audit' => self::PLANE_EVIDENCE,

        // Learning Plane: improvement signals.
        'memory_promotion' => self::PLANE_LEARNING,
        'metric_trends' => self::PLANE_LEARNING,
        'curator_proposals' => self::PLANE_LEARNING,
        'quality_calibration' => self::PLANE_LEARNING,

        // Surface Plane: user entry and rendering.
        'cli' => self::PLANE_SURFACE,
        'app' => self::PLANE_SURFACE,
        'mobile' => self::PLANE_SURFACE,
        'api' => self::PLANE_SURFACE,
        'mcp' => self::PLANE_SURFACE,
        'voice' => self::PLANE_SURFACE,
        'rendering' => self::PLANE_SURFACE,
        'user_entry' => self::PLANE_SURFACE,
    ];

    /**
     * Explicit per-plane prohibitions, taken verbatim from the doc body and
     * frontmatter decisions. Each entry maps a capability the plane is FORBIDDEN
     * to take, even if it is tempted to, to the documented reason code.
     *
     * @var array<string,array<string,string>>
     */
    private const PROHIBITIONS = [
        self::PLANE_CONTROL => [
            // "Control Plane compiles the operation; it does not perform domain work."
            'semantic_work' => 'control_does_not_perform_domain_work',
            'execution' => 'control_does_not_perform_domain_work',
        ],
        self::PLANE_DOMAIN => [
            // "It cannot create a private pipeline."
            'private_pipeline' => 'domain_cannot_create_private_pipeline',
            'allowed_path' => 'allowed_path_is_a_control_authority',
        ],
        self::PLANE_RUNTIME => [
            // "Runtime executes; it never chooses mission or policy."
            'mission' => 'runtime_never_chooses_mission',
            'policy' => 'runtime_never_chooses_policy',
            'allowed_path' => 'allowed_path_is_a_control_authority',
        ],
        self::PLANE_SURFACE => [
            // "Surface never decides provider, domain, tool or autonomy."
            'provider' => 'surface_never_decides_provider',
            'domain' => 'surface_never_decides_domain',
            'tool' => 'surface_never_decides_tool',
            'autonomy' => 'surface_never_decides_autonomy',
        ],
        self::PLANE_EVIDENCE => [
            // Evidence is truth AFTER execution; it does not pick the path.
            'allowed_path' => 'allowed_path_is_a_control_authority',
            'execution' => 'evidence_is_truth_after_execution_not_execution',
        ],
        self::PLANE_LEARNING => [
            // Learning proposes improvement; it does not decide the path.
            'allowed_path' => 'allowed_path_is_a_control_authority',
        ],
    ];

    /**
     * Capabilities whose authority is to CHOOSE the allowed path / constraints
     * of an operation. The doc states this is a Control-plane authority and no
     * other plane may take it ("It chooses allowed path and constraints").
     *
     * @var list<string>
     */
    private const PATH_CHOOSING_CAPABILITIES = [
        'allowed_path',
        'constraints',
        'mission',
        'autonomy',
        'provider',
    ];

    /**
     * Decide whether a plane has the authority to own a capability / action.
     *
     * Order of resolution, faithful to the doc:
     *   1. Unknown plane name -> VERDICT_UNKNOWN_PLANE (planes are a closed set).
     *   2. An explicit prohibition for that (plane, capability) -> VERDICT_DENIED
     *      with the documented reason ("does not perform domain work", "never
     *      chooses mission or policy", "never decides provider/domain/tool/
     *      autonomy", "cannot create a private pipeline").
     *   3. The capability is owned by a DIFFERENT plane -> VERDICT_DENIED.
     *   4. The capability is owned by THIS plane, and is an execution capability
     *      with no receipt -> VERDICT_REQUIRES_RECEIPT ("Runtime executes a
     *      receipt").
     *   5. Owned and admissible -> VERDICT_OWNS.
     *
     * @param  string  $plane       one of self::PLANES.
     * @param  string  $capability  documented capability / action key.
     * @param  bool  $hasReceipt     does the action carry a compiled receipt?
     *
     * @return array{schema:string,plane:string,capability:string,verdict:string,owns:bool,owner_plane:string,reason:string}
     */
    public function authorize(string $plane, string $capability, bool $hasReceipt = false): array
    {
        $p = strtolower(trim($plane));
        $c = strtolower(trim($capability));

        if (! in_array($p, self::PLANES, true)) {
            return $this->verdict(self::PLANE_UNKNOWN, $c, self::VERDICT_UNKNOWN_PLANE, false, self::PLANE_UNKNOWN, 'plane_is_not_one_of_the_six_authority_boundaries');
        }

        // 2. Explicit, documented prohibition wins before anything else.
        if (isset(self::PROHIBITIONS[$p][$c])) {
            $owner = self::CAPABILITY_OWNER[$c] ?? self::PLANE_UNKNOWN;

            return $this->verdict($p, $c, self::VERDICT_DENIED, false, $owner, self::PROHIBITIONS[$p][$c]);
        }

        $owner = self::CAPABILITY_OWNER[$c] ?? null;

        // Path-choosing capability with no owner row is still a Control-only
        // authority (mission/autonomy/provider live as prohibitions per-plane,
        // but the positive owner is always Control).
        if ($owner === null && in_array($c, self::PATH_CHOOSING_CAPABILITIES, true)) {
            $owner = self::PLANE_CONTROL;
        }

        if ($owner === null) {
            return $this->verdict($p, $c, self::VERDICT_UNKNOWN_CAPABILITY, false, self::PLANE_UNKNOWN, 'capability_not_in_any_plane_contract');
        }

        // 3. Owned by a different plane -> denied (exclusive ownership).
        if ($owner !== $p) {
            return $this->verdict($p, $c, self::VERDICT_DENIED, false, $owner, 'capability_owned_by_'.$owner.'_plane');
        }

        // 4. Runtime execution requires a receipt ("Runtime executes a receipt").
        if ($this->isExecutionCapability($c) && ! $hasReceipt) {
            return $this->verdict($p, $c, self::VERDICT_REQUIRES_RECEIPT, false, $owner, 'runtime_executes_a_receipt');
        }

        // 5. Owned and admissible.
        return $this->verdict($p, $c, self::VERDICT_OWNS, true, $owner, 'plane_owns_capability');
    }

    /**
     * Whether a plane may CHOOSE the allowed path / constraints of an operation.
     * Only the Control plane may; "It chooses allowed path and constraints."
     *
     * @param  string  $plane  one of self::PLANES.
     *
     * @return array{schema:string,plane:string,may_choose_path:bool,reason:string}
     */
    public function mayChooseAllowedPath(string $plane): array
    {
        $p = strtolower(trim($plane));
        $may = $p === self::PLANE_CONTROL;

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'plane' => in_array($p, self::PLANES, true) ? $p : self::PLANE_UNKNOWN,
            'may_choose_path' => $may,
            'reason' => $may
                ? 'control_plane_chooses_allowed_path_and_constraints'
                : 'only_control_plane_chooses_allowed_path',
        ];
    }

    /**
     * Whether Runtime may execute the given action.
     *
     * "Runtime executes; it never chooses mission or policy." + "Runtime executes
     * a receipt." Execution is allowed only when a compiled receipt is present
     * and the action is not itself a mission/policy CHOICE.
     *
     * @param  bool  $hasReceipt   does the action carry a compiled receipt?
     * @param  bool  $choosesMissionOrPolicy  is the action choosing mission/policy?
     *
     * @return array{schema:string,may_execute:bool,reason:string}
     */
    public function runtimeMayExecute(bool $hasReceipt, bool $choosesMissionOrPolicy = false): array
    {
        if ($choosesMissionOrPolicy) {
            return [
                'schema' => self::RECEIPT_SCHEMA,
                'may_execute' => false,
                'reason' => 'runtime_never_chooses_mission_or_policy',
            ];
        }

        if (! $hasReceipt) {
            return [
                'schema' => self::RECEIPT_SCHEMA,
                'may_execute' => false,
                'reason' => 'runtime_executes_a_receipt_none_present',
            ];
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'may_execute' => true,
            'reason' => 'runtime_executes_compiled_receipt',
        ];
    }

    /**
     * Resolve which plane owns a capability (for placement / routing).
     *
     * @param  string  $capability  documented capability key.
     *
     * @return array{schema:string,capability:string,owner_plane:string,placed:bool,reason:string}
     */
    public function ownerOf(string $capability): array
    {
        $c = strtolower(trim($capability));
        $owner = self::CAPABILITY_OWNER[$c] ?? null;

        if ($owner === null && in_array($c, self::PATH_CHOOSING_CAPABILITIES, true)) {
            $owner = self::PLANE_CONTROL;
        }

        if ($owner === null) {
            return [
                'schema' => self::RECEIPT_SCHEMA,
                'capability' => $c,
                'owner_plane' => self::PLANE_UNKNOWN,
                'placed' => false,
                'reason' => 'capability_not_in_any_plane_contract',
            ];
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'capability' => $c,
            'owner_plane' => $owner,
            'placed' => true,
            'reason' => 'capability_owned_by_'.$owner.'_plane',
        ];
    }

    /**
     * The full, closed manifest of the six planes with their owned capabilities
     * and documented prohibitions. A command can emit this as-is for audit.
     *
     * @return array{schema:string,plane_count:int,planes:list<string>,owns:array<string,list<string>>,prohibitions:array<string,array<string,string>>,path_authority:string,invariants:list<string>}
     */
    public function manifest(): array
    {
        $owns = [];
        foreach (self::PLANES as $plane) {
            $owns[$plane] = [];
        }
        foreach (self::CAPABILITY_OWNER as $capability => $plane) {
            $owns[$plane][] = $capability;
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'plane_count' => count(self::PLANES),
            'planes' => self::PLANES,
            'owns' => $owns,
            'prohibitions' => self::PROHIBITIONS,
            'path_authority' => self::PLANE_CONTROL,
            'invariants' => [
                'planes_are_authority_boundaries_not_folders_or_ui_sections',
                'control_plane_compiles_the_operation_it_does_not_perform_domain_work',
                'runtime_executes_it_never_chooses_mission_or_policy',
                'runtime_executes_a_receipt',
                'surface_never_decides_provider_domain_tool_or_autonomy',
                'domain_cannot_create_a_private_pipeline',
            ],
        ];
    }

    /** Execution capabilities that require a compiled receipt to proceed. */
    private function isExecutionCapability(string $capability): bool
    {
        return in_array($capability, ['execution', 'execute_receipt'], true);
    }

    /**
     * @return array{schema:string,plane:string,capability:string,verdict:string,owns:bool,owner_plane:string,reason:string}
     */
    private function verdict(string $plane, string $capability, string $verdict, bool $owns, string $ownerPlane, string $reason): array
    {
        return [
            'schema' => self::RECEIPT_SCHEMA,
            'plane' => $plane,
            'capability' => $capability,
            'verdict' => $verdict,
            'owns' => $owns,
            'owner_plane' => $ownerPlane,
            'reason' => $reason,
        ];
    }
}
