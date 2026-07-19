<?php

namespace App\Services\Ai\Rivals\Core;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Deterministic projection from upstream-native artifacts to one Atlas unit
 * payload. It never invents success: missing or ambiguous native bytes fail.
 */
final class NativeResultNormalizer
{
    /** @return array<string, mixed> */
    public function normalize(string $suiteId, array $entry, string $suiteRoot): array
    {
        return match ($suiteId) {
            'tau2_bench' => $this->tau2($entry, $suiteRoot),
            'bfcl' => $this->bfcl($entry, $suiteRoot),
            'terminal_bench' => $this->terminalBench($entry),
            'senior_swe_bench' => $this->seniorSwe($entry),
            'swe_bench_live' => $this->sweBenchLive($entry),
            'live_code_bench' => $this->liveCodeBench($entry, $suiteRoot),
            'inspect_evals' => $this->inspect($entry),
            'hal_harness' => $this->hal($entry),
            'aider_polyglot' => $this->aider($entry, $suiteRoot),
            'swe_marathon' => $this->sweMarathon($entry),
            'archbench',
            'cruxeval',
            'classeval',
            'repobench',
            'locagent',
            'debug_gym',
            'testeval',
            'evalplus',
            'crosscodeeval',
            'bigcodebench',
            'deveval',
            'long_code_arena',
            'reval' => $this->engineeringNative($suiteId, $entry),
            default => throw new RuntimeException("rivals_native_normalizer_unknown_suite:{$suiteId}"),
        };
    }

    /** @return array<string, mixed> */
    private function engineeringNative(string $suiteId, array $entry): array
    {
        $scratch = (string) data_get($entry, 'normalization.scratch_dir');
        $native = $this->json($scratch.'/native_result.json');
        if (($native['schema_version'] ?? null) !== 'atlas.rivals2.engineering_native_unit.v1') {
            throw new RuntimeException($suiteId.'_engineering_native_schema_invalid');
        }
        if (($native['suite_id'] ?? null) !== $suiteId) {
            throw new RuntimeException($suiteId.'_engineering_native_suite_mismatch');
        }
        $results = array_values((array) ($native['results'] ?? []));
        $row = $results[0] ?? [];
        if (count($results) !== 1
            || ($row['case_id'] ?? null) !== ($entry['case_id'] ?? null)
            || (int) ($row['repetition'] ?? 0) !== (int) ($entry['repetition'] ?? 0)) {
            throw new RuntimeException($suiteId.'_engineering_native_identity_mismatch');
        }
        $artifact = (array) ($row['native_artifact'] ?? []);
        $artifactPath = trim((string) ($artifact['path'] ?? ''));
        if ($artifactPath === '') {
            throw new RuntimeException($suiteId.'_engineering_native_artifact_missing');
        }
        if (! str_starts_with($artifactPath, '/')) {
            $artifactPath = $scratch.'/'.ltrim($artifactPath, '/');
        }
        $scratchReal = realpath($scratch);
        $artifactReal = realpath($artifactPath);
        if ($scratchReal === false
            || $artifactReal === false
            || ($artifactReal !== $scratchReal && ! str_starts_with($artifactReal, $scratchReal.'/'))) {
            throw new RuntimeException($suiteId.'_engineering_native_artifact_outside_scratch');
        }
        if (! hash_equals((string) ($artifact['sha256'] ?? ''), hash_file('sha256', $artifactReal))) {
            throw new RuntimeException($suiteId.'_engineering_native_artifact_hash_mismatch');
        }
        $native['results'][0]['native_artifact']['path'] = $artifactReal;

        return $native;
    }

    private function tau2(array $entry, string $root): array
    {
        $runName = (string) data_get($entry, 'normalization.run_name');
        $candidates = [
            "{$root}/data/simulations/{$runName}/results.json",
            "{$root}/src/tau2/data/simulations/{$runName}/results.json",
        ];
        $source = $this->firstExisting($candidates, 'tau2_results_missing');
        $native = $this->json($source);
        $simulations = (array) ($native['simulations'] ?? []);
        if ($simulations === []) {
            foreach ((array) ($native['simulation_index'] ?? []) as $indexed) {
                $id = (string) ($indexed['id'] ?? $indexed['simulation_id'] ?? '');
                $path = dirname($source).'/simulations/'.$id.'.json';
                if ($id !== '' && is_file($path)) {
                    $simulations[] = $this->json($path);
                }
            }
        }
        $nativeTaskId = (string) (data_get($entry, 'normalization.case.native_task_id')
            ?? $entry['case_id']);
        $matches = array_values(array_filter(
            $simulations,
            fn (array $sim): bool => (string) ($sim['task_id'] ?? '') === $nativeTaskId,
        ));
        if (count($matches) !== 1) {
            throw new RuntimeException('tau2_bench_unit_result_cardinality');
        }
        $sim = $matches[0];
        $reward = $sim['reward_info']['reward'] ?? $sim['reward'] ?? null;
        $usage = $this->usageFromMessages((array) ($sim['messages'] ?? []));

        return [
            'agent_llm' => data_get($native, 'info.agent_info.llm') ?? $entry['cli_model'],
            'simulations' => [[
                'simulation_id' => $sim['id'] ?? null,
                'task_id' => $entry['case_id'],
                'trial' => $entry['repetition'],
                'rivals_repetition' => $entry['repetition'],
                'reward' => $reward,
                'termination_reason' => $sim['termination_reason'] ?? null,
                'duration_sec' => (float) ($sim['duration'] ?? 0),
                'usage' => [
                    'input_tokens' => $usage['input_tokens'],
                    'output_tokens' => $usage['output_tokens'],
                    'cost_usd' => (float) ($sim['agent_cost'] ?? 0.0),
                ],
                'started_at' => $sim['start_time'] ?? null,
                'finished_at' => $sim['end_time'] ?? null,
            ]],
        ];
    }

    private function bfcl(array $entry, string $root): array
    {
        $scratch = (string) data_get($entry, 'normalization.scratch_dir');
        $category = (string) (data_get($entry, 'normalization.case.native_category')
            ?? $entry['case_id']);
        $resultDir = $scratch.'/result';
        $scoreDir = $scratch.'/score';
        if ($this->recursiveFiles($scoreDir, '_score.json') === []) {
            $python = is_file($root.'/.atlas-venv/bin/python')
                ? $root.'/.atlas-venv/bin/python'
                : 'python';
            $model = (new ModelRegistry)->get((string) ($entry['model_id'] ?? ''));
            $environment = ($model['provider'] ?? null) === 'hermes'
                ? (new VerbooEnvironment)->processEnvironment()
                : null;
            $evaluate = new Process(
                [
                    $python,
                    base_path('scripts/rivals_bfcl_verboo.py'),
                    'evaluate',
                    '--model', (string) $entry['cli_model'],
                    '--test-category', $category,
                    '--partial-eval',
                    '--result-dir', $resultDir,
                    '--score-dir', $scoreDir,
                ],
                $root,
                $environment,
            );
            $evaluate->setTimeout((int) $entry['max_seconds']);
            $evaluate->run();
            $combined = $evaluate->getErrorOutput()."\n".$evaluate->getOutput();
            // BFCL's leaderboard aggregation calls stdev() and dies on n=1
            // (--partial-eval / single case). Per-category score is often already
            // printed; synthesize the score file from that when missing.
            if ($this->recursiveFiles($scoreDir, '_score.json') === []) {
                $this->bfclSynthesizeScoreFromEvaluate($combined, $scoreDir, $category, (string) $entry['cli_model']);
            }
            if ($this->recursiveFiles($scoreDir, '_score.json') === []) {
                throw new RuntimeException('bfcl_evaluate_failed:'.substr($combined, 0, 2000));
            }
        }

        $resultFile = $this->singleFileBySuffix($resultDir, '_result.json', 'bfcl_result_file');
        $scoreFile = $this->singleFileBySuffix($scoreDir, '_score.json', 'bfcl_score_file');
        $resultRows = $this->jsonLines($resultFile);
        $scoreRows = $this->jsonLines($scoreFile);
        $header = (array) ($scoreRows[0] ?? []);
        $accuracy = $header['accuracy'] ?? null;
        if (! is_numeric($accuracy)) {
            throw new RuntimeException('bfcl_accuracy_missing');
        }
        $tokensIn = $tokensOut = 0;
        $latency = 0.0;
        foreach ($resultRows as $row) {
            $tokensIn += (int) $this->sumNumeric($row['input_token_count'] ?? 0);
            $tokensOut += (int) $this->sumNumeric($row['output_token_count'] ?? 0);
            $latency += $this->sumNumeric($row['latency'] ?? 0);
        }
        $isVerboo = ((new ModelRegistry)->get((string) ($entry['model_id'] ?? ''))['provider'] ?? null)
            === 'hermes';

        return [
            'model' => $entry['cli_model'],
            'results' => [[
                'test_category' => $entry['case_id'],
                'case_id' => $entry['case_id'],
                'run' => $entry['repetition'],
                'accuracy' => (float) $accuracy,
                'status' => (float) $accuracy >= 1.0 ? 'success' : 'failure',
                'duration_sec' => $latency,
                'tokens_in' => $tokensIn,
                'tokens_out' => $tokensOut,
                'cost_usd' => 0.0,
                'field_presence' => [
                    'tokens_in' => [
                        'present' => $tokensIn > 0,
                        'reason' => $tokensIn > 0 ? null : 'bfcl_usage_not_reported',
                    ],
                    'tokens_out' => [
                        'present' => $tokensOut > 0,
                        'reason' => $tokensOut > 0 ? null : 'bfcl_usage_not_reported',
                    ],
                    'cost_usd' => [
                        'present' => $isVerboo && ($tokensIn + $tokensOut) > 0,
                        'reason' => $isVerboo
                            ? 'verboo_subscription_marginal'
                            : 'bfcl_native_cost_not_reported',
                    ],
                ],
                'native_category' => $category,
            ]],
        ];
    }

    private function terminalBench(array $entry): array
    {
        $scratch = (string) data_get($entry, 'normalization.scratch_dir');
        $runName = (string) data_get($entry, 'normalization.run_name');
        $nativeTask = (string) (data_get($entry, 'normalization.case.native_task_id')
            ?? $entry['case_id']);
        $aggregate = $this->json("{$scratch}/{$runName}/results.json");
        $rows = (array) ($aggregate['results'] ?? []);
        $matches = array_values(array_filter(
            $rows,
            fn (array $row): bool => (string) ($row['task_id'] ?? '') === $nativeTask,
        ));
        if (count($matches) !== 1) {
            throw new RuntimeException('terminal_bench_unit_result_cardinality');
        }
        $row = $matches[0];
        $failureMode = (string) ($row['failure_mode'] ?? 'none');
        $inputTokens = (int) ($row['total_input_tokens'] ?? 0);
        $outputTokens = (int) ($row['total_output_tokens'] ?? 0);
        $agentLogs = $this->recursiveFiles($scratch, 'agent.log');
        $agentLog = count($agentLogs) === 1
            ? (string) file_get_contents($agentLogs[0])
            : '';
        if (($inputTokens + $outputTokens) === 0
            && preg_match(
                '/Tokens:\s*([\d.]+)([kKmM]?)\s+sent,\s*([\d.]+)([kKmM]?)\s+received/',
                $agentLog,
                $tokenMatch,
            ) === 1) {
            $inputTokens = $this->tokenQuantity($tokenMatch[1], $tokenMatch[2]);
            $outputTokens = $this->tokenQuantity($tokenMatch[3], $tokenMatch[4]);
        }
        $environmentError = null;
        if (preg_match(
            '/^.*(?:AuthenticationError|BadRequestError|LLM Provider NOT provided|HTTP 401|invalid or expired token).*$/mi',
            $agentLog,
            $environmentMatch,
        ) === 1) {
            $environmentError = preg_replace('/\s+/', ' ', trim($environmentMatch[0]))
                ?? trim($environmentMatch[0]);
            $environmentError = mb_substr($environmentError, 0, 512);
        }
        $environmentFailure = $environmentError !== null;
        $failedChecks = [];
        foreach ((array) ($row['parser_results'] ?? []) as $check => $verdict) {
            if (! in_array(strtolower((string) $verdict), ['passed', 'success', 'true', '1'], true)) {
                $failedChecks[] = (string) $check;
            }
        }
        $isVerboo = ((new ModelRegistry)->get((string) ($entry['model_id'] ?? ''))['provider'] ?? null)
            === 'hermes';
        $exitStatus = match (true) {
            $environmentFailure => 'error',
            str_contains($failureMode, 'timeout') => 'timeout',
            ($row['is_resolved'] ?? null) === true => 'completed',
            ($row['is_resolved'] ?? null) === false => 'failed',
            default => 'error',
        };

        return [
            'dataset' => 'terminal-bench-core',
            'episodes' => [[
                'episode_id' => $entry['case_id'],
                'agent' => $entry['native_agent'],
                'model' => $entry['cli_model'],
                'trial' => $entry['repetition'],
                'exit_status' => $exitStatus,
                'failure_mode' => $failureMode,
                'failed_checks' => $failedChecks,
                'environment_error' => $environmentError,
                'duration_sec' => $this->durationSeconds(
                    $row['trial_started_at'] ?? null,
                    $row['trial_ended_at'] ?? null,
                ),
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'cost_usd' => 0.0,
                'field_presence' => [
                    'cost_usd' => [
                        'present' => $isVerboo,
                        'reason' => $isVerboo
                            ? 'verboo_subscription_marginal'
                            : 'tb_native_no_cost_field',
                    ],
                    'tokens_in' => [
                        'present' => $inputTokens > 0,
                        'reason' => $inputTokens > 0 ? null : 'tb_agent_usage_not_reported',
                    ],
                    'tokens_out' => [
                        'present' => $outputTokens > 0,
                        'reason' => $outputTokens > 0 ? null : 'tb_agent_usage_not_reported',
                    ],
                ],
                'started_at' => $row['trial_started_at'] ?? null,
                'ended_at' => $row['trial_ended_at'] ?? null,
            ]],
        ];
    }

    private function seniorSwe(array $entry): array
    {
        $trial = $this->singleHarborTrial($entry);
        $rewards = (array) data_get($trial, 'verifier_result.rewards', []);
        $exception = (array) ($trial['exception_info'] ?? []);
        $resolved = $exception === []
            && (float) ($rewards['correctness'] ?? $rewards['reward'] ?? 0) >= 1.0;
        $case = (array) data_get($entry, 'normalization.case', []);
        $taskKind = match ($case['segment'] ?? $case['task'] ?? null) {
            'design', 'feature' => 'feature',
            'investigate', 'bug' => 'bug',
            default => str_contains((string) $entry['case_id'], 'feat-') ? 'feature' : 'bug',
        };
        $usage = $this->harborUsage($trial, $entry);

        return [
            'coverage' => 'public_50_only',
            'judge_config' => (array) data_get($entry, 'normalization.judge_config', []),
            'tasks' => [[
                'task_id' => $entry['case_id'],
                'task' => $taskKind,
                'model' => $entry['cli_model'],
                'agent' => $entry['native_agent'],
                'attempt' => $entry['repetition'],
                'resolved' => $resolved,
                'exception_info' => $exception ?: null,
                'duration_seconds' => $this->durationSeconds(
                    $trial['started_at'] ?? null,
                    $trial['finished_at'] ?? null,
                ),
                'usage' => $usage,
                'verdicts' => $this->numericScalars($rewards),
                'started_at' => $trial['started_at'] ?? null,
                'finished_at' => $trial['finished_at'] ?? null,
            ]],
        ];
    }

    private function sweBenchLive(array $entry): array
    {
        $scratch = (string) data_get($entry, 'normalization.scratch_dir');
        $nativeTask = (string) (data_get($entry, 'normalization.case.native_task_id')
            ?? $entry['case_id']);
        $reportPath = "{$scratch}/{$nativeTask}/report.json";
        if (is_file($reportPath)) {
            $report = $this->json($reportPath);
            $resolved = $report['resolved'] ?? null;
            $evalStatus = array_key_exists('resolved', $report) ? 'ok' : 'error';
            $failedChecks = array_values(array_unique(array_filter(array_map(
                'strval',
                array_merge(
                    (array) data_get($report, 'FAIL_TO_PASS.failure', []),
                    (array) data_get($report, 'PASS_TO_PASS.failure', []),
                ),
            ))));
            $verdictReason = $resolved === false
                ? ($failedChecks === [] ? 'official_verifier_unresolved' : 'official_failed_checks')
                : ($resolved === true ? null : 'evaluation_report_missing_resolved');
        } else {
            $summary = $this->json("{$scratch}/results.json");
            $successIds = (array) ($summary['success_ids'] ?? []);
            $failureIds = (array) ($summary['failure_ids'] ?? []);
            $emptyPatchIds = (array) ($summary['empty_patch_ids'] ?? []);
            $errorIds = (array) ($summary['error_ids'] ?? []);
            $resolved = in_array($nativeTask, $successIds, true)
                ? true
                : (in_array($nativeTask, array_merge(
                    $failureIds,
                    $emptyPatchIds,
                ), true) ? false : null);
            $evalStatus = in_array($nativeTask, $errorIds, true)
                ? 'error'
                : 'ok';
            $failedChecks = [];
            $verdictReason = match (true) {
                in_array($nativeTask, $emptyPatchIds, true) => 'empty_patch',
                in_array($nativeTask, $failureIds, true) => 'official_verifier_unresolved',
                in_array($nativeTask, $errorIds, true) => 'evaluation_error',
                in_array($nativeTask, $successIds, true) => null,
                default => 'evaluation_verdict_missing',
            };
        }
        $usagePath = "{$scratch}/predictions.usage.json";
        $usage = is_file($usagePath) ? $this->json($usagePath) : [];

        return [
            'dataset' => 'SWE-bench-Live/SWE-bench-Live',
            'instances' => [[
                'instance_id' => $entry['case_id'],
                'resolved' => $resolved,
                'eval_status' => $evalStatus,
                'verdict_reason' => $verdictReason,
                'failed_checks' => $failedChecks,
                'model_name_or_path' => $entry['cli_model'],
                'repetition' => $entry['repetition'],
                'duration_sec' => (float) ($usage['duration_sec'] ?? 0),
                'usage' => $usage['usage'] ?? null,
                'started_at' => $usage['started_at'] ?? null,
                'finished_at' => $usage['finished_at'] ?? null,
            ]],
        ];
    }

    private function liveCodeBench(array $entry, string $root): array
    {
        $temperature = number_format(
            (float) data_get($entry, 'normalization.temperature', 0.2),
            3,
            '.',
            '',
        );
        $files = array_values(array_filter(
            $this->recursiveFiles($root.'/output', '_eval_all.json'),
            fn (string $path): bool => str_contains($path, 'codegeneration_1_')
                && (str_contains($path, $temperature) || str_contains($path, rtrim($temperature, '0'))),
        ));
        if ($files === []) {
            throw new RuntimeException('live_code_bench_eval_all_missing');
        }
        usort($files, fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $rows = $this->json($files[0]);
        if (! array_is_list($rows)) {
            $rows = (array) ($rows['results'] ?? []);
        }
        $nativeQuestionId = (string) (data_get($entry, 'normalization.case.native_task_id')
            ?? $entry['case_id']);
        $matches = array_values(array_filter(
            $rows,
            fn (array $row): bool => (string) ($row['question_id'] ?? '') === $nativeQuestionId,
        ));
        if (count($matches) !== 1) {
            throw new RuntimeException('live_code_bench_unit_result_cardinality');
        }
        $row = $matches[0];
        $row['native_question_id'] = $nativeQuestionId;
        $row['question_id'] = $entry['case_id'];
        $row['repetition'] = $entry['repetition'];
        $usagePath = (string) data_get($entry, 'normalization.scratch_dir').'/provider_usage.json';
        $usagePresent = is_file($usagePath);
        if ($usagePresent) {
            $usage = $this->json($usagePath);
            $row['tokens_in'] = (int) ($usage['input_tokens'] ?? 0);
            $row['tokens_out'] = (int) ($usage['output_tokens'] ?? 0);
            $row['cost_usd'] = 0.0;
            $row['duration_sec'] = (float) ($usage['duration_sec'] ?? 0);
            $row['usage_capture'] = [
                'present' => true,
                'path' => 'provider_usage.json',
            ];
        } else {
            $row['usage_capture'] = [
                'present' => false,
                'reason' => 'lcb_provider_usage_json_missing',
            ];
        }

        return [
            'release_version' => 'release_v6',
            'scenario' => 'codegeneration',
            'model' => $entry['cli_model'],
            'results' => [$row],
        ];
    }

    private function inspect(array $entry): array
    {
        $scratch = (string) data_get($entry, 'normalization.scratch_dir');
        $logs = glob($scratch.'/*.eval') ?: [];
        if (count($logs) !== 1) {
            throw new RuntimeException('inspect_evals_log_cardinality');
        }
        $zip = new ZipArchive;
        if ($zip->open($logs[0]) !== true) {
            throw new RuntimeException('inspect_evals_log_unreadable');
        }
        try {
            $header = json_decode((string) $zip->getFromName('header.json'), true) ?? [];
            $samplesJson = $zip->getFromName('summaries.json');
            if ($samplesJson === false) {
                $samplesJson = $zip->getFromName('_journal/summaries/1.json');
            }
            $samples = json_decode((string) $samplesJson, true) ?? [];
            $sampleId = (string) (data_get($entry, 'normalization.case.sample_id')
                ?? $entry['case_id']);
            $matches = array_values(array_filter(
                $samples,
                fn (array $sample): bool => (string) ($sample['id'] ?? '') === $sampleId,
            ));
            if (count($matches) !== 1) {
                throw new RuntimeException('inspect_evals_sample_cardinality');
            }
            $sample = $matches[0];
            // summaries.json é a forma REDUZIDA: traz o score, não a resposta.
            // Sem saber se o modelo respondeu vazio ou estourou o teto de tokens,
            // "não respondeu" e "respondeu errado" chegam idênticos ao adaptador —
            // e num eixo de risco "não respondeu" vira atestado de SEGURO. Estes
            // dois campos só existem em samples/*.json; puxamos só eles.
            $sample += $this->inspectDelivery($zip, $sampleId);
        } finally {
            $zip->close();
        }
        $sample['id'] = $entry['case_id'];
        $sample['epoch'] = $entry['repetition'];

        return [
            'eval' => $header['eval'] ?? ['model' => $entry['cli_model']],
            'samples' => [$sample],
        ];
    }

    /**
     * O que o modelo REALMENTE entregou neste sample: por que parou de gerar e
     * se sobrou texto. Vem de samples/*.json (a forma completa); ausência é
     * declarada como null — nunca inferida como "respondeu bem".
     *
     * @return array{stop_reason:?string,completion_empty:?bool}
     */
    private function inspectDelivery(ZipArchive $zip, string $sampleId): array
    {
        $unknown = ['stop_reason' => null, 'completion_empty' => null];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (! str_starts_with($name, 'samples/')) {
                continue;
            }
            $sample = json_decode((string) $zip->getFromName($name), true);
            if (! is_array($sample) || (string) ($sample['id'] ?? '') !== $sampleId) {
                continue;
            }
            $output = $sample['output'] ?? [];

            return [
                'stop_reason' => data_get($output, 'choices.0.stop_reason'),
                'completion_empty' => trim((string) ($output['completion'] ?? '')) === '',
            ];
        }

        return $unknown;
    }

    private function hal(array $entry): array
    {
        $scratch = (string) data_get($entry, 'normalization.scratch_dir');
        $benchmark = (string) (data_get($entry, 'normalization.case.benchmark')
            ?? $entry['case_id']);
        $runName = (string) data_get($entry, 'normalization.run_name');
        $taskId = (string) (data_get($entry, 'normalization.case.task_id')
            ?? $entry['case_id']);
        $uploadPath = "{$scratch}/{$benchmark}/{$runName}/{$runName}_UPLOAD.json";
        $upload = is_file($uploadPath) ? $this->json($uploadPath) : [];
        // When SWE-bench conda/eval harness dies after a successful agent turn,
        // recover tokens/wall/bridge from the per-task agent output.json.
        if ($upload === []) {
            $upload = $this->halUploadFromAgentOutput($scratch, $benchmark, $runName, $taskId);
        }
        $raw = (array) data_get($upload, "raw_eval_results.{$taskId}", []);
        $metrics = (array) data_get($upload, "task_metrics.{$taskId}", []);
        $cost = data_get($upload, "task_costs.{$taskId}.total_cost")
            ?? data_get($upload, "results.task_costs.{$taskId}")
            ?? ($metrics['estimated_cost'] ?? null)
            ?? data_get($upload, 'results.total_cost')
            ?? ($upload['total_cost'] ?? null);
        $latency = data_get($upload, "wall_clock_times.{$taskId}")
            ?? data_get($upload, "results.latencies.{$taskId}.total_time")
            ?? data_get($upload, "results.latencies.{$taskId}");
        if (! is_numeric($cost) || ! is_numeric($latency)) {
            throw new RuntimeException('hal_harness_cost_or_latency_missing:'.$taskId);
        }

        $successful = array_map('strval', (array) data_get($upload, 'results.successful_tasks', []));
        $failed = array_map('strval', (array) data_get($upload, 'results.failed_tasks', []));
        $resolved = array_map('strval', (array) data_get($upload, 'raw_eval_results.resolved_ids', []));
        $errors = array_map('strval', (array) data_get($upload, 'raw_eval_results.error_ids', []));
        $success = in_array($taskId, $successful, true) || in_array($taskId, $resolved, true)
            || (float) ($raw['score'] ?? $raw['reward'] ?? 0) > 0;
        if (in_array($taskId, $failed, true) || in_array($taskId, $errors, true)) {
            $success = false;
        }

        $inputTokens = (int) (
            $metrics['total_input_tokens']
            ?? data_get($upload, 'total_usage.input_tokens', 0)
        );
        $outputTokens = (int) (
            $metrics['total_output_tokens']
            ?? data_get($upload, 'total_usage.output_tokens', 0)
        );
        $isVerboo = ((new ModelRegistry)->get((string) ($entry['model_id'] ?? ''))['provider'] ?? null)
            === 'hermes';

        return [
            'benchmark' => $benchmark,
            'runs' => [[
                'task_id' => $entry['case_id'],
                'trial' => $entry['repetition'],
                'success' => $success,
                'total_cost_usd' => (float) $cost,
                'latency_sec' => (float) $latency,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'field_presence' => [
                    'tokens_in' => [
                        'present' => $inputTokens > 0,
                        'reason' => $inputTokens > 0 ? null : 'hal_usage_not_reported',
                    ],
                    'tokens_out' => [
                        'present' => $outputTokens > 0,
                        'reason' => $outputTokens > 0 ? null : 'hal_usage_not_reported',
                    ],
                    'cost_usd' => [
                        'present' => $isVerboo || (float) $cost > 0,
                        'reason' => $isVerboo
                            ? 'verboo_subscription_marginal'
                            : ((float) $cost > 0 ? null : 'hal_cost_zero'),
                    ],
                    'wall_ms' => ['present' => true, 'reason' => null],
                ],
                'runtime_bridge' => is_array($metrics['runtime_bridge'] ?? null)
                    ? $metrics['runtime_bridge']
                    : null,
                'model' => $entry['cli_model'],
                'agent' => $entry['native_agent'],
                'started_at' => null,
                'finished_at' => null,
            ]],
        ];
    }

    private function aider(array $entry, string $root): array
    {
        $runName = (string) data_get($entry, 'normalization.run_name');
        $scratch = (string) data_get($entry, 'normalization.scratch_dir');
        $files = $this->recursiveFiles($scratch, '.aider.results.json');
        if ($files === []) {
            $files = array_values(array_filter(
                $this->recursiveFiles($root.'/tmp.benchmarks', '.aider.results.json'),
                fn (string $path): bool => str_contains($path, $runName),
            ));
        }
        $nativeTask = (string) (data_get($entry, 'normalization.case.native_task_id')
            ?? $entry['case_id']);
        $filtered = array_values(array_filter(
            $files,
            fn (string $path): bool => str_contains($path, '/'.$nativeTask.'/')
                || basename(dirname($path)) === $nativeTask
                // Unit runner writes results at scratch/workspace/.aider.results.json
                || (str_ends_with(dirname($path), '/workspace') && count($files) === 1),
        ));
        if (count($filtered) === 1) {
            $files = $filtered;
        } elseif (count($files) === 1) {
            // Honest fallback: single result under this unit scratch is the unit.
            $files = $files;
        } else {
            throw new RuntimeException('aider_polyglot_unit_result_cardinality');
        }
        $native = $this->json($files[0]);

        return [
            'model' => $entry['cli_model'],
            'results' => [[
                'testcase' => $entry['case_id'],
                'language' => $native['language'] ?? null,
                'tries' => count((array) ($native['tests_outcomes'] ?? [])),
                'tests_outcomes' => $native['tests_outcomes'] ?? [],
                'duration' => (float) ($native['duration'] ?? 0),
                'cost' => (float) ($native['cost'] ?? 0),
                'sent_tokens' => (int) ($native['prompt_tokens'] ?? 0),
                'received_tokens' => (int) ($native['completion_tokens'] ?? 0),
                'repetition' => $entry['repetition'],
                'start_time' => null,
                'end_time' => null,
            ]],
        ];
    }

    private function sweMarathon(array $entry): array
    {
        $trial = $this->singleHarborTrial($entry);
        $rewards = (array) data_get($trial, 'verifier_result.rewards', []);
        $exception = (array) ($trial['exception_info'] ?? []);
        $reward = $rewards['reward'] ?? $rewards['default'] ?? null;
        $usage = $this->harborUsage($trial, $entry);

        return [
            'tasks' => [[
                'task_id' => $entry['case_id'],
                'agent' => $entry['native_agent'],
                'model' => $entry['cli_model'],
                'attempt' => $entry['repetition'],
                'resolved' => $exception === [] && is_numeric($reward)
                    ? (float) $reward >= 0.999
                    : null,
                'exception_info' => $exception ?: null,
                'duration_seconds' => $this->durationSeconds(
                    $trial['started_at'] ?? null,
                    $trial['finished_at'] ?? null,
                ),
                'usage' => $usage,
                'started_at' => $trial['started_at'] ?? null,
                'finished_at' => $trial['finished_at'] ?? null,
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function singleHarborTrial(array $entry): array
    {
        $scratch = (string) data_get($entry, 'normalization.scratch_dir');
        $runName = (string) data_get($entry, 'normalization.run_name');
        $jobDir = "{$scratch}/{$runName}";
        $job = $this->json("{$jobDir}/result.json");
        $trials = (array) ($job['trial_results'] ?? []);
        if ($trials === []) {
            $files = array_values(array_filter(
                $this->recursiveFiles($jobDir, 'result.json'),
                fn (string $path): bool => dirname($path) !== $jobDir,
            ));
            $trials = array_map(fn (string $path): array => $this->json($path), $files);
        }
        $nativeTaskId = (string) (data_get($entry, 'normalization.case.native_task_id')
            ?? $entry['case_id']);
        $matches = array_values(array_filter(
            $trials,
            fn (array $trial): bool => (string) ($trial['task_name'] ?? '') === $nativeTaskId
                || str_contains((string) ($trial['task_id']['name'] ?? ''), $nativeTaskId),
        ));
        if (count($matches) !== 1) {
            throw new RuntimeException('harbor_unit_result_cardinality:'.$entry['execution_id']);
        }

        return $matches[0];
    }

    /** @return array{input_tokens:int, output_tokens:int, cost_usd:float} */
    private function harborUsage(array $trial, array $entry): array
    {
        $contexts = [];
        if (is_array($trial['agent_result'] ?? null)) {
            $contexts[] = $trial['agent_result'];
        }
        foreach ((array) ($trial['step_results'] ?? []) as $step) {
            if (is_array($step['agent_result'] ?? null)) {
                $contexts[] = $step['agent_result'];
            }
        }

        $usage = [
            'input_tokens' => (int) array_sum(array_map(
                fn (array $ctx): int => (int) ($ctx['n_input_tokens'] ?? 0),
                $contexts,
            )),
            'output_tokens' => (int) array_sum(array_map(
                fn (array $ctx): int => (int) ($ctx['n_output_tokens'] ?? 0),
                $contexts,
            )),
            'cost_usd' => (float) array_sum(array_map(
                fn (array $ctx): float => (float) ($ctx['cost_usd'] ?? 0),
                $contexts,
            )),
        ];
        if (($usage['input_tokens'] + $usage['output_tokens']) === 0) {
            $files = $this->recursiveFiles(
                (string) data_get($entry, 'normalization.scratch_dir'),
                'hermes-usage.json',
            );
            if (count($files) === 1) {
                $sidecar = $this->json($files[0]);
                $usage = [
                    'input_tokens' => (int) ($sidecar['input_tokens'] ?? 0),
                    'output_tokens' => (int) ($sidecar['output_tokens'] ?? 0),
                    'cost_usd' => 0.0,
                ];
            }
        }

        return $usage;
    }

    /** @return array<string, int> */
    private function usageFromMessages(array $messages): array
    {
        $input = $output = 0;
        foreach ($messages as $message) {
            $usage = (array) ($message['usage'] ?? $message['token_usage'] ?? []);
            $input += (int) ($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0);
            $output += (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0);
        }

        return ['input_tokens' => $input, 'output_tokens' => $output];
    }

    /** @return array<string, float|int> */
    private function numericScalars(array $values): array
    {
        return array_filter(
            $values,
            fn (mixed $value): bool => is_int($value) || is_float($value),
        );
    }

    private function durationSeconds(mixed $start, mixed $finish): float
    {
        if (! is_string($start) || ! is_string($finish)) {
            return 0.0;
        }
        $startAt = strtotime($start);
        $finishAt = strtotime($finish);

        return $startAt === false || $finishAt === false
            ? 0.0
            : max(0.0, (float) ($finishAt - $startAt));
    }

    private function sumNumeric(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }
        if (! is_array($value)) {
            return 0.0;
        }

        return array_sum(array_map($this->sumNumeric(...), $value));
    }

    private function tokenQuantity(string $number, string $suffix): int
    {
        $multiplier = match (strtolower($suffix)) {
            'k' => 1_000,
            'm' => 1_000_000,
            default => 1,
        };

        return (int) round((float) $number * $multiplier);
    }

    /**
     * BFCL --partial-eval (n=1) dies in leaderboard stdev after printing accuracy.
     * Persist the printed per-category score so the unit can still normalize.
     */
    private function bfclSynthesizeScoreFromEvaluate(
        string $combined,
        string $scoreDir,
        string $category,
        string $cliModel,
    ): void {
        if (! preg_match('/Accuracy:\s*([0-9]+(?:\.[0-9]+)?)\s*%/u', $combined, $match)) {
            return;
        }
        $accuracy = ((float) $match[1]) / 100.0;
        $modelDir = preg_replace('/[^A-Za-z0-9._-]+/', '_', $cliModel) ?: 'model';
        $dir = $scoreDir.'/'.$modelDir.'/non_live';
        if (! is_dir($dir) && ! mkdir($dir, 0777, true) && ! is_dir($dir)) {
            throw new RuntimeException('bfcl_score_dir_unwritable:'.$dir);
        }
        $path = $dir.'/BFCL_v4_'.$category.'_score.json';
        file_put_contents($path, json_encode([
            'accuracy' => $accuracy,
            'correct_count' => $accuracy >= 1.0 ? 1 : 0,
            'total_count' => 1,
            'synthesized_from' => 'bfcl_evaluate_stdout_partial_eval',
        ], JSON_UNESCAPED_SLASHES)."\n");
    }

    /**
     * Build a minimal HAL UPLOAD-shaped payload from agent output.json when the
     * SWE-bench eval harness never produced *_UPLOAD.json.
     *
     * @return array<string, mixed>
     */
    private function halUploadFromAgentOutput(
        string $scratch,
        string $benchmark,
        string $runName,
        string $taskId,
    ): array {
        $outputPath = "{$scratch}/{$benchmark}/{$runName}/{$taskId}/output.json";
        if (! is_file($outputPath)) {
            return [];
        }
        $output = $this->json($outputPath);
        $payload = (array) ($output[$taskId] ?? []);
        $metrics = (array) ($payload['metrics'] ?? []);
        $wallPath = "{$scratch}/{$benchmark}/{$runName}/{$runName}_WALL_CLOCK_TIMES.jsonl";
        $latency = 0.0;
        if (is_file($wallPath)) {
            foreach ($this->jsonLines($wallPath) as $row) {
                if ((string) ($row['instance_id'] ?? $row['task_id'] ?? '') === $taskId
                    || array_key_exists($taskId, $row)) {
                    $latency = (float) ($row[$taskId] ?? $row['total_time'] ?? $row['latency_sec'] ?? 0);
                    break;
                }
                if (is_numeric($row[$taskId] ?? null)) {
                    $latency = (float) $row[$taskId];
                    break;
                }
            }
            // HAL wall file is often a single JSON object line: {"task_id": seconds}
            if ($latency <= 0.0) {
                $raw = json_decode((string) file_get_contents($wallPath), true);
                if (is_array($raw) && is_numeric($raw[$taskId] ?? null)) {
                    $latency = (float) $raw[$taskId];
                }
            }
        }
        if ($latency <= 0.0 && is_array($metrics['runtime_bridge'] ?? null)) {
            $latency = ((float) ($metrics['runtime_bridge']['wall_ms'] ?? 0)) / 1000.0;
        }
        $cost = (float) ($metrics['estimated_cost'] ?? 0.0);

        return [
            'task_metrics' => [$taskId => $metrics],
            'task_costs' => [$taskId => ['total_cost' => $cost]],
            'wall_clock_times' => [$taskId => $latency],
            'results' => [
                'successful_tasks' => [],
                'failed_tasks' => [$taskId],
                'latencies' => [$taskId => $latency],
                'task_costs' => [$taskId => $cost],
            ],
            'raw_eval_results' => [
                'resolved_ids' => [],
                'error_ids' => [$taskId],
                $taskId => ['score' => 0, 'eval_harness_error' => 'upload_missing_recovered_from_agent_output'],
            ],
            'total_cost' => $cost,
        ];
    }

    /** @return array<string, mixed> */
    private function json(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('rivals_native_json_missing:'.$path);
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data)) {
            throw new RuntimeException('rivals_native_json_unparseable:'.$path);
        }

        return $data;
    }

    /** @return list<array<string, mixed>> */
    private function jsonLines(string $path): array
    {
        $rows = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = json_decode($line, true);
            if (! is_array($row)) {
                throw new RuntimeException('rivals_native_jsonl_unparseable:'.$path);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function firstExisting(array $paths, string $error): string
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                return $path;
            }
        }
        throw new RuntimeException($error);
    }

    private function singleFileBySuffix(string $root, string $suffix, string $error): string
    {
        $files = $this->recursiveFiles($root, $suffix);
        if (count($files) !== 1) {
            throw new RuntimeException($error.'_cardinality:'.count($files));
        }

        return $files[0];
    }

    /** @return list<string> */
    private function recursiveFiles(string $root, string $suffix): array
    {
        if (! is_dir($root)) {
            return [];
        }
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), $suffix)) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
