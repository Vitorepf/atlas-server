<?php

namespace App\Console\Commands;

use App\Services\Ai\Rivals\Adapters\AtlasBenchSuiteAdapter;
use App\Services\Ai\Rivals\Adapters\LocalFakeSuiteAdapter;
use App\Services\Ai\Rivals\Contracts\BenchmarkSuiteAdapter;
use App\Services\Ai\Rivals\Core\Adjudicator;
use App\Services\Ai\Rivals\Core\ArmRegistry;
use App\Services\Ai\Rivals\Core\ModelRegistry;
use App\Services\Ai\Rivals\Core\ReplayVerifier;
use App\Services\Ai\Rivals\Core\ReportBuilder;
use App\Services\Ai\Rivals\Core\ResultLedger;
use App\Services\Ai\Rivals\Core\RunPlan;
use App\Services\Ai\Rivals\Support\RunPaths;
use Illuminate\Console\Command;

/**
 * Único entrypoint do Rivals (produto público: Rivals, versão 2.0; substitui
 * atlas:forge:rivals). Fail-closed: nenhuma ação chama provider por conta própria.
 * atlas:rivals2 permanece só como alias temporário de compatibilidade.
 */
class AtlasRivalsCommand extends Command
{
    protected $aliases = ['atlas:rivals2'];

    protected $signature = 'atlas:rivals
        {action : doctor|benchmarks|benchmark-smoke|models|arms|mine|plan|run|run-fake|run-bench|verify|adjudicate|report|report-all|uplift|ledger}
        {--repo= : (benchmark-smoke) repo_id do registry (vazio = todos)}
        {--model= : (uplift) model_id comparado nos dois runtimes}
        {--base-runtime=bare}
        {--atlas-runtime=atlas_dev}
        {--suite=local_fake}
        {--limit=5 : (mine) máximo de cases a minerar}
        {--file= : (import-cases/import-results) arquivo ou diretório de origem}
        {--run= : run_id (default: run mais recente)}
        {--arms=local_fake_model@bare : lista model@runtime separada por vírgula}
        {--repetitions=3}
        {--seed=1}
        {--verify : (ledger) verifica a hash chain}
        {--json}';

    protected $description = 'Rivals 2.0 — benchmark interno Atlas (model-vs-model + Atlas uplift), fail-closed';

    public function handle(): int
    {
        $action = $this->argument('action');
        $payload = match ($action) {
            'doctor' => $this->doctor(),
            'benchmarks' => (new \App\Services\Ai\Rivals\Benchmarks\BenchmarkRepoManager)->status(),
            'benchmark-smoke' => $this->benchmarkSmoke(),
            'run' => $this->runSuite(),
            'models' => ['schema_version' => 'atlas.rivals2.models.v1', 'models' => (new ModelRegistry)->all()],
            'arms' => $this->arms(),
            'mine' => $this->mine(),
            'import-cases' => $this->importCases(),
            'import-results' => $this->importResults(),
            'plan' => $this->plan(),
            'run-fake' => $this->runFake(),
            'run-bench' => $this->runBench(),
            'verify' => $this->withRun(fn ($runId) => ['run_id' => $runId] + (new ReplayVerifier)->verify($runId)),
            'adjudicate' => $this->withRun(function ($runId) {
                $adjudication = (new Adjudicator)->adjudicate($runId);
                // toda adjudicação (válida OU inválida) entra na chain — auditoria completa
                $entry = (new ResultLedger)->append($runId, $adjudication);

                return $adjudication + ['ledger_entry_id' => $entry['entry_id']];
            }),
            'report' => $this->withRun(fn ($runId) => (new ReportBuilder)->build($runId)),
            'report-all' => (new ReportBuilder)->buildAll(),
            'uplift' => $this->withRun(function ($runId) {
                $model = (string) $this->option('model');
                if ($model === '') {
                    return ['status' => 'error', 'error' => 'uplift_requires_model_option'];
                }

                return (new \App\Services\Ai\Rivals\Core\AtlasUpliftRunner)->compare(
                    $runId,
                    $model,
                    (string) $this->option('base-runtime'),
                    (string) $this->option('atlas-runtime'),
                );
            }),
            'ledger' => $this->ledger(),
            default => ['status' => 'error', 'error' => "unknown_action:{$action}"],
        };

        $isError = ($payload['status'] ?? 'ok') === 'error'
            || ($payload['verified'] ?? true) === false
            || ($payload['verdict'] ?? 'valid') === 'invalid';

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return $isError ? self::FAILURE : self::SUCCESS;
    }

    private function doctor(): array
    {
        $root = RunPaths::root();
        RunPaths::ensureDir($root);
        $checks = [
            'config_loaded' => config('atlas_rivals') !== null,
            'storage_writable' => is_writable($root),
            'provider_spend_allowed' => (bool) config('atlas_rivals.provider_spend_allowed'),
            'ledger_chain' => (new ResultLedger)->verifyChain(),
        ];
        // resumo honesto dos benchmark repos externos (informativo; blocked
        // não derruba o doctor — é estado do mundo, não defeito do Rivals)
        $benchmarks = (new \App\Services\Ai\Rivals\Benchmarks\BenchmarkRepoManager)->status();
        $checks['benchmark_repos'] = [
            'total' => $benchmarks['total'],
            'running' => $benchmarks['running'],
            'blocked' => $benchmarks['blocked'],
        ];
        $ok = $checks['config_loaded'] && $checks['storage_writable'] && $checks['ledger_chain']['verified'];

        return [
            'schema_version' => 'atlas.rivals2.doctor.v1',
            'product' => 'Rivals',
            'version' => (string) config('atlas_rivals.version'),
            'status' => $ok ? 'ok' : 'error',
            'storage_root' => $root,
            'checks' => $checks,
        ];
    }

    /** Smoke REAL (clone/install/execução externa) de 1 repo ou de todos. */
    private function benchmarkSmoke(): array
    {
        $manager = new \App\Services\Ai\Rivals\Benchmarks\BenchmarkRepoManager;
        $repo = (string) $this->option('repo');
        if ($repo !== '' && ! isset($manager->registry()[$repo])) {
            return ['status' => 'error', 'error' => "unknown_benchmark_repo:{$repo}"];
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

        return [
            'schema_version' => 'atlas.rivals2.benchmark_smoke_action.v1',
            // smoke que falha é resultado HONESTO (blocked), não erro do comando;
            // erro do comando = repo desconhecido/registry vazio
            'status' => $results === [] ? 'error' : 'ok',
            'error' => $results === [] ? 'no_repos_in_registry' : null,
            'running' => count($results) - $blocked,
            'blocked' => $blocked,
            'results' => $results,
        ];
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

    private function adapterFor(string $suiteId): ?BenchmarkSuiteAdapter
    {
        return match ($suiteId) {
            LocalFakeSuiteAdapter::SUITE_ID => new LocalFakeSuiteAdapter,
            AtlasBenchSuiteAdapter::SUITE_ID => new AtlasBenchSuiteAdapter,
            \App\Services\Ai\Rivals\Adapters\EliteRealitySuiteAdapter::SUITE_ID => new \App\Services\Ai\Rivals\Adapters\EliteRealitySuiteAdapter,
            'senior_swe_bench' => new \App\Services\Ai\Rivals\Adapters\External\SeniorSweBenchAdapter,
            'harbor_terminal_bench' => new \App\Services\Ai\Rivals\Adapters\External\HarborTerminalBenchAdapter,
            'aider_polyglot' => new \App\Services\Ai\Rivals\Adapters\External\AiderBenchAdapter,
            'inspect_evals' => new \App\Services\Ai\Rivals\Adapters\External\InspectEvalsAdapter,
            'swe_bench_live' => new \App\Services\Ai\Rivals\Adapters\External\SweBenchLiveAdapter,
            'hal_harness' => new \App\Services\Ai\Rivals\Adapters\External\HalHarnessAdapter,
            'tau2_bfcl' => new \App\Services\Ai\Rivals\Adapters\External\Tau2BfclAdapter,
            'live_code_bench' => new \App\Services\Ai\Rivals\Adapters\External\LiveCodeBenchAdapter,
            default => null,
        };
    }

    /** Importa cases de uma suite externa para external/<suite>/cases/. */
    private function importCases(): array
    {
        $suiteId = (string) $this->option('suite');
        if ($this->adapterFor($suiteId) === null) {
            return ['status' => 'error', 'error' => "unknown_suite:{$suiteId}"];
        }
        $source = (string) $this->option('file');
        $files = is_dir($source) ? glob($source.'/*.json') : (is_file($source) ? [$source] : []);
        if ($files === []) {
            return ['status' => 'error', 'error' => "no_case_files_at:{$source}"];
        }

        $dir = RunPaths::root()."/external/{$suiteId}/cases";
        RunPaths::ensureDir($dir);
        $imported = [];
        $rejected = [];
        foreach ($files as $file) {
            $payload = json_decode(file_get_contents($file), true);
            $cases = isset($payload['case_id']) ? [$payload] : (array) $payload;
            foreach ($cases as $case) {
                if (! isset($case['case_id'], $case['task_type'])) {
                    $rejected[] = ['file' => basename($file), 'reason' => 'missing_case_id_or_task_type'];
                    continue;
                }
                file_put_contents($dir.'/'.$case['case_id'].'.json', json_encode($case, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $imported[] = $case['case_id'];
            }
        }

        return ['schema_version' => 'atlas.rivals2.import_cases.v1', 'status' => 'ok', 'suite' => $suiteId, 'imported' => $imported, 'rejected' => $rejected];
    }

    /**
     * Importa o resultado NATIVO de uma execução externa para o run e o
     * transforma em receipts de primeira classe (→ verify/adjudicate/ledger).
     */
    private function importResults(): array
    {
        return $this->withRun(function (string $runId) {
            $plan = RunPlan::load($runId);
            $adapter = $this->adapterFor($plan->data['suite_id']);
            if ($adapter === null) {
                return ['status' => 'error', 'error' => 'unknown_suite:'.$plan->data['suite_id']];
            }
            $source = (string) $this->option('file');
            if (! is_file($source)) {
                return ['status' => 'error', 'error' => "results_file_not_found:{$source}"];
            }
            $destDir = RunPaths::runDir($runId).'/external_results';
            RunPaths::ensureDir($destDir);
            copy($source, $destDir.'/'.$adapter->suiteId().'.json');

            $receipts = $adapter->ingestResults(RunPaths::runDir($runId));
            foreach ($receipts as $receipt) {
                $receipt->append();
            }
            $pack = (new \App\Services\Ai\Rivals\Core\EvidencePackBuilder)->build($runId);

            return [
                'schema_version' => 'atlas.rivals2.import_results.v1',
                'status' => 'ok',
                'run_id' => $runId,
                'suite' => $adapter->suiteId(),
                'receipts_ingested' => count($receipts),
                'evidence_pack_built' => ($pack['receipts_hash']['present'] ?? false) === true,
            ];
        });
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
        $adapter = $this->adapterFor((string) $this->option('suite'));
        if ($adapter === null) {
            return ['status' => 'error', 'error' => 'unknown_suite:'.$this->option('suite')];
        }
        $cases = $adapter->listCases();
        if ($cases === []) {
            return ['status' => 'error', 'error' => 'no_cases_available_mine_first'];
        }
        $registry = new ArmRegistry;
        try {
            $arms = array_map(fn ($s) => $registry->parse(trim($s)), explode(',', (string) $this->option('arms')));
        } catch (\Throwable $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }

        $plan = RunPlan::make(
            $adapter->suiteId(),
            array_column($cases, 'case_id'),
            $arms,
            (int) $this->option('repetitions'),
            ['max_usd' => 0.0, 'max_minutes' => 5],
            (int) $this->option('seed'),
        );
        $runId = $plan->persist();

        return [
            'schema_version' => 'atlas.rivals2.plan_action.v1',
            'status' => 'ok',
            'run_id' => $runId,
            'commands' => $adapter->planCommands($plan),
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
            $adapter->execute($plan);
            $pack = (new \App\Services\Ai\Rivals\Core\EvidencePackBuilder)->build($runId);

            return [
                'schema_version' => 'atlas.rivals2.run_fake.v1',
                'status' => 'ok',
                'run_id' => $runId,
                'receipts' => count($adapter->ingestResults(RunPaths::runDir($runId))),
                'evidence_pack_built' => ($pack['receipts_hash']['present'] ?? false) === true,
            ];
        });
    }

    private function runBench(): array
    {
        return $this->withRun(function (string $runId) {
            $plan = RunPlan::load($runId);
            $adapter = $this->adapterFor($plan->data['suite_id']);
            if (! $adapter instanceof AtlasBenchSuiteAdapter) {
                return ['status' => 'error', 'error' => 'run_is_not_atlas_bench'];
            }
            $adapter->execute($plan);
            $pack = (new \App\Services\Ai\Rivals\Core\EvidencePackBuilder)->build($runId);

            return [
                'schema_version' => 'atlas.rivals2.run_bench.v1',
                'status' => 'ok',
                'run_id' => $runId,
                'receipts' => count($adapter->ingestResults(RunPaths::runDir($runId))),
                'evidence_pack_built' => ($pack['receipts_hash']['present'] ?? false) === true,
            ];
        });
    }

    private function ledger(): array
    {
        $ledger = new ResultLedger;
        if ($this->option('verify')) {
            $chain = $ledger->verifyChain();

            return ['schema_version' => 'atlas.rivals2.ledger.v1', 'status' => $chain['verified'] ? 'ok' : 'error'] + $chain;
        }

        return ['schema_version' => 'atlas.rivals2.ledger.v1', 'status' => 'ok', 'tail' => $ledger->tail()];
    }

    private function withRun(callable $fn): array
    {
        $runId = $this->option('run') ?: RunPaths::latestRunId();
        if ($runId === null) {
            return ['status' => 'error', 'error' => 'no_run_found_use_plan_first'];
        }

        try {
            return $fn($runId);
        } catch (\Throwable $e) {
            return ['status' => 'error', 'run_id' => $runId, 'error' => $e->getMessage()];
        }
    }
}
