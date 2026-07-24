<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAdaptiveBatchSizeGovernor;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAmbitionBudgetGovernor;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainQueuePressureGovernor;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainTaskGraphQualityRunner;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainWorkerDrainRateForecaster;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Read-only operator surface: atlas:external-brain:queue-pressure
 *
 * Combines three pure pressure/throughput organs into one control surface so
 * origination volume follows real drain telemetry and worker-starvation risk
 * instead of a fixed batch habit:
 *
 *   - {@see AtlasExternalBrainWorkerDrainRateForecaster}   — how fast muscles actually consume the queue
 *   - {@see AtlasExternalBrainAdaptiveBatchSizeGovernor}   — how big the next origination batch should be
 *   - {@see AtlasExternalBrainQueuePressureGovernor}       — worker-floor pressure signal
 *
 * Pure composition: no enqueue, no queue reads, no provider calls. All facts
 * are supplied via CLI options; the command only wires the three organs.
 *
 * Output (always JSON):
 *   schema, drain_forecast, adaptive_batch, worker_floor, recommended_batch_size
 */
final class AtlasExternalBrainQueuePressureCommand extends Command
{
    use EmitsCanonicalJson;

    private const SCHEMA = 'atlas.external_brain.queue_pressure.v1';

    /** @var string */
    protected $signature = 'atlas:external-brain:queue-pressure
        {--queue-depth=0 : Current claimable queue depth}
        {--active-leases=0 : Active worker leases}
        {--recent-successes=0 : Recent proven successes}
        {--recent-give-backs=0 : Recent give_back outcomes}
        {--median-task-minutes=0 : Median minutes per task}
        {--claimed-records=0 : Currently claimed queue records}
        {--servable-now=0 : Currently servable task count}
        {--theme-saturation=0 : Theme saturation ratio 0..1}
        {--candidate-value-score=0 : Candidate value score 0..1}
        {--high-priority-gap-count=0 : Count of high-priority gaps needing fill}
        {--claimable-per-active-worker= : Claimable depth per active worker}
        {--malformed-count=0 : Malformed packet count}
        {--queue-health=1.0 : Queue health ratio 0..1 (fed to the ambition budget governor)}
        {--stale-backlog-ratio=0 : Stale backlog ratio 0..1}
        {--structural-leverage=0 : Structural leverage score 0..1}
        {--backlog-pressure=0 : Backlog pressure ratio 0..1}
        {--ambition-budget-total=100 : Total ambition budget for this cycle}
        {--quality-runner-input= : JSON-encoded input for TaskGraphQualityRunner (task pruning, attribution, mutation test, counterfactual review)}
        {--json : Emit JSON output (always on)}';

    /** @var string */
    protected $description = 'Read-only: combine drain forecast, adaptive batch size, and worker-floor pressure into one control surface.';

    public function handle(): int
    {
        $forecaster = new AtlasExternalBrainWorkerDrainRateForecaster;
        $batchGovernor = new AtlasExternalBrainAdaptiveBatchSizeGovernor;
        $pressureGovernor = new AtlasExternalBrainQueuePressureGovernor;
        $ambitionBudgetGovernor = new AtlasExternalBrainAmbitionBudgetGovernor;

        $activeLeases = (int) $this->option('active-leases');
        $queueDepth = (int) $this->option('queue-depth');

        $drainForecast = $forecaster->forecast([
            'active_leases' => $activeLeases,
            'recent_successes' => (int) $this->option('recent-successes'),
            'recent_give_backs' => (int) $this->option('recent-give-backs'),
            'median_task_minutes' => (float) $this->option('median-task-minutes'),
            'queue_depth' => $queueDepth,
            'claimed_records' => (int) $this->option('claimed-records'),
        ]);

        $adaptiveBatch = $batchGovernor->govern([
            'queue_depth' => $queueDepth,
            'servable_now' => (int) $this->option('servable-now'),
            'active_workers' => max(1, $activeLeases),
            'drain_rate_per_hour' => (float) $drainForecast['estimated_drain_per_hour'],
            'theme_saturation' => (float) $this->option('theme-saturation'),
            'candidate_value_score' => (float) $this->option('candidate-value-score'),
            'high_priority_gap_count' => (int) $this->option('high-priority-gap-count'),
        ]);

        $claimablePerActiveWorkerOption = $this->option('claimable-per-active-worker');
        $workerFloor = $pressureGovernor->evaluateWorkerFloor([
            'malformed_count' => (int) $this->option('malformed-count'),
            'active_leases' => $activeLeases,
            'claimable_per_active_worker' => $claimablePerActiveWorkerOption !== null
                ? (float) $claimablePerActiveWorkerOption
                : null,
        ]);

        $ambitionBudget = $ambitionBudgetGovernor->governQueueAmbition([
            'queue_health' => (float) $this->option('queue-health'),
            'stale_backlog_ratio' => (float) $this->option('stale-backlog-ratio'),
            'structural_leverage' => (float) $this->option('structural-leverage'),
            'backlog_pressure' => (float) $this->option('backlog-pressure'),
            'budget_total' => (int) $this->option('ambition-budget-total'),
        ]);

        $recommendedBatchSize = $workerFloor['action'] === AtlasExternalBrainQueuePressureGovernor::ACTION_REQUEST_BOUNDED_BATCH
            ? max((int) $adaptiveBatch['recommended_batch_size'], (int) $workerFloor['max_tasks'])
            : (int) $adaptiveBatch['recommended_batch_size'];

        if ($recommendedBatchSize === 0 && $drainForecast['recommended_originator_pace'] === 'increase_origination') {
            $recommendedBatchSize = 2;
        }

        // Task-graph quality runner (optional, invoked only when --quality-runner-input is given)
        $qualityRunner = null;
        $qualityRunnerInput = (string) $this->option('quality-runner-input');
        if ($qualityRunnerInput !== '') {
            $decoded = json_decode($qualityRunnerInput, true);
            if (is_array($decoded)) {
                $qualityRunner = (new AtlasExternalBrainTaskGraphQualityRunner)->run($decoded);
            }
        }

        $payload = [
            'schema' => self::SCHEMA,
            'drain_forecast' => $drainForecast,
            'adaptive_batch' => $adaptiveBatch,
            'worker_floor' => $workerFloor,
            'ambition_budget' => $ambitionBudget,
            'quality_runner' => $qualityRunner,
            // Worker-floor pressure and the drain-informed adaptive batch both feed the final
            // recommendation; a starvation signal from either organ wins over a habitual batch.
            'recommended_batch_size' => $recommendedBatchSize,
        ];

        $this->line($this->encode($payload));

        return self::SUCCESS;
    }
}
