<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Models\AtlasProject;
use App\Services\Ai\ExecutionAuthority\ForgeProviderTopologyPort;
use App\Services\Ai\Programming\ForgeTopology\ForgeTopologyCapacityAlignmentValidator;
use App\Services\Ai\Programming\ForgeTopology\ForgeTopologyFallbackChainCoherenceValidator;
use App\Services\Ai\Programming\ForgeTopology\ForgeTopologyRoleCoverageValidator;
use App\Services\Ai\Programming\ForgeTopology\ForgeTopologyRoleRedundancyValidator;
use App\Services\Ai\Support\AiValueNormalizer;
use Illuminate\Support\Str;

/**
 * Atlas Forge Provider Topology read-model.
 *
 * Materializes the canonical role assignment, model/provider selection, risk
 * fit and fallback chain that Atlas Decide produces for every heavy Forge run.
 *
 * THIS SERVICE IS A READ MODEL ONLY. It never calls an external provider, never
 * spends tokens and never mutates Obra state. Defaults come from local policy
 * registered here and live next to the Decision Receipt contract — concrete
 * runtime topology must still be persisted by Atlas Decide on dispatch.
 *
 * Schema: atlas.forge.provider_topology.v1
 * Doc: docs/engineering-knowledge-base/atlas-forge-provider-topology-and-fallback-v1.md
 *
 * Canonical roles (see atlas-forge-continuum-os.md):
 *   - primary_builder    · one-shot implementation, hardest work
 *   - critical_reviewer  · review, tests, regression, contract reasoning
 *   - context_scout      · long context, RAG, docs, code intelligence
 *   - repair_agent       · failure packets, minimal patches, retest
 *   - local_tool_runner  · local tooling: lint, test, graph, evidence
 */
class AtlasForgeProviderTopologyService implements ForgeProviderTopologyPort
{
    public const SCHEMA_VERSION = 'atlas.forge.provider_topology.v1';

    public const ROLE_PRIMARY_BUILDER = 'primary_builder';

    public const ROLE_CRITICAL_REVIEWER = 'critical_reviewer';

    public const ROLE_CONTEXT_SCOUT = 'context_scout';

    public const ROLE_REPAIR_AGENT = 'repair_agent';

    public const ROLE_LOCAL_TOOL_RUNNER = 'local_tool_runner';

    /** @var list<string> */
    public const CANONICAL_ROLES = [
        self::ROLE_PRIMARY_BUILDER,
        self::ROLE_CRITICAL_REVIEWER,
        self::ROLE_CONTEXT_SCOUT,
        self::ROLE_REPAIR_AGENT,
        self::ROLE_LOCAL_TOOL_RUNNER,
    ];

    public const STRATEGY_ONE_SHOT_ENTERPRISE = 'one_shot_enterprise_default';

    public const STATUS_SELECTED = 'selected';

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_UNAVAILABLE = 'unavailable';

    public const STATUS_FALLBACK_SELECTED = 'fallback_selected';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_NOT_REQUIRED = 'not_required';

    public function __construct(
        private readonly AtlasForgeProviderFallbackPolicyService $fallbackPolicy,
        private readonly AtlasForgeProviderCapacityService $capacityService,
    ) {}

    /**
     * Produce the canonical provider topology read-model.
     *
     * @param  array<string,mixed>  $options  obra_id, decision_receipt, fast_path_run_id, decision_receipt_id, strategy, simulate_provider_failure
     * @return array<string,mixed>
     */
    public function topology(array $options = []): array
    {
        $obraId = AiValueNormalizer::trimmedStringOrNull($options['obra_id'] ?? null);
        $project = $obraId !== null
            ? AtlasProject::query()->whereKey($obraId)->first()
            : null;

        $obraExists = $project !== null;
        $receiptTopology = $this->receiptTopology($options, $project);
        $strategy = AiValueNormalizer::trimmedStringOrNull($options['strategy'] ?? null)
            ?? AiValueNormalizer::trimmedStringOrNull(data_get($receiptTopology, 'strategy'))
            ?? self::STRATEGY_ONE_SHOT_ENTERPRISE;
        $fastPathRunId = AiValueNormalizer::trimmedStringOrNull($options['fast_path_run_id'] ?? null);
        $decisionReceiptId = AiValueNormalizer::trimmedStringOrNull($options['decision_receipt_id'] ?? null)
            ?? AiValueNormalizer::trimmedStringOrNull(data_get($receiptTopology, 'decision_receipt_id'));

        $topologyId = AiValueNormalizer::trimmedStringOrNull($options['provider_topology_id'] ?? null)
            ?? AiValueNormalizer::trimmedStringOrNull(data_get($receiptTopology, 'provider_topology_id'))
            ?? 'topo_'.(string) Str::ulid();

        $defaultRoles = $receiptTopology !== null ? $this->rolesFromReceiptTopology($receiptTopology) : $this->defaultRoles();
        $fallbackChain = $receiptTopology !== null ? $this->fallbackChainFromReceiptTopology($receiptTopology) : $this->defaultFallbackChain();

        // Capacity is sourced from AtlasForgeProviderCapacityService — local
        // signals only, never a provider call. This replaces both the static
        // default and any receipt-projected stub so the topology reflects the
        // real local availability of each provider runtime.
        $capacitySnapshot = $this->capacityService->snapshot([
            'obra_id' => $obraExists ? $obraId : null,
        ]);
        $providerCapacity = is_array($capacitySnapshot['providers'] ?? null)
            ? $capacitySnapshot['providers']
            : $this->defaultProviderCapacity();
        $capacityByProvider = [];
        foreach ($providerCapacity as $entry) {
            if (is_array($entry) && is_string($entry['provider'] ?? null)) {
                $capacityByProvider[(string) $entry['provider']] = $entry;
            }
        }
        $capacityExhausted = (string) ($capacitySnapshot['status'] ?? '') === AtlasForgeProviderCapacityService::TOP_STATUS_BLOCKED;
        $capacityRuntimeAllowed = (bool) ($capacitySnapshot['runtime_dispatch_allowed'] ?? false);

        // Roles reflect capacity: unavailable provider → role unavailable;
        // degraded provider → role degraded (when not already selected).
        // Provider field may be a vendor name (anthropic/openai/google) or a
        // capacity runtime key (claude_cli/codex_cli/...). Resolve via helper.
        if ($obraExists) {
            foreach ($defaultRoles as $idx => $role) {
                $vendor = AiValueNormalizer::trimmedStringOrNull($role['provider'] ?? null);
                $model = AiValueNormalizer::trimmedStringOrNull($role['model'] ?? null);
                $runtimeKey = $this->capacityRuntimeKeyFor($vendor, $model);
                if ($runtimeKey === null) {
                    continue;
                }
                $capacityEntry = $capacityByProvider[$runtimeKey] ?? null;
                if ($capacityEntry === null) {
                    continue;
                }
                $providerStatus = (string) ($capacityEntry['status'] ?? '');
                if ($providerStatus === AtlasForgeProviderCapacityService::STATUS_UNAVAILABLE) {
                    $defaultRoles[$idx]['status'] = self::STATUS_UNAVAILABLE;
                    $defaultRoles[$idx]['capability_reason'] = 'Runtime '.$runtimeKey.' is unavailable per capacity snapshot.';
                } elseif ($providerStatus === AtlasForgeProviderCapacityService::STATUS_DEGRADED
                    && in_array((string) ($defaultRoles[$idx]['status'] ?? ''), [self::STATUS_AVAILABLE, self::STATUS_SELECTED], true)
                ) {
                    $defaultRoles[$idx]['capability_reason'] = 'Runtime '.$runtimeKey.' is degraded per capacity snapshot.';
                }
                $defaultRoles[$idx]['runtime_key'] = $runtimeKey;
            }
        }

        // Fallback chain capable flag must consider capacity for the entry's
        // provider/model — if the runtime is unavailable, capable becomes
        // false even if the static chain marked it as capable.
        foreach ($fallbackChain as $idx => $entry) {
            $vendor = is_array($entry) ? AiValueNormalizer::trimmedStringOrNull($entry['provider'] ?? null) : null;
            $model = is_array($entry) ? AiValueNormalizer::trimmedStringOrNull($entry['model'] ?? null) : null;
            $runtimeKey = $this->capacityRuntimeKeyFor($vendor, $model);
            if ($runtimeKey === null) {
                continue;
            }
            $capacityEntry = $capacityByProvider[$runtimeKey] ?? null;
            if ($capacityEntry === null) {
                continue;
            }
            $providerStatus = (string) ($capacityEntry['status'] ?? '');
            if ($providerStatus === AtlasForgeProviderCapacityService::STATUS_UNAVAILABLE) {
                $fallbackChain[$idx]['capable'] = false;
                $fallbackChain[$idx]['reason'] = ($entry['reason'] ?? '').' [capacity_unavailable]';
            }
            $fallbackChain[$idx]['runtime_key'] = $runtimeKey;
        }
        $decisionSource = AiValueNormalizer::trimmedStringOrNull(data_get($receiptTopology, 'decision_source')) ?? 'static_policy';
        $decisionReceiptHash = AiValueNormalizer::trimmedStringOrNull(data_get($receiptTopology, 'decision_receipt_hash'));
        $receiptSchemaVersion = AiValueNormalizer::trimmedStringOrNull(data_get($receiptTopology, 'receipt_schema_version'));
        $fallbackChildReceiptRequired = (bool) data_get($receiptTopology, 'fallback_child_receipt_required', false);
        $runtimeDispatchAllowed = $receiptTopology !== null
            ? (bool) data_get($receiptTopology, 'runtime_dispatch_allowed', false)
            : false;

        $blockers = [];
        $evidenceRefs = [
            'docs/engineering-knowledge-base/atlas-forge-continuum-os.md',
            'docs/engineering-knowledge-base/atlas-forge-provider-topology-and-fallback-v1.md',
            'docs/engineering-knowledge-base/system-graph/atlas-decide.md',
        ];

        if (! $obraExists) {
            $blockers[] = 'obra_required';
            foreach ($defaultRoles as $idx => $role) {
                $defaultRoles[$idx]['status'] = self::STATUS_BLOCKED;
                $defaultRoles[$idx]['capability_reason'] = 'Obra not bound; topology is fail-closed until an Obra is selected.';
            }
        }

        // Capacity exhausted at the snapshot level becomes a topology-level
        // honest blocker, even before any failure simulation. Atlas Decide can
        // re-emit a Decision Receipt later when capacity recovers.
        if ($obraExists && $capacityExhausted) {
            if (! in_array(AtlasForgeProviderFallbackPolicyService::BLOCKER_CAPACITY_EXHAUSTED, $blockers, true)) {
                $blockers[] = AtlasForgeProviderFallbackPolicyService::BLOCKER_CAPACITY_EXHAUSTED;
            }
            $runtimeDispatchAllowed = false;
            foreach ($defaultRoles as $idx => $role) {
                if (($role['status'] ?? null) === self::STATUS_AVAILABLE
                    || ($role['status'] ?? null) === self::STATUS_SELECTED
                ) {
                    $defaultRoles[$idx]['status'] = self::STATUS_BLOCKED;
                    $defaultRoles[$idx]['capability_reason'] = 'No capable provider available — capacity snapshot reports exhausted.';
                }
            }
        }
        if ($obraExists && ! $capacityRuntimeAllowed) {
            $runtimeDispatchAllowed = false;
        }

        $fallbackEvent = null;
        $simulate = AiValueNormalizer::trimmedStringOrNull($options['simulate_provider_failure'] ?? null);

        if ($simulate !== null) {
            $primary = $defaultRoles[0] ?? null;
            $failure = [
                'type' => $simulate,
                'role' => $primary['role'] ?? self::ROLE_PRIMARY_BUILDER,
                'provider' => $primary['provider'] ?? null,
                'model' => $primary['model'] ?? null,
                'reason' => 'Simulated by atlas:forge:continuum-certify --simulate-provider-failure='.$simulate,
            ];
            $classification = $this->fallbackPolicy->classify($failure, [
                'provider_topology_id' => $topologyId,
                'obra_id' => $obraId,
                'strategy' => $strategy,
                'roles' => $defaultRoles,
                'fallback_chain' => $fallbackChain,
                'capacity_snapshot_id' => $capacitySnapshot['snapshot_id'] ?? null,
                'decision_receipt_id' => $decisionReceiptId,
            ]);
            $fallbackEvent = $classification['event'];

            if ($classification['action'] === AtlasForgeProviderFallbackPolicyService::ACTION_BLOCK) {
                $blocker = (string) ($classification['blocker'] ?? AtlasForgeProviderFallbackPolicyService::BLOCKER_CAPACITY_EXHAUSTED);
                if (! in_array($blocker, $blockers, true)) {
                    $blockers[] = $blocker;
                }
                if (isset($defaultRoles[0])) {
                    $defaultRoles[0]['status'] = self::STATUS_BLOCKED;
                    $defaultRoles[0]['capability_reason'] = 'Provider failure '.$simulate.' produced honest blocker '.$blocker.'.';
                }
            } elseif ($classification['action'] === AtlasForgeProviderFallbackPolicyService::ACTION_REROUTE) {
                $fallbackChildReceiptRequired = true;
                $runtimeDispatchAllowed = false;
                $selected = $classification['selected_fallback'] ?? null;
                if (isset($defaultRoles[0])) {
                    $defaultRoles[0]['status'] = self::STATUS_UNAVAILABLE;
                    $defaultRoles[0]['capability_reason'] = 'Provider failure '.$simulate.'; rerouted to fallback role.';
                }
                if ($selected !== null) {
                    $fallbackRole = (string) ($selected['role'] ?? self::ROLE_CRITICAL_REVIEWER);
                    foreach ($defaultRoles as $idx => $entry) {
                        if (($entry['role'] ?? null) === $fallbackRole) {
                            $defaultRoles[$idx]['status'] = self::STATUS_FALLBACK_SELECTED;
                            $defaultRoles[$idx]['capability_reason'] = 'Selected as governed fallback after '.$simulate.' on primary_builder.';
                        }
                    }
                }
            } elseif ($classification['action'] === AtlasForgeProviderFallbackPolicyService::ACTION_RETRY_LATER) {
                if (isset($defaultRoles[0])) {
                    $defaultRoles[0]['status'] = self::STATUS_UNAVAILABLE;
                    $defaultRoles[0]['capability_reason'] = 'Provider failure '.$simulate.'; same role can retry later under governed backoff.';
                }
            }
        }

        $status = $this->resolveStatus($obraExists, $blockers, $fallbackEvent);

        $nextAction = match (true) {
            ! $obraExists => 'bind_obra_to_atlas_code_forge',
            in_array(AtlasForgeProviderFallbackPolicyService::BLOCKER_CAPACITY_EXHAUSTED, $blockers, true) => 'wait_for_provider_capacity_or_change_strategy',
            $fallbackEvent !== null && ($fallbackEvent['action'] ?? null) === AtlasForgeProviderFallbackPolicyService::ACTION_REROUTE => 'dispatch_fallback_role_with_governed_receipt',
            $fallbackEvent !== null && ($fallbackEvent['action'] ?? null) === AtlasForgeProviderFallbackPolicyService::ACTION_RETRY_LATER => 'retry_primary_builder_under_governed_backoff',
            default => 'open_atlas_decide_receipt_then_dispatch_primary_builder',
        };

        // Observe-only validation read-model (Obra #7 W2): computed from the
        // arrays already materialized above; never mutates status/blockers.
        // Fail-open per field: a validator error yields an explicit null.
        $topologyValidation = [
            'role_coverage' => $this->observedValidation(
                fn (): array => (new ForgeTopologyRoleCoverageValidator)->inspect($defaultRoles),
            ),
            'fallback_chain_coherence' => $this->observedValidation(
                fn (): array => (new ForgeTopologyFallbackChainCoherenceValidator)->inspect($fallbackChain),
            ),
            'capacity_alignment' => $this->observedValidation(
                fn (): array => (new ForgeTopologyCapacityAlignmentValidator)->inspect($defaultRoles, $providerCapacity),
            ),
            'role_redundancy' => $this->observedValidation(
                fn (): array => (new ForgeTopologyRoleRedundancyValidator)->inspect(
                    array_column($defaultRoles, null, 'role'),
                ),
            ),
        ];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'obra_id' => $obraId,
            'obra_present' => $obraExists,
            'fast_path_run_id' => $fastPathRunId,
            'decision_receipt_id' => $decisionReceiptId,
            'decision_receipt_hash' => $decisionReceiptHash,
            'receipt_schema_version' => $receiptSchemaVersion,
            'provider_topology_id' => $topologyId,
            'generated_at' => now()->toIso8601String(),
            'strategy' => $strategy,
            'decision_source' => $decisionSource,
            'roles' => $defaultRoles,
            'fallback_chain' => $fallbackChain,
            'provider_capacity' => $providerCapacity,
            'blockers' => array_values(array_unique($blockers)),
            'topology_validation' => $topologyValidation,
            'last_fallback_event' => $fallbackEvent,
            'fallback_child_receipt_required' => $fallbackChildReceiptRequired,
            'runtime_dispatch_allowed' => $runtimeDispatchAllowed && $blockers === [],
            'evidence_refs' => $evidenceRefs,
            'next_action' => $nextAction,
            'capacity_snapshot_id' => $capacitySnapshot['snapshot_id'] ?? null,
            'capacity_summary' => [
                'status' => $capacitySnapshot['status'] ?? null,
                'best_available_provider' => $capacitySnapshot['best_available_provider'] ?? null,
                'available_count' => $capacitySnapshot['available_count'] ?? null,
                'degraded_count' => $capacitySnapshot['degraded_count'] ?? null,
                'unavailable_count' => $capacitySnapshot['unavailable_count'] ?? null,
                'unknown_count' => $capacitySnapshot['unknown_count'] ?? null,
                'runtime_dispatch_allowed' => $capacitySnapshot['runtime_dispatch_allowed'] ?? false,
            ],
            'external_provider_call' => false,
            'is_read_model' => true,
            'note' => $receiptTopology !== null
                ? 'Provider Topology projetada de Decision Receipt real do Atlas Decide; nenhum provider externo foi chamado.'
                : 'Provider Topology em static_policy: read-model de certificacao sem Decision Receipt runtime.',
        ];
    }

    /**
     * Fail-open wrapper for observe-only validators: an error in a validator
     * never breaks the read-model — the field becomes an explicit null.
     *
     * @param  callable(): array<string,mixed>  $inspect
     * @return array<string,mixed>|null
     */
    private function observedValidation(callable $inspect): ?array
    {
        try {
            return $inspect();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>|null
     */
    private function receiptTopology(array $options, ?AtlasProject $project): ?array
    {
        $explicit = $options['decision_receipt'] ?? null;
        if (is_array($explicit)) {
            $topology = data_get($explicit, 'forge_provider_topology');
            if (is_array($topology)) {
                return $topology;
            }
            $metadataTopology = data_get($explicit, 'metadata.forge_provider_topology');
            if (is_array($metadataTopology)) {
                return $metadataTopology;
            }
        }

        if ($project) {
            $latest = data_get($project->metadata, 'latest_atlas_forge_provider_topology');
            if (is_array($latest)) {
                return $latest;
            }
            $latestReceiptTopology = data_get($project->metadata, 'latest_atlas_forge_decision_receipt.forge_provider_topology');
            if (is_array($latestReceiptTopology)) {
                return $latestReceiptTopology;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $topology
     * @return array<int,array<string,mixed>>
     */
    private function rolesFromReceiptTopology(array $topology): array
    {
        $roles = data_get($topology, 'roles');
        if (! is_array($roles) || $roles === []) {
            return $this->defaultRoles();
        }

        return array_values(array_map(function (mixed $role): array {
            $role = is_array($role) ? $role : [];

            return array_merge([
                'role' => 'unknown',
                'provider' => null,
                'model' => null,
                'status' => self::STATUS_AVAILABLE,
                'capability_reason' => 'Projected from Atlas Decide Decision Receipt.',
                'risk_fit' => 'medium',
                'quality_role' => 'receipt_projected',
                'autonomy_level' => 'governed',
                'fallback_order' => 99,
                'requires_human_review' => true,
                'evidence_required' => true,
                'decision_source' => 'live_atlas_decide',
            ], $role);
        }, $roles));
    }

    /**
     * @param  array<string,mixed>  $topology
     * @return array<int,array<string,mixed>>
     */
    private function fallbackChainFromReceiptTopology(array $topology): array
    {
        $chain = data_get($topology, 'fallback_chain');
        if (! is_array($chain) || $chain === []) {
            return $this->defaultFallbackChain();
        }

        return array_values(array_map(fn (mixed $entry): array => is_array($entry) ? $entry : [], $chain));
    }

    /**
     * @param  array<string,mixed>  $topology
     * @return array<int,array<string,mixed>>
     */
    private function providerCapacityFromReceiptTopology(array $topology): array
    {
        $capacity = data_get($topology, 'provider_capacity');
        if (! is_array($capacity) || $capacity === []) {
            return $this->defaultProviderCapacity();
        }

        return array_values(array_map(fn (mixed $entry): array => is_array($entry) ? $entry : [], $capacity));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function defaultRoles(): array
    {
        return [
            [
                'role' => self::ROLE_PRIMARY_BUILDER,
                'provider' => 'claude_cli',
                'model' => 'claude-opus-4-7',
                'status' => self::STATUS_SELECTED,
                'capability_reason' => 'One-shot enterprise implementation forte com long-context para Forge pesado.',
                'risk_fit' => 'critical',
                'autonomy_level' => 'governed',
                'fallback_order' => 1,
                'requires_human_review' => true,
                'evidence_required' => true,
                'decision_source' => 'static_policy',
            ],
            [
                'role' => self::ROLE_CRITICAL_REVIEWER,
                'provider' => 'codex_cli',
                'model' => 'selected-by-decide',
                'status' => self::STATUS_AVAILABLE,
                'capability_reason' => 'Revisao critica, contratos e testes; perfil complementar ao primary.',
                'risk_fit' => 'high',
                'autonomy_level' => 'review_only',
                'fallback_order' => 2,
                'requires_human_review' => true,
                'evidence_required' => true,
                'decision_source' => 'static_policy',
            ],
            [
                'role' => self::ROLE_CONTEXT_SCOUT,
                'provider' => 'gemini_cli',
                'model' => 'selected-by-decide',
                'status' => self::STATUS_AVAILABLE,
                'capability_reason' => 'Context window grande para docs/code intelligence/RAG enterprise.',
                'risk_fit' => 'medium',
                'autonomy_level' => 'read_only',
                'fallback_order' => 3,
                'requires_human_review' => false,
                'evidence_required' => true,
                'decision_source' => 'static_policy',
            ],
            [
                'role' => self::ROLE_REPAIR_AGENT,
                'provider' => 'claude_cli',
                'model' => 'selected-by-decide',
                'status' => self::STATUS_AVAILABLE,
                'capability_reason' => 'Patch minimo guiado por failure packet com latencia baixa.',
                'risk_fit' => 'medium',
                'autonomy_level' => 'governed',
                'fallback_order' => 4,
                'requires_human_review' => true,
                'evidence_required' => true,
                'decision_source' => 'static_policy',
            ],
            [
                'role' => self::ROLE_LOCAL_TOOL_RUNNER,
                'provider' => 'atlas-local',
                'model' => 'atlas-runtime',
                'status' => self::STATUS_AVAILABLE,
                'capability_reason' => 'Lint, test, graph e evidence locais — nunca chama provider externo.',
                'risk_fit' => 'low',
                'autonomy_level' => 'local_only',
                'fallback_order' => 5,
                'requires_human_review' => false,
                'evidence_required' => true,
                'decision_source' => 'static_policy',
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function defaultFallbackChain(): array
    {
        return [
            [
                'order' => 1,
                'role' => self::ROLE_CRITICAL_REVIEWER,
                'provider' => 'codex_cli',
                'model' => 'selected-by-decide',
                'capable' => true,
                'reason' => 'Critic reviewer pode atuar como builder secundario se primary falhar.',
            ],
            [
                'order' => 2,
                'role' => self::ROLE_CONTEXT_SCOUT,
                'provider' => 'gemini_cli',
                'model' => 'selected-by-decide',
                'capable' => true,
                'reason' => 'Context scout assume sob long-context quando primary atinge context_limit.',
            ],
            [
                'order' => 3,
                'role' => self::ROLE_REPAIR_AGENT,
                'provider' => 'claude_cli',
                'model' => 'selected-by-decide',
                'capable' => false,
                'reason' => 'Repair agent nao assume primary; somente patch focado.',
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function defaultProviderCapacity(): array
    {
        return [
            [
                'provider' => 'claude_cli',
                'capacity_state' => 'available',
                'quota_state' => 'unknown',
                'rate_limit_state' => 'unknown',
            ],
            [
                'provider' => 'codex_cli',
                'capacity_state' => 'available',
                'quota_state' => 'unknown',
                'rate_limit_state' => 'unknown',
            ],
            [
                'provider' => 'gemini_cli',
                'capacity_state' => 'available',
                'quota_state' => 'unknown',
                'rate_limit_state' => 'unknown',
            ],
            [
                'provider' => 'atlas-local',
                'capacity_state' => 'available',
                'quota_state' => 'not_applicable',
                'rate_limit_state' => 'not_applicable',
            ],
        ];
    }

    /**
     * @param  array<int,string>  $blockers
     * @param  array<string,mixed>|null  $fallbackEvent
     */
    private function resolveStatus(bool $obraExists, array $blockers, ?array $fallbackEvent): string
    {
        if (! $obraExists) {
            return 'blocked_obra_required';
        }

        if (in_array(AtlasForgeProviderFallbackPolicyService::BLOCKER_CAPACITY_EXHAUSTED, $blockers, true)) {
            return AtlasForgeProviderFallbackPolicyService::BLOCKER_CAPACITY_EXHAUSTED;
        }

        if ($blockers !== []) {
            return 'blocked';
        }

        if ($fallbackEvent !== null) {
            return match ((string) ($fallbackEvent['action'] ?? '')) {
                AtlasForgeProviderFallbackPolicyService::ACTION_REROUTE => 'rerouted',
                AtlasForgeProviderFallbackPolicyService::ACTION_RETRY_LATER => 'retry_later',
                AtlasForgeProviderFallbackPolicyService::ACTION_BLOCK => 'blocked',
                default => 'available',
            };
        }

        return 'available';
    }

    /**
     * Map a (vendor, model) tuple to the canonical capacity runtime key.
     * Roles defined in the topology may use vendor names (e.g. "anthropic")
     * while the capacity service tracks runtimes (e.g. "claude_cli"). This
     * helper bridges the two without forcing either side to rename.
     */
    public function capacityRuntimeKeyFor(?string $vendor, ?string $model): ?string
    {
        if ($vendor === null && $model === null) {
            return null;
        }

        if ($vendor !== null && in_array(
            $vendor,
            AtlasForgeProviderCapacityService::CANONICAL_PROVIDERS,
            true,
        )) {
            return $vendor;
        }

        $modelLower = $model !== null ? strtolower($model) : '';
        $vendorLower = $vendor !== null ? strtolower($vendor) : '';

        if ($vendorLower === 'anthropic' || str_contains($modelLower, 'claude')) {
            return AtlasForgeProviderCapacityService::PROVIDER_CLAUDE_CLI;
        }
        if ($vendorLower === 'openai' || str_contains($modelLower, 'gpt') || str_contains($modelLower, 'codex')) {
            return AtlasForgeProviderCapacityService::PROVIDER_CODEX_CLI;
        }
        if ($vendorLower === 'google' || str_contains($modelLower, 'gemini')) {
            return AtlasForgeProviderCapacityService::PROVIDER_GEMINI_CLI;
        }
        if ($vendorLower === 'atlas-local' || $vendorLower === 'atlas_local' || str_contains($modelLower, 'atlas')) {
            return AtlasForgeProviderCapacityService::PROVIDER_ATLAS_LOCAL;
        }

        return null;
    }
}
