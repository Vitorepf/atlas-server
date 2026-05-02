<?php

namespace App\Services\Ai\Cli;

use App\Models\AiJob;
use App\Models\AiSession;
use App\Models\AiThread;
use App\Models\AiTrace;
use App\Services\Ai\AtlasAiRuntimeSettings;
use App\Support\AtlasSecurity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class AtlasCliDogfoodService
{
    public function __construct(
        private readonly AtlasAiRuntimeSettings $runtimeSettings,
    ) {}

    /**
     * @return array<int,string>
     */
    public function requiredScenarios(): array
    {
        return [
            'ask_session',
            'dev_task',
            'debug_fix',
            'provider_handoff',
            'quality_gate',
            'tui_status',
            'release_check',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function start(string $workspace, ?string $scenario = null, ?string $provider = null, ?string $notes = null): array
    {
        $event = $this->eventPayload(
            workspace: $workspace,
            scenario: $scenario ?: 'manual_session',
            provider: $provider,
            result: 'running',
            durationMinutes: null,
            notes: $notes,
            metadata: [],
            startedAt: now(),
            finishedAt: null,
        );

        $data = $this->appendEvent($event);

        return [
            'ok' => true,
            'status' => 'started',
            'path' => $this->path(),
            'event' => $event,
            'total_events' => count($data['events']),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function record(string $workspace, ?string $scenario = null, ?string $provider = null, string $result = 'passed', ?int $durationMinutes = null, ?string $notes = null, array $metadata = []): array
    {
        $result = $this->normalizeResult($result);
        $event = $this->eventPayload(
            workspace: $workspace,
            scenario: $scenario ?: 'manual_session',
            provider: $provider,
            result: $result,
            durationMinutes: $durationMinutes,
            notes: $notes,
            metadata: $metadata,
            startedAt: null,
            finishedAt: now(),
        );

        $data = $this->appendEvent($event);

        return [
            'ok' => $result === 'passed',
            'status' => 'recorded',
            'path' => $this->path(),
            'event' => $event,
            'total_events' => count($data['events']),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function runSmoke(string $workspace, string $releaseVersion = 'v2.0.0', bool $stopOnFailure = false): array
    {
        $workspace = $this->resolveWorkspace($workspace);
        $runId = (string) Str::uuid();
        $results = [];
        $artifacts = $this->emptyArtifacts();

        foreach ($this->smokeScenarios($workspace, $releaseVersion) as $scenario => $definition) {
            $started = microtime(true);
            $process = new Process($definition['command'], base_path(), AtlasSecurity::processEnv(profile: 'internal'));
            $process->setTimeout($definition['timeout'] ?? 120);
            $process->run();
            $durationMinutes = max(1, (int) ceil((microtime(true) - $started) / 60));
            $result = $process->isSuccessful() ? 'passed' : 'failed';
            $extracted = $this->extractArtifacts($process->getOutput());
            $artifacts = $this->mergeArtifacts($artifacts, $extracted);
            $this->markSmokeArtifacts($extracted, $runId);

            $record = $this->record(
                workspace: $workspace,
                scenario: $scenario,
                provider: $definition['provider'] ?? null,
                result: $result,
                durationMinutes: $durationMinutes,
                notes: 'dogfood smoke: '.($definition['description'] ?? $scenario),
                metadata: [
                    'profile' => 'smoke',
                    'run_id' => $runId,
                    'cleanup_policy' => 'delete_smoke_artifacts_after_run',
                    'artifacts' => $extracted,
                    'command' => $this->redactCommand($definition['command']),
                    'exit_code' => $process->getExitCode(),
                    'stdout_excerpt' => Str::limit(trim(AtlasSecurity::redactString($process->getOutput())), 1200, '...'),
                    'stderr_excerpt' => Str::limit(trim(AtlasSecurity::redactString($process->getErrorOutput())), 1200, '...'),
                ],
            );

            $results[] = [
                'scenario' => $scenario,
                'status' => $result,
                'exit_code' => $process->getExitCode(),
                'duration_minutes' => $durationMinutes,
                'record' => $record['event'],
            ];

            if ($stopOnFailure && ! $process->isSuccessful()) {
                break;
            }
        }

        $cleanup = $this->cleanupSmokeArtifacts($artifacts);
        $failed = collect($results)->where('status', 'failed')->values()->all();
        if (($cleanup['status'] ?? null) !== 'passed') {
            $failed[] = [
                'scenario' => 'smoke_cleanup',
                'status' => 'failed',
                'exit_code' => null,
                'duration_minutes' => 0,
                'record' => null,
                'cleanup' => $cleanup,
            ];
        }

        return [
            'ok' => $failed === [],
            'status' => $failed === [] ? 'passed' : 'failed',
            'profile' => 'smoke',
            'run_id' => $runId,
            'path' => $this->path(),
            'workspace' => $workspace,
            'results' => $results,
            'artifacts' => $artifacts,
            'cleanup' => $cleanup,
            'failed' => $failed,
            'report' => $this->report($workspace, 1),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function report(string $workspace, int $days = 3, bool $requireReal = false): array
    {
        $days = max(1, min($days, 30));
        $workspace = $this->resolveWorkspace($workspace);
        $since = now()->copy()->subDays($days - 1)->startOfDay();
        $required = $this->requiredScenarios();
        $events = collect($this->read()['events'])
            ->filter(function (array $event) use ($workspace, $since): bool {
                if (($event['workspace'] ?? null) !== $workspace) {
                    return false;
                }

                $date = $this->eventDate($event);

                return $date !== null && $date->greaterThanOrEqualTo($since);
            })
            ->values();

        $passed = $events->where('result', 'passed');
        $passedReal = $passed
            ->reject(fn (array $event): bool => data_get($event, 'metadata.profile') === 'smoke')
            ->values();
        $failed = $events->whereIn('result', ['failed', 'blocked']);
        $covered = $passed
            ->pluck('scenario')
            ->filter()
            ->unique()
            ->values()
            ->all();
        $realCovered = $passedReal
            ->pluck('scenario')
            ->filter()
            ->unique()
            ->values()
            ->all();
        $missing = array_values(array_diff($required, $covered));
        $missingReal = array_values(array_diff($required, $realCovered));
        $observedDays = $events
            ->map(fn (array $event): ?string => $this->eventDate($event)?->toDateString())
            ->filter()
            ->unique()
            ->values()
            ->all();

        $gates = [
            [
                'name' => 'observation_window',
                'status' => count($observedDays) >= $days ? 'passed' : 'needs_review',
                'detail' => count($observedDays)." dia(s) observado(s) de {$days}.",
            ],
            [
                'name' => 'scenario_coverage',
                'status' => $missing === [] ? 'passed' : 'needs_review',
                'detail' => $missing === [] ? 'Todos os cenarios obrigatorios passaram.' : 'Cenarios pendentes: '.implode(', ', $missing).'.',
            ],
            [
                'name' => 'blocking_failures',
                'status' => $failed->isEmpty() ? 'passed' : 'failed',
                'detail' => $failed->isEmpty() ? 'Nenhuma falha bloqueante registrada.' : $failed->count().' falha(s) ou bloqueio(s) no periodo.',
            ],
        ];
        if ($requireReal) {
            $gates[] = [
                'name' => 'real_usage_coverage',
                'status' => $missingReal === [] ? 'passed' : 'needs_review',
                'detail' => $missingReal === [] ? 'Todos os cenarios tem evidencia real.' : 'Cenarios sem uso real: '.implode(', ', $missingReal).'.',
            ];
        }

        $status = $this->statusFromGates($gates);

        return [
            'ok' => $status === 'passed',
            'status' => $status,
            'generated_at' => now()->toJSON(),
            'path' => $this->path(),
            'workspace' => $workspace,
            'window_days' => $days,
            'observed_days' => $observedDays,
            'requires_real_usage' => $requireReal,
            'required_scenarios' => $required,
            'covered_scenarios' => $covered,
            'real_covered_scenarios' => $realCovered,
            'missing_scenarios' => $missing,
            'missing_real_scenarios' => $missingReal,
            'event_counts' => [
                'total' => $events->count(),
                'passed' => $passed->count(),
                'passed_real' => $passedReal->count(),
                'passed_smoke' => $passed->count() - $passedReal->count(),
                'failed_or_blocked' => $failed->count(),
                'running' => $events->where('result', 'running')->count(),
            ],
            'gates' => $gates,
            'recent_events' => $events
                ->sortByDesc(fn (array $event): string => (string) ($event['finished_at'] ?? $event['started_at'] ?? $event['created_at'] ?? ''))
                ->take(12)
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function reset(): array
    {
        File::delete($this->path());

        return [
            'ok' => true,
            'status' => 'reset',
            'path' => $this->path(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function appendEvent(array $event): array
    {
        $data = $this->read();
        $data['events'][] = $event;

        File::ensureDirectoryExists(dirname($this->path()));
        File::put($this->path(), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        return $data;
    }

    /**
     * @return array{version:int,events:array<int,array<string,mixed>>}
     */
    private function read(): array
    {
        if (! File::exists($this->path())) {
            return ['version' => 1, 'events' => []];
        }

        $decoded = json_decode(File::get($this->path()), true);

        return [
            'version' => 1,
            'events' => is_array($decoded['events'] ?? null) ? array_values($decoded['events']) : [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function eventPayload(string $workspace, string $scenario, ?string $provider, string $result, ?int $durationMinutes, ?string $notes, array $metadata, ?Carbon $startedAt, ?Carbon $finishedAt): array
    {
        return [
            'id' => (string) Str::uuid(),
            'scenario' => $this->normalizeScenario($scenario),
            'result' => $this->normalizeResult($result),
            'provider' => $provider ? $this->normalizeScenario($provider) : null,
            'workspace' => $this->resolveWorkspace($workspace),
            'duration_minutes' => $durationMinutes === null ? null : max(0, $durationMinutes),
            'notes' => $notes ? AtlasSecurity::redactString($notes) : null,
            'metadata' => AtlasSecurity::redactArray($metadata),
            'started_at' => $startedAt?->toJSON(),
            'finished_at' => $finishedAt?->toJSON(),
            'created_at' => now()->toJSON(),
        ];
    }

    private function normalizeScenario(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->replace(['-', ' '], '_')
            ->replaceMatches('/[^a-z0-9_]/', '')
            ->trim('_')
            ->toString() ?: 'manual_session';
    }

    private function normalizeResult(string $value): string
    {
        $value = $this->normalizeScenario($value);

        return in_array($value, ['running', 'passed', 'failed', 'blocked', 'needs_review'], true)
            ? $value
            : 'needs_review';
    }

    private function eventDate(array $event): ?Carbon
    {
        $value = $event['finished_at'] ?? $event['started_at'] ?? $event['created_at'] ?? null;

        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }

    /**
     * @param  array<int,array<string,mixed>>  $gates
     */
    private function statusFromGates(array $gates): string
    {
        $statuses = collect($gates)->pluck('status');

        return match (true) {
            $statuses->contains('failed') => 'failed',
            $statuses->contains('needs_review') => 'needs_review',
            default => 'passed',
        };
    }

    private function path(): string
    {
        return (string) config('atlas.cli.dogfood_path', storage_path('app/atlas-cli/dogfood.json'));
    }

    /**
     * @return array<string,array{description:string,provider?:string,timeout?:int,command:array<int,string>}>
     */
    private function smokeScenarios(string $workspace, string $releaseVersion): array
    {
        return [
            'ask_session' => [
                'description' => 'cria thread Atlas CLI sem chamar provider',
                'provider' => $this->runtimeSettings->defaultProvider(),
                'timeout' => 120,
                'command' => [PHP_BINARY, 'artisan', 'atlas:ai:chat', 'Dogfood smoke: crie uma sessao Atlas CLI sem executar provider.', '--workspace='.$workspace, '--new-thread', '--no-run', '--json'],
            ],
            'dev_task' => [
                'description' => 'gera plano de dev sem chamar provider',
                'provider' => 'codex_cli',
                'timeout' => 120,
                'command' => [PHP_BINARY, 'artisan', 'atlas:cli:dev', 'Dogfood smoke: valide o preflight de desenvolvimento.', '--workspace='.$workspace, '--plan-only', '--json'],
            ],
            'debug_fix' => [
                'description' => 'cria trace de debug sem executar provider',
                'provider' => $this->runtimeSettings->defaultProvider(),
                'timeout' => 120,
                'command' => [PHP_BINARY, 'artisan', 'atlas:ai:chat', 'Dogfood smoke: investigue sem executar provider.', '--workspace='.$workspace, '--mode=debug', '--no-run', '--json'],
            ],
            'provider_handoff' => [
                'description' => 'registra handoff de provider na sessao ativa',
                'provider' => 'codex_cli',
                'timeout' => 120,
                'command' => [PHP_BINARY, 'artisan', 'atlas:cli:state', 'handoff', '--workspace='.$workspace, '--provider=codex_cli', '--no-compact', '--json'],
            ],
            'quality_gate' => [
                'description' => 'executa quality gate sem testes destrutivos',
                'timeout' => 180,
                'command' => [PHP_BINARY, 'artisan', 'atlas:cli:quality', '--workspace='.$workspace, '--json'],
            ],
            'tui_status' => [
                'description' => 'renderiza um frame TUI',
                'timeout' => 120,
                'command' => [PHP_BINARY, 'artisan', 'atlas:cli:tui', '--workspace='.$workspace, '--once'],
            ],
            'release_check' => [
                'description' => 'valida gate estrutural de release em preflight',
                'timeout' => 180,
                'command' => [PHP_BINARY, 'artisan', 'atlas:cli:release', '--release-version='.$releaseVersion, '--preflight', '--no-final', '--skip-dogfood', '--allow-dirty', '--json'],
            ],
        ];
    }

    /**
     * @return array{trace_ids:array<int,string>,thread_ids:array<int,string>,session_ids:array<int,string>,job_ids:array<int,string>}
     */
    private function extractArtifacts(string $stdout): array
    {
        $decoded = json_decode(trim($stdout), true);
        if (! is_array($decoded)) {
            return $this->emptyArtifacts();
        }

        $artifacts = $this->emptyArtifacts();
        foreach ([
            'trace_id' => 'trace_ids',
            'thread_id' => 'thread_ids',
            'session_id' => 'session_ids',
            'job_id' => 'job_ids',
            'thread.id' => 'thread_ids',
            'session.id' => 'session_ids',
            'created_provider_handoff.thread_id' => 'thread_ids',
            'created_provider_handoff.session_id' => 'session_ids',
        ] as $source => $target) {
            $value = data_get($decoded, $source);
            if (is_string($value) && $value !== '') {
                $artifacts[$target][] = $value;
            }
        }

        return $this->uniqueArtifacts($artifacts);
    }

    /**
     * @return array{trace_ids:array<int,string>,thread_ids:array<int,string>,session_ids:array<int,string>,job_ids:array<int,string>}
     */
    private function emptyArtifacts(): array
    {
        return [
            'trace_ids' => [],
            'thread_ids' => [],
            'session_ids' => [],
            'job_ids' => [],
        ];
    }

    /**
     * @param  array<string,array<int,string>>  $left
     * @param  array<string,array<int,string>>  $right
     * @return array{trace_ids:array<int,string>,thread_ids:array<int,string>,session_ids:array<int,string>,job_ids:array<int,string>}
     */
    private function mergeArtifacts(array $left, array $right): array
    {
        return $this->uniqueArtifacts([
            'trace_ids' => [...($left['trace_ids'] ?? []), ...($right['trace_ids'] ?? [])],
            'thread_ids' => [...($left['thread_ids'] ?? []), ...($right['thread_ids'] ?? [])],
            'session_ids' => [...($left['session_ids'] ?? []), ...($right['session_ids'] ?? [])],
            'job_ids' => [...($left['job_ids'] ?? []), ...($right['job_ids'] ?? [])],
        ]);
    }

    /**
     * @param  array<string,array<int,string>>  $artifacts
     * @return array{trace_ids:array<int,string>,thread_ids:array<int,string>,session_ids:array<int,string>,job_ids:array<int,string>}
     */
    private function uniqueArtifacts(array $artifacts): array
    {
        return [
            'trace_ids' => array_values(array_unique(array_filter((array) ($artifacts['trace_ids'] ?? []), 'is_string'))),
            'thread_ids' => array_values(array_unique(array_filter((array) ($artifacts['thread_ids'] ?? []), 'is_string'))),
            'session_ids' => array_values(array_unique(array_filter((array) ($artifacts['session_ids'] ?? []), 'is_string'))),
            'job_ids' => array_values(array_unique(array_filter((array) ($artifacts['job_ids'] ?? []), 'is_string'))),
        ];
    }

    /**
     * @param  array<string,array<int,string>>  $artifacts
     */
    private function markSmokeArtifacts(array $artifacts, string $runId): void
    {
        foreach ([
            AiTrace::class => 'trace_ids',
            AiThread::class => 'thread_ids',
            AiSession::class => 'session_ids',
            AiJob::class => 'job_ids',
        ] as $model => $key) {
            foreach (($artifacts[$key] ?? []) as $id) {
                $record = $model::query()->find($id);
                if (! $record) {
                    continue;
                }

                $metadata = $record->metadata ?? [];
                $metadata['dogfood_profile'] = 'smoke';
                $metadata['dogfood_run_id'] = $runId;
                $metadata['dogfood_cleanup_policy'] = 'delete_after_run';
                $record->forceFill(['metadata' => $metadata])->save();
            }
        }
    }

    /**
     * @param  array<string,array<int,string>>  $artifacts
     * @return array<string,mixed>
     */
    private function cleanupSmokeArtifacts(array $artifacts): array
    {
        $artifacts = $this->uniqueArtifacts($artifacts);
        if ($artifacts === $this->emptyArtifacts()) {
            return [
                'status' => 'passed',
                'deleted' => [],
                'detail' => 'Nenhum artefato de smoke persistente detectado.',
            ];
        }

        try {
            return DB::transaction(function () use ($artifacts): array {
                $traceIds = $artifacts['trace_ids'];
                $threadIds = $artifacts['thread_ids'];
                $sessionIds = $artifacts['session_ids'];
                $jobIds = $this->relatedJobIds($traceIds, $artifacts['job_ids']);
                $attemptIds = $this->relatedAttemptIds($jobIds);
                $deleted = [];

                $deleted['ai_stream_events'] = $this->deleteAny('ai_stream_events', [
                    'trace_id' => $traceIds,
                    'ai_job_id' => $jobIds,
                    'ai_job_attempt_id' => $attemptIds,
                ]);
                $deleted['ai_worker_events'] = $this->deleteAny('ai_worker_events', [
                    'ai_job_id' => $jobIds,
                    'ai_job_attempt_id' => $attemptIds,
                ]);
                $deleted['ai_tool_events'] = $this->deleteAny('ai_tool_events', ['trace_id' => $traceIds]);
                $deleted['ai_router_decisions'] = $this->deleteAny('ai_router_decisions', ['trace_id' => $traceIds]);
                $deleted['ai_memory_deltas'] = $this->deleteAny('ai_memory_deltas', [
                    'trace_id' => $traceIds,
                    'thread_id' => $threadIds,
                    'session_id' => $sessionIds,
                ]);
                $deleted['ai_permission_sessions'] = $this->deleteAny('ai_permission_sessions', [
                    'trace_id' => $traceIds,
                    'thread_id' => $threadIds,
                    'session_id' => $sessionIds,
                ]);
                $deleted['ai_quality_actions'] = $this->deleteAny('ai_quality_actions', [
                    'trace_id' => $traceIds,
                    'remediation_trace_id' => $traceIds,
                    'thread_id' => $threadIds,
                    'session_id' => $sessionIds,
                ]);
                $deleted['ai_quality_evaluations'] = $this->deleteAny('ai_quality_evaluations', [
                    'trace_id' => $traceIds,
                    'thread_id' => $threadIds,
                    'session_id' => $sessionIds,
                ]);
                $deleted['ai_context_snapshots'] = $this->deleteAny('ai_context_snapshots', [
                    'trace_id' => $traceIds,
                    'thread_id' => $threadIds,
                    'session_id' => $sessionIds,
                ]);
                $deleted['ai_messages'] = $this->deleteAny('ai_messages', [
                    'trace_id' => $traceIds,
                    'thread_id' => $threadIds,
                ]);
                $deleted['ai_provider_handoffs'] = $this->deleteAny('ai_provider_handoffs', [
                    'thread_id' => $threadIds,
                    'session_id' => $sessionIds,
                ]);
                $deleted['ai_compactions'] = $this->deleteAny('ai_compactions', [
                    'thread_id' => $threadIds,
                    'session_id' => $sessionIds,
                ]);
                $deleted['ai_session_states'] = $this->deleteAny('ai_session_states', [
                    'thread_id' => $threadIds,
                    'session_id' => $sessionIds,
                ]);
                $deleted['ai_job_attempts'] = $this->deleteAny('ai_job_attempts', ['ai_job_id' => $jobIds]);
                $deleted['ai_jobs'] = $this->deleteAny('ai_jobs', [
                    'id' => $jobIds,
                    'trace_id' => $traceIds,
                ]);

                $this->updateNull('ai_threads', 'last_trace_id', $traceIds, ['last_trace_id']);
                $deleted['ai_traces'] = $this->deleteAny('ai_traces', ['id' => $traceIds]);
                $deleted['ai_sessions'] = $this->deleteAny('ai_sessions', [
                    'id' => $sessionIds,
                    'thread_id' => $threadIds,
                ]);
                $deleted['ai_threads'] = $this->deleteAny('ai_threads', ['id' => $threadIds]);

                return [
                    'status' => 'passed',
                    'deleted' => $deleted,
                    'artifacts' => $artifacts,
                ];
            });
        } catch (\Throwable $exception) {
            return [
                'status' => 'failed',
                'error' => $exception->getMessage(),
                'artifacts' => $artifacts,
            ];
        }
    }

    /**
     * @param  array<int,string>  $traceIds
     * @param  array<int,string>  $explicitJobIds
     * @return array<int,string>
     */
    private function relatedJobIds(array $traceIds, array $explicitJobIds): array
    {
        if (! Schema::hasTable('ai_jobs')) {
            return $explicitJobIds;
        }

        return array_values(array_unique([
            ...$explicitJobIds,
            ...DB::table('ai_jobs')->whereIn('trace_id', $traceIds)->pluck('id')->map(fn (mixed $id): string => (string) $id)->all(),
        ]));
    }

    /**
     * @param  array<int,string>  $jobIds
     * @return array<int,string>
     */
    private function relatedAttemptIds(array $jobIds): array
    {
        if ($jobIds === [] || ! Schema::hasTable('ai_job_attempts')) {
            return [];
        }

        return DB::table('ai_job_attempts')->whereIn('ai_job_id', $jobIds)->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();
    }

    /**
     * @param  array<string,array<int,string>>  $columns
     */
    private function deleteAny(string $table, array $columns): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        $query = DB::table($table);
        $hasPredicate = false;
        foreach ($columns as $column => $ids) {
            if ($ids === [] || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $hasPredicate = true;
            $query->orWhereIn($column, $ids);
        }

        return $hasPredicate ? $query->delete() : 0;
    }

    /**
     * @param  array<int,string>  $ids
     * @param  array<int,string>  $columns
     */
    private function updateNull(string $table, string $whereColumn, array $ids, array $columns): int
    {
        if ($ids === [] || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $whereColumn)) {
            return 0;
        }

        $updates = [];
        foreach ($columns as $column) {
            if (Schema::hasColumn($table, $column)) {
                $updates[$column] = null;
            }
        }

        return $updates === [] ? 0 : DB::table($table)->whereIn($whereColumn, $ids)->update($updates);
    }

    /**
     * @param  array<int,string>  $command
     * @return array<int,string>
     */
    private function redactCommand(array $command): array
    {
        return AtlasSecurity::redactCommand($command);
    }

    private function resolveWorkspace(string $workspace): string
    {
        $resolved = realpath($workspace);

        return $resolved && is_dir($resolved) ? $resolved : $workspace;
    }
}
