<?php

namespace App\Services\Ai\Hermes;

use App\Models\AiMemoryDelta;
use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Memory\AtlasMemoryDeltaPromotionService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * AREA D — Hermes Memory Review Gate.
 *
 * Reviews the pending Hermes-sourced `ai_memory_deltas` (produced by
 * {@see HermesMemoryAdapter}) and decides — under an explicit Atlas gate —
 * which candidates may be promoted into canonical Atlas memory
 * (`atlas_memory_entries`), which are deduped/superseded, which are rejected
 * and which are held for operator confirmation.
 *
 * Governance invariants (fail-closed):
 *  - `operator_confirmed` defaults to FALSE, so nothing promotes; every
 *    eligible candidate is held for confirmation.
 *  - NEVER promote on low confidence, class noise, suggested-discard, or a
 *    conflict with canonical docs.
 *  - Promotion happens ONLY through {@see AtlasMemoryDeltaPromotionService}
 *    (no direct AtlasMemoryEntry::create); Atlas remains the memory authority.
 *  - Every receipt carries the Hermes result-packet refs and seals with a
 *    deterministic `receipt_hash` as the LAST statement on every path.
 */
class HermesMemoryReviewGate
{
    use HermesAdapterReceipt;

    private const HARD_CAP = 100;

    public function __construct(private readonly AtlasMemoryDeltaPromotionService $promotion) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function review(array $options = []): array
    {
        $operatorConfirmed = (bool) ($options['operator_confirmed'] ?? false);
        $receipt = [
            'schema_version' => 'atlas.hermes.memory_gate_receipt.v1',
            'gate' => 'hermes_memory_review_gate',
            'canonical_memory' => 'atlas',
            'atlas_memory_store' => 'atlas_memory_entries',
            'memory_authority' => 'atlas',
            'operator_confirmed' => $operatorConfirmed,
            'promotion_allowed_now' => false,
            'candidate_count' => 0,
            'eligible_candidate_count' => 0,
            'promoted_count' => 0,
            'rejected_count' => 0,
            'deduped_count' => 0,
            'held_count' => 0,
            'skipped_count' => 0,
            'promoted_delta_ids' => [],
            'promoted_memory_entry_ids' => [],
            'rejected_delta_ids' => [],
            'deduped_delta_ids' => [],
            'held_delta_ids' => [],
            'skipped_candidates' => [],
            'decisions' => [],
            'hermes_result_packet_refs' => [],
            'status' => 'no_candidates',
        ];

        if (! DatabaseTableAvailability::has('ai_memory_deltas')) {
            $receipt['status'] = 'memory_gate_unavailable';

            return $this->withReceiptHash($receipt);
        }

        $deltas = $this->pendingHermesDeltas($options);
        $receipt['candidate_count'] = $deltas->count();

        if ($deltas->isEmpty()) {
            return $this->withReceiptHash($receipt);
        }

        $rejectIds = array_map('strval', (array) ($options['reject_delta_ids'] ?? []));

        foreach ($deltas as $delta) {
            $packetRef = $this->hermesPacketRef($delta);
            $receipt['hermes_result_packet_refs'][] = $packetRef;
            $candidateId = $packetRef['candidate_id'];

            if (! $this->isHermesDelta($delta)) {
                $receipt['skipped_count']++;
                $receipt['skipped_candidates'][] = [
                    'candidate_id' => $candidateId,
                    'reason' => 'not_a_hermes_delta',
                ];
                $receipt['decisions'][] = [
                    'delta_id' => $delta->id,
                    'candidate_id' => $candidateId,
                    'decision' => 'skipped',
                    'reason' => 'not_a_hermes_delta',
                ];

                continue;
            }

            if (in_array((string) $delta->id, $rejectIds, true)) {
                $this->markRejected($delta);
                $receipt['rejected_count']++;
                $receipt['rejected_delta_ids'][] = $delta->id;
                $receipt['decisions'][] = [
                    'delta_id' => $delta->id,
                    'candidate_id' => $candidateId,
                    'decision' => 'rejected',
                    'reason' => 'operator_rejected',
                ];

                continue;
            }

            if (($dupId = $this->isDuplicate($delta)) !== null) {
                $this->markSuperseded($delta, $dupId);
                $receipt['deduped_count']++;
                $receipt['deduped_delta_ids'][] = $delta->id;
                $receipt['decisions'][] = [
                    'delta_id' => $delta->id,
                    'candidate_id' => $candidateId,
                    'decision' => 'deduped',
                    'reason' => 'duplicate_of_canonical_memory',
                    'superseded_by' => $dupId,
                ];

                continue;
            }

            $receipt['eligible_candidate_count']++;

            if (($noPromote = $this->noPromoteReason($delta, $options)) !== null) {
                $receipt['held_count']++;
                $receipt['held_delta_ids'][] = $delta->id;
                $receipt['decisions'][] = [
                    'delta_id' => $delta->id,
                    'candidate_id' => $candidateId,
                    'decision' => 'held',
                    'reason' => $noPromote,
                ];

                continue;
            }

            if (! $operatorConfirmed) {
                $receipt['held_count']++;
                $receipt['held_delta_ids'][] = $delta->id;
                $receipt['decisions'][] = [
                    'delta_id' => $delta->id,
                    'candidate_id' => $candidateId,
                    'decision' => 'held',
                    'reason' => 'operator_confirmation_required',
                ];

                continue;
            }

            $entry = $this->promoteDelta($delta);
            $receipt['promoted_count']++;
            $receipt['promoted_delta_ids'][] = $delta->id;
            $receipt['promoted_memory_entry_ids'][] = $entry->id;
            $receipt['decisions'][] = [
                'delta_id' => $delta->id,
                'candidate_id' => $candidateId,
                'decision' => 'promoted',
                'reason' => 'operator_confirmed_promotion',
                'memory_entry_id' => $entry->id,
            ];
        }

        $receipt['promotion_allowed_now'] = $operatorConfirmed && $receipt['promoted_count'] > 0;
        $receipt['status'] = $this->resolveStatus($receipt, $operatorConfirmed);

        return $this->withReceiptHash($receipt);
    }

    /**
     * @param  array<string,mixed>  $receipt
     */
    private function resolveStatus(array $receipt, bool $operatorConfirmed): string
    {
        if ($receipt['promoted_count'] > 0) {
            return 'promoted';
        }

        if (! $operatorConfirmed && $receipt['held_count'] > 0) {
            return 'held_for_confirmation';
        }

        return 'reviewed';
    }

    /**
     * @param  array<string,mixed>  $options
     * @return Collection<int,AiMemoryDelta>
     */
    private function pendingHermesDeltas(array $options): Collection
    {
        $query = AiMemoryDelta::query()->where('status', 'pending');

        $deltaIds = array_values(array_filter(
            array_map('strval', (array) ($options['delta_ids'] ?? [])),
            static fn (string $id): bool => $id !== '',
        ));
        if ($deltaIds !== []) {
            $query->whereIn('id', $deltaIds);
        }

        $scope = $this->string($options['scope'] ?? null, 255);
        if ($scope !== null) {
            $query->where('scope', $scope);
        }

        $cap = $this->cap($options);

        $deltas = $query
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($cap)
            ->get();

        // Default scan path is Hermes-only: foreign deltas are owned by other
        // gates and never enter this receipt. When the operator targets explicit
        // `delta_ids`, keep non-Hermes deltas so the loop surfaces an auditable
        // `not_a_hermes_delta` skip instead of silently dropping them.
        if ($deltaIds === []) {
            $deltas = $deltas->filter(fn (AiMemoryDelta $delta): bool => $this->isHermesDelta($delta));
        }

        return $deltas->values();
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function cap(array $options): int
    {
        $limit = $options['limit'] ?? null;
        if (! is_numeric($limit)) {
            return self::HARD_CAP;
        }

        $limit = (int) $limit;

        return $limit > 0 ? min($limit, self::HARD_CAP) : self::HARD_CAP;
    }

    private function isHermesDelta(AiMemoryDelta $delta): bool
    {
        foreach ($this->evidenceItems($delta) as $item) {
            if (($item['kind'] ?? null) === 'hermes_result_packet') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string,mixed>
     */
    private function hermesPacketRef(AiMemoryDelta $delta): array
    {
        foreach ($this->evidenceItems($delta) as $item) {
            if (($item['kind'] ?? null) !== 'hermes_result_packet') {
                continue;
            }

            return [
                'candidate_id' => $this->string($item['candidate_id'] ?? null, 120) ?: 'hermes_memory_candidate_unknown',
                'result_id' => $item['result_id'] ?? null,
                'result_hash' => $item['result_hash'] ?? null,
                'mission_id' => $item['mission_id'] ?? null,
                'mission_hash' => $item['mission_hash'] ?? null,
                'cli_invocation_hash' => $item['cli_invocation_hash'] ?? null,
            ];
        }

        return [
            'candidate_id' => 'hermes_memory_candidate_unknown',
            'result_id' => null,
            'result_hash' => null,
            'mission_id' => null,
            'mission_hash' => null,
            'cli_invocation_hash' => null,
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function noPromoteReason(AiMemoryDelta $delta, array $options): ?string
    {
        if ((float) $delta->confidence < 0.6) {
            return 'low_confidence';
        }

        if ($this->conflictsWithCanonicalDocs($delta, $options)) {
            return 'conflicts_with_canonical_docs';
        }

        foreach ($this->doNotUseWhen($delta) as $marker) {
            $needle = strtolower($marker);
            if (str_contains($needle, 'classified as noise')
                || str_contains($needle, 'class noise')
                || str_contains($needle, 'is noise')
            ) {
                return 'class_noise';
            }
            if (str_contains($needle, 'suggested action was discard')
                || (str_contains($needle, 'suggested_action') && str_contains($needle, 'discard'))
                || str_contains($needle, 'suggested action discard')
            ) {
                return 'suggested_action_discard';
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function conflictsWithCanonicalDocs(AiMemoryDelta $delta, array $options): bool
    {
        $conflictIds = array_map('strval', (array) ($options['conflicts_with_canonical_docs_ids'] ?? []));
        if (in_array((string) $delta->id, $conflictIds, true)) {
            return true;
        }

        foreach ($this->doNotUseWhen($delta) as $marker) {
            if (str_contains(strtolower($marker), 'conflicts with canonical')) {
                return true;
            }
        }

        return false;
    }

    private function isDuplicate(AiMemoryDelta $delta): ?string
    {
        $promotedSibling = AiMemoryDelta::query()
            ->where('status', 'promoted')
            ->where('claim', $delta->claim)
            ->where('scope', $delta->scope)
            ->whereKeyNot($delta->id)
            ->orderBy('created_at')
            ->first();

        if ($promotedSibling instanceof AiMemoryDelta) {
            if (is_string($promotedSibling->promoted_memory_entry_id) && $promotedSibling->promoted_memory_entry_id !== '') {
                return $promotedSibling->promoted_memory_entry_id;
            }

            return $promotedSibling->id;
        }

        if (DatabaseTableAvailability::has('atlas_memory_entries')) {
            $promotedDeltaIds = AiMemoryDelta::query()
                ->where('status', 'promoted')
                ->where('claim', $delta->claim)
                ->where('scope', $delta->scope)
                ->whereKeyNot($delta->id)
                ->pluck('id')
                ->all();

            if ($promotedDeltaIds !== []) {
                $entry = AtlasMemoryEntry::query()
                    ->where('source_type', 'ai_memory_delta')
                    ->whereIn('source_id', $promotedDeltaIds)
                    ->first();

                if ($entry instanceof AtlasMemoryEntry) {
                    return $entry->id;
                }
            }
        }

        return null;
    }

    private function promoteDelta(AiMemoryDelta $delta): AtlasMemoryEntry
    {
        $delta->update(['status' => 'accepted']);

        return $this->promotion->promote($delta, [
            'promoted_by' => 'hermes_memory_review_gate',
            'metadata' => [
                'hermes_memory_gate_review' => array_merge(
                    $this->hermesPacketRef($delta),
                    ['operator_confirmed' => true],
                ),
            ],
        ]);
    }

    private function markRejected(AiMemoryDelta $delta): void
    {
        $delta->update(['status' => 'rejected']);
    }

    private function markSuperseded(AiMemoryDelta $delta, string $supersededById): void
    {
        $delta->update([
            'status' => 'superseded',
            'superseded_by' => $supersededById,
        ]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function evidenceItems(AiMemoryDelta $delta): array
    {
        $evidence = $delta->evidence;

        return is_array($evidence)
            ? array_values(array_filter($evidence, static fn (mixed $item): bool => is_array($item)))
            : [];
    }

    /**
     * @return array<int,string>
     */
    private function doNotUseWhen(AiMemoryDelta $delta): array
    {
        $markers = $delta->do_not_use_when;
        if (! is_array($markers)) {
            return [];
        }

        $out = [];
        foreach ($markers as $marker) {
            try {
                if (is_string($marker) || is_numeric($marker)) {
                    $out[] = (string) $marker;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return $out;
    }

    private function string(mixed $value, int $limit): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $limit, '');
    }
}
