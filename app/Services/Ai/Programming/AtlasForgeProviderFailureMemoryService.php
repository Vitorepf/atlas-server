<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Models\AtlasProject;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Atlas Forge Provider Failure Memory.
 *
 * Persists governed provider-failure events to Obra metadata (always) and to
 * `atlas_ledger_events` (when the table exists), capped at 50 entries per Obra
 * with deduplication of nearly-identical sequential events.
 *
 * Hard rules:
 *   - NEVER calls an external provider;
 *   - NEVER spends a token;
 *   - NEVER auto-completes work;
 *   - NEVER bypasses the review/completion gate;
 *   - cooldown is suggested per failure type, NEVER reduces quality gates;
 *   - dedupes only nearby duplicates (same provider+model+role+failure_type
 *     within 60 seconds), so the audit trail keeps recurrence honest.
 *
 * Schema: atlas.forge.provider_failure_memory.v1 (collection)
 * Event: atlas.forge.provider_failure_memory_event.v1
 *
 * Doc: docs/engineering-knowledge-base/atlas-forge-provider-capacity-continuity-v1.md
 */
class AtlasForgeProviderFailureMemoryService
{
    public const SCHEMA_VERSION = 'atlas.forge.provider_failure_memory.v1';
    public const EVENT_SCHEMA_VERSION = 'atlas.forge.provider_failure_memory_event.v1';

    public const METADATA_KEY = 'atlas_forge_provider_failure_memory';

    public const MAX_EVENTS = 50;
    public const DEDUPE_WINDOW_SECONDS = 60;

    /** @var array<string,int> */
    private const COOLDOWN_BY_FAILURE = [
        'rate_limit' => 60,
        'quota_exhausted' => 600,
        'auth_failed' => 0,
        'timeout' => 30,
        'context_limit' => 0,
        'model_unavailable' => 120,
        'provider_error' => 30,
        'insufficient_capability' => 0,
        'provider_capacity_exhausted' => 900,
    ];

    /**
     * Record a governed failure event for a given Obra.
     *
     * @param array{
     *     provider: string,
     *     model?: string|null,
     *     role?: string|null,
     *     failure_type: string,
     *     action?: string|null,
     *     blocker?: string|null,
     *     reason?: string|null,
     *     fallback_event_id?: string|null,
     *     decision_receipt_id?: string|null,
     *     provider_topology_id?: string|null,
     *     capacity_snapshot_id?: string|null,
     *     provider_status_before?: string|null,
     *     provider_status_after?: string|null,
     *     occurred_at?: \DateTimeInterface|string|null,
     * } $payload
     * @return array<string,mixed>  Recorded event.
     */
    public function record(AtlasProject $project, array $payload): array
    {
        $failureType = $this->stringOrNull($payload['failure_type'] ?? null);
        if ($failureType === null
            || ! in_array($failureType, AtlasForgeProviderFallbackPolicyService::KNOWN_FAILURES, true)
        ) {
            throw new \InvalidArgumentException('unknown_failure_type:'.(string) $failureType);
        }

        $provider = $this->stringOrNull($payload['provider'] ?? null);
        if ($provider === null) {
            throw new \InvalidArgumentException('provider_required');
        }

        $occurredAt = $this->resolveOccurredAt($payload['occurred_at'] ?? null);
        $cooldownUntil = $this->resolveCooldownUntil($failureType, $occurredAt);

        $event = [
            'schema_version' => self::EVENT_SCHEMA_VERSION,
            'event_id' => 'fme_'.(string) Str::ulid(),
            'occurred_at' => $occurredAt->toIso8601String(),
            'provider' => $provider,
            'model' => $this->stringOrNull($payload['model'] ?? null),
            'role' => $this->stringOrNull($payload['role'] ?? null),
            'failure_type' => $failureType,
            'action' => $this->stringOrNull($payload['action'] ?? null),
            'blocker' => $this->stringOrNull($payload['blocker'] ?? null),
            'reason' => $this->stringOrNull($payload['reason'] ?? null),
            'cooldown_until' => $cooldownUntil?->toIso8601String(),
            'fallback_event_id' => $this->stringOrNull($payload['fallback_event_id'] ?? null),
            'decision_receipt_id' => $this->stringOrNull($payload['decision_receipt_id'] ?? null),
            'provider_topology_id' => $this->stringOrNull($payload['provider_topology_id'] ?? null),
            'capacity_snapshot_id' => $this->stringOrNull($payload['capacity_snapshot_id'] ?? null),
            'provider_status_before' => $this->stringOrNull($payload['provider_status_before'] ?? null),
            'provider_status_after' => $this->stringOrNull($payload['provider_status_after'] ?? null),
            'silent' => false,
            'reduces_quality_gates' => false,
            'bypasses_review_completion_gate' => false,
            'auto_completes_work' => false,
            'external_provider_call' => false,
            'evidence_hash' => null,
        ];
        $event['evidence_hash'] = hash('sha256', (string) json_encode([
            $event['occurred_at'],
            $event['provider'],
            $event['model'],
            $event['role'],
            $event['failure_type'],
            $event['blocker'],
            $event['reason'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $existing = $this->memoryFor($project);
        $events = is_array($existing['events'] ?? null) ? $existing['events'] : [];

        // Dedup: same provider+model+role+failure_type within DEDUPE_WINDOW_SECONDS.
        $deduped = $this->dedupe($events, $event);
        if (! $deduped) {
            $events[] = $event;
        }

        // Cap to MAX_EVENTS keeping the newest.
        if (count($events) > self::MAX_EVENTS) {
            $events = array_slice($events, -self::MAX_EVENTS);
        }

        $memory = [
            'schema_version' => self::SCHEMA_VERSION,
            'obra_id' => (string) $project->getKey(),
            'event_count' => count($events),
            'events' => $events,
            'updated_at' => $occurredAt->toIso8601String(),
            'external_provider_call' => false,
        ];

        $metadata = is_array($project->metadata) ? $project->metadata : [];
        $metadata[self::METADATA_KEY] = $memory;
        $project->forceFill(['metadata' => $metadata])->save();

        $this->maybeWriteLedger($project, $event);

        return $event;
    }

    /**
     * Read the failure memory for an Obra.
     *
     * @return array<string,mixed>
     */
    public function memoryFor(?AtlasProject $project): array
    {
        if ($project === null) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'obra_id' => null,
                'event_count' => 0,
                'events' => [],
                'updated_at' => null,
                'external_provider_call' => false,
            ];
        }

        $metadata = is_array($project->metadata) ? $project->metadata : [];
        $memory = data_get($metadata, self::METADATA_KEY);

        if (! is_array($memory) || ! isset($memory['events']) || ! is_array($memory['events'])) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'obra_id' => (string) $project->getKey(),
                'event_count' => 0,
                'events' => [],
                'updated_at' => null,
                'external_provider_call' => false,
            ];
        }

        $events = array_values(array_filter($memory['events'], 'is_array'));
        // Sort newest first.
        usort($events, static fn (array $a, array $b): int => strcmp(
            (string) ($b['occurred_at'] ?? ''),
            (string) ($a['occurred_at'] ?? ''),
        ));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'obra_id' => (string) $project->getKey(),
            'event_count' => count($events),
            'events' => $events,
            'updated_at' => $memory['updated_at'] ?? null,
            'external_provider_call' => false,
        ];
    }

    public function snapshot(?AtlasProject $project): array
    {
        $memory = $this->memoryFor($project);
        $memory['cooldown_policy'] = self::COOLDOWN_BY_FAILURE;
        $memory['max_events'] = self::MAX_EVENTS;
        $memory['dedupe_window_seconds'] = self::DEDUPE_WINDOW_SECONDS;
        $memory['known_failures'] = AtlasForgeProviderFallbackPolicyService::KNOWN_FAILURES;

        return $memory;
    }

    /**
     * @param list<array<string,mixed>> $events
     * @param array<string,mixed> $candidate
     */
    private function dedupe(array $events, array $candidate): bool
    {
        if (empty($events)) {
            return false;
        }

        $candidateAt = $this->parseIso($candidate['occurred_at'] ?? null);

        for ($i = count($events) - 1; $i >= 0; $i--) {
            $event = $events[$i];
            if (! is_array($event)) {
                continue;
            }
            if (($event['provider'] ?? null) !== ($candidate['provider'] ?? null)) {
                continue;
            }
            if (($event['model'] ?? null) !== ($candidate['model'] ?? null)) {
                continue;
            }
            if (($event['role'] ?? null) !== ($candidate['role'] ?? null)) {
                continue;
            }
            if (($event['failure_type'] ?? null) !== ($candidate['failure_type'] ?? null)) {
                continue;
            }

            $existingAt = $this->parseIso($event['occurred_at'] ?? null);
            if ($existingAt === null || $candidateAt === null) {
                return true;
            }
            if (abs($candidateAt->diffInSeconds($existingAt, true)) <= self::DEDUPE_WINDOW_SECONDS) {
                return true;
            }
            // Older event of same shape outside window — stop scanning.
            return false;
        }

        return false;
    }

    private function parseIso(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function resolveOccurredAt(mixed $value): Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy();
        }
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance(\DateTimeImmutable::createFromInterface($value));
        }
        if (is_string($value) && $value !== '') {
            try {
                return Carbon::parse($value);
            } catch (Throwable) {
                // fall through
            }
        }

        return Carbon::now();
    }

    private function resolveCooldownUntil(string $failureType, Carbon $occurredAt): ?Carbon
    {
        $seconds = self::COOLDOWN_BY_FAILURE[$failureType] ?? 0;
        if ($seconds <= 0) {
            return null;
        }

        return $occurredAt->copy()->addSeconds($seconds);
    }

    /**
     * @param array<string,mixed> $event
     */
    private function maybeWriteLedger(AtlasProject $project, array $event): void
    {
        if (! Schema::hasTable('atlas_ledger_events')) {
            return;
        }

        try {
            DB::table('atlas_ledger_events')->insert([
                'event_id' => (string) ($event['event_id'] ?? Str::ulid()),
                'schema_version' => self::EVENT_SCHEMA_VERSION,
                'event_type' => 'PROVIDER_FAILURE_RECORDED',
                'emitter_stage' => 'forge_provider_failure_memory',
                'emitter_version' => 'v1',
                'payload' => json_encode([
                    'obra_id' => (string) $project->getKey(),
                    'event' => $event,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'payload_hash' => $event['evidence_hash'] ?? null,
                'occurred_at' => $event['occurred_at'] ?? now()->toIso8601String(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable) {
            // Ledger is best-effort. Metadata is the source of truth.
        }
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
