<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Insights;

use Closure;

/**
 * Deterministic FACT bundle for the Cortex Insights surface. Produces the structural facts the registered axes
 * consume — first-class being the orphan_spike axis: current_orphan_fqcns (classes under
 * app/Services/Ai/AutonomousEvolution whose class name has zero non-test references elsewhere in app/) and
 * prior_orphan_fqcns (read from a persisted snapshot, empty when absent). Empty-list defaults are supplied for
 * every other required_fact_key the registered axes declare so a full `inspect` run never throws.
 *
 * Pure: no provider/DB/network. The current-orphan source is injectable (closure or explicit list) so tests
 * pin a known set; the real default scans the bounded AutonomousEvolution tree.
 */
final class AtlasCortexInsightFactsProducer
{
    /**
     * @param  Closure():list<string>|list<string>|null  $currentOrphanSource  null ⇒ real scan
     */
    public function __construct(
        private readonly Closure|array|null $currentOrphanSource = null,
        private readonly ?string $snapshotPathOverride = null,
        private readonly ?string $appRootOverride = null,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function produce(): array
    {
        $facts = [
            'current_orphan_fqcns' => $this->currentOrphanFqcns(),
            'prior_orphan_fqcns' => $this->priorOrphanFqcns(),
        ];

        // Empty-list defaults for the OTHER axes' required keys so a full inspect run never throws on a missing
        // key. The orphan_spike keys above always win.
        foreach ($this->otherRequiredFactKeys() as $key) {
            $facts[$key] ??= [];
        }

        return $facts;
    }

    /**
     * @return list<string>
     */
    private function currentOrphanFqcns(): array
    {
        if (is_array($this->currentOrphanSource)) {
            return $this->normalize($this->currentOrphanSource);
        }
        if ($this->currentOrphanSource instanceof Closure) {
            return $this->normalize((array) ($this->currentOrphanSource)());
        }

        return $this->scanOrphans();
    }

    /**
     * @return list<string>
     */
    private function priorOrphanFqcns(): array
    {
        $path = $this->snapshotPath();
        if (! is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) @file_get_contents($path), true);
        if (is_array($decoded) && isset($decoded['orphan_fqcns']) && is_array($decoded['orphan_fqcns'])) {
            return $this->normalize($decoded['orphan_fqcns']);
        }

        return is_array($decoded) ? $this->normalize($decoded) : [];
    }

    /**
     * Real default: a class under the AutonomousEvolution tree is an orphan when its short name appears in NO
     * app/ file other than its own definition.
     *
     * ponytail: O(app_files × scoped_classes) string scan — fine for an occasional introspection run; if it
     * ever dominates, build a one-pass token index of app/ instead.
     *
     * @return list<string>
     */
    private function scanOrphans(): array
    {
        $appRoot = rtrim($this->appRootOverride ?? (function_exists('base_path') ? base_path('app') : __DIR__), '/');
        $scopeRoot = $appRoot.'/Services/Ai/AutonomousEvolution';
        if (! is_dir($scopeRoot)) {
            return [];
        }

        $fqcnByShort = [];
        $definingFile = [];
        foreach ($this->phpFiles($scopeRoot) as $file) {
            $contents = (string) @file_get_contents($file);
            $namespace = $this->namespaceOf($contents);
            foreach ($this->classNamesOf($contents) as $short) {
                $fqcnByShort[$short] = ($namespace !== '' ? $namespace.'\\' : '').$short;
                $definingFile[$short] = $file;
            }
        }
        if ($fqcnByShort === []) {
            return [];
        }

        $referenced = [];
        foreach ($this->phpFiles($appRoot) as $file) {
            $contents = (string) @file_get_contents($file);
            foreach ($fqcnByShort as $short => $fqcn) {
                if (isset($referenced[$short]) || $file === $definingFile[$short]) {
                    continue;
                }
                if (str_contains($contents, $short) && preg_match('/\b'.preg_quote($short, '/').'\b/', $contents) === 1) {
                    $referenced[$short] = true;
                }
            }
        }

        $orphans = [];
        foreach ($fqcnByShort as $short => $fqcn) {
            if (! isset($referenced[$short])) {
                $orphans[] = $fqcn;
            }
        }

        return $this->normalize($orphans);
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }
        $files = [];
        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iter as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->isFile() && strtolower($entry->getExtension()) === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        return $files;
    }

    private function namespaceOf(string $contents): string
    {
        return preg_match('/^\s*namespace\s+([A-Za-z_][A-Za-z0-9_\\\\]*)\s*;/m', $contents, $m) === 1 ? $m[1] : '';
    }

    /**
     * @return list<string>
     */
    private function classNamesOf(string $contents): array
    {
        $names = [];
        if (preg_match_all('/^(?:final\s+|abstract\s+)?(?:readonly\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)\b/m', $contents, $m) > 0) {
            foreach ($m[1] as $name) {
                $names[] = (string) $name;
            }
        }

        return array_values(array_unique($names));
    }

    private function snapshotPath(): string
    {
        if ($this->snapshotPathOverride !== null && trim($this->snapshotPathOverride) !== '') {
            return $this->snapshotPathOverride;
        }

        return (string) config(
            'atlas.loop.cortex.insights.orphan_snapshot_path',
            (function_exists('storage_path') ? storage_path('atlas/cortex/orphan-snapshot.json') : 'orphan-snapshot.json'),
        );
    }

    /**
     * @return list<string>
     */
    private function otherRequiredFactKeys(): array
    {
        $keys = [];
        foreach ((new AtlasCortexInsightObserverRegistry)->axes() as $descriptor) {
            foreach ((array) ($descriptor['required_fact_keys'] ?? []) as $key) {
                $keys[(string) $key] = true;
            }
        }
        unset($keys['current_orphan_fqcns'], $keys['prior_orphan_fqcns']);

        return array_keys($keys);
    }

    /**
     * @param  list<mixed>  $values
     * @return list<string>
     */
    private function normalize(array $values): array
    {
        $values = array_values(array_unique(array_filter(
            array_map(static fn (mixed $v): string => trim((string) $v), $values),
            static fn (string $v): bool => $v !== '',
        )));
        sort($values, SORT_STRING);

        return $values;
    }
}
