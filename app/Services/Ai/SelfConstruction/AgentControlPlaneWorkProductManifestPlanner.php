<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Plans the work-product manifest a runtime pilot WOULD collect, including
 * expected outputs, output paths, validation commands, proof requirements
 * and an artifact-hash plan. Automatic collection at runtime is disabled.
 *
 * Read-only: never starts processes, never calls Codex CLI/app, never
 * spawns subprocesses, never invokes adapters, never dispatches work,
 * never spends tokens, never advances the next required slice, never
 * enables self-programming, never writes the ledger.
 */
final class AgentControlPlaneWorkProductManifestPlanner
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_work_product_manifest_plan.v1';

    public const MODE = 'read_only_agent_control_plane_work_product_manifest_plan';

    /**
     * @param  array<string, mixed>  $taskPacket
     * @param  array<string, mixed>  $scopeLock
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function plan(array $taskPacket, array $scopeLock = [], array $options = []): array
    {
        $packetId = (string) ($taskPacket['task_packet_id'] ?? 'task-packet-unknown');
        $writeSet = (array) ($scopeLock['write_set'] ?? data_get($taskPacket, 'normalized_scope.allowed_files', []));

        $expectedOutputs = (array) ($options['expected_outputs'] ?? []);
        if ($expectedOutputs === []) {
            foreach ($writeSet as $path) {
                $expectedOutputs[] = [
                    'path' => (string) $path,
                    'kind' => $this->classifyKind((string) $path),
                    'optional' => false,
                ];
            }
        }

        $outputPaths = [];
        foreach ($expectedOutputs as $output) {
            $outputPaths[] = (string) ($output['path'] ?? '');
        }
        $outputPaths = array_values(array_unique(array_filter($outputPaths)));
        sort($outputPaths);

        $validationCommands = (array) ($options['validation_commands'] ?? [
            'php -l app/Services/Ai/SelfConstruction',
            './vendor/bin/pint --test app/Services/Ai/SelfConstruction',
            'php artisan test --filter=AgentControlPlaneRuntimePilot',
        ]);

        $proofRequirements = (array) ($options['proof_requirements'] ?? [
            'php_syntax_check_clean',
            'pint_format_clean',
            'test_suite_green',
            'evidence_dry_run_present',
            'continuation_summary_present',
        ]);

        $artifactHashPlan = [];
        foreach ($outputPaths as $path) {
            $artifactHashPlan[] = [
                'path' => $path,
                'hash_algorithm' => 'sha256',
                'hash_collection_runtime_enabled' => false,
                'placeholder_hash' => hash('sha256', $path),
            ];
        }

        $blockingReasons = [];
        if ((string) ($taskPacket['status'] ?? 'unknown') !== 'planned') {
            $blockingReasons[] = 'task_packet_not_planned';
        }
        if ($writeSet === []) {
            $blockingReasons[] = 'write_set_empty';
        }

        $status = $blockingReasons === [] ? 'planned' : 'planned_blocked';

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'manifest_plan_id' => (string) Str::uuid(),
            'task_packet_id' => $packetId,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'status' => $status,
            'expected_outputs' => $expectedOutputs,
            'expected_output_count' => count($expectedOutputs),
            'output_paths' => $outputPaths,
            'validation_commands' => $validationCommands,
            'proof_requirements' => $proofRequirements,
            'artifact_hash_plan' => $artifactHashPlan,
            'collection_allowed' => false,
            'automatic_collection_runtime_enabled' => false,
            'blocking_reasons' => $blockingReasons,
            'read_only' => true,
            'runtime_disabled' => true,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'persistence_allowed' => false,
            'non_execution_guarantees' => [
                'work_product_manifest_planner_does_not_start_codex',
                'work_product_manifest_planner_does_not_call_codex_cli_or_app',
                'work_product_manifest_planner_does_not_spawn_subprocess',
                'work_product_manifest_planner_does_not_invoke_adapter',
                'work_product_manifest_planner_does_not_call_provider',
                'work_product_manifest_planner_does_not_dispatch_work',
                'work_product_manifest_planner_does_not_spend_tokens',
                'work_product_manifest_planner_does_not_enable_self_programming',
                'work_product_manifest_planner_does_not_write_ledger',
                'work_product_manifest_planner_does_not_collect_artifacts',
                'work_product_manifest_planner_does_not_mutate_pointer',
            ],
            'human_summary' => sprintf(
                'Work product manifest planned for packet %s (%d outputs, %d validation commands).',
                $packetId,
                count($expectedOutputs),
                count($validationCommands),
            ),
        ];

        $payload['work_product_manifest_hash'] = $this->stableHash($this->normalizeForHash($payload));

        return $payload;
    }

    private function classifyKind(string $path): string
    {
        $lower = strtolower($path);
        if (str_ends_with($lower, '.php')) {
            return 'php_source';
        }
        if (str_ends_with($lower, '.md')) {
            return 'markdown_doc';
        }
        if (str_ends_with($lower, '.json')) {
            return 'json_artifact';
        }
        if (str_contains($lower, 'tests/')) {
            return 'test_file';
        }
        if (str_ends_with($lower, '.tsx') || str_ends_with($lower, '.ts')) {
            return 'typescript_source';
        }

        return 'unknown';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeForHash(array $payload): array
    {
        $clone = $payload;
        unset($clone['manifest_plan_id'], $clone['generated_at'], $clone['work_product_manifest_hash'], $clone['human_summary'], $clone['task_packet_id']);

        return $this->recursivelyKsort($clone);
    }

    /**
     * @param  array<mixed, mixed>  $value
     * @return array<mixed, mixed>
     */
    private function recursivelyKsort(array $value): array
    {
        $isAssoc = $value !== [] && array_keys($value) !== range(0, count($value) - 1);
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = $this->recursivelyKsort($entry);
            }
        }
        if ($isAssoc) {
            ksort($value);
        }

        return $value;
    }

    /**
     * @param  array<mixed, mixed>  $payload
     */
    private function stableHash(array $payload): string
    {
        $payload = $this->recursivelyKsort($payload);

        return hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
