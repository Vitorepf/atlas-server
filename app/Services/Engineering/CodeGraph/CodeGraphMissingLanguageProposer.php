<?php

namespace App\Services\Engineering\CodeGraph;


/**
 * Self-construction proposer: surfaces the languages/extensions the code-graph
 * cannot yet extract, ranked by how much of the codebase they lock out (AP-811/AP-812 M-7).
 *
 * This is Atlas proposing to build its OWN missing extractor. The code-graph
 * extractors cover a fixed set of file extensions ("covered"); every other
 * extension present in the workspace is a blind spot — files that exist but never
 * become nodes/edges. Ranking those blind spots by file_count tells the operator
 * which extractor to build next for the largest coverage gain.
 *
 * Strictly proposal-only and pure: no DB, no IO, no provider, no Python runtime,
 * no self-write. It returns a ranked recommendation list and decides nothing —
 * it never builds, registers, enables, or routes anything. Promotion (turning a
 * proposal into an actual extractor) stays a separate, human-reviewed step. The
 * proposer FEEDS the decision; it is not a parallel decision brain.
 *
 * @phpstan-type LanguageProposal array{extension:string, file_count:int, proposal:string, priority:int}
 */
class CodeGraphMissingLanguageProposer
{
    public const SCHEMA = 'atlas.code_graph.missing_language.v1';

    /** The only proposal kind this slice emits: build an extractor for the extension. */
    public const PROPOSAL_ADD_EXTRACTOR = 'add_extractor';

    /**
     * Rank the uncovered extensions by file_count (desc), tie-broken by extension
     * (asc) for determinism, and assign a 1-based priority following that order.
     * Covered extensions and non-positive / malformed counts are omitted.
     *
     * @param  array<int|string,mixed>  $extensionCounts  extension => file count for every
     *   extension seen in the workspace, e.g. ['php' => 9000, 'py' => 120, 'rs' => 40].
     *   Keys are normalized (lowercased, leading dots stripped); counts must be positive ints.
     * @param  array<int,mixed>  $coveredExtensions  extensions an extractor already exists for,
     *   e.g. ['php', 'py']. Normalized the same way before comparison.
     * @return array{schema_version:string, proposals:array<int,LanguageProposal>, stats:array<string,int>}
     */
    public function propose(array $extensionCounts, array $coveredExtensions): array
    {
        $covered = $this->coveredSet($coveredExtensions);

        $stats = [
            'extensions_seen' => 0,
            'covered' => 0,
            'uncovered' => 0,
            'skipped_invalid' => 0,
        ];

        // Merge counts for extensions that normalize to the same key (e.g. "PHP"
        // and ".php") so a single extension is never proposed twice.
        /** @var array<string,int> $merged extension => summed file count */
        $merged = [];
        foreach ($extensionCounts as $rawExtension => $rawCount) {
            $extension = $this->normalizeExtension($rawExtension);
            $count = $this->positiveInt($rawCount);
            if ($extension === null || $count === null) {
                $stats['skipped_invalid']++;

                continue;
            }
            $stats['extensions_seen']++;
            $merged[$extension] = ($merged[$extension] ?? 0) + $count;
        }

        $uncovered = [];
        foreach ($merged as $extension => $count) {
            if (isset($covered[$extension])) {
                $stats['covered']++;

                continue;
            }
            $uncovered[] = ['extension' => $extension, 'file_count' => $count];
        }
        $stats['uncovered'] = count($uncovered);

        // Most-files-first; extension name asc breaks ties deterministically.
        usort(
            $uncovered,
            static fn (array $a, array $b): int => $b['file_count'] <=> $a['file_count']
                ?: strcmp($a['extension'], $b['extension']),
        );

        $proposals = [];
        foreach ($uncovered as $index => $row) {
            $proposals[] = [
                'extension' => $row['extension'],
                'file_count' => $row['file_count'],
                'proposal' => self::PROPOSAL_ADD_EXTRACTOR,
                'priority' => $index + 1,
            ];
        }

        return [
            'schema_version' => self::SCHEMA,
            'proposals' => $proposals,
            'stats' => $stats,
        ];
    }

    /**
     * @param  array<int,mixed>  $coveredExtensions
     * @return array<string,true>
     */
    private function coveredSet(array $coveredExtensions): array
    {
        $set = [];
        foreach ($coveredExtensions as $raw) {
            $extension = $this->normalizeExtension($raw);
            if ($extension !== null) {
                $set[$extension] = true;
            }
        }

        return $set;
    }

    /**
     * Normalize an extension key/value: must be a string or int-like, lowercased,
     * with surrounding whitespace, a single leading dot, and "*" glob prefixes
     * ("*.py", ".py") stripped to a bare token ("py"). Returns null when empty.
     */
    private function normalizeExtension(mixed $value): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (! is_string($value)) {
            return null;
        }
        $trimmed = strtolower(trim($value));
        $trimmed = ltrim($trimmed, '*');
        $trimmed = ltrim($trimmed, '.');
        $trimmed = trim($trimmed);

        return $trimmed === '' ? null : $trimmed;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && ctype_digit(trim($value))) {
            $int = (int) trim($value);

            return $int > 0 ? $int : null;
        }

        return null;
    }
}
