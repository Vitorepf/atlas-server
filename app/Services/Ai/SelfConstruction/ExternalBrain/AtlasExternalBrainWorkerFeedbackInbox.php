<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Normalizes terse worker reports into typed feedback facts the originator can use
 * next cycle, making muscle feedback first-class input instead of operator screenshots.
 *
 * OUTCOME TYPES:
 *   success   — task completed; evidence may or may not be present
 *   give_back — worker returned the task with a reason
 *   blocked   — task could not proceed (dependency / environment)
 *   ambiguous — worker note is unclear; cannot be classified reliably
 *
 * NORMALIZATION RULES (first match per outcome_type):
 *   success + non-empty evidence      → evidence_status=verified,   confidence=high,   requires_action=false
 *   success + empty/missing evidence  → evidence_status=unverified,  confidence=medium, needs_review=true,  requires_action=false
 *   give_back                         → evidence_status=verified,   confidence=high,   requires_action=true
 *   blocked                           → evidence_status=verified,   confidence=high,   requires_action=true
 *   ambiguous                         → evidence_status=needs_review, confidence=low,  requires_action=false
 *
 * ALSO marked needs_review=true when:
 *   - note text is absent or < 10 characters (too terse to trust)
 *
 * INPUT (per note):
 *   {
 *     task_id:      string
 *     outcome_type: success | give_back | blocked | ambiguous
 *     note?:        string   (worker's free-text report)
 *     evidence?:    string   (runnable proof reference, e.g. test path that passed)
 *   }
 *
 * OUTPUT (per normalized fact):
 *   {
 *     task_id, outcome_type, evidence_status, confidence,
 *     normalized_reason, needs_review, requires_action
 *   }
 *
 * PURE / DETERMINISTIC / NO I/O.
 */
final class AtlasExternalBrainWorkerFeedbackInbox
{
    public const SCHEMA = 'atlas.external_brain.worker_feedback_inbox.v1';

    public const OUTCOME_SUCCESS   = 'success';
    public const OUTCOME_GIVE_BACK = 'give_back';
    public const OUTCOME_BLOCKED   = 'blocked';
    public const OUTCOME_AMBIGUOUS = 'ambiguous';

    public const EVIDENCE_VERIFIED     = 'verified';
    public const EVIDENCE_UNVERIFIED   = 'unverified';
    public const EVIDENCE_NEEDS_REVIEW = 'needs_review';

    public const CONFIDENCE_HIGH   = 'high';
    public const CONFIDENCE_MEDIUM = 'medium';
    public const CONFIDENCE_LOW    = 'low';

    private const MIN_NOTE_LENGTH = 10;

    /**
     * Normalize a single worker note into a typed feedback fact.
     *
     * @param  array<string,mixed>  $note
     * @return array<string,mixed>
     */
    public function normalize(array $note): array
    {
        $taskId      = (string) ($note['task_id'] ?? '');
        $outcomeType = (string) ($note['outcome_type'] ?? '');
        $noteText    = trim((string) ($note['note'] ?? ''));
        $evidence    = trim((string) ($note['evidence'] ?? ''));

        $tooTerse = strlen($noteText) < self::MIN_NOTE_LENGTH;

        [$evidenceStatus, $confidence, $needsReview, $requiresAction, $normalizedReason]
            = $this->classify($outcomeType, $evidence, $noteText, $tooTerse);

        return [
            'schema'             => self::SCHEMA,
            'task_id'            => $taskId,
            'outcome_type'       => $outcomeType,
            'evidence_status'    => $evidenceStatus,
            'confidence'         => $confidence,
            'normalized_reason'  => $normalizedReason,
            'needs_review'       => $needsReview,
            'requires_action'    => $requiresAction,
        ];
    }

    /**
     * Normalize a batch of worker notes.
     *
     * @param  list<array<string,mixed>>  $notes
     * @return array<string,mixed>
     */
    public function ingest(array $notes): array
    {
        $facts       = [];
        $needsReview = 0;
        $requiresAction = 0;

        foreach ($notes as $note) {
            $fact = $this->normalize($note);
            if ($fact['needs_review']) {
                $needsReview++;
            }
            if ($fact['requires_action']) {
                $requiresAction++;
            }
            $facts[] = $fact;
        }

        return [
            'schema'          => self::SCHEMA,
            'facts'           => $facts,
            'count'           => count($facts),
            'needs_review'    => $needsReview,
            'requires_action' => $requiresAction,
        ];
    }

    /**
     * @return array{string,string,bool,bool,string}
     *         [evidence_status, confidence, needs_review, requires_action, normalized_reason]
     */
    private function classify(
        string $outcomeType,
        string $evidence,
        string $noteText,
        bool   $tooTerse,
    ): array {
        $hasEvidence = $evidence !== '';

        switch ($outcomeType) {
            case self::OUTCOME_SUCCESS:
                if ($hasEvidence && ! $tooTerse) {
                    return [
                        self::EVIDENCE_VERIFIED,
                        self::CONFIDENCE_HIGH,
                        false,
                        false,
                        'Task completed with verifiable evidence.',
                    ];
                }
                // no evidence or too terse → unverified, needs review
                return [
                    self::EVIDENCE_UNVERIFIED,
                    self::CONFIDENCE_MEDIUM,
                    true,
                    false,
                    $hasEvidence
                        ? 'Success note is too terse to trust; evidence present but note lacks detail.'
                        : 'Success claimed but no evidence provided; cannot verify.',
                ];

            case self::OUTCOME_GIVE_BACK:
                $reason = $noteText !== '' ? $noteText : 'No reason provided.';

                return [
                    self::EVIDENCE_VERIFIED,
                    $tooTerse ? self::CONFIDENCE_MEDIUM : self::CONFIDENCE_HIGH,
                    $tooTerse,
                    true,
                    'Worker returned task: '.$reason,
                ];

            case self::OUTCOME_BLOCKED:
                $reason = $noteText !== '' ? $noteText : 'No blocker detail provided.';

                return [
                    self::EVIDENCE_VERIFIED,
                    $tooTerse ? self::CONFIDENCE_MEDIUM : self::CONFIDENCE_HIGH,
                    $tooTerse,
                    true,
                    'Task blocked: '.$reason,
                ];

            case self::OUTCOME_AMBIGUOUS:
                return [
                    self::EVIDENCE_NEEDS_REVIEW,
                    self::CONFIDENCE_LOW,
                    true,
                    false,
                    'Ambiguous outcome; cannot classify without more context.',
                ];

            default:
                return [
                    self::EVIDENCE_NEEDS_REVIEW,
                    self::CONFIDENCE_LOW,
                    true,
                    false,
                    'Unknown outcome type "'.$outcomeType.'"; flagged for review.',
                ];
        }
    }
}
