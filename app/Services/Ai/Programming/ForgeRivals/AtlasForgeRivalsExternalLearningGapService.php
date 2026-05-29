<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

/**
 * Atlas Forge Rivals · External Learning Gap v1.
 *
 * Read-only coverage matrix that tells the operator which provider/model ×
 * category × difficulty buckets still need evidence before Atlas Decide can
 * safely learn from Rivals measurements. It never calls providers and never
 * mutates routing.
 */
final class AtlasForgeRivalsExternalLearningGapService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.external_learning_gap.v1';

    /** @var list<string> */
    private const DEFAULT_PROVIDERS = ['claude', 'codex', 'gemini', 'cursor', 'composer'];

    /** @var list<string> */
    private const DEFAULT_CATEGORIES = ['frontend', 'backend', 'bugfix', 'refactor', 'test', 'docs', 'security', 'architecture', 'performance'];

    /** @var list<string> */
    private const DEFAULT_DIFFICULTIES = ['L1', 'L2', 'L3', 'L4', 'L5'];

    /** @var list<string> */
    private const DEFAULT_ROLES = ['builder'];

    public function __construct(
        private readonly AtlasForgeRivalsProviderPerformanceLedgerService $ledger,
        private readonly AtlasForgeRivalsProviderModelRegistryService $models,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function report(array $input = []): array
    {
        $snapshot = $this->ledger->snapshot($this->ledgerSnapshotInput($input));
        $observed = $this->observedBuckets((array) data_get($snapshot, 'aggregates.by_task_category_difficulty_role_model', []));
        $threshold = max(1, (int) ($input['min_valid_per_bucket'] ?? AtlasForgeRivalsProviderPerformanceLedgerService::STATISTICAL_REPEAT_MIN_VALID_PER_BUCKET));

        $targets = $this->targetRows($input);
        $rows = [];
        foreach ($targets as $target) {
            $modelBlockers = array_values(array_map('strval', (array) ($target['model_resolution_blockers'] ?? [])));
            $key = $this->bucketKey(
                (string) $target['provider'],
                (string) $target['model'],
                (string) $target['task_category'],
                (string) $target['difficulty_level'],
                (string) $target['role'],
            );
            $existing = $observed[$key] ?? null;
            $valid = (int) ($existing['valid_count'] ?? 0);
            $missing = $modelBlockers === [] ? max(0, $threshold - $valid) : 0;
            $rows[] = [
                'status' => $modelBlockers !== []
                    ? 'blocked_invalid_model_filter'
                    : ($missing === 0 ? 'sufficient_for_statistical_repeat_bucket' : 'missing_external_evidence'),
                'case_set' => $target['case_set'],
                'provider' => $target['provider'],
                'provider_family' => $target['provider_family'],
                'requested_model' => $target['requested_model'] ?? null,
                'model' => $target['model'],
                'model_id' => $target['model_id'],
                'model_resolution_blockers' => $modelBlockers,
                'task_category' => $target['task_category'],
                'difficulty_level' => $target['difficulty_level'],
                'role' => $target['role'],
                'valid_evidence_count' => $valid,
                'required_valid_evidence_count' => $threshold,
                'missing_valid_evidence_count' => $missing,
                'observed_case_ids' => array_values((array) ($existing['case_ids'] ?? [])),
                'dry_run_command' => $this->nextMeasurementCommand($target, true),
                'real_execution_command_template' => $this->nextMeasurementCommand($target, false),
                'required_confirmations_before_real_execution' => [
                    'confirm_runbook_reviewed',
                    'confirm_provider_cost',
                    'confirm_real_provider_call',
                ],
                'next_measurement_command' => $this->nextMeasurementCommand($target, true),
            ];
        }

        $missingRows = array_values(array_filter($rows, static fn (array $row): bool => (int) $row['missing_valid_evidence_count'] > 0));
        $modelBlockers = array_values(array_unique(array_merge(...array_map(
            static fn (array $row): array => (array) ($row['model_resolution_blockers'] ?? []),
            $rows,
        ))));
        $coveredRows = count($rows) - count($missingRows);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $modelBlockers === [] ? 'ok' : 'blocked',
            'learning_gap_status' => $modelBlockers !== []
                ? 'blocked_invalid_model_filter'
                : ($missingRows === [] ? 'coverage_floor_met' : 'needs_more_external_evidence'),
            'generated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            'ledger_total_entries' => $snapshot['total_entries'] ?? 0,
            'ledger_filtered_entries' => $snapshot['filtered_entries'] ?? 0,
            'minimum_valid_evidence_per_bucket' => $threshold,
            'target_bucket_count' => count($rows),
            'covered_bucket_count' => $coveredRows,
            'missing_bucket_count' => count($missingRows),
            'coverage_ratio' => count($rows) > 0 ? round($coveredRows / count($rows), 4) : 0.0,
            'filters' => $snapshot['filters'] ?? [],
            'target_providers' => array_values(array_unique(array_map(static fn (array $row): string => (string) $row['provider_family'], $targets))),
            'target_categories' => array_values(array_unique(array_map(static fn (array $row): string => (string) $row['task_category'], $targets))),
            'target_difficulties' => array_values(array_unique(array_map(static fn (array $row): string => (string) $row['difficulty_level'], $targets))),
            'target_roles' => array_values(array_unique(array_map(static fn (array $row): string => (string) $row['role'], $targets))),
            'blockers' => $modelBlockers,
            'rows' => (bool) ($input['include_full_matrix'] ?? false) ? $rows : null,
            'rows_preview' => array_slice($rows, 0, 50),
            'missing_buckets_preview' => array_slice($missingRows, 0, 50),
            'next_commands_preview' => array_values(array_unique(array_map(
                static fn (array $row): string => (string) $row['next_measurement_command'],
                array_slice($missingRows, 0, 20),
            ))),
            'atlas_decide_learning_effect' => $missingRows === []
                ? 'coverage_ready_for_replay_matrix_ledger_and_policy_review'
                : 'collect_more_external_evidence_before_model_preference',
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
            'next_command' => 'php artisan atlas:forge:rivals external-learning-gap --json',
        ];
    }

    /**
     * Target-shaping filters such as provider/category/difficulty belong to
     * this service's grid. Passing them straight to the ledger would exact
     * match provider aliases like `claude` against stored providers like
     * `anthropic_claude` and hide valid evidence.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function ledgerSnapshotInput(array $input): array
    {
        return array_intersect_key($input, array_flip([
            'run_id',
            'run_ids',
            'run_family',
            'prompt_mode',
            'mode_filter',
        ]));
    }

    /**
     * @param  list<array<string,mixed>>  $aggregateRows
     * @return array<string,array<string,mixed>>
     */
    private function observedBuckets(array $aggregateRows): array
    {
        $out = [];
        foreach ($aggregateRows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $provider = $this->providerFamily((string) ($row['provider'] ?? ''));
            $model = (string) ($row['model'] ?? '');
            $category = (string) ($row['task_category'] ?? '');
            $difficulty = (string) ($row['difficulty_level'] ?? '');
            $role = (string) ($row['role'] ?? '');
            if ($provider === '' || $model === '' || $category === '' || $difficulty === '' || $role === '') {
                continue;
            }
            $out[$this->bucketKey($provider, $model, $category, $difficulty, $role)] = $row + [
                'provider_family' => $provider,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return list<array<string,mixed>>
     */
    private function targetRows(array $input): array
    {
        $providers = $this->csv($input['provider'] ?? null, self::DEFAULT_PROVIDERS);
        $categories = $this->csv($input['task_category'] ?? $input['category'] ?? null, self::DEFAULT_CATEGORIES);
        $difficulties = array_map('strtoupper', $this->csv($input['difficulty_level'] ?? $input['difficulty'] ?? null, self::DEFAULT_DIFFICULTIES));
        $roles = $this->csv($input['role'] ?? null, self::DEFAULT_ROLES);
        $modelFilters = $this->csv($input['model'] ?? $input['arm_a_model'] ?? null, []);
        $caseSet = trim((string) ($input['case_set'] ?? ''));
        $caseSet = $caseSet !== '' ? $caseSet : 'industrial-50';

        $rows = [];
        foreach ($providers as $provider) {
            foreach ($this->targetModelsForProvider($provider, $modelFilters) as $model) {
                foreach ($categories as $category) {
                    foreach ($difficulties as $difficulty) {
                        foreach ($roles as $role) {
                            $rows[] = [
                                'case_set' => $caseSet,
                                'provider' => $this->ledgerProviderForFamily($provider),
                                'provider_family' => $provider,
                                'requested_model' => $model['requested_model'] ?? null,
                                'model' => (string) $model['canonical_model'],
                                'model_id' => (string) $model['model_id'],
                                'model_resolution_blockers' => (array) ($model['blockers'] ?? []),
                                'task_category' => $category,
                                'difficulty_level' => $difficulty,
                                'role' => $role,
                            ];
                        }
                    }
                }
            }
        }

        return $rows;
    }

    /**
     * @param  list<string>  $modelFilters
     * @return list<array<string,mixed>>
     */
    private function targetModelsForProvider(string $provider, array $modelFilters = []): array
    {
        $provider = strtolower(trim($provider));
        $providers = $this->models->providers();
        $def = $providers[$provider] ?? null;
        if (! is_array($def)) {
            return [[
                'canonical_model' => 'unknown',
                'model_id' => 'unknown',
            ]];
        }

        if ($modelFilters !== []) {
            return array_values(array_map(function (string $model) use ($provider): array {
                $resolved = $this->models->resolve($provider, $model);
                if ((bool) ($resolved['ok'] ?? false)) {
                    return [
                        'requested_model' => $model,
                        'canonical_model' => (string) ($resolved['canonical_model'] ?? $model),
                        'model_id' => (string) ($resolved['model_id'] ?? $model),
                        'blockers' => [],
                    ];
                }

                return [
                    'requested_model' => $model,
                    'canonical_model' => 'unknown',
                    'model_id' => 'unknown',
                    'blockers' => (array) ($resolved['blockers'] ?? ['provider_model_unknown:'.$provider.':'.$model]),
                ];
            }, $modelFilters));
        }

        $rows = [];
        foreach ((array) ($def['models'] ?? []) as $canonical => $model) {
            if (! is_array($model)) {
                continue;
            }
            $rows[] = [
                'canonical_model' => (string) $canonical,
                'model_id' => (string) ($model['model_id'] ?? $canonical),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<string>  $default
     * @return list<string>
     */
    private function csv(mixed $raw, array $default): array
    {
        if (! is_string($raw) || trim($raw) === '') {
            return $default;
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (string $value): string => strtolower(trim($value)),
            preg_split('/[,|]/', $raw) ?: [],
        ), static fn (string $value): bool => $value !== '')));
    }

    private function bucketKey(string $provider, string $model, string $category, string $difficulty, string $role): string
    {
        return implode('|', [
            $this->providerFamily($provider),
            strtolower(trim($model)),
            strtolower(trim($category)),
            strtoupper(trim($difficulty)),
            strtolower(trim($role)),
        ]);
    }

    private function providerFamily(string $provider): string
    {
        return match (strtolower(trim($provider))) {
            'anthropic_claude', 'claude_cli', 'claude' => 'claude',
            'openai_codex', 'openai_gpt', 'codex_cli', 'codex' => 'codex',
            'google_gemini', 'gemini_cli', 'gemini' => 'gemini',
            'cursor_cli', 'cursor' => 'cursor',
            'composer_2_5', 'composer' => 'composer',
            default => strtolower(trim($provider)),
        };
    }

    private function ledgerProviderForFamily(string $provider): string
    {
        return match ($this->providerFamily($provider)) {
            'claude' => 'claude',
            'codex' => 'codex',
            'gemini' => 'gemini',
            'cursor' => 'cursor',
            'composer' => 'composer',
            default => strtolower(trim($provider)),
        };
    }

    /**
     * @param  array<string,mixed>  $target
     */
    private function nextMeasurementCommand(array $target, bool $dryRun): string
    {
        $targetProvider = $this->providerFamily((string) $target['provider_family']);
        $baseline = $this->baselineForProvider($targetProvider);
        $command = sprintf(
            'php artisan atlas:forge:rivals run-arena --case-set=%s --mode=provider_arena --arm-a=%s --arm-a-model=%s --arm-b=%s --arm-b-model=%s --task-category=%s --difficulty=%s --role=%s',
            (string) ($target['case_set'] ?? 'industrial-50'),
            $this->armForProvider($targetProvider),
            (string) $target['model'],
            $baseline['arm'],
            $baseline['model'],
            (string) $target['task_category'],
            (string) $target['difficulty_level'],
            (string) $target['role'],
        );

        if ($dryRun) {
            return $command.' --dry-run --json';
        }

        return $command.' --confirm-runbook-reviewed --confirm-provider-cost --confirm-real-provider-call --json';
    }

    /**
     * @return array{arm:string,model:string}
     */
    private function baselineForProvider(string $provider): array
    {
        return match ($this->providerFamily($provider)) {
            'claude' => ['arm' => 'codex_cli', 'model' => 'gpt-5.5'],
            'codex', 'gemini' => ['arm' => 'claude_code', 'model' => 'sonnet'],
            'cursor', 'composer' => ['arm' => 'codex_cli', 'model' => 'gpt-5.5'],
            default => ['arm' => 'claude_code', 'model' => 'sonnet'],
        };
    }

    private function armForProvider(string $provider): string
    {
        return match ($this->providerFamily($provider)) {
            'claude' => 'claude_code',
            'codex' => 'codex_cli',
            'gemini' => 'gemini_cli',
            'cursor' => 'cursor_cli',
            'composer' => 'composer_2_5',
            default => 'claude_code',
        };
    }
}
