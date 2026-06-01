<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas AI Architecture Audit Capability Ownership Map decider.
 *
 * Pure, deterministic runtime for the ownership-map doc. The doc's hard claim is
 * anti-duplication governance: "Every repeated capability must have one owner and
 * be consumed by surfaces/domains." This service makes that claim enforceable
 * instead of decorative. Two documented mechanisms are modelled and never lie:
 *
 *  1. The ownership table (doc body | Capability | Canonical owner | Notes |):
 *     fifteen capabilities, each mapped to exactly one canonical owner. `ownerOf`
 *     resolves a capability to its single owner; an unmapped capability is a gap,
 *     not a guess. Two capabilities can never legally resolve to two owners — the
 *     map is the single source of truth.
 *
 *  2. The Placement Rule (doc "Placement Rule"), three ordered clauses:
 *       a. "If it executes external commands, it is Runtime or Tool Runtime."
 *       b. "If more than one domain needs it, it is Core or Runtime."
 *       c. "If more than one surface needs it, it is not surface-owned."
 *     `placeCapability` applies these to placement signals and returns the
 *     allowed layer(s). The execution clause is strongest: anything that runs
 *     external commands is forced to Runtime/Tool Runtime regardless of how many
 *     surfaces or domains touch it. Multi-domain forces Core/Runtime. Multi-
 *     surface only forbids surface-ownership. A single-surface, single-domain,
 *     non-executing capability is the only case that MAY be surface-owned.
 *
 * Duplication audit (doc Resumo + decisions): a claim that a surface *owns* a
 * capability is a violation when the Placement Rule says that capability cannot
 * be surface-owned (it spans surfaces/domains or executes). `auditOwnershipClaim`
 * returns that verdict with the exact failing clause, which is the whole reason
 * the map exists — "preventing duplication across surfaces and domains."
 *
 * Evidence gate (doc frontmatter forbidden_changes): "Declarar runtime,
 * maturidade ou prontidao sem evidencia verificavel e gates verdes." Placement
 * signals are read as explicit booleans/ints; absence is treated as the
 * conservative default (single surface, single domain, no execution) and never
 * silently widens a claim.
 *
 * NEVER calls a provider. NEVER executes a command. No database.
 *
 * @see docs/engineering-knowledge-base/architecture-audit/capability-ownership-map.md
 */
class AtlasCapabilityOwnershipMapService
{
    public const SCHEMA_VERSION = 'atlas.aaeos.capability_ownership_map.v1';

    /** Placement verdict tokens. */
    public const PLACEMENT_SURFACE = 'surface';

    public const PLACEMENT_CORE = 'core';

    public const PLACEMENT_RUNTIME = 'runtime';

    public const PLACEMENT_TOOL_RUNTIME = 'tool_runtime';

    /**
     * Doc ownership table — each capability has exactly one canonical owner.
     * Order preserved from the doc. The `notes` mirror the doc's Notes column.
     *
     * @var array<string,array{capability:string,owner:string,notes:string}>
     */
    public const OWNERSHIP = [
        'domain_intent_resolution' => [
            'capability' => 'Domain/intent resolution',
            'owner' => 'Atlas AI Core',
            'notes' => 'Classifies domain, risk and task type.',
        ],
        'provider_model_policy_choice' => [
            'capability' => 'Provider/model/policy choice',
            'owner' => 'Atlas Decide',
            'notes' => 'Emits receipt; does not execute.',
        ],
        'policy_profiles' => [
            'capability' => 'Policy profiles',
            'owner' => 'Policy/Profile layer',
            'notes' => 'Merges global, domain, flow, surface, risk and session overrides.',
        ],
        'domain_flow_profiles' => [
            'capability' => 'Domain/flow profiles',
            'owner' => 'Profile resolver',
            'notes' => 'Separates vertical domain from executable flow.',
        ],
        'base_context' => [
            'capability' => 'Base context',
            'owner' => 'Context Builder',
            'notes' => 'Task, conversation, workspace and policy context.',
        ],
        'memory_open_brain' => [
            'capability' => 'Memory/Open Brain',
            'owner' => 'Memory Context Core',
            'notes' => 'Provider-safe knowledge, memory quality and code refs.',
        ],
        'engineering_context' => [
            'capability' => 'Engineering context',
            'owner' => 'Programming domain',
            'notes' => 'Deep repo/task context for programming flows.',
        ],
        'programming' => [
            'capability' => 'Programming',
            'owner' => 'Programming domain',
            'notes' => 'Unifies dev, forge, fix, continue, app and workers.',
        ],
        'heavy_harness' => [
            'capability' => 'Heavy harness',
            'owner' => 'Engineering Harness',
            'notes' => 'Runtime intensity, not separate product.',
        ],
        'tools' => [
            'capability' => 'Tools',
            'owner' => 'Super Tool Runtime',
            'notes' => 'Registry, planner, policy, executor, normalizer and evidence.',
        ],
        'programming_gates' => [
            'capability' => 'Programming gates',
            'owner' => 'Programming Quality Matrix',
            'notes' => 'Composes basic, tool, blueprint and release gates.',
        ],
        'repair' => [
            'capability' => 'Repair',
            'owner' => 'Domain repair loop',
            'notes' => 'Uses failure taxonomy and repair capsule.',
        ],
        'evidence_packet' => [
            'capability' => 'Evidence packet',
            'owner' => 'Evidence layer',
            'notes' => 'Domain-specific final packet and ledger events.',
        ],
        'telemetry' => [
            'capability' => 'Telemetry',
            'owner' => 'Telemetry/read models',
            'notes' => 'Quality, cost, latency, repair, provider performance.',
        ],
        'evolution' => [
            'capability' => 'Evolution',
            'owner' => 'Self-Improvement/Curator',
            'notes' => 'Detects gaps, drift and duplicate behavior.',
        ],
    ];

    /**
     * Resolve a capability to its single canonical owner (doc ownership table).
     * An unmapped capability returns found=false with a null owner — the map
     * never invents an owner, because a guessed owner is exactly the duplication
     * the doc exists to prevent.
     *
     * @return array{schema_version:string,key:string,found:bool,capability:?string,owner:?string,notes:?string}
     */
    public function ownerOf(string $capability): array
    {
        $key = $this->normalizeKey($capability);
        $row = self::OWNERSHIP[$key] ?? null;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'key' => $key,
            'found' => $row !== null,
            'capability' => $row['capability'] ?? null,
            'owner' => $row['owner'] ?? null,
            'notes' => $row['notes'] ?? null,
        ];
    }

    /**
     * Apply the doc "Placement Rule" to placement signals.
     *
     * Doc clauses, evaluated in strength order:
     *   1. executes_external_commands -> Runtime or Tool Runtime (strongest).
     *   2. multi_domain (>1 domain needs it) -> Core or Runtime.
     *   3. multi_surface (>1 surface needs it) -> not surface-owned.
     *
     * Only a single-surface, single-domain, non-executing capability MAY be
     * surface-owned. `surface_ownable` is the single boolean a caller needs to
     * gate a "this surface owns X" claim.
     *
     * @param  array{executes_external_commands?:bool,surface_count?:int,domain_count?:int}  $signals
     * @return array<string,mixed>
     */
    public function placeCapability(array $signals): array
    {
        $executes = (bool) ($signals['executes_external_commands'] ?? false);
        $surfaceCount = max(0, (int) ($signals['surface_count'] ?? 1));
        $domainCount = max(0, (int) ($signals['domain_count'] ?? 1));

        $multiSurface = $surfaceCount > 1;
        $multiDomain = $domainCount > 1;

        $appliedRules = [];
        $allowedLayers = [];

        // Clause 1 (strongest): executes external commands -> Runtime/Tool Runtime.
        if ($executes) {
            $allowedLayers = [self::PLACEMENT_RUNTIME, self::PLACEMENT_TOOL_RUNTIME];
            $appliedRules[] = 'executes_external_commands_is_runtime_or_tool_runtime';
        }

        // Clause 2: more than one domain -> Core or Runtime.
        if ($multiDomain) {
            $appliedRules[] = 'multi_domain_is_core_or_runtime';
            if (! $executes) {
                $allowedLayers = [self::PLACEMENT_CORE, self::PLACEMENT_RUNTIME];
            }
        }

        // Clause 3: more than one surface -> not surface-owned (Core/Runtime/Tool).
        if ($multiSurface) {
            $appliedRules[] = 'multi_surface_is_not_surface_owned';
            if ($allowedLayers === []) {
                $allowedLayers = [self::PLACEMENT_CORE, self::PLACEMENT_RUNTIME, self::PLACEMENT_TOOL_RUNTIME];
            }
        }

        // Default: single surface, single domain, no execution -> may be surface-owned.
        if ($allowedLayers === []) {
            $allowedLayers = [self::PLACEMENT_SURFACE];
            $appliedRules[] = 'single_surface_single_domain_non_executing_may_be_surface_owned';
        }

        $surfaceOwnable = $allowedLayers === [self::PLACEMENT_SURFACE];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'signals' => [
                'executes_external_commands' => $executes,
                'surface_count' => $surfaceCount,
                'domain_count' => $domainCount,
                'multi_surface' => $multiSurface,
                'multi_domain' => $multiDomain,
            ],
            'allowed_layers' => $allowedLayers,
            'surface_ownable' => $surfaceOwnable,
            'applied_rules' => $appliedRules,
        ];
    }

    /**
     * Duplication audit (doc Resumo + decisions): is a claim that some surface
     * *owns* a capability legitimate under the Placement Rule? A surface may own
     * a capability only when that capability is surface-ownable. Otherwise the
     * claim is a duplication violation and the failing clause is named.
     *
     * @param  array{executes_external_commands?:bool,surface_count?:int,domain_count?:int}  $signals
     * @return array{schema_version:string,claimed_owner_type:string,valid:bool,surface_ownable:bool,allowed_layers:list<string>,violations:list<string>}
     */
    public function auditOwnershipClaim(string $claimedOwnerType, array $signals): array
    {
        $placement = $this->placeCapability($signals);
        $isSurfaceClaim = $this->normalizeKey($claimedOwnerType) === self::PLACEMENT_SURFACE;
        $surfaceOwnable = (bool) $placement['surface_ownable'];

        $violations = [];
        // The only way a claim is invalid is claiming surface ownership of a
        // capability the Placement Rule forbids from being surface-owned.
        $valid = ! $isSurfaceClaim || $surfaceOwnable;
        if ($isSurfaceClaim && ! $surfaceOwnable) {
            $violations[] = 'surface_ownership_claimed_for_cross_cutting_or_executing_capability';
            foreach ($placement['applied_rules'] as $rule) {
                if ($rule !== 'single_surface_single_domain_non_executing_may_be_surface_owned') {
                    $violations[] = $rule;
                }
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'claimed_owner_type' => $this->normalizeKey($claimedOwnerType),
            'valid' => $valid,
            'surface_ownable' => $surfaceOwnable,
            'allowed_layers' => $placement['allowed_layers'],
            'violations' => $violations,
        ];
    }

    /**
     * Full ownership map snapshot (doc ownership table) for the CLI / read model.
     *
     * @return array<string,mixed>
     */
    public function map(): array
    {
        $owners = [];
        foreach (self::OWNERSHIP as $row) {
            $owners[$row['owner']] = ($owners[$row['owner']] ?? 0) + 1;
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'capability_count' => count(self::OWNERSHIP),
            'distinct_owner_count' => count($owners),
            'capabilities' => array_values(self::OWNERSHIP),
        ];
    }

    /**
     * Normalize a free-form capability / owner-type label to a stable key:
     * lowercase, separators ("/", "-", spaces) collapsed to single underscores.
     */
    private function normalizeKey(string $value): string
    {
        $lower = strtolower(trim($value));
        $underscored = (string) preg_replace('/[^a-z0-9]+/', '_', $lower);

        return trim($underscored, '_');
    }
}
