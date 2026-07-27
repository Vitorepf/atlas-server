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
    /**
     * Roots whose immediate subdirectories each become a zone.
     *
     * index() already reads and inverts EVERY php file under app/ — 1,586,012
     * lines — on every run. Emitting over only app/Services/Ai meant 344,627 of
     * those lines (21.7% of the corpus) were tokenised and thrown away, leaving
     * app/Services/Engineering (73,652 lines), the controllers and eight other
     * service trees with no map at all. The reading was already paid for.
     */
    private const ZONE_ROOTS = [
        'app/Services/Ai',
        'app/Services',
        'app/Http/Controllers',
    ];

    /**
     * Directories whose OWN files (not subdirectories) form a single zone —
     * for trees that are flat by design, like the 161 controllers and 407 models
     * sitting directly in their folder.
     */
    private const FLAT_ZONES = [
        'app/Http/Controllers',
        'app/Models',
        'app/Support',
    ];

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
        foreach ($this->zones() as $zoneDir => $files) {
            $rows = $this->facadeRows($zoneDir.'/', $files);
            if ($rows === []) {
                continue;
            }
            $maps[$zoneDir.'/CODEMAP.md'] = $this->render($zoneDir, $rows);
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

        foreach (self::ZONE_ROOTS as $root) {
            foreach ((glob($this->basePath.'/'.$root.'/*', GLOB_ONLYDIR) ?: []) as $dir) {
                $zoneDir = $root.'/'.basename($dir);
                // A nested root owns its own subtree — app/Services must not swallow
                // app/Services/Ai and emit one 4,612-file zone on top of its 107.
                if ($this->ownedByANestedRoot($zoneDir)) {
                    continue;
                }
                $this->collectZone($zones, $zoneDir, recursive: true);
            }
        }

        foreach (self::FLAT_ZONES as $zoneDir) {
            $this->collectZone($zones, $zoneDir, recursive: false);
        }

        ksort($zones);

        return $zones;
    }

    private function ownedByANestedRoot(string $zoneDir): bool
    {
        foreach (self::ZONE_ROOTS as $root) {
            if ($root !== $zoneDir && str_starts_with($root, $zoneDir.'/')) {
                return true;
            }
            if ($root === $zoneDir) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,list<string>>  $zones
     */
    private function collectZone(array &$zones, string $zoneDir, bool $recursive): void
    {
        $prefix = $zoneDir.'/';
        $files = [];
        $lines = 0;
        foreach ($this->sources as $path => $source) {
            if (! str_starts_with($path, $prefix)) {
                continue;
            }
            if (! $recursive && str_contains(substr($path, strlen($prefix)), '/')) {
                continue;
            }
            $files[] = $path;
            $lines += substr_count($source, "\n");
        }
        if ($lines >= self::MIN_ZONE_LINES && $files !== []) {
            sort($files);
            $zones[$zoneDir] = $files;
        }
    }

    /**
     * @param  list<string>  $files
     * @return list<array{class:string,target:string}>
     */
    private function facadeRows(string $zonePrefix, array $files): array
    {
        $prefix = $zonePrefix;
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
