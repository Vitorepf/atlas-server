<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Turns ranked opportunities into a bounded, ordered task batch.
 *
 * Rules (applied in order):
 *
 *  1. VALIDATION — an opportunity is rejected if it lacks any of:
 *       objective, allowed_files (non-empty), acceptance_criteria (non-empty),
 *       required_evidence (non-empty), value_mechanism (non-empty string).
 *
 *  2. MICROTASK GROUPING — a "thin" opportunity has exactly 1 allowed_file
 *       and exactly 1 acceptance criterion. Thin tasks in the same category
 *       are merged into one packet; an isolated thin task (no siblings) is
 *       rejected with reason 'thin_microtask_no_grouping_partner'.
 *
 *  3. COLLISION GUARD — if an opportunity's allowed_files intersect with a
 *       file already claimed by a higher-ranked packet, it is rejected with
 *       reason 'allowed_files_collision'.
 *
 *  4. DEPENDENCY WAVES — emitted packets are assigned to dependency waves
 *       by category so callers can parallelise safely:
 *         wave 1: architecture_unlock, test_gate
 *         wave 2: bug_fix, runtime_continuity
 *         wave 3: task_quality_repair, docs_sync, learning_loop
 *       Unknown categories land in wave 2.
 *
 *  5. BATCH CAP — at most max_batch packets are emitted (default 20).
 *
 * Pure / deterministic. No I/O.
 */
final class AtlasExternalBrainHighValueBatchComposer
{
    public const SCHEMA = 'atlas.external_brain.high_value_batch_composer.v1';

    public const DEFAULT_MAX_BATCH = 20;

    private const WAVE_MAP = [
        'architecture_unlock' => 1,
        'test_gate'           => 1,
        'bug_fix'             => 2,
        'runtime_continuity'  => 2,
        'task_quality_repair' => 3,
        'docs_sync'           => 3,
        'learning_loop'       => 3,
    ];

    /**
     * Compose a bounded task batch from ranked opportunities.
     *
     * Each opportunity may carry:
     *   - label (string)                  — becomes task_packet_id
     *   - objective (string)              — required
     *   - allowed_files (list<string>)    — required, non-empty
     *   - acceptance_criteria (list<string>) — required, non-empty
     *   - required_evidence (list<string>) — required, non-empty
     *   - value_mechanism (string)        — required, non-empty
     *   - category (string)               — drives wave assignment
     *   - final_score (float)             — carried through for caller
     *
     * @param  list<array<string, mixed>>  $rankedOpportunities
     * @param  array<string, mixed>        $options  {max_batch?: int}
     * @return array{
     *     schema:   string,
     *     emitted:  list<array<string,mixed>>,
     *     grouped:  list<array<string,mixed>>,
     *     rejected: list<array{label:string,reason:string,detail?:string}>,
     *     stats:    array<string,int>,
     * }
     */
    public function compose(array $rankedOpportunities, array $options = []): array
    {
        $maxBatch = max(1, (int) ($options['max_batch'] ?? self::DEFAULT_MAX_BATCH));

        // Phase 1: validate — partition into valid and rejected.
        $valid   = [];
        $rejected = [];
        foreach ($rankedOpportunities as $opp) {
            $miss = $this->missingRequiredFields($opp);
            if ($miss !== []) {
                $rejected[] = [
                    'label'  => (string) ($opp['label'] ?? ''),
                    'reason' => 'missing_required_fields',
                    'detail' => implode(',', $miss),
                ];

                continue;
            }
            $valid[] = $opp;
        }

        // Phase 2: separate thin microtasks; group by category.
        $thin     = [];
        $standard = [];
        foreach ($valid as $opp) {
            if ($this->isThin($opp)) {
                $cat          = (string) ($opp['category'] ?? 'uncategorised');
                $thin[$cat][] = $opp;
            } else {
                $standard[] = $opp;
            }
        }

        $grouped = [];
        foreach ($thin as $cat => $tasks) {
            if (count($tasks) < 2) {
                foreach ($tasks as $t) {
                    $rejected[] = [
                        'label'  => (string) ($t['label'] ?? ''),
                        'reason' => 'thin_microtask_no_grouping_partner',
                        'detail' => 'category:'.$cat,
                    ];
                }

                continue;
            }

            // Merge thin tasks in this category into one packet.
            $merged  = $this->mergeThin($cat, $tasks);
            $grouped[] = $merged;
        }

        // Phase 3: build candidate list (standard + grouped), assign waves, collision-guard.
        $candidates = array_merge($standard, $grouped);
        $claimedFiles = [];
        $emitted      = [];

        foreach ($candidates as $opp) {
            if (count($emitted) >= $maxBatch) {
                $rejected[] = [
                    'label'  => (string) ($opp['label'] ?? ''),
                    'reason' => 'batch_cap_reached',
                    'detail' => 'max_batch:'.$maxBatch,
                ];

                continue;
            }

            $files = $this->allowedFiles($opp);
            $collision = array_values(array_intersect($files, $claimedFiles));
            if ($collision !== []) {
                $rejected[] = [
                    'label'  => (string) ($opp['label'] ?? ''),
                    'reason' => 'allowed_files_collision',
                    'detail' => implode(',', $collision),
                ];

                continue;
            }

            foreach ($files as $f) {
                $claimedFiles[] = $f;
            }

            $emitted[] = $this->emit($opp);
        }

        return [
            'schema'   => self::SCHEMA,
            'emitted'  => $emitted,
            'grouped'  => $grouped,
            'rejected' => $rejected,
            'stats'    => [
                'opportunities_in'   => count($rankedOpportunities),
                'valid'              => count($valid),
                'emitted_count'      => count($emitted),
                'grouped_count'      => count($grouped),
                'rejected_count'     => count($rejected),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $opp
     * @return list<string>
     */
    private function missingRequiredFields(array $opp): array
    {
        $missing = [];
        if (trim((string) ($opp['objective'] ?? '')) === '') {
            $missing[] = 'objective';
        }
        if (empty($this->allowedFiles($opp))) {
            $missing[] = 'allowed_files';
        }
        if (empty($this->listField($opp, 'acceptance_criteria'))) {
            $missing[] = 'acceptance_criteria';
        }
        if (empty($this->listField($opp, 'required_evidence'))) {
            $missing[] = 'required_evidence';
        }
        if (trim((string) ($opp['value_mechanism'] ?? '')) === '') {
            $missing[] = 'value_mechanism';
        }

        return $missing;
    }

    private function isThin(array $opp): bool
    {
        return count($this->allowedFiles($opp)) === 1
            && count($this->listField($opp, 'acceptance_criteria')) === 1;
    }

    /** @param  list<array<string,mixed>>  $tasks */
    private function mergeThin(string $category, array $tasks): array
    {
        $files    = [];
        $criteria = [];
        $evidence = [];
        $labels   = [];
        $score    = 0.0;

        foreach ($tasks as $t) {
            $labels[]  = (string) ($t['label'] ?? '');
            $files     = array_unique(array_merge($files, $this->allowedFiles($t)));
            $criteria  = array_merge($criteria, $this->listField($t, 'acceptance_criteria'));
            $evidence  = array_unique(array_merge($evidence, $this->listField($t, 'required_evidence')));
            $score     = max($score, (float) ($t['final_score'] ?? 0.0));
        }

        return [
            'label'              => 'grouped_'.$category.'_'.count($tasks).'tasks',
            'objective'          => 'Grouped '.$category.' microtasks: '.implode(', ', $labels),
            'category'           => $category,
            'allowed_files'      => array_values($files),
            'acceptance_criteria' => array_values($criteria),
            'required_evidence'  => array_values($evidence),
            'value_mechanism'    => 'grouped_microtask_consolidation:'.$category,
            'final_score'        => $score,
            'grouped_from'       => $labels,
        ];
    }

    private function emit(array $opp): array
    {
        $cat  = (string) ($opp['category'] ?? '');
        $wave = self::WAVE_MAP[$cat] ?? 2;

        return [
            'task_packet_id'     => (string) ($opp['label'] ?? ''),
            'objective'          => (string) ($opp['objective'] ?? ''),
            'category'           => $cat,
            'allowed_files'      => $this->allowedFiles($opp),
            'acceptance_criteria' => $this->listField($opp, 'acceptance_criteria'),
            'required_evidence'  => $this->listField($opp, 'required_evidence'),
            'value_mechanism'    => (string) ($opp['value_mechanism'] ?? ''),
            'final_score'        => (float) ($opp['final_score'] ?? 0.0),
            'dependency_wave'    => $wave,
            'grouped_from'       => (array) ($opp['grouped_from'] ?? []),
        ];
    }

    /** @return list<string> */
    private function allowedFiles(array $opp): array
    {
        return array_values(array_filter(
            array_map('strval', (array) ($opp['allowed_files'] ?? [])),
            static fn (string $f): bool => $f !== '',
        ));
    }

    /** @return list<string> */
    private function listField(array $opp, string $field): array
    {
        return array_values(array_filter(
            array_map('strval', (array) ($opp[$field] ?? [])),
            static fn (string $v): bool => $v !== '',
        ));
    }
}
