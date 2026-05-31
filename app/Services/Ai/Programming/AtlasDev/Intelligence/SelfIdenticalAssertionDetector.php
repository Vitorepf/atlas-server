<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Intelligence;

final class SelfIdenticalAssertionDetector
{
    /**
     * @return array{self_identical_count: int, matches: list<string>}
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

        $matches = [];
        if (preg_match_all('/(?:(?:\$this->|self::|static::))?assert(?:Same|Equals)\s*\(/', $methodBody, $calls, PREG_OFFSET_CAPTURE) === false) {
            return ['self_identical_count' => 0, 'matches' => []];
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

            $args = $splitArgs(substr($methodBody, $open + 1, $close - $open - 1));
            if (count($args) >= 2 && $args[0] === $args[1]) {
                $matches[] = substr($methodBody, $start, $close - $start + 1);
            }
        }

        return [
            'self_identical_count' => count($matches),
            'matches' => $matches,
        ];
    }
}
