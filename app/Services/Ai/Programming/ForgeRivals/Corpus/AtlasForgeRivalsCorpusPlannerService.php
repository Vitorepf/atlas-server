<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals\Corpus;

use App\Services\Ai\Programming\ForgeRivals\DeepSwe\AtlasForgeRivalsDeepSweCompatibilityService;

/**
 * Atlas Forge Rivals · Provider Arena Corpus Planner (v1).
 *
 * Resolves --case-set / --case / --task-category into a structured,
 * replayable plan of cases that the Provider Arena will iterate over.
 *
 * In dry-run / local_fake the planner is the entire execution: it emits a
 * multi-case manifest with no provider invocation. In real-provider modes
 * the planner is the gate that the arena run service consults before
 * delegating to the battery.
 *
 * Honest blockers (no silent no-ops):
 *   - unknown_case_set
 *   - unknown_case_id
 *   - unknown_task_category
 *   - corpus_plan_empty
 *   - both_case_and_case_set_resolve_to_nothing
 *
 * Schema: atlas.forge.rivals.provider_arena_corpus_plan.v1
 */
final class AtlasForgeRivalsCorpusPlannerService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.provider_arena_corpus_plan.v1';

    public function __construct(
        private readonly AtlasForgeRivalsProviderArenaCorpusService $corpus,
        private readonly AtlasForgeRivalsDeepSweCompatibilityService $deepSwe,
    ) {}

    /**
     * @param  array<string,mixed>  $input  Expects keys: case_set?, case?, task_category?
     * @return array<string,mixed>
     */
    public function plan(array $input): array
    {
        $caseSet = strtolower(trim((string) ($input['case_set'] ?? '')));
        $caseId = trim((string) ($input['case'] ?? ''));
        $taskCategory = strtolower(trim((string) ($input['task_category'] ?? '')));

        $blockers = [];
        $resolved = [];
        $appliedFilters = [];

        if ($caseSet === 'deepswe') {
            $deepSwePlan = $this->deepSwe->inspect($input);
            if (($deepSwePlan['status'] ?? '') !== 'ok') {
                return [
                    'status' => 'blocked',
                    'schema_version' => self::SCHEMA_VERSION,
                    'blockers' => (array) ($deepSwePlan['blockers'] ?? ['deepswe_plan_blocked']),
                    'applied_filters' => ['case_set=deepswe'],
                    'cases' => [],
                    'count' => 0,
                    'external_provider_call' => false,
                    'provider_tokens_spent' => false,
                    'separated_from_external_rivals_certification' => true,
                    'deepswe_readiness' => $deepSwePlan,
                ];
            }

            return [
                'status' => 'ok',
                'schema_version' => self::SCHEMA_VERSION,
                'applied_filters' => ['case_set=deepswe'],
                'cases' => $this->summarizeDeepSweTasks((array) ($deepSwePlan['tasks'] ?? []), $taskCategory),
                'count' => (int) ($deepSwePlan['valid_task_count'] ?? 0),
                'replay_manifest' => $this->deepSweReplayManifest($deepSwePlan),
                'external_provider_call' => false,
                'provider_tokens_spent' => false,
                'separated_from_external_rivals_certification' => true,
                'deepswe_readiness' => $deepSwePlan,
                'note' => 'Plano DeepSWE/Harbor gerado sem Pier, Docker, provider ou token spend.',
            ];
        }

        if ($caseId !== '') {
            try {
                $resolved = [$this->corpus->case($caseId)];
                $appliedFilters[] = 'case='.$caseId;
            } catch (\InvalidArgumentException $e) {
                $blockers[] = $e->getMessage();
            }
        } elseif ($caseSet !== '') {
            try {
                $resolved = $this->corpus->casesForCaseSet($caseSet);
                $appliedFilters[] = 'case_set='.$caseSet;
            } catch (\InvalidArgumentException $e) {
                $blockers[] = $e->getMessage();
            }
        } elseif ($taskCategory !== '') {
            try {
                $resolved = $this->corpus->casesForTaskCategory($taskCategory);
                $appliedFilters[] = 'task_category='.$taskCategory;
            } catch (\InvalidArgumentException $e) {
                $blockers[] = $e->getMessage();
            }
        } else {
            $resolved = $this->corpus->casesForCaseSet(AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_QUICK);
            $appliedFilters[] = 'case_set=quick (default)';
        }

        if ($taskCategory !== '' && $caseId === '' && $caseSet !== '') {
            $canonicalTaskCategory = AtlasForgeRivalsProviderArenaCorpusService::LEGACY_CATEGORY_ALIAS[$taskCategory] ?? $taskCategory;
            $resolved = array_values(array_filter(
                $resolved,
                static fn (array $c): bool => (string) ($c['category'] ?? '') === $canonicalTaskCategory,
            ));
            $appliedFilters[] = 'task_category='.$canonicalTaskCategory;
        }

        if ($blockers === [] && $resolved === []) {
            $blockers[] = 'corpus_plan_empty';
        }

        if ($blockers !== []) {
            return [
                'status' => 'blocked',
                'schema_version' => self::SCHEMA_VERSION,
                'blockers' => array_values(array_unique($blockers)),
                'applied_filters' => $appliedFilters,
                'cases' => [],
                'count' => 0,
                'external_provider_call' => false,
                'separated_from_external_rivals_certification' => true,
            ];
        }

        return [
            'status' => 'ok',
            'schema_version' => self::SCHEMA_VERSION,
            'applied_filters' => $appliedFilters,
            'cases' => $this->summarize($resolved),
            'count' => count($resolved),
            'replay_manifest' => $this->replayManifest($resolved, $appliedFilters),
            'external_provider_call' => false,
            'separated_from_external_rivals_certification' => true,
            'note' => 'Plano declarativo gerado sem provider; cada caso preserva seu próprio replay_manifest interno.',
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $cases
     * @return list<array<string,mixed>>
     */
    private function summarize(array $cases): array
    {
        return array_values(array_map(
            static fn (array $c): array => [
                'case_id' => (string) ($c['case_id'] ?? ''),
                'title' => (string) ($c['title'] ?? ''),
                'category' => (string) ($c['category'] ?? ''),
                'secondary_categories' => array_values(array_map(
                    static fn ($s): string => (string) $s,
                    (array) ($c['secondary_categories'] ?? []),
                )),
                'difficulty' => (string) ($c['difficulty'] ?? ''),
                'task_category' => (string) ($c['task_category'] ?? ''),
                'role_focus' => (string) ($c['role_focus'] ?? ''),
                'task_type' => (string) ($c['task_type'] ?? ''),
                'industrial_suite' => (string) ($c['industrial_suite'] ?? ''),
                'industrial_case_set' => (string) ($c['industrial_case_set'] ?? ''),
                'industrial_domains' => array_values(array_map(
                    static fn ($v): string => (string) $v,
                    (array) ($c['industrial_domains'] ?? []),
                )),
                'human_prompt' => (string) ($c['human_prompt'] ?? ''),
                'context_profile' => $c['context_profile'] ?? null,
                'measurement_tags' => array_values(array_map(
                    static fn ($v): string => (string) $v,
                    (array) ($c['measurement_tags'] ?? []),
                )),
                'human_prompt_probe' => $c['human_prompt_probe'] ?? null,
                'anti_tie_pressure' => $c['anti_tie_pressure'] ?? null,
                'meta_provider_stress' => $c['meta_provider_stress'] ?? null,
                'extreme_differentiator' => $c['extreme_differentiator'] ?? null,
                'ceiling_360' => $c['ceiling_360'] ?? null,
                'ceiling_pressure_profile' => $c['ceiling_pressure_profile'] ?? null,
                'extreme_hardening' => $c['extreme_hardening'] ?? null,
                'measured_capabilities' => array_values(array_map(
                    static fn ($v): string => (string) $v,
                    (array) ($c['measured_capabilities'] ?? []),
                )),
                'difficulty_level' => (string) ($c['difficulty_level'] ?? ''),
                'difficulty_score' => isset($c['difficulty_score']) ? (float) $c['difficulty_score'] : null,
                'difficulty_reason' => (string) ($c['difficulty_reason'] ?? ''),
                'planning_weight' => isset($c['planning_weight']) ? (float) $c['planning_weight'] : null,
                'execution_weight' => isset($c['execution_weight']) ? (float) $c['execution_weight'] : null,
                'ambiguity_level' => (string) ($c['ambiguity_level'] ?? ''),
                'risk_level' => (string) ($c['risk_level'] ?? ''),
                'objective' => (string) ($c['objective'] ?? ''),
                'business_rule' => (string) ($c['business_rule'] ?? ''),
                'fixture_seed_path' => (string) ($c['fixture_seed_path'] ?? ''),
                'quick_test_command' => (string) ($c['quick_test_command'] ?? ''),
                'full_test_command' => (string) ($c['full_test_command'] ?? ''),
                'allowed_files_scope' => array_values(array_map(
                    static fn ($g): string => (string) $g,
                    (array) ($c['allowed_files_scope'] ?? []),
                )),
                'forbidden_files_scope' => array_values(array_map(
                    static fn ($g): string => (string) $g,
                    (array) ($c['forbidden_files_scope'] ?? []),
                )),
                'expected_changed_files' => array_values(array_map(
                    static fn ($g): string => (string) $g,
                    (array) ($c['expected_changed_files'] ?? []),
                )),
                'acceptance_criteria' => array_values(array_map(
                    static fn ($v): string => (string) $v,
                    (array) ($c['acceptance_criteria'] ?? []),
                )),
                'quality_weights' => $c['quality_weights'] ?? null,
                'quality_gates' => $c['quality_gates'] ?? null,
                'scoring_dimensions' => array_values(array_map(
                    static fn ($v): string => (string) $v,
                    (array) ($c['scoring_dimensions'] ?? []),
                )),
                'oracle' => $c['oracle'] ?? null,
                'hidden_oracle_metadata' => $c['hidden_oracle_metadata'] ?? null,
                'statistical_repeat' => $c['statistical_repeat'] ?? null,
                'timeout_policy' => $c['timeout_policy'] ?? null,
                'evidence_requirements' => array_values(array_map(
                    static fn ($v): string => (string) $v,
                    (array) ($c['evidence_requirements'] ?? []),
                )),
                'replay_requirements' => array_values(array_map(
                    static fn ($v): string => (string) $v,
                    (array) ($c['replay_requirements'] ?? []),
                )),
                'fairness_notes' => (string) ($c['fairness_notes'] ?? ''),
                'human_review_notes' => (string) ($c['human_review_notes'] ?? ''),
                'claim_level' => (string) ($c['claim_level'] ?? ''),
                'invalid_if' => array_values(array_map(
                    static fn ($v): string => (string) $v,
                    (array) ($c['invalid_if'] ?? []),
                )),
            ],
            $cases,
        ));
    }

    /**
     * @param  list<array<string,mixed>>  $cases
     * @param  list<string>  $appliedFilters
     * @return array<string,mixed>
     */
    private function replayManifest(array $cases, array $appliedFilters): array
    {
        $caseIds = array_values(array_map(
            static fn (array $c): string => (string) ($c['case_id'] ?? ''),
            $cases,
        ));

        $deterministic = [
            'schema_version' => 'atlas.forge.rivals.provider_arena_corpus_replay.v1',
            'corpus_content_hash' => $this->corpus->contentHash(),
            'applied_filters' => $appliedFilters,
            'case_ids' => $caseIds,
            'external_provider_call' => false,
            'separated_from_external_rivals_certification' => true,
        ];

        $deterministic['plan_hash'] = hash(
            'sha256',
            (string) json_encode($deterministic, JSON_UNESCAPED_SLASHES),
        );

        return $deterministic + [
            'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'replay_contract' => 'rodar planner com os mesmos filters em qualquer host reproduz a mesma lista, na mesma ordem, byte a byte.',
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $tasks
     * @return list<array<string,mixed>>
     */
    private function summarizeDeepSweTasks(array $tasks, string $taskCategory): array
    {
        return array_values(array_map(
            static fn (array $task): array => [
                'case_id' => 'deepswe:'.(string) ($task['task_id'] ?? basename((string) ($task['task_dir'] ?? 'task'))),
                'title' => 'DeepSWE '.(string) ($task['task_id'] ?? 'task'),
                'category' => $taskCategory !== '' ? $taskCategory : 'external_benchmark',
                'secondary_categories' => ['deepswe', 'harbor', 'external_reproducible_benchmark'],
                'difficulty' => 'external',
                'task_category' => $taskCategory,
                'case_source' => 'deepswe',
                'case_set' => 'deepswe',
                'task_format' => 'harbor',
                'task_type' => 'external_long_horizon_engineering',
                'language' => $task['language'] ?? null,
                'repository' => $task['repository'] ?? null,
                'base_commit' => $task['base_commit'] ?? null,
                'prebuilt_image' => $task['prebuilt_image'] ?? null,
                'manifest_hash' => $task['manifest_hash'] ?? null,
                'artifact_hashes' => $task['artifact_hashes'] ?? [],
                'verifier' => $task['verifier'] ?? null,
                'solution_reference' => $task['solution_reference'] ?? null,
                'agent_visible_inputs' => $task['agent_visible_inputs'] ?? null,
                'evidence_requirements' => (array) ($task['evidence_requirements'] ?? []),
                'replay_requirements' => [
                    'same_task_manifest_hash',
                    'same_verifier_hash',
                    'same_environment_metadata',
                    'same_runner_contract',
                ],
                'claim_level' => 'claim_blocked_until_real_reproducible_runs',
                'fairness_notes' => 'External DeepSWE/Harbor task. Solution reference is forbidden to the agent; score requires programmatic verifier and trajectory evidence.',
                'invalid_if' => [
                    'solution_reference_used_by_agent',
                    'verifier_missing_or_failed',
                    'trajectory_missing',
                    'patch_missing',
                    'replay_or_matrix_missing',
                ],
            ],
            $tasks,
        ));
    }

    /**
     * @param  array<string,mixed>  $deepSwePlan
     * @return array<string,mixed>
     */
    private function deepSweReplayManifest(array $deepSwePlan): array
    {
        $tasks = (array) ($deepSwePlan['tasks'] ?? []);
        $caseIds = array_values(array_map(
            static fn (array $task): string => 'deepswe:'.(string) ($task['task_id'] ?? basename((string) ($task['task_dir'] ?? 'task'))),
            $tasks,
        ));

        $deterministic = [
            'schema_version' => 'atlas.forge.rivals.deepswe_corpus_replay.v1',
            'case_source' => 'deepswe',
            'case_set' => 'deepswe',
            'source_manifest_hash' => $deepSwePlan['manifest_hash'] ?? null,
            'case_ids' => $caseIds,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'separated_from_external_rivals_certification' => true,
        ];
        $deterministic['plan_hash'] = hash('sha256', (string) json_encode($deterministic, JSON_UNESCAPED_SLASHES));

        return $deterministic + [
            'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'replay_contract' => 'DeepSWE replay exige mesmo manifest hash, verifier hash, ambiente e runner contract.',
        ];
    }
}
