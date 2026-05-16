<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Provider;

/**
 * Parses the Atlas Dev provider response into a DiffParseResult.
 *
 * The canonical output_contract accepts three positive shapes:
 *   - unified diff (raw or fenced ```diff ... ```);
 *   - "no_patch_needed: true" + reason: line;
 *   - "blocked: true" + question: line.
 *
 * Anything else is MODE_INVALID with descriptive errors so CompletionStateGate
 * can fail loudly instead of guessing.
 */
final class DiffParser
{
    private const DIFF_FENCE_PATTERN = '/```\s*(?:diff|patch|unified[-_]?diff)?\s*\n(?<body>.*?)\n```/si';

    private const FILE_LINE_PATTERN = '/^\+\+\+\s+(?:b\/)?(?P<path>[^\s\t\r\n]+)/m';

    private const FILE_LINE_OLD_PATTERN = '/^\-\-\-\s+(?:a\/)?(?P<path>[^\s\t\r\n]+)/m';

    public function parse(string $rawResponse): DiffParseResult
    {
        $normalized = $this->normalise($rawResponse);

        $blockedMatch = $this->extractBlocked($normalized);
        if ($blockedMatch !== null) {
            return DiffParseResult::blocked($blockedMatch);
        }

        $noPatchMatch = $this->extractNoPatchNeeded($normalized);
        if ($noPatchMatch !== null) {
            return DiffParseResult::noPatchNeeded($noPatchMatch);
        }

        $diff = $this->extractDiff($normalized);
        if ($diff === null) {
            return DiffParseResult::invalid([
                'no_unified_diff_detected',
                'no_no_patch_needed_marker',
                'no_blocked_marker',
            ]);
        }

        $changedFiles = $this->extractChangedFiles($diff);
        if ($changedFiles === []) {
            return DiffParseResult::invalid(['diff_present_but_no_changed_files_detected']);
        }

        return DiffParseResult::patch($diff, $changedFiles);
    }

    private function normalise(string $value): string
    {
        return str_replace(["\r\n", "\r"], "\n", $value);
    }

    private function extractBlocked(string $text): ?string
    {
        if (! preg_match('/^\s*(?:\*\*)?blocked(?:\*\*)?\s*[:=]\s*(?:\*\*)?true\b(?:\*\*)?/mi', $text)) {
            return null;
        }
        if (! preg_match('/^\s*(?:\*\*)?question(?:\*\*)?\s*[:=]\s*(?P<q>.+)$/mi', $text, $m)) {
            return null;
        }

        return $this->cleanMarkerValue($m['q']);
    }

    private function extractNoPatchNeeded(string $text): ?string
    {
        if (! preg_match('/^\s*(?:\*\*)?no_patch_needed(?:\*\*)?\s*[:=]\s*(?:\*\*)?true\b(?:\*\*)?/mi', $text)) {
            return null;
        }
        if (! preg_match('/^\s*(?:\*\*)?reason(?:\*\*)?\s*[:=]\s*(?P<r>.+)$/mi', $text, $m)) {
            return null;
        }

        return $this->cleanMarkerValue($m['r']);
    }

    private function extractDiff(string $text): ?string
    {
        if (preg_match(self::DIFF_FENCE_PATTERN, $text, $m)) {
            $body = trim($m['body']);
            if ($body !== '' && $this->looksLikeUnifiedDiff($body)) {
                return $body;
            }
        }

        if ($this->looksLikeUnifiedDiff($text)) {
            // Extract from first `--- ` line to end-of-text or next fence
            $offset = $this->firstDiffOffset($text);
            if ($offset !== null) {
                return rtrim(substr($text, $offset));
            }
        }

        return null;
    }

    private function looksLikeUnifiedDiff(string $body): bool
    {
        return preg_match('/^\-\-\-\s+(?:a\/)?\S+/m', $body) === 1
            && preg_match('/^\+\+\+\s+(?:b\/)?\S+/m', $body) === 1
            && preg_match('/^@@\s+-?\d+(?:,\d+)?\s+\+?\d+(?:,\d+)?\s+@@/m', $body) === 1;
    }

    private function firstDiffOffset(string $text): ?int
    {
        if (preg_match('/^(?:diff --git |---\s+(?:a\/)?\S+)/m', $text, $m, PREG_OFFSET_CAPTURE)) {
            return (int) $m[0][1];
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function extractChangedFiles(string $diff): array
    {
        $files = [];

        if (preg_match_all(self::FILE_LINE_PATTERN, $diff, $matches)) {
            foreach ($matches['path'] as $path) {
                $clean = $this->cleanPath($path);
                if ($clean !== null && $clean !== '/dev/null') {
                    $files[$clean] = true;
                }
            }
        }

        // For deletions the +++ line is /dev/null and the canonical path is on ---
        if (preg_match_all(self::FILE_LINE_OLD_PATTERN, $diff, $matches)) {
            foreach ($matches['path'] as $path) {
                $clean = $this->cleanPath($path);
                if ($clean !== null && $clean !== '/dev/null' && ! isset($files[$clean])) {
                    $files[$clean] = true;
                }
            }
        }

        return array_keys($files);
    }

    private function cleanPath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        return $path;
    }

    private function cleanMarkerValue(string $value): string
    {
        return trim(trim($value), "* \t\n\r\0\x0B");
    }
}
