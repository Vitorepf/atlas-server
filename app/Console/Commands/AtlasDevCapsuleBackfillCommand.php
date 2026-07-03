<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AtlasDevFailureCapsule;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevFailureCapsuleRuntimeService;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevTaskPacketRuntimeService;
use App\Services\Ai\Programming\AtlasDev\Support\WorkspaceOriginIdentity;
use Illuminate\Console\Command;

/**
 * Idempotent re-hydration of the failure-capsule learning table from the real
 * `failure_capsule.*.json` receipts of failed runs. The receipts store is the
 * durable source of truth; the DB table is a read model that the 02/07 wiper
 * incidents destroyed three times — this command makes recovery one call
 * (updateOrCreate on failure_hash: safe to run over a populated table).
 */
class AtlasDevCapsuleBackfillCommand extends Command
{
    protected $signature = 'atlas:dev:capsule-backfill {--json : Print machine-readable JSON}';

    protected $description = 'Re-hydrate atlas_dev_failure_capsules from the real failure_capsule receipts (idempotent).';

    public function handle(DevFailureCapsuleRuntimeService $capsules, DevTaskPacketRuntimeService $packets): int
    {
        $backfilled = 0;
        $skipped = 0;
        $slug = WorkspaceOriginIdentity::slug(base_path());

        foreach (glob(storage_path('atlas-dev/receipts/*/failure_capsule.*.json')) ?: [] as $file) {
            $capsule = json_decode((string) file_get_contents($file), true);
            if (! is_array($capsule)) {
                $skipped++;

                continue;
            }
            $runId = basename(dirname($file));
            try {
                $packet = $packets->persist([
                    'run_id' => $runId,
                    'task_id' => 'backfill-'.$runId,
                    'objective' => (string) ($capsule['objective'] ?? 'backfilled failure capsule'),
                    'workspace_slug' => $slug,
                    'allowed_files' => (array) ($capsule['changed_files'] ?? []),
                    'source' => 'capsule_backfill',
                ]);
                $capsules->persist([
                    'run_id' => $runId,
                    'task_id' => 'backfill-'.$runId,
                    'failing_gate' => (string) ($capsule['gate'] ?? $capsule['failing_gate'] ?? 'unknown'),
                    'error_excerpt' => (string) ($capsule['primary_error_excerpt'] ?? $capsule['error_excerpt'] ?? ''),
                    'changed_files' => (array) ($capsule['changed_files'] ?? []),
                ], $packet);
                $backfilled++;
            } catch (\Throwable) {
                $skipped++;
            }
        }

        $summary = [
            'backfilled' => $backfilled,
            'skipped' => $skipped,
            'rows' => AtlasDevFailureCapsule::query()->count(),
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->line('  capsule backfill: backfilled='.$backfilled.'  skipped='.$skipped.'  rows='.$summary['rows']);

        return self::SUCCESS;
    }
}
