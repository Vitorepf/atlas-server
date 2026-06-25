<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\ExternalDeps;

/**
 * Facts-only drift detector for external dependencies.
 *
 * Compares the CURRENT composer.lock state against a persisted last-known-good snapshot and emits
 * a FACT report: added_packages, removed_packages, version_changed, content_hash_changed,
 * unexpected (true when lock changed without a matching composer.json constraint change).
 *
 * Persists snapshots under storage/app/atlas/loop/external_deps/snapshots/ as deterministic JSON
 * (sorted keys, LF newlines) and rotates to keep at most N (default 50).
 *
 * NEVER mutates composer.json or composer.lock. NEVER emits risk/severity/recommendation/safe/unsafe.
 */
final class AtlasLoopExternalDepDriftDetector
{
    public const SCHEMA = 'atlas.loop.external_dep_drift.v1';

    public const SNAPSHOT_DIR = 'atlas/loop/external_deps/snapshots';

    public function __construct(
        private readonly string $snapshotRoot,
        private readonly int $retention = 50,
    ) {}

    /**
     * @param  array<string,mixed>  $currentLock   parsed composer.lock subset:
     *                                             { content-hash, packages: [{name, version}], ... }
     * @param  array<string,mixed>  $currentJson   parsed composer.json subset: { require, require-dev }
     * @return array<string,mixed>
     */
    public function detect(array $currentLock, array $currentJson): array
    {
        $currentSnapshot = $this->buildSnapshot($currentLock, $currentJson);
        $lastSnapshot = $this->latestSnapshot();

        $report = $this->compare($lastSnapshot, $currentSnapshot);
        $this->persist($currentSnapshot);
        $this->rotate();

        return $report;
    }

    /**
     * @param  array<string,mixed>  $lock
     * @param  array<string,mixed>  $json
     * @return array<string,mixed>
     */
    private function buildSnapshot(array $lock, array $json): array
    {
        $packages = [];
        foreach ((array) ($lock['packages'] ?? []) as $pkg) {
            if (! is_array($pkg)) {
                continue;
            }
            $name = (string) ($pkg['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $packages[$name] = (string) ($pkg['version'] ?? '');
        }
        ksort($packages);

        $constraints = [];
        foreach ((array) ($json['require'] ?? []) as $name => $constraint) {
            $constraints[(string) $name] = (string) $constraint;
        }
        foreach ((array) ($json['require-dev'] ?? []) as $name => $constraint) {
            $constraints[(string) $name] = (string) $constraint;
        }
        ksort($constraints);

        return [
            'content_hash' => (string) ($lock['content-hash'] ?? ''),
            'packages' => $packages,
            'constraints' => $constraints,
        ];
    }

    /**
     * @param  array<string,mixed>|null  $last
     * @param  array<string,mixed>  $current
     * @return array<string,mixed>
     */
    private function compare(?array $last, array $current): array
    {
        $lastPackages = is_array($last['packages'] ?? null) ? $last['packages'] : [];
        $lastConstraints = is_array($last['constraints'] ?? null) ? $last['constraints'] : [];
        $lastHash = (string) ($last['content_hash'] ?? '');

        $added = array_values(array_diff(array_keys($current['packages']), array_keys($lastPackages)));
        $removed = array_values(array_diff(array_keys($lastPackages), array_keys($current['packages'])));
        sort($added, SORT_STRING);
        sort($removed, SORT_STRING);

        $versionChanged = [];
        foreach ($current['packages'] as $name => $version) {
            if (! isset($lastPackages[$name])) {
                continue;
            }
            if ((string) $lastPackages[$name] === (string) $version) {
                continue;
            }
            $constraintChanged = ($lastConstraints[$name] ?? null) !== ($current['constraints'][$name] ?? null);
            $versionChanged[] = [
                'name' => (string) $name,
                'from' => (string) $lastPackages[$name],
                'to' => (string) $version,
                'constraint_changed' => $constraintChanged,
            ];
        }
        usort($versionChanged, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        $contentHashChanged = $lastHash !== '' && $lastHash !== $current['content_hash'];
        $lockOnlyDrift = false;
        foreach ($versionChanged as $v) {
            if (! $v['constraint_changed']) {
                $lockOnlyDrift = true;
                break;
            }
        }
        if ($added !== [] || $removed !== []) {
            // Added/removed without matching constraint signal also counts as unexpected when no
            // constraint mention exists.
            foreach (array_merge($added, $removed) as $name) {
                if (! array_key_exists($name, $current['constraints']) && ! array_key_exists($name, $lastConstraints)) {
                    $lockOnlyDrift = true;
                    break;
                }
            }
        }
        $unexpected = $contentHashChanged && $lockOnlyDrift;

        return [
            'schema_version' => self::SCHEMA,
            'added_packages' => $added,
            'removed_packages' => $removed,
            'version_changed' => $versionChanged,
            'content_hash_changed' => $contentHashChanged,
            'unexpected' => $unexpected,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function latestSnapshot(): ?array
    {
        $files = $this->listSnapshots();
        if ($files === []) {
            return null;
        }
        $latestPath = $files[count($files) - 1];
        $raw = @file_get_contents($latestPath);
        if ($raw === false || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string,mixed>  $snapshot
     */
    private function persist(array $snapshot): void
    {
        $dir = $this->snapshotRoot.'/'.self::SNAPSHOT_DIR;
        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
        // Deterministic name: content_hash + lexicographic suffix to disambiguate equal hashes.
        $base = $snapshot['content_hash'] !== '' ? $snapshot['content_hash'] : 'unhashed';
        $suffix = 0;
        do {
            $name = sprintf('%s-%04d.json', $base, $suffix++);
            $path = $dir.'/'.$name;
        } while (file_exists($path) && $this->fileContent($path) !== $this->encode($snapshot));

        @file_put_contents($path, $this->encode($snapshot));
    }

    private function rotate(): void
    {
        $files = $this->listSnapshots();
        $excess = count($files) - $this->retention;
        for ($i = 0; $i < $excess; $i++) {
            @unlink($files[$i]);
        }
    }

    /**
     * @return list<string>
     */
    private function listSnapshots(): array
    {
        $dir = $this->snapshotRoot.'/'.self::SNAPSHOT_DIR;
        if (! is_dir($dir)) {
            return [];
        }
        $files = glob($dir.'/*.json');
        if ($files === false) {
            return [];
        }
        sort($files, SORT_STRING);

        return array_values($files);
    }

    /**
     * @param  array<string,mixed>  $snapshot
     */
    private function encode(array $snapshot): string
    {
        ksort($snapshot);
        if (isset($snapshot['packages']) && is_array($snapshot['packages'])) {
            ksort($snapshot['packages']);
        }
        if (isset($snapshot['constraints']) && is_array($snapshot['constraints'])) {
            ksort($snapshot['constraints']);
        }

        return json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n";
    }

    private function fileContent(string $path): string
    {
        return (string) @file_get_contents($path);
    }
}
