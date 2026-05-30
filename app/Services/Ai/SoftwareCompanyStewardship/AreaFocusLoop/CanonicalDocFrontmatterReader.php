<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * Software Company Stewardship Stack · Area Focus Loop ·
 * Canonical Doc Frontmatter Reader (read-only collaborator).
 *
 * Read-only filesystem collaborator for the Canonical Doc Backlog Miner. It
 * discovers canonical engineering-knowledge-base docs and extracts the REAL
 * directive lines already written in their YAML frontmatter
 * (`next_actions` / `allowed_changes` / `forbidden_changes`) with 1-based line
 * numbers, so the miner can map each finding 1:1 onto a verbatim source line.
 *
 * Hard invariants:
 *   - NO state, NO provider, NO write. Pure read of `.md` files.
 *   - If a doc cannot be read or has no recognised block, it emits nothing —
 *     never a placeholder, never inferred work.
 *   - Line numbers are 1-based over explode("\n", $contents) (index + 1) so a
 *     downstream test can re-read the file and assert the captured text.
 */
class CanonicalDocFrontmatterReader
{
    /** YAML frontmatter list-keys the miner mines from. */
    private const DIRECTIVE_KEYS = [
        'next_actions' => 'next_action',
        'allowed_changes' => 'allowed_change',
        'forbidden_changes' => 'forbidden_change',
    ];

    /**
     * Discover canonical docs under a docs root (sorted, capped).
     *
     * @return list<string> absolute file paths
     */
    public function discoverDocs(string $docsRoot, int $maxDocs = 500): array
    {
        $root = rtrim($docsRoot, '/');
        if ($root === '' || ! is_dir($root)) {
            return [];
        }

        $found = glob($root.'/*.md');
        if ($found === false) {
            return [];
        }
        sort($found);

        if ($maxDocs > 0 && count($found) > $maxDocs) {
            $found = array_slice($found, 0, $maxDocs);
        }

        return array_values($found);
    }

    /**
     * Extract directive list-items from a doc's YAML frontmatter.
     *
     * @return list<array{path:string,line:int,text:string,directive_kind:'next_action'|'allowed_change'|'forbidden_change'}>
     */
    public function extractDirectives(string $path): array
    {
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            return [];
        }
        $contents = @file_get_contents($path);
        if ($contents === false || $contents === '') {
            return [];
        }

        $lines = explode("\n", $contents);

        // Frontmatter is the block delimited by the first two `---` lines.
        $frontmatterStart = null;
        $frontmatterEnd = null;
        foreach ($lines as $i => $raw) {
            if (rtrim($raw) === '---') {
                if ($frontmatterStart === null) {
                    $frontmatterStart = $i;

                    continue;
                }
                $frontmatterEnd = $i;
                break;
            }
        }
        if ($frontmatterStart === null || $frontmatterEnd === null) {
            return [];
        }

        $directives = [];
        $activeKind = null;
        for ($i = $frontmatterStart + 1; $i < $frontmatterEnd; $i++) {
            $raw = $lines[$i];
            $trimmed = ltrim($raw);

            // A new top-level key (no leading whitespace, contains ':') closes
            // any active block. Recognised directive keys open a new block.
            if ($raw !== '' && $raw[0] !== ' ' && $raw[0] !== "\t" && str_contains($raw, ':')) {
                $key = trim(substr($raw, 0, (int) strpos($raw, ':')));
                $activeKind = self::DIRECTIVE_KEYS[$key] ?? null;

                continue;
            }

            if ($activeKind === null) {
                continue;
            }

            // Inside an active block: only `- item` list entries count.
            if (! str_starts_with($trimmed, '- ')) {
                continue;
            }
            $text = $this->normalizeItem(substr($trimmed, 2));
            if ($text === '') {
                continue;
            }

            $directives[] = [
                'path' => $path,
                'line' => $i + 1, // 1-based
                'text' => $text,
                'directive_kind' => $activeKind,
            ];
        }

        return $directives;
    }

    /**
     * Read the doc-level `risk_level` scalar from frontmatter, if present.
     * Returns '' when absent/unreadable (no inference).
     */
    public function extractRiskLevel(string $path): string
    {
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            return '';
        }
        $contents = @file_get_contents($path);
        if ($contents === false || $contents === '') {
            return '';
        }
        foreach (explode("\n", $contents) as $raw) {
            if (rtrim($raw) === '---') {
                continue;
            }
            if (preg_match('/^risk_level:\s*(.+?)\s*$/', $raw, $m) === 1) {
                return strtolower(trim($m[1], "\"' "));
            }
        }

        return '';
    }

    /** Strip surrounding YAML quotes and trim, preserving the verbatim item text. */
    private function normalizeItem(string $item): string
    {
        $item = trim($item);
        if (strlen($item) >= 2) {
            $first = $item[0];
            $last = $item[strlen($item) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $item = substr($item, 1, -1);
            }
        }

        return trim($item);
    }
}
