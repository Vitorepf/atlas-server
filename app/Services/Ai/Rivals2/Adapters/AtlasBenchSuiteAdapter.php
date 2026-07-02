<?php

namespace App\Services\Ai\Rivals2\Adapters;

use App\Services\Ai\Rivals2\Contracts\BenchmarkSuiteAdapter;
use App\Services\Ai\Rivals2\Core\RunPlan;
use App\Services\Ai\Rivals2\Core\RunReceipt;
use App\Services\Ai\Rivals2\Support\EventStream;
use App\Services\Ai\Rivals2\Support\RunPaths;
use App\Services\Ai\Rivals2\Support\SchemaContract;
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
        return rtrim(config('atlas_rivals2.atlasbench.repo_path'), '/');
    }

    private function casesDir(): string
    {
        return RunPaths::root().'/atlasbench/cases';
    }

    /** Minera cases frescos do histórico git. Retorna os cases gerados. */
    public function mineCases(int $limit = 5): array
    {
        $repo = $this->repoPath();
        $window = (int) config('atlas_rivals2.atlasbench.mine_window_commits', 300);
        $maxDiff = (int) config('atlas_rivals2.atlasbench.max_diff_lines', 400);

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
            if ($diffLines === 0 || $diffLines > $maxDiff) {
                continue;
            }

            $case = [
                'schema_version' => 'atlas.rivals2.atlasbench_case.v1',
                'case_id' => 'ab_'.substr($sha, 0, 10),
                'task_type' => $this->taskTypeFor($subject),
                'title' => $subject,
                'base_sha' => trim(Process::path($repo)->run('git rev-parse '.escapeshellarg($sha.'^'))->output()),
                'golden_sha' => $sha,
                'check_command' => $this->checkCommandFor($testFiles),
                'changed_files' => ['code' => $codeFiles, 'tests' => $testFiles],
                'diff_lines' => $diffLines,
                'mined_at' => now()->toIso8601String(),
            ];
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
        $cases = [];
        foreach (glob($this->casesDir().'/*.json') as $file) {
            $case = json_decode(file_get_contents($file), true);
            if (isset($filters['task_type']) && $case['task_type'] !== $filters['task_type']) {
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
                        'command' => "rivals2-atlasbench --case={$caseId} --arm={$arm['arm_id']} --rep={$rep}",
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
        $modelId = explode('@', $armId, 2)[0];
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
            // vendor compartilhado read-only p/ rodar checks PHP sem composer install
            if (is_dir($repo.'/vendor') && ! is_dir($worktree.'/vendor')) {
                symlink($repo.'/vendor', $worktree.'/vendor');
            }

            $patchOutput = $this->applySolver($repo, $worktree, $case, $modelId);

            $timeout = (int) config('atlas_rivals2.atlasbench.check_timeout_seconds', 300);
            $timedOut = false;
            try {
                $check = Process::path($worktree)->timeout($timeout)->run($case['check_command']);
                $status = $check->successful() ? 'success' : 'failure';
                $checkOutput = $check->output()."\n".$check->errorOutput();
            } catch (\Illuminate\Process\Exceptions\ProcessTimedOutException) {
                $timedOut = true;
                $status = 'timeout';
                $checkOutput = "check timed out after {$timeout}s";
            }

            $artifacts = [];
            foreach (['patch.diff' => $patchOutput, 'check_output.txt' => $checkOutput] as $name => $content) {
                $rel = "artifacts/{$slug}__{$name}";
                file_put_contents(RunPaths::runDir($runId).'/'.$rel, $content);
                $artifacts[] = ['path' => $rel, 'sha256' => hash_file('sha256', RunPaths::runDir($runId).'/'.$rel)];
            }

            RunReceipt::fromArray([
                'schema_version' => SchemaContract::RUN_RECEIPT,
                'run_id' => $runId,
                'case_id' => $case['case_id'],
                'task_type' => $case['task_type'],
                'arm_id' => $armId,
                'repetition' => $rep,
                'status' => $status,
                'wall_ms' => (int) round((microtime(true) - $t0) * 1000),
                'tokens_in' => 0, // harness arms não consomem modelo
                'tokens_out' => 0,
                'cost_usd' => 0.0,
                'artifacts' => $artifacts,
                'judge_config' => $plan->data['judge_config'] ?? null,
                'started_at' => $startedAt,
                'finished_at' => now()->toIso8601String(),
            ])->append();
            EventStream::append($runId, 'case_finished', ['case' => $case['case_id'], 'arm' => $armId, 'rep' => $rep, 'status' => $status, 'timed_out' => $timedOut]);
        } finally {
            if (is_link($worktree.'/vendor')) {
                unlink($worktree.'/vendor');
            }
            Process::path($repo)->run('git worktree remove --force '.escapeshellarg($worktree));
        }
    }

    private function applySolver(string $repo, string $worktree, array $case, string $modelId): string
    {
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
            // fail-closed: arm de modelo real ainda não é executável (Slice 3);
            // NUNCA simular resultado de modelo que não rodou.
            default => throw new RuntimeException("atlasbench_arm_not_executable_in_slice_2:{$modelId}"),
        };
    }

    private function taskTypeFor(string $subject): string
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
