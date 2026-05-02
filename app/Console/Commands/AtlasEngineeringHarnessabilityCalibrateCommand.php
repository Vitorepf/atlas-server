<?php

namespace App\Console\Commands;

use App\Services\Engineering\EngineeringHarnessabilityService;
use Illuminate\Console\Command;

class AtlasEngineeringHarnessabilityCalibrateCommand extends Command
{
    protected $signature = 'atlas:engineering:harnessability:calibrate
        {--limit=300 : Number of recent engineering runs to analyze}
        {--json : Print machine-readable JSON}';

    protected $description = 'Calibrate Atlas Engineering harnessability autonomy thresholds from historical runs and outcomes.';

    public function handle(EngineeringHarnessabilityService $harnessability): int
    {
        $calibration = $harnessability->calibrate([
            'limit' => (int) $this->option('limit'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line(json_encode(['harnessability_calibration' => $calibration], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Harnessability Calibration</>', (string) ($calibration['confidence'] ?? 'unknown'));
        $this->components->twoColumnDetail('Sample', (string) ($calibration['sample_count'] ?? 0));
        $this->components->twoColumnDetail('Policy source', (string) data_get($calibration, 'recommended_thresholds.policy_source', 'static_default'));
        $this->components->twoColumnDetail('Worktree below', (string) data_get($calibration, 'recommended_thresholds.require_worktree_below_score', '-'));
        $this->components->twoColumnDetail('Danger min score', (string) data_get($calibration, 'recommended_thresholds.danger_permission_min_score', '-'));

        $recommendations = (array) ($calibration['recommendations'] ?? []);
        if ($recommendations !== []) {
            $this->newLine();
            $this->line('<fg=yellow>Recomendacoes</>');
            foreach ($recommendations as $recommendation) {
                $this->line('  - '.(string) $recommendation);
            }
        }

        return self::SUCCESS;
    }
}
