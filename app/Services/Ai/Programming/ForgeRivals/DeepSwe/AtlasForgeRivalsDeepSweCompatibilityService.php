<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals\DeepSwe;

/**
 * Read-only DeepSWE/Harbor compatibility surface for Rivals.
 *
 * This service imports task manifests and plans Pier-compatible execution
 * without spawning agents, Pier, Docker or providers.
 */
final class AtlasForgeRivalsDeepSweCompatibilityService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.deepswe_compatibility.v1';

    public function __construct(
        private readonly AtlasForgeRivalsDeepSweTaskParserService $parser,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function inspect(array $input): array
    {
        $path = $this->inputPath($input);
        if ($path === null) {
            return $this->blocked(['deepswe_path_required:use_--deepswe-path_or_--input']);
        }

        if (! is_dir($path)) {
            return $this->blocked(['deepswe_path_not_found:'.$path], $path);
        }

        $taskDirs = $this->discoverTaskDirs($path);
        if ($taskDirs === []) {
            return $this->blocked(['deepswe_no_harbor_tasks_found:'.$path], $path);
        }

        $tasks = array_map(fn (string $dir): array => $this->parser->parseTask($dir), $taskDirs);
        $blockers = [];
        foreach ($tasks as $task) {
            foreach ((array) ($task['blockers'] ?? []) as $blocker) {
                $blockers[] = (string) ($task['task_id'] ?? basename((string) ($task['task_dir'] ?? 'task'))).':'.$blocker;
            }
        }

        $okTasks = array_values(array_filter($tasks, static fn (array $task): bool => ($task['status'] ?? '') === 'ok'));
        $manifest = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $blockers === [] ? 'ok' : 'blocked',
            'suite_id' => 'atlas-forge-rivals-deepswe-compatibility-v1',
            'case_source' => 'deepswe',
            'task_format' => 'harbor',
            'input_path' => $path,
            'task_count' => count($tasks),
            'valid_task_count' => count($okTasks),
            'blocked_task_count' => count($tasks) - count($okTasks),
            'blockers' => array_values(array_unique($blockers)),
            'tasks' => array_map(fn (array $task): array => $this->taskSummary($task), $tasks),
            'pier_plan' => $this->pierPlan($path, $input),
            'evidence_contract' => $this->evidenceContract(),
            'claim_policy' => $this->claimPolicy(),
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'separated_from_external_rivals_certification' => true,
            'canonical_phrase' => 'Rivals emits measured evidence; Atlas Decide decides model routing.',
            'next_command' => 'php artisan atlas:forge:rivals deepswe --deepswe-path='.$path.' --json',
            'note' => 'DeepSWE/Harbor readiness only. No Pier process, agent, provider, Docker or token spend was started.',
        ];

        $manifest['manifest_hash'] = hash('sha256', (string) json_encode([
            'schema_version' => self::SCHEMA_VERSION,
            'case_source' => 'deepswe',
            'task_hashes' => array_map(
                static fn (array $task): ?string => $task['manifest_hash'] ?? null,
                $tasks,
            ),
            'pier_plan' => $manifest['pier_plan'],
            'evidence_contract' => $manifest['evidence_contract'],
            'claim_policy' => $manifest['claim_policy'],
        ], JSON_UNESCAPED_SLASHES));

        return $manifest;
    }

    /**
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    private function blocked(array $blockers, ?string $path = null): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'blocked',
            'suite_id' => 'atlas-forge-rivals-deepswe-compatibility-v1',
            'case_source' => 'deepswe',
            'task_format' => 'harbor',
            'input_path' => $path,
            'task_count' => 0,
            'valid_task_count' => 0,
            'blocked_task_count' => 0,
            'blockers' => array_values(array_unique($blockers)),
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'separated_from_external_rivals_certification' => true,
            'next_command' => 'php artisan atlas:forge:rivals deepswe --deepswe-path=<deep-swe/tasks-or-task> --json',
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function inputPath(array $input): ?string
    {
        foreach (['deepswe_path', 'input', 'repo_root'] as $key) {
            $value = $input[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return rtrim(trim($value), DIRECTORY_SEPARATOR);
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function discoverTaskDirs(string $path): array
    {
        if (is_file($path.'/task.toml')) {
            return [$path];
        }

        $dirs = [];
        foreach (glob($path.'/*/task.toml') ?: [] as $taskToml) {
            $dirs[] = dirname($taskToml);
        }
        sort($dirs);

        return $dirs;
    }

    /**
     * @param  array<string,mixed>  $task
     * @return array<string,mixed>
     */
    private function taskSummary(array $task): array
    {
        return [
            'schema_version' => $task['schema_version'] ?? AtlasForgeRivalsDeepSweTaskParserService::SCHEMA_VERSION,
            'status' => $task['status'] ?? 'blocked',
            'task_id' => $task['task_id'] ?? null,
            'task_dir' => $task['task_dir'] ?? null,
            'language' => $task['language'] ?? null,
            'repository' => $task['repository'] ?? null,
            'base_commit' => $task['base_commit'] ?? null,
            'prebuilt_image' => $task['prebuilt_image'] ?? null,
            'artifact_hashes' => $task['artifact_hashes'] ?? [],
            'verifier' => $task['verifier'] ?? null,
            'solution_reference' => $task['solution_reference'] ?? [
                'present' => false,
                'forbidden_to_agent' => true,
                'content_exposed' => false,
            ],
            'agent_visible_inputs' => $task['agent_visible_inputs'] ?? null,
            'evidence_requirements' => $task['evidence_requirements'] ?? [],
            'claim_policy' => $task['claim_policy'] ?? $this->claimPolicy(),
            'manifest_hash' => $task['manifest_hash'] ?? null,
            'blockers' => $task['blockers'] ?? [],
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function pierPlan(string $path, array $input): array
    {
        $agent = trim((string) ($input['agent'] ?? 'mini-swe-agent'));
        $model = trim((string) ($input['model'] ?? ''));
        $nTasks = $input['n_tasks'] ?? null;
        $sampleSeed = $input['sample_seed'] ?? null;

        $command = ['pier', 'run', '-p', $path, '--agent', $agent];
        if ($model !== '') {
            $command[] = '--model';
            $command[] = $model;
        }
        if (is_numeric($nTasks)) {
            $command[] = '--n-tasks';
            $command[] = (string) (int) $nTasks;
        }
        if (is_numeric($sampleSeed)) {
            $command[] = '--sample-seed';
            $command[] = (string) (int) $sampleSeed;
        }

        return [
            'schema_version' => 'atlas.forge.rivals.deepswe_pier_plan.v1',
            'status' => 'plan_only',
            'command' => $command,
            'provider_called' => false,
            'pier_spawned' => false,
            'docker_spawned' => false,
            'requires_explicit_real_run_confirmations' => true,
            'required_confirmations_for_real_run' => [
                'runbook_reviewed',
                'provider_cost',
                'real_provider_call',
                'benchmark_data_handling_reviewed',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function evidenceContract(): array
    {
        return [
            'schema_version' => 'atlas.forge.rivals.deepswe_evidence_contract.v1',
            'required_before_score' => [
                'task_manifest',
                'task_manifest_hash',
                'instruction_hash',
                'verifier_hash',
                'environment_metadata',
                'trajectory',
                'patch',
                'stdout_stderr',
                'verifier_result',
                'replay_result',
                'matrix_lock',
            ],
            'solution_policy' => [
                'solution_reference_allowed_for_human_review' => true,
                'solution_content_forbidden_to_agent' => true,
                'solution_content_forbidden_in_rivals_json' => true,
            ],
            'score_policy' => [
                'verifier_failed_blocks_score' => true,
                'missing_trajectory_blocks_score' => true,
                'missing_patch_blocks_score' => true,
                'missing_replay_blocks_claim' => true,
                'missing_matrix_lock_blocks_claim' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function claimPolicy(): array
    {
        return [
            'ready_for_external_claim' => false,
            'external_claim_allowed' => false,
            'claim_level' => 'claim_blocked_until_real_reproducible_runs',
            'local_import_is_not_benchmark_claim' => true,
            'dry_run_is_not_benchmark_claim' => true,
            'requires_reproducible_environment' => true,
            'requires_programmatic_verifier_green' => true,
            'requires_trajectory_pack' => true,
            'requires_replay_green' => true,
            'requires_matrix_lock' => true,
            'requires_statistical_repeat_for_routing_confidence' => true,
            'external_rivals_certification_status' => 'blocked_requires_human_approval',
        ];
    }
}
