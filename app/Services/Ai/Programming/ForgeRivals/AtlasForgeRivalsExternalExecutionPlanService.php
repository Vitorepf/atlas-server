<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

/**
 * Atlas Forge Rivals · External Execution Plan v1.
 *
 * Plan-only matrix for the paid/reproducible benchmark step. It turns the
 * current provider/model/category/difficulty coverage gap into an operator
 * run plan, while keeping real execution, claims and Atlas Decide topology
 * changes hard-blocked.
 */
final class AtlasForgeRivalsExternalExecutionPlanService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.external_execution_plan.v1';

    public function __construct(
        private readonly AtlasForgeRivalsExternalExecutionPreflightService $preflight,
        private readonly AtlasForgeRivalsExternalLearningGapService $learningGap,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function plan(array $input): array
    {
        $caseSet = trim((string) ($input['case_set'] ?? ''));
        $normalizedInput = array_replace($input, [
            'case_set' => $caseSet !== '' ? $caseSet : 'industrial-50',
            'include_full_matrix' => true,
        ]);

        $preflight = $this->preflight->snapshot($normalizedInput);
        $gap = $this->learningGap->report($normalizedInput);
        $rows = array_values(array_filter(
            (array) ($gap['rows'] ?? $gap['rows_preview'] ?? []),
            static fn ($row): bool => is_array($row),
        ));
        $missingRows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => (int) ($row['missing_valid_evidence_count'] ?? 0) > 0,
        ));

        $preflightBlockers = array_values(array_merge(
            (array) ($preflight['blockers'] ?? []),
            (array) ($gap['blockers'] ?? []),
        ));
        $status = $preflightBlockers === [] ? 'ok' : 'blocked';
        $missingRunCount = array_sum(array_map(
            static fn (array $row): int => (int) ($row['missing_valid_evidence_count'] ?? 0),
            $missingRows,
        ));
        $matrixSummary = $this->matrixSummary($rows);
        $executionBatches = $this->executionBatchesPreview($missingRows);
        $modelGapSummary = $this->modelGapSummary($rows, $missingRows);
        $strongClaimGate = [
            'schema_version' => 'atlas.forge.rivals.external_execution_plan_claim_gate.v1',
            'status' => 'blocked_until_real_reproducible_evidence_complete',
            'requires' => [
                'minimum_50_successful_external_runs',
                'provider_receipts_per_arm',
                'evidence_pack_green_per_case',
                'scorecard_green_per_case',
                'replay_green',
                'matrix_lock_green',
                'statistical_repeat_confidence_ready',
                'atlas_decide_learning_eligibility_ok',
                'human_external_certification',
            ],
            'missing_external_run_count' => $missingRunCount,
            'claim_ready' => false,
            'external_claim_allowed' => false,
            'score_or_claim_allowed' => false,
        ];
        $manifest = $this->operatorRunbookManifest(
            input: $normalizedInput,
            matrixSummary: $matrixSummary,
            executionBatches: $executionBatches,
            modelGapSummary: $modelGapSummary,
            missingRows: $missingRows,
            strongClaimGate: $strongClaimGate,
        );
        $export = $this->writeManifestIfRequested($manifest, $normalizedInput);
        if (($export['status'] ?? null) === 'blocked') {
            $preflightBlockers[] = (string) ($export['blocker'] ?? 'external_execution_plan_manifest_write_failed');
            $status = 'blocked';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'plan_status' => $status === 'ok'
                ? 'ready_for_operator_review_before_paid_external_runs'
                : 'blocked_before_operator_review',
            'case_set' => $normalizedInput['case_set'],
            'preflight_status' => $preflight['status'] ?? null,
            'learning_gap_status' => $gap['learning_gap_status'] ?? null,
            'provider_count' => $preflight['provider_count'] ?? 0,
            'target_bucket_count' => $gap['target_bucket_count'] ?? count($rows),
            'covered_bucket_count' => $gap['covered_bucket_count'] ?? 0,
            'missing_bucket_count' => $gap['missing_bucket_count'] ?? count($missingRows),
            'coverage_ratio' => $gap['coverage_ratio'] ?? 0.0,
            'minimum_valid_evidence_per_bucket' => $gap['minimum_valid_evidence_per_bucket'] ?? null,
            'planned_missing_external_run_count' => $missingRunCount,
            'runner_bridge_contract' => $preflight['runner_bridge_contract'] ?? null,
            'provider_binary_checks' => $preflight['provider_binary_checks'] ?? [],
            'execution_matrix_summary' => $matrixSummary,
            'model_gap_summary' => $modelGapSummary,
            'execution_batches_preview' => $executionBatches,
            'missing_buckets_preview' => array_slice($missingRows, 0, 50),
            'next_commands_preview' => array_values(array_unique(array_map(
                static fn (array $row): string => (string) ($row['next_measurement_command'] ?? ''),
                array_slice($missingRows, 0, 25),
            ))),
            'required_confirmations_before_any_real_command' => [
                'confirm_runbook_reviewed',
                'confirm_provider_cost',
                'confirm_real_provider_call',
            ],
            'external_execution_runbook_manifest' => $manifest,
            'external_execution_runbook_manifest_path' => $export['path'] ?? null,
            'external_execution_runbook_manifest_hash' => $export['sha256'] ?? null,
            'strong_claim_gate' => $strongClaimGate,
            'atlas_decide_learning_effect' => $missingRunCount === 0
                ? 'coverage_floor_met_recheck_replay_matrix_statistical_repeat_before_policy_review'
                : 'execute_missing_external_runs_then_ingest_replay_matrix_and_record_ledger_before_model_preference',
            'blockers' => array_values(array_unique(array_map('strval', $preflightBlockers))),
            'real_execution_allowed_by_this_plan' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'claim_ready' => false,
            'external_claim_allowed' => false,
            'score_or_claim_allowed' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'canonical_phrase' => 'Rivals emits measured evidence; Atlas Decide decides model routing.',
            'next_command' => $status === 'ok'
                ? 'review this plan, then run external providers only with explicit confirmations'
                : 'fix preflight blockers before planning paid external runs',
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @param  list<array<string,mixed>>  $missingRows
     * @return array<string,mixed>
     */
    private function modelGapSummary(array $rows, array $missingRows): array
    {
        $targets = [];
        foreach ($rows as $row) {
            $key = implode('|', [
                (string) ($row['provider_family'] ?? $row['provider'] ?? 'unknown'),
                (string) ($row['model'] ?? 'unknown'),
            ]);
            $targets[$key] ??= [
                'provider_family' => $row['provider_family'] ?? $row['provider'] ?? null,
                'provider' => $row['provider'] ?? null,
                'model' => $row['model'] ?? null,
                'model_id' => $row['model_id'] ?? null,
                'target_bucket_count' => 0,
                'covered_bucket_count' => 0,
                'missing_bucket_count' => 0,
                'missing_external_run_count' => 0,
                'task_categories' => [],
                'difficulty_levels' => [],
                'roles' => [],
            ];
            $targets[$key]['target_bucket_count']++;
            $targets[$key]['task_categories'][] = (string) ($row['task_category'] ?? '');
            $targets[$key]['difficulty_levels'][] = (string) ($row['difficulty_level'] ?? '');
            $targets[$key]['roles'][] = (string) ($row['role'] ?? '');
            if ((int) ($row['missing_valid_evidence_count'] ?? 0) === 0 && (array) ($row['model_resolution_blockers'] ?? []) === []) {
                $targets[$key]['covered_bucket_count']++;
            }
        }

        foreach ($missingRows as $row) {
            $key = implode('|', [
                (string) ($row['provider_family'] ?? $row['provider'] ?? 'unknown'),
                (string) ($row['model'] ?? 'unknown'),
            ]);
            if (! isset($targets[$key])) {
                continue;
            }
            $targets[$key]['missing_bucket_count']++;
            $targets[$key]['missing_external_run_count'] += (int) ($row['missing_valid_evidence_count'] ?? 0);
        }

        $rowsOut = [];
        foreach ($targets as $row) {
            $targetCount = max(1, (int) ($row['target_bucket_count'] ?? 0));
            $coveredCount = (int) ($row['covered_bucket_count'] ?? 0);
            $missingRunCount = (int) ($row['missing_external_run_count'] ?? 0);
            $rowsOut[] = [
                'provider_family' => $row['provider_family'],
                'provider' => $row['provider'],
                'model' => $row['model'],
                'model_id' => $row['model_id'],
                'target_bucket_count' => $targetCount,
                'covered_bucket_count' => $coveredCount,
                'missing_bucket_count' => (int) ($row['missing_bucket_count'] ?? 0),
                'missing_external_run_count' => $missingRunCount,
                'coverage_ratio' => round($coveredCount / $targetCount, 4),
                'readiness_status' => $missingRunCount === 0
                    ? 'coverage_floor_met_recheck_replay_matrix_repeat'
                    : 'needs_external_runs_for_model_bucket',
                'task_categories' => $this->uniqueNonEmpty($row['task_categories']),
                'difficulty_levels' => $this->uniqueNonEmpty($row['difficulty_levels']),
                'roles' => $this->uniqueNonEmpty($row['roles']),
            ];
        }

        usort($rowsOut, static function (array $a, array $b): int {
            $missing = ((int) ($b['missing_external_run_count'] ?? 0)) <=> ((int) ($a['missing_external_run_count'] ?? 0));
            if ($missing !== 0) {
                return $missing;
            }

            return strcmp((string) ($a['provider_family'] ?? ''), (string) ($b['provider_family'] ?? ''))
                ?: strcmp((string) ($a['model'] ?? ''), (string) ($b['model'] ?? ''));
        });

        $missingModels = array_values(array_filter(
            $rowsOut,
            static fn (array $row): bool => (int) ($row['missing_external_run_count'] ?? 0) > 0,
        ));

        return [
            'schema_version' => 'atlas.forge.rivals.external_execution_model_gap_summary.v1',
            'status' => $missingModels === [] ? 'coverage_floor_met' : 'needs_external_runs',
            'model_count' => count($rowsOut),
            'models_missing_evidence_count' => count($missingModels),
            'total_missing_external_run_count' => array_sum(array_map(
                static fn (array $row): int => (int) ($row['missing_external_run_count'] ?? 0),
                $missingModels,
            )),
            'rows' => $rowsOut,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'claim_ready' => false,
            'routing_effect' => 'none',
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    private function matrixSummary(array $rows): array
    {
        $providers = [];
        $models = [];
        $categories = [];
        $difficulties = [];
        foreach ($rows as $row) {
            $providers[] = (string) ($row['provider_family'] ?? $row['provider'] ?? '');
            $models[] = (string) ($row['model'] ?? '');
            $categories[] = (string) ($row['task_category'] ?? '');
            $difficulties[] = (string) ($row['difficulty_level'] ?? '');
        }

        return [
            'schema_version' => 'atlas.forge.rivals.external_execution_matrix_summary.v1',
            'row_count' => count($rows),
            'providers' => $this->uniqueNonEmpty($providers),
            'models' => $this->uniqueNonEmpty($models),
            'task_categories' => $this->uniqueNonEmpty($categories),
            'difficulty_levels' => $this->uniqueNonEmpty($difficulties),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $missingRows
     * @return list<array<string,mixed>>
     */
    private function executionBatchesPreview(array $missingRows): array
    {
        $buckets = [];
        foreach ($missingRows as $row) {
            $key = implode('|', [
                (string) ($row['provider_family'] ?? $row['provider'] ?? 'unknown'),
                (string) ($row['model'] ?? 'unknown'),
            ]);
            $buckets[$key] ??= [
                'provider_family' => $row['provider_family'] ?? $row['provider'] ?? null,
                'model' => $row['model'] ?? null,
                'model_id' => $row['model_id'] ?? null,
                'missing_bucket_count' => 0,
                'missing_external_run_count' => 0,
                'dry_run_commands_preview' => [],
                'real_execution_command_templates_preview' => [],
            ];
            $buckets[$key]['missing_bucket_count']++;
            $buckets[$key]['missing_external_run_count'] += (int) ($row['missing_valid_evidence_count'] ?? 0);
            if (count($buckets[$key]['dry_run_commands_preview']) < 5 && is_string($row['dry_run_command'] ?? null)) {
                $buckets[$key]['dry_run_commands_preview'][] = $row['dry_run_command'];
            }
            if (count($buckets[$key]['real_execution_command_templates_preview']) < 5 && is_string($row['real_execution_command_template'] ?? null)) {
                $buckets[$key]['real_execution_command_templates_preview'][] = $row['real_execution_command_template'];
            }
        }

        return array_slice(array_values($buckets), 0, 20);
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $matrixSummary
     * @param  list<array<string,mixed>>  $executionBatches
     * @param  array<string,mixed>  $modelGapSummary
     * @param  list<array<string,mixed>>  $missingRows
     * @param  array<string,mixed>  $strongClaimGate
     * @return array<string,mixed>
     */
    private function operatorRunbookManifest(
        array $input,
        array $matrixSummary,
        array $executionBatches,
        array $modelGapSummary,
        array $missingRows,
        array $strongClaimGate,
    ): array {
        $manifest = [
            'schema_version' => 'atlas.forge.rivals.external_execution_runbook_manifest.v1',
            'status' => 'plan_only_requires_operator_review',
            'case_set' => $input['case_set'] ?? 'industrial-50',
            'filters' => [
                'provider' => $input['provider'] ?? null,
                'task_category' => $input['task_category'] ?? $input['category'] ?? null,
                'difficulty' => $input['difficulty'] ?? $input['difficulty_level'] ?? null,
                'role' => $input['role'] ?? null,
                'run_family' => $input['run_family'] ?? null,
                'prompt_mode' => $input['prompt_mode'] ?? null,
            ],
            'execution_matrix_summary' => $matrixSummary,
            'model_gap_summary' => $modelGapSummary,
            'execution_batches' => $executionBatches,
            'missing_buckets' => array_map(
                static fn (array $row): array => [
                    'provider_family' => $row['provider_family'] ?? null,
                    'provider' => $row['provider'] ?? null,
                    'case_set' => $row['case_set'] ?? null,
                    'model' => $row['model'] ?? null,
                    'model_id' => $row['model_id'] ?? null,
                    'task_category' => $row['task_category'] ?? null,
                    'difficulty_level' => $row['difficulty_level'] ?? null,
                    'role' => $row['role'] ?? null,
                    'valid_evidence_count' => $row['valid_evidence_count'] ?? 0,
                    'required_valid_evidence_count' => $row['required_valid_evidence_count'] ?? null,
                    'missing_valid_evidence_count' => $row['missing_valid_evidence_count'] ?? 0,
                    'dry_run_command' => $row['dry_run_command'] ?? $row['next_measurement_command'] ?? null,
                    'real_execution_command_template' => $row['real_execution_command_template'] ?? null,
                    'required_confirmations_before_real_execution' => $row['required_confirmations_before_real_execution'] ?? [
                        'confirm_runbook_reviewed',
                        'confirm_provider_cost',
                        'confirm_real_provider_call',
                    ],
                    'next_measurement_command' => $row['next_measurement_command'] ?? $row['dry_run_command'] ?? null,
                ],
                $missingRows,
            ),
            'operator_sequence' => [
                '1_review_this_manifest_and_provider_cost',
                '2_run_each_next_measurement_command_only_with_explicit_confirmations',
                '3_collect_evidence_pack_per_case',
                '4_run_replay_strict',
                '5_run_matrix_report_and_evidence_lock',
                '6_record_provider_performance_ledger',
                '7_recheck_decide_learning_packet',
                '8_keep_external_claim_blocked_until_human_certification',
            ],
            'strong_claim_gate' => $strongClaimGate,
            'required_confirmations_before_any_real_command' => [
                'confirm_runbook_reviewed',
                'confirm_provider_cost',
                'confirm_real_provider_call',
            ],
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'claim_ready' => false,
            'external_claim_allowed' => false,
            'score_or_claim_allowed' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'canonical_phrase' => 'Rivals emits measured evidence; Atlas Decide decides model routing.',
        ];
        $manifest['plan_fingerprint'] = hash('sha256', $this->stableJson($manifest));

        return $manifest;
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function writeManifestIfRequested(array $manifest, array $input): array
    {
        $path = trim((string) ($input['output_path'] ?? ''));
        if ($path === '') {
            return ['status' => 'not_requested'];
        }

        $dir = dirname($path);
        if ($dir !== '' && ! is_dir($dir) && ! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            return [
                'status' => 'blocked',
                'path' => $path,
                'blocker' => 'external_execution_plan_manifest_directory_not_writable:'.$dir,
            ];
        }

        $json = $this->stableJson($manifest).PHP_EOL;
        if (@file_put_contents($path, $json) === false) {
            return [
                'status' => 'blocked',
                'path' => $path,
                'blocker' => 'external_execution_plan_manifest_write_failed:'.$path,
            ];
        }

        return [
            'status' => 'ok',
            'path' => $path,
            'sha256' => hash('sha256', $json),
        ];
    }

    /**
     * @param  array<string,mixed>|list<mixed>  $value
     */
    private function stableJson(array $value): string
    {
        $normalized = $this->ksortRecursive($value);

        return (string) json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function ksortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->ksortRecursive($item), $value);
        }
        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->ksortRecursive($item), $value);
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function uniqueNonEmpty(array $values): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (string $value): string => trim($value), $values),
            static fn (string $value): bool => $value !== '',
        )));
    }
}
