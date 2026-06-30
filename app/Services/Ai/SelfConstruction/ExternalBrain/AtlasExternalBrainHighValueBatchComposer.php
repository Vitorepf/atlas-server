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

    private const GENERIC_VM_KEYWORDS = ['general', 'misc', 'wrapper', 'observability-only'];

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

            if ($this->hasGenericValueMechanism((string) ($opp['value_mechanism'] ?? ''), $opp)) {
                $rejected[] = [
                    'label'  => (string) ($opp['label'] ?? ''),
                    'reason' => 'generic_value_mechanism',
                    'detail' => 'value_mechanism:'.strtolower(trim((string) ($opp['value_mechanism'] ?? ''))),
                ];

                continue;
            }

            $valid[] = $opp;
        }

        // Phase 1.5: template-farm detection — reject same-shape overflow.
        $templateFarmRejected = [];
        [$valid, $templateFarmRejected] = $this->detectTemplateFarm($valid);
        $rejected = array_merge($rejected, $templateFarmRejected);

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
            'strategic_diversity'            => $this->computeStrategicDiversity($emitted),
            'dependency_chain_summary'       => $this->computeDependencyChainSummary($emitted),
            'batch_thesis'                   => $this->computeBatchThesis($emitted),
            'rejected_template_farm_reasons' => $templateFarmRejected,
            'wave_plan'                          => $this->computeWavePlan($emitted),
            'learning_signals_by_rejection_reason' => $this->computeLearningSignals($rejected),
        ];
    }

    /**
     * Groups emitted packet ids by dependency_wave, so callers can parallelise the batch safely
     * without re-deriving wave assignment from category.
     *
     * @param  list<array<string,mixed>>  $emitted
     * @return array<string, list<string>>
     */
    private function computeWavePlan(array $emitted): array
    {
        $plan = [];
        foreach ($emitted as $packet) {
            $wave = (string) ($packet['dependency_wave'] ?? '2');
            $plan[$wave][] = (string) ($packet['task_packet_id'] ?? '');
        }
        ksort($plan, SORT_NUMERIC);
        foreach ($plan as $wave => $ids) {
            sort($plan[$wave], SORT_STRING);
        }

        return $plan;
    }

    /**
     * Aggregates rejections by reason so the brain can learn which rejection patterns recur and
     * adjust future opportunity generation, instead of re-discovering the same failure each batch.
     *
     * @param  list<array{label:string,reason:string,detail?:string}>  $rejected
     * @return array<string, array{count:int, labels:list<string>}>
     */
    private function computeLearningSignals(array $rejected): array
    {
        $signals = [];
        foreach ($rejected as $r) {
            $reason = (string) ($r['reason'] ?? 'unknown');
            $signals[$reason]['count'] = ($signals[$reason]['count'] ?? 0) + 1;
            $signals[$reason]['labels'][] = (string) ($r['label'] ?? '');
        }
        ksort($signals, SORT_STRING);

        return $signals;
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
        } else {
            $files   = $this->allowedFiles($opp);
            $hasImpl = (bool) array_filter($files, fn(string $f): bool => str_starts_with($f, 'app/'));
            $hasTest = (bool) array_filter($files, fn(string $f): bool => str_starts_with($f, 'tests/'));
            if (! $hasImpl) {
                $missing[] = 'allowed_files_impl_path';
            }
            if (! $hasTest) {
                $missing[] = 'allowed_files_test_path';
            }
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
        return count($this->listField($opp, 'acceptance_criteria')) === 1;
    }

    private function hasGenericValueMechanism(string $rawVm, array $opp): bool
    {
        $vm = strtolower(trim($rawVm));
        foreach (self::GENERIC_VM_KEYWORDS as $kw) {
            if ($vm === $kw || str_starts_with($vm, $kw.':') || str_starts_with($vm, $kw.'_') || str_starts_with($vm, $kw.'-')) {
                return trim((string) ($opp['concrete_evidence'] ?? '')) === '';
            }
        }
        return false;
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

    /**
     * Detect template-farm patterns in the valid list.
     * A template farm is when > 50% of valid items share the same (category, top-dir-prefix).
     * When detected (≥ 4 valid items), keep 1 representative per group and reject the rest.
     *
     * @param  list<array<string,mixed>>  $valid
     * @return array{0: list<array<string,mixed>>, 1: list<array<string,string>>}
     */
    private function detectTemplateFarm(array $valid): array
    {
        $total = count($valid);
        if ($total < 4) {
            return [$valid, []];
        }

        // Group by (category, topDirPrefix)
        $groups = [];
        foreach ($valid as $idx => $opp) {
            $cat    = (string) ($opp['category'] ?? 'uncategorised');
            $prefix = $this->topDirPrefix($this->allowedFiles($opp)[0] ?? '');
            $key    = $cat.'||'.$prefix;
            $groups[$key][] = $idx;
        }

        // Find if any group captures > 50%
        $farmKeys = [];
        foreach ($groups as $key => $indices) {
            if (count($indices) / $total > 0.50) {
                $farmKeys[] = $key;
            }
        }

        if ($farmKeys === []) {
            return [$valid, []];
        }

        // Keep the first item per farm group, reject the rest
        $keepIndices = [];
        $rejectEntries = [];

        foreach ($groups as $key => $indices) {
            if (in_array($key, $farmKeys, true)) {
                [$first, $rest] = [array_shift($indices), $indices];
                $keepIndices[]  = $first;
                foreach ($rest as $idx) {
                    $opp = $valid[$idx];
                    $rejectEntries[] = [
                        'label'  => (string) ($opp['label'] ?? ''),
                        'reason' => 'template_farm',
                        'detail' => 'shape:'.$key,
                    ];
                }
            } else {
                foreach ($indices as $idx) {
                    $keepIndices[] = $idx;
                }
            }
        }

        sort($keepIndices);
        $kept = array_values(array_map(static fn (int $i): array => $valid[$i], $keepIndices));

        return [$kept, $rejectEntries];
    }

    /**
     * Compute a directory-depth-aware prefix key for template-farm detection.
     * Shallow dirs (< 3 components) return the full path so each file is unique.
     * Deep dirs (≥ 3 components) return the first-3-segment prefix for grouping.
     */
    private function topDirPrefix(string $path): string
    {
        $allParts = explode('/', $path);
        $dirParts = array_slice($allParts, 0, -1);  // strip filename

        if (count($dirParts) < 3) {
            return $path;  // too shallow → each file is its own key, no false grouping
        }

        return implode('/', array_slice($dirParts, 0, 3));
    }

    /**
     * Compute strategic diversity facts for the emitted batch.
     *
     * @param  list<array<string,mixed>>  $emitted
     * @return array<string,mixed>
     */
    private function computeStrategicDiversity(array $emitted): array
    {
        $distribution = [];
        foreach ($emitted as $packet) {
            $cat = (string) ($packet['category'] ?? 'unknown');
            $distribution[$cat] = ($distribution[$cat] ?? 0) + 1;
        }

        $distinctCategories = count($distribution);

        return [
            'distinct_categories'  => $distinctCategories,
            'category_distribution' => $distribution,
            'is_diverse'           => $distinctCategories >= 2 || count($emitted) < 3,
        ];
    }

    /**
     * Build a dependency chain summary grouped by wave.
     *
     * @param  list<array<string,mixed>>  $emitted
     * @return array<string,mixed>
     */
    private function computeDependencyChainSummary(array $emitted): array
    {
        $byWave = [];
        foreach ($emitted as $packet) {
            $wave = (int) ($packet['dependency_wave'] ?? 2);
            $cat  = (string) ($packet['category'] ?? 'unknown');
            $byWave[$wave][] = $cat;
        }

        ksort($byWave);

        $wavesPresent = array_keys($byWave);
        $segments     = [];
        foreach ($byWave as $wave => $cats) {
            $unique    = array_unique($cats);
            sort($unique);
            $segments[] = 'wave '.$wave.' ('.implode(', ', $unique).')';
        }

        return [
            'waves_present'      => $wavesPresent,
            'chain_description'  => $segments !== [] ? implode(' → ', $segments) : 'empty batch',
            'prerequisite_count' => count($byWave[1] ?? []),
        ];
    }

    /**
     * Generate a deterministic batch thesis from the emitted set.
     *
     * @param  list<array<string,mixed>>  $emitted
     */
    private function computeBatchThesis(array $emitted): string
    {
        $total = count($emitted);
        if ($total === 0) {
            return 'Empty batch — no tasks emitted.';
        }

        $byWave = [];
        foreach ($emitted as $packet) {
            $wave = (int) ($packet['dependency_wave'] ?? 2);
            $cat  = (string) ($packet['category'] ?? 'unknown');
            $byWave[$wave][$cat] = ($byWave[$wave][$cat] ?? 0) + 1;
        }

        ksort($byWave);
        $parts = [];
        foreach ($byWave as $wave => $cats) {
            $catStr = [];
            foreach ($cats as $cat => $count) {
                $catStr[] = $count.'× '.$cat;
            }
            $parts[] = count($byWave[$wave]).' category'.((array_sum($byWave[$wave]) > 1) ? '-types' : '').' in wave '.$wave.' ('.implode(', ', $catStr).')';
        }

        return 'Batch of '.$total.' task'.($total > 1 ? 's' : '').': '.implode('; ', $parts).'.';
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
