<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeMap;

/**
 * Builds one CODEMAP per zone of app/Services/Ai.
 *
 * A hand-written map of a 1.5M-line corpus rots within a week — the root
 * CODEMAP.md covered ~40 of 6656 files and had drifted into an invalid state.
 * So the per-zone maps are DERIVED: a zone's public façades are the classes
 * some file outside that zone actually names. Nothing is authored, so nothing
 * can go stale without the verifier noticing.
 *
 * The map answers exactly one question — "which symbol owns this capability,
 * and where does it live" — which is the hop an agent otherwise pays for with
 * archaeology.
 */
final class ZoneCodeMapBuilder
{
    private const ZONE_ROOT = 'app/Services/Ai';

    /** Zones below this stay unmapped; the map is for territory, not corners. */
    private const MIN_ZONE_LINES = 500;

    private const HEADER = "| Public façade | Concrete navigation target |\n| --- | --- |";

    /** @var array<string,string> path => source */
    private array $sources = [];

    /** @var array<string,list<string>> token => paths naming it */
    private array $mentions = [];

    public function __construct(private readonly string $basePath) {}

    /**
     * @return array<string,string> relative CODEMAP path => rendered markdown
     */
    public function build(): array
    {
        $this->index();

        $maps = [];
        foreach ($this->zones() as $zone => $files) {
            $rows = $this->facadeRows($zone, $files);
            if ($rows === []) {
                continue;
            }
            $maps[self::ZONE_ROOT.'/'.$zone.'/CODEMAP.md'] = $this->render($zone, $rows);
        }

        return $maps;
    }

    /**
     * Zones worth mapping, each with its own PHP files.
     *
     * @return array<string,list<string>>
     */
    private function zones(): array
    {
        $zones = [];
        $root = $this->basePath.'/'.self::ZONE_ROOT;

        foreach ((glob($root.'/*', GLOB_ONLYDIR) ?: []) as $dir) {
            $zone = basename($dir);
            $files = [];
            $lines = 0;
            foreach ($this->sources as $path => $source) {
                if (str_starts_with($path, self::ZONE_ROOT.'/'.$zone.'/')) {
                    $files[] = $path;
                    $lines += substr_count($source, "\n");
                }
            }
            if ($lines >= self::MIN_ZONE_LINES && $files !== []) {
                sort($files);
                $zones[$zone] = $files;
            }
        }

        ksort($zones);

        return $zones;
    }

    /**
     * @param  list<string>  $files
     * @return list<array{class:string,target:string}>
     */
    private function facadeRows(string $zone, array $files): array
    {
        $prefix = self::ZONE_ROOT.'/'.$zone.'/';
        $rows = [];

        foreach ($files as $path) {
            $class = basename($path, '.php');
            if (! $this->namedOutside($class, $prefix)) {
                continue;
            }
            $fqcn = $this->fqcn($path);
            $entry = $this->primaryEntry($this->sources[$path]);
            if ($fqcn === null || $entry === null) {
                continue;
            }
            $rows[] = ['class' => $class, 'target' => $fqcn.'::'.$entry];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['class'], $b['class']));

        return $rows;
    }

    private function namedOutside(string $class, string $zonePrefix): bool
    {
        foreach ($this->mentions[$class] ?? [] as $path) {
            if (! str_starts_with($path, $zonePrefix)) {
                return true;
            }
        }

        return false;
    }

    private function fqcn(string $path): ?string
    {
        if (preg_match('/^namespace\s+([A-Za-z0-9_\\\\]+)\s*;/m', $this->sources[$path], $m) !== 1) {
            return null;
        }

        return $m[1].'\\'.basename($path, '.php');
    }

    /**
     * First public method that is not glue — the door an agent should knock on.
     */
    private function primaryEntry(string $source): ?string
    {
        preg_match_all('/public\s+(?:static\s+)?function\s+(\w+)\s*\(/', $source, $m);

        foreach ($m[1] ?? [] as $method) {
            if (! str_starts_with($method, '__')) {
                return $method;
            }
        }

        return null;
    }

    /**
     * @param  list<array{class:string,target:string}>  $rows
     */
    private function render(string $zone, array $rows): string
    {
        $lines = [
            '# CODEMAP — '.$zone,
            '',
            '<!-- ATLAS-CODEMAP: GENERATED — do not hand-edit.',
            '     Rebuild: php artisan atlas:codemap --write',
            '     Verify:  php artisan atlas:codemap --verify -->',
            '',
            'Public façades of this zone: classes named by at least one file outside it.',
            'The target is the first public entry method — the door, not the whole surface.',
            '',
            self::HEADER,
        ];

        foreach ($rows as $row) {
            $lines[] = '| '.$row['class'].' | `'.$row['target'].'` |';
        }

        $lines[] = '';
        $lines[] = 'Façades: '.count($rows).'.';
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function index(): void
    {
        if ($this->sources !== []) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->basePath.'/app', \FilesystemIterator::SKIP_DOTS),
        );

        $classNames = [];
        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $absolute = $file->getPathname();
            $relative = ltrim(str_replace($this->basePath, '', $absolute), '/');
            $this->sources[$relative] = (string) @file_get_contents($absolute);
            $classNames[$file->getBasename('.php')] = true;
        }

        // Inverted index restricted to real class names: a zone façade is a class
        // some outside file names, and only names we declare can be that.
        foreach ($this->sources as $path => $source) {
            preg_match_all('/\b[A-Z][A-Za-z0-9_]{3,}\b/', $source, $m);
            foreach (array_unique($m[0] ?? []) as $token) {
                if (isset($classNames[$token])) {
                    $this->mentions[$token][] = $path;
                }
            }
        }
    }
}
