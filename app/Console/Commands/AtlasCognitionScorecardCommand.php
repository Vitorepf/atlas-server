<?php

namespace App\Console\Commands;

use App\Services\Ai\Cognition\AtlasCognitionScoreCardService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Cognition Operating System — runtime scorecard CLI.
 *
 *   php artisan atlas:cognition:scorecard
 *   php artisan atlas:cognition:scorecard --json
 *   php artisan atlas:cognition:scorecard --strict --json
 *
 * Exit codes:
 *   0 — scorecard built (and overall>=10 when --strict)
 *   1 — runtime exception
 *   3 — --strict and overall < 10.0
 */
class AtlasCognitionScorecardCommand extends Command
{
    protected $signature = 'atlas:cognition:scorecard
        {--strict : Exit 3 when overall < 10.0}
        {--json : Machine-readable JSON output}';

    protected $description = 'Atlas Cognition Operating System (ACOS) scorecard · 73 subsistemas em 3 dimensões estruturais: code (service existe) + doc (canon resolvido por ownership FQN-bound) + pipeline (provado por green-run receipt real). Volume orgânico = uso do operador, fora do escopo do scorecard.';

    public function handle(AtlasCognitionScoreCardService $service): int
    {
        try {
            $report = $service->build();
        } catch (Throwable $e) {
            $this->error('[atlas:cognition:scorecard] '.$e->getMessage());

            return 1;
        }

        $overall = (float) ($report['score']['overall_out_of_10'] ?? 0);
        $exitCode = 0;
        if ($this->option('strict') && $overall < 10.0) {
            $exitCode = 3;
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'ok' => $overall >= 10.0,
                'action' => 'cognition-scorecard',
                'strict' => (bool) $this->option('strict'),
                'report' => $report,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $exitCode;
        }

        $this->line('[atlas:cognition:scorecard] schema='.$report['schema_version']);
        $this->line('subsystems='.$report['subsystem_count'].'  overall='.$overall.'/10');
        foreach ($report['score']['dimensions'] as $dim => $info) {
            $this->line(sprintf('  %-9s %s/10  (%d/%d)', $dim, $info['score_out_of_10'], $info['sum'], $info['max']));
        }
        $this->line('hash='.$report['scorecard_hash']);

        return $exitCode;
    }
}
