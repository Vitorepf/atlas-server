<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\SelfMod\FormalProofs;

final class AtlasLoopFormalInvariantExpressionParser
{
    /**
     * @var list<string>
     */
    private const ALLOWED_PREDICATES = ['always', 'never', 'implies'];

    /**
     * @return array{
     *   ast:?array<string,mixed>,
     *   errors:list<array{offset:int,message:string,token:?string}>
     * }
     */
    public function parse(string $input): array
    {
        $state = new AtlasLoopFormalInvariantExpressionParserState($this->tokenize($input));
        $ast = $this->parseExpression($state);

        if ($ast === null && $state->errors === []) {
            $state->error('empty_expression', null);
        }

        if ($state->errors === [] && ! $state->isAtEnd()) {
            $token = $state->current();
            $state->error('unexpected_trailing_token', $token);
        }

        return [
            'ast' => $state->errors === [] ? $ast : null,
            'errors' => $state->errors,
        ];
    }

    /**
     * @return list<array{type:string,value:string,offset:int}>
     */
    private function tokenize(string $input): array
    {
        $tokens = [];
        $length = strlen($input);
        $offset = 0;

        while ($offset < $length) {
            $char = $input[$offset];
            if (preg_match('/\s/', $char) === 1) {
                $offset++;

                continue;
            }

            $two = substr($input, $offset, 2);
            if (in_array($two, ['&&', '||', '==', '!=', '<=', '>=', '++', '--', '->', '::'], true)) {
                $tokens[] = ['type' => 'symbol', 'value' => $two, 'offset' => $offset];
                $offset += 2;

                continue;
            }

            if (in_array($char, ['(', ')', ',', '!', '<', '>', '+', '-', '='], true)) {
                $tokens[] = ['type' => 'symbol', 'value' => $char, 'offset' => $offset];
                $offset++;

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                $end = $offset + 1;
                $buffer = '';
                while ($end < $length && $input[$end] !== $quote) {
                    $buffer .= $input[$end];
                    $end++;
                }

                if ($end >= $length) {
                    $tokens[] = ['type' => 'invalid', 'value' => substr($input, $offset), 'offset' => $offset];
                    break;
                }

                $tokens[] = ['type' => 'string', 'value' => $buffer, 'offset' => $offset];
                $offset = $end + 1;

                continue;
            }

            if (preg_match('/\G\d+/A', $input, $matches, 0, $offset) === 1) {
                $tokens[] = ['type' => 'int', 'value' => $matches[0], 'offset' => $offset];
                $offset += strlen($matches[0]);

                continue;
            }

            if (preg_match('/\G\$[A-Za-z_][A-Za-z0-9_]*/A', $input, $matches, 0, $offset) === 1) {
                $value = $matches[0];
                $offset += strlen($value);
                while (preg_match('/\G->([A-Za-z_][A-Za-z0-9_]*)/A', $input, $chain, 0, $offset) === 1) {
                    $value .= '->'.$chain[1];
                    $offset += strlen($chain[0]);
                }
                $tokens[] = ['type' => 'identifier', 'value' => $value, 'offset' => $offset - strlen($value)];

                continue;
            }

            if (preg_match('/\G[A-Za-z_][A-Za-z0-9_\\\\]*/A', $input, $matches, 0, $offset) === 1) {
                $value = $matches[0];
                $originalOffset = $offset;
                $offset += strlen($value);

                if (preg_match('/\G::([A-Za-z_][A-Za-z0-9_]*)/A', $input, $constMatch, 0, $offset) === 1) {
                    $value .= '::'.$constMatch[1];
                    $offset += strlen($constMatch[0]);
                    $tokens[] = ['type' => 'identifier', 'value' => $value, 'offset' => $originalOffset];

                    continue;
                }

                if (in_array($value, ['true', 'false'], true)) {
                    $tokens[] = ['type' => 'bool', 'value' => $value, 'offset' => $originalOffset];

                    continue;
                }

                if ($value === 'null') {
                    $tokens[] = ['type' => 'null', 'value' => $value, 'offset' => $originalOffset];

                    continue;
                }

                $tokens[] = ['type' => 'bareword', 'value' => $value, 'offset' => $originalOffset];

                continue;
            }

            $tokens[] = ['type' => 'invalid', 'value' => $char, 'offset' => $offset];
            $offset++;
        }

        return $tokens;
    }

    private function parseExpression(AtlasLoopFormalInvariantExpressionParserState $state): ?array
    {
        return $this->parseOr($state);
    }

    private function parseOr(AtlasLoopFormalInvariantExpressionParserState $state): ?array
    {
        $left = $this->parseAnd($state);

        while ($state->matchSymbol('||')) {
            $operator = $state->previous();
            $right = $this->parseAnd($state);
            if ($left === null || $right === null) {
                return null;
            }

            $left = $this->node('binary', $operator['value'], null, null, [$left, $right]);
        }

        return $left;
    }

    private function parseAnd(AtlasLoopFormalInvariantExpressionParserState $state): ?array
    {
        $left = $this->parseEquality($state);

        while ($state->matchSymbol('&&')) {
            $operator = $state->previous();
            $right = $this->parseEquality($state);
            if ($left === null || $right === null) {
                return null;
            }

            $left = $this->node('binary', $operator['value'], null, null, [$left, $right]);
        }

        return $left;
    }

    private function parseEquality(AtlasLoopFormalInvariantExpressionParserState $state): ?array
    {
        $left = $this->parseComparison($state);

        while ($state->matchSymbol('==', '!=')) {
            $operator = $state->previous();
            $right = $this->parseComparison($state);
            if ($left === null || $right === null) {
                return null;
            }

            $left = $this->node('binary', $operator['value'], null, null, [$left, $right]);
        }

        return $left;
    }

    private function parseComparison(AtlasLoopFormalInvariantExpressionParserState $state): ?array
    {
        $left = $this->parseAdditive($state);

        while ($state->matchSymbol('<', '<=', '>', '>=')) {
            $operator = $state->previous();
            $right = $this->parseAdditive($state);
            if ($left === null || $right === null) {
                return null;
            }

            $left = $this->node('binary', $operator['value'], null, null, [$left, $right]);
        }

        return $left;
    }

    private function parseAdditive(AtlasLoopFormalInvariantExpressionParserState $state): ?array
    {
        $left = $this->parseUnary($state);

        while ($state->matchSymbol('+', '-')) {
            $operator = $state->previous();
            $right = $this->parseUnary($state);
            if ($left === null || $right === null) {
                return null;
            }

            $left = $this->node('binary', $operator['value'], null, null, [$left, $right]);
        }

        return $left;
    }

    private function parseUnary(AtlasLoopFormalInvariantExpressionParserState $state): ?array
    {
        if ($state->matchSymbol('!')) {
            $operator = $state->previous();
            $child = $this->parseUnary($state);
            if ($child === null) {
                return null;
            }

            return $this->node('unary', $operator['value'], null, null, [$child]);
        }

        if ($state->matchSymbol('++', '--')) {
            $state->error('increment_not_allowed', $state->previous());

            return null;
        }

        return $this->parsePrimary($state);
    }

    private function parsePrimary(AtlasLoopFormalInvariantExpressionParserState $state): ?array
    {
        $token = $state->current();
        if ($token === null) {
            $state->error('unexpected_end_of_input', null);

            return null;
        }

        if ($token['type'] === 'invalid') {
            $state->error('unknown_token', $token);
            $state->advance();

            return null;
        }

        if ($state->matchType('int')) {
            return $this->node('literal', null, (int) $state->previous()['value'], null, []);
        }

        if ($state->matchType('string')) {
            return $this->node('literal', null, $state->previous()['value'], null, []);
        }

        if ($state->matchType('bool')) {
            return $this->node('literal', null, $state->previous()['value'] === 'true', null, []);
        }

        if ($state->matchType('null')) {
            return $this->node('literal', null, null, null, []);
        }

        if ($state->matchSymbol('(')) {
            $expression = $this->parseExpression($state);
            if (! $state->matchSymbol(')')) {
                $state->error('unclosed_parenthesis', $state->current());

                return null;
            }

            return $this->node('group', null, null, null, [$expression]);
        }

        if ($state->matchSymbol('=')) {
            $state->error('assignment_not_allowed', $state->previous());

            return null;
        }

        if ($state->matchType('identifier')) {
            return $this->node('identifier', null, null, $state->previous()['value'], []);
        }

        if ($state->matchType('bareword')) {
            $name = $state->previous()['value'];
            if ($state->matchSymbol('(')) {
                if (! in_array($name, self::ALLOWED_PREDICATES, true)) {
                    $state->error('non_whitelisted_predicate', ['value' => $name, 'offset' => $state->previous()['offset']]);
                    $this->consumeArgumentsUntilClosingParen($state);

                    return null;
                }

                $arguments = [];
                if (! $state->checkSymbol(')')) {
                    do {
                        $argument = $this->parseExpression($state);
                        if ($argument === null) {
                            return null;
                        }
                        $arguments[] = $argument;
                    } while ($state->matchSymbol(','));
                }

                if (! $state->matchSymbol(')')) {
                    $state->error('unclosed_parenthesis', $state->current());

                    return null;
                }

                return $this->node('predicate', $name, null, null, $arguments);
            }

            $state->error('unknown_identifier_token', ['value' => $name, 'offset' => $state->previous()['offset']]);

            return null;
        }

        $state->error('unexpected_token', $token);
        $state->advance();

        return null;
    }

    private function consumeArgumentsUntilClosingParen(AtlasLoopFormalInvariantExpressionParserState $state): void
    {
        $depth = 1;
        while (! $state->isAtEnd() && $depth > 0) {
            $token = $state->advance();
            if (($token['value'] ?? null) === '(') {
                $depth++;
            } elseif (($token['value'] ?? null) === ')') {
                $depth--;
            }
        }
    }

    /**
     * @param  list<array<string,mixed>|null>  $children
     * @return array<string,mixed>
     */
    private function node(string $type, ?string $op, mixed $value, ?string $name, array $children): array
    {
        $node = ['type' => $type];
        if ($op !== null) {
            $node['op'] = $op;
        }
        if (func_num_args() >= 3 && ($type === 'literal' || $value !== null)) {
            $node['value'] = $value;
        }
        if ($name !== null) {
            $node['name'] = $name;
        }
        if ($children !== []) {
            $node['children'] = array_values(array_filter($children, static fn (mixed $child): bool => is_array($child)));
        }

        return $node;
    }
}

final class AtlasLoopFormalInvariantExpressionParserState
{
    /**
     * @param  list<array{type:string,value:string,offset:int}>  $tokens
     */
    public function __construct(
        private readonly array $tokens,
    ) {}

    public int $index = 0;

    /**
     * @var list<array{offset:int,message:string,token:?string}>
     */
    public array $errors = [];

    /**
     * @return array{type:string,value:string,offset:int}|null
     */
    public function current(): ?array
    {
        return $this->tokens[$this->index] ?? null;
    }

    /**
     * @return array{type:string,value:string,offset:int}|null
     */
    public function previous(): ?array
    {
        return $this->tokens[$this->index - 1] ?? null;
    }

    public function isAtEnd(): bool
    {
        return $this->index >= count($this->tokens);
    }

    /**
     * @return array{type:string,value:string,offset:int}|null
     */
    public function advance(): ?array
    {
        $token = $this->current();
        $this->index++;

        return $token;
    }

    public function matchType(string $type): bool
    {
        $token = $this->current();
        if (($token['type'] ?? null) !== $type) {
            return false;
        }

        $this->advance();

        return true;
    }

    public function matchSymbol(string ...$symbols): bool
    {
        $token = $this->current();
        if (($token['type'] ?? null) !== 'symbol' || ! in_array($token['value'], $symbols, true)) {
            return false;
        }

        $this->advance();

        return true;
    }

    public function checkSymbol(string $symbol): bool
    {
        $token = $this->current();

        return ($token['type'] ?? null) === 'symbol' && ($token['value'] ?? null) === $symbol;
    }

    /**
     * @param  array{value?:string,offset?:int}|null  $token
     */
    public function error(string $message, ?array $token): void
    {
        $this->errors[] = [
            'offset' => (int) ($token['offset'] ?? -1),
            'message' => $message,
            'token' => isset($token['value']) ? (string) $token['value'] : null,
        ];
    }
}
