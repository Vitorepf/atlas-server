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
    public const CLASS_TEST_ONLY_PACKET         = 'test_only_packet';
    public const CLASS_FORBIDDEN_TARGET         = 'forbidden_implementation_target';
    public const CLASS_CONTRADICTORY_ACCEPTANCE = 'contradictory_acceptance';
    public const CLASS_DUPLICATE_TARGET         = 'duplicate_target';
    public const CLASS_TEMPLATE_FARM            = 'template_farm';

    /** Which gate/planner is responsible for catching each frozen regression class. */
    public const GATE_BY_CLASS = [
        self::CLASS_POISON => 'AtlasExternalBrainSeedQualityGate',
        self::CLASS_WRAPPER_FARM => 'AtlasExternalBrainOriginatorBatchValueAuditor',
        self::CLASS_DUPLICATE => 'AtlasExternalBrainOriginatorBatchValueAuditor',
        self::CLASS_UNDERSPECIFIED => 'AtlasTaskServingPacketQualityGate',
        self::CLASS_TEST_ONLY_PACKET => 'AtlasTaskServingPacketQualityGate',
        self::CLASS_FORBIDDEN_TARGET => 'AgentControlPlaneScopeLockRuntimeValidator',
        self::CLASS_CONTRADICTORY_ACCEPTANCE => 'AtlasTaskServingPacketQualityGate',
        self::CLASS_DUPLICATE_TARGET => 'AtlasExternalBrainOriginatorBatchValueAuditor',
        self::CLASS_TEMPLATE_FARM => 'AtlasExternalBrainOriginatorBatchValueAuditor',
    ];

    private const SIMILARITY_THRESHOLD        = 0.55;
    private const CROSS_CANDIDATE_THRESHOLD   = 0.65;

    private const FAIL_LABELS    = ['poison', 'give_back'];
    private const WARNING_LABELS = ['shallow_wrapper', 'duplicate'];

    private const NEGATION_MARKERS = ['must not', 'never', 'cannot', 'should not', 'is forbidden'];

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
                        'gate'                  => self::GATE_BY_CLASS[self::CLASS_POISON],
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
                        'gate'                  => self::GATE_BY_CLASS[self::CLASS_WRAPPER_FARM],
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
                        'gate'                  => self::GATE_BY_CLASS[self::CLASS_DUPLICATE],
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
                        'gate'                  => self::GATE_BY_CLASS[self::CLASS_DUPLICATE],
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
                    'gate'                  => self::GATE_BY_CLASS[self::CLASS_UNDERSPECIFIED],
                ];
                if ($overallVerdict === self::VERDICT_PASS) {
                    $overallVerdict = self::VERDICT_WARNING;
                }
            }

            // 5. Test-only packet (fail) — allowed_files exist but every one is a test file.
            if ($this->isTestOnlyPacket($spec)) {
                $matchedRegressions[] = [
                    'spec_id'               => $specId,
                    'class'                 => self::CLASS_TEST_ONLY_PACKET,
                    'evidence'              => 'allowed_files are entirely test files with no implementation target',
                    'matched_example_label' => 'none',
                    'gate'                  => self::GATE_BY_CLASS[self::CLASS_TEST_ONLY_PACKET],
                ];
                $overallVerdict = self::VERDICT_FAIL;
            }

            // 6. Forbidden implementation target (fail).
            $forbiddenHit = $this->forbiddenTargetHit($spec, (array) ($input['forbidden_targets'] ?? []));
            if ($forbiddenHit !== null) {
                $matchedRegressions[] = [
                    'spec_id'               => $specId,
                    'class'                 => self::CLASS_FORBIDDEN_TARGET,
                    'evidence'              => "allowed_files includes forbidden target: {$forbiddenHit}",
                    'matched_example_label' => 'none',
                    'gate'                  => self::GATE_BY_CLASS[self::CLASS_FORBIDDEN_TARGET],
                ];
                $overallVerdict = self::VERDICT_FAIL;
            }

            // 7. Contradictory acceptance (fail) — two criteria over the same subject that negate each other.
            $contradiction = $this->contradictoryAcceptancePair((array) ($spec['acceptance_criteria'] ?? []));
            if ($contradiction !== null) {
                $matchedRegressions[] = [
                    'spec_id'               => $specId,
                    'class'                 => self::CLASS_CONTRADICTORY_ACCEPTANCE,
                    'evidence'              => "contradictory acceptance criteria: \"{$contradiction[0]}\" vs \"{$contradiction[1]}\"",
                    'matched_example_label' => 'none',
                    'gate'                  => self::GATE_BY_CLASS[self::CLASS_CONTRADICTORY_ACCEPTANCE],
                ];
                $overallVerdict = self::VERDICT_FAIL;
            }

            // 8. Duplicate target (warning) — same primary allowed_files target as a historical or sibling candidate.
            $duplicateTargetOf = $this->duplicateTargetMatch($spec, $candidates, $idx, $historical);
            if ($duplicateTargetOf !== null) {
                $matchedRegressions[] = [
                    'spec_id'               => $specId,
                    'class'                 => self::CLASS_DUPLICATE_TARGET,
                    'evidence'              => "same primary target as {$duplicateTargetOf}",
                    'matched_example_label' => 'duplicate_target',
                    'gate'                  => self::GATE_BY_CLASS[self::CLASS_DUPLICATE_TARGET],
                ];
                if ($overallVerdict === self::VERDICT_PASS) {
                    $overallVerdict = self::VERDICT_WARNING;
                }
            }

            // 9. Template-farm spec (warning) — matches a historical 'template_farm' labeled example.
            foreach ($historical as $example) {
                $label = (string) ($example['label'] ?? '');
                if ($label !== 'template_farm') {
                    continue;
                }
                $sim = $this->similarity($kw, $this->keywords((string) ($example['objective'] ?? '')));
                if ($sim >= self::SIMILARITY_THRESHOLD) {
                    $matchedRegressions[] = [
                        'spec_id'               => $specId,
                        'class'                 => self::CLASS_TEMPLATE_FARM,
                        'evidence'              => "similarity {$sim} with template_farm example: \"{$example['objective']}\"",
                        'matched_example_label' => 'template_farm',
                        'gate'                  => self::GATE_BY_CLASS[self::CLASS_TEMPLATE_FARM],
                    ];
                    if ($overallVerdict === self::VERDICT_PASS) {
                        $overallVerdict = self::VERDICT_WARNING;
                    }
                    break;
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

    private function isTestOnlyPacket(array $spec): bool
    {
        $allowedFiles = (array) ($spec['allowed_files'] ?? []);
        if ($allowedFiles === [] || ! empty($spec['implementation_files'])) {
            return false;
        }

        foreach ($allowedFiles as $file) {
            $file = strtolower((string) $file);
            if (! str_contains($file, 'test')) {
                return false;
            }
        }

        return true;
    }

    /** @param  list<string>  $forbiddenTargets */
    private function forbiddenTargetHit(array $spec, array $forbiddenTargets): ?string
    {
        if ($forbiddenTargets === []) {
            return null;
        }

        $allowedFiles = (array) ($spec['allowed_files'] ?? []);
        foreach ($allowedFiles as $file) {
            if (in_array((string) $file, $forbiddenTargets, true)) {
                return (string) $file;
            }
        }

        return null;
    }

    /**
     * @param  list<mixed>  $criteria
     * @return array{0:string,1:string}|null
     */
    private function contradictoryAcceptancePair(array $criteria): ?array
    {
        $count = count($criteria);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = (string) $criteria[$i];
                $b = (string) $criteria[$j];
                $kwA = $this->keywords($a);
                $kwB = $this->keywords($b);
                if ($this->similarity($kwA, $kwB) < self::SIMILARITY_THRESHOLD) {
                    continue;
                }
                if ($this->hasNegationMarker($a) !== $this->hasNegationMarker($b)) {
                    return [$a, $b];
                }
            }
        }

        return null;
    }

    private function hasNegationMarker(string $text): bool
    {
        $lower = strtolower($text);
        foreach (self::NEGATION_MARKERS as $marker) {
            if (str_contains($lower, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @param  list<array<string,mixed>>  $historical
     */
    private function duplicateTargetMatch(array $spec, array $candidates, int $idx, array $historical): ?string
    {
        $target = $this->primaryTarget($spec);
        if ($target === '') {
            return null;
        }

        foreach ($candidates as $jdx => $other) {
            if ($jdx === $idx) {
                continue;
            }
            if ($this->primaryTarget($other) === $target) {
                return (string) ($other['task_id'] ?? "spec_{$jdx}");
            }
        }

        foreach ($historical as $example) {
            if ((string) ($example['label'] ?? '') !== 'duplicate_target') {
                continue;
            }
            if ($this->primaryTarget($example) === $target) {
                return 'historical_duplicate_target_example';
            }
        }

        return null;
    }

    private function primaryTarget(array $spec): string
    {
        $allowedFiles = (array) ($spec['allowed_files'] ?? []);

        return (string) ($allowedFiles[0] ?? '');
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
