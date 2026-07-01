<?php

namespace App\Services\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Maestro\PacketEvolution\AtlasMaestroPacketSchemaDeprecationGate;
use App\Services\Ai\SelfConstruction\Maestro\PacketEvolution\AtlasMaestroPacketSchemaVersioning;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use App\Services\Ai\SelfConstruction\Support\HashesKsortedPayloadCanonically;
use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;

/**
 * Builds a deterministic, dry-run task packet for a single agent inside the
 * Agent Control Plane Runtime Pilot Simulator. The packet captures every
 * field needed by future runtime dispatch (objective, scope, evidence,
 * lease, cost) without ever writing the ledger, claiming a packet, calling
 * a provider, dispatching work, or enabling self-programming.
 *
 * Read-only: never starts processes, never calls Codex CLI/app, never
 * spawns subprocesses, never invokes adapters, never dispatches work,
 * never spends tokens, never advances the next required slice, never
 * enables self-programming, never writes the ledger.
 */
final class AgentControlPlaneTaskPacketBuilder
{
    use RecursivelyKsortsArrays;
    use HashesKsortedPayloadCanonically;
    public const SCHEMA_VERSION = AtlasMaestroPacketSchemaVersioning::CANONICAL_V1;

    public const MODE = 'read_only_agent_control_plane_task_packet_builder';

    /**
     * Canonical Atlas-native ownership contract baked into every task packet.
     * Single source of truth — `build()` reads from here so future packets
     * never re-declare a parallel literal.
     *
     * @return array<string,mixed>
     */
    public static function defaultSimplicityContract(): array
    {
        return [
            'default_execution_topology' => 'shared_local_main_with_allowed_files',
            'default_worktree_or_sandbox' => false,
            'human_or_external_provider_dependency_allowed' => false,
            'operator_dependency_allowed' => false,
            'human_dependency_allowed' => false,
            'external_provider_dependency_allowed' => false,
            'final_runtime_owner' => 'atlas_native',
            'steady_state_runtime_owner' => 'atlas_server',
            'steady_state_requires_operator' => false,
            'steady_state_requires_human' => false,
            'steady_state_requires_external_provider' => false,
            'external_worker_role' => 'bootstrap_or_replaceable_muscle_only',
        ];
    }

    public const FORBIDDEN_AXES = [
        'app/Services/Ai/SelfImprovement/',
        'app/Services/Ai/Programming/',
        'app/Http/Controllers/AtlasCode',
        'routes/api.php',
        'atlas-desktop/',
        'forge/',
        'rivals/',
        'cartografia/',
        'voice/',
    ];

    private const RUNNABLE_PROOF_MARKERS = ['phpunit', 'artisan test', 'pytest', 'jest', 'rspec'];

    /**
     * Hard value contract (AC1/AC2) — opt-in via input['require_hard_value_contract'] = true.
     * The simulator's default dry-run packets stay backward compatible (AC3); only callers that
     * explicitly request the hard contract get the four new blocking checks below.
     */
    private const REQUIRE_HARD_VALUE_CONTRACT_KEY = 'require_hard_value_contract';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function build(array $input = []): array
    {
        $blockingReasons = [];
        $warnings = [];

        $objective = trim((string) ($input['objective'] ?? ''));
        if ($objective === '') {
            $blockingReasons[] = 'objective_missing';
        }

        $source = trim((string) ($input['source'] ?? 'operator_intake'));
        $operatorId = trim((string) ($input['operator_id'] ?? 'operator-unknown'));
        $parentRunId = trim((string) ($input['parent_run_id'] ?? ''));
        $continuationContext = (array) ($input['continuation_context'] ?? []);

        $rawScopeIn = (array) ($input['scope_in'] ?? []);
        $rawScopeOut = (array) ($input['scope_out'] ?? []);
        $rawAllowed = (array) ($input['allowed_files'] ?? []);
        $rawForbidden = (array) ($input['forbidden_files'] ?? []);
        $acceptance = array_values(array_filter(array_map(
            fn ($value): string => trim((string) $value),
            (array) ($input['acceptance_criteria'] ?? [])
        )));
        $requiredEvidence = array_values(array_filter(array_map(
            fn ($value): string => trim((string) $value),
            (array) ($input['required_evidence'] ?? [])
        )));

        $scopeIn = $this->normalizePaths($rawScopeIn);
        $scopeOut = $this->normalizePaths($rawScopeOut);
        $allowed = $this->normalizePaths($rawAllowed);
        $forbidden = $this->normalizePaths($rawForbidden);

        if ($scopeIn === [] && $allowed === []) {
            $blockingReasons[] = 'scope_empty';
        }

        $forbiddenInAllowed = WriteSetOverlap::collidingPaths($allowed, $forbidden); // A5/MF-12: prefix-aware dir-vs-file
        if ($forbiddenInAllowed !== []) {
            $blockingReasons[] = 'forbidden_files_inside_allowed_files';
        }

        // Reviewed-axis exception: a lead-reviewed spec entering through the autonomous-gov-
        // bootstrap source (stamped ONLY by AtlasTaskSeedGovLanesCommand) may name exact
        // FORBIDDEN_AXES prefixes to exempt. Any other source ignores this input entirely — the
        // autonomous originator, replenisher and brain stay byte-identically fenced. An entry
        // that is not an exact known axis prefix is silently ignored (fail-closed).
        $reviewedAxisExceptions = $source === 'autonomous-gov-bootstrap'
            ? array_values(array_intersect(
                array_map('strval', (array) ($input['reviewed_axis_exceptions'] ?? [])),
                self::FORBIDDEN_AXES,
            ))
            : [];

        $axisHits = [];
        $axisExceptionsGrantedFor = [];
        foreach ($allowed as $path) {
            foreach (self::FORBIDDEN_AXES as $axis) {
                if (! str_starts_with($path, $axis)) {
                    continue;
                }
                if (in_array($axis, $reviewedAxisExceptions, true)) {
                    $axisExceptionsGrantedFor[] = $axis;

                    continue;
                }
                $axisHits[] = ['path' => $path, 'forbidden_axis' => $axis];
            }
        }
        if ($axisHits !== []) {
            $blockingReasons[] = 'forbidden_axis_in_allowed_files';
        }
        $axisExceptionsGrantedFor = array_values(array_unique($axisExceptionsGrantedFor));
        sort($axisExceptionsGrantedFor);

        $requireHardValueContract = (bool) ($input[self::REQUIRE_HARD_VALUE_CONTRACT_KEY] ?? false);
        if ($requireHardValueContract) {
            $blockingReasons = array_merge($blockingReasons, $this->hardValueContractBlockingReasons(
                $allowed,
                $acceptance,
                $requiredEvidence,
                $input,
            ));
        }

        $riskLevel = strtolower(trim((string) ($input['risk_level'] ?? 'low')));
        if (! in_array($riskLevel, ['low', 'medium', 'high', 'critical'], true)) {
            $warnings[] = 'risk_level_unknown_defaulting_low';
            $riskLevel = 'low';
        }

        $maxRuntime = max(0, (int) ($input['max_runtime_seconds'] ?? 3600));
        $maxTokenBudget = max(0, (int) ($input['max_token_budget'] ?? 0));
        $workspacePolicy = (array) ($input['workspace_policy'] ?? []);
        $workspacePolicy = $this->normalizeWorkspacePolicy($workspacePolicy);

        $packetId = (string) ($input['task_packet_id'] ?? Str::uuid()->toString());

        $normalizedScope = [
            'scope_in' => $scopeIn,
            'scope_out' => $scopeOut,
            'allowed_files' => $allowed,
            'forbidden_files' => $forbidden,
            'forbidden_axes' => self::FORBIDDEN_AXES,
            'forbidden_in_allowed' => $forbiddenInAllowed,
            'forbidden_axis_hits' => $axisHits,
        ];

        $evidenceRequirements = [
            'required' => $requiredEvidence === [] ? [
                'task_packet_created',
                'claim_lease_simulated',
                'scope_lock_planned',
                'continuation_summary_planned',
                'cost_import_planned',
                'work_product_manifest_planned',
                'runtime_pilot_completed',
            ] : $requiredEvidence,
            'persist_allowed' => false,
            'persist_runtime_enabled' => false,
        ];

        $leaseRequirements = [
            'lease_required' => true,
            'lease_ttl_seconds' => (int) ($input['lease_ttl_seconds'] ?? 1800),
            'renewable' => true,
            'simulated_only' => true,
            'persistence_allowed' => false,
        ];

        $claimRequirements = [
            'claim_required' => true,
            'claim_persistence_allowed' => false,
            'simulated_only' => true,
        ];

        $rollbackRequirements = [
            'rollback_strategy' => (string) ($input['rollback_strategy'] ?? 'plan_only'),
            'rollback_persistence_allowed' => false,
            'rollback_runtime_enabled' => false,
        ];

        $killSwitchRequirements = [
            'kill_switch_required' => true,
            'kill_switch_arming_runtime_enabled' => false,
            'kill_switch_simulated_only' => true,
        ];

        $continuationRequirements = [
            'continuation_required' => true,
            'continuation_context_keys' => array_keys($continuationContext),
            'context_compaction_required' => true,
            'continuation_runtime_enabled' => false,
        ];

        $costBudgetRequirements = [
            'max_token_budget' => $maxTokenBudget,
            'max_runtime_seconds' => $maxRuntime,
            'token_spend_allowed' => false,
            'budget_runtime_enabled' => false,
        ];

        $status = $blockingReasons === [] ? 'planned' : 'blocked';

        $scopeHash = $this->stableHash($normalizedScope);
        $acceptanceHash = $this->stableHash([
            'acceptance' => $acceptance,
            'evidence' => $evidenceRequirements['required'],
        ]);

        $packet = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'task_packet_id' => $packetId,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'status' => $status,
            'objective' => $objective,
            'source' => $source,
            'operator_id' => $operatorId,
            'parent_run_id' => $parentRunId,
            'normalized_scope' => $normalizedScope,
            'axis_exception_granted' => $axisExceptionsGrantedFor !== [] ? [
                'axes' => $axisExceptionsGrantedFor,
                'source' => $source,
            ] : null,
            'scope_hash' => $scopeHash,
            'acceptance_criteria' => $acceptance,
            'acceptance_hash' => $acceptanceHash,
            'evidence_requirements' => $evidenceRequirements,
            'risk_classification' => [
                'risk_level' => $riskLevel,
                'risk_axis_hits' => count($axisHits),
                'risk_forbidden_overlaps' => count($forbiddenInAllowed),
            ],
            'workspace_policy' => array_merge([
                'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
                'isolation' => 'shared_local_main_with_scope_lock',
                'auto_apply' => false,
            ], $workspacePolicy),
            'simplicity_contract' => self::defaultSimplicityContract(),
            'lease_requirements' => $leaseRequirements,
            'claim_requirements' => $claimRequirements,
            'rollback_requirements' => $rollbackRequirements,
            'kill_switch_requirements' => $killSwitchRequirements,
            'continuation_requirements' => $continuationRequirements,
            'cost_budget_requirements' => $costBudgetRequirements,
            'continuation_context' => $continuationContext,
            'dedup_target' => (string) ($input['dedup_target'] ?? $scopeHash),
            'expected_structural_leverage' => (float) ($input['expected_structural_leverage'] ?? 0.0),
            'blocking_reasons' => $blockingReasons,
            'warnings' => $warnings,
            'read_only' => true,
            'runtime_disabled' => true,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'completion_claim_allowed' => false,
            'non_execution_guarantees' => [
                'task_packet_builder_does_not_start_codex',
                'task_packet_builder_does_not_call_codex_cli_or_app',
                'task_packet_builder_does_not_spawn_subprocess',
                'task_packet_builder_does_not_invoke_adapter',
                'task_packet_builder_does_not_call_provider',
                'task_packet_builder_does_not_dispatch_work',
                'task_packet_builder_does_not_spend_tokens',
                'task_packet_builder_does_not_enable_self_programming',
                'task_packet_builder_does_not_write_ledger',
                'task_packet_builder_does_not_mutate_pointer',
                'task_packet_builder_does_not_promote_completion_claim',
            ],
            'human_summary' => $status === 'planned'
                ? sprintf('Task packet %s planned for objective "%s" (%d allowed paths).', $packetId, Str::limit($objective, 80), count($allowed))
                : sprintf('Task packet %s blocked: %s.', $packetId, implode(', ', $blockingReasons)),
        ];

        $packet['task_packet_hash'] = $this->stableHash($this->normalizeForHash($packet));

        AtlasMaestroPacketSchemaDeprecationGate::assertServeable($packet);

        return $packet;
    }

    /**
     * AC1: hard value contract blocking reasons — only evaluated when
     * require_hard_value_contract = true was explicitly requested.
     *
     * @param  list<string>  $allowed
     * @param  list<string>  $acceptance
     * @param  list<string>  $requiredEvidence
     * @param  array<string,mixed>  $input
     * @return list<string>
     */
    private function hardValueContractBlockingReasons(
        array $allowed,
        array $acceptance,
        array $requiredEvidence,
        array $input,
    ): array {
        $reasons = [];

        $isTestPath = static fn (string $path): bool => str_contains(strtolower($path), '/tests/')
            || str_ends_with(strtolower($path), 'test.php');

        $hasImplementationFile = false;
        $hasTestFile = false;
        foreach ($allowed as $path) {
            if ($isTestPath($path)) {
                $hasTestFile = true;
            } else {
                $hasImplementationFile = true;
            }
        }

        if (! $hasImplementationFile) {
            $reasons[] = 'missing_implementation_file';
        }

        $hasRunnableProofMention = false;
        foreach (array_merge($acceptance, $requiredEvidence) as $text) {
            foreach (self::RUNNABLE_PROOF_MARKERS as $marker) {
                if (str_contains(strtolower($text), $marker)) {
                    $hasRunnableProofMention = true;
                    break 2;
                }
            }
        }
        if (! $hasTestFile && ! $hasRunnableProofMention) {
            $reasons[] = 'missing_runnable_test_file';
        }

        if ($requiredEvidence === []) {
            $reasons[] = 'missing_required_evidence';
        }

        $structuralValueRationale = trim((string) ($input['structural_value_rationale'] ?? ''));
        if ($structuralValueRationale === '') {
            $reasons[] = 'missing_structural_value_rationale';
        }

        return $reasons;
    }

    /**
     * @param  array<int|string, mixed>  $paths
     * @return list<string>
     */
    private function normalizePaths(array $paths): array
    {
        $normalized = [];
        foreach ($paths as $path) {
            $value = trim((string) $path);
            if ($value === '') {
                continue;
            }
            $value = str_replace('\\', '/', $value);
            $value = preg_replace('#/{2,}#', '/', $value) ?? $value;
            $value = ltrim($value, '/');
            $normalized[] = $value;
        }
        $normalized = array_values(array_unique($normalized));
        sort($normalized);

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $packet
     * @return array<string, mixed>
     */
    private function normalizeForHash(array $packet): array
    {
        $clone = $packet;
        unset($clone['task_packet_id'], $clone['generated_at'], $clone['task_packet_hash'], $clone['human_summary']);

        return $this->recursivelyKsort($clone);
    }

    /**
     * @param  array<string,mixed>  $workspacePolicy
     * @return array<string,mixed>
     */
    private function normalizeWorkspacePolicy(array $workspacePolicy): array
    {
        $isolation = strtolower(trim((string) ($workspacePolicy['isolation'] ?? '')));
        $exceptional = (bool) ($workspacePolicy['exceptional_isolation_required'] ?? false);
        $legacyOrDefaultWorktree = in_array($isolation, [
            'simulated_worktree',
            'simulated_worktree_per_packet',
            'isolated',
            'isolated_worktree',
            'isolated_worktree_required',
            'worktree',
            'worktree_required',
            'sandbox',
            'sandbox_required',
        ], true);

        if ($legacyOrDefaultWorktree && ! $exceptional) {
            $workspacePolicy['isolation'] = 'shared_local_main_with_scope_lock';
            $workspacePolicy['legacy_isolation_normalized_from'] = $isolation;
        }

        return $workspacePolicy;
    }


}
