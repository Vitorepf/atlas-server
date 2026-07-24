<?php

namespace App\Console\Commands;

use App\Console\Concerns\EmitsCanonicalJson;
use App\Services\Ai\ProgrammingRuntime\Telemetry\ProgrammingRuntimeTelemetryAggregator;
use App\Services\Ai\ProgrammingRuntime\Telemetry\ProgrammingRuntimeTelemetryRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use App\Support\YesNo;

class AtlasAiProgrammingRuntimeTelemetryCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:programming-runtime-telemetry
        {--action=aggregate : aggregate|record}
        {--event-name= : when --action=record, the event name (required)}
        {--flow= : optional flow}
        {--core= : optional selected_core (atlas_dev|atlas_forge|atlas_dev_to_forge)}
        {--provider= : optional provider key for cost estimation}
        {--run-id= : optional run id}
        {--execution-status= : optional execution status}
        {--duration-ms= : optional measured duration}
        {--tokens-in= : optional input token count}
        {--tokens-out= : optional output token count}
        {--total-tokens= : optional total token count}
        {--cost-estimate-usd= : optional measured/estimated USD cost}
        {--since= : optional ISO timestamp lower bound for aggregate}
        {--until= : optional ISO timestamp upper bound for aggregate}
        {--json : output JSON only}';

    protected $description = 'Programming Runtime telemetry — record an internal event or emit the aggregate read model. Never runs a benchmark.';

    public function handle(
        ProgrammingRuntimeTelemetryAggregator $aggregator,
        ProgrammingRuntimeTelemetryRecorder $recorder,
    ): int {
        $action = (string) $this->option('action');

        return match ($action) {
            'aggregate' => $this->handleAggregate($aggregator),
            'record' => $this->handleRecord($recorder),
            default => $this->failWith('invalid action ['.$action.']; supported: aggregate, record'),
        };
    }

    private function handleAggregate(ProgrammingRuntimeTelemetryAggregator $aggregator): int
    {
        $since = $this->parseTimestamp($this->option('since'));
        $until = $this->parseTimestamp($this->option('until'));
        $report = $aggregator->aggregate($since, $until);

        if ($this->option('json')) {
            $this->line($this->encodeOrEmptyObject($report));
        } else {
            $this->renderHuman($report);
        }

        return Command::SUCCESS;
    }

    private function handleRecord(ProgrammingRuntimeTelemetryRecorder $recorder): int
    {
        $eventName = (string) ($this->option('event-name') ?? '');
        if ($eventName === '') {
            $this->error('record requires --event-name=<name>');

            return Command::FAILURE;
        }

        try {
            $event = $recorder->record([
                'event_name' => $eventName,
                'flow' => $this->option('flow'),
                'selected_core' => $this->option('core'),
                'provider' => $this->option('provider'),
                'run_id' => $this->option('run-id'),
                'execution_status' => $this->option('execution-status'),
                'duration_ms' => $this->option('duration-ms') !== null ? (int) $this->option('duration-ms') : null,
                'tokens_in' => $this->option('tokens-in') !== null ? (int) $this->option('tokens-in') : null,
                'tokens_out' => $this->option('tokens-out') !== null ? (int) $this->option('tokens-out') : null,
                'total_tokens' => $this->option('total-tokens') !== null ? (int) $this->option('total-tokens') : null,
                'cost_estimate_usd' => $this->option('cost-estimate-usd') !== null ? (float) $this->option('cost-estimate-usd') : null,
                'metadata' => ['source' => 'cli:atlas:ai:programming-runtime-telemetry'],
            ]);
        } catch (\Throwable $exception) {
            $this->error('record failed: '.$exception->getMessage());

            return Command::FAILURE;
        }

        $payload = [
            'schema_version' => 'atlas.programming.runtime_telemetry.command.v1',
            'action' => 'record',
            'recorded' => $event !== null,
            'reason' => $event === null ? 'telemetry_table_missing_no_op' : null,
            'event' => $event === null ? null : [
                'id' => $event->id,
                'event_name' => $event->event_name,
                'event_hash' => $event->event_hash,
                'flow' => $event->flow,
                'selected_core' => $event->selected_core,
                'cost_estimate_usd' => $event->cost_estimate_usd,
            ],
            'benchmark_not_run' => true,
        ];
        $this->line($this->encodeOrEmptyObject($payload));

        return Command::SUCCESS;
    }

    private function failWith(string $message): int
    {
        $this->error($message);

        return Command::FAILURE;
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function renderHuman(array $report): void
    {
        $this->line(sprintf(
            '[programming-runtime-telemetry] schema=%s generated_at=%s total=%d',
            (string) $report['schema_version'],
            (string) $report['generated_at'],
            (int) $report['total_events'],
        ));
        $this->line(sprintf(
            'benchmark_not_run=%s rivals_compared=%s',
            YesNo::trueFalse($report['claim_policy']['benchmark_not_run'] ?? true),
            YesNo::trueFalse($report['claim_policy']['rivals_compared'] ?? false),
        ));
        if (isset($report['reason'])) {
            $this->line('reason: '.(string) $report['reason']);
        }
        foreach (['by_flow', 'by_core', 'by_execution_status', 'by_blocker_bucket'] as $bucket) {
            $entries = (array) ($report[$bucket] ?? []);
            if ($entries === []) {
                continue;
            }
            $this->line($bucket.':');
            foreach ($entries as $key => $count) {
                $this->line(sprintf('  - %s: %d', (string) $key, (int) $count));
            }
        }
    }

    private function parseTimestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

}
