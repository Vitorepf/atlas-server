<?php

namespace App\Services\Ai\VentureFoundry\Comprehension;

/**
 * Safe, read-only, scoped reader over a venture's source workspace.
 *
 * This is THE shared primitive of the comprehension subsystem: every
 * capability (business rules, problems, improvements, audience, docs) reads
 * the real repository only through this class, so file access is uniformly
 * scoped, excluded, capped and CITED (path + line). It never writes and never
 * escapes the workspace root (path traversal is rejected).
 */
class WorkspaceReader
{
    /** Directories never traversed (build output, deps, vcs, caches). */
    public const DEFAULT_EXCLUDES = [
        'vendor', 'node_modules', '.git', '.github', 'dist', 'build', '.next',
        'coverage', 'storage', 'bootstrap/cache', '.yarn', '.docker', 'docker',
        'public/build', 'public/hot', '.idea', '.vscode', 'tmp', 'temp',
        '__pycache__', '.pytest_cache', '.expo', 'ios/Pods', 'android/.gradle',
    ];

    /** Default text extensions worth scanning. */
    public const CODE_GLOBS = [
        'php', 'js', 'jsx', 'ts', 'tsx', 'vue', 'py', 'rb', 'go', 'java',
        'json', 'yaml', 'yml', 'env', 'md', 'sql', 'blade.php', 'css', 'scss',
    ];

    /** Filename suffixes skipped even when the extension matches (generated/minified). */
    public const SKIP_SUFFIXES = [
        '.min.js', '.min.css', '.bundle.js', '.map', '.lock',
        'composer.lock', 'package-lock.json', 'yarn.lock', 'pnpm-lock.yaml',
    ];

    /** Bound the per-file line cache so scanning a huge repo cannot exhaust memory. */
    private const MAX_LINE_CACHE = 96;

    private readonly string $root;

    /** @var array<string,list<string>> bounded cache of file lines by relative path */
    private array $lineCache = [];

    /** @var list<string>|null cache of the scannable file list */
    private ?array $fileCache = null;

    private int $filesRead = 0;

    /**
     * @param  string  $workspacePath  absolute path to the workspace root
     * @param  list<string>  $excludes  directory names/prefixes to skip
     * @param  int  $maxFileBytes  per-file read cap (default 512 KiB)
     */
    public function __construct(
        string $workspacePath,
        private readonly array $excludes = self::DEFAULT_EXCLUDES,
        private readonly int $maxFileBytes = 524_288,
        private readonly int $maxFiles = 20_000,
    ) {
        $real = realpath($workspacePath);
        if ($real === false || ! is_dir($real)) {
            throw ComprehensionException::workspaceUnavailable($workspacePath);
        }
        $this->root = rtrim($real, DIRECTORY_SEPARATOR);
    }

    public function root(): string
    {
        return $this->root;
    }

    public function filesRead(): int
    {
        return $this->filesRead;
    }

    /**
     * Resolve sub-repository roots (a monorepo may hold several apps). A root
     * is any directory under the workspace holding a composer.json or
     * package.json (depth-limited), plus the workspace itself.
     *
     * @return list<string> relative repo roots ('' = workspace root)
     */
    public function repoRoots(int $maxDepth = 2): array
    {
        $roots = $this->initialRepoRoots();

        foreach ($this->repoRootDirectoryIterator($maxDepth) as $info) {
            /** @var \SplFileInfo $info */
            $this->appendRepoRootIfDiscovered($roots, $info->getPathname());
        }

        return $this->repoRootsOrWorkspaceRoot($roots);
    }

    /**
     * @return list<string>
     */
    private function initialRepoRoots(): array
    {
        return $this->hasRepoManifest($this->root) ? [''] : [];
    }

    private function repoRootDirectoryIterator(int $maxDepth): \RecursiveIteratorIterator
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
                fn (\SplFileInfo $f) => $this->shouldInspectRepoRootDirectory($f),
            ),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        $iterator->setMaxDepth($maxDepth);

        return $iterator;
    }

    private function shouldInspectRepoRootDirectory(\SplFileInfo $file): bool
    {
        return $file->isDir() && ! $this->isExcludedAbsolute($file->getPathname());
    }

    /**
     * @param  list<string>  $roots
     */
    private function appendRepoRootIfDiscovered(array &$roots, string $directory): void
    {
        if (! $this->hasRepoManifest($directory)) {
            return;
        }

        $relative = $this->toRelative($directory);
        if ($relative === '' || in_array($relative, $roots, true)) {
            return;
        }

        $roots[] = $relative;
    }

    private function hasRepoManifest(string $directory): bool
    {
        return is_file($directory.'/composer.json') || is_file($directory.'/package.json');
    }

    /**
     * @param  list<string>  $roots
     * @return list<string>
     */
    private function repoRootsOrWorkspaceRoot(array $roots): array
    {
        return $roots === [] ? [''] : $roots;
    }

    /**
     * List scannable files (relative paths), filtered by extension globs.
     *
     * @param  list<string>  $extensions  file extensions (no dot); empty = CODE_GLOBS
     * @return list<string>
     */
    public function files(array $extensions = [], ?string $underRelative = null): array
    {
        $extensions = $this->normalizeExtensions($extensions);
        $underRelative = $this->normalizeUnderRelative($underRelative);

        if ($this->fileCache === null) {
            $this->fileCache = $this->scanAllFiles();
        }

        return $this->filterFiles($this->fileCache, $underRelative, $extensions);
    }

    private function normalizeExtensions(array $extensions): array
    {
        return $extensions === [] ? self::CODE_GLOBS : array_map('strtolower', $extensions);
    }

    private function normalizeUnderRelative(?string $underRelative): ?string
    {
        while ($underRelative !== null && str_starts_with($underRelative, './')) {
            $underRelative = substr($underRelative, 2);
        }
        if ($underRelative === '.') {
            $underRelative = '';
        }

        return $underRelative;
    }

    /**
     * @param  list<string>  $fileCache
     * @param  list<string>  $extensions
     * @return list<string>
     */
    private function filterFiles(array $fileCache, ?string $underRelative, array $extensions): array
    {
        $out = [];
        foreach ($fileCache as $rel) {
            if ($underRelative !== null && $underRelative !== '' && ! str_starts_with($rel, rtrim($underRelative, '/').'/')) {
                continue;
            }
            $lower = strtolower($rel);
            if ($this->isSkippedFile($lower)) {
                continue;
            }
            foreach ($extensions as $ext) {
                if (str_ends_with($lower, '.'.$ext)) {
                    $out[] = $rel;
                    break;
                }
            }
        }

        return $out;
    }

    private function isSkippedFile(string $lowerRel): bool
    {
        foreach (self::SKIP_SUFFIXES as $suffix) {
            if (str_ends_with($lowerRel, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Read a file's full content (capped), or null if missing/too big/binary.
     */
    public function read(string $relativePath): ?string
    {
        $abs = $this->guard($relativePath);
        if ($abs === null || ! is_file($abs)) {
            return null;
        }
        $size = filesize($abs);
        if ($size === false || $size > $this->maxFileBytes) {
            return null;
        }
        $content = @file_get_contents($abs);
        if ($content === false || $this->looksBinary($content)) {
            return null;
        }
        $this->filesRead++;

        return $content;
    }

    /**
     * Return a file's lines indexed from 1 (cached).
     *
     * @return list<string> 0-based array; line N is at index N-1
     */
    public function lines(string $relativePath): array
    {
        if (array_key_exists($relativePath, $this->lineCache)) {
            return $this->lineCache[$relativePath];
        }
        $content = $this->read($relativePath);
        $lines = $content === null ? [] : preg_split('/\r\n|\r|\n/', $content);
        $lines = is_array($lines) ? $lines : [];

        // Bounded cache: evict the oldest entry so scanning a huge repo cannot
        // accumulate every file's line array in memory.
        if (count($this->lineCache) >= self::MAX_LINE_CACHE) {
            array_shift($this->lineCache);
        }
        $this->lineCache[$relativePath] = $lines;

        return $lines;
    }

    /**
     * Grep a regex across files, returning cited matches.
     *
     * @param  string  $pattern  PCRE pattern (with delimiters)
     * @param  list<string>  $extensions
     * @return list<array{path:string,line:int,text:string}>
     */
    public function grep(string $pattern, array $extensions = [], int $maxMatches = 500, ?string $underRelative = null): array
    {
        $matches = [];
        foreach ($this->files($extensions, $underRelative) as $rel) {
            // Read each file once WITHOUT populating the bounded line cache; the
            // per-file lines array is transient (freed each iteration), so
            // grepping a large repo stays flat in memory regardless of repo size.
            $content = $this->read($rel);
            if ($content === null) {
                continue;
            }
            $fileLines = preg_split('/\r\n|\r|\n/', $content);
            if (! is_array($fileLines)) {
                continue;
            }
            foreach ($fileLines as $i => $text) {
                if (@preg_match($pattern, $text) === 1) {
                    $matches[] = ['path' => $rel, 'line' => $i + 1, 'text' => trim($text)];
                    if (count($matches) >= $maxMatches) {
                        return $matches;
                    }
                }
            }
            unset($fileLines);
        }

        return $matches;
    }

    /**
     * Decode a JSON manifest (composer.json / package.json), or [] if absent.
     *
     * @return array<string,mixed>
     */
    public function json(string $relativePath): array
    {
        $content = $this->read($relativePath);
        if ($content === null) {
            return [];
        }
        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * A short, cited snippet around a line (for evidence).
     */
    public function snippet(string $relativePath, int $line, int $context = 0): string
    {
        $lines = $this->lines($relativePath);
        $idx = $line - 1;
        if (! isset($lines[$idx])) {
            return '';
        }
        if ($context <= 0) {
            return trim($lines[$idx]);
        }
        $from = max(0, $idx - $context);
        $to = min(count($lines) - 1, $idx + $context);

        return trim(implode("\n", array_slice($lines, $from, $to - $from + 1)));
    }

    /**
     * @return list<string>
     */
    private function scanAllFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS),
                fn (\SplFileInfo $f) => $this->isAcceptableNode($f),
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $info) {
            /** @var \SplFileInfo $info */
            if (! $info->isFile()) {
                continue;
            }
            $real = realpath($info->getPathname());
            if ($real === false || ! str_starts_with($real, $this->root.DIRECTORY_SEPARATOR)) {
                continue;
            }
            $files[] = $this->toRelative($info->getPathname());
            if (count($files) >= $this->maxFiles) {
                break;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Decide whether the iterator should descend into / yield a node.
     * For non-directories we accept (the per-file filter runs in the foreach
     * below); for directories we reject symlinks, anything escaping the
     * workspace root, and anything in the exclude list.
     */
    private function isAcceptableNode(\SplFileInfo $f): bool
    {
        if (! $f->isDir()) {
            return true;
        }
        if ($f->isLink()) {
            return false;
        }
        $real = realpath($f->getPathname());
        if ($real === false || ($real !== $this->root && ! str_starts_with($real, $this->root.DIRECTORY_SEPARATOR))) {
            return false;
        }

        return ! $this->isExcludedAbsolute($f->getPathname());
    }

    private function guard(string $relativePath): ?string
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        if ($relativePath === '' || in_array('..', explode('/', $relativePath), true)) {
            return null;
        }
        $abs = $this->root.DIRECTORY_SEPARATOR.$relativePath;
        $real = realpath($abs);
        if ($real === false) {
            // File may legitimately not exist; still enforce the prefix on the
            // lexical path so traversal can't sneak through a missing segment.
            return str_starts_with($abs, $this->root.DIRECTORY_SEPARATOR) ? $abs : null;
        }

        return str_starts_with($real, $this->root.DIRECTORY_SEPARATOR) ? $real : null;
    }

    private function isExcludedAbsolute(string $absoluteDir): bool
    {
        $rel = $this->toRelative($absoluteDir);
        foreach ($this->excludes as $excluded) {
            $excluded = trim($excluded, '/');
            if ($rel === $excluded || str_starts_with($rel.'/', $excluded.'/') || str_contains($rel, '/'.$excluded.'/') || str_ends_with($rel, '/'.$excluded)) {
                return true;
            }
        }

        return false;
    }

    private function toRelative(string $absolute): string
    {
        $absolute = rtrim($absolute, DIRECTORY_SEPARATOR);
        if ($absolute === $this->root) {
            return '';
        }
        if (str_starts_with($absolute, $this->root.DIRECTORY_SEPARATOR)) {
            return str_replace('\\', '/', substr($absolute, strlen($this->root) + 1));
        }

        return str_replace('\\', '/', $absolute);
    }

    private function looksBinary(string $content): bool
    {
        return str_contains($content, "\0");
    }
}
