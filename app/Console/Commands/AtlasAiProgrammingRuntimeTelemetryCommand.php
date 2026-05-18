<?php

namespace App\Console\Commands;

use App\Services\Ai\ProgrammingRuntime\Telemetry\ProgrammingRuntimeTelemetryAggregator;
use App\Services\Ai\ProgrammingRuntime\Telemetry\ProgrammingRuntimeTelemetryRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class AtlasAiProgrammingRuntimeTelemetryCommand extends Command
{
    protected $signature = 'atlas:ai:programming-runtime-telemetry
        {--action=aggregate : aggregate|record}
        {--event-name= : when --action=record, the event name (required)}
        {--flow= : optional flow}
        {--core= : optional selected_core (atlas_dev|atlas_forge|atlas_dev_to_forge)}
        {--execution-status= : optional execution status}
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
            $this->line($this->encode($report));
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
                'execution_status' => $this->option('execution-status'),
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
            ],
            'benchmark_not_run' => true,
        ];
        $this->line($this->encode($payload));

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
            ($report['claim_policy']['benchmark_not_run'] ?? true) ? 'true' : 'false',
            ($report['claim_policy']['rivals_compared'] ?? false) ? 'true' : 'false',
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

    /**
     * @param  array<string,mixed>  $payload
     */
    private function encode(array $payload): string
    {
        $encoded = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return is_string($encoded) ? $encoded : '{}';
    }
}
