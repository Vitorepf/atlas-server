<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * Pure coverage-theater detector for the area-focus loop.
 *
 * Given the added lines of one or more test files (the same per-file diff bodies
 * the loop already carries) plus the list of production symbols the change is
 * supposed to exercise, this scorer classifies the test contribution WITHOUT any
 * I/O, DB, facade, provider or filesystem access. It judges only the literal
 * added-line text.
 *
 * The mandate is to refuse "green that proves nothing": tests built from
 * tautological assertions (assertTrue(true), assertSame identical operands),
 * explicit no-assertion markers (expectNotToPerformAssertions()), or empty test
 * bodies that never touch the production symbol under change. Such a contribution
 * is flagged as `theater` and scored low so the loop can withhold credit.
 *
 * Every returned field is COMPUTED from the inputs via real classification rules;
 * no value is keyed to a particular fixture.
 */
final class TestMeaningfulnessScorer
{
    public const SCHEMA_VERSION = 'atlas.software_company_stewardship.test_meaningfulness.v1';

    private const BAND_HIGH_THRESHOLD = 0.66;

    private const BAND_MEDIUM_THRESHOLD = 0.4;

    /**
     * Hard ceiling applied to a theater contribution so it can never present as a
     * high/medium-meaningfulness change purely on density arithmetic.
     */
    private const THEATER_SCORE_CEILING = 0.39;

    /**
     * Explicit markers that an assertion line proves nothing on its own.
     *
     * @var list<string>
     */
    private const DEGENERATE_MARKERS = [
        'expectNotToPerformAssertions',
        'markTestSkipped',
        'markTestIncomplete',
    ];

    /**
     * @param  list<array{path?: string, added_lines?: list<string>}>  $testFiles
     * @param  list<string>  $productionSymbols
     * @return array{
     *     schema_version: string,
     *     meaningfulness_score: float,
     *     assertion_density: float,
     *     test_method_count: int,
     *     real_assertion_count: int,
     *     degenerate_assertion_count: int,
     *     production_referencing_assertion_count: int,
     *     theater: bool,
     *     band: 'high'|'medium'|'low',
     *     reasons: list<string>
     * }
     */
    public function score(array $testFiles, array $productionSymbols = []): array
    {
        $symbols = $this->normalizeSymbols($productionSymbols);

        $methodCount = 0;
        $realAssertions = 0;
        $degenerateAssertions = 0;
        $productionReferencingAssertions = 0;

        foreach ($testFiles as $file) {
            $lines = $this->normalizeLines($file['added_lines'] ?? []);

            $methodCount += $this->countTestMethods($lines);

            foreach ($lines as $line) {
                if (! $this->isAssertionLine($line)) {
                    continue;
                }

                if ($this->isDegenerateAssertion($line)) {
                    $degenerateAssertions++;

                    continue;
                }

                $realAssertions++;
                if ($this->referencesProductionSymbol($line, $symbols)) {
                    $productionReferencingAssertions++;
                }
            }
        }

        $totalAssertions = $realAssertions + $degenerateAssertions;
        $assertionDensity = $realAssertions / max(1, $methodCount);

        $theater = $this->isTheater(
            $methodCount,
            $realAssertions,
            $degenerateAssertions,
            $productionReferencingAssertions,
            $totalAssertions,
        );

        $score = $this->meaningfulnessScore(
            $methodCount,
            $realAssertions,
            $degenerateAssertions,
            $productionReferencingAssertions,
            $totalAssertions,
            $assertionDensity,
            $theater,
        );

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'meaningfulness_score' => $score,
            'assertion_density' => round($assertionDensity, 4),
            'test_method_count' => $methodCount,
            'real_assertion_count' => $realAssertions,
            'degenerate_assertion_count' => $degenerateAssertions,
            'production_referencing_assertion_count' => $productionReferencingAssertions,
            'theater' => $theater,
            'band' => $this->band($score),
            'reasons' => $this->reasons(
                $methodCount,
                $realAssertions,
                $degenerateAssertions,
                $productionReferencingAssertions,
                $totalAssertions,
                $theater,
            ),
        ];
    }

    /**
     * Count test methods inside one file's added lines. A method counts when its
     * declaration name starts with `test`, OR when a `#[Test]` attribute precedes
     * a function declaration of any name. An empty-body method still counts: only
     * the declaration line is required.
     *
     * @param  list<string>  $lines
     */
    private function countTestMethods(array $lines): int
    {
        $count = 0;
        $pendingTestAttribute = false;

        foreach ($lines as $line) {
            if ($this->isTestAttributeLine($line)) {
                $pendingTestAttribute = true;

                continue;
            }

            $name = $this->functionDeclarationName($line);
            if ($name === null) {
                continue;
            }

            if ($pendingTestAttribute || $this->looksLikeTestName($name)) {
                $count++;
            }

            $pendingTestAttribute = false;
        }

        return $count;
    }

    /**
     * @return string|null lowercase function name, or null when the line is not a
     *                     function declaration
     */
    private function functionDeclarationName(string $line): ?string
    {
        if (preg_match('/function\s+([A-Za-z_]\w*)\s*\(/', $line, $matches) !== 1) {
            return null;
        }

        return strtolower($matches[1]);
    }

    private function looksLikeTestName(string $lowercaseName): bool
    {
        return str_starts_with($lowercaseName, 'test');
    }

    private function isTestAttributeLine(string $line): bool
    {
        return preg_match('/#\[\s*Test\b/i', $line) === 1;
    }

    private function isAssertionLine(string $line): bool
    {
        foreach (self::DEGENERATE_MARKERS as $marker) {
            if (stripos($line, $marker.'(') !== false) {
                return true;
            }
        }

        return preg_match('/\bassert[A-Za-z]*\s*\(/', $line) === 1;
    }

    private function isDegenerateAssertion(string $line): bool
    {
        foreach (self::DEGENERATE_MARKERS as $marker) {
            if (stripos($line, $marker.'(') !== false) {
                return true;
            }
        }

        if ($this->isTautologicalBooleanAssertion($line)) {
            return true;
        }

        return $this->hasIdenticalOperands($line);
    }

    /**
     * assertTrue(true) / assertFalse(false) and their literal kin prove nothing.
     */
    private function isTautologicalBooleanAssertion(string $line): bool
    {
        return preg_match('/\bassertTrue\s*\(\s*true\s*\)/i', $line) === 1
            || preg_match('/\bassertFalse\s*\(\s*false\s*\)/i', $line) === 1
            || preg_match('/\bassertNotFalse\s*\(\s*true\s*\)/i', $line) === 1
            || preg_match('/\bassertNotTrue\s*\(\s*false\s*\)/i', $line) === 1;
    }

    /**
     * Detect assertSame/assertEquals/assertNotSame style calls whose first two
     * comma-separated operands are byte-identical, e.g. assertSame($x, $x).
     */
    private function hasIdenticalOperands(string $line): bool
    {
        if (preg_match('/\bassert(Same|Equals|NotSame|NotEquals|EqualsCanonicalizing)\s*\((.*)\)/i', $line, $matches) !== 1) {
            return false;
        }

        $arguments = $this->splitTopLevelArguments($matches[2]);
        if (count($arguments) < 2) {
            return false;
        }

        return trim($arguments[0]) === trim($arguments[1]) && trim($arguments[0]) !== '';
    }

    /**
     * Split an argument string on top-level commas only (ignoring commas nested in
     * parentheses or brackets), returning at most the leading operands needed to
     * compare the first two.
     *
     * @return list<string>
     */
    private function splitTopLevelArguments(string $argumentString): array
    {
        $arguments = [];
        $depth = 0;
        $current = '';
        $length = strlen($argumentString);

        for ($i = 0; $i < $length; $i++) {
            $char = $argumentString[$i];

            if ($char === '(' || $char === '[' || $char === '{') {
                $depth++;
            } elseif ($char === ')' || $char === ']' || $char === '}') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            }

            if ($char === ',' && $depth === 0) {
                $arguments[] = $current;
                $current = '';

                continue;
            }

            $current .= $char;
        }

        if ($current !== '') {
            $arguments[] = $current;
        }

        return $arguments;
    }

    /**
     * @param  list<string>  $symbols
     */
    private function referencesProductionSymbol(string $line, array $symbols): bool
    {
        foreach ($symbols as $symbol) {
            if ($symbol !== '' && stripos($line, $symbol) !== false) {
                return true;
            }
        }

        return false;
    }

    private function isTheater(
        int $methodCount,
        int $realAssertions,
        int $degenerateAssertions,
        int $productionReferencingAssertions,
        int $totalAssertions,
    ): bool {
        if ($methodCount === 0 && $totalAssertions === 0) {
            return false;
        }

        $noProductionReference = $methodCount > 0 && $productionReferencingAssertions === 0;
        $degenerateOutnumberReal = $degenerateAssertions >= $realAssertions;

        return $noProductionReference || $degenerateOutnumberReal;
    }

    private function meaningfulnessScore(
        int $methodCount,
        int $realAssertions,
        int $degenerateAssertions,
        int $productionReferencingAssertions,
        int $totalAssertions,
        float $assertionDensity,
        bool $theater,
    ): float {
        if ($methodCount === 0 && $totalAssertions === 0) {
            return 0.0;
        }

        $realRatio = $realAssertions / max(1, $totalAssertions);
        $productionRatio = $productionReferencingAssertions / max(1, $totalAssertions);
        $densityAdequacy = min(1.0, $assertionDensity);

        $score = 0.4 * $realRatio + 0.35 * $productionRatio + 0.25 * $densityAdequacy;
        $score = max(0.0, min(1.0, $score));

        if ($theater) {
            $score = min($score, self::THEATER_SCORE_CEILING);
        }

        return round($score, 4);
    }

    private function band(float $score): string
    {
        if ($score >= self::BAND_HIGH_THRESHOLD) {
            return 'high';
        }

        if ($score >= self::BAND_MEDIUM_THRESHOLD) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * @return list<string>
     */
    private function reasons(
        int $methodCount,
        int $realAssertions,
        int $degenerateAssertions,
        int $productionReferencingAssertions,
        int $totalAssertions,
        bool $theater,
    ): array {
        $reasons = [];

        if ($methodCount === 0) {
            $reasons[] = 'no_test_methods';
        }

        if ($methodCount > 0 && $productionReferencingAssertions === 0) {
            $reasons[] = 'no_production_reference';
        }

        if ($totalAssertions > 0 && $degenerateAssertions >= $realAssertions) {
            $reasons[] = 'degenerate_outnumber_real';
        }

        if ($realAssertions < $methodCount) {
            $reasons[] = 'methods_without_real_assertion';
        }

        if (! $theater && $realAssertions > 0) {
            $reasons[] = 'meaningful_assertions_present';
        }

        if ($reasons === []) {
            $reasons[] = 'no_meaningfulness_signal';
        }

        return $reasons;
    }

    /**
     * @param  mixed  $addedLines
     * @return list<string>
     */
    private function normalizeLines($addedLines): array
    {
        if (! is_array($addedLines)) {
            return [];
        }

        $lines = [];
        foreach ($addedLines as $line) {
            if (is_string($line)) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @param  list<string>  $productionSymbols
     * @return list<string>
     */
    private function normalizeSymbols(array $productionSymbols): array
    {
        $symbols = [];
        foreach ($productionSymbols as $symbol) {
            if (is_string($symbol) && trim($symbol) !== '') {
                $symbols[] = trim($symbol);
            }
        }

        return $symbols;
    }
}
