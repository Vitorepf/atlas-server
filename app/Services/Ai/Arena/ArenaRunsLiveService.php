<?php

namespace App\Services\Ai\Arena;

use App\Services\Ai\Rivals\Core\RunStateMachine;
use App\Services\Ai\Rivals\Support\RunPaths;

final class ArenaRunsLiveService
{
    public function __construct(private readonly ArenaMeasurementStore $store = new ArenaMeasurementStore) {}

    /** @return array<string, mixed> */
    public function live(): array
    {
        $runs = [];
        foreach ($this->store->queuedRequests() as $entry) {
            if (($entry['status'] ?? null) !== 'queued') {
                continue;
            }
            $runs[] = [
                'run_id_public' => $entry['run_id_public'] ?? null,
                'suite' => $entry['suite'] ?? null,
                'engine' => $entry['engine'] ?? null,
                'arm' => $entry['arm'] ?? null,
                'status' => 'queued',
                'queued_at' => $entry['queued_at'] ?? null,
                'origin' => $entry['origin'] ?? null,
            ];
        }

        foreach ($this->liveRunIds() as $runId) {
            $state = $this->readJson(RunPaths::runDir($runId).'/state.json');
            $manifest = $this->readJson(RunPaths::nativeManifestPath($runId));
            $latest = $this->latestMeasurementForRun($runId);
            $status = ($state['state'] ?? null) === RunStateMachine::NATIVE_RUNNING ? 'running' : 'queued';
            $runs[] = array_filter([
                'run_id_public' => $this->store->publicRunId($runId),
                'suite' => $manifest['suite_id'] ?? $latest['suite'] ?? null,
                'engine' => $latest['engine'] ?? null,
                'arm' => $latest['arm'] ?? null,
                'status' => $status,
                'cases_done' => $this->casesDone($runId),
                'cases_total' => $this->casesTotal($manifest),
                'started_at' => $status === 'running' ? ($state['updated_at'] ?? null) : null,
                'queued_at' => $status === 'queued' ? ($state['updated_at'] ?? null) : null,
            ], static fn ($value): bool => $value !== null);
        }

        usort($runs, static function (array $a, array $b): int {
            $aTime = (string) ($a['started_at'] ?? $a['queued_at'] ?? '');
            $bTime = (string) ($b['started_at'] ?? $b['queued_at'] ?? '');

            return strcmp($aTime, $bTime);
        });

        return [
            'schema_version' => 'atlas.arena.runs_live.v1',
            'generated_at' => now()->toIso8601String(),
            'runs' => $runs,
        ];
    }

    /**
     * Catálogo público de motores rodáveis: enabled e nunca harness-only.
     *
     * @return array<string, mixed>
     */
    public function engines(): array
    {
        $engines = [];
        foreach ((array) config('atlas_rivals.models', []) as $engine => $model) {
            if (! is_string($engine) || ! is_array($model)) {
                continue;
            }
            if (($model['enabled'] ?? false) !== true || ! $this->store->isPublicEngine($engine)) {
                continue;
            }
            $engines[] = [
                'engine' => $engine,
                'access_type' => (string) ($model['access_type'] ?? ''),
                'local' => ($model['local'] ?? false) === true,
            ];
        }
        usort($engines, static fn (array $a, array $b): int => strcmp($a['engine'], $b['engine']));

        return [
            'schema_version' => 'atlas.arena.engines.v1',
            'generated_at' => now()->toIso8601String(),
            'engines' => $engines,
        ];
    }

    /** @return array<string, mixed> */
    public function start(array $input): array
    {
        $actor = trim((string) ($input['operator_actor'] ?? $input['actor'] ?? ''));
        $reason = trim((string) ($input['operator_reason'] ?? $input['motivo'] ?? $input['reason'] ?? ''));
        if ($actor === '') {
            return $this->error('operator_actor_required');
        }
        if ($reason === '') {
            return $this->error('operator_reason_required');
        }

        $engine = trim((string) ($input['engine'] ?? ''));
        if ($engine === '') {
            return $this->error('engine_required');
        }
        if (! $this->store->isPublicEngine($engine)) {
            return $this->error('engine_harness_only', ['engine' => $engine]);
        }

        $suites = $this->requestedSuites($input['suites'] ?? []);
        if ($suites === []) {
            return $this->error('suite_required');
        }
        $installed = array_keys((array) config('atlas_rivals.benchmarks.repos', []));
        foreach ($suites as $suite) {
            if (! in_array($suite, $installed, true)) {
                return $this->error('adapter_missing', ['suite' => $suite]);
            }
        }

        $arms = array_values(array_filter((array) ($input['arms'] ?? ['baseline', 'with_atlas']), 'is_string'));
        if ($arms === []) {
            return $this->error('arm_required');
        }
        foreach ($arms as $arm) {
            if (! in_array($arm, ['baseline', 'with_atlas'], true)) {
                return $this->error('invalid_arm', ['arm' => $arm]);
            }
        }

        // Origem do disparo (iphone|ipad|mac|cli) — allowlist; fora dela, omitida.
        $origin = trim((string) ($input['origin'] ?? ''));
        $origin = in_array($origin, ['iphone', 'ipad', 'mac', 'cli'], true) ? $origin : null;

        $queuedAt = now()->toIso8601String();
        $planned = [];
        foreach ($suites as $suite) {
            foreach ($arms as $arm) {
                $seed = array_filter([
                    'suite' => $suite,
                    'engine' => $engine,
                    'arm' => $arm,
                    'origin' => $origin,
                    'queued_at' => $queuedAt,
                    'actor_hash' => hash('sha256', $actor),
                    'reason_hash' => hash('sha256', $reason),
                ], static fn ($value): bool => $value !== null);
                $entry = [
                    'schema_version' => 'atlas.arena.queued_run.v1',
                    'status' => 'queued',
                    'run_id_public' => 'arq_'.substr(hash('sha256', json_encode($seed, JSON_UNESCAPED_SLASHES)), 0, 20),
                ] + $seed;
                $this->store->appendQueuedRequest($entry);
                $planned[] = $entry;
            }
        }

        return [
            'status_code' => 202,
            'payload' => [
                'schema_version' => 'atlas.arena.start_receipt.v1',
                'status' => 'enqueued',
                'receipt_hash' => hash('sha256', json_encode($planned, JSON_UNESCAPED_SLASHES)),
                'runs_planned' => count($planned),
                'started' => false,
                'worker_implemented' => false,
                'provider_invoked' => false,
                'note' => 'measurement_worker_missing_enqueue_only',
            ],
        ];
    }

    /** @return array{status_code:int,payload:array<string,mixed>} */
    private function error(string $reason, array $extra = []): array
    {
        return [
            'status_code' => 422,
            'payload' => [
                'schema_version' => 'atlas.arena.start_error.v1',
                'status' => 'error',
                'reason' => $reason,
            ] + $extra,
        ];
    }

    /** @return list<string> */
    private function requestedSuites(mixed $value): array
    {
        if ($value === 'all' || $value === ['all']) {
            $installed = array_keys((array) config('atlas_rivals.benchmarks.repos', []));

            return array_values(array_intersect($this->store->suites(), $installed));
        }
        if (is_string($value)) {
            $value = [$value];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $suite): string => trim((string) $suite),
            (array) $value
        ))));
    }

    /** @return list<string> */
    private function liveRunIds(): array
    {
        $runsDir = RunPaths::runsDir();
        if (! is_dir($runsDir)) {
            return [];
        }
        $staleSeconds = max(1, (int) config('atlas_arena.live_stale_minutes', 240)) * 60;
        $ids = [];
        foreach (array_diff(scandir($runsDir) ?: [], ['.', '..']) as $runId) {
            if (! is_dir($runsDir.'/'.$runId)) {
                continue;
            }
            $state = $this->readJson($runsDir.'/'.$runId.'/state.json');
            if (! in_array(($state['state'] ?? null), [
                RunStateMachine::PLANNED,
                RunStateMachine::PREFLIGHTED,
                RunStateMachine::NATIVE_RUNNING,
            ], true)) {
                continue;
            }
            $updated = strtotime((string) ($state['updated_at'] ?? ''));
            if ($updated !== false && (time() - $updated) > $staleSeconds) {
                continue;
            }
            $ids[] = (string) $runId;
        }
        sort($ids);

        return $ids;
    }

    /** @return array<string, mixed>|null */
    private function latestMeasurementForRun(string $runId): ?array
    {
        foreach (array_reverse($this->store->measurements()) as $row) {
            if (($row['run_id_public'] ?? null) === $this->store->publicRunId($runId)) {
                return $row;
            }
        }

        return null;
    }

    private function casesDone(string $runId): int
    {
        $path = RunPaths::eventsPath($runId);
        if (! is_file($path)) {
            return 0;
        }
        $done = 0;
        foreach (array_filter(explode(PHP_EOL, (string) file_get_contents($path))) as $line) {
            $event = json_decode($line, true);
            if (is_array($event) && in_array(($event['event_type'] ?? null), ['unit_finished', 'native_execution_finished', 'case_finished'], true)) {
                $done++;
            }
        }

        return $done;
    }

    /** @param array<string, mixed> $manifest */
    private function casesTotal(array $manifest): ?int
    {
        if (isset($manifest['expected_executions']) && is_numeric($manifest['expected_executions'])) {
            return (int) $manifest['expected_executions'];
        }
        if (isset($manifest['entries']) && is_array($manifest['entries'])) {
            return count($manifest['entries']);
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }
}
