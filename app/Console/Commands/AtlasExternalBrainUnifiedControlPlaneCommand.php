<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainUnifiedControlPlaneSnapshot;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Read-only unified control-plane snapshot for the external-brain pipeline.
 *
 * Composes live queue health, worker outcomes, maturity gaps and amplifier
 * status (all via CLI options — this command never queries the DB, never
 * calls a provider, and never mutates the queue) into one honest stop/go
 * snapshot for origination.
 *
 * Fail-closed: the process exit code is non-zero unless the composed
 * verdict is exactly "go" — a stale-evidence or weak-integration-coverage
 * yellow snapshot never reads as a passing exit code, even though the JSON
 * payload still reports the full snapshot for inspection.
 */
final class AtlasExternalBrainUnifiedControlPlaneCommand extends Command
{
    use EmitsCanonicalJson;

    /** @var string */
    protected $signature = 'atlas:external-brain:unified-control-plane
        {--queue-pressure= : low|high (default low)}
        {--simplification-pressure= : low|high (default low)}
        {--model-amplifier-status= : healthy|watch|rollback_candidate (default healthy)}
        {--maturity-gap-count= : integer count of open maturity gaps (default 0)}
        {--worker-success-rate= : 0.0-1.0 (default 1.0)}
        {--give-back-rate= : 0.0-1.0 (default 0.0)}
        {--malformed-rate= : 0.0-1.0 (default 0.0)}
        {--task-value-degrading : mark task value as degrading}
        {--muscle-outcomes-degrading : mark muscle outcomes as degrading}
        {--evidence-age-hours= : hours since evidence was last refreshed (default 0)}
        {--integration-coverage-percent= : 0-100 (default 100)}
        {--final-readiness-percent= : 0-100 (default 100)}
    ';

    /** @var string */
    protected $description = 'Read-only external-brain unified control-plane snapshot (fail-closed stop/go).';

    public function handle(AtlasExternalBrainUnifiedControlPlaneSnapshot $snapshot): int
    {
        $snap = $snapshot->compose($this->buildInputs());

        $this->line($this->encode($snap));

        return $snap['stop_go_verdict'] === AtlasExternalBrainUnifiedControlPlaneSnapshot::VERDICT_GO
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildInputs(): array
    {
        $input = [];

        foreach ([
            'queue-pressure' => 'queue_pressure',
            'simplification-pressure' => 'simplification_pressure',
            'model-amplifier-status' => 'model_amplifier_status',
        ] as $option => $key) {
            $value = $this->option($option);
            if ($value !== null) {
                $input[$key] = (string) $value;
            }
        }

        foreach ([
            'maturity-gap-count' => 'maturity_gap_count',
            'worker-success-rate' => 'worker_success_rate',
            'give-back-rate' => 'give_back_rate',
            'malformed-rate' => 'malformed_rate',
            'evidence-age-hours' => 'evidence_age_hours',
            'integration-coverage-percent' => 'integration_coverage_percent',
            'final-readiness-percent' => 'final_readiness_percent',
        ] as $option => $key) {
            $value = $this->option($option);
            if ($value !== null) {
                $input[$key] = is_numeric($value) ? (float) $value : $value;
            }
        }

        $input['task_value_degrading'] = (bool) $this->option('task-value-degrading');
        $input['muscle_outcomes_degrading'] = (bool) $this->option('muscle-outcomes-degrading');

        return $input;
    }
}
