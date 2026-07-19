<?php

namespace App\Services\Ai\Rivals\Adapters\External;

use App\Services\Ai\Rivals\Contracts\BenchmarkSuiteAdapter;
use App\Services\Ai\Rivals\Core\ClaimTier;
use App\Services\Ai\Rivals\Core\ModelRegistry;
use App\Services\Ai\Rivals\Core\NativeExecutionManifest;
use App\Services\Ai\Rivals\Core\NativeExecutionReceipt;
use App\Services\Ai\Rivals\Core\RunPlan;
use App\Services\Ai\Rivals\Core\RunReceipt;
use App\Services\Ai\Rivals\Support\RunPaths;
use App\Services\Ai\Rivals\Support\RuntimeProofAttacher;
use App\Services\Ai\Rivals\Support\SchemaContract;
use RuntimeException;

/**
 * Base dos adapters externos: cases importados, comandos documentados (nunca
 * executados aqui), ingest nativo fail-closed com remap para arm canônico.
 */
abstract class AbstractExternalSuiteAdapter implements BenchmarkSuiteAdapter
{
    abstract public function suiteId(): string;

    /** Template com placeholders tipados: {cli_model} {native_agent} {runtime} {case_id} {rep} {output_path}. */
    abstract protected function commandTemplate(): string;

    /** @param array<string, mixed> $binding */
    protected function commandTemplateForArm(array $binding): string
    {
        return $this->commandTemplate();
    }

    /**
     * Args extras específicos DESTE caso, anexados após o template base.
     *
     * Existe porque um template só não serve para suítes multi-task: no inspect,
     * cada task tem parâmetros próprios (`-T x=y`) e passá-los globalmente
     * quebraria as tasks que não os declaram. Default = nenhum.
     *
     * @param  array<string, mixed>  $case
     * @param  array<string, mixed>  $binding
     * @return list<string>
     */
    protected function extraArgsForCase(array $case, array $binding): array
    {
        return [];
    }

    /**
     * Mapeia payload nativo → overrides de receipt. Deve incluir metadata.native
     * com cli_model + native_agent para binding canônico.
     *
     * @return array<int, array>
     */
    abstract protected function mapResults(array $native): array;

    public function listCases(array $filters = []): array
    {
        $dir = RunPaths::root().'/external/'.$this->suiteId().'/cases';
        if (! is_dir($dir)) {
            return [];
        }
        $cases = [];
        foreach (glob($dir.'/*.json') ?: [] as $file) {
            $case = json_decode(file_get_contents($file), true);
            if (! is_array($case)) {
                continue;
            }
            if (isset($filters['task_type']) && ($case['task_type'] ?? null) !== $filters['task_type']) {
                continue;
            }
            if (isset($filters['source_repo']) && ($case['source_repo'] ?? $this->suiteId()) !== $filters['source_repo']) {
                continue;
            }
            $cases[] = $case;
        }

        return $cases;
    }

    public function planCommands(RunPlan $plan): array
    {
        $models = new ModelRegistry;
        $commands = [];
        $caseIndex = collect($this->listCases())->keyBy('case_id');
        foreach ($plan->data['case_ids'] as $caseId) {
            $case = (array) ($caseIndex[$caseId] ?? [
                'case_id' => $caseId,
                'task_type' => 'coding_patch',
            ]);
            foreach ($plan->data['arms'] as $arm) {
                $binding = $this->enrichArmBinding($arm, $models);
                for ($rep = 1; $rep <= $plan->data['repetitions']; $rep++) {
                    $executionId = NativeExecutionManifest::executionId(
                        $plan->runId(),
                        (string) $caseId,
                        (string) $binding['arm_id'],
                        $rep,
                    );
                    $seed = NativeExecutionManifest::seedFor(
                        $plan->data['seed'],
                        (string) $caseId,
                        (string) $binding['arm_id'],
                        $rep,
                    );
                    $outputPath = NativeExecutionManifest::unitResultPath(
                        (string) $caseId,
                        (string) $binding['arm_id'],
                        $rep,
                    );
                    $nativeOutputPath = RunPaths::runDir($plan->runId()).'/'.$outputPath;
                    $scratchDir = RunPaths::runDir($plan->runId())
                        .'/native_scratch/'.$executionId;
                    $runName = 'rivals_'.$executionId;
                    $replacements = [
                        '{case_id}' => (string) $caseId,
                        '{case_file}' => RunPaths::root().'/external/'.$this->suiteId().'/cases/'.$caseId.'.json',
                        '{cli_model}' => (string) $binding['native_model'],
                        '{atlas_cli_model}' => (string) $binding['cli_model'],
                        '{native_agent}' => (string) $binding['native_agent'],
                        '{runtime}' => (string) $binding['runtime'],
                        '{rep}' => (string) $rep,
                        '{output_path}' => $nativeOutputPath,
                        '{output_parent}' => $scratchDir,
                        '{jobs_dir}' => $scratchDir,
                        '{log_dir}' => $scratchDir,
                        '{results_parent}' => $scratchDir,
                        '{eval_scratch_dir}' => $scratchDir,
                        '{predictions_path}' => $scratchDir.'/predictions.json',
                        '{run_id}' => $plan->runId(),
                        '{atlas_root}' => base_path(),
                        '{execution_id}' => $executionId,
                        '{run_name}' => $runName,
                        '{seed}' => (string) $seed,
                        '{temperature}' => number_format(0.2 + (($rep - 1) * 0.001), 3, '.', ''),
                        '{native_domain}' => (string) ($case['domain'] ?? 'airline'),
                        '{native_task_id}' => (string) ($case['native_task_id'] ?? $caseId),
                        '{native_category}' => (string) ($case['native_category'] ?? $caseId),
                        '{native_test_id}' => (string) ($case['native_test_id'] ?? $caseId),
                        '{task_ref}' => (string) ($case['task_ref'] ?? 'inspect_evals/'.$caseId),
                        '{sample_id}' => (string) ($case['sample_id'] ?? $caseId),
                        '{benchmark}' => (string) ($case['benchmark'] ?? $caseId),
                        '{task_id}' => (string) ($case['task_id'] ?? $caseId),
                        '{agent_dir}' => (string) ($case['agent_dir'] ?? 'agents/'.$binding['native_agent']),
                        '{agent_function}' => (string) ($case['agent_function'] ?? 'main.run'),
                        '{agent_name}' => (string) ($case['agent_name'] ?? $binding['native_agent']),
                        '{marathon_env}' => (string) config(
                            'atlas_rivals.native_execution.swe_marathon_environment',
                            'docker',
                        ),
                        // legacy placeholders — never substitute arm_id as agent
                        '{model}' => (string) $binding['native_model'],
                    ];
                    $template = $this->commandTemplateForArm($binding);
                    // Fail-fast pré-spend: runtime não-bare numa suíte cujo template é o
                    // bare puro (sem override nem placeholder {runtime}) rodaria bare
                    // disfarçado e morreria depois no runtime-proof como env_failure.
                    if (($binding['runtime'] ?? 'bare') !== 'bare'
                        && $template === $this->commandTemplate()
                        && ! str_contains($template, '{runtime}')) {
                        throw new RuntimeException(
                            $this->suiteId().'_runtime_unsupported:'.$binding['runtime'],
                        );
                    }
                    $argv = array_map(
                        fn (string $token): string => strtr($token, $replacements),
                        str_getcsv($template, ' ', '"', '\\'),
                    );
                    $unresolved = implode(' ', $argv);
                    if (str_contains($unresolved, '{') || str_contains($unresolved, '}')) {
                        throw new RuntimeException($this->suiteId().'_unresolved_placeholders:'.$unresolved);
                    }
                    if (str_contains($template, '{arm_id}')) {
                        throw new RuntimeException($this->suiteId().'_arm_id_placeholder_forbidden');
                    }
                    // Args extras POR CASO, depois do fail-fast acima (que compara
                    // contra o template base de propósito — não pode ser burlado
                    // por um arg de task). Ex.: o inspect precisa de `-T grader=`
                    // em tasks cujo juiz aponta para modelo que o router não tem.
                    $argv = array_merge($argv, $this->extraArgsForCase($case, $binding));
                    $command = implode(' ', array_map('escapeshellarg', $argv));
                    $commands[] = [
                        'case_id' => $caseId,
                        'arm_id' => $binding['arm_id'],
                        'native_binding_id' => $binding['native_binding_id'],
                        'repetition' => $rep,
                        'output_path' => $outputPath,
                        'argv' => $argv,
                        'command' => $command,
                        'normalization' => [
                            'scratch_dir' => $scratchDir,
                            'run_name' => $runName,
                            'seed' => $seed,
                            'temperature' => 0.2 + (($rep - 1) * 0.001),
                            'case' => $case,
                            'judge_config' => $plan->data['judge_config'] ?? null,
                        ],
                    ];
                }
            }
        }

        return $commands;
    }

    public function ingestResults(string $runDir, ?RunPlan $plan = null): array
    {
        $runId = basename(rtrim($runDir, '/'));
        $plan ??= $this->tryLoadPlan($runId);
        $bindings = $this->bindingIndex($plan);
        $models = new ModelRegistry;
        $manifest = null;
        try {
            $manifest = NativeExecutionManifest::load($runId);
        } catch (\Throwable) {
            $manifest = null;
        }

        if ($manifest !== null
            && in_array($manifest->data['claim_tier'], [ClaimTier::PRODUCTION, ClaimTier::PUBLIC], true)) {
            $nativeReceipts = collect(NativeExecutionReceipt::loadAll($runId))->keyBy(
                fn (NativeExecutionReceipt $receipt): string => (string) $receipt->data['execution_id']
            );
            if ($nativeReceipts->count() !== count($manifest->entries())) {
                throw new RuntimeException($this->suiteId().'_native_receipt_set_incomplete');
            }
            $receipts = [];
            foreach ($manifest->entries() as $entry) {
                $executionId = (string) $entry['execution_id'];
                /** @var NativeExecutionReceipt|null $nativeReceipt */
                $nativeReceipt = $nativeReceipts[$executionId] ?? null;
                if ($nativeReceipt === null) {
                    throw new RuntimeException($this->suiteId().'_native_receipt_missing:'.$executionId);
                }
                $rel = (string) $entry['expected_result_path'];
                $path = rtrim($runDir, '/').'/'.$rel;
                if (! is_file($path)
                    || ! hash_equals(
                        (string) $nativeReceipt->data['result_sha256'],
                        hash_file('sha256', $path),
                    )) {
                    throw new RuntimeException($this->suiteId().'_native_result_unverified:'.$executionId);
                }
                $nativePayload = json_decode((string) file_get_contents($path), true);
                $mapped = (is_array($nativePayload) && isset($nativePayload['_rivals_runner']) && is_array($nativePayload['_rivals_runner']))
                    ? [$this->receiptFromRunnerStub($entry, $nativePayload['_rivals_runner'], $runId, $plan)]
                    : $this->receiptsFromNativeFile(
                        $path,
                        $rel,
                        $runId,
                        $plan,
                        $bindings,
                        $models,
                    );
                if (count($mapped) !== 1) {
                    throw new RuntimeException($this->suiteId().'_unit_result_cardinality:'.$executionId);
                }
                $receipt = $mapped[0];
                if ($receipt->data['case_id'] !== $entry['case_id']
                    || $receipt->data['arm_id'] !== $entry['arm_id']
                    || $receipt->data['repetition'] !== $entry['repetition']) {
                    throw new RuntimeException($this->suiteId().'_unit_result_identity_mismatch:'.$executionId);
                }
                $receipts[] = $receipt;
            }

            return $receipts;
        }

        $rel = 'external_results/'.$this->suiteId().'.json';
        $path = rtrim($runDir, '/').'/'.$rel;
        if (! is_file($path)) {
            throw new RuntimeException($this->suiteId().'_results_missing:'.$path);
        }

        return $this->receiptsFromNativeFile(
            $path,
            $rel,
            $runId,
            $plan,
            $bindings,
            $models,
        );
    }

    /**
     * Synthesize an honest environment_failure receipt when the native runner
     * only left a `_rivals_runner` stub (no harness result JSON).
     *
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $stub
     */
    private function receiptFromRunnerStub(
        array $entry,
        array $stub,
        string $runId,
        ?RunPlan $plan,
    ): RunReceipt {
        $reason = (string) ($stub['reason'] ?? 'native_result_not_created');
        $status = (string) ($stub['status'] ?? 'environment_failure');

        return RunReceipt::fromArray([
            'schema_version' => SchemaContract::RUN_RECEIPT,
            'run_id' => $runId,
            'case_id' => (string) $entry['case_id'],
            'task_type' => $this->caseTaskType((string) $entry['case_id']) ?? 'unknown',
            'arm_id' => (string) $entry['arm_id'],
            'repetition' => (int) $entry['repetition'],
            'status' => 'error',
            'failure_class' => 'environment_failure',
            'failure_reason' => $reason,
            'wall_ms' => 0,
            'tokens_in' => 0,
            'tokens_out' => 0,
            'cost_usd' => 0.0,
            'field_presence' => [
                'wall_ms' => ['present' => false, 'reason' => $reason],
                'tokens_in' => ['present' => false, 'reason' => $reason],
                'tokens_out' => ['present' => false, 'reason' => $reason],
                'cost_usd' => ['present' => false, 'reason' => $reason],
            ],
            'claim_tier' => (string) ($plan?->data['claim_tier'] ?? ClaimTier::PRODUCTION),
            'artifacts' => [[
                'path' => (string) ($entry['expected_result_path'] ?? ''),
                'sha256' => is_string($entry['expected_result_path'] ?? null)
                    && is_file(RunPaths::runDir($runId).'/'.$entry['expected_result_path'])
                    ? hash_file('sha256', RunPaths::runDir($runId).'/'.$entry['expected_result_path'])
                    : hash('sha256', json_encode($stub)),
            ]],
            'harness_only' => false,
            'started_at' => null,
            'finished_at' => null,
            'metadata' => [
                'native' => [
                    'runner_stub' => true,
                    'runner_status' => $status,
                    'runner_reason' => $reason,
                    'execution_id' => $entry['execution_id'] ?? null,
                    'source_repo' => $this->suiteId(),
                ],
                'plan_run_id' => $plan?->runId(),
            ],
        ]);
    }

    /**
     * @param  array<string, array<string, mixed>>  $bindings
     * @return list<RunReceipt>
     */
    private function receiptsFromNativeFile(
        string $path,
        string $rel,
        string $runId,
        ?RunPlan $plan,
        array $bindings,
        ModelRegistry $models,
    ): array {
        $native = json_decode(file_get_contents($path), true);
        if (! is_array($native)) {
            throw new RuntimeException($this->suiteId().'_results_unparseable:'.$path);
        }
        $executionReceipt = collect(NativeExecutionReceipt::loadAll($runId))->first(
            fn (NativeExecutionReceipt $receipt): bool => $receipt->data['expected_result_path'] === $rel,
        );
        $manifestEntry = is_file(RunPaths::nativeManifestPath($runId))
            ? collect(NativeExecutionManifest::load($runId)->entries())->first(
                fn (array $entry): bool => $entry['expected_result_path'] === $rel,
            )
            : null;
        // Fail-closed defaults: never claim usage present until reconcile proves it.
        $base = [
            'schema_version' => SchemaContract::RUN_RECEIPT,
            'run_id' => $runId,
            'repetition' => 1,
            'wall_ms' => 0,
            'tokens_in' => 0,
            'tokens_out' => 0,
            'cost_usd' => 0.0,
            'failure_class' => null,
            'failure_reason' => null,
            'field_presence' => [
                'wall_ms' => ['present' => false, 'reason' => 'undeclared'],
                'tokens_in' => ['present' => false, 'reason' => 'undeclared'],
                'tokens_out' => ['present' => false, 'reason' => 'undeclared'],
                'cost_usd' => ['present' => false, 'reason' => 'undeclared'],
            ],
            'claim_tier' => (string) ($plan?->data['claim_tier'] ?? ClaimTier::PRODUCTION),
            'artifacts' => [['path' => $rel, 'sha256' => hash_file('sha256', $path)]],
            'started_at' => null,
            'finished_at' => null,
            'harness_only' => false,
        ];

        $receipts = [];
        foreach ($this->mapResults($native) as $overrides) {
            $merged = array_merge($base, $overrides);
            $merged['field_presence'] = array_replace(
                $base['field_presence'],
                (array) ($overrides['field_presence'] ?? [])
            );
            if ((int) $merged['wall_ms'] <= 0 && $executionReceipt instanceof NativeExecutionReceipt) {
                $merged['wall_ms'] = (int) $executionReceipt->data['wall_ms'];
                $merged['started_at'] ??= $executionReceipt->data['started_at'];
                $merged['finished_at'] ??= $executionReceipt->data['finished_at'];
                $merged['field_presence']['wall_ms'] = [
                    'present' => true,
                    'reason' => 'native_execution_receipt',
                ];
            }
            if ($plan !== null) {
                $merged = $this->remapToCanonicalArm(
                    $merged,
                    $plan,
                    $bindings,
                    is_array($manifestEntry) ? (string) $manifestEntry['arm_id'] : null,
                );
                $binding = data_get($merged, 'metadata.canonical_arm');
                if (is_array($binding)) {
                    $merged = (new RuntimeProofAttacher)->attach(
                        $merged,
                        $binding,
                        $runId,
                        $rel,
                    );
                }
            }
            if (! array_key_exists('failure_class', $overrides)) {
                $merged['failure_class'] = RunReceipt::defaultFailureClass((string) $merged['status']);
            }
            if (($merged['status'] ?? null) === 'success') {
                $merged['failure_reason'] = null;
            } elseif (! is_string($merged['failure_reason'] ?? null)
                || trim((string) $merged['failure_reason']) === '') {
                $environmentError = $merged['environment_error'] ?? null;
                $merged['failure_reason'] = is_string($environmentError)
                    && trim($environmentError) !== ''
                        ? trim($environmentError)
                        : RunReceipt::defaultFailureReason(
                            (string) $merged['status'],
                            is_string($merged['failure_class'] ?? null)
                                ? $merged['failure_class']
                                : null,
                        );
                if ($merged['failure_reason'] !== null) {
                    $merged['failure_reason'] = $this->suiteId().':'.$merged['failure_reason'];
                }
            }
            $modelId = explode('@', (string) ($merged['arm_id'] ?? ''), 2)[0];
            $harnessOnly = ($merged['harness_only'] ?? false) === true
                || $models->isHarnessOnly($modelId);
            $merged['harness_only'] = $harnessOnly;
            if ($harnessOnly) {
                $merged['claim_tier'] = ClaimTier::HARNESS;
            }
            $provider = (string) ($models->get($modelId)['provider'] ?? '');
            $merged = $this->reconcileFieldPresence($merged, $provider);
            $receipts[] = RunReceipt::fromArray($merged);
        }

        return $receipts;
    }

    /** @param array<string, mixed> $exception */
    protected function nativeExceptionReason(array $exception, string $fallback): string
    {
        $type = trim((string) ($exception['exception_type'] ?? $exception['type'] ?? ''));
        $message = trim((string) ($exception['exception_message'] ?? $exception['message'] ?? ''));
        if ($type !== '' && $message !== '') {
            return $type.': '.$message;
        }
        if ($type !== '') {
            return $type;
        }
        if ($message !== '') {
            return $message;
        }

        return $fallback;
    }

    /**
     * Fail-closed usage honesty: never leave present=true with empty tokens/cost lies.
     *
     * @param  array<string, mixed>  $merged
     * @return array<string, mixed>
     */
    protected function reconcileFieldPresence(array $merged, string $provider = ''): array
    {
        $fp = (array) ($merged['field_presence'] ?? []);

        foreach (['tokens_in', 'tokens_out'] as $field) {
            $value = (int) ($merged[$field] ?? 0);
            $entry = is_array($fp[$field] ?? null) ? $fp[$field] : [];
            $reason = (string) ($entry['reason'] ?? 'usage_not_reported');
            if ($value <= 0) {
                $fp[$field] = [
                    'present' => false,
                    'reason' => $reason === 'undeclared' ? 'usage_not_reported' : $reason,
                ];
            } else {
                $fp[$field] = [
                    'present' => true,
                    'reason' => (($entry['present'] ?? null) === true && ($entry['reason'] ?? null) !== null)
                        ? $entry['reason']
                        : null,
                ];
            }
        }

        $wall = (int) ($merged['wall_ms'] ?? 0);
        $wallEntry = is_array($fp['wall_ms'] ?? null) ? $fp['wall_ms'] : [];
        $wallReason = (string) ($wallEntry['reason'] ?? 'wall_ms_not_reported');
        if ($wallReason === 'native_execution_receipt' && $wall >= 0) {
            $fp['wall_ms'] = ['present' => true, 'reason' => 'native_execution_receipt'];
        } elseif ($wall > 0) {
            $fp['wall_ms'] = ['present' => true, 'reason' => null];
        } else {
            $fp['wall_ms'] = [
                'present' => false,
                'reason' => $wallReason === 'undeclared' ? 'wall_ms_not_reported' : $wallReason,
            ];
        }

        $cost = (float) ($merged['cost_usd'] ?? 0.0);
        $costEntry = is_array($fp['cost_usd'] ?? null) ? $fp['cost_usd'] : [];
        $costReason = (string) ($costEntry['reason'] ?? '');
        $tokensOk = (($fp['tokens_in']['present'] ?? false) === true)
            && (($fp['tokens_out']['present'] ?? false) === true);
        if ($cost > 0.0) {
            $fp['cost_usd'] = ['present' => true, 'reason' => null];
        } elseif ($costReason === 'verboo_subscription_marginal' && $tokensOk) {
            $fp['cost_usd'] = ['present' => true, 'reason' => 'verboo_subscription_marginal'];
        } elseif ($provider === 'hermes' && $tokensOk) {
            $fp['cost_usd'] = ['present' => true, 'reason' => 'verboo_subscription_marginal'];
        } else {
            $fp['cost_usd'] = [
                'present' => false,
                'reason' => ($costReason !== '' && $costReason !== 'undeclared')
                    ? $costReason
                    : 'cost_not_reported',
            ];
        }

        $merged['field_presence'] = $fp;

        return $merged;
    }

    /** @param array<string, mixed> $arm */
    protected function enrichArmBinding(array $arm, ModelRegistry $models): array
    {
        $modelId = (string) ($arm['model_id'] ?? '');
        $runtime = (string) ($arm['runtime'] ?? 'bare');
        $armId = (string) ($arm['arm_id'] ?? "{$modelId}@{$runtime}");
        $model = $models->get($modelId) ?? [];
        $cliModel = (string) ($arm['cli_model'] ?? $model['cli_model'] ?? $modelId);
        $nativeModel = (string) ($arm['native_model']
            ?? $model['native_models'][$this->suiteId()]
            ?? $cliModel);
        $nativeAgent = (string) ($arm['native_agent'] ?? $this->defaultNativeAgent());
        $sourceRepo = (string) ($arm['source_repo'] ?? $this->suiteId());
        $nativeBindingId = (string) (
            $arm['native_binding_id']
            ?? "{$nativeModel}|{$nativeAgent}|{$sourceRepo}|{$runtime}"
        );

        return [
            'arm_id' => $armId,
            'model_id' => $modelId,
            'runtime' => $runtime,
            'cli_model' => $cliModel,
            'native_model' => $nativeModel,
            'native_agent' => $nativeAgent,
            'suite_id' => $this->suiteId(),
            'source_repo' => $sourceRepo,
            'native_binding_id' => $nativeBindingId,
        ];
    }

    protected function defaultNativeAgent(): string
    {
        $repos = (array) config('atlas_rivals.benchmarks.repos', []);
        $spec = $repos[$this->suiteId()] ?? [];

        return (string) ($spec['native_agent_default'] ?? $this->suiteId());
    }

    /** @return array<string, array<string, mixed>> */
    private function bindingIndex(?RunPlan $plan): array
    {
        if ($plan === null) {
            return [];
        }
        $models = new ModelRegistry;
        $index = [];
        foreach ($plan->data['arms'] as $arm) {
            $binding = $this->enrichArmBinding($arm, $models);
            $index[$binding['native_binding_id']] = $binding;
            $index[$binding['native_model'].'|'.$binding['native_agent']] = $binding;
            $index[$binding['native_model']] = $binding;
            $index[$binding['cli_model'].'|'.$binding['native_agent']] = $binding;
            $index[$binding['cli_model']] = $binding;
        }

        return $index;
    }

    /** @param array<string, mixed> $receipt */
    private function remapToCanonicalArm(
        array $receipt,
        RunPlan $plan,
        array $bindings,
        ?string $manifestArmId = null,
    ): array {
        $native = (array) (($receipt['metadata']['native'] ?? []) ?: []);
        $cliModel = (string) ($native['cli_model'] ?? '');
        $nativeAgent = (string) ($native['native_agent'] ?? $this->defaultNativeAgent());
        $sourceRepo = (string) ($native['source_repo'] ?? $this->suiteId());
        $candidates = [
            "{$cliModel}|{$nativeAgent}|{$sourceRepo}",
            "{$cliModel}|{$nativeAgent}",
            $cliModel,
        ];
        $binding = null;
        if ($manifestArmId !== null) {
            $models = new ModelRegistry;
            foreach ($plan->data['arms'] as $arm) {
                $candidate = $this->enrichArmBinding($arm, $models);
                if ($candidate['arm_id'] === $manifestArmId) {
                    $binding = $candidate;
                    break;
                }
            }
        } else {
            foreach ($candidates as $key) {
                if ($key !== '' && isset($bindings[$key])) {
                    $binding = $bindings[$key];
                    break;
                }
            }
        }
        if ($binding === null) {
            throw new RuntimeException($this->suiteId().'_native_binding_unresolved:'.$cliModel.'@'.$nativeAgent);
        }
        if ($cliModel !== '' && ! in_array(
            $cliModel,
            [$binding['cli_model'], $binding['native_model']],
            true,
        )) {
            throw new RuntimeException($this->suiteId().'_native_model_binding_mismatch:'.$cliModel);
        }
        if (! in_array($receipt['case_id'], $plan->data['case_ids'], true)) {
            throw new RuntimeException($this->suiteId().'_case_not_in_plan:'.$receipt['case_id']);
        }
        $rep = (int) ($receipt['repetition'] ?? 0);
        if ($rep < 1 || $rep > (int) $plan->data['repetitions']) {
            throw new RuntimeException($this->suiteId().'_repetition_out_of_range:'.$rep);
        }
        $receipt['arm_id'] = $binding['arm_id'];
        $receipt['metadata'] = array_merge((array) ($receipt['metadata'] ?? []), [
            'native' => $native + [
                'cli_model' => $cliModel,
                'native_agent' => $nativeAgent,
                'source_repo' => $sourceRepo,
            ],
            'canonical_arm' => $binding,
        ]);

        return $receipt;
    }

    private function tryLoadPlan(string $runId): ?RunPlan
    {
        try {
            return RunPlan::load($runId);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function caseTaskType(string $caseId): ?string
    {
        $file = RunPaths::root().'/external/'.$this->suiteId().'/cases/'.$caseId.'.json';
        if (! is_file($file)) {
            return null;
        }

        return json_decode(file_get_contents($file), true)['task_type'] ?? null;
    }
}
