<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerClaimExecuteReportCycle;
use Illuminate\Console\Command;

/**
 * Arms the dormant orphan {@see AtlasNativeWorkerClaimExecuteReportCycle::run()} at the operator surface:
 * previews the native-worker claim → execute → report cycle (the planned steps) WITHOUT claiming or executing.
 *
 * Strictly preview/read-only: the command FORCES dry_run=true regardless of supplied options, so no task is
 * claimed, no patch is materialized, no command runs, and nothing is reported or mutated.
 */
final class AtlasLoopClaimCyclePreviewCommand extends Command
{
    protected $signature = 'atlas:loop:claim-cycle-preview {--options=} {--json}';

    protected $description = 'Read-only preview of the native-worker claim-execute-report cycle (dry-run forced).';

    public function handle(): int
    {
        $options = [];
        $raw = trim((string) $this->option('options'));
        if ($raw !== '') {
            if (is_file($raw) && is_readable($raw)) {
                $raw = (string) file_get_contents($raw);
            }
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                $this->line((string) json_encode([
                    'outcome' => 'refused',
                    'reason' => 'usage_error',
                    'message' => '--options must be a JSON object',
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

                return self::FAILURE;
            }
            $options = $decoded;
        }

        // PREVIEW ONLY: force dry-run and strip any callbacks so nothing is claimed/executed/reported.
        $options['dry_run'] = true;
        unset($options['claim_callback'], $options['report_callback'], $options['patch_materializer']);

        $plan = app(AtlasNativeWorkerClaimExecuteReportCycle::class)->run($options);

        if ($this->option('json')) {
            $this->line((string) json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('status: '.$plan['status'].'  dry_run: '.($plan['dry_run'] ? 'yes' : 'no').'  applied_steps: '.count($plan['applied_steps']));
        }

        return self::SUCCESS;
    }
}
