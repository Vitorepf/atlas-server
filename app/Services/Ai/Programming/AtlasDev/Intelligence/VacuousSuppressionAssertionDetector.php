<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Intelligence;

final class VacuousSuppressionAssertionDetector
{
    /**
     * @return array{verdict: 'empty'|'suppression_only'|'has_real_statements', suppression_count: int, has_other_statements: bool}
     */
    public function detect(string $methodBody): array
    {
        $stripComments = static function (string $source): string {
            $clean = '';
            foreach (token_get_all("<?php\n".$source) as $token) {
                if (is_array($token)) {
                    if (in_array($token[0], [T_OPEN_TAG, T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }

                    $clean .= $token[1];
                } else {
                    $clean .= $token;
                }
            }

            return $clean;
        };

        $normalizedExecutable = static fn (string $source): string => trim((string) preg_replace('/[\s;{}]+/', '', $source));

        $clean = $stripComments($methodBody);
        if ($normalizedExecutable($clean) === '') {
            return [
                'verdict' => 'empty',
                'suppression_count' => 0,
                'has_other_statements' => false,
            ];
        }

        $ranges = [];
        if (preg_match_all(
            '/(?:(?:\$this|self|static)\s*(?:->|::)\s*)?(?:markTestIncomplete|markTestSkipped|expectNotToPerformAssertions)\s*\(/',
            $clean,
            $calls,
            PREG_OFFSET_CAPTURE
        ) !== false) {
            foreach ($calls[0] as [$call, $start]) {
                $open = $start + strlen($call) - 1;
                $depth = 0;
                $quote = null;
                $close = null;
                $length = strlen($clean);

                for ($i = $open; $i < $length; $i++) {
                    $char = $clean[$i];
                    $previous = $i > 0 ? $clean[$i - 1] : '';

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

                $end = $close + 1;
                while ($end < $length && ctype_space($clean[$end])) {
                    $end++;
                }
                if ($end < $length && $clean[$end] === ';') {
                    $end++;
                }

                $ranges[] = [$start, $end];
            }
        }

        $residual = $clean;
        foreach (array_reverse($ranges) as [$start, $end]) {
            $residual = substr_replace($residual, str_repeat(' ', $end - $start), $start, $end - $start);
        }

        $hasOtherStatements = $normalizedExecutable($residual) !== '';

        return [
            'verdict' => $hasOtherStatements ? 'has_real_statements' : 'suppression_only',
            'suppression_count' => count($ranges),
            'has_other_statements' => $hasOtherStatements,
        ];
    }
}
