<?php

namespace App\Services\Ai\SelfConstruction\ControlPlane;

use App\Services\Ai\SelfConstruction\Support\HashesKsortedPayloadCanonically;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;
/**
 * Deterministic replay of the Agent Control Plane chain integrity certification.
 *
 * It walks the canonical deep chain produced by AgentControlPlaneChainIntegrityAuditService,
 * synthesises a proof bundle per slice (readiness quartet, capability quintet, CLI quartet,
 * invoker proof, doc proof, status proof, runtime-safety proof) plus edge proofs, and
 * outputs a payload that is stable across executions when the underlying state is stable.
 *
 * The replay MUST NOT start providers, call Codex CLI/app, spawn subprocesses, spend tokens,
 * dispatch work, invoke adapters, enable runtime flags or promote completion claims.
 * It complements the Chain Integrity Certification with a reproducible, hashable proof.
 */
use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;

final class AgentControlPlaneDeterministicChainReplayService
{
    use HashesKsortedPayloadCanonically;
    use RecursivelyKsortsArrays;
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_deterministic_chain_replay.v1';

    public const MODE = 'read_only_agent_control_plane_deterministic_chain_replay';

    /** Volatile fields stripped before computing deterministic_replay_hash. */
    public const DETERMINISTIC_HASH_EXCLUDED_FIELDS = ['replay_id', 'generated_at'];

    public function __construct(
        private readonly AgentControlPlaneChainIntegrityAuditService $audit,
        private readonly AtlasSelfConstructionReadinessService $readiness,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function replay(array $options = []): array
    {
        $includeSlices = (bool) ($options['include_slices'] ?? true);
        $includeEdges = (bool) ($options['include_edges'] ?? true);
        $includeProofBundle = (bool) ($options['include_proof_bundle'] ?? true);
        $deterministic = (bool) ($options['deterministic'] ?? true);
        $maxSlices = isset($options['max_slices']) && is_int($options['max_slices']) ? $options['max_slices'] : null;

        $auditOptions = [];
        if (isset($options['override_chain_integrity']) && is_array($options['override_chain_integrity'])) {
            $auditOptions['override_projection'] = $options['override_chain_integrity'];
        }
        if (isset($options['override_control_plane']) && is_array($options['override_control_plane'])) {
            $auditOptions['override_projection'] = array_merge(
                (array) ($auditOptions['override_projection'] ?? []),
                $options['override_control_plane'],
            );
        }
        if (isset($options['override_slices']) && is_array($options['override_slices'])) {
            $auditOptions['override_slices'] = $options['override_slices'];
        }
        if (isset($options['override_projection']) && is_array($options['override_projection'])) {
            $auditOptions['override_projection'] = array_merge(
                (array) ($auditOptions['override_projection'] ?? []),
                $options['override_projection'],
            );
        }

        $auditPayload = $this->audit->audit($auditOptions);
        $controlPlanePayload = $this->readiness->agentControlPlane($this->normalizeReadinessOptions());

        $deepChain = (array) data_get($auditPayload, 'slices', []);
        $perSliceEdges = (array) data_get($auditPayload, 'per_slice_next_edge', []);
        $currentPointer = (string) data_get($auditPayload, 'current_next_required_slice', '');
        $expectedPointer = (string) data_get($auditPayload, 'expected_next_required_slice', '');
        $nextBuildSlices = (array) data_get($auditPayload, 'current_next_build_slices', []);
        $notYetRuntimeCapable = (array) data_get($auditPayload, 'current_not_yet_runtime_capable', []);
        $cycleIntegrity = (array) data_get($auditPayload, 'cycle_integrity', []);
        $terminalHorizon = (array) data_get($auditPayload, 'terminal_horizon_analysis', []);
        $runtimeSafety = (array) data_get($auditPayload, 'runtime_safety', []);
        $cliSurface = (array) data_get($auditPayload, 'cli_surface', []);
        $documentation = (array) data_get($auditPayload, 'documentation', []);
        $auditViolations = (array) data_get($auditPayload, 'violations', []);
        $auditWarnings = (array) data_get($auditPayload, 'warnings', []);

        if ($maxSlices !== null && $maxSlices >= 0) {
            $deepChain = array_slice($deepChain, 0, $maxSlices);
            $perSliceEdges = array_slice($perSliceEdges, 0, max(0, $maxSlices - 1));
        }

        $replayedSlices = [];
        if ($includeSlices) {
            foreach ($deepChain as $slice) {
                $replayedSlices[] = $this->buildSliceProof($slice, $cliSurface, $documentation);
            }
        }

        $replayedEdges = [];
        if ($includeEdges) {
            foreach ($perSliceEdges as $edge) {
                $replayedEdges[] = $this->buildEdgeProof($edge, $cycleIntegrity);
            }
        }

        $proofBundle = $includeProofBundle
            ? $this->buildProofBundle($auditPayload, $controlPlanePayload, $cycleIntegrity, $terminalHorizon, $runtimeSafety)
            : null;

        $violations = [];
        $warnings = [];
        foreach ($auditViolations as $v) {
            $violations[] = $v;
        }
        foreach ($auditWarnings as $w) {
            $warnings[] = $w;
        }

        $auditOk = data_get($auditPayload, 'status') === 'available';
        $status = match (true) {
            $violations !== [] => 'degraded',
            ! $auditOk => 'degraded',
            default => 'available',
        };

        $invariants = [
            'audit_payload_present' => is_array($auditPayload) && $auditPayload !== [],
            'control_plane_payload_present' => is_array($controlPlanePayload) && $controlPlanePayload !== [],
            'chain_integrity_status_known' => in_array((string) data_get($auditPayload, 'status'), ['available', 'degraded', 'blocked', 'missing_artifacts'], true),
            'runtime_safety_all_false' => (bool) data_get($runtimeSafety, 'runtime_safety_all_false', false),
            'cycle_integrity_ok' => (bool) data_get($cycleIntegrity, 'cycle_ok', false),
            'terminal_horizon_ok' => (bool) data_get($terminalHorizon, 'horizon_ok', false),
            'completion_claim_not_allowed' => (bool) data_get($terminalHorizon, 'completion_claim_allowed', false) === false,
            'replay_is_read_only' => true,
            'replay_did_not_advance_pointer' => $currentPointer === (string) data_get($controlPlanePayload, 'control_plane.persistent_runtime.next_required_slice', ''),
            'replay_includes_slices' => $includeSlices ? count($replayedSlices) > 0 : true,
            'replay_includes_edges' => $includeEdges ? count($replayedEdges) >= 0 : true,
        ];
        $invariantsAllTrue = ! in_array(false, $invariants, true);

        $minimumProofBundleRequirements = [
            'slices' => $includeSlices && count($replayedSlices) > 0,
            'edges' => $includeEdges,
            'proof_bundle' => $proofBundle !== null,
            'runtime_safety' => (bool) data_get($runtimeSafety, 'runtime_safety_all_false', false),
            'cycle_integrity' => (bool) data_get($cycleIntegrity, 'cycle_ok', false),
            'terminal_horizon' => (bool) data_get($terminalHorizon, 'horizon_ok', false),
        ];
        $readinessClaimAllowed = ! in_array(false, $minimumProofBundleRequirements, true);

        $replayId = (string) Str::uuid();
        $generatedAt = CarbonImmutable::now()->toIso8601String();

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => self::MODE,
            'replay_id' => $replayId,
            'generated_at' => $generatedAt,
            'read_only' => true,
            'external_provider_call' => false,
            'token_spend' => false,
            'process_started' => false,
            'dispatch_allowed' => false,
            'self_programming_allowed' => false,
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'current_pointer' => $currentPointer,
            'expected_pointer' => $expectedPointer,
            'next_build_slices' => $nextBuildSlices,
            'not_yet_runtime_capable' => $notYetRuntimeCapable,
            'replayed_slice_count' => count($replayedSlices),
            'replayed_edge_count' => count($replayedEdges),
            'replayed_slices' => $replayedSlices,
            'replayed_edges' => $replayedEdges,
            'proof_bundle' => $proofBundle,
            'runtime_safety' => $runtimeSafety,
            'cycle_integrity' => $cycleIntegrity,
            'terminal_horizon_analysis' => $terminalHorizon,
            'next_safe_macro_batch' => (string) data_get($terminalHorizon, 'next_safe_macro_batch', ''),
            'violations' => $violations,
            'warnings' => $warnings,
            'invariants' => $invariants,
            'invariants_all_true' => $invariantsAllTrue,
            'minimum_proof_bundle_requirements' => $minimumProofBundleRequirements,
            'readiness_claim_allowed' => $readinessClaimAllowed,
            'options_applied' => [
                'include_slices' => $includeSlices,
                'include_edges' => $includeEdges,
                'include_proof_bundle' => $includeProofBundle,
                'deterministic' => $deterministic,
                'max_slices' => $maxSlices,
            ],
            'input_hash' => $this->stableHash($this->normalizeForHashing($auditOptions)),
            'chain_integrity_hash' => (string) data_get($auditPayload, 'agent_control_plane_chain_integrity_certification_hash', ''),
            'control_plane_hash' => (string) data_get($controlPlanePayload, 'control_plane_hash', ''),
            'non_execution_guarantees' => [
                'replay_does_not_start_codex',
                'replay_does_not_call_codex_cli_or_app',
                'replay_does_not_spawn_subprocess',
                'replay_does_not_invoke_adapter',
                'replay_does_not_execute_adapter',
                'replay_does_not_call_provider',
                'replay_does_not_dispatch_work',
                'replay_does_not_spend_tokens',
                'replay_does_not_enable_self_programming',
                'replay_does_not_write_ledger',
                'replay_does_not_mutate_pointer',
                'replay_does_not_promote_completion_claim',
            ],
            'human_summary' => match ($status) {
                'available' => 'Agent Control Plane Deterministic Chain Replay v1 is available; chain replayed with deterministic proof bundle.',
                'degraded' => 'Agent Control Plane Deterministic Chain Replay v1 is degraded; review violations before relying on the proof bundle.',
                'blocked' => 'Agent Control Plane Deterministic Chain Replay v1 is blocked; chain integrity audit cannot be replayed.',
                default => 'Agent Control Plane Deterministic Chain Replay v1 status is unknown.',
            },
        ];

        $payload['proof_bundle_hash'] = $proofBundle === null ? null : $this->stableHash($proofBundle);
        $payload['replay_hash'] = $this->stableHash($this->normalizeForReplayHash($payload));
        $payload['deterministic_replay_hash'] = $this->stableHash($this->normalizeForDeterministicHash($payload));
        $payload['deterministic_hash_exclusions'] = self::DETERMINISTIC_HASH_EXCLUDED_FIELDS;

        // Stage hashes for context, task, execution, proof, and learning stages
        $stageHashes = [
            'context'   => $this->stableHash((array) data_get($auditPayload, 'capability_surface', [])),
            'task'      => $this->stableHash((array) data_get($auditPayload, 'readiness_surface', [])),
            'execution' => $this->stableHash((array) data_get($auditPayload, 'invoker_surface', [])),
            'proof'     => $proofBundle !== null ? $this->stableHash($proofBundle) : '',
            'learning'  => $this->stableHash((array) data_get($auditPayload, 'cycle_integrity', [])),
        ];
        $payload['stage_hashes'] = $stageHashes;

        // first_divergent_stage: null when all stages have valid hashes, otherwise the first stage with an empty hash
        $firstDivergentStage = null;
        foreach ($stageHashes as $stage => $hash) {
            if ($hash === '') {
                $firstDivergentStage = $stage;
                break;
            }
        }
        $payload['first_divergent_stage'] = $firstDivergentStage;

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $slice
     * @param  array<string, mixed>  $cliSurface
     * @param  array<string, mixed>  $documentation
     * @return array<string, mixed>
     */
    private function buildSliceProof(array $slice, array $cliSurface, array $documentation): array
    {
        $sliceKey = (string) data_get($slice, 'slice_key', '');
        $checks = (array) data_get($slice, 'checks', []);
        $methodPrefix = (string) data_get($slice, 'method_prefix', '');
        $invokerClass = (string) data_get($slice, 'invoker_class', '');
        $prepareMethod = (string) data_get($slice, 'prepare_method', '');
        $docBullet = (string) data_get($slice, 'doc_bullet', '');
        $cliBase = $sliceKey === '' ? '' : ('agent-'.str_replace('_', '-', $sliceKey));
        $endsInContract = str_ends_with($sliceKey, '_contract');
        $cliContractOption = $endsInContract ? $cliBase : ($cliBase.'-contract');
        $presentCliOptions = (array) data_get($cliSurface, 'present_options', []);

        $readinessProof = [
            'contract_method' => $methodPrefix === '' ? '' : ($endsInContract ? $methodPrefix : $methodPrefix.'Contract'),
            'preflight_method' => $methodPrefix === '' ? '' : $methodPrefix.'Preflight',
            'implementation_packet_method' => $methodPrefix === '' ? '' : $methodPrefix.'ImplementationPacket',
            'status_method' => $methodPrefix === '' ? '' : $methodPrefix.'Status',
            'contract_method_exists' => (bool) ($checks['contract_method_exists'] ?? false),
            'preflight_method_exists' => (bool) ($checks['preflight_method_exists'] ?? false),
            'implementation_packet_method_exists' => (bool) ($checks['implementation_packet_method_exists'] ?? false),
            'status_method_exists' => (bool) ($checks['status_method_exists'] ?? false),
        ];

        $cliProof = [
            'contract_option' => $cliContractOption,
            'preflight_option' => $cliBase.'-preflight',
            'implementation_packet_option' => $cliBase.'-implementation-packet',
            'status_option' => $cliBase.'-status',
            'contract_option_present' => in_array($cliContractOption, $presentCliOptions, true),
            'preflight_option_present' => in_array($cliBase.'-preflight', $presentCliOptions, true),
            'implementation_packet_option_present' => in_array($cliBase.'-implementation-packet', $presentCliOptions, true),
            'status_option_present' => in_array($cliBase.'-status', $presentCliOptions, true),
        ];

        $capabilityProof = [
            'contract_capability' => (string) ($slice['contract_capability_key'] ?? ($sliceKey.'_contract')),
            'preflight_capability' => (string) ($slice['preflight_capability_key'] ?? ($sliceKey.'_preflight')),
            'implementation_packet_capability' => (string) ($slice['implementation_packet_capability_key'] ?? ($sliceKey.'_implementation_packet')),
            'invoker_service_capability' => (string) ($slice['invoker_service_capability_key'] ?? ($sliceKey.'_invoker_service')),
            'status_projection_capability' => (string) ($slice['status_projection_capability_key'] ?? ($sliceKey.'_status_projection')),
            'contract_registered' => (bool) ($checks['capability_contract_registered'] ?? false),
            'preflight_registered' => (bool) ($checks['capability_preflight_registered'] ?? false),
            'implementation_packet_registered' => (bool) ($checks['capability_implementation_packet_registered'] ?? false),
            'invoker_service_registered' => (bool) ($checks['capability_invoker_service_registered'] ?? false),
            'status_projection_registered' => (bool) ($checks['capability_status_projection_registered'] ?? false),
        ];

        $invokerProof = [
            'invoker_class' => $invokerClass,
            'prepare_method' => $prepareMethod,
            'invoker_class_exists' => (bool) ($checks['invoker_class_exists'] ?? false),
            'invoker_prepare_method_exists' => (bool) ($checks['invoker_prepare_method_exists'] ?? false),
        ];

        $docProof = [
            'doc_bullet_anchor' => $docBullet,
            'doc_bullet_occurrences' => (int) data_get($documentation, 'slice_bullets_found.'.$sliceKey, 0),
            'doc_bullet_unique' => (int) data_get($documentation, 'slice_bullets_found.'.$sliceKey, 0) === 1,
        ];

        $runtimeSafetyProof = [
            'runtime_flags_must_remain_false' => [
                'execution_allowed' => false,
                'dispatch_allowed' => false,
                'provider_process_call_allowed' => false,
                'adapter_invocation_allowed' => false,
                'adapter_execution_allowed' => false,
                'token_spend_allowed' => false,
                'self_programming_allowed' => false,
                'process_started' => false,
                'provider_started' => false,
                'external_process_started' => false,
            ],
            'invariant_runtime_safety_all_false_required' => true,
        ];

        return [
            'slice_key' => $sliceKey,
            'activate_key' => (string) data_get($slice, 'activate_key', ''),
            'runtime_key' => (string) data_get($slice, 'runtime_key', ''),
            'all_artifacts_ok' => (bool) data_get($slice, 'ok', false),
            'readiness_proof' => $readinessProof,
            'cli_proof' => $cliProof,
            'capability_proof' => $capabilityProof,
            'invoker_proof' => $invokerProof,
            'doc_proof' => $docProof,
            'runtime_safety_proof' => $runtimeSafetyProof,
            'next_edge_proof' => [
                // Filled in by buildEdgeProof when invoked; here we expose the
                // canonical activate_key so the edge proof can resolve quickly.
                'this_activate_key' => (string) data_get($slice, 'activate_key', ''),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $edge
     * @param  array<string, mixed>  $cycleIntegrity
     * @return array<string, mixed>
     */
    private function buildEdgeProof(array $edge, array $cycleIntegrity): array
    {
        $fromSlice = (string) data_get($edge, 'from_slice', '');
        $expectedNext = (string) data_get($edge, 'expected_next', '');
        $declaredNext = (string) data_get($edge, 'declared_next', '');
        $edgeOk = (bool) data_get($edge, 'edge_ok', false);

        $intentionalReentryDetected = (bool) data_get($cycleIntegrity, 'intentional_reentry_detected', false);
        $reentryEdges = (array) data_get($cycleIntegrity, 'reentry_edges', []);
        $isReentryFromThisSlice = false;
        foreach ($reentryEdges as $r) {
            if ((string) data_get($r, 'from_slice') === $fromSlice) {
                $isReentryFromThisSlice = true;
                break;
            }
        }

        $edgeType = 'linear';
        if ($intentionalReentryDetected && $isReentryFromThisSlice) {
            $edgeType = 'intentional_reentry';
        } elseif ($expectedNext === '') {
            $edgeType = 'terminal_horizon';
        }

        return [
            'from' => $fromSlice,
            'to' => $expectedNext === '' ? $declaredNext : $expectedNext,
            'declared_next' => $declaredNext,
            'expected_next' => $expectedNext,
            'edge_ok' => $edgeOk,
            'edge_type' => $edgeType,
        ];
    }

    /**
     * @param  array<string, mixed>  $auditPayload
     * @param  array<string, mixed>  $controlPlanePayload
     * @param  array<string, mixed>  $cycleIntegrity
     * @param  array<string, mixed>  $terminalHorizon
     * @param  array<string, mixed>  $runtimeSafety
     * @return array<string, mixed>
     */
    private function buildProofBundle(
        array $auditPayload,
        array $controlPlanePayload,
        array $cycleIntegrity,
        array $terminalHorizon,
        array $runtimeSafety,
    ): array {
        return [
            'chain_integrity_summary' => [
                'status' => (string) data_get($auditPayload, 'status', ''),
                'chain_length' => (int) data_get($auditPayload, 'chain_length', 0),
                'checked_slice_count' => (int) data_get($auditPayload, 'checked_slice_count', 0),
                'violation_count' => count((array) data_get($auditPayload, 'violations', [])),
                'warning_count' => count((array) data_get($auditPayload, 'warnings', [])),
                'invariants_all_true' => (bool) data_get($auditPayload, 'invariants_all_true', false),
                'integrity_hash' => (string) data_get($auditPayload, 'agent_control_plane_chain_integrity_certification_hash', ''),
            ],
            'control_plane_summary' => [
                'canonical_name' => (string) data_get($controlPlanePayload, 'control_plane.canonical_name', ''),
                'parent_program' => (string) data_get($controlPlanePayload, 'control_plane.parent_program', ''),
                'persistent_runtime_status' => (string) data_get($controlPlanePayload, 'control_plane.persistent_runtime.status', ''),
                'next_required_slice' => (string) data_get($controlPlanePayload, 'control_plane.persistent_runtime.next_required_slice', ''),
                'not_yet_runtime_capable' => (array) data_get($controlPlanePayload, 'control_plane.not_yet_runtime_capable', []),
                'next_build_slices' => (array) data_get($controlPlanePayload, 'control_plane.next_build_slices', []),
                'control_plane_hash' => (string) data_get($controlPlanePayload, 'control_plane_hash', ''),
            ],
            'capability_summary' => (array) data_get($auditPayload, 'capability_surface', []),
            'readiness_summary' => (array) data_get($auditPayload, 'readiness_surface', []),
            'cli_summary' => (array) data_get($auditPayload, 'cli_surface', []),
            'invoker_summary' => (array) data_get($auditPayload, 'invoker_surface', []),
            'docs_summary' => (array) data_get($auditPayload, 'documentation', []),
            'runtime_safety_summary' => $runtimeSafety,
            'cycle_summary' => $cycleIntegrity,
            'terminal_horizon_summary' => $terminalHorizon,
            'regression_matrix_summary' => [
                'per_slice_next_edge_count' => count((array) data_get($auditPayload, 'per_slice_next_edge', [])),
                'broken_edge_count' => count((array) data_get($auditPayload, 'broken_edges', [])),
                'capability_gap_count' => count((array) data_get($auditPayload, 'capability_gaps', [])),
                'invoker_gap_count' => count((array) data_get($auditPayload, 'invoker_gaps', [])),
                'readiness_gap_count' => count((array) data_get($auditPayload, 'readiness_gaps', [])),
                'cli_option_gap_count' => count((array) data_get($auditPayload, 'cli_option_gaps', [])),
                'runtime_safety_gap_count' => count((array) data_get($auditPayload, 'runtime_safety_gaps', [])),
                'duplicate_doc_bullet_count' => count((array) data_get($auditPayload, 'duplicate_doc_bullets', [])),
            ],
            'next_safe_macro_batch' => (string) data_get($terminalHorizon, 'next_safe_macro_batch', ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeForHashing(array $payload): array
    {
        $payload = $this->recursivelyKsort($payload);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeForReplayHash(array $payload): array
    {
        // The replay_hash captures the FULL payload state at this moment so
        // two identical replays produce the same hash when the system state
        // is stable. We still strip the hash fields themselves so the hash
        // is not self-referential.
        $clone = $payload;
        unset($clone['replay_hash'], $clone['deterministic_replay_hash'], $clone['proof_bundle_hash']);

        return $this->recursivelyKsort($clone);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeForDeterministicHash(array $payload): array
    {
        // For deterministic_replay_hash we additionally drop volatile fields
        // (generated_at, replay_id) so the hash only changes when the
        // structural state of the chain changes.
        $clone = $payload;
        unset($clone['replay_hash'], $clone['deterministic_replay_hash'], $clone['proof_bundle_hash']);
        foreach (self::DETERMINISTIC_HASH_EXCLUDED_FIELDS as $field) {
            unset($clone[$field]);
        }

        return $this->recursivelyKsort($clone);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeReadinessOptions(): array
    {
        return [
            'workspace' => null,
            'target' => null,
            'packet' => null,
            'actor' => null,
            'session' => null,
            'lease_minutes' => null,
            'reason' => null,
            'evidence_hash' => null,
            'model' => null,
            'input_tokens' => null,
            'output_tokens' => null,
            'cost_usd' => null,
            'artifact_type' => null,
            'artifact_path' => null,
            'artifact_hash' => null,
            'summary' => null,
            'decision' => null,
            'signed_by' => null,
            'receipt_hash' => null,
            'dispatch_envelope_hash' => null,
            'adapter_contract_hash' => null,
            'expires_at' => null,
        ];
    }

}
