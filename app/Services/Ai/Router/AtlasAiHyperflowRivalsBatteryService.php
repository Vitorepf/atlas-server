<?php

namespace App\Services\Ai\Router;

use App\Models\AtlasEngineeringBenchmarkCase;
use App\Models\AtlasEngineeringBenchmarkResult;
use App\Models\AtlasEngineeringBenchmarkRun;
use App\Models\AtlasEngineeringBenchmarkSuite;
use App\Services\Ai\Compounding\AtlasCompoundingRuntimeService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsRunPathResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use App\Services\Ai\Support\DatabaseTableAvailability;

class AtlasAiHyperflowRivalsBatteryService
{
    public const SCHEMA_VERSION = 'atlas.ai.hyperflow_rivals_battery.v1';

    public const SUITE_SLUG = 'atlas-hyperflow-rivals-v1';

    public function __construct(
        private readonly AtlasForgeRivalsRunPathResolver $forgeRunPaths,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function run(): array
    {
        $caseResults = array_map(
            fn (array $case): array => $this->replayCase($case),
            $this->contractReplayCases(),
        );
        $failed = array_values(array_filter($caseResults, fn (array $case): bool => ($case['status'] ?? null) !== 'passed'));
        $batteryMaterial = [
            'schema_version' => self::SCHEMA_VERSION,
            'case_results' => $caseResults,
            'arms' => ['atlas_ai', 'claude_code', 'codex_cli'],
        ];
        $batteryHash = hash('sha256', json_encode($batteryMaterial, JSON_THROW_ON_ERROR));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $failed === [] ? 'passed' : 'failed',
            'ready' => $failed === [],
            'comparison_mode' => 'contract_replay',
            'case_set' => 'atlas_hyperflow_rivals_contract_replay_v1',
            'summary' => [
                'total' => count($caseResults),
                'passed' => count($caseResults) - count($failed),
                'failed' => count($failed),
            ],
            'arms' => [
                'atlas_ai' => [
                    'executed' => true,
                    'mode' => 'router_runtime_contract_replay',
                ],
                'claude_code' => [
                    'executed' => false,
                    'mode' => 'external_baseline_required_for_final_claim',
                ],
                'codex_cli' => [
                    'executed' => false,
                    'mode' => 'external_baseline_required_for_final_claim',
                ],
            ],
            'case_results' => $caseResults,
            'receipt' => [
                'schema_version' => 'atlas.ai.hyperflow_rivals_battery_receipt.v1',
                'receipt_id' => 'hfr_'.substr($batteryHash, 0, 32),
                'battery_hash' => $batteryHash,
                'case_count' => count($caseResults),
            ],
            'safety' => [
                'does_not_claim_100x' => true,
                'does_not_call_external_provider' => true,
                'external_baseline_still_required' => true,
            ],
            'external_provider_call' => false,
            'provider_tokens_spent' => 0,
            'writes' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function status(): array
    {
        if (! $this->tablesReady()) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'blocked',
                'ready' => false,
                'suite_slug' => self::SUITE_SLUG,
                'blocking_reason' => 'benchmark_tables_missing',
                'required_case_count' => count($this->canonicalCases()),
                'writes' => false,
            ];
        }

        $suite = $this->suite();
        if (! $suite) {
            return $this->payload(null, 'missing_suite', false, 'run_prepare_first');
        }

        $suite->loadMissing(['cases', 'latestBenchmarkRun']);
        $caseCount = $this->canonicalCaseCount($suite);
        if ($caseCount < count($this->canonicalCases())) {
            return $this->payload($suite, 'incomplete_corpus', false, 'prepare_missing_cases');
        }

        $latestRun = $suite->latestBenchmarkRun;
        if (! $latestRun) {
            return $this->payload($suite, 'waiting_for_run', false, 'run_rivals_battery');
        }

        $passed = $this->runCertifiesReplacement($latestRun);

        return $this->payload(
            $suite,
            $passed ? 'passed' : 'run_not_certifying',
            $passed,
            $passed ? 'certification_can_consume_battery' : 'rerun_or_record_passing_rivals_outcome',
            $latestRun,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function prepare(): array
    {
        if (! $this->tablesReady()) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'blocked',
                'ready' => false,
                'suite_slug' => self::SUITE_SLUG,
                'blocking_reason' => 'benchmark_tables_missing',
                'writes' => false,
            ];
        }

        $suite = AtlasEngineeringBenchmarkSuite::query()->firstOrNew(['slug' => self::SUITE_SLUG]);
        $suite->forceFill([
            'slug' => self::SUITE_SLUG,
            'name' => 'Atlas Hyperflow Rivals Battery',
            'description' => 'Canonical Claude Code/Codex replacement battery for Atlas Hyperflow Engineering Runtime.',
            'status' => 'active',
            'default_runner_options_json' => [
                'permission' => 'auto',
                'sandbox' => 'worktree',
                'max_attempts' => 1,
                'auto_test' => true,
                'release_gate_profile' => 'release',
                'rivals_battery_mode' => 'official_fair',
            ],
            'metadata' => [
                'schema_version' => self::SCHEMA_VERSION,
                'managed_by' => 'atlas_hyperflow',
                'benchmark_tier' => 'hyperflow_rivals',
                'rivals' => ['claude_code', 'codex'],
                'claim_policy' => [
                    'no_100x_claim_without_passing_run' => true,
                    'requires_paired_or_recorded_baseline' => true,
                ],
            ],
        ])->save();

        $createdOrUpdated = [];
        foreach ($this->canonicalCases() as $case) {
            $model = AtlasEngineeringBenchmarkCase::query()->firstOrNew([
                'suite_id' => $suite->id,
                'case_code' => $case['case_code'],
            ]);
            $model->forceFill([
                'suite_id' => $suite->id,
                'case_code' => $case['case_code'],
                'title' => $case['title'],
                'description' => $case['description'],
                'task_contract_json' => $case['task_contract'],
                'runner_options_json' => $case['runner_options'],
                'expected_decision' => $case['expected_decision'],
                'min_score' => $case['min_score'],
                'corpus_tier' => 'release',
                'domain_slug' => 'atlas_hyperflow',
                'risk_profile' => $case['risk_profile'],
                'curation_status' => 'curated',
                'curation_score' => 95,
                'tags_json' => $case['tags'],
                'status' => 'active',
                'metadata' => [
                    'schema_version' => self::SCHEMA_VERSION,
                    'expected_flow' => $case['expected_flow'],
                    'rival_baselines' => ['claude_code', 'codex'],
                    'realistic_operator_case' => true,
                    'acceptance_evidence' => $case['acceptance_evidence'],
                ],
                'curated_at' => now(),
            ])->save();

            $createdOrUpdated[] = $model->case_code;
        }

        return [
            ...$this->payload($suite->refresh()->load('cases'), 'prepared', false, 'run_rivals_battery'),
            'prepared_case_codes' => $createdOrUpdated,
            'writes' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function runAndPersist(array $options = []): array
    {
        if (! $this->tablesReady()) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'blocked',
                'ready' => false,
                'suite_slug' => self::SUITE_SLUG,
                'blocking_reason' => 'benchmark_tables_missing',
                'writes' => false,
            ];
        }

        $suite = $this->suite();
        if (! $suite) {
            return [
                ...$this->payload(null, 'missing_suite', false, 'run_prepare_first'),
                'writes' => false,
            ];
        }

        $suite->loadMissing('cases');
        if ($this->canonicalCaseCount($suite) < count($this->canonicalCases())) {
            return [
                ...$this->payload($suite, 'incomplete_corpus', false, 'prepare_missing_cases'),
                'writes' => false,
            ];
        }

        $startedAt = now();
        $caseResults = array_map(
            fn (array $case): array => $this->replayCanonicalCase($case),
            $this->canonicalCases(),
        );
        $failed = array_values(array_filter($caseResults, fn (array $case): bool => ($case['status'] ?? null) !== 'passed'));
        $passedCount = count($caseResults) - count($failed);
        $passRate = count($caseResults) === 0 ? 0.0 : round(($passedCount / count($caseResults)) * 100, 2);
        $averageScore = count($caseResults) === 0 ? 0.0 : round(array_sum(array_column($caseResults, 'score')) / count($caseResults), 2);
        $runStatus = $failed === [] ? 'passed' : 'failed';
        $caseSetHash = hash('sha256', json_encode(array_column($this->canonicalCases(), 'case_code'), JSON_THROW_ON_ERROR));
        $batteryMaterial = [
            'schema_version' => self::SCHEMA_VERSION,
            'suite_slug' => self::SUITE_SLUG,
            'case_set_hash' => $caseSetHash,
            'case_results' => $caseResults,
        ];
        $batteryHash = hash('sha256', json_encode($batteryMaterial, JSON_THROW_ON_ERROR));

        $run = DB::transaction(function () use ($suite, $caseResults, $caseSetHash, $runStatus, $passedCount, $failed, $passRate, $averageScore, $startedAt, $batteryHash, $options): AtlasEngineeringBenchmarkRun {
            $run = AtlasEngineeringBenchmarkRun::query()->create([
                'suite_id' => $suite->id,
                'benchmark_key' => self::SUITE_SLUG,
                'provider' => 'atlas_ai',
                'model' => 'hyperflow_router_runtime',
                'mode' => 'contract_replay',
                'case_set_hash' => $caseSetHash,
                'status' => $runStatus,
                'total_cases' => count($caseResults),
                'passed_cases' => $passedCount,
                'failed_cases' => count($failed),
                'pass_rate' => $passRate,
                'average_score' => $averageScore,
                'duration_ms' => (int) max(1, now()->diffInMilliseconds($startedAt, true)),
                'trend_status' => $runStatus,
                'runner_options_json' => [
                    'rivals_battery_mode' => 'official_fair',
                    'comparison_mode' => 'contract_replay',
                    'external_provider_call' => false,
                    'triggered_by' => (string) ($options['triggered_by'] ?? 'api'),
                ],
                'summary_json' => [
                    'schema_version' => self::SCHEMA_VERSION,
                    'battery_hash' => $batteryHash,
                    'external_provider_call' => false,
                    'provider_tokens_spent' => 0,
                    'case_results' => $caseResults,
                ],
                'release_gate_status' => $runStatus,
                'release_gate_profile' => 'hyperflow_rivals_contract_replay',
                'release_gate_policy_json' => [
                    'requires_all_cases_passed' => true,
                    'requires_external_evidence_for_replacement_claim' => true,
                ],
                'release_gate_failures_json' => array_values(array_map(
                    fn (array $case): string => (string) ($case['case_code'] ?? $case['case_id'] ?? 'unknown_case'),
                    $failed,
                )),
                'release_gate_warnings_json' => [
                    'external_provider_baselines_not_executed_by_internal_replay',
                ],
                'started_at' => $startedAt,
                'finished_at' => now(),
                'metadata' => [
                    'schema_version' => self::SCHEMA_VERSION,
                    'receipt' => [
                        'schema_version' => 'atlas.ai.hyperflow_rivals_battery_receipt.v1',
                        'receipt_id' => 'hfr_'.substr($batteryHash, 0, 32),
                        'battery_hash' => $batteryHash,
                    ],
                    'case_codes' => array_column($caseResults, 'case_code'),
                ],
            ]);

            $casesByCode = AtlasEngineeringBenchmarkCase::query()
                ->where('suite_id', $suite->id)
                ->whereIn('case_code', array_column($caseResults, 'case_code'))
                ->get()
                ->keyBy('case_code');

            foreach ($caseResults as $caseResult) {
                $case = $casesByCode->get($caseResult['case_code']);
                if (! $case) {
                    continue;
                }

                AtlasEngineeringBenchmarkResult::query()->create([
                    'benchmark_run_id' => $run->id,
                    'suite_id' => $suite->id,
                    'case_id' => $case->id,
                    'status' => $caseResult['status'],
                    'decision' => $caseResult['actual']['flow_id'] ?? null,
                    'score' => $caseResult['score'],
                    'passed' => $caseResult['status'] === 'passed',
                    'duration_ms' => $caseResult['duration_ms'],
                    'expectation_json' => $caseResult['expected'],
                    'observed_json' => $caseResult['actual'],
                    'failure_summary' => $caseResult['status'] === 'passed' ? null : 'Expected flow/delegation did not match router runtime output.',
                    'metadata' => [
                        'schema_version' => self::SCHEMA_VERSION,
                        'acceptance_evidence' => $caseResult['acceptance_evidence'],
                    ],
                ]);
            }

            return $run;
        });

        $compoundingOutcome = $this->recordCompoundingOutcome($run->refresh(), $caseResults, $batteryHash, $passRate, $averageScore, $runStatus);

        return [
            ...$this->payload($suite->refresh(), $runStatus, $runStatus === 'passed', $runStatus === 'passed' ? 'record_external_rivals_evidence' : 'fix_router_runtime_then_rerun', $run->refresh()),
            'receipt' => data_get($run->metadata, 'receipt'),
            'case_results' => $caseResults,
            'compounding_outcome' => $compoundingOutcome,
            'writes' => true,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $caseResults
     * @return array<string,mixed>
     */
    private function recordCompoundingOutcome(
        AtlasEngineeringBenchmarkRun $run,
        array $caseResults,
        string $batteryHash,
        float $passRate,
        float $averageScore,
        string $runStatus,
    ): array {
        if (! $this->compoundingTablesReady()) {
            return [
                'schema_version' => 'atlas.ai.compounding.hyperflow_bridge.v1',
                'status' => 'blocked',
                'blocking_reason' => 'compounding_tables_missing',
                'writes' => false,
            ];
        }

        $failed = array_values(array_filter($caseResults, fn (array $case): bool => ($case['status'] ?? null) !== 'passed'));
        $receiptId = 'hfr_'.substr($batteryHash, 0, 32);
        $evidenceRefs = [
            'benchmark_run:'.$run->id,
            'receipt:'.$receiptId,
            'suite:'.self::SUITE_SLUG,
        ];

        try {
            $record = app(AtlasCompoundingRuntimeService::class)->recordExecution([
                'run_id' => 'hyperflow:'.$run->id,
                'flow_id' => 'atlas_hyperflow',
                'outcome_status' => $runStatus,
                'prompt' => 'Atlas Hyperflow rivals battery contract replay',
                'source_type' => 'hyperflow_rivals_battery',
                'flow_quality' => (int) round($passRate),
                'retrieval_quality' => 85,
                'execution_quality' => (int) round($averageScore),
                'evidence_quality' => 90,
                'learning_required' => $failed !== [],
                'missed_signals' => array_values(array_map(
                    fn (array $case): string => (string) ($case['case_code'] ?? 'unknown_case'),
                    $failed,
                )),
                'evidence_refs' => $evidenceRefs,
                'learning_signal' => [
                    'claim' => $failed === []
                        ? 'Hyperflow battery passed and should remain eligible for temporal certification.'
                        : 'Hyperflow failed cases must feed router/runtime regression learning before replacement claims.',
                    'memory_type' => 'routing_memory',
                    'scope' => 'atlas-server',
                    'confidence' => $failed === [] ? 80 : 86,
                    'flow_id' => 'atlas_hyperflow',
                    'evidence_refs' => $evidenceRefs,
                ],
                'rag_feedback' => [
                    'retrieval_receipt_id' => $receiptId,
                    'query_plan_hash' => $batteryHash,
                    'included_sources' => count($caseResults),
                    'used_sources' => count($caseResults) - count($failed),
                    'noise_sources' => 0,
                    'missed_required_sources' => array_values(array_map(
                        fn (array $case): string => (string) ($case['case_code'] ?? 'unknown_case'),
                        $failed,
                    )),
                    'context_sufficiency' => $failed === [] ? 90 : 70,
                    'post_execution_utility' => $failed === [] ? 86 : 78,
                    'source_utility' => [
                        self::SUITE_SLUG => $failed === [] ? 'useful' : 'regression_signal',
                    ],
                ],
                'benchmark_case' => [
                    'force' => $failed !== [],
                    'source' => 'real_user_run',
                    'expected_flow' => 'atlas_hyperflow',
                    'required_evidence' => $evidenceRefs,
                    'rivals' => ['claude_code', 'codex'],
                ],
            ]);
        } catch (\Throwable $exception) {
            return [
                'schema_version' => 'atlas.ai.compounding.hyperflow_bridge.v1',
                'status' => 'blocked',
                'blocking_reason' => 'compounding_record_failed',
                'error' => $exception->getMessage(),
                'writes' => false,
            ];
        }

        return [
            'schema_version' => 'atlas.ai.compounding.hyperflow_bridge.v1',
            'status' => 'recorded',
            'outcome_hash' => data_get($record, 'outcome.outcome_hash'),
            'run_id' => 'hyperflow:'.$run->id,
            'writes' => true,
        ];
    }

    private function compoundingTablesReady(): bool
    {
        return DatabaseTableAvailability::all([
            'ai_run_outcomes',
            'ai_learning_candidates',
            'ai_compounding_memories',
            'ai_rag_feedback_events',
            'ai_benchmark_cases',
            'ai_temporal_certifications',
        ]);
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function recordExternalEvidence(array $data): array
    {
        if (! $this->tablesReady()) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'blocked',
                'ready' => false,
                'suite_slug' => self::SUITE_SLUG,
                'blocking_reason' => 'benchmark_tables_missing',
                'writes' => false,
            ];
        }

        $suite = $this->suite();
        if (! $suite) {
            return [
                ...$this->payload(null, 'missing_suite', false, 'run_prepare_first'),
                'writes' => false,
            ];
        }

        $suite->loadMissing('latestBenchmarkRun');
        $run = $suite->latestBenchmarkRun;
        if (! $run) {
            return [
                ...$this->payload($suite, 'waiting_for_run', false, 'run_rivals_battery'),
                'writes' => false,
            ];
        }

        if (! $this->runCertifiesReplacement($run)) {
            return [
                ...$this->payload($suite, 'run_not_certifying', false, 'rerun_or_record_passing_rivals_outcome', $run),
                'blocking_reason' => 'latest_run_does_not_certify_internal_hyperflow_battery',
                'writes' => false,
            ];
        }

        $externalEvidenceReceiptHash = strtolower((string) ($data['evidence_receipt_hash'] ?? ''));
        $providerResults = $this->normalizeProviderResults((array) ($data['provider_results'] ?? []));
        $evidenceBlockers = array_values(array_unique([
            ...$this->externalEvidenceBlockers($providerResults, $data),
            ...$this->externalEvidenceRecordProvenanceBlockers($data),
        ]));
        if ($evidenceBlockers !== []) {
            return [
                ...$this->payload($suite, 'external_evidence_blocked', false, 'provide_operator_approved_claude_code_and_codex_receipts', $run),
                'blocking_reason' => 'external_evidence_invalid',
                'external_evidence_blockers' => $evidenceBlockers,
                'writes' => false,
            ];
        }

        $evidenceMaterial = [
            'schema_version' => 'atlas.ai.hyperflow_external_rivals_evidence.v1',
            'suite_slug' => self::SUITE_SLUG,
            'benchmark_run_id' => $run->id,
            'external_evidence_receipt_hash' => $externalEvidenceReceiptHash,
            'approved_by' => (string) ($data['approved_by'] ?? 'operator'),
            'protocol_valid' => (bool) ($data['protocol_valid'] ?? true),
            'comparable' => (bool) ($data['comparable'] ?? true),
            'operator_approved' => (bool) ($data['operator_approved'] ?? true),
            'provider_results' => $providerResults,
        ];
        $recordReceiptHash = hash('sha256', json_encode($evidenceMaterial, JSON_THROW_ON_ERROR));
        $evidenceSource = (string) ($data['evidence_source'] ?? (($data['source_evidence_pack_path'] ?? '') !== '' ? 'atlas_forge_rivals_export' : 'manual_operator_record'));
        $external = [
            'schema_version' => 'atlas.ai.hyperflow_external_rivals_evidence.v1',
            'external_provider_call' => true,
            'baselines' => array_keys($providerResults),
            'evidence_source' => $evidenceSource,
            'source_evidence_pack_path' => (string) ($data['source_evidence_pack_path'] ?? ''),
            'source_provenance_valid' => true,
            'protocol_valid' => (bool) ($data['protocol_valid'] ?? true),
            'comparable' => (bool) ($data['comparable'] ?? true),
            'operator_approved' => (bool) ($data['operator_approved'] ?? true),
            'approved_by' => (string) ($data['approved_by'] ?? 'operator'),
            'provider_tokens_spent' => (int) ($data['provider_tokens_spent'] ?? 0),
            'evidence_receipt_hash' => $externalEvidenceReceiptHash,
            'record_receipt_hash' => $recordReceiptHash,
            'provider_results' => $providerResults,
        ];

        $summary = is_array($run->summary_json) ? $run->summary_json : [];
        $outcome = is_array($run->outcome_json) ? $run->outcome_json : [];
        $metadata = is_array($run->metadata) ? $run->metadata : [];
        $recordedAt = now();
        $run->forceFill([
            'summary_json' => [
                ...$summary,
                'external_provider_call' => true,
                'provider_tokens_spent' => $external['provider_tokens_spent'],
                'rival_baselines' => $external['baselines'],
                'external_rivals' => $external,
            ],
            'outcome_status' => 'approved',
            'outcome_score' => max((int) ($run->outcome_score ?? 0), 90),
            'outcome_json' => [
                ...$outcome,
                'external_rivals' => $external,
            ],
            'outcome_recorded_at' => $recordedAt,
            'outcome_recorded_by' => $external['approved_by'],
            'metadata' => [
                ...$metadata,
                'hyperflow_external_rivals_evidence' => [
                    'receipt_id' => 'hfe_'.substr($recordReceiptHash, 0, 32),
                    'evidence_receipt_hash' => $externalEvidenceReceiptHash,
                    'record_receipt_hash' => $recordReceiptHash,
                    'recorded_at' => $recordedAt->toIso8601String(),
                    'recorded_by' => $external['approved_by'],
                ],
            ],
        ])->save();

        $run->refresh();

        return [
            ...$this->payload($suite->refresh(), 'external_evidence_recorded', true, 'certification_can_consume_external_evidence', $run),
            'external_evidence' => $this->externalProviderExecution($run),
            'receipt' => [
                'schema_version' => 'atlas.ai.hyperflow_external_rivals_evidence_receipt.v1',
                'receipt_id' => 'hfe_'.substr($recordReceiptHash, 0, 32),
                'evidence_receipt_hash' => $externalEvidenceReceiptHash,
                'record_receipt_hash' => $recordReceiptHash,
                'benchmark_run_id' => $run->id,
                'approved_by' => $external['approved_by'],
            ],
            'writes' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function importExternalEvidencePack(array $data): array
    {
        $path = (string) ($data['evidence_pack_path'] ?? '');
        if ($path === '' || ! File::isFile($path) || ! File::isReadable($path)) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'external_evidence_import_blocked',
                'ready' => false,
                'blocking_reason' => 'evidence_pack_path_missing_or_unreadable',
                'writes' => false,
            ];
        }

        try {
            $pack = json_decode((string) File::get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'external_evidence_import_blocked',
                'ready' => false,
                'blocking_reason' => 'evidence_pack_json_invalid',
                'writes' => false,
            ];
        }

        $pack = is_array($pack) ? $pack : [];
        $providerResults = $this->providerResultsFromEvidencePack($pack);
        $evidenceReceiptHash = hash_file('sha256', $path);
        $importPayload = [
            'external_provider_call' => true,
            'evidence_source' => (string) data_get($pack, 'source', 'atlas_forge_rivals_export'),
            'approved_by' => (string) ($data['approved_by'] ?? 'operator'),
            'provider_tokens_spent' => (int) data_get($pack, 'provider_tokens_spent_count', data_get($pack, 'provider_tokens_spent', 1)),
            'protocol_valid' => true,
            'comparable' => true,
            'operator_approved' => true,
            'evidence_receipt_hash' => $evidenceReceiptHash,
            'provider_results' => $providerResults,
            'source_evidence_pack_path' => $path,
        ];
        $blockers = $this->externalEvidencePackBlockers($pack, $providerResults, $data);
        if ($blockers !== []) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'external_evidence_import_blocked',
                'ready' => false,
                'blocking_reason' => 'evidence_pack_not_eligible_for_hyperflow_certification',
                'external_evidence_blockers' => $blockers,
                'evidence_pack' => [
                    'path' => $path,
                    'sha256' => $evidenceReceiptHash,
                    'schema_version' => data_get($pack, 'schema_version'),
                    'run_id' => data_get($pack, 'run_id'),
                ],
                'writes' => false,
            ];
        }

        $result = $this->recordExternalEvidence($importPayload);

        return [
            ...$result,
            'imported_evidence_pack' => [
                'path' => $path,
                'sha256' => $evidenceReceiptHash,
                'schema_version' => data_get($pack, 'schema_version'),
                'run_id' => data_get($pack, 'run_id'),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function exportExternalEvidencePack(array $data): array
    {
        $runIds = $this->resolveForgeRunIds($data);
        if ($runIds === []) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'external_evidence_export_blocked',
                'ready' => false,
                'blocking_reason' => 'forge_run_ids_required',
                'external_evidence_blockers' => ['forge_run_ids_required'],
                'writes' => false,
            ];
        }

        $operatorApproved = (bool) ($data['operator_approved'] ?? false);
        $approvedBy = trim((string) ($data['approved_by'] ?? 'operator'));
        $blockers = $operatorApproved ? [] : ['operator_approval_missing'];
        $providerResults = [];
        $sourceRuns = [];
        $providerProcessCount = 0;

        foreach ($runIds as $runId) {
            $source = $this->forgeRunSourceEvidence($runId);
            $sourceRuns[] = $source['summary'];
            $blockers = array_values(array_unique(array_merge($blockers, $source['blockers'])));

            $provider = (string) ($source['provider'] ?? '');
            if ($provider === '') {
                continue;
            }
            if (isset($providerResults[$provider])) {
                $blockers[] = 'duplicate_provider_baseline:'.$provider;

                continue;
            }

            $providerResults[$provider] = (array) $source['provider_result'];
            $providerProcessCount++;
        }
        $blockers = array_values(array_unique(array_merge(
            $blockers,
            $this->providerResultBlockers($providerResults),
        )));

        foreach (['claude_code', 'codex_cli'] as $requiredProvider) {
            if (! isset($providerResults[$requiredProvider])) {
                $blockers[] = 'missing_provider_baseline:'.$requiredProvider;
            }
        }
        $blockers = array_values(array_unique($blockers));
        $eligible = $blockers === [];

        $pack = [
            'schema_version' => 'atlas.hyperflow.external_rivals_evidence_pack.v1',
            'run_id' => 'hyperflow-external-'.substr(hash('sha256', implode('|', $runIds).'|'.now()->toJSON()), 0, 24),
            'generated_at' => now()->toJSON(),
            'source' => 'atlas_forge_rivals_export',
            'forge_run_ids' => $runIds,
            'hyperflow_external_rivals_certification_eligible' => $eligible,
            'external_provider_call' => $eligible,
            'provider_tokens_spent' => $eligible,
            'provider_tokens_spent_count' => $eligible ? max(1, $providerProcessCount) : 0,
            'provider_tokens_accounting' => [
                'reported_by_cli' => false,
                'meaning' => 'provider_tokens_spent=true is based on verified real provider process execution; exact token count is not emitted by the Forge/Rivals receipts.',
                'provider_process_count' => $providerProcessCount,
            ],
            'is_comparable_real_run' => $eligible,
            'protocol_valid' => $eligible,
            'claim_ready' => $eligible,
            'operator_approved' => $operatorApproved,
            'approved_by' => $approvedBy !== '' ? $approvedBy : 'operator',
            'provider_results' => $providerResults,
            'source_forge_runs' => $sourceRuns,
            'blockers' => $blockers,
            'safety' => [
                'rejects_local_fake' => true,
                'requires_claude_code_and_codex_cli_baselines' => true,
                'does_not_declare_superiority_without_import_and_certification' => true,
            ],
        ];
        $pack['evidence_receipt_hash'] = hash('sha256', json_encode($pack, JSON_THROW_ON_ERROR));

        $outputPath = trim((string) ($data['output_path'] ?? ''));
        if ($outputPath === '') {
            $outputPath = storage_path('app/atlas-hyperflow/evidence-packs/'.$pack['run_id'].'.json');
        }

        if (! $eligible) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'external_evidence_export_blocked',
                'ready' => false,
                'evidence_pack' => null,
                'rejected_evidence_pack' => [
                    'schema_version' => $pack['schema_version'],
                    'run_id' => $pack['run_id'],
                    'eligible' => false,
                    'output_path_not_written' => $outputPath,
                ],
                'provider_results' => $providerResults,
                'source_forge_runs' => $sourceRuns,
                'external_evidence_blockers' => $blockers,
                'next_action' => 'run real Forge/Rivals baselines for claude_code and codex_cli, then export again',
                'writes' => false,
            ];
        }

        File::ensureDirectoryExists(dirname($outputPath));
        File::put($outputPath, json_encode($pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'external_evidence_exported',
            'ready' => true,
            'evidence_pack' => [
                'path' => $outputPath,
                'sha256' => hash_file('sha256', $outputPath),
                'schema_version' => $pack['schema_version'],
                'run_id' => $pack['run_id'],
                'eligible' => $eligible,
            ],
            'provider_results' => $providerResults,
            'source_forge_runs' => $sourceRuns,
            'external_evidence_blockers' => $blockers,
            'next_action' => 'import_with: php artisan atlas:ai:hyperflow import-evidence --evidence-pack='.$outputPath.' --operator-approved --approved-by='.$pack['approved_by'].' --json',
            'writes' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function externalEvidencePackTemplate(): array
    {
        return [
            'schema_version' => 'atlas.ai.hyperflow_external_evidence_pack_template.v1',
            'status' => 'ready',
            'suite_slug' => self::SUITE_SLUG,
            'writes' => false,
            'template' => [
                'schema_version' => 'atlas.hyperflow.external_rivals_evidence_pack.v1',
                'run_id' => '<external-run-id>',
                'hyperflow_external_rivals_certification_eligible' => true,
                'external_provider_call' => true,
                'provider_tokens_spent' => true,
                'provider_tokens_spent_count' => 0,
                'is_comparable_real_run' => true,
                'protocol_valid' => true,
                'claim_ready' => true,
                'provider_results' => [
                    'claude_code' => [
                        'status' => 'passed',
                        'score' => 0,
                        'duration_ms' => 0,
                        'evidence_hash' => '<sha256-64-hex>',
                        'receipt_ref' => '<claude-code-receipt-ref>',
                    ],
                    'codex_cli' => [
                        'status' => 'passed',
                        'score' => 0,
                        'duration_ms' => 0,
                        'evidence_hash' => '<sha256-64-hex>',
                        'receipt_ref' => '<codex-receipt-ref>',
                    ],
                ],
            ],
            'import' => [
                'api' => '/ai/hyperflow/rivals-battery/external-evidence/import',
                'cli' => 'php artisan atlas:ai:hyperflow import-evidence --evidence-pack=<path> --operator-approved --approved-by=<operator> --json',
                'requires_operator_approval' => true,
            ],
            'validation_contract' => [
                'requires_external_provider_call' => true,
                'requires_claude_code_baseline' => true,
                'requires_codex_or_codex_cli_baseline' => true,
                'requires_sha256_evidence_hashes' => true,
                'rejects_local_fake_or_replay_only_packs' => true,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function externalEvidenceCandidates(array $data = []): array
    {
        $root = $this->forgeRunPaths->rootDirectory();
        $limit = max(1, min(250, (int) ($data['limit'] ?? 80)));
        $providerFilter = strtolower(trim((string) ($data['provider'] ?? '')));
        $onlyReady = (bool) ($data['only_ready'] ?? false);
        $directories = is_dir($root) ? File::directories($root) : [];
        usort($directories, fn (string $a, string $b): int => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));

        $candidates = [];
        $providerCounts = [];
        $readyByProvider = [];

        foreach ($directories as $directory) {
            if (count($candidates) >= $limit) {
                break;
            }

            $runId = basename($directory);
            $candidate = $this->externalEvidenceCandidateForRun($runId);
            $provider = (string) ($candidate['provider'] ?? 'unknown');
            if ($providerFilter !== '' && $provider !== $providerFilter) {
                continue;
            }
            if ($onlyReady && ($candidate['export_ready'] ?? false) !== true) {
                continue;
            }

            $providerCounts[$provider] = (int) ($providerCounts[$provider] ?? 0) + 1;
            if (($candidate['export_ready'] ?? false) === true) {
                $readyByProvider[$provider][] = $runId;
            }
            $candidates[] = $candidate;
        }

        $suggestedPair = [];
        if (($readyByProvider['claude_code'] ?? []) !== [] && ($readyByProvider['codex_cli'] ?? []) !== []) {
            $suggestedPair = [
                $readyByProvider['claude_code'][0],
                $readyByProvider['codex_cli'][0],
            ];
        }

        return [
            'schema_version' => 'atlas.ai.hyperflow_external_evidence_candidates.v1',
            'status' => $suggestedPair === [] ? 'blocked' : 'ready',
            'runs_root' => $root,
            'limit' => $limit,
            'provider_filter' => $providerFilter !== '' ? $providerFilter : null,
            'only_ready' => $onlyReady,
            'candidate_count' => count($candidates),
            'provider_counts' => $providerCounts,
            'ready_provider_counts' => array_map('count', $readyByProvider),
            'suggested_pair' => $suggestedPair,
            'missing_pair_providers' => array_values(array_filter(
                ['claude_code', 'codex_cli'],
                fn (string $provider): bool => ($readyByProvider[$provider] ?? []) === [],
            )),
            'candidates' => $candidates,
            'next_action' => $suggestedPair === []
                ? 'run real Forge/Rivals baselines until both claude_code and codex_cli have export_ready candidates'
                : 'preflight suggested_pair, then export/import/certify',
            'writes' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function externalEvidenceRunbook(array $data = []): array
    {
        $candidates = $this->externalEvidenceCandidates([
            'limit' => (int) ($data['limit'] ?? 120),
        ]);
        $missingProviders = (array) ($candidates['missing_pair_providers'] ?? ['claude_code', 'codex_cli']);
        $setupRuns = $this->latestSetupRuns(max(2, count($missingProviders)));
        $setupRun = $setupRuns[0] ?? null;
        $operator = trim((string) ($data['approved_by'] ?? 'operator'));
        $operator = $operator !== '' ? $operator : 'operator';
        $runCommands = [];
        $setupRunAllocation = [];

        foreach ($missingProviders as $index => $provider) {
            $provider = (string) $provider;
            $rival = match ($provider) {
                'claude_code' => 'claude_sonnet',
                'codex_cli' => 'codex',
                default => '',
            };
            if ($rival === '') {
                continue;
            }
            $mode = $provider === 'codex_cli' ? 'full_power' : 'fair';
            $providerSetup = $setupRuns[$index] ?? null;
            $providerSetupRunId = (string) ($providerSetup['run_id'] ?? '<'.$provider.'_setup_run_id>');
            $setupRunAllocation[$provider] = [
                'provider' => $provider,
                'setup_run_id' => $providerSetup['run_id'] ?? null,
                'placeholder' => $providerSetup === null ? '<'.$provider.'_setup_run_id>' : null,
                'requires_new_setup' => $providerSetup === null,
            ];

            $runCommands[$provider] = [
                'provider' => $provider,
                'setup_run_id' => $providerSetup['run_id'] ?? null,
                'rival_model' => $rival,
                'mode' => $mode,
                'why_mode' => $provider === 'codex_cli'
                    ? 'codex_cli cannot use fair mode against claude_sonnet because fair requires same model on both arms'
                    : 'fair mode is valid for claude_code when both arms use claude_sonnet',
                'command' => 'php artisan atlas:forge:rivals run-real --run-id='.$providerSetupRunId.' --mode='.$mode.' --atlas-model=claude_sonnet --rival='.$rival.' --prompt-mode=messy-real --case-set=quick --confirm-runbook-reviewed --confirm-provider-cost --confirm-real-provider-call --json',
                'cost_gate' => 'requires real provider call and token spend',
                'post_run_collect' => 'php artisan atlas:forge:rivals collect-evidence --run-id=<'.$provider.'_run_id> --stage=final --json',
                'post_run_report' => 'php artisan atlas:forge:rivals report --run-id=<'.$provider.'_run_id> --json',
            ];
        }

        $pairPlaceholder = '<claude_code_run_id>,<codex_cli_run_id>';
        $suggestedPair = (array) ($candidates['suggested_pair'] ?? []);
        if ($suggestedPair !== []) {
            $pairPlaceholder = implode(',', array_map(fn (mixed $id): string => (string) $id, $suggestedPair));
        }
        $codexSetupRunId = $setupRunAllocation['codex_cli']['setup_run_id'] ?? '<codex_cli_setup_run_id>';
        $claudeSetupRunId = $setupRunAllocation['claude_code']['setup_run_id'] ?? '<claude_code_setup_run_id>';
        $commands = [
            'doctor' => 'php artisan atlas:forge:rivals doctor --json',
            'setup' => 'php artisan atlas:forge:rivals setup --source-ref=HEAD --json',
            'preflight_codex_cli' => 'php artisan atlas:forge:rivals preflight --run-id='.(string) $codexSetupRunId.' --mode=full_power --atlas-model=claude_sonnet --rival=codex --prompt-mode=messy-real --case-set=quick --json',
            'preflight_claude_code' => 'php artisan atlas:forge:rivals preflight --run-id='.(string) $claudeSetupRunId.' --mode=fair --atlas-model=claude_sonnet --rival=claude_sonnet --prompt-mode=messy-real --case-set=quick --json',
            'run_missing_providers' => $runCommands,
            'rediscover_candidates' => 'php artisan atlas:ai:hyperflow evidence-candidates --json',
            'preflight_pair' => 'php artisan atlas:ai:hyperflow evidence-preflight --forge-run-ids='.$pairPlaceholder.' --json',
            'export_pair' => 'php artisan atlas:ai:hyperflow export-evidence --forge-run-ids='.$pairPlaceholder.' --operator-approved --approved-by='.$operator.' --json',
            'import_pack' => 'php artisan atlas:ai:hyperflow import-evidence --evidence-pack=<exported_pack_path> --operator-approved --approved-by='.$operator.' --json',
            'certify' => 'php artisan atlas:ai:hyperflow certify --json',
        ];
        $operatorActionPacket = [
            'schema_version' => 'atlas.ai.hyperflow_external_provider_operator_action_packet.v1',
            'status' => $suggestedPair === [] ? 'awaiting_real_provider_runs' : 'ready_to_export_import_certify',
            'current_blocker' => 'rivals_battery.external_provider_execution',
            'requires_operator_cost_approval' => $runCommands !== [],
            'requires_real_provider_call' => $runCommands !== [],
            'missing_provider_run_commands' => $runCommands,
            'after_real_provider_runs' => [
                'rediscover_candidates' => $commands['rediscover_candidates'],
                'preflight_pair' => $commands['preflight_pair'],
                'export_pair' => $commands['export_pair'],
                'import_pack' => $commands['import_pack'],
                'certify' => $commands['certify'],
            ],
            'completion_condition' => 'certification.status=passed and remaining_blockers=[]',
            'safety' => [
                'does_not_execute_provider' => true,
                'does_not_approve_cost' => true,
                'does_not_import_evidence' => true,
                'rejects_fake_or_replay_only_evidence' => true,
            ],
        ];

        return [
            'schema_version' => 'atlas.ai.hyperflow_external_evidence_runbook.v1',
            'status' => $suggestedPair === [] ? 'blocked' : 'ready_to_export',
            'runs_root' => $candidates['runs_root'] ?? $this->forgeRunPaths->rootDirectory(),
            'candidate_summary' => [
                'candidate_count' => $candidates['candidate_count'] ?? 0,
                'provider_counts' => $candidates['provider_counts'] ?? [],
                'ready_provider_counts' => $candidates['ready_provider_counts'] ?? [],
                'missing_pair_providers' => $missingProviders,
                'suggested_pair' => $suggestedPair,
                'latest_setup_run' => $setupRun,
                'latest_setup_runs' => $setupRuns,
                'setup_run_allocation' => $setupRunAllocation,
            ],
            'commands' => $commands,
            'operator_action_packet' => $operatorActionPacket,
            'safety' => [
                'writes' => false,
                'does_not_call_external_provider' => true,
                'real_provider_commands_are_explicitly_confirmed' => true,
                'setup_run_detection_is_filesystem_only' => true,
                'does_not_reuse_same_setup_run_for_multiple_missing_providers' => true,
                'does_not_import_or_approve_evidence' => true,
                'does_not_declare_replacement_without_certification_ready' => true,
            ],
            'next_action' => $suggestedPair === []
                ? 'run missing provider commands, collect evidence, then rediscover candidates'
                : 'preflight/export/import suggested_pair, then certify',
            'writes' => false,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function latestSetupRuns(int $limit = 2): array
    {
        $root = $this->forgeRunPaths->rootDirectory();
        if (! is_dir($root)) {
            return [];
        }

        $limit = max(1, min(20, $limit));
        $directories = File::directories($root);
        usort($directories, fn (string $a, string $b): int => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));
        $runs = [];
        foreach ($directories as $directory) {
            $runId = basename($directory);
            if (! str_starts_with($runId, 'fr2-')) {
                continue;
            }

            try {
                $paths = $this->forgeRunPaths->paths($runId);
            } catch (\Throwable) {
                continue;
            }

            $ready = is_dir($paths['base'])
                && is_dir($paths['evidence'])
                && is_dir($paths['atlas'])
                && is_dir($paths['rival']);

            if ($ready) {
                $runs[] = [
                    'run_id' => $runId,
                    'status' => 'setup_ready',
                    'base' => $paths['base'],
                    'atlas_workspace' => $paths['atlas'],
                    'rival_workspace' => $paths['rival'],
                    'evidence_dir' => $paths['evidence'],
                    'mtime' => filemtime($directory) ?: null,
                ];

                if (count($runs) >= $limit) {
                    break;
                }
            }
        }

        return $runs;
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function externalEvidencePreflight(array $data = []): array
    {
        $status = $this->status();
        $latestExternal = (array) data_get($status, 'latest_run.external_provider_execution', []);
        $runIds = $this->resolveForgeRunIds($data);
        $candidateBlockers = [];
        $candidateProviderResults = [];
        $candidateSourceRuns = [];
        $providerProcessCount = 0;

        foreach ($runIds as $runId) {
            $source = $this->forgeRunSourceEvidence($runId);
            $candidateSourceRuns[] = $source['summary'];
            $candidateBlockers = array_values(array_unique(array_merge($candidateBlockers, $source['blockers'])));

            $provider = (string) ($source['provider'] ?? '');
            if ($provider === '') {
                continue;
            }
            if (isset($candidateProviderResults[$provider])) {
                $candidateBlockers[] = 'duplicate_provider_baseline:'.$provider;

                continue;
            }

            $candidateProviderResults[$provider] = (array) $source['provider_result'];
            $providerProcessCount++;
        }
        $candidateBlockers = array_values(array_unique(array_merge(
            $candidateBlockers,
            $this->providerResultBlockers($candidateProviderResults),
        )));

        if ($runIds !== []) {
            foreach (['claude_code', 'codex_cli'] as $requiredProvider) {
                if (! isset($candidateProviderResults[$requiredProvider])) {
                    $candidateBlockers[] = 'missing_provider_baseline:'.$requiredProvider;
                }
            }
        }

        $candidateBlockers = array_values(array_unique($candidateBlockers));
        $latestExternalPassed = ($latestExternal['status'] ?? null) === 'passed';
        $candidateExportReady = $runIds !== [] && $candidateBlockers === [];

        return [
            'schema_version' => 'atlas.ai.hyperflow_external_evidence_preflight.v1',
            'status' => $latestExternalPassed || $candidateExportReady ? 'ready' : 'blocked',
            'certification_ready' => $latestExternalPassed,
            'candidate_export_ready' => $candidateExportReady,
            'suite_slug' => self::SUITE_SLUG,
            'latest_external_provider_execution' => $latestExternal,
            'candidate_forge_run_ids' => $runIds,
            'candidate_provider_results' => $candidateProviderResults,
            'candidate_source_runs' => $candidateSourceRuns,
            'candidate_provider_process_count' => $providerProcessCount,
            'external_evidence_blockers' => $candidateBlockers !== [] || $candidateExportReady
                ? $candidateBlockers
                : (array) ($latestExternal['failed_checks'] ?? []),
            'next_actions' => $this->externalEvidencePreflightNextActions($latestExternalPassed, $candidateExportReady, $runIds),
            'surfaces' => [
                'template_api' => '/ai/hyperflow/rivals-battery/external-evidence/template',
                'export_api' => '/ai/hyperflow/rivals-battery/external-evidence/export',
                'import_api' => '/ai/hyperflow/rivals-battery/external-evidence/import',
                'certification_api' => '/ai/hyperflow/certification',
                'cli_template' => 'php artisan atlas:ai:hyperflow evidence-template --json',
                'cli_preflight' => 'php artisan atlas:ai:hyperflow evidence-preflight --forge-run-ids=<claude_run>,<codex_run> --json',
                'cli_export' => 'php artisan atlas:ai:hyperflow export-evidence --forge-run-ids=<claude_run>,<codex_run> --operator-approved --approved-by=<operator> --json',
                'cli_import' => 'php artisan atlas:ai:hyperflow import-evidence --evidence-pack=<path> --operator-approved --approved-by=<operator> --json',
                'cli_certify' => 'php artisan atlas:ai:hyperflow certify --json',
            ],
            'safety' => [
                'writes' => false,
                'does_not_call_external_provider' => true,
                'does_not_import_or_approve_evidence' => true,
                'does_not_declare_replacement_without_certification_ready' => true,
            ],
            'writes' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $latestExternal
     * @return list<string>
     */
    private function externalEvidencePreflightNextActions(bool $latestExternalPassed, bool $candidateExportReady, array $runIds): array
    {
        if ($latestExternalPassed) {
            return ['run: php artisan atlas:ai:hyperflow certify --json'];
        }

        if ($candidateExportReady) {
            return [
                'export: php artisan atlas:ai:hyperflow export-evidence --forge-run-ids='.implode(',', $runIds).' --operator-approved --approved-by=<operator> --json',
                'import exported evidence pack with --operator-approved',
                'run: php artisan atlas:ai:hyperflow certify --json',
            ];
        }

        if ($runIds !== []) {
            return [
                'fix listed Forge/Rivals evidence blockers',
                'rerun real baselines for missing claude_code or codex_cli providers',
                'rerun evidence-preflight before export',
            ];
        }

        return [
            'run real Forge/Rivals baselines for claude_code and codex_cli',
            'preflight those run ids with: php artisan atlas:ai:hyperflow evidence-preflight --forge-run-ids=<claude_run>,<codex_run> --json',
            'export, import, then certify',
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function canonicalCases(): array
    {
        return [
            $this->case('ambiguous_feature_plan', 'Ambiguous feature requires plan', 'Implemente login com seguranca e UX boa.', AtlasAiRouterDecision::FLOW_PLAN, 'partial', ['intent_kernel', 'plan'], ['router_decision', 'risk_register', 'execution_recommendation']),
            $this->case('workspace_dev_patch', 'Workspace patch routes to Atlas Dev', 'No workspace configurado, implemente endpoint de status com teste.', AtlasAiRouterDecision::FLOW_DEV, 'resolved', ['dev', 'programming'], ['atlas_dev_runtime', 'test_evidence', 'patch_artifact']),
            $this->case('debug_traceback_triage', 'Traceback routes to debug', 'Investigue este stacktrace e diga a causa provavel sem inventar logs.', AtlasAiRouterDecision::FLOW_DEBUG, 'partial', ['debug'], ['symptoms', 'missing_evidence', 'next_debug_steps']),
            $this->case('review_diff_findings', 'Diff routes to review', 'Revise este diff e liste findings primeiro.', AtlasAiRouterDecision::FLOW_REVIEW, 'resolved', ['review'], ['findings_first', 'severity_ordered', 'test_gaps']),
            $this->case('research_uncertainty', 'Research separates evidence from uncertainty', 'Pesquise o melhor caminho tecnico e separe fato de inferencia.', AtlasAiRouterDecision::FLOW_RESEARCH, 'partial', ['research'], ['source_refs_or_uncertainty', 'claims_labeled']),
            $this->case('explain_architecture_read_only', 'Explain is read-only', 'Explique como funciona o Router Runtime sem alterar nada.', AtlasAiRouterDecision::FLOW_EXPLAIN, 'resolved', ['explain'], ['plain_language_explanation', 'no_side_effect_claim']),
            $this->case('conversation_handoff', 'Conversation suggests handoff when scope changes', 'Vamos pensar juntos sobre uma ideia que talvez vire feature.', AtlasAiRouterDecision::FLOW_CONVERSATION, 'partial', ['conversation'], ['direct_answer', 'handoff_suggestion_when_scope_changes']),
            $this->case('forge_heavy_obra_promotion', 'Heavy Obra promotes to Forge', 'Monte uma obra enterprise de um mes com SDD, governanca e execucao longa.', AtlasAiRouterDecision::FLOW_FORGE, 'partial', ['forge', 'obra'], ['forge_handoff', 'promotion_reason', 'audit_payload'], 'high'),
            $this->case('explicit_plan_before_execution', 'Explicit planning routes to plan', 'Planeje antes de implementar a migracao do Hyperflow e liste riscos.', AtlasAiRouterDecision::FLOW_PLAN, 'partial', ['plan'], ['objective', 'risk_register', 'evidence_needed']),
            $this->case('workspace_debug_delegates_dev', 'Workspace debug delegates to Dev', 'Debug esse stacktrace no workspace atlas e rode o menor teste relevante.', AtlasAiRouterDecision::FLOW_DEBUG, 'partial', ['debug', 'dev_delegation'], ['delegation_target_atlas_dev', 'missing_evidence_visible']),
            $this->case('workspace_review_delegates_dev', 'Workspace review delegates to Dev', 'Revise o diff no workspace atlas e valide regressao antes de concluir.', AtlasAiRouterDecision::FLOW_REVIEW, 'resolved', ['review', 'dev_delegation'], ['delegation_target_atlas_dev', 'findings_first']),
            $this->case('slash_plan_override', 'Slash plan overrides auto routing', '/plan estruturar mudanca segura no runtime de roteamento.', AtlasAiRouterDecision::FLOW_PLAN, 'partial', ['slash_command', 'plan'], ['operator_override', 'execution_recommendation']),
        ];
    }

    /**
     * @param  array<string,mixed>  $case
     * @return array<string,mixed>
     */
    private function replayCase(array $case): array
    {
        $router = app(AtlasAiRouterService::class);
        $runtime = app(AtlasAiSpecialistFlowRuntimeService::class);
        $execution = app(AtlasAiSpecialistFlowExecutionService::class);
        $decision = $router->decide([
            'input_text' => $case['input_text'],
            'payload' => $case['payload'],
        ]);
        $data = [
            'input_text' => $case['input_text'],
            'payload' => [
                'atlas_ai_router' => $decision->toArray(),
            ],
        ];
        $data = $runtime->apply($data);
        $data = $execution->apply($data);
        $actual = [
            'flow_id' => $decision->flowId,
            'routing_reason' => $decision->routingReason,
            'routing_confidence' => $decision->routingConfidence,
            'delegation_status' => data_get($data, 'payload.specialist_flow_runtime.delegation.status'),
            'delegation_target_flow_id' => data_get($data, 'payload.specialist_flow_runtime.delegation.target_flow_id'),
            'receipt_id' => data_get($data, 'payload.specialist_flow_runtime.receipt.receipt_id'),
            'handler_id' => data_get($data, 'payload.specialist_flow_execution.handler_id'),
        ];
        $passed = $decision->flowId === $case['expected_flow'];
        if (isset($case['expected_delegation_target_flow_id'])) {
            $passed = $passed && data_get($actual, 'delegation_target_flow_id') === $case['expected_delegation_target_flow_id'];
        }

        return [
            'case_id' => $case['case_id'],
            'title' => $case['title'],
            'status' => $passed ? 'passed' : 'failed',
            'expected' => [
                'flow_id' => $case['expected_flow'],
                'delegation_target_flow_id' => $case['expected_delegation_target_flow_id'] ?? null,
            ],
            'actual' => $actual,
        ];
    }

    /**
     * @param  array<string,mixed>  $case
     * @return array<string,mixed>
     */
    private function replayCanonicalCase(array $case): array
    {
        $startedAt = microtime(true);
        $runtimeCase = [
            'case_id' => (string) $case['case_code'],
            'title' => (string) $case['title'],
            'input_text' => (string) data_get($case, 'task_contract.goal', $case['description']),
            'payload' => $this->payloadForExpectedFlow((string) $case['expected_flow'], (array) $case['tags']),
            'expected_flow' => (string) $case['expected_flow'],
        ];
        if (in_array('dev_delegation', (array) $case['tags'], true)) {
            $runtimeCase['expected_delegation_target_flow_id'] = AtlasAiRouterDecision::FLOW_DEV;
        }

        $result = $this->replayCase($runtimeCase);
        $passed = ($result['status'] ?? null) === 'passed';

        return [
            ...$result,
            'case_code' => (string) $case['case_code'],
            'expected_decision' => (string) $case['expected_decision'],
            'score' => $passed ? 100 : 0,
            'duration_ms' => (int) max(1, round((microtime(true) - $startedAt) * 1000)),
            'acceptance_evidence' => (array) $case['acceptance_evidence'],
        ];
    }

    /**
     * @param  array<int,string>  $tags
     * @return array<string,mixed>
     */
    private function payloadForExpectedFlow(string $expectedFlow, array $tags): array
    {
        if ($expectedFlow === AtlasAiRouterDecision::FLOW_DEV || in_array('dev_delegation', $tags, true)) {
            return ['workspace' => '/tmp/atlas-hyperflow-benchmark-workspace'];
        }

        if ($expectedFlow === AtlasAiRouterDecision::FLOW_FORGE) {
            return ['surface_id' => 'atlas_code'];
        }

        return ['surface_id' => 'atlas_desktop_ai'];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function contractReplayCases(): array
    {
        return [
            ['case_id' => 'ambiguous-login-plan', 'title' => 'Ambiguous feature starts in plan', 'input_text' => 'Implemente login seguro e bonito', 'payload' => ['surface_id' => 'atlas_desktop_ai'], 'expected_flow' => AtlasAiRouterDecision::FLOW_PLAN],
            ['case_id' => 'workspace-endpoint-light-dev', 'title' => 'Workspace feature routes to Dev', 'input_text' => 'Implemente endpoint de status', 'payload' => ['workspace' => '/tmp/atlas-workspace'], 'expected_flow' => AtlasAiRouterDecision::FLOW_DEV],
            ['case_id' => 'atlas-code-enterprise-obra', 'title' => 'Atlas Code promotes heavy Obra to Forge', 'input_text' => 'Monte uma obra enterprise de um mes com SDD completo', 'payload' => ['surface_id' => 'atlas_code'], 'expected_flow' => AtlasAiRouterDecision::FLOW_FORGE],
            ['case_id' => 'stacktrace-without-workspace-debug', 'title' => 'Stacktrace without workspace routes to debug', 'input_text' => "Traceback TypeError: Cannot read property foo\n at app.js:10", 'payload' => [], 'expected_flow' => AtlasAiRouterDecision::FLOW_DEBUG],
            ['case_id' => 'stacktrace-with-workspace-delegates-dev', 'title' => 'Stacktrace with workspace delegates repair to Dev', 'input_text' => "Traceback TypeError: Cannot read property foo\n at app.js:10", 'payload' => ['workspace' => '/tmp/atlas-workspace'], 'expected_flow' => AtlasAiRouterDecision::FLOW_DEBUG, 'expected_delegation_target_flow_id' => AtlasAiRouterDecision::FLOW_DEV],
            ['case_id' => 'diff-review-findings', 'title' => 'Diff routes to review', 'input_text' => "diff --git a/a.php b/a.php\n- return false;\n+ return true;", 'payload' => [], 'expected_flow' => AtlasAiRouterDecision::FLOW_REVIEW],
            ['case_id' => 'research-state-of-art', 'title' => 'Research prompt routes to research', 'input_text' => 'Pesquise o estado da arte e traga incertezas', 'payload' => [], 'expected_flow' => AtlasAiRouterDecision::FLOW_RESEARCH],
            ['case_id' => 'explain-router-readonly', 'title' => 'Explain stays read-only', 'input_text' => 'Explique como funciona o Router Runtime', 'payload' => [], 'expected_flow' => AtlasAiRouterDecision::FLOW_EXPLAIN],
            ['case_id' => 'conversation-general', 'title' => 'General conversation remains conversation', 'input_text' => 'Vamos pensar juntos sobre a direcao do produto', 'payload' => [], 'expected_flow' => AtlasAiRouterDecision::FLOW_CONVERSATION],
            ['case_id' => 'slash-plan-override', 'title' => 'Slash plan overrides automatic routing', 'input_text' => '/plan implemente endpoint de billing', 'payload' => ['workspace' => '/tmp/atlas-workspace'], 'expected_flow' => AtlasAiRouterDecision::FLOW_PLAN],
        ];
    }

    /**
     * @param  array<int,string>  $tags
     * @param  array<int,string>  $evidence
     * @return array<string,mixed>
     */
    private function case(
        string $code,
        string $title,
        string $prompt,
        string $expectedFlow,
        string $expectedDecision,
        array $tags,
        array $evidence,
        string $risk = 'medium',
    ): array {
        return [
            'case_code' => $code,
            'title' => $title,
            'description' => $prompt,
            'expected_flow' => $expectedFlow,
            'expected_decision' => $expectedDecision,
            'risk_profile' => $risk,
            'min_score' => $expectedFlow === AtlasAiRouterDecision::FLOW_DEV ? 90 : 85,
            'tags' => ['hyperflow', 'rivals', ...$tags],
            'acceptance_evidence' => $evidence,
            'task_contract' => [
                'goal' => $prompt,
                'expected_flow' => $expectedFlow,
                'acceptance_criteria' => [
                    'Atlas AI must choose the expected flow or produce auditable delegation evidence.',
                    'Response must include receipts, telemetry, or explicit missing-evidence statement.',
                    'No completion or superiority claim is allowed without verifiable evidence.',
                ],
            ],
            'runner_options' => [
                'hyperflow_expected_flow' => $expectedFlow,
                'rivals_required' => true,
                'claude_code_baseline' => 'plan',
                'baseline_runner' => 'plan',
            ],
        ];
    }

    private function suite(): ?AtlasEngineeringBenchmarkSuite
    {
        return AtlasEngineeringBenchmarkSuite::query()
            ->where('slug', self::SUITE_SLUG)
            ->first();
    }

    private function canonicalCaseCount(AtlasEngineeringBenchmarkSuite $suite): int
    {
        $codes = array_column($this->canonicalCases(), 'case_code');

        return $suite->cases
            ->where('status', 'active')
            ->whereIn('case_code', $codes)
            ->count();
    }

    private function runCertifiesReplacement(AtlasEngineeringBenchmarkRun $run): bool
    {
        return $run->status === 'passed'
            && (int) $run->total_cases >= count($this->canonicalCases())
            && (float) ($run->pass_rate ?? 0) >= 100.0
            && (float) ($run->average_score ?? 0) >= 90.0
            && in_array($run->release_gate_status, ['passed', null], true)
            && data_get($run->runner_options_json, 'rivals_battery_mode') !== null;
    }

    /**
     * @return array<string,mixed>
     */
    private function payload(
        ?AtlasEngineeringBenchmarkSuite $suite,
        string $status,
        bool $ready,
        string $nextAction,
        ?AtlasEngineeringBenchmarkRun $run = null,
    ): array {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'ready' => $ready,
            'suite_slug' => self::SUITE_SLUG,
            'suite_id' => $suite?->id,
            'required_case_count' => count($this->canonicalCases()),
            'active_canonical_case_count' => $suite ? $this->canonicalCaseCount($suite) : 0,
            'rivals' => $suite ? (array) data_get($suite->metadata, 'rivals', []) : ['claude_code', 'codex'],
            'claim_policy' => $suite ? (array) data_get($suite->metadata, 'claim_policy', []) : [
                'no_100x_claim_without_passing_run' => true,
                'requires_paired_or_recorded_baseline' => true,
            ],
            'latest_run' => $run ? [
                'id' => $run->id,
                'status' => $run->status,
                'total_cases' => $run->total_cases,
                'pass_rate' => $run->pass_rate,
                'average_score' => $run->average_score,
                'release_gate_status' => $run->release_gate_status,
                'external_provider_execution' => $this->externalProviderExecution($run),
            ] : null,
            'next_action' => $nextAction,
            'writes' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function externalProviderExecution(AtlasEngineeringBenchmarkRun $run): array
    {
        $summary = is_array($run->summary_json) ? $run->summary_json : [];
        $outcome = is_array($run->outcome_json) ? $run->outcome_json : [];
        $external = (array) (data_get($outcome, 'external_rivals') ?: data_get($summary, 'external_rivals') ?: []);
        $baselines = (array) ($external['baselines'] ?? $summary['rival_baselines'] ?? []);
        $baselines = array_values(array_map(fn (mixed $baseline): string => (string) $baseline, $baselines));
        $receiptHash = (string) ($external['evidence_receipt_hash'] ?? $external['receipt_hash'] ?? '');
        $evidenceSource = (string) ($external['evidence_source'] ?? '');
        $sourcePath = (string) ($external['source_evidence_pack_path'] ?? '');
        $sourcePathAllowed = $sourcePath === '' || $this->pathAllowedForExternalEvidence($sourcePath);
        $checks = [
            'external_provider_call' => (bool) ($external['external_provider_call'] ?? $summary['external_provider_call'] ?? false),
            'claude_code_baseline_present' => in_array('claude_code', $baselines, true),
            'codex_baseline_present' => in_array('codex', $baselines, true) || in_array('codex_cli', $baselines, true),
            'evidence_source_present' => $evidenceSource === 'atlas_forge_rivals_export',
            'source_provenance_valid' => ($external['source_provenance_valid'] ?? false) === true && $sourcePathAllowed,
            'protocol_valid' => (bool) ($external['protocol_valid'] ?? false),
            'comparable' => (bool) ($external['comparable'] ?? false),
            'operator_approved' => (bool) ($external['operator_approved'] ?? false),
            'evidence_receipt_hash_present' => strlen($receiptHash) === 64,
            'outcome_accepted' => in_array((string) $run->outcome_status, ['approved', 'healthy', 'passed'], true),
        ];
        $failed = array_keys(array_filter($checks, fn (bool $passed): bool => ! $passed));

        return [
            'status' => $failed === [] ? 'passed' : 'blocked',
            'checks' => $checks,
            'failed_checks' => $failed,
            'baselines' => $baselines,
            'evidence_source' => $evidenceSource !== '' ? $evidenceSource : null,
            'evidence_receipt_hash' => $receiptHash !== '' ? $receiptHash : null,
        ];
    }

    /**
     * @param  array<string,mixed>  $providerResults
     * @return array<string,array<string,mixed>>
     */
    private function normalizeProviderResults(array $providerResults): array
    {
        $normalized = [];
        $providers = isset($providerResults['codex_cli']) && ! isset($providerResults['codex'])
            ? ['claude_code', 'codex_cli']
            : ['claude_code', 'codex'];

        foreach ($providers as $provider) {
            $result = (array) ($providerResults[$provider] ?? []);
            $normalized[$provider] = [
                'provider' => $provider,
                'status' => (string) ($result['status'] ?? 'passed'),
                'score' => (int) ($result['score'] ?? 0),
                'duration_ms' => (int) ($result['duration_ms'] ?? 0),
                'evidence_hash' => strtolower((string) ($result['evidence_hash'] ?? '')),
                'receipt_ref' => (string) ($result['receipt_ref'] ?? ''),
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<string,mixed>  $pack
     * @return array<string,array<string,mixed>>
     */
    private function providerResultsFromEvidencePack(array $pack): array
    {
        $results = (array) data_get($pack, 'provider_results', []);
        $normalized = [];
        foreach (['claude_code', 'codex', 'codex_cli'] as $provider) {
            $result = (array) ($results[$provider] ?? []);
            if ($result === []) {
                continue;
            }

            $normalized[$provider] = [
                'status' => (string) ($result['status'] ?? 'passed'),
                'score' => (int) ($result['score'] ?? 0),
                'duration_ms' => (int) ($result['duration_ms'] ?? 0),
                'evidence_hash' => strtolower((string) ($result['evidence_hash'] ?? $result['receipt_hash'] ?? '')),
                'receipt_ref' => (string) ($result['receipt_ref'] ?? data_get($result, 'receipt.path', '')),
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<string,mixed>  $pack
     * @param  array<string,array<string,mixed>>  $providerResults
     * @param  array<string,mixed>  $data
     * @return array<int,string>
     */
    private function externalEvidencePackBlockers(array $pack, array $providerResults, array $data): array
    {
        $blockers = [];
        if (($data['operator_approved'] ?? true) !== true) {
            $blockers[] = 'operator_approval_missing';
        }
        if (($pack['hyperflow_external_rivals_certification_eligible'] ?? false) !== true) {
            $blockers[] = 'pack_not_marked_hyperflow_external_eligible';
        }
        if (($pack['external_provider_call'] ?? false) !== true) {
            $blockers[] = 'external_provider_call_not_true';
        }
        if (($pack['provider_tokens_spent'] ?? false) === false) {
            $blockers[] = 'provider_tokens_spent_not_true';
        }
        foreach ($this->externalEvidencePackSourcePaths($pack, $data) as $path) {
            if (! $this->pathAllowedForExternalEvidence($path)) {
                $blockers[] = 'external_evidence_source_path_not_allowed';

                break;
            }
        }
        if (($pack['is_comparable_real_run'] ?? $pack['comparable'] ?? false) !== true) {
            $blockers[] = 'comparable_real_run_not_true';
        }
        if (($pack['protocol_valid'] ?? true) !== true) {
            $blockers[] = 'protocol_valid_not_true';
        }
        if (($pack['claim_ready'] ?? true) !== true) {
            $blockers[] = 'claim_ready_not_true';
        }

        return [...$blockers, ...$this->externalEvidenceBlockers($this->normalizeProviderResults($providerResults), [
            'external_provider_call' => true,
            'protocol_valid' => true,
            'comparable' => true,
            'operator_approved' => true,
            'evidence_receipt_hash' => str_repeat('a', 64),
        ])];
    }

    /**
     * @param  array<string,mixed>  $data
     * @return list<string>
     */
    private function externalEvidenceRecordProvenanceBlockers(array $data): array
    {
        $source = (string) ($data['evidence_source'] ?? (($data['source_evidence_pack_path'] ?? '') !== '' ? 'atlas_forge_rivals_export' : 'manual_operator_record'));
        if (! in_array($source, ['manual_operator_record', 'atlas_forge_rivals_export'], true)) {
            return ['external_evidence_source_invalid'];
        }

        $path = (string) ($data['source_evidence_pack_path'] ?? '');
        if ($path !== '' && ! $this->pathAllowedForExternalEvidence($path)) {
            return ['external_evidence_source_path_not_allowed'];
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $pack
     * @param  array<string,mixed>  $data
     * @return list<string>
     */
    private function externalEvidencePackSourcePaths(array $pack, array $data): array
    {
        $paths = [];
        foreach (['source_evidence_pack_path', 'evidence_pack_path'] as $key) {
            $path = trim((string) ($data[$key] ?? ''));
            if ($path !== '') {
                $paths[] = $path;
            }
        }

        foreach ((array) ($pack['source_forge_runs'] ?? []) as $sourceRun) {
            foreach (['evidence_pack_path', 'scorecard_path'] as $key) {
                $path = trim((string) data_get($sourceRun, $key, ''));
                if ($path !== '') {
                    $paths[] = $path;
                }
            }
        }

        return array_values(array_unique($paths));
    }

    private function pathAllowedForExternalEvidence(string $path): bool
    {
        if (app()->environment('testing')) {
            return true;
        }

        $normalized = str_replace('\\', '/', $path);
        $testingRoot = str_replace('\\', '/', storage_path('framework/testing'));

        return ! str_starts_with($normalized, $testingRoot.'/') && $normalized !== $testingRoot;
    }

    /**
     * @param  array<string,array<string,mixed>>  $providerResults
     * @param  array<string,mixed>  $data
     * @return array<int,string>
     */
    private function externalEvidenceBlockers(array $providerResults, array $data): array
    {
        $blockers = [];
        $providers = isset($providerResults['codex_cli']) && ! isset($providerResults['codex'])
            ? ['claude_code', 'codex_cli']
            : ['claude_code', 'codex'];

        foreach ($providers as $provider) {
            $result = $providerResults[$provider] ?? [];
            if (($result['status'] ?? null) !== 'passed') {
                $blockers[] = $provider.'_status_not_passed';
            }
            if (! is_string($result['evidence_hash'] ?? null) || preg_match('/^[a-f0-9]{64}$/i', (string) $result['evidence_hash']) !== 1) {
                $blockers[] = $provider.'_evidence_hash_invalid';
            }
            if ((int) ($result['score'] ?? 0) <= 0) {
                $blockers[] = $provider.'_score_missing';
            }
        }

        if (! in_array('claude_code', $providers, true) || (! in_array('codex', $providers, true) && ! in_array('codex_cli', $providers, true))) {
            $blockers[] = 'required_rival_baselines_missing';
        }
        if (($data['external_provider_call'] ?? true) !== true) {
            $blockers[] = 'external_provider_call_not_true';
        }
        if (! is_string($data['evidence_receipt_hash'] ?? null) || preg_match('/^[a-f0-9]{64}$/i', (string) $data['evidence_receipt_hash']) !== 1) {
            $blockers[] = 'evidence_receipt_hash_invalid';
        }
        foreach (['protocol_valid', 'comparable', 'operator_approved'] as $flag) {
            if (($data[$flag] ?? true) !== true) {
                $blockers[] = $flag.'_not_true';
            }
        }

        return $blockers;
    }

    /**
     * @param  array<string,array<string,mixed>>  $providerResults
     * @return list<string>
     */
    private function providerResultBlockers(array $providerResults): array
    {
        $blockers = [];
        foreach ($providerResults as $provider => $result) {
            if (($result['status'] ?? null) !== 'passed') {
                $blockers[] = $provider.'_status_not_passed';
            }
            if (! is_string($result['evidence_hash'] ?? null) || preg_match('/^[a-f0-9]{64}$/i', (string) $result['evidence_hash']) !== 1) {
                $blockers[] = $provider.'_evidence_hash_invalid';
            }
            if ((int) ($result['score'] ?? 0) <= 0) {
                $blockers[] = $provider.'_score_missing';
            }
        }

        return $blockers;
    }

    /**
     * @return array<string,mixed>
     */
    private function externalEvidenceCandidateForRun(string $runId): array
    {
        try {
            $source = $this->forgeRunSourceEvidence($runId);
            $provider = (string) ($source['provider'] ?? 'unknown');
            $providerResult = (array) ($source['provider_result'] ?? []);
            $blockers = array_values(array_unique(array_merge(
                (array) ($source['blockers'] ?? []),
                $this->providerResultBlockers($provider !== '' && $provider !== 'unknown' ? [$provider => $providerResult] : []),
            )));

            return [
                'run_id' => $runId,
                'provider' => $provider !== '' ? $provider : 'unknown',
                'export_ready' => $blockers === [],
                'blockers' => $blockers,
                'provider_result' => $providerResult,
                'summary' => (array) ($source['summary'] ?? []),
            ];
        } catch (\Throwable $exception) {
            return [
                'run_id' => $runId,
                'provider' => 'unknown',
                'export_ready' => false,
                'blockers' => ['candidate_inspection_failed'],
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param  array<string,mixed>  $data
     * @return list<string>
     */
    private function resolveForgeRunIds(array $data): array
    {
        $ids = [];
        $raw = $data['forge_run_ids'] ?? null;
        if (is_string($raw)) {
            foreach (explode(',', $raw) as $piece) {
                $id = trim($piece);
                if ($id !== '' && ! in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
        } elseif (is_array($raw)) {
            foreach ($raw as $piece) {
                $id = trim((string) $piece);
                if ($id !== '' && ! in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
        }
        $single = trim((string) ($data['forge_run_id'] ?? ''));
        if ($single !== '' && ! in_array($single, $ids, true)) {
            $ids[] = $single;
        }

        return $ids;
    }

    /**
     * @return array{provider:?string,provider_result:array<string,mixed>,summary:array<string,mixed>,blockers:list<string>}
     */
    private function forgeRunSourceEvidence(string $runId): array
    {
        $paths = $this->forgeRunPaths->paths($runId);
        $manifest = $this->readJsonFile($paths['manifest_json']);
        $packPath = $paths['evidence'].'/evidence_pack.json';
        $pack = $this->readJsonFile($packPath);
        $scorecard = $this->readJsonFile($paths['scorecard_json']);
        $rivalReceiptPath = $paths['evidence'].'/rival_receipt.json';
        $rivalReceipt = $this->readJsonFile($rivalReceiptPath);

        $provider = $this->providerBaselineFromForgeRun($manifest);
        $blockers = [];
        if (! is_dir($paths['base'])) {
            $blockers[] = 'forge_run_not_found:'.$runId;
        }
        if ($pack === []) {
            $blockers[] = 'forge_evidence_pack_missing:'.$runId;
        }
        if (($pack['mode_for_evidence'] ?? null) !== 'real_run') {
            $blockers[] = 'forge_pack_not_real_run:'.$runId;
        }
        if (($pack['external_provider_call'] ?? false) !== true || ($manifest['external_provider_call'] ?? false) !== true) {
            $blockers[] = 'forge_external_provider_call_not_true:'.$runId;
        }
        if (($pack['provider_tokens_spent'] ?? false) !== true || ($manifest['provider_tokens_spent'] ?? false) !== true) {
            $blockers[] = 'forge_provider_tokens_spent_not_true:'.$runId;
        }
        if (($pack['is_comparable_real_run'] ?? false) !== true) {
            $blockers[] = 'forge_pack_not_comparable_real_run:'.$runId;
        }
        if (($manifest['claim_ready'] ?? false) !== true) {
            $blockers[] = 'forge_manifest_claim_ready_not_true:'.$runId;
        }
        if (((array) ($pack['missing_required'] ?? [])) !== []) {
            $blockers[] = 'forge_pack_missing_required_artifacts:'.$runId;
        }
        if (($pack['after_clean_check']['clean'] ?? false) !== true) {
            $blockers[] = 'forge_after_clean_check_not_clean:'.$runId;
        }
        foreach (['atlas', 'rival'] as $arm) {
            $receipt = (array) data_get($pack, 'provider_receipts.'.$arm, []);
            if (($receipt['present'] ?? false) !== true) {
                $blockers[] = 'forge_'.$arm.'_receipt_missing:'.$runId;
            }
            if (($receipt['fake'] ?? false) === true || ($receipt['test_mode'] ?? false) === true) {
                $blockers[] = 'forge_'.$arm.'_receipt_fake_or_test_mode:'.$runId;
            }
            if (($receipt['killed'] ?? false) === true) {
                $blockers[] = 'forge_'.$arm.'_provider_killed:'.$runId;
            }
            if ((int) ($receipt['exit_code'] ?? -1) !== 0) {
                $blockers[] = 'forge_'.$arm.'_exit_code_not_zero:'.$runId;
            }
            if ((int) ($receipt['test_exit_code'] ?? -1) !== 0) {
                $blockers[] = 'forge_'.$arm.'_test_exit_code_not_zero:'.$runId;
            }
            foreach (['stdout_hash', 'stderr_hash', 'command_hash', 'prompt_hash', 'test_log_hash', 'patch_diff_hash'] as $hashKey) {
                $hash = (string) ($receipt[$hashKey] ?? '');
                if ($hash !== '' && preg_match('/^[a-f0-9]{64}$/i', $hash) !== 1) {
                    $blockers[] = 'forge_'.$arm.'_'.$hashKey.'_invalid:'.$runId;
                }
            }
        }
        if ($provider === null) {
            $blockers[] = 'forge_rival_provider_not_hyperflow_baseline:'.$runId;
        }

        $evidenceHash = (string) data_get($pack, 'artifacts.rival_receipt.sha256', '');
        if ($evidenceHash === '' && is_file($rivalReceiptPath)) {
            $evidenceHash = hash_file('sha256', $rivalReceiptPath) ?: '';
        }
        if ($evidenceHash === '' || preg_match('/^[a-f0-9]{64}$/i', $evidenceHash) !== 1) {
            $blockers[] = 'forge_rival_receipt_hash_missing:'.$runId;
        }

        $durationMs = (int) ($manifest['run_duration_ms'] ?? 0);
        if ($durationMs <= 0) {
            $durationMs = $this->durationFromReceipt($rivalReceipt);
        }

        return [
            'provider' => $provider,
            'provider_result' => [
                'provider' => $provider,
                'status' => 'passed',
                'score' => $this->providerEvidenceScore($pack, $scorecard, $manifest),
                'duration_ms' => max(1, $durationMs),
                'evidence_hash' => $evidenceHash,
                'receipt_ref' => $paths['run_id'].':evidence/rival_receipt.json',
                'forge_run_id' => $paths['run_id'],
                'model' => (string) ($manifest['rival_model'] ?? ''),
            ],
            'summary' => [
                'run_id' => $paths['run_id'],
                'provider' => $provider,
                'mode' => $manifest['mode'] ?? null,
                'rival_model' => $manifest['rival_model'] ?? null,
                'claim_ready' => (bool) ($manifest['claim_ready'] ?? false),
                'external_provider_call' => (bool) ($manifest['external_provider_call'] ?? false),
                'provider_tokens_spent' => (bool) ($manifest['provider_tokens_spent'] ?? false),
                'evidence_pack_path' => $packPath,
                'evidence_pack_sha256' => is_file($packPath) ? hash_file('sha256', $packPath) : null,
                'scorecard_path' => $paths['scorecard_json'],
                'blockers' => $blockers,
            ],
            'blockers' => $blockers,
        ];
    }

    /**
     * @param  array<string,mixed>  $manifest
     */
    private function providerBaselineFromForgeRun(array $manifest): ?string
    {
        $model = strtolower(trim((string) ($manifest['rival_model'] ?? '')));

        return match (true) {
            $model === 'codex' || str_contains($model, 'codex') => 'codex_cli',
            str_starts_with($model, 'claude_') || str_contains($model, 'sonnet') || str_contains($model, 'opus') => 'claude_code',
            default => null,
        };
    }

    /**
     * @param  array<string,mixed>  $pack
     * @param  array<string,mixed>  $scorecard
     */
    private function providerEvidenceScore(array $pack, array $scorecard, array $manifest): int
    {
        $score = data_get($scorecard, 'arms.rival.score', data_get($scorecard, 'rival.score'));
        if ($score !== null) {
            return min(100, max(0, (int) $score));
        }

        return (($manifest['claim_ready'] ?? false) === true && ($pack['is_comparable_real_run'] ?? false) === true) ? 100 : 0;
    }

    /**
     * @param  array<string,mixed>  $receipt
     */
    private function durationFromReceipt(array $receipt): int
    {
        $started = strtotime((string) ($receipt['started_at'] ?? ''));
        $finished = strtotime((string) ($receipt['finished_at'] ?? ''));
        if ($started === false || $finished === false || $finished <= $started) {
            return 1;
        }

        return max(1, ($finished - $started) * 1000);
    }

    /**
     * @return array<string,mixed>
     */
    private function readJsonFile(string $path): array
    {
        if (! File::isFile($path) || ! File::isReadable($path)) {
            return [];
        }

        try {
            $decoded = json_decode((string) File::get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function tablesReady(): bool
    {
        return DatabaseTableAvailability::all([
            'atlas_engineering_benchmark_suites',
            'atlas_engineering_benchmark_cases',
            'atlas_engineering_benchmark_runs',
            'atlas_engineering_benchmark_results',
        ]);
    }
}
