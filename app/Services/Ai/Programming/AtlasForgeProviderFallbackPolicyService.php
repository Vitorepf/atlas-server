<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Models\AtlasProject;
use Illuminate\Support\Str;
use Throwable;

/**
 * Atlas Forge Provider Fallback Policy.
 *
 * Classifies provider failures and decides governed fallback actions for the
 * Atlas Forge Continuum OS. Never silent: every decision produces an evidence
 * event payload that the certification block, the state projection and the
 * desktop cockpit can render.
 *
 * Decision space:
 *   - reroute       · pick the next fallback role/provider that can take over.
 *   - retry_later   · same provider/model can recover; defer dispatch.
 *   - block         · no provider capable; fail-closed with explicit reason.
 *
 * Schemas:
 *   - atlas.forge.provider_fallback_policy.v1   · policy read-model
 *   - atlas.forge.provider_fallback_event.v1    · evidence event payload
 *
 * Hard rules (validated by certification + tests):
 *   - fallback is NEVER silent (always emits event + receipt payload);
 *   - fallback NEVER reduces quality gates;
 *   - fallback NEVER auto-completes work;
 *   - fallback NEVER bypasses human review when contract requires it;
 *   - when no capable provider is available, the policy MUST emit
 *     `provider_capacity_exhausted` as a hard blocker.
 *
 * Doc: docs/engineering-knowledge-base/atlas-forge-provider-topology-and-fallback-v1.md
 */
class AtlasForgeProviderFallbackPolicyService
{
    public const SCHEMA_VERSION = 'atlas.forge.provider_fallback_policy.v1';
    public const EVENT_SCHEMA_VERSION = 'atlas.forge.provider_fallback_event.v1';

    public const ACTION_REROUTE = 'reroute';
    public const ACTION_RETRY_LATER = 'retry_later';
    public const ACTION_BLOCK = 'block';

    public const FAILURE_RATE_LIMIT = 'rate_limit';
    public const FAILURE_QUOTA_EXHAUSTED = 'quota_exhausted';
    public const FAILURE_AUTH_FAILED = 'auth_failed';
    public const FAILURE_TIMEOUT = 'timeout';
    public const FAILURE_CONTEXT_LIMIT = 'context_limit';
    public const FAILURE_MODEL_UNAVAILABLE = 'model_unavailable';
    public const FAILURE_PROVIDER_ERROR = 'provider_error';
    public const FAILURE_INSUFFICIENT_CAPABILITY = 'insufficient_capability';
    public const FAILURE_CAPACITY_EXHAUSTED = 'provider_capacity_exhausted';

    /** @var list<string> */
    public const KNOWN_FAILURES = [
        self::FAILURE_RATE_LIMIT,
        self::FAILURE_QUOTA_EXHAUSTED,
        self::FAILURE_AUTH_FAILED,
        self::FAILURE_TIMEOUT,
        self::FAILURE_CONTEXT_LIMIT,
        self::FAILURE_MODEL_UNAVAILABLE,
        self::FAILURE_PROVIDER_ERROR,
        self::FAILURE_INSUFFICIENT_CAPABILITY,
        self::FAILURE_CAPACITY_EXHAUSTED,
    ];

    public const BLOCKER_CAPACITY_EXHAUSTED = self::FAILURE_CAPACITY_EXHAUSTED;
    public const BLOCKER_AUTH_FAILED = 'auth_failed_no_credential_rotation_available';
    public const BLOCKER_INSUFFICIENT_CAPABILITY = 'no_capable_provider_for_role';
    public const BLOCKER_UNKNOWN_FAILURE = 'unknown_provider_failure_type';

    public function __construct(
        private readonly AtlasForgeProviderFailureMemoryService $failureMemory,
        private readonly AtlasForgeProviderCapacityService $capacityService,
    ) {}

    /**
     * Read-model snapshot of the policy. Pure data; no provider call.
     *
     * @return array<string,mixed>
     */
    public function policy(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'event_schema_version' => self::EVENT_SCHEMA_VERSION,
            'known_failures' => self::KNOWN_FAILURES,
            'actions' => [
                self::ACTION_REROUTE,
                self::ACTION_RETRY_LATER,
                self::ACTION_BLOCK,
            ],
            'invariants' => [
                'no_silent_fallback' => true,
                'fallback_emits_evidence_event' => true,
                'fallback_does_not_reduce_quality_gates' => true,
                'fallback_does_not_auto_complete_work' => true,
                'fallback_does_not_bypass_review_completion_gate' => true,
                'capacity_exhausted_is_hard_blocker' => true,
                'unknown_failure_blocks_fail_closed' => true,
            ],
            'failure_default_action' => [
                self::FAILURE_RATE_LIMIT => self::ACTION_REROUTE,
                self::FAILURE_QUOTA_EXHAUSTED => self::ACTION_REROUTE,
                self::FAILURE_AUTH_FAILED => self::ACTION_BLOCK,
                self::FAILURE_TIMEOUT => self::ACTION_RETRY_LATER,
                self::FAILURE_CONTEXT_LIMIT => self::ACTION_REROUTE,
                self::FAILURE_MODEL_UNAVAILABLE => self::ACTION_REROUTE,
                self::FAILURE_PROVIDER_ERROR => self::ACTION_RETRY_LATER,
                self::FAILURE_INSUFFICIENT_CAPABILITY => self::ACTION_REROUTE,
                self::FAILURE_CAPACITY_EXHAUSTED => self::ACTION_BLOCK,
            ],
        ];
    }

    /**
     * Classify a provider failure against the current topology and decide the
     * governed action. Always emits an evidence event payload — never silent.
     *
     * @param  array<string,mixed>  $failure   Provider failure context.
     * @param  array<string,mixed>  $topology  Provider topology read-model (atlas.forge.provider_topology.v1).
     * @return array<string,mixed>
     */
    public function classify(array $failure, array $topology): array
    {
        $failureType = $this->normalizeFailureType($failure['type'] ?? null);
        $failedRole = $this->stringOrNull($failure['role'] ?? null);
        $failedProvider = $this->stringOrNull($failure['provider'] ?? null);
        $failedModel = $this->stringOrNull($failure['model'] ?? null);
        $reason = $this->stringOrNull($failure['reason'] ?? null);
        $occurredAt = $this->stringOrNull($failure['occurred_at'] ?? null) ?? now()->toIso8601String();
        $eventId = $this->stringOrNull($failure['event_id'] ?? null) ?? (string) Str::ulid();

        $topologyId = $this->stringOrNull(data_get($topology, 'provider_topology_id'));
        $obraId = $this->stringOrNull(data_get($topology, 'obra_id'));
        $strategy = $this->stringOrNull(data_get($topology, 'strategy'));
        $fallbackChain = $this->normalizeFallbackChain($topology['fallback_chain'] ?? []);
        $roles = $this->normalizeRoles($topology['roles'] ?? []);

        $defaultAction = $this->policy()['failure_default_action'][$failureType] ?? self::ACTION_BLOCK;
        $action = $defaultAction;
        $blocker = null;
        $selectedFallback = null;

        if (! in_array($failureType, self::KNOWN_FAILURES, true)) {
            $action = self::ACTION_BLOCK;
            $blocker = self::BLOCKER_UNKNOWN_FAILURE;
        } elseif ($failureType === self::FAILURE_CAPACITY_EXHAUSTED) {
            $action = self::ACTION_BLOCK;
            $blocker = self::BLOCKER_CAPACITY_EXHAUSTED;
        } elseif ($action === self::ACTION_REROUTE || $action === self::ACTION_RETRY_LATER) {
            $candidate = $this->pickFallback($failedRole, $failedProvider, $failedModel, $fallbackChain, $roles);
            if ($candidate !== null) {
                $action = self::ACTION_REROUTE;
                $selectedFallback = $candidate;
            } elseif ($action === self::ACTION_REROUTE) {
                $action = self::ACTION_BLOCK;
                $blocker = self::BLOCKER_INSUFFICIENT_CAPABILITY;
            }
        } elseif ($action === self::ACTION_BLOCK) {
            $blocker = match ($failureType) {
                self::FAILURE_AUTH_FAILED => self::BLOCKER_AUTH_FAILED,
                default => self::BLOCKER_INSUFFICIENT_CAPABILITY,
            };
        }

        // CAPACITY EXHAUSTED is the canonical "no capable provider" honest blocker.
        if ($action === self::ACTION_BLOCK && $blocker === self::BLOCKER_INSUFFICIENT_CAPABILITY) {
            $blocker = self::BLOCKER_CAPACITY_EXHAUSTED;
        }

        $capacitySnapshotId = $this->stringOrNull(data_get($topology, 'capacity_snapshot_id'));
        [$providerStatusBefore, $providerStatusAfter] = $this->resolveProviderStatusTransition(
            $failedProvider,
            $action,
            $blocker,
            $failedModel,
        );
        $cooldownUntil = $this->resolveCooldownUntil($failureType, $occurredAt);

        $event = [
            'schema_version' => self::EVENT_SCHEMA_VERSION,
            'event_id' => $eventId,
            'occurred_at' => $occurredAt,
            'failure_type' => $failureType,
            'failed_role' => $failedRole,
            'failed_provider' => $failedProvider,
            'failed_model' => $failedModel,
            'reason' => $reason,
            'action' => $action,
            'selected_fallback_role' => $selectedFallback['role'] ?? null,
            'selected_fallback_provider' => $selectedFallback['provider'] ?? null,
            'selected_fallback_model' => $selectedFallback['model'] ?? null,
            'blocker' => $blocker,
            'silent' => false,
            'reduces_quality_gates' => false,
            'bypasses_review_completion_gate' => false,
            'auto_completes_work' => false,
            'fallback_child_receipt_required' => $action === self::ACTION_REROUTE,
            'runtime_dispatch_allowed' => $action !== self::ACTION_REROUTE && $action !== self::ACTION_BLOCK,
            'obra_id' => $obraId,
            'provider_topology_id' => $topologyId,
            'strategy' => $strategy,
            'capacity_snapshot_id' => $capacitySnapshotId,
            'provider_status_before' => $providerStatusBefore,
            'provider_status_after' => $providerStatusAfter,
            'cooldown_until' => $cooldownUntil,
            'failure_memory_event_id' => null,
            'failure_memory_recorded' => false,
        ];

        $memoryEvent = $this->maybeRecordFailureMemory($obraId, $event, $topology);
        if (is_array($memoryEvent)) {
            $event['failure_memory_event_id'] = $memoryEvent['event_id'] ?? null;
            $event['failure_memory_recorded'] = true;
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'action' => $action,
            'failure_type' => $failureType,
            'failure_recognized' => in_array($failureType, self::KNOWN_FAILURES, true),
            'failed_role' => $failedRole,
            'failed_provider' => $failedProvider,
            'failed_model' => $failedModel,
            'selected_fallback' => $selectedFallback,
            'blocker' => $blocker,
            'event' => $event,
            'invariants' => [
                'no_silent_fallback' => $event['silent'] === false,
                'fallback_does_not_reduce_quality_gates' => $event['reduces_quality_gates'] === false,
                'fallback_does_not_bypass_review_completion_gate' => $event['bypasses_review_completion_gate'] === false,
                'fallback_does_not_auto_complete_work' => $event['auto_completes_work'] === false,
                'fallback_child_receipt_required_before_reroute' => ($action !== self::ACTION_REROUTE) || $event['fallback_child_receipt_required'] === true,
                'capacity_exhausted_blocks_when_no_capable_provider' => ($action !== self::ACTION_BLOCK) || $blocker !== null,
            ],
        ];
    }

    /**
     * Resolve the provider status before and after applying this failure
     * decision. The "before" comes from the local capacity snapshot; the
     * "after" is the decision-derived state ("limited" for rate_limit,
     * "exhausted" for capacity_exhausted, etc.). Used by the failure memory
     * event for honest before/after auditing.
     *
     * @return array{0:?string, 1:?string}
     */
    private function resolveProviderStatusTransition(
        ?string $failedProvider,
        string $action,
        ?string $blocker,
        ?string $failedModel = null,
    ): array {
        $before = null;
        if ($failedProvider !== null) {
            try {
                $providers = $this->capacityService->providers();
                $runtimeKey = $this->resolveRuntimeKey($failedProvider, $failedModel);
                foreach ($providers as $entry) {
                    if (! is_array($entry)) {
                        continue;
                    }
                    $entryProvider = (string) ($entry['provider'] ?? '');
                    if ($entryProvider === $failedProvider || $entryProvider === $runtimeKey) {
                        $before = (string) ($entry['status'] ?? 'unknown');
                        break;
                    }
                }
            } catch (Throwable) {
                $before = null;
            }
        }

        $after = match ($action) {
            self::ACTION_BLOCK => $blocker === self::BLOCKER_CAPACITY_EXHAUSTED
                ? AtlasForgeProviderCapacityService::STATUS_UNAVAILABLE
                : AtlasForgeProviderCapacityService::STATUS_UNAVAILABLE,
            self::ACTION_REROUTE => AtlasForgeProviderCapacityService::STATUS_UNAVAILABLE,
            self::ACTION_RETRY_LATER => AtlasForgeProviderCapacityService::STATUS_DEGRADED,
            default => null,
        };

        return [$before, $after];
    }

    /**
     * Map (vendor, model) → canonical capacity runtime key. Mirrors
     * AtlasForgeProviderTopologyService::capacityRuntimeKeyFor — kept local
     * here to avoid forcing a circular dependency into the topology service.
     */
    private function resolveRuntimeKey(?string $vendor, ?string $model): ?string
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

        return match (true) {
            $vendorLower === 'anthropic' || str_contains($modelLower, 'claude') => AtlasForgeProviderCapacityService::PROVIDER_CLAUDE_CLI,
            $vendorLower === 'openai' || str_contains($modelLower, 'gpt') || str_contains($modelLower, 'codex') => AtlasForgeProviderCapacityService::PROVIDER_CODEX_CLI,
            $vendorLower === 'google' || str_contains($modelLower, 'gemini') => AtlasForgeProviderCapacityService::PROVIDER_GEMINI_CLI,
            $vendorLower === 'atlas-local' || $vendorLower === 'atlas_local' || str_contains($modelLower, 'atlas') => AtlasForgeProviderCapacityService::PROVIDER_ATLAS_LOCAL,
            default => null,
        };
    }

    /**
     * Cooldown lookup keyed by canonical failure type. Aligned with
     * AtlasForgeProviderFailureMemoryService::COOLDOWN_BY_FAILURE so policy
     * and memory always agree on the suggested cooldown window.
     */
    private function resolveCooldownUntil(string $failureType, string $occurredAtIso): ?string
    {
        $seconds = match ($failureType) {
            self::FAILURE_RATE_LIMIT => 60,
            self::FAILURE_QUOTA_EXHAUSTED => 600,
            self::FAILURE_TIMEOUT => 30,
            self::FAILURE_MODEL_UNAVAILABLE => 120,
            self::FAILURE_PROVIDER_ERROR => 30,
            self::FAILURE_CAPACITY_EXHAUSTED => 900,
            default => 0,
        };

        if ($seconds <= 0) {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($occurredAtIso)
                ->addSeconds($seconds)
                ->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Persist the failure to AtlasForgeProviderFailureMemoryService when an
     * Obra is bound. Best-effort: failure to record never silences the
     * decision — the policy still emits the canonical event payload.
     *
     * @param  array<string,mixed>  $event
     * @param  array<string,mixed>  $topology
     * @return array<string,mixed>|null
     */
    private function maybeRecordFailureMemory(
        ?string $obraId,
        array $event,
        array $topology,
    ): ?array {
        if ($obraId === null) {
            return null;
        }

        try {
            $project = AtlasProject::query()->whereKey($obraId)->first();
        } catch (Throwable) {
            return null;
        }

        if ($project === null) {
            return null;
        }

        try {
            return $this->failureMemory->record($project, [
                'provider' => (string) ($event['failed_provider'] ?? ''),
                'model' => $event['failed_model'] ?? null,
                'role' => $event['failed_role'] ?? null,
                'failure_type' => (string) ($event['failure_type'] ?? ''),
                'action' => $event['action'] ?? null,
                'blocker' => $event['blocker'] ?? null,
                'reason' => $event['reason'] ?? null,
                'fallback_event_id' => $event['event_id'] ?? null,
                'decision_receipt_id' => data_get($topology, 'decision_receipt_id'),
                'provider_topology_id' => $event['provider_topology_id'] ?? null,
                'capacity_snapshot_id' => $event['capacity_snapshot_id'] ?? null,
                'provider_status_before' => $event['provider_status_before'] ?? null,
                'provider_status_after' => $event['provider_status_after'] ?? null,
                'occurred_at' => $event['occurred_at'] ?? null,
            ]);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string,mixed>|null  $value
     */
    private function normalizeFailureType(mixed $value): string
    {
        $value = $this->stringOrNull($value);
        if ($value === null) {
            return self::FAILURE_PROVIDER_ERROR;
        }

        return in_array($value, self::KNOWN_FAILURES, true) ? $value : $value;
    }

    /**
     * @param  array<int,array<string,mixed>>|mixed  $chain
     * @return array<int,array<string,mixed>>
     */
    private function normalizeFallbackChain(mixed $chain): array
    {
        if (! is_array($chain)) {
            return [];
        }

        return array_values(array_filter(array_map(function (mixed $entry): ?array {
            if (! is_array($entry)) {
                return null;
            }
            $role = $this->stringOrNull($entry['role'] ?? null);
            $provider = $this->stringOrNull($entry['provider'] ?? null);
            $model = $this->stringOrNull($entry['model'] ?? null);
            $order = (int) ($entry['order'] ?? 0);
            $capable = ! array_key_exists('capable', $entry) || (bool) $entry['capable'];
            if ($role === null && $provider === null && $model === null) {
                return null;
            }

            return [
                'role' => $role,
                'provider' => $provider,
                'model' => $model,
                'order' => $order,
                'capable' => $capable,
            ];
        }, $chain), fn (?array $value): bool => $value !== null));
    }

    /**
     * @param  array<int,array<string,mixed>>|mixed  $roles
     * @return array<int,array<string,mixed>>
     */
    private function normalizeRoles(mixed $roles): array
    {
        if (! is_array($roles)) {
            return [];
        }

        return array_values(array_filter(array_map(function (mixed $entry): ?array {
            if (! is_array($entry)) {
                return null;
            }
            $role = $this->stringOrNull($entry['role'] ?? null);
            if ($role === null) {
                return null;
            }
            $provider = $this->stringOrNull($entry['provider'] ?? null);
            $model = $this->stringOrNull($entry['model'] ?? null);
            $status = $this->stringOrNull($entry['status'] ?? null) ?? 'available';

            return [
                'role' => $role,
                'provider' => $provider,
                'model' => $model,
                'status' => $status,
            ];
        }, $roles), fn (?array $value): bool => $value !== null));
    }

    /**
     * Pick the next capable fallback excluding the failed (role+provider+model)
     * tuple. Returns null when no capable fallback exists — caller must block.
     *
     * @param  array<int,array<string,mixed>>  $chain
     * @param  array<int,array<string,mixed>>  $roles
     * @return array<string,mixed>|null
     */
    private function pickFallback(
        ?string $failedRole,
        ?string $failedProvider,
        ?string $failedModel,
        array $chain,
        array $roles,
    ): ?array {
        $sorted = $chain;
        usort($sorted, static fn (array $a, array $b): int => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

        foreach ($sorted as $entry) {
            if (! ($entry['capable'] ?? true)) {
                continue;
            }
            if ($failedRole !== null && $entry['role'] === $failedRole
                && $entry['provider'] === $failedProvider
                && $entry['model'] === $failedModel) {
                continue;
            }
            if ($entry['provider'] === null && $entry['model'] === null) {
                continue;
            }

            return [
                'role' => $entry['role'] ?? $failedRole,
                'provider' => $entry['provider'],
                'model' => $entry['model'],
                'order' => $entry['order'] ?? 0,
                'source' => 'fallback_chain',
            ];
        }

        // No explicit fallback chain hit. Try other roles in the topology that
        // are available with a different provider/model from the failed tuple.
        foreach ($roles as $candidate) {
            if ($candidate['status'] !== 'available' && $candidate['status'] !== 'selected') {
                continue;
            }
            if ($candidate['provider'] === $failedProvider && $candidate['model'] === $failedModel) {
                continue;
            }
            if ($candidate['provider'] === null && $candidate['model'] === null) {
                continue;
            }
            // Only consider as fallback when the role differs OR provider/model differs.
            if ($candidate['role'] === $failedRole
                && $candidate['provider'] === $failedProvider
                && $candidate['model'] === $failedModel) {
                continue;
            }

            return [
                'role' => $candidate['role'],
                'provider' => $candidate['provider'],
                'model' => $candidate['model'],
                'order' => 0,
                'source' => 'topology_role',
            ];
        }

        return null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
