<?php

namespace App\Services\Ai\Rivals\Core;

use App\Services\Ai\Rivals\Support\AtomicWriter;
use App\Services\Ai\Rivals\Support\RunPaths;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/** Portable, read-only-verifiable inventory of one completed run directory. */
final class BundleManifest
{
    public const SCHEMA = 'atlas.rivals2.bundle_manifest.v1';

    /** @return array<string, mixed> */
    public function build(string $runId): array
    {
        $runDir = RunPaths::runDir($runId);
        $files = $this->inventory($runDir);
        $latestLedger = collect((new ResultLedger)->entries())
            ->reverse()
            ->firstWhere('run_id', $runId);
        $manifest = [
            'schema_version' => self::SCHEMA,
            'run_id' => $runId,
            'files' => $files,
            'evidence_hash' => is_file(RunPaths::evidencePath($runId))
                ? (json_decode((string) file_get_contents(RunPaths::evidencePath($runId)), true)['evidence_hash'] ?? null)
                : null,
            'ledger_entry_hash' => $latestLedger['entry_hash'] ?? null,
            'generator_git_head' => trim((string) shell_exec(
                'git -C '.escapeshellarg(base_path()).' rev-parse HEAD 2>/dev/null'
            )),
            'built_at' => now()->toIso8601String(),
        ];
        $manifest['bundle_hash'] = self::hashPayload($manifest);
        AtomicWriter::write(
            RunPaths::bundleManifestPath($runId),
            json_encode(
                $manifest,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
            )
        );

        return $manifest;
    }

    /** @return array{verified: bool, bundle_hash: ?string, failures: list<string>} */
    public function verify(string $bundleDirectory): array
    {
        $path = rtrim($bundleDirectory, '/').'/bundle_manifest.json';
        if (! is_file($path)) {
            return ['verified' => false, 'bundle_hash' => null, 'failures' => ['bundle_manifest_missing']];
        }
        $manifest = json_decode((string) file_get_contents($path), true);
        if (! is_array($manifest) || ($manifest['schema_version'] ?? null) !== self::SCHEMA) {
            return ['verified' => false, 'bundle_hash' => null, 'failures' => ['bundle_manifest_invalid']];
        }
        $failures = [];
        if (! hash_equals(
            (string) ($manifest['bundle_hash'] ?? ''),
            self::hashPayload($manifest),
        )) {
            $failures[] = 'bundle_hash_mismatch';
        }
        foreach ((array) ($manifest['files'] ?? []) as $relative => $recorded) {
            $file = rtrim($bundleDirectory, '/').'/'.$relative;
            if (! is_file($file)) {
                $failures[] = "bundle_file_missing:{$relative}";
            } elseif (! hash_equals(
                (string) ($recorded['sha256'] ?? ''),
                hash_file('sha256', $file),
            )) {
                $failures[] = "bundle_file_hash_mismatch:{$relative}";
            }
        }
        $evidencePath = rtrim($bundleDirectory, '/').'/evidence_pack.json';
        $evidence = is_file($evidencePath)
            ? (json_decode((string) file_get_contents($evidencePath), true) ?? [])
            : [];
        if (($manifest['evidence_hash'] ?? null) !== null
            && ! hash_equals(
                (string) $manifest['evidence_hash'],
                (string) ($evidence['evidence_hash'] ?? ''),
            )) {
            $failures[] = 'bundle_evidence_hash_mismatch';
        }

        return [
            'verified' => $failures === [],
            'bundle_hash' => $manifest['bundle_hash'] ?? null,
            'failures' => $failures,
        ];
    }

    /** @return array<string, array{sha256: string, size_bytes: int}> */
    private function inventory(string $runDir): array
    {
        if (! is_dir($runDir)) {
            throw new RuntimeException('rivals_bundle_run_directory_missing');
        }
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($runDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $relative = ltrim(substr($file->getPathname(), strlen(rtrim($runDir, '/'))), '/');
            if ($relative === 'bundle_manifest.json'
                || $relative === '.run.lock'
                || str_starts_with($relative, 'worktrees/')
                || str_starts_with($relative, 'native_scratch/')) {
                continue;
            }
            $files[$relative] = [
                'sha256' => hash_file('sha256', $file->getPathname()),
                'size_bytes' => $file->getSize(),
            ];
        }
        ksort($files);

        return $files;
    }

    /** @param array<string, mixed> $payload */
    public static function hashPayload(array $payload): string
    {
        unset($payload['bundle_hash']);

        return hash('sha256', json_encode(
            self::canonicalize($payload),
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonicalize(...), $value);
    }
}
