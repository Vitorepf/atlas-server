<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

/**
 * Atlas Forge Rivals · Statistical Repeat Dry-Run Validator.
 *
 * Executes the statistical-repeat operator plan through the existing
 * ArenaRunService dry-run path. It never shells out and never invokes providers;
 * the purpose is to prove that every planned repeat bucket resolves to a valid
 * Provider Arena contract before an operator considers paid repetitions.
 */
final class AtlasForgeRivalsStatisticalRepeatDryRunService
{
    public function __construct(
        private readonly AtlasForgeRivalsProviderPerformanceLedgerService $ledger,
        private readonly AtlasForgeRivalsArenaRunService $arenaRun,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function validate(array $input): array
    {
        $plan = $this->ledger->statisticalRepeatPlan($input);
        $executionPlan = (array) ($plan['execution_plan'] ?? []);
        $risk = (array) ($executionPlan['operator_cost_risk_summary'] ?? []);
        $inputs = $this->plannedDryRunInputs($executionPlan);
        $limit = $this->limitFromInput($input, count($inputs));
        $selected = array_slice($inputs, 0, $limit);

        $blockers = [];
        if (($risk['status'] ?? null) !== 'ready_for_dry_run_review') {
            $blockers[] = 'statistical_repeat_plan_not_ready_for_dry_run_review';
        }
        if ($inputs === []) {
            $blockers[] = 'statistical_repeat_plan_has_no_dry_run_inputs';
        }

        if ($blockers !== []) {
            return $this->blocked($plan, $blockers, 'php artisan atlas:forge:rivals statistical-repeat-plan --json');
        }

        $results = [];
        $failed = [];
        foreach ($selected as $index => $dryRunInput) {
            $payload = $this->arenaRun->run(array_replace($dryRunInput, [
                'dry_run' => true,
                'confirmations' => [],
            ]));
            $cases = (array) ($payload['cases'] ?? []);
            $caseFilterIntegrity = $this->caseFilterIntegrity($dryRunInput, $cases);
            $ok = ($payload['status'] ?? null) === 'ok'
                && ($payload['dry_run'] ?? null) === true
                && ($payload['external_provider_call'] ?? true) === false
                && ($payload['provider_tokens_spent'] ?? true) === false
                && ($payload['routing_effect'] ?? 'none') === 'none'
                && ($caseFilterIntegrity['status'] ?? null) === 'ok';
            $row = [
                'index' => $index + 1,
                'status' => (string) ($payload['status'] ?? 'unknown'),
                'ok' => $ok,
                'input' => $dryRunInput,
                'case_count' => $payload['case_count'] ?? null,
                'cases_preview' => $this->compactCasesPreview($cases, $dryRunInput),
                'case_filter_integrity' => $caseFilterIntegrity,
                'verdict' => $payload['verdict'] ?? null,
                'requires_external_provider_call' => (bool) ($payload['requires_external_provider_call'] ?? false),
                'external_provider_call' => (bool) ($payload['external_provider_call'] ?? true),
                'provider_tokens_spent' => (bool) ($payload['provider_tokens_spent'] ?? true),
                'routing_effect' => (string) ($payload['routing_effect'] ?? 'unknown'),
                'blockers' => array_values(array_merge(
                    (array) ($payload['blockers'] ?? []),
                    (array) ($caseFilterIntegrity['blockers'] ?? []),
                )),
            ];
            $results[] = $row;
            if (! $ok) {
                $failed[] = $row;
            }
        }

        $status = $failed === [] ? 'ok' : 'blocked';
        $planFingerprint = $this->planFingerprint($inputs);
        $runbook = $this->realExecutionRunbook($selected, $planFingerprint, $failed === [] && count($selected) === count($inputs));
        $runbookPath = $this->writeRunbookIfRequested($runbook, $input);
        $operatorRunbookSummary = $this->operatorRunbookSummary(
            runbook: $runbook,
            plannedCount: count($inputs),
            validatedCount: count($selected),
            failedCount: count($failed),
            validationLimited: count($selected) < count($inputs),
            runbookPath: $runbookPath,
        );

        return [
            'status' => $status,
            'schema_version' => 'atlas.forge.rivals.statistical_repeat_dry_run_validation.v1',
            'plan_fingerprint' => $planFingerprint,
            'plan_status' => $plan['external_claim_readiness_status'] ?? null,
            'statistical_repeat_plan_status' => $plan['statistical_repeat_measurement_plan']['status'] ?? null,
            'operator_plan_status' => $risk['status'] ?? null,
            'planned_dry_run_count' => count($inputs),
            'validated_dry_run_count' => count($selected),
            'validation_limited' => count($selected) < count($inputs),
            'passed_count' => count($selected) - count($failed),
            'failed_count' => count($failed),
            'failures_preview' => array_slice($failed, 0, 10),
            'results_preview' => array_slice($results, 0, 10),
            'ready_for_operator_real_repeat_review' => $failed === [] && count($selected) === count($inputs),
            'operator_runbook_summary' => $operatorRunbookSummary,
            'real_execution_runbook_path' => $runbookPath,
            'real_execution_runbook' => $runbook,
            'real_provider_execution_still_requires_confirmations' => true,
            'claim_ready' => false,
            'external_claim_allowed' => false,
            'score_or_claim_allowed' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'canonical_phrase' => 'Rivals emits measured evidence; Atlas Decide decides model routing.',
            'next_command' => $failed === []
                ? 'review real_execution_runbook before any explicit real provider confirmation'
                : 'fix statistical-repeat dry-run blockers before paid repetitions',
        ];
    }

    /**
     * @param  array<string,mixed>  $executionPlan
     * @return list<array<string,mixed>>
     */
    private function plannedDryRunInputs(array $executionPlan): array
    {
        $inputs = [];
        foreach ((array) ($executionPlan['execution_batches'] ?? []) as $batch) {
            if (! is_array($batch)) {
                continue;
            }
            foreach ((array) ($batch['inputs'] ?? []) as $input) {
                if (is_array($input)) {
                    $inputs[] = $input;
                }
            }
        }

        return $inputs;
    }

    /**
     * @param  list<array<string,mixed>>|array<int,array<string,mixed>>  $cases
     * @return list<array<string,mixed>>
     */
    private function compactCasesPreview(array $cases, array $input): array
    {
        return array_values(array_map(
            fn (array $case): array => [
                'case_id' => $case['case_id'] ?? null,
                'title' => $case['title'] ?? null,
                'case_set' => $this->nonEmptyString($case['case_set'] ?? null)
                    ?? $this->nonEmptyString($case['industrial_case_set'] ?? null),
                'category' => $case['category'] ?? null,
                'task_category' => $case['task_category'] ?? null,
                'difficulty_level' => $case['difficulty_level'] ?? null,
                'role_focus' => $case['role_focus'] ?? null,
                'task_type' => $case['task_type'] ?? null,
                'industrial_domains' => array_values(array_map(
                    static fn ($domain): string => (string) $domain,
                    (array) ($case['industrial_domains'] ?? []),
                )),
                'requested_task_category_match' => $this->caseMatchesRequestedTaskCategory($case, (string) ($input['task_category'] ?? '')),
                'evidence_requirement_count' => count((array) ($case['evidence_requirements'] ?? [])),
                'expected_changed_file_count' => count((array) ($case['expected_changed_files'] ?? [])),
            ],
            array_slice($cases, 0, 5),
        ));
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  list<array<string,mixed>>|array<int,array<string,mixed>>  $cases
     * @return array<string,mixed>
     */
    private function caseFilterIntegrity(array $input, array $cases): array
    {
        $requestedCategory = strtolower(trim((string) ($input['task_category'] ?? '')));
        $requestedDifficulty = strtoupper(trim((string) ($input['difficulty'] ?? $input['difficulty_level'] ?? '')));
        $mismatches = [];
        $matchedBy = [];

        foreach ($cases as $case) {
            if (! is_array($case)) {
                continue;
            }
            $caseId = (string) ($case['case_id'] ?? 'unknown_case');
            $categoryMatch = $requestedCategory === ''
                ? ['ok' => true, 'basis' => 'not_requested']
                : $this->caseMatchesRequestedTaskCategory($case, $requestedCategory);
            $difficultyMatch = $requestedDifficulty === ''
                || strtoupper((string) ($case['difficulty_level'] ?? $case['difficulty'] ?? '')) === $requestedDifficulty;

            if (($categoryMatch['ok'] ?? false) !== true) {
                $mismatches[] = [
                    'case_id' => $caseId,
                    'dimension' => 'task_category',
                    'requested' => $requestedCategory,
                    'actual_task_category' => $case['task_category'] ?? null,
                    'actual_category' => $case['category'] ?? null,
                    'actual_task_type' => $case['task_type'] ?? null,
                    'actual_industrial_domains' => array_values((array) ($case['industrial_domains'] ?? [])),
                ];
            }
            if (! $difficultyMatch) {
                $mismatches[] = [
                    'case_id' => $caseId,
                    'dimension' => 'difficulty',
                    'requested' => $requestedDifficulty,
                    'actual' => $case['difficulty_level'] ?? $case['difficulty'] ?? null,
                ];
            }

            $basis = (string) ($categoryMatch['basis'] ?? 'unknown');
            $matchedBy[$basis] = ($matchedBy[$basis] ?? 0) + 1;
        }

        $blockers = array_values(array_map(
            static fn (array $mismatch): string => 'statistical_repeat_case_filter_mismatch:'
                .$mismatch['case_id'].':'.$mismatch['dimension'],
            array_slice($mismatches, 0, 10),
        ));

        return [
            'schema_version' => 'atlas.forge.rivals.statistical_repeat_case_filter_integrity.v1',
            'status' => $mismatches === [] ? 'ok' : 'blocked',
            'requested_task_category' => $requestedCategory !== '' ? $requestedCategory : null,
            'requested_difficulty' => $requestedDifficulty !== '' ? $requestedDifficulty : null,
            'case_count' => count($cases),
            'matched_by' => $matchedBy,
            'mismatch_count' => count($mismatches),
            'mismatches_preview' => array_slice($mismatches, 0, 10),
            'blockers' => $blockers,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'routing_effect' => 'none',
        ];
    }

    /**
     * @return array{ok:bool,basis:string}
     */
    private function caseMatchesRequestedTaskCategory(array $case, string $requestedCategory): array
    {
        $requested = strtolower(trim($requestedCategory));
        if ($requested === '') {
            return ['ok' => true, 'basis' => 'not_requested'];
        }

        $category = strtolower(trim((string) ($case['category'] ?? '')));
        $taskCategory = strtolower(trim((string) ($case['task_category'] ?? '')));
        $taskType = strtolower(trim((string) ($case['task_type'] ?? '')));
        $domains = array_values(array_map(
            static fn ($domain): string => strtolower(trim((string) $domain)),
            (array) ($case['industrial_domains'] ?? []),
        ));

        $legacyAliases = [
            'backend' => 'backend_logic',
            'frontend' => 'frontend_ui',
            'bugfix' => 'realistic_bugfix',
            'tests' => 'test_design',
            'docs' => 'planning',
            'performance' => 'integration_performance',
            'integration' => 'integration_performance',
        ];
        $canonical = $legacyAliases[$requested] ?? $requested;

        if ($taskCategory === $requested) {
            return ['ok' => true, 'basis' => 'task_category'];
        }
        if ($category === $canonical) {
            return ['ok' => true, 'basis' => 'canonical_category'];
        }
        if (in_array($requested, $domains, true)) {
            return ['ok' => true, 'basis' => 'industrial_domain'];
        }
        if ($taskType === $requested) {
            return ['ok' => true, 'basis' => 'task_type'];
        }

        return ['ok' => false, 'basis' => 'no_match'];
    }

    private function nonEmptyString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function limitFromInput(array $input, int $available): int
    {
        $raw = $input['n_tasks'] ?? null;
        if (is_numeric($raw) && (int) $raw > 0) {
            return min($available, (int) $raw);
        }

        return $available;
    }

    /**
     * @param  list<array<string,mixed>>  $inputs
     * @return array<string,mixed>
     */
    private function realExecutionRunbook(array $inputs, string $planFingerprint, bool $fullPlanValidated): array
    {
        $entries = array_values(array_map(
            fn (array $input, int $index): array => $this->realRunbookEntry($input, $planFingerprint, $index + 1),
            $inputs,
            array_keys($inputs),
        ));
        $allRunIds = array_values(array_map(static fn (array $entry): string => (string) $entry['run_id'], $entries));
        $runbookId = 'statrep-'.substr($planFingerprint, 0, 12);
        $batches = [];
        foreach (array_chunk($entries, 10) as $index => $batch) {
            $batchRunIds = array_values(array_map(static fn (array $entry): string => (string) $entry['run_id'], $batch));
            $batches[] = [
                'batch' => $index + 1,
                'command_count' => count($batch),
                'run_ids' => $batchRunIds,
                'commands' => array_values(array_map(static fn (array $entry): string => (string) $entry['command'], $batch)),
                'post_run_commands' => array_values(array_map(static fn (array $entry): array => (array) $entry['post_run_commands'], $batch)),
                'batch_post_run_commands' => $this->aggregatePostRunCommands($batchRunIds, $runbookId.'-batch-'.sprintf('%02d', $index + 1)),
                'entries' => $batch,
                'operator_must_review_cost_before_running' => true,
                'external_provider_call_if_operator_runs' => true,
                'provider_tokens_spent_if_operator_runs' => true,
            ];
        }

        return [
            'schema_version' => 'atlas.forge.rivals.statistical_repeat_real_execution_runbook.v1',
            'status' => $fullPlanValidated ? 'ready_for_human_cost_review' : 'dry_run_subset_only_not_ready_for_real_execution',
            'plan_fingerprint' => $planFingerprint,
            'run_id_prefix' => $runbookId,
            'command_count' => count($entries),
            'batch_count' => count($batches),
            'batches' => $batches,
            'final_post_run_commands' => $this->aggregatePostRunCommands($allRunIds, $runbookId),
            'required_confirmations_before_any_command' => [
                'confirm_runbook_reviewed',
                'confirm_provider_cost',
                'confirm_real_provider_call',
            ],
            'must_rerun_before_real_execution' => 'php artisan atlas:forge:rivals statistical-repeat-dry-run --json',
            'post_run_required_sequence' => [
                'collect_evidence',
                'replay_strict',
                'adjudicate',
                'ledger_record',
                'matrix_report',
                'decide_learning_recheck',
            ],
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'planning_only' => true,
            'routing_effect' => 'none',
            'score_or_claim_allowed' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $runbook
     * @return array<string,mixed>
     */
    private function operatorRunbookSummary(
        array $runbook,
        int $plannedCount,
        int $validatedCount,
        int $failedCount,
        bool $validationLimited,
        ?string $runbookPath,
    ): array {
        $batches = array_values((array) ($runbook['batches'] ?? []));
        $firstBatch = (array) ($batches[0] ?? []);
        $firstCommands = array_values((array) ($firstBatch['commands'] ?? []));
        $firstEntries = array_values((array) ($firstBatch['entries'] ?? []));
        $firstCommand = (string) ($firstCommands[0] ?? '');
        $firstEntry = (array) ($firstEntries[0] ?? []);
        $finalPostRun = (array) ($runbook['final_post_run_commands'] ?? []);

        return [
            'schema_version' => 'atlas.forge.rivals.statistical_repeat_operator_runbook_summary.v1',
            'status' => $failedCount === 0 && ! $validationLimited
                ? 'ready_for_human_cost_review'
                : 'not_ready_for_real_execution',
            'run_id_prefix' => $runbook['run_id_prefix'] ?? null,
            'plan_fingerprint' => $runbook['plan_fingerprint'] ?? null,
            'planned_dry_run_count' => $plannedCount,
            'validated_dry_run_count' => $validatedCount,
            'failed_dry_run_count' => $failedCount,
            'validation_limited' => $validationLimited,
            'real_command_count' => (int) ($runbook['command_count'] ?? 0),
            'batch_count' => (int) ($runbook['batch_count'] ?? 0),
            'first_run_id' => $firstEntry['run_id'] ?? null,
            'first_real_command' => $firstCommand,
            'first_post_run_commands' => (array) ($firstEntry['post_run_commands'] ?? []),
            'first_batch_post_run_commands' => $this->abbreviateAggregateCommands((array) ($firstBatch['batch_post_run_commands'] ?? [])),
            'final_post_run_commands_preview' => $this->abbreviateAggregateCommands($finalPostRun),
            'required_confirmations_before_any_real_command' => $runbook['required_confirmations_before_any_command'] ?? [],
            'post_run_required_sequence' => $runbook['post_run_required_sequence'] ?? [],
            'required_before_claim_or_atlas_decide_policy_review' => [
                'all_planned_real_runs_complete',
                'evidence_pack_green',
                'strict_replay_green',
                'scorecard_per_case_present',
                'adjudicator_green',
                'matrix_report_green',
                'ledger_record_green',
                'statistical_repeat_confidence_ready',
                'atlas_decide_learning_packet_rechecked',
                'human_external_certification_review',
            ],
            'runbook_artifact_path' => $runbookPath,
            'operator_warning' => 'Do not run real commands until cost, provider call and runbook confirmations are intentionally approved.',
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'external_provider_call_if_operator_runs_real_commands' => true,
            'provider_tokens_spent_if_operator_runs_real_commands' => true,
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
    }

    /**
     * @param  array<string,string>  $commands
     * @return array<string,string>
     */
    private function abbreviateAggregateCommands(array $commands): array
    {
        $out = [];
        foreach ($commands as $key => $command) {
            $out[(string) $key] = $this->abbreviateRunIdsInCommand((string) $command);
        }

        return $out;
    }

    private function abbreviateRunIdsInCommand(string $command): string
    {
        return preg_replace('/--run-ids=\\S+/', '--run-ids=<planned-run-ids>', $command) ?? $command;
    }

    /**
     * @param  array<string,mixed>  $runbook
     * @param  array<string,mixed>  $input
     */
    private function writeRunbookIfRequested(array $runbook, array $input): ?string
    {
        $path = $input['output_path'] ?? null;
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $path = trim($path);
        $dir = dirname($path);
        if ($dir !== '' && $dir !== '.' && ! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }

        file_put_contents($path, $this->stableJson($runbook).PHP_EOL);

        return $path;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function realRunbookEntry(array $input, string $planFingerprint, int $index): array
    {
        $runId = $this->runIdFor($planFingerprint, $index);

        return [
            'run_id' => $runId,
            'sequence' => $index,
            'input' => $input,
            'command' => $this->realCommand($input, $runId),
            'post_run_commands' => $this->postRunCommands($input, $runId),
            'external_provider_call_if_operator_runs' => true,
            'provider_tokens_spent_if_operator_runs' => true,
            'routing_effect' => 'none',
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function realCommand(array $input, string $runId): string
    {
        $parts = [
            'php artisan atlas:forge:rivals run-arena',
            '--run-id='.$runId,
            '--case-set='.(string) ($input['case_set'] ?? 'statistical-repeat'),
            '--mode='.(string) ($input['mode'] ?? 'provider_arena'),
            '--prompt-mode='.(string) ($input['prompt_mode'] ?? 'human-normal'),
            '--task-category='.(string) ($input['task_category'] ?? 'bugfix'),
            '--difficulty='.(string) ($input['difficulty'] ?? 'L3'),
        ];

        foreach ([
            'run_family' => 'run-family',
            'arm_a' => 'arm-a',
            'arm_a_model' => 'arm-a-model',
            'arm_b' => 'arm-b',
            'arm_b_model' => 'arm-b-model',
        ] as $key => $option) {
            $value = $input[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $parts[] = '--'.$option.'='.$value;
            }
        }

        $parts[] = '--confirm-runbook-reviewed';
        $parts[] = '--confirm-provider-cost';
        $parts[] = '--confirm-real-provider-call';
        $parts[] = '--json';

        return implode(' ', $parts);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,string>
     */
    private function postRunCommands(array $input, string $runId): array
    {
        $taskCategory = (string) ($input['task_category'] ?? 'bugfix');

        return [
            'replay_strict' => 'php artisan atlas:forge:rivals replay --run-id='.$runId.' --strict --json',
            'adjudicate' => 'php artisan atlas:forge:rivals adjudicate --run-id='.$runId.' --json',
            'ledger_record' => 'php artisan atlas:forge:rivals ledger-record --run-id='.$runId.' --task-category='.$taskCategory.' --role=builder --json',
            'decide_learning_recheck' => 'php artisan atlas:forge:rivals decide-learning --json',
        ];
    }

    /**
     * @param  list<string>  $runIds
     * @return array<string,string>
     */
    private function aggregatePostRunCommands(array $runIds, string $batteryId): array
    {
        if ($runIds === []) {
            return [
                'status' => 'no_run_ids_available',
            ];
        }

        $runIdsOption = implode(',', $runIds);

        return [
            'battery_evidence' => 'php artisan atlas:forge:rivals battery-evidence --run-ids='.$runIdsOption.' --battery-id='.$batteryId.' --json',
            'battery_verify_evidence' => 'php artisan atlas:forge:rivals battery-verify-evidence --run-ids='.$runIdsOption.' --battery-id='.$batteryId.' --strict --json',
            'matrix_report' => 'php artisan atlas:forge:rivals matrix-report --run-ids='.$runIdsOption.' --battery-id='.$batteryId.' --strict --json',
            'decide_learning_recheck' => 'php artisan atlas:forge:rivals decide-learning --json',
        ];
    }

    private function runIdFor(string $planFingerprint, int $index): string
    {
        return sprintf('statrep-%s-%03d', substr($planFingerprint, 0, 12), $index);
    }

    /**
     * @param  list<array<string,mixed>>  $inputs
     */
    private function planFingerprint(array $inputs): string
    {
        return hash('sha256', $this->stableJson($inputs));
    }

    private function stableJson(mixed $value): string
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as $key => $entry) {
                $value[$key] = $this->stableJsonValue($entry);
            }
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($encoded) ? $encoded : '';
    }

    private function stableJsonValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $entry) {
            $value[$key] = $this->stableJsonValue($entry);
        }

        return $value;
    }

    /**
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    private function blocked(array $plan, array $blockers, string $nextCommand): array
    {
        return [
            'status' => 'blocked',
            'schema_version' => 'atlas.forge.rivals.statistical_repeat_dry_run_validation.v1',
            'blockers' => $blockers,
            'operator_plan_status' => data_get($plan, 'execution_plan.operator_cost_risk_summary.status'),
            'planned_dry_run_count' => 0,
            'validated_dry_run_count' => 0,
            'ready_for_operator_real_repeat_review' => false,
            'claim_ready' => false,
            'external_claim_allowed' => false,
            'score_or_claim_allowed' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'canonical_phrase' => 'Rivals emits measured evidence; Atlas Decide decides model routing.',
            'next_command' => $nextCommand,
        ];
    }
}
