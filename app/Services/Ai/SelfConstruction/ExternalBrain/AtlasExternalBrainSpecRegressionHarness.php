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

    /** @var array<string,string> */
    private const REPAIR_HINTS = [
        self::CLASS_POISON => 'Rewrite the spec objective so it no longer resembles a known poison pattern — add precise domain-specific context and measurable acceptance criteria.',
        self::CLASS_WRAPPER_FARM => 'Eliminate thin delegation. The spec must describe novel behavior, not just wrap an existing service.',
        self::CLASS_DUPLICATE => 'Consolidate with the duplicate spec or re-scope to a materially different objective.',
        self::CLASS_UNDERSPECIFIED => 'Add implementation_files, test_files and acceptance_criteria so the spec has concrete scope.',
        self::CLASS_TEST_ONLY_PACKET => 'Include an implementation target (app/ file) alongside the test file. A repair spec without code to fix is not actionable.',
        self::CLASS_FORBIDDEN_TARGET => 'Remove the forbidden target from allowed_files and re-scope to a different implementation path.',
        self::CLASS_CONTRADICTORY_ACCEPTANCE => 'Remove or rephrase one of the conflicting acceptance criteria so the spec no longer demands mutually exclusive outcomes.',
        self::CLASS_DUPLICATE_TARGET => 'Each spec must target a unique primary file. Consolidate or split scopes across distinct target paths.',
        self::CLASS_TEMPLATE_FARM => 'Add domain-specific behavior evidence and concrete implementation details. Template-only specs add no measurable capability.',
    ];

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

        // Single detection-result circuit: every regression class is recorded through this
        // closure so matched_regressions and verdict escalation can never diverge — a class
        // marked FAIL always both appends the record AND escalates the verdict together.
        $record = function (string $specId, string $class, string $evidence, string $matchedLabel, string $severity) use (&$matchedRegressions, &$overallVerdict): void {
            $matchedRegressions[] = [
                'spec_id'               => $specId,
                'class'                 => $class,
                'evidence'              => $evidence,
                'matched_example_label' => $matchedLabel,
                'severity'              => $severity,
                'gate'                  => self::GATE_BY_CLASS[$class],
            ];

            if ($severity === self::VERDICT_FAIL) {
                $overallVerdict = self::VERDICT_FAIL;
            } elseif ($severity === self::VERDICT_WARNING && $overallVerdict === self::VERDICT_PASS) {
                $overallVerdict = self::VERDICT_WARNING;
            }
        };

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
                    $record($specId, self::CLASS_POISON, "similarity {$sim} with {$label} example: \"{$example['objective']}\"", $label, self::VERDICT_FAIL);
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
                    $record($specId, self::CLASS_WRAPPER_FARM, "similarity {$sim} with shallow_wrapper example: \"{$example['objective']}\"", 'shallow_wrapper', self::VERDICT_WARNING);
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
                    $record($specId, self::CLASS_DUPLICATE, "similarity {$sim} with duplicate example: \"{$example['objective']}\"", 'duplicate', self::VERDICT_WARNING);
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
                    $record($specId, self::CLASS_DUPLICATE, "cross-candidate similarity {$sim} with {$otherId}", 'cross_candidate_duplicate', self::VERDICT_WARNING);
                    continue 2;
                }
            }

            // 4. Underspecified scope (warning)
            if ($this->isUnderspecified($spec)) {
                $record($specId, self::CLASS_UNDERSPECIFIED, 'no implementation_files, no test_files, and no acceptance_criteria', 'none', self::VERDICT_WARNING);
            }

            // 5. Test-only packet (fail) — allowed_files exist but every one is a test file.
            if ($this->isTestOnlyPacket($spec)) {
                $record($specId, self::CLASS_TEST_ONLY_PACKET, 'allowed_files are entirely test files with no implementation target', 'none', self::VERDICT_FAIL);
            }

            // 6. Forbidden implementation target (fail).
            $forbiddenHit = $this->forbiddenTargetHit($spec, (array) ($input['forbidden_targets'] ?? []));
            if ($forbiddenHit !== null) {
                $record($specId, self::CLASS_FORBIDDEN_TARGET, "allowed_files includes forbidden target: {$forbiddenHit}", 'none', self::VERDICT_FAIL);
            }

            // 7. Contradictory acceptance (fail) — two criteria over the same subject that negate each other.
            $contradiction = $this->contradictoryAcceptancePair((array) ($spec['acceptance_criteria'] ?? []));
            if ($contradiction !== null) {
                $record($specId, self::CLASS_CONTRADICTORY_ACCEPTANCE, "contradictory acceptance criteria: \"{$contradiction[0]}\" vs \"{$contradiction[1]}\"", 'none', self::VERDICT_FAIL);
            }

            // 8. Duplicate target (warning) — same primary allowed_files target as a historical or sibling candidate.
            $duplicateTargetOf = $this->duplicateTargetMatch($spec, $candidates, $idx, $historical);
            if ($duplicateTargetOf !== null) {
                $record($specId, self::CLASS_DUPLICATE_TARGET, "same primary target as {$duplicateTargetOf}", 'duplicate_target', self::VERDICT_WARNING);
            }

            // 9. Template-farm spec (warning) — matches a historical 'template_farm' labeled example.
            foreach ($historical as $example) {
                $label = (string) ($example['label'] ?? '');
                if ($label !== 'template_farm') {
                    continue;
                }
                $sim = $this->similarity($kw, $this->keywords((string) ($example['objective'] ?? '')));
                if ($sim >= self::SIMILARITY_THRESHOLD) {
                    $record($specId, self::CLASS_TEMPLATE_FARM, "similarity {$sim} with template_farm example: \"{$example['objective']}\"", 'template_farm', self::VERDICT_WARNING);
                    break;
                }
            }
        }

        // AC1: a whole BATCH exhibiting template_farm is worse than one candidate touching the
        // pattern — 2+ template_farm matches in the same replay means the originator itself is
        // template-farming, not just one weak spec. Escalate the batch to fail.
        $templateFarmMatchCount = count(array_filter(
            $matchedRegressions,
            static fn (array $m): bool => $m['class'] === self::CLASS_TEMPLATE_FARM,
        ));
        $batchTemplateFarm = $templateFarmMatchCount >= 2;
        if ($batchTemplateFarm) {
            $overallVerdict = self::VERDICT_FAIL;
            $evidenceLines[] = "batch_template_farm: {$templateFarmMatchCount} candidates matched template_farm — whole batch fails replay";
        }

        $evidenceLines[] = count($matchedRegressions) === 0
            ? 'all candidates passed regression replay'
            : count($matchedRegressions).' regression(s) matched across '.count($candidates).' candidate(s)';

        // AC4: replayed_fixtures — count of historical examples replayed.
        $replayedFixtures = count($historical);

        // AC4: failed_fixtures — unique labels from historical examples that caused a FAIL.
        $failedFixtureLabels = array_values(array_unique(array_filter(array_map(
            static fn (array $m): string => $m['severity'] === self::VERDICT_FAIL ? $m['matched_example_label'] : '',
            $matchedRegressions,
        ), static fn (string $l): bool => $l !== '')));

        // AC4: repaired_spec_hints — hints targeted at the regression classes found.
        $matchedClasses = array_values(array_unique(array_map(
            static fn (array $m): string => $m['class'],
            $matchedRegressions,
        )));
        $repairHints = [];
        foreach ($matchedClasses as $class) {
            if (isset(self::REPAIR_HINTS[$class])) {
                $repairHints[] = ['class' => $class, 'hint' => self::REPAIR_HINTS[$class]];
            }
        }

        return [
            'schema'               => self::SCHEMA,
            'verdict'              => $overallVerdict,
            'matched_regressions'  => $matchedRegressions,
            'evidence'             => $evidenceLines,
            'covered_regressions'  => array_values(array_keys(self::GATE_BY_CLASS)),
            'replayed_fixtures'    => $replayedFixtures,
            'failed_fixtures'      => $failedFixtureLabels,
            'repaired_spec_hints'  => $repairHints,
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
