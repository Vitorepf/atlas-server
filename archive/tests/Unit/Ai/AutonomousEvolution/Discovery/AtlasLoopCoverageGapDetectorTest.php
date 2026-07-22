<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopCoverageGapDetector;
use PHPUnit\Framework\TestCase;

/**
 * Pins the coverage-gap extraction shape against the REAL grind result observed live
 * (soak 019ec853, L7PromotionRequestBuilder): a refactor blocked by mutation_adequacy_gate
 * because the sibling test does not kill a relocated `strict_equals` decision.
 */
final class AtlasLoopCoverageGapDetectorTest extends TestCase
{
    private function blockedResult(): array
    {
        return [
            'semantic_implementation_certification' => [
                'reports' => [[
                    'certified' => false,
                    'reasons' => ['mutation_adequacy_gate:mutation_survived'],
                    'mutation_adequacy_gate' => [
                        'status' => 'mutation_survived',
                        'mutants' => [[
                            'file' => 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/L7PromotionRequestBuilder.php',
                            'mutation_id' => 'c054b759ca7c3ee5',
                            'operator' => 'strict_equals',
                            'original_hash' => 'sha256:ec73',
                            'mutant_hash' => 'sha256:ad9a',
                            'killed' => false,
                            'survived' => true,
                            'command_results' => [[
                                'command' => "./vendor/bin/phpunit 'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/L7PromotionRequestBuilderTest.php'",
                                'passed' => true,
                            ]],
                        ]],
                    ],
                ]],
            ],
        ];
    }

    public function test_extracts_the_actionable_coverage_gap_from_a_blocked_refactor(): void
    {
        $gaps = (new AtlasLoopCoverageGapDetector())->gapsFromTaskResult($this->blockedResult());

        $this->assertCount(1, $gaps);
        $this->assertSame('app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/L7PromotionRequestBuilder.php', $gaps[0]['target_file']);
        $this->assertSame('strict_equals', $gaps[0]['decision_operator']);
        $this->assertSame('c054b759ca7c3ee5', $gaps[0]['mutation_id']);
        $this->assertSame('sha256:ad9a', $gaps[0]['mutant_hash']);
        $this->assertSame('tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/L7PromotionRequestBuilderTest.php', $gaps[0]['sibling_test']);
    }

    public function test_certified_refactor_yields_no_gap(): void
    {
        $r = $this->blockedResult();
        $r['semantic_implementation_certification']['reports'][0]['certified'] = true;
        $r['semantic_implementation_certification']['reports'][0]['reasons'] = ['certified'];
        $r['semantic_implementation_certification']['reports'][0]['mutation_adequacy_gate']['status'] = 'mutation_killed';
        $r['semantic_implementation_certification']['reports'][0]['mutation_adequacy_gate']['mutants'][0]['survived'] = false;
        $r['semantic_implementation_certification']['reports'][0]['mutation_adequacy_gate']['mutants'][0]['killed'] = true;

        $this->assertSame([], (new AtlasLoopCoverageGapDetector())->gapsFromTaskResult($r));
    }

    public function test_rejection_for_a_different_reason_is_not_misattributed(): void
    {
        // A decisions-gate / cross-file refusal is NOT a coverage gap the test lane can fix.
        $r = $this->blockedResult();
        $r['semantic_implementation_certification']['reports'][0]['reasons'] = ['complexity_decisions_gate:not_reduced'];
        $r['semantic_implementation_certification']['reports'][0]['mutation_adequacy_gate']['status'] = 'mutation_killed';

        $this->assertSame([], (new AtlasLoopCoverageGapDetector())->gapsFromTaskResult($r));
    }

    public function test_duplicate_mutants_dedupe_and_partial_mutants_are_dropped(): void
    {
        $r = $this->blockedResult();
        $mutants = $r['semantic_implementation_certification']['reports'][0]['mutation_adequacy_gate']['mutants'];
        // duplicate of the same (file, mutation_id)
        $mutants[] = $mutants[0];
        // a partial mutant with no mutation_id is unactionable -> dropped
        $mutants[] = ['file' => 'app/Foo.php', 'operator' => 'greater', 'survived' => true];
        $r['semantic_implementation_certification']['reports'][0]['mutation_adequacy_gate']['mutants'] = $mutants;

        $gaps = (new AtlasLoopCoverageGapDetector())->gapsFromTaskResult($r);
        $this->assertCount(1, $gaps);
    }

    public function test_empty_or_malformed_result_is_safe(): void
    {
        $d = new AtlasLoopCoverageGapDetector();
        $this->assertSame([], $d->gapsFromTaskResult([]));
        $this->assertSame([], $d->gapsFromTaskResult(['semantic_implementation_certification' => 'not-an-array']));
    }

    public function test_noise_is_filtered_to_only_actionable_gaps(): void
    {
        // Build a report whose surviving mutants are ALL noise: a cosmetic string_literal, a src/*
        // self-contained fixture, a *Test.php target, and a generated-fixture sibling. None is an
        // actionable production-code coverage gap.
        $r = $this->blockedResult();
        $r['semantic_implementation_certification']['reports'][0]['mutation_adequacy_gate']['mutants'] = [
            ['file' => 'app/Services/Foo.php', 'mutation_id' => 'm1', 'operator' => 'string_literal', 'survived' => true,
                'command_results' => [['command' => "./vendor/bin/phpunit 'tests/Unit/FooTest.php'"]]],
            ['file' => 'src/L8Scorer.php', 'mutation_id' => 'm2', 'operator' => 'strict_equals', 'survived' => true,
                'command_results' => [['command' => "./vendor/bin/phpunit 'tests/atlas_generated_0.php'"]]],
            ['file' => 'app/Services/BarTest.php', 'mutation_id' => 'm3', 'operator' => 'return_integer', 'survived' => true,
                'command_results' => [['command' => "./vendor/bin/phpunit 'tests/Unit/BarTest.php'"]]],
            ['file' => 'app/Services/Baz.php', 'mutation_id' => 'm4', 'operator' => 'strict_equals', 'survived' => true,
                'command_results' => [['command' => "./vendor/bin/phpunit 'tests/atlas_generated_3.php'"]]],
        ];

        $this->assertSame([], (new AtlasLoopCoverageGapDetector())->gapsFromTaskResult($r), 'cosmetic + src/* + *Test.php + generated-sibling are all non-actionable');
    }

    public function test_real_decision_gap_on_app_file_survives_the_filters(): void
    {
        $r = $this->blockedResult();
        $r['semantic_implementation_certification']['reports'][0]['mutation_adequacy_gate']['mutants'] = [
            // noise
            ['file' => 'src/X.php', 'mutation_id' => 'n1', 'operator' => 'string_literal', 'survived' => true,
                'command_results' => [['command' => "./vendor/bin/phpunit 'tests/atlas_generated_0.php'"]]],
            // the one real, actionable decision gap
            ['file' => 'app/Services/Ai/Real/PromotionScorer.php', 'mutation_id' => 'real1', 'operator' => 'strict_equals',
                'mutant_hash' => 'sha256:zz', 'survived' => true,
                'command_results' => [['command' => "./vendor/bin/phpunit 'tests/Unit/Ai/Real/PromotionScorerTest.php'"]]],
        ];

        $gaps = (new AtlasLoopCoverageGapDetector())->gapsFromTaskResult($r);
        $this->assertCount(1, $gaps);
        $this->assertSame('app/Services/Ai/Real/PromotionScorer.php', $gaps[0]['target_file']);
        $this->assertSame('tests/Unit/Ai/Real/PromotionScorerTest.php', $gaps[0]['sibling_test']);
    }
}
