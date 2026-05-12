<?php

namespace App\Services\Ai\SelfConstruction;

use InvalidArgumentException;

class AgentProviderAdapterRegistry
{
    /**
     * @return array<string,mixed>
     */
    public function resolve(string $provider, string $adapter): array
    {
        $provider = $this->normalizeKey($provider);
        $adapter = $this->normalizeKey($adapter);
        $descriptor = $this->descriptors()[$provider] ?? null;

        if ($descriptor === null) {
            throw new InvalidArgumentException('provider_adapter_not_registered');
        }

        if ((string) $descriptor['adapter'] !== $adapter) {
            throw new InvalidArgumentException('provider_adapter_mismatch');
        }

        return $descriptor;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function descriptors(): array
    {
        return [
            'codex' => [
                'provider' => 'codex',
                'adapter' => 'codex',
                'adapter_id' => 'ADAPTER-CODEX-SELF-CONSTRUCTION-0001',
                'role' => 'implementation_worker',
                'supported_invocation_modes' => ['manual_codex_app_session', 'future_codex_cli_or_api_runtime'],
                'required_context' => ['packet_scope', 'continuation_summary', 'allowed_files', 'required_gates'],
                'required_outputs' => ['heartbeat_events', 'work_products', 'cost_events_when_available', 'test_output'],
                'forbidden_capabilities' => ['self_merge', 'policy_mutation', 'unbounded_chat_history', 'provider_start_without_signed_release'],
                'external_process_start_enabled' => false,
                'token_spend_enabled' => false,
            ],
            'claude' => [
                'provider' => 'claude',
                'adapter' => 'claude',
                'adapter_id' => 'ADAPTER-CLAUDE-SELF-CONSTRUCTION-0001',
                'role' => 'planner_or_reviewer',
                'supported_invocation_modes' => ['manual_claude_session', 'future_claude_cli_or_api_runtime'],
                'required_context' => ['architecture_context', 'acceptance_criteria', 'diff_or_work_product_summary'],
                'required_outputs' => ['review_findings', 'risk_assessment', 'approval_recommendation'],
                'forbidden_capabilities' => ['edit_without_write_scope', 'approve_sensitive_action_without_human_receipt', 'bypass_quality_gates'],
                'external_process_start_enabled' => false,
                'token_spend_enabled' => false,
            ],
            'gemini' => [
                'provider' => 'gemini',
                'adapter' => 'gemini',
                'adapter_id' => 'ADAPTER-GEMINI-SELF-CONSTRUCTION-0001',
                'role' => 'scout_or_long_context_mapper',
                'supported_invocation_modes' => ['manual_gemini_session', 'future_gemini_cli_or_api_runtime'],
                'required_context' => ['repo_map', 'docs_map', 'search_questions', 'known_hot_scopes'],
                'required_outputs' => ['source_inventory', 'context_map', 'implementation_risks', 'candidate_files'],
                'forbidden_capabilities' => ['write_code_without_explicit_packet', 'invent_source_paths', 'replace_codebase_inspection_with_summary_only'],
                'external_process_start_enabled' => false,
                'token_spend_enabled' => false,
            ],
            'local' => [
                'provider' => 'local',
                'adapter' => 'bash',
                'adapter_id' => 'ADAPTER-LOCAL-RUNTIME-SELF-CONSTRUCTION-0001',
                'role' => 'deterministic_gate_runner',
                'supported_invocation_modes' => ['future_restricted_local_shell_adapter'],
                'required_context' => ['command', 'cwd', 'timeout_seconds', 'expected_exit_policy'],
                'required_outputs' => ['exit_code', 'stdout_summary', 'stderr_summary', 'evidence_hash'],
                'forbidden_capabilities' => ['destructive_command_without_signed_receipt', 'network_or_secret_access_without_policy', 'background_process_without_liveness_tracking'],
                'external_process_start_enabled' => false,
                'token_spend_enabled' => false,
            ],
            'http' => [
                'provider' => 'http',
                'adapter' => 'http',
                'adapter_id' => 'ADAPTER-HTTP-PROVIDER-SELF-CONSTRUCTION-0001',
                'role' => 'future_remote_provider_bridge',
                'supported_invocation_modes' => ['future_policy_bound_http_adapter'],
                'required_context' => ['endpoint_policy_hash', 'redaction_policy_hash', 'request_schema_hash'],
                'required_outputs' => ['response_summary', 'status_code', 'cost_events_when_available', 'evidence_hash'],
                'forbidden_capabilities' => ['raw_secret_exfiltration', 'unredacted_sensitive_payload', 'unbounded_retry_loop'],
                'external_process_start_enabled' => false,
                'token_spend_enabled' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function projection(): array
    {
        $descriptors = array_values($this->descriptors());

        return [
            'status' => 'provider_adapter_registry_ready',
            'registry_id' => 'AGENT-PROVIDER-ADAPTER-REGISTRY-SELF-CONSTRUCTION-0001',
            'provider_count' => count($descriptors),
            'providers' => $descriptors,
            'registry_policy' => [
                'registry_is_authoritative_for_adapter_identity' => true,
                'external_process_start_allowed' => false,
                'token_spend_allowed' => false,
                'provider_specific_execution_requires_future_contract' => true,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $descriptor
     */
    public function descriptorHash(array $descriptor): string
    {
        ksort($descriptor);

        return hash('sha256', json_encode($descriptor, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function normalizeKey(string $value): string
    {
        return strtolower(trim($value));
    }
}
