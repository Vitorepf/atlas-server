<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Intelligence;

final class LiteralTautologyAssertionDetector
{
    /**
     * @return array{tautology_count: int, matches: list<string>}
     */
    public function detect(string $methodBody): array
    {
        $splitArgs = static function (string $source): array {
            $parts = [];
            $current = '';
            $depth = 0;
            $quote = null;
            $length = strlen($source);

            for ($i = 0; $i < $length; $i++) {
                $char = $source[$i];
                $previous = $i > 0 ? $source[$i - 1] : '';

                if (($char === "'" || $char === '"') && $previous !== '\\') {
                    $quote = $quote === $char ? null : ($quote ?? $char);
                } elseif ($quote === null && str_contains('([{', $char)) {
                    $depth++;
                } elseif ($quote === null && str_contains(')]}', $char)) {
                    $depth = max(0, $depth - 1);
                } elseif ($quote === null && $depth === 0 && $char === ',') {
                    $parts[] = trim($current);
                    $current = '';

                    continue;
                }

                $current .= $char;
            }

            if (trim($current) !== '' || $parts !== []) {
                $parts[] = trim($current);
            }

            return $parts;
        };
        $isLiteral = static fn (string $arg): bool => in_array($arg, ['true', 'false'], true)
            || preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d+)?$/', $arg) === 1
            || preg_match("/^'(?:[^'\\\\]|\\\\.)*'$/", $arg) === 1
            || preg_match('/^"(?:[^"\\\\]|\\\\.)*"$/', $arg) === 1;

        $matches = [];
        if (preg_match_all('/(?:(?:\$this->|self::|static::))?assert(?:True|False|Same|Equals)\s*\(/', $methodBody, $calls, PREG_OFFSET_CAPTURE) === false) {
            return ['tautology_count' => 0, 'matches' => []];
        }

        foreach ($calls[0] as [$call, $start]) {
            $open = $start + strlen($call) - 1;
            $depth = 0;
            $quote = null;
            $close = null;
            $length = strlen($methodBody);

            for ($i = $open; $i < $length; $i++) {
                $char = $methodBody[$i];
                $previous = $i > 0 ? $methodBody[$i - 1] : '';

                if (($char === "'" || $char === '"') && $previous !== '\\') {
                    $quote = $quote === $char ? null : ($quote ?? $char);
                } elseif ($quote === null && $char === '(') {
                    $depth++;
                } elseif ($quote === null && $char === ')' && --$depth === 0) {
                    $close = $i;
                    break;
                }
            }

            if ($close === null) {
                continue;
            }

            $name = preg_replace('/^.*assert/', 'assert', trim(substr($methodBody, $start, strlen($call) - 1)));
            $args = $splitArgs(substr($methodBody, $open + 1, $close - $open - 1));
            $first = trim((string) ($args[0] ?? ''));
            $second = trim((string) ($args[1] ?? ''));

            $singleLiteral = ($name === 'assertTrue' && $first === 'true')
                || ($name === 'assertFalse' && $first === 'false');
            $pairLiteral = in_array($name, ['assertSame', 'assertEquals'], true)
                && $first !== ''
                && $first === $second
                && $isLiteral($first);

            if ($singleLiteral || $pairLiteral) {
                $matches[] = substr($methodBody, $start, $close - $start + 1);
            }
        }

        return [
            'tautology_count' => count($matches),
            'matches' => $matches,
        ];
    }
}
