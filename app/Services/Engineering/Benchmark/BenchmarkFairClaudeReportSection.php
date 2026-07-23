<?php

namespace App\Services\Engineering\Benchmark;

use App\Models\AiTraceMetricSummary;
use App\Models\AtlasEngineeringBenchmarkCase;
use App\Models\AtlasEngineeringBenchmarkResult;
use App\Models\AtlasEngineeringBenchmarkRun;
use App\Models\AtlasEngineeringBenchmarkSuite;
use App\Models\AtlasEngineeringRun;
use App\Models\AtlasTask;
use App\Services\Ai\FairClaudePolicy;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;
use App\Services\Engineering\EngineeringHarnessRunnerService;
use App\Services\Engineering\EngineeringClaudeCodeBaselineRunnerService;
use App\Services\Engineering\EngineeringWorkspaceService;
use App\Services\Engineering\EngineeringReleaseGateAlertService;
use App\Services\Engineering\EngineeringBenchmarkInput;
use App\Services\Engineering\EngineeringStringListNormalizer;

class BenchmarkFairClaudeReportSection
{
    public function __construct(
        private readonly BenchmarkPrimitives $primitives,
        private readonly BenchmarkRunResultsSection $runResults,
    ) {}

    public function fairClaudeBatteryExecutionContract(
        AtlasEngineeringBenchmarkSuite $suite,
        array $readiness,
        array $paired,
        array $baseline,
        array $replay,
    ): array {
        $releaseCount = (int) ($readiness['release_corpus_case_count'] ?? 0);
        $minimumReleaseCount = (int) ($readiness['minimum_release_corpus_case_count'] ?? 6);
        $corpusPrepared = $releaseCount >= $minimumReleaseCount;
        $pairedRunCount = (int) ($paired['case_count'] ?? 0);
        $comparableCount = (int) ($readiness['comparable_count'] ?? 0);
        $baselineExecuted = (bool) ($baseline['enabled'] ?? false);
        $replayVerified = (bool) ($replay['enabled'] ?? false)
            && (int) ($replay['packet_count'] ?? 0) > 0
            && (int) ($replay['artifact_integrity_failed_count'] ?? 0) === 0;

        return [
            'schema_version' => 'atlas.fair_claude.battery_execution_contract.v1',
            'status' => $corpusPrepared ? 'operator_execution_required' : 'prepare_corpus_required',
            'summary' => $corpusPrepared
                ? 'Corpus pronto; falta executar bateria pareada real com provider e baseline Claude Code.'
                : 'Corpus release ainda incompleto; prepare o corpus antes de qualquer bateria.',
            'operator_required' => true,
            'agent_auto_execution_allowed' => false,
            'provider_dispatch_required' => true,
            'external_cost_possible' => true,
            'synthetic_scores_allowed' => false,
            'current_state' => [
                'corpus_prepared' => $corpusPrepared,
                'release_corpus_case_count' => $releaseCount,
                'minimum_release_corpus_case_count' => $minimumReleaseCount,
                'paired_case_count' => $pairedRunCount,
                'comparable_case_count' => $comparableCount,
                'baseline_executed' => $baselineExecuted,
                'replay_verified' => $replayVerified,
                'ready_for_claim' => (bool) ($readiness['ready_for_claim'] ?? false),
            ],
            'start_requirements' => [
                'release_corpus_prepared',
                'separate_atlas_workspace',
                'separate_claude_code_baseline_workspace',
                'claude_code_binary_available',
                'operator_accepts_time_and_provider_cost',
                'runbook_ready_to_start_battery_true',
            ],
            'commands' => [
                'prepare_corpus' => 'atlas rivals prepare --json',
                'runbook' => 'atlas rivals runbook --json',
                'quick_battery' => 'atlas rivals run --json',
                'medium_battery' => 'atlas rivals run --medium --json',
                'full_battery' => 'atlas rivals run --full --json',
                'report' => 'atlas rivals report --json',
                'verify_export' => 'atlas rivals verify --output-dir=atlas-rivals-report --json',
                'artisan_report' => "php artisan atlas:engineering:benchmark:rivals report --suite={$suite->slug} --json",
            ],
            'recommended_presets' => [
                [
                    'id' => 'quick',
                    'case_count' => 1,
                    'estimated_time' => '10-25 min',
                    'purpose' => 'provar que a automacao e o baseline executam sem pagar a bateria inteira.',
                ],
                [
                    'id' => 'medium',
                    'case_count' => 3,
                    'estimated_time' => '30-75 min',
                    'purpose' => 'gerar sinal util sem custo de bateria completa.',
                ],
                [
                    'id' => 'full',
                    'case_count' => null,
                    'estimated_time' => '90-180+ min',
                    'purpose' => 'produzir evidencia forte para claim externo.',
                ],
            ],
            'safety' => [
                'read_model_only' => true,
                'report_does_not_execute_provider' => true,
                'operator_must_start_run' => true,
                'claim_blocked_until_real_comparable_cases' => $comparableCount === 0,
            ],
        ];
    }

    public function fairClaudeReplayReport(Collection $runs): array
    {
        $manifests = $runs
            ->map(fn (AtlasEngineeringBenchmarkRun $run): array => $this->primitives->arrayValue(data_get(
                $this->runResults->withReplayManifestArtifactVerification($this->primitives->arrayValue($run->summary_json ?? [])),
                'replay_manifest',
                [],
            )))
            ->filter(fn (array $manifest): bool => (bool) ($manifest['enabled'] ?? false))
            ->values();

        if ($manifests->isEmpty()) {
            return [
                'enabled' => false,
                'run_count' => 0,
                'packet_count' => 0,
                'artifact_integrity_passed_count' => 0,
                'artifact_integrity_failed_count' => 0,
            ];
        }

        $integrityPassed = $manifests
            ->filter(fn (array $manifest): bool => (bool) data_get($manifest, 'artifact.integrity.hash_matches'))
            ->count();

        return [
            'enabled' => true,
            'run_count' => $manifests->count(),
            'packet_count' => $manifests->sum(fn (array $manifest): int => (int) ($manifest['packet_count'] ?? 0)),
            'providers' => $manifests
                ->flatMap(fn (array $manifest): array => (array) ($manifest['providers'] ?? []))
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'model_locks' => $manifests
                ->flatMap(fn (array $manifest): array => (array) ($manifest['model_locks'] ?? []))
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'artifact_integrity_passed_count' => $integrityPassed,
            'artifact_integrity_failed_count' => max(0, $manifests->count() - $integrityPassed),
            'manifest_hashes' => $manifests
                ->pluck('manifest_hash')
                ->filter()
                ->values()
                ->all(),
        ];
    }

    public function fairClaudeReportReadiness(Collection $runs, array $paired, array $baseline, array $replay, array $corpusManifest): array
    {
        $blocking = [];
        $minimumReleaseCount = 6;
        $releaseCorpusCount = (int) data_get($corpusManifest, 'official_subsets.release', 0);
        $activeCorpusCount = (int) ($corpusManifest['active_cases'] ?? 0);
        $comparableCount = (int) ($paired['comparable_count'] ?? 0);
        $pairedEnabled = (bool) ($paired['enabled'] ?? false);

        if ($releaseCorpusCount < $minimumReleaseCount) {
            $blocking[] = 'fair_release_corpus_below_minimum';
        }
        if ($runs->isEmpty()) {
            $blocking[] = 'no_fair_claude_runs';
        }
        if (! $pairedEnabled) {
            $blocking[] = 'paired_scorecard_missing';
        }
        if ((int) ($paired['fair_mode_count'] ?? 0) === 0) {
            $blocking[] = 'fair_atlas_arm_missing';
        }
        if ($comparableCount === 0) {
            $blocking[] = 'no_comparable_cases';
        }
        if ($comparableCount > 0 && $comparableCount < $minimumReleaseCount) {
            $blocking[] = 'fair_comparable_cases_below_release_minimum';
        }
        if (! (bool) ($baseline['enabled'] ?? false) || (int) ($baseline['executed_count'] ?? 0) === 0) {
            $blocking[] = 'claude_code_baseline_not_executed';
        }
        if (! (bool) ($replay['enabled'] ?? false) || (int) ($replay['artifact_integrity_failed_count'] ?? 0) > 0) {
            $blocking[] = 'replay_manifest_not_fully_verified';
        }
        if ($pairedEnabled) {
            if ((float) ($paired['protocol_validity_rate'] ?? 0.0) < 100.0) {
                $blocking[] = 'fair_protocol_validity_below_100';
            }
            if ((float) ($paired['final_gate_pass_rate'] ?? 0.0) < 100.0) {
                $blocking[] = 'fair_final_gate_pass_rate_below_100';
            }
            if ((float) ($paired['pass_without_human_rate'] ?? 0.0) < 100.0) {
                $blocking[] = 'fair_pass_without_human_rate_below_100';
            }
            if ((int) ($paired['provider_violation_count'] ?? 0) > 0) {
                $blocking[] = 'provider_lock_violation';
            }
            if ((int) ($paired['fallback_violation_count'] ?? 0) > 0) {
                $blocking[] = 'fallback_violation';
            }
        }

        $atlasWins = (int) ($paired['atlas_win_count'] ?? 0);
        $baselineWins = (int) ($paired['claude_code_baseline_win_count'] ?? 0);
        $ties = (int) ($paired['tie_count'] ?? 0);
        $status = match (true) {
            $blocking !== [] => 'not_ready',
            $atlasWins > $baselineWins => 'atlas_leading',
            $baselineWins > $atlasWins => 'baseline_leading',
            $ties > 0 => 'tied',
            default => 'inconclusive',
        };

        return [
            'status' => $status,
            'ready_for_claim' => $blocking === [] && $atlasWins > $baselineWins,
            'blocking_reasons' => $blocking,
            'atlas_win_count' => $atlasWins,
            'claude_code_baseline_win_count' => $baselineWins,
            'tie_count' => $ties,
            'comparable_count' => $comparableCount,
            'fair_mode_count' => (int) ($paired['fair_mode_count'] ?? 0),
            'active_corpus_case_count' => $activeCorpusCount,
            'release_corpus_case_count' => $releaseCorpusCount,
            'minimum_release_corpus_case_count' => $minimumReleaseCount,
            'minimum_comparable_case_count' => $minimumReleaseCount,
        ];
    }

    public function fairClaudeRunHistoryPayload(AtlasEngineeringBenchmarkRun $run, bool $officialFairScope): array
    {
        $summary = $this->primitives->arrayValue($run->summary_json ?? []);
        $runResults = $run->results instanceof Collection
            ? $run->results
            : collect($run->results ?? []);
        $fairResults = $runResults
            ->filter(fn (AtlasEngineeringBenchmarkResult $result): bool => (bool) data_get($result->observed_json ?? [], 'paired_scorecard.fair_mode'))
            ->values();
        $paired = $this->runResults->pairedScorecardSummary($fairResults, $this->primitives->runsCostMicrousd(collect([$run])));
        if (! (bool) ($paired['enabled'] ?? false)) {
            $paired = $this->primitives->arrayValue(data_get($summary, 'paired_scorecard', []));
        }
        $baseline = $this->runResults->claudeCodeBaselineSummary($fairResults);
        if (! (bool) ($baseline['enabled'] ?? false)) {
            $baseline = $this->primitives->arrayValue(data_get($summary, 'claude_code_baseline', []));
        }
        $replay = $this->runResults->safeReplayManifestSummaryForReport($this->primitives->arrayValue(data_get(
            $this->runResults->withReplayManifestArtifactVerification($summary),
            'replay_manifest',
            [],
        )));

        $atlasWins = (int) ($paired['atlas_win_count'] ?? data_get($paired, 'winners.atlas', 0));
        $baselineWins = (int) ($paired['claude_code_baseline_win_count'] ?? data_get($paired, 'winners.claude_code_baseline', 0));
        $tieCount = (int) ($paired['tie_count'] ?? data_get($paired, 'winners.tie', 0));
        $comparableCount = (int) ($paired['comparable_count'] ?? 0);
        $invalidCaseCount = (int) ($paired['invalid_case_count'] ?? 0);
        $blocking = [];

        if (! $officialFairScope) {
            $blocking[] = 'not_official_fair_claude_scope';
        }
        if (! (bool) ($paired['enabled'] ?? false)) {
            $blocking[] = 'paired_scorecard_missing';
        }
        if ((int) ($paired['fair_mode_count'] ?? 0) === 0) {
            $blocking[] = 'fair_atlas_arm_missing';
        }
        if ($comparableCount === 0) {
            $blocking[] = 'no_comparable_cases';
        }
        if (! (bool) ($baseline['enabled'] ?? false) || (int) ($baseline['executed_count'] ?? $baseline['case_count'] ?? 0) === 0) {
            $blocking[] = 'claude_code_baseline_not_executed';
        }
        if (! (bool) ($replay['enabled'] ?? false) || (int) ($replay['artifact_integrity_failed_count'] ?? 0) > 0) {
            $blocking[] = 'replay_manifest_not_fully_verified';
        }
        if ((int) ($paired['provider_violation_count'] ?? 0) > 0) {
            $blocking[] = 'provider_lock_violation';
        }
        if ((int) ($paired['fallback_violation_count'] ?? 0) > 0) {
            $blocking[] = 'fallback_violation';
        }

        $winner = match (true) {
            $atlasWins > $baselineWins => 'atlas',
            $baselineWins > $atlasWins => 'claude_code_baseline',
            $tieCount > 0 => 'tie',
            default => null,
        };
        $healthStatus = match (true) {
            $blocking !== [] => 'blocked',
            $winner === 'atlas' => 'atlas_leading',
            $winner === 'claude_code_baseline' => 'baseline_leading',
            $winner === 'tie' => 'tied',
            default => 'inconclusive',
        };
        $resultIntegrityStatus = match (true) {
            $comparableCount === 0 && $invalidCaseCount > 0 => 'invalid_battery_no_comparable_score',
            $comparableCount === 0 => 'no_comparable_score',
            $blocking !== [] => 'comparable_score_blocked',
            default => 'claim_ready',
        };
        $claimWinnerAdmitted = $resultIntegrityStatus === 'claim_ready' && $winner !== null;

        return [
            'id' => $run->id,
            'status' => $run->status,
            'benchmark_key' => $run->benchmark_key,
            'provider' => $run->provider,
            'model' => $run->model,
            'total_cases' => $run->total_cases,
            'passed_cases' => $run->passed_cases,
            'failed_cases' => $run->failed_cases,
            'duration_ms' => $run->duration_ms,
            'cost_microusd' => $run->cost_microusd,
            'fair_report_scope' => $officialFairScope ? 'official_fair_claude' : 'paired_non_fair',
            'history_summary' => [
                'schema_version' => 1,
                'health_status' => $healthStatus,
                'winner' => $winner,
                'blocking_reasons' => $blocking,
                'atlas_win_count' => $atlasWins,
                'claude_code_baseline_win_count' => $baselineWins,
                'tie_count' => $tieCount,
                'comparable_count' => $comparableCount,
                'invalid_case_count' => $invalidCaseCount,
                'result_integrity_status' => $resultIntegrityStatus,
                'score_admitted' => $comparableCount > 0,
                'claim_winner_admitted' => $claimWinnerAdmitted,
                'protocol_validity_rate' => $this->primitives->nullableFloat($paired['protocol_validity_rate'] ?? null),
                'pass_without_human_rate' => $this->primitives->nullableFloat($paired['pass_without_human_rate'] ?? null),
                'pass_without_human_rate_medium_hard' => $this->primitives->nullableFloat($paired['pass_without_human_rate_medium_hard'] ?? null),
                'final_gate_pass_rate' => $this->primitives->nullableFloat($paired['final_gate_pass_rate'] ?? null),
                'repair_conversion_rate' => $this->primitives->nullableFloat($paired['repair_conversion_rate'] ?? null),
                'baseline_executed_count' => (int) ($baseline['executed_count'] ?? $baseline['case_count'] ?? 0),
                'replay_packet_count' => (int) ($replay['packet_count'] ?? 0),
                'replay_integrity_failed_count' => (int) ($replay['artifact_integrity_failed_count'] ?? 0),
            ],
            'paired_scorecard' => $paired,
            'claude_code_baseline' => $baseline,
            'replay_manifest' => $replay,
            'finished_at' => $run->finished_at?->toJSON(),
            'created_at' => $run->created_at?->toJSON(),
        ];
    }

    public function fairClaudeHistoryTimeline(Collection $runHistory): array
    {
        return [
            'schema_version' => 'atlas.fair_claude.history_timeline.v1',
            'summary' => [
                'run_count' => $runHistory->count(),
                'blocked_count' => $runHistory
                    ->filter(fn (array $run): bool => data_get($run, 'history_summary.health_status') === 'blocked')
                    ->count(),
                'claim_ready_count' => $runHistory
                    ->filter(fn (array $run): bool => data_get($run, 'history_summary.claim_winner_admitted') === true)
                    ->count(),
                'comparable_case_count' => $runHistory
                    ->sum(fn (array $run): int => (int) data_get($run, 'history_summary.comparable_count', 0)),
                'invalid_case_count' => $runHistory
                    ->sum(fn (array $run): int => (int) data_get($run, 'history_summary.invalid_case_count', 0)),
            ],
            'entries' => $runHistory
                ->map(fn (array $run): array => [
                    'run_id' => $run['id'] ?? null,
                    'status' => $run['status'] ?? null,
                    'finished_at' => $run['finished_at'] ?? null,
                    'created_at' => $run['created_at'] ?? null,
                    'provider' => $run['provider'] ?? null,
                    'model' => $run['model'] ?? null,
                    'health_status' => data_get($run, 'history_summary.health_status'),
                    'winner_for_history' => data_get($run, 'history_summary.winner'),
                    'score_admitted' => (bool) data_get($run, 'history_summary.score_admitted', false),
                    'claim_winner_admitted' => (bool) data_get($run, 'history_summary.claim_winner_admitted', false),
                    'result_integrity_status' => data_get($run, 'history_summary.result_integrity_status'),
                    'comparable_count' => (int) data_get($run, 'history_summary.comparable_count', 0),
                    'invalid_case_count' => (int) data_get($run, 'history_summary.invalid_case_count', 0),
                    'atlas_win_count' => (int) data_get($run, 'history_summary.atlas_win_count', 0),
                    'claude_code_baseline_win_count' => (int) data_get($run, 'history_summary.claude_code_baseline_win_count', 0),
                    'tie_count' => (int) data_get($run, 'history_summary.tie_count', 0),
                    'blocking_reasons' => (array) data_get($run, 'history_summary.blocking_reasons', []),
                    'baseline_executed_count' => (int) data_get($run, 'history_summary.baseline_executed_count', 0),
                    'replay_packet_count' => (int) data_get($run, 'history_summary.replay_packet_count', 0),
                    'replay_integrity_failed_count' => (int) data_get($run, 'history_summary.replay_integrity_failed_count', 0),
                    'duration_ms' => $run['duration_ms'] ?? null,
                    'cost_microusd' => $run['cost_microusd'] ?? null,
                ])
                ->values()
                ->all(),
        ];
    }

    public function fairClaudeReportNextActions(
        array $readiness,
        array $paired,
        array $baseline,
        array $replay,
        Collection $caseComparisons,
        array $invalidBatteryTriage = [],
    ): array {
        $actions = [];
        $blocking = collect((array) ($readiness['blocking_reasons'] ?? []))
            ->filter(fn (mixed $reason): bool => is_string($reason) && $reason !== '')
            ->values();
        $invalidBatteryNeedsTriage = (int) ($paired['invalid_case_count'] ?? 0) > 0
            && (int) ($readiness['comparable_count'] ?? 0) === 0
            && $blocking->contains('fair_protocol_validity_below_100')
            && (bool) ($invalidBatteryTriage['required_before_rerun'] ?? true);

        $add = function (
            string $id,
            string $severity,
            string $title,
            string $detail,
            ?string $command = null,
            ?string $owner = null,
        ) use (&$actions): void {
            $actions[$id] = [
                'id' => $id,
                'severity' => $severity,
                'title' => $title,
                'detail' => $detail,
                'command' => $command,
                'owner' => $owner,
            ];
        };

        if ($blocking->contains('fair_release_corpus_below_minimum')) {
            $add(
                'prepare_release_corpus',
                'critical',
                'Preparar corpus release',
                'O relatório não pode sustentar claim antes de ter o mínimo de casos release ativos.',
                'atlas rivals prepare --json',
                'benchmark_operator',
            );
        }

        if (! $blocking->contains('fair_release_corpus_below_minimum') && $blocking->contains('no_fair_claude_runs')) {
            $add(
                'run_battery_runbook',
                'critical',
                'Validar runbook da bateria',
                'Antes de executar provider, confirme workspace Atlas, workspace Claude Code separado e binário Claude Code disponível.',
                'atlas rivals runbook --json',
                'benchmark_operator',
            );
        }

        if ($invalidBatteryNeedsTriage) {
            $add(
                'triage_invalid_battery_before_provider_rerun',
                'critical',
                'Triar bateria inválida antes de novo custo',
                'A última bateria real tem protocolo Atlas inválido e zero casos comparáveis; corrija gates, escopo e replay antes de gastar provider novamente.',
                null,
                'engineering_operator',
            );
        }

        if (! $invalidBatteryNeedsTriage && ($blocking->contains('no_fair_claude_runs')
            || $blocking->contains('fair_atlas_arm_missing')
            || $blocking->contains('no_comparable_cases')
            || $blocking->contains('fair_comparable_cases_below_release_minimum'))
        ) {
            $add(
                'run_paired_battery',
                'critical',
                'Executar bateria pareada completa',
                'Ainda não existe amostra release comparável suficiente para sustentar um resultado justo.',
                'atlas rivals run --json',
                'benchmark_operator',
            );
        }

        if ($blocking->contains('claude_code_baseline_not_executed') || ! (bool) ($baseline['enabled'] ?? false)) {
            $add(
                'run_claude_code_baseline',
                'critical',
                'Executar baseline Claude Code',
                'A comparação justa exige o braço Claude Code CLI com o mesmo Opus e workspace isolado.',
                'atlas rivals run-claude-code --json',
                'benchmark_operator',
            );
        }

        if ($blocking->contains('replay_manifest_not_fully_verified') || ! (bool) ($replay['enabled'] ?? false)) {
            $add(
                'verify_replay_manifest',
                'critical',
                'Verificar replay e integridade',
                'O claim só é auditável quando os packets e artifacts do replay estão íntegros.',
                'atlas rivals report --json',
                'benchmark_operator',
            );
        }

        if ((int) ($paired['provider_violation_count'] ?? 0) > 0 || (int) ($paired['fallback_violation_count'] ?? 0) > 0) {
            $add(
                'rerun_after_protocol_violation',
                'critical',
                'Descartar runs com violação de fair mode',
                'Provider drift ou fallback invalida o protocolo; rode novamente mantendo Claude Opus travado.',
                'atlas rivals run --json',
                'benchmark_operator',
            );
        }

        if ($blocking->contains('fair_protocol_validity_below_100')
            || $blocking->contains('fair_final_gate_pass_rate_below_100')
            || $blocking->contains('fair_pass_without_human_rate_below_100')
            || ((float) ($paired['protocol_validity_rate'] ?? 0.0) < 100.0 && (bool) ($paired['enabled'] ?? false))
        ) {
            $add(
                'inspect_protocol_invalid_cases',
                'critical',
                'Corrigir casos sem validade experimental',
                'Protocol validity, final gate e pass_without_human precisam estar em 100% para claim.',
                null,
                'engineering_operator',
            );
        }

        $baselineWins = (int) ($paired['claude_code_baseline_win_count'] ?? 0);
        if ($baselineWins > 0) {
            $add(
                'review_baseline_wins',
                'warning',
                'Revisar vitórias do Claude Code',
                "{$baselineWins} caso(s) favorecem o baseline; priorize esses casos no próximo ciclo de harness.",
                null,
                'engineering_operator',
            );
        }

        $blockedCaseCount = $caseComparisons
            ->filter(fn (array $comparison): bool => ! (bool) ($comparison['comparable'] ?? false))
            ->count();
        if ($blockedCaseCount > 0) {
            $add(
                'resolve_incomparable_cases',
                'warning',
                'Resolver casos inconclusivos',
                "{$blockedCaseCount} caso(s) ainda não são comparáveis entre Atlas e Claude Code.",
                null,
                'engineering_operator',
            );
        }

        if ((bool) ($readiness['ready_for_claim'] ?? false)) {
            $add(
                'publish_claim_packet',
                'info',
                'Publicar pacote de evidência',
                'O relatório está pronto para claim; exporte scorecard, replay manifest e case comparisons para revisão externa.',
                'atlas rivals report --json',
                'benchmark_operator',
            );
        }

        if ($actions === []) {
            $add(
                'collect_more_runs',
                'info',
                'Coletar mais amostras',
                'Não há bloqueio crítico novo; aumente a amostra para estabilizar a comparação.',
                'atlas rivals run --json',
                'benchmark_operator',
            );
        }

        $rank = ['critical' => 0, 'warning' => 1, 'info' => 2];

        return collect($actions)
            ->sortBy(fn (array $action): int => $rank[(string) ($action['severity'] ?? 'info')] ?? 3)
            ->values()
            ->all();
    }

    public function fairClaudeExecutiveSummary(
        array $readiness,
        array $paired,
        array $baseline,
        array $replay,
        Collection $caseComparisons,
    ): array {
        $atlasWins = (int) ($readiness['atlas_win_count'] ?? 0);
        $baselineWins = (int) ($readiness['claude_code_baseline_win_count'] ?? 0);
        $tieCount = (int) ($readiness['tie_count'] ?? 0);
        $comparableCount = (int) ($readiness['comparable_count'] ?? 0);
        $ready = (bool) ($readiness['ready_for_claim'] ?? false);
        $claim = match (true) {
            $ready => 'ready_to_claim_atlas_lead',
            $comparableCount === 0 => 'insufficient_comparable_data',
            $baselineWins > $atlasWins => 'baseline_leading',
            $atlasWins > $baselineWins => 'atlas_leading_but_blocked',
            $tieCount > 0 => 'tied_but_blocked',
            default => 'not_ready',
        };
        $headline = match ($claim) {
            'ready_to_claim_atlas_lead' => 'Atlas está pronto para claim no protocolo Fair Claude.',
            'atlas_leading_but_blocked' => 'Atlas lidera, mas ainda há bloqueios de evidência.',
            'baseline_leading' => 'Claude Code lidera a amostra atual.',
            'tied_but_blocked' => 'A amostra está empatada e ainda bloqueada.',
            'insufficient_comparable_data' => 'Ainda não há amostra comparável suficiente.',
            default => 'Relatório Fair Claude ainda não está pronto para claim.',
        };
        $topRisks = collect((array) ($readiness['blocking_reasons'] ?? []))
            ->take(5)
            ->values()
            ->all();
        $baselineWinCases = $caseComparisons
            ->filter(fn (array $comparison): bool => ($comparison['winner'] ?? null) === 'claude_code_baseline')
            ->pluck('case_code')
            ->filter()
            ->values()
            ->all();
        $blockedCases = $caseComparisons
            ->filter(fn (array $comparison): bool => ! (bool) ($comparison['comparable'] ?? false))
            ->pluck('case_code')
            ->filter()
            ->values()
            ->all();

        return [
            'claim_status' => $claim,
            'headline' => $headline,
            'ready_for_claim' => $ready,
            'winner' => $atlasWins > $baselineWins
                ? 'atlas'
                : ($baselineWins > $atlasWins ? 'claude_code_baseline' : ($tieCount > 0 ? 'tie' : null)),
            'confidence' => $ready ? 'high' : ($comparableCount > 0 ? 'limited' : 'none'),
            'sample' => [
                'comparable_cases' => $comparableCount,
                'fair_mode_cases' => (int) ($readiness['fair_mode_count'] ?? 0),
                'atlas_wins' => $atlasWins,
                'claude_code_baseline_wins' => $baselineWins,
                'ties' => $tieCount,
            ],
            'quality_bar' => [
                'protocol_validity_rate' => $paired['protocol_validity_rate'] ?? null,
                'pass_without_human_rate' => $paired['pass_without_human_rate'] ?? null,
                'medium_hard_pass_without_human_rate' => $paired['pass_without_human_rate_medium_hard'] ?? null,
                'final_gate_pass_rate' => $paired['final_gate_pass_rate'] ?? null,
            ],
            'auditability' => [
                'baseline_executed' => (bool) ($baseline['enabled'] ?? false) && (int) ($baseline['executed_count'] ?? 0) > 0,
                'replay_verified' => (bool) ($replay['enabled'] ?? false) && (int) ($replay['artifact_integrity_failed_count'] ?? 0) === 0,
                'manifest_hash_count' => count((array) ($replay['manifest_hashes'] ?? [])),
            ],
            'top_risks' => $topRisks,
            'baseline_win_cases' => $baselineWinCases,
            'blocked_cases' => $blockedCases,
        ];
    }

    public function fairClaudeResultIntegrity(
        array $readiness,
        array $paired,
        Collection $caseComparisons,
        array $invalidBatteryTriage = [],
        array $experimentValidity = [],
    ): array {
        $atlasWins = (int) ($readiness['atlas_win_count'] ?? 0);
        $baselineWins = (int) ($readiness['claude_code_baseline_win_count'] ?? 0);
        $tieCount = (int) ($readiness['tie_count'] ?? 0);
        $comparableCount = (int) ($readiness['comparable_count'] ?? 0);
        $minimumComparableCount = (int) ($readiness['minimum_comparable_case_count'] ?? 6);
        $invalidCaseCount = (int) ($paired['invalid_case_count'] ?? 0);
        $inconclusiveCount = (int) ($paired['inconclusive_count'] ?? max(0, $caseComparisons->count() - $comparableCount));
        $readyForClaim = (bool) ($readiness['ready_for_claim'] ?? false);
        $provisionalLeader = match (true) {
            $comparableCount === 0 => null,
            $atlasWins > $baselineWins => 'atlas',
            $baselineWins > $atlasWins => 'claude_code_baseline',
            $tieCount > 0 => 'tie',
            default => null,
        };
        $winnerForClaim = $readyForClaim ? $provisionalLeader : null;
        $status = match (true) {
            $readyForClaim && $winnerForClaim !== null => 'claim_ready',
            $comparableCount === 0 && $invalidCaseCount > 0 => 'invalid_battery_no_comparable_score',
            $comparableCount === 0 => 'no_comparable_score',
            $comparableCount < $minimumComparableCount => 'limited_sample_not_claimable',
            default => 'comparable_score_blocked',
        };
        $operatorHeadline = match ($status) {
            'claim_ready' => 'Resultado comparável pronto para claim.',
            'invalid_battery_no_comparable_score' => 'Bateria real inválida: nenhum vencedor pode ser declarado.',
            'no_comparable_score' => 'Sem score comparável: rode uma bateria válida antes de declarar resultado.',
            'limited_sample_not_claimable' => 'Existe líder provisório, mas a amostra ainda é pequena para claim.',
            default => 'Existe score comparável, mas os gates ainda bloqueiam claim.',
        };

        return [
            'schema_version' => 'atlas.fair_claude.result_integrity.v1',
            'status' => $status,
            'operator_headline' => $operatorHeadline,
            'score_admitted' => $comparableCount > 0,
            'claim_winner_admitted' => $winnerForClaim !== null,
            'winner_for_claim' => $winnerForClaim,
            'provisional_leader' => $provisionalLeader,
            'policy' => [
                'invalid_cases_count_as_losses' => false,
                'inconclusive_cases_count_as_losses' => false,
                'only_comparable_cases_enter_win_loss_math' => true,
                'external_variables_can_block_comparability' => true,
                'external_variables_cannot_decide_winner' => true,
                'synthetic_scores_allowed' => false,
                'triaged_invalid_batteries_remain_excluded_from_score' => true,
            ],
            'experiment_validity' => $experimentValidity,
            'invalid_battery_triage' => $invalidBatteryTriage,
            'triage_required_before_rerun' => (bool) ($invalidBatteryTriage['required_before_rerun'] ?? ($status === 'invalid_battery_no_comparable_score')),
            'counts' => [
                'case_comparison_count' => $caseComparisons->count(),
                'comparable_count' => $comparableCount,
                'minimum_comparable_case_count' => $minimumComparableCount,
                'invalid_case_count' => $invalidCaseCount,
                'inconclusive_count' => $inconclusiveCount,
                'atlas_win_count' => $atlasWins,
                'claude_code_baseline_win_count' => $baselineWins,
                'tie_count' => $tieCount,
            ],
            'status_counts' => $caseComparisons
                ->pluck('comparison_status')
                ->filter(fn (mixed $status): bool => is_string($status) && $status !== '')
                ->countBy()
                ->all(),
            'blocking_reason_counts' => $caseComparisons
                ->flatMap(fn (array $comparison): array => (array) ($comparison['blocking_reasons'] ?? []))
                ->filter(fn (mixed $reason): bool => is_string($reason) && $reason !== '')
                ->countBy()
                ->all(),
            'ui_contract' => [
                'primary_state' => $status,
                'primary_metric' => 'comparable_count',
                'must_not_render_winner' => $winnerForClaim === null,
                'must_not_render_invalid_cases_as_losses' => true,
                'must_label_provisional_leader_when_not_claim_ready' => $provisionalLeader !== null && $winnerForClaim === null,
                'must_show_blocking_reasons' => true,
            ],
            'first_invalid_cases' => $caseComparisons
                ->filter(fn (array $comparison): bool => ! (bool) ($comparison['comparable'] ?? false))
                ->take(5)
                ->map(fn (array $comparison): array => [
                    'case_code' => $comparison['case_code'] ?? null,
                    'title' => $comparison['title'] ?? null,
                    'comparison_status' => $comparison['comparison_status'] ?? null,
                    'winner' => null,
                    'blocking_reasons' => (array) ($comparison['blocking_reasons'] ?? []),
                    'plain_explanation' => 'Caso excluído do placar porque não passou no protocolo de comparabilidade.',
                ])
                ->values()
                ->all(),
        ];
    }

    public function fairClaudeExperimentValidity(array $readiness, array $paired, Collection $caseComparisons): array
    {
        $blockingReasonCounts = $caseComparisons
            ->flatMap(fn (array $comparison): array => (array) ($comparison['blocking_reasons'] ?? []))
            ->filter(fn (mixed $reason): bool => is_string($reason) && $reason !== '')
            ->countBy()
            ->all();
        $confounderReasons = [
            'dirty_state_overlap',
            'scope_safety_unverified',
            'possible_secret_in_diff',
            'isolated_patch_apply_failed',
            'provider_lock_violation',
            'fallback_violation',
            'fair_protocol_not_valid',
            'atlas_protocol_invalid',
        ];
        $observedConfounders = collect($confounderReasons)
            ->filter(fn (string $reason): bool => ((int) ($blockingReasonCounts[$reason] ?? 0)) > 0)
            ->values()
            ->all();
        $comparableCount = (int) ($readiness['comparable_count'] ?? 0);
        $readyForClaim = (bool) ($readiness['ready_for_claim'] ?? false);
        $status = match (true) {
            $readyForClaim && $observedConfounders === [] => 'valid_for_claim',
            $comparableCount > 0 && $observedConfounders === [] => 'limited_valid_comparable_sample',
            $observedConfounders !== [] => 'blocked_by_confounders',
            default => 'not_enough_comparable_data',
        };

        return [
            'schema_version' => 'atlas.fair_claude.experiment_validity.v1',
            'status' => $status,
            'ab_test_validity_model' => [
                'same_case_snapshot_required' => true,
                'equivalent_initial_state_required' => true,
                'same_acceptance_gates_required' => true,
                'same_prompt_and_task_contract_required' => true,
                'provider_model_locks_required' => true,
                'clean_isolated_workspaces_required' => true,
                'deterministic_replay_required' => true,
                'no_provider_specific_case_filtering' => true,
            ],
            'external_variable_policy' => [
                'non_evaluated_variables_cannot_decide_winner' => true,
                'non_evaluated_variables_can_only_block_comparability' => true,
                'dirty_workspace_blocks_provider_battery' => true,
                'provider_or_model_drift_blocks_comparability' => true,
                'fallback_blocks_comparability' => true,
                'invalid_protocol_blocks_score_admission' => true,
            ],
            'score_policy' => [
                'winner_requires_valid_comparable_case' => true,
                'invalid_cases_excluded_from_win_loss_math' => true,
                'inconclusive_cases_excluded_from_win_loss_math' => true,
                'claim_requires_ready_for_claim_gate' => true,
                'synthetic_scores_allowed' => false,
            ],
            'observed' => [
                'comparable_count' => $comparableCount,
                'invalid_case_count' => (int) ($paired['invalid_case_count'] ?? 0),
                'inconclusive_count' => (int) ($paired['inconclusive_count'] ?? max(0, $caseComparisons->count() - $comparableCount)),
                'provider_violation_count' => (int) ($paired['provider_violation_count'] ?? 0),
                'fallback_violation_count' => (int) ($paired['fallback_violation_count'] ?? 0),
                'protocol_validity_rate' => $this->primitives->nullableFloat($paired['protocol_validity_rate'] ?? null),
                'final_gate_pass_rate' => $this->primitives->nullableFloat($paired['final_gate_pass_rate'] ?? null),
                'blocking_reason_counts' => $blockingReasonCounts,
                'observed_confounders' => $observedConfounders,
            ],
            'operator_summary' => match ($status) {
                'valid_for_claim' => 'O desenho experimental esta valido para claim.',
                'limited_valid_comparable_sample' => 'A comparacao tem casos validos, mas ainda nao atingiu todos os gates de claim.',
                'blocked_by_confounders' => 'A comparacao esta bloqueada por variaveis externas ou protocolo invalido; nao declare vencedor.',
                default => 'Ainda nao ha dados comparaveis suficientes para avaliar vencedor.',
            },
        ];
    }

    public function fairClaudeInvalidBatteryFingerprint(
        Collection $fairRuns,
        array $readiness,
        array $paired,
        array $replay,
        Collection $caseComparisons,
    ): string {
        return hash('sha256', $this->primitives->canonicalJsonForHash([
            'run_ids' => $fairRuns
                ->pluck('id')
                ->map(fn (mixed $id): string => (string) $id)
                ->sort()
                ->values()
                ->all(),
            'manifest_hashes' => collect((array) ($replay['manifest_hashes'] ?? []))
                ->map(fn (mixed $hash): string => (string) $hash)
                ->sort()
                ->values()
                ->all(),
            'case_statuses' => $caseComparisons
                ->pluck('comparison_status')
                ->filter(fn (mixed $status): bool => is_string($status) && $status !== '')
                ->countBy()
                ->sortKeys()
                ->all(),
            'blocking_reasons' => $caseComparisons
                ->flatMap(fn (array $comparison): array => (array) ($comparison['blocking_reasons'] ?? []))
                ->filter(fn (mixed $reason): bool => is_string($reason) && $reason !== '')
                ->countBy()
                ->sortKeys()
                ->all(),
            'comparable_count' => (int) ($readiness['comparable_count'] ?? 0),
            'invalid_case_count' => (int) ($paired['invalid_case_count'] ?? 0),
        ]));
    }

    public function fairClaudeInvalidBatteryTriageState(AtlasEngineeringBenchmarkSuite $suite, string $fingerprint): array
    {
        $record = $this->primitives->arrayValue(data_get($suite->metadata ?? [], 'rivals_invalid_battery_triage', []));
        $records = collect((array) ($record['records'] ?? []))
            ->filter(fn (mixed $entry): bool => is_array($entry))
            ->values();
        if ($records->isEmpty() && $record !== []) {
            $records = collect([$record]);
        }
        $acceptedRecord = $records
            ->first(fn (array $entry): bool => ($entry['status'] ?? null) === 'triaged_quarantined'
                && is_string($entry['invalid_battery_fingerprint'] ?? null)
                && hash_equals((string) $entry['invalid_battery_fingerprint'], $fingerprint));
        $accepted = is_array($acceptedRecord);

        return [
            'schema_version' => 'atlas.fair_claude.invalid_battery_triage.v1',
            'status' => $accepted ? 'triaged_quarantined' : 'triage_required',
            'required_before_rerun' => ! $accepted,
            'invalid_battery_fingerprint' => $fingerprint,
            'accepted_record' => $accepted ? Arr::only($acceptedRecord, [
                'status',
                'invalid_battery_fingerprint',
                'reason',
                'triaged_at',
                'triaged_by',
                'policy',
            ]) : null,
            'accepted_fingerprints' => $records
                ->pluck('invalid_battery_fingerprint')
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'accepted_record_count' => $records->count(),
            'policy' => [
                'quarantine_does_not_delete_history' => true,
                'quarantined_cases_stay_out_of_win_loss_math' => true,
                'new_provider_run_still_requires_clean_workspaces_and_operator_cost_confirmation' => true,
                'claim_still_requires_new_comparable_cases' => true,
            ],
        ];
    }

    public function fairClaudeEvidencePacket(
        AtlasEngineeringBenchmarkSuite $suite,
        Collection $fairRuns,
        array $readiness,
        array $paired,
        array $baseline,
        array $replay,
        Collection $caseComparisons,
        array $nextActions,
        array $resultIntegrity,
    ): array {
        $packet = [
            'schema_version' => 1,
            'kind' => 'fair_claude_claim_evidence_packet',
            'generated_at' => now()->toJSON(),
            'suite' => [
                'id' => $suite->id,
                'slug' => $suite->slug,
                'name' => $suite->name,
            ],
            'protocol' => [
                'atlas_provider_lock' => FairClaudePolicy::PROVIDER_LOCK,
                'atlas_model_lock' => FairClaudePolicy::MODEL_LOCK,
                'baseline_provider_lock' => 'claude_code_cli',
                'baseline_model_lock' => FairClaudePolicy::MODEL_LOCK,
                'fallback_disabled' => true,
                'atlas_decide_disabled' => true,
            ],
            'claim' => [
                'ready_for_claim' => (bool) ($readiness['ready_for_claim'] ?? false),
                'readiness_status' => $readiness['status'] ?? null,
                'blocking_reasons' => $readiness['blocking_reasons'] ?? [],
                'winner' => $resultIntegrity['winner_for_claim'] ?? null,
                'provisional_leader' => $resultIntegrity['provisional_leader'] ?? null,
                'claim_winner_admitted' => (bool) ($resultIntegrity['claim_winner_admitted'] ?? false),
            ],
            'result_integrity' => Arr::only($resultIntegrity, [
                'schema_version',
                'status',
                'operator_headline',
                'score_admitted',
                'claim_winner_admitted',
                'winner_for_claim',
                'provisional_leader',
                'policy',
                'experiment_validity',
                'counts',
                'ui_contract',
            ]),
            'scorecard' => Arr::only($paired, [
                'case_count',
                'fair_mode_count',
                'comparable_count',
                'atlas_win_count',
                'claude_code_baseline_win_count',
                'tie_count',
                'protocol_validity_rate',
                'pass_without_human_rate',
                'pass_without_human_rate_medium_hard',
                'repair_conversion_rate',
                'final_gate_pass_rate',
                'provider_violation_count',
                'fallback_violation_count',
            ]),
            'audit' => [
                'run_ids' => $fairRuns->pluck('id')->values()->all(),
                'run_count' => $fairRuns->count(),
                'case_comparison_count' => $caseComparisons->count(),
                'replay_enabled' => (bool) ($replay['enabled'] ?? false),
                'replay_manifest_hashes' => (array) ($replay['manifest_hashes'] ?? []),
                'replay_packet_count' => (int) ($replay['packet_count'] ?? 0),
                'artifact_integrity_passed_count' => (int) ($replay['artifact_integrity_passed_count'] ?? 0),
                'artifact_integrity_failed_count' => (int) ($replay['artifact_integrity_failed_count'] ?? 0),
                'baseline_enabled' => (bool) ($baseline['enabled'] ?? false),
                'baseline_executed_count' => (int) ($baseline['executed_count'] ?? 0),
            ],
            'case_outcomes' => $caseComparisons
                ->map(fn (array $comparison): array => Arr::only($comparison, [
                    'case_code',
                    'comparison_status',
                    'comparable',
                    'winner',
                    'winner_reason',
                    'blocking_reasons',
                    'deltas',
                ]))
                ->values()
                ->all(),
            'next_action_ids' => collect($nextActions)
                ->pluck('id')
                ->filter()
                ->values()
                ->all(),
        ];

        return array_merge($packet, [
            'evidence_hash' => hash('sha256', $this->primitives->canonicalJsonForHash(Arr::except($packet, ['generated_at']))),
        ]);
    }

    public function fairClaudeClaimMarkdown(
        array $executiveSummary,
        array $evidencePacket,
        array $nextActions,
        Collection $caseComparisons,
    ): string {
        $scorecard = $this->primitives->arrayValue($evidencePacket['scorecard'] ?? []);
        $audit = $this->primitives->arrayValue($evidencePacket['audit'] ?? []);
        $protocol = $this->primitives->arrayValue($evidencePacket['protocol'] ?? []);
        $claim = $this->primitives->arrayValue($evidencePacket['claim'] ?? []);
        $sample = $this->primitives->arrayValue($executiveSummary['sample'] ?? []);
        $quality = $this->primitives->arrayValue($executiveSummary['quality_bar'] ?? []);
        $auditability = $this->primitives->arrayValue($executiveSummary['auditability'] ?? []);
        $integrity = $this->primitives->arrayValue($executiveSummary['result_integrity'] ?? []);
        $experimentValidity = $this->primitives->arrayValue($integrity['experiment_validity'] ?? []);
        $hash = (string) ($evidencePacket['evidence_hash'] ?? '');
        $lines = [
            '# Atlas Rivals Fair Claude Report',
            '',
            '## Executive Summary',
            '',
            '- Claim status: `'.$this->primitives->markdownInline((string) ($executiveSummary['claim_status'] ?? 'unknown')).'`',
            '- Headline: '.$this->primitives->markdownText((string) ($executiveSummary['headline'] ?? '')),
            '- Winner: `'.$this->primitives->markdownInline((string) ($executiveSummary['winner'] ?? 'none')).'`',
            '- Confidence: `'.$this->primitives->markdownInline((string) ($executiveSummary['confidence'] ?? 'none')).'`',
            '- Evidence hash: `'.$this->primitives->markdownInline($hash).'`',
            '',
            '## Result Integrity',
            '',
            '- Status: `'.$this->primitives->markdownInline((string) ($integrity['status'] ?? 'unknown')).'`',
            '- Score admitted: `'.($integrity['score_admitted'] ?? false ? 'true' : 'false').'`',
            '- Claim winner admitted: `'.($integrity['claim_winner_admitted'] ?? false ? 'true' : 'false').'`',
            '- Winner for claim: `'.$this->primitives->markdownInline((string) ($integrity['winner_for_claim'] ?? 'none')).'`',
            '- Provisional leader: `'.$this->primitives->markdownInline((string) ($integrity['provisional_leader'] ?? 'none')).'`',
            '- Operator note: '.$this->primitives->markdownText((string) ($integrity['operator_headline'] ?? '')),
            '',
            '## Experiment Validity',
            '',
            '- Status: `'.$this->primitives->markdownInline((string) ($experimentValidity['status'] ?? 'unknown')).'`',
            '- Non-evaluated variables cannot decide winner: `'.(data_get($experimentValidity, 'external_variable_policy.non_evaluated_variables_cannot_decide_winner') ? 'true' : 'false').'`',
            '- Non-evaluated variables can only block comparability: `'.(data_get($experimentValidity, 'external_variable_policy.non_evaluated_variables_can_only_block_comparability') ? 'true' : 'false').'`',
            '- No provider-specific case filtering: `'.(data_get($experimentValidity, 'ab_test_validity_model.no_provider_specific_case_filtering') ? 'true' : 'false').'`',
            '- Observed confounders: `'.$this->primitives->markdownInline(implode(', ', (array) data_get($experimentValidity, 'observed.observed_confounders', [])) ?: 'none').'`',
            '- Operator summary: '.$this->primitives->markdownText((string) ($experimentValidity['operator_summary'] ?? '')),
            '',
            '## Sample',
            '',
            '| Metric | Value |',
            '| --- | ---: |',
            '| Comparable cases | '.$this->primitives->markdownNumber($sample['comparable_cases'] ?? null).' |',
            '| Fair mode cases | '.$this->primitives->markdownNumber($sample['fair_mode_cases'] ?? null).' |',
            '| Atlas wins | '.$this->primitives->markdownNumber($sample['atlas_wins'] ?? null).' |',
            '| Claude Code wins | '.$this->primitives->markdownNumber($sample['claude_code_baseline_wins'] ?? null).' |',
            '| Ties | '.$this->primitives->markdownNumber($sample['ties'] ?? null).' |',
            '',
            '## Quality Bar',
            '',
            '| Metric | Value |',
            '| --- | ---: |',
            '| Protocol validity | '.$this->primitives->markdownPercent($quality['protocol_validity_rate'] ?? null).' |',
            '| Pass without human | '.$this->primitives->markdownPercent($quality['pass_without_human_rate'] ?? null).' |',
            '| Medium/hard pass without human | '.$this->primitives->markdownPercent($quality['medium_hard_pass_without_human_rate'] ?? null).' |',
            '| Final gate pass | '.$this->primitives->markdownPercent($quality['final_gate_pass_rate'] ?? null).' |',
            '| Repair conversion | '.$this->primitives->markdownPercent($scorecard['repair_conversion_rate'] ?? null).' |',
            '',
            '## Protocol Lock',
            '',
            '| Lock | Value |',
            '| --- | --- |',
            '| Atlas provider | `'.$this->primitives->markdownInline((string) ($protocol['atlas_provider_lock'] ?? '-')).'` |',
            '| Atlas model | `'.$this->primitives->markdownInline((string) ($protocol['atlas_model_lock'] ?? '-')).'` |',
            '| Baseline provider | `'.$this->primitives->markdownInline((string) ($protocol['baseline_provider_lock'] ?? '-')).'` |',
            '| Baseline model | `'.$this->primitives->markdownInline((string) ($protocol['baseline_model_lock'] ?? '-')).'` |',
            '| Fallback disabled | `'.($protocol['fallback_disabled'] ?? false ? 'true' : 'false').'` |',
            '| Atlas Decide disabled | `'.($protocol['atlas_decide_disabled'] ?? false ? 'true' : 'false').'` |',
            '',
            '## Auditability',
            '',
            '| Evidence | Value |',
            '| --- | ---: |',
            '| Ready for claim | `'.($claim['ready_for_claim'] ?? false ? 'true' : 'false').'` |',
            '| Baseline executed | `'.($auditability['baseline_executed'] ?? false ? 'true' : 'false').'` |',
            '| Replay verified | `'.($auditability['replay_verified'] ?? false ? 'true' : 'false').'` |',
            '| Replay packets | '.$this->primitives->markdownNumber($audit['replay_packet_count'] ?? null).' |',
            '| Artifact integrity failures | '.$this->primitives->markdownNumber($audit['artifact_integrity_failed_count'] ?? null).' |',
            '| Manifest hashes | '.$this->primitives->markdownNumber($auditability['manifest_hash_count'] ?? null).' |',
            '',
            '## Next Actions',
            '',
        ];

        if ($nextActions === []) {
            $lines[] = '- No next actions generated.';
        } else {
            foreach ($nextActions as $action) {
                $line = '- `'.$this->primitives->markdownInline((string) ($action['severity'] ?? 'info')).'` '.$this->primitives->markdownText((string) ($action['title'] ?? 'Action'));
                if (($action['command'] ?? null) !== null) {
                    $line .= ' - `'.$this->primitives->markdownInline((string) $action['command']).'`';
                }
                $lines[] = $line;
            }
        }

        $lines = array_merge($lines, [
            '',
            '## Case Outcomes',
            '',
            '| Case | Status | Winner | Reason | Delta Score | Delta Time |',
            '| --- | --- | --- | --- | ---: | ---: |',
        ]);

        $caseRows = $caseComparisons->take(30);
        if ($caseRows->isEmpty()) {
            $lines[] = '| - | - | - | No case comparisons available. | - | - |';
        } else {
            foreach ($caseRows as $comparison) {
                $deltas = $this->primitives->arrayValue($comparison['deltas'] ?? []);
                $lines[] = '| '.$this->primitives->markdownCell((string) ($comparison['case_code'] ?? $comparison['case_id'] ?? '-'))
                    .' | '.$this->primitives->markdownCell((string) ($comparison['comparison_status'] ?? '-'))
                    .' | '.$this->primitives->markdownCell((string) ($comparison['winner'] ?? '-'))
                    .' | '.$this->primitives->markdownCell((string) ($comparison['winner_reason'] ?? '-'))
                    .' | '.$this->primitives->markdownNumber($deltas['score'] ?? null)
                    .' | '.$this->primitives->markdownNumber($deltas['duration_ms'] ?? null).' |';
            }
        }

        return implode("\n", $lines)."\n";
    }
}
