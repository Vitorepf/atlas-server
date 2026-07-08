<?php

namespace App\Services\Ai\SelfConstruction\ControlPlane;

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

        // Default facts where no per-adapter outcome/evidence is given: all families
        // recognised, zero give_back, zero age, fallback=available for known adapters.
        $defaultFacts = [
            'self_declared'           => false,
            'evidence_age_days'       => 0,
            'max_evidence_age_days'   => self::DEFAULT_MAX_EVIDENCE_AGE_DAYS,
            'give_back_rate'          => 0.0,
            'capability_families'     => ['implementation', 'planning', 'review', 'scouting', 'documentation'],
        ];

        $enriched = [];
        foreach ($descriptors as $d) {
            $provider = (string) ($d['provider'] ?? '');
            $cap = $this->computeCapabilityScore(
                $defaultFacts['capability_families'],
                $defaultFacts['give_back_rate'],
                $defaultFacts['evidence_age_days'],
                $defaultFacts['max_evidence_age_days'],
                $provider,
            );
            $d['capability_score']   = $cap['capability_score'];
            $d['capability_verdict'] = $cap['capability_verdict'];
            $d['fallback_available'] = $cap['fallback_available'];
            $enriched[] = $d;
        }

        return [
            'status' => 'provider_adapter_registry_ready',
            'registry_id' => 'AGENT-PROVIDER-ADAPTER-REGISTRY-SELF-CONSTRUCTION-0001',
            'provider_count' => count($descriptors),
            'providers' => $enriched,
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

    /**
     * Compute a capability_score (0.0–1.0) and capability_verdict for an adapter
     * from supported task families, evidence freshness, give_back rate, and
     * fallback availability.
     *
     * @param  list<string>  $supportedFamilies
     * @return array{capability_score:float, capability_verdict:string, fallback_available:bool}
     */
    private function computeCapabilityScore(
        array $supportedFamilies,
        float $giveBackRate,
        int $evidenceAgeDays,
        int $maxEvidenceAgeDays,
        string $provider,
    ): array {
        $familyFactor = min(1.0, count($supportedFamilies) / 5.0);
        $freshnessFactor = $evidenceAgeDays > $maxEvidenceAgeDays ? 0.0 : 1.0 - ($evidenceAgeDays / max(1, $maxEvidenceAgeDays));
        $giveBackFactor = 1.0 - min(1.0, $giveBackRate);

        $score = round($familyFactor * 0.30 + $freshnessFactor * 0.35 + $giveBackFactor * 0.35, 4);

        $verdict = match (true) {
            $score >= 0.75 => 'capable',
            $score >= 0.50 => 'marginal',
            default        => 'incapable',
        };

        $fallbackAvailable = in_array($provider, ['codex', 'claude', 'gemini', 'local', 'http'], true);

        return [
            'capability_score'   => $score,
            'capability_verdict' => $verdict,
            'fallback_available' => $fallbackAvailable,
        ];
    }

    private const DEFAULT_MAX_EVIDENCE_AGE_DAYS = 14;

    private const HIGH_GIVE_BACK_RATE_CEILING = 0.50;

    private const MIN_OUTCOME_SAMPLE_FOR_VERDICT = 3;

    /**
     * Evaluates a provider adapter's REAL capability per task family, from
     * observed outcomes — never from its static descriptor name or an
     * assumed model strength tier.
     *
     * Globally fails closed (every family blocked, routing_hint forced to
     * caution) when capability_facts.self_declared=true with no outcome
     * evidence, or evidence_age_days exceeds max_evidence_age_days — a
     * provider cannot certify itself, and old evidence may be wrong now.
     *
     * Per-family blockers (any one excludes the family):
     *   missing_capability:<name>
     *   high_give_back_rate (enough samples, give_back_rate over ceiling)
     *   known_failure_mode
     *
     * safe_routing_hint:
     *   route_with_caution_collect_evidence — global evidence failure
     *   avoid_routing                       — no family allowed
     *   route_only_to_allowed_families       — some allowed, some blocked
     *   route_freely                        — all evaluated families allowed
     *
     * @param  array<string, mixed>  $capabilityFacts
     * @return array<string, mixed>
     */
    public function evaluateAdapterCapability(string $provider, array $capabilityFacts): array
    {
        $provider = $this->normalizeKey($provider);
        $capabilities = array_map('strval', (array) ($capabilityFacts['capabilities'] ?? []));
        $taskFamilies = (array) ($capabilityFacts['task_families'] ?? []);
        $selfDeclared = (bool) ($capabilityFacts['self_declared'] ?? false);
        $evidenceAgeDays = (int) ($capabilityFacts['evidence_age_days'] ?? 0);
        $maxEvidenceAgeDays = (int) ($capabilityFacts['max_evidence_age_days'] ?? self::DEFAULT_MAX_EVIDENCE_AGE_DAYS);
        $recentOutcomes = (array) ($capabilityFacts['recent_outcomes'] ?? []);
        $knownFailureModes = array_map('strval', (array) ($capabilityFacts['known_failure_modes'] ?? []));

        $evidenceStale = $evidenceAgeDays > $maxEvidenceAgeDays;
        $globalEvidenceFailure = $selfDeclared || $evidenceStale;

        $allowed = [];
        $blocked = [];

        foreach ($taskFamilies as $familyFacts) {
            if (! is_array($familyFacts) || ! isset($familyFacts['family'])) {
                continue;
            }
            $family = (string) $familyFacts['family'];
            $requiredCapabilities = array_map('strval', (array) ($familyFacts['required_capabilities'] ?? []));

            $reasons = [];
            if ($selfDeclared) {
                $reasons[] = 'self_declared_evidence_not_verified';
            }
            if ($evidenceStale) {
                $reasons[] = sprintf('stale_evidence_age_days_%d_exceeds_max_%d', $evidenceAgeDays, $maxEvidenceAgeDays);
            }

            foreach (array_diff($requiredCapabilities, $capabilities) as $missing) {
                $reasons[] = "missing_capability:{$missing}";
            }

            $familyOutcomes = array_values(array_filter(
                $recentOutcomes,
                static fn ($o): bool => is_array($o) && (string) ($o['family'] ?? '') === $family,
            ));
            $sampleSize = count($familyOutcomes);
            $giveBackCount = count(array_filter(
                $familyOutcomes,
                static fn (array $o): bool => (string) ($o['outcome'] ?? '') === 'give_back',
            ));
            $giveBackRate = $sampleSize > 0 ? round($giveBackCount / $sampleSize, 4) : 0.0;
            if ($sampleSize >= self::MIN_OUTCOME_SAMPLE_FOR_VERDICT && $giveBackRate > self::HIGH_GIVE_BACK_RATE_CEILING) {
                $reasons[] = 'high_give_back_rate';
            }

            if (in_array($family, $knownFailureModes, true)) {
                $reasons[] = 'known_failure_mode';
            }

            if ($reasons === []) {
                $allowed[] = $family;
            } else {
                $blocked[] = ['family' => $family, 'reasons' => $reasons];
            }
        }

        $safeRoutingHint = match (true) {
            $globalEvidenceFailure => 'route_with_caution_collect_evidence',
            $allowed === [] => 'avoid_routing',
            $blocked !== [] => 'route_only_to_allowed_families',
            default => 'route_freely',
        };

        return [
            'provider' => $provider,
            'capabilities' => $capabilities,
            'evidence_freshness' => [
                'age_days' => $evidenceAgeDays,
                'max_age_days' => $maxEvidenceAgeDays,
                'is_stale' => $evidenceStale,
                'self_declared' => $selfDeclared,
            ],
            'global_evidence_failure' => $globalEvidenceFailure,
            'safe_task_families' => $allowed,
            'blocked_task_families' => $blocked,
            'safe_routing_hint' => $safeRoutingHint,
            'external_process_start_enabled' => false,
            'token_spend_allowed' => false,
        ];
    }
}
