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
 *   success + runnable evidence + non-terse note → evidence_status=verified, confidence=high
 *   success + missing/non-runnable evidence      → evidence_status=unverified, needs_review=true
 *   give_back                                    → evidence_status=verified, requires_action=true
 *   blocked                                      → evidence_status=verified, requires_action=true
 *   ambiguous                                    → evidence_status=needs_review, confidence=low
 *
 * ALSO marked needs_review=true when note text is absent or < 10 characters.
 *
 * OUTPUT facts carry additional routing fields:
 *   task_family, worker_id, model_tier  — passed through from input for routing
 *   evidence_strength                   — strong | weak | none | unknown
 *   root_cause_hint                     — deterministic keyword derived from outcome + note
 *   routing_signal                      — where the brain should send this fact next
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

    public const STRENGTH_STRONG  = 'strong';
    public const STRENGTH_WEAK    = 'weak';
    public const STRENGTH_NONE    = 'none';
    public const STRENGTH_UNKNOWN = 'unknown';

    public const ROUTING_COMPOUNDING         = 'route_to_compounding';
    public const ROUTING_REVIEW_QUEUE        = 'route_to_review_queue';
    public const ROUTING_GIVE_BACK_REPAIR    = 'route_to_give_back_repair';
    public const ROUTING_BLOCKER_RESOLUTION  = 'route_to_blocker_resolution';
    public const ROUTING_TRIAGE              = 'route_to_triage';

    private const MIN_NOTE_LENGTH = 10;

    /**
     * Normalize a single worker note into a typed feedback fact.
     *
     * @param  array<string,mixed>  $note
     * @return array<string,mixed>
     */
    public function normalize(array $note): array
    {
        $taskId      = (string) ($note['task_id']      ?? '');
        $outcomeType = (string) ($note['outcome_type'] ?? '');
        $noteText    = trim((string) ($note['note']    ?? ''));
        $evidence    = trim((string) ($note['evidence'] ?? ''));
        $taskFamily  = (string) ($note['task_family']  ?? '');
        $workerId    = (string) ($note['worker_id']    ?? '');
        $modelTier   = (string) ($note['model_tier']   ?? '');

        $tooTerse    = strlen($noteText) < self::MIN_NOTE_LENGTH;
        $runnable    = $this->isRunnableEvidence($evidence);

        [$evidenceStatus, $confidence, $needsReview, $requiresAction, $normalizedReason]
            = $this->classify($outcomeType, $evidence, $noteText, $tooTerse, $runnable);

        $isVerifiedSuccess = $outcomeType === self::OUTCOME_SUCCESS && ! $needsReview;

        return [
            'schema'             => self::SCHEMA,
            'task_id'            => $taskId,
            'outcome_type'       => $outcomeType,
            'task_family'        => $taskFamily,
            'worker_id'          => $workerId,
            'model_tier'         => $modelTier,
            'evidence_status'    => $evidenceStatus,
            'confidence'         => $confidence,
            'evidence_strength'  => $this->evidenceStrength($evidence, $runnable, $tooTerse),
            'root_cause_hint'    => $this->rootCauseHint($outcomeType, $noteText, $isVerifiedSuccess),
            'routing_signal'     => $this->routingSignal($outcomeType, $needsReview),
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
        $facts          = [];
        $needsReview    = 0;
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
        bool   $runnable,
    ): array {
        $hasEvidence = $evidence !== '';

        switch ($outcomeType) {
            case self::OUTCOME_SUCCESS:
                if ($hasEvidence && $runnable && ! $tooTerse) {
                    return [
                        self::EVIDENCE_VERIFIED,
                        self::CONFIDENCE_HIGH,
                        false,
                        false,
                        'Task completed with verifiable evidence.',
                    ];
                }
                // shallow-success: no runnable evidence or too terse — never treat as green learning
                return [
                    self::EVIDENCE_UNVERIFIED,
                    self::CONFIDENCE_MEDIUM,
                    true,
                    false,
                    $hasEvidence
                        ? 'Success note is too terse or evidence is not runnable; cannot verify as green.'
                        : 'Success claimed but no runnable evidence provided; cannot verify.',
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

    private function isRunnableEvidence(string $evidence): bool
    {
        return $evidence !== '' && (
            str_contains($evidence, 'vendor/bin/')
            || str_contains($evidence, 'artisan')
            || str_contains($evidence, '/opt/homebrew/bin/php')
            || str_ends_with($evidence, '.php')
        );
    }

    private function evidenceStrength(string $evidence, bool $runnable, bool $tooTerse): string
    {
        if ($evidence === '') {
            return self::STRENGTH_NONE;
        }
        if ($runnable && ! $tooTerse) {
            return self::STRENGTH_STRONG;
        }
        return self::STRENGTH_WEAK;
    }

    private function rootCauseHint(string $outcomeType, string $noteText, bool $verifiedSuccess): ?string
    {
        return match ($outcomeType) {
            self::OUTCOME_SUCCESS    => $verifiedSuccess ? null : 'shallow_success_no_runnable_evidence',
            self::OUTCOME_GIVE_BACK  => $this->giveBackHint($noteText),
            self::OUTCOME_BLOCKED    => $this->blockerHint($noteText),
            self::OUTCOME_AMBIGUOUS  => 'unclear_outcome',
            default                  => 'unknown_outcome_type',
        };
    }

    private function giveBackHint(string $note): string
    {
        $lc = strtolower($note);
        if (str_contains($lc, 'scope') || str_contains($lc, 'too wide')) {
            return 'scope_too_wide';
        }
        if (str_contains($lc, 'forbidden')) {
            return 'forbidden_files';
        }
        if (str_contains($lc, 'dependency') || str_contains($lc, 'missing')) {
            return 'dependency_missing';
        }
        if (str_contains($lc, 'timeout')) {
            return 'timeout';
        }
        return 'unspecified_give_back';
    }

    private function blockerHint(string $note): string
    {
        $lc = strtolower($note);
        if (str_contains($lc, 'migration')) {
            return 'migration_failure';
        }
        if (str_contains($lc, 'ci') || str_contains($lc, 'environment')) {
            return 'environment_error';
        }
        if (str_contains($lc, 'dependency')) {
            return 'dependency_missing';
        }
        return 'unspecified_blocker';
    }

    private function routingSignal(string $outcomeType, bool $needsReview): string
    {
        return match (true) {
            $outcomeType === self::OUTCOME_SUCCESS && ! $needsReview => self::ROUTING_COMPOUNDING,
            $outcomeType === self::OUTCOME_SUCCESS && $needsReview   => self::ROUTING_REVIEW_QUEUE,
            $outcomeType === self::OUTCOME_GIVE_BACK                 => self::ROUTING_GIVE_BACK_REPAIR,
            $outcomeType === self::OUTCOME_BLOCKED                   => self::ROUTING_BLOCKER_RESOLUTION,
            default                                                   => self::ROUTING_TRIAGE,
        };
    }
}
