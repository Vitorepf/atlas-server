<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskQuality;

/**
 * Pure, facts-only completer that turns review_recommended blocked-respec drafts (from
 * {@see \App\Console\Commands\AtlasTaskBlockedRespecPlanCommand}) into can_submit replacement drafts
 * WHENEVER field-recovery evidence is strong enough — never by trusting the recovery blindly. Closes
 * the gap where the plan reports drafts missing allowed_files/acceptance_criteria/required_evidence but
 * cannot itself produce a safe packet. Does not enqueue, mutate queue records, retire packets, call
 * providers, write files, or run git — it only returns a proposal payload.
 *
 * Input item shape: {draft: array, field_recovery: {allowed_files?, acceptance_criteria?,
 *   required_evidence?, trust:'trusted'|'ambiguous'|'untrusted', confidence:float}}
 */
final class AtlasTaskBlockedReplacementDraftCompleter
{
    public const SCHEMA = 'atlas.self_construction.task_quality.blocked_replacement_draft_completer.v1';

    public const TRUST_TRUSTED = 'trusted';

    private const REQUIRED_FIELDS = ['allowed_files', 'acceptance_criteria', 'required_evidence'];

    private const CONFIDENCE_THRESHOLD = 0.75;

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public function complete(array $items): array
    {
        $completedDrafts = [];
        foreach ($items as $item) {
            $draft = (array) ($item['draft'] ?? []);
            $fieldRecovery = (array) ($item['field_recovery'] ?? []);
            $completedDrafts[] = $this->completeOne($draft, $fieldRecovery);
        }

        return [
            'schema' => self::SCHEMA,
            'completed_drafts' => $completedDrafts,
            'mutates_queue' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $fieldRecovery
     * @return array<string, mixed>
     */
    private function completeOne(array $draft, array $fieldRecovery): array
    {
        $sourceId = (string) ($draft['task_packet_id'] ?? '');
        $trust = (string) ($fieldRecovery['trust'] ?? 'untrusted');
        $confidence = (float) ($fieldRecovery['confidence'] ?? 0.0);

        $merged = $draft;
        foreach (self::REQUIRED_FIELDS as $field) {
            $missing = ! isset($merged[$field]) || $merged[$field] === [] || $merged[$field] === '';
            $recovered = (array) ($fieldRecovery[$field] ?? []);
            if ($missing && $recovered !== []) {
                $merged[$field] = $recovered;
            }
        }

        $missingFields = array_values(array_filter(
            self::REQUIRED_FIELDS,
            static fn (string $f): bool => ! isset($merged[$f]) || $merged[$f] === [] || $merged[$f] === '',
        ));

        $refusalReasons = [];
        if ($trust !== self::TRUST_TRUSTED) {
            $refusalReasons[] = 'field_recovery_trust_not_trusted:'.$trust;
        }
        if ($confidence < self::CONFIDENCE_THRESHOLD) {
            $refusalReasons[] = 'confidence_below_threshold:'.$confidence;
        }
        if ($missingFields !== []) {
            $refusalReasons[] = 'missing_required_fields:'.implode(',', $missingFields);
        }

        $canSubmit = $missingFields === [] && $trust === self::TRUST_TRUSTED && $confidence >= self::CONFIDENCE_THRESHOLD;

        return [
            'task_packet_id' => $sourceId,
            'replacement_task_packet_id' => $canSubmit ? $this->replacementId($sourceId, $merged) : null,
            'can_submit' => $canSubmit,
            'missing_fields' => $missingFields,
            'refusal_reasons' => $refusalReasons,
            'allowed_files' => $merged['allowed_files'] ?? [],
            'acceptance_criteria' => $merged['acceptance_criteria'] ?? [],
            'required_evidence' => $merged['required_evidence'] ?? [],
        ];
    }

    /**
     * Deterministically derives a replacement id from the source packet id and a fingerprint of the
     * repaired content, so the same (source, repaired fields) pair always yields the same replacement
     * id, and the id can never collide with the source id (it always carries the -replacement- suffix).
     *
     * @param  array<string, mixed>  $merged
     */
    private function replacementId(string $sourceId, array $merged): string
    {
        $fingerprint = substr(hash('sha256', $sourceId.'|'.json_encode($merged, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 12);

        return $sourceId.'-replacement-'.$fingerprint;
    }
}
