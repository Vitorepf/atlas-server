<?php

namespace App\Services\Ai\Rivals\Core;

use App\Services\Ai\Rivals\Support\AtomicWriter;
use App\Services\Ai\Rivals\Support\EventsLifecycleContract;
use App\Services\Ai\Rivals\Support\RunPaths;
use App\Services\Ai\Rivals\Support\SchemaContract;
use RuntimeException;

/**
 * Relatório empresarial consolidado Fase A — sempre 10 suites, nunca claim agregado.
 */
class EnterpriseReportBuilder
{
    /** Rótulos humanos (PT) das famílias de uplift — fonte única para fatos, perfil e dashboard. */
    public const FAMILY_LABELS = [
        'long_horizon' => 'Trabalho longo (HAL)',
        'patch_swe' => 'Correção de bugs reais (SWE-bench Live)',
        'terminal' => 'Terminal (Terminal-Bench)',
        'tool_function' => 'Uso de ferramentas (BFCL)',
        'polyglot' => 'Código poliglota (Aider)',
    ];

    public static function familyLabel(?string $family): string
    {
        return self::FAMILY_LABELS[(string) $family] ?? (string) $family;
    }

    /**
     * Capacidades = o que os 10 benchmarks MEDEM (o resultado que importa),
     * não os benchmarks em si (o instrumento). Cada suíte alimenta uma
     * capacidade primária; a visão por-benchmark vira drill-down. Fonte única.
     */
    public const CAPABILITIES = [
        'coding' => [
            'label' => 'Programação',
            'measures' => 'Escrever e corrigir código em repositórios reais e problemas algorítmicos.',
            'suites' => ['senior_swe_bench', 'swe_bench_live', 'live_code_bench', 'aider_polyglot', 'terminal_bench'],
        ],
        'tool_use' => [
            'label' => 'Uso de ferramentas',
            'measures' => 'Chamar funções/ferramentas certas e conduzir diálogo de agente com usuário simulado.',
            'suites' => ['tau2_bench', 'bfcl'],
        ],
        'long_horizon' => [
            'label' => 'Trabalho de longo prazo',
            'measures' => 'Tarefas agênticas longas, multi-etapa, mais próximas de trabalho real de engenharia.',
            'suites' => ['hal_harness', 'swe_marathon'],
        ],
        'reasoning' => [
            'label' => 'Raciocínio',
            'measures' => 'Resolver problemas que exigem raciocínio passo a passo (ex.: matemática).',
            'suites' => ['inspect_evals'],
        ],
    ];

    public function build(): array
    {
        $suiteIds = (new SuiteRegistry)->externalSuiteIds();
        $upliftFamilies = (array) config('atlas_rivals.uplift_families', []);
        $primaryModel = (string) config('atlas_rivals.fase_a.primary_model', 'verboo_kimi_k2_7');

        $runs = $this->scanRuns();
        $suiteRows = [];
        $included = [];
        $excluded = [];
        $gaps = [];
        $modelsSeen = [];

        foreach ($suiteIds as $suiteId) {
            $match = $this->bestRunForSuite($suiteId, $runs);
            if ($match === null) {
                $suiteRows[] = $this->emptySuiteRow($suiteId);
                $gaps[] = "not_run:{$suiteId}";

                continue;
            }

            [$runId, $meta] = $match;
            $row = $this->suiteRowFromRun($suiteId, $runId, $meta);
            $suiteRows[] = $row;
            if (in_array($row['status'], ['ok', 'missing_data', 'failed'], true)) {
                $included[] = $runId;
            } else {
                $excluded[] = ['run_id' => $runId, 'suite_id' => $suiteId, 'reason' => $row['status']];
            }
            foreach ($row['missing_fields'] as $field) {
                $gaps[] = "missing_data:{$suiteId}:{$field}";
            }
            if ($row['status'] === 'missing_data') {
                $gaps[] = "missing_data:{$suiteId}";
            }
            foreach ((array) ($meta['claim_scope']['models'] ?? []) as $modelId) {
                if (is_string($modelId) && $modelId !== '') {
                    $modelsSeen[$modelId] = true;
                }
            }
        }

        $modelIds = array_keys($modelsSeen);
        sort($modelIds);

        $atlasUplift = ['families' => []];
        foreach ($upliftFamilies as $family => $suiteId) {
            $familyRow = $this->upliftFamilyRow((string) $family, (string) $suiteId, $runs, $primaryModel);
            $atlasUplift['families'][] = $familyRow;
            if ($familyRow['status'] === 'not_run') {
                $gaps[] = "uplift_not_run:{$family}";
            } elseif (($familyRow['status'] ?? '') !== 'real_uplift') {
                $gaps[] = 'uplift_'.$familyRow['status'].':'.$family
                    .(isset($familyRow['reason']) ? ':'.$familyRow['reason'] : '');
            }
        }

        // Suítes fora de uplift_families são bare-only POR CONSTRUÇÃO (sem bridge
        // Atlas); declarar explícito em vez de deixar como gap silencioso.
        $atlasUplift['bare_only_suites'] = array_values(array_map(
            static fn (string $suiteId): array => [
                'suite_id' => $suiteId,
                'status' => 'bare_only',
                'reason' => 'atlas_runtime_not_supported',
            ],
            array_diff((new SuiteRegistry)->externalSuiteIds(), array_values($upliftFamilies)),
        ));

        $modelMatrix = count($modelIds) >= 2
            ? [
                'mode' => 'model_vs_model',
                'model_ids' => $modelIds,
                'rows' => $this->modelMatrixRows($suiteRows, $runs),
            ]
            : $this->singleModelAtlasFaceMatrix($primaryModel, $suiteRows, $runs, $atlasUplift);

        $counts = [
            'ok' => 0,
            'failed' => 0,
            'blocked' => 0,
            'missing_data' => 0,
            'not_run' => 0,
        ];
        foreach ($suiteRows as $row) {
            $counts[$row['status']] = ($counts[$row['status']] ?? 0) + 1;
        }

        $facts = $this->buildMeasuredFacts($primaryModel, $suiteRows, $atlasUplift, $counts);

        $deliveryInventory = EnterpriseSuiteDeliveryCatalog::all();

        $report = [
            'schema_version' => SchemaContract::ENTERPRISE_REPORT,
            'built_at' => now()->toIso8601String(),
            'claim_allowed' => false,
            'claim_blockers' => ['aggregate_view_claims_live_per_run'],
            'executive_summary' => [
                'primary_model' => $primaryModel,
                'models_observed' => $modelIds,
                'provider_binding' => 'hermes+verboo',
                'suites_ok' => $counts['ok'],
                'suites_failed' => $counts['failed'],
                'suites_blocked' => $counts['blocked'],
                'suites_missing_data' => $counts['missing_data'],
                'suites_not_run' => $counts['not_run'],
                'uplift_families_total' => count($upliftFamilies),
                'uplift_families_ready' => count(array_filter(
                    $atlasUplift['families'],
                    fn (array $f): bool => ($f['status'] ?? '') === 'real_uplift',
                )),
                'narrative' => $facts['headline'] ?? null,
            ],
            'delivery_inventory' => array_values($deliveryInventory),
            'suite_rows' => $suiteRows,
            'model_dissections' => $dissections = (new EnterpriseModelDissectionBuilder)->build([
                'executive_summary' => [
                    'primary_model' => $primaryModel,
                    'models_observed' => $modelIds,
                ],
                'suite_rows' => $suiteRows,
                'atlas_uplift' => $atlasUplift,
            ]),
            'model_capabilities' => $this->buildCapabilityAggregates($suiteRows, $atlasUplift, $primaryModel),
            'model_profiles' => $this->buildModelProfiles(
                $dissections,
                $atlasUplift,
                $this->suiteReliabilityMap($suiteRows),
            ),
            'model_matrix' => $modelMatrix,
            'atlas_uplift' => $atlasUplift,
            'facts' => $facts,
            'gaps' => array_values(array_unique($gaps)),
            'included_run_ids' => array_values(array_unique($included)),
            'excluded_run_ids' => $excluded,
        ];
        $report['report_hash'] = self::hashPayload($report);

        $violations = SchemaContract::validate($report, SchemaContract::ENTERPRISE_REPORT);
        if ($violations !== []) {
            throw new RuntimeException('rivals_invalid_enterprise_report:'.implode(',', $violations));
        }

        AtomicWriter::write(
            RunPaths::enterpriseReportPath(),
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
        );
        $presenter = new EnterpriseReportPresenter;
        AtomicWriter::write(RunPaths::enterpriseMarkdownPath(), $presenter->markdown($report, $runs));
        AtomicWriter::write(RunPaths::enterpriseCsvPath(), $this->csv($report));
        AtomicWriter::write(RunPaths::enterpriseHtmlPath(), $presenter->html($report, $runs));

        return $report;
    }

    /** @return list<array{run_id: string, suite_id: string, adjudication: array<string, mixed>, report: ?array<string, mixed>, uplift: ?array<string, mixed>}> */
    private function scanRuns(): array
    {
        $runsDir = RunPaths::runsDir();
        if (! is_dir($runsDir)) {
            return [];
        }
        $out = [];
        foreach (array_diff(scandir($runsDir) ?: [], ['.', '..']) as $runId) {
            if (! is_dir($runsDir.'/'.$runId)) {
                continue;
            }
            $planPath = RunPaths::planPath($runId);
            if (! is_file($planPath)) {
                continue;
            }
            $plan = json_decode((string) file_get_contents($planPath), true) ?? [];
            $suiteId = (string) ($plan['suite_id'] ?? '');
            if ($suiteId === '') {
                continue;
            }
            $adjPath = RunPaths::adjudicationPath($runId);
            $adjudication = is_file($adjPath)
                ? (json_decode((string) file_get_contents($adjPath), true) ?? [])
                : [];
            $reportPath = RunPaths::reportPath($runId);
            $report = is_file($reportPath)
                ? (json_decode((string) file_get_contents($reportPath), true) ?? null)
                : null;
            $upliftPath = RunPaths::runDir($runId).'/uplift.json';
            $uplift = is_file($upliftPath)
                ? (json_decode((string) file_get_contents($upliftPath), true) ?? null)
                : null;
            $out[] = [
                'run_id' => $runId,
                'suite_id' => $suiteId,
                'adjudication' => $adjudication,
                'report' => $report,
                'uplift' => $uplift,
                'claim_scope' => $adjudication['claim_scope'] ?? ($report['claim_scope'] ?? []),
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function bestRunForSuite(string $suiteId, array $runs): ?array
    {
        $candidates = array_values(array_filter(
            $runs,
            fn (array $run): bool => ($run['suite_id'] ?? null) === $suiteId,
        ));
        if ($candidates === []) {
            return null;
        }
        usort($candidates, function (array $a, array $b): int {
            $aValid = (($a['adjudication']['pipeline_valid'] ?? false) === true) ? 1 : 0;
            $bValid = (($b['adjudication']['pipeline_valid'] ?? false) === true) ? 1 : 0;
            if ($aValid !== $bValid) {
                return $bValid <=> $aValid;
            }

            return strcmp((string) $b['run_id'], (string) $a['run_id']);
        });

        $best = $candidates[0];

        return [(string) $best['run_id'], $best];
    }

    /** @return array<string, mixed> */
    private function emptySuiteRow(string $suiteId): array
    {
        $delivery = EnterpriseSuiteDeliveryCatalog::forSuite($suiteId);

        return [
            'suite_id' => $suiteId,
            'status' => 'not_run',
            'run_id' => null,
            'success_rate_itt' => null,
            'intelligence_rate' => null,
            'median_wall_ms' => null,
            'tokens_in_avg' => null,
            'tokens_out_avg' => null,
            'tokens_per_task' => null,
            'tokens_per_second' => null,
            'total_tokens' => null,
            'cost_per_1k_tokens' => null,
            'cost_per_task' => null,
            'cost_basis' => null,
            'env_failure_rate' => null,
            'tokens_coverage_incomplete' => false,
            'events_complete' => false,
            'is_atlas_fact' => false,
            'missing_fields' => [],
            'pipeline_valid' => false,
            'internal_claim_allowed' => false,
            'axes' => [
                'pipeline' => ['ok' => false, 'status' => 'not_run'],
                'measurement' => ['ok' => false, 'status' => 'not_run', 'missing_fields' => []],
                'intelligence' => ['rate' => null, 'itt' => null, 'status' => 'not_run'],
                'claim' => ['internal_ok' => false, 'status' => 'not_run', 'blockers' => []],
            ],
            'category' => $delivery['category'],
            'title' => $delivery['title'],
            'delivery' => $delivery,
            'full_metrics' => null,
            'report_rows' => [],
            'native_signals' => [],
            'case_ids' => $delivery['fase_a_case_pack'],
            'artifacts' => [],
            'adjudication' => null,
            'observed_native_metric_keys' => [],
            'observed_report_metric_keys' => [],
            'delivery_coverage' => $this->deliveryCoverage($delivery, [], []),
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function suiteRowFromRun(string $suiteId, string $runId, array $meta): array
    {
        $adj = (array) ($meta['adjudication'] ?? []);
        $report = $meta['report'];
        $pipelineValid = ($adj['pipeline_valid'] ?? false) === true;
        $internalAllowed = ($adj['internal_claim_allowed'] ?? $adj['claim_allowed'] ?? false) === true;
        $delivery = EnterpriseSuiteDeliveryCatalog::forSuite($suiteId);

        $metrics = $this->aggregateReportMetrics(is_array($report) ? $report : []);
        $missing = $metrics['missing_fields'];
        $reportRows = is_array($report) ? array_values(array_filter(
            (array) ($report['rows'] ?? []),
            'is_array',
        )) : [];
        $fullMetrics = $this->fullMetricsFromRows($reportRows);
        $nativeSignals = $this->harvestNativeSignals($suiteId, $runId);
        $caseIds = $this->caseIdsForRun($runId, $meta, $delivery);
        $unitsExpected = (int) ($meta['units_expected'] ?? data_get($report, 'units_expected', 0));
        $executionEvidence = $this->executionEvidenceForRun($runId, $unitsExpected);
        $artifacts = $this->runArtifacts($runId);
        $eventsComplete = $this->eventsCompleteForRun($runId);
        $measurementStatus = $this->measurementStatus(
            $missing,
            (bool) ($metrics['tokens_coverage_incomplete'] ?? false),
            $suiteId,
            $runId,
        );
        if (($metrics['intelligence_rate'] ?? null) === null) {
            $metrics['intelligence_rate'] = $this->intelligenceFromReceipts($runId);
        }

        $status = 'failed';
        if (! $pipelineValid && $report === null) {
            $status = 'blocked';
        } elseif ($pipelineValid && $missing !== []) {
            $status = 'missing_data';
        } elseif ($pipelineValid) {
            $status = 'ok';
        }

        $observedNativeKeys = $this->flattenObservedKeys($nativeSignals);
        $observedReportKeys = $reportRows === [] ? [] : array_values(array_unique(array_merge(
            ...array_map(fn (array $row): array => array_keys($row), $reportRows),
        )));

        $axes = [
            'pipeline' => [
                'ok' => $pipelineValid,
                'status' => $pipelineValid ? 'ok' : ($report === null ? 'blocked' : 'failed'),
                'blockers' => array_values((array) ($adj['pipeline_blockers'] ?? [])),
            ],
            'measurement' => [
                'ok' => $missing === [] && $measurementStatus !== 'harness_omit',
                'status' => $measurementStatus,
                'missing_fields' => $missing,
            ],
            'intelligence' => [
                'rate' => $metrics['intelligence_rate'] ?? null,
                'itt' => $metrics['success_rate_itt'] ?? null,
                'status' => ($metrics['intelligence_rate'] ?? null) === null && ($metrics['success_rate_itt'] ?? null) === null
                    ? 'unknown'
                    : 'measured',
            ],
            'claim' => [
                'internal_ok' => $internalAllowed,
                'status' => $internalAllowed ? 'allowed' : 'blocked',
                'blockers' => array_values((array) ($adj['internal_claim_blockers'] ?? [])),
            ],
        ];
        $isAtlasFact = $pipelineValid
            && $internalAllowed
            && $eventsComplete
            && $missing === []
            && $measurementStatus !== 'harness_omit';

        return [
            'suite_id' => $suiteId,
            'status' => $status,
            'run_id' => $runId,
            'success_rate_itt' => $metrics['success_rate_itt'],
            'intelligence_rate' => $metrics['intelligence_rate'] ?? null,
            'median_wall_ms' => $metrics['median_wall_ms'],
            'tokens_in_avg' => $metrics['tokens_in_avg'],
            'tokens_out_avg' => $metrics['tokens_out_avg'],
            'tokens_per_task' => $metrics['tokens_per_task'],
            'tokens_per_second' => $metrics['tokens_per_second'],
            'total_tokens' => $metrics['total_tokens'],
            'cost_per_1k_tokens' => $metrics['cost_per_1k_tokens'],
            'cost_per_task' => $metrics['cost_per_task'],
            'cost_basis' => $metrics['cost_basis'],
            'env_failure_rate' => $metrics['env_failure_rate'],
            'tokens_coverage_incomplete' => (bool) ($metrics['tokens_coverage_incomplete'] ?? false),
            'events_complete' => $eventsComplete,
            'is_atlas_fact' => $isAtlasFact,
            'missing_fields' => $missing,
            'pipeline_valid' => $pipelineValid,
            'internal_claim_allowed' => $internalAllowed,
            'axes' => $axes,
            'category' => $delivery['category'],
            'title' => $delivery['title'],
            'delivery' => $delivery,
            'reliable' => $executionEvidence['reliable'],
            'unreliable_reason' => $executionEvidence['unreliable_reason'],
            'execution_evidence' => $executionEvidence,
            'full_metrics' => $fullMetrics,
            'report_rows' => $reportRows,
            'native_signals' => $nativeSignals,
            'case_ids' => $caseIds,
            'artifacts' => $artifacts,
            'adjudication' => [
                'pipeline_valid' => $pipelineValid,
                'claim_tier' => $adj['claim_tier'] ?? ($report['claim_tier'] ?? null),
                'internal_claim_allowed' => $internalAllowed,
                'public_claim_allowed' => ($adj['public_claim_allowed'] ?? false) === true,
                'pipeline_blockers' => array_values((array) ($adj['pipeline_blockers'] ?? [])),
                'internal_claim_blockers' => array_values((array) ($adj['internal_claim_blockers'] ?? [])),
                'not_ready_reasons' => array_values((array) ($adj['not_ready_reasons'] ?? [])),
                'statistical_analysis' => $adj['statistical_analysis'] ?? ($report['statistical_analysis'] ?? null),
            ],
            'observed_native_metric_keys' => $observedNativeKeys,
            'observed_report_metric_keys' => $observedReportKeys,
            'delivery_coverage' => $this->deliveryCoverage($delivery, $observedNativeKeys, $observedReportKeys),
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array{
     *   success_rate_itt: ?float,
     *   median_wall_ms: ?float,
     *   tokens_in_avg: ?float,
     *   tokens_out_avg: ?float,
     *   cost_per_task: ?float,
     *   cost_basis: ?string,
     *   env_failure_rate: ?float,
     *   missing_fields: list<string>
     * }
     */
    private function aggregateReportMetrics(array $report): array
    {
        $rows = (array) ($report['rows'] ?? []);
        if ($rows === []) {
            return [
                'success_rate_itt' => null,
                'intelligence_rate' => null,
                'median_wall_ms' => null,
                'tokens_in_avg' => null,
                'tokens_out_avg' => null,
                'tokens_per_task' => null,
                'tokens_per_second' => null,
                'total_tokens' => null,
                'cost_per_1k_tokens' => null,
                'cost_per_task' => null,
                'cost_basis' => null,
                'env_failure_rate' => null,
                'missing_fields' => ['report_rows'],
            ];
        }

        $successRates = [];
        $intelligenceRates = [];
        $walls = [];
        $tokensIn = [];
        $tokensOut = [];
        $tokensPerTask = [];
        $tokensPerSecond = [];
        $totalTokens = [];
        $costPer1k = [];
        $costs = [];
        $envRates = [];
        $missing = [];
        $tokenCoverageIncomplete = false;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (isset($row['success_rate_itt'])) {
                $successRates[] = (float) $row['success_rate_itt'];
            }
            if (($row['intelligence_rate'] ?? null) !== null && is_numeric($row['intelligence_rate'])) {
                $intelligenceRates[] = (float) $row['intelligence_rate'];
            }
            if ($row['median_wall_ms'] !== null && $row['median_wall_ms'] !== '') {
                $walls[] = (float) $row['median_wall_ms'];
            }
            if (($row['avg_tokens_in'] ?? null) !== null) {
                $tokensIn[] = (float) $row['avg_tokens_in'];
            }
            if (($row['avg_tokens_out'] ?? null) !== null) {
                $tokensOut[] = (float) $row['avg_tokens_out'];
            }
            if (($row['tokens_per_task'] ?? null) !== null && is_numeric($row['tokens_per_task'])) {
                $tokensPerTask[] = (float) $row['tokens_per_task'];
            }
            if (($row['tokens_per_second'] ?? null) !== null && is_numeric($row['tokens_per_second'])) {
                $tokensPerSecond[] = (float) $row['tokens_per_second'];
            }
            if (($row['total_tokens'] ?? null) !== null && is_numeric($row['total_tokens'])) {
                $totalTokens[] = (float) $row['total_tokens'];
            }
            if (($row['cost_per_1k_tokens'] ?? null) !== null && is_numeric($row['cost_per_1k_tokens'])) {
                $costPer1k[] = (float) $row['cost_per_1k_tokens'];
            }
            if (($row['cost_per_task'] ?? null) !== null) {
                $costs[] = (float) $row['cost_per_task'];
            }
            if (isset($row['environment_failure_rate'])) {
                $envRates[] = (float) $row['environment_failure_rate'];
            }
            $coverage = (array) ($row['tokens_coverage'] ?? []);
            $n = (int) ($coverage['n'] ?? 0);
            $in = (int) ($coverage['in'] ?? 0);
            $out = (int) ($coverage['out'] ?? 0);
            if ($n > 0 && ($in < $n || $out < $n)) {
                $tokenCoverageIncomplete = true;
            }
        }

        // Honesty: missing_data only when no measured token averages exist.
        // Partial coverage (env failures / harness omit on some units) stays visible
        // via tokens_coverage_* facets — never invent zeros for absent units.
        if ($tokensIn === []) {
            $missing[] = 'tokens_in';
        }
        if ($tokensOut === []) {
            $missing[] = 'tokens_out';
        }
        if ($walls === []) {
            $missing[] = 'wall_ms';
        }
        $missing = array_values(array_unique($missing));

        $costBasis = null;
        if ($costs !== []) {
            $costBasis = max($costs) == 0.0
                ? 'verboo_subscription_marginal'
                : 'reported_usd';
        }

        return $this->enrichTokenThroughput([
            'success_rate_itt' => $successRates === [] ? null : round(array_sum($successRates) / count($successRates), 4),
            'intelligence_rate' => $intelligenceRates === [] ? null : round(array_sum($intelligenceRates) / count($intelligenceRates), 4),
            'median_wall_ms' => $walls === [] ? null : $this->median($walls),
            'tokens_in_avg' => $tokensIn === [] ? null : round(array_sum($tokensIn) / count($tokensIn), 2),
            'tokens_out_avg' => $tokensOut === [] ? null : round(array_sum($tokensOut) / count($tokensOut), 2),
            'tokens_per_task' => $tokensPerTask === [] ? null : round(array_sum($tokensPerTask) / count($tokensPerTask), 4),
            'tokens_per_second' => $tokensPerSecond === [] ? null : round(array_sum($tokensPerSecond) / count($tokensPerSecond), 4),
            'total_tokens' => $totalTokens === [] ? null : round(array_sum($totalTokens), 2),
            'cost_per_1k_tokens' => $costPer1k === [] ? null : round(array_sum($costPer1k) / count($costPer1k), 6),
            'cost_per_task' => $costs === [] ? null : round(array_sum($costs) / count($costs), 6),
            'cost_basis' => $costBasis,
            'env_failure_rate' => $envRates === [] ? null : round(array_sum($envRates) / count($envRates), 4),
            'tokens_coverage_incomplete' => $tokenCoverageIncomplete,
            'missing_fields' => $missing,
        ]);
    }

    /**
     * Backfill throughput fields from usage + wall when older report rows omit them.
     *
     * @param  array<string, mixed>  $metrics
     * @return array<string, mixed>
     */
    private function enrichTokenThroughput(array $metrics): array
    {
        $in = isset($metrics['tokens_in_avg']) && is_numeric($metrics['tokens_in_avg'])
            ? (float) $metrics['tokens_in_avg']
            : (isset($metrics['avg_tokens_in']) && is_numeric($metrics['avg_tokens_in'])
                ? (float) $metrics['avg_tokens_in']
                : null);
        $out = isset($metrics['tokens_out_avg']) && is_numeric($metrics['tokens_out_avg'])
            ? (float) $metrics['tokens_out_avg']
            : (isset($metrics['avg_tokens_out']) && is_numeric($metrics['avg_tokens_out'])
                ? (float) $metrics['avg_tokens_out']
                : null);
        $sum = ($in !== null || $out !== null) ? (float) ($in ?? 0) + (float) ($out ?? 0) : null;

        if (($metrics['total_tokens'] ?? null) === null && $sum !== null) {
            $totalIn = isset($metrics['total_tokens_in']) && is_numeric($metrics['total_tokens_in'])
                ? (float) $metrics['total_tokens_in'] : null;
            $totalOut = isset($metrics['total_tokens_out']) && is_numeric($metrics['total_tokens_out'])
                ? (float) $metrics['total_tokens_out'] : null;
            if ($totalIn !== null || $totalOut !== null) {
                $metrics['total_tokens'] = round((float) ($totalIn ?? 0) + (float) ($totalOut ?? 0), 2);
            } else {
                $metrics['total_tokens'] = round($sum, 2);
            }
        }

        if (($metrics['tokens_per_task'] ?? null) === null && $sum !== null) {
            $metrics['tokens_per_task'] = round($sum, 4);
        }
        if (($metrics['avg_tokens_per_task'] ?? null) === null && $sum !== null) {
            $metrics['avg_tokens_per_task'] = round($sum, 4);
        }

        $wallMs = null;
        if (isset($metrics['median_wall_ms']) && is_numeric($metrics['median_wall_ms']) && (float) $metrics['median_wall_ms'] > 0) {
            $wallMs = (float) $metrics['median_wall_ms'];
        } elseif (isset($metrics['avg_wall_ms']) && is_numeric($metrics['avg_wall_ms']) && (float) $metrics['avg_wall_ms'] > 0) {
            $wallMs = (float) $metrics['avg_wall_ms'];
        }
        if ($wallMs !== null && ($metrics['median_wall_sec'] ?? null) === null) {
            $metrics['median_wall_sec'] = round($wallMs / 1000.0, 6);
        }

        if (($metrics['tokens_per_second'] ?? null) === null && $sum !== null && $wallMs !== null && $wallMs > 0) {
            $metrics['tokens_per_second'] = round($sum / ($wallMs / 1000.0), 4);
        }
        if (($metrics['tokens_per_second_aggregate'] ?? null) === null && ($metrics['tokens_per_second'] ?? null) !== null) {
            $metrics['tokens_per_second_aggregate'] = $metrics['tokens_per_second'];
        }
        if (($metrics['tokens_in_per_second'] ?? null) === null && $in !== null && $wallMs !== null && $wallMs > 0) {
            $metrics['tokens_in_per_second'] = round($in / ($wallMs / 1000.0), 4);
        }
        if (($metrics['tokens_out_per_second'] ?? null) === null && $out !== null && $wallMs !== null && $wallMs > 0) {
            $metrics['tokens_out_per_second'] = round($out / ($wallMs / 1000.0), 4);
        }

        $totalTok = isset($metrics['total_tokens']) && is_numeric($metrics['total_tokens'])
            ? (float) $metrics['total_tokens']
            : $sum;
        $totalCost = isset($metrics['total_cost_usd']) && is_numeric($metrics['total_cost_usd'])
            ? (float) $metrics['total_cost_usd']
            : null;
        if (($metrics['cost_per_1k_tokens'] ?? null) === null && $totalTok !== null && $totalTok > 0 && $totalCost !== null && $totalCost > 0) {
            $metrics['cost_per_1k_tokens'] = round(($totalCost / $totalTok) * 1000.0, 6);
        }

        return $metrics;
    }

    /**
     * Face Atlas×modelo para bateria single-model: 1 linha de ranking + per_suite factual.
     *
     * @param  list<array<string, mixed>>  $suiteRows
     * @param  list<array<string, mixed>>  $runs
     * @param  array<string, mixed>  $atlasUplift
     * @return array<string, mixed>
     */
    private function singleModelAtlasFaceMatrix(
        string $primaryModel,
        array $suiteRows,
        array $runs,
        array $atlasUplift,
    ): array {
        $familyBySuite = [];
        foreach ((array) ($atlasUplift['families'] ?? []) as $family) {
            $sid = (string) ($family['suite_id'] ?? '');
            if ($sid !== '') {
                $familyBySuite[$sid] = $family;
            }
        }

        $perSuite = [];
        $bareAll = [];
        $barePaired = [];
        $atlasPaired = [];

        foreach ($suiteRows as $row) {
            $suiteId = (string) ($row['suite_id'] ?? '');
            if ($suiteId === '') {
                continue;
            }
            $scores = $this->armScoresForSuite($suiteId, $primaryModel, $runs);
            $bare = $scores['bare'] ?? (($row['intelligence_rate'] ?? null) === null
                ? null
                : (float) $row['intelligence_rate']);
            if ($bare === null && ($row['success_rate_itt'] ?? null) !== null
                && (($row['env_failure_rate'] ?? 0) == 0)) {
                $bare = (float) $row['success_rate_itt'];
            }
            $atlas = $scores['atlas'] ?? null;
            $family = $familyBySuite[$suiteId] ?? null;
            $upliftStatus = is_array($family) ? (string) ($family['status'] ?? 'not_run') : 'not_applicable';
            $comparable = $upliftStatus === 'real_uplift'
                && $bare !== null
                && ($family['atlas_intelligence'] ?? $atlas) !== null;

            $atlasShown = null;
            $delta = null;
            if ($comparable) {
                $atlasShown = isset($family['atlas_intelligence'])
                    ? (float) $family['atlas_intelligence']
                    : (float) $atlas;
                $bareForDelta = isset($family['bare_intelligence'])
                    ? (float) $family['bare_intelligence']
                    : (float) $bare;
                $delta = round($atlasShown - $bareForDelta, 4);
                $barePaired[] = $bareForDelta;
                $atlasPaired[] = $atlasShown;
            }

            if ($bare !== null) {
                $bareAll[] = (float) $bare;
            }

            $perSuite[] = [
                'suite_id' => $suiteId,
                'status' => $row['status'] ?? null,
                'bare_intelligence' => $bare,
                'atlas_intelligence' => $comparable ? $atlasShown : null,
                'delta_intelligence' => $delta,
                'uplift_status' => $upliftStatus,
                'comparable' => $comparable,
                'reason' => $comparable ? null : ($family['reason'] ?? ($upliftStatus === 'not_applicable' ? 'suite_not_in_uplift_families' : $upliftStatus)),
            ];
        }

        $avg = static fn (array $vals): ?float => $vals === [] ? null : round(array_sum($vals) / count($vals), 4);
        $pairsValid = count($barePaired);
        $pairsTotal = count((array) ($atlasUplift['families'] ?? []));

        $row = [
            'model_id' => $primaryModel,
            'rank' => 1,
            'bare_intelligence' => $avg($bareAll),
            'bare_suite_count' => count($bareAll),
            'atlas_intelligence' => $avg($atlasPaired),
            'bare_on_paired' => $avg($barePaired),
            'delta_intelligence' => ($avg($barePaired) !== null && $avg($atlasPaired) !== null)
                ? round((float) $avg($atlasPaired) - (float) $avg($barePaired), 4)
                : null,
            'pairs_valid' => $pairsValid,
            'pairs_total' => $pairsTotal,
            'pair_coverage' => $pairsTotal > 0 ? "{$pairsValid}/{$pairsTotal}" : '0/0',
            'per_suite' => $perSuite,
        ];

        return [
            'mode' => 'single_model_battery',
            'model_id' => $primaryModel,
            'face' => 'model_with_without_atlas',
            'rows' => [$row],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     * @return array{bare: ?float, atlas: ?float}
     */
    private function armScoresForSuite(string $suiteId, string $modelId, array $runs): array
    {
        $bare = [];
        $atlas = [];
        // Fail-closed on stale pollution: only the best pipeline_valid (else newest) run.
        $best = $this->bestRunForSuite($suiteId, $runs);
        $scoped = $best === null ? [] : [$best[1]];
        // Caller may pass a single preferred run (e.g. upliftFamilyRow) — honor that.
        if (count($runs) === 1 && (string) ($runs[0]['suite_id'] ?? '') === $suiteId) {
            $scoped = $runs;
        }
        foreach ($scoped as $run) {
            foreach ((array) (($run['report']['rows'] ?? []) ?: []) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $score = null;
                if (($row['intelligence_rate'] ?? null) !== null && is_numeric($row['intelligence_rate'])) {
                    $score = (float) $row['intelligence_rate'];
                } elseif (($row['success_rate_itt'] ?? null) !== null && is_numeric($row['success_rate_itt'])) {
                    // Legacy rows without intelligence_rate: only use ITT when env rate is zero/absent.
                    $env = $row['environment_failure_rate'] ?? null;
                    if ($env === null || (is_numeric($env) && (float) $env === 0.0)) {
                        $score = (float) $row['success_rate_itt'];
                    }
                }
                if ($score === null) {
                    continue;
                }
                $armId = (string) ($row['arm_id'] ?? '');
                if ($armId === $modelId.'@bare') {
                    $bare[] = $score;
                }
                if ($armId === $modelId.'@atlas_dev') {
                    $atlas[] = $score;
                }
            }
            // Receipts fallback when report rows omit intelligence_rate and env polluted ITT.
            $runId = (string) ($run['run_id'] ?? '');
            if ($runId !== '' && ($bare === [] || $atlas === [])) {
                $bareBits = [];
                $atlasBits = [];
                foreach (RunReceipt::loadAll($runId) as $receipt) {
                    if (($receipt->data['failure_class'] ?? null) === FailureClass::ENVIRONMENT) {
                        continue;
                    }
                    $armId = (string) ($receipt->data['arm_id'] ?? '');
                    $ok = ($receipt->data['status'] ?? null) === 'success' ? 1.0 : 0.0;
                    if ($armId === $modelId.'@bare') {
                        $bareBits[] = $ok;
                    }
                    if ($armId === $modelId.'@atlas_dev') {
                        $atlasBits[] = $ok;
                    }
                }
                if ($bare === [] && $bareBits !== []) {
                    $bare[] = round(array_sum($bareBits) / count($bareBits), 4);
                }
                if ($atlas === [] && $atlasBits !== []) {
                    $atlas[] = round(array_sum($atlasBits) / count($atlasBits), 4);
                }
            }
        }

        return [
            'bare' => $bare === [] ? null : round(array_sum($bare) / count($bare), 4),
            'atlas' => $atlas === [] ? null : round(array_sum($atlas) / count($atlas), 4),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $suiteRows
     * @param  array<string, mixed>  $atlasUplift
     * @param  array<string, int>  $counts
     * @return array<string, mixed>
     */
    private function buildMeasuredFacts(
        string $primaryModel,
        array $suiteRows,
        array $atlasUplift,
        array $counts,
    ): array {
        $measured = [];
        $incomplete = [];
        $better = 0;
        $worse = 0;

        foreach ((array) ($atlasUplift['families'] ?? []) as $family) {
            $name = self::familyLabel((string) ($family['family'] ?? $family['suite_id'] ?? '?'));
            if (($family['status'] ?? '') === 'real_uplift'
                && ($family['bare_intelligence'] ?? null) !== null
                && ($family['atlas_intelligence'] ?? null) !== null) {
                $delta = (float) ($family['delta_intelligence']
                    ?? ((float) $family['atlas_intelligence'] - (float) $family['bare_intelligence']));
                $pct = round($delta * 100, 1);
                $sign = $pct >= 0 ? '+' : '';
                $measured[] = "{$name}: sem Atlas "
                    .round((float) $family['bare_intelligence'] * 100, 1).'% → com Atlas '
                    .round((float) $family['atlas_intelligence'] * 100, 1)."% ({$sign}{$pct} pp)";
                if ($delta > 0) {
                    $better++;
                } elseif ($delta < 0) {
                    $worse++;
                }
            } else {
                $reason = (string) ($family['reason'] ?? $family['status'] ?? 'incomplete');
                $incomplete[] = "{$name}: comparação Atlas ainda inválida ({$reason})";
            }
        }

        foreach ($suiteRows as $row) {
            $status = (string) ($row['status'] ?? '');
            $suiteId = (string) ($row['suite_id'] ?? '?');
            if (in_array($status, ['missing_data', 'failed', 'blocked', 'not_run'], true)) {
                $fields = array_values((array) ($row['missing_fields'] ?? []));
                $suffix = $fields === [] ? '' : ' ('.implode(',', $fields).')';
                $incomplete[] = "{$suiteId}: suite status={$status}{$suffix}";
            } elseif (($row['tokens_coverage_incomplete'] ?? false) === true) {
                $incomplete[] = "{$suiteId}: tokens medidos com coverage parcial (env/harness omit em algumas units)";
            }
        }

        $ready = count(array_filter(
            (array) ($atlasUplift['families'] ?? []),
            fn (array $f): bool => ($f['status'] ?? '') === 'real_uplift',
        ));
        $total = count((array) ($atlasUplift['families'] ?? []));

        if ($ready === 0) {
            $headline = "{$primaryModel}: ainda sem pares bare×Atlas válidos ({$ready}/{$total}).";
        } elseif ($worse > $better) {
            $headline = "{$primaryModel}: nos {$ready} pares válidos, Atlas piorou mais vezes do que melhorou ({$worse}↓ / {$better}↑).";
        } elseif ($better > $worse) {
            $headline = "{$primaryModel}: nos {$ready} pares válidos, Atlas melhorou mais vezes do que piorou ({$better}↑ / {$worse}↓).";
        } else {
            $headline = "{$primaryModel}: nos {$ready} pares válidos, resultado misto ({$better}↑ / {$worse}↓).";
        }

        return [
            'headline' => $headline,
            'measured' => array_values(array_unique($measured)),
            'incomplete' => array_values(array_unique($incomplete)),
            'pairs_valid' => $ready,
            'pairs_total' => $total,
            'suites_ok' => (int) ($counts['ok'] ?? 0),
            'suites_missing_data' => (int) ($counts['missing_data'] ?? 0),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $suiteRows
     * @param  list<array<string, mixed>>  $runs
     * @return list<array<string, mixed>>
     */
    private function modelMatrixRows(array $suiteRows, array $runs): array
    {
        $bySuite = [];
        $suiteIds = array_values(array_unique(array_filter(array_map(
            fn (array $row): string => (string) ($row['suite_id'] ?? ''),
            $suiteRows,
        ))));
        foreach ($suiteIds as $suiteId) {
            $best = $this->bestRunForSuite($suiteId, $runs);
            if ($best === null) {
                continue;
            }
            $run = $best[1];
            $models = array_values(array_filter(
                (array) ($run['claim_scope']['models'] ?? []),
                fn ($model): bool => is_string($model) && $model !== '',
            ));
            if (count($models) < 2) {
                // Infer from report rows arm_ids when claim_scope is thin.
                $reportRows = (array) (($run['report']['rows'] ?? []) ?: []);
                $fromArms = [];
                foreach ($reportRows as $row) {
                    $armId = (string) ($row['arm_id'] ?? '');
                    if ($armId !== '' && str_contains($armId, '@bare')) {
                        $fromArms[explode('@', $armId, 2)[0]] = true;
                    }
                }
                $models = array_keys($fromArms);
            }
            if (count($models) < 2) {
                continue;
            }
            sort($models);
            $metrics = [];
            foreach ((array) (($run['report']['rows'] ?? []) ?: []) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $armId = (string) ($row['arm_id'] ?? '');
                if (! str_ends_with($armId, '@bare')) {
                    continue;
                }
                $modelId = explode('@', $armId, 2)[0];
                $intel = ($row['intelligence_rate'] ?? null);
                if ($intel === null && (($row['environment_failure_rate'] ?? 0) == 0)) {
                    $intel = $row['success_rate_itt'] ?? null;
                }
                $metrics[$modelId] = [
                    'success_rate_itt' => $row['success_rate_itt'] ?? null,
                    'intelligence_rate' => $intel,
                    'median_wall_ms' => $row['median_wall_ms'] ?? null,
                    'cost_per_task' => $row['cost_per_task'] ?? null,
                    'tokens_in_avg' => $row['avg_tokens_in'] ?? null,
                    'tokens_out_avg' => $row['avg_tokens_out'] ?? null,
                ];
            }
            $bySuite[$suiteId] = [
                'suite_id' => $suiteId,
                'run_id' => $run['run_id'],
                'models' => $models,
                'per_model' => $metrics,
                'status' => (($run['adjudication']['pipeline_valid'] ?? false) === true) ? 'ok' : 'failed',
            ];
        }

        return array_values($bySuite);
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     * @return array<string, mixed>
     */
    /**
     * Perfil legível por modelo: onde é forte/fraco no braço bare (por suíte
     * medida) e o que muda com Atlas (deltas das famílias de uplift). Deriva
     * SÓ do que foi medido — suíte sem dado fica fora, nunca vira zero.
     *
     * @param  array<string,mixed>  $dissections
     * @param  array<string,mixed>  $atlasUplift
     * @return list<array<string,mixed>>
     */
    /**
     * Agrega as suítes nas CAPACIDADES que elas medem — a visão principal.
     * Score bare = média das suítes CONFIÁVEIS da capacidade, ponderada por
     * unidades atribuíveis ao modelo. Atlas = média das suítes da capacidade
     * com par bare×Atlas medido. Eficiência (tokens/task, tempo/task) por
     * capacidade e global. Nada inventado: suíte não confiável fica fora do
     * score e é listada à parte.
     *
     * @param  list<array<string,mixed>>  $suiteRows
     * @param  array<string,mixed>  $atlasUplift
     * @return array<string,mixed>
     */
    private function buildCapabilityAggregates(array $suiteRows, array $atlasUplift, string $primaryModel): array
    {
        $bySuite = [];
        foreach ($suiteRows as $row) {
            if (is_array($row) && isset($row['suite_id'])) {
                $bySuite[(string) $row['suite_id']] = $row;
            }
        }
        // Atlas por suíte (via famílias de uplift): só o que foi realmente medido.
        $atlasBySuite = [];
        foreach ((array) ($atlasUplift['families'] ?? []) as $fam) {
            if (! is_array($fam) || ! isset($fam['suite_id'])) {
                continue;
            }
            if (is_numeric($fam['atlas_intelligence'] ?? null) && is_numeric($fam['bare_intelligence'] ?? null)) {
                $atlasBySuite[(string) $fam['suite_id']] = [
                    'atlas' => (float) $fam['atlas_intelligence'],
                    'bare' => (float) $fam['bare_intelligence'],
                    'delta' => (float) ($fam['delta_intelligence'] ?? ((float) $fam['atlas_intelligence'] - (float) $fam['bare_intelligence'])),
                    'diagnostic_only' => ($fam['diagnostic_only'] ?? false) === true,
                ];
            }
        }

        $capabilities = [];
        $globalTokens = [];
        $globalWall = [];
        foreach (self::CAPABILITIES as $capId => $cap) {
            $bareNum = 0.0;
            $bareDen = 0.0;
            $tokens = [];
            $walls = [];
            $reliableSuites = [];
            $unreliableSuites = [];
            $atlasNum = 0.0;
            $atlasDen = 0.0;
            $atlasBareNum = 0.0;
            $atlasSuites = [];
            $anyDiagnostic = false;

            foreach ($cap['suites'] as $suiteId) {
                $row = $bySuite[$suiteId] ?? null;
                if ($row === null) {
                    continue;
                }
                $ev = (array) ($row['execution_evidence'] ?? []);
                $weight = (float) ((($ev['blame_summary']['model_failures'] ?? 0) + ($ev['blame_summary']['successes'] ?? 0)) ?: 1);
                $score = $row['intelligence_rate'] ?? $row['success_rate_itt'] ?? null;

                if (($row['reliable'] ?? true) === true && is_numeric($score)) {
                    $reliableSuites[] = $suiteId;
                    $bareNum += (float) $score * $weight;
                    $bareDen += $weight;
                    if (is_numeric($row['tokens_per_task'] ?? null)) {
                        $tokens[] = (float) $row['tokens_per_task'];
                        $globalTokens[] = (float) $row['tokens_per_task'];
                    }
                    if (is_numeric($row['median_wall_ms'] ?? null)) {
                        $walls[] = (float) $row['median_wall_ms'];
                        $globalWall[] = (float) $row['median_wall_ms'];
                    }
                } elseif (($row['reliable'] ?? true) !== true) {
                    $unreliableSuites[] = ['suite_id' => $suiteId, 'reason' => $row['unreliable_reason'] ?? null];
                }

                // Atlas: só suítes desta capacidade com par medido.
                if (isset($atlasBySuite[$suiteId])) {
                    $a = $atlasBySuite[$suiteId];
                    $atlasNum += $a['atlas'] * $weight;
                    $atlasBareNum += $a['bare'] * $weight;
                    $atlasDen += $weight;
                    $atlasSuites[] = $suiteId;
                    $anyDiagnostic = $anyDiagnostic || $a['diagnostic_only'];
                }
            }

            $bareScore = $bareDen > 0 ? round($bareNum / $bareDen, 4) : null;
            $atlasScore = $atlasDen > 0 ? round($atlasNum / $atlasDen, 4) : null;
            $atlasBare = $atlasDen > 0 ? round($atlasBareNum / $atlasDen, 4) : null;
            $delta = ($atlasScore !== null && $atlasBare !== null) ? round($atlasScore - $atlasBare, 4) : null;

            $capabilities[] = [
                'id' => $capId,
                'label' => $cap['label'],
                'measures' => $cap['measures'],
                'suites_total' => count($cap['suites']),
                'suites_reliable' => count($reliableSuites),
                'reliable_suite_ids' => $reliableSuites,
                'unreliable_suites' => $unreliableSuites,
                'bare_intelligence' => $bareScore,
                'atlas_intelligence' => $atlasScore,
                'atlas_bare_baseline' => $atlasBare,
                'delta_intelligence' => $delta,
                'atlas_measured_on' => count($atlasSuites),
                'atlas_diagnostic_only' => $anyDiagnostic,
                'tokens_per_task' => $tokens === [] ? null : round(array_sum($tokens) / count($tokens)),
                'median_wall_ms' => $walls === [] ? null : round(array_sum($walls) / count($walls)),
            ];
        }

        return [
            'model_id' => $primaryModel,
            'schema' => 'capacidades = o que os benchmarks medem; suíte = instrumento (drill-down)',
            'capabilities' => $capabilities,
            'efficiency' => [
                'tokens_per_task_mean' => $globalTokens === [] ? null : round(array_sum($globalTokens) / count($globalTokens)),
                'median_wall_ms_mean' => $globalWall === [] ? null : round(array_sum($globalWall) / count($globalWall)),
                'cost_basis' => 'verboo_subscription_marginal',
                'note' => 'Custo marginal $0 (assinatura Verboo); eficiência real se lê em tokens/task e tempo/task.',
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $suiteRows
     * @return array<string,array{reliable:bool,reason:?string}>
     */
    private function suiteReliabilityMap(array $suiteRows): array
    {
        $map = [];
        foreach ($suiteRows as $row) {
            if (! is_array($row) || ! isset($row['suite_id'])) {
                continue;
            }
            $map[(string) $row['suite_id']] = [
                'reliable' => (bool) ($row['reliable'] ?? true),
                'reason' => $row['unreliable_reason'] ?? null,
            ];
        }

        return $map;
    }

    /**
     * @param  array<string,mixed>  $dissections
     * @param  array<string,mixed>  $atlasUplift
     * @param  array<string,array{reliable:bool,reason:?string}>  $reliability
     * @return list<array<string,mixed>>
     */
    private function buildModelProfiles(array $dissections, array $atlasUplift, array $reliability = []): array
    {
        $dissections['_suite_reliability'] = $reliability;
        $byModel = [];
        foreach ((array) ($dissections['models'] ?? []) as $entry) {
            if (! is_array($entry) || ($entry['present'] ?? false) !== true) {
                continue;
            }
            $byModel[(string) ($entry['model_id'] ?? '')][(string) ($entry['runtime'] ?? '')] = $entry;
        }

        $profiles = [];
        foreach ($byModel as $modelId => $runtimes) {
            $strengths = [];
            $weaknesses = [];
            $middle = [];
            $unreliable = [];
            // suite_rows carrega reliable/unreliable_reason; per_suite (dissecção)
            // não — cruzamos por suite_id para não julgar modelo em suíte que não terminou.
            $reliabilityBySuite = [];
            foreach ((array) ($dissections['_suite_reliability'] ?? []) as $suiteId => $rel) {
                $reliabilityBySuite[(string) $suiteId] = $rel;
            }
            foreach ((array) data_get($runtimes['bare'] ?? [], 'per_suite', []) as $suiteId => $row) {
                if (! is_array($row) || ($row['present'] ?? false) !== true
                    || ! is_numeric($row['success_rate_itt'] ?? null)) {
                    continue;
                }
                $rate = (float) $row['success_rate_itt'];
                $rel = $reliabilityBySuite[(string) $suiteId] ?? ['reliable' => true, 'reason' => null];
                $cell = [
                    'suite_id' => (string) $suiteId,
                    'success_rate_itt' => $rate,
                    'status' => (string) ($row['status'] ?? ''),
                    'category' => $row['category'] ?? null,
                    'reliable' => (bool) ($rel['reliable'] ?? true),
                    'unreliable_reason' => $rel['reason'] ?? null,
                ];
                // Execução incompleta/env-failure alta NÃO é fraqueza do modelo:
                // vai para um balde à parte, fora de forte/mediano/fraco.
                if (($rel['reliable'] ?? true) !== true) {
                    $unreliable[] = $cell;

                    continue;
                }
                match (true) {
                    $rate >= 0.5 => $strengths[] = $cell,
                    $rate <= 0.2 => $weaknesses[] = $cell,
                    default => $middle[] = $cell,
                };
            }
            usort($strengths, static fn (array $a, array $b): int => $b['success_rate_itt'] <=> $a['success_rate_itt']);
            usort($weaknesses, static fn (array $a, array $b): int => $a['success_rate_itt'] <=> $b['success_rate_itt']);

            $atlasDeltas = [];
            foreach ((array) ($atlasUplift['families'] ?? []) as $family) {
                if (! is_array($family)) {
                    continue;
                }
                $atlasDeltas[] = [
                    'family' => $family['family'] ?? null,
                    'suite_id' => $family['suite_id'] ?? null,
                    'status' => $family['status'] ?? null,
                    'diagnostic_only' => $family['diagnostic_only'] ?? null,
                    'bare_intelligence' => $family['bare_intelligence'] ?? null,
                    'atlas_intelligence' => $family['atlas_intelligence'] ?? null,
                    'delta_intelligence' => $family['delta_intelligence'] ?? null,
                ];
            }

            $fmt = static fn (array $cells): string => implode(', ', array_map(
                static fn (array $c): string => $c['suite_id'].' ('.round($c['success_rate_itt'] * 100).'%)',
                $cells,
            ));
            $measuredDeltas = array_values(array_filter(
                $atlasDeltas,
                static fn (array $f): bool => is_numeric($f['delta_intelligence'] ?? null),
            ));
            $deltaText = $measuredDeltas === []
                ? 'sem par bare×atlas provado ainda'
                : implode('; ', array_map(
                    static fn (array $f): string => $f['suite_id'].' '
                        .(($f['delta_intelligence'] >= 0 ? '+' : '').round($f['delta_intelligence'] * 100).'pp'
                        .(($f['diagnostic_only'] ?? false) === true ? ' (diagnóstico)' : '')),
                    $measuredDeltas,
                ));

            $unreliableText = $unreliable === []
                ? ''
                : ' NÃO CONFIÁVEL (execução incompleta/ambiente, não julga o modelo): '.implode(', ', array_map(
                    static fn (array $c): string => $c['suite_id'].' ['.($c['unreliable_reason'] ?? 'unreliable').']',
                    $unreliable,
                )).'.';

            $profiles[] = [
                'model_id' => $modelId,
                'runtimes_measured' => array_keys($runtimes),
                'strengths' => array_slice($strengths, 0, 5),
                'middle' => $middle,
                'weaknesses' => array_slice($weaknesses, 0, 5),
                'unreliable' => $unreliable,
                'atlas_deltas' => $atlasDeltas,
                'narrative' => sprintf(
                    '%s bare (só suítes confiáveis): forte em %s; mediano em %s; fraco em %s. Com Atlas: %s.%s',
                    $modelId,
                    $strengths !== [] ? $fmt($strengths) : 'nenhuma suíte confiável ≥50%',
                    $middle !== [] ? $fmt($middle) : '—',
                    $weaknesses !== [] ? $fmt($weaknesses) : 'nenhuma suíte confiável ≤20%',
                    $deltaText,
                    $unreliableText,
                ),
            ];
        }

        return $profiles;
    }

    private function upliftFamilyRow(string $family, string $suiteId, array $runs, string $primaryModel = 'verboo_kimi_k2_7'): array
    {
        $candidates = array_values(array_filter(
            $runs,
            fn (array $run): bool => ($run['suite_id'] ?? null) === $suiteId && is_array($run['uplift'] ?? null),
        ));
        usort($candidates, function (array $a, array $b): int {
            $aValid = (($a['adjudication']['pipeline_valid'] ?? false) === true) ? 1 : 0;
            $bValid = (($b['adjudication']['pipeline_valid'] ?? false) === true) ? 1 : 0;
            if ($aValid !== $bValid) {
                return $bValid <=> $aValid;
            }

            return strcmp((string) $b['run_id'], (string) $a['run_id']);
        });
        foreach ($candidates as $run) {
            $uplift = $run['uplift'] ?? null;
            if (! is_array($uplift)) {
                continue;
            }
            $kind = (string) ($uplift['uplift_kind'] ?? 'unsupported');
            $status = $kind === 'real_uplift' ? 'real_uplift' : (string) ($uplift['uplift_kind'] ?? 'unsupported');
            $reason = isset($uplift['reason']) ? (string) $uplift['reason'] : null;

            $bareIntel = null;
            $atlasIntel = null;
            $delta = null;
            $deltas = (array) ($uplift['deltas'] ?? []);
            if ($deltas !== [] && is_array($deltas[0] ?? null)) {
                $d0 = $deltas[0];
                if (isset($d0['base']['success_rate']) && is_numeric($d0['base']['success_rate'])) {
                    $bareIntel = round((float) $d0['base']['success_rate'], 4);
                }
                if (isset($d0['atlas']['success_rate']) && is_numeric($d0['atlas']['success_rate'])) {
                    $atlasIntel = round((float) $d0['atlas']['success_rate'], 4);
                }
                if (isset($d0['delta_success_rate']) && is_numeric($d0['delta_success_rate'])) {
                    $delta = round((float) $d0['delta_success_rate'], 4);
                }
            }

            $armScores = $this->armScoresForSuite($suiteId, $primaryModel, [$run]);
            $bareIntel ??= $armScores['bare'];
            if ($status === 'real_uplift') {
                $atlasIntel ??= $armScores['atlas'];
                if ($delta === null && $bareIntel !== null && $atlasIntel !== null) {
                    $delta = round($atlasIntel - $bareIntel, 4);
                }
            } else {
                // Unsupported: never publish atlas score as comparable fact (avoids 0% falso).
                $atlasIntel = null;
                $delta = null;
            }

            $excluded = array_values(array_map('strval', (array) ($uplift['excluded_pair_keys'] ?? [])));
            $provenPairCount = (int) ($uplift['proven_pair_count'] ?? 0);
            $diagnosticOnly = $status === 'real_uplift' && $excluded !== [];

            return [
                'family' => $family,
                'label' => self::familyLabel($family),
                'suite_id' => $suiteId,
                'run_id' => $run['run_id'],
                'status' => $status,
                'reason' => $reason,
                'uplift_supported' => (bool) ($uplift['uplift_supported'] ?? false),
                'comparable' => $status === 'real_uplift' && $bareIntel !== null && $atlasIntel !== null && ! $diagnosticOnly,
                'diagnostic_only' => $diagnosticOnly,
                'proven_pair_count' => $provenPairCount,
                'excluded_pair_keys' => $excluded,
                'bare_intelligence' => $bareIntel,
                'atlas_intelligence' => $atlasIntel,
                'delta_intelligence' => $delta,
                'internal_claim_allowed' => (bool) ($uplift['internal_claim_allowed'] ?? $uplift['claim_allowed'] ?? false),
                'stop_the_line' => (bool) ($uplift['stop_the_line'] ?? false),
            ];
        }

        return [
            'family' => $family,
            'label' => self::familyLabel($family),
            'suite_id' => $suiteId,
            'run_id' => null,
            'status' => 'not_run',
            'reason' => 'not_run',
            'uplift_supported' => false,
            'comparable' => false,
            'diagnostic_only' => false,
            'proven_pair_count' => 0,
            'excluded_pair_keys' => [],
            'bare_intelligence' => null,
            'atlas_intelligence' => null,
            'delta_intelligence' => null,
            'internal_claim_allowed' => false,
            'stop_the_line' => false,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private function fullMetricsFromRows(array $rows): ?array
    {
        if ($rows === []) {
            return null;
        }

        $pick = static function (array $row, string $key): mixed {
            return $row[$key] ?? null;
        };

        $first = $rows[0];
        $dimensions = [];
        $failureClasses = [];
        $wilson = null;
        $stabilities = [];
        $p95Walls = [];
        $avgWalls = [];
        $tokenCoverages = [];
        $realities = [];
        $patchBloats = [];

        foreach ($rows as $row) {
            if (is_array($row['dimensions'] ?? null)) {
                foreach ($row['dimensions'] as $dim => $value) {
                    if (is_numeric($value)) {
                        $dimensions[(string) $dim][] = (float) $value;
                    }
                }
            }
            foreach ((array) ($row['failure_classes'] ?? []) as $cls => $count) {
                $failureClasses[(string) $cls] = ($failureClasses[(string) $cls] ?? 0) + (int) $count;
            }
            if ($wilson === null && is_array($row['success_rate_wilson_95'] ?? null)) {
                $wilson = $row['success_rate_wilson_95'];
            }
            if (isset($row['stability']) && is_numeric($row['stability'])) {
                $stabilities[] = (float) $row['stability'];
            }
            if (isset($row['p95_wall_ms']) && is_numeric($row['p95_wall_ms'])) {
                $p95Walls[] = (float) $row['p95_wall_ms'];
            }
            if (isset($row['avg_wall_ms']) && is_numeric($row['avg_wall_ms'])) {
                $avgWalls[] = (float) $row['avg_wall_ms'];
            }
            if (is_array($row['tokens_coverage'] ?? null)) {
                $tokenCoverages[] = $row['tokens_coverage'];
            }
            if (isset($row['reality'])) {
                $realities[] = $row['reality'];
            }
            if (isset($row['avg_patch_bloat']) && is_numeric($row['avg_patch_bloat'])) {
                $patchBloats[] = (float) $row['avg_patch_bloat'];
            }
        }

        $dimMeans = [];
        foreach ($dimensions as $dim => $values) {
            $dimMeans[$dim] = round(array_sum($values) / count($values), 6);
        }

        return $this->enrichTokenThroughput([
            'task_types' => array_values(array_unique(array_filter(array_map(
                fn (array $row): string => (string) ($row['task_type'] ?? ''),
                $rows,
            )))),
            'arm_ids' => array_values(array_unique(array_filter(array_map(
                fn (array $row): string => (string) ($row['arm_id'] ?? ''),
                $rows,
            )))),
            'n' => array_sum(array_map(fn (array $row): int => (int) ($row['n'] ?? 0), $rows)),
            'planned_attempts' => array_sum(array_map(fn (array $row): int => (int) ($row['planned_attempts'] ?? 0), $rows)),
            'observed_attempts' => array_sum(array_map(fn (array $row): int => (int) ($row['observed_attempts'] ?? 0), $rows)),
            'valid_results' => array_sum(array_map(fn (array $row): int => (int) ($row['valid_results'] ?? 0), $rows)),
            'successes' => array_sum(array_map(fn (array $row): int => (int) ($row['successes'] ?? 0), $rows)),
            'success_rate_itt' => $this->meanNullable(array_column($rows, 'success_rate_itt')),
            'success_rate' => $this->meanNullable(array_column($rows, 'success_rate')),
            'success_rate_valid_results' => $this->meanNullable(array_column($rows, 'success_rate_valid_results')),
            'success_rate_wilson_95' => $wilson,
            'environment_failure_rate' => $this->meanNullable(array_column($rows, 'environment_failure_rate')),
            'failure_classes' => $failureClasses,
            'total_cost_usd' => $this->sumNullable(array_column($rows, 'total_cost_usd')),
            'avg_cost_usd' => $this->meanNullable(array_column($rows, 'avg_cost_usd')),
            'cost_per_task' => $this->meanNullable(array_column($rows, 'cost_per_task')),
            'median_cost_usd' => $this->meanNullable(array_column($rows, 'median_cost_usd')),
            'p95_cost_usd' => $this->meanNullable(array_column($rows, 'p95_cost_usd')),
            'median_cost_ci_95' => $pick($first, 'median_cost_ci_95'),
            'avg_tokens_in' => $this->meanNullable(array_column($rows, 'avg_tokens_in')),
            'avg_tokens_out' => $this->meanNullable(array_column($rows, 'avg_tokens_out')),
            'total_tokens_in' => $this->sumNullable(array_column($rows, 'total_tokens_in')),
            'total_tokens_out' => $this->sumNullable(array_column($rows, 'total_tokens_out')),
            'total_tokens' => $this->sumNullable(array_column($rows, 'total_tokens')),
            'tokens_per_task' => $this->meanNullable(array_column($rows, 'tokens_per_task')),
            'avg_tokens_per_task' => $this->meanNullable(array_column($rows, 'avg_tokens_per_task')),
            'tokens_in_per_task' => $this->meanNullable(array_column($rows, 'tokens_in_per_task')),
            'tokens_out_per_task' => $this->meanNullable(array_column($rows, 'tokens_out_per_task')),
            'tokens_per_second' => $this->meanNullable(array_column($rows, 'tokens_per_second')),
            'tokens_per_second_aggregate' => $this->meanNullable(array_column($rows, 'tokens_per_second_aggregate')),
            'tokens_in_per_second' => $this->meanNullable(array_column($rows, 'tokens_in_per_second')),
            'tokens_out_per_second' => $this->meanNullable(array_column($rows, 'tokens_out_per_second')),
            'cost_per_1k_tokens' => $this->meanNullable(array_column($rows, 'cost_per_1k_tokens')),
            'tokens_coverage' => $tokenCoverages[0] ?? null,
            'avg_wall_ms' => $avgWalls === [] ? null : round(array_sum($avgWalls) / count($avgWalls), 6),
            'median_wall_ms' => $this->meanNullable(array_column($rows, 'median_wall_ms')),
            'median_wall_sec' => $this->meanNullable(array_column($rows, 'median_wall_sec')),
            'p95_wall_ms' => $p95Walls === [] ? null : round(array_sum($p95Walls) / count($p95Walls), 6),
            'median_wall_ci_95' => $pick($first, 'median_wall_ci_95'),
            'stability' => $stabilities === [] ? null : round(array_sum($stabilities) / count($stabilities), 4),
            'dimensions' => $dimMeans === [] ? null : $dimMeans,
            'avg_patch_bloat' => $patchBloats === [] ? null : round(array_sum($patchBloats) / count($patchBloats), 6),
            'reality' => $realities[0] ?? null,
            'per_arm' => array_map(function (array $row): array {
                return $this->enrichTokenThroughput([
                    'task_type' => $row['task_type'] ?? null,
                    'arm_id' => $row['arm_id'] ?? null,
                    'success_rate_itt' => $row['success_rate_itt'] ?? null,
                    'success_rate_wilson_95' => $row['success_rate_wilson_95'] ?? null,
                    'cost_per_task' => $row['cost_per_task'] ?? null,
                    'cost_per_1k_tokens' => $row['cost_per_1k_tokens'] ?? null,
                    'total_cost_usd' => $row['total_cost_usd'] ?? null,
                    'median_wall_ms' => $row['median_wall_ms'] ?? null,
                    'median_wall_sec' => $row['median_wall_sec'] ?? null,
                    'p95_wall_ms' => $row['p95_wall_ms'] ?? null,
                    'avg_tokens_in' => $row['avg_tokens_in'] ?? null,
                    'avg_tokens_out' => $row['avg_tokens_out'] ?? null,
                    'total_tokens_in' => $row['total_tokens_in'] ?? null,
                    'total_tokens_out' => $row['total_tokens_out'] ?? null,
                    'total_tokens' => $row['total_tokens'] ?? null,
                    'tokens_per_task' => $row['tokens_per_task'] ?? null,
                    'avg_tokens_per_task' => $row['avg_tokens_per_task'] ?? null,
                    'tokens_in_per_task' => $row['tokens_in_per_task'] ?? null,
                    'tokens_out_per_task' => $row['tokens_out_per_task'] ?? null,
                    'tokens_per_second' => $row['tokens_per_second'] ?? null,
                    'tokens_per_second_aggregate' => $row['tokens_per_second_aggregate'] ?? null,
                    'tokens_in_per_second' => $row['tokens_in_per_second'] ?? null,
                    'tokens_out_per_second' => $row['tokens_out_per_second'] ?? null,
                    'tokens_coverage' => $row['tokens_coverage'] ?? null,
                    'stability' => $row['stability'] ?? null,
                    'environment_failure_rate' => $row['environment_failure_rate'] ?? null,
                    'failure_classes' => $row['failure_classes'] ?? [],
                    'dimensions' => $row['dimensions'] ?? null,
                    'n' => $row['n'] ?? null,
                    'successes' => $row['successes'] ?? null,
                ]);
            }, $rows),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function harvestNativeSignals(string $suiteId, string $runId): array
    {
        $dir = RunPaths::runDir($runId).'/external_results/units';
        if (! is_dir($dir)) {
            return [];
        }

        $out = [];
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $file) {
            if (! str_ends_with($file, '.json')) {
                continue;
            }
            $payload = json_decode((string) file_get_contents($dir.'/'.$file), true);
            if (! is_array($payload)) {
                continue;
            }
            foreach ($this->extractNativeRows($suiteId, $payload) as $row) {
                $row['_unit_file'] = $file;
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function extractNativeRows(string $suiteId, array $payload): array
    {
        return match ($suiteId) {
            'tau2_bench' => array_map(static function (array $sim): array {
                $usage = (array) ($sim['usage'] ?? []);

                return [
                    'simulation_id' => $sim['simulation_id'] ?? null,
                    'task_id' => $sim['task_id'] ?? null,
                    'reward' => $sim['reward'] ?? null,
                    'termination_reason' => $sim['termination_reason'] ?? null,
                    'duration_sec' => $sim['duration_sec'] ?? null,
                    'tokens_in' => $usage['input_tokens'] ?? null,
                    'tokens_out' => $usage['output_tokens'] ?? null,
                    'cost_usd' => $usage['cost_usd'] ?? null,
                ];
            }, array_values(array_filter((array) ($payload['simulations'] ?? []), 'is_array'))),
            'bfcl' => array_map(static fn (array $row): array => [
                'case_id' => $row['case_id'] ?? null,
                'test_category' => $row['test_category'] ?? null,
                'native_category' => $row['native_category'] ?? null,
                'accuracy' => $row['accuracy'] ?? null,
                'status' => $row['status'] ?? null,
                'duration_sec' => $row['duration_sec'] ?? null,
                'tokens_in' => $row['tokens_in'] ?? null,
                'tokens_out' => $row['tokens_out'] ?? null,
                'cost_usd' => $row['cost_usd'] ?? null,
                'field_presence' => $row['field_presence'] ?? null,
            ], array_values(array_filter((array) ($payload['results'] ?? []), 'is_array'))),
            'terminal_bench' => array_map(static fn (array $ep): array => [
                'episode_id' => $ep['episode_id'] ?? null,
                'exit_status' => $ep['exit_status'] ?? null,
                'failure_mode' => $ep['failure_mode'] ?? null,
                'duration_sec' => $ep['duration_sec'] ?? null,
                'input_tokens' => $ep['input_tokens'] ?? null,
                'output_tokens' => $ep['output_tokens'] ?? null,
                'cost_usd' => $ep['cost_usd'] ?? null,
                'field_presence' => $ep['field_presence'] ?? null,
            ], array_values(array_filter((array) ($payload['episodes'] ?? []), 'is_array'))),
            'senior_swe_bench' => array_map(static function (array $task) use ($payload): array {
                return [
                    'task_id' => $task['task_id'] ?? null,
                    'task' => $task['task'] ?? null,
                    'resolved' => $task['resolved'] ?? null,
                    'exception_info' => $task['exception_info'] ?? null,
                    'duration_seconds' => $task['duration_seconds'] ?? null,
                    'usage' => $task['usage'] ?? null,
                    'verdicts' => $task['verdicts'] ?? null,
                    'judge_config' => $payload['judge_config'] ?? null,
                    'coverage' => $payload['coverage'] ?? null,
                ];
            }, array_values(array_filter((array) ($payload['tasks'] ?? []), 'is_array'))),
            'swe_bench_live' => array_map(static fn (array $inst): array => [
                'instance_id' => $inst['instance_id'] ?? null,
                'resolved' => $inst['resolved'] ?? null,
                'eval_status' => $inst['eval_status'] ?? null,
                'duration_sec' => $inst['duration_sec'] ?? null,
                'usage' => $inst['usage'] ?? null,
                'model_name_or_path' => $inst['model_name_or_path'] ?? null,
            ], array_values(array_filter((array) ($payload['instances'] ?? []), 'is_array'))),
            'live_code_bench' => array_map(static fn (array $row): array => [
                'question_id' => $row['question_id'] ?? ($row['native_question_id'] ?? null),
                'pass@1' => $row['pass@1'] ?? null,
                'graded_list' => $row['graded_list'] ?? null,
                'difficulty' => $row['difficulty'] ?? null,
                'platform' => $row['platform'] ?? null,
                'contest_id' => $row['contest_id'] ?? null,
                'usage_capture' => $row['usage_capture'] ?? null,
                'tokens_in' => $row['tokens_in'] ?? null,
                'tokens_out' => $row['tokens_out'] ?? null,
                'duration_sec' => $row['duration_sec'] ?? null,
            ], array_values(array_filter((array) ($payload['results'] ?? []), 'is_array'))),
            'inspect_evals' => array_map(static function (array $sample) use ($payload): array {
                return [
                    'sample_id' => $sample['id'] ?? null,
                    'scores' => $sample['scores'] ?? null,
                    'total_time' => $sample['total_time'] ?? null,
                    'working_time' => $sample['working_time'] ?? null,
                    'model_usage' => $sample['model_usage'] ?? null,
                    'completed' => $sample['completed'] ?? null,
                    'error' => $sample['error'] ?? null,
                    'retries' => $sample['retries'] ?? null,
                    'eval' => $payload['eval'] ?? null,
                ];
            }, array_values(array_filter((array) ($payload['samples'] ?? []), 'is_array'))),
            'hal_harness' => array_map(static fn (array $run): array => [
                'task_id' => $run['task_id'] ?? null,
                'success' => $run['success'] ?? null,
                'total_cost_usd' => $run['total_cost_usd'] ?? null,
                'latency_sec' => $run['latency_sec'] ?? null,
                'input_tokens' => $run['input_tokens'] ?? null,
                'output_tokens' => $run['output_tokens'] ?? null,
                'field_presence' => $run['field_presence'] ?? null,
                'runtime_bridge' => $run['runtime_bridge'] ?? null,
                'model' => $run['model'] ?? null,
                'agent' => $run['agent'] ?? null,
            ], array_values(array_filter((array) ($payload['runs'] ?? []), 'is_array'))),
            'aider_polyglot' => array_map(static fn (array $row): array => [
                'testcase' => $row['testcase'] ?? null,
                'language' => $row['language'] ?? null,
                'tries' => $row['tries'] ?? null,
                'tests_outcomes' => $row['tests_outcomes'] ?? null,
                'duration' => $row['duration'] ?? null,
                'cost' => $row['cost'] ?? null,
                'sent_tokens' => $row['sent_tokens'] ?? null,
                'received_tokens' => $row['received_tokens'] ?? null,
            ], array_values(array_filter((array) ($payload['results'] ?? []), 'is_array'))),
            'swe_marathon' => array_map(static fn (array $task): array => [
                'task_id' => $task['task_id'] ?? null,
                'resolved' => $task['resolved'] ?? null,
                'exception_info' => $task['exception_info'] ?? null,
                'duration_seconds' => $task['duration_seconds'] ?? null,
                'usage' => $task['usage'] ?? null,
                'agent' => $task['agent'] ?? null,
                'model' => $task['model'] ?? null,
            ], array_values(array_filter((array) ($payload['tasks'] ?? []), 'is_array'))),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $delivery
     * @return list<string>
     */
    private function caseIdsForRun(string $runId, array $meta, array $delivery): array
    {
        $planPath = RunPaths::planPath($runId);
        if (is_file($planPath)) {
            $plan = json_decode((string) file_get_contents($planPath), true) ?? [];
            $fromPlan = array_values(array_filter(
                array_map('strval', (array) ($plan['case_ids'] ?? [])),
                fn (string $id): bool => $id !== '',
            ));
            if ($fromPlan !== []) {
                return $fromPlan;
            }
        }
        $fromScope = array_values(array_filter(
            array_map('strval', (array) ($meta['claim_scope']['cases'] ?? [])),
            fn (string $id): bool => $id !== '',
        ));
        if ($fromScope !== []) {
            return $fromScope;
        }

        return array_values(array_map('strval', (array) ($delivery['fase_a_case_pack'] ?? [])));
    }

    /** @return array<string, mixed> */
    private function runArtifacts(string $runId): array
    {
        $dir = RunPaths::runDir($runId);
        $map = [
            'report_json' => $dir.'/report.json',
            'report_md' => $dir.'/report.md',
            'report_csv' => $dir.'/report.csv',
            'adjudication_json' => $dir.'/adjudication.json',
            'evidence_pack_json' => $dir.'/evidence_pack.json',
            'plan_json' => $dir.'/plan.json',
            'native_execution_manifest_json' => $dir.'/native_execution_manifest.json',
            'receipts_jsonl' => $dir.'/receipts.jsonl',
            'events_jsonl' => RunPaths::eventsPath($runId),
            'uplift_json' => $dir.'/uplift.json',
        ];
        $out = [];
        foreach ($map as $key => $path) {
            $out[$key] = [
                'path' => $path,
                'present' => is_file($path),
                'bytes' => is_file($path) ? filesize($path) : null,
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $signals
     * @return list<string>
     */
    private function flattenObservedKeys(array $signals): array
    {
        $keys = [];
        foreach ($signals as $signal) {
            foreach (array_keys($signal) as $key) {
                if ($key === '_unit_file') {
                    continue;
                }
                $value = $signal[$key];
                if ($value === null) {
                    continue;
                }
                $keys[$key] = true;
                if (is_array($value) && ! array_is_list($value)) {
                    foreach (array_keys($value) as $child) {
                        $keys[$key.'.'.$child] = true;
                    }
                }
            }
        }
        $list = array_keys($keys);
        sort($list);

        return $list;
    }

    /**
     * @param  array<string, mixed>  $delivery
     * @param  list<string>  $observedNative
     * @param  list<string>  $observedReport
     * @return array<string, mixed>
     */
    private function deliveryCoverage(array $delivery, array $observedNative, array $observedReport): array
    {
        $expectedNative = array_values(array_map('strval', (array) ($delivery['native_metrics'] ?? [])));
        $expectedReport = array_values(array_map('strval', (array) ($delivery['atlas_report_metrics'] ?? [])));
        $nativeHit = [];
        $nativeMiss = [];
        foreach ($expectedNative as $metric) {
            $base = explode('.', $metric, 2)[0];
            $hit = in_array($metric, $observedNative, true)
                || in_array($base, $observedNative, true)
                || ($base !== $metric && str_starts_with($metric, $base.'.') && in_array($base, $observedNative, true))
                || ($metric === 'verdicts.*' && count(array_filter($observedNative, fn (string $k): bool => str_starts_with($k, 'verdicts'))) > 0)
                || ($metric === 'scores' && in_array('scores', $observedNative, true));
            // wildcard / nested tolerance
            if (! $hit) {
                foreach ($observedNative as $obs) {
                    if ($obs === $base || str_starts_with($obs, $base.'.') || str_starts_with($metric, $obs)) {
                        $hit = true;
                        break;
                    }
                    if (str_ends_with($metric, '.*') && str_starts_with($obs, substr($metric, 0, -1))) {
                        $hit = true;
                        break;
                    }
                }
            }
            if ($hit) {
                $nativeHit[] = $metric;
            } else {
                $nativeMiss[] = $metric;
            }
        }
        $reportHit = array_values(array_intersect($expectedReport, $observedReport));
        $reportMiss = array_values(array_diff($expectedReport, $observedReport));

        return [
            'native_expected' => count($expectedNative),
            'native_observed' => count($nativeHit),
            'native_missing' => $nativeMiss,
            'report_expected' => count($expectedReport),
            'report_observed' => count($reportHit),
            'report_missing' => $reportMiss,
            'dimensions_expected' => array_values((array) ($delivery['capability_dimensions'] ?? [])),
            'uplift_eligible' => (bool) ($delivery['uplift_eligible'] ?? false),
            'uplift_family' => $delivery['uplift_family'] ?? null,
        ];
    }

    /** @param list<mixed> $values */
    private function meanNullable(array $values): ?float
    {
        $nums = array_values(array_filter($values, 'is_numeric'));
        if ($nums === []) {
            return null;
        }

        return round(array_sum(array_map('floatval', $nums)) / count($nums), 6);
    }

    /** @param list<mixed> $values */
    private function sumNullable(array $values): ?float
    {
        $nums = array_values(array_filter($values, 'is_numeric'));
        if ($nums === []) {
            return null;
        }

        return round(array_sum(array_map('floatval', $nums)), 6);
    }

    /** @param list<float> $values */
    private function median(array $values): float
    {
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);
        if ($n % 2 === 1) {
            return round($values[$mid], 6);
        }

        return round(($values[$mid - 1] + $values[$mid]) / 2, 6);
    }

    /** @param array<string, mixed> $report */
    private function csv(array $report): string
    {
        $handle = fopen('php://temp', 'w+');
        fputcsv($handle, [
            'suite_id',
            'category',
            'status',
            'run_id',
            'success_rate_itt',
            'intelligence_rate',
            'median_wall_ms',
            'p95_wall_ms',
            'tokens_in_avg',
            'tokens_out_avg',
            'tokens_per_task',
            'tokens_per_second',
            'total_tokens',
            'cost_per_1k_tokens',
            'cost_per_task',
            'cost_basis',
            'env_failure_rate',
            'stability',
            'uplift_family',
            'native_coverage',
            'report_coverage',
            'pipeline_valid',
            'internal_claim_allowed',
            'events_complete',
            'is_atlas_fact',
            'measurement_status',
            'missing_fields',
            'case_ids',
        ]);
        foreach ($report['suite_rows'] as $row) {
            $full = (array) ($row['full_metrics'] ?? []);
            $cov = (array) ($row['delivery_coverage'] ?? []);
            $axes = (array) ($row['axes'] ?? []);
            fputcsv($handle, [
                $row['suite_id'],
                $row['category'] ?? ($row['delivery']['category'] ?? ''),
                $row['status'],
                $row['run_id'],
                $row['success_rate_itt'],
                $row['intelligence_rate'] ?? null,
                $row['median_wall_ms'],
                $full['p95_wall_ms'] ?? null,
                $row['tokens_in_avg'],
                $row['tokens_out_avg'],
                $row['tokens_per_task'] ?? ($full['tokens_per_task'] ?? null),
                $row['tokens_per_second'] ?? ($full['tokens_per_second'] ?? null),
                $row['total_tokens'] ?? ($full['total_tokens'] ?? null),
                $row['cost_per_1k_tokens'] ?? ($full['cost_per_1k_tokens'] ?? null),
                $row['cost_per_task'],
                $row['cost_basis'],
                $row['env_failure_rate'],
                $full['stability'] ?? null,
                $row['delivery']['uplift_family'] ?? null,
                ($cov['native_observed'] ?? 0).'/'.($cov['native_expected'] ?? 0),
                ($cov['report_observed'] ?? 0).'/'.($cov['report_expected'] ?? 0),
                ($row['pipeline_valid'] ?? false) ? 'true' : 'false',
                ($row['internal_claim_allowed'] ?? false) ? 'true' : 'false',
                ($row['events_complete'] ?? false) ? 'true' : 'false',
                ($row['is_atlas_fact'] ?? false) ? 'true' : 'false',
                $axes['measurement']['status'] ?? '',
                implode('|', (array) ($row['missing_fields'] ?? [])),
                implode('|', (array) ($row['case_ids'] ?? [])),
            ]);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return (string) $csv;
    }

    private function eventsCompleteForRun(string $runId): bool
    {
        return EventsLifecycleContract::isClaimGradeComplete(
            RunPaths::eventsPath($runId),
        );
    }

    /**
     * @param  list<string>  $missing
     */
    private function measurementStatus(array $missing, bool $coverageIncomplete, string $suiteId, ?string $runId = null): string
    {
        if ($this->harnessOmitsUsage($suiteId, $runId, $missing)) {
            return 'harness_omit';
        }
        if ($missing === [] && ! $coverageIncomplete) {
            return 'complete';
        }
        if ($missing !== [] && $coverageIncomplete === false) {
            return 'omitted';
        }
        if ($coverageIncomplete) {
            return 'partial';
        }

        return $missing === [] ? 'complete' : 'omitted';
    }

    /**
     * @param  list<string>  $missing
     */
    private function harnessOmitsUsage(string $suiteId, ?string $runId, array $missing): bool
    {
        if ($suiteId === 'inspect_evals' && $missing !== []) {
            return true;
        }
        if ($runId === null || $missing === []) {
            return false;
        }
        foreach (RunReceipt::loadAll($runId) as $receipt) {
            $presence = (array) ($receipt->data['field_presence'] ?? []);
            foreach (['tokens_in', 'tokens_out', 'cost_usd'] as $field) {
                $reason = (string) (($presence[$field]['reason'] ?? '') ?: '');
                if ($reason !== '' && preg_match('/omit|harness_omit|inspect_logs_omit/i', $reason) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Ledger de execução por unidade: a evidência que separa "modelo errou" de
     * "teste/ambiente falhou ou não terminou". Cada unidade carrega status,
     * failure_class, exit code, wall_ms e — para as que NÃO deram success — o
     * tail do stderr do log nativo (o "porquê"). Somado a isso, um veredito de
     * confiabilidade: env-failure alta ou unidades faltando ⇒ suíte não_confiável
     * (não conta como fraqueza do modelo).
     *
     * @return array<string,mixed>
     */
    private function executionEvidenceForRun(string $runId, int $unitsExpected = 0): array
    {
        $receipts = RunReceipt::loadAll($runId);

        // exit_code + log tail por unidade nativa, indexado pelo prefixo do
        // expected_result_path (<case>__<arm>__r<rep>__<hash>.json).
        $native = [];
        foreach (NativeExecutionReceipt::loadAll($runId) as $nr) {
            $file = basename((string) ($nr->data['expected_result_path'] ?? ''));
            $key = preg_replace('/__[0-9a-f]+\.json$/', '', $file) ?: $file;
            $logDir = RunPaths::nativeReceiptsDir($runId).'/logs';
            $stderrPath = $logDir.'/'.($nr->data['execution_id'] ?? '').'.stderr.log';
            $native[$key] = [
                'execution_id' => $nr->data['execution_id'] ?? null,
                'exit_code' => $nr->data['exit_code'] ?? null,
                'exit_nonzero_promoted' => (bool) ($nr->data['exit_nonzero_promoted'] ?? false),
                'wall_ms' => $nr->data['wall_ms'] ?? null,
                'stderr_path' => is_file($stderrPath) ? $stderrPath : null,
            ];
        }

        $units = [];
        $classes = ['success' => 0, 'model_failure' => 0, 'environment_failure' => 0, 'timeout' => 0, 'invalid_result' => 0, 'other' => 0];
        foreach ($receipts as $r) {
            $status = (string) ($r->data['status'] ?? '');
            $failureClass = (string) ($r->data['failure_class'] ?? '');
            $bucket = match (true) {
                $status === 'success' => 'success',
                $failureClass === FailureClass::ENVIRONMENT => 'environment_failure',
                $failureClass === 'model_failure' => 'model_failure',
                $failureClass === 'timeout', $status === 'timeout' => 'timeout',
                $failureClass === 'invalid_result' => 'invalid_result',
                default => 'other',
            };
            $classes[$bucket]++;
            $key = ($r->data['case_id'] ?? '').'__'.str_replace('@', '_', (string) ($r->data['arm_id'] ?? '')).'__r'.($r->data['repetition'] ?? '');
            $nat = $native[$key] ?? [];
            $stderrTail = null;
            if ($bucket !== 'success' && is_string($nat['stderr_path'] ?? null)) {
                $raw = (string) file_get_contents($nat['stderr_path']);
                $stderrTail = mb_substr(rtrim($raw), -800);
            }
            $units[] = [
                'case_id' => $r->data['case_id'] ?? null,
                'arm_id' => $r->data['arm_id'] ?? null,
                'repetition' => $r->data['repetition'] ?? null,
                'status' => $status,
                'failure_class' => $failureClass ?: null,
                'blame' => match ($bucket) {
                    'success' => 'success',
                    'model_failure', 'invalid_result' => 'model',
                    'environment_failure', 'timeout' => 'environment_or_flow',
                    default => 'unknown',
                },
                'exit_code' => $nat['exit_code'] ?? null,
                'exit_nonzero_promoted' => $nat['exit_nonzero_promoted'] ?? false,
                'wall_ms' => $r->data['wall_ms'] ?? ($nat['wall_ms'] ?? null),
                'stderr_log' => $nat['stderr_path'] ?? null,
                'stderr_tail' => $stderrTail,
            ];
        }

        $total = count($units);
        $envAndFlow = $classes['environment_failure'] + $classes['timeout'];
        $modelAttributable = $classes['success'] + $classes['model_failure'] + $classes['invalid_result'];
        $unitsMissing = $unitsExpected > 0 ? max(0, $unitsExpected - $total) : 0;
        $envRate = $total > 0 ? round($envAndFlow / $total, 4) : 0.0;
        // Confiável = dá para JULGAR O MODELO nesta suíte. O critério é COBERTURA
        // (fração de unidades com desfecho atribuível ao modelo), não o gate de
        // claim de 5%: uma suíte 17/18 model_failure é uma fraqueza real do
        // modelo; uma suíte 7/9 environment_failure é o teste que não rodou.
        $coverage = $total > 0 ? round($modelAttributable / $total, 4) : 0.0;
        $minCoverage = (float) config('atlas_rivals.report.min_model_coverage', 0.7);
        $reliable = $total > 0 && $unitsMissing === 0 && $coverage >= $minCoverage;
        $reason = match (true) {
            $total === 0 => 'no_units_recorded',
            $unitsMissing > 0 => 'units_missing:'.$unitsMissing.'_of_'.$unitsExpected,
            $coverage < $minCoverage => 'model_coverage_'.$coverage.'_below_'.$minCoverage.'_env_or_flow_ate_the_run',
            default => null,
        };

        return [
            'units_expected' => $unitsExpected,
            'units_recorded' => $total,
            'units_missing' => $unitsMissing,
            'class_counts' => $classes,
            'environment_or_flow_rate' => $envRate,
            'model_coverage' => $coverage,
            'reliable' => $reliable,
            'unreliable_reason' => $reason,
            'blame_summary' => [
                'model_failures' => $classes['model_failure'] + $classes['invalid_result'],
                'environment_or_flow_failures' => $envAndFlow,
                'successes' => $classes['success'],
            ],
            'units' => $units,
        ];
    }

    /**
     * Prefer report intelligence_rate; fall back to receipts (excludes environment_failure).
     */
    private function intelligenceFromReceipts(string $runId): ?float
    {
        $items = RunReceipt::loadAll($runId);
        if ($items === []) {
            return null;
        }
        $nonEnv = array_values(array_filter(
            $items,
            fn (RunReceipt $r): bool => ($r->data['failure_class'] ?? null) !== FailureClass::ENVIRONMENT,
        ));
        if ($nonEnv === []) {
            return null;
        }
        $successes = count(array_filter(
            $nonEnv,
            fn (RunReceipt $r): bool => ($r->data['status'] ?? null) === 'success',
        ));

        return round($successes / count($nonEnv), 4);
    }

    /** @param array<string, mixed> $payload */
    public static function hashPayload(array $payload): string
    {
        unset($payload['report_hash']);

        return hash('sha256', json_encode(
            self::canonicalize($payload),
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonicalize(...), $value);
    }
}
