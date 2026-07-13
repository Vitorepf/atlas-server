<?php

namespace App\Services\Ai\Rivals\Core;

/**
 * Contrato estático do que cada suite Fase A entrega — sempre presente no enterprise report,
 * mesmo quando o run ainda não rodou (campos observed ficam vazios / not_run).
 */
final class EnterpriseSuiteDeliveryCatalog
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $uplift = (array) config('atlas_rivals.uplift_families', []);
        $familyBySuite = [];
        foreach ($uplift as $family => $suiteId) {
            $familyBySuite[(string) $suiteId] = (string) $family;
        }
        $casePacks = (array) config('atlas_rivals.fase_a.case_packs', []);

        $catalog = [
            'tau2_bench' => [
                'category' => 'Tool use',
                'title' => 'τ²-Bench',
                'origin' => 'sierra-research/tau2-bench',
                'purpose' => 'Multi-turn tool-use / agent dialog (airline domain in Fase A).',
                'task_types' => ['tool_use_function_calling'],
                'native_artifact' => 'data/simulations/{run}/results.json (+ per-sim JSON)',
                'native_metrics' => [
                    'reward', 'termination_reason', 'duration_sec',
                    'usage.input_tokens', 'usage.output_tokens', 'usage.cost_usd',
                    'agent_llm', 'simulation_id', 'task_id',
                ],
                'atlas_report_metrics' => self::sharedReportMetrics(),
                'capability_dimensions' => [],
                'native_categories' => ['domain=airline (Fase A case pack)'],
                'per_run_reports' => ['report.json', 'report.md', 'report.csv', 'adjudication.json', 'evidence_pack.json'],
                'graphs' => ['none_native', 'enterprise_aggregate_only'],
                'notes' => ['Success = reward ≥ 1.0', 'Not in uplift families'],
            ],
            'bfcl' => [
                'category' => 'Tool use',
                'title' => 'BFCL',
                'origin' => 'ShishirPatil/gorilla (BFCL)',
                'purpose' => 'Structured function calling (simple / multiple / parallel).',
                'task_types' => ['tool_use_function_calling'],
                'native_artifact' => '*_result.json + *_score.json (JSONL)',
                'native_metrics' => [
                    'accuracy', 'status', 'duration_sec', 'tokens_in', 'tokens_out', 'cost_usd',
                    'test_category', 'native_category', 'field_presence',
                ],
                'atlas_report_metrics' => self::sharedReportMetrics(),
                'capability_dimensions' => [],
                'native_categories' => ['simple', 'multiple', 'parallel'],
                'per_run_reports' => ['report.json', 'report.md', 'report.csv', 'adjudication.json', 'evidence_pack.json'],
                'graphs' => ['none_native', 'enterprise_aggregate_only'],
                'notes' => ['Accuracy kept in metadata.native / native_signals', 'Uplift family tool_function'],
            ],
            'terminal_bench' => [
                'category' => 'Terminal',
                'title' => 'Terminal-Bench',
                'origin' => 'laude-institute/terminal-bench',
                'purpose' => 'Real shell / terminal agent tasks.',
                'task_types' => ['terminal_agent'],
                'native_artifact' => '{run}/results.json (+ agent.log sidecar)',
                'native_metrics' => [
                    'exit_status', 'failure_mode', 'duration_sec',
                    'input_tokens', 'output_tokens', 'cost_usd', 'field_presence',
                ],
                'atlas_report_metrics' => self::sharedReportMetrics(),
                'capability_dimensions' => [],
                'native_categories' => ['terminal-bench-core'],
                'per_run_reports' => ['report.json', 'report.md', 'report.csv', 'adjudication.json', 'evidence_pack.json'],
                'graphs' => ['none_native', 'enterprise_aggregate_only'],
                'notes' => ['Auth/provider errors → environment_failure', 'Uplift family terminal'],
            ],
            'senior_swe_bench' => [
                'category' => 'SWE',
                'title' => 'Senior SWE-Bench',
                'origin' => 'snorkel-ai/senior-swe-bench',
                'purpose' => 'Senior Harbor SWE tasks with multi-axis judge verdicts.',
                'task_types' => ['feature_under_specified', 'bug_investigation'],
                'native_artifact' => 'Harbor result.json / trial_results',
                'native_metrics' => [
                    'resolved', 'exception_info', 'duration_seconds', 'usage',
                    'verdicts.*', 'judge_config', 'coverage',
                ],
                'atlas_report_metrics' => array_merge(self::sharedReportMetrics(), ['dimensions']),
                'capability_dimensions' => [
                    'correctness', 'validation', 'rubric', 'taste', 'bloat_practice',
                ],
                'native_categories' => ['feature', 'bug', 'coverage=public_50_only'],
                'per_run_reports' => ['report.json', 'report.md', 'report.csv', 'adjudication.json', 'evidence_pack.json'],
                'graphs' => ['none_native', 'enterprise_capacity_axis_from_dimensions'],
                'notes' => ['Only suite that routinely populates report dimensions / capacity', 'Requires judge_config'],
            ],
            'swe_bench_live' => [
                'category' => 'SWE',
                'title' => 'SWE-Bench Live',
                'origin' => 'microsoft/SWE-bench-Live',
                'purpose' => 'Live GitHub issue repair (predictions then evaluation).',
                'task_types' => ['repair_regression_fixing'],
                'native_artifact' => 'report.json / results.json + predictions.usage.json',
                'native_metrics' => [
                    'resolved', 'eval_status', 'duration_sec', 'usage',
                    'instance_id', 'model_name_or_path',
                ],
                'atlas_report_metrics' => self::sharedReportMetrics(),
                'capability_dimensions' => [],
                'native_categories' => ['SWE-bench-Live'],
                'per_run_reports' => ['report.json', 'report.md', 'report.csv', 'adjudication.json', 'evidence_pack.json'],
                'graphs' => ['none_native', 'enterprise_aggregate_only'],
                'notes' => ['Eval harness often omits agent usage', 'Uplift family patch_swe'],
            ],
            'live_code_bench' => [
                'category' => 'Coding',
                'title' => 'LiveCodeBench',
                'origin' => 'LiveCodeBench/LiveCodeBench',
                'purpose' => 'Contest coding with auto-judge.',
                'task_types' => ['coding_patch'],
                'native_artifact' => '*_eval_all.json (+ provider_usage.json sidecar)',
                'native_metrics' => [
                    'pass@1', 'graded_list', 'question_id', 'difficulty',
                    'platform', 'contest_id', 'usage_capture', 'tokens_in', 'tokens_out', 'duration_sec',
                ],
                'atlas_report_metrics' => self::sharedReportMetrics(),
                'capability_dimensions' => [],
                'native_categories' => ['scenario/release_version from harness'],
                'per_run_reports' => ['report.json', 'report.md', 'report.csv', 'adjudication.json', 'evidence_pack.json'],
                'graphs' => ['none_native', 'enterprise_aggregate_only'],
                'notes' => [
                    'pass@1 stays in native_signals, not report dimensions',
                    'Tokens require provider_usage.json sidecar; absent scratch → honest omit (lcb_provider_usage_json_missing)',
                ],
            ],
            'inspect_evals' => [
                'category' => 'Reasoning',
                'title' => 'Inspect Evals',
                'origin' => 'UKGovernmentBEIS/inspect_evals',
                'purpose' => 'Inspect AI evals (Fase A: GSM8K samples).',
                'task_types' => ['coding_patch', 'tool_use_function_calling'],
                'native_artifact' => '*.eval zip (header + summaries)',
                'native_metrics' => [
                    'scores', 'total_time', 'working_time', 'model_usage',
                    'completed', 'error', 'retries', 'sample_id',
                ],
                'atlas_report_metrics' => self::sharedReportMetrics(),
                'capability_dimensions' => [],
                'native_categories' => ['gsm8k (Fase A case pack)'],
                'per_run_reports' => ['report.json', 'report.md', 'report.csv', 'adjudication.json', 'evidence_pack.json'],
                'graphs' => ['none_native', 'enterprise_aggregate_only'],
                'notes' => [
                    'mockllm ⇒ harness_only (no market claim)',
                    'Harness omits provider usage in *.eval logs → tokens stay missing_data (inspect_logs_omit_usage); never invent 0',
                ],
            ],
            'hal_harness' => [
                'category' => 'Long horizon',
                'title' => 'HAL Harness',
                'origin' => 'princeton-pli/hal-harness',
                'purpose' => 'Long-horizon agentic harness (UPLOAD.json).',
                'task_types' => ['long_horizon_engineering'],
                'native_artifact' => '{run}_UPLOAD.json',
                'native_metrics' => [
                    'success', 'total_cost_usd', 'latency_sec',
                    'input_tokens', 'output_tokens', 'field_presence',
                    'runtime_bridge', 'model', 'agent',
                ],
                'atlas_report_metrics' => self::sharedReportMetrics(),
                'capability_dimensions' => [],
                'native_categories' => ['HAL benchmark id from harness'],
                'per_run_reports' => ['report.json', 'report.md', 'report.csv', 'adjudication.json', 'evidence_pack.json'],
                'graphs' => ['none_native', 'enterprise_aggregate_only'],
                'notes' => ['Cost + latency required (fail-closed)', 'Uplift family long_horizon'],
            ],
            'aider_polyglot' => [
                'category' => 'Coding',
                'title' => 'Aider Polyglot',
                'origin' => 'Aider-AI/aider (+ polyglot-benchmark)',
                'purpose' => 'Multi-language editing benchmark.',
                'task_types' => ['coding_patch'],
                'native_artifact' => '.aider.results.json',
                'native_metrics' => [
                    'language', 'tests_outcomes', 'tries', 'duration', 'cost',
                    'sent_tokens', 'received_tokens', 'testcase',
                ],
                'atlas_report_metrics' => self::sharedReportMetrics(),
                'capability_dimensions' => [],
                'native_categories' => ['language per testcase'],
                'per_run_reports' => ['report.json', 'report.md', 'report.csv', 'adjudication.json', 'evidence_pack.json'],
                'graphs' => ['none_native', 'enterprise_aggregate_only'],
                'notes' => ['Success = last tests_outcomes true', 'Uplift family polyglot'],
            ],
            'swe_marathon' => [
                'category' => 'Long horizon',
                'title' => 'SWE-Marathon',
                'origin' => 'abundant-ai/swe-marathon',
                'purpose' => 'Ultra long-horizon Harbor tasks (docker/Modal).',
                'task_types' => ['long_horizon_engineering'],
                'native_artifact' => 'Harbor result.json / trial_results',
                'native_metrics' => [
                    'resolved', 'exception_info', 'duration_seconds', 'usage',
                    'task_id', 'agent', 'model',
                ],
                'atlas_report_metrics' => self::sharedReportMetrics(),
                'capability_dimensions' => [],
                'native_categories' => ['Harbor marathon tasks'],
                'per_run_reports' => ['report.json', 'report.md', 'report.csv', 'adjudication.json', 'evidence_pack.json'],
                'graphs' => ['none_native', 'enterprise_aggregate_only'],
                'notes' => [
                    'Binary reward — no multi-axis verdicts',
                    'Env timeouts common',
                    'Harbor cardinality/env failures omit usage — coverage may be partial without inventing zeros',
                ],
            ],
        ];

        foreach ($catalog as $suiteId => &$entry) {
            $entry['suite_id'] = $suiteId;
            $entry['fase_a_case_pack'] = array_values(array_map('strval', (array) ($casePacks[$suiteId] ?? [])));
            $entry['uplift_family'] = $familyBySuite[$suiteId] ?? null;
            $entry['uplift_eligible'] = isset($familyBySuite[$suiteId]);
        }
        unset($entry);

        return $catalog;
    }

    /** @return list<string> */
    private static function sharedReportMetrics(): array
    {
        return [
            'n', 'planned_attempts', 'observed_attempts', 'valid_results', 'successes',
            'success_rate', 'success_rate_itt', 'success_rate_valid_results', 'success_rate_wilson_95',
            'environment_failure_rate', 'failure_classes',
            'total_cost_usd', 'avg_cost_usd', 'cost_per_task', 'median_cost_usd', 'p95_cost_usd', 'median_cost_ci_95',
            'avg_tokens_in', 'avg_tokens_out', 'total_tokens_in', 'total_tokens_out', 'total_tokens',
            'tokens_per_task', 'avg_tokens_per_task', 'tokens_in_per_task', 'tokens_out_per_task',
            'tokens_per_second', 'tokens_per_second_aggregate', 'tokens_in_per_second', 'tokens_out_per_second',
            'tokens_coverage', 'cost_per_1k_tokens',
            'avg_wall_ms', 'median_wall_ms', 'median_wall_sec', 'p95_wall_ms', 'median_wall_ci_95',
            'stability', 'avg_patch_bloat', 'reality', 'task_type', 'arm_id',
        ];
    }

    /** @return array<string, mixed> */
    public static function forSuite(string $suiteId): array
    {
        return self::all()[$suiteId] ?? [
            'suite_id' => $suiteId,
            'category' => 'Unknown',
            'title' => $suiteId,
            'origin' => null,
            'purpose' => 'External suite',
            'task_types' => [],
            'native_artifact' => null,
            'native_metrics' => [],
            'atlas_report_metrics' => self::sharedReportMetrics(),
            'capability_dimensions' => [],
            'native_categories' => [],
            'per_run_reports' => ['report.json', 'report.md', 'report.csv'],
            'graphs' => ['enterprise_aggregate_only'],
            'notes' => [],
            'fase_a_case_pack' => [],
            'uplift_family' => null,
            'uplift_eligible' => false,
        ];
    }
}
