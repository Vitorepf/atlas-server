<?php

namespace App\Console\Commands;

use App\Services\Ai\Rivals\Adapters\AtlasBenchSuiteAdapter;
use App\Services\Ai\Rivals\Adapters\LocalFakeSuiteAdapter;
use App\Services\Ai\Rivals\Benchmarks\BenchmarkRepoManager;
use App\Services\Ai\Rivals\Contracts\BenchmarkSuiteAdapter;
use App\Services\Ai\Rivals\Core\Adjudicator;
use App\Services\Ai\Rivals\Core\ArmRegistry;
use App\Services\Ai\Rivals\Core\AtlasUpliftRunner;
use App\Services\Ai\Rivals\Core\BundleManifest;
use App\Services\Ai\Rivals\Core\EvidencePackBuilder;
use App\Services\Ai\Rivals\Core\EnterpriseReportBuilder;
use App\Services\Ai\Rivals\Core\FaseABatteryOrchestrator;
use App\Services\Ai\Rivals\Core\FaseAClosureReceipt;
use App\Services\Ai\Rivals\Core\FrozenUnitManifest;
use App\Services\Ai\Rivals\Core\ModelRegistry;
use App\Services\Ai\Rivals\Core\NativeExecutionBundleImporter;
use App\Services\Ai\Rivals\Core\NativeExecutionManifest;
use App\Services\Ai\Rivals\Core\Preregistration;
use App\Services\Ai\Rivals\Core\ReplayVerifier;
use App\Services\Ai\Rivals\Core\ReportBuilder;
use App\Services\Ai\Rivals\Core\ResultLedger;
use App\Services\Ai\Rivals\Core\RunAutopsy;
use App\Services\Ai\Rivals\Core\RunPlan;
use App\Services\Ai\Rivals\Core\RunReceipt;
use App\Services\Ai\Rivals\Core\RunStateMachine;
use App\Services\Ai\Rivals\Core\SuiteRegistry;
use App\Services\Ai\Rivals\Core\WorldTrialReadiness;
use App\Services\Ai\Rivals\Support\RunLock;
use App\Services\Ai\Rivals\Support\RunPaths;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process as ProcessFacade;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Único entrypoint do Rivals (produto público: Rivals, versão 2.0; substitui
 * atlas:forge:rivals). Fail-closed: nenhuma ação chama provider por conta própria.
 * atlas:rivals2 permanece só como alias temporário de compatibilidade.
 */
class AtlasRivalsCommand extends Command
{
    use EmitsCanonicalJson;

    protected $aliases = ['atlas:rivals2'];

    protected $signature = 'atlas:rivals
        {action : doctor|benchmarks|benchmark-smoke|models|arms|mine|import-cases|import-results|plan|preflight|status|world-readiness|resume|cancel|run|run-fake|run-bench|verify|adjudicate|report|report-all|report-enterprise|battery|uplift|bundle|verify-bundle|closure|ledger|autopsy}
        {--repo= : (benchmark-smoke) repo_id do registry (vazio = todos)}
        {--model= : (uplift) model_id comparado nos dois runtimes}
        {--base-runtime=bare}
        {--atlas-runtime=atlas_dev}
        {--suite=local_fake}
        {--mode=bare : (battery) bare|uplift|atlas|model_matrix|status|prepare|execute}
        {--kind=bare : (battery prepare/execute) bare|uplift|atlas|model_matrix}
        {--profile=fase_a : (battery) fase_a|engineering_native}
        {--cases= : (plan) comma-separated case ids; default all imported cases}
        {--max-cases= : (plan) corta a lista de cases em N (rodada bounded, ordem determinística)}
        {--limit=5 : (mine) máximo de cases a minerar}
        {--file= : (import-cases/import-results) arquivo ou diretório de origem}
        {--source-repo= : (import-cases/plan) source_repo de proveniência}
        {--judge-config= : (plan) path JSON de judge_config}
        {--allow-synthetic-frozen : (plan) suites externas sem snapshot git — mesmo trilho da battery (FrozenUnitManifest allowSynthetic)}
        {--budget= : (plan) budget USD}
        {--max-minutes= : (plan) hard wall-clock cap per native execution}
        {--approve-provider-spend : (plan) aprovação explícita de spend}
        {--fast : (battery) 1 case × 1 rep × N suites — pipeline proof, not claim-ready}
        {--dry-run : (battery execute) lista/valida units sem gastar provider}
        {--replace-import : (import-results) substitui receipts/evidence anteriores}
        {--strict : falha se smoke blocked / uplift unsupported}
        {--run= : run_id (default: run mais recente)}
        {--arms=local_fake_model@bare : lista model@runtime separada por vírgula}
        {--repetitions=3}
        {--seed=1}
        {--verify : (ledger) verifica a hash chain}
        {--semantic : (ledger) verifica chain + estado semântico atual}
        {--repair-semantic : (ledger) supersede + re-append adjudication/report do epoch atual}
        {--quarantine-epoch : (ledger) move chain corrompida para ledger.quarantine.* e inicia epoch limpo}
        {--md : (autopsy) emite markdown além do json}
        {--require-clean-worktree : (battery execute / plan) falha se git dirty}
        {--reason= : (cancel) motivo obrigatório}
        {--workspace= : (compat launcher bin/atlas) ignorado — Rivals roda no atlas-server}
        {--json}';

    protected $description = 'Rivals 2.0 — benchmark interno Atlas (model-vs-model + Atlas uplift), fail-closed';

    public function handle(): int
    {
        $action = $this->argument('action');
        $enabled = (bool) config('atlas_rivals.enabled', false);
        $batteryMode = $action === 'battery' ? (string) ($this->option('mode') ?: 'bare') : '';
        // prepare mutates disk (import+plan); execute is blocked but still gated.
        $batteryMutating = $action === 'battery'
            && in_array($batteryMode, ['prepare', 'execute'], true);
        $mutating = $batteryMutating || ! in_array($action, [
            'doctor', 'benchmarks', 'models', 'arms', 'ledger', 'status', 'world-readiness',
            'verify-bundle', 'report', 'report-all', 'report-enterprise', 'battery', 'autopsy',
        ], true);
        if (! $enabled && $mutating) {
            $payload = [
                'status' => 'error',
                'error' => 'atlas_rivals_disabled',
                'hint' => 'Set ATLAS_RIVALS2_ENABLED=true to mutate or run Rivals 2.0.',
            ];
            $this->line($this->encode($payload));

            return self::FAILURE;
        }

        $payload = match ($action) {
            'doctor' => $this->doctor(),
            'benchmarks' => $this->benchmarks(),
            'benchmark-smoke' => $this->benchmarkSmoke(),
            'run' => $this->runSuite(),
            'models' => ['schema_version' => 'atlas.rivals2.models.v1', 'models' => (new ModelRegistry)->all()],
            'arms' => $this->arms(),
            'mine' => $this->mine(),
            'import-cases' => $this->importCases(),
            'import-results' => $this->importResults(),
            'plan' => $this->plan(),
            'preflight' => $this->preflight(),
            'status' => $this->runStatus(),
            'world-readiness' => $this->worldReadiness(),
            'resume' => $this->resume(),
            'cancel' => $this->cancel(),
            'run-fake' => $this->runFake(),
            'run-bench' => $this->runBench(),
            'verify' => $this->withRun(function ($runId) {
                (new RunStateMachine)->assertAtLeast($runId, RunStateMachine::EVIDENCE_BUILT);
                $result = ['run_id' => $runId] + (new ReplayVerifier)->verify($runId);
                if (($result['verified'] ?? false) === true) {
                    (new RunStateMachine)->mark($runId, RunStateMachine::VERIFIED, $result);
                }

                return $result;
            }, lock: true),
            'adjudicate' => $this->withRun(function ($runId) {
                (new RunStateMachine)->assertAtLeast($runId, RunStateMachine::VERIFIED);
                $adjudication = (new Adjudicator)->adjudicate($runId);
                (new RunStateMachine)->mark($runId, RunStateMachine::ADJUDICATED, [
                    'claim_allowed' => (bool) ($adjudication['claim_allowed'] ?? false),
                ]);
                // toda adjudicação (válida OU inválida) entra na chain — auditoria completa
                $entry = (new ResultLedger)->append($runId, $adjudication);

                return $adjudication + ['ledger_entry_id' => $entry['entry_id']];
            }, lock: true),
            'report' => $this->withRun(function ($runId) {
                (new RunStateMachine)->assertAtLeast($runId, RunStateMachine::ADJUDICATED);
                $report = (new ReportBuilder)->build($runId);
                (new RunStateMachine)->mark($runId, RunStateMachine::REPORTED, [
                    'claim_allowed' => (bool) ($report['claim_allowed'] ?? false),
                ]);
                (new ResultLedger)->appendReport($runId, $report);

                return $report;
            }, lock: true),
            'report-all' => (new ReportBuilder)->buildAll(),
            'report-enterprise' => (new EnterpriseReportBuilder)->build(
                (string) $this->option('profile'),
            ),
            'battery' => $this->runBattery(),
            'uplift' => $this->withRun(function ($runId) {
                (new RunStateMachine)->assertAtLeast($runId, RunStateMachine::ADJUDICATED);
                $model = (string) $this->option('model');
                if ($model === '') {
                    return ['status' => 'error', 'error' => 'uplift_requires_model_option'];
                }

                $result = (new AtlasUpliftRunner)->compare(
                    $runId,
                    $model,
                    (string) $this->option('base-runtime'),
                    (string) $this->option('atlas-runtime'),
                );
                if ($this->option('strict') && ! ($result['uplift_supported'] ?? false)) {
                    return $result + ['status' => 'error', 'error' => 'uplift_unsupported'];
                }

                return $result + ['status' => 'ok'];
            }, lock: true),
            'bundle' => $this->bundle(),
            'verify-bundle' => $this->verifyBundle(),
            'closure' => $this->closure(),
            'ledger' => $this->ledger(),
            'autopsy' => $this->autopsy(),
            default => ['status' => 'error', 'error' => "unknown_action:{$action}"],
        };

        $isError = ($payload['status'] ?? 'ok') === 'error'
            || ($payload['verified'] ?? true) === false
            || ($payload['verdict'] ?? 'valid') === 'invalid';

        if ($this->option('json')) {
            $this->line($this->encode($payload));
        } else {
            $this->line($this->encode($payload));
        }

        return $isError ? self::FAILURE : self::SUCCESS;
    }

    private function runBattery(): array
    {
        $mode = (string) ($this->option('mode') ?: 'bare');
        $fast = (bool) $this->option('fast');
        $orchestrator = new FaseABatteryOrchestrator;
        if ($mode === 'status') {
            return $orchestrator->status();
        }
        if ($mode === 'execute') {
            try {
                $this->assertCleanWorktreeIfRequired();

                // --suite=a,b executa só essas (default local_fake = todas).
                // A bateria roda sequencial; sem isto uma suíte de segundos espera
                // horas atrás das de ~23min/tarefa, por ordem e não por necessidade.
                $only = (string) ($this->option('suite') ?: '');
                $onlySuites = ($only === '' || $only === 'local_fake')
                    ? []
                    : array_values(array_filter(array_map('trim', explode(',', $only))));

                return $orchestrator->execute(
                    (string) ($this->option('kind') ?: 'bare'),
                    (bool) $this->option('approve-provider-spend'),
                    (bool) $this->option('dry-run'),
                    $fast,
                    $onlySuites,
                    (string) ($this->option('profile') ?: 'fase_a'),
                    (int) $this->option('repetitions'),
                );
            } catch (\Throwable $e) {
                return [
                    'status' => 'error',
                    'error' => $e->getMessage(),
                    'hint' => 'Fase A execute = smoke 10/10 + prepare + rivals-native-runner (Hermes+Verboo) + report-enterprise. Cloud never spends.',
                ];
            }
        }
        if ($mode === 'prepare') {
            try {
                $this->assertCleanWorktreeIfRequired();

                return $orchestrator->prepare(
                    (string) ($this->option('kind') ?: 'bare'),
                    (bool) $this->option('approve-provider-spend'),
                    $fast,
                    (string) ($this->option('profile') ?: 'fase_a'),
                    (int) $this->option('repetitions'),
                );
            } catch (\Throwable $e) {
                return ['status' => 'error', 'error' => $e->getMessage()];
            }
        }

        try {
            return $orchestrator->dryRun(
                $mode,
                $fast,
                (string) ($this->option('profile') ?: 'fase_a'),
                (int) $this->option('repetitions'),
            ) + ['status' => 'ok'];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    private function doctor(): array
    {
        $root = RunPaths::root();
        RunPaths::ensureDir($root);
        $registry = new SuiteRegistry;
        $registryOk = true;
        $registryError = null;
        try {
            $registry->assertComplete();
        } catch (\Throwable $e) {
            $registryOk = false;
            $registryError = $e->getMessage();
        }
        $checks = [
            'config_loaded' => config('atlas_rivals') !== null,
            'storage_writable' => is_writable($root),
            'provider_spend_allowed' => (bool) config('atlas_rivals.provider_spend_allowed'),
            'ledger_chain' => (new ResultLedger)->verifyChain(),
            'suite_registry_complete' => $registryOk,
            'suite_registry_error' => $registryError,
            'fase_a_closure' => (new FaseAClosureReceipt)->verify(),
        ];
        // resumo honesto dos benchmark repos externos (informativo; blocked
        // não derruba o doctor — é estado do mundo, não defeito do Rivals)
        $benchmarks = (new BenchmarkRepoManager)->status();
        $checks['benchmark_repos'] = [
            'total' => $benchmarks['total'],
            'running' => $benchmarks['running'],
            'blocked' => $benchmarks['blocked'],
        ];
        // Harbor / long-horizon suites: surface smoke state for claim-grade preflight
        $harborSuites = ['senior_swe_bench', 'swe_marathon', 'terminal_bench'];
        $harborPreflight = [];
        foreach ($harborSuites as $repoId) {
            $repo = collect((array) ($benchmarks['repos'] ?? []))->firstWhere('repo_id', $repoId)
                ?? collect((array) ($benchmarks['repos'] ?? []))->firstWhere('suite_id', $repoId);
            $harborPreflight[$repoId] = [
                'status' => is_array($repo) ? ($repo['status'] ?? 'unknown') : 'missing',
                'running' => is_array($repo) && ($repo['status'] ?? null) === 'running',
                'error' => is_array($repo) ? ($repo['error'] ?? null) : 'repo_not_in_benchmark_status',
            ];
        }
        $checks['harbor_preflight'] = $harborPreflight;
        $ok = $checks['config_loaded'] && $checks['storage_writable'] && $checks['ledger_chain']['verified'] && $registryOk;

        return [
            'schema_version' => 'atlas.rivals2.doctor.v1',
            'product' => 'Rivals',
            'version' => (string) config('atlas_rivals.version'),
            'status' => $ok ? 'ok' : 'error',
            'storage_root' => $root,
            'canonical_suite_ids' => $registry->externalSuiteIds(),
            'catalog' => $registryOk ? $registry->catalog() : [],
            'checks' => $checks,
        ];
    }

    private function benchmarks(): array
    {
        $status = (new BenchmarkRepoManager)->status();
        if ($this->option('strict') && (int) ($status['blocked'] ?? 0) > 0) {
            return $status + ['status' => 'error', 'error' => 'smoke_not_all_green'];
        }

        return $status;
    }

    private function preflight(): array
    {
        return $this->withRun(function (string $runId) {
            $plan = RunPlan::load($runId);
            $suiteId = (string) $plan->data['suite_id'];
            $checks = [
                'plan_valid' => true,
                'storage_writable' => is_writable(RunPaths::runDir($runId)),
            ];
            if (in_array($suiteId, (new SuiteRegistry)->externalSuiteIds(), true)) {
                $manifest = NativeExecutionManifest::load($runId);
                $repo = collect(
                    (new BenchmarkRepoManager)->status()['repos'] ?? []
                )->firstWhere('repo_id', $suiteId);
                $checks['manifest_valid'] = $manifest->data['run_id'] === $runId;
                $checks['benchmark_smoke_running'] = ($repo['status'] ?? null) === 'running';
                $plannedCommit = $plan->data['environment']['repo_commit'] ?? null;
                $checks['repo_commit_matches'] = $plannedCommit === null
                    || $plannedCommit === ($repo['commit'] ?? null);
            }
            $ok = ! in_array(false, $checks, true);
            if (! $ok) {
                return [
                    'status' => 'error',
                    'error' => 'rivals_preflight_failed',
                    'run_id' => $runId,
                    'checks' => $checks,
                ];
            }
            $state = (new RunStateMachine)->current($runId);
            if (($state['state'] ?? null) === RunStateMachine::PLANNED) {
                $state = (new RunStateMachine)->mark(
                    $runId,
                    RunStateMachine::PREFLIGHTED,
                    ['checks' => $checks],
                );
            }

            return [
                'schema_version' => 'atlas.rivals2.preflight.v1',
                'status' => 'ok',
                'run_id' => $runId,
                'suite_id' => $suiteId,
                'checks' => $checks,
                'state' => $state,
            ];
        }, lock: true);
    }

    private function runStatus(): array
    {
        return $this->withRun(function (string $runId) {
            $plan = RunPlan::load($runId);
            $stateMachine = new RunStateMachine;
            $state = $stateMachine->current($runId);
            $heartbeat = $this->eventsHeartbeat($runId);

            return [
                'schema_version' => 'atlas.rivals2.status.v1',
                'status' => 'ok',
                'run_id' => $runId,
                'suite_id' => $plan->data['suite_id'],
                'state' => $state,
                'resume_action' => $stateMachine->resumeAction($runId),
                'receipts_expected' => count($plan->expectedReceiptKeys()),
                'receipts_observed' => count(RunReceipt::loadAll($runId)),
                'events_present' => $heartbeat['events_present'],
                'events_count' => $heartbeat['events_count'],
                'last_event_type' => $heartbeat['last_event_type'],
                'last_event_at' => $heartbeat['last_event_at'],
                'heartbeat_age_seconds' => $heartbeat['heartbeat_age_seconds'],
                'stall' => $heartbeat['stall'],
                'latest_ledger_entry' => collect((new ResultLedger)->entries())
                    ->reverse()
                    ->firstWhere('run_id', $runId),
            ];
        });
    }

    /**
     * @return array{events_present: bool, events_count: int, last_event_type: ?string, last_event_at: ?string, heartbeat_age_seconds: ?int, stall: bool}
     */
    private function eventsHeartbeat(string $runId): array
    {
        $path = RunPaths::eventsPath($runId);
        $out = [
            'events_present' => is_file($path) && filesize($path) > 0,
            'events_count' => 0,
            'last_event_type' => null,
            'last_event_at' => null,
            'heartbeat_age_seconds' => null,
            'stall' => false,
        ];
        if (! $out['events_present']) {
            $out['stall'] = true;

            return $out;
        }
        $lastType = null;
        $lastAt = null;
        $count = 0;
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $event = json_decode($line, true);
            if (! is_array($event)) {
                continue;
            }
            $count++;
            $lastType = is_string($event['event_type'] ?? null) ? $event['event_type'] : $lastType;
            $lastAt = is_string($event['timestamp'] ?? null) ? $event['timestamp'] : $lastAt;
        }
        $out['events_count'] = $count;
        $out['last_event_type'] = $lastType;
        $out['last_event_at'] = $lastAt;
        if (is_string($lastAt) && $lastAt !== '') {
            $ts = strtotime($lastAt);
            if ($ts !== false) {
                $age = max(0, time() - $ts);
                $out['heartbeat_age_seconds'] = $age;
                $running = ($this->loadStateLabel($runId) ?? '') === RunStateMachine::NATIVE_RUNNING;
                // Stall = in-flight run with no event for >15min
                $out['stall'] = $running && $age > 900;
            }
        }

        return $out;
    }

    private function loadStateLabel(string $runId): ?string
    {
        $state = (new RunStateMachine)->current($runId);

        return is_string($state['state'] ?? null) ? $state['state'] : null;
    }

    private function assertCleanWorktreeIfRequired(): void
    {
        if (! (bool) $this->option('require-clean-worktree')) {
            return;
        }
        $dirty = trim((string) shell_exec('git -C '.escapeshellarg(base_path()).' status --porcelain 2>/dev/null'));
        if ($dirty !== '') {
            throw new \RuntimeException('rivals_require_clean_worktree_failed');
        }
    }

    /** Read-only evaluation of a preregistered campaign manifest. */
    private function worldReadiness(): array
    {
        $path = trim((string) $this->option('file'));
        if ($path === '' || ! is_file($path)) {
            return [
                'schema_version' => 'atlas.rivals2.world_trial_readiness.v1',
                'status' => 'error',
                'error' => 'world_readiness_manifest_file_required',
            ];
        }

        try {
            $manifest = (new \App\Services\Ai\Rivals\Core\CampaignManifest)->readFile($path);
        } catch (\InvalidArgumentException $e) {
            return [
                'schema_version' => 'atlas.rivals2.world_trial_readiness.v1',
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }

        $readiness = (new WorldTrialReadiness)->evaluate($manifest);

        return [
            'schema_version' => 'atlas.rivals2.world_trial_readiness.v1',
            'status' => $readiness['status'],
            'manifest_schema' => $manifest['schema_version'] ?? null,
            'readiness' => $readiness,
            'claim_issued' => false,
        ];
    }

    private function resume(): array
    {
        return $this->withRun(function (string $runId) {
            $stateMachine = new RunStateMachine;
            $next = $stateMachine->resumeAction($runId);

            return [
                'schema_version' => 'atlas.rivals2.resume.v1',
                'status' => in_array($next, ['operator_intervention', 'plan'], true)
                    ? 'error'
                    : 'ok',
                'run_id' => $runId,
                'state' => $stateMachine->current($runId),
                'next_action' => $next,
                'hint' => match ($next) {
                    'native_execution' => 'Run remaining manifest units; completed unit receipts are idempotent.',
                    'build_evidence' => 'Run atlas:rivals verify after evidence is rebuilt.',
                    'verify' => 'Run atlas:rivals verify --run='.$runId,
                    'adjudicate' => 'Run atlas:rivals adjudicate --run='.$runId,
                    'report' => 'Run atlas:rivals report --run='.$runId,
                    'bundle' => 'Run atlas:rivals report --run='.$runId.' before bundle support.',
                    'complete' => 'Run is already bundled.',
                    default => 'Operator intervention is required.',
                },
            ];
        });
    }

    private function cancel(): array
    {
        $reason = trim((string) $this->option('reason'));
        if ($reason === '') {
            return ['status' => 'error', 'error' => 'cancel_requires_reason'];
        }

        return $this->withRun(function (string $runId) use ($reason) {
            $state = (new RunStateMachine)->cancel($runId, $reason);

            return [
                'schema_version' => 'atlas.rivals2.cancel.v1',
                'status' => 'ok',
                'run_id' => $runId,
                'state' => $state,
            ];
        }, lock: true);
    }

    /** Smoke REAL (clone/install/execução externa) de 1 repo ou de todos. */
    private function benchmarkSmoke(): array
    {
        $manager = new BenchmarkRepoManager;
        $repo = (string) $this->option('repo');
        if ($repo !== '') {
            try {
                $repo = (new SuiteRegistry)->canonicalize($repo, allowLegacyAlias: false);
            } catch (\Throwable $e) {
                return ['status' => 'error', 'error' => $e->getMessage()];
            }
            if (! isset($manager->registry()[$repo])) {
                return ['status' => 'error', 'error' => "unknown_benchmark_repo:{$repo}"];
            }
        }
        $ids = $repo !== '' ? [$repo] : array_keys($manager->registry());

        $results = [];
        $blocked = 0;
        foreach ($ids as $id) {
            try {
                $result = $manager->smoke($id);
            } catch (\Throwable $e) {
                $result = ['repo_id' => $id, 'status' => 'blocked', 'error' => $e->getMessage()];
            }
            $blocked += $result['status'] === 'blocked' ? 1 : 0;
            $results[] = $result;
        }

        $payload = [
            'schema_version' => 'atlas.rivals2.benchmark_smoke_action.v1',
            // smoke que falha é resultado HONESTO (blocked), não erro do comando;
            // erro do comando = repo desconhecido/registry vazio
            'status' => $results === [] ? 'error' : 'ok',
            'error' => $results === [] ? 'no_repos_in_registry' : null,
            'running' => count($results) - $blocked,
            'blocked' => $blocked,
            'results' => $results,
        ];
        if ($this->option('strict') && $blocked > 0) {
            $payload['status'] = 'error';
            $payload['error'] = 'smoke_not_all_green';
        }

        return $payload;
    }

    /** Dispatcher canônico: roda o run mais recente conforme a suite do plano. */
    private function runSuite(): array
    {
        return $this->withRun(function (string $runId) {
            $plan = RunPlan::load($runId);
            $suiteId = $plan->data['suite_id'];

            return match (true) {
                $suiteId === LocalFakeSuiteAdapter::SUITE_ID => $this->runFake(),
                $this->adapterFor($suiteId) instanceof AtlasBenchSuiteAdapter => $this->runBench(),
                default => [
                    'status' => 'error',
                    'error' => "suite_runs_externally:{$suiteId} — use benchmark-smoke + import-results",
                ],
            };
        });
    }

    private function arms(): array
    {
        $registry = new ArmRegistry;
        $arms = [];
        $errors = [];
        foreach (explode(',', (string) $this->option('arms')) as $spec) {
            try {
                $arms[] = $registry->parse(trim($spec));
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        return [
            'schema_version' => 'atlas.rivals2.arms.v1',
            'status' => $errors === [] ? 'ok' : 'error',
            'arms' => $arms,
            'errors' => $errors,
            'runtimes' => config('atlas_rivals.runtimes'),
        ];
    }

    private function adapterFor(string $suiteId, bool $allowLegacyAlias = true): ?BenchmarkSuiteAdapter
    {
        try {
            return (new SuiteRegistry)->adapterFor($suiteId, $allowLegacyAlias);
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveSuiteOption(bool $forNewPlan = true): array
    {
        $raw = (string) $this->option('suite');
        $registry = new SuiteRegistry;
        if ($forNewPlan && $registry->isLegacyAlias($raw)) {
            return [
                'ok' => false,
                'error' => "legacy_alias_forbidden_for_new_plans:{$raw}",
                'hint' => 'Use canonical suite_id '.$registry->canonicalize($raw),
            ];
        }
        try {
            $suiteId = $registry->canonicalize($raw, allowLegacyAlias: ! $forNewPlan);

            return ['ok' => true, 'suite_id' => $suiteId, 'adapter' => $registry->adapterFor($suiteId, allowLegacyAlias: ! $forNewPlan)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Importa cases de uma suite externa para external/<suite>/cases/. */
    private function importCases(): array
    {
        $resolved = $this->resolveSuiteOption(forNewPlan: true);
        if (! ($resolved['ok'] ?? false)) {
            return ['status' => 'error'] + $resolved;
        }
        /** @var BenchmarkSuiteAdapter $adapter */
        $adapter = $resolved['adapter'];
        $suiteId = $resolved['suite_id'];
        $source = (string) $this->option('file');
        try {
            $source = RunPaths::assertImportSource($source);
        } catch (\Throwable $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }
        $files = is_dir($source) ? glob($source.'/*.json') : (is_file($source) ? [$source] : []);
        if ($files === []) {
            return ['status' => 'error', 'error' => "no_case_files_at:{$source}"];
        }

        $sourceRepo = trim((string) $this->option('source-repo')) ?: $suiteId;
        $dir = RunPaths::root()."/external/{$suiteId}/cases";
        RunPaths::ensureDir($dir);
        $imported = [];
        $rejected = [];
        foreach ($files as $file) {
            $payload = json_decode(file_get_contents($file), true);
            $cases = isset($payload['case_id']) ? [$payload] : (array) ($payload['cases'] ?? $payload);
            foreach ($cases as $case) {
                if (! is_array($case) || ! isset($case['case_id'], $case['task_type'])) {
                    $rejected[] = ['file' => basename($file), 'reason' => 'missing_case_id_or_task_type'];

                    continue;
                }
                try {
                    RunPaths::assertCaseId((string) $case['case_id']);
                } catch (\Throwable $e) {
                    $rejected[] = ['file' => basename($file), 'reason' => $e->getMessage()];

                    continue;
                }
                $case['suite_id'] = $suiteId;
                $case['source_repo'] = $case['source_repo'] ?? $sourceRepo;
                file_put_contents($dir.'/'.$case['case_id'].'.json', json_encode($case, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $imported[] = $case['case_id'];
            }
        }

        return [
            'schema_version' => 'atlas.rivals2.import_cases.v1',
            'status' => 'ok',
            'suite' => $suiteId,
            'adapter' => $adapter->suiteId(),
            'imported' => $imported,
            'rejected' => $rejected,
        ];
    }

    /**
     * Importa o resultado NATIVO de uma execução externa para o run e o
     * transforma em receipts de primeira classe (→ verify/adjudicate/ledger).
     */
    private function importResults(): array
    {
        return $this->withRun(function (string $runId) {
            $plan = RunPlan::load($runId);
            $suiteId = (new SuiteRegistry)->canonicalize((string) $plan->data['suite_id']);
            $adapter = $this->adapterFor($suiteId);
            if ($adapter === null) {
                return ['status' => 'error', 'error' => 'unknown_suite:'.$suiteId];
            }
            $source = (string) $this->option('file');
            if (! is_file($source) && ! is_dir($source)) {
                return ['status' => 'error', 'error' => "results_source_not_found:{$source}"];
            }
            $source = RunPaths::assertImportSource($source);

            $runDir = RunPaths::runDir($runId);
            $receiptsPath = RunPaths::receiptsPath($runId);
            $replace = (bool) $this->option('replace-import');
            if (is_file($receiptsPath) && ! $replace) {
                return [
                    'status' => 'error',
                    'error' => 'import_already_exists',
                    'hint' => 'Pass --replace-import to rebuild receipts/evidence from a new native import.',
                ];
            }
            $states = new RunStateMachine;
            if ($replace) {
                if (is_file(RunPaths::adjudicationPath($runId))
                    || is_file(RunPaths::reportPath($runId))) {
                    (new ResultLedger)->supersede($runId, 'replace_import');
                }
                foreach ([
                    'receipts.jsonl',
                    'evidence.json',
                    'evidence_pack.json',
                    'adjudication.json',
                    'report.json',
                    'report.md',
                    'uplift.json',
                ] as $stale) {
                    $path = $runDir.'/'.$stale;
                    if (is_file($path)) {
                        unlink($path);
                    }
                }
                File::deleteDirectory(RunPaths::nativeReceiptsDir($runId));
                File::deleteDirectory(RunPaths::nativeResultsDir($runId));
                $states->resetForReplaceImport($runId, ['source' => $source]);
            }

            $importSummary = null;
            if (is_file(RunPaths::nativeManifestPath($runId))) {
                $importSummary = (new NativeExecutionBundleImporter)->import(
                    $runId,
                    $adapter->suiteId(),
                    $source,
                );
            } else {
                if (! is_file($source)) {
                    return ['status' => 'error', 'error' => 'legacy_results_file_required'];
                }
                $destDir = $runDir.'/external_results';
                RunPaths::ensureDir($destDir);
                copy($source, $destDir.'/'.$adapter->suiteId().'.json');
                $importSummary = [
                    'mode' => 'legacy_v1',
                    'results_imported' => 1,
                    'native_receipts_imported' => 0,
                ];
            }

            $states->mark($runId, RunStateMachine::NATIVE_RUNNING, ['source' => $source]);
            // External adapters auto-load the plan for native→canonical arm remap.
            $receipts = $adapter->ingestResults($runDir);
            foreach ($receipts as $receipt) {
                $receipt->append();
            }
            $states->mark($runId, RunStateMachine::RESULTS_IMPORTED, [
                'receipts' => count($receipts),
                'replaced' => $replace,
            ]);
            $pack = (new EvidencePackBuilder)->build($runId);
            $states->mark($runId, RunStateMachine::EVIDENCE_BUILT, [
                'evidence_hash' => $pack['evidence_hash'] ?? null,
            ]);

            return [
                'schema_version' => 'atlas.rivals2.import_results.v1',
                'status' => 'ok',
                'run_id' => $runId,
                'suite' => $adapter->suiteId(),
                'receipts_ingested' => count($receipts),
                'replaced' => $replace,
                'native_import' => $importSummary,
                'evidence_pack_built' => ($pack['receipts_hash']['present'] ?? false) === true,
                'state' => (new RunStateMachine)->current($runId),
            ];
        }, lock: true);
    }

    private function mine(): array
    {
        // --suite=elite_reality minera com pisos elite; default segue atlas_bench
        $adapter = $this->adapterFor((string) $this->option('suite'));
        if (! $adapter instanceof AtlasBenchSuiteAdapter) {
            $adapter = new AtlasBenchSuiteAdapter;
        }
        $cases = $adapter->mineCases((int) $this->option('limit'));

        return [
            'schema_version' => 'atlas.rivals2.mine.v1',
            'status' => 'ok',
            'suite' => $adapter->suiteId(),
            'mined' => count($cases),
            'cases' => array_map(fn ($c) => [
                'case_id' => $c['case_id'],
                'task_type' => $c['task_type'],
                'title' => $c['title'],
                'diff_lines' => $c['diff_lines'],
            ], $cases),
        ];
    }

    private function plan(): array
    {
        try {
            $this->assertCleanWorktreeIfRequired();
        } catch (\Throwable $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }
        $resolved = $this->resolveSuiteOption(forNewPlan: true);
        if (! ($resolved['ok'] ?? false)) {
            return ['status' => 'error'] + $resolved;
        }
        /** @var BenchmarkSuiteAdapter $adapter */
        $adapter = $resolved['adapter'];
        $suiteId = $resolved['suite_id'];
        $cases = $adapter->listCases();
        $requestedCases = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) ($this->option('cases') ?? '')),
        )));
        if ($requestedCases !== []) {
            $knownCases = array_column($cases, null, 'case_id');
            $unknownCases = array_values(array_diff($requestedCases, array_keys($knownCases)));
            if ($unknownCases !== []) {
                return [
                    'status' => 'error',
                    'error' => 'unknown_cases:'.implode(',', $unknownCases),
                ];
            }
            $cases = array_values(array_map(
                fn (string $caseId): array => $knownCases[$caseId],
                $requestedCases,
            ));
        }
        if ($cases === []) {
            return ['status' => 'error', 'error' => 'no_cases_available_mine_first'];
        }
        $maxCases = (int) ($this->option('max-cases') ?? 0);
        if ($maxCases > 0 && count($cases) > $maxCases) {
            // Determinístico (ordem do listCases): rodada bounded, nunca amostra oculta.
            $cases = array_slice($cases, 0, $maxCases);
        }

        $budgetUsd = $this->option('budget') !== null && $this->option('budget') !== ''
            ? (float) $this->option('budget')
            : 0.0;
        $maxMinutes = $this->option('max-minutes') !== null && $this->option('max-minutes') !== ''
            ? max(1, (int) $this->option('max-minutes'))
            : (int) config("atlas_rivals.benchmarks.repos.{$suiteId}.native_timeout_minutes", 30);
        if ($budgetUsd > 0 && ! $this->option('approve-provider-spend')) {
            return [
                'status' => 'error',
                'error' => 'provider_spend_not_approved',
                'hint' => 'Pass --approve-provider-spend when budget > 0.',
            ];
        }

        $judgeConfig = null;
        $judgePath = trim((string) $this->option('judge-config'));
        if ($judgePath !== '') {
            if (! is_file($judgePath)) {
                return ['status' => 'error', 'error' => "judge_config_not_found:{$judgePath}"];
            }
            try {
                $judgePath = RunPaths::assertImportSource($judgePath);
            } catch (\Throwable $e) {
                return ['status' => 'error', 'error' => $e->getMessage()];
            }
            $judgeConfig = json_decode((string) file_get_contents($judgePath), true);
            if (! is_array($judgeConfig)) {
                return ['status' => 'error', 'error' => 'judge_config_invalid_json'];
            }
        }

        $registry = new ArmRegistry;
        try {
            $arms = array_map(
                fn ($s) => $registry->parse(trim($s), $suiteId),
                explode(',', (string) $this->option('arms'))
            );
        } catch (\Throwable $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }

        $sourceRepo = trim((string) $this->option('source-repo')) ?: $suiteId;
        $repoMeta = ((new BenchmarkRepoManager)->status()['repos'] ?? []);
        $repoRow = collect($repoMeta)->firstWhere('repo_id', $suiteId) ?? [];

        $plan = RunPlan::make(
            $adapter->suiteId(),
            array_column($cases, 'case_id'),
            $arms,
            (int) $this->option('repetitions'),
            ['max_usd' => $budgetUsd, 'max_minutes' => $maxMinutes],
            (int) $this->option('seed'),
            $judgeConfig,
        );
        // pin upstream provenance into environment for claim_scope
        $data = $plan->data;
        $data['environment']['source_repo'] = $sourceRepo;
        $data['environment']['repo_commit'] = $repoRow['commit'] ?? null;
        $data['environment']['adapter_hash'] = hash('sha256', $adapter::class.'|'.(string) config('atlas_rivals.version', '2.0'));
        $data['environment']['approve_provider_spend'] = (bool) $this->option('approve-provider-spend');
        $plan = RunPlan::fromArray($data);
        $preregistration = Preregistration::fromPlan($plan);
        $data = $plan->data;
        $data['preregistration_hash'] = $preregistration->hash();
        $plan = RunPlan::fromArray($data);
        $runId = $plan->persist();
        $preregistration->persist();
        $freeze = null;
        if (in_array($suiteId, (new SuiteRegistry)->externalSuiteIds(), true)) {
            $freeze = FrozenUnitManifest::fromPlan(
                $plan,
                $cases,
                app()->environment('testing') || (bool) $this->option('allow-synthetic-frozen')
            );
            $freeze->persist();
        }
        (new RunStateMachine)->mark($runId, RunStateMachine::PLANNED, [
            'suite_id' => $suiteId,
            'cases' => count($cases),
            'arms' => count($arms),
        ]);
        $commands = $adapter->planCommands($plan);
        $manifest = null;
        if (in_array($suiteId, (new SuiteRegistry)->externalSuiteIds(), true)) {
            $manifest = NativeExecutionManifest::fromPlan($plan, $adapter, $commands);
            $manifest->persist();
        }

        return [
            'schema_version' => 'atlas.rivals2.plan_action.v1',
            'status' => 'ok',
            'run_id' => $runId,
            'suite_id' => $suiteId,
            'state' => (new RunStateMachine)->current($runId),
            'commands' => $commands,
            'native_manifest_path' => $manifest !== null
                ? RunPaths::nativeManifestPath($runId)
                : null,
            'native_manifest_hash' => $manifest?->hash(),
            'preregistration_hash' => $preregistration->hash(),
            'frozen_unit_manifest_hash' => $freeze?->hash(),
            'note' => $manifest !== null
                ? 'Execute native commands outside PHP, then atlas:rivals import-results --run='.$runId.' --file=...'
                : null,
        ];
    }

    private function runFake(): array
    {
        return $this->withRun(function (string $runId) {
            $plan = RunPlan::load($runId);
            if ($plan->data['suite_id'] !== LocalFakeSuiteAdapter::SUITE_ID) {
                return ['status' => 'error', 'error' => 'run_is_not_local_fake'];
            }
            $adapter = new LocalFakeSuiteAdapter;
            $states = new RunStateMachine;
            $states->mark($runId, RunStateMachine::NATIVE_RUNNING, ['executor' => 'local_fake']);
            $adapter->execute($plan);
            $states->mark($runId, RunStateMachine::RESULTS_IMPORTED, [
                'receipts' => count($adapter->ingestResults(RunPaths::runDir($runId))),
            ]);
            $pack = (new EvidencePackBuilder)->build($runId);
            $states->mark($runId, RunStateMachine::EVIDENCE_BUILT);

            return [
                'schema_version' => 'atlas.rivals2.run_fake.v1',
                'status' => 'ok',
                'run_id' => $runId,
                'receipts' => count($adapter->ingestResults(RunPaths::runDir($runId))),
                'evidence_pack_built' => ($pack['receipts_hash']['present'] ?? false) === true,
            ];
        }, lock: true);
    }

    private function runBench(): array
    {
        return $this->withRun(function (string $runId) {
            $plan = RunPlan::load($runId);
            $adapter = $this->adapterFor($plan->data['suite_id']);
            if (! $adapter instanceof AtlasBenchSuiteAdapter) {
                return ['status' => 'error', 'error' => 'run_is_not_atlas_bench'];
            }
            $states = new RunStateMachine;
            $states->mark($runId, RunStateMachine::NATIVE_RUNNING, ['executor' => 'atlas_bench']);
            $adapter->execute($plan);
            $states->mark($runId, RunStateMachine::RESULTS_IMPORTED, [
                'receipts' => count($adapter->ingestResults(RunPaths::runDir($runId))),
            ]);
            $pack = (new EvidencePackBuilder)->build($runId);
            $states->mark($runId, RunStateMachine::EVIDENCE_BUILT);

            return [
                'schema_version' => 'atlas.rivals2.run_bench.v1',
                'status' => 'ok',
                'run_id' => $runId,
                'receipts' => count($adapter->ingestResults(RunPaths::runDir($runId))),
                'evidence_pack_built' => ($pack['receipts_hash']['present'] ?? false) === true,
            ];
        }, lock: true);
    }

    private function bundle(): array
    {
        return $this->withRun(function (string $runId) {
            $states = new RunStateMachine;
            $states->assertAtLeast($runId, RunStateMachine::REPORTED);
            $states->mark($runId, RunStateMachine::BUNDLED, [
                'status' => 'building_manifest',
            ]);
            $bundle = (new BundleManifest)->build($runId);
            $verify = (new BundleManifest)->verify(RunPaths::runDir($runId));
            if (! $verify['verified']) {
                $states->fail($runId, 'bundle_verification_failed');

                return [
                    'status' => 'error',
                    'error' => 'bundle_verification_failed',
                    'run_id' => $runId,
                    'verify' => $verify,
                ];
            }
            (new ResultLedger)->appendBundle($runId, $bundle);

            return [
                'schema_version' => 'atlas.rivals2.bundle_action.v1',
                'status' => 'ok',
                'run_id' => $runId,
                'bundle_path' => RunPaths::bundleManifestPath($runId),
                'bundle_hash' => $bundle['bundle_hash'],
                'files' => count($bundle['files']),
                'verified' => true,
            ];
        }, lock: true);
    }

    private function verifyBundle(): array
    {
        $source = trim((string) $this->option('file'));
        if ($source === '') {
            $runId = $this->option('run') ?: RunPaths::latestRunId();
            $source = $runId !== null ? RunPaths::runDir($runId) : '';
        }
        if ($source === '' || ! is_dir($source)) {
            return ['status' => 'error', 'error' => 'bundle_directory_required'];
        }
        $verify = (new BundleManifest)->verify($source);

        return [
            'schema_version' => 'atlas.rivals2.bundle_verification.v1',
            'status' => $verify['verified'] ? 'ok' : 'error',
            'bundle_directory' => $source,
        ] + $verify;
    }

    private function closure(): array
    {
        $closure = new FaseAClosureReceipt;
        if ($this->option('verify')) {
            $verify = $closure->verify();

            return [
                'schema_version' => 'atlas.rivals2.fase_a_closure_verification.v1',
                'status' => $verify['verified'] ? 'ok' : 'error',
            ] + $verify;
        }

        $tests = ProcessFacade::path(base_path())
            ->env([
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => ':memory:',
                'ATLAS_ALLOW_LIVE_DB_TESTS' => '0',
            ])
            ->timeout(300)
            ->run([
                PHP_BINARY,
                'artisan',
                'test',
                'tests/Unit/Ai/Rivals',
                'tests/Feature/Ai/Rivals',
                '--no-coverage',
            ]);
        $docs = ProcessFacade::path(base_path())
            ->timeout(120)
            ->run([
                PHP_BINARY,
                'artisan',
                'atlas:engineering:knowledge',
                'docs-health',
                '--enforce',
                '--json',
            ]);
        $codeGates = [
            'tests' => [
                'passed' => $tests->successful(),
                'exit_code' => $tests->exitCode(),
                'output_sha256' => hash('sha256', $tests->output().$tests->errorOutput()),
            ],
            'docs_health' => [
                'passed' => $docs->successful(),
                'exit_code' => $docs->exitCode(),
                'output_sha256' => hash('sha256', $docs->output().$docs->errorOutput()),
            ],
        ];
        $receipt = $closure->build($codeGates);
        $authorized = ($receipt['fase_a_100_percent_authorized'] ?? false) === true;

        return [
            'schema_version' => 'atlas.rivals2.fase_a_closure_action.v1',
            'status' => $authorized ? 'ok' : 'error',
            'authorized' => $authorized,
            'closure_path' => RunPaths::closureReceiptPath(),
            'closure_hash' => $receipt['closure_hash'],
            'gates' => $receipt['gates'],
            'blockers' => $receipt['blockers'],
        ];
    }

    private function ledger(): array
    {
        $ledger = new ResultLedger;
        if ($this->option('quarantine-epoch')) {
            return $ledger->quarantineCorruptEpoch();
        }
        if ($this->option('repair-semantic')) {
            return $this->withRun(function (string $runId) use ($ledger): array {
                return $ledger->repairSemanticForRun($runId);
            }, lock: true);
        }
        if ($this->option('verify')) {
            $chain = $this->option('semantic')
                ? $ledger->verifySemantic()
                : $ledger->verifyChain();

            return [
                'schema_version' => 'atlas.rivals2.ledger.v2',
                'mode' => $this->option('semantic') ? 'semantic' : 'chain',
                'status' => $chain['verified'] ? 'ok' : 'error',
            ] + $chain;
        }

        return ['schema_version' => 'atlas.rivals2.ledger.v2', 'status' => 'ok', 'tail' => $ledger->tail()];
    }

    private function autopsy(): array
    {
        return $this->withRun(function (string $runId): array {
            $autopsy = (new RunAutopsy)->build($runId);
            $payload = $autopsy + ['status' => ($autopsy['trust']['is_atlas_fact'] ?? false) ? 'ok' : 'diagnostic'];
            if ($this->option('md')) {
                $payload['markdown'] = (new RunAutopsy)->toMarkdown($autopsy);
            }

            return $payload;
        });
    }

    private function withRun(callable $fn, bool $lock = false): array
    {
        $runId = $this->option('run') ?: RunPaths::latestRunId();
        if ($runId === null) {
            return ['status' => 'error', 'error' => 'no_run_found_use_plan_first'];
        }

        try {
            return $lock
                ? RunLock::exclusive($runId, fn (): array => $fn($runId))
                : $fn($runId);
        } catch (\Throwable $e) {
            return ['status' => 'error', 'run_id' => $runId, 'error' => $e->getMessage()];
        }
    }
}
