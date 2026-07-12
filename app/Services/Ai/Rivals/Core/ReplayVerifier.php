<?php

namespace App\Services\Ai\Rivals\Core;

use App\Services\Ai\Rivals\Support\RunPaths;
use App\Services\Ai\Rivals\Support\SchemaContract;

/**
 * Re-verificação fail-closed: recomputa todos os hashes do evidence pack
 * contra o disco. Qualquer drift (1 byte) = verified=false com failure nomeada.
 */
class ReplayVerifier
{
    /** @return array{verified: bool, failures: list<string>} */
    public function verify(string $runId): array
    {
        $failures = [];
        $packPath = RunPaths::evidencePath($runId);
        if (! is_file($packPath)) {
            return ['verified' => false, 'failures' => ['evidence_pack_not_found']];
        }
        $pack = json_decode(file_get_contents($packPath), true) ?? [];
        if (($pack['schema_version'] ?? null) === SchemaContract::EVIDENCE_PACK_V1) {
            return $this->verifyV1($runId, $pack);
        }
        if (($pack['schema_version'] ?? null) !== SchemaContract::EVIDENCE_PACK) {
            return ['verified' => false, 'failures' => ['evidence_pack_schema_mismatch']];
        }
        $runDir = RunPaths::runDir($runId);
        if (! hash_equals(
            (string) ($pack['evidence_hash'] ?? ''),
            EvidencePackBuilder::hashPayload($pack),
        )) {
            $failures[] = 'evidence_hash_mismatch';
        }

        foreach ([
            'plan_hash' => RunPaths::planPath($runId),
            'receipts_hash' => RunPaths::receiptsPath($runId),
        ] as $field => $path) {
            $this->verifyRequiredDescriptor($pack[$field] ?? null, $path, $field, $failures);
        }
        foreach ([
            'preregistration_hash' => RunPaths::preregistrationPath($runId),
            'manifest_hash' => RunPaths::nativeManifestPath($runId),
            'state_hash' => RunPaths::evidenceStateSnapshotPath($runId),
            'events_hash' => RunPaths::evidenceEventsSnapshotPath($runId),
            'smoke_receipt_hash' => RunPaths::smokeSnapshotPath($runId),
        ] as $field => $path) {
            $this->verifyOptionalDescriptor($pack[$field] ?? null, $path, $field, $failures);
        }

        $this->verifyFileMap($pack['native_receipts'] ?? [], $runDir, 'native_receipt', $failures);
        $this->verifyFileMap($pack['raw_results'] ?? [], $runDir, 'raw_result', $failures);
        foreach ($pack['artifacts'] ?? [] as $relPath => $recorded) {
            try {
                RunPaths::assertRelativePath((string) $relPath);
            } catch (\Throwable) {
                $failures[] = "artifact_path_escape:{$relPath}";

                continue;
            }
            if (($recorded['present'] ?? false) !== true) {
                $failures[] = "artifact_missing_in_pack:{$relPath}";

                continue;
            }
            $abs = $runDir.'/'.$relPath;
            if (! is_file($abs)) {
                $failures[] = "artifact_missing_on_disk:{$relPath}";
            } else {
                try {
                    $abs = RunPaths::resolveContained($runDir, (string) $relPath);
                } catch (\Throwable) {
                    $failures[] = "artifact_path_escape:{$relPath}";

                    continue;
                }
            }
            if (is_file($abs) && hash_file('sha256', $abs) !== $recorded['sha256']) {
                $failures[] = "artifact_hash_mismatch:{$relPath}";
            }
        }
        foreach ($pack['orphan_artifacts'] ?? [] as $orphan) {
            $failures[] = "orphan_artifact:{$orphan}";
        }

        return [
            'verified' => $failures === [],
            'failures' => array_values(array_unique($failures)),
            'evidence_hash' => $pack['evidence_hash'] ?? null,
        ];
    }

    /**
     * Independently reproduce a persisted adjudication's content hash. A drift means the
     * verdict on disk no longer matches its scored content, so the claim built on it is
     * not replayable.
     *
     * @return array{verified: bool, failures: list<string>}
     */
    public function verifyAdjudication(string $runId): array
    {
        $path = RunPaths::adjudicationPath($runId);
        if (! is_file($path)) {
            return ['verified' => false, 'failures' => ['adjudication_not_found']];
        }
        $adjudication = json_decode((string) file_get_contents($path), true) ?? [];
        $recorded = (string) ($adjudication['adjudication_hash'] ?? '');
        if ($recorded === '') {
            return ['verified' => false, 'failures' => ['adjudication_hash_missing']];
        }
        if (! hash_equals($recorded, Adjudicator::hashAdjudication($adjudication))) {
            return ['verified' => false, 'failures' => ['adjudication_hash_mismatch']];
        }

        return ['verified' => true, 'failures' => []];
    }

    /** @param array<string, mixed> $pack */
    private function verifyV1(string $runId, array $pack): array
    {
        $failures = [];
        $runDir = RunPaths::runDir($runId);
        foreach ([
            'plan_hash' => RunPaths::planPath($runId),
            'receipts_hash' => RunPaths::receiptsPath($runId),
        ] as $field => $path) {
            $this->verifyRequiredDescriptor($pack[$field] ?? null, $path, $field, $failures);
        }
        foreach ($pack['artifacts'] ?? [] as $relPath => $recorded) {
            $this->verifyRequiredDescriptor($recorded, $runDir.'/'.$relPath, 'artifact:'.$relPath, $failures);
        }

        return ['verified' => $failures === [], 'failures' => $failures, 'legacy_v1' => true];
    }

    private function verifyRequiredDescriptor(
        mixed $recorded,
        string $path,
        string $field,
        array &$failures,
    ): void {
        if (! is_array($recorded) || ($recorded['present'] ?? false) !== true) {
            $failures[] = "{$field}_missing_in_pack";
        } elseif (! is_file($path)) {
            $failures[] = "{$field}_file_missing_on_disk";
        } elseif (hash_file('sha256', $path) !== ($recorded['sha256'] ?? null)) {
            $failures[] = "{$field}_hash_mismatch";
        }
    }

    private function verifyOptionalDescriptor(
        mixed $recorded,
        string $path,
        string $field,
        array &$failures,
    ): void {
        if (! is_array($recorded)) {
            $failures[] = "{$field}_descriptor_missing";

            return;
        }
        if (($recorded['present'] ?? false) !== true) {
            return;
        }
        if (! is_file($path)) {
            $failures[] = "{$field}_file_missing_on_disk";
        } elseif (hash_file('sha256', $path) !== ($recorded['sha256'] ?? null)) {
            $failures[] = "{$field}_hash_mismatch";
        }
    }

    /** @param array<string, mixed> $map */
    private function verifyFileMap(
        array $map,
        string $runDir,
        string $prefix,
        array &$failures,
    ): void {
        foreach ($map as $relative => $recorded) {
            try {
                $path = RunPaths::resolveContained($runDir, (string) $relative);
            } catch (\Throwable) {
                $failures[] = "{$prefix}_path_escape:{$relative}";

                continue;
            }
            $this->verifyRequiredDescriptor(
                $recorded,
                $path,
                $prefix.':'.$relative,
                $failures,
            );
        }
    }
}
