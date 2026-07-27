<?php

namespace App\Services\Ai\SelfConstruction\ControlPlane;

use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class AgentControlPlaneAdapterExecutionRuntimeBoundaryCertificationService
{
    use RecursivelyKsortsArrays { recursivelyKsort as ksortRecursive; }

    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_adapter_execution_runtime_boundary.v1';

    public const MODE = 'read_only_agent_control_plane_adapter_execution_runtime_boundary_certification';

    public function __construct(
        private readonly AgentProviderAdapterRegistry $registry = new AgentProviderAdapterRegistry,
    ) {}

    /** @return array<string, mixed> */
    public function certify(array $options = []): array
    {
        $provider = strtolower(trim((string) ($options['provider'] ?? 'codex')));
        $adapter = strtolower(trim((string) ($options['adapter'] ?? $provider)));
        $executionMode = (string) ($options['execution_mode'] ?? 'dry_run_contract_only');
        $violations = [];

        try {
            $descriptor = $this->registry->resolve($provider, $adapter);
            $descriptorHash = $this->registry->descriptorHash($descriptor);
        } catch (InvalidArgumentException $e) {
            $descriptor = [];
            $descriptorHash = '';
            $violations[] = ['code' => 'adapter_descriptor_invalid', 'message' => $e->getMessage()];
        }

        if ($descriptor === []) {
            $violations[] = ['code' => 'adapter_descriptor_missing', 'message' => 'Adapter descriptor must be present.'];
        } else {
            foreach (['adapter_id', 'provider', 'adapter', 'required_context', 'required_outputs'] as $field) {
                if (($descriptor[$field] ?? null) === null || $descriptor[$field] === [] || $descriptor[$field] === '') {
                    $violations[] = ['code' => 'adapter_descriptor_missing_'.$field, 'message' => 'Adapter descriptor field is required.'];
                }
            }
            if ((bool) ($descriptor['external_process_start_enabled'] ?? true)) {
                $violations[] = ['code' => 'adapter_descriptor_external_process_enabled', 'message' => 'Descriptor must not enable external process start.'];
            }
            if ((bool) ($descriptor['token_spend_enabled'] ?? true)) {
                $violations[] = ['code' => 'adapter_descriptor_token_spend_enabled', 'message' => 'Descriptor must not enable token spend.'];
            }
        }

        if ($executionMode !== 'dry_run_contract_only') {
            $violations[] = ['code' => 'live_execution_requested', 'message' => 'Adapter execution boundary only allows dry-run contract mode.'];
        }
        foreach (['adapter_invocation_allowed', 'adapter_execution_allowed', 'provider_process_call_allowed', 'provider_call_allowed', 'token_spend_allowed', 'dispatch_allowed', 'process_started', 'provider_started', 'external_process_started', 'self_programming_allowed'] as $flag) {
            if (($options[$flag] ?? false) === true) {
                $violations[] = ['code' => 'runtime_flag_true', 'flag' => $flag, 'message' => 'Adapter execution boundary rejects runtime-enabling flags.'];
            }
        }

        $guardrailMatrix = [
            'contract_ready' => true,
            'descriptor_valid' => $descriptor !== [] && $descriptorHash !== '',
            'evidence_bridge_required_before_live' => true,
            'scope_lock_required_before_live' => true,
            'approval_required_before_live' => true,
            'no_live_adapter_execution' => $executionMode === 'dry_run_contract_only',
            'no_provider_call' => true,
            'no_token_spend' => true,
            'no_dispatch' => true,
            'no_self_programming' => true,
        ];
        $failureTaxonomy = [
            'adapter_descriptor_missing',
            'adapter_descriptor_invalid',
            'live_execution_requested',
            'provider_call_requested',
            'token_spend_requested',
            'dispatch_requested',
            'runtime_flag_true',
            'missing_required_context',
            'missing_required_output_contract',
            'unsafe_workspace_binding',
            'approval_receipt_missing',
            'evidence_bridge_missing',
        ];
        $envelope = [
            'provider' => $provider,
            'adapter' => $adapter,
            'execution_mode' => $executionMode,
            'adapter_descriptor_hash' => $descriptorHash,
            'adapter_execution_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'dispatch_allowed' => false,
        ];
        $envelope['execution_envelope_hash'] = $this->stableHash($envelope);
        $guardrailMatrixHash = $this->stableHash($guardrailMatrix);
        $failureTaxonomyHash = $this->stableHash($failureTaxonomy);

        $status = $violations === [] ? 'available' : 'blocked';
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'certified_at' => CarbonImmutable::now()->toIso8601String(),
            'adapter_descriptor' => $descriptor,
            'adapter_descriptor_hash' => $descriptorHash,
            'execution_envelope_dry_run' => $envelope,
            'guardrail_matrix' => $guardrailMatrix,
            'guardrail_matrix_hash' => $guardrailMatrixHash,
            'failure_taxonomy' => $failureTaxonomy,
            'failure_taxonomy_hash' => $failureTaxonomyHash,
            'violations' => $violations,
            'violation_count' => count($violations),
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_process_call_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'dispatch_allowed' => false,
            'process_started' => false,
            'provider_started' => false,
            'external_process_started' => false,
            'self_programming_allowed' => false,
            'runtime_safety' => [
                'runtime_safety_all_false' => true,
                'adapter_invocation_allowed' => false,
                'adapter_execution_allowed' => false,
                'provider_process_call_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'dispatch_allowed' => false,
                'process_started' => false,
                'provider_started' => false,
                'external_process_started' => false,
                'self_programming_allowed' => false,
            ],
            'next_action' => $status === 'available'
                ? 'keep_adapter_execution_runtime_blocked_until_signed_provider_execution_gate'
                : 'repair_adapter_execution_runtime_boundary_certification_violations',
        ];
        $payload['certification_hash'] = $this->stableHash($payload);

        return $payload;
    }

    private function stableHash(array $payload): string
    {
        unset($payload['certified_at'], $payload['certification_hash']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

}
