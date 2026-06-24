<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\SelfMod\AtlasLoopSelfModEditClassifier;
use App\Services\Ai\AutonomousEvolution\SelfMod\AtlasLoopSelfModInvariantExtractor;
use App\Services\Ai\AutonomousEvolution\SelfMod\AtlasLoopSelfModInvariantSurvivalChecker;
use Tests\TestCase;

final class AtlasLoopSelfModInvariantSurvivalCheckerTest extends TestCase
{
    public function test_cosmetic_edit_holds_all_invariants_and_has_no_uncheckable_results(): void
    {
        $checker = new AtlasLoopSelfModInvariantSurvivalChecker(new AtlasLoopSelfModEditClassifier, new AtlasLoopSelfModInvariantExtractor);
        $pre = <<<'PHP'
<?php
namespace Demo\Cosmetic;
final class CosmeticClass {
    public int $count = 1;
}
PHP;
        $post = <<<'PHP'
<?php
namespace Demo\Cosmetic;

final class CosmeticClass {
    public int $count = 1;
}
PHP;

        $report = $checker->check($pre, $post, ['kind' => 'COSMETIC'], [
            'Demo\Cosmetic\CosmeticClass' => [
                ['class' => 'Demo\Cosmetic\CosmeticClass', 'invariant_id' => 'one', 'expression' => '$this->count >= 0', 'applies_to' => 'class'],
                ['class' => 'Demo\Cosmetic\CosmeticClass', 'invariant_id' => 'two', 'expression' => '$this->count === 1', 'applies_to' => 'class'],
            ],
        ]);

        $this->assertSame('HOLDS', $report['results'][0]['status']);
        $this->assertSame('HOLDS', $report['results'][1]['status']);
        $this->assertSame('APPROVED', $report['verdict']);
    }

    public function test_semantic_edit_returns_violated_for_failing_method_level_invariant(): void
    {
        $checker = new AtlasLoopSelfModInvariantSurvivalChecker(new AtlasLoopSelfModEditClassifier, new AtlasLoopSelfModInvariantExtractor);
        $pre = <<<'PHP'
<?php
namespace Demo\Semantic;
final class Counter {
    private int $value = 0;
    public function decrement(int $amount): void { $this->value -= $amount; }
    public function count(): int { return $this->value; }
}
PHP;
        $post = $pre;

        $report = $checker->check($pre, $post, ['kind' => 'SEMANTIC'], [
            'Demo\Semantic\Counter' => [
                ['class' => 'Demo\Semantic\Counter', 'invariant_id' => 'non_negative_count', 'expression' => '$this->count() >= 0', 'applies_to' => 'method', 'method' => 'decrement'],
            ],
        ], [
            'Demo\Semantic\Counter' => [
                'method_calls' => [
                    'decrement' => [[1]],
                ],
            ],
        ]);

        $this->assertSame('VIOLATED', $report['results'][0]['status']);
        $this->assertSame('non_negative_count', $report['results'][0]['evidence']['failing_invariant_id']);
        $this->assertSame(-1, $report['results'][0]['evidence']['offending_value']);
    }

    public function test_structural_edit_with_missing_property_is_uncheckable_and_rejected(): void
    {
        $checker = new AtlasLoopSelfModInvariantSurvivalChecker(new AtlasLoopSelfModEditClassifier, new AtlasLoopSelfModInvariantExtractor);
        $pre = <<<'PHP'
<?php
namespace Demo\StructuralBad;
final class Flag {
    public bool $enabled = true;
}
PHP;
        $post = <<<'PHP'
<?php
namespace Demo\StructuralBad;
final class Flag {
}
PHP;

        $report = $checker->check($pre, $post, ['kind' => 'STRUCTURAL'], [
            'Demo\StructuralBad\Flag' => [
                ['class' => 'Demo\StructuralBad\Flag', 'invariant_id' => 'flag_enabled', 'expression' => '$this->enabled === true', 'applies_to' => 'class'],
            ],
        ]);

        $this->assertSame('UNCHECKABLE', $report['results'][0]['status']);
        $this->assertSame('enabled', $report['results'][0]['evidence']['missing_symbol']);
        $this->assertSame('REJECTED', $report['verdict']);
    }

    public function test_structural_edit_with_holding_class_level_invariants_is_approved(): void
    {
        $checker = new AtlasLoopSelfModInvariantSurvivalChecker(new AtlasLoopSelfModEditClassifier, new AtlasLoopSelfModInvariantExtractor);
        $pre = <<<'PHP'
<?php
namespace Demo\StructuralGood;
final class Flag {
    public bool $enabled = true;
}
PHP;
        $post = <<<'PHP'
<?php
namespace Demo\StructuralGood;
final class Flag {
    public bool $enabled = true;
    public string $label = 'ok';
}
PHP;

        $report = $checker->check($pre, $post, ['kind' => 'STRUCTURAL'], [
            'Demo\StructuralGood\Flag' => [
                ['class' => 'Demo\StructuralGood\Flag', 'invariant_id' => 'flag_enabled', 'expression' => '$this->enabled === true', 'applies_to' => 'class'],
            ],
        ]);

        $this->assertSame('HOLDS', $report['results'][0]['status']);
        $this->assertSame('APPROVED', $report['verdict']);
    }
}
