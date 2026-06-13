<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * Pure scaffold / filler hollowness scorer for an autonomous-loop diff.
 *
 * Where FinalDeliveryQualityGateService blocks on explicit self-incriminating
 * markers (TODO / shape-only / mock-in-product), this scorer measures a graded
 * HOLLOWNESS DENSITY: a delivery can be free of any tripwire marker yet still be
 * a wall of comments wrapping almost no executable behaviour.
 * A reviewer feeds the added-line bodies of the product files a cycle is about
 * to land; the scorer classifies every line and grades how much of the scored
 * surface (behavioural + commentary + marker) is real logic versus filler. The
 * density is the non-behavioural share of that surface, always within [0,1].
 * Blank lines are recognised and skipped: they are not scored on either side of
 * the ratio, so trailing whitespace can neither prove logic nor feign hollowness.
 *
 * Deterministic and self-contained: it reads only the supplied diff, performs
 * no I/O, no clock, no randomness. Test files are exempt from scoring (mocks,
 * docblocks and scaffolding are legitimate there).
 */
final class ScaffoldDensityScorer
{
    public const SCHEMA_VERSION = 'atlas.software_company_stewardship.scaffold_density.v1';

    public const BAND_CLEAN = 'clean';

    public const BAND_THIN = 'thin';

    public const BAND_HOLLOW = 'hollow';

    /**
     * A density at or above this is hollow.
     */
    public const HOLLOW_THRESHOLD = 0.85;

    /**
     * A density at or above this (and below the hollow threshold) is thin.
     */
    public const THIN_THRESHOLD = 0.5;

    /**
     * Self-declared incompleteness / test-double markers. Mirrors the lexicon of
     * FinalDeliveryQualityGateService::NON_FINAL_MARKERS for the tokens this
     * scorer recognises; an added line matching any of these is a marker line
     * (it never counts as behavioural or commentary, even when it lives inside a
     * comment). Connectors accept a space or a hyphen so "shape only" and
     * "shape-only" both classify.
     *
     * @var list<array{pattern:string,label:string}>
     */
    private const MARKER_PATTERNS = [
        ['pattern' => '/step\s+\d+\s+of\s+\d+/i', 'label' => 'step_n_of_m'],
        ['pattern' => '/\bshape[\s-]+only\b/i', 'label' => 'shape_only'],
        ['pattern' => '/\bfuture[\s-]+(step|steps|work|wiring)\b/i', 'label' => 'future_work'],
        ['pattern' => '/\bnot[\s-]+(yet[\s-]+)?implemented\b/i', 'label' => 'not_implemented'],
        ['pattern' => '/\bunimplemented\b/i', 'label' => 'unimplemented'],
        ['pattern' => '/\bplaceholder\b/i', 'label' => 'placeholder'],
        ['pattern' => '/\bTODO\b/', 'label' => 'todo'],
        ['pattern' => '/\bFIXME\b/', 'label' => 'fixme'],
        ['pattern' => '/\bXXX\b/', 'label' => 'xxx'],
        ['pattern' => '/\bMockery\b/', 'label' => 'mock_in_product'],
        ['pattern' => '/->shouldReceive\s*\(/', 'label' => 'mock_in_product'],
        ['pattern' => '/\bcreateMock\s*\(/', 'label' => 'mock_in_product'],
        ['pattern' => '/\bgetMockBuilder\s*\(/', 'label' => 'mock_in_product'],
        ['pattern' => '/::mock\s*\(/', 'label' => 'mock_in_product'],
    ];

    /**
     * Comment / docblock line prefixes (checked against the trimmed line). A
     * line that starts with one of these and carries no marker token is
     * commentary.
     *
     * @var list<string>
     */
    private const COMMENTARY_PREFIXES = ['//', '#', '/*', '*/', '*'];

    /**
     * @param  array<string,list<string>>  $diff  product file path => list of added-line bodies (no leading '+')
     * @return array{
     *     schema_version: string,
     *     scaffold_density: float,
     *     real_logic_line_count: int,
     *     commentary_line_count: int,
     *     marker_line_count: int,
     *     total_added_lines: int,
     *     scanned_product_files: int,
     *     marker_hits: list<array{file:string,marker:string,line:string}>,
     *     filler_only: bool,
     *     band: string
     * }
     */
    public function score(array $diff): array
    {
        $behavioural = 0;
        $commentary = 0;
        $marker = 0;
        $scannedProductFiles = 0;
        $markerHits = [];

        foreach ($diff as $path => $lines) {
            if (! $this->isProductFile((string) $path)) {
                continue;
            }

            $scannedProductFiles++;

            foreach ((array) $lines as $rawLine) {
                $line = (string) $rawLine;
                $markerLabel = $this->markerLabel($line);

                if ($markerLabel !== null) {
                    $marker++;
                    $markerHits[] = [
                        'file' => (string) $path,
                        'marker' => $markerLabel,
                        'line' => trim($line),
                    ];

                    continue;
                }

                // Blank lines are skipped entirely: they are neither real logic
                // nor commentary, and they do not participate in the density
                // ratio (kept out of both numerator and denominator).
                if (trim($line) === '') {
                    continue;
                }

                if ($this->isCommentary($line)) {
                    $commentary++;

                    continue;
                }

                $behavioural++;
            }
        }

        $totalAddedLines = $behavioural + $commentary + $marker;
        // Density is the non-behavioural share of the scored surface and is
        // bounded to [0,1]: the denominator (behavioural+commentary+marker)
        // counts only scored lines, so the numerator must be drawn from the
        // same set (commentary+marker). Blank lines are excluded from both the
        // numerator and the denominator — they neither prove logic nor inflate
        // hollowness past 1.0. A wall of blank lines alone yields density 0.0.
        $numerator = $commentary + $marker;
        $scaffoldDensity = $totalAddedLines === 0
            ? 0.0
            : round($numerator / $totalAddedLines, 2);

        $fillerOnly = $behavioural === 0 && $totalAddedLines > 0;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'scaffold_density' => $scaffoldDensity,
            'real_logic_line_count' => $behavioural,
            'commentary_line_count' => $commentary,
            'marker_line_count' => $marker,
            'total_added_lines' => $totalAddedLines,
            'scanned_product_files' => $scannedProductFiles,
            'marker_hits' => $markerHits,
            'filler_only' => $fillerOnly,
            'band' => $this->band($scaffoldDensity, $fillerOnly),
        ];
    }

    /**
     * Test files are exempt: scaffolding, docblocks and test-doubles are
     * legitimate there. Mirrors FinalDeliveryQualityGateService's product-file
     * exclusion.
     */
    private function isProductFile(string $path): bool
    {
        $normalized = strtolower($path);

        return ! str_contains($normalized, '/tests/')
            && ! str_starts_with($normalized, 'tests/')
            && ! str_ends_with($path, 'Test.php');
    }

    private function markerLabel(string $line): ?string
    {
        foreach (self::MARKER_PATTERNS as $marker) {
            if (preg_match($marker['pattern'], $line) === 1) {
                return $marker['label'];
            }
        }

        return null;
    }

    private function isCommentary(string $line): bool
    {
        $trimmed = ltrim($line);

        foreach (self::COMMENTARY_PREFIXES as $prefix) {
            if (str_starts_with($trimmed, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function band(float $density, bool $fillerOnly): string
    {
        if ($fillerOnly || $density >= self::HOLLOW_THRESHOLD) {
            return self::BAND_HOLLOW;
        }

        if ($density >= self::THIN_THRESHOLD) {
            return self::BAND_THIN;
        }

        return self::BAND_CLEAN;
    }
}
