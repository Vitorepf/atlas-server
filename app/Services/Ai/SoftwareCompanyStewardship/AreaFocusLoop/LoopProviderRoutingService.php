<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Loop Per-Lane Provider Routing with Circuit Breaker (AP-804 / LHL-17).
 *
 * Produces an explicit, auditable provider routing decision PER 24h-loop lane
 * (implementer_simple / repair_agent / architect_judge / context_scout).
 * Each lane carries a preferred provider + model, a fallback chain, and an
 * honest circuit-breaker posture derived from {@see ProviderReliabilityLayerService}.
 *
 * Hard invariants:
 *   - NEVER invokes a provider; `provider_invoked` is always false.
 *   - Circuit state is read from the reliability seam — never fabricated.
 *   - A budget breach pauses the whole routing plan (status=budget_paused).
 *   - An open circuit on the preferred provider silently fails over to the
 *     next available provider in the fallback chain; if the full chain is open
 *     the lane is marked blocked (honest, never dressed as ready).
 *   - Same-error-hash repeated >= 2 escalates the lane to the next stronger tier.
 *   - Timeout count >= 2 triggers fallback to an alternative provider/model.
 *   - Rate limit triggers backoff notation (backoff_required=true) then fallback.
 *   - All decisions carry an auditable reason; no silent swaps.
 *   - Deterministic: same input -> same routing_id / plan_hash.
 *
 * Lane → capability tier:
 *   context_scout      → cheap_fast   (haiku / gemini_cli / codex-mini)
 *   implementer_simple → builder      (sonnet / claude_cli / codex_cli)
 *   repair_agent       → builder+     (sonnet upgraded if error persisted)
 *   architect_judge    → premium      (opus / strongest model, only when needed)
 *
 * Providers in scope (what binaries exist in the runtime):
 *   claude_cli, codex_cli, gemini_cli, atlas-local
 */
final class LoopProviderRoutingService
{
    public const PLAN_SCHEMA = 'atlas.software_company_stewardship.loop_provider_routing_plan.v1';

    public const ENTRY_SCHEMA = 'atlas.software_company_stewardship.loop_provider_routing_entry.v1';

    public const AP_CONTRACT = 'AP-804';

    public const SLICE_ID = 'LHL-17';

    // Plan-level status.
    public const STATUS_OK = 'ok';

    public const STATUS_DEGRADED = 'degraded';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_BUDGET_PAUSED = 'budget_paused';

    // Lane invocation states (plan-only service).
    public const INVOCATION_PLANNED = 'planned';

    public const INVOCATION_DEFERRED = 'deferred';

    public const INVOCATION_BLOCKED = 'blocked';

    // Canonical 24h-loop lanes.
    public const LANE_CONTEXT_SCOUT = 'context_scout';

    public const LANE_IMPLEMENTER_SIMPLE = 'implementer_simple';

    public const LANE_REPAIR_AGENT = 'repair_agent';

    public const LANE_ARCHITECT_JUDGE = 'architect_judge';

    public const CANONICAL_LANES = [
        self::LANE_CONTEXT_SCOUT,
        self::LANE_IMPLEMENTER_SIMPLE,
        self::LANE_REPAIR_AGENT,
        self::LANE_ARCHITECT_JUDGE,
    ];

    // Capability tiers.
    private const TIER_CHEAP_FAST = 'cheap_fast';

    private const TIER_BUILDER = 'builder';

    private const TIER_BUILDER_PLUS = 'builder_plus';

    private const TIER_PREMIUM = 'premium';

    /** Lane -> base capability tier. repair_agent may upgrade to builder_plus on error escalation. */
    private const LANE_TIER = [
        self::LANE_CONTEXT_SCOUT => self::TIER_CHEAP_FAST,
        self::LANE_IMPLEMENTER_SIMPLE => self::TIER_BUILDER,
        self::LANE_REPAIR_AGENT => self::TIER_BUILDER,
        self::LANE_ARCHITECT_JUDGE => self::TIER_PREMIUM,
    ];

    /**
     * Provider preference chain per tier. Ordered from most preferred to least.
     * Only providers that actually exist in the runtime are listed here.
     */
    private const TIER_PROVIDER_CHAIN = [
        // cheap_fast: context scouting — gemini/codex preferred, minimax as last fallback
        self::TIER_CHEAP_FAST => ['gemini_cli', 'codex_cli', 'claude_cli', 'minimax_m27_cli'],
        // builder: implementation — claude preferred, minimax as 2nd option when Cursor/Claude exhausted
        self::TIER_BUILDER => ['claude_cli', 'minimax_m27_cli', 'codex_cli', 'gemini_cli'],
        self::TIER_BUILDER_PLUS => ['claude_cli', 'minimax_m27_cli', 'codex_cli'],
        // premium: judge/architect — strongest models only, minimax as last resort
        self::TIER_PREMIUM => ['claude_cli', 'codex_cli', 'gemini_cli', 'minimax_m27_cli'],
    ];

    /**
     * Default model profile hint per tier. Concrete model is resolved from
     * topology/seam when available; this label is carried when unknown.
     */
    private const TIER_MODEL_PROFILE = [
        self::TIER_CHEAP_FAST => 'fast',       // haiku-class / gemini-flash / codex-mini
        self::TIER_BUILDER => 'standard',      // sonnet-class
        self::TIER_BUILDER_PLUS => 'standard', // sonnet-class (upgraded path)
        self::TIER_PREMIUM => 'premium',       // opus-class
    ];

    /** Repeated-error escalation threshold (same error hash count). */
    private const ERROR_ESCALATION_THRESHOLD = 2;

    /** Timeout count threshold to trigger fallback to alternative provider. */
    private const TIMEOUT_FALLBACK_THRESHOLD = 2;

    public function __construct(
        private readonly ProviderReliabilityLayerService $reliability,
    ) {}

    /**
     * Produce a full per-lane routing plan for the 24h loop.
     *
     * Input keys:
     *   lanes                   – list<{lane:string, [same_error_count:int], [timeout_count:int]}> (optional; defaults to all canonical lanes)
     *   provider_topology       – provider availability map (from AtlasDecide / topology seam) (optional)
     *   provider_reliability    – reliability seam forwarded to ProviderReliabilityLayerService (optional)
     *   budget                  – budget seam (cap_usd, spent_usd, remaining_usd)
     *   budget_breach           – bool override
     *   area                    – string (default: agentic_engineering_os)
     *   focus                   – string (default: dev_forge)
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function plan(array $input = []): array
    {
        $area = trim((string) ($input['area'] ?? 'agentic_engineering_os')) ?: 'agentic_engineering_os';
        $focus = trim((string) ($input['focus'] ?? 'dev_forge')) ?: 'dev_forge';

        // Assess reliability once; its budget breach propagates to the plan.
        $reliabilityInput = array_merge(
            is_array($input['provider_reliability'] ?? null) ? $input['provider_reliability'] : [],
            [
                'area' => $area,
                'focus' => $focus,
                'budget' => $input['budget'] ?? null,
                'budget_breach' => $input['budget_breach'] ?? false,
                'budget_remaining_usd' => $input['budget_remaining_usd'] ?? null,
            ],
        );
        $reliabilityReport = $this->reliability->assess($reliabilityInput);

        // Budget breach short-circuits the whole plan.
        if ((bool) ($reliabilityReport['budget_breach'] ?? false)) {
            return $this->buildPlan(
                area: $area,
                focus: $focus,
                status: self::STATUS_BUDGET_PAUSED,
                lanes: [],
                blockers: ['provider_budget_breach_pauses_loop'],
                warnings: [],
                reliabilityStatus: (string) ($reliabilityReport['status'] ?? 'unknown'),
            );
        }

        $topology = is_array($input['provider_topology'] ?? null) ? $input['provider_topology'] : [];
        $laneSpecs = $this->extractLaneSpecs($input);

        $openCircuitProviders = $this->openCircuitSet($reliabilityReport);

        $entries = [];
        $anyBlocked = false;
        $anyDegraded = false;
        $planBlockers = [];
        $planWarnings = array_values((array) ($reliabilityReport['warnings'] ?? []));

        // Propagate reliability blockers (excluding budget, already handled).
        foreach ((array) ($reliabilityReport['blockers'] ?? []) as $rb) {
            if ($rb !== 'provider_budget_breach_pauses_loop') {
                $planBlockers[] = (string) $rb;
            }
        }

        foreach ($laneSpecs as $spec) {
            $entry = $this->routeLane($spec, $topology, $openCircuitProviders);
            $entries[] = $entry;

            if ($entry['invocation_state'] === self::INVOCATION_BLOCKED) {
                $anyBlocked = true;
                $planBlockers[] = 'lane_blocked:'.$entry['lane'];
            } elseif ($entry['invocation_state'] === self::INVOCATION_DEFERRED || $entry['fallback_applied']) {
                $anyDegraded = true;
            }

            if ($entry['backoff_required']) {
                $planWarnings[] = 'lane_rate_limit_backoff:'.$entry['lane'];
            }
        }

        $status = match (true) {
            $anyBlocked || $planBlockers !== [] => self::STATUS_BLOCKED,
            $anyDegraded => self::STATUS_DEGRADED,
            default => self::STATUS_OK,
        };

        return $this->buildPlan(
            area: $area,
            focus: $focus,
            status: $status,
            lanes: $entries,
            blockers: $planBlockers,
            warnings: $planWarnings,
            reliabilityStatus: (string) ($reliabilityReport['status'] ?? 'unknown'),
        );
    }

    // ---------------------------------------------------------------- per-lane

    /**
     * Route a single lane, accounting for circuit breaker state and error escalation.
     *
     * @param  array<string,mixed>  $spec
     * @param  array<string,mixed>  $topology
     * @param  array<string,bool>  $openCircuitProviders  keyed by provider id
     * @return array<string,mixed>
     */
    private function routeLane(array $spec, array $topology, array $openCircuitProviders): array
    {
        $lane = (string) ($spec['lane'] ?? '');
        $sameErrorCount = max(0, (int) ($spec['same_error_count'] ?? 0));
        $timeoutCount = max(0, (int) ($spec['timeout_count'] ?? 0));
        $rateLimitHit = (bool) ($spec['rate_limit_hit'] ?? false);

        // Determine effective tier (repair_agent escalates on repeated errors).
        $baseTier = self::LANE_TIER[$lane] ?? self::TIER_BUILDER;
        $tier = $this->effectiveTier($lane, $baseTier, $sameErrorCount);

        $chain = self::TIER_PROVIDER_CHAIN[$tier];
        $profile = self::TIER_MODEL_PROFILE[$tier];

        // Reasons / escalation notes.
        $notes = [];
        if ($tier !== $baseTier) {
            $notes[] = "tier_escalated:{$baseTier}->{$tier} (same_error_count={$sameErrorCount})";
        }
        if ($timeoutCount >= self::TIMEOUT_FALLBACK_THRESHOLD) {
            $notes[] = "timeout_fallback_triggered (timeout_count={$timeoutCount})";
        }
        if ($rateLimitHit) {
            $notes[] = 'rate_limit_hit: backoff_required=true';
        }

        // Build qualifying chain: skip open-circuit providers.
        // Timeout fallback: if timeout count meets threshold, skip the head of the chain
        // (rotate past the preferred) to force an alternative provider.
        $skipHead = $timeoutCount >= self::TIMEOUT_FALLBACK_THRESHOLD;
        $qualifyingChain = $this->buildQualifyingChain($chain, $openCircuitProviders, $topology, $skipHead, $notes);

        $preferred = $chain[0] ?? null;
        $fallback = array_values(array_filter($chain, static fn ($p) => $p !== $preferred));

        if ($qualifyingChain === []) {
            return $this->entry(
                lane: $lane,
                tier: $tier,
                preferred: $preferred ?? 'none',
                fallbackChain: $fallback,
                selected: null,
                model: null,
                profile: $profile,
                fallbackApplied: false,
                backoffRequired: $rateLimitHit,
                reason: 'all_providers_circuit_open: no available provider in chain=['.implode(',', $chain).']'.($notes !== [] ? '; '.implode('; ', $notes) : ''),
                invocation: self::INVOCATION_BLOCKED,
            );
        }

        $selected = $qualifyingChain[0];
        $fallbackApplied = $selected !== $preferred;
        $model = $this->resolveModel($selected, $tier, $topology);

        $reason = $fallbackApplied
            ? "preferred '{$preferred}' circuit_open/unavailable; fell back to '{$selected}' (next qualifying in chain)"
            : "preferred '{$selected}' available and circuit_closed for tier={$tier}";

        if ($notes !== []) {
            $reason .= '; '.implode('; ', $notes);
        }

        return $this->entry(
            lane: $lane,
            tier: $tier,
            preferred: $preferred ?? $selected,
            fallbackChain: array_values(array_filter($qualifyingChain, static fn ($p) => $p !== $selected)),
            selected: $selected,
            model: $model,
            profile: $profile,
            fallbackApplied: $fallbackApplied,
            backoffRequired: $rateLimitHit,
            reason: $reason,
            invocation: self::INVOCATION_PLANNED,
        );
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param  list<string>  $chain
     * @param  array<string,bool>  $openCircuitProviders
     * @param  array<string,mixed>  $topology
     * @param  list<string>  $notes
     * @return list<string>
     */
    private function buildQualifyingChain(
        array $chain,
        array $openCircuitProviders,
        array $topology,
        bool $skipHead,
        array &$notes,
    ): array {
        $qualifying = [];
        $skippedHead = false;

        foreach ($chain as $idx => $provider) {
            // Skip open-circuit providers (circuit breaker fired).
            if ($openCircuitProviders[$provider] ?? false) {
                $notes[] = "provider_circuit_open_skipped:{$provider}";
                continue;
            }
            // Timeout fallback: skip only the very first candidate (head of chain) once.
            if ($skipHead && $idx === 0 && ! $skippedHead) {
                $skippedHead = true;
                $notes[] = "provider_head_skipped_for_timeout_fallback:{$provider}";
                continue;
            }
            // Check topology availability if topology is present.
            $providerFacts = $topology['providers'][$provider] ?? null;
            if ($providerFacts !== null && is_array($providerFacts)) {
                if (! $this->isAvailableInTopology($providerFacts)) {
                    $notes[] = "provider_topology_unavailable_skipped:{$provider}";
                    continue;
                }
            }
            $qualifying[] = $provider;
        }

        // If timeout-fallback skipped the head and we're now fully empty, re-add
        // the head as last resort (honest degraded rather than hard block).
        if ($qualifying === [] && $skipHead && isset($chain[0]) && ! ($openCircuitProviders[$chain[0]] ?? false)) {
            $qualifying[] = $chain[0];
            $notes[] = 'timeout_fallback_exhausted_chain:restored_head_as_last_resort';
        }

        return $qualifying;
    }

    private function effectiveTier(string $lane, string $baseTier, int $sameErrorCount): string
    {
        if ($lane === self::LANE_REPAIR_AGENT && $sameErrorCount >= self::ERROR_ESCALATION_THRESHOLD) {
            // Escalate repair_agent from builder to builder_plus when errors persist.
            return self::TIER_BUILDER_PLUS;
        }

        return $baseTier;
    }

    private function resolveModel(string $provider, string $tier, array $topology): ?string
    {
        $providerFacts = $topology['providers'][$provider] ?? null;
        if (is_array($providerFacts)) {
            $models = is_array($providerFacts['models'] ?? null) ? $providerFacts['models'] : [];
            if ($models !== []) {
                return is_string($models[0]) ? $models[0] : null;
            }
        }

        // No topology model — return the profile hint so callers know intent.
        return null;
    }

    /**
     * @param  array<string,mixed>  $providerFacts
     */
    private function isAvailableInTopology(array $providerFacts): bool
    {
        if (array_key_exists('available', $providerFacts)) {
            return $providerFacts['available'] === true;
        }
        $state = strtolower(trim((string) ($providerFacts['state'] ?? '')));

        return $state === 'available';
    }

    /**
     * Build a set of provider IDs whose circuit state is OPEN in the reliability report.
     *
     * @param  array<string,mixed>  $reliabilityReport
     * @return array<string,bool>
     */
    private function openCircuitSet(array $reliabilityReport): array
    {
        $open = [];
        $providers = is_array($reliabilityReport['providers'] ?? null) ? $reliabilityReport['providers'] : [];
        foreach ($providers as $p) {
            if (! is_array($p)) {
                continue;
            }
            $id = (string) ($p['id'] ?? '');
            $circuit = (string) ($p['circuit_state'] ?? '');
            if ($id !== '' && $circuit === ProviderReliabilityLayerService::CIRCUIT_OPEN) {
                $open[$id] = true;
            }
        }

        return $open;
    }

    /**
     * Extract lane specs from input. Accepts an explicit `lanes` list or defaults
     * to all canonical lanes with zero error/timeout counts.
     *
     * @param  array<string,mixed>  $input
     * @return list<array<string,mixed>>
     */
    private function extractLaneSpecs(array $input): array
    {
        $raw = $input['lanes'] ?? null;

        if (is_array($raw) && $raw !== []) {
            $specs = [];
            foreach ($raw as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $lane = trim((string) ($item['lane'] ?? ($item['role'] ?? '')));
                if ($lane === '') {
                    continue;
                }
                $specs[] = [
                    'lane' => $lane,
                    'same_error_count' => (int) ($item['same_error_count'] ?? 0),
                    'timeout_count' => (int) ($item['timeout_count'] ?? 0),
                    'rate_limit_hit' => (bool) ($item['rate_limit_hit'] ?? false),
                ];
            }

            return $specs;
        }

        // Default: route all canonical lanes with no faults.
        return array_map(
            static fn (string $lane): array => [
                'lane' => $lane,
                'same_error_count' => 0,
                'timeout_count' => 0,
                'rate_limit_hit' => false,
            ],
            self::CANONICAL_LANES,
        );
    }

    /**
     * @param  list<array<string,mixed>>  $lanes
     * @param  list<string>  $blockers
     * @param  list<string>  $warnings
     * @return array<string,mixed>
     */
    private function buildPlan(
        string $area,
        string $focus,
        string $status,
        array $lanes,
        array $blockers,
        array $warnings,
        string $reliabilityStatus,
    ): array {
        $payload = [
            'schema_version' => self::PLAN_SCHEMA,
            'ap_contract' => self::AP_CONTRACT,
            'slice_id' => self::SLICE_ID,
            'status' => $status,
            'routing_id' => 'lpr_'.substr(MissionCanonicalHash::sha256([
                $area,
                $focus,
                $status,
                $this->lanesFingerprint($lanes),
            ]), 0, 16),
            'area' => $area,
            'focus' => $focus,
            'planned_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'lanes' => $lanes,
            'reliability_status' => $reliabilityStatus,
            'summary' => [
                'lane_count' => count($lanes),
                'blocked_lanes' => array_values(array_map(
                    static fn (array $e): string => $e['lane'],
                    array_filter($lanes, static fn (array $e): bool => $e['invocation_state'] === self::INVOCATION_BLOCKED),
                )),
                'fallback_applied_lanes' => array_values(array_map(
                    static fn (array $e): string => $e['lane'],
                    array_filter($lanes, static fn (array $e): bool => (bool) ($e['fallback_applied'] ?? false)),
                )),
                'backoff_required_lanes' => array_values(array_map(
                    static fn (array $e): string => $e['lane'],
                    array_filter($lanes, static fn (array $e): bool => (bool) ($e['backoff_required'] ?? false)),
                )),
            ],
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
            'claim_policy' => [
                'read_only' => true,
                'provider_invoked' => false,
                'no_silent_fallback' => true,
                'budget_paused_honest' => true,
                'blocked_never_dressed_as_ready' => true,
            ],
        ];

        $payload['plan_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->withoutVolatile($payload));

        return $payload;
    }

    /**
     * @param  list<string>  $fallbackChain
     * @return array<string,mixed>
     */
    private function entry(
        string $lane,
        string $tier,
        string $preferred,
        array $fallbackChain,
        ?string $selected,
        ?string $model,
        string $profile,
        bool $fallbackApplied,
        bool $backoffRequired,
        string $reason,
        string $invocation,
    ): array {
        $entry = [
            'entry_schema' => self::ENTRY_SCHEMA,
            'lane' => $lane,
            'tier' => $tier,
            'preferred_provider' => $preferred,
            'fallback_chain' => $fallbackChain,
            'selected_provider' => $selected,
            'selected_model' => $model,
            'selected_profile' => $profile,
            'fallback_applied' => $fallbackApplied,
            'backoff_required' => $backoffRequired,
            'reason' => $reason,
            'invocation_state' => $invocation,
            'provider_invoked' => false,
        ];
        $entry['entry_hash'] = 'sha256:'.MissionCanonicalHash::sha256($entry);

        return $entry;
    }

    /**
     * @param  list<array<string,mixed>>  $lanes
     */
    private function lanesFingerprint(array $lanes): string
    {
        $parts = [];
        foreach ($lanes as $e) {
            $parts[] = implode(':', [
                (string) ($e['lane'] ?? ''),
                (string) ($e['selected_provider'] ?? 'none'),
                (string) ($e['invocation_state'] ?? ''),
                (string) ($e['fallback_applied'] ?? 0),
            ]);
        }
        sort($parts);

        return implode('|', $parts);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function withoutVolatile(array $payload): array
    {
        unset($payload['planned_at'], $payload['plan_hash']);

        return $payload;
    }
}
