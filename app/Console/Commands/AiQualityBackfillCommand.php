<?php

namespace App\Console\Commands;

use App\Models\AiTrace;
use App\Services\Ai\Analysis\AiQualityEvaluator;
use App\Services\Ai\Telemetry\AiTraceMetricAggregator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Throwable;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Backfills missing AiQualityEvaluation rows for historical AiTraces.
 *
 * Why this exists
 * ---------------
 * The worker (AiWorker) calls AiQualityEvaluator::evaluateTrace at job completion,
 * but several real-world conditions historically left traces without an evaluation:
 *   1. Traces from before the evaluator was added.
 *   2. Failure paths that, until the Fix 6 patch, only ran completeRemediationActions.
 *   3. Future bug-fix windows where the worker was patched but historical rows already
 *      missed evaluation.
 *
 * Without backfill, AiTraceMetricAggregator's autoQualityScore() falls back to its
 * static scoring (succeeded → 72, failed/cancelled → 20) for those rows, which
 * silently distorts dashboard averages.
 *
 * Behavior
 * --------
 * - Selects traces whose status is terminal and whose response_text is non-empty
 *   (matching the evaluator's internal gate at AiQualityEvaluator:27).
 * - Skips traces that already have an evaluation (idempotent — safe to re-run).
 * - --dry-run reports counts without writing.
 * - --recompute optionally re-aggregates the trace metric summary after evaluation
 *   so the new score propagates to the scorecard immediately.
 */
class AiQualityBackfillCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:quality:backfill
        {--since= : ISO date (YYYY-MM-DD); defaults to --hours window}
        {--hours=720 : Window in hours when --since is not given (default 30 days, max 8760 = 1y)}
        {--limit=500 : Max traces to process this run}
        {--dry-run : Count candidates without writing any evaluation}
        {--recompute : Also call AiTraceMetricAggregator::recomputeTrace after each evaluation}
        {--json : Machine-readable output}';

    protected $description = 'Backfill heuristic quality evaluations for terminal traces missing one.';

    public function handle(AiQualityEvaluator $evaluator, AiTraceMetricAggregator $aggregator): int
    {
        if (! DatabaseTableAvailability::has('ai_quality_evaluations') || ! DatabaseTableAvailability::has('ai_traces')) {
            $this->error('Required tables missing. Run php artisan migrate.');

            return self::FAILURE;
        }

        $since = $this->resolveSince();
        $limit = max(1, min(10_000, (int) $this->option('limit')));
        $dryRun = (bool) $this->option('dry-run');
        $recompute = (bool) $this->option('recompute');

        $candidatesQuery = AiTrace::query()
            ->whereIn('status', ['succeeded', 'failed', 'cancelled'])
            ->where('created_at', '>=', $since)
            ->whereNotNull('response_text')
            ->where('response_text', '!=', '')
            ->whereDoesntHave('qualityEvaluation')
            ->orderBy('created_at')
            ->limit($limit);

        $totalCandidates = (clone $candidatesQuery)->count();

        $evaluated = 0;
        $skippedByEvaluator = 0;
        $recomputed = 0;
        $errors = 0;
        $errorSamples = [];

        if (! $dryRun) {
            foreach ($candidatesQuery->cursor() as $trace) {
                try {
                    $evaluation = $evaluator->evaluateTrace($trace);
                    if ($evaluation === null) {
                        // Evaluator's internal gate decided to skip (e.g. blank response after trim).
                        $skippedByEvaluator++;

                        continue;
                    }

                    $evaluated++;

                    if ($recompute && DatabaseTableAvailability::has('ai_trace_metric_summaries')) {
                        $aggregator->recomputeTrace($trace->id);
                        $recomputed++;
                    }
                } catch (Throwable $exception) {
                    $errors++;
                    if (count($errorSamples) < 5) {
                        $errorSamples[] = [
                            'trace_id' => $trace->id,
                            'error' => $exception->getMessage(),
                        ];
                    }
                    report($exception);
                }
            }
        }

        $payload = [
            'ok' => true,
            'dry_run' => $dryRun,
            'since' => $since->toIso8601String(),
            'limit' => $limit,
            'candidates' => $totalCandidates,
            'evaluated' => $evaluated,
            'skipped_by_evaluator' => $skippedByEvaluator,
            'recomputed' => $recomputed,
            'errors' => $errors,
            'error_samples' => $errorSamples,
        ];

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return $errors === 0 ? self::SUCCESS : self::FAILURE;
        }

        if ($dryRun) {
            $this->info("DRY RUN — {$totalCandidates} trace(s) would be evaluated since {$since->toDateString()}.");
        } else {
            $this->info("Backfilled quality evaluations: {$evaluated}/{$totalCandidates} since {$since->toDateString()}.");
            if ($skippedByEvaluator > 0) {
                $this->line("Skipped (evaluator gate): {$skippedByEvaluator}");
            }
            if ($recomputed > 0) {
                $this->line("Trace metric summaries recomputed: {$recomputed}");
            }
            if ($errors > 0) {
                $this->warn("Errors encountered: {$errors}. See logs.");
            }
        }

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function resolveSince(): CarbonImmutable
    {
        $sinceOption = $this->option('since');
        if (is_string($sinceOption) && trim($sinceOption) !== '') {
            try {
                return CarbonImmutable::parse(trim($sinceOption));
            } catch (Throwable) {
                $this->warn("--since '{$sinceOption}' is not a valid date; falling back to --hours window.");
            }
        }

        $hours = max(1, min(8760, (int) $this->option('hours')));

        return CarbonImmutable::now()->subHours($hours);
    }
}
