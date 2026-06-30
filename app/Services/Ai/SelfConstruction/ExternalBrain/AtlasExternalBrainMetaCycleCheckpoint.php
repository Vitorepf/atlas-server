<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Captures one originator cycle as a compact, provider-safe fact so the external brain
 * can continue across cycles without relying on chat memory.
 *
 * FIELDS (per checkpoint):
 *   cycle_id                  string        unique id for this cycle
 *   recorded_at               int           unix timestamp
 *   queue_pressure            float         0.0–1.0  (ratio of queue depth vs capacity)
 *   targets_considered        int           how many candidate targets were evaluated
 *   tasks_enqueued            int           how many tasks were accepted and enqueued
 *   tasks_skipped             int           how many were rejected/skipped this cycle
 *   rejection_reasons         map<string,int>
 *   validation_results        { passed: int, failed: int, rate: float }
 *   carryover_notes           list<string>  notes forwarded from the previous cycle
 *   next_cycle_recommendation string        continue|consolidate|repair|pause|drain|escalate
 *
 * PROVIDER-SAFE REJECTION: capture() rejects input that contains forbidden keys at
 *   ANY nested level (recursive scan):
 *   - raw_prompt / prompt_text / prompt_template
 *   - raw_log / log_data
 *   - task payloads with more than MAX_TASK_KEYS keys (unbounded payload guard)
 *
 * NEXT-CYCLE RECOMMENDATION RULES (first match):
 *   escalate    — queue_pressure >= 0.90
 *   drain       — queue_pressure >= 0.70 AND tasks_enqueued = 0
 *   consolidate — tasks_enqueued > 0 AND validation_rate < 0.50
 *   repair      — tasks_enqueued = 0 AND tasks_skipped = 0 AND validation_results.failed > 0
 *   pause       — tasks_enqueued = 0 AND tasks_skipped > 0 AND validation_rate < 0.50
 *   continue    — default
 *
 * PURE / DETERMINISTIC / NO I/O. State held in-memory per instance.
 */
final class AtlasExternalBrainMetaCycleCheckpoint
{
    public const SCHEMA = 'atlas.external_brain.meta_cycle_checkpoint.v1';

    public const REC_CONTINUE    = 'continue';
    public const REC_CONSOLIDATE = 'consolidate';
    public const REC_REPAIR      = 'repair';
    public const REC_PAUSE       = 'pause';
    public const REC_DRAIN       = 'drain';
    public const REC_ESCALATE    = 'escalate';

    private const MAX_TASK_KEYS = 20;

    private const FORBIDDEN_KEYS = [
        'raw_prompt', 'prompt_text', 'prompt_template',
        'raw_log', 'log_data',
    ];

    /** @var list<array<string,mixed>> */
    private array $history = [];

    /**
     * Capture a cycle as a compact checkpoint.
     *
     * @param  array<string,mixed>  $cycle
     * @return array<string,mixed>
     */
    public function capture(array $cycle): array
    {
        // Provider-safe validation — scan all nested levels.
        $forbiddenKey = $this->findForbiddenKey($cycle);
        if ($forbiddenKey !== null) {
            return [
                'schema'           => self::SCHEMA,
                'accepted'         => false,
                'rejection_reason' => 'provider_unsafe_key:'.$forbiddenKey,
            ];
        }

        // Unbounded task payload guard.
        $tasks = $cycle['tasks'] ?? null;
        if (is_array($tasks)) {
            foreach ($tasks as $task) {
                if (is_array($task) && count($task) > self::MAX_TASK_KEYS) {
                    return [
                        'schema'           => self::SCHEMA,
                        'accepted'         => false,
                        'rejection_reason' => 'unbounded_task_payload',
                    ];
                }
            }
        }

        $cycleId           = (string) ($cycle['cycle_id'] ?? '');
        $recordedAt        = (int) ($cycle['recorded_at'] ?? 0);
        $queuePressure     = min(1.0, max(0.0, (float) ($cycle['queue_pressure'] ?? 0.0)));
        $targetsConsidered = max(0, (int) ($cycle['targets_considered'] ?? 0));
        $tasksEnqueued     = max(0, (int) ($cycle['tasks_enqueued'] ?? 0));
        $tasksSkipped      = max(0, (int) ($cycle['tasks_skipped'] ?? 0));
        $carryoverNotes    = array_values(array_map('strval', (array) ($cycle['carryover_notes'] ?? [])));

        $rawReasons = is_array($cycle['rejection_reasons'] ?? null) ? $cycle['rejection_reasons'] : [];
        $rejectionReasons = [];
        foreach ($rawReasons as $reason => $count) {
            $rejectionReasons[(string) $reason] = max(0, (int) $count);
        }

        $rawValidation = is_array($cycle['validation_results'] ?? null) ? $cycle['validation_results'] : [];
        $valPassed     = max(0, (int) ($rawValidation['passed'] ?? 0));
        $valFailed     = max(0, (int) ($rawValidation['failed'] ?? 0));
        $valTotal      = $valPassed + $valFailed;
        $valRate       = $valTotal > 0 ? round($valPassed / $valTotal, 4) : 0.0;

        $recommendation = $this->recommend($queuePressure, $tasksEnqueued, $tasksSkipped, $valRate, $valFailed);

        // Cross-cycle continuity sections (AC2). Each is optional input; capture() only
        // PASSES THEM THROUGH compactly — it never invents content for a missing section.
        $domainMapSummary       = is_array($cycle['domain_map_summary']       ?? null) ? $cycle['domain_map_summary']       : null;
        $queuedTargetDigest     = is_array($cycle['queued_target_digest']     ?? null) ? $cycle['queued_target_digest']     : null;
        $outcomeLearningDigest  = is_array($cycle['outcome_learning_digest']  ?? null) ? $cycle['outcome_learning_digest']  : null;
        $nextFrontier           = isset($cycle['next_frontier']) ? trim((string) $cycle['next_frontier']) : null;

        $rejectedCandidates = is_array($cycle['rejected_candidates'] ?? null) ? $cycle['rejected_candidates'] : null;
        $rejectedCandidateDigest = null;
        if ($rejectedCandidates !== null) {
            $byReason = [];
            foreach ($rejectedCandidates as $rejected) {
                if (! is_array($rejected)) {
                    continue;
                }
                $reason = (string) ($rejected['reason'] ?? 'unspecified');
                $byReason[$reason] = ($byReason[$reason] ?? 0) + 1;
            }
            $rejectedCandidateDigest = [
                'total'     => count($rejectedCandidates),
                'by_reason' => $byReason,
            ];
        }

        $sections = [
            'domain_map_summary'       => $domainMapSummary,
            'queued_target_digest'     => $queuedTargetDigest,
            'rejected_candidate_digest' => $rejectedCandidateDigest,
            'outcome_learning_digest'  => $outcomeLearningDigest,
            'next_frontier'            => $nextFrontier !== '' ? $nextFrontier : null,
        ];

        $continuationUnsafeReasons = [];
        foreach ($sections as $sectionName => $sectionValue) {
            if ($sectionValue === null) {
                $continuationUnsafeReasons[] = "missing:{$sectionName}";
                continue;
            }
            if (is_array($sectionValue) && (bool) ($sectionValue['stale'] ?? false) === true) {
                $continuationUnsafeReasons[] = "stale:{$sectionName}";
            }
        }
        $continuationSafe = $continuationUnsafeReasons === [];

        $checkpoint = [
            'cycle_id'                  => $cycleId,
            'recorded_at'               => $recordedAt,
            'queue_pressure'            => $queuePressure,
            'targets_considered'        => $targetsConsidered,
            'tasks_enqueued'            => $tasksEnqueued,
            'tasks_skipped'             => $tasksSkipped,
            'rejection_reasons'         => $rejectionReasons,
            'validation_results'        => [
                'passed' => $valPassed,
                'failed' => $valFailed,
                'rate'   => $valRate,
            ],
            'carryover_notes'           => $carryoverNotes,
            'next_cycle_recommendation' => $recommendation,
            'domain_map_summary'        => $domainMapSummary,
            'queued_target_digest'      => $queuedTargetDigest,
            'rejected_candidate_digest' => $rejectedCandidateDigest,
            'outcome_learning_digest'   => $outcomeLearningDigest,
            'next_frontier'             => $sections['next_frontier'],
            'continuation_safe'         => $continuationSafe,
            'continuation_unsafe_reasons' => $continuationUnsafeReasons,
        ];

        $this->history[] = $checkpoint;

        return ['schema' => self::SCHEMA, 'accepted' => true, 'checkpoint' => $checkpoint];
    }

    /**
     * Return the most recently captured checkpoint, or null if none.
     *
     * @return array<string,mixed>
     */
    public function latest(): array
    {
        $checkpoint = $this->history !== [] ? end($this->history) : null;

        return ['schema' => self::SCHEMA, 'checkpoint' => $checkpoint];
    }

    /**
     * Return all captured checkpoints in capture order.
     *
     * @return array<string,mixed>
     */
    public function history(): array
    {
        return ['schema' => self::SCHEMA, 'checkpoints' => $this->history, 'count' => count($this->history)];
    }

    private function recommend(
        float $queuePressure,
        int $tasksEnqueued,
        int $tasksSkipped,
        float $valRate,
        int $valFailed,
    ): string {
        if ($queuePressure >= 0.90) {
            return self::REC_ESCALATE;
        }
        if ($queuePressure >= 0.70 && $tasksEnqueued === 0) {
            return self::REC_DRAIN;
        }
        if ($tasksEnqueued > 0 && $valRate < 0.50) {
            return self::REC_CONSOLIDATE;
        }
        if ($tasksEnqueued === 0 && $tasksSkipped === 0 && $valFailed > 0) {
            return self::REC_REPAIR;
        }
        if ($tasksEnqueued === 0 && $tasksSkipped > 0 && $valRate < 0.50) {
            return self::REC_PAUSE;
        }

        return self::REC_CONTINUE;
    }

    /** Recursively scan $data for any key in FORBIDDEN_KEYS. Returns the first found key or null. */
    private function findForbiddenKey(mixed $data): ?string
    {
        if (! is_array($data)) {
            return null;
        }
        foreach ($data as $key => $value) {
            if (in_array((string) $key, self::FORBIDDEN_KEYS, true)) {
                return (string) $key;
            }
            $nested = $this->findForbiddenKey($value);
            if ($nested !== null) {
                return $nested;
            }
        }
        return null;
    }
}
