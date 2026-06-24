<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\SelfMod\FormalProofs\AtlasLoopFormalInvariantExpressionParser;
use Tests\TestCase;

final class AtlasLoopFormalInvariantExpressionParserTest extends TestCase
{
    public function test_parser_builds_deterministic_ast_for_supported_invariant_shapes(): void
    {
        $parser = new AtlasLoopFormalInvariantExpressionParser;
        $expressions = [
            'always($this->ready == true)',
            'never($this->count < 0)',
            'implies($this->enabled, ClassName::CONST >= 1)',
            '$this->items + 1 >= 3 && $this->enabled == true',
            '!$this->disabled',
            '($this->left + 1) >= ($this->right - 2)',
        ];

        foreach ($expressions as $expression) {
            $first = $parser->parse($expression);
            $second = $parser->parse($expression);

            $this->assertSame([], $first['errors'], $expression);
            $this->assertSame($first, $second, $expression);
            $this->assertIsArray($first['ast'], $expression);
            $this->assertArrayHasKey('type', $first['ast'], $expression);
            $this->assertSame(
                json_encode($first['ast'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                json_encode($second['ast'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $expression,
            );
        }
    }

    public function test_parser_fail_closes_for_hostile_inputs_without_throwing(): void
    {
        $parser = new AtlasLoopFormalInvariantExpressionParser;
        $inputs = [
            'unknownFn($this->ready)',
            '$this->value = 1',
            'always(($this->ready == true)',
            '@badtoken',
        ];

        foreach ($inputs as $input) {
            $result = $parser->parse($input);

            $this->assertNull($result['ast'], $input);
            $this->assertNotSame([], $result['errors'], $input);
            $this->assertArrayHasKey('offset', $result['errors'][0], $input);
            $this->assertArrayHasKey('message', $result['errors'][0], $input);
        }
    }

    public function test_allowed_predicates_are_exactly_the_whitelist(): void
    {
        $parser = new AtlasLoopFormalInvariantExpressionParser;

        $this->assertSame([], $parser->parse('always($this->ready == true)')['errors']);
        $this->assertSame([], $parser->parse('never($this->count < 0)')['errors']);
        $this->assertSame([], $parser->parse('implies($this->enabled, $this->count >= 1)')['errors']);

        $rejected = $parser->parse('sometimes($this->ready == true)');
        $this->assertNull($rejected['ast']);
        $this->assertSame('non_whitelisted_predicate', $rejected['errors'][0]['message']);
        $this->assertSame('sometimes', $rejected['errors'][0]['token']);
    }
}
