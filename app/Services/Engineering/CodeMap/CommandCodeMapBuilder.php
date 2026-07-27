<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeMap;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The index for app/Console/Commands, which ZoneCodeMapBuilder cannot produce.
 *
 * That builder defines a façade as "a class some file OUTSIDE its zone names".
 * Commands are named by nobody: Laravel discovers them by scanning the directory
 * and dispatches them by signature string. So the façade heuristic reports an
 * empty zone for 965 files and 137,121 lines, and an agent looking for a command
 * has to grep 909 flat files to find one.
 *
 * The key an agent actually holds is the artisan name — `atlas:task:health`, not
 * `AtlasTaskHealthCommand`. So that is the key here, paired with the description
 * the command already declares and the file to open. Everything is read straight
 * out of $signature/$description; nothing boots, nothing runs.
 */
final class CommandCodeMapBuilder
{
    private const COMMAND_ROOT = 'app/Console/Commands';

    private const HEADER = "| Artisan name | What it does | File |\n| --- | --- | --- |";

    public function __construct(private readonly string $basePath) {}

    /**
     * @return array<string,string> relative CODEMAP path => rendered markdown
     */
    public function build(): array
    {
        $rows = [];

        foreach ($this->commandFiles() as $relative => $source) {
            $name = $this->signatureName($source);
            if ($name === null) {
                continue;
            }
            $description = $this->description($source);
            $rows[] = ['name' => $name, 'description' => $description, 'file' => $relative];

            // Deprecated names live in $aliases and are HIDDEN from `artisan list`,
            // so the index is the only place an agent can still find them.
            foreach ($this->aliases($source) as $alias) {
                $rows[] = [
                    'name' => $alias,
                    'description' => "Alias of `{$name}`. ".$description,
                    'file' => $relative,
                ];
            }
        }

        if ($rows === []) {
            return [];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return [self::COMMAND_ROOT.'/CODEMAP.md' => $this->render($rows)];
    }

    /**
     * @return array<string,string> relative path => source
     */
    private function commandFiles(): array
    {
        $root = $this->basePath.'/'.self::COMMAND_ROOT;
        if (! is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = ltrim(str_replace($this->basePath, '', $file->getPathname()), '/');
            $files[$relative] = (string) @file_get_contents($file->getPathname());
        }

        ksort($files);

        return $files;
    }

    /**
     * The artisan name is everything before the first argument/option brace.
     * Signatures here are routinely multi-line, so the name is taken from the
     * first non-empty chunk rather than assuming it all sits on one line.
     */
    private function signatureName(string $source): ?string
    {
        // Two declarations reach artisan: $signature (name + args) and the bare
        // $name used by dispatcher commands that parse their own input.
        // AtlasAiSelfConstructionMotherCommand is the second kind, and reading only
        // $signature silently dropped it from the index.
        if (preg_match('/\$signature\s*=\s*([\'"])(.*?)\1\s*;/s', $source, $m) !== 1
            && preg_match('/\$name\s*=\s*([\'"])(.*?)\1\s*;/s', $source, $m) !== 1) {
            return null;
        }

        $name = trim(explode('{', $m[2], 2)[0]);
        $name = trim(preg_split('/\s/', $name, 2)[0] ?? '');

        return $name === '' ? null : $name;
    }

    /**
     * @return list<string>
     */
    private function aliases(string $source): array
    {
        if (preg_match('/\$aliases\s*=\s*\[(.*?)\]\s*;/s', $source, $m) !== 1) {
            return [];
        }

        preg_match_all('/[\'"]([a-z][a-z0-9:_-]*)[\'"]/i', $m[1], $found);

        return array_values($found[1] ?? []);
    }

    private function description(string $source): string
    {
        if (preg_match('/\$description\s*=\s*([\'"])(.*?)\1\s*;/s', $source, $m) !== 1) {
            return '—';
        }

        $text = trim(preg_replace('/\s+/', ' ', $m[2]) ?? '');
        // The table is one row per command; a paragraph-long description would
        // make the map unreadable, and the file is one click away for the rest.
        if (mb_strlen($text) > 160) {
            $text = mb_substr($text, 0, 157).'...';
        }

        return str_replace('|', '\\|', $text);
    }

    /**
     * @param  list<array{name:string,description:string,file:string}>  $rows
     */
    private function render(array $rows): string
    {
        $lines = [
            '# CODEMAP — '.self::COMMAND_ROOT,
            '',
            '<!-- ATLAS-CODEMAP: GENERATED — do not hand-edit.',
            '     Rebuild: php artisan atlas:codemap --write',
            '     Verify:  php artisan atlas:codemap --verify -->',
            '',
            'Every artisan command in this tree, keyed by the name you type.',
            'Laravel discovers these by scanning the directory, so no class names',
            'this file — which is why the façade index cannot see them.',
            '',
            self::HEADER,
        ];

        foreach ($rows as $row) {
            $lines[] = sprintf('| `%s` | %s | `%s` |', $row['name'], $row['description'], $row['file']);
        }

        return implode("\n", $lines)."\n";
    }
}
