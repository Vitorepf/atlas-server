<?php

namespace App\Services\Ai\Rivals2\Adapters;

use App\Services\Ai\Rivals2\Contracts\BenchmarkSuiteAdapter;
use App\Services\Ai\Rivals2\Core\RunPlan;
use App\Services\Ai\Rivals2\Core\RunReceipt;
use App\Services\Ai\Rivals2\Support\EventStream;
use App\Services\Ai\Rivals2\Support\RunPaths;
use App\Services\Ai\Rivals2\Support\SchemaContract;

/**
 * Suite determinística provider-free que prova o pipeline inteiro
 * (plan → run → receipts → evidence → verify → adjudicate → ledger → report).
 * Ela existe para provar fail-closed, NÃO para gerar números: report de
 * local_fake nunca é claim sobre modelo real. Comportamento por case_id:
 * *_ok = success, *_flaky = success só em repetição ímpar, *_fail = failure,
 * *_timeout = timeout.
 */
class LocalFakeSuiteAdapter implements BenchmarkSuiteAdapter
{
    public const SUITE_ID = 'local_fake';

    public function suiteId(): string
    {
        return self::SUITE_ID;
    }

    public function listCases(array $filters = []): array
    {
        $cases = [
            ['case_id' => 'fake_patch_ok', 'task_type' => 'coding_patch', 'title' => 'Deterministic passing patch case'],
            ['case_id' => 'fake_patch_flaky', 'task_type' => 'coding_patch', 'title' => 'Flaky patch case (odd reps pass)'],
            ['case_id' => 'fake_patch_fail', 'task_type' => 'coding_patch', 'title' => 'Deterministic failing patch case'],
            ['case_id' => 'fake_bug_ok', 'task_type' => 'bug_investigation', 'title' => 'Deterministic passing bug case'],
            ['case_id' => 'fake_bug_timeout', 'task_type' => 'bug_investigation', 'title' => 'Deterministic timeout bug case'],
            ['case_id' => 'fake_bug_fail', 'task_type' => 'bug_investigation', 'title' => 'Deterministic failing bug case'],
        ];
        if (isset($filters['task_type'])) {
            $cases = array_values(array_filter($cases, fn ($c) => $c['task_type'] === $filters['task_type']));
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
                        'command' => "rivals2-local-fake --case={$caseId} --arm={$arm['arm_id']} --rep={$rep} --seed={$plan->data['seed']}",
                    ];
                }
            }
        }

        return $commands;
    }

    /** Executa a suite fake: grava artifacts reais em disco + receipts.jsonl. */
    public function execute(RunPlan $plan): void
    {
        $runId = $plan->runId();
        $taskTypes = collect($this->listCases())->keyBy('case_id');
        RunPaths::ensureDir(RunPaths::artifactsDir($runId));
        EventStream::append($runId, 'fake_execution_started', ['seed' => $plan->data['seed']]);

        foreach ($this->planCommands($plan) as $cmd) {
            $status = $this->statusFor($cmd['case_id'], $cmd['repetition']);
            $artifactRel = "artifacts/{$cmd['case_id']}__".str_replace('@', '_', $cmd['arm_id'])."__r{$cmd['repetition']}.txt";
            $artifactAbs = RunPaths::runDir($runId).'/'.$artifactRel;
            // conteúdo determinístico por (seed, case, arm, rep)
            file_put_contents($artifactAbs, "rivals2 local_fake output\n".hash(
                'sha256',
                "{$plan->data['seed']}|{$cmd['case_id']}|{$cmd['arm_id']}|{$cmd['repetition']}"
            )."\nstatus={$status}\n");

            $wallMs = 50 + (crc32("{$plan->data['seed']}|{$cmd['case_id']}|{$cmd['repetition']}") % 200);
            RunReceipt::fromArray([
                'schema_version' => SchemaContract::RUN_RECEIPT,
                'run_id' => $runId,
                'case_id' => $cmd['case_id'],
                'task_type' => $taskTypes[$cmd['case_id']]['task_type'] ?? 'coding_patch',
                'arm_id' => $cmd['arm_id'],
                'repetition' => $cmd['repetition'],
                'status' => $status,
                'wall_ms' => $status === 'timeout' ? 5000 : $wallMs,
                'tokens_in' => 1000,
                'tokens_out' => 200,
                'cost_usd' => 0.0,
                'artifacts' => [[
                    'path' => $artifactRel,
                    'sha256' => hash_file('sha256', $artifactAbs),
                ]],
                'judge_config' => $plan->data['judge_config'] ?? null,
                'started_at' => now()->toIso8601String(),
                'finished_at' => now()->toIso8601String(),
            ])->append();
        }

        EventStream::append($runId, 'fake_execution_finished');
    }

    public function ingestResults(string $runDir): array
    {
        return RunReceipt::loadAll(basename($runDir));
    }

    private function statusFor(string $caseId, int $repetition): string
    {
        return match (true) {
            str_ends_with($caseId, '_timeout') => 'timeout',
            str_ends_with($caseId, '_fail') => 'failure',
            str_ends_with($caseId, '_flaky') => $repetition % 2 === 1 ? 'success' : 'failure',
            default => 'success',
        };
    }
}
