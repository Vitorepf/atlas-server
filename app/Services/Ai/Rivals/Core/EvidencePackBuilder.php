<?php

namespace App\Services\Ai\Rivals\Core;

use App\Services\Ai\Rivals\Support\RunPaths;
use App\Services\Ai\Rivals\Support\SchemaContract;
use Illuminate\Support\Facades\Process;

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

        $artifacts = [];
        foreach (RunReceipt::loadAll($runId) as $receipt) {
            foreach ($receipt->data['artifacts'] as $artifact) {
                $abs = $runDir.'/'.$artifact['path'];
                $artifacts[$artifact['path']] = is_file($abs)
                    ? ['present' => true, 'sha256' => hash_file('sha256', $abs)]
                    : ['present' => false, 'reason_missing' => 'artifact_file_not_on_disk'];
            }
        }

        $pack = [
            'schema_version' => SchemaContract::EVIDENCE_PACK,
            'run_id' => $runId,
            'plan_hash' => is_file($planPath)
                ? ['present' => true, 'sha256' => hash_file('sha256', $planPath)]
                : ['present' => false, 'reason_missing' => 'plan_json_not_found'],
            'receipts_hash' => is_file($receiptsPath)
                ? ['present' => true, 'sha256' => hash_file('sha256', $receiptsPath)]
                : ['present' => false, 'reason_missing' => 'receipts_jsonl_not_found'],
            'artifacts' => $artifacts,
            'workspace_fingerprint' => $this->workspaceFingerprint(),
            'built_at' => now()->toIso8601String(),
        ];

        file_put_contents(
            RunPaths::evidencePath($runId),
            json_encode($pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
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

        return [
            'present' => true,
            'git_head' => trim($head->output()),
            'dirty' => trim($dirty->output()) !== '',
        ];
    }
}
