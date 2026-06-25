<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\MultiLang\AtlasCortexLanguageRegistry;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\MultiLang\AtlasCortexTypeScriptParserFacts;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\MultiLang\AtlasCortexYamlConfigFactExtractor;
use Illuminate\Console\Command;

/**
 * Operator surface for the Cortex MultiLang sub-system.
 *   atlas:loop:cortex:multilang list
 *   atlas:loop:cortex:multilang extract --path=<abs>
 *   atlas:loop:cortex:multilang history --language=<lang> [--limit=N]
 *
 * FACT-only output. No provider, no scoring. Refuses paths outside Loop scope roots.
 */
final class AtlasLoopCortexMultiLangCommand extends Command
{
    public const EXIT_OK = 0;

    public const EXIT_USAGE = 2;

    public const SCOPE_ROOTS = ['app/', 'tests/', 'docs/', 'config/'];

    protected $signature = 'atlas:loop:cortex:multilang {action : list|extract|history}
        {--path= : absolute path to extract (extract)}
        {--language= : language id (history)}
        {--limit=10}';

    protected $description = 'Cortex MultiLang FACTS CLI (list | extract | history).';

    public function handle(AtlasCortexLanguageRegistry $registry): int
    {
        $action = (string) $this->argument('action');

        return match ($action) {
            'list' => $this->list($registry),
            'extract' => $this->extract($registry),
            'history' => $this->history(),
            default => $this->refuse('unknown_action:'.$action),
        };
    }

    private function list(AtlasCortexLanguageRegistry $registry): int
    {
        $rows = [];
        foreach ($registry->supported() as $lang) {
            $rows[] = $registry->get($lang);
        }
        $this->emit($rows);

        return self::EXIT_OK;
    }

    private function extract(AtlasCortexLanguageRegistry $registry): int
    {
        $path = (string) ($this->option('path') ?? '');
        if ($path === '' || ! is_file($path)) {
            return $this->refuse('extract_requires_existing_path');
        }
        if (! $this->insideLoopScope($path)) {
            return $this->refuse('path_outside_loop_scope:'.$path);
        }
        $language = $registry->resolveForPath($path);
        if ($language === null) {
            return $this->refuse('unsupported_extension');
        }
        $binding = $registry->get($language);

        try {
            $facts = $this->invokeExtractor($language, $binding, $path);
        } catch (\Throwable $e) {
            return $this->refuse('extract_failed:'.$e->getMessage());
        }

        $payload = [
            'language' => $language,
            'path' => $path,
            'facts' => $facts,
        ];
        $bytes = $this->canonicalEncode($payload);
        $sha = hash('sha256', $bytes);
        $this->persist($language, $sha, $bytes);

        $this->emit(['language' => $language, 'path' => $path, 'sha256' => $sha, 'facts' => $facts]);

        return self::EXIT_OK;
    }

    private function history(): int
    {
        $language = (string) ($this->option('language') ?? '');
        if ($language === '') {
            return $this->refuse('history_requires_language');
        }
        $limit = max(1, (int) ($this->option('limit') ?? 10));
        $root = $this->languageDir($language);
        if (! is_dir($root)) {
            $this->emit([]);

            return self::EXIT_OK;
        }
        $files = glob($root.'/*.json') ?: [];
        sort($files, SORT_STRING);
        $files = array_slice($files, -$limit);
        $rows = [];
        foreach ($files as $f) {
            $decoded = json_decode((string) file_get_contents($f), true);
            $rows[] = [
                'file' => $f,
                'language' => $language,
                'path' => is_array($decoded) ? ($decoded['path'] ?? '') : '',
                'sha256' => pathinfo($f, PATHINFO_FILENAME),
            ];
        }
        $this->emit($rows);

        return self::EXIT_OK;
    }

    /**
     * @param  array<string,mixed>  $binding
     * @return array<string,mixed>
     */
    private function invokeExtractor(string $language, array $binding, string $path): array
    {
        $extractorClass = (string) ($binding['extractor_class'] ?? '');
        if ($language === 'typescript') {
            $roots = array_filter(array_map(static fn (string $rel): string => function_exists('base_path') ? base_path(rtrim($rel, '/')) : '', self::SCOPE_ROOTS));

            return (new AtlasCortexTypeScriptParserFacts(array_values($roots)))->parse($path);
        }
        if ($language === 'yaml') {
            return (new AtlasCortexYamlConfigFactExtractor())->extract($path);
        }
        if ($language === 'php') {
            // PHP extractor takes an FQCN, not a path; surface a minimal FACT shape derived from the file.
            return [
                'note' => 'php_path_extraction_requires_class_resolution',
                'declared_classes' => $this->grepDeclaredClasses($path),
            ];
        }
        if ($extractorClass !== '' && class_exists($extractorClass) && method_exists($extractorClass, 'extract')) {
            $instance = new $extractorClass();

            return (array) $instance->extract($path);
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function grepDeclaredClasses(string $path): array
    {
        $contents = (string) file_get_contents($path);
        $matches = [];
        if (preg_match_all('/^(?:final|abstract|readonly)?\s*class\s+(\w+)/m', $contents, $m)) {
            $matches = array_values(array_unique($m[1]));
        }

        return $matches;
    }

    private function insideLoopScope(string $absolute): bool
    {
        $base = function_exists('base_path') ? rtrim(base_path(), '/').'/' : '';
        if ($base === '' || ! str_starts_with($absolute, $base)) {
            return false;
        }
        $rel = substr($absolute, strlen($base));
        foreach (self::SCOPE_ROOTS as $root) {
            if (str_starts_with($rel, $root)) {
                return true;
            }
        }

        return false;
    }

    private function languageDir(string $language): string
    {
        $base = function_exists('storage_path') ? storage_path('atlas/cortex/multilang') : sys_get_temp_dir().'/atlas/cortex/multilang';

        return $base.'/'.$language;
    }

    private function persist(string $language, string $sha, string $bytes): void
    {
        $dir = $this->languageDir($language);
        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
        @file_put_contents($dir.'/'.$sha.'.json', $bytes);
    }

    /**
     * @param  array<int|string,mixed>  $payload
     */
    private function canonicalEncode(array $payload): string
    {
        return (string) json_encode($this->sortRecursive($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  mixed  $value
     * @return mixed
     */
    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $v): mixed => $this->sortRecursive($v), $value);
        }
        ksort($value, SORT_STRING);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = $this->sortRecursive($v);
        }

        return $out;
    }

    /**
     * @param  array<int|string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        $this->getOutput()->writeln($this->canonicalEncode($payload));
    }

    private function refuse(string $reason): int
    {
        $this->getOutput()->writeln($reason);

        return self::EXIT_USAGE;
    }
}
