<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs;

final class AssertionTargetReferenceCheck
{
    /**
     * @param  list<string>  $changedSymbols
     * @return array{exercises: bool, matched_symbols: list<string>, reason: string}
     */
    public function referencesChangedSymbol(string $testSource, array $changedSymbols): array
    {
        if ($changedSymbols === []) {
            return [
                'exercises' => false,
                'matched_symbols' => [],
                'reason' => 'no_target_symbols',
            ];
        }

        $scannable = $this->stripNonExecutable($testSource);

        $matched = [];
        foreach ($changedSymbols as $symbol) {
            $basename = $this->basename((string) $symbol);

            if ($basename === '' || in_array($basename, $matched, true)) {
                continue;
            }

            if ($this->wordBoundaryMatch($scannable, $basename)) {
                $matched[] = $basename;
            }
        }

        $exercises = $matched !== [];

        return [
            'exercises' => $exercises,
            'matched_symbols' => $matched,
            'reason' => $exercises ? 'references_changed_symbol' : 'no_reference_to_changed_symbol',
        ];
    }

    private function stripNonExecutable(string $source): string
    {
        $source = preg_replace('#/\*.*?\*/#s', ' ', $source) ?? $source;

        $kept = [];
        foreach (preg_split('/\R/', $source) ?: [] as $line) {
            $trimmed = ltrim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')) {
                continue;
            }

            if (preg_match('/^use\s+[^;]+;/', $trimmed) === 1) {
                continue;
            }

            $inline = strpos($line, '//');
            if ($inline !== false) {
                $line = substr($line, 0, $inline);
            }

            $kept[] = $line;
        }

        return implode("\n", $kept);
    }

    private function basename(string $symbol): string
    {
        $symbol = trim($symbol);

        $afterScope = strrpos($symbol, '::');
        if ($afterScope !== false) {
            $symbol = substr($symbol, $afterScope + 2);
        }

        $afterBackslash = strrpos($symbol, '\\');
        if ($afterBackslash !== false) {
            $symbol = substr($symbol, $afterBackslash + 1);
        }

        return trim($symbol);
    }

    private function wordBoundaryMatch(string $scannable, string $basename): bool
    {
        return preg_match('/(?<![A-Za-z0-9_])'.preg_quote($basename, '/').'(?![A-Za-z0-9_])/', $scannable) === 1;
    }
}
