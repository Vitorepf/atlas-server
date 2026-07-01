<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainSimplificationOutcomeLearner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainSimplificationOutcomeLearnerTest extends TestCase
{
    private function learner(): AtlasExternalBrainSimplificationOutcomeLearner
    {
        return new AtlasExternalBrainSimplificationOutcomeLearner;
    }

    public function test_success_learning_case_increases_priority(): void
    {
        $r = $this->learner()->learn([
            'outcomes' => [
                ['pattern' => 'stale_scaffold_delete', 'result' => 'success', 'line_reduction' => 200],
            ],
        ]);

        $adjustment = $r['next_policy_adjustments'][0];
        $this->assertSame('stale_scaffold_delete', $adjustment['pattern']);
        $this->assertSame('increase', $adjustment['direction']);
        $this->assertGreaterThan(0, $adjustment['delta']);
        $this->assertContains('green_commit_with_real_line_reduction', $adjustment['reasons']);
    }

    public function test_give_back_penalty_case_decreases_priority(): void
    {
        $r = $this->learner()->learn([
            'outcomes' => [
                ['pattern' => 'purpose_tag_merge', 'result' => 'give_back'],
            ],
        ]);

        $adjustment = $r['next_policy_adjustments'][0];
        $this->assertSame('purpose_tag_merge', $adjustment['pattern']);
        $this->assertSame('decrease', $adjustment['direction']);
        $this->assertLessThan(0, $adjustment['delta']);
        $this->assertContains('give_back_outcome', $adjustment['reasons']);
    }

    public function test_poison_outcome_decreases_priority_more_than_give_back(): void
    {
        $r = $this->learner()->learn([
            'outcomes' => [
                ['pattern' => 'a', 'result' => 'give_back'],
                ['pattern' => 'b', 'result' => 'poison'],
            ],
        ]);

        $byPattern = [];
        foreach ($r['next_policy_adjustments'] as $adj) {
            $byPattern[$adj['pattern']] = $adj;
        }

        $this->assertLessThan($byPattern['a']['delta'], $byPattern['b']['delta']);
    }

    public function test_regression_detected_penalizes_even_a_success(): void
    {
        $r = $this->learner()->learn([
            'outcomes' => [
                ['pattern' => 'io_semantic_merge', 'result' => 'success', 'line_reduction' => 500, 'regression_detected' => true],
            ],
        ]);

        $adjustment = $r['next_policy_adjustments'][0];
        $this->assertSame('decrease', $adjustment['direction'], 'regression must override an otherwise-green success');
        $this->assertContains('regression_detected', $adjustment['reasons']);
    }

    public function test_multiple_outcomes_for_same_pattern_accumulate(): void
    {
        $r = $this->learner()->learn([
            'outcomes' => [
                ['pattern' => 'x', 'result' => 'success', 'line_reduction' => 100],
                ['pattern' => 'x', 'result' => 'success', 'line_reduction' => 50],
                ['pattern' => 'x', 'result' => 'give_back'],
            ],
        ]);

        $this->assertCount(1, $r['next_policy_adjustments']);
        $adjustment = $r['next_policy_adjustments'][0];
        $this->assertSame(1.0, $adjustment['delta']); // +1 +1 -1
        $this->assertContains('green_commit_with_real_line_reduction', $adjustment['reasons']);
        $this->assertContains('give_back_outcome', $adjustment['reasons']);
    }

    public function test_no_provider_calls_in_source(): void
    {
        $src = (string) file_get_contents(
            __DIR__.'/../../../../../app/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainSimplificationOutcomeLearner.php'
        );
        foreach (['Http::', 'curl_', 'shell_exec', 'exec(', 'proc_open', 'OpenAI', 'Anthropic'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src);
        }
    }

    public function test_empty_outcomes_produces_empty_adjustments(): void
    {
        $r = $this->learner()->learn([]);

        $this->assertSame([], $r['next_policy_adjustments']);
    }

    public function test_schema_present(): void
    {
        $r = $this->learner()->learn([]);

        $this->assertSame(AtlasExternalBrainSimplificationOutcomeLearner::SCHEMA, $r['schema']);
    }
}
