<?php

namespace App\Services\Ai\Rivals\Core;

use App\Services\Ai\Rivals\Support\RunPaths;

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
        $runDir = RunPaths::runDir($runId);

        foreach (['plan_hash' => RunPaths::planPath($runId), 'receipts_hash' => RunPaths::receiptsPath($runId)] as $field => $path) {
            $recorded = $pack[$field] ?? null;
            if (! is_array($recorded) || ($recorded['present'] ?? false) !== true) {
                $failures[] = "{$field}_missing_in_pack";
            } elseif (! is_file($path)) {
                $failures[] = "{$field}_file_missing_on_disk";
            } elseif (hash_file('sha256', $path) !== $recorded['sha256']) {
                $failures[] = "{$field}_hash_mismatch";
            }
        }

        foreach ($pack['artifacts'] ?? [] as $relPath => $recorded) {
            if (($recorded['present'] ?? false) !== true) {
                $failures[] = "artifact_missing_in_pack:{$relPath}";
                continue;
            }
            $abs = $runDir.'/'.$relPath;
            if (! is_file($abs)) {
                $failures[] = "artifact_missing_on_disk:{$relPath}";
            } elseif (hash_file('sha256', $abs) !== $recorded['sha256']) {
                $failures[] = "artifact_hash_mismatch:{$relPath}";
            }
        }

        return ['verified' => $failures === [], 'failures' => $failures];
    }
}
