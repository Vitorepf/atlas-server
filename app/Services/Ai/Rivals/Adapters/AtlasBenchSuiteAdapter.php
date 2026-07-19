<?php

namespace App\Services\Ai\Rivals\Adapters;

use App\Services\Ai\Rivals\Contracts\BenchmarkSuiteAdapter;
use App\Services\Ai\Rivals\Core\ClaimTier;
use App\Services\Ai\Rivals\Core\ContaminationGuard;
use App\Services\Ai\Rivals\Core\ModelRegistry;
use App\Services\Ai\Rivals\Core\RealityScoreCard;
use App\Services\Ai\Rivals\Core\RunPlan;
use App\Services\Ai\Rivals\Core\RunReceipt;
use App\Services\Ai\Rivals\Support\EventStream;
use App\Services\Ai\Rivals\Support\RunPaths;
use App\Services\Ai\Rivals\Support\SchemaContract;
use App\Support\AtlasCloneDir;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * AtlasBench interno (estilo SWE-smith): minera commits reais do repo Atlas que
 * tocaram código E teste, congela cada um como case fresh (base_sha → golden_sha,
 * check = teste real do commit) e executa arms em worktrees isoladas fora do
 * repo vivo. Mineração e execução são 100% provider-free; arms de modelo real
 * entram no Slice 3. harness_null/harness_golden validam só a mecânica.
 */
class AtlasBenchSuiteAdapter implements BenchmarkSuiteAdapter
{
    public const SUITE_ID = 'atlas_bench';

    public function suiteId(): string
    {
        return self::SUITE_ID;
    }

    private function repoPath(): string
    {
        return rtrim(config('atlas_rivals.atlasbench.repo_path'), '/');
    }

    /** Bloco de config da suite (elite_reality sobrescreve com pisos mais duros). */
    protected function configBlock(): string
    {
        return 'atlasbench';
    }

    protected function benchConfig(string $key, mixed $default = null): mixed
    {
        return config('atlas_rivals.'.$this->configBlock().".{$key}",
            config("atlas_rivals.atlasbench.{$key}", $default));
    }

    protected function casesDir(): string
    {
        return RunPaths::root().'/'.$this->configBlock().'/cases';
    }

    /** Minera cases frescos do histórico git. Retorna os cases gerados. */
    public function mineCases(int $limit = 5): array
    {
        $repo = $this->repoPath();
        $window = (int) $this->benchConfig('mine_window_commits', 300);
        $maxDiff = (int) $this->benchConfig('max_diff_lines', 400);

        $log = Process::path($repo)->run(
            "git log --no-merges -n {$window} --pretty=format:%H%x09%s"
        );
        if (! $log->successful()) {
            throw new RuntimeException('atlasbench_git_log_failed: '.$log->errorOutput());
        }

        RunPaths::ensureDir($this->casesDir());
        $cases = [];
        foreach (array_filter(explode("\n", $log->output())) as $line) {
            if (count($cases) >= $limit) {
                break;
            }
            [$sha, $subject] = array_pad(explode("\t", $line, 2), 2, '');
            $files = array_filter(explode("\n", Process::path($repo)
                ->run('git show --name-only --pretty=format: '.escapeshellarg($sha))->output()));
            $testFiles = array_values(array_filter($files, fn ($f) => str_starts_with($f, 'tests/') && str_ends_with($f, '.php')));
            $codeFiles = array_values(array_filter($files, fn ($f) => str_starts_with($f, 'app/') && str_ends_with($f, '.php')));
            if ($testFiles === [] || $codeFiles === []) {
                continue;
            }
            $diffStat = Process::path($repo)->run('git show --numstat --pretty=format: '.escapeshellarg($sha))->output();
            $diffLines = array_sum(array_map(
                fn ($l) => (int) (explode("\t", $l)[0] ?? 0) + (int) (explode("\t", $l)[1] ?? 0),
                array_filter(explode("\n", $diffStat))
            ));
            $minDiff = (int) $this->benchConfig('min_diff_lines', 40);
            $minCodeFiles = (int) $this->benchConfig('min_code_files', 2);
            if ($diffLines < $minDiff || $diffLines > $maxDiff || count($codeFiles) < $minCodeFiles) {
                continue; // piso sênior: sem micro-commit, sem single-file trivial
            }

            $ticketBody = trim(Process::path($repo)->run('git show -s --format=%b '.escapeshellarg($sha))->output());
            $case = [
                'schema_version' => 'atlas.rivals2.atlasbench_case.v1',
                'protocol' => 'atlasbench.v3_real_ticket',
                'commit_date' => trim(Process::path($repo)->run('git show -s --format=%cI '.escapeshellarg($sha))->output()),
                // intenção REAL escrita pelo autor do commit (corpo da mensagem)
                'ticket_body' => $ticketBody,
                'case_id' => 'ab_'.substr($sha, 0, 10),
                'task_type' => $this->taskTypeFor($subject, $ticketBody, $codeFiles),
                'title' => $subject,
                'base_sha' => trim(Process::path($repo)->run('git rev-parse '.escapeshellarg($sha.'^'))->output()),
                'golden_sha' => $sha,
                'check_command' => $this->checkCommandFor($testFiles),
                'changed_files' => ['code' => $codeFiles, 'tests' => $testFiles],
                'diff_lines' => $diffLines,
                'mined_at' => now()->toIso8601String(),
            ];
            // sintoma REAL: roda a prova oculta no estado base e captura a falha —
            // é o "bug report" que um sênior receberia, sem revelar o código do teste
            $case['symptom_excerpt'] = $this->captureSymptom($repo, $case);
            if (! $this->acceptCase($case)) {
                continue;
            }

            // Contamination Guard fail-closed: case com receita/sem snapshot não vira corpus
            $audit = (new ContaminationGuard)->audit($case);
            if ($audit['violations'] !== []) {
                continue;
            }
            $case['contamination'] = $audit;

            file_put_contents(
                $this->casesDir().'/'.$case['case_id'].'.json',
                json_encode($case, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
            $cases[] = $case;
        }

        return $cases;
    }

    public function listCases(array $filters = []): array
    {
        if (! is_dir($this->casesDir())) {
            return [];
        }
        $guard = new ContaminationGuard;
        $cases = [];
        foreach (glob($this->casesDir().'/*.json') as $file) {
            $case = json_decode(file_get_contents($file), true);
            if (isset($filters['task_type']) && $case['task_type'] !== $filters['task_type']) {
                continue;
            }
            // re-auditoria a cada leitura: corpus que envelheceu (long-lived) sai do jogo
            if ($guard->audit($case)['violations'] !== []) {
                continue;
            }
            $cases[] = $case;
        }

        return $cases;
    }

    public function planCommands(RunPlan $plan): array
    {
        $commands = [];
        foreach ($plan->data['case_ids'] as $caseId) {
            foreach ($plan->data['arms'] as $arm) {
                for ($rep = 1; $rep <= $plan->data['repetitions']; $rep++) {
                    $commands[] = [
                        'case_id' => $caseId,
                        'arm_id' => $arm['arm_id'],
                        'repetition' => $rep,
                        'command' => "rivals-atlasbench --case={$caseId} --arm={$arm['arm_id']} --rep={$rep}",
                    ];
                }
            }
        }

        return $commands;
    }

    /** Executa o plano em worktrees isoladas (fora do repo vivo). */
    public function execute(RunPlan $plan): void
    {
        $runId = $plan->runId();
        $cases = collect($this->listCases())->keyBy('case_id');
        RunPaths::ensureDir(RunPaths::artifactsDir($runId));
        EventStream::append($runId, 'atlasbench_execution_started');

        foreach ($this->planCommands($plan) as $cmd) {
            $case = $cases[$cmd['case_id']] ?? null;
            if ($case === null) {
                throw new RuntimeException("atlasbench_unknown_case:{$cmd['case_id']}");
            }
            $this->executeOne($plan, $case, $cmd['arm_id'], $cmd['repetition']);
        }

        EventStream::append($runId, 'atlasbench_execution_finished');
    }

    public function ingestResults(string $runDir): array
    {
        return RunReceipt::loadAll(basename($runDir));
    }

    private function executeOne(RunPlan $plan, array $case, string $armId, int $rep): void
    {
        $runId = $plan->runId();
        $repo = $this->repoPath();
        [$modelId, $runtime] = array_pad(explode('@', $armId, 2), 2, 'bare');
        $slug = $case['case_id'].'__'.str_replace('@', '_', $armId)."__r{$rep}";
        $worktree = RunPaths::runDir($runId).'/worktrees/'.$slug;
        RunPaths::ensureDir(dirname($worktree));
        $startedAt = now()->toIso8601String();
        $t0 = microtime(true);

        $provision = Process::path($repo)->run(
            'git worktree add --detach '.escapeshellarg($worktree).' '.escapeshellarg($case['base_sha'])
        );
        if (! $provision->successful()) {
            throw new RuntimeException('atlasbench_worktree_failed: '.$provision->errorOutput());
        }

        try {
            $this->provisionVendor($repo, $worktree);
            $this->provisionDatabaseFloor($worktree);

            // timeout do SOLVER é medição (receipt timeout), nunca morte da bateria
            $solverTimedOut = false;
            try {
                $patchOutput = $this->applySolver($repo, $worktree, $case, $modelId, $runtime, $plan);
            } catch (ProcessTimedOutException) {
                $solverTimedOut = true;
                $patchOutput = "(solver timed out)\n";
            }

            // protocolo v2 (hidden tests): o solver nunca vê nem controla a prova.
            // 1) tocar nos arquivos de teste do case = tampering → error fail-closed;
            // 2) o diff de TESTES do commit golden é injetado só agora, pós-solve.
            $testFiles = $case['changed_files']['tests'] ?? [];
            $blockReason = null;
            if ($modelId !== 'harness_golden' && $testFiles !== []) {
                $touched = array_filter(explode("\n", Process::path($worktree)->run('git diff --name-only')->output()));
                if (array_intersect($touched, $testFiles) !== []) {
                    $blockReason = 'test_tampering_detected: solver modified hidden acceptance test files';
                } else {
                    // teste PRÓPRIO criado pelo solver no path do teste oculto não é
                    // tampering (arquivo não existia no base) — mas a prova oculta manda:
                    // remove a colisão UNTRACKED antes do inject, senão git apply falha.
                    // Tracked fica intacto (modificação tracked já caiu como tampering).
                    foreach ($testFiles as $testFile) {
                        $tracked = Process::path($worktree)
                            ->run('git ls-files --error-unmatch '.escapeshellarg($testFile))->successful();
                        if (! $tracked && is_file($worktree.'/'.$testFile)) {
                            unlink($worktree.'/'.$testFile);
                        }
                    }
                    $testDiff = Process::path($repo)->run(
                        'git diff '.escapeshellarg($case['base_sha']).' '.escapeshellarg($case['golden_sha']).' -- '
                        .implode(' ', array_map('escapeshellarg', $testFiles))
                    );
                    if (trim($testDiff->output()) !== '') {
                        $apply = Process::path($worktree)->input($testDiff->output())->run('git apply -');
                        if (! $apply->successful()) {
                            $blockReason = 'hidden_test_injection_failed: '.substr($apply->errorOutput(), 0, 300);
                        }
                    }
                }
            }

            $timeout = (int) $this->benchConfig('check_timeout_seconds', 300);
            $timedOut = $solverTimedOut;
            if ($solverTimedOut) {
                $status = 'timeout';
                $checkOutput = 'solver timed out after '.$this->benchConfig('solver_timeout_seconds', 3600).'s (backstop anti-hang)';
            } elseif ($blockReason !== null) {
                $status = 'error';
                $checkOutput = $blockReason;
            } else {
                try {
                    $check = Process::path($worktree)->timeout($timeout)->run($case['check_command']);
                    $status = $check->successful() ? 'success' : 'failure';
                    $checkOutput = $check->output()."\n".$check->errorOutput();
                } catch (ProcessTimedOutException) {
                    $timedOut = true;
                    $status = 'timeout';
                    $checkOutput = "check timed out after {$timeout}s";
                }
            }

            $bridgePath = $worktree.'/.rivals_atlas_dev_bridge.json';
            $bridgeReceipt = is_file($bridgePath)
                ? (json_decode((string) file_get_contents($bridgePath), true) ?? null)
                : null;
            $bareProviderPath = $worktree.'/.rivals_bare_provider.json';
            $bareProviderReceipt = is_file($bareProviderPath)
                ? (json_decode((string) file_get_contents($bareProviderPath), true) ?? null)
                : null;
            $providerReceipt = $runtime === 'bare' ? $bareProviderReceipt : $bridgeReceipt;
            $artifactContents = ['patch.diff' => $patchOutput, 'check_output.txt' => $checkOutput];
            if (is_array($bridgeReceipt)) {
                $artifactContents['atlas_dev_bridge.json'] = json_encode(
                    $bridgeReceipt,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                );
            }
            if (is_array($bareProviderReceipt)) {
                $artifactContents['bare_provider.json'] = json_encode(
                    $bareProviderReceipt,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                );
            }
            $artifacts = [];
            foreach ($artifactContents as $name => $content) {
                $rel = "artifacts/{$slug}__{$name}";
                file_put_contents(RunPaths::runDir($runId).'/'.$rel, $content);
                $artifacts[] = ['path' => $rel, 'sha256' => hash_file('sha256', RunPaths::runDir($runId).'/'.$rel)];
            }

            $harnessOnly = (new ModelRegistry)->isHarnessOnly($modelId);
            $usagePresent = (bool) data_get($providerReceipt, 'usage.present', false);
            RunReceipt::fromArray([
                'schema_version' => SchemaContract::RUN_RECEIPT,
                'run_id' => $runId,
                'case_id' => $case['case_id'],
                'task_type' => $case['task_type'],
                'arm_id' => $armId,
                'repetition' => $rep,
                'status' => $status,
                'failure_class' => RunReceipt::defaultFailureClass($status),
                'failure_reason' => RunReceipt::defaultFailureReason(
                    $status,
                    RunReceipt::defaultFailureClass($status),
                ),
                'wall_ms' => (int) round((microtime(true) - $t0) * 1000),
                'tokens_in' => (int) (data_get($providerReceipt, 'usage.input_tokens') ?? 0),
                'tokens_out' => (int) (data_get($providerReceipt, 'usage.output_tokens') ?? 0),
                'cost_usd' => (float) (data_get($providerReceipt, 'usage.cost_usd') ?? 0.0),
                'field_presence' => [
                    'wall_ms' => ['present' => true, 'reason' => null],
                    'tokens_in' => [
                        'present' => $harnessOnly || $usagePresent,
                        'reason' => $harnessOnly || $usagePresent ? null : 'solver_cli_usage_not_captured',
                    ],
                    'tokens_out' => [
                        'present' => $harnessOnly || $usagePresent,
                        'reason' => $harnessOnly || $usagePresent ? null : 'solver_cli_usage_not_captured',
                    ],
                    'cost_usd' => [
                        'present' => $harnessOnly || $usagePresent,
                        'reason' => $harnessOnly || $usagePresent ? null : 'solver_cli_usage_not_captured',
                    ],
                ],
                'claim_tier' => (string) ($plan->data['claim_tier'] ?? ClaimTier::DIAGNOSTIC),
                'harness_only' => $harnessOnly,
                'artifacts' => $artifacts,
                'judge_config' => $plan->data['judge_config'] ?? null,
                // Reality Score: vetor mecânico por dimensão (nunca score único)
                'reality' => $reality = (new RealityScoreCard)->evaluate($case, $patchOutput, $status),
                'patch_lines' => $reality['patch_lines'],
                'golden_lines' => $reality['golden_lines'],
                'patch_bloat_ratio' => $reality['bloat_ratio'],
                'metadata' => [
                    'runtime' => $runtime,
                    'runtime_bridge' => $bridgeReceipt,
                    'direct_provider' => $bareProviderReceipt,
                ],
                'started_at' => $startedAt,
                'finished_at' => now()->toIso8601String(),
            ])->append();
            EventStream::append($runId, 'case_finished', ['case' => $case['case_id'], 'arm' => $armId, 'rep' => $rep, 'status' => $status, 'timed_out' => $timedOut]);
        } finally {
            $this->removeVendor($worktree);
            Process::path($repo)->run('git worktree remove --force '.escapeshellarg($worktree));
        }
    }

    /**
     * Vendor ISOLADO por worktree via clone APFS copy-on-write — nunca symlink do
     * vendor vivo: solver com --yolo rodando "composer dump-autoload" através do
     * symlink reescreve o classmap do repo VIVO com paths da worktree (incidente
     * real 02/07: autoload do Atlas quebrado no meio da bateria).
     */
    protected function provisionVendor(string $repo, string $worktree): void
    {
        if (! is_dir($repo.'/vendor') || file_exists($worktree.'/vendor')) {
            return;
        }
        $clone = Process::run('cp -Rc '.escapeshellarg($repo.'/vendor').' '.escapeshellarg($worktree.'/vendor'));
        if (! $clone->successful()) {
            throw new RuntimeException('atlasbench_vendor_clone_failed: '.substr($clone->errorOutput(), 0, 300));
        }
    }

    /**
     * PISO PÉTREO — DB do repo VIVO fora do alcance do braço. O worktree não tem
     * .env, e config/database.php defaulta para o pgsql VIVO (5433) quando o env
     * está ausente; um braço em commit antigo (sem o kill-switch de live-DB nos
     * tests) rodando `artisan test`/migration down() WIPOU as tabelas do DB vivo
     * (incidentes 02/07 18:11, 20:51 e 23:10 UTC, provados no log DDL do
     * Postgres). Pinar sqlite :memory: via .env escrito no provision cobre
     * QUALQUER commit de base — solver e check nunca enxergam o DB vivo.
     */
    protected function provisionDatabaseFloor(string $worktree): void
    {
        $floor = "APP_ENV=testing\n"
            .'APP_KEY=base64:'.base64_encode(random_bytes(32))."\n"
            ."DB_CONNECTION=sqlite\n"
            ."DB_DATABASE=:memory:\n"
            ."ATLAS_LOOP_MASTER_ENABLED=false\n"
            ."ATLAS_ALLOW_LIVE_DB_TESTS=0\n";
        foreach (['.env', '.env.testing'] as $file) {
            if (! file_exists($worktree.'/'.$file)) {
                file_put_contents($worktree.'/'.$file, $floor);
            }
        }
    }

    protected function removeVendor(string $worktree): void
    {
        if (is_link($worktree.'/vendor')) {
            unlink($worktree.'/vendor'); // legado: worktrees antigas ainda symlinkadas

            return;
        }
        if (is_dir($worktree.'/vendor')) {
            Process::run('rm -rf '.escapeshellarg($worktree.'/vendor'));
        }
    }

    private function applySolver(string $repo, string $worktree, array $case, string $modelId, string $runtime, RunPlan $plan): string
    {
        if ($runtime !== 'bare') {
            // S4: runtime Atlas roda via wrapper CLI configurado (mesmo modelo,
            // cérebro Atlas por cima). Sem wrapper → bloqueio honesto, nunca simula.
            $runtimeCmd = config("atlas_rivals.runtime_commands.{$runtime}");
            if (! is_string($runtimeCmd) || $runtimeCmd === '') {
                throw new RuntimeException("atlasbench_runtime_not_executable:{$runtime}:uplift_supported=false");
            }

            return $this->runCliArm($worktree, $case, $modelId, $runtimeCmd);
        }

        return match ($modelId) {
            'harness_null' => "(no patch — null solver)\n",
            'harness_golden' => (function () use ($repo, $worktree, $case): string {
                $diff = Process::path($repo)->run(
                    'git diff '.escapeshellarg($case['base_sha']).' '.escapeshellarg($case['golden_sha'])
                );
                $apply = Process::path($worktree)->input($diff->output())->run('git apply -');
                if (! $apply->successful()) {
                    throw new RuntimeException('atlasbench_golden_apply_failed: '.$apply->errorOutput());
                }

                return $diff->output();
            })(),
            default => $this->runCliArm($worktree, $case, $modelId),
        };
    }

    /**
     * Braço executado via CLI dentro da worktree isolada — modelo puro (S3, template
     * do ModelRegistry) ou runtime Atlas sobre o modelo (S4, template de
     * runtime_commands). Fail-closed: modelo desconhecido/sem perfil CLI → bloqueia;
     * provider não-local sem flag de spend → bloqueia. NUNCA inventa resultado.
     */
    private function runCliArm(string $worktree, array $case, string $modelId, ?string $overrideCommand = null): string
    {
        $model = (new ModelRegistry)->get($modelId);
        if ($model === null || ! ($model['enabled'] ?? false)) {
            throw new RuntimeException("atlasbench_unknown_or_disabled_model:{$modelId}");
        }
        $command = $overrideCommand ?? ($model['command'] ?? null);
        if (! is_string($command) || $command === '') {
            throw new RuntimeException("atlasbench_model_has_no_cli_command:{$modelId}");
        }
        if (($model['provider'] ?? '') !== 'local' && config('atlas_rivals.provider_spend_allowed') !== true) {
            throw new RuntimeException("atlasbench_provider_spend_not_allowed:{$modelId}");
        }

        // protocolo v2 (anti-cola): tarefa under-specified como um ticket real —
        // sem check command, sem paths de teste, sem lista de arquivos-alvo.
        // Os testes de aceitação são OCULTOS e injetados só na correção.
        $promptFile = $worktree.'/.rivals_task.md';
        file_put_contents($promptFile, $this->ticketFor($case));

        $timeout = (int) $this->benchConfig('solver_timeout_seconds', 3600);
        $resolved = str_replace(
            ['{workspace}', '{prompt_file}', '{cli_model}'],
            [escapeshellarg($worktree), escapeshellarg($promptFile), escapeshellarg($model['cli_model'] ?? $modelId)],
            $command
        );
        $exec = Process::path($worktree)->timeout($timeout)->run($resolved);
        if (! $exec->successful()) {
            throw new RuntimeException("atlasbench_model_cli_failed:{$modelId}: ".substr($exec->errorOutput(), 0, 500));
        }
        if ($overrideCommand !== null) {
            $bridgePath = $worktree.'/.rivals_atlas_dev_bridge.json';
            $bridge = is_file($bridgePath)
                ? json_decode((string) file_get_contents($bridgePath), true)
                : null;
            if (! is_array($bridge)
                || ($bridge['status'] ?? null) !== 'passed'
                || ($bridge['real_provider'] ?? false) !== true
                || ($bridge['provider'] ?? null) !== 'hermes_cli'
                || ($bridge['model'] ?? null) !== ($model['cli_model'] ?? $modelId)
                || data_get($bridge, 'fair_mode.single_provider') !== true
                || data_get($bridge, 'fair_mode.decide_disabled') !== true
                || data_get($bridge, 'fair_mode.fallback_disabled') !== true
                || data_get($bridge, 'usage.present') !== true) {
                throw new RuntimeException('atlasbench_runtime_bridge_receipt_invalid:'.$modelId);
            }
        } elseif (($model['provider'] ?? null) === 'hermes') {
            $barePath = $worktree.'/.rivals_bare_provider.json';
            $bare = is_file($barePath)
                ? json_decode((string) file_get_contents($barePath), true)
                : null;
            if (! is_array($bare)
                || ! in_array(($bare['status'] ?? null), ['passed', 'incomplete'], true)
                || ($bare['real_provider'] ?? false) !== true
                || ($bare['provider'] ?? null) !== 'verboo'
                || ($bare['model'] ?? null) !== ($model['cli_model'] ?? $modelId)
                || data_get($bare, 'usage.present') !== true) {
                throw new RuntimeException('atlasbench_bare_provider_receipt_invalid:'.$modelId);
            }
        }
        unlink($promptFile);

        return Process::path($worktree)->run('git diff')->output();
    }

    /** Ticket visível ao solver. Elite sobrescreve (sem título = sem cola do subject). */
    protected function ticketFor(array $case): string
    {
        $ticket = [
            "# Ticket: {$case['title']}",
            '',
        ];
        if (! empty($case['ticket_body'])) {
            $ticket[] = $case['ticket_body'];
            $ticket[] = '';
        }
        if (! empty($case['symptom_excerpt'])) {
            $ticket[] = '## Observed behavior (report from the team)';
            $ticket[] = '```';
            $ticket[] = $case['symptom_excerpt'];
            $ticket[] = '```';
            $ticket[] = '';
        }
        $ticket[] = 'You are a senior engineer on this codebase. Implement what this ticket asks,';
        $ticket[] = 'end to end, at production quality. The team will grade your change against';
        $ticket[] = 'their own acceptance checks — they are NOT provided to you.';
        $ticket[] = 'Do NOT modify or delete any existing test files: grading runs on the';
        $ticket[] = 'pristine test suite, and touching it invalidates your submission.';
        $ticket[] = 'Explore the repository, find where the change belongs, implement it fully,';
        $ticket[] = 'and follow the existing code style. Do not ask questions.';

        return implode("\n", $ticket);
    }

    /**
     * Executa a prova oculta no BASE (com os testes do golden injetados) numa
     * worktree efêmera e devolve o excerto da falha — linhas com paths de
     * teste são removidas para não vazar a prova. null = sem sintoma (feature).
     */
    private function captureSymptom(string $repo, array $case): ?string
    {
        $worktree = sys_get_temp_dir().'/rivals_symptom_'.$case['case_id'].'_'.substr(bin2hex(random_bytes(3)), 0, 6);
        $provision = Process::path($repo)->run(
            'git worktree add --detach '.escapeshellarg($worktree).' '.escapeshellarg($case['base_sha'])
        );
        if (! $provision->successful()) {
            return null;
        }

        try {
            // PISO PÉTREO — mesmo floor do provision de execução: sem .env o
            // check herdaria o pgsql VIVO via default de config/database.php
            // (2º vetor do wiper, onda 23:46 UTC 02/07: o symptom check em
            // worktree sem floor dropou as tabelas dev runtime intelligence).
            $this->provisionDatabaseFloor($worktree);
            // P6 (Obra #19): vendor is CLONED (APFS clonefile), never symlinked — a symlinked
            // vendor lets `composer dump-autoload` in the worktree rewrite the LIVE autoload (wiper).
            if (is_dir($repo.'/vendor') && ! is_dir($worktree.'/vendor')) {
                AtlasCloneDir::copy($repo.'/vendor', $worktree.'/vendor');
            }
            $testDiff = Process::path($repo)->run(
                'git diff '.escapeshellarg($case['base_sha']).' '.escapeshellarg($case['golden_sha']).' -- '
                .implode(' ', array_map('escapeshellarg', $case['changed_files']['tests']))
            );
            if (trim($testDiff->output()) !== '') {
                Process::path($worktree)->input($testDiff->output())->run('git apply -');
            }
            $check = Process::path($worktree)->timeout(180)->run($case['check_command']);
            if ($check->successful()) {
                return null; // prova já passa no base? case suspeito, sem sintoma
            }
            $lines = array_filter(
                explode("\n", $check->output()."\n".$check->errorOutput()),
                fn ($l) => ! str_contains($l, 'tests/') && trim($l) !== ''
            );

            return substr(implode("\n", array_slice($lines, -25)), -1800) ?: null;
        } catch (\Throwable) {
            return null;
        } finally {
            if (is_link($worktree.'/vendor')) {
                unlink($worktree.'/vendor');
            }
            Process::path($repo)->run('git worktree remove --force '.escapeshellarg($worktree));
        }
    }

    /** Piso extra por suite (elite endurece). Base aceita tudo que passou nos pisos gerais. */
    protected function acceptCase(array $case): bool
    {
        return true;
    }

    protected function taskTypeFor(string $subject, string $body = '', array $codeFiles = []): string
    {
        $s = mb_strtolower($subject);

        return match (true) {
            str_contains($s, 'fix') || str_contains($s, 'bug') => 'repair_regression_fixing',
            str_contains($s, 'refactor') || str_contains($s, 'extract') => 'refactor',
            str_contains($s, 'arch') || str_contains($s, 'design') => 'architecture_design',
            default => 'coding_patch',
        };
    }

    private function checkCommandFor(array $testFiles): string
    {
        $args = implode(' ', array_map('escapeshellarg', $testFiles));

        return "php vendor/bin/phpunit --no-coverage {$args}";
    }
}
