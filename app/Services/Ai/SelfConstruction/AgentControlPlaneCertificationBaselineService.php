<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Str;

/**
 * Certification baseline for the Agent Control Plane.
 *
 * Combines the canonical projections (control plane, chain integrity,
 * deterministic replay, snapshot store, replay diff and promotion gate)
 * into a deterministic, hashable baseline payload that downstream
 * certification services can reference.
 *
 * Read-only by design: never starts processes, never calls Codex CLI/app,
 * never spawns subprocesses, never invokes adapters, never dispatches
 * work, never spends tokens, never advances the next required slice,
 * never enables self-programming, never writes the ledger.
 */
final class AgentControlPlaneCertificationBaselineService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_certification_baseline.v1';

    public const MODE = 'read_only_agent_control_plane_certification_baseline';

    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $readiness,
        private readonly AgentControlPlaneChainIntegrityAuditService $audit,
        private readonly AgentControlPlaneDeterministicChainReplayService $replay,
        private readonly AgentControlPlaneReplaySnapshotStore $store,
        private readonly AgentControlPlaneReplayDiffService $diff,
        private readonly AgentControlPlaneMacroSprintPromotionGate $gate,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function build(array $options = []): array
    {
        $controlPlane = $this->readiness->agentControlPlane($this->normalizeReadinessOptions());
        $chainIntegrity = $this->audit->audit();
        $replay = $this->replay->replay($options);
        $registry = $this->store->registry();
        $latestSnapshot = $this->store->latest();
        $diff = $this->diff->diff();
        $gate = $this->gate->evaluate();

        $docsSection = $this->buildDocsSection();
        $commandSection = $this->buildCommandSection();
        $capabilitySection = $this->buildCapabilitySection($controlPlane);
        $readinessSection = $this->buildReadinessSection($chainIntegrity);
        $invokerSection = $this->buildInvokerSection($chainIntegrity);
        $testSection = $this->buildTestSection();
        $runtimeSafetySection = $this->buildRuntimeSafetySection($replay);

        $sections = [
            'control_plane' => [
                'canonical_name' => (string) data_get($controlPlane, 'control_plane.canonical_name', ''),
                'parent_program' => (string) data_get($controlPlane, 'control_plane.parent_program', ''),
                'control_plane_hash' => (string) data_get($controlPlane, 'control_plane_hash', ''),
                'persistent_runtime_status' => (string) data_get($controlPlane, 'control_plane.persistent_runtime.status', ''),
                'next_required_slice' => (string) data_get($controlPlane, 'control_plane.persistent_runtime.next_required_slice', ''),
                'next_build_slices' => (array) data_get($controlPlane, 'control_plane.next_build_slices', []),
                'not_yet_runtime_capable' => (array) data_get($controlPlane, 'control_plane.not_yet_runtime_capable', []),
                'current_capability_count' => count((array) data_get($controlPlane, 'control_plane.current_capability', [])),
            ],
            'chain_integrity' => [
                'status' => (string) data_get($chainIntegrity, 'status', ''),
                'chain_length' => (int) data_get($chainIntegrity, 'chain_length', 0),
                'integrity_hash' => (string) data_get($chainIntegrity, 'agent_control_plane_chain_integrity_certification_hash', ''),
                'violation_count' => count((array) data_get($chainIntegrity, 'violations', [])),
                'warning_count' => count((array) data_get($chainIntegrity, 'warnings', [])),
            ],
            'deterministic_replay' => [
                'status' => (string) data_get($replay, 'status', ''),
                'replay_hash' => (string) data_get($replay, 'replay_hash', ''),
                'deterministic_replay_hash' => (string) data_get($replay, 'deterministic_replay_hash', ''),
                'proof_bundle_hash' => (string) data_get($replay, 'proof_bundle_hash', ''),
                'replayed_slice_count' => (int) data_get($replay, 'replayed_slice_count', 0),
                'replayed_edge_count' => (int) data_get($replay, 'replayed_edge_count', 0),
            ],
            'snapshot_store' => [
                'entry_count' => (int) data_get($registry, 'entry_count', 0),
                'latest_snapshot_id' => (string) data_get($latestSnapshot, 'snapshot_id', ''),
                'latest_deterministic_replay_hash' => (string) data_get($latestSnapshot, 'deterministic_replay_hash', ''),
                'latest_proof_bundle_hash' => (string) data_get($latestSnapshot, 'proof_bundle_hash', ''),
                'latest_created_at' => (string) data_get($latestSnapshot, 'created_at', ''),
                'corrupt' => (bool) data_get($registry, 'corrupt', false),
            ],
            'replay_diff' => [
                'status' => (string) data_get($diff, 'status', ''),
                'diff_hash' => (string) data_get($diff, 'diff_hash', ''),
                'changed' => (bool) data_get($diff, 'changed', false),
                'regression_count' => (int) data_get($diff, 'regression_count', 0),
                'improvement_count' => (int) data_get($diff, 'improvement_count', 0),
            ],
            'promotion_gate' => [
                'status' => (string) data_get($gate, 'status', ''),
                'gate_hash' => (string) data_get($gate, 'gate_hash', ''),
                'promotion_allowed' => (bool) data_get($gate, 'promotion_allowed', false),
                'completion_claim_allowed' => (bool) data_get($gate, 'completion_claim_allowed', false),
                'blocker_count' => (int) data_get($gate, 'blocker_count', 0),
                'warning_count' => (int) data_get($gate, 'warning_count', 0),
            ],
            'docs' => $docsSection,
            'command_surface' => $commandSection,
            'capability_surface' => $capabilitySection,
            'readiness_surface' => $readinessSection,
            'invoker_surface' => $invokerSection,
            'test_surface' => $testSection,
            'runtime_safety' => $runtimeSafetySection,
        ];

        $chainIntegrityHash = (string) data_get($chainIntegrity, 'agent_control_plane_chain_integrity_certification_hash', '');
        $deterministicReplayHash = (string) data_get($replay, 'deterministic_replay_hash', '');
        $proofBundleHash = (string) data_get($replay, 'proof_bundle_hash', '');
        $latestSnapshotHash = (string) data_get($latestSnapshot, 'deterministic_replay_hash', '');
        $replayDiffHash = (string) data_get($diff, 'diff_hash', '');
        $promotionGateHash = (string) data_get($gate, 'gate_hash', '');

        $docsHash = $this->stableHash($docsSection);
        $commandSurfaceHash = $this->stableHash($commandSection);
        $capabilitySurfaceHash = $this->stableHash($capabilitySection);
        $readinessSurfaceHash = $this->stableHash($readinessSection);
        $invokerSurfaceHash = $this->stableHash($invokerSection);
        $testSurfaceHash = $this->stableHash($testSection);
        $runtimeSafetyHash = $this->stableHash($runtimeSafetySection);

        $invariants = [
            'control_plane_present' => $controlPlane !== [],
            'chain_integrity_present' => $chainIntegrity !== [],
            'replay_present' => $replay !== [],
            'baseline_is_read_only' => true,
            'baseline_does_not_advance_pointer' => true,
            'runtime_safety_all_false' => (bool) data_get($replay, 'runtime_safety.runtime_safety_all_false', false),
            'cycle_integrity_ok' => (bool) data_get($replay, 'cycle_integrity.cycle_ok', false),
            'terminal_horizon_ok' => (bool) data_get($replay, 'terminal_horizon_analysis.horizon_ok', false),
            'completion_claim_not_allowed' => (bool) data_get($gate, 'completion_claim_allowed', false) === false,
            'gate_hash_present' => $promotionGateHash !== '',
            'replay_hash_present' => $deterministicReplayHash !== '',
            'chain_integrity_hash_present' => $chainIntegrityHash !== '',
        ];
        $invariantsAllTrue = ! in_array(false, $invariants, true);

        $baselineId = (string) Str::uuid();
        $generatedAt = CarbonImmutable::now()->toIso8601String();

        $status = match (true) {
            data_get($chainIntegrity, 'status') !== 'available' => 'degraded',
            data_get($replay, 'status') !== 'available' => 'degraded',
            ! $invariantsAllTrue => 'degraded',
            default => 'available',
        };

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'baseline_id' => $baselineId,
            'generated_at' => $generatedAt,
            'status' => $status,
            'mode' => self::MODE,
            'read_only' => true,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'external_provider_call' => false,
            'token_spend' => false,
            'process_started' => false,
            'self_programming_allowed' => false,
            'completion_claim_allowed' => false,
            'current_pointer' => (string) data_get($controlPlane, 'control_plane.persistent_runtime.next_required_slice', ''),
            'expected_pointer' => (string) data_get($chainIntegrity, 'expected_next_required_slice', ''),
            'next_build_slices' => (array) data_get($controlPlane, 'control_plane.next_build_slices', []),
            'not_yet_runtime_capable' => (array) data_get($controlPlane, 'control_plane.not_yet_runtime_capable', []),
            'chain_integrity_hash' => $chainIntegrityHash,
            'deterministic_replay_hash' => $deterministicReplayHash,
            'proof_bundle_hash' => $proofBundleHash,
            'latest_snapshot_hash' => $latestSnapshotHash,
            'replay_diff_hash' => $replayDiffHash,
            'promotion_gate_hash' => $promotionGateHash,
            'docs_hash' => $docsHash,
            'command_surface_hash' => $commandSurfaceHash,
            'capability_surface_hash' => $capabilitySurfaceHash,
            'readiness_surface_hash' => $readinessSurfaceHash,
            'invoker_surface_hash' => $invokerSurfaceHash,
            'test_surface_hash' => $testSurfaceHash,
            'runtime_safety_hash' => $runtimeSafetyHash,
            'sections' => $sections,
            'invariants' => $invariants,
            'invariants_all_true' => $invariantsAllTrue,
            'non_execution_guarantees' => [
                'baseline_does_not_start_codex',
                'baseline_does_not_call_codex_cli_or_app',
                'baseline_does_not_spawn_subprocess',
                'baseline_does_not_invoke_adapter',
                'baseline_does_not_execute_adapter',
                'baseline_does_not_call_provider',
                'baseline_does_not_dispatch_work',
                'baseline_does_not_spend_tokens',
                'baseline_does_not_enable_self_programming',
                'baseline_does_not_write_ledger',
                'baseline_does_not_mutate_pointer',
                'baseline_does_not_promote_completion_claim',
            ],
            'human_summary' => match ($status) {
                'available' => 'Agent Control Plane Certification Baseline v1 is available; all canonical projections aligned.',
                'degraded' => 'Agent Control Plane Certification Baseline v1 is degraded; review chain integrity, replay or invariants before relying on the baseline.',
                'blocked' => 'Agent Control Plane Certification Baseline v1 is blocked; baseline cannot be built.',
                default => 'Agent Control Plane Certification Baseline v1 status is unknown.',
            },
        ];

        $payload['baseline_hash'] = $this->stableHash($this->normalizeForBaselineHash($payload));
        $payload['baseline_fingerprint'] = substr($payload['baseline_hash'], 0, 12);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $controlPlane
     * @return array<string, mixed>
     */
    private function buildCapabilitySection(array $controlPlane): array
    {
        $capabilities = (array) data_get($controlPlane, 'control_plane.current_capability', []);
        sort($capabilities);

        return [
            'capability_count' => count($capabilities),
            'capabilities' => $capabilities,
            'duplicate_count' => count($capabilities) - count(array_unique($capabilities)),
        ];
    }

    /**
     * @param  array<string, mixed>  $chainIntegrity
     * @return array<string, mixed>
     */
    private function buildReadinessSection(array $chainIntegrity): array
    {
        $surface = (array) data_get($chainIntegrity, 'readiness_surface', []);

        return [
            'deep_checked' => (int) data_get($surface, 'deep_checked', 0),
            'shallow_checked' => (int) data_get($surface, 'shallow_checked', 0),
            'all_quartet_methods_present' => (bool) data_get($surface, 'all_quartet_methods_present', false),
            'readiness_gap_count' => count((array) data_get($chainIntegrity, 'readiness_gaps', [])),
        ];
    }

    /**
     * @param  array<string, mixed>  $chainIntegrity
     * @return array<string, mixed>
     */
    private function buildInvokerSection(array $chainIntegrity): array
    {
        $surface = (array) data_get($chainIntegrity, 'invoker_surface', []);

        return [
            'deep_checked' => (int) data_get($surface, 'deep_checked', 0),
            'invoker_gap_count' => count((array) data_get($chainIntegrity, 'invoker_gaps', [])),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCommandSection(): array
    {
        $kernel = app(ConsoleKernel::class);
        $registry = $kernel->all();
        $relevant = [];
        foreach (array_keys($registry) as $name) {
            if (str_starts_with((string) $name, 'atlas:ai:self-construction')) {
                $relevant[] = $name;
            }
        }
        sort($relevant);

        return [
            'self_construction_command_count' => count($relevant),
            'self_construction_commands' => $relevant,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDocsSection(): array
    {
        $path = base_path('docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md');
        if (! is_file($path)) {
            return [
                'present' => false,
                'byte_size' => 0,
                'line_count' => 0,
                'sha256' => '',
            ];
        }
        $contents = (string) file_get_contents($path);

        return [
            'present' => true,
            'path' => 'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            'byte_size' => strlen($contents),
            'line_count' => substr_count($contents, "\n") + 1,
            'sha256' => hash('sha256', $contents),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTestSection(): array
    {
        $path = base_path('tests/Feature/Ai');
        if (! is_dir($path)) {
            return ['file_count' => 0, 'files' => []];
        }
        $files = [];
        foreach (scandir($path) ?: [] as $entry) {
            if (str_contains($entry, 'AtlasAiSelfConstruction')) {
                $files[] = $entry;
            }
        }
        sort($files);

        return [
            'file_count' => count($files),
            'files' => $files,
        ];
    }

    /**
     * @param  array<string, mixed>  $replay
     * @return array<string, mixed>
     */
    private function buildRuntimeSafetySection(array $replay): array
    {
        $runtimeSafety = (array) data_get($replay, 'runtime_safety', []);

        return [
            'runtime_safety_all_false' => (bool) data_get($runtimeSafety, 'runtime_safety_all_false', false),
            'observed_flags' => (array) data_get($runtimeSafety, 'observed_flags', []),
            'expected_flags' => (array) data_get($runtimeSafety, 'expected_flags', []),
            'gap_count' => count((array) data_get($runtimeSafety, 'runtime_safety_gaps', [])),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeForBaselineHash(array $payload): array
    {
        $clone = $payload;
        unset(
            $clone['baseline_id'],
            $clone['generated_at'],
            $clone['baseline_hash'],
            $clone['baseline_fingerprint'],
            // promotion_gate_hash and the underlying replay_hash include
            // `generated_at`/`replay_id`/`gate_id`, so baseline_hash strips
            // them; deterministic_replay_hash is the canonical stable proxy.
            $clone['promotion_gate_hash'],
        );
        if (isset($clone['sections']['promotion_gate'])) {
            unset($clone['sections']['promotion_gate']['gate_hash']);
        }
        if (isset($clone['sections']['deterministic_replay'])) {
            unset($clone['sections']['deterministic_replay']['replay_hash']);
        }

        return $this->recursivelyKsort($clone);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeReadinessOptions(): array
    {
        return array_fill_keys([
            'workspace', 'target', 'packet', 'actor', 'session',
            'lease_minutes', 'reason', 'evidence_hash', 'model',
            'input_tokens', 'output_tokens', 'cost_usd', 'artifact_type',
            'artifact_path', 'artifact_hash', 'summary', 'decision',
            'signed_by', 'receipt_hash', 'dispatch_envelope_hash',
            'adapter_contract_hash', 'expires_at',
        ], null);
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
