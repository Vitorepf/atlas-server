<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

final class AtlasCortexIntentExtractor
{
    public function __construct(
        private readonly ?string $docsRoot = null,
    ) {
    }

    public function extract(string $phpFilePath): IntentExtractionFact
    {
        try {
            $bytes = is_file($phpFilePath) ? (string) file_get_contents($phpFilePath) : '';
        } catch (Throwable) {
            return new IntentExtractionFact('', null, null, null, null, IntentExtractionFact::CONFIDENCE_LOW);
        }

        $fqcn = $this->fqcn($bytes);
        $docblock = $this->classDocblock($bytes);
        $purpose = $this->tagValue($docblock, 'purpose');
        $design = $this->tagValue($docblock, 'design');
        if ($purpose === null && $design === null) {
            $purpose = $this->firstParagraphAfterNamespace($bytes);
        }

        try {
            $doc = $this->docMention($fqcn, $phpFilePath);
        } catch (Throwable) {
            $doc = ['path' => null, 'excerpt' => null];
        }

        return new IntentExtractionFact(
            fqcn: $fqcn,
            docblockPurpose: $purpose,
            docblockDesign: $design,
            docPath: $doc['path'],
            docExcerpt: $doc['excerpt'],
            confidence: $this->confidence($purpose, $design, $doc['path']),
        );
    }

    /**
     * @return list<IntentExtractionFact>
     */
    public function extractAll(string $phpRoot): array
    {
        if (! is_dir($phpRoot)) {
            return [];
        }

        $paths = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($phpRoot));
        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $paths[] = $file->getPathname();
        }
        sort($paths, SORT_STRING);

        return array_map(fn (string $path): IntentExtractionFact => $this->extract($path), $paths);
    }

    private function fqcn(string $bytes): string
    {
        preg_match('/^\\s*namespace\\s+([^;]+);/m', $bytes, $namespaceMatch);
        preg_match('/(?:^|\\n)\\s*(?:final\\s+|abstract\\s+|readonly\\s+)*(?:class|interface|trait|enum)\\s+([A-Za-z_][A-Za-z0-9_]*)/m', $bytes, $classMatch);
        $namespace = trim((string) ($namespaceMatch[1] ?? ''));
        $class = trim((string) ($classMatch[1] ?? ''));
        if ($class === '') {
            return $namespace;
        }

        return ltrim($namespace.'\\'.$class, '\\');
    }

    private function classDocblock(string $bytes): ?string
    {
        if (preg_match('/(?P<doc>\\/\\*\\*.*?\\*\\/)\\s*(?:final\\s+|abstract\\s+|readonly\\s+)*(?:class|interface|trait|enum)\\s+[A-Za-z_][A-Za-z0-9_]*/s', $bytes, $match) !== 1) {
            return null;
        }

        return (string) $match['doc'];
    }

    private function tagValue(?string $docblock, string $tag): ?string
    {
        if ($docblock === null) {
            return null;
        }

        $pattern = '/@'.preg_quote($tag, '/').'\\s+([^\\r\\n]+)/';
        if (preg_match($pattern, $docblock, $match) !== 1) {
            return null;
        }

        return $this->cleanText((string) $match[1]);
    }

    private function firstParagraphAfterNamespace(string $bytes): ?string
    {
        $offset = 0;
        if (preg_match('/^\\s*namespace\\s+[^;]+;\\s*/m', $bytes, $match, PREG_OFFSET_CAPTURE) === 1) {
            $offset = (int) $match[0][1] + strlen((string) $match[0][0]);
        }
        $tail = substr($bytes, $offset);
        $tail = preg_replace('/^\\s*(?:use\\s+[^;]+;\\s*)+/m', '', $tail) ?? $tail;
        $paragraphs = preg_split('/\\R\\s*\\R/', trim($tail)) ?: [];
        foreach ($paragraphs as $paragraph) {
            $clean = $this->cleanText((string) $paragraph);
            if ($clean !== ''
                && ! str_starts_with($clean, '/**')
                && ! str_starts_with($clean, '<?php')
                && ! preg_match('/^(?:final\\s+|abstract\\s+|readonly\\s+)*(?:class|interface|trait|enum)\\s+/i', $clean)
            ) {
                return $clean;
            }
        }

        return null;
    }

    /**
     * @return array{path:?string, excerpt:?string}
     */
    private function docMention(string $fqcn, string $phpFilePath): array
    {
        $docsRoot = $this->docsRoot ?? $this->defaultDocsRoot();
        if (! is_dir($docsRoot)) {
            return ['path' => null, 'excerpt' => null];
        }

        $shortName = str_contains($fqcn, '\\') ? substr($fqcn, (int) strrpos($fqcn, '\\') + 1) : pathinfo($phpFilePath, PATHINFO_FILENAME);
        $needles = array_values(array_filter([$fqcn, $shortName], static fn (string $needle): bool => $needle !== ''));
        if ($needles === []) {
            return ['path' => null, 'excerpt' => null];
        }

        $paths = $this->markdownPaths($docsRoot);
        foreach ($paths as $path) {
            $bytes = (string) file_get_contents($path);
            foreach ($needles as $needle) {
                $pos = strpos($bytes, $needle);
                if ($pos === false) {
                    continue;
                }

                return [
                    'path' => $this->relativePath($path),
                    'excerpt' => $this->excerpt($bytes, $pos),
                ];
            }
        }

        return ['path' => null, 'excerpt' => null];
    }

    /**
     * @return list<string>
     */
    private function markdownPaths(string $root): array
    {
        $paths = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || strtolower($file->getExtension()) !== 'md') {
                continue;
            }
            $paths[] = $file->getPathname();
        }

        usort($paths, static function (string $left, string $right): int {
            $leftPreferred = str_contains($left, 'engineering-knowledge-base') ? 0 : 1;
            $rightPreferred = str_contains($right, 'engineering-knowledge-base') ? 0 : 1;

            return [$leftPreferred, $left] <=> [$rightPreferred, $right];
        });

        return $paths;
    }

    private function excerpt(string $bytes, int $pos): string
    {
        $start = max(0, $pos - 60);
        $excerpt = substr($bytes, $start, 200);

        return $this->cleanText($excerpt);
    }

    private function confidence(?string $purpose, ?string $design, ?string $docPath): string
    {
        if ($purpose !== null || $design !== null) {
            return IntentExtractionFact::CONFIDENCE_HIGH;
        }
        if ($docPath !== null) {
            return IntentExtractionFact::CONFIDENCE_MEDIUM;
        }

        return IntentExtractionFact::CONFIDENCE_LOW;
    }

    private function defaultDocsRoot(): string
    {
        try {
            return function_exists('base_path') ? base_path('docs') : getcwd().'/docs';
        } catch (Throwable) {
            return getcwd().'/docs';
        }
    }

    private function relativePath(string $path): string
    {
        $docsRoot = $this->docsRoot !== null ? rtrim($this->docsRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR : null;
        if ($docsRoot !== null && str_starts_with($path, $docsRoot)) {
            return substr($path, strlen($docsRoot));
        }

        try {
            $base = function_exists('base_path') ? rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR : getcwd().DIRECTORY_SEPARATOR;
        } catch (Throwable) {
            $base = getcwd().DIRECTORY_SEPARATOR;
        }

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    private function cleanText(string $text): string
    {
        $text = preg_replace('/^\\s*\\*\\s?/m', '', $text) ?? $text;
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/\\s+/u', ' ', $text) ?? $text;

        return trim($text, " \t\n\r\0\x0B*/");
    }
}
