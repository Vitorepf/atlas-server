<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos;

use App\Services\Ai\Aaeos\AtlasAaeosGateSignalEvaluator;
use PHPUnit\Framework\TestCase;

final class AtlasAaeosGateSignalEvaluatorTest extends TestCase
{
    private AtlasAaeosGateSignalEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new AtlasAaeosGateSignalEvaluator();
    }

    public function testEachReturnDeclaresGateSignalSchemaVersion(): void
    {
        $intent = $this->evaluator->evaluateIntentClarity([
            'resolved_target' => 'app/Services/Ai/Aaeos/Foo.php',
            'scope_bounded' => true,
            'ambiguity_tokens' => [],
            'missing_answers' => [],
        ]);
        $spec = $this->evaluator->evaluateSpecPackAcceptanceCriteria([
            'acceptance_criteria' => ['a', 'b', 'c'],
        ]);
        $tasks = $this->evaluator->evaluateTaskPackAtomicity([
            'tasks' => [['scope' => 'build login', 'acceptance' => 'renders']],
        ]);
        $phase = $this->evaluator->evaluatePhaseGates([
            'intent' => ['resolved_target' => 'x', 'scope_bounded' => true],
        ]);

        $this->assertSame('atlas.aaeos.gate_signal.v1', $intent['schema_version']);
        $this->assertSame('atlas.aaeos.gate_signal.v1', $spec['schema_version']);
        $this->assertSame('atlas.aaeos.gate_signal.v1', $tasks['schema_version']);
        $this->assertSame('atlas.aaeos.gate_signal.v1', $phase['schema_version']);
    }

    public function testGateKeysMatchRunbookLexicon(): void
    {
        $this->assertSame(
            'intent_clarity_score_min_0_8',
            $this->evaluator->evaluateIntentClarity([])['gate']
        );
        $this->assertSame(
            'spec_pack_acceptance_criteria_min_3',
            $this->evaluator->evaluateSpecPackAcceptanceCriteria([])['gate']
        );
        $this->assertSame(
            'task_pack_atomic_true_for_each',
            $this->evaluator->evaluateTaskPackAtomicity([])['gate']
        );
    }

    /**
     * Acceptance (1) — P1.
     */
    public function testP1HighClarityPassesAndLowClarityFailsStrictlyBelow(): void
    {
        $high = $this->evaluator->evaluateIntentClarity([
            'resolved_target' => 'app/Services/Ai/Aaeos/Target.php',
            'scope_bounded' => true,
            'ambiguity_tokens' => [],
            'missing_answers' => [],
        ]);

        $this->assertIsFloat($high['computed_value']);
        $this->assertGreaterThanOrEqual(0.8, $high['computed_value']);
        $this->assertTrue($high['passed']);

        $low = $this->evaluator->evaluateIntentClarity([
            'resolved_target' => null,
            'scope_bounded' => false,
            'ambiguity_tokens' => ['it', 'that'],
            'missing_answers' => ['which target?'],
        ]);

        $this->assertIsFloat($low['computed_value']);
        $this->assertLessThan(0.8, $low['computed_value']);
        $this->assertFalse($low['passed']);

        // The low-clarity score must be strictly less than the high-clarity score.
        $this->assertLessThan($high['computed_value'], $low['computed_value']);
    }

    public function testP1MissingAnswersAcceptedAsIntCount(): void
    {
        $result = $this->evaluator->evaluateIntentClarity([
            'resolved_target' => null,
            'scope_bounded' => false,
            'ambiguity_tokens' => ['foo', 'bar'],
            'missing_answers' => 3,
        ]);

        $this->assertSame(0.0, $result['computed_value']);
        $this->assertFalse($result['passed']);
    }

    /**
     * Acceptance (2) — P7.
     */
    public function testP7ExactlyTwoCriteriaFailsAndThreeDistinctWithBlankPasses(): void
    {
        $two = $this->evaluator->evaluateSpecPackAcceptanceCriteria([
            'acceptance_criteria' => ['renders the page', 'returns 200'],
        ]);

        $this->assertSame(2, $two['computed_value']);
        $this->assertFalse($two['passed']);

        $three = $this->evaluator->evaluateSpecPackAcceptanceCriteria([
            'acceptance_criteria' => ['renders the page', 'returns 200', 'logs the event', '   '],
        ]);

        $this->assertSame(3, $three['computed_value']);
        $this->assertTrue($three['passed']);
    }

    public function testP7DuplicateCriteriaDoNotInflateCount(): void
    {
        $result = $this->evaluator->evaluateSpecPackAcceptanceCriteria([
            'acceptance_criteria' => ['returns 200', 'returns 200', 'logs the event'],
        ]);

        $this->assertSame(2, $result['computed_value']);
        $this->assertFalse($result['passed']);
    }

    /**
     * Acceptance (3) — P8.
     */
    public function testP8CompoundOrMissingAcceptanceFailsAndNamesIndexWhileCleanTasksPass(): void
    {
        $compound = $this->evaluator->evaluateTaskPackAtomicity([
            'tasks' => [
                ['scope' => 'wire the gate', 'acceptance' => 'gate emits signal'],
                ['scope' => 'build X and deploy Y', 'acceptance' => 'both shipped'],
            ],
        ]);

        $this->assertFalse($compound['passed']);
        $this->assertFalse($compound['computed_value']);
        $this->assertContains('task_1_compound_scope', $compound['reasons']);

        $missingAcceptance = $this->evaluator->evaluateTaskPackAtomicity([
            'tasks' => [
                ['scope' => 'write the migration'],
            ],
        ]);

        $this->assertFalse($missingAcceptance['passed']);
        $this->assertContains('task_0_missing_acceptance', $missingAcceptance['reasons']);

        $clean = $this->evaluator->evaluateTaskPackAtomicity([
            'tasks' => [
                ['scope' => 'create the class', 'acceptance' => 'class exists'],
                ['scope' => 'write the test', 'acceptance' => 'test passes'],
            ],
        ]);

        $this->assertTrue($clean['passed']);
        $this->assertTrue($clean['computed_value']);
    }

    /**
     * Acceptance (4) — evaluatePhaseGates fans across all three phases.
     */
    public function testEvaluatePhaseGatesReflectsAndOverThreeGates(): void
    {
        $allGood = $this->evaluator->evaluatePhaseGates([
            'intent' => [
                'resolved_target' => 'app/Services/Ai/Aaeos/Target.php',
                'scope_bounded' => true,
                'ambiguity_tokens' => [],
                'missing_answers' => [],
            ],
            'spec_pack' => [
                'acceptance_criteria' => ['a clear', 'b clear', 'c clear'],
            ],
            'task_pack' => [
                'tasks' => [
                    ['scope' => 'build the class', 'acceptance' => 'class exists'],
                ],
            ],
        ]);

        $this->assertCount(3, $allGood['gates']);
        $this->assertTrue($allGood['all_passed']);

        $oneBad = $this->evaluator->evaluatePhaseGates([
            'intent' => [
                'resolved_target' => 'app/Services/Ai/Aaeos/Target.php',
                'scope_bounded' => true,
                'ambiguity_tokens' => [],
                'missing_answers' => [],
            ],
            'spec_pack' => [
                'acceptance_criteria' => ['only one'],
            ],
            'task_pack' => [
                'tasks' => [
                    ['scope' => 'build the class', 'acceptance' => 'class exists'],
                ],
            ],
        ]);

        $this->assertCount(3, $oneBad['gates']);
        $this->assertFalse($oneBad['all_passed']);
    }

    public function testEmptyPhaseOutputsFlaggedWithNoPhaseOutputsReason(): void
    {
        $result = $this->evaluator->evaluatePhaseGates([]);

        $this->assertSame([], $result['gates']);
        $this->assertFalse($result['all_passed']);
        $this->assertContains('no_phase_outputs', $result['reasons']);
    }

    public function testReasonsAreDeterministicallySorted(): void
    {
        $result = $this->evaluator->evaluateIntentClarity([
            'resolved_target' => null,
            'scope_bounded' => false,
            'ambiguity_tokens' => ['it'],
            'missing_answers' => ['which?'],
        ]);

        $sorted = $result['reasons'];
        sort($sorted, SORT_STRING);

        $this->assertSame($sorted, $result['reasons']);
    }

    public function testIdenticalInputsProduceIdenticalOutput(): void
    {
        $phaseOutputs = [
            'intent' => [
                'resolved_target' => 'app/Foo.php',
                'scope_bounded' => true,
                'ambiguity_tokens' => ['maybe'],
                'missing_answers' => 1,
            ],
            'spec_pack' => [
                'acceptance_criteria' => ['x', 'y'],
            ],
            'task_pack' => [
                'tasks' => [
                    ['scope' => 'do A and do B', 'acceptance' => 'done'],
                ],
            ],
        ];

        $first = $this->evaluator->evaluatePhaseGates($phaseOutputs);
        $second = $this->evaluator->evaluatePhaseGates($phaseOutputs);

        $this->assertSame($first, $second);
    }
}
