<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Models\AiProviderHealthSnapshot;
use App\Models\AiWorkerEvent;
use App\Models\AtlasProject;
use App\Services\Ai\AtlasAiRuntimeSettings;
use App\Services\Ai\Support\AiStringListNormalizer;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Atlas Forge Provider Capacity (read-model).
 *
 * Inspects only LOCAL signals (config presence, runtime binary presence,
 * cached health snapshots, worker events, ledger events, persisted failure
 * memory) to publish the canonical capacity contract for the 5 Forge runtime
 * providers:
 *
 *   - claude_cli
 *   - codex_cli
 *   - gemini_cli
 *   - claude_codex
 *   - atlas-local
 *
 * Hard rules enforced by this service:
 *   - NEVER calls an external provider;
 *   - NEVER spends a token;
 *   - NEVER mutates Obra state (read-only inspection);
 *   - When a signal is missing, reports `unknown` honestly — never `available`.
 *
 * Schema: atlas.forge.provider_capacity.v1
 * Doc: docs/engineering-knowledge-base/atlas-forge-provider-capacity-continuity-v1.md
 */
class AtlasForgeProviderCapacityService
{
    public const SCHEMA_VERSION = 'atlas.forge.provider_capacity.v1';
    public const ENTRY_SCHEMA_VERSION = 'atlas.forge.provider_capacity_entry.v1';

    public const PROVIDER_CLAUDE_CLI = 'claude_cli';
    public const PROVIDER_CODEX_CLI = 'codex_cli';
    public const PROVIDER_GEMINI_CLI = 'gemini_cli';
    public const PROVIDER_CLAUDE_CODEX = 'claude_codex';
    public const PROVIDER_ATLAS_LOCAL = 'atlas-local';

    /** @var list<string> */
    public const CANONICAL_PROVIDERS = [
        self::PROVIDER_CLAUDE_CLI,
        self::PROVIDER_CODEX_CLI,
        self::PROVIDER_GEMINI_CLI,
        self::PROVIDER_CLAUDE_CODEX,
        self::PROVIDER_ATLAS_LOCAL,
    ];

    public const STATUS_AVAILABLE = 'available';
    public const STATUS_DEGRADED = 'degraded';
    public const STATUS_UNAVAILABLE = 'unavailable';
    public const STATUS_UNKNOWN = 'unknown';

    public const TOP_STATUS_AVAILABLE = 'available';
    public const TOP_STATUS_DEGRADED = 'degraded';
    public const TOP_STATUS_BLOCKED = 'blocked';

    private const FAILURE_MEMORY_KEY = 'atlas_forge_provider_failure_memory';

    public function __construct(
        private readonly AtlasAiRuntimeSettings $runtimeSettings,
    ) {}

    /**
     * Build the canonical capacity snapshot.
     *
     * @param array{
     *     obra_id?: string|null,
     *     workspace?: string|null,
     *     now?: \DateTimeInterface|null,
     * } $options
     * @return array<string,mixed>
     */
    public function snapshot(array $options = []): array
    {
        $obraId = $this->stringOrNull($options['obra_id'] ?? null);
        $workspace = $this->stringOrNull($options['workspace'] ?? null) ?? base_path();
        $now = $this->resolveNow($options['now'] ?? null);

        $project = $obraId !== null ? $this->resolveObra($obraId) : null;
        $obraResolved = $project !== null;
        $obraResolutionStatus = match (true) {
            $obraId === null => 'not_required',
            $obraResolved => 'resolved',
            default => 'not_found',
        };

        $failureMemoryByProvider = $this->failureMemoryByProvider($project);
        $healthSnapshots = $this->latestHealthSnapshots();
        $workerEventsByProvider = $this->latestWorkerEventsByProvider($now);

        $providers = [];
        foreach (self::CANONICAL_PROVIDERS as $providerKey) {
            $providers[] = $this->buildEntry(
                providerKey: $providerKey,
                workspace: $workspace,
                healthSnapshot: $healthSnapshots[$providerKey] ?? null,
                workerEvent: $workerEventsByProvider[$providerKey] ?? null,
                failureMemory: $failureMemoryByProvider[$providerKey] ?? [],
                now: $now,
            );
        }

        $availableCount = 0;
        $degradedCount = 0;
        $unavailableCount = 0;
        $unknownCount = 0;

        foreach ($providers as $entry) {
            $status = (string) ($entry['status'] ?? self::STATUS_UNKNOWN);
            match ($status) {
                self::STATUS_AVAILABLE => $availableCount++,
                self::STATUS_DEGRADED => $degradedCount++,
                self::STATUS_UNAVAILABLE => $unavailableCount++,
                default => $unknownCount++,
            };
        }

        $bestAvailable = $this->pickBestAvailable($providers);
        $topStatus = $this->resolveTopStatus($availableCount, $degradedCount, $unavailableCount, count($providers));

        $blockers = [];
        if ($availableCount === 0 && $degradedCount === 0) {
            $blockers[] = 'provider_capacity_exhausted';
        }
        if ($obraId !== null && ! $obraResolved) {
            $blockers[] = 'obra_not_found';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $topStatus,
            'generated_at' => $now->toIso8601String(),
            'snapshot_id' => 'capsnap_'.(string) Str::ulid(),
            'workspace' => $workspace,
            'obra_id' => $obraId,
            'obra_resolved' => $obraResolved,
            'obra_resolution_status' => $obraResolutionStatus,
            'providers' => $providers,
            'best_available_provider' => $bestAvailable,
            'provider_count' => count($providers),
            'available_count' => $availableCount,
            'degraded_count' => $degradedCount,
            'unavailable_count' => $unavailableCount,
            'unknown_count' => $unknownCount,
            'blockers' => AiStringListNormalizer::uniqueStrings($blockers),
            'runtime_dispatch_allowed' => $availableCount > 0
                && ! in_array('provider_capacity_exhausted', $blockers, true),
            'next_action' => $this->resolveNextAction(
                $topStatus,
                $availableCount,
                $degradedCount,
                $bestAvailable,
                $blockers,
            ),
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'is_read_model' => true,
            'note' => 'Read-model local de capacidade. Atlas Decide e quem despacha runtime; este service so audita sinais locais sem chamar provider externo nem gastar token.',
            'separated_from' => 'external_rivals_certification',
        ];
    }

    /**
     * Get only the provider entry list (without top-level metadata). Used by
     * topology integration to merge capacity into role assignments.
     *
     * @param array<string,mixed> $options
     * @return list<array<string,mixed>>
     */
    public function providers(array $options = []): array
    {
        $snapshot = $this->snapshot($options);

        return is_array($snapshot['providers'] ?? null) ? $snapshot['providers'] : [];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function latestHealthSnapshots(): array
    {
        if (! DatabaseTableAvailability::has('ai_provider_health_snapshots')) {
            return [];
        }

        try {
            $rows = AiProviderHealthSnapshot::query()
                ->orderByDesc('checked_at')
                ->limit(50)
                ->get();
        } catch (Throwable) {
            return [];
        }

        $byProvider = [];
        foreach ($rows as $row) {
            $provider = (string) ($row->provider ?? '');
            if ($provider === '' || isset($byProvider[$provider])) {
                continue;
            }
            $byProvider[$provider] = [
                'status' => (string) ($row->status ?? 'unknown'),
                'checked_at' => $row->checked_at?->toIso8601String(),
                'last_success_at' => $row->last_success_at?->toIso8601String(),
                'last_failure_at' => $row->last_failure_at?->toIso8601String(),
                'message' => $row->message,
                'metadata' => is_array($row->metadata) ? $row->metadata : [],
            ];
        }

        return $byProvider;
    }

    /**
     * @return array<string,AiWorkerEvent>
     */
    private function latestWorkerEventsByProvider(Carbon $now): array
    {
        if (! DatabaseTableAvailability::has('ai_worker_events')) {
            return [];
        }

        try {
            $rows = AiWorkerEvent::query()
                ->where('occurred_at', '>=', $now->copy()->subHours(24))
                ->orderByDesc('occurred_at')
                ->limit(80)
                ->get();
        } catch (Throwable) {
            return [];
        }

        $byProvider = [];
        foreach ($rows as $event) {
            $provider = $event->provider ?: data_get($event->metadata, 'provider');
            if (! is_string($provider) || $provider === '') {
                continue;
            }
            if (isset($byProvider[$provider])) {
                continue;
            }
            $byProvider[$provider] = $event;
        }

        return $byProvider;
    }

    /**
     * @return array<string,list<array<string,mixed>>>
     */
    private function failureMemoryByProvider(?AtlasProject $project): array
    {
        if ($project === null) {
            return [];
        }

        $metadata = is_array($project->metadata) ? $project->metadata : [];
        $events = data_get($metadata, self::FAILURE_MEMORY_KEY.'.events');
        if (! is_array($events)) {
            return [];
        }

        $byProvider = [];
        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }
            $provider = $event['provider'] ?? null;
            if (! is_string($provider) || $provider === '') {
                continue;
            }
            $byProvider[$provider][] = $event;
        }

        return $byProvider;
    }

    /**
     * @param array<string,mixed>|null $healthSnapshot
     * @param list<array<string,mixed>> $failureMemory
     * @return array<string,mixed>
     */
    private function buildEntry(
        string $providerKey,
        string $workspace,
        ?array $healthSnapshot,
        ?AiWorkerEvent $workerEvent,
        array $failureMemory,
        Carbon $now,
    ): array {
        $config = $this->runtimeSettings->providerConfig($providerKey);
        $configPresent = ! empty($config);

        $runtimePresent = $this->runtimePresent($providerKey, $config, $workspace);
        $authState = $this->resolveAuthState($providerKey, $configPresent);

        $latestFailure = $failureMemory[0] ?? null;
        $cooldownUntil = $this->extractCooldownUntil($failureMemory, $now);

        $rateLimitState = $this->deriveRateLimitState($providerKey, $latestFailure, $cooldownUntil, $now);
        $quotaState = $this->deriveQuotaState($providerKey, $latestFailure);
        $capacityState = $this->deriveCapacityState($providerKey, $configPresent, $runtimePresent, $authState, $latestFailure, $rateLimitState, $quotaState);
        $status = $this->deriveStatus($providerKey, $configPresent, $runtimePresent, $authState, $capacityState, $rateLimitState, $quotaState, $healthSnapshot);

        $confidence = $this->resolveConfidence($providerKey, $configPresent, $runtimePresent, $healthSnapshot, $workerEvent, $failureMemory);

        $blockers = [];
        if ($capacityState === 'exhausted') {
            $blockers[] = 'provider_capacity_exhausted';
        }
        if ($authState === 'invalid' || $authState === 'missing') {
            $blockers[] = 'auth_'.$authState;
        }
        if ($status === self::STATUS_UNAVAILABLE && ! $runtimePresent) {
            $blockers[] = 'runtime_not_present';
        }

        $evidenceRefs = $this->resolveEvidenceRefs($providerKey, $configPresent, $healthSnapshot, $workerEvent, $failureMemory);

        return [
            'schema_version' => self::ENTRY_SCHEMA_VERSION,
            'provider' => $providerKey,
            'label' => $this->labelFor($providerKey),
            'status' => $status,
            'capacity_state' => $capacityState,
            'quota_state' => $quotaState,
            'rate_limit_state' => $rateLimitState,
            'auth_state' => $authState,
            'runtime_present' => $runtimePresent,
            'config_present' => $configPresent,
            'last_success_at' => $this->resolveLastSuccessAt($healthSnapshot, $workerEvent),
            'last_failure_at' => $this->resolveLastFailureAt($healthSnapshot, $latestFailure),
            'last_failure_type' => $latestFailure['failure_type'] ?? null,
            'cooldown_until' => $cooldownUntil?->toIso8601String(),
            'confidence' => $confidence,
            'evidence_refs' => $evidenceRefs,
            'blockers' => $blockers,
            'next_action' => $this->resolveProviderNextAction($status, $capacityState, $rateLimitState, $quotaState, $authState, $blockers),
            'external_provider_call' => false,
        ];
    }

    /**
     * @param array<string,mixed> $config
     */
    private function runtimePresent(string $providerKey, array $config, string $workspace): bool
    {
        if ($providerKey === self::PROVIDER_ATLAS_LOCAL) {
            return true;
        }

        if ($providerKey === self::PROVIDER_CLAUDE_CODEX) {
            $claudeConfig = $this->runtimeSettings->providerConfig(self::PROVIDER_CLAUDE_CLI);
            $codexConfig = $this->runtimeSettings->providerConfig(self::PROVIDER_CODEX_CLI);

            return ! empty($claudeConfig) && ! empty($codexConfig);
        }

        if (empty($config)) {
            return false;
        }

        $binary = $this->stringOrNull($config['binary'] ?? null);
        if ($binary === null) {
            return false;
        }

        // If binary is an absolute path, check directly. Otherwise, presence of
        // the config entry is the local signal we have without invoking PATH
        // probing (which would still be local but fragile across hosts).
        if ($binary !== '' && $binary[0] === DIRECTORY_SEPARATOR) {
            return is_executable($binary);
        }

        return true;
    }

    private function resolveAuthState(string $providerKey, bool $configPresent): string
    {
        if ($providerKey === self::PROVIDER_ATLAS_LOCAL) {
            return 'not_applicable';
        }

        if (! $configPresent) {
            return 'missing';
        }

        // CLI providers use device-local credentials (e.g. `claude login`); we
        // cannot probe them without invoking the binary. Mark as configured
        // when the runtime is wired and let failure memory downgrade to invalid.
        return 'configured';
    }

    /**
     * @param list<array<string,mixed>> $failureMemory
     */
    private function extractCooldownUntil(array $failureMemory, Carbon $now): ?Carbon
    {
        foreach ($failureMemory as $event) {
            $cooldown = $event['cooldown_until'] ?? null;
            if (is_string($cooldown) && $cooldown !== '') {
                try {
                    $when = Carbon::parse($cooldown);
                } catch (Throwable) {
                    continue;
                }
                if ($when->greaterThan($now)) {
                    return $when;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed>|null $latestFailure
     */
    private function deriveRateLimitState(string $providerKey, ?array $latestFailure, ?Carbon $cooldownUntil, Carbon $now): string
    {
        if ($providerKey === self::PROVIDER_ATLAS_LOCAL) {
            return 'not_applicable';
        }

        if ($cooldownUntil !== null && $cooldownUntil->greaterThan($now)) {
            return 'cooldown';
        }

        $type = is_array($latestFailure) ? ($latestFailure['failure_type'] ?? null) : null;
        if ($type === 'rate_limit') {
            return 'limited';
        }

        return 'unknown';
    }

    /**
     * @param array<string,mixed>|null $latestFailure
     */
    private function deriveQuotaState(string $providerKey, ?array $latestFailure): string
    {
        if ($providerKey === self::PROVIDER_ATLAS_LOCAL) {
            return 'not_applicable';
        }

        $type = is_array($latestFailure) ? ($latestFailure['failure_type'] ?? null) : null;

        return match ($type) {
            'quota_exhausted' => 'exhausted',
            'rate_limit' => 'limited',
            default => 'unknown',
        };
    }

    /**
     * @param array<string,mixed>|null $latestFailure
     */
    private function deriveCapacityState(
        string $providerKey,
        bool $configPresent,
        bool $runtimePresent,
        string $authState,
        ?array $latestFailure,
        string $rateLimitState,
        string $quotaState,
    ): string {
        if (! $runtimePresent || ! $configPresent) {
            return 'unknown';
        }

        if ($authState === 'invalid' || $authState === 'missing') {
            return 'unknown';
        }

        $type = is_array($latestFailure) ? ($latestFailure['failure_type'] ?? null) : null;
        if ($type === 'provider_capacity_exhausted') {
            return 'exhausted';
        }

        if ($quotaState === 'exhausted') {
            return 'exhausted';
        }

        if ($rateLimitState === 'cooldown' || $rateLimitState === 'limited' || $quotaState === 'limited') {
            return 'limited';
        }

        if ($providerKey === self::PROVIDER_ATLAS_LOCAL) {
            return 'available';
        }

        return 'available';
    }

    /**
     * @param array<string,mixed>|null $healthSnapshot
     */
    private function deriveStatus(
        string $providerKey,
        bool $configPresent,
        bool $runtimePresent,
        string $authState,
        string $capacityState,
        string $rateLimitState,
        string $quotaState,
        ?array $healthSnapshot,
    ): string {
        if ($providerKey === self::PROVIDER_ATLAS_LOCAL) {
            return self::STATUS_AVAILABLE;
        }

        if (! $configPresent || ! $runtimePresent) {
            return self::STATUS_UNAVAILABLE;
        }

        if ($authState === 'invalid' || $authState === 'missing') {
            return self::STATUS_UNAVAILABLE;
        }

        if ($capacityState === 'exhausted' || $quotaState === 'exhausted') {
            return self::STATUS_UNAVAILABLE;
        }

        if ($capacityState === 'limited' || $rateLimitState === 'cooldown' || $rateLimitState === 'limited') {
            return self::STATUS_DEGRADED;
        }

        if (is_array($healthSnapshot)) {
            $hsStatus = (string) ($healthSnapshot['status'] ?? '');
            if (in_array($hsStatus, ['failed', 'down', 'offline'], true)) {
                return self::STATUS_UNAVAILABLE;
            }
            if (in_array($hsStatus, ['degraded', 'stale'], true)) {
                return self::STATUS_DEGRADED;
            }
            if (in_array($hsStatus, ['online', 'healthy', 'available'], true)) {
                return self::STATUS_AVAILABLE;
            }
        }

        // No definitive signal beyond config/runtime presence. Honest unknown.
        return self::STATUS_UNKNOWN;
    }

    /**
     * @param array<string,mixed>|null $healthSnapshot
     * @param list<array<string,mixed>> $failureMemory
     */
    private function resolveConfidence(
        string $providerKey,
        bool $configPresent,
        bool $runtimePresent,
        ?array $healthSnapshot,
        ?AiWorkerEvent $workerEvent,
        array $failureMemory,
    ): string {
        if ($providerKey === self::PROVIDER_ATLAS_LOCAL) {
            return 'high';
        }

        $signals = 0;
        if ($configPresent) {
            $signals++;
        }
        if ($runtimePresent) {
            $signals++;
        }
        if (is_array($healthSnapshot) && ($healthSnapshot['checked_at'] ?? null) !== null) {
            $signals++;
        }
        if ($workerEvent !== null) {
            $signals++;
        }
        if (! empty($failureMemory)) {
            $signals++;
        }

        return match (true) {
            $signals >= 3 => 'high',
            $signals >= 2 => 'medium',
            default => 'low',
        };
    }

    /**
     * @param array<string,mixed>|null $healthSnapshot
     * @param list<array<string,mixed>> $failureMemory
     * @return list<string>
     */
    private function resolveEvidenceRefs(
        string $providerKey,
        bool $configPresent,
        ?array $healthSnapshot,
        ?AiWorkerEvent $workerEvent,
        array $failureMemory,
    ): array {
        $refs = [];

        if ($configPresent) {
            $refs[] = "config:atlas.ai.providers.{$providerKey}";
        }
        if (is_array($healthSnapshot)) {
            $refs[] = "model:AiProviderHealthSnapshot:{$providerKey}";
        }
        if ($workerEvent !== null) {
            $refs[] = "model:AiWorkerEvent:{$providerKey}";
        }
        if (! empty($failureMemory)) {
            $refs[] = "metadata:atlas_forge_provider_failure_memory:{$providerKey}";
        }
        if ($providerKey === self::PROVIDER_ATLAS_LOCAL) {
            $refs[] = 'runtime:php-cli-local';
        }

        return $refs;
    }

    /**
     * @param array<string,mixed>|null $healthSnapshot
     */
    private function resolveLastSuccessAt(?array $healthSnapshot, ?AiWorkerEvent $workerEvent): ?string
    {
        $candidates = [];
        if (is_array($healthSnapshot) && ! empty($healthSnapshot['last_success_at'])) {
            $candidates[] = (string) $healthSnapshot['last_success_at'];
        }
        if ($workerEvent !== null
            && in_array((string) ($workerEvent->event_type ?? ''), ['worker_started', 'worker_idle', 'worker_running'], true)
            && $workerEvent->occurred_at !== null
        ) {
            $candidates[] = $workerEvent->occurred_at->toIso8601String();
        }

        sort($candidates);

        return empty($candidates) ? null : end($candidates);
    }

    /**
     * @param array<string,mixed>|null $healthSnapshot
     * @param array<string,mixed>|null $latestFailure
     */
    private function resolveLastFailureAt(?array $healthSnapshot, ?array $latestFailure): ?string
    {
        $candidates = [];
        if (is_array($healthSnapshot) && ! empty($healthSnapshot['last_failure_at'])) {
            $candidates[] = (string) $healthSnapshot['last_failure_at'];
        }
        if (is_array($latestFailure) && ! empty($latestFailure['occurred_at'])) {
            $candidates[] = (string) $latestFailure['occurred_at'];
        }

        sort($candidates);

        return empty($candidates) ? null : end($candidates);
    }

    /**
     * @param list<string> $blockers
     */
    private function resolveProviderNextAction(
        string $status,
        string $capacityState,
        string $rateLimitState,
        string $quotaState,
        string $authState,
        array $blockers,
    ): string {
        if (in_array('provider_capacity_exhausted', $blockers, true)) {
            return 'block';
        }
        if (in_array('auth_invalid', $blockers, true) || in_array('auth_missing', $blockers, true)) {
            return 'reauth';
        }
        if ($rateLimitState === 'cooldown') {
            return 'retry_later';
        }
        if ($capacityState === 'limited' || $quotaState === 'limited') {
            return 'observe';
        }
        if ($status === self::STATUS_AVAILABLE) {
            return 'use';
        }

        return 'observe';
    }

    /**
     * @param list<array<string,mixed>> $providers
     */
    private function pickBestAvailable(array $providers): ?string
    {
        $priority = [
            self::PROVIDER_CLAUDE_CLI,
            self::PROVIDER_CLAUDE_CODEX,
            self::PROVIDER_CODEX_CLI,
            self::PROVIDER_GEMINI_CLI,
            self::PROVIDER_ATLAS_LOCAL,
        ];

        $byProvider = [];
        foreach ($providers as $entry) {
            $byProvider[(string) $entry['provider']] = $entry;
        }

        foreach ($priority as $providerKey) {
            $entry = $byProvider[$providerKey] ?? null;
            if ($entry === null) {
                continue;
            }
            if ((string) $entry['status'] === self::STATUS_AVAILABLE) {
                return $providerKey;
            }
        }

        // Degraded fallback ordering.
        foreach ($priority as $providerKey) {
            $entry = $byProvider[$providerKey] ?? null;
            if ($entry === null) {
                continue;
            }
            if ((string) $entry['status'] === self::STATUS_DEGRADED) {
                return $providerKey;
            }
        }

        return null;
    }

    private function resolveTopStatus(int $available, int $degraded, int $unavailable, int $total): string
    {
        if ($available === 0 && $degraded === 0) {
            return self::TOP_STATUS_BLOCKED;
        }
        if ($unavailable > 0 || $degraded > 0) {
            return self::TOP_STATUS_DEGRADED;
        }

        return self::TOP_STATUS_AVAILABLE;
    }

    /**
     * @param list<string> $blockers
     */
    private function resolveNextAction(
        string $topStatus,
        int $availableCount,
        int $degradedCount,
        ?string $bestAvailable,
        array $blockers,
    ): string {
        if (in_array('provider_capacity_exhausted', $blockers, true)) {
            return 'block_runtime_dispatch_until_provider_capacity_recovers';
        }
        if (in_array('obra_not_found', $blockers, true)) {
            return 'provide_existing_obra_id';
        }
        if ($availableCount === 0 && $degradedCount > 0) {
            return 'use_degraded_provider_with_governed_caution';
        }
        if ($bestAvailable !== null) {
            return 'dispatch_to:'.$bestAvailable;
        }

        return 'observe_capacity_signals';
    }

    private function labelFor(string $providerKey): string
    {
        return match ($providerKey) {
            self::PROVIDER_CLAUDE_CLI => 'Claude CLI',
            self::PROVIDER_CODEX_CLI => 'Codex CLI',
            self::PROVIDER_GEMINI_CLI => 'Gemini CLI',
            self::PROVIDER_CLAUDE_CODEX => 'Claude orchestrating Codex',
            self::PROVIDER_ATLAS_LOCAL => 'Atlas local runtime',
            default => $providerKey,
        };
    }

    private function resolveObra(string $obraId): ?AtlasProject
    {
        if (! Str::isUuid($obraId)) {
            return null;
        }

        try {
            return AtlasProject::query()->whereKey($obraId)->first();
        } catch (Throwable) {
            return null;
        }
    }

    private function resolveNow(mixed $value): Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy();
        }
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance(\DateTimeImmutable::createFromInterface($value));
        }

        return Carbon::now();
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
