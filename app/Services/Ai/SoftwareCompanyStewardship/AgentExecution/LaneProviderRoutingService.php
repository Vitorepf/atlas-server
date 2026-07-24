<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AgentExecution;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\AtlasForgeProviderTopologyService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipStringListNormalizer;
use App\Support\AtlasSecurity;

/**
 * Lane Provider Routing (AP-804).
 *
 * Produces an explicit, auditable, substitutable provider plan PER multi-agent
 * lane (AP-797), so the Stewardship multi-agent cycle is never locked to one
 * hardcoded Cursor/Claude/Codex account. Each lane gets desired capabilities, a
 * preferred provider, a fallback chain, the selected provider/model/profile,
 * auth mode, availability and an honest reason/blocker.
 *
 * Atlas Decide is authoritative when present: a normalized provider topology
 * (mapped from {@see AtlasForgeProviderTopologyService})
 * supplies provider availability and per-role assignments. When no topology is
 * supplied the plan degrades HONESTLY — it stays a `deferred` plan carrying the
 * `atlas_decide_unavailable` blocker, never a silent fallback and never a claim
 * that a provider was invoked.
 *
 * Hard invariants:
 *   - NEVER invokes a provider; `provider_invoked` is always false and
 *     `invocation_state` is `planned` or `deferred`, never `real`.
 *   - NEVER silent: every fallback step and degradation has an explicit reason.
 *   - NEVER a single account-as-truth: preferred + fallback_chain are a
 *     capability-tier policy, overridable by Atlas Decide topology assignments.
 *   - NEVER echoes a secret: only whitelisted topology fields are read; free
 *     text is redacted; `auth_mode` is a label (account/session/api/unknown).
 *   - Deterministic: same input -> same plan_hash / entry_hash.
 */
final class LaneProviderRoutingService
{
    public const PLAN_SCHEMA = 'atlas.agent_execution.lane_provider_plan.v1';

    public const ENTRY_SCHEMA = 'atlas.agent_execution.lane_provider_plan_entry.v1';

    public const AP_CONTRACT = 'AP-804';

    // Routing provenance.
    public const SOURCE_ATLAS_DECIDE = 'atlas_decide_topology';

    public const SOURCE_INJECTED = 'injected_topology';

    public const SOURCE_DEGRADED = 'degraded_default_no_atlas_decide';

    // Auth modes (label only — never a secret).
    public const AUTH_ACCOUNT = 'account';

    public const AUTH_SESSION = 'session';

    public const AUTH_API = 'api';

    public const AUTH_UNKNOWN = 'unknown';

    // Availability.
    public const AVAIL_AVAILABLE = 'available';

    public const AVAIL_UNAVAILABLE = 'unavailable';

    public const AVAIL_UNKNOWN = 'unknown';

    // Invocation states (this service only plans).
    public const INVOCATION_PLANNED = 'planned';

    public const INVOCATION_DEFERRED = 'deferred';

    // Blockers.
    public const BLOCK_PROVIDER_UNAVAILABLE = 'blocked_provider_unavailable';

    public const BLOCK_ATLAS_DECIDE_UNAVAILABLE = 'atlas_decide_unavailable';

    /** Canonical multi-agent lane roles (mirrors AP-797). */
    private const ROLE_TIER = [
        'context_scout' => 'cheap_fast',
        'architect' => 'strong_reasoning',
        'implementer' => 'builder_write',
        'reviewer' => 'critical_review',
        'judge' => 'critical_review',
        'repair_agent' => 'builder_write',
    ];

    /** Desired capabilities declared per lane (drives provider eligibility). */
    private const TIER_CAPABILITIES = [
        'cheap_fast' => ['read_context', 'fast', 'low_cost', 'large_context'],
        'strong_reasoning' => ['strong_reasoning', 'planning', 'spec_authoring'],
        'builder_write' => ['tool_use', 'file_write', 'code_edit', 'strong_reasoning'],
        'critical_review' => ['critical_review', 'deterministic_judgement', 'reasoning'],
    ];

    /** A provider qualifies for a tier if it declares at least one of these. */
    private const TIER_REQUIRED_ANY = [
        'cheap_fast' => ['read_context', 'fast', 'low_cost', 'large_context'],
        'strong_reasoning' => ['strong_reasoning', 'planning'],
        'builder_write' => ['tool_use', 'file_write', 'code_edit'],
        'critical_review' => ['critical_review', 'deterministic_judgement', 'reasoning'],
    ];

    /**
     * Default capability-tier provider preference. This is a POLICY with explicit
     * fallback, NOT a single account-as-truth, and Atlas Decide topology
     * role_assignments override the preferred head.
     */
    private const TIER_PROVIDER_POLICY = [
        'cheap_fast' => ['gemini_cli', 'claude_cli', 'codex_cli'],
        'strong_reasoning' => ['claude_cli', 'codex_cli', 'gemini_cli'],
        'builder_write' => ['cursor_cli', 'claude_cli', 'codex_cli'],
        'critical_review' => ['codex_cli', 'claude_cli', 'gemini_cli'],
    ];

    /** Model profile alias used when a concrete model id is unknown. */
    private const TIER_PROFILE = [
        'cheap_fast' => 'fast',
        'strong_reasoning' => 'premium',
        'builder_write' => 'default',
        'critical_review' => 'default',
    ];

    /** Lane role -> Atlas Decide / Forge topology role for assignment lookup. */
    private const ROLE_TO_TOPOLOGY_ROLE = [
        'context_scout' => 'context_scout',
        'architect' => 'primary_builder',
        'implementer' => 'primary_builder',
        'reviewer' => 'critical_reviewer',
        'judge' => 'critical_reviewer',
        'repair_agent' => 'repair_agent',
    ];

    /**
     * Build a full lane provider plan document for a set of lanes.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function route(array $input): array
    {
        $topology = is_array($input['provider_topology'] ?? null) ? $input['provider_topology'] : [];
        $lanes = $this->extractLanes($input);
        $source = $this->resolveSource($topology);

        $entries = [];
        $blocked = [];
        $degraded = false;
        foreach ($lanes as $lane) {
            $entry = $this->routeLane((string) $lane['role'], (string) $lane['lane_id'], $topology, $source);
            $entries[] = $entry;
            if ($entry['blocker'] !== null) {
                $blocked[] = $entry['lane_id'];
            }
            if ($entry['invocation_state'] === self::INVOCATION_DEFERRED) {
                $degraded = true;
            }
        }

        $doc = [
            'schema_version' => self::PLAN_SCHEMA,
            'ap_contract' => self::AP_CONTRACT,
            'substrate_contract' => 'AP-793',
            'source' => $source,
            'atlas_decide_used' => $source === self::SOURCE_ATLAS_DECIDE,
            'atlas_decide_status' => $source === self::SOURCE_DEGRADED ? 'unavailable_degraded' : 'available',
            'lanes' => $entries,
            'summary' => [
                'lane_count' => count($entries),
                'blocked_lane_ids' => $blocked,
                'degraded' => $degraded,
            ],
            'claim_policy' => [
                'no_provider_call' => true,
                'no_silent_fallback' => true,
                'provider_invoked' => false,
                'secrets_redacted' => true,
                'deterministic' => true,
                'per_lane_routing' => true,
            ],
        ];
        $doc['plan_hash'] = 'sha256:'.MissionCanonicalHash::sha256($doc);

        return $doc;
    }

    /**
     * Route a single lane. Used by {@see self::route()} and embedded into each
     * lane by {@see MultiAgentLaneOrchestratorService}. Deterministic; carries no
     * timestamp.
     *
     * @param  array<string,mixed>  $topology
     * @return array<string,mixed>
     */
    public function routeLane(string $role, string $laneId, array $topology, ?string $source = null): array
    {
        $source ??= $this->resolveSource($topology);
        $tier = self::ROLE_TIER[$role] ?? 'strong_reasoning';
        $desired = self::TIER_CAPABILITIES[$tier];
        $providers = is_array($topology['providers'] ?? null) ? $topology['providers'] : [];
        $assignments = is_array($topology['role_assignments'] ?? null) ? $topology['role_assignments'] : [];

        $policy = self::TIER_PROVIDER_POLICY[$tier];
        $assigned = $this->assignedProvider($role, $assignments);

        if ($source === self::SOURCE_DEGRADED || $providers === []) {
            // No Atlas Decide / topology: keep a preferred plan, but stay deferred
            // with an HONEST blocker. Never a silent fallback, never "available".
            $preferred = $assigned['provider'] ?? $policy[0];
            $fallback = array_values(array_filter($policy, static fn ($p) => $p !== $preferred));

            return $this->entry(
                laneId: $laneId,
                role: $role,
                tier: $tier,
                desired: $desired,
                preferred: $preferred,
                fallback: $fallback,
                selected: $preferred,
                model: $assigned['model'],
                profile: self::TIER_PROFILE[$tier],
                authMode: self::AUTH_UNKNOWN,
                availability: self::AVAIL_UNKNOWN,
                fallbackApplied: false,
                reason: 'atlas_decide_unavailable: plan deferred using default capability policy; provider not confirmed available',
                blocker: self::BLOCK_ATLAS_DECIDE_UNAVAILABLE,
                invocation: self::INVOCATION_DEFERRED,
                source: $source,
            );
        }

        // Topology present: build the qualifying chain, Atlas Decide head first.
        $qualifying = [];
        foreach ($policy as $p) {
            if (isset($providers[$p]) && $this->qualifies($providers[$p], $tier)) {
                $qualifying[] = $p;
            }
        }
        $preferred = $assigned['provider'] !== null && isset($providers[$assigned['provider']])
            ? $assigned['provider']
            : ($qualifying[0] ?? $policy[0]);

        $chain = StewardshipStringListNormalizer::uniqueMergedStrings(
            [$preferred],
            array_values(array_filter($qualifying, static fn ($p) => $p !== $preferred)),
        );

        $selected = null;
        foreach ($chain as $p) {
            if ($this->isAvailable($providers[$p] ?? null)) {
                $selected = $p;
                break;
            }
        }

        if ($selected === null) {
            return $this->entry(
                laneId: $laneId,
                role: $role,
                tier: $tier,
                desired: $desired,
                preferred: $preferred,
                fallback: array_values(array_filter($chain, static fn ($p) => $p !== $preferred)),
                selected: null,
                model: null,
                profile: self::TIER_PROFILE[$tier],
                authMode: self::AUTH_UNKNOWN,
                availability: self::AVAIL_UNAVAILABLE,
                fallbackApplied: false,
                reason: 'no provider in [preferred, ...fallback_chain] is available; chain=['.implode(',', $chain).']',
                blocker: self::BLOCK_PROVIDER_UNAVAILABLE,
                invocation: self::INVOCATION_PLANNED,
                source: $source,
            );
        }

        $fallbackApplied = $selected !== $preferred;
        $providerFacts = $providers[$selected];
        $model = ($selected === $assigned['provider'] && $assigned['model'] !== null)
            ? $assigned['model']
            : $this->firstModel($providerFacts);

        $reason = $fallbackApplied
            ? "preferred '{$preferred}' unavailable; fell back to '{$selected}' (next available in chain)"
            : "preferred '{$selected}' available and capability-qualified for {$tier}";

        return $this->entry(
            laneId: $laneId,
            role: $role,
            tier: $tier,
            desired: $desired,
            preferred: $preferred,
            fallback: array_values(array_filter($chain, static fn ($p) => $p !== $preferred)),
            selected: $selected,
            model: $model,
            profile: self::TIER_PROFILE[$tier],
            authMode: $this->mapAuth($providerFacts['auth_mode'] ?? null),
            availability: self::AVAIL_AVAILABLE,
            fallbackApplied: $fallbackApplied,
            reason: $reason,
            blocker: null,
            invocation: self::INVOCATION_PLANNED,
            source: $source,
        );
    }

    /**
     * @param  list<string>  $desired
     * @param  list<string>  $fallback
     * @return array<string,mixed>
     */
    private function entry(
        string $laneId,
        string $role,
        string $tier,
        array $desired,
        string $preferred,
        array $fallback,
        ?string $selected,
        ?string $model,
        string $profile,
        string $authMode,
        string $availability,
        bool $fallbackApplied,
        string $reason,
        ?string $blocker,
        string $invocation,
        string $source,
    ): array {
        $entry = [
            'entry_schema' => self::ENTRY_SCHEMA,
            'lane_id' => $laneId,
            'role' => $role,
            'tier' => $tier,
            'desired_capabilities' => $desired,
            'preferred_provider' => $preferred,
            'fallback_chain' => $fallback,
            'selected_provider' => $selected,
            'selected_model' => $model !== null ? AtlasSecurity::redactString($model) : null,
            'selected_profile' => $profile,
            'auth_mode' => $authMode,
            'availability' => $availability,
            'fallback_applied' => $fallbackApplied,
            'reason' => AtlasSecurity::redactString($reason),
            'blocker' => $blocker,
            'invocation_state' => $invocation,
            'provider_invoked' => false,
            'routing_source' => $source,
        ];
        $entry['entry_hash'] = 'sha256:'.MissionCanonicalHash::sha256($entry);

        return $entry;
    }

    /**
     * @param  array<string,mixed>  $assignments
     * @return array{provider:?string,model:?string}
     */
    private function assignedProvider(string $role, array $assignments): array
    {
        $topologyRole = self::ROLE_TO_TOPOLOGY_ROLE[$role] ?? $role;
        $assignment = $assignments[$topologyRole] ?? ($assignments[$role] ?? null);
        if (! is_array($assignment)) {
            return ['provider' => null, 'model' => null];
        }
        $provider = isset($assignment['provider']) && is_string($assignment['provider']) && trim($assignment['provider']) !== ''
            ? trim($assignment['provider'])
            : null;
        $model = isset($assignment['model']) && is_string($assignment['model']) && trim($assignment['model']) !== ''
            ? trim($assignment['model'])
            : null;

        return ['provider' => $provider, 'model' => $model];
    }

    /**
     * @param  array<string,mixed>  $providerFacts
     */
    private function qualifies(array $providerFacts, string $tier): bool
    {
        $caps = StewardshipStringListNormalizer::arrayTrimmedStrings($providerFacts['capabilities'] ?? []);
        if ($caps === []) {
            // Unknown capabilities: trust the tier policy ordering.
            return true;
        }

        return array_intersect($caps, self::TIER_REQUIRED_ANY[$tier]) !== [];
    }

    private function isAvailable(mixed $providerFacts): bool
    {
        if (! is_array($providerFacts)) {
            return false;
        }
        if (array_key_exists('available', $providerFacts)) {
            return $providerFacts['available'] === true;
        }
        $state = strtolower((string) ($providerFacts['state'] ?? ''));

        return $state === self::AVAIL_AVAILABLE;
    }

    /**
     * @param  array<string,mixed>  $providerFacts
     */
    private function firstModel(array $providerFacts): ?string
    {
        $models = StewardshipStringListNormalizer::arrayTrimmedStrings($providerFacts['models'] ?? []);

        return $models[0] ?? null;
    }

    private function mapAuth(mixed $raw): string
    {
        $value = is_string($raw) ? strtolower(trim($raw)) : '';

        return match ($value) {
            'account', 'local_account' => self::AUTH_ACCOUNT,
            'session' => self::AUTH_SESSION,
            'api', 'api_key' => self::AUTH_API,
            default => self::AUTH_UNKNOWN,
        };
    }

    private function resolveSource(array $topology): string
    {
        if (! is_array($topology['providers'] ?? null) || $topology['providers'] === []) {
            return self::SOURCE_DEGRADED;
        }
        $declared = (string) ($topology['source'] ?? '');

        return $declared === self::SOURCE_ATLAS_DECIDE ? self::SOURCE_ATLAS_DECIDE : self::SOURCE_INJECTED;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return list<array{lane_id:string,role:string}>
     */
    private function extractLanes(array $input): array
    {
        $raw = $input['lanes'] ?? null;
        if (! is_array($raw) && is_array($input['lane_plan']['lanes'] ?? null)) {
            $raw = $input['lane_plan']['lanes'];
        }
        if (! is_array($raw)) {
            return [];
        }

        $lanes = [];
        foreach ($raw as $i => $lane) {
            if (! is_array($lane)) {
                continue;
            }
            $role = (string) ($lane['role'] ?? '');
            if ($role === '') {
                continue;
            }
            $laneId = (string) ($lane['lane_id'] ?? ('lane_'.$i));
            $lanes[] = ['lane_id' => $laneId, 'role' => $role];
        }

        return $lanes;
    }
}
