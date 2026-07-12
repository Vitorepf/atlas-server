<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

/**
 * MAXC-01 — Pure deterministic extractor: monolithic task string → K typed facets.
 *
 * Zero deps: regex + explode. No provider calls, no DB, no service resolution.
 * Facets are typed sub-queries the packFor caller can route to distinct sources
 * (memory, code, evidence) with `record_usage=false` (peek) — the delivered pack
 * still records usage exactly ONCE at delivery (invariant preserved).
 *
 * Facet types:
 *   - symbol  : PascalCase / FQCN / ClassName::method / ClassName->method
 *   - path    : posix paths with '/', or files with recognized extensions
 *   - command : `atlas:*` artisan-like commands (colon-separated ids)
 *   - phrase  : quoted phrases (double or single quotes)
 *   - term    : residual tokens after conjunction/punctuation split
 */
final class TaskFacetExtractor
{
    public const FACET_SYMBOL = 'symbol';
    public const FACET_PATH = 'path';
    public const FACET_COMMAND = 'command';
    public const FACET_PHRASE = 'phrase';
    public const FACET_TERM = 'term';

    /** Recognized file extensions for path detection (whitelist to avoid abbreviations). */
    private const PATH_EXTENSIONS = [
        'php', 'js', 'jsx', 'ts', 'tsx', 'json', 'md', 'yml', 'yaml',
        'sh', 'zsh', 'py', 'rs', 'go', 'html', 'css', 'sql', 'jsonl',
        'lock', 'toml', 'ini', 'conf', 'env', 'xml',
    ];

    /**
     * @return array{
     *   facets: array<int,array{type:string,value:string,essential:bool}>,
     *   raw_task: string,
     *   counts: array<string,int>
     * }
     */
    public function extract(string $task): array
    {
        $raw = trim($task);
        if ($raw === '') {
            return [
                'facets' => [],
                'raw_task' => '',
                'counts' => [],
            ];
        }

        $work = $raw;
        $facets = [];
        $seen = [];

        $push = static function (string $type, string $value, bool $essential) use (&$facets, &$seen): void {
            $value = trim($value);
            if ($value === '') {
                return;
            }
            $key = $type.'|'.mb_strtolower($value);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $facets[] = ['type' => $type, 'value' => $value, 'essential' => $essential];
        };

        // 1) Quoted phrases — extract and mask so subsequent regexes don't re-match.
        if (preg_match_all('/(?<![\\w\\/])(["\'])([^"\'\\r\\n]{2,120})\\1/u', $work, $m)) {
            foreach ($m[2] as $phrase) {
                $push(self::FACET_PHRASE, $phrase, true);
            }
            $work = preg_replace('/(?<![\\w\\/])(["\'])([^"\'\\r\\n]{2,120})\\1/u', ' ', $work) ?? $work;
        }

        // 2) `atlas:*` (or generic verb:noun[:sub]) commands.
        if (preg_match_all('/\\b([a-z][a-z0-9]{1,30}(?::[a-z0-9\\-]{1,40}){1,3})\\b/', $work, $m)) {
            foreach ($m[1] as $cmd) {
                $push(self::FACET_COMMAND, $cmd, true);
            }
        }

        // 3) FQCN + Class::method + Class->method + PascalCase.
        //    3a) Full-qualified class name (App\Services\...).
        if (preg_match_all('/\\b([A-Z][A-Za-z0-9_]*(?:\\\\[A-Z][A-Za-z0-9_]*){1,10})(?:::([a-zA-Z_][A-Za-z0-9_]*))?\\b/', $work, $m)) {
            foreach ($m[1] as $i => $fqcn) {
                $method = (string) ($m[2][$i] ?? '');
                $value = $method !== '' ? $fqcn.'::'.$method : $fqcn;
                $push(self::FACET_SYMBOL, $value, true);
            }
        }
        //    3b) Bare ClassName::method or ClassName->method (single class name).
        if (preg_match_all('/\\b([A-Z][A-Za-z0-9_]{2,})(?:(::|->)([a-zA-Z_][A-Za-z0-9_]*))?\\b/', $work, $m)) {
            foreach ($m[1] as $i => $name) {
                $sep = (string) ($m[2][$i] ?? '');
                $method = (string) ($m[3][$i] ?? '');
                $value = $method !== '' ? $name.$sep.$method : $name;
                $push(self::FACET_SYMBOL, $value, $method !== '');
            }
        }

        // 4) Paths — posix-like with '/' AND recognized file extension, or with a
        //    slash + at least one path separator segment.
        if (preg_match_all('/\\b([\\w\\-.]+\\/[\\w\\-.\\/]{1,200})/u', $work, $m)) {
            foreach ($m[1] as $path) {
                $push(self::FACET_PATH, $path, true);
            }
        }
        // 4b) Files with a recognized extension without a path separator.
        $extAlt = implode('|', self::PATH_EXTENSIONS);
        if (preg_match_all('/\\b([\\w\\-.]{2,120}\\.(?:'.$extAlt.'))\\b/u', $work, $m)) {
            foreach ($m[1] as $file) {
                $push(self::FACET_PATH, $file, true);
            }
        }

        // 5) Residual terms after splitting on conjunctions and punctuation.
        //    Strip already-captured tokens by masking the accumulated facets.
        $residual = $work;
        foreach ($facets as $facet) {
            $residual = str_replace($facet['value'], ' ', $residual);
        }
        $splits = preg_split('/[\\s,;:!?\\.]+|\\b(?:and|or|e|ou|vs|then|com|para|de|do|da)\\b/iu', $residual) ?: [];
        foreach ($splits as $token) {
            $token = trim($token, " \t\n\r\0\x0B-_'\"()[]{}");
            if (mb_strlen($token) < 3) {
                continue;
            }
            if (mb_strtolower($token) !== $token && mb_strtoupper($token) !== $token) {
                // Mixed-case single word — skip, likely already captured as symbol.
                continue;
            }
            $push(self::FACET_TERM, mb_strtolower($token), false);
        }

        $counts = [];
        foreach ($facets as $facet) {
            $counts[$facet['type']] = ($counts[$facet['type']] ?? 0) + 1;
        }

        return [
            'facets' => $facets,
            'raw_task' => $raw,
            'counts' => $counts,
        ];
    }
}
