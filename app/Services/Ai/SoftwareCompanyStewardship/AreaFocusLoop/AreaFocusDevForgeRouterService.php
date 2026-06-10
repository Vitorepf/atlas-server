<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;

/**
 * Area Focus Loop · Dev/Forge Work Order Router (AP-719).
 *
 * Atlas Software Company Stewardship Stack é stack/capability family dentro do
 * Atlas Autonomous Software Company Runtime, não OS novo.
 *
 * Turns Area Focus findings (AP-717) and inbox items (AP-718) into governed,
 * proposal-only WORK ORDERS routed to the correct owner — Self-Directed
 * Evolution (uncontracted gaps), Atlas Dev (small/local/low-risk), Forge
 * (long-horizon/cross-system) or operator review (high-risk/sensitive). It then
 * allocates governed Dev/Forge budgets and a WIP limit, blocking anything over
 * budget or WIP.
 *
 * Boundary (no duplication, no execution):
 *   - It anchors on the finding's existing `route_hint` (already classified and
 *     safety-escalated by the Finding Engine), normalizing per AP-719 rather than
 *     re-deriving a parallel classifier.
 *   - It NEVER drafts specs (Self-Directed Evolution owns that), NEVER invokes
 *     Atlas Dev or Forge, NEVER opens a branch, NEVER merges, deploys, accesses
 *     secrets or makes destructive changes, and persists nothing.
 *
 * `max_governed` means maximum useful throughput inside the safety boundary, not
 * permissionless autonomy: budgets and the WIP limit cap how many work orders are
 * emitted; the rest are blocked transparently for the next cycle / operator.
 *
 * Inputs are supplied via `$input` (decoupled from the finding engine / inbox on
 * purpose), keeping the projection deterministic and side-effect free.
 */
class AreaFocusDevForgeRouterService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.area_work_order_plan.v1';

    public const WORK_ORDER_SCHEMA = 'atlas.software_company_stewardship.area_work_order.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_BLOCKED = 'blocked';

    public const DEFAULT_AREA_ID = 'agentic_engineering_os';

    public const ROUTE_SELF_DIRECTED_EVOLUTION = 'self_directed_evolution';

    public const ROUTE_ATLAS_DEV = 'atlas_dev';

    public const ROUTE_FORGE = 'forge';

    public const ROUTE_OPERATOR_REVIEW = 'operator_review';

    public const WO_EMITTED = 'emitted';

    public const WO_BLOCKED = 'blocked';

    public const BLOCK_BUDGET_EXHAUSTED = 'budget_exhausted';

    public const BLOCK_WIP_LIMIT_REACHED = 'wip_limit_reached';

    /** Conservative governed defaults when the operator/contract provides none. */
    private const DEFAULT_DEV_BUDGET = 3;

    private const DEFAULT_FORGE_BUDGET = 1;

    private const DEFAULT_WIP_LIMIT = 5;

    /** Tokens that force operator_review regardless of the finding's route hint. */
    private const SENSITIVE_TOKENS = [
        'secret', 'secrets', 'deploy', 'merge', 'auth', 'billing', 'production',
        'prod config', 'finance', 'trading', 'cyber', 'data deletion', 'data_deletion',
        'destructive', 'migration', 'drop table', 'force push',
    ];

    /** @var array<string,int> */
    private const SEVERITY_RANK = [
        'critical' => 4,
        'high' => 3,
        'medium' => 2,
        'low' => 1,
        'unknown' => 0,
    ];

    /** Finding types that are long-horizon / cross-system → Forge. */
    private const FORGE_TYPES = [
        'weak_handoff', 'duplicate_runtime_risk', 'replay_gap', 'dev_forge_routing_gap',
    ];

    /** Finding types that are uncontracted gaps → Self-Directed Evolution. */
    private const SDE_TYPES = [
        'self_directed_spec_gap', 'docs_stale',
    ];

    /** Finding types that are small/local with clear tests → Atlas Dev. */
    private const DEV_TYPES = [
        'missing_test', 'failing_gate_hint',
    ];

    /** Finding types that always require operator review. */
    private const OPERATOR_TYPES = [
        'missing_owner_doc', 'missing_evidence',
    ];

    /** Routes that consume a Dev/Forge execution budget lane. */
    private const ROUTE_LANE = [
        self::ROUTE_ATLAS_DEV => 'dev',
        self::ROUTE_FORGE => 'forge',
        self::ROUTE_SELF_DIRECTED_EVOLUTION => 'sde',
        self::ROUTE_OPERATOR_REVIEW => 'operator',
    ];

    /**
     * Project a governed work order plan from findings and/or inbox items.
     *
     * `$input`:
     *   - findings:     list<array>  AP-717 findings (optional)
     *   - inbox_items:  list<array>  AP-718 inbox items (optional)
     *   - area_id:      string       canonical area (default agentic_engineering_os)
     *   - dev_budget:   int|array    max emitted atlas_dev work orders
     *   - forge_budget: int|array    max emitted forge work orders
     *   - wip_limit:    int|array    max total emitted work orders
     *   - risk_policy:  array        sensitive domains forcing operator_review
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function project(array $input = []): array
    {
        $areaId = trim((string) ($input['area_id'] ?? self::DEFAULT_AREA_ID)) ?: self::DEFAULT_AREA_ID;

        $findings = AreaFocusLoopPayloadNormalizer::listOfArrays($input['findings'] ?? null);
        $inboxItems = AreaFocusLoopPayloadNormalizer::listOfArrays($input['inbox_items'] ?? null);

        if ($findings === [] && $inboxItems === []) {
            return $this->blockedEnvelope($areaId, 'inputs_required',
                'AreaFocusDevForgeRouterService::project() requires at least one of input["findings"] or input["inbox_items"].');
        }

        $devCap = $this->normalizeBudget($input['dev_budget'] ?? null, self::DEFAULT_DEV_BUDGET, ['max_concurrent_work_orders', 'max', 'limit', 'value']);
        $forgeCap = $this->normalizeBudget($input['forge_budget'] ?? null, self::DEFAULT_FORGE_BUDGET, ['max_concurrent_obras', 'max', 'limit', 'value']);
        $wipCap = $this->normalizeBudget($input['wip_limit'] ?? null, self::DEFAULT_WIP_LIMIT, ['max_findings', 'max', 'limit', 'value']);
        $sensitiveDomains = $this->sensitiveDomains($input['risk_policy'] ?? null);

        $blockers = [];
        $sources = $this->mergeSources($areaId, $findings, $inboxItems, $blockers);
        $sources = $this->sortSources($sources);

        $devUsed = 0;
        $forgeUsed = 0;
        $wipUsed = 0;
        $workOrders = [];

        foreach ($sources as $source) {
            $route = $this->deriveRoute($source, $sensitiveDomains);
            $lane = self::ROUTE_LANE[$route] ?? 'operator';

            $status = self::WO_EMITTED;
            $blockReason = null;

            if ($wipUsed >= $wipCap) {
                $status = self::WO_BLOCKED;
                $blockReason = self::BLOCK_WIP_LIMIT_REACHED;
            } elseif ($lane === 'dev' && $devUsed >= $devCap) {
                $status = self::WO_BLOCKED;
                $blockReason = self::BLOCK_BUDGET_EXHAUSTED;
            } elseif ($lane === 'forge' && $forgeUsed >= $forgeCap) {
                $status = self::WO_BLOCKED;
                $blockReason = self::BLOCK_BUDGET_EXHAUSTED;
            }

            if ($status === self::WO_EMITTED) {
                $wipUsed++;
                if ($lane === 'dev') {
                    $devUsed++;
                } elseif ($lane === 'forge') {
                    $forgeUsed++;
                }
            }

            $workOrders[] = $this->makeWorkOrder($areaId, $source, $route, $lane, $status, $blockReason);
        }

        $emitted = count(array_filter($workOrders, static fn (array $w): bool => $w['status'] === self::WO_EMITTED));
        $blocked = count($workOrders) - $emitted;

        $status = match (true) {
            $workOrders === [] && $blockers !== [] => self::STATUS_BLOCKED,
            $blocked > 0 || $blockers !== [] => self::STATUS_PARTIAL,
            default => self::STATUS_READY,
        };

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'status' => $status,
            'ap_contract' => 'AP-719',
            'area_id' => $areaId,
            'stewardship_stack' => $this->stewardshipStack(),
            'governed_mode' => $this->governedMode($devCap, $forgeCap, $wipCap),
            'budget_state' => [
                'dev_cap' => $devCap,
                'dev_used' => $devUsed,
                'forge_cap' => $forgeCap,
                'forge_used' => $forgeUsed,
                'wip_cap' => $wipCap,
                'wip_used' => $wipUsed,
            ],
            'work_order_count' => count($workOrders),
            'emitted_count' => $emitted,
            'blocked_count' => $blocked,
            'counts' => $this->counts($workOrders),
            'work_orders' => $workOrders,
            'blockers' => $blockers,
            'reused_owners' => $this->reusedOwners(),
            'claim_policy' => $this->claimPolicy(),
        ];
        $payload['report_hash'] = 'sha256:'.MissionCanonicalHash::sha256($payload);
        $payload['generated_at'] = AreaFocusUtcClock::atomNow();

        return $payload;
    }

    /**
     * Materialize {@see TheRoutingDecisionRationaleContract} for a routed work order.
     *
     * Validates bounded input keys. Empty input returns
     * {@see TheRoutingDecisionRationaleContract::defaults}. Non-empty input
     * maps owner, risk, authority and route for every supported route.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function theRoutingDecisionRationale(array $input = []): array
    {
        $this->validateTheRoutingDecisionRationaleInput($input);

        if ($input === []) {
            return TheRoutingDecisionRationaleContract::defaults()->toArray();
        }

        return TheRoutingDecisionRationaleContract::fromArray($input)->toArray();
    }

    // ---------- source normalization + dedupe ----------

    /**
     * Merge findings and inbox items into a single routable source list,
     * deduplicated by finding_hash (findings win over inbox items).
     *
     * @param  list<array<string,mixed>>  $findings
     * @param  list<array<string,mixed>>  $inboxItems
     * @param  list<array<string,mixed>>  $blockers
     * @return list<array<string,mixed>>
     */
    private function mergeSources(string $areaId, array $findings, array $inboxItems, array &$blockers): array
    {
        $byHash = [];
        $ordered = [];

        foreach ($findings as $index => $finding) {
            $hash = (string) ($finding['finding_hash'] ?? '');
            if ($hash === '') {
                $blockers[] = ['reason' => 'invalid_finding', 'detail' => "findings[{$index}] has no finding_hash."];

                continue;
            }
            if (isset($byHash[$hash])) {
                continue;
            }
            $byHash[$hash] = true;
            $ordered[] = $this->source('finding', $hash, $finding, $areaId);
        }

        foreach ($inboxItems as $index => $item) {
            $hash = (string) ($item['finding_hash'] ?? '');
            if ($hash === '') {
                $blockers[] = ['reason' => 'invalid_inbox_item', 'detail' => "inbox_items[{$index}] has no finding_hash."];

                continue;
            }
            if (isset($byHash[$hash])) {
                continue; // already represented by a finding.
            }
            $byHash[$hash] = true;
            $ordered[] = $this->source('inbox_item', $hash, $item, $areaId);
        }

        return $ordered;
    }

    /**
     * Normalize one finding/inbox item into a route-able source record.
     *
     * @param  array<string,mixed>  $raw
     * @return array<string,mixed>
     */
    private function source(string $kind, string $hash, array $raw, string $areaId): array
    {
        $severity = AreaFocusScalarNormalizer::severityOrMedium($raw['severity'] ?? ($raw['risk_level'] ?? ($raw['risk'] ?? null)));
        $routeHint = (string) ($raw['route_hint'] ?? ($raw['route'] ?? ''));

        return [
            'kind' => $kind,
            'source_ref' => $hash,
            'source_id' => (string) ($raw['finding_id'] ?? ($raw['item_id'] ?? '')),
            'area_id' => (string) ($raw['area_id'] ?? $areaId),
            'finding_type' => (string) ($raw['finding_type'] ?? ($raw['type'] ?? '')),
            'title' => (string) ($raw['title'] ?? ''),
            'detail' => (string) ($raw['detail'] ?? ($raw['rationale'] ?? '')),
            'severity' => $severity,
            'confidence' => (string) ($raw['confidence'] ?? 'medium'),
            'route_hint' => $routeHint,
            'blast_radius' => strtolower((string) ($raw['blast_radius'] ?? ($raw['scope'] ?? ''))),
            'has_spec' => array_key_exists('has_spec', $raw) ? (bool) $raw['has_spec'] : null,
            'spec_draftable' => (bool) ($raw['spec_draftable'] ?? false),
            'priority_score' => (int) ($raw['priority_score'] ?? ((self::SEVERITY_RANK[$severity] ?? 0) * 100)),
            'evidence_refs' => AreaFocusStringListNormalizer::coercedStringValues($raw['evidence_refs'] ?? []),
            'recommended_action' => (string) ($raw['recommended_action'] ?? ''),
        ];
    }

    // ---------- routing ----------

    /**
     * AP-719 routing precedence (safety first), anchored on the finding's
     * existing route_hint:
     *   1. operator_review — sensitive/destructive/secrets/deploy/merge,
     *      missing owner docs, critical severity, ambiguous (low confidence +
     *      high severity), or an explicit operator_review hint;
     *   2. self_directed_evolution — uncontracted gap (no spec yet);
     *   3. forge — long-horizon/multi-agent/cross-system/high-context;
     *   4. atlas_dev — small/local/low-risk with clear tests;
     *   5. operator_review — conservative default.
     *
     * @param  array<string,mixed>  $s
     * @param  list<string>  $sensitiveDomains
     */
    private function deriveRoute(array $s, array $sensitiveDomains): string
    {
        $type = (string) $s['finding_type'];
        $severity = (string) $s['severity'];
        $confidence = (string) $s['confidence'];
        $hint = (string) $s['route_hint'];
        $blast = (string) $s['blast_radius'];

        // 1. operator_review (safety first).
        if (
            $this->isSensitive($s, $sensitiveDomains)
            || $severity === 'critical'
            || in_array($type, self::OPERATOR_TYPES, true)
            || $hint === self::ROUTE_OPERATOR_REVIEW
            || ($confidence === 'low' && in_array($severity, ['high', 'critical'], true))
        ) {
            return self::ROUTE_OPERATOR_REVIEW;
        }

        // 2. self_directed_evolution (gap without a spec).
        if (
            $hint === self::ROUTE_SELF_DIRECTED_EVOLUTION
            || in_array($type, self::SDE_TYPES, true)
            || $s['has_spec'] === false
            || $s['spec_draftable'] === true
        ) {
            return self::ROUTE_SELF_DIRECTED_EVOLUTION;
        }

        // 3. forge (long-horizon / cross-system).
        if (
            $hint === self::ROUTE_FORGE
            || in_array($type, self::FORGE_TYPES, true)
            || in_array($blast, ['cross_system', 'broad', 'long_horizon', 'multi_agent'], true)
        ) {
            return self::ROUTE_FORGE;
        }

        // 4. atlas_dev (small / local / low-risk).
        if (
            $hint === self::ROUTE_ATLAS_DEV
            || in_array($type, self::DEV_TYPES, true)
            || (in_array($severity, ['low', 'medium'], true) && in_array($blast, ['local', 'subsystem', ''], true))
        ) {
            return self::ROUTE_ATLAS_DEV;
        }

        // 5. conservative default.
        return self::ROUTE_OPERATOR_REVIEW;
    }

    /**
     * @param  array<string,mixed>  $s
     * @param  list<string>  $sensitiveDomains
     */
    private function isSensitive(array $s, array $sensitiveDomains): bool
    {
        $blob = strtolower(trim(implode(' ', [
            (string) $s['title'],
            (string) $s['detail'],
            (string) $s['finding_type'],
        ])));
        $blobSpaced = str_replace('_', ' ', $blob);

        $tokens = array_merge(self::SENSITIVE_TOKENS, array_map(
            static fn (string $d): string => str_replace('_', ' ', strtolower($d)),
            $sensitiveDomains,
        ));

        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token !== '' && (str_contains($blob, $token) || str_contains($blobSpaced, $token))) {
                return true;
            }
        }

        return false;
    }

    // ---------- work order construction ----------

    /**
     * @param  array<string,mixed>  $source
     * @return array<string,mixed>
     */
    private function makeWorkOrder(string $areaId, array $source, string $route, string $lane, string $status, ?string $blockReason): array
    {
        $raw = hash('sha256', implode('|', [$areaId, (string) $source['source_ref'], $route, $lane]));
        $routingDecisionRationale = $this->theRoutingDecisionRationale([
            'area_id' => $areaId,
            'route' => $route,
            'severity' => (string) $source['severity'],
        ]);

        return [
            'schema_version' => self::WORK_ORDER_SCHEMA,
            'work_order_id' => 'awo_'.substr($raw, 0, 16),
            'work_order_hash' => 'sha256:'.$raw,
            'area_id' => $areaId,
            'source' => $source['kind'],
            'source_ref' => $source['source_ref'],
            'source_id' => $source['source_id'],
            'finding_type' => $source['finding_type'],
            'title' => $source['title'],
            'rationale' => $source['detail'],
            'route' => $route,
            'route_reason' => $this->routeReason($route),
            'routing_decision_rationale' => $routingDecisionRationale,
            'lane' => $lane,
            'severity' => $source['severity'],
            'risk_level' => $source['severity'],
            'confidence' => $source['confidence'],
            'blast_radius' => $source['blast_radius'] !== '' ? $source['blast_radius'] : 'unknown',
            'priority_score' => $source['priority_score'],
            'evidence_refs' => $source['evidence_refs'],
            'recommended_action' => $source['recommended_action'] !== ''
                ? $source['recommended_action']
                : $this->routeReason($route),
            'status' => $status,
            'block_reason' => $blockReason,
            'requires_operator_review' => true,
            'execution_performed' => false,
            'dispatched' => false,
        ];
    }

    private function routeReason(string $route): string
    {
        return match ($route) {
            self::ROUTE_OPERATOR_REVIEW => 'High-risk, ambiguous, destructive or sensitive (secrets/deploy/merge/missing owner docs): operator decides before any work.',
            self::ROUTE_SELF_DIRECTED_EVOLUTION => 'Uncontracted gap (no spec yet): route to Self-Directed Evolution for a proposal-only spec draft.',
            self::ROUTE_FORGE => 'Long-horizon, multi-agent or cross-system work: recommend a Forge work order under max_governed budget after operator approval.',
            self::ROUTE_ATLAS_DEV => 'Small, local, low-risk work with clear tests: recommend an Atlas Dev work order under max_governed budget after operator approval.',
            default => 'Operator review required.',
        };
    }

    // ---------- summaries + policy blocks ----------

    /**
     * @param  list<array<string,mixed>>  $workOrders
     * @return array<string,mixed>
     */
    private function counts(array $workOrders): array
    {
        $byRoute = [];
        $byStatus = [];
        $byBlockReason = [];
        foreach ($workOrders as $wo) {
            $route = (string) $wo['route'];
            $st = (string) $wo['status'];
            $byRoute[$route] = ($byRoute[$route] ?? 0) + 1;
            $byStatus[$st] = ($byStatus[$st] ?? 0) + 1;
            if (($wo['block_reason'] ?? null) !== null) {
                $reason = (string) $wo['block_reason'];
                $byBlockReason[$reason] = ($byBlockReason[$reason] ?? 0) + 1;
            }
        }
        ksort($byRoute);
        ksort($byStatus);
        ksort($byBlockReason);

        return [
            'by_route' => $byRoute,
            'by_status' => $byStatus,
            'by_block_reason' => $byBlockReason,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function governedMode(int $devCap, int $forgeCap, int $wipCap): array
    {
        return [
            'mode' => 'max_governed',
            'definition' => 'maximum useful throughput inside the canonical safety boundary; not permissionless autonomy',
            'dev_budget' => $devCap,
            'forge_budget' => $forgeCap,
            'wip_limit' => $wipCap,
            'invariants' => [
                'executes_dev' => false,
                'executes_forge' => false,
                'dispatches_work' => false,
                'merge_without_operator' => false,
                'deploy_without_operator' => false,
                'secret_access' => false,
                'destructive_change' => false,
                'branch_isolation_required' => true,
                'budget_required' => true,
                'wip_limit_required' => true,
                'kill_switch_required' => true,
                'evidence_pack_required' => true,
                'morning_inbox_required' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function stewardshipStack(): array
    {
        return [
            'umbrella' => 'Atlas Software Company Stewardship Stack',
            'level_name' => 'Area Focus Loop',
            'parent_runtime' => 'Atlas Autonomous Software Company Runtime',
            'canonical_statement' => 'Atlas Software Company Stewardship Stack é stack/capability family dentro do Atlas Autonomous Software Company Runtime, não OS novo.',
            'aps' => ['AP-712', 'AP-715', 'AP-717', 'AP-718', 'AP-719'],
            'new_os_created' => false,
            'parallel_runtime_created' => false,
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function reusedOwners(): array
    {
        return [
            'finding_engine' => [
                'ap' => 'AP-717',
                'owner_service' => AgenticEngineeringOsFindingEngineService::class,
                'reused_signal' => 'route_hint',
                'role' => 'route classification anchored here, not re-derived',
            ],
            'self_directed_evolution' => [
                'owner_doc' => 'docs/engineering-knowledge-base/atlas-self-directed-evolution-layer.md',
                'role' => 'owns gap/spec proposal; router only routes, never drafts',
            ],
            'atlas_dev' => [
                'owner_doc' => 'docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md',
                'role' => 'execution owner for small/local work; router emits work orders only',
            ],
            'forge' => [
                'owner_doc' => 'docs/engineering-knowledge-base/atlas-forge-operating-system.md',
                'role' => 'execution owner for long-horizon work; router emits work orders only',
            ],
        ];
    }

    /**
     * @return array<string,bool>
     */
    private function claimPolicy(): array
    {
        return [
            'read_only' => true,
            'writes_state' => false,
            'persists_registry' => false,
            'parallel_runtime_created' => false,
            'parallel_classifier_created' => false,
            'new_os_created' => false,
            'provider_invoked' => false,
            'drafts_spec' => false,
            'dev_invoked' => false,
            'forge_invoked' => false,
            'work_dispatched' => false,
            'execution_performed' => false,
            'creates_branch' => false,
            'merge_without_operator' => false,
            'deploy_without_operator' => false,
            'secret_access' => false,
            'destructive_change' => false,
            'autoapproval_allowed' => false,
            'external_side_effect_allowed' => false,
            'requires_operator_review' => true,
            'reuses_finding_route_classification' => true,
        ];
    }

    // ---------- blocked envelope + helpers ----------

    /**
     * @return array<string,mixed>
     */
    private function blockedEnvelope(string $areaId, string $reason, string $detail): array
    {
        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'status' => self::STATUS_BLOCKED,
            'ap_contract' => 'AP-719',
            'area_id' => $areaId,
            'reason' => $reason,
            'detail' => $detail,
            'stewardship_stack' => $this->stewardshipStack(),
            'work_order_count' => 0,
            'emitted_count' => 0,
            'blocked_count' => 0,
            'work_orders' => [],
            'reused_owners' => $this->reusedOwners(),
            'claim_policy' => $this->claimPolicy(),
        ];
        $payload['report_hash'] = 'sha256:'.MissionCanonicalHash::sha256($payload);
        $payload['generated_at'] = AreaFocusUtcClock::atomNow();

        return $payload;
    }

    /**
     * Deterministic allocation order: severity desc, priority desc, hash asc.
     *
     * @param  list<array<string,mixed>>  $sources
     * @return list<array<string,mixed>>
     */
    private function sortSources(array $sources): array
    {
        usort($sources, function (array $a, array $b): int {
            return ((self::SEVERITY_RANK[$b['severity']] ?? 0) <=> (self::SEVERITY_RANK[$a['severity']] ?? 0))
                ?: (((int) $b['priority_score']) <=> ((int) $a['priority_score']))
                ?: (((string) $a['source_ref']) <=> ((string) $b['source_ref']));
        });

        return array_values($sources);
    }

    /**
     * Normalize a budget/WIP input that may be an int, numeric string, or an
     * array carrying a numeric cap under one of $keys. Non-numeric (e.g.
     * `units => governed_capacity`) falls back to $default.
     *
     * @param  list<string>  $keys
     */
    private function normalizeBudget(mixed $value, int $default, array $keys): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }
        if (is_numeric($value)) {
            return max(0, (int) $value);
        }
        if (is_array($value)) {
            foreach ($keys as $key) {
                if (isset($value[$key]) && is_numeric($value[$key])) {
                    return max(0, (int) $value[$key]);
                }
            }
        }

        return $default;
    }

    /**
     * @return list<string>
     */
    private function sensitiveDomains(mixed $riskPolicy): array
    {
        if (! is_array($riskPolicy)) {
            return [];
        }
        $domains = [];
        foreach (['sensitive_domains', 'inbox_only_domains', 'inbox_only_for_sensitive_domains'] as $key) {
            foreach ((array) ($riskPolicy[$key] ?? []) as $d) {
                if (is_string($d) && $d !== '') {
                    $domains[] = $d;
                }
            }
        }

        return AreaFocusStringListNormalizer::uniqueStringValues($domains);
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function validateTheRoutingDecisionRationaleInput(array $input): void
    {
        if ($input === []) {
            return;
        }

        $allowedKeys = [
            'area_id',
            'focus',
            'owner',
            'risk',
            'risk_level',
            'severity',
            'authority_available',
            'route',
            'route_hint',
        ];
        foreach (array_keys($input) as $key) {
            if (! in_array($key, $allowedKeys, true)) {
                throw new \InvalidArgumentException("Unknown routing decision rationale input key: {$key}");
            }
        }

        foreach (['area_id', 'focus', 'owner', 'risk', 'risk_level', 'severity', 'route', 'route_hint'] as $key) {
            if (array_key_exists($key, $input) && ! is_string($input[$key])) {
                throw new \InvalidArgumentException("{$key} must be a string.");
            }
        }

        if (array_key_exists('authority_available', $input) && ! is_bool($input['authority_available'])) {
            throw new \InvalidArgumentException('authority_available must be a boolean.');
        }
    }
}
