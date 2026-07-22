<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\SelfMod\FormalProofs\AtlasLoopFormalInvariantExpressionParser;
use App\Services\Ai\AutonomousEvolution\SelfMod\FormalProofs\AtlasLoopFormalInvariantSurvivalProver;
use Tests\TestCase;

final class AtlasLoopFormalInvariantSurvivalProverTest extends TestCase
{
    public function test_always_invariant_preserved_by_edit_returns_survives(): void
    {
        $ast = $this->parse('always($this->ready == true)');
        $pre = <<<'PHP'
<?php
final class Fixture {
    public function boot(): void {
        $this->ready = true;
    }
}
PHP;
        $post = <<<'PHP'
<?php
final class Fixture {
    public function boot(): void {
        $this->ready = true;
        $noop = 1;
    }
}
PHP;

        $result = (new AtlasLoopFormalInvariantSurvivalProver)->prove('inv-ready', $ast, $pre, $post);

        $this->assertSame('survives', $result['verdict']);
        $this->assertSame('inv-ready', $result['invariant_id']);
        $this->assertNotSame([], $result['witness_nodes']);
        $this->assertSame('$this->ready', $result['witness_nodes'][0]['symbol']);
    }

    public function test_deleted_guard_assignment_returns_broken_with_witness_lines(): void
    {
        $ast = $this->parse('always($this->ready == true)');
        $pre = <<<'PHP'
<?php
final class Fixture {
    public function boot(): void {
        $this->ready = true;
    }
}
PHP;
        $post = <<<'PHP'
<?php
final class Fixture {
    public function boot(): void {
        $noop = true;
    }
}
PHP;

        $result = (new AtlasLoopFormalInvariantSurvivalProver)->prove('inv-ready', $ast, $pre, $post);

        $this->assertSame('broken', $result['verdict']);
        $this->assertNotSame([], $result['witness_nodes']);
        $this->assertSame(4, $result['witness_nodes'][0]['start_line']);
        $this->assertSame(4, $result['witness_nodes'][0]['end_line']);
    }

    public function test_unsupported_ast_node_is_indeterminate_never_survives(): void
    {
        $ast = [
            'type' => 'mystery',
            'children' => [],
        ];

        $result = (new AtlasLoopFormalInvariantSurvivalProver)->prove('inv-unknown', $ast, '<?php final class X {}', '<?php final class X {}');

        $this->assertSame('indeterminate', $result['verdict']);
        $this->assertSame('unsupported_ast_node', $result['unsupported_reason']);
        $this->assertSame([], $result['witness_nodes']);
    }

    public function test_never_invariant_broken_when_forbidden_symbol_persists_in_post(): void
    {
        $ast = $this->parse('never($dangerousFlag)');
        $pre = <<<'PHP'
<?php
final class Fixture {
    public function run(): void {
        $dangerousFlag = true;
    }
}
PHP;
        $post = <<<'PHP'
<?php
final class Fixture {
    public function run(): void {
        $dangerousFlag = true;
        $extra = 1;
    }
}
PHP;

        $result = (new AtlasLoopFormalInvariantSurvivalProver)->prove('inv-never', $ast, $pre, $post);

        $this->assertSame('broken', $result['verdict'], 'forbidden symbol persists in post — must be broken, not survives');
        $this->assertNotSame([], $result['witness_nodes']);
    }

    public function test_implies_invariant_broken_when_antecedent_present_and_consequent_absent_in_post(): void
    {
        $ast = $this->parse('implies($featureFlag, $featureGuard)');
        $pre = <<<'PHP'
<?php
final class Fixture {
    public function run(): void {
        $featureFlag = true;
        $featureGuard = true;
    }
}
PHP;
        $post = <<<'PHP'
<?php
final class Fixture {
    public function run(): void {
        $featureFlag = true;
    }
}
PHP;

        $result = (new AtlasLoopFormalInvariantSurvivalProver)->prove('inv-implies', $ast, $pre, $post);

        $this->assertSame('broken', $result['verdict'], 'antecedent present but consequent removed — implication violated');
        $this->assertNotSame([], $result['witness_nodes']);
    }

    /**
     * @return array<string,mixed>
     */
    private function parse(string $expression): array
    {
        $parsed = (new AtlasLoopFormalInvariantExpressionParser)->parse($expression);
        $this->assertSame([], $parsed['errors']);
        $this->assertIsArray($parsed['ast']);

        return $parsed['ast'];
    }
}
