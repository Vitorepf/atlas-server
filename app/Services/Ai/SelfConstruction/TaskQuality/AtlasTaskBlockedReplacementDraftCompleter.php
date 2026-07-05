<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskQuality;

use App\Services\Ai\SelfConstruction\WriteSetOverlap;

/**
 * Pure, facts-only completer that turns review_recommended blocked-respec drafts (from
 * {@see \App\Console\Commands\AtlasTaskBlockedRespecPlanCommand}) into can_submit replacement drafts
 * WHENEVER field-recovery evidence is strong enough — never by trusting the recovery blindly. Closes
 * the gap where the plan reports drafts missing allowed_files/acceptance_criteria/required_evidence but
 * cannot itself produce a safe packet. Does not enqueue, mutate queue records, retire packets, call
 * providers, write files, or run git — it only returns a proposal payload.
 *
 * Input item shape: {draft: array, field_recovery: {objective?, allowed_files?,
 *   acceptance_criteria?, required_evidence?, trust:'trusted'|'ambiguous'|'untrusted',
 *   confidence:float, is_test_only?, requires_human?, forbidden_targets?}}
 *
 * objective is filled from field_recovery when the draft is missing it (same conservative
 * merge as the other three fields), but is never required to submit — replacement drafts
 * predating this field must not regress. Test-only, forbidden-target and human-dependent
 * drafts are always refused regardless of trust/confidence, since no amount of field recovery
 * makes an unsafe replacement shape safe to submit.
 */
final class AtlasTaskBlockedReplacementDraftCompleter
{
    public const SCHEMA = 'atlas.self_construction.task_quality.blocked_replacement_draft_completer.v1';

    public const TRUST_TRUSTED = 'trusted';

    private const REQUIRED_FIELDS = ['allowed_files', 'acceptance_criteria', 'required_evidence'];

    private const FILLABLE_FIELDS = ['objective', 'allowed_files', 'acceptance_criteria', 'required_evidence'];

    private const CONFIDENCE_THRESHOLD = 0.75;

    /** @var non-empty-string */
    private const IMPLEMENTATION_PREFIX = 'app/';

    /** @var non-empty-string */
    private const TEST_PREFIX = 'tests/';

    /** @var list<string> */
    private const FORBIDDEN_TARGET_KEYWORDS = ['Brain', 'Gateway', 'Harness', 'Core', 'Immune'];

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public function complete(array $items): array
    {
        $completedDrafts = [];
        $totals = [
            'can_submit' => 0,
            'missing_fields' => 0,
            'low_confidence' => 0,
            'untrusted' => 0,
            'forbidden_or_ambiguous' => 0,
        ];

        foreach ($items as $item) {
            $draft = (array) ($item['draft'] ?? []);
            $fieldRecovery = (array) ($item['field_recovery'] ?? []);
            $completed = $this->completeOne($draft, $fieldRecovery);
            $completedDrafts[] = $completed;

            if ($completed['can_submit']) {
                $totals['can_submit']++;
            }
            if ($completed['missing_fields'] !== []) {
                $totals['missing_fields']++;
            }
            foreach ($completed['refusal_reasons'] as $refusalReason) {
                if (str_starts_with((string) $refusalReason, 'confidence_below_threshold')) {
                    $totals['low_confidence']++;
                    break;
                }
            }

            $trust = (string) ($fieldRecovery['trust'] ?? 'untrusted');
            if ($trust === 'untrusted') {
                $totals['untrusted']++;
            } elseif ($trust !== self::TRUST_TRUSTED) {
                $totals['forbidden_or_ambiguous']++;
            }
        }

        return [
            'schema' => self::SCHEMA,
            'completed_drafts' => $completedDrafts,
            'mutates_queue' => false,
            'burn_down_report' => array_merge(['total' => count($items)], $totals),
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
        foreach (self::FILLABLE_FIELDS as $field) {
            $current = $merged[$field] ?? null;
            $missing = $current === null || $current === [] || $current === '';
            $recoveredRaw = $fieldRecovery[$field] ?? null;
            $recovered = $field === 'objective' ? trim((string) $recoveredRaw) : (array) ($recoveredRaw ?? []);
            $recoveredIsUseful = $field === 'objective' ? $recovered !== '' : $recovered !== [];
            if ($missing && $recoveredIsUseful) {
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

        // AC2: safety refusals — no amount of trusted, high-confidence field recovery makes an
        // unsafe replacement shape submittable.
        if ((bool) ($draft['is_test_only'] ?? $fieldRecovery['is_test_only'] ?? false)) {
            $refusalReasons[] = 'test_only_replacement_refused';
        }
        if ((bool) ($draft['requires_human'] ?? $fieldRecovery['requires_human'] ?? false)) {
            $refusalReasons[] = 'human_dependent_replacement_refused';
        }
        $forbiddenTargets = $this->matchedForbiddenTargets(
            (array) ($merged['allowed_files'] ?? []),
            array_values(array_filter(array_map('strval', (array) ($fieldRecovery['forbidden_targets'] ?? [])))),
        );
        foreach ($forbiddenTargets as $target) {
            $refusalReasons[] = 'forbidden_target_replacement_refused:'.$target;
        }

        $allowedFilesList = (array) ($merged['allowed_files'] ?? []);
        $hasImpl = $this->hasPrefix($allowedFilesList, self::IMPLEMENTATION_PREFIX);
        $hasTest = $this->hasPrefix($allowedFilesList, self::TEST_PREFIX);
        if (! $hasImpl) {
            $refusalReasons[] = 'missing_implementation_scope';
        }
        if (! $hasTest) {
            $refusalReasons[] = 'missing_test_scope';
        }

        $canSubmit = $missingFields === []
            && $trust === self::TRUST_TRUSTED
            && $confidence >= self::CONFIDENCE_THRESHOLD
            && $forbiddenTargets === []
            && ! (bool) ($draft['is_test_only'] ?? $fieldRecovery['is_test_only'] ?? false)
            && ! (bool) ($draft['requires_human'] ?? $fieldRecovery['requires_human'] ?? false)
            && $hasImpl
            && $hasTest;

        $repairHints = [];
        if (! $canSubmit) {
            if ($missingFields !== []) {
                $repairHints[] = 'Provide missing required fields: '.implode(', ', $missingFields);
            }
            if (! $hasImpl) {
                $repairHints[] = 'Add at least one implementation file (e.g. app/...) to allowed_files';
            }
            if (! $hasTest) {
                $repairHints[] = 'Add at least one focused test file (e.g. tests/...) to allowed_files';
            }
            foreach ($forbiddenTargets as $ft) {
                $repairHints[] = 'Remove forbidden target: '.$ft;
            }
            if ((bool) ($draft['is_test_only'] ?? $fieldRecovery['is_test_only'] ?? false)) {
                $repairHints[] = 'Remove test-only flag — replacement must include implementation scope';
            }
            if ((bool) ($draft['requires_human'] ?? $fieldRecovery['requires_human'] ?? false)) {
                $repairHints[] = 'Remove human-dependent flag — replacement must be worker-safe';
            }
            if ($trust !== self::TRUST_TRUSTED) {
                $repairHints[] = 'Strengthen field-recovery evidence to trusted';
            }
            if ($confidence < self::CONFIDENCE_THRESHOLD) {
                $repairHints[] = 'Raise field-recovery confidence above threshold (≥ '.self::CONFIDENCE_THRESHOLD.')';
            }
        }

        return [
            'task_packet_id' => $sourceId,
            'replacement_task_packet_id' => $canSubmit ? $this->replacementId($sourceId, $merged) : null,
            'can_submit' => $canSubmit,
            'missing_fields' => $missingFields,
            'missing_fact_blockers' => array_map(static fn (string $f): string => 'missing_fact:'.$f, $missingFields),
            'refusal_reasons' => $refusalReasons,
            'repair_hints' => $repairHints,
            'has_implementation_scope' => $hasImpl,
            'has_test_scope' => $hasTest,
            'objective' => $merged['objective'] ?? '',
            'allowed_files' => $allowedFilesList,
            'acceptance_criteria' => $merged['acceptance_criteria'] ?? [],
            'required_evidence' => $merged['required_evidence'] ?? [],
        ];
    }

    /**
     * A path is a forbidden target when it matches a caller-supplied forbidden_targets list, or
     * when it touches a core-system keyword — a replacement draft never gets to invent its way
     * around either.
     *
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $forbiddenTargets
     * @return list<string>
     */
    private function matchedForbiddenTargets(array $allowedFiles, array $forbiddenTargets): array
    {
        $matched = [];
        foreach ($allowedFiles as $path) {
            $path = (string) $path;
            if (WriteSetOverlap::collidingPaths([$path], $forbiddenTargets) !== []) {
                $matched[] = $path;

                continue;
            }
            foreach (self::FORBIDDEN_TARGET_KEYWORDS as $keyword) {
                if (str_contains($path, $keyword)) {
                    $matched[] = $path;

                    break;
                }
            }
        }

        return array_values(array_unique($matched));
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

    /**
     * @param  list<string>  $paths
     * @param  non-empty-string  $prefix
     */
    private function hasPrefix(array $paths, string $prefix): bool
    {
        foreach ($paths as $path) {
            if (str_starts_with((string) $path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
