<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * HARD LAW (operator mandate, 2026-05-29): an autonomous-loop cycle may NEVER
 * land its final block as anything that is not 100% final.
 *
 * Shipping scaffold/mock/"Step 1 of 3" as a completed merge is a critical defect
 * for an unattended loop: it compounds — a month of running would accrete a
 * mountain of self-declared-incomplete blocks all counted as "done", while no
 * promised behavior was actually delivered (progress theater).
 *
 * This gate scans the PRODUCT files a cycle is about to merge and BLOCKS the
 * merge when the code declares itself incomplete (scaffold / shape-only /
 * "Step N of M" / future work / TODO / not implemented / placeholder) or ships
 * test-doubles (Mockery / shouldReceive / createMock) in production code.
 *
 * It is intentionally evidence-based and deterministic: it keys off explicit,
 * self-incriminating markers the producing provider wrote, not heuristics about
 * "is this wired". Test files are exempt (mocks/stubs are legitimate there).
 *
 * A blocked cycle is NOT a completable attempt — the finding is retried until it
 * is delivered 100% final, or review-locked after the bounded attempt cap.
 */
final class FinalDeliveryQualityGateService
{
    public const BLOCKER = 'delivery_not_final_scaffold_or_mock';

    /**
     * Self-declared incompleteness markers. A final delivery must contain NONE
     * of these in its product code.
     *
     * @var list<array{pattern:string,label:string}>
     */
    private const NON_FINAL_MARKERS = [
        ['pattern' => '/step\s+\d+\s+of\s+\d+/i', 'label' => 'step_n_of_m'],
        ['pattern' => '/\bshape\s+only\b/i', 'label' => 'shape_only'],
        ['pattern' => '/\bfuture\s+(step|steps|work|wiring)\b/i', 'label' => 'future_work'],
        ['pattern' => '/wiring\s+is\s+a\s+future/i', 'label' => 'wiring_is_future'],
        ['pattern' => '/\bremaining\s+(rule|rules|route|routes|step|steps)\b/i', 'label' => 'remaining_work'],
        ['pattern' => '/\bfirst\s+rule\s+only\b/i', 'label' => 'first_rule_only'],
        ['pattern' => '/\bnot\s+(yet\s+)?implemented\b/i', 'label' => 'not_implemented'],
        ['pattern' => '/\bunimplemented\b/i', 'label' => 'unimplemented'],
        ['pattern' => '/\bplaceholder\b/i', 'label' => 'placeholder'],
        ['pattern' => '/\bTODO\b/', 'label' => 'todo'],
        ['pattern' => '/\bFIXME\b/', 'label' => 'fixme'],
        ['pattern' => '/\bXXX\b/', 'label' => 'xxx'],
        // Test-doubles must never ship in production code.
        ['pattern' => '/\bMockery\b/', 'label' => 'mock_in_product'],
        ['pattern' => '/->shouldReceive\s*\(/', 'label' => 'mock_in_product'],
        ['pattern' => '/\bcreateMock\s*\(/', 'label' => 'mock_in_product'],
        ['pattern' => '/\bgetMockBuilder\s*\(/', 'label' => 'mock_in_product'],
        ['pattern' => '/::mock\s*\(/', 'label' => 'mock_in_product'],
    ];

    /**
     * Assess a set of changed files about to be merged as a final delivery.
     * When baseline contents are supplied, only newly introduced markers block;
     * pre-existing debt in a touched file must not create a false blocked cycle.
     *
     * @param  array<string,string>  $files  path => file contents
     * @param  array<string,string>  $baselineFiles  path => file contents before this cycle
     * @return array{final:bool,blocker:?string,violations:list<array{file:string,marker:string,excerpt:string}>,scanned_product_files:int}
     */
    public function assess(array $files, array $baselineFiles = []): array
    {
        $violations = [];
        $scanned = 0;

        foreach ($files as $path => $contents) {
            if (! $this->isProductPhpFile((string) $path)) {
                continue;
            }
            $scanned++;
            foreach (self::NON_FINAL_MARKERS as $marker) {
                $match = $this->firstNewMarkerMatch(
                    (string) $contents,
                    (string) ($baselineFiles[(string) $path] ?? ''),
                    $marker['pattern'],
                );
                if ($match !== null) {
                    $violations[] = [
                        'file' => (string) $path,
                        'marker' => $marker['label'],
                        'excerpt' => $this->excerpt((string) $contents, $match),
                    ];
                    break; // one violation per file is enough to block
                }
            }
        }

        return [
            'final' => $violations === [],
            'blocker' => $violations === [] ? null : self::BLOCKER,
            'violations' => $violations,
            'scanned_product_files' => $scanned,
        ];
    }

    /**
     * A human/operator-facing reason string for the cycle blocker detail.
     *
     * @param  list<array{file:string,marker:string,excerpt:string}>  $violations
     */
    public function reason(array $violations): string
    {
        if ($violations === []) {
            return '';
        }
        $parts = array_map(
            static fn (array $v): string => basename($v['file']).' ('.$v['marker'].')',
            array_slice($violations, 0, 5),
        );

        return 'Final-delivery law: the merge candidate declares itself non-final or ships test-doubles in product code — '
            .implode(', ', $parts)
            .'. A loop cycle must deliver 100% final, wired code; scaffold/shape-only/mock is rejected (no merge) and the finding is retried until complete.';
    }

    private function isProductPhpFile(string $path): bool
    {
        $p = strtolower($path);
        if (! str_ends_with($p, '.php')) {
            return false;
        }
        if (str_contains($p, '/tests/') || str_starts_with($p, 'tests/') || str_ends_with($p, 'test.php')) {
            return false;
        }

        return true;
    }

    private function firstNewMarkerMatch(string $contents, string $baseline, string $pattern): ?string
    {
        $matchCount = preg_match_all($pattern, $contents, $matches);
        if ($matchCount === false || $matchCount === 0) {
            return null;
        }

        $baselineCounts = [];
        $baselineMatchCount = $baseline !== '' ? preg_match_all($pattern, $baseline, $baselineMatches) : 0;
        if ($baselineMatchCount !== false && $baselineMatchCount > 0) {
            foreach ($baselineMatches[0] as $match) {
                $key = $this->markerKey((string) $match);
                $baselineCounts[$key] = ($baselineCounts[$key] ?? 0) + 1;
            }
        }

        foreach ($matches[0] as $match) {
            $match = (string) $match;
            $key = $this->markerKey($match);
            if (($baselineCounts[$key] ?? 0) > 0) {
                $baselineCounts[$key]--;

                continue;
            }

            return $match;
        }

        return null;
    }

    private function markerKey(string $match): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $match) ?? $match));
    }

    private function excerpt(string $contents, string $match): string
    {
        $pos = mb_stripos($contents, $match);
        if ($pos === false) {
            return mb_substr(trim($match), 0, 120);
        }
        $start = max(0, $pos - 30);

        return trim(mb_substr($contents, $start, 120));
    }
}
