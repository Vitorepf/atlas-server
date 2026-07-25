<?php

namespace App\Services\Ai\OperatorIntelligence;

use App\Models\AiMemoryDelta;
use App\Models\OperatorLearningCandidate;
use App\Models\OperatorProfileItem;
use App\Services\Ai\OperatorIntelligence\Support\OperatorProfileRegistrySupport;
use App\Services\Ai\Support\DatabaseTableAvailability;

class OperatorProfileRegistry
{
    public function __construct(
        private readonly OperatorProfilePolicyCompiler $compiler,
    ) {}

    public function promoteCandidate(OperatorLearningCandidate $candidate): OperatorProfileItem
    {
        $signal = $candidate->signal;
        $value = (array) ($candidate->value ?? []);
        $profileKey = $this->profileKey($candidate, $value);
        $scopeType = (string) ($value['scope_type'] ?? $signal?->scope_type ?? 'global');
        $scopeId = $value['scope_id'] ?? $signal?->scope_id;

        // Honor the learned validity hint from the comprehension extractor.
        // The extractor persists validity_hint in signal metadata as
        // durable|momentary|scoped. Map it to the profile item's validity_kind
        // and valid_until so the active-item expiry gate can expire momentary
        // preferences instead of injecting them into every future prompt forever.
        $validityHint = (string) ($signal?->metadata['validity_hint'] ?? $value['validity_hint'] ?? 'durable');
        [$validityKind, $validUntil] = $this->resolveValidity($validityHint, $value, $signal); // uses pure hint map

        $existing = OperatorProfileItem::query()
            ->where('operator_id', $candidate->operator_id)
            ->where('profile_key', $profileKey)
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->first();

        $payload = [
            'operator_id' => $candidate->operator_id,
            'taxonomy_item_id' => $candidate->taxonomy_item_id,
            'profile_key' => $profileKey,
            'value' => $value,
            'summary' => (string) ($value['summary'] ?? $candidate->claim),
            'scope_type' => $scopeType,
            'scope_id' => is_string($scopeId) && trim($scopeId) !== '' ? trim($scopeId) : null,
            'validity_kind' => $validityKind,
            'valid_from' => $value['valid_from'] ?? $signal?->valid_from,
            'valid_until' => $validUntil,
            'confidence' => max((float) $candidate->confidence, (float) ($existing?->confidence ?? 0.0)),
            'privacy_class' => (string) ($value['privacy_class'] ?? $signal?->privacy_class ?? 'normal'),
            'automation_level' => $this->automationLevel($candidate, $value),
            'status' => OperatorProfileItem::STATUS_ACTIVE,
            'source_candidate_id' => $candidate->id,
        ];

        $item = $existing ?? new OperatorProfileItem();
        $item->fill($payload);
        $item->save();

        $this->compiler->compileItem($item);

        if (($value['mirror_to_memory'] ?? false) === true) {
            $this->mirrorToMemoryDelta($item);
        }

        return $item->refresh();
    }

    /**
     * Map the comprehension extractor's validity hint to the profile item's
     * validity_kind and valid_until.
     *
     * - durable  → permanent, valid_until = null (lives forever)
     * - momentary → temporary, valid_until = now + 1 hour (expires quickly)
     * - scoped   → session, valid_until = now + 8 hours (bounded session)
     *
     * If the candidate value already carries an explicit validity_kind or
     * valid_until, those take precedence (the caller overrode the hint).
     *
     * @param  array<string,mixed>  $value
     * @return array{0:string,1:\Carbon\CarbonImmutable|null}
     */
    private function resolveValidity(string $hint, array $value, ?\App\Models\OperatorLearningSignal $signal): array
    {
        // Explicit override from the candidate value wins.
        if (isset($value['validity_kind']) && is_string($value['validity_kind']) && $value['validity_kind'] !== '') {
            return [
                $value['validity_kind'],
                $value['valid_until'] ?? $signal?->valid_until,
            ];
        }

        [$kind, $hours] = OperatorProfileRegistrySupport::validityFromHint($hint);
        if ($hours === null) {
            return [$kind, $value['valid_until'] ?? $signal?->valid_until];
        }

        return [$kind, now()->addHours($hours)];
    }

    /**
     * @return array<int,OperatorProfileItem>
     */
    public function activeForOperator(string $operatorId, array $filters = []): array
    {
        $query = OperatorProfileItem::query()
            ->with('policyRules')
            ->where('operator_id', $operatorId)
            ->active();

        if (isset($filters['taxonomy_prefix']) && is_string($filters['taxonomy_prefix'])) {
            $query->where('taxonomy_item_id', 'like', strtoupper($filters['taxonomy_prefix']).'%');
        }

        return $query
            ->orderByDesc('confidence')
            ->orderByDesc('updated_at')
            ->limit((int) ($filters['limit'] ?? 200))
            ->get()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    public function payload(OperatorProfileItem $item): array
    {
        return [
            'id' => $item->id,
            'operator_id' => $item->operator_id,
            'taxonomy_item_id' => $item->taxonomy_item_id,
            'profile_key' => $item->profile_key,
            'summary' => $item->summary,
            'value' => $item->value,
            'scope_type' => $item->scope_type,
            'scope_id' => $item->scope_id,
            'validity_kind' => $item->validity_kind,
            'confidence' => $item->confidence,
            'privacy_class' => $item->privacy_class,
            'automation_level' => $item->automation_level,
            'status' => $item->status,
            'source_candidate_id' => $item->source_candidate_id,
            'source_memory_entry_id' => $item->source_memory_entry_id,
            'last_applied_at' => $item->last_applied_at?->toIso8601String(),
            'policy_rules' => $item->relationLoaded('policyRules') ? $item->policyRules->map->toArray()->all() : null,
        ];
    }

    private function profileKey(OperatorLearningCandidate $candidate, array $value): string
    {
        return OperatorProfileRegistrySupport::profileKey(
            (string) $candidate->taxonomy_item_id,
            is_string($value['profile_key'] ?? null) ? $value['profile_key'] : null,
        );
    }

    private function automationLevel(OperatorLearningCandidate $candidate, array $value): string
    {
        return OperatorProfileRegistrySupport::automationLevel(
            is_string($value['automation_level'] ?? null) ? $value['automation_level'] : null,
            (bool) $candidate->auto_apply_eligible,
            (bool) config('atlas_operator_intelligence.auto_apply_enabled', false),
            (string) config('atlas_operator_intelligence.default_automation_level', 'observe'),
            OperatorProfileItem::AUTOMATION_LEVELS,
        );
    }

    private function mirrorToMemoryDelta(OperatorProfileItem $item): void
    {
        if (! DatabaseTableAvailability::has('ai_memory_deltas')) {
            return;
        }

        $delta = AiMemoryDelta::query()->create([
            'type' => 'preference',
            'claim' => $item->summary,
            'evidence' => [[
                'type' => 'operator_profile_item',
                'id' => $item->id,
                'taxonomy_item_id' => $item->taxonomy_item_id,
            ]],
            'scope' => $item->scope_type.($item->scope_id ? ':'.$item->scope_id : ''),
            'confidence' => $item->confidence,
            'valid_from' => $item->valid_from,
            'valid_until' => $item->valid_until,
            'use_when' => ['operator_profile_context'],
            'do_not_use_when' => ['privacy_class:secret'],
            'requires_confirmation' => true,
            'status' => 'pending',
        ]);

        $item->forceFill(['source_memory_entry_id' => $delta->promoted_memory_entry_id])->save();
    }
}
