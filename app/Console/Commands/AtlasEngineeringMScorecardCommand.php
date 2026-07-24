<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Scorecard mínimo e honesto do multiplicador M — só fatos de execução real:
 * receipts do Dev senior loop (status/repair), distribuição da esteira de
 * task-serving e vitalidade das memórias de aprendizado (linhas reais em
 * tabelas que já estiveram mortas). Cada invocação apendia um snapshot em
 * storage/atlas/m_scorecard/history.jsonl para comparação antes/depois.
 * Read-only sobre os dados de origem; nunca fabrica números.
 */
class AtlasEngineeringMScorecardCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:engineering:m-scorecard {--json : Machine-readable JSON} {--no-record : Do not append to history}';

    protected $description = 'Honest engineering-multiplier scorecard: real Dev run outcomes, repair conversion, task-serving flow and learning-memory liveness.';

    public function handle(): int
    {
        $data = [
            'schema' => 'atlas.engineering.m_scorecard.v1',
            'generated_at' => now()->toIso8601String(),
            'dev_runs' => $this->devRuns(),
            'task_serving' => $this->taskServing(),
            'learning_liveness' => $this->learningLiveness(),
        ];

        if (! (bool) $this->option('no-record')) {
            $this->record($data);
        }

        if ((bool) $this->option('json')) {
            $this->line($this->encode($data));

            return self::SUCCESS;
        }

        $d = $data['dev_runs'];
        $this->info('M scorecard (fatos de execução real)');
        $this->line("dev runs: total={$d['total']} passed={$d['passed']} failed={$d['failed']} blocked={$d['blocked']} needs_review={$d['needs_review']} pass_rate={$d['pass_rate']}");
        $this->line("repair: attempted={$d['repair_attempted']} recovered={$d['repair_recovered']} conversion={$d['repair_conversion']}");
        $t = $data['task_serving'];
        $this->line('task serving: '.json_encode($t));
        $this->line('learning liveness: '.json_encode($data['learning_liveness']));

        return self::SUCCESS;
    }

    /** @return array<string,int|float|null> */
    private function devRuns(): array
    {
        $total = $passed = $failed = $blocked = $needsReview = 0;
        $repairAttempted = $repairRecovered = 0;

        foreach (glob(storage_path('atlas-dev/receipts/*/senior_engineer_loop_execution.json')) ?: [] as $file) {
            $row = json_decode((string) file_get_contents($file), true);
            if (! is_array($row)) {
                continue;
            }
            $total++;
            match ((string) ($row['status'] ?? '')) {
                'passed' => $passed++,
                'failed' => $failed++,
                'blocked' => $blocked++,
                'needs_review' => $needsReview++,
                default => null,
            };
            $loop = (array) ($row['debug_loop'] ?? []);
            if ((int) ($loop['attempts_executed'] ?? 0) > 0) {
                $repairAttempted++;
                if (($loop['recovered'] ?? false) === true) {
                    $repairRecovered++;
                }
            }
        }

        return [
            'total' => $total,
            'passed' => $passed,
            'failed' => $failed,
            'blocked' => $blocked,
            'needs_review' => $needsReview,
            'pass_rate' => $total > 0 ? round($passed / $total, 3) : null,
            'repair_attempted' => $repairAttempted,
            'repair_recovered' => $repairRecovered,
            'repair_conversion' => $repairAttempted > 0 ? round($repairRecovered / $repairAttempted, 3) : null,
        ];
    }

    /** @return array<string,mixed> */
    private function taskServing(): array
    {
        try {
            $buffer = new \Symfony\Component\Console\Output\BufferedOutput;
            \Illuminate\Support\Facades\Artisan::call('atlas:task:health', ['--json' => true], $buffer);
            $health = json_decode($buffer->fetch(), true);
            $dist = (array) ($health['queue_status_distribution'] ?? []);

            return [
                'completed' => (int) ($dist['completed_dry_run'] ?? 0),
                'cancelled' => (int) ($dist['cancelled'] ?? 0),
                'blocked' => (int) ($dist['blocked'] ?? 0),
                'claimable' => (int) ($dist['claimable'] ?? 0),
            ];
        } catch (\Throwable) {
            return ['unavailable' => true];
        }
    }

    /** @return array<string,int|string> */
    private function learningLiveness(): array
    {
        $out = [];
        foreach ([
            'atlas_dev_failure_capsules',
            'atlas_dev_outcome_memories',
            'ai_compounding_memories',
            'atlas_aemor_outcomes',
            'ai_programming_runtime_telemetry_events',
        ] as $table) {
            try {
                $out[$table] = (int) DB::table($table)->count();
            } catch (\Throwable) {
                $out[$table] = 'missing'; // tabela morta = drift, o doctor acusa
            }
        }

        return $out;
    }

    /** @param array<string,mixed> $data */
    private function record(array $data): void
    {
        try {
            $dir = storage_path('atlas/m_scorecard');
            if (! is_dir($dir)) {
                mkdir($dir, 0o755, true);
            }
            file_put_contents($dir.'/history.jsonl', json_encode($data, JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // registro do histórico nunca bloqueia a leitura do scorecard
        }
    }
}
