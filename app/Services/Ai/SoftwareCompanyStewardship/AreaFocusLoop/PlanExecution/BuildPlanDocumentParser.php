<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution;

/**
 * Software Company Stewardship Stack · Area Focus Loop · Plan Execution ·
 * Build Plan Document Parser (Pilar 1 / decomposer).
 *
 * Parses a canonical build-plan markdown document into the structured rows the
 * {@see BuildPlanDecomposerService} needs. It NEVER invents structure: it reads
 * only what the document literally states.
 *
 *   - Frontmatter `id` + `title` (between the leading `---` fences).
 *   - Section `## 6. Decomposicao em slices ordenados` markdown table
 *     (columns Slice | Entrega | Aceite | Guarda) into ordered slice rows.
 *   - Section `## 10` sequencing text `S1 -> S2 -> ...` into dependency edges.
 *
 * Pure: no filesystem, no provider, no mutation. Reading the file is the
 * caller's job; this parser receives raw markdown text.
 */
final class BuildPlanDocumentParser
{
    /** Heading prefix for the ordered-slice decomposition section. */
    private const SECTION_6_PREFIX = '## 6.';

    /** Heading prefix for the sequencing & dependencies section. */
    private const SECTION_10_PREFIX = '## 10.';

    /**
     * @return array{
     *   plan_id:string,
     *   plan_title:string,
     *   slices:list<array{label:string,delivery:string,acceptance_criteria:list<string>,authority_guard:string}>,
     *   duplicate_slice_labels:list<string>,
     *   section_6_found:bool,
     *   section_10_found:bool,
     *   dependency_edges:list<array{from:string,to:string}>
     * }
     */
    public function parse(string $markdown): array
    {
        $frontmatter = $this->parseFrontmatter($markdown);

        $section6 = $this->extractSection($markdown, self::SECTION_6_PREFIX);
        $section10 = $this->extractSection($markdown, self::SECTION_10_PREFIX);

        $parsed = $section6 !== null
            ? $this->parseSliceTable($section6)
            : ['slices' => [], 'duplicate_slice_labels' => []];
        $slices = $parsed['slices'];
        $duplicateLabels = $parsed['duplicate_slice_labels'];
        $edges = $section10 !== null ? $this->parseSequencingEdges($section10) : [];

        return [
            'plan_id' => $frontmatter['id'],
            'plan_title' => $frontmatter['title'],
            'slices' => $slices,
            'duplicate_slice_labels' => $duplicateLabels,
            'section_6_found' => $section6 !== null && $slices !== [],
            'section_10_found' => $section10 !== null && $edges !== [],
            'dependency_edges' => $edges,
        ];
    }

    /**
     * @return array{id:string,title:string}
     */
    private function parseFrontmatter(string $markdown): array
    {
        $id = '';
        $title = '';

        $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];
        if (($lines[0] ?? '') !== '---') {
            return ['id' => $id, 'title' => $title];
        }

        for ($i = 1, $count = count($lines); $i < $count; $i++) {
            $line = $lines[$i];
            if (trim($line) === '---') {
                break;
            }
            if (preg_match('/^id:\s*(.+)$/', $line, $m) === 1) {
                $id = trim($m[1]);
            } elseif (preg_match('/^title:\s*(.+)$/', $line, $m) === 1) {
                $title = trim($m[1]);
            }
        }

        return ['id' => $id, 'title' => $title];
    }

    /**
     * Return the raw lines of the named section (from the matching heading up to
     * the next `## ` heading), or null when the heading is absent.
     */
    private function extractSection(string $markdown, string $headingPrefix): ?string
    {
        $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];
        $collecting = false;
        $collected = [];
        $inFence = false;

        foreach ($lines as $line) {
            // Fenced code blocks may legitimately contain lines that begin with
            // `## ` (e.g. shell comments or markdown samples). Toggle on each
            // ``` fence so heading detection never truncates a section inside a
            // fence.
            if (str_starts_with(ltrim($line), '```')) {
                $inFence = ! $inFence;
                if ($collecting) {
                    $collected[] = $line;
                }

                continue;
            }
            if (! $inFence && str_starts_with($line, '## ')) {
                if ($collecting) {
                    break;
                }
                if (str_starts_with($line, $headingPrefix)) {
                    $collecting = true;
                }

                continue;
            }
            if ($collecting) {
                $collected[] = $line;
            }
        }

        return $collecting ? implode("\n", $collected) : null;
    }

    /**
     * Parse the Slice | Entrega | Aceite | Guarda markdown table into ordered
     * rows. The header row and `---` separator row are skipped. Acceptance is
     * split on bullet markers `;` / ` - ` so multiple criteria become a list.
     *
     * Duplicate slice labels are an AMBIGUOUS join key: the decomposer keys
     * sequencing edges by label, so two `S1` rows make dependency wiring
     * non-deterministic. We keep the FIRST occurrence (stable) and skip the
     * rest, recording each offending label so the decomposer can raise a
     * blocker. The skip strengthens dedup (I6); it never invents structure.
     *
     * @return array{slices:list<array{label:string,delivery:string,acceptance_criteria:list<string>,authority_guard:string}>,duplicate_slice_labels:list<string>}
     */
    private function parseSliceTable(string $section): array
    {
        $rows = [];
        $seen = [];
        $duplicates = [];
        foreach (preg_split('/\r\n|\r|\n/', $section) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || ! str_starts_with($trimmed, '|')) {
                continue;
            }

            $cells = $this->tableCells($trimmed);
            if (count($cells) < 4) {
                continue;
            }

            $label = $this->stripMarkdownEmphasis($cells[0]);
            // Skip header row and separator row.
            if (! preg_match('/^S\d+$/', $label)) {
                continue;
            }

            if (isset($seen[$label])) {
                $duplicates[$label] = true;

                continue;
            }
            $seen[$label] = true;

            $rows[] = [
                'label' => $label,
                'delivery' => $this->stripMarkdownEmphasis($cells[1]),
                'acceptance_criteria' => $this->splitCriteria($cells[2]),
                'authority_guard' => $this->stripMarkdownEmphasis($cells[3]),
            ];
        }

        $duplicateLabels = array_keys($duplicates);
        sort($duplicateLabels);

        return ['slices' => $rows, 'duplicate_slice_labels' => $duplicateLabels];
    }

    /**
     * @return list<string>
     */
    private function tableCells(string $line): array
    {
        $line = trim($line);
        $line = ltrim($line, '|');
        $line = rtrim($line, '|');

        // Split only on UNescaped pipes so a cell containing a literal `\|`
        // (e.g. `verde \| cobertura`) keeps column alignment, then unescape.
        $parts = preg_split('/(?<!\\\\)\|/', $line) ?: [$line];

        return array_map(
            fn (string $cell): string => trim(str_replace('\\|', '|', $cell)),
            $parts,
        );
    }

    /**
     * @return list<string>
     */
    private function splitCriteria(string $cell): array
    {
        $clean = $this->stripMarkdownEmphasis($cell);
        if ($clean === '') {
            return [];
        }

        // Acceptance cells list multiple gates separated by `;` or by the
        // documented ` - ` bullet (surrounded by whitespace so it never splits
        // a hyphenated word). Split can only INCREASE criteria, never satisfy
        // an empty Aceite (AFEF I1 evidence-bound gate preserved).
        $parts = preg_split('/\s*;\s*|\s+-\s+/', $clean) ?: [$clean];

        return array_values(array_filter(array_map(
            static fn (string $part): string => trim($part),
            $parts,
        ), static fn (string $part): bool => $part !== ''));
    }

    private function stripMarkdownEmphasis(string $value): string
    {
        $value = str_replace(['**', '`'], '', $value);

        return trim($value);
    }

    /**
     * Parse `Sx -> Sy` arrow tokens from the free-text sequencing section into
     * directed edges. Edges are derived ONLY from explicit `->` arrows: no
     * linear N-1 chain is ever synthesized. A run `Sa -> Sb -> Sc` yields edges
     * (a,b) and (b,c).
     *
     * @return list<array{from:string,to:string}>
     */
    private function parseSequencingEdges(string $section): array
    {
        $edges = [];
        $seen = [];

        // Parenthetical clauses are explanatory prose, not sequencing tokens,
        // and frequently mention OTHER slices ("(precisa de S3 diff + S4 ready)").
        // Strip them so the arrow chain reflects only the actual sequence path.
        $section = (string) preg_replace('/\([^()]*\)/', ' ', $section);

        // Walk every `->` arrow and bind the slice token immediately before it
        // to the slice token immediately after it. This captures overlapping
        // chains: `S1 -> S2 -> S3` yields (S1,S2) and (S2,S3). A `->` with no
        // slice token on one side (e.g. an "em paralelo S4" mention without an
        // arrow) contributes NO edge.
        $offset = 0;
        while (($arrowPos = strpos($section, '->', $offset)) !== false) {
            $before = substr($section, 0, $arrowPos);
            $afterFull = substr($section, $arrowPos + 2);
            // Bound the lookahead to the text before the NEXT arrow so a `to`
            // token never leaps across an intervening arrow.
            $nextArrow = strpos($afterFull, '->');
            $after = $nextArrow === false ? $afterFull : substr($afterFull, 0, $nextArrow);

            // `from` = nearest slice token before the arrow; `to` = nearest
            // slice token after it (a parenthetical clause such as
            // "(proposta antes de decisao)" may sit between a slice token and
            // its arrow). Both must exist for an edge to be emitted.
            $from = preg_match('/S(\d+)\D*$/s', $before, $bm) === 1 ? 'S'.$bm[1] : '';
            $to = preg_match('/^\D*S(\d+)/', $after, $am) === 1 ? 'S'.$am[1] : '';

            $offset = $arrowPos + 2;

            if ($from === '' || $to === '') {
                continue;
            }
            $key = $from.'>'.$to;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $edges[] = ['from' => $from, 'to' => $to];
        }

        return $edges;
    }
}
