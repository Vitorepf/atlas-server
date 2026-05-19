<?php

declare(strict_types=1);

namespace App\Services\Ai\LongHorizon\Replay;

use App\Models\AtlasLongHorizonCompactionReceipt;
use App\Models\AtlasLongHorizonContinuationPack;
use App\Models\AtlasLongHorizonReplayManifest;
use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * TEOS-I2 · Replay Manifest builder.
 *
 * Takes an existing `atlas.long_horizon.continuation_pack.v2` (and optionally
 * its `atlas.long_horizon.compaction_receipt.v1`) and emits a
 * `atlas.long_horizon.replay_manifest.v1` row.
 *
 * The manifest is a **provider-independent index** of everything a fresh
 * agent (Claude / Codex / Gemini / Atlas) needs to resume the scope without
 * re-reading the raw chat — required refs, what is available locally, what
 * is missing, what events/evidence to pull, the context_pack_hash to verify
 * integrity, and reader_instructions describing how to consume it.
 *
 * Hard rules:
 *   - NEVER invokes a provider; NEVER reads raw conversation text.
 *   - NEVER copies free-form chat into the manifest — only canonical refs
 *     and the pack's own `state_summary` / `objective` (already sanitized
 *     by the continuation pack composer).
 *   - `hash` is deterministic via {@see AtlasLongHorizonReplayManifest::canonicalHash()}.
 *   - `replay_status` is derived (not user-supplied): ready / partial /
 *     blocked / requires_recovery — see {@see deriveStatus()}.
 *   - Idempotent persistence keyed by `(continuation_pack_id, compaction_receipt_id)`:
 *     same inputs produce the same hash, so re-emission upserts the row.
 */
class LongHorizonReplayManifestBuilder
{
    /** Maximum chars retained from continuation pack textual fields. */
    private const SUMMARY_MAX_LEN = 1800;

    private const REASON_MAX_LEN = 400;

    /**
     * @param  array<string,mixed>  $options
     */
    public function buildFromContinuationPack(
        AtlasLongHorizonContinuationPack $pack,
        ?AtlasLongHorizonCompactionReceipt $receipt = null,
        array $options = [],
    ): AtlasLongHorizonReplayManifest {
        $requiredRefs = $this->collectRequiredRefs($pack, $receipt);
        $availableRefs = $this->collectAvailableRefs($pack, $receipt);
        $missingRefs = $this->diffRefs($requiredRefs, $availableRefs);

        $eventRefs = $this->collectEventRefs($pack, $receipt);
        $evidenceRefs = $this->collectEvidenceRefs($pack, $receipt);

        $replayStatus = $this->deriveStatus($pack, $receipt, $missingRefs);
        $safetyNotes = $this->buildSafetyNotes($pack, $receipt, $missingRefs);
        $readerInstructions = $this->buildReaderInstructions($pack, $receipt, $replayStatus, $missingRefs);
        $summary = $this->providerIndependentSummary($pack, $receipt);

        $payload = [
            'schema_version' => AtlasLongHorizonCanon::REPLAY_MANIFEST_SCHEMA_VERSION,
            'scope_type' => (string) $pack->scope_type,
            'scope_id' => $pack->scope_id !== null ? (string) $pack->scope_id : null,
            'continuation_pack_id' => (string) $pack->id,
            'compaction_receipt_id' => $receipt?->id !== null ? (string) $receipt->id : null,
            'required_refs' => $requiredRefs,
            'available_refs' => $availableRefs,
            'missing_refs' => $missingRefs,
            'event_refs' => $eventRefs,
            'evidence_refs' => $evidenceRefs,
            'context_pack_hash' => $pack->context_pack_hash,
            'reader_instructions' => $readerInstructions,
            'provider_independent_summary' => $summary,
            'safety_notes' => $safetyNotes,
            'replay_status' => $replayStatus,
        ];

        $hash = AtlasLongHorizonReplayManifest::canonicalHash($payload);
        $payload['hash'] = $hash;

        // Idempotent persistence: same continuation_pack + receipt → same hash → upsert.
        $existing = AtlasLongHorizonReplayManifest::query()
            ->where('continuation_pack_id', (string) $pack->id)
            ->where('compaction_receipt_id', $receipt?->id !== null ? (string) $receipt->id : null)
            ->orderByDesc('created_at')
            ->first();

        if ($existing !== null && $existing->hash === $hash) {
            return $existing;
        }

        return AtlasLongHorizonReplayManifest::query()->create([
            'uuid' => (string) Str::uuid(),
            ...$payload,
        ]);
    }

    /**
     * @param  array<string,mixed>  $options
     */
    public function buildById(string $continuationPackUuid, ?string $compactionReceiptUuid = null, array $options = []): AtlasLongHorizonReplayManifest
    {
        $pack = AtlasLongHorizonContinuationPack::query()
            ->where('uuid', $continuationPackUuid)
            ->first();
        if ($pack === null) {
            throw new InvalidArgumentException("continuation_pack not found by uuid [{$continuationPackUuid}]");
        }

        $receipt = null;
        if ($compactionReceiptUuid !== null) {
            $receipt = AtlasLongHorizonCompactionReceipt::query()
                ->where('uuid', $compactionReceiptUuid)
                ->first();
            if ($receipt === null) {
                throw new InvalidArgumentException("compaction_receipt not found by uuid [{$compactionReceiptUuid}]");
            }
        }

        return $this->buildFromContinuationPack($pack, $receipt, $options);
    }

    /**
     * @return list<string>
     */
    private function collectRequiredRefs(
        AtlasLongHorizonContinuationPack $pack,
        ?AtlasLongHorizonCompactionReceipt $receipt,
    ): array {
        $refs = [];

        // Continuation pack semantically declares the refs it needs.
        $manifest = (array) ($pack->context_manifest ?? []);
        foreach ($manifest as $entry) {
            $ref = $this->flattenRef($entry);
            if ($ref !== null) {
                $refs[] = $ref;
            }
        }

        $sourceReceipts = (array) ($pack->source_receipts ?? []);
        foreach ($sourceReceipts as $entry) {
            $ref = $this->flattenRef($entry);
            if ($ref !== null) {
                $refs[] = $ref;
            }
        }

        if ($receipt !== null) {
            foreach ((array) ($receipt->must_keep_items ?? []) as $entry) {
                $ref = $this->flattenRef($entry);
                if ($ref !== null) {
                    $refs[] = $ref;
                }
            }
            foreach ((array) ($receipt->recovery_queries ?? []) as $entry) {
                $ref = $this->flattenRef($entry);
                if ($ref !== null) {
                    $refs[] = 'recovery_query:'.$ref;
                }
            }
        }

        return array_values(array_unique($refs));
    }

    /**
     * @return list<string>
     */
    private function collectAvailableRefs(
        AtlasLongHorizonContinuationPack $pack,
        ?AtlasLongHorizonCompactionReceipt $receipt,
    ): array {
        $refs = [];

        foreach ((array) ($pack->evidence_refs ?? []) as $entry) {
            $ref = $this->flattenRef($entry);
            if ($ref !== null) {
                $refs[] = $ref;
            }
        }

        if ($receipt !== null) {
            foreach ((array) ($receipt->evidence_refs ?? []) as $entry) {
                $ref = $this->flattenRef($entry);
                if ($ref !== null) {
                    $refs[] = $ref;
                }
            }
            foreach ((array) ($receipt->retained_items ?? []) as $entry) {
                $ref = $this->flattenRef($entry);
                if ($ref !== null) {
                    $refs[] = $ref;
                }
            }
        }

        return array_values(array_unique($refs));
    }

    /**
     * @param  list<string>  $required
     * @param  list<string>  $available
     * @return list<string>
     */
    private function diffRefs(array $required, array $available): array
    {
        $availableSet = array_flip($available);
        $missing = [];
        foreach ($required as $ref) {
            if (str_starts_with($ref, 'recovery_query:')) {
                // recovery_query refs are always treated as "open work", not
                // satisfied evidence — they belong in event_refs / missing
                // depending on receipt status, surfaced explicitly below.
                $missing[] = $ref;

                continue;
            }
            if (! isset($availableSet[$ref])) {
                $missing[] = $ref;
            }
        }

        return array_values(array_unique($missing));
    }

    /**
     * @return list<string>
     */
    private function collectEventRefs(
        AtlasLongHorizonContinuationPack $pack,
        ?AtlasLongHorizonCompactionReceipt $receipt,
    ): array {
        $events = [];

        // Decisions act as historical events relevant to replay.
        foreach ((array) ($pack->decisions ?? []) as $decision) {
            $events[] = $this->scopedEventLabel('decision', $decision);
        }
        foreach ((array) ($pack->superseded_decisions ?? []) as $decision) {
            $events[] = $this->scopedEventLabel('superseded_decision', $decision);
        }
        foreach ((array) ($pack->blockers ?? []) as $blocker) {
            $events[] = $this->scopedEventLabel('blocker', $blocker);
        }
        foreach ((array) ($pack->risks ?? []) as $risk) {
            $events[] = $this->scopedEventLabel('risk', $risk);
        }

        if ($receipt !== null) {
            foreach ((array) ($receipt->detected_contradictions ?? []) as $entry) {
                $events[] = $this->scopedEventLabel('contradiction', $entry);
            }
            foreach ((array) ($receipt->stale_risks ?? []) as $entry) {
                $events[] = $this->scopedEventLabel('stale_risk', $entry);
            }
            foreach ((array) ($receipt->discarded_items ?? []) as $entry) {
                $events[] = $this->scopedEventLabel('discarded', $entry);
            }
        }

        return array_values(array_unique(array_filter($events, static fn ($v): bool => $v !== null && $v !== '')));
    }

    /**
     * @return list<string>
     */
    private function collectEvidenceRefs(
        AtlasLongHorizonContinuationPack $pack,
        ?AtlasLongHorizonCompactionReceipt $receipt,
    ): array {
        $refs = [];
        foreach ((array) ($pack->evidence_refs ?? []) as $entry) {
            $ref = $this->flattenRef($entry);
            if ($ref !== null) {
                $refs[] = $ref;
            }
        }
        if ($receipt !== null) {
            foreach ((array) ($receipt->evidence_refs ?? []) as $entry) {
                $ref = $this->flattenRef($entry);
                if ($ref !== null) {
                    $refs[] = $ref;
                }
            }
        }

        return array_values(array_unique($refs));
    }

    /**
     * @param  list<string>  $missingRefs
     */
    private function deriveStatus(
        AtlasLongHorizonContinuationPack $pack,
        ?AtlasLongHorizonCompactionReceipt $receipt,
        array $missingRefs,
    ): string {
        $packMode = (string) ($pack->safe_resume_mode ?? AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE);

        // Hard block — pack already says human is needed.
        if (in_array($packMode, [
            AtlasLongHorizonCanon::SAFE_RESUME_BLOCKED,
            AtlasLongHorizonCanon::SAFE_RESUME_ASK_HUMAN,
            AtlasLongHorizonCanon::SAFE_RESUME_ESCALATE_TO_FORGE,
        ], true)) {
            return AtlasLongHorizonCanon::REPLAY_STATUS_BLOCKED;
        }

        // Receipt declared loss → recovery first.
        if ($receipt !== null) {
            $lossRisk = (string) ($receipt->loss_risk ?? '');
            $hasUnresolved = ! empty((array) ($receipt->unresolved_loss ?? []))
                || ! empty((array) ($receipt->recovery_queries ?? []));
            if ($hasUnresolved && in_array($lossRisk, ['high', 'medium'], true)) {
                return AtlasLongHorizonCanon::REPLAY_STATUS_REQUIRES_RECOVERY;
            }
        }

        // Pack already stale → partial unless explicitly blocked.
        $staleAt = $pack->stale_after;
        if ($staleAt !== null && method_exists($staleAt, 'lt') && $staleAt->lt(Carbon::now())) {
            return AtlasLongHorizonCanon::REPLAY_STATUS_PARTIAL;
        }

        if ($missingRefs !== []) {
            return AtlasLongHorizonCanon::REPLAY_STATUS_PARTIAL;
        }

        if (in_array($packMode, [
            AtlasLongHorizonCanon::SAFE_RESUME_READ_ONLY,
            AtlasLongHorizonCanon::SAFE_RESUME_REVIEW,
            AtlasLongHorizonCanon::SAFE_RESUME_REPAIR,
        ], true)) {
            return AtlasLongHorizonCanon::REPLAY_STATUS_PARTIAL;
        }

        return AtlasLongHorizonCanon::REPLAY_STATUS_READY;
    }

    /**
     * @param  list<string>  $missingRefs
     * @return list<string>
     */
    private function buildSafetyNotes(
        AtlasLongHorizonContinuationPack $pack,
        ?AtlasLongHorizonCompactionReceipt $receipt,
        array $missingRefs,
    ): array {
        $notes = [
            'do_not_invoke_provider_to_complete_manifest',
            'do_not_paste_raw_chat_into_replay_output',
            'verify_context_pack_hash_before_resume',
        ];

        $packMode = (string) ($pack->safe_resume_mode ?? '');
        if ($packMode !== '' && $packMode !== AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE) {
            $notes[] = 'continuation_pack_safe_resume_mode='.$packMode;
        }

        if ($missingRefs !== []) {
            $notes[] = 'downgrade_to_read_only_unless_missing_refs_rehydrated';
        }

        if ($receipt !== null) {
            $lossRisk = (string) ($receipt->loss_risk ?? '');
            if ($lossRisk !== '' && $lossRisk !== 'low') {
                $notes[] = 'compaction_receipt_loss_risk='.$lossRisk;
            }
            if (! empty((array) ($receipt->unresolved_loss ?? []))) {
                $notes[] = 'replay_recovery_queries_before_execute';
            }
            if (! empty((array) ($receipt->detected_contradictions ?? []))) {
                $notes[] = 'resolve_detected_contradictions_before_execute';
            }
        }

        $humanDecisions = (array) ($pack->human_decisions_required ?? []);
        if ($humanDecisions !== []) {
            $notes[] = 'human_decisions_pending:'.count($humanDecisions);
        }

        return array_values(array_unique($notes));
    }

    /**
     * @param  list<string>  $missingRefs
     */
    private function buildReaderInstructions(
        AtlasLongHorizonContinuationPack $pack,
        ?AtlasLongHorizonCompactionReceipt $receipt,
        string $replayStatus,
        array $missingRefs,
    ): string {
        $lines = [];
        $lines[] = '1. Verify integrity by comparing the manifest `context_pack_hash` against the source continuation_pack.';
        $lines[] = '2. Resume the scope using `provider_independent_summary` as the operational ground — do NOT paste raw chat.';
        $lines[] = '3. Pull `event_refs` and `evidence_refs` from local stores; do not re-derive from chat history.';

        if ($missingRefs !== []) {
            $lines[] = '4. Missing required refs detected ('.count($missingRefs).'). Rehydrate them OR downgrade to read_only / review before any execute.';
        } else {
            $lines[] = '4. All required refs satisfied locally.';
        }

        $lines[] = match ($replayStatus) {
            AtlasLongHorizonCanon::REPLAY_STATUS_READY => '5. status=ready: resume execution honoring `next_safe_action` from the pack.',
            AtlasLongHorizonCanon::REPLAY_STATUS_PARTIAL => '5. status=partial: hold execute; treat as read_only/review until gaps resolve.',
            AtlasLongHorizonCanon::REPLAY_STATUS_REQUIRES_RECOVERY => '5. status=requires_recovery: replay recovery_queries from the compaction receipt before any execute.',
            default => '5. status=blocked: ask human or escalate; do NOT execute autonomously.',
        };

        $lines[] = '6. NEVER call a provider to "fill in" this manifest. Provider-independent by design.';

        $next = (string) ($pack->next_safe_action ?? '');
        if ($next !== '') {
            $lines[] = '7. Pack-declared next_safe_action: '.$this->truncate($next, self::REASON_MAX_LEN);
        }

        return implode("\n", $lines);
    }

    private function providerIndependentSummary(
        AtlasLongHorizonContinuationPack $pack,
        ?AtlasLongHorizonCompactionReceipt $receipt,
    ): string {
        $segments = [];
        $segments[] = 'scope='.((string) $pack->scope_type).':'.((string) ($pack->scope_id ?? '∅'));
        $segments[] = 'objective='.$this->truncate((string) $pack->objective, 240);
        $segments[] = 'current_phase='.((string) ($pack->current_phase ?? 'unspecified'));
        $segments[] = 'state='.$this->truncate((string) $pack->state_summary, 600);
        $segments[] = 'safe_resume_mode='.((string) ($pack->safe_resume_mode ?? AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE));
        if ($receipt !== null) {
            $segments[] = 'compaction_loss_risk='.((string) ($receipt->loss_risk ?? 'low'));
            $segments[] = 'compaction_quality_score='.((string) ($receipt->quality_score ?? ''));
        }

        $text = implode(' | ', array_filter($segments, static fn (string $v): bool => $v !== ''));

        return $this->truncate($text, self::SUMMARY_MAX_LEN);
    }

    private function scopedEventLabel(string $kind, mixed $entry): ?string
    {
        $ref = $this->flattenRef($entry);
        if ($ref === null) {
            return null;
        }

        return $kind.':'.$ref;
    }

    private function flattenRef(mixed $entry): ?string
    {
        if (is_string($entry)) {
            $trim = trim($entry);

            return $trim === '' ? null : $this->truncate($trim, 240);
        }
        if (is_array($entry)) {
            // Common shape: ['type'=>x, 'ref'=>y] or ['ref'=>...] or ['id'=>...].
            $type = $entry['type'] ?? $entry['kind'] ?? null;
            $ref = $entry['ref'] ?? $entry['id'] ?? $entry['uuid'] ?? $entry['query'] ?? $entry['title'] ?? null;
            if (is_string($ref) && trim($ref) !== '') {
                $prefix = is_string($type) && trim($type) !== '' ? trim($type).':' : '';

                return $this->truncate($prefix.trim($ref), 240);
            }
        }

        return null;
    }

    private function truncate(string $value, int $max): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max);
    }
}
