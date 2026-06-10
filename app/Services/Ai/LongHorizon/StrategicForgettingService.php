<?php

declare(strict_types=1);

namespace App\Services\Ai\LongHorizon;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * TEOS-I3 · Strategic Forgetting.
 *
 * Produces a deterministic, read-only receipt over Atlas durable memory. The
 * service decides what should be retained, compressed, archived, demoted,
 * expired, superseded or forgotten without mutating rows. Destructive forget
 * remains a separate human-approved execution path.
 */
class StrategicForgettingService
{
    public const STATUS_READY = 'ready';

    public const STATUS_BLOCKED = 'blocked';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function plan(array $input = []): array
    {
        $scopeType = $this->stringOrNull($input['scope_type'] ?? null);
        $scopeId = $this->stringOrNull($input['scope_id'] ?? null);
        $limit = max(1, min(500, (int) ($input['limit'] ?? 100)));
        $now = ($input['now'] ?? null) instanceof CarbonImmutable ? $input['now'] : CarbonImmutable::now();

        if ($scopeType !== null && ! in_array($scopeType, AtlasMemoryEntry::SCOPES, true)) {
            throw new InvalidArgumentException("scope_type [{$scopeType}] is not an AtlasMemoryEntry scope");
        }

        if (! DatabaseTableAvailability::has('atlas_memory_entries')) {
            return $this->blocked($scopeType, $scopeId, $now, 'atlas_memory_entries table is missing');
        }

        $query = AtlasMemoryEntry::query()
            ->orderByDesc('updated_at')
            ->limit($limit);
        if ($scopeType !== null) {
            $query->where('scope_type', $scopeType);
        }
        if ($scopeId !== null) {
            $query->where('scope_id', $scopeId);
        }

        /** @var Collection<int,AtlasMemoryEntry> $entries */
        $entries = $query->get();
        $decisions = $entries->map(fn (AtlasMemoryEntry $entry): array => $this->decide($entry, $now))->values()->all();

        $payload = [
            'schema_version' => AtlasLongHorizonCanon::STRATEGIC_FORGETTING_RECEIPT_SCHEMA_VERSION,
            'status' => self::STATUS_READY,
            'generated_at' => $now->toJSON(),
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'summary' => $this->summarize($decisions),
            'decisions' => $decisions,
            'policy' => [
                'allowed_policies' => AtlasLongHorizonCanon::STRATEGIC_FORGETTING_POLICIES,
                'destructive_forget_requires_human_approval' => true,
                'read_only' => true,
                'benchmark_not_run' => true,
            ],
        ];
        $payload['receipt_hash'] = $this->hashReceipt($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function decide(AtlasMemoryEntry $entry, CarbonImmutable $now): array
    {
        $metadata = is_array($entry->metadata) ? $entry->metadata : [];
        $confidence = $entry->confidence === null ? null : (float) $entry->confidence;
        $recordedAt = $entry->recorded_at ? CarbonImmutable::parse($entry->recorded_at) : $now;
        $lastUsedAt = $entry->last_used_at ? CarbonImmutable::parse($entry->last_used_at) : null;
        $ageDays = $recordedAt->diffInDays($now);
        $idleDays = $lastUsedAt ? $lastUsedAt->diffInDays($now) : $ageDays;

        $policy = AtlasLongHorizonCanon::FORGETTING_POLICY_RETAIN;
        $reason = 'recent_or_sufficient_signal';
        $requiresReview = false;

        if (($entry->privacy_class === 'secret') || (bool) ($metadata['requested_for_deletion'] ?? false)) {
            $policy = AtlasLongHorizonCanon::FORGETTING_POLICY_FORGET;
            $reason = $entry->privacy_class === 'secret' ? 'secret_memory_requires_forget_review' : 'deletion_requested';
            $requiresReview = true;
        } elseif (! empty($entry->superseded_by_id)) {
            $policy = AtlasLongHorizonCanon::FORGETTING_POLICY_SUPERSEDE;
            $reason = 'superseded_by_newer_memory';
        } elseif ($entry->stale_after !== null && CarbonImmutable::parse($entry->stale_after)->lessThan($now)) {
            $policy = AtlasLongHorizonCanon::FORGETTING_POLICY_EXPIRE;
            $reason = 'stale_after_elapsed';
        } elseif ($entry->valid_until !== null && CarbonImmutable::parse($entry->valid_until)->lessThan($now)) {
            $policy = AtlasLongHorizonCanon::FORGETTING_POLICY_DEMOTE;
            $reason = 'temporal_validity_elapsed';
        } elseif ($confidence !== null && $confidence < 0.3 && $idleDays >= 90) {
            $policy = AtlasLongHorizonCanon::FORGETTING_POLICY_ARCHIVE;
            $reason = 'low_signal_and_unused';
        } elseif ($confidence !== null && $confidence < 0.7 && $ageDays >= 30) {
            $policy = AtlasLongHorizonCanon::FORGETTING_POLICY_COMPRESS;
            $reason = 'medium_signal_candidate_for_compaction';
        }

        if ((bool) ($metadata['must_keep'] ?? false)) {
            $policy = AtlasLongHorizonCanon::FORGETTING_POLICY_RETAIN;
            $reason = 'must_keep';
            $requiresReview = false;
        }

        return [
            'memory_entry_id' => (string) $entry->id,
            'memory_hash' => (string) ($entry->content_hash ?: sha1((string) $entry->id)),
            'scope_type' => (string) $entry->scope_type,
            'scope_id' => $entry->scope_id,
            'policy' => $policy,
            'reason' => $reason,
            'requires_human_review' => $requiresReview,
            'read_only_effect' => $this->readOnlyEffect($policy),
            'age_days' => $ageDays,
            'idle_days' => $idleDays,
            'confidence' => $confidence,
            'authority_level' => $entry->authority_level,
            'evidence_refs' => $this->evidenceRefs($entry),
        ];
    }

    private function readOnlyEffect(string $policy): string
    {
        return match ($policy) {
            AtlasLongHorizonCanon::FORGETTING_POLICY_COMPRESS => 'candidate_for_compaction_receipt',
            AtlasLongHorizonCanon::FORGETTING_POLICY_ARCHIVE => 'candidate_for_cold_storage',
            AtlasLongHorizonCanon::FORGETTING_POLICY_DEMOTE => 'lower_rag_precedence',
            AtlasLongHorizonCanon::FORGETTING_POLICY_EXPIRE => 'block_use_until_refresh',
            AtlasLongHorizonCanon::FORGETTING_POLICY_SUPERSEDE => 'follow_superseded_by_pointer',
            AtlasLongHorizonCanon::FORGETTING_POLICY_FORGET => 'requires_tombstone_and_human_approved_delete',
            default => 'keep_active',
        };
    }

    /**
     * @return array<int,string>
     */
    private function evidenceRefs(AtlasMemoryEntry $entry): array
    {
        $refs = ['memory_entry:'.$entry->id];
        if ($entry->source_type !== null && $entry->source_id !== null) {
            $refs[] = $entry->source_type.':'.$entry->source_id;
        }
        if ($entry->trace_id !== null) {
            $refs[] = 'trace:'.$entry->trace_id;
        }

        return array_values(array_unique($refs));
    }

    /**
     * @param  array<int,array<string,mixed>>  $decisions
     * @return array<string,mixed>
     */
    private function summarize(array $decisions): array
    {
        $byPolicy = array_fill_keys(AtlasLongHorizonCanon::STRATEGIC_FORGETTING_POLICIES, 0);
        $reviewRequired = 0;
        foreach ($decisions as $decision) {
            $policy = (string) ($decision['policy'] ?? '');
            if (array_key_exists($policy, $byPolicy)) {
                $byPolicy[$policy]++;
            }
            if ((bool) ($decision['requires_human_review'] ?? false)) {
                $reviewRequired++;
            }
        }

        return [
            'total' => count($decisions),
            'by_policy' => $byPolicy,
            'human_review_required' => $reviewRequired,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function blocked(?string $scopeType, ?string $scopeId, CarbonImmutable $now, string $reason): array
    {
        $payload = [
            'schema_version' => AtlasLongHorizonCanon::STRATEGIC_FORGETTING_RECEIPT_SCHEMA_VERSION,
            'status' => self::STATUS_BLOCKED,
            'generated_at' => $now->toJSON(),
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'summary' => ['total' => 0, 'by_policy' => [], 'human_review_required' => 0],
            'decisions' => [],
            'blockers' => [$reason],
            'policy' => [
                'destructive_forget_requires_human_approval' => true,
                'read_only' => true,
                'benchmark_not_run' => true,
            ],
        ];
        $payload['receipt_hash'] = $this->hashReceipt($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hashReceipt(array $payload): string
    {
        unset($payload['generated_at']);

        return MissionCanonicalHash::sha256($payload);
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
