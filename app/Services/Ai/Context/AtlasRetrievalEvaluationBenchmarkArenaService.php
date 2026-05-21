<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Carbon;

final class AtlasRetrievalEvaluationBenchmarkArenaService
{
    public const SCHEMA_VERSION = 'atlas.aucri.retrieval_evaluation_arena.v1';

    public const GOLDEN_CASE_SCHEMA = 'atlas.aucri.golden_retrieval_case.v1';

    public const EVAL_RESULT_SCHEMA = 'atlas.aucri.retrieval_eval_result.v1';

    public const SUMMARY_SCHEMA = 'atlas.aucri.retrieval_eval_summary.v1';

    public function __construct(
        private readonly AtlasContextFreshnessQualityGateService $freshnessQualityGate,
        private readonly AtlasRetrievalFeedbackLoopService $feedbackLoop,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function evaluate(array $input = []): array
    {
        $risk = $this->risk((string) ($input['risk_level'] ?? $input['risk'] ?? 'low'));
        $cases = $this->goldenCases((array) ($input['cases'] ?? []), $risk);
        $results = array_map(fn (array $case): array => $this->evaluateCase($case, $risk), $cases);
        $summary = $this->summary($results);
        $regressions = $this->regressions($results, $summary, $risk);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $this->status($regressions, $summary, $risk),
            'generated_at' => Carbon::now()->toIso8601String(),
            'arena' => [
                'name' => 'Atlas Context Arena',
                'mode' => 'internal_golden_set',
                'risk_level' => $risk,
                'external_rivals_enabled' => false,
                'external_benchmark_enabled' => false,
            ],
            'golden_set' => [
                'schema_version' => 'atlas.aucri.golden_set.v1',
                'case_count' => count($cases),
                'case_hashes' => array_values(array_map(
                    static fn (array $case): string => (string) $case['case_hash'],
                    $cases,
                )),
                'golden_set_hash' => MissionCanonicalHash::sha256(array_map(
                    static fn (array $case): array => [
                        'case_id' => $case['case_id'],
                        'query_hash' => $case['query_hash'],
                        'domain' => $case['domain'],
                        'task_type' => $case['task_type'],
                        'required_sources' => $case['required_sources'],
                    ],
                    $cases,
                )),
            ],
            'results' => $results,
            'summary' => $summary,
            'regression_report' => [
                'schema_version' => 'atlas.aucri.retrieval_regression_report.v1',
                'status' => $regressions === [] ? 'pass' : 'blocked',
                'regression_count' => count($regressions),
                'regressions' => $regressions,
            ],
            'promotion_gate' => [
                'status' => $regressions === [] && (string) $summary['status'] === 'pass' ? 'pass' : 'blocked',
                'operator_review_required' => $regressions !== [] || $risk !== 'low',
                'auto_promote_retrieval_changes' => false,
                'minimum_required_source_recall' => $this->minimumRecall($risk),
                'minimum_groundedness' => $this->minimumGroundedness($risk),
                'minimum_context_roi' => $this->minimumRoi($risk),
            ],
            'claims' => [
                'providers_invoked' => false,
                'writes' => false,
                'rivals_run' => false,
                'benchmark_run' => false,
                'external_superiority_claim' => false,
                'raw_text_exposed' => false,
            ],
        ];

        $hashPayload = $payload;
        unset($hashPayload['generated_at']);
        $payload['arena_hash'] = MissionCanonicalHash::sha256($hashPayload);

        return $payload;
    }

    /**
     * @param  array<int,mixed>  $cases
     * @return array<int,array<string,mixed>>
     */
    private function goldenCases(array $cases, string $risk): array
    {
        $rawCases = $cases === [] ? $this->defaultCases() : $cases;

        return array_values(array_map(
            fn (mixed $case, int $index): array => $this->normalizeCase(is_array($case) ? $case : [], $index, $risk),
            $rawCases,
            array_keys($rawCases),
        ));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function defaultCases(): array
    {
        return [
            [
                'case_id' => 'programming_debug_core',
                'query' => 'debug repo with failing tests, implementation service and canonical docs',
                'domain' => 'developer',
                'task_type' => 'debug',
                'required_sources' => ['evidence_replay', 'code_intelligence', 'memory_signals'],
                'expected_outcome' => 'passed',
            ],
            [
                'case_id' => 'forge_observer_core',
                'query' => 'forge obra needs evidence, runtime receipt and operator review',
                'domain' => 'forge',
                'task_type' => 'planning',
                'required_sources' => ['memory_signals', 'semantic_candidate'],
                'expected_outcome' => 'passed',
            ],
            [
                'case_id' => 'research_grounded_context',
                'query' => 'research answer needs source refs, uncertainty and evidence ledger',
                'domain' => 'research',
                'task_type' => 'direct',
                'required_sources' => ['memory_signals'],
                'expected_outcome' => 'passed',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $case
     * @return array<string,mixed>
     */
    private function normalizeCase(array $case, int $index, string $risk): array
    {
        $query = trim((string) ($case['query'] ?? $case['objective'] ?? 'atlas retrieval evaluation case'));
        $requiredSources = array_values(array_unique(array_filter(array_map(
            static fn (mixed $source): string => is_scalar($source) ? trim((string) $source) : '',
            (array) ($case['required_sources'] ?? ['doc']),
        ))));
        $requiredSources = $requiredSources === [] ? ['doc'] : $requiredSources;

        $normalized = [
            'schema_version' => self::GOLDEN_CASE_SCHEMA,
            'case_id' => trim((string) ($case['case_id'] ?? 'case_'.$index)),
            'query_hash' => MissionCanonicalHash::sha256($query),
            'domain' => trim((string) ($case['domain'] ?? 'atlas')),
            'task_type' => trim((string) ($case['task_type'] ?? 'direct')),
            'risk_level' => $this->risk((string) ($case['risk_level'] ?? $risk)),
            'required_sources' => $requiredSources,
            'forbidden_sources' => array_values(array_unique(array_filter(array_map(
                static fn (mixed $source): string => is_scalar($source) ? trim((string) $source) : '',
                (array) ($case['forbidden_sources'] ?? []),
            )))),
            'expected_outcome' => $this->outcome((string) ($case['expected_outcome'] ?? 'passed')),
            'max_refs' => max(count($requiredSources), min(20, (int) ($case['max_refs'] ?? 8))),
        ];
        $normalized['case_hash'] = MissionCanonicalHash::sha256($normalized);

        return $normalized;
    }

    /**
     * @param  array<string,mixed>  $case
     * @return array<string,mixed>
     */
    private function evaluateCase(array $case, string $risk): array
    {
        $gate = $this->freshnessQualityGate->evaluate([
            'objective' => 'case:'.$case['query_hash'],
            'domain' => $case['domain'],
            'task_type' => $case['task_type'],
            'risk_level' => $case['risk_level'] ?? $risk,
            'max_refs' => $case['max_refs'],
        ]);
        $feedback = $this->feedbackLoop->capture([
            'objective' => 'case:'.$case['case_hash'],
            'domain' => $case['domain'],
            'task_type' => $case['task_type'],
            'risk_level' => $case['risk_level'] ?? $risk,
            'outcome_status' => $case['expected_outcome'],
            'max_refs' => $case['max_refs'],
            'record' => false,
        ]);
        $selectedItems = (array) data_get($gate, 'freshness_report.items', []);
        $coverage = $this->sourceTypeCoverage($selectedItems, (array) $case['required_sources']);
        $selected = (array) data_get($feedback, 'feedback_event.source_utility', []);
        $requiredSources = (array) $case['required_sources'];
        $coveredRequired = count(array_filter(
            $requiredSources,
            static fn (string $source): bool => ($coverage[$source] ?? false) === true,
        ));
        $recall = count($requiredSources) === 0 ? 1.0 : $coveredRequired / count($requiredSources);
        $noiseSources = (int) data_get($feedback, 'context_roi.noise_sources', 0);
        $selectedCount = max(1, count($selected));
        $precision = max(0.0, min(1.0, 1.0 - ($noiseSources / $selectedCount)));
        $groundedness = $this->groundedness($coverage, $feedback, $precision);
        $roi = (float) data_get($feedback, 'context_roi.roi_score', 0.0);
        $missedSources = array_values(array_filter(
            $requiredSources,
            static fn (string $source): bool => ($coverage[$source] ?? false) !== true,
        ));
        $caseRegressions = $this->caseRegressions($missedSources, $recall, $groundedness, $roi, (string) ($case['risk_level'] ?? $risk));

        $result = [
            'schema_version' => self::EVAL_RESULT_SCHEMA,
            'case_id' => (string) $case['case_id'],
            'case_hash' => (string) $case['case_hash'],
            'status' => $caseRegressions === [] ? 'pass' : 'fail',
            'metrics' => [
                'required_source_recall' => round($recall, 4),
                'precision_proxy' => round($precision, 4),
                'groundedness' => round($groundedness, 4),
                'context_roi' => round($roi, 4),
                'selected_count' => count($selected),
                'missed_required_count' => count($missedSources),
                'noise_count' => $noiseSources,
            ],
            'required_source_coverage' => $coverage,
            'missed_required_sources' => $missedSources,
            'regressions' => $caseRegressions,
            'feedback_hash' => (string) ($feedback['retrieval_feedback_hash'] ?? ''),
        ];
        $result['result_hash'] = MissionCanonicalHash::sha256($result);

        return $result;
    }

    /**
     * @param  array<int,array<string,mixed>>  $selectedItems
     * @param  array<int,string>  $requiredSources
     * @return array<string,bool>
     */
    private function sourceTypeCoverage(array $selectedItems, array $requiredSources): array
    {
        $selectedTypes = array_values(array_unique(array_filter(array_map(
            static fn (array $item): string => (string) ($item['source_type'] ?? ''),
            $selectedItems,
        ))));

        return collect($requiredSources)
            ->mapWithKeys(static fn (string $source): array => [$source => in_array($source, $selectedTypes, true)])
            ->all();
    }

    /**
     * @param  array<string,bool>  $coverage
     * @param  array<string,mixed>  $feedback
     */
    private function groundedness(array $coverage, array $feedback, float $precision): float
    {
        $coverageScore = $coverage === []
            ? 0.0
            : count(array_filter($coverage)) / max(1, count($coverage));
        $sufficiency = match ((string) data_get($feedback, 'status', 'blocked')) {
            'recorded', 'learning_candidate' => 0.86,
            'needs_review' => 0.54,
            default => 0.34,
        };

        return max(0.0, min(1.0, $coverageScore * 0.48 + $precision * 0.24 + $sufficiency * 0.28));
    }

    /**
     * @param  array<int,string>  $missedSources
     * @return array<int,array<string,mixed>>
     */
    private function caseRegressions(array $missedSources, float $recall, float $groundedness, float $roi, string $risk): array
    {
        $regressions = [];
        if ($missedSources !== []) {
            $regressions[] = [
                'reason' => 'required_source_missing',
                'severity' => 'critical',
                'missed_required_sources' => $missedSources,
            ];
        }

        if ($recall < $this->minimumRecall($risk)) {
            $regressions[] = [
                'reason' => 'required_source_recall_below_floor',
                'severity' => 'critical',
                'observed' => round($recall, 4),
                'floor' => $this->minimumRecall($risk),
            ];
        }

        if ($groundedness < $this->minimumGroundedness($risk)) {
            $regressions[] = [
                'reason' => 'groundedness_below_floor',
                'severity' => $risk === 'low' ? 'warn' : 'critical',
                'observed' => round($groundedness, 4),
                'floor' => $this->minimumGroundedness($risk),
            ];
        }

        if ($roi < $this->minimumRoi($risk)) {
            $regressions[] = [
                'reason' => 'context_roi_below_floor',
                'severity' => $risk === 'low' ? 'warn' : 'critical',
                'observed' => round($roi, 4),
                'floor' => $this->minimumRoi($risk),
            ];
        }

        return $regressions;
    }

    /**
     * @param  array<int,array<string,mixed>>  $results
     * @return array<string,mixed>
     */
    private function summary(array $results): array
    {
        $caseCount = count($results);
        $passCount = count(array_filter($results, static fn (array $result): bool => $result['status'] === 'pass'));
        $metrics = [
            'required_source_recall' => $this->average($results, 'required_source_recall'),
            'precision_proxy' => $this->average($results, 'precision_proxy'),
            'groundedness' => $this->average($results, 'groundedness'),
            'context_roi' => $this->average($results, 'context_roi'),
        ];

        return [
            'schema_version' => self::SUMMARY_SCHEMA,
            'status' => $caseCount > 0 && $passCount === $caseCount ? 'pass' : 'fail',
            'case_count' => $caseCount,
            'passed' => $passCount,
            'failed' => $caseCount - $passCount,
            'metrics' => $metrics,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $results
     */
    private function average(array $results, string $metric): float
    {
        if ($results === []) {
            return 0.0;
        }

        $values = array_map(
            static fn (array $result): float => (float) data_get($result, 'metrics.'.$metric, 0.0),
            $results,
        );

        return round(array_sum($values) / count($values), 4);
    }

    /**
     * @param  array<int,array<string,mixed>>  $results
     * @param  array<string,mixed>  $summary
     * @return array<int,array<string,mixed>>
     */
    private function regressions(array $results, array $summary, string $risk): array
    {
        $regressions = [];
        foreach ($results as $result) {
            foreach ((array) ($result['regressions'] ?? []) as $regression) {
                $regressions[] = [
                    'case_id' => (string) ($result['case_id'] ?? ''),
                    'case_hash' => (string) ($result['case_hash'] ?? ''),
                    'reason' => (string) ($regression['reason'] ?? 'retrieval_regression'),
                    'severity' => (string) ($regression['severity'] ?? 'critical'),
                    'evidence' => $regression,
                ];
            }
        }

        foreach ([
            'required_source_recall' => $this->minimumRecall($risk),
            'groundedness' => $this->minimumGroundedness($risk),
            'context_roi' => $this->minimumRoi($risk),
        ] as $metric => $floor) {
            $observed = (float) data_get($summary, 'metrics.'.$metric, 0.0);
            if ($observed < $floor) {
                $regressions[] = [
                    'case_id' => 'summary',
                    'case_hash' => MissionCanonicalHash::sha256(['metric' => $metric, 'observed' => $observed, 'floor' => $floor]),
                    'reason' => $metric.'_below_suite_floor',
                    'severity' => 'critical',
                    'evidence' => [
                        'observed' => round($observed, 4),
                        'floor' => $floor,
                    ],
                ];
            }
        }

        return $regressions;
    }

    /**
     * @param  array<int,array<string,mixed>>  $regressions
     * @param  array<string,mixed>  $summary
     */
    private function status(array $regressions, array $summary, string $risk): string
    {
        $critical = array_filter($regressions, static fn (array $regression): bool => ($regression['severity'] ?? 'critical') === 'critical');
        if ($critical !== []) {
            return 'blocked';
        }

        if ($regressions !== [] || (string) ($summary['status'] ?? 'fail') !== 'pass' || $risk !== 'low') {
            return 'watch';
        }

        return 'ready';
    }

    private function minimumRecall(string $risk): float
    {
        return match ($risk) {
            'high', 'irreversible' => 1.0,
            'medium' => 0.90,
            default => 0.80,
        };
    }

    private function minimumGroundedness(string $risk): float
    {
        return match ($risk) {
            'high', 'irreversible' => 0.86,
            'medium' => 0.78,
            default => 0.68,
        };
    }

    private function minimumRoi(string $risk): float
    {
        return match ($risk) {
            'high', 'irreversible' => 0.70,
            'medium' => 0.62,
            default => 0.50,
        };
    }

    private function risk(string $risk): string
    {
        return in_array($risk, ['low', 'medium', 'high', 'irreversible'], true) ? $risk : 'low';
    }

    private function outcome(string $outcome): string
    {
        return in_array($outcome, ['passed', 'partial', 'failed', 'unknown'], true) ? $outcome : 'passed';
    }
}
