<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentValidationGateResultRepository;
use App\Services\Ai\SelfConstruction\Maestro\Projection\AtlasMaestroWorkloadConsumptionRateReporter;
use App\Services\Ai\SelfConstruction\Maestro\Projection\AtlasMaestroWorkloadProjectionFactEmitter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class AtlasTaskMaestroProjectionCommand extends Command
{
    protected $signature = 'atlas:task:maestro-projection {verb : rate|empty|history} {--window=3600} {--json}';

    protected $description = 'Emit FACT-only Maestro workload projections and history.';

    private const DISABLED_SCHEMA = 'atlas.maestro.projection.disabled.v1';

    private const HISTORY_SCHEMA = 'atlas.maestro.projection.history.v1';

    private const HISTORY_PATH = 'app/atlas/self-construction/maestro/projection/history.jsonl';

    public function handle(): int
    {
        if (! AtlasLoopMasterSwitch::enabled()) {
            return $this->emitPayload([
                'schema' => self::DISABLED_SCHEMA,
                'status' => 'disabled',
            ], self::SUCCESS);
        }

        $verb = (string) $this->argument('verb');
        $now = CarbonImmutable::now('UTC');

        return match ($verb) {
            'rate' => $this->handleRate($now),
            'empty' => $this->handleEmpty($now),
            'history' => $this->handleHistory(),
            default => $this->emitPayload([
                'schema' => self::DISABLED_SCHEMA,
                'status' => 'invalid_verb',
            ], self::FAILURE),
        };
    }

    private function handleRate(CarbonImmutable $now): int
    {
        $payload = $this->consumptionReporter()->report($this->registrySnapshot(), $now, $this->windowSeconds());
        $this->appendHistory($payload);
        $this->recordValidationGateResult('rate', $payload);

        return $this->emitPayload($payload, self::SUCCESS);
    }

    private function handleEmpty(CarbonImmutable $now): int
    {
        $snapshot = $this->registrySnapshot();
        $payload = $this->projectionEmitter()->emit(
            $this->consumptionReporter()->report($snapshot, $now, $this->windowSeconds()),
            $snapshot,
            $now
        );
        $this->appendHistory($payload);
        $this->recordValidationGateResult('empty', $payload);

        return $this->emitPayload($payload, self::SUCCESS);
    }

    /**
     * Persist the emitted projection as a validation gate result so downstream FACT consumers
     * can query the history via AgentValidationGateResultRepository::all()/failed()/digest().
     * Wires the previously-orphaned AgentValidationGateResultRepository into the live flow.
     *
     * @param  array<string,mixed>  $payload
     */
    private function recordValidationGateResult(string $verb, array $payload): void
    {
        $repo = $this->validationGateRepository();
        $overall = (string) ($payload['status'] ?? 'ok');
        $repo->store([
            'verb' => $verb,
            'overall_status' => $overall === '' ? 'ok' : $overall,
            'projection_schema' => (string) ($payload['schema'] ?? ''),
            'projection' => $payload,
        ]);
    }

    private function validationGateRepository(): AgentValidationGateResultRepository
    {
        /** @var AgentValidationGateResultRepository */
        return app(AgentValidationGateResultRepository::class);
    }

    private function handleHistory(): int
    {
        $path = storage_path(self::HISTORY_PATH);
        if (! is_file($path)) {
            return $this->emitPayload([
                'schema' => self::HISTORY_SCHEMA,
                'rows' => [],
            ], self::SUCCESS);
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $rows = [];
        foreach (array_slice(is_array($lines) ? $lines : [], -20) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $this->emitPayload([
            'schema' => self::HISTORY_SCHEMA,
            'rows' => $rows,
        ], self::SUCCESS);
    }

    /**
     * @return array{events:list<array<string,mixed>>,packets:list<array<string,mixed>>}
     */
    private function registrySnapshot(): array
    {
        $repo = $this->queueRepository();
        $packets = $repo->list();
        $events = [];

        foreach ($packets as $packet) {
            foreach ((array) ($packet['history'] ?? []) as $event) {
                if (is_array($event)) {
                    $events[] = $event + ['client_id' => (string) data_get($packet, 'metadata.client_id', 'unknown')];
                }
            }
        }

        return [
            'packets' => is_array($packets) ? $packets : [],
            'events' => $events,
        ];
    }

    private function appendHistory(array $payload): void
    {
        $path = storage_path(self::HISTORY_PATH);
        @mkdir(dirname($path), 0775, true);
        file_put_contents(
            $path,
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL,
            FILE_APPEND
        );
    }

    private function emitPayload(array $payload, int $exitCode): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return $exitCode;
        }

        if (($payload['schema'] ?? null) === self::HISTORY_SCHEMA) {
            $this->table(['schema', 'rows'], [[self::HISTORY_SCHEMA, count((array) ($payload['rows'] ?? []))]]);

            return $exitCode;
        }

        if (($payload['schema'] ?? null) === self::DISABLED_SCHEMA) {
            $this->table(['schema', 'status'], [[self::DISABLED_SCHEMA, (string) ($payload['status'] ?? '')]]);

            return $exitCode;
        }

        $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $exitCode;
    }

    private function windowSeconds(): int
    {
        return max(1, (int) $this->option('window'));
    }

    private function queueRepository(): AgentControlPlaneTaskPacketQueueRepository
    {
        /** @var AgentControlPlaneTaskPacketQueueRepository */
        return app(AgentControlPlaneTaskPacketQueueRepository::class);
    }

    private function consumptionReporter(): AtlasMaestroWorkloadConsumptionRateReporter
    {
        /** @var AtlasMaestroWorkloadConsumptionRateReporter */
        return app(AtlasMaestroWorkloadConsumptionRateReporter::class);
    }

    private function projectionEmitter(): AtlasMaestroWorkloadProjectionFactEmitter
    {
        /** @var AtlasMaestroWorkloadProjectionFactEmitter */
        return app(AtlasMaestroWorkloadProjectionFactEmitter::class);
    }
}
