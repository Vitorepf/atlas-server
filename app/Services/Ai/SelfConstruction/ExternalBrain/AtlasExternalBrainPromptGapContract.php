<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure prompt-text contract for generated External Brain prompts.
 *
 * A real Codex session stopped origination early because the prompt let it treat a
 * merely-comfortable queue depth as a stopping condition, instead of continuously
 * searching for high-value gap closure. This contract rejects that failure mode by
 * evaluating the literal prompt TEXT (never a live model call) against:
 *
 *   1. FORBIDDEN — any phrase that frames "queue depth is comfortable / sufficient"
 *      as a valid reason to stop origination.
 *   2. REQUIRED  — explicit priority clauses for integration gaps, closed-loop proof,
 *      and anti-proxy task quality.
 *   3. REQUIRED  — an escalation clause that forces deeper search or a design-path
 *      escalation specifically when local high-value candidates are exhausted.
 *
 * Pure. No I/O, no provider calls, deterministic on identical input text.
 */
final class AtlasExternalBrainPromptGapContract
{
    public const SCHEMA = 'atlas.external_brain.prompt_gap_contract.v1';

    public const BLOCKER_COMFORTABLE_QUEUE_STOP = 'allows_comfortable_queue_stopping';
    public const BLOCKER_MISSING_CLAUSE_PREFIX  = 'missing_required_clause:';

    /** Any of these substrings frames a comfortable queue depth as a stop condition. */
    private const COMFORTABLE_WAIT_PATTERNS = [
        'queue depth is sufficient',
        'queue depth looks comfortable',
        'sufficient_depth',
        'queue is comfortable',
        'ok to stop once the queue is healthy',
        'stop because the queue',
        'stop once queue depth',
    ];

    /**
     * Each entry: clause name => list of variant substrings, ANY of which satisfies it.
     * All clauses are mandatory.
     */
    private const REQUIRED_PRIORITY_CLAUSES = [
        'integration_gap_priority' => ['integration gap'],
        'closed_loop_proof'        => ['closed-loop proof', 'closed loop proof'],
        'anti_proxy_task_quality'  => ['anti-proxy', 'anti proxy'],
    ];

    /** Phrases indicating local candidates are exhausted. */
    private const LOCAL_EXHAUSTION_PHRASES = [
        'no local high-value task',
        'local candidates dry up',
        'no local candidates',
        'local supply is exhausted',
    ];

    /** Phrases indicating the required escalation action. */
    private const ESCALATION_PHRASES = [
        'deeper search',
        'design-path escalation',
        'design path escalation',
    ];

    /**
     * @return array{schema:string, accepted:bool, blockers:list<string>, missing_required_clauses:list<string>}
     */
    public function evaluate(string $promptText): array
    {
        $normalized = strtolower($promptText);
        $blockers   = [];

        foreach (self::COMFORTABLE_WAIT_PATTERNS as $pattern) {
            if (str_contains($normalized, $pattern)) {
                $blockers[] = self::BLOCKER_COMFORTABLE_QUEUE_STOP.':'.$pattern;
            }
        }

        $missingClauses = [];
        foreach (self::REQUIRED_PRIORITY_CLAUSES as $clause => $variants) {
            if (! $this->containsAny($normalized, $variants)) {
                $missingClauses[] = $clause;
                $blockers[]       = self::BLOCKER_MISSING_CLAUSE_PREFIX.$clause;
            }
        }

        // Gap-closure escalation: BOTH a local-exhaustion trigger AND a concrete escalation
        // action must be present — mentioning "deeper search" alone, unmoored from the
        // condition that triggers it, is not a real escalation clause.
        $hasExhaustionTrigger = $this->containsAny($normalized, self::LOCAL_EXHAUSTION_PHRASES);
        $hasEscalationAction  = $this->containsAny($normalized, self::ESCALATION_PHRASES);
        if (! $hasExhaustionTrigger || ! $hasEscalationAction) {
            $missingClauses[] = 'deeper_search_escalation';
            $blockers[]       = self::BLOCKER_MISSING_CLAUSE_PREFIX.'deeper_search_escalation';
        }

        return [
            'schema'                   => self::SCHEMA,
            'accepted'                 => $blockers === [],
            'blockers'                 => $blockers,
            'missing_required_clauses' => $missingClauses,
        ];
    }

    /** @param list<string> $variants */
    private function containsAny(string $haystack, array $variants): bool
    {
        foreach ($variants as $variant) {
            if (str_contains($haystack, $variant)) {
                return true;
            }
        }

        return false;
    }
}
