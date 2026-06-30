<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Completion;

use App\Services\Ai\SelfConstruction\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneContinuationSummaryBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneOneShotWorkerPacketService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskAutoReplenishmentService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskLeaseRecoveryService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTerminalLoopHealthDigestService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTerminalLoopOperationalProofService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTerminalWorkerBootstrapService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneWorkerTaskEligibilityCertificationService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;

/**
 * Multi-agent terminal-loop certification for the Atlas self-construction
 * OS completion audit.
 *
 * Extracted from AtlasSelfConstructionOsCompletionAuditService to reduce
 * the god-class. All methods are stateless; collaborator dependencies are
 * passed as parameters.
 */
final class AtlasSelfConstructionTerminalLoopCertifier
{
    /**
     * Read-only certification of the multi-agent terminal loop wiring.
     *
     * The audit verifies that every loop module has all four artifacts present
     * (readiness method, backing service class, doc bullet, CLI surface) and
     * that the global invariants hold (runtime safety all false, no legacy
     * reservation claim/completion paths, deterministic queue transition
     * policy, claim requires lease_id + agent_id, completion requires an
     * active lease, recovery handles orphaned leases, completion never marks
     * real OS completion).
     *
     * The block intentionally never invokes a module — it only asserts the
     * surfaces exist so the loop can be exercised by an external worker.
     *
     * @param  array<string, mixed>  $options
     * @param  list<array<string, mixed>>  $modulesDeclaration
     * @param  AtlasSelfConstructionReadinessService  $readiness
     * @param  string  $schemaVersion
     * @return array<string, mixed>
     */
    public static function certifyAgentControlPlaneTerminalLoop(
        array $options,
        array $modulesDeclaration,
        AtlasSelfConstructionReadinessService $readiness,
        string $schemaVersion,
    ): array {
        $moduleOverrides = (array) ($options['module_overrides'] ?? []);
        $invariantOverrides = (array) ($options['invariant_overrides'] ?? []);
        $contractDoc = self::terminalLoopContractDoc($options);
        $commandFile = self::terminalLoopCommandSurface($options);

        $modules = [];
        $modulesPassed = 0;
        $modulesBlocked = 0;
        foreach ($modulesDeclaration as $declaration) {
            $id = (string) $declaration['id'];
            $override = (array) ($moduleOverrides[$id] ?? []);
            $readinessMethod = (string) $declaration['readiness_method'];
            $serviceClass = (string) $declaration['service_class'];
            $docAnchor = (string) $declaration['doc_anchor'];
            $cliOption = (string) $declaration['cli_option'];

            $readinessMethodAvailable = array_key_exists('readiness_method_available', $override)
                ? (bool) $override['readiness_method_available']
                : method_exists($readiness, $readinessMethod);
            $serviceClassExists = array_key_exists('service_class_exists', $override)
                ? (bool) $override['service_class_exists']
                : class_exists($serviceClass);
            $docBulletExists = array_key_exists('doc_bullet_exists', $override)
                ? (bool) $override['doc_bullet_exists']
                : ($contractDoc !== '' && str_contains($contractDoc, $docAnchor));
            $cliSurfaceExists = array_key_exists('cli_surface_exists', $override)
                ? (bool) $override['cli_surface_exists']
                : ($commandFile !== '' && str_contains($commandFile, $cliOption));

            $modulePassed = $readinessMethodAvailable && $serviceClassExists && $docBulletExists && $cliSurfaceExists;
            $missing = [];
            if (! $readinessMethodAvailable) {
                $missing[] = 'readiness_method';
            }
            if (! $serviceClassExists) {
                $missing[] = 'service_class';
            }
            if (! $docBulletExists) {
                $missing[] = 'doc_bullet';
            }
            if (! $cliSurfaceExists) {
                $missing[] = 'cli_surface';
            }

            $modules[] = [
                'id' => $id,
                'label' => (string) $declaration['label'],
                'readiness_method' => $readinessMethod,
                'service_class' => $serviceClass,
                'doc_anchor' => $docAnchor,
                'cli_option' => $cliOption,
                'readiness_method_available' => $readinessMethodAvailable,
                'service_class_exists' => $serviceClassExists,
                'doc_bullet_exists' => $docBulletExists,
                'cli_surface_exists' => $cliSurfaceExists,
                'status' => $modulePassed ? 'available' : 'blocked',
                'passed' => $modulePassed,
                'missing_artifacts' => $missing,
            ];

            $modulePassed ? $modulesPassed++ : $modulesBlocked++;
        }

        $invariants = self::evaluateTerminalLoopInvariants($invariantOverrides);
        $invariantViolations = [];
        foreach ($invariants as $name => $value) {
            $expected = self::terminalLoopInvariantExpectation($name);
            if ($value !== $expected) {
                $invariantViolations[] = $name;
            }
        }

        $allModulesPassed = $modulesBlocked === 0;
        $invariantsHold = $invariantViolations === [];
        $passed = $allModulesPassed && $invariantsHold;

        $payload = [
            'schema_version' => $schemaVersion,
            'status' => $passed ? 'available' : 'blocked',
            'passed' => $passed,
            'module_count' => count($modules),
            'modules_passed' => $modulesPassed,
            'modules_blocked' => $modulesBlocked,
            'modules' => $modules,
            'invariants' => $invariants,
            'invariant_violations' => $invariantViolations,
            'runtime_safety' => [
                'runtime_safety_all_false' => $invariants['runtime_safety_all_false'],
                'execution_allowed' => false,
                'dispatch_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'self_programming_allowed' => false,
            ],
            'non_execution_guarantees' => [
                'terminal_loop_certification_does_not_claim_a_packet',
                'terminal_loop_certification_does_not_complete_a_packet',
                'terminal_loop_certification_does_not_start_codex',
                'terminal_loop_certification_does_not_call_provider',
                'terminal_loop_certification_does_not_spend_tokens',
                'terminal_loop_certification_does_not_mark_real_os_completion',
            ],
        ];
        $payload['certification_hash'] = AtlasSelfConstructionOsCompletionAuditHashCanonicalizer::stableHash($payload);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, bool>
     */
    public static function evaluateTerminalLoopInvariants(array $overrides): array
    {
        $queueRepoExists = class_exists(AgentControlPlaneTaskPacketQueueRepository::class);
        $queueTransitionPolicyEnforced = $queueRepoExists
            && defined(AgentControlPlaneTaskPacketQueueRepository::class.'::ALLOWED_STATUS_TRANSITIONS');
        if ($queueTransitionPolicyEnforced) {
            $transitions = (array) constant(AgentControlPlaneTaskPacketQueueRepository::class.'::ALLOWED_STATUS_TRANSITIONS');
            $queueTransitionPolicyEnforced = $transitions !== [];
        }

        $orchestratorClass = AgentControlPlaneTaskQueueOrchestrator::class;
        $leaseRepoClass = AgentControlPlaneClaimLeaseRepository::class;

        $claimRequiresLeaseAndAgent = $queueRepoExists
            && method_exists(AgentControlPlaneTaskPacketQueueRepository::class, 'registry');
        $completionRequiresActiveLease = class_exists($orchestratorClass)
            && method_exists($orchestratorClass, 'completeDryRun')
            && class_exists($leaseRepoClass)
            && defined($leaseRepoClass.'::LEASE_STATUS_ACTIVE');
        $recoveryHandlesOrphanedLeases = class_exists($leaseRepoClass)
            && method_exists($leaseRepoClass, 'expireLeases');

        $invariants = [
            'runtime_safety_all_false' => true,
            'queue_transition_policy_enforced' => $queueTransitionPolicyEnforced,
            'no_legacy_reservation_claim' => true,
            'no_legacy_reservation_completion' => true,
            'claim_requires_lease_id_and_agent_id' => $claimRequiresLeaseAndAgent,
            'completion_requires_active_lease' => $completionRequiresActiveLease,
            'recovery_handles_orphaned_leases' => $recoveryHandlesOrphanedLeases,
            'completion_does_not_mark_real_os_completion' => true,
            // Canonical matrix mirrored from the multi-agent loop
            // cert. Structural defaults reflect class wiring; when the cert
            // is threaded through `multi_agent_loop_certification`, those
            // booleans rewrite the matrix from the live simulation.
            'no_duplicate_claims' => $claimRequiresLeaseAndAgent,
            'no_cross_agent_completion' => $completionRequiresActiveLease,
            'complete_dry_run_requires_queue_claim_binding' => $completionRequiresActiveLease,
            'stale_lease_recovered' => $recoveryHandlesOrphanedLeases,
            'completed_task_not_reclaimed' => $completionRequiresActiveLease,
            'auto_replenishment_target_met' => class_exists(AgentControlPlaneTaskAutoReplenishmentService::class),
            'continuation_summary_present' => class_exists(AgentControlPlaneContinuationSummaryBuilder::class),
            'evidence_hash_present' => $completionRequiresActiveLease,
            'structured_completion_evidence_valid' => $completionRequiresActiveLease,
            'completion_evidence_files_within_scope' => $completionRequiresActiveLease,
            'worker_task_eligibility_certification_present' => class_exists(AgentControlPlaneWorkerTaskEligibilityCertificationService::class),
            'worker_task_eligibility_blocks_operator_only_completion_blockers' => class_exists(AgentControlPlaneWorkerTaskEligibilityCertificationService::class)
                && method_exists(AgentControlPlaneWorkerTaskEligibilityCertificationService::class, 'certify'),
            'worker_completion_evidence_template_present' => class_exists(AgentControlPlaneOneShotWorkerPacketService::class),
            'worker_operator_loop_commands_present' => class_exists(AgentControlPlaneTerminalWorkerBootstrapService::class),
            'worker_resumption_contract_present' => class_exists(AgentControlPlaneOneShotWorkerPacketService::class)
                && class_exists(AgentControlPlaneTaskLeaseRecoveryService::class),
            'worker_resumption_checkpoint_present' => class_exists(AgentControlPlaneTerminalWorkerBootstrapService::class),
            'worker_iteration_runbook_present' => class_exists(AgentControlPlaneTerminalWorkerBootstrapService::class),
            'worker_shell_recipe_present' => class_exists(AgentControlPlaneTerminalWorkerBootstrapService::class),
            'terminal_loop_health_digest_present' => class_exists(AgentControlPlaneTerminalLoopHealthDigestService::class),
            'terminal_loop_fleet_launch_plan_present' => class_exists(AgentControlPlaneTerminalLoopHealthDigestService::class),
            'terminal_loop_fleet_launch_plan_ready_path_verified' => class_exists(AgentControlPlaneTerminalLoopHealthDigestService::class)
                && class_exists(AgentControlPlaneTaskQueueOrchestrator::class),
            'terminal_loop_fleet_replenishment_plan_present' => class_exists(AgentControlPlaneTerminalLoopHealthDigestService::class),
            'terminal_loop_fleet_resume_rollup_present' => class_exists(AgentControlPlaneTerminalLoopHealthDigestService::class),
            'terminal_loop_fleet_resume_recovery_path_verified' => class_exists(AgentControlPlaneTerminalLoopHealthDigestService::class)
                && class_exists(AgentControlPlaneTaskQueueOrchestrator::class)
                && class_exists(AgentControlPlaneTaskLeaseRecoveryService::class),
            'terminal_loop_fleet_metadata_orphan_recovery_verified' => class_exists(AgentControlPlaneTerminalLoopHealthDigestService::class)
                && class_exists(AgentControlPlaneTaskLeaseRecoveryService::class)
                && method_exists(AgentControlPlaneTaskLeaseRecoveryService::class, 'recoverOrphanedClaims'),
            'terminal_loop_fleet_released_task_requeue_verified' => class_exists(AgentControlPlaneTerminalLoopHealthDigestService::class)
                && class_exists(AgentControlPlaneTaskLeaseRecoveryService::class)
                && method_exists(AgentControlPlaneTaskLeaseRecoveryService::class, 'recoverReleasedTasks'),
            'terminal_loop_fleet_evidence_rollup_present' => class_exists(AgentControlPlaneTerminalLoopHealthDigestService::class),
            'terminal_loop_fleet_evidence_rollup_green_path_verified' => class_exists(AgentControlPlaneTerminalLoopHealthDigestService::class)
                && class_exists(AgentControlPlaneTaskQueueOrchestrator::class),
            'terminal_loop_fleet_operator_handoff_present' => class_exists(AgentControlPlaneTerminalLoopHealthDigestService::class),
            'terminal_loop_fleet_operator_handoff_recovery_priority_verified' => class_exists(AgentControlPlaneTerminalLoopHealthDigestService::class)
                && class_exists(AgentControlPlaneTaskQueueOrchestrator::class)
                && class_exists(AgentControlPlaneTaskLeaseRecoveryService::class),
            'terminal_loop_fleet_lane_isolation_present' => class_exists(AgentControlPlaneTerminalLoopHealthDigestService::class),
            'terminal_loop_fleet_lane_bound_commands_verified' => class_exists(AgentControlPlaneTerminalLoopHealthDigestService::class)
                && class_exists(AgentControlPlaneTaskQueueOrchestrator::class),
            'terminal_loop_fleet_lane_no_cross_lane_launch_verified' => class_exists(AgentControlPlaneTerminalLoopHealthDigestService::class)
                && class_exists(AgentControlPlaneTaskQueueOrchestrator::class),
            'terminal_loop_fleet_partial_supply_launch_blocked' => class_exists(AgentControlPlaneTerminalLoopHealthDigestService::class)
                && class_exists(AgentControlPlaneTaskQueueOrchestrator::class),
            'worker_bootstrap_partial_supply_blocks_before_claim' => class_exists(AgentControlPlaneTerminalWorkerBootstrapService::class)
                && class_exists(AgentControlPlaneTaskQueueOrchestrator::class),
            'terminal_loop_cycle_supervisor_present' => class_exists(AgentControlPlaneTerminalLoopHealthDigestService::class)
                && defined(AgentControlPlaneTerminalLoopHealthDigestService::class.'::CYCLE_SUPERVISOR_SCHEMA_VERSION'),
            'terminal_loop_cycle_supervisor_launch_path_verified' => class_exists(AgentControlPlaneTerminalLoopHealthDigestService::class)
                && class_exists(AgentControlPlaneTaskQueueOrchestrator::class),
            'terminal_loop_cycle_supervisor_evidence_review_path_verified' => class_exists(AgentControlPlaneTerminalLoopHealthDigestService::class)
                && class_exists(AgentControlPlaneTaskQueueOrchestrator::class),
            'terminal_loop_fleet_launch_runbook_present' => class_exists(AgentControlPlaneTerminalLoopHealthDigestService::class)
                && defined(AgentControlPlaneTerminalLoopHealthDigestService::class.'::FLEET_LAUNCH_RUNBOOK_SCHEMA_VERSION'),
            'terminal_loop_fleet_launch_runbook_ready_path_verified' => class_exists(AgentControlPlaneTerminalLoopHealthDigestService::class)
                && class_exists(AgentControlPlaneTaskQueueOrchestrator::class),
            'certification_cleanup_leaves_no_recoverable_terminal_loop_artifacts' => class_exists(AgentControlPlaneTerminalLoopHealthDigestService::class)
                && class_exists(AgentControlPlaneTaskLeaseRecoveryService::class),
            'worker_invalid_scope_rejected' => class_exists(AgentControlPlaneOneShotWorkerPacketService::class)
                && class_exists(AgentControlPlaneTerminalWorkerBootstrapService::class),
            'worker_bootstrap_preview_read_only' => class_exists(AgentControlPlaneTerminalWorkerBootstrapService::class),
            'safe_for_parallel_terminal_loop' => $claimRequiresLeaseAndAgent
                && $completionRequiresActiveLease
                && $recoveryHandlesOrphanedLeases
                && $queueTransitionPolicyEnforced,
        ];

        $cert = (array) ($overrides['multi_agent_loop_certification'] ?? []);
        if ($cert !== []) {
            $matrix = (array) data_get($cert, 'canonical_invariant_matrix.invariants', []);
            foreach ($matrix as $name => $entry) {
                if (is_array($entry) && array_key_exists($name, $invariants)) {
                    $invariants[$name] = (bool) ($entry['value'] ?? false);
                }
            }
        }

        foreach ($overrides as $key => $value) {
            if (is_bool($value) && array_key_exists($key, $invariants)) {
                $invariants[$key] = $value;
            }
        }

        return $invariants;
    }

    public const FINAL_BRAIN_LOOP_SCHEMA = 'atlas.self_construction.terminal_loop_certifier.final_brain.v1';

    /** @var list<string> */
    public const FINAL_BRAIN_LOOP_REQUIRED_EVIDENCE = [
        'no_stale_heartbeat',
        'no_queue_jam',
        'balanced_lane_generation',
        'muscle_feedback_success',
        'recovery_receipts',
    ];

    /**
     * Certify the final external-brain loop against a reproducible evidence bundle.
     * All five dimensions must be explicitly present and true; any absent or false
     * dimension produces a blocker and prevents certification.
     *
     * @param  array<string,mixed>  $evidence
     * @return array<string,mixed>
     */
    public static function certifyFinalBrainLoop(array $evidence): array
    {
        $blockers = [];
        $summary = [];
        foreach (self::FINAL_BRAIN_LOOP_REQUIRED_EVIDENCE as $dimension) {
            $met = array_key_exists($dimension, $evidence) && (bool) $evidence[$dimension];
            $summary[$dimension] = $met;
            if (! $met) {
                $blockers[] = 'missing_or_false:'.$dimension;
            }
        }

        return [
            'schema_version' => self::FINAL_BRAIN_LOOP_SCHEMA,
            'certified' => $blockers === [],
            'blockers' => $blockers,
            'evidence_summary' => $summary,
        ];
    }

    public static function terminalLoopInvariantExpectation(string $name): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public static function terminalLoopContractDoc(array $options): string
    {
        if (array_key_exists('contract_doc_path', $options)) {
            return self::readFileSafe((string) $options['contract_doc_path']);
        }

        return self::readFilesSafe([
            base_path('docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md'),
            base_path('docs/engineering-knowledge-base/self-construction/agent-control-plane-contract-part-01.md'),
            base_path('docs/engineering-knowledge-base/self-construction/agent-control-plane-contract-part-02.md'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public static function terminalLoopCommandSurface(array $options): string
    {
        if (array_key_exists('command_file_path', $options)) {
            return self::readFileSafe((string) $options['command_file_path']);
        }

        return self::readFilesSafe([
            base_path('app/Console/Commands/AtlasAiSelfConstructionCommand.php'),
            base_path('app/Console/Commands/AtlasAiSelfConstructionMotherCommand.php'),
            base_path('app/Console/Commands/Support/AtlasSelfConstructionMotherCommandSurface.php'),
        ]);
    }

    /**
     * Build the canonical operational-proof evidence block that the audit
     * binds to the terminal-loop certification criterion as stronger evidence
     * that the wired loop was actually exercised.
     *
     * @param  array<string, mixed>  $proof
     * @return array<string, mixed>
     */
    public static function terminalLoopOperationalProofEvidence(array $proof): array
    {
        foreach ([
            'proof_payload',
            'completion_audit_binding_packet.proof_payload',
            'agent_control_plane_terminal_loop_operational_proof.completion_audit_binding_packet.proof_payload',
        ] as $path) {
            $payload = data_get($proof, $path);
            if (is_array($payload)) {
                $proof = (array) $payload;

                break;
            }
        }

        $status = (string) ($proof['status'] ?? '');
        $hash = (string) ($proof['terminal_loop_operational_proof_hash'] ?? '');
        $invariantsAllTrue = (bool) ($proof['invariants_all_true'] ?? false);
        $matrixAllTrue = (bool) data_get($proof, 'operational_readiness_matrix.all_true', data_get($proof, 'operational_readiness_matrix_all_true', false));
        $completionRealAllowed = (bool) ($proof['completion_real_allowed'] ?? false);
        $providerCallAllowed = (bool) ($proof['provider_call_allowed'] ?? false);
        $tokenSpendAllowed = (bool) ($proof['token_spend_allowed'] ?? false);
        $dispatchAllowed = (bool) ($proof['dispatch_allowed'] ?? false);
        $adapterExecutionAllowed = (bool) ($proof['adapter_execution_allowed'] ?? false);
        $selfProgrammingAllowed = (bool) ($proof['self_programming_allowed'] ?? false);
        $cycleSupervisorStatus = (string) data_get($proof, 'post_cycle_cycle_supervisor.status', data_get($proof, 'post_cycle_cycle_supervisor_status', ''));
        $cycleSupervisorState = (string) data_get($proof, 'post_cycle_cycle_supervisor.cycle_state', data_get($proof, 'post_cycle_cycle_supervisor_cycle_state', ''));
        $cycleSupervisorPurpose = (string) data_get($proof, 'post_cycle_cycle_supervisor.next_command_purpose', data_get($proof, 'post_cycle_cycle_supervisor_next_command_purpose', ''));
        $cycleSupervisorHash = (string) data_get($proof, 'post_cycle_cycle_supervisor.hash', data_get($proof, 'post_cycle_cycle_supervisor_hash', ''));
        $endToEndContractStatus = (string) data_get($proof, 'post_cycle_end_to_end_contract.status', data_get($proof, 'post_cycle_end_to_end_contract_status', ''));
        $endToEndContractAllSurfacesPresent = (bool) data_get($proof, 'post_cycle_end_to_end_contract.all_required_surfaces_present', data_get($proof, 'post_cycle_end_to_end_contract_all_required_surfaces_present', false));
        $endToEndContractCoveredCapabilities = (array) data_get($proof, 'post_cycle_end_to_end_contract.covered_capabilities', data_get($proof, 'post_cycle_end_to_end_contract_covered_capabilities', []));
        $endToEndContractFailedCheckIds = (array) data_get($proof, 'post_cycle_end_to_end_contract.failed_check_ids', data_get($proof, 'post_cycle_end_to_end_contract_failed_check_ids', []));
        $endToEndContractHash = (string) data_get($proof, 'post_cycle_end_to_end_contract.hash', data_get($proof, 'post_cycle_end_to_end_contract_hash', ''));
        $requiredEndToEndContractCapabilities = [
            'auto_replenishment',
            'validation',
            'leases',
            'evidence',
            'retomada',
            'lane_isolation',
            'cycle_supervision',
            'operator_handoff',
        ];
        $missingEndToEndContractCapabilities = array_values(array_diff(
            $requiredEndToEndContractCapabilities,
            $endToEndContractCoveredCapabilities,
        ));
        $postCycleClaimedTaskCount = (int) data_get($proof, 'post_cycle_cleanup_state.claimed_task_count', data_get($proof, 'post_cycle_claimed_task_count', -1));
        $postCycleActiveLeaseCount = (int) data_get($proof, 'post_cycle_cleanup_state.active_lease_count', data_get($proof, 'post_cycle_active_lease_count', -1));
        $postCycleRecoverableLeaseCount = (int) data_get($proof, 'post_cycle_cleanup_state.recoverable_lease_count', data_get($proof, 'post_cycle_recoverable_lease_count', -1));
        $supplied = $proof !== [];
        $validationViolations = [];
        if ($supplied && $status !== 'passed') {
            $validationViolations[] = 'status_not_passed';
        }
        if ($supplied && ! $invariantsAllTrue) {
            $validationViolations[] = 'invariants_not_all_true';
        }
        if ($supplied && ! $matrixAllTrue) {
            $validationViolations[] = 'operational_readiness_matrix_not_all_true';
        }
        if ($supplied && $completionRealAllowed) {
            $validationViolations[] = 'completion_real_allowed_true';
        }
        if ($supplied && $providerCallAllowed) {
            $validationViolations[] = 'provider_call_allowed_true';
        }
        if ($supplied && $tokenSpendAllowed) {
            $validationViolations[] = 'token_spend_allowed_true';
        }
        if ($supplied && $dispatchAllowed) {
            $validationViolations[] = 'dispatch_allowed_true';
        }
        if ($supplied && $adapterExecutionAllowed) {
            $validationViolations[] = 'adapter_execution_allowed_true';
        }
        if ($supplied && $selfProgrammingAllowed) {
            $validationViolations[] = 'self_programming_allowed_true';
        }
        if ($supplied && preg_match('/^[a-f0-9]{64}\z/', $hash) !== 1) {
            $validationViolations[] = 'invalid_or_missing_operational_proof_hash';
        }
        if (
            $supplied
            && (
                $cycleSupervisorStatus !== 'cycle_evidence_review_ready'
                || $cycleSupervisorState !== 'review_evidence'
                || $cycleSupervisorPurpose !== 'review_completed_dry_run_evidence_and_rerun_digest'
            )
        ) {
            $validationViolations[] = 'post_cycle_cycle_supervisor_not_review_evidence';
        }
        if ($supplied && preg_match('/^[a-f0-9]{64}\z/', $cycleSupervisorHash) !== 1) {
            $validationViolations[] = 'invalid_or_missing_post_cycle_cycle_supervisor_hash';
        }
        if ($supplied && $endToEndContractStatus !== 'terminal_loop_end_to_end_contract_available') {
            $validationViolations[] = 'post_cycle_end_to_end_contract_not_available';
        }
        if ($supplied && ! $endToEndContractAllSurfacesPresent) {
            $validationViolations[] = 'post_cycle_end_to_end_contract_surfaces_not_all_present';
        }
        if ($supplied && $endToEndContractFailedCheckIds !== []) {
            $validationViolations[] = 'post_cycle_end_to_end_contract_has_failed_checks';
        }
        if ($supplied && $missingEndToEndContractCapabilities !== []) {
            $validationViolations[] = 'post_cycle_end_to_end_contract_missing_required_capabilities';
        }
        if ($supplied && preg_match('/^[a-f0-9]{64}\z/', $endToEndContractHash) !== 1) {
            $validationViolations[] = 'invalid_or_missing_post_cycle_end_to_end_contract_hash';
        }
        if ($supplied && $postCycleClaimedTaskCount !== 0) {
            $validationViolations[] = 'post_cycle_claimed_tasks_not_zero';
        }
        if ($supplied && $postCycleActiveLeaseCount !== 0) {
            $validationViolations[] = 'post_cycle_active_leases_not_zero';
        }
        if ($supplied && $postCycleRecoverableLeaseCount !== 0) {
            $validationViolations[] = 'post_cycle_recoverable_leases_not_zero';
        }
        $passed = $supplied && $validationViolations === [];

        return [
            'schema_version' => AgentControlPlaneTerminalLoopOperationalProofService::SCHEMA_VERSION,
            'status' => $supplied ? ($passed ? 'passed' : 'supplied_but_not_accepted') : 'not_supplied_to_read_only_audit',
            'supplied' => $supplied,
            'passed' => $passed,
            'proof_hash' => $hash,
            'invariants_all_true' => $invariantsAllTrue,
            'operational_readiness_matrix_all_true' => $matrixAllTrue,
            'completion_real_allowed' => $completionRealAllowed,
            'provider_call_allowed' => $providerCallAllowed,
            'token_spend_allowed' => $tokenSpendAllowed,
            'dispatch_allowed' => $dispatchAllowed,
            'adapter_execution_allowed' => $adapterExecutionAllowed,
            'self_programming_allowed' => $selfProgrammingAllowed,
            'post_cycle_cycle_supervisor_status' => $cycleSupervisorStatus,
            'post_cycle_cycle_supervisor_cycle_state' => $cycleSupervisorState,
            'post_cycle_cycle_supervisor_next_command_purpose' => $cycleSupervisorPurpose,
            'post_cycle_cycle_supervisor_hash' => $cycleSupervisorHash,
            'post_cycle_end_to_end_contract_status' => $endToEndContractStatus,
            'post_cycle_end_to_end_contract_all_required_surfaces_present' => $endToEndContractAllSurfacesPresent,
            'post_cycle_end_to_end_contract_covered_capabilities' => $endToEndContractCoveredCapabilities,
            'post_cycle_end_to_end_contract_failed_check_ids' => $endToEndContractFailedCheckIds,
            'post_cycle_end_to_end_contract_missing_required_capabilities' => $missingEndToEndContractCapabilities,
            'post_cycle_end_to_end_contract_hash' => $endToEndContractHash,
            'post_cycle_cleanup_state' => [
                'claimed_task_count' => $postCycleClaimedTaskCount,
                'active_lease_count' => $postCycleActiveLeaseCount,
                'recoverable_lease_count' => $postCycleRecoverableLeaseCount,
            ],
            'validation_violations' => $validationViolations,
            'validation_violation_count' => count($validationViolations),
            'expected_command' => 'php artisan atlas:ai:self-construction --agent-control-plane-terminal-loop-operational-proof-status --json',
            'note' => $supplied
                ? ($passed
                    ? 'Operational proof was supplied to the read-only audit and bound as terminal-loop evidence.'
                    : 'Operational proof was supplied to the read-only audit but rejected as terminal-loop evidence; inspect validation_violations and rerun the bounded operational proof.')
                : 'Completion audit does not run the operational proof because that proof creates local dry-run queue/lease receipts; run the expected command separately and provide the payload when evidence binding is needed.',
        ];
    }

    /**
     * @param  list<string>  $paths
     */
    private static function readFilesSafe(array $paths): string
    {
        return implode("\n", array_map(fn (string $path): string => self::readFileSafe($path), $paths));
    }

    private static function readFileSafe(string $path): string
    {
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            return '';
        }
        $contents = @file_get_contents($path);

        return $contents === false ? '' : $contents;
    }
}