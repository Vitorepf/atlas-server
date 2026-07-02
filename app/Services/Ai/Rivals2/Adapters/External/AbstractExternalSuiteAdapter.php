<?php

namespace App\Services\Ai\Rivals2\Adapters\External;

use App\Services\Ai\Rivals2\Contracts\BenchmarkSuiteAdapter;
use App\Services\Ai\Rivals2\Core\RunPlan;
use App\Services\Ai\Rivals2\Core\RunReceipt;
use App\Services\Ai\Rivals2\Support\RunPaths;
use App\Services\Ai\Rivals2\Support\SchemaContract;
use RuntimeException;

/**
 * Slice 7 (skeleton): base comum dos adapters de suites EXTERNAS.
 * Honestidade primeiro: cases só existem se foram importados para
 * external/<suite_id>/cases/; comandos são DOCUMENTADOS, nunca executados
 * aqui; ingest lê o formato NATIVO da suite e fail-closes em campo ausente.
 * Execução real das suites é fase futura.
 */
abstract class AbstractExternalSuiteAdapter implements BenchmarkSuiteAdapter
{
    abstract public function suiteId(): string;

    /** Comando externo documentado, com placeholders {case_id} {model} {arm_id} {rep}. */
    abstract protected function commandTemplate(): string;

    /**
     * Mapeia o payload nativo → lista de overrides de receipt (mesclados
     * sobre baseReceipt()). Lança RuntimeException nomeada em campo ausente.
     *
     * @return array<int, array>
     */
    abstract protected function mapResults(array $native): array;

    public function listCases(array $filters = []): array
    {
        $dir = RunPaths::root().'/external/'.$this->suiteId().'/cases';
        if (! is_dir($dir)) {
            return []; // nada importado = nada; NUNCA inventar cases
        }
        $cases = [];
        foreach (glob($dir.'/*.json') as $file) {
            $case = json_decode(file_get_contents($file), true);
            if (! is_array($case)) {
                continue;
            }
            if (isset($filters['task_type']) && ($case['task_type'] ?? null) !== $filters['task_type']) {
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
                [$model] = array_pad(explode('@', $arm['arm_id'], 2), 1, '');
                for ($rep = 1; $rep <= $plan->data['repetitions']; $rep++) {
                    $commands[] = [
                        'case_id' => $caseId,
                        'arm_id' => $arm['arm_id'],
                        'repetition' => $rep,
                        'command' => strtr($this->commandTemplate(), [
                            '{case_id}' => $caseId,
                            '{model}' => $model,
                            '{arm_id}' => $arm['arm_id'],
                            '{rep}' => (string) $rep,
                        ]),
                    ];
                }
            }
        }

        return $commands;
    }

    public function ingestResults(string $runDir): array
    {
        $rel = 'external_results/'.$this->suiteId().'.json';
        $path = rtrim($runDir, '/').'/'.$rel;
        if (! is_file($path)) {
            throw new RuntimeException($this->suiteId().'_results_missing:'.$path);
        }
        $native = json_decode(file_get_contents($path), true);
        if (! is_array($native)) {
            throw new RuntimeException($this->suiteId().'_results_unparseable:'.$path);
        }

        $base = [
            'schema_version' => SchemaContract::RUN_RECEIPT,
            'run_id' => basename(rtrim($runDir, '/')),
            'repetition' => 1,
            'wall_ms' => 0,
            'tokens_in' => 0,
            'tokens_out' => 0,
            'cost_usd' => 0.0,
            // o resultado nativo é o artifact hash-pinned do ingest
            'artifacts' => [['path' => $rel, 'sha256' => hash_file('sha256', $path)]],
            'started_at' => null,
            'finished_at' => null,
        ];

        return array_map(
            fn (array $overrides) => RunReceipt::fromArray(array_merge($base, $overrides)),
            $this->mapResults($native)
        );
    }

    /** task_type do case importado (external/<suite>/cases/<case_id>.json), ou null. */
    protected function caseTaskType(string $caseId): ?string
    {
        $file = RunPaths::root().'/external/'.$this->suiteId().'/cases/'.$caseId.'.json';
        if (! is_file($file)) {
            return null;
        }

        return json_decode(file_get_contents($file), true)['task_type'] ?? null;
    }
}
