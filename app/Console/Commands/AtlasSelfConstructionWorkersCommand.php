<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerExecutionEnvelopeBuilder;
use App\Services\Ai\SelfConstruction\WorkerSwarm\AtlasSelfConstructionWorkerCapabilityContract;
use Illuminate\Console\Command;
use Throwable;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Read-only CLI for the Worker Swarm surface. Four verbs:
 *   capability — evaluate a worker profile via the capability contract.
 *   match      — emit MATCH FACTS for {worker, packet} pairs (no scheduling).
 *   envelope   — build an AtlasNativeWorkerExecutionEnvelope from a packet payload.
 *   normalize  — convert worker-reported result fields into a canonical normalized facts envelope.
 *
 * Every verb is FACTS-ONLY: no provider call, no shell, no git, no worker started, no storage mutation.
 */
final class AtlasSelfConstructionWorkersCommand extends Command
{
    use EmitsCanonicalJson;

    /** @var string */
    protected $signature = 'atlas:self-construction:workers {action : capability|match|envelope|normalize} {--facts=} {--json}';

    /** @var string */
    protected $description = 'Read-only Worker Swarm surface: capability / match / envelope / normalize.';

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        $facts = $this->readJson('facts');

        $payload = match ($action) {
            'capability' => $this->capability($facts),
            'match' => $this->match($facts),
            'envelope' => $this->envelope($facts),
            'normalize' => $this->normalize($facts),
            default => ['status' => 'unknown_action', 'action' => $action],
        };
        $this->line($this->encode($payload));

        return ($payload['status'] ?? 'ok') === 'ok' || ! isset($payload['status']) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string,mixed>|null  $facts
     * @return array<string,mixed>
     */
    private function capability(?array $facts): array
    {
        if (! is_array($facts)) {
            return ['status' => 'usage_error', 'reason' => '--facts JSON file required'];
        }
        $r = $this->app()->make(AtlasSelfConstructionWorkerCapabilityContract::class)->evaluate($facts);

        return ['status' => 'ok', 'capability' => $r];
    }

    /**
     * @param  array<string,mixed>|null  $facts
     * @return array<string,mixed>
     */
    private function match(?array $facts): array
    {
        if (! is_array($facts) || ! isset($facts['workers'], $facts['packets'])) {
            return ['status' => 'usage_error', 'reason' => '--facts JSON with workers + packets required'];
        }
        $workers = is_array($facts['workers']) ? $facts['workers'] : [];
        $packets = is_array($facts['packets']) ? $facts['packets'] : [];

        $matches = [];
        foreach ($packets as $p) {
            if (! is_array($p)) {
                continue;
            }
            $required = is_array($p['required_capabilities'] ?? null) ? array_map('strval', $p['required_capabilities']) : [];
            $matchedWorkers = [];
            foreach ($workers as $w) {
                if (! is_array($w)) {
                    continue;
                }
                $caps = is_array($w['declared_capabilities'] ?? null) ? array_map('strval', $w['declared_capabilities']) : [];
                $missing = array_values(array_diff($required, $caps));
                if ($missing === []) {
                    $matchedWorkers[] = (string) ($w['worker_id'] ?? '');
                }
            }
            sort($matchedWorkers, SORT_STRING);
            $matches[] = ['packet_id' => (string) ($p['id'] ?? ''), 'eligible_workers' => $matchedWorkers];
        }
        usort($matches, static fn (array $a, array $b): int => strcmp($a['packet_id'], $b['packet_id']));

        return ['status' => 'ok', 'matches' => $matches];
    }

    /**
     * @param  array<string,mixed>|null  $facts
     * @return array<string,mixed>
     */
    private function envelope(?array $facts): array
    {
        if (! is_array($facts)) {
            return ['status' => 'usage_error', 'reason' => '--facts JSON file required'];
        }
        try {
            $env = $this->app()->make(AtlasNativeWorkerExecutionEnvelopeBuilder::class)->build($facts);
        } catch (Throwable $e) {
            return ['status' => 'envelope_invalid', 'reason' => $e->getMessage()];
        }

        return ['status' => 'ok', 'envelope' => $env];
    }

    /**
     * @param  array<string,mixed>|null  $facts
     * @return array<string,mixed>
     */
    private function normalize(?array $facts): array
    {
        if (! is_array($facts)) {
            return ['status' => 'usage_error', 'reason' => '--facts JSON file required'];
        }
        $normalized = [
            'task_packet_id' => (string) ($facts['task_packet_id'] ?? ''),
            'lease_id' => (string) ($facts['lease_id'] ?? ''),
            'files_changed' => array_values(array_map('strval', (array) ($facts['files_changed'] ?? []))),
            'commands_run' => array_values((array) ($facts['commands_run'] ?? [])),
            'tests_or_gates_result' => is_array($facts['tests_or_gates_result'] ?? null) ? $facts['tests_or_gates_result'] : ['passed' => null],
            'evidence_hash' => (string) ($facts['evidence_hash'] ?? ''),
            'scope_deviations' => array_values((array) ($facts['scope_deviations'] ?? [])),
            'residual_risks' => array_values(array_map('strval', (array) ($facts['residual_risks'] ?? []))),
            'runtime_owner' => (string) ($facts['runtime_owner'] ?? ''),
        ];

        return ['status' => 'ok', 'normalized' => $normalized];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readJson(string $optionName): ?array
    {
        $path = (string) ($this->option($optionName) ?? '');
        if ($path === '' || ! is_file($path)) {
            return null;
        }
        try {
            $decoded = json_decode((string) file_get_contents($path), true);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /** @return \Illuminate\Contracts\Container\Container */
    private function app()
    {
        return $this->getLaravel();
    }
}
