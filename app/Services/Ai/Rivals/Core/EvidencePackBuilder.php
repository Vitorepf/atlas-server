<?php

namespace App\Services\Ai\Rivals\Core;

use App\Services\Ai\Rivals\Support\AtomicWriter;
use App\Services\Ai\Rivals\Support\RunPaths;
use App\Services\Ai\Rivals\Support\SchemaContract;
use Illuminate\Support\Facades\Process;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Evidence pack hash-pinned do run. Campo indisponível vira
 * {present:false, reason_missing} — nunca é inventado.
 */
class EvidencePackBuilder
{
    public function build(string $runId): array
    {
        $runDir = RunPaths::runDir($runId);
        $planPath = RunPaths::planPath($runId);
        $receiptsPath = RunPaths::receiptsPath($runId);
        $plan = RunPlan::load($runId);
        $this->snapshot(
            (new RunStateMachine)->path($runId),
            RunPaths::evidenceStateSnapshotPath($runId),
        );
        $this->snapshot(
            RunPaths::eventsPath($runId),
            RunPaths::evidenceEventsSnapshotPath($runId),
        );
        $smokeSource = RunPaths::root().'/benchmarks/'.$plan->data['suite_id'].'/latest.json';
        if (is_file($smokeSource)) {
            $this->snapshot($smokeSource, RunPaths::smokeSnapshotPath($runId));
        }

        $artifacts = [];
        $referenced = [];
        foreach (RunReceipt::loadAll($runId) as $receipt) {
            foreach ($receipt->data['artifacts'] as $artifact) {
                RunPaths::assertRelativePath((string) $artifact['path']);
                $candidate = $runDir.'/'.$artifact['path'];
                $abs = is_file($candidate)
                    ? RunPaths::resolveContained($runDir, (string) $artifact['path'])
                    : $candidate;
                $referenced[] = (string) $artifact['path'];
                $artifacts[$artifact['path']] = is_file($abs)
                    ? ['present' => true, 'sha256' => hash_file('sha256', $abs)]
                    : ['present' => false, 'reason_missing' => 'artifact_file_not_on_disk'];
            }
        }
        $onDiskArtifacts = array_keys($this->hashDirectory(RunPaths::artifactsDir($runId), $runDir));
        $orphanArtifacts = array_values(array_diff($onDiskArtifacts, $referenced));

        $pack = [
            'schema_version' => SchemaContract::EVIDENCE_PACK,
            'run_id' => $runId,
            'plan_hash' => $this->descriptor($planPath, 'plan_json_not_found'),
            'preregistration_hash' => $this->descriptor(
                RunPaths::preregistrationPath($runId),
                'preregistration_not_found',
            ),
            'manifest_hash' => $this->descriptor(
                RunPaths::nativeManifestPath($runId),
                'native_manifest_not_applicable_or_missing',
            ),
            'native_receipts' => $this->hashDirectory(
                RunPaths::nativeReceiptsDir($runId),
                $runDir,
            ),
            'raw_results' => $this->hashDirectory(RunPaths::nativeResultsDir($runId), $runDir),
            'receipts_hash' => $this->descriptor($receiptsPath, 'receipts_jsonl_not_found'),
            'state_hash' => $this->descriptor(
                RunPaths::evidenceStateSnapshotPath($runId),
                'state_snapshot_not_found',
            ),
            'events_hash' => $this->descriptor(
                RunPaths::evidenceEventsSnapshotPath($runId),
                'events_snapshot_not_found',
            ),
            'smoke_receipt_hash' => $this->descriptor(
                RunPaths::smokeSnapshotPath($runId),
                in_array($plan->data['suite_id'], (new SuiteRegistry)->externalSuiteIds(), true)
                    ? 'smoke_receipt_not_found'
                    : 'smoke_not_applicable_internal_suite',
            ),
            'adapter' => $this->adapterEvidence($runId, $plan),
            'artifacts' => $artifacts,
            'orphan_artifacts' => $orphanArtifacts,
            'workspace_fingerprint' => $this->workspaceFingerprint(),
            'built_at' => now()->toIso8601String(),
        ];
        $pack['evidence_hash'] = self::hashPayload($pack);
        $violations = SchemaContract::validate($pack, SchemaContract::EVIDENCE_PACK);
        if ($violations !== []) {
            throw new RuntimeException('rivals_invalid_evidence_pack:'.implode(',', $violations));
        }

        AtomicWriter::write(
            RunPaths::evidencePath($runId),
            json_encode(
                $pack,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
            )
        );

        return $pack;
    }

    private function workspaceFingerprint(): array
    {
        $head = Process::path(base_path())->run('git rev-parse HEAD');
        if (! $head->successful()) {
            return ['present' => false, 'reason_missing' => 'git_unavailable'];
        }
        $dirty = Process::path(base_path())->run('git status --porcelain');
        $branch = Process::path(base_path())->run('git branch --show-current');
        $dirtyLines = array_values(array_filter(explode("\n", trim($dirty->output()))));

        return [
            'present' => true,
            'git_head' => trim($head->output()),
            'branch' => trim($branch->output()),
            'dirty' => $dirtyLines !== [],
            'dirty_count' => count($dirtyLines),
        ];
    }

    /** @return array{present: bool, sha256?: string, size_bytes?: int, reason_missing?: string} */
    private function descriptor(string $path, string $reason): array
    {
        return is_file($path)
            ? [
                'present' => true,
                'sha256' => hash_file('sha256', $path),
                'size_bytes' => filesize($path),
            ]
            : ['present' => false, 'reason_missing' => $reason];
    }

    private function snapshot(string $source, string $destination): void
    {
        if (! is_file($source)) {
            return;
        }
        AtomicWriter::write($destination, (string) file_get_contents($source));
    }

    /** @return array<string, array{present: true, sha256: string, size_bytes: int}> */
    private function hashDirectory(
        string $directory,
        string $relativeTo,
        ?callable $filter = null,
    ): array {
        if (! is_dir($directory)) {
            return [];
        }
        $hashes = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            if ($filter !== null && ! $filter($path)) {
                continue;
            }
            $relative = ltrim(substr($path, strlen(rtrim($relativeTo, '/'))), '/');
            $hashes[$relative] = [
                'present' => true,
                'sha256' => hash_file('sha256', $path),
                'size_bytes' => $file->getSize(),
            ];
        }
        ksort($hashes);

        return $hashes;
    }

    /** @return array<string, mixed> */
    private function adapterEvidence(string $runId, RunPlan $plan): array
    {
        try {
            $manifest = NativeExecutionManifest::load($runId);

            return [
                'class' => $manifest->data['adapter_class'],
                'sha256' => $manifest->data['adapter_hash'],
                'runner_version' => $manifest->data['runner_version'],
                'repo_commit' => $manifest->data['upstream']['repo_commit'] ?? null,
            ];
        } catch (\Throwable) {
            return [
                'class' => null,
                'sha256' => $plan->data['environment']['adapter_hash'] ?? null,
                'runner_version' => null,
                'repo_commit' => $plan->data['environment']['repo_commit'] ?? null,
            ];
        }
    }

    /** @param array<string, mixed> $payload */
    public static function hashPayload(array $payload): string
    {
        unset($payload['evidence_hash']);

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
