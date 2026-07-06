<?php

namespace App\Services\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Support\HashesKsortedPayloadCanonically;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;

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
    use HashesKsortedPayloadCanonically;
    use RecursivelyKsortsArrays;
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

    private const GENERIC_SUCCESS_PHRASES = [
        'done', 'success', 'completed', 'task complete', 'all good', 'ok', 'finished', 'works now', 'fixed',
    ];

    private const RUNNABLE_PROOF_INDICATORS = ['phpunit', 'artisan', 'vendor/bin', './vendor'];

    /**
     * Validates that a submitted work-product manifest declares a concrete,
     * verifiable work product — not just generic success text. All four
     * sections (changed_files, proof_commands, implementation_notes,
     * outcome_learning_payload) must be present and non-generic for the
     * manifest to be accepted.
     *
     * REJECTION RULES:
     *   changed_files is empty                                -> missing_changed_files
     *   proof_commands is empty, or none contain a runnable
     *     indicator (phpunit/artisan/vendor/bin)               -> missing_runnable_proof_command
     *   implementation_notes is empty or matches a generic
     *     success phrase verbatim (e.g. "done", "all good")    -> implementation_notes_too_generic
     *   outcome_learning_payload is empty                       -> missing_outcome_learning_payload
     *
     * Pure: no I/O, no side effects.
     *
     * @param  array<string, mixed>  $manifest  { changed_files?: list<string>,
     *   proof_commands?: list<string>, implementation_notes?: string,
     *   outcome_learning_payload?: array<string,mixed> }
     * @return array{valid: bool, missing_requirements: list<string>, rejected_reason: string|null}
     */
    public function validateManifest(array $manifest): array
    {
        $changedFiles = array_values(array_filter(array_map('strval', (array) ($manifest['changed_files'] ?? []))));
        $proofCommands = array_values(array_filter(array_map('strval', (array) ($manifest['proof_commands'] ?? []))));
        $implementationNotes = trim((string) ($manifest['implementation_notes'] ?? ''));
        $outcomeLearningPayload = (array) ($manifest['outcome_learning_payload'] ?? []);

        $missing = [];

        if ($changedFiles === []) {
            $missing[] = 'missing_changed_files';
        }

        $hasRunnableProof = false;
        foreach ($proofCommands as $command) {
            $lower = strtolower($command);
            foreach (self::RUNNABLE_PROOF_INDICATORS as $indicator) {
                if (str_contains($lower, $indicator)) {
                    $hasRunnableProof = true;
                    break 2;
                }
            }
        }
        if (! $hasRunnableProof) {
            $missing[] = 'missing_runnable_proof_command';
        }

        $isGenericNotes = $implementationNotes === ''
            || in_array(strtolower($implementationNotes), self::GENERIC_SUCCESS_PHRASES, true);
        if ($isGenericNotes) {
            $missing[] = 'implementation_notes_too_generic';
        }

        if ($outcomeLearningPayload === []) {
            $missing[] = 'missing_outcome_learning_payload';
        }

        return [
            'valid' => $missing === [],
            'missing_requirements' => $missing,
            'rejected_reason' => $missing[0] ?? null,
        ];
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

}
