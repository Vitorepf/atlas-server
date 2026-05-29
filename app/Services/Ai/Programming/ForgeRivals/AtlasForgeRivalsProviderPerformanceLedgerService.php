<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Atlas Forge Rivals · Provider Performance Ledger.
 *
 * Append-only local ledger of every Rivals/Provider Arena run, captured by
 * provider × model × role × task_category × mode. Becomes the canonical
 * substrate that lets Atlas Decide learn — without ever spending tokens here.
 *
 * The ledger:
 *   - reads scorecard + manifest produced by the existing adjudicator/run-real
 *     pipeline (no provider calls of its own);
 *   - persists each record as one JSON file under `ledger/entries/<id>.json`
 *     plus a single append-only `ledger/entries.jsonl` index;
 *   - never overwrites: re-recording the same run_id yields a new entry with
 *     a fresh `entry_id` and the prior entries stay intact;
 *   - rejects records that lack the canonical safety fields (run_id,
 *     evidence_pack_hash, task_category, role);
 *   - keeps `claim_ready=false` by default for every entry — the ledger
 *     records evidence, it does NOT decide a claim;
 *   - never unblocks `external_rivals_certification` and never reads the
 *     real provider receipts beyond the local scorecard JSON.
 *
 * Schema: atlas.forge.rivals.provider_performance_ledger_entry.v1
 *
 * Aggregates exposed by this service (read-only views over the ledger file):
 *   - by_task_category
 *   - by_task_category_difficulty_role_model
 *   - by_run_family_prompt_task_category_difficulty_role_model
 *   - by_role
 *   - by_provider_model
 *   - by_framework (when scorecard surfaces it)
 *   - statistical_repeat_readiness
 *   - atlas_forge_vs_raw_provider_delta
 *   - fair_vs_full_power_delta
 *   - cost_quality_frontier
 *
 * Confidence model:
 *   - `evidence_count >= 6` ⇒ confidence='high'
 *   - `evidence_count >= 3` ⇒ confidence='medium'
 *   - `evidence_count >= 1` ⇒ confidence='low'
 *   - `evidence_count == 0` ⇒ confidence='insufficient_evidence'
 *   - `latest_age_days > 14` ⇒ `stale_data=true`
 *
 * IMPORTANT: This ledger NEVER calls a provider. NEVER spends tokens. NEVER
 * unlocks external_rivals_certification. It is read-side intelligence built
 * on top of evidence packs that already exist on disk.
 */
final class AtlasForgeRivalsProviderPerformanceLedgerService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.provider_performance_ledger.v1';

    public const ENTRY_SCHEMA_VERSION = 'atlas.forge.rivals.provider_performance_ledger_entry.v1';

    /** @var list<string> The seven canonical Atlas operator roles a provider can fill. */
    public const ROLES = [
        'builder',
        'reviewer',
        'repair_agent',
        'context_scout',
        'test_generator',
        'architect',
        'docs',
    ];

    /** @var list<string> Canonical task categories. */
    public const TASK_CATEGORIES = [
        'planning',
        'frontend',
        'backend',
        'bugfix',
        'refactor',
        'feature',
        'test',
        'docs',
        'devops',
        'security',
        'unknown',
    ];

    public const STALE_AGE_DAYS = 14;

    public const CONFIDENCE_HIGH_THRESHOLD = 6;

    public const CONFIDENCE_MEDIUM_THRESHOLD = 3;

    public const CONFIDENCE_INSUFFICIENT = 'insufficient_evidence';

    public const CONFIDENCE_LOW = 'low';

    public const CONFIDENCE_MEDIUM = 'medium';

    public const CONFIDENCE_HIGH = 'high';

    public const STATISTICAL_REPEAT_MIN_VALID_PER_BUCKET = 3;

    public const STATISTICAL_CONFIDENCE_INTERVAL_MIN_SAMPLE = 3;

    public const STATISTICAL_STABILITY_STDDEV_MAX = 8.0;

    private readonly AtlasForgeRivalsProviderModelRegistryService $modelRegistry;

    public function __construct(
        private readonly AtlasForgeRivalsRunPathResolver $paths,
        ?AtlasForgeRivalsProviderModelRegistryService $modelRegistry = null,
    ) {
        $this->modelRegistry = $modelRegistry ?? new AtlasForgeRivalsProviderModelRegistryService;
    }

    /**
     * Append a ledger entry built from a run's scorecard + manifest. Idempotent
     * in the sense that re-running the same run_id appends a new entry that
     * preserves history; nothing is overwritten.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function record(array $input): array
    {
        $runId = trim((string) ($input['run_id'] ?? ''));
        if ($runId === '') {
            return [
                'status' => 'blocked',
                'blockers' => ['run_id_required'],
                'next_command' => 'php artisan atlas:forge:rivals ledger-record --run-id=<id> --json',
            ];
        }

        try {
            $runPaths = $this->paths->paths($runId);
        } catch (\Throwable $e) {
            return [
                'status' => 'blocked',
                'blockers' => ['run_id_invalid:'.$e->getMessage()],
                'next_command' => '',
            ];
        }

        if (! is_dir($runPaths['base'])) {
            return [
                'status' => 'blocked',
                'blockers' => ['run_not_found:'.$runPaths['run_id']],
                'next_command' => 'php artisan atlas:forge:rivals run-battery --run-id='.$runPaths['run_id'].' --json',
            ];
        }

        $manifest = $this->readJson($runPaths['manifest_json']);
        $scorecard = $this->readJson($runPaths['scorecard_json']);
        $evidencePack = $this->readJson($runPaths['evidence'].'/evidence_pack.json');

        $blockers = [];
        if ($manifest === []) {
            $blockers[] = 'manifest_missing';
        }
        if ($scorecard === []) {
            $blockers[] = 'scorecard_missing';
        }
        if ($evidencePack === []) {
            $blockers[] = 'evidence_pack_missing';
        }
        if ($blockers !== []) {
            return [
                'status' => 'blocked',
                'blockers' => $blockers,
                'next_command' => 'php artisan atlas:forge:rivals adjudicate --run-id='.$runPaths['run_id'].' --json',
            ];
        }

        $taskCategory = $this->resolveTaskCategory($input, $manifest, $scorecard);
        $role = $this->resolveRole($input, $manifest);
        $framework = $this->resolveFramework($input, $manifest, $scorecard);

        if ($taskCategory === '') {
            return [
                'status' => 'blocked',
                'blockers' => ['task_category_required'],
                'next_command' => 'php artisan atlas:forge:rivals ledger-record --run-id='.$runPaths['run_id'].' --task-category=<cat> --json',
            ];
        }
        if ($role === '') {
            return [
                'status' => 'blocked',
                'blockers' => ['role_required'],
                'next_command' => 'php artisan atlas:forge:rivals ledger-record --run-id='.$runPaths['run_id'].' --role=<role> --json',
            ];
        }

        $evidenceHash = $this->resolveEvidenceHash($evidencePack);
        if ($evidenceHash === '') {
            return [
                'status' => 'blocked',
                'blockers' => ['evidence_hash_required'],
                'next_command' => 'php artisan atlas:forge:rivals collect-evidence --run-id='.$runPaths['run_id'].' --json',
            ];
        }

        $evidenceBlockers = $this->evidenceIntegrityBlockers($scorecard, $evidencePack, $runPaths);
        if ($evidenceBlockers !== []) {
            return [
                'status' => 'blocked',
                'blockers' => $evidenceBlockers,
                'next_command' => 'php artisan atlas:forge:rivals replay --run-id='.$runPaths['run_id'].' --json --strict',
            ];
        }

        $atlasEntry = $this->buildArmEntry(
            arm: 'atlas',
            runId: $runPaths['run_id'],
            manifest: $manifest,
            scorecard: $scorecard,
            evidencePack: $evidencePack,
            runPaths: $runPaths,
            taskCategory: $taskCategory,
            role: $role,
            framework: $framework,
            evidenceHash: $evidenceHash,
        );
        $rivalEntry = $this->buildArmEntry(
            arm: 'rival',
            runId: $runPaths['run_id'],
            manifest: $manifest,
            scorecard: $scorecard,
            evidencePack: $evidencePack,
            runPaths: $runPaths,
            taskCategory: $taskCategory,
            role: $role,
            framework: $framework,
            evidenceHash: $evidenceHash,
        );

        $this->ensureLedgerDirectory();
        $this->persistEntry($atlasEntry);
        $this->persistEntry($rivalEntry);

        return [
            'status' => 'ok',
            'schema_version' => self::SCHEMA_VERSION,
            'run_id' => $runPaths['run_id'],
            'entries_recorded' => [$atlasEntry, $rivalEntry],
            'ledger_path' => $this->ledgerIndexPath(),
            'evidence_paths' => [
                $this->ledgerIndexPath(),
                $this->ledgerEntryPath($atlasEntry['entry_id']),
                $this->ledgerEntryPath($rivalEntry['entry_id']),
            ],
            'separated_from_external_rivals_certification' => true,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'next_command' => 'php artisan atlas:forge:rivals ledger --json',
        ];
    }

    /**
     * Return a snapshot of the ledger plus computed aggregates.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function snapshot(array $input = []): array
    {
        $entries = $this->loadEntries();
        $filters = $this->buildFilters($input);
        $filtered = $this->applyFilters($entries, $filters);

        $aggregates = [
            'by_task_category' => $this->aggregateByTaskCategory($filtered),
            'by_task_category_difficulty_role_model' => $this->aggregateByTaskCategoryDifficultyRoleModel($filtered),
            'by_run_family_prompt_task_category_difficulty_role_model' => $this->aggregateByRunFamilyPromptTaskCategoryDifficultyRoleModel($filtered),
            'by_role' => $this->aggregateByRole($filtered),
            'by_provider_model' => $this->aggregateByProviderModel($filtered),
            'by_framework' => $this->aggregateByFramework($filtered),
            'atlas_decide_learning_eligibility' => $this->atlasDecideLearningEligibility($filtered),
            'statistical_repeat_readiness' => $this->statisticalRepeatReadiness($filtered),
            'atlas_forge_vs_raw_provider_delta' => $this->atlasVsRawDelta($filtered),
            'fair_vs_full_power_delta' => $this->fairVsFullPowerDelta($filtered),
            'cost_quality_frontier' => $this->costQualityFrontier($filtered),
        ];

        return [
            'status' => 'ok',
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => $this->utcNow(),
            'ledger_path' => $this->ledgerIndexPath(),
            'ledger_root' => $this->ledgerRoot(),
            'filters' => $filters,
            'total_entries' => count($entries),
            'filtered_entries' => count($filtered),
            'entries_preview' => array_slice($filtered, -20),
            'aggregates' => $aggregates,
            'external_claim_readiness' => $this->externalClaimReadiness($filtered, $aggregates),
            'invalid_entries_excluded_from_ranking' => true,
            'claim_ready' => false,
            'separated_from_external_rivals_certification' => true,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
        ];
    }

    /**
     * Return an operator plan for the next statistical-repeat runs required
     * before any strong external claim can even be reviewed by a human.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function statisticalRepeatPlan(array $input = []): array
    {
        $snapshot = $this->snapshot($input);
        $external = (array) ($snapshot['external_claim_readiness'] ?? []);
        $plan = (array) ($external['statistical_repeat_measurement_plan'] ?? []);
        $executionPlan = $this->statisticalRepeatExecutionPlan($plan);

        return [
            'status' => 'ok',
            'schema_version' => 'atlas.forge.rivals.statistical_repeat_operator_plan.v1',
            'generated_at' => $this->utcNow(),
            'filters' => $snapshot['filters'] ?? [],
            'total_ledger_entries' => $snapshot['total_entries'] ?? 0,
            'filtered_ledger_entries' => $snapshot['filtered_entries'] ?? 0,
            'external_claim_readiness_status' => $external['status'] ?? 'blocked_until_reproducible_evidence_complete',
            'reproducible_evidence_ready_for_human_certification' => (bool) ($external['reproducible_evidence_ready_for_human_certification'] ?? false),
            'requirements' => $external['requirements'] ?? [],
            'blockers' => $external['blockers'] ?? [],
            'statistical_repeat_readiness' => data_get($snapshot, 'aggregates.statistical_repeat_readiness'),
            'statistical_repeat_measurement_plan' => $plan,
            'repeat_target_count' => (int) ($plan['repeat_target_count'] ?? count((array) ($plan['repeat_targets_preview'] ?? []))),
            'repeat_target_preview_count' => count((array) ($plan['repeat_targets_preview'] ?? [])),
            'repeat_targets_preview' => $plan['repeat_targets_preview'] ?? [],
            'unstable_target_count' => (int) ($plan['unstable_target_count'] ?? count((array) ($plan['unstable_targets_preview'] ?? []))),
            'unstable_target_preview_count' => count((array) ($plan['unstable_targets_preview'] ?? [])),
            'unstable_targets_preview' => $plan['unstable_targets_preview'] ?? [],
            'execution_plan' => $executionPlan,
            'next_command' => 'php artisan atlas:forge:rivals statistical-repeat-plan --json',
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
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    private function statisticalRepeatExecutionPlan(array $plan): array
    {
        $targets = array_values((array) ($plan['repeat_targets'] ?? $plan['repeat_targets_preview'] ?? []));
        $unstableTargets = array_values((array) ($plan['unstable_targets'] ?? $plan['unstable_targets_preview'] ?? []));
        $notReadyBucketCount = (int) ($plan['not_ready_bucket_count'] ?? count($targets));
        $unstableBucketCount = (int) ($plan['unstable_bucket_count'] ?? count($unstableTargets));
        $groups = [];
        $totalSuggestedRuns = 0;

        foreach ($targets as $target) {
            if (! is_array($target)) {
                continue;
            }
            $provider = (string) ($target['provider'] ?? 'unknown');
            $model = (string) ($target['model'] ?? 'unknown');
            $key = $provider.':'.$model;
            $missing = max(0, (int) ($target['suggested_minimum_additional_runs'] ?? $target['missing_valid_repetitions'] ?? 0));
            $totalSuggestedRuns += $missing;

            $groups[$key] ??= [
                'provider' => $provider,
                'model' => $model,
                'target_count' => 0,
                'suggested_minimum_additional_runs' => 0,
                'provider_driver_resolution' => $this->repeatProviderDriverResolution($provider, $model),
                'targets_preview' => [],
                'dry_run_inputs_preview' => [],
                'dry_run_commands_preview' => [],
            ];
            $groups[$key]['target_count']++;
            $groups[$key]['suggested_minimum_additional_runs'] += $missing;
            $groups[$key]['targets_preview'][] = [
                'task_category' => $target['task_category'] ?? null,
                'difficulty_level' => $target['difficulty_level'] ?? null,
                'run_family' => $target['run_family'] ?? null,
                'prompt_mode' => $target['prompt_mode'] ?? null,
                'role' => $target['role'] ?? null,
                'valid_count' => $target['valid_count'] ?? 0,
                'missing_valid_repetitions' => $target['missing_valid_repetitions'] ?? $missing,
            ];
            $groups[$key]['dry_run_inputs_preview'][] = $this->repeatDryRunInput($target, $groups[$key]['provider_driver_resolution']);
            $groups[$key]['dry_run_commands_preview'][] = $this->repeatDryRunCommand($target, $groups[$key]['provider_driver_resolution']);
        }

        $outGroups = array_values($groups);
        foreach ($outGroups as &$group) {
            $group['targets_preview'] = array_slice($group['targets_preview'], 0, 10);
            $inputs = $this->uniqueDryRunInputs((array) ($group['dry_run_inputs_preview'] ?? []));
            $group['dry_run_input_count'] = count($inputs);
            $group['dry_run_inputs'] = $inputs;
            $group['dry_run_inputs_preview'] = array_slice($inputs, 0, 10);
            $commands = array_values(array_unique($group['dry_run_commands_preview']));
            $group['dry_run_command_count'] = count($commands);
            $group['dry_run_commands'] = $commands;
            $group['dry_run_commands_preview'] = array_slice($commands, 0, 10);
        }
        unset($group);

        usort($outGroups, static function (array $a, array $b): int {
            $runs = ((int) ($b['suggested_minimum_additional_runs'] ?? 0)) <=> ((int) ($a['suggested_minimum_additional_runs'] ?? 0));
            if ($runs !== 0) {
                return $runs;
            }

            return strcmp((string) ($a['provider'] ?? ''), (string) ($b['provider'] ?? ''));
        });

        return [
            'schema_version' => 'atlas.forge.rivals.statistical_repeat_execution_plan.v1',
            'status' => ($plan['status'] ?? null) === 'complete' ? 'complete' : 'needs_repetition',
            'known_not_ready_bucket_count' => $notReadyBucketCount,
            'known_unstable_bucket_count' => $unstableBucketCount,
            'planned_target_count' => count($targets),
            'planned_unstable_target_count' => count($unstableTargets),
            'preview_target_count' => count((array) ($plan['repeat_targets_preview'] ?? $targets)),
            'preview_unstable_target_count' => count((array) ($plan['unstable_targets_preview'] ?? $unstableTargets)),
            'preview_limited' => $notReadyBucketCount > count($targets) || $unstableBucketCount > count($unstableTargets),
            'group_count' => count($outGroups),
            'total_suggested_minimum_additional_runs' => $totalSuggestedRuns,
            'groups_by_provider_model' => $outGroups,
            'execution_batches' => $this->repeatExecutionBatches($outGroups),
            'operator_cost_risk_summary' => $this->repeatOperatorCostRiskSummary($outGroups, $totalSuggestedRuns),
            'operator_sequence' => [
                '1_review_groups_by_provider_model',
                '2_run_dry_run_commands_first',
                '3_confirm_runbook_provider_cost_and_real_provider_call_before_real_runs',
                '4_run_replay_matrix_and_ledger_record_only_after_trusted_evidence',
                '5_recheck_decide_learning_until_consumption_summary_changes',
            ],
            'confirmation_required_before_real_provider' => true,
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
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $groups
     * @return array<string,mixed>
     */
    private function repeatOperatorCostRiskSummary(array $groups, int $totalSuggestedRuns): array
    {
        $unresolved = array_values(array_filter(
            $groups,
            static fn (array $group): bool => ($group['provider_driver_resolution']['status'] ?? null) !== 'resolved_for_dry_run_plan'
        ));
        $providers = array_values(array_unique(array_map(
            static fn (array $group): string => (string) ($group['provider'] ?? 'unknown'),
            $groups,
        )));
        sort($providers);

        return [
            'schema_version' => 'atlas.forge.rivals.statistical_repeat_operator_cost_risk_summary.v1',
            'status' => $unresolved === [] ? 'ready_for_dry_run_review' : 'requires_operator_resolution_before_dry_run',
            'minimum_additional_runs_estimate' => $totalSuggestedRuns,
            'provider_model_group_count' => count($groups),
            'providers_in_plan' => $providers,
            'unresolved_provider_model_group_count' => count($unresolved),
            'cost_estimate_available' => false,
            'cost_estimate_reason' => 'provider_real_calls_not_executed_and_pricing_receipts_not_available_in_plan',
            'risk_level' => $totalSuggestedRuns >= 100 ? 'high_volume_repeat_plan' : 'bounded_repeat_plan',
            'dry_run_only_until_confirmed' => true,
            'required_confirmations_before_real_provider' => [
                'confirm_runbook_reviewed',
                'confirm_provider_cost',
                'confirm_real_provider_call',
            ],
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'score_or_claim_allowed' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $groups
     * @return list<array<string,mixed>>
     */
    private function repeatExecutionBatches(array $groups): array
    {
        $commands = [];
        $inputs = [];
        foreach ($groups as $group) {
            foreach ((array) ($group['dry_run_inputs'] ?? []) as $input) {
                if (! is_array($input)) {
                    continue;
                }
                $key = $this->stableJson($input);
                if ($key !== '' && ! array_key_exists($key, $inputs)) {
                    $inputs[$key] = $input;
                }
            }
            foreach ((array) ($group['dry_run_commands'] ?? []) as $command) {
                $command = trim((string) $command);
                if ($command !== '' && ! in_array($command, $commands, true)) {
                    $commands[] = $command;
                }
            }
        }

        $batches = [];
        foreach (array_chunk($commands, 10) as $index => $batchCommands) {
            $batchInputs = array_slice(array_values($inputs), $index * 10, 10);
            $batches[] = [
                'batch' => $index + 1,
                'command_count' => count($batchCommands),
                'commands' => $batchCommands,
                'input_count' => count($batchInputs),
                'inputs' => $batchInputs,
                'run_dry_first' => true,
                'external_provider_call' => false,
                'provider_tokens_spent' => false,
                'score_or_claim_allowed' => false,
                'routing_effect' => 'none',
            ];
        }

        return $batches;
    }

    /**
     * @param  list<array<string,mixed>>  $inputs
     * @return list<array<string,mixed>>
     */
    private function uniqueDryRunInputs(array $inputs): array
    {
        $unique = [];
        foreach ($inputs as $input) {
            if (! is_array($input)) {
                continue;
            }
            $key = $this->stableJson($input);
            if ($key !== '' && ! array_key_exists($key, $unique)) {
                $unique[$key] = $input;
            }
        }

        return array_values($unique);
    }

    /**
     * @return array<string,mixed>
     */
    private function repeatProviderDriverResolution(string $provider, string $model): array
    {
        $normalizedProvider = strtolower($provider);
        $normalizedModel = strtolower($model);
        $arm = match (true) {
            str_contains($normalizedProvider, 'claude') || str_contains($normalizedProvider, 'anthropic') => 'claude_code',
            str_contains($normalizedProvider, 'codex') || str_contains($normalizedProvider, 'openai') => 'codex_cli',
            str_contains($normalizedProvider, 'gemini') || str_contains($normalizedProvider, 'google') => 'gemini_cli',
            default => null,
        };
        $notes = ['dry_run_only_until_explicit_real_provider_confirmations'];
        if ($arm === null) {
            $arm = match (true) {
                str_contains($normalizedModel, 'sonnet') || str_contains($normalizedModel, 'opus') || str_contains($normalizedModel, 'claude') => 'claude_code',
                str_contains($normalizedModel, 'gpt-5.5') || str_contains($normalizedModel, 'gpt-codex') || str_contains($normalizedModel, 'codex') => 'codex_cli',
                str_contains($normalizedModel, 'gemini') => 'gemini_cli',
                default => null,
            };
            $notes = $arm === null
                ? ['provider_model_not_mapped_to_canonical_arm_registry']
                : ['provider_unknown_resolved_from_model_alias_for_dry_run_only', 'dry_run_only_until_explicit_real_provider_confirmations'];
        }

        return [
            'status' => $arm === null ? 'operator_resolution_required' : 'resolved_for_dry_run_plan',
            'arm' => $arm,
            'model' => $normalizedModel === '' ? null : $model,
            'provider' => $provider,
            'notes' => $notes,
        ];
    }

    /**
     * @param  array<string,mixed>  $target
     * @param  array<string,mixed>  $driver
     * @return array<string,mixed>
     */
    private function repeatDryRunInput(array $target, array $driver): array
    {
        $opponent = $this->repeatOpponentFor((string) ($driver['arm'] ?? ''), (string) ($driver['model'] ?? ''));

        return [
            'case_set' => 'statistical-repeat',
            'mode' => 'provider_arena',
            'prompt_mode' => (string) ($target['prompt_mode'] ?? 'human-normal'),
            'task_category' => (string) ($target['task_category'] ?? 'unknown'),
            'difficulty' => (string) ($target['difficulty_level'] ?? 'L3'),
            'run_family' => ($target['run_family'] ?? null) !== null ? (string) $target['run_family'] : null,
            'arm_a' => ($driver['arm'] ?? null) !== null ? (string) $driver['arm'] : null,
            'arm_a_model' => ($driver['model'] ?? null) !== null ? (string) $driver['model'] : null,
            'arm_b' => $opponent['arm'],
            'arm_b_model' => $opponent['model'],
            'dry_run' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $target
     * @param  array<string,mixed>  $driver
     */
    private function repeatDryRunCommand(array $target, array $driver): string
    {
        $opponent = $this->repeatOpponentFor((string) ($driver['arm'] ?? ''), (string) ($driver['model'] ?? ''));
        $command = 'php artisan atlas:forge:rivals run-arena --case-set=statistical-repeat --mode=provider_arena'
            .' --prompt-mode='.(string) ($target['prompt_mode'] ?? 'human-normal')
            .' --task-category='.(string) ($target['task_category'] ?? 'unknown')
            .' --difficulty='.(string) ($target['difficulty_level'] ?? 'L3');
        if (($target['run_family'] ?? null) !== null) {
            $command .= ' --run-family='.(string) $target['run_family'];
        }

        if (($driver['arm'] ?? null) !== null) {
            $command .= ' --arm-a='.(string) $driver['arm'];
        }
        if (($driver['model'] ?? null) !== null) {
            $command .= ' --arm-a-model='.(string) $driver['model'];
        }
        $command .= ' --arm-b='.$opponent['arm'];
        $command .= ' --arm-b-model='.$opponent['model'];

        return $command.' --dry-run --json';
    }

    /**
     * @return array{arm:string,model:string}
     */
    private function repeatOpponentFor(string $arm, string $model): array
    {
        if ($arm === 'codex_cli') {
            return ['arm' => 'claude_code', 'model' => 'sonnet'];
        }
        if ($arm === 'gemini_cli') {
            return ['arm' => 'codex_cli', 'model' => 'gpt-5.5'];
        }
        if ($arm === 'claude_code' && str_contains(strtolower($model), 'opus')) {
            return ['arm' => 'codex_cli', 'model' => 'gpt-5.5'];
        }

        return ['arm' => 'codex_cli', 'model' => 'gpt-5.5'];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function loadEntries(): array
    {
        $path = $this->ledgerIndexPath();
        if (! is_file($path)) {
            return [];
        }
        $entries = [];
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return [];
        }
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (is_array($row)) {
                $entries[] = $this->normalizeLoadedEntry($row);
            }
        }
        fclose($handle);

        return $entries;
    }

    /**
     * Legacy ledger rows are immutable on disk, but read models may normalize
     * provider/model aliases through the canonical registry. This preserves the
     * append-only contract while preventing old `provider=unknown, model=sonnet`
     * rows from polluting Atlas Decide learning packets.
     *
     * @param  array<string,mixed>  $entry
     * @return array<string,mixed>
     */
    private function normalizeLoadedEntry(array $entry): array
    {
        $originalProvider = (string) ($entry['provider'] ?? 'unknown');
        $originalModel = (string) ($entry['model'] ?? 'unknown');
        $provider = $originalProvider;
        $model = $originalModel;

        if (trim($provider) === '' || strtolower(trim($provider)) === 'unknown') {
            $provider = $this->inferProviderFromModel($model, (string) ($entry['arm'] ?? ''));
        }

        $model = $this->resolveCanonicalModel($provider, $model);

        if ($provider !== $originalProvider || $model !== $originalModel) {
            $entry['provider_model_resolution_source'] = 'read_side_registry_alias_normalization';
            $entry['original_provider'] = $originalProvider;
            $entry['original_model'] = $originalModel;
            $entry['provider'] = $provider;
            $entry['model'] = $model;
            $entry['arm_id'] = implode(':', [
                (string) ($entry['arm'] ?? 'unknown'),
                $provider,
                $model,
                (string) ($entry['mode'] ?? 'unknown'),
            ]);
        }

        $difficulty = strtoupper(trim((string) ($entry['difficulty_level'] ?? '')));
        $blockers = $this->atlasDecideLearningBlockers(
            provider: (string) ($entry['provider'] ?? ''),
            model: (string) ($entry['model'] ?? ''),
            taskCategory: (string) ($entry['task_category'] ?? ''),
            difficultyLevel: preg_match('/^L[1-5]$/', $difficulty) === 1 ? $difficulty : null,
            role: (string) ($entry['role'] ?? ''),
        );
        $entry['atlas_decide_learning_blockers'] = $blockers;
        $entry['atlas_decide_learning_eligible'] = $blockers === [];

        return $entry;
    }

    public function ledgerRoot(): string
    {
        $candidate = (string) (function_exists('config')
            ? (config('atlas_rivals.ledger_root') ?? $this->defaultLedgerRoot())
            : $this->defaultLedgerRoot());
        $candidate = rtrim(trim($candidate), '/');
        if ($candidate === '') {
            return $this->defaultLedgerRoot();
        }

        return $candidate;
    }

    public function ledgerIndexPath(): string
    {
        return $this->ledgerRoot().'/entries.jsonl';
    }

    public function ledgerEntryPath(string $entryId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-.]+/', '_', $entryId) ?? 'entry';

        return $this->ledgerRoot().'/entries/'.$safe.'.json';
    }

    /**
     * Pure helper: compute a confidence band for an `evidence_count`. Exposed
     * so the projection service can mirror identical thresholds.
     */
    public function confidenceFor(int $evidenceCount): string
    {
        if ($evidenceCount <= 0) {
            return self::CONFIDENCE_INSUFFICIENT;
        }
        if ($evidenceCount >= self::CONFIDENCE_HIGH_THRESHOLD) {
            return self::CONFIDENCE_HIGH;
        }
        if ($evidenceCount >= self::CONFIDENCE_MEDIUM_THRESHOLD) {
            return self::CONFIDENCE_MEDIUM;
        }

        return self::CONFIDENCE_LOW;
    }

    public function ageDays(string $isoDate, ?DateTimeImmutable $now = null): int
    {
        if ($isoDate === '') {
            return PHP_INT_MAX;
        }
        try {
            $dt = new DateTimeImmutable($isoDate);
        } catch (\Throwable) {
            return PHP_INT_MAX;
        }
        $now = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $diff = $now->getTimestamp() - $dt->getTimestamp();

        return (int) max(0, intdiv($diff, 86_400));
    }

    /**
     * @param  list<float|int>  $scores
     * @return array<string,mixed>
     */
    public function scoreStats(array $scores): array
    {
        $values = array_values(array_map(static fn (float|int $v): float => (float) $v, $scores));
        sort($values);
        $count = count($values);
        if ($count === 0) {
            return [
                'sample_count' => 0,
                'mean_score' => null,
                'median_score' => null,
                'score_stddev' => null,
                'confidence_interval_95' => null,
                'score_stability' => 'insufficient_sample',
            ];
        }

        $mean = array_sum($values) / $count;
        $middle = intdiv($count, 2);
        $median = $count % 2 === 1
            ? $values[$middle]
            : (($values[$middle - 1] + $values[$middle]) / 2.0);

        $stddev = null;
        if ($count >= 2) {
            $variance = array_sum(array_map(static fn (float $v): float => ($v - $mean) ** 2, $values)) / ($count - 1);
            $stddev = sqrt($variance);
        }

        $interval = null;
        if ($count >= self::STATISTICAL_CONFIDENCE_INTERVAL_MIN_SAMPLE && $stddev !== null) {
            $margin = 1.96 * ($stddev / sqrt($count));
            $interval = [
                'low' => round(max(0.0, $mean - $margin), 4),
                'high' => round(min(100.0, $mean + $margin), 4),
                'margin' => round($margin, 4),
                'method' => 'normal_approximation_95pct',
                'sample_count' => $count,
            ];
        }

        $stability = 'insufficient_sample';
        if ($count >= self::STATISTICAL_REPEAT_MIN_VALID_PER_BUCKET && $stddev !== null) {
            $stability = $stddev <= self::STATISTICAL_STABILITY_STDDEV_MAX ? 'stable' : 'unstable';
        }

        return [
            'sample_count' => $count,
            'mean_score' => round($mean, 4),
            'median_score' => round($median, 4),
            'score_stddev' => $stddev === null ? null : round($stddev, 4),
            'confidence_interval_95' => $interval,
            'score_stability' => $stability,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function resolveTaskCategory(array $input, array $manifest, array $scorecard): string
    {
        $raw = trim((string) ($input['task_category']
            ?? $manifest['task_category']
            ?? $scorecard['task_category']
            ?? ''));
        if ($raw === '') {
            return '';
        }
        $value = strtolower($raw);
        if (! in_array($value, self::TASK_CATEGORIES, true)) {
            return 'unknown';
        }

        return $value;
    }

    private function resolveRole(array $input, array $manifest): string
    {
        $raw = trim((string) ($input['role'] ?? $manifest['role'] ?? ''));
        if ($raw === '') {
            return '';
        }
        $value = strtolower($raw);
        if (! in_array($value, self::ROLES, true)) {
            throw new InvalidArgumentException(
                "Unknown role: '{$raw}'. Supported: ".implode(', ', self::ROLES).'.'
            );
        }

        return $value;
    }

    private function resolveFramework(array $input, array $manifest, array $scorecard): ?string
    {
        foreach ([$input['framework'] ?? null, $manifest['framework'] ?? null, $scorecard['framework'] ?? null] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return strtolower(trim($candidate));
            }
        }

        return null;
    }

    private function resolveEvidenceHash(array $evidencePack): string
    {
        $artifacts = (array) ($evidencePack['artifacts'] ?? []);
        $parts = [];
        foreach (['manifest', 'atlas_receipt', 'rival_receipt', 'workspace_hashes'] as $key) {
            $row = $artifacts[$key] ?? null;
            if (is_array($row) && isset($row['sha256']) && is_string($row['sha256'])) {
                $parts[] = $key.':'.$row['sha256'];
            }
        }
        if ($parts === []) {
            return '';
        }

        return hash('sha256', implode('|', $parts));
    }

    /**
     * @param  array<string,mixed>  $evidencePack
     */
    private function resolveEvidenceArtifactPath(array $evidencePack, string $key, array $runPaths): string
    {
        $direct = (string) ($evidencePack['paths'][$key] ?? '');
        if ($direct !== '' && is_file($direct)) {
            return $direct;
        }

        $artifact = $evidencePack['artifacts'][$key] ?? null;
        if (is_array($artifact)) {
            $path = (string) ($artifact['path'] ?? '');
            if ($path !== '' && is_file($path)) {
                return $path;
            }

            $oldBase = rtrim((string) ($evidencePack['paths']['base'] ?? $evidencePack['run_dir'] ?? ''), '/');
            if ($path !== '' && $oldBase !== '' && str_starts_with($path, $oldBase.'/')) {
                $relative = ltrim(substr($path, strlen($oldBase)), '/');
                $restored = rtrim((string) ($runPaths['base'] ?? ''), '/').'/'.$relative;
                if (is_file($restored)) {
                    return $restored;
                }
            }

            return $path;
        }

        if ($direct !== '') {
            $oldBase = rtrim((string) ($evidencePack['paths']['base'] ?? $evidencePack['run_dir'] ?? ''), '/');
            if ($oldBase !== '' && str_starts_with($direct, $oldBase.'/')) {
                $relative = ltrim(substr($direct, strlen($oldBase)), '/');
                $restored = rtrim((string) ($runPaths['base'] ?? ''), '/').'/'.$relative;
                if (is_file($restored)) {
                    return $restored;
                }
            }
        }

        return $direct;
    }

    /**
     * @param  array<string,mixed>  $scorecard
     * @param  array<string,mixed>  $evidencePack
     * @param  array<string,string>  $runPaths
     * @return list<string>
     */
    private function evidenceIntegrityBlockers(array $scorecard, array $evidencePack, array $runPaths): array
    {
        $blockers = [];

        if (($scorecard['replay_passes'] ?? false) !== true) {
            $blockers[] = 'scorecard_replay_passes_required';
        }

        foreach ($this->stringList($evidencePack['missing_evidence'] ?? []) as $key) {
            $blockers[] = 'evidence_pack_missing_evidence:'.$key;
        }

        $artifacts = (array) ($evidencePack['artifacts'] ?? []);
        foreach ($artifacts as $key => $artifact) {
            if (! is_array($artifact) || ($artifact['present'] ?? false) !== true) {
                continue;
            }

            $path = $this->resolveEvidenceArtifactPath($evidencePack, (string) $key, $runPaths);
            if ($path === '' || ! is_file($path)) {
                $blockers[] = 'evidence_artifact_missing_at_ledger:'.(string) $key;

                continue;
            }

            $expected = (string) ($artifact['sha256'] ?? '');
            $actual = hash_file('sha256', $path) ?: '';
            if ($expected !== '' && $actual !== $expected) {
                $blockers[] = 'evidence_artifact_hash_mismatch_at_ledger:'.(string) $key;
            }
        }

        return array_values(array_unique($blockers));
    }

    /**
     * @return array<string,mixed>
     */
    private function buildArmEntry(
        string $arm,
        string $runId,
        array $manifest,
        array $scorecard,
        array $evidencePack,
        array $runPaths,
        string $taskCategory,
        string $role,
        ?string $framework,
        string $evidenceHash,
    ): array {
        $score = $arm === 'atlas'
            ? ($scorecard['atlas_score'] ?? null)
            : ($scorecard['rival_score'] ?? null);
        $winner = $scorecard['winner'] ?? null;
        $hardFailures = $this->stringList($scorecard['hard_failures'] ?? []);
        $isInvalid = $hardFailures !== [] || $score === null;
        $isTie = $winner === 'human_review_required_tie';

        $outcome = 'invalid';
        if ($isInvalid) {
            $outcome = 'invalid';
        } elseif ($isTie) {
            $outcome = 'human_review_required';
        } elseif ($winner === $arm) {
            $outcome = 'winner';
        } elseif ($winner !== null) {
            $outcome = 'loser';
        }

        $dimensions = $scorecard['quality_dimensions'] ?? null;
        $scoresByDimension = [];
        if (is_array($dimensions)) {
            foreach ($dimensions as $name => $dim) {
                if (is_array($dim) && isset($dim[$arm])) {
                    $scoresByDimension[(string) $name] = (float) $dim[$arm];
                }
            }
        }

        $mode = (string) ($manifest['mode'] ?? 'unknown');
        $runFamily = $this->resolveRunFamily($manifest, $runId);
        $promptMode = $this->resolvePromptMode($manifest);
        $receiptKey = $arm.'_receipt';
        $receiptPath = $this->resolveEvidenceArtifactPath($evidencePack, $receiptKey, $runPaths);
        $receipt = is_file($receiptPath) ? $this->readJson($receiptPath) : [];

        $model = $arm === 'atlas'
            ? (string) ($manifest['atlas_model'] ?? 'unknown')
            : (string) ($manifest['rival_model'] ?? 'unknown');
        $provider = $this->resolveProvider($receipt, $model, $arm);
        $model = $this->resolveCanonicalModel($provider, $model);
        $difficultyLevel = $this->resolveDifficultyLevel($manifest);
        $atlasDecideLearningBlockers = $this->atlasDecideLearningBlockers(
            provider: $provider,
            model: $model,
            taskCategory: $taskCategory,
            difficultyLevel: $difficultyLevel,
            role: $role,
        );
        $runnerType = $arm === 'atlas' ? 'atlas_forge' : 'raw_provider';
        $armId = $arm.':'.$provider.':'.$model.':'.$mode;

        $durationMs = $this->durationMs($receipt);
        $tokensUsed = (int) ($receipt['tokens_used'] ?? 0);
        $costEstimate = $this->coerceFloat($receipt['token_cost'] ?? null);
        $testsPassed = (int) ($receipt['test_exit_code'] ?? -1) === 0;
        $scopeViolations = count($this->stringList($receipt['out_of_scope_files'] ?? []));
        $interventionCount = (int) ($receipt['intervention_count'] ?? 0);
        $replayPassed = (bool) ($scorecard['replay_passes'] ?? false);

        $entryId = $this->buildEntryId($runId, $arm, $evidenceHash);
        $generatedAt = $this->utcNow();

        return [
            'schema_version' => self::ENTRY_SCHEMA_VERSION,
            'entry_id' => $entryId,
            'recorded_at' => $generatedAt,
            'run_id' => $runId,
            'battery_id' => (string) ($manifest['battery_id'] ?? $manifest['run_id'] ?? $runId),
            'arena_run_id' => (string) ($manifest['arena_run_id'] ?? $runId),
            'run_family' => $runFamily,
            'prompt_mode' => $promptMode,
            'case_id' => (string) ($manifest['case_id'] ?? $manifest['task_id'] ?? 'unknown'),
            'task_id' => (string) ($manifest['task_id'] ?? $manifest['case_id'] ?? 'unknown'),
            'case_source' => (string) ($manifest['case_source'] ?? $manifest['preset'] ?? 'unknown'),
            'arm' => $arm,
            'arm_id' => $armId,
            'runner_type' => $runnerType,
            'provider' => $provider,
            'model' => $model,
            'task_category' => $taskCategory,
            'difficulty_level' => $difficultyLevel,
            'difficulty_weight' => $this->resolveDifficultyWeight($manifest),
            'role' => $role,
            'framework' => $framework,
            'mode' => $mode,
            'preset' => (string) ($manifest['preset'] ?? 'unknown'),
            'score_total' => $score === null ? null : round((float) $score, 4),
            'scores_by_dimension' => $scoresByDimension,
            'winner' => $winner,
            'outcome' => $outcome,
            'human_review_required' => (bool) ($scorecard['human_review_required'] ?? false),
            'hard_failures' => $hardFailures,
            'tests_passed' => $testsPassed,
            'replay_passed' => $replayPassed,
            'scope_violations' => $scopeViolations,
            'intervention_count' => $interventionCount,
            'duration_ms' => $durationMs,
            'cost_estimate' => $costEstimate,
            'tokens_used' => $tokensUsed,
            'evidence_pack_hash' => $evidenceHash,
            'adjudication_hash' => $this->scorecardHash($scorecard),
            'valid_for_ranking' => $score !== null && $hardFailures === [] && $replayPassed,
            'atlas_decide_learning_eligible' => $atlasDecideLearningBlockers === [],
            'atlas_decide_learning_blockers' => $atlasDecideLearningBlockers,
            'claim_ready' => false,
            'separated_from_external_rivals_certification' => true,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
        ];
    }

    /**
     * @return list<string>
     */
    private function atlasDecideLearningBlockers(
        string $provider,
        string $model,
        string $taskCategory,
        ?string $difficultyLevel,
        string $role,
    ): array {
        $blockers = [];
        if (trim($provider) === '' || strtolower(trim($provider)) === 'unknown') {
            $blockers[] = 'provider_required_for_atlas_decide_learning';
        }
        if (trim($model) === '' || strtolower(trim($model)) === 'unknown') {
            $blockers[] = 'model_required_for_atlas_decide_learning';
        }
        if (trim($taskCategory) === '' || strtolower(trim($taskCategory)) === 'unknown') {
            $blockers[] = 'task_category_required_for_atlas_decide_learning';
        }
        if ($difficultyLevel === null || ! in_array($difficultyLevel, ['L1', 'L2', 'L3', 'L4', 'L5'], true)) {
            $blockers[] = 'difficulty_level_required_for_atlas_decide_learning';
        }
        if (trim($role) === '' || strtolower(trim($role)) === 'unknown') {
            $blockers[] = 'role_required_for_atlas_decide_learning';
        }

        return $blockers;
    }

    /**
     * @param  array<string,mixed>  $entry
     * @return list<string>
     */
    private function atlasDecideLearningBlockersForEntry(array $entry): array
    {
        $stored = array_values(array_filter(
            array_map(static fn ($blocker): string => (string) $blocker, (array) ($entry['atlas_decide_learning_blockers'] ?? [])),
            static fn (string $blocker): bool => $blocker !== '',
        ));
        if ($stored !== []) {
            return $stored;
        }

        if (array_key_exists('atlas_decide_learning_eligible', $entry)
            && (bool) $entry['atlas_decide_learning_eligible'] === true) {
            return [];
        }

        $difficulty = strtoupper(trim((string) ($entry['difficulty_level'] ?? '')));

        return $this->atlasDecideLearningBlockers(
            provider: (string) ($entry['provider'] ?? ''),
            model: (string) ($entry['model'] ?? ''),
            taskCategory: (string) ($entry['task_category'] ?? ''),
            difficultyLevel: preg_match('/^L[1-5]$/', $difficulty) === 1 ? $difficulty : null,
            role: (string) ($entry['role'] ?? ''),
        );
    }

    /**
     * @param  array<string,mixed>  $entry
     */
    private function atlasDecideLearningEligibleForEntry(array $entry): bool
    {
        return $this->atlasDecideLearningBlockersForEntry($entry) === [];
    }

    private function buildEntryId(string $runId, string $arm, string $evidenceHash): string
    {
        $micro = (string) (int) (microtime(true) * 1_000_000);
        $rand = bin2hex(random_bytes(3));

        return $runId.'-'.$arm.'-'.substr($evidenceHash, 0, 12).'-'.substr($micro, -6).$rand;
    }

    private function scorecardHash(array $scorecard): string
    {
        $serial = (string) json_encode([
            'winner' => $scorecard['winner'] ?? null,
            'atlas_score' => $scorecard['atlas_score'] ?? null,
            'rival_score' => $scorecard['rival_score'] ?? null,
            'hard_failures' => $scorecard['hard_failures'] ?? [],
            'tie_threshold' => $scorecard['tie_threshold'] ?? null,
        ]);

        return hash('sha256', $serial);
    }

    private function inferProviderFromModel(string $model, string $arm): string
    {
        $normalized = strtolower(trim($model));
        if ($normalized === '' || $normalized === 'unknown') {
            return 'unknown';
        }
        if (str_contains($normalized, 'claude')) {
            return 'anthropic_claude';
        }
        if (str_contains($normalized, 'codex')) {
            return 'openai_codex';
        }
        if (str_contains($normalized, 'gpt')) {
            return 'openai_gpt';
        }
        if (str_contains($normalized, 'gemini')) {
            return 'google_gemini';
        }
        if ($normalized === 'auto') {
            return 'atlas_decide';
        }
        foreach ($this->modelRegistry->providers() as $provider => $definition) {
            if (! is_array($definition)) {
                continue;
            }
            $resolved = $this->modelRegistry->resolve((string) $provider, $normalized);
            if ((bool) ($resolved['ok'] ?? false)) {
                return $this->ledgerProviderForRegistryProvider((string) $provider, $normalized);
            }
        }

        return 'unknown';
    }

    /**
     * @param  array<string,mixed>  $receipt
     */
    private function resolveProvider(array $receipt, string $model, string $arm): string
    {
        $provider = strtolower(trim((string) ($receipt['provider'] ?? '')));
        if ($provider !== '') {
            return $provider;
        }

        return $this->inferProviderFromModel($model, $arm);
    }

    private function resolveCanonicalModel(string $provider, string $model): string
    {
        $registryProvider = $this->registryProviderForLedgerProvider($provider);
        if ($registryProvider === null) {
            return $model;
        }

        $resolved = $this->modelRegistry->resolve($registryProvider, $model);
        if (! (bool) ($resolved['ok'] ?? false)) {
            return $model;
        }

        $canonical = trim((string) ($resolved['canonical_model'] ?? ''));

        return $canonical === '' ? $model : $canonical;
    }

    private function registryProviderForLedgerProvider(string $provider): ?string
    {
        return match (strtolower(trim($provider))) {
            'anthropic_claude', 'claude_cli', 'claude' => 'claude',
            'openai_codex', 'openai_gpt', 'codex_cli', 'codex' => 'codex',
            'google_gemini', 'gemini_cli', 'gemini' => 'gemini',
            'cursor_cli', 'cursor' => 'cursor',
            'composer_2_5', 'composer' => 'composer',
            default => null,
        };
    }

    private function ledgerProviderForRegistryProvider(string $provider, string $model): string
    {
        $provider = strtolower(trim($provider));
        $model = strtolower(trim($model));

        return match ($provider) {
            'claude' => 'anthropic_claude',
            'codex' => str_contains($model, 'gpt') ? 'openai_gpt' : 'openai_codex',
            'gemini' => 'google_gemini',
            'cursor' => 'cursor',
            'composer' => 'composer',
            default => 'unknown',
        };
    }

    /**
     * @param  array<string,mixed>  $manifest
     */
    private function resolveDifficultyLevel(array $manifest): ?string
    {
        foreach (['difficulty_level', 'difficulty_band', 'difficulty'] as $key) {
            $raw = strtoupper(trim((string) ($manifest[$key] ?? '')));
            if (preg_match('/^L[1-5]$/', $raw) === 1) {
                return $raw;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $manifest
     */
    private function resolveDifficultyWeight(array $manifest): ?float
    {
        $explicit = $this->coerceFloat($manifest['difficulty_weight'] ?? null);
        if ($explicit !== null) {
            return $explicit;
        }

        return match ($this->resolveDifficultyLevel($manifest)) {
            'L1' => 1.0,
            'L2' => 1.5,
            'L3' => 2.0,
            'L4' => 2.5,
            'L5' => 3.0,
            default => null,
        };
    }

    /**
     * @param  array<string,mixed>  $manifest
     */
    private function resolveRunFamily(array $manifest, string $runId): string
    {
        foreach (['run_family', 'experiment_id', 'battery_id', 'arena_run_id', 'run_id'] as $key) {
            $candidate = strtolower(trim((string) ($manifest[$key] ?? '')));
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return strtolower(trim($runId));
    }

    /**
     * @param  array<string,mixed>  $manifest
     */
    private function resolvePromptMode(array $manifest): ?string
    {
        foreach (['prompt_mode', 'human_prompt_mode', 'mode_profile', 'prompt_profile'] as $key) {
            $candidate = strtolower(trim((string) ($manifest[$key] ?? '')));
            if ($candidate !== '') {
                return $candidate;
            }
        }

        $mode = strtolower(trim((string) ($manifest['mode'] ?? '')));
        if (in_array($mode, ['spec-perfect', 'human-normal', 'messy-real', 'enterprise-change'], true)) {
            return $mode;
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $receipt
     */
    private function durationMs(array $receipt): int
    {
        $started = (string) ($receipt['started_at'] ?? '');
        $finished = (string) ($receipt['finished_at'] ?? '');
        if ($started === '' || $finished === '') {
            return 0;
        }
        try {
            $a = new DateTimeImmutable($started);
            $b = new DateTimeImmutable($finished);
        } catch (\Throwable) {
            return 0;
        }

        return (int) max(0, ($b->getTimestamp() - $a->getTimestamp()) * 1_000);
    }

    private function ensureLedgerDirectory(): void
    {
        $root = $this->ledgerRoot();
        if (! is_dir($root)) {
            @mkdir($root, 0o755, true);
        }
        $entriesDir = $root.'/entries';
        if (! is_dir($entriesDir)) {
            @mkdir($entriesDir, 0o755, true);
        }
    }

    /**
     * @param  array<string,mixed>  $entry
     */
    private function persistEntry(array $entry): void
    {
        $entryFile = $this->ledgerEntryPath((string) $entry['entry_id']);
        file_put_contents(
            $entryFile,
            (string) json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        $line = (string) json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $index = $this->ledgerIndexPath();
        $handle = fopen($index, 'a');
        if ($handle !== false) {
            fwrite($handle, $line.PHP_EOL);
            fclose($handle);
        }
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function buildFilters(array $input): array
    {
        $taskCategory = trim((string) ($input['task_category'] ?? ''));
        $role = trim((string) ($input['role'] ?? ''));
        $provider = trim((string) ($input['provider'] ?? ''));
        $framework = trim((string) ($input['framework'] ?? ''));
        $difficulty = trim((string) ($input['difficulty'] ?? $input['difficulty_level'] ?? ''));
        $runFamily = trim((string) ($input['run_family'] ?? ''));
        $promptMode = trim((string) ($input['prompt_mode'] ?? $input['human_prompt_mode'] ?? ''));
        $runIds = $this->stringList($input['run_ids'] ?? []);

        return [
            'task_category' => $taskCategory === '' ? null : strtolower($taskCategory),
            'role' => $role === '' ? null : strtolower($role),
            'provider' => $provider === '' ? null : strtolower($provider),
            'framework' => $framework === '' ? null : strtolower($framework),
            'difficulty_level' => $difficulty === '' ? null : strtoupper($difficulty),
            'run_family' => $runFamily === '' ? null : strtolower($runFamily),
            'prompt_mode' => $promptMode === '' ? null : strtolower($promptMode),
            'run_ids' => $runIds,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $entries
     * @param  array<string,mixed>  $filters
     * @return list<array<string,mixed>>
     */
    private function applyFilters(array $entries, array $filters): array
    {
        return array_values(array_filter($entries, function (array $e) use ($filters): bool {
            if ($filters['task_category'] !== null && ($e['task_category'] ?? null) !== $filters['task_category']) {
                return false;
            }
            if ($filters['role'] !== null && ($e['role'] ?? null) !== $filters['role']) {
                return false;
            }
            if ($filters['provider'] !== null && ($e['provider'] ?? null) !== $filters['provider']) {
                return false;
            }
            if ($filters['framework'] !== null && ($e['framework'] ?? null) !== $filters['framework']) {
                return false;
            }
            if ($filters['difficulty_level'] !== null && ($e['difficulty_level'] ?? null) !== $filters['difficulty_level']) {
                return false;
            }
            if ($filters['run_family'] !== null && ($e['run_family'] ?? null) !== $filters['run_family']) {
                return false;
            }
            if ($filters['prompt_mode'] !== null && ($e['prompt_mode'] ?? null) !== $filters['prompt_mode']) {
                return false;
            }
            if ($filters['run_ids'] !== [] && ! in_array((string) ($e['run_id'] ?? ''), $filters['run_ids'], true)) {
                return false;
            }

            return true;
        }));
    }

    /**
     * @param  list<array<string,mixed>>  $entries
     * @return list<array<string,mixed>>
     */
    private function rankByKey(array $entries, string $keyPath): array
    {
        $buckets = [];
        foreach ($entries as $entry) {
            $key = (string) ($entry[$keyPath] ?? 'unknown');
            $buckets[$key] ??= [];
            $buckets[$key][] = $entry;
        }
        $rows = [];
        foreach ($buckets as $key => $items) {
            $rows[] = $this->summarizeBucket($key, $items, $keyPath);
        }
        usort($rows, static fn (array $a, array $b): int => $b['average_score_valid'] <=> $a['average_score_valid']);

        return $rows;
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return array<string,mixed>
     */
    private function summarizeBucket(string $key, array $items, string $keyPath): array
    {
        $valid = array_values(array_filter($items, static fn (array $i): bool => (bool) ($i['valid_for_ranking'] ?? false)));
        $invalid = count($items) - count($valid);
        $scores = array_map(static fn (array $i): float => (float) ($i['score_total'] ?? 0), $valid);
        $scoreStats = $this->scoreStats($scores);
        $averageScore = $valid === [] ? 0.0 : round(array_sum($scores) / max(1, count($scores)), 4);
        $costs = array_values(array_filter(
            array_map(static fn (array $i): ?float => isset($i['cost_estimate']) ? (float) $i['cost_estimate'] : null, $valid),
            static fn (?float $value): bool => $value !== null,
        ));
        $durations = array_map(static fn (array $i): int => (int) ($i['duration_ms'] ?? 0), $valid);
        $tokens = array_map(static fn (array $i): int => (int) ($i['tokens_used'] ?? 0), $valid);
        $averageCost = $costs === [] ? null : round(array_sum($costs) / count($costs), 6);
        $averageDurationMs = $durations === [] ? null : (int) round(array_sum($durations) / count($durations));
        $averageTokens = $tokens === [] ? null : (int) round(array_sum($tokens) / count($tokens));
        $latestIso = '';
        foreach ($items as $i) {
            $iso = (string) ($i['recorded_at'] ?? '');
            if ($iso !== '' && $iso > $latestIso) {
                $latestIso = $iso;
            }
        }
        $latestRunIds = [];
        $sortedByDate = $items;
        usort($sortedByDate, static fn (array $a, array $b): int => strcmp((string) ($b['recorded_at'] ?? ''), (string) ($a['recorded_at'] ?? '')));
        foreach (array_slice($sortedByDate, 0, 5) as $i) {
            $rid = (string) ($i['run_id'] ?? '');
            if ($rid !== '' && ! in_array($rid, $latestRunIds, true)) {
                $latestRunIds[] = $rid;
            }
        }
        $ageDays = $latestIso === '' ? null : $this->ageDays($latestIso);
        $stale = $ageDays !== null && $ageDays > self::STALE_AGE_DAYS;

        $providerSet = $this->collectUniqueStrings($items, 'provider');
        $modelSet = $this->collectUniqueStrings($items, 'model');
        $atlasDecideEligible = array_values(array_filter(
            $valid,
            fn (array $i): bool => $this->atlasDecideLearningEligibleForEntry($i)
        ));
        $atlasDecideBlockers = [];
        foreach ($items as $item) {
            foreach ($this->atlasDecideLearningBlockersForEntry($item) as $blocker) {
                $blocker = (string) $blocker;
                if ($blocker !== '') {
                    $atlasDecideBlockers[$blocker] = ($atlasDecideBlockers[$blocker] ?? 0) + 1;
                }
            }
        }

        return [
            'key_path' => $keyPath,
            'key' => $key,
            'evidence_count' => count($items),
            'valid_count' => count($valid),
            'invalid_count' => $invalid,
            'average_score_valid' => $averageScore,
            'median_score_valid' => $scoreStats['median_score'],
            'score_stddev' => $scoreStats['score_stddev'],
            'confidence_interval_95' => $scoreStats['confidence_interval_95'],
            'score_stability' => $scoreStats['score_stability'],
            'average_cost_estimate_valid' => $averageCost,
            'average_duration_ms_valid' => $averageDurationMs,
            'average_tokens_used_valid' => $averageTokens,
            'cost_per_score_point_valid' => $averageCost === null || $averageScore <= 0.0 ? null : round($averageCost / $averageScore, 8),
            'max_score_valid' => $valid === [] ? null : round(max($scores), 4),
            'min_score_valid' => $valid === [] ? null : round(min($scores), 4),
            'providers' => $providerSet,
            'models' => $modelSet,
            'atlas_decide_learning_eligible_count' => count($atlasDecideEligible),
            'atlas_decide_learning_ineligible_count' => max(0, count($valid) - count($atlasDecideEligible)),
            'atlas_decide_learning_blockers' => $atlasDecideBlockers,
            'confidence' => $this->confidenceFor(count($valid)),
            'latest_recorded_at' => $latestIso === '' ? null : $latestIso,
            'latest_age_days' => $ageDays,
            'stale_data' => $stale,
            'latest_run_ids' => $latestRunIds,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $entries
     * @return array<string,mixed>
     */
    private function atlasDecideLearningEligibility(array $entries): array
    {
        $valid = array_values(array_filter($entries, static fn (array $entry): bool => (bool) ($entry['valid_for_ranking'] ?? false)));
        $eligible = array_values(array_filter($valid, fn (array $entry): bool => $this->atlasDecideLearningEligibleForEntry($entry)));
        $blockerCounts = [];
        $preview = [];
        foreach ($valid as $entry) {
            $blockers = $this->atlasDecideLearningBlockersForEntry($entry);
            if ($blockers === []) {
                continue;
            }
            foreach ($blockers as $blocker) {
                $blockerCounts[$blocker] = ($blockerCounts[$blocker] ?? 0) + 1;
            }
            if (count($preview) < 10) {
                $preview[] = [
                    'run_id' => $entry['run_id'] ?? null,
                    'entry_id' => $entry['entry_id'] ?? null,
                    'provider' => $entry['provider'] ?? null,
                    'model' => $entry['model'] ?? null,
                    'task_category' => $entry['task_category'] ?? null,
                    'difficulty_level' => $entry['difficulty_level'] ?? null,
                    'role' => $entry['role'] ?? null,
                    'blockers' => $blockers,
                ];
            }
        }

        return [
            'schema_version' => 'atlas.forge.rivals.atlas_decide_learning_eligibility.v1',
            'status' => count($eligible) === count($valid) ? 'ok' : 'blocked_for_some_entries',
            'valid_for_ranking_count' => count($valid),
            'eligible_entry_count' => count($eligible),
            'ineligible_entry_count' => max(0, count($valid) - count($eligible)),
            'blocker_counts' => $blockerCounts,
            'ineligible_entries_preview' => $preview,
            'score_or_claim_allowed' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $entries
     * @return list<array<string,mixed>>
     */
    private function aggregateByTaskCategory(array $entries): array
    {
        return $this->rankByKey($entries, 'task_category');
    }

    /**
     * @param  list<array<string,mixed>>  $entries
     * @return list<array<string,mixed>>
     */
    private function aggregateByRole(array $entries): array
    {
        return $this->rankByKey($entries, 'role');
    }

    /**
     * @param  list<array<string,mixed>>  $entries
     * @return list<array<string,mixed>>
     */
    private function aggregateByProviderModel(array $entries): array
    {
        $buckets = [];
        foreach ($entries as $entry) {
            $provider = (string) ($entry['provider'] ?? 'unknown');
            $model = (string) ($entry['model'] ?? 'unknown');
            $key = $provider.':'.$model;
            $buckets[$key] ??= [];
            $buckets[$key][] = $entry;
        }
        $rows = [];
        foreach ($buckets as $key => $items) {
            $row = $this->summarizeBucket($key, $items, 'provider_model');
            [$provider, $model] = explode(':', $key, 2) + ['unknown', 'unknown'];
            $row['provider'] = $provider;
            $row['model'] = $model;
            $rows[] = $row;
        }
        usort($rows, static fn (array $a, array $b): int => $b['average_score_valid'] <=> $a['average_score_valid']);

        return $rows;
    }

    /**
     * @param  list<array<string,mixed>>  $entries
     * @return list<array<string,mixed>>
     */
    private function aggregateByFramework(array $entries): array
    {
        $relevant = array_values(array_filter($entries, static fn (array $e): bool => isset($e['framework']) && $e['framework'] !== null));
        if ($relevant === []) {
            return [];
        }

        return $this->rankByKey($relevant, 'framework');
    }

    /**
     * @param  list<array<string,mixed>>  $entries
     * @return list<array<string,mixed>>
     */
    private function aggregateByTaskCategoryDifficultyRoleModel(array $entries): array
    {
        $buckets = [];
        foreach ($entries as $entry) {
            $key = implode('|', [
                (string) ($entry['task_category'] ?? 'unknown'),
                (string) ($entry['difficulty_level'] ?? 'unknown'),
                (string) ($entry['role'] ?? 'unknown'),
                (string) ($entry['provider'] ?? 'unknown'),
                (string) ($entry['model'] ?? 'unknown'),
            ]);
            $buckets[$key] ??= [];
            $buckets[$key][] = $entry;
        }

        $rows = [];
        foreach ($buckets as $key => $items) {
            [$category, $difficulty, $role, $provider, $model] = explode('|', $key, 5) + ['unknown', 'unknown', 'unknown', 'unknown', 'unknown'];
            $row = $this->summarizeBucket($key, $items, 'task_category_difficulty_role_provider_model');
            $row['task_category'] = $category;
            $row['difficulty_level'] = $difficulty === 'unknown' ? null : $difficulty;
            $row['role'] = $role;
            $row['provider'] = $provider;
            $row['model'] = $model;
            $row['case_ids'] = $this->collectUniqueStrings($items, 'case_id');
            $row['statistical_repeat_ready'] = $row['valid_count'] >= self::STATISTICAL_REPEAT_MIN_VALID_PER_BUCKET;
            $row['missing_valid_repetitions'] = max(0, self::STATISTICAL_REPEAT_MIN_VALID_PER_BUCKET - (int) $row['valid_count']);
            $rows[] = $row;
        }

        usort($rows, static function (array $a, array $b): int {
            if ($a['average_score_valid'] === $b['average_score_valid']) {
                return $b['valid_count'] <=> $a['valid_count'];
            }

            return $b['average_score_valid'] <=> $a['average_score_valid'];
        });

        return $rows;
    }

    /**
     * @param  list<array<string,mixed>>  $entries
     * @return list<array<string,mixed>>
     */
    private function aggregateByRunFamilyPromptTaskCategoryDifficultyRoleModel(array $entries): array
    {
        $buckets = [];
        foreach ($entries as $entry) {
            $key = implode('|', [
                (string) ($entry['run_family'] ?? 'unknown'),
                (string) ($entry['prompt_mode'] ?? 'unknown'),
                (string) ($entry['task_category'] ?? 'unknown'),
                (string) ($entry['difficulty_level'] ?? 'unknown'),
                (string) ($entry['role'] ?? 'unknown'),
                (string) ($entry['provider'] ?? 'unknown'),
                (string) ($entry['model'] ?? 'unknown'),
            ]);
            $buckets[$key] ??= [];
            $buckets[$key][] = $entry;
        }

        $rows = [];
        foreach ($buckets as $key => $items) {
            [$runFamily, $promptMode, $category, $difficulty, $role, $provider, $model] = explode('|', $key, 7) + ['unknown', 'unknown', 'unknown', 'unknown', 'unknown', 'unknown', 'unknown'];
            $row = $this->summarizeBucket($key, $items, 'run_family_prompt_task_category_difficulty_role_provider_model');
            $row['run_family'] = $runFamily === 'unknown' ? null : $runFamily;
            $row['prompt_mode'] = $promptMode === 'unknown' ? null : $promptMode;
            $row['task_category'] = $category;
            $row['difficulty_level'] = $difficulty === 'unknown' ? null : $difficulty;
            $row['role'] = $role;
            $row['provider'] = $provider;
            $row['model'] = $model;
            $row['case_ids'] = $this->collectUniqueStrings($items, 'case_id');
            $row['statistical_repeat_ready'] = $row['valid_count'] >= self::STATISTICAL_REPEAT_MIN_VALID_PER_BUCKET;
            $row['missing_valid_repetitions'] = max(0, self::STATISTICAL_REPEAT_MIN_VALID_PER_BUCKET - (int) $row['valid_count']);
            $rows[] = $row;
        }

        usort($rows, static function (array $a, array $b): int {
            if ($a['average_score_valid'] === $b['average_score_valid']) {
                return $b['valid_count'] <=> $a['valid_count'];
            }

            return $b['average_score_valid'] <=> $a['average_score_valid'];
        });

        return $rows;
    }

    /**
     * @param  list<array<string,mixed>>  $entries
     * @return array<string,mixed>
     */
    private function statisticalRepeatReadiness(array $entries): array
    {
        $rows = $this->aggregateByRunFamilyPromptTaskCategoryDifficultyRoleModel($entries);
        $validRows = array_values(array_filter($rows, static fn (array $row): bool => (int) ($row['valid_count'] ?? 0) > 0));
        $notReady = array_values(array_filter($validRows, static fn (array $row): bool => ! (bool) ($row['statistical_repeat_ready'] ?? false)));
        $unstable = array_values(array_filter($validRows, static fn (array $row): bool => ($row['score_stability'] ?? null) === 'unstable'));
        $confidenceReady = $validRows !== [] && $notReady === [] && $unstable === [];
        $notReadyBuckets = array_map(static fn (array $row): array => [
            'task_category' => $row['task_category'] ?? null,
            'difficulty_level' => $row['difficulty_level'] ?? null,
            'run_family' => $row['run_family'] ?? null,
            'prompt_mode' => $row['prompt_mode'] ?? null,
            'role' => $row['role'] ?? null,
            'provider' => $row['provider'] ?? null,
            'model' => $row['model'] ?? null,
            'valid_count' => $row['valid_count'] ?? 0,
            'missing_valid_repetitions' => $row['missing_valid_repetitions'] ?? self::STATISTICAL_REPEAT_MIN_VALID_PER_BUCKET,
        ], $notReady);
        $unstableBuckets = array_map(static fn (array $row): array => [
            'task_category' => $row['task_category'] ?? null,
            'difficulty_level' => $row['difficulty_level'] ?? null,
            'run_family' => $row['run_family'] ?? null,
            'prompt_mode' => $row['prompt_mode'] ?? null,
            'role' => $row['role'] ?? null,
            'provider' => $row['provider'] ?? null,
            'model' => $row['model'] ?? null,
            'valid_count' => $row['valid_count'] ?? 0,
            'score_stddev' => $row['score_stddev'] ?? null,
            'confidence_interval_95' => $row['confidence_interval_95'] ?? null,
        ], $unstable);

        return [
            'status' => $confidenceReady ? 'ok' : self::CONFIDENCE_INSUFFICIENT,
            'minimum_valid_repetitions_per_bucket' => self::STATISTICAL_REPEAT_MIN_VALID_PER_BUCKET,
            'confidence_interval_min_sample' => self::STATISTICAL_CONFIDENCE_INTERVAL_MIN_SAMPLE,
            'stability_stddev_max' => self::STATISTICAL_STABILITY_STDDEV_MAX,
            'bucket_count' => count($validRows),
            'ready_bucket_count' => count($validRows) - count($notReady),
            'not_ready_bucket_count' => count($notReady),
            'unstable_bucket_count' => count($unstable),
            'confidence_ready' => $confidenceReady,
            'not_ready_buckets' => $notReadyBuckets,
            'not_ready_buckets_preview' => array_slice($notReadyBuckets, 0, 20),
            'unstable_buckets' => $unstableBuckets,
            'unstable_buckets_preview' => array_slice($unstableBuckets, 0, 20),
            'claim_ready' => false,
            'external_claim_allowed' => false,
            'advisory_only' => true,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $entries
     * @param  array<string,mixed>  $aggregates
     * @return array<string,mixed>
     */
    private function externalClaimReadiness(array $entries, array $aggregates): array
    {
        $statisticalRepeat = (array) ($aggregates['statistical_repeat_readiness'] ?? []);
        $measurementPlan = $this->statisticalRepeatMeasurementPlan($statisticalRepeat);
        $validEntries = array_values(array_filter($entries, static fn (array $entry): bool => (bool) ($entry['valid_for_ranking'] ?? false)));
        $invalidEntries = count($entries) - count($validEntries);

        $blockers = [];
        if ($validEntries === []) {
            $blockers[] = 'valid_replayable_ledger_entries_required';
        }
        if (($statisticalRepeat['confidence_ready'] ?? false) !== true) {
            $blockers[] = 'statistical_repeat_repetitions_required';
        }
        if ((int) ($statisticalRepeat['unstable_bucket_count'] ?? 0) > 0) {
            $blockers[] = 'unstable_score_segments_require_more_evidence_or_human_review';
        }

        $readyForHumanCertification = $blockers === [];

        return [
            'status' => $readyForHumanCertification
                ? 'ready_for_human_certification_external_claim_still_blocked'
                : 'blocked_until_reproducible_evidence_complete',
            'schema_version' => 'atlas.forge.rivals.external_claim_readiness.v1',
            'valid_entry_count' => count($validEntries),
            'invalid_entry_count' => $invalidEntries,
            'requirements' => [
                'valid_replayable_ledger_entries' => $validEntries !== [],
                'statistical_repeat_confidence_ready' => (bool) ($statisticalRepeat['confidence_ready'] ?? false),
                'minimum_valid_repetitions_per_bucket' => self::STATISTICAL_REPEAT_MIN_VALID_PER_BUCKET,
                'stable_score_buckets' => (int) ($statisticalRepeat['unstable_bucket_count'] ?? 0) === 0,
                'human_external_certification_required' => true,
                'external_rivals_certification_unlocked' => false,
            ],
            'blockers' => $blockers,
            'statistical_repeat_measurement_plan' => $measurementPlan,
            'reproducible_evidence_ready_for_human_certification' => $readyForHumanCertification,
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
     * @param  array<string,mixed>  $statisticalRepeat
     * @return array<string,mixed>
     */
    private function statisticalRepeatMeasurementPlan(array $statisticalRepeat): array
    {
        $repeatTargets = array_values(array_map(
            fn (array $row): array => $this->repeatTarget($row),
            (array) ($statisticalRepeat['not_ready_buckets'] ?? $statisticalRepeat['not_ready_buckets_preview'] ?? []),
        ));
        $unstableTargets = array_values(array_map(
            fn (array $row): array => $this->repeatTarget($row),
            (array) ($statisticalRepeat['unstable_buckets'] ?? $statisticalRepeat['unstable_buckets_preview'] ?? []),
        ));
        $complete = (bool) ($statisticalRepeat['confidence_ready'] ?? false)
            && $repeatTargets === []
            && $unstableTargets === [];

        return [
            'schema_version' => 'atlas.forge.rivals.statistical_repeat_measurement_plan.v1',
            'status' => $complete ? 'complete' : 'needs_repetition',
            'minimum_valid_repetitions_per_bucket' => self::STATISTICAL_REPEAT_MIN_VALID_PER_BUCKET,
            'bucket_count' => (int) ($statisticalRepeat['bucket_count'] ?? 0),
            'ready_bucket_count' => (int) ($statisticalRepeat['ready_bucket_count'] ?? 0),
            'not_ready_bucket_count' => (int) ($statisticalRepeat['not_ready_bucket_count'] ?? 0),
            'unstable_bucket_count' => (int) ($statisticalRepeat['unstable_bucket_count'] ?? 0),
            'repeat_target_count' => count($repeatTargets),
            'unstable_target_count' => count($unstableTargets),
            'repeat_targets' => $repeatTargets,
            'repeat_targets_preview' => array_slice($repeatTargets, 0, 20),
            'unstable_targets' => $unstableTargets,
            'unstable_targets_preview' => array_slice($unstableTargets, 0, 20),
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'claim_ready' => false,
            'external_claim_allowed' => false,
            'score_or_claim_allowed' => false,
            'advisory_only' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function repeatTarget(array $row): array
    {
        $missing = max(0, (int) ($row['missing_valid_repetitions'] ?? self::STATISTICAL_REPEAT_MIN_VALID_PER_BUCKET));
        $promptMode = (string) ($row['prompt_mode'] ?? 'human-normal');
        $taskCategory = (string) ($row['task_category'] ?? 'unknown');
        $difficulty = (string) ($row['difficulty_level'] ?? 'L3');

        return [
            'task_category' => $taskCategory,
            'difficulty_level' => $difficulty,
            'run_family' => $row['run_family'] ?? null,
            'prompt_mode' => $promptMode,
            'role' => $row['role'] ?? null,
            'provider' => $row['provider'] ?? null,
            'model' => $row['model'] ?? null,
            'valid_count' => (int) ($row['valid_count'] ?? 0),
            'missing_valid_repetitions' => $missing,
            'suggested_minimum_additional_runs' => $missing,
            'next_command' => 'php artisan atlas:forge:rivals run-battery --preset=statistical-repeat --mode=provider_arena --prompt-mode='
                .$promptMode.' --task-category='.$taskCategory.' --difficulty='.$difficulty.' --json',
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $entries
     * @return array<string,mixed>
     */
    private function atlasVsRawDelta(array $entries): array
    {
        $valid = array_values(array_filter($entries, static fn (array $e): bool => (bool) ($e['valid_for_ranking'] ?? false)));
        if ($valid === []) {
            return [
                'status' => self::CONFIDENCE_INSUFFICIENT,
                'atlas_forge_avg' => null,
                'raw_provider_avg' => null,
                'delta' => null,
                'sample_size_atlas_forge' => 0,
                'sample_size_raw_provider' => 0,
            ];
        }
        $atlasForge = array_filter($valid, static fn (array $e): bool => ($e['runner_type'] ?? null) === 'atlas_forge');
        $raw = array_filter($valid, static fn (array $e): bool => ($e['runner_type'] ?? null) === 'raw_provider');
        $atlasAvg = $atlasForge === [] ? null : $this->averageScore($atlasForge);
        $rawAvg = $raw === [] ? null : $this->averageScore($raw);
        $delta = ($atlasAvg !== null && $rawAvg !== null) ? round($atlasAvg - $rawAvg, 4) : null;

        return [
            'status' => $delta === null ? self::CONFIDENCE_INSUFFICIENT : 'ok',
            'atlas_forge_avg' => $atlasAvg,
            'raw_provider_avg' => $rawAvg,
            'delta' => $delta,
            'sample_size_atlas_forge' => count($atlasForge),
            'sample_size_raw_provider' => count($raw),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $entries
     * @return array<string,mixed>
     */
    private function fairVsFullPowerDelta(array $entries): array
    {
        $valid = array_values(array_filter($entries, static fn (array $e): bool => (bool) ($e['valid_for_ranking'] ?? false)));
        $fair = array_filter($valid, static fn (array $e): bool => ($e['mode'] ?? null) === 'fair');
        $full = array_filter($valid, static fn (array $e): bool => ($e['mode'] ?? null) === 'full_power');
        $fairAvg = $fair === [] ? null : $this->averageScore($fair);
        $fullAvg = $full === [] ? null : $this->averageScore($full);
        $delta = ($fairAvg !== null && $fullAvg !== null) ? round($fullAvg - $fairAvg, 4) : null;

        return [
            'status' => $delta === null ? self::CONFIDENCE_INSUFFICIENT : 'ok',
            'fair_avg' => $fairAvg,
            'full_power_avg' => $fullAvg,
            'delta_full_minus_fair' => $delta,
            'sample_size_fair' => count($fair),
            'sample_size_full_power' => count($full),
        ];
    }

    /**
     * Pareto-style sketch: each (provider, model, mode) bucket contributes a
     * point (avg_cost, avg_score). Invalid entries are excluded from the
     * frontier so a hard-failed run can never appear as a cost win.
     *
     * @param  list<array<string,mixed>>  $entries
     * @return list<array<string,mixed>>
     */
    private function costQualityFrontier(array $entries): array
    {
        $valid = array_values(array_filter($entries, static fn (array $e): bool => (bool) ($e['valid_for_ranking'] ?? false)));
        if ($valid === []) {
            return [];
        }
        $buckets = [];
        foreach ($valid as $entry) {
            $key = ($entry['provider'] ?? 'unknown').':'.($entry['model'] ?? 'unknown').':'.($entry['mode'] ?? 'unknown');
            $buckets[$key] ??= ['cost' => [], 'score' => [], 'tokens' => [], 'duration' => []];
            $buckets[$key]['cost'][] = (float) ($entry['cost_estimate'] ?? 0.0);
            $buckets[$key]['score'][] = (float) ($entry['score_total'] ?? 0.0);
            $buckets[$key]['tokens'][] = (int) ($entry['tokens_used'] ?? 0);
            $buckets[$key]['duration'][] = (int) ($entry['duration_ms'] ?? 0);
        }
        $rows = [];
        foreach ($buckets as $key => $data) {
            $count = count($data['score']);
            $rows[] = [
                'key' => $key,
                'avg_cost' => $count === 0 ? 0.0 : round(array_sum($data['cost']) / $count, 6),
                'avg_score' => $count === 0 ? 0.0 : round(array_sum($data['score']) / $count, 4),
                'avg_tokens' => $count === 0 ? 0 : (int) round(array_sum($data['tokens']) / $count),
                'avg_duration_ms' => $count === 0 ? 0 : (int) round(array_sum($data['duration']) / $count),
                'sample_size' => $count,
            ];
        }
        // Sort by score desc, then cost asc.
        usort($rows, static function (array $a, array $b): int {
            if ($a['avg_score'] === $b['avg_score']) {
                return $a['avg_cost'] <=> $b['avg_cost'];
            }

            return $b['avg_score'] <=> $a['avg_score'];
        });

        return $rows;
    }

    /**
     * @param  iterable<array<string,mixed>>  $entries
     */
    private function averageScore(iterable $entries): float
    {
        $sum = 0.0;
        $count = 0;
        foreach ($entries as $entry) {
            $sum += (float) ($entry['score_total'] ?? 0);
            $count++;
        }

        return $count === 0 ? 0.0 : round($sum / $count, 4);
    }

    /**
     * @param  list<array<string,mixed>>  $entries
     * @return list<string>
     */
    private function collectUniqueStrings(array $entries, string $field): array
    {
        $out = [];
        foreach ($entries as $entry) {
            $value = (string) ($entry[$field] ?? '');
            if ($value !== '' && ! in_array($value, $out, true)) {
                $out[] = $value;
            }
        }
        sort($out);

        return $out;
    }

    private function readJson(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $blob = (string) @file_get_contents($path);
        $row = json_decode($blob, true);

        return is_array($row) ? $row : [];
    }

    /**
     * @param  mixed  $value
     */
    private function coerceFloat($value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(static fn ($v): string => (string) $v, $value));
    }

    /**
     * @param  array<string,mixed>  $value
     */
    private function stableJson(array $value): string
    {
        ksort($value);
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($encoded) ? $encoded : '';
    }

    private function utcNow(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }

    private function defaultLedgerRoot(): string
    {
        if (function_exists('storage_path')) {
            return storage_path('app/rivals-forge-ledger');
        }

        return '/Users/vitorepf/develop/Atlas-rivals/ledger';
    }
}
