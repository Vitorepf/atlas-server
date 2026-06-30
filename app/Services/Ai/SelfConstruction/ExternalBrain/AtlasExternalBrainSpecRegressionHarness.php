<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure spec regression harness. Replays candidate task specs against labeled
 * historical examples (poison, give_back, duplicate, shallow_wrapper, success)
 * before enqueue, catching originator quality regressions.
 *
 * Regression classes (first match wins per candidate):
 *   poison          — objective similarity ≥ 0.55 with a 'poison' or 'give_back' example → fail
 *   wrapper_farm    — objective similarity ≥ 0.55 with a 'shallow_wrapper' example → warning
 *   duplicate       — similarity ≥ 0.55 with a 'duplicate' example, OR cross-candidate Jaccard ≥ 0.65 → warning
 *   underspecified  — no impl files AND no test files AND no acceptance criteria → warning
 *
 * Known-good macro spec (AC2): candidate with implementation_files + test_files + behavior_evidence
 * is NEVER blocked — regression detection is skipped entirely for such specs.
 *
 * Verdict: fail > warning > pass
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainSpecRegressionHarness
{
    public const SCHEMA = 'atlas.external_brain.spec_regression_harness.v1';

    public const VERDICT_PASS    = 'pass';
    public const VERDICT_WARNING = 'warning';
    public const VERDICT_FAIL    = 'fail';

    public const CLASS_POISON          = 'poison';
    public const CLASS_WRAPPER_FARM    = 'wrapper_farm';
    public const CLASS_DUPLICATE       = 'duplicate';
    public const CLASS_UNDERSPECIFIED  = 'underspecified_scope';

    private const SIMILARITY_THRESHOLD        = 0.55;
    private const CROSS_CANDIDATE_THRESHOLD   = 0.65;

    private const FAIL_LABELS    = ['poison', 'give_back'];
    private const WARNING_LABELS = ['shallow_wrapper', 'duplicate'];

    /**
     * @param  array{
     *   candidate_specs?: list<array<string,mixed>>,
     *   historical_examples?: list<array<string,mixed>>,
     * }  $input
     * @return array{schema:string, verdict:string, matched_regressions:list<array<string,string>>, evidence:list<string>}
     */
    public function replay(array $input): array
    {
        $candidates = (array) ($input['candidate_specs']     ?? []);
        $historical  = (array) ($input['historical_examples'] ?? []);

        $matchedRegressions = [];
        $evidenceLines      = [];
        $overallVerdict     = self::VERDICT_PASS;

        $candidateFingerprints = array_map(
            fn (array $c): array => $this->keywords((string) ($c['objective'] ?? '')),
            $candidates,
        );

        foreach ($candidates as $idx => $spec) {
            $specId    = (string) ($spec['task_id'] ?? "spec_{$idx}");
            $objective = (string) ($spec['objective'] ?? '');

            // AC2: known-good macro spec is never blocked
            if ($this->isKnownGoodMacroSpec($spec)) {
                $evidenceLines[] = "{$specId}: known-good macro spec (impl+test+behavior) — skipped regression check";
                continue;
            }

            $kw = $candidateFingerprints[$idx];

            // 1. Poison check (fail)
            foreach ($historical as $example) {
                $label = (string) ($example['label'] ?? '');
                if (! in_array($label, self::FAIL_LABELS, true)) {
                    continue;
                }
                $sim = $this->similarity($kw, $this->keywords((string) ($example['objective'] ?? '')));
                if ($sim >= self::SIMILARITY_THRESHOLD) {
                    $matchedRegressions[] = [
                        'spec_id'               => $specId,
                        'class'                 => self::CLASS_POISON,
                        'evidence'              => "similarity {$sim} with {$label} example: \"{$example['objective']}\"",
                        'matched_example_label' => $label,
                    ];
                    $overallVerdict = self::VERDICT_FAIL;
                    continue 2;
                }
            }

            // 2. Wrapper-farm check (warning)
            foreach ($historical as $example) {
                $label = (string) ($example['label'] ?? '');
                if ($label !== 'shallow_wrapper') {
                    continue;
                }
                $sim = $this->similarity($kw, $this->keywords((string) ($example['objective'] ?? '')));
                if ($sim >= self::SIMILARITY_THRESHOLD) {
                    $matchedRegressions[] = [
                        'spec_id'               => $specId,
                        'class'                 => self::CLASS_WRAPPER_FARM,
                        'evidence'              => "similarity {$sim} with shallow_wrapper example: \"{$example['objective']}\"",
                        'matched_example_label' => 'shallow_wrapper',
                    ];
                    if ($overallVerdict === self::VERDICT_PASS) {
                        $overallVerdict = self::VERDICT_WARNING;
                    }
                    continue 2;
                }
            }

            // 3. Duplicate check (warning) — historical first
            foreach ($historical as $example) {
                $label = (string) ($example['label'] ?? '');
                if ($label !== 'duplicate') {
                    continue;
                }
                $sim = $this->similarity($kw, $this->keywords((string) ($example['objective'] ?? '')));
                if ($sim >= self::SIMILARITY_THRESHOLD) {
                    $matchedRegressions[] = [
                        'spec_id'               => $specId,
                        'class'                 => self::CLASS_DUPLICATE,
                        'evidence'              => "similarity {$sim} with duplicate example: \"{$example['objective']}\"",
                        'matched_example_label' => 'duplicate',
                    ];
                    if ($overallVerdict === self::VERDICT_PASS) {
                        $overallVerdict = self::VERDICT_WARNING;
                    }
                    continue 2;
                }
            }

            // 3b. Cross-candidate duplicate check
            foreach ($candidates as $jdx => $other) {
                if ($jdx <= $idx) {
                    continue;
                }
                $sim = $this->similarity($kw, $candidateFingerprints[$jdx]);
                if ($sim >= self::CROSS_CANDIDATE_THRESHOLD) {
                    $otherId = (string) ($other['task_id'] ?? "spec_{$jdx}");
                    $matchedRegressions[] = [
                        'spec_id'               => $specId,
                        'class'                 => self::CLASS_DUPLICATE,
                        'evidence'              => "cross-candidate similarity {$sim} with {$otherId}",
                        'matched_example_label' => 'cross_candidate_duplicate',
                    ];
                    if ($overallVerdict === self::VERDICT_PASS) {
                        $overallVerdict = self::VERDICT_WARNING;
                    }
                    continue 2;
                }
            }

            // 4. Underspecified scope (warning)
            if ($this->isUnderspecified($spec)) {
                $matchedRegressions[] = [
                    'spec_id'               => $specId,
                    'class'                 => self::CLASS_UNDERSPECIFIED,
                    'evidence'              => "no implementation_files, no test_files, and no acceptance_criteria",
                    'matched_example_label' => 'none',
                ];
                if ($overallVerdict === self::VERDICT_PASS) {
                    $overallVerdict = self::VERDICT_WARNING;
                }
            }
        }

        $evidenceLines[] = count($matchedRegressions) === 0
            ? 'all candidates passed regression replay'
            : count($matchedRegressions).' regression(s) matched across '.count($candidates).' candidate(s)';

        return [
            'schema'               => self::SCHEMA,
            'verdict'              => $overallVerdict,
            'matched_regressions'  => $matchedRegressions,
            'evidence'             => $evidenceLines,
        ];
    }

    private function isKnownGoodMacroSpec(array $spec): bool
    {
        $hasImpl      = ! empty($spec['implementation_files']);
        $hasTests     = ! empty($spec['test_files']);
        $hasEvidence  = ! empty($spec['behavior_evidence']);

        return $hasImpl && $hasTests && $hasEvidence;
    }

    private function isUnderspecified(array $spec): bool
    {
        $hasImpl      = ! empty($spec['implementation_files']);
        $hasTests     = ! empty($spec['test_files']);
        $hasCriteria  = ! empty($spec['acceptance_criteria']);

        return ! $hasImpl && ! $hasTests && ! $hasCriteria;
    }

    /** @return list<string> */
    private function keywords(string $text): array
    {
        $text  = strtolower(preg_replace('/[^a-zA-Z0-9\s]/', ' ', $text) ?? '');
        $words = array_filter(explode(' ', $text), fn (string $w): bool => strlen($w) >= 4);

        return array_values(array_unique($words));
    }

    private function similarity(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        $intersection = count(array_intersect($a, $b));
        $union        = count(array_unique(array_merge($a, $b)));

        return $union === 0 ? 0.0 : round($intersection / $union, 4);
    }
}
