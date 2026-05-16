<?php

declare(strict_types=1);

namespace Tests\Unit\Planning;

use PHPUnit\Framework\TestCase;

/**
 * Golden checker for docs/planning/inbox/feature.slices.md.
 *
 * The arm under test must produce a slices markdown that:
 *  - contains 3..5 slices
 *  - each slice declares id, title, depends_on, definition_of_done
 *  - no slice depends on itself
 *  - no dependency cycle
 *  - every acceptance criterion id from feature.md is covered by at
 *    least one slice's definition_of_done.
 *
 * The arm may extend `parseSlicesMarkdown()` (or wire a different parser)
 * as long as the public contract — array<int,Slice> — stays stable.
 */
final class IncrementalSlicesTest extends TestCase
{
    private const FEATURE_PATH = __DIR__.'/../../../docs/planning/inbox/feature.md';

    private const SLICES_PATH = __DIR__.'/../../../docs/planning/inbox/feature.slices.md';

    public function test_slices_file_exists(): void
    {
        $this->assertFileExists(self::SLICES_PATH, 'feature.slices.md was not produced');
    }

    public function test_slice_count_in_range(): void
    {
        $slices = $this->parseSlicesMarkdown((string) file_get_contents(self::SLICES_PATH));
        $this->assertGreaterThanOrEqual(3, count($slices));
        $this->assertLessThanOrEqual(5, count($slices));
    }

    public function test_each_slice_has_required_fields(): void
    {
        $slices = $this->parseSlicesMarkdown((string) file_get_contents(self::SLICES_PATH));
        foreach ($slices as $slice) {
            $this->assertArrayHasKey('id', $slice);
            $this->assertArrayHasKey('title', $slice);
            $this->assertArrayHasKey('depends_on', $slice);
            $this->assertArrayHasKey('definition_of_done', $slice);
            $this->assertNotEmpty($slice['definition_of_done'], 'definition_of_done cannot be empty');
        }
    }

    public function test_no_self_dependency(): void
    {
        $slices = $this->parseSlicesMarkdown((string) file_get_contents(self::SLICES_PATH));
        foreach ($slices as $slice) {
            $this->assertNotContains($slice['id'], $slice['depends_on'], "slice {$slice['id']} depends on itself");
        }
    }

    public function test_no_dependency_cycle(): void
    {
        $slices = $this->parseSlicesMarkdown((string) file_get_contents(self::SLICES_PATH));
        $graph = [];
        foreach ($slices as $slice) {
            $graph[$slice['id']] = $slice['depends_on'];
        }
        $this->assertFalse($this->graphHasCycle($graph), 'dependency graph has a cycle');
    }

    public function test_covers_every_acceptance_criterion(): void
    {
        $acceptanceIds = $this->extractAcceptanceCriterionIds((string) file_get_contents(self::FEATURE_PATH));
        $this->assertNotEmpty($acceptanceIds, 'feature.md must declare acceptance criteria');

        $slices = $this->parseSlicesMarkdown((string) file_get_contents(self::SLICES_PATH));
        $covered = [];
        foreach ($slices as $slice) {
            foreach ($slice['definition_of_done'] as $bullet) {
                foreach ($acceptanceIds as $id) {
                    if (str_contains($bullet, $id)) {
                        $covered[$id] = true;
                    }
                }
            }
        }
        $missing = array_values(array_diff($acceptanceIds, array_keys($covered)));
        $this->assertSame([], $missing, 'acceptance ids not covered by any slice: '.implode(',', $missing));
    }

    /** @return list<array{id:string,title:string,depends_on:list<string>,definition_of_done:list<string>}> */
    private function parseSlicesMarkdown(string $markdown): array
    {
        $slices = [];
        $current = null;
        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            if (preg_match('/^##\s+(slice-\S+)\s*[—:-]?\s*(.*)$/', $line, $m)) {
                if ($current !== null) {
                    $slices[] = $current;
                }
                $current = [
                    'id' => $m[1],
                    'title' => trim($m[2]),
                    'depends_on' => [],
                    'definition_of_done' => [],
                ];

                continue;
            }
            if ($current === null) {
                continue;
            }
            if (preg_match('/^- depends_on:\s*\[(.*?)\]\s*$/', $line, $m)) {
                $list = array_map('trim', array_filter(explode(',', $m[1]), static fn ($v) => trim((string) $v) !== ''));
                $current['depends_on'] = array_values($list);

                continue;
            }
            if (preg_match('/^- dod:\s*(.+)$/i', $line, $m)) {
                $current['definition_of_done'][] = trim($m[1]);
            }
        }
        if ($current !== null) {
            $slices[] = $current;
        }

        return $slices;
    }

    /** @return list<string> e.g. ["A1","A2",...] */
    private function extractAcceptanceCriterionIds(string $featureMarkdown): array
    {
        $ids = [];
        if (preg_match('/##\s+Acceptance criteria(.*?)(##\s|\z)/si', $featureMarkdown, $m)) {
            foreach (preg_split('/\R/', $m[1]) ?: [] as $line) {
                if (preg_match('/^\s*(\d+)\.\s+/', $line, $mm)) {
                    $ids[] = 'A'.$mm[1];
                }
            }
        }

        return $ids;
    }

    /** @param array<string,list<string>> $graph */
    private function graphHasCycle(array $graph): bool
    {
        $state = [];
        foreach (array_keys($graph) as $node) {
            if (! isset($state[$node]) && $this->dfsCycle($node, $graph, $state)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,list<string>> $graph @param array<string,int> $state */
    private function dfsCycle(string $node, array $graph, array &$state): bool
    {
        $state[$node] = 1;
        foreach ($graph[$node] ?? [] as $next) {
            if (! isset($state[$next])) {
                if ($this->dfsCycle($next, $graph, $state)) {
                    return true;
                }
            } elseif ($state[$next] === 1) {
                return true;
            }
        }
        $state[$node] = 2;

        return false;
    }
}
