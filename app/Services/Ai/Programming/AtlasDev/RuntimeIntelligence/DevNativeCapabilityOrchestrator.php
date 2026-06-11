<?php

namespace App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevStringListNormalizer;

class DevNativeCapabilityOrchestrator
{
    public const SCHEMA_VERSION = 'atlas.dev.native_capabilities.v1';

    /** @var list<string> */
    public const DECISION_KINDS = [
        'test_impact',
        'senior_review',
        'delegation_route',
        'prompt_projection_guard',
        'scope_guard',
        'simulation',
        'completion_gate',
        'provider_capacity_memory',
        'forge_escalation_policy',
        'decision_materialization',
    ];

    /**
     * @param  array<string,mixed>  $packet
     * @param  array<string,mixed>  $signals
     * @return array<string,mixed>
     */
    public function evaluate(array $packet, array $signals = []): array
    {
        $contextGate = $signals['context_gate'] ?? [];
        $outcome = is_array($signals['outcome'] ?? null) ? $signals['outcome'] : [];
        $failure = is_array($signals['failure'] ?? null) ? $signals['failure'] : [];
        $changedFiles = AtlasDevStringListNormalizer::uniqueTrimmedScalarValues($signals['changed_files'] ?? $outcome['changed_files'] ?? $failure['changed_files'] ?? []);

        $decisions = [
            'test_impact' => $this->testImpact($packet),
            'senior_review' => $this->seniorReview($packet, $signals, $changedFiles),
            'delegation_route' => $this->delegationRoute($packet, $signals, $contextGate),
            'prompt_projection_guard' => $this->promptProjectionGuard($packet, $contextGate),
            'scope_guard' => $this->scopeGuard($packet, $changedFiles),
            'simulation' => $this->simulation($packet, $changedFiles),
            'completion_gate' => $this->completionGate($packet, $signals, $changedFiles),
            'provider_capacity_memory' => $this->providerCapacityMemory($packet, $signals, $outcome, $failure),
            'forge_escalation_policy' => $this->forgeEscalationPolicy($packet, $signals, $contextGate),
        ];

        $decisions['decision_materialization'] = [
            'schema_version' => 'atlas.dev.decision_materialization_plan.v1',
            'status' => 'ready',
            'decision_kinds' => array_keys($decisions),
            'required_table' => 'atlas_dev_decision_materializations',
            'reason' => 'Every important Dev decision must be replayable without chat context.',
        ];

        $blockers = [];
        foreach ($decisions as $kind => $decision) {
            if (in_array((string) ($decision['status'] ?? ''), ['blocked', 'needs_review'], true)) {
                $blockers[] = [
                    'kind' => $kind,
                    'status' => $decision['status'],
                    'reason' => $decision['reason'] ?? $decision['summary'] ?? 'Dev native decision needs attention',
                ];
            }
        }

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'run_id' => $packet['run_id'] ?? null,
            'task_id' => $packet['task_id'] ?? null,
            'status' => $blockers === [] ? 'ready' : 'needs_review',
            'decisions' => $decisions,
            'blockers' => $blockers,
            'coverage' => [
                'total_blocks' => 15,
                'base_blocks' => [
                    'task_packet',
                    'context_gate',
                    'failure_capsule',
                    'outcome_memory',
                    'run_certification',
                ],
                'decision_blocks' => array_keys($decisions),
            ],
        ];
        $payload['native_capabilities_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $packet
     * @return array<string,mixed>
     */
    private function testImpact(array $packet): array
    {
        $focused = AtlasDevStringListNormalizer::uniqueTrimmedScalarValues($packet['suggested_tests'] ?? []);
        $risk = (string) ($packet['risk_band'] ?? 'medium');
        $fallback = $risk === 'critical' || $risk === 'high'
            ? ['php artisan test --filter=AtlasDev|Programming|RouterRuntime']
            : ['php artisan test tests/Feature/Ai/Programming/AtlasDev/AtlasDevRuntimeIntelligenceTest.php'];

        return [
            'schema_version' => 'atlas.dev.test_impact.v1',
            'status' => $focused === [] && ! in_array((string) ($packet['task_class'] ?? ''), ['read_only', 'trivial'], true) ? 'needs_review' : 'ready',
            'focused_tests' => $focused,
            'fallback_tests' => $fallback,
            'skip_reason' => $focused === [] && in_array((string) ($packet['task_class'] ?? ''), ['read_only', 'trivial'], true)
                ? 'read_only_or_trivial_task'
                : null,
            'reason' => $focused === [] ? 'No focused test declared by packet.' : 'Focused tests declared by packet.',
        ];
    }

    /**
     * @param  array<string,mixed>  $packet
     * @param  array<string,mixed>  $signals
     * @param  list<string>  $changedFiles
     * @return array<string,mixed>
     */
    private function seniorReview(array $packet, array $signals, array $changedFiles): array
    {
        $risk = (string) ($packet['risk_band'] ?? 'medium');
        $sensitive = $this->sensitiveReasons($packet, $changedFiles);
        $required = in_array($risk, ['high', 'critical'], true) || $sensitive !== [];
        $hasEvidence = AtlasDevStringListNormalizer::uniqueTrimmedScalarValues($signals['senior_review_evidence'] ?? []) !== [];

        return [
            'schema_version' => 'atlas.dev.senior_review.v1',
            'status' => ! $required ? 'not_required' : ($hasEvidence ? 'ready' : 'needs_review'),
            'required' => $required,
            'sensitive_reasons' => $sensitive,
            'evidence_refs' => AtlasDevStringListNormalizer::uniqueTrimmedScalarValues($signals['senior_review_evidence'] ?? []),
            'reason' => $required ? 'Sensitive or high-risk Dev task requires senior review evidence.' : 'Risk does not require senior review.',
        ];
    }

    /**
     * @param  array<string,mixed>  $packet
     * @param  array<string,mixed>  $signals
     * @param  array<string,mixed>  $contextGate
     * @return array<string,mixed>
     */
    private function delegationRoute(array $packet, array $signals, array $contextGate): array
    {
        $expectedFiles = AtlasDevStringListNormalizer::uniqueTrimmedScalarValues($packet['expected_files'] ?? []);
        $risk = (string) ($packet['risk_band'] ?? 'medium');
        $repeatFailures = (int) ($signals['repeat_failures'] ?? 0);
        $contextBlocked = ($contextGate['provider_safe'] ?? true) === false;

        $route = 'local';
        $reason = 'Task is narrow enough for Atlas Dev local execution.';
        if ($repeatFailures >= 2 || count($expectedFiles) > 6 || $risk === 'critical') {
            $route = 'forge_escalation';
            $reason = 'Task exceeds Dev short-run envelope.';
        } elseif ($contextBlocked) {
            $route = 'explorer_subagent';
            $reason = 'Context is incomplete; exploration should happen before provider execution.';
        } elseif (count($expectedFiles) > 3 || $risk === 'high') {
            $route = 'worker_subagent';
            $reason = 'Task benefits from bounded worker delegation or split implementation.';
        }

        return [
            'schema_version' => 'atlas.dev.delegation_route.v1',
            'status' => 'ready',
            'route' => $route,
            'allowed_routes' => ['local', 'explorer_subagent', 'worker_subagent', 'forge_escalation'],
            'reason' => $reason,
        ];
    }

    /**
     * @param  array<string,mixed>  $packet
     * @param  array<string,mixed>  $contextGate
     * @return array<string,mixed>
     */
    private function promptProjectionGuard(array $packet, array $contextGate): array
    {
        $providerSafe = (bool) ($contextGate['provider_safe'] ?? false);
        $hasScope = AtlasDevStringListNormalizer::uniqueTrimmedScalarValues($packet['allowed_files'] ?? []) !== [] || AtlasDevStringListNormalizer::uniqueTrimmedScalarValues($packet['expected_files'] ?? []) !== [];
        $hasDone = AtlasDevStringListNormalizer::uniqueTrimmedScalarValues($packet['acceptance_criteria'] ?? []) !== [];

        return [
            'schema_version' => 'atlas.dev.prompt_projection_guard.v1',
            'status' => $providerSafe && $hasScope && $hasDone ? 'ready' : 'blocked',
            'provider_safe' => $providerSafe,
            'redaction_required' => true,
            'scope_required' => true,
            'allowed_files' => AtlasDevStringListNormalizer::uniqueTrimmedScalarValues($packet['allowed_files'] ?? []),
            'forbidden_files' => AtlasDevStringListNormalizer::uniqueTrimmedScalarValues($packet['forbidden_files'] ?? []),
            'non_goals' => ['Do not expand scope beyond the DevTaskPacket.', 'Do not modify Forge unless escalation explicitly requires it.'],
            'done_criteria' => AtlasDevStringListNormalizer::uniqueTrimmedScalarValues($packet['acceptance_criteria'] ?? []),
            'reason' => 'Provider/subagent prompt must be scoped, redacted and evidence-driven.',
        ];
    }

    /**
     * @param  array<string,mixed>  $packet
     * @param  list<string>  $changedFiles
     * @return array<string,mixed>
     */
    private function scopeGuard(array $packet, array $changedFiles): array
    {
        $allowed = AtlasDevStringListNormalizer::uniqueTrimmedScalarValues($packet['allowed_files'] ?? []);
        $expected = AtlasDevStringListNormalizer::uniqueTrimmedScalarValues($packet['expected_files'] ?? []);
        $forbidden = AtlasDevStringListNormalizer::uniqueTrimmedScalarValues($packet['forbidden_files'] ?? []);
        $permitted = AtlasDevStringListNormalizer::uniqueMergedStrings($allowed, $expected);
        $violations = [];

        foreach ($changedFiles as $file) {
            if ($this->matchesAny($file, $forbidden)) {
                $violations[] = ['file' => $file, 'reason' => 'forbidden_file_match'];
            } elseif ($permitted !== [] && ! $this->matchesAny($file, $permitted)) {
                $violations[] = ['file' => $file, 'reason' => 'outside_declared_scope'];
            }
        }

        return [
            'schema_version' => 'atlas.dev.scope_guard.v1',
            'status' => $violations === [] ? 'ready' : 'blocked',
            'allowed_files' => $allowed,
            'expected_files' => $expected,
            'forbidden_files' => $forbidden,
            'changed_files' => $changedFiles,
            'violations' => $violations,
            'reason' => $violations === [] ? 'Changed files remain within task scope.' : 'Changed files violate task scope.',
        ];
    }

    /**
     * @param  array<string,mixed>  $packet
     * @param  list<string>  $changedFiles
     * @return array<string,mixed>
     */
    private function simulation(array $packet, array $changedFiles): array
    {
        $expected = AtlasDevStringListNormalizer::uniqueTrimmedScalarValues($packet['expected_files'] ?? []);
        $likelyFiles = AtlasDevStringListNormalizer::uniqueMergedStrings($expected, $changedFiles);
        $risk = (string) ($packet['risk_band'] ?? 'medium');
        $docs = array_values(array_filter($likelyFiles, static fn (string $file): bool => str_starts_with($file, 'docs/')));

        return [
            'schema_version' => 'atlas.dev.pre_execution_simulation.v1',
            'status' => 'ready',
            'impact' => [
                'risk_band' => $risk,
                'likely_file_count' => count($likelyFiles),
                'requires_docs_update' => $docs !== [],
            ],
            'likely_files' => $likelyFiles,
            'affected_tests' => AtlasDevStringListNormalizer::uniqueTrimmedScalarValues($packet['suggested_tests'] ?? []),
            'conflict_risk' => count($likelyFiles) > 6 || in_array($risk, ['high', 'critical'], true) ? 'elevated' : 'normal',
            'docs_to_update' => $docs,
            'reason' => 'Pre-execution simulation predicts files, tests, docs and conflict risk.',
        ];
    }

    /**
     * @param  array<string,mixed>  $packet
     * @param  array<string,mixed>  $signals
     * @param  list<string>  $changedFiles
     * @return array<string,mixed>
     */
    private function completionGate(array $packet, array $signals, array $changedFiles): array
    {
        $outcome = is_array($signals['outcome'] ?? null) ? $signals['outcome'] : [];
        $evidence = AtlasDevStringListNormalizer::uniqueTrimmedScalarValues($outcome['evidence_kinds'] ?? $signals['evidence_kinds'] ?? []);
        $tests = AtlasDevStringListNormalizer::uniqueTrimmedScalarValues($outcome['selected_tests'] ?? $packet['suggested_tests'] ?? []);
        $diffClean = (bool) ($signals['diff_clean'] ?? false);
        $scopeStatus = (string) ($signals['scope_status'] ?? 'unknown');
        $outcomeStatus = (string) ($outcome['outcome_status'] ?? 'unknown');

        $ready = $outcomeStatus === 'success'
            && $evidence !== []
            && ($tests !== [] || in_array((string) ($packet['task_class'] ?? ''), ['read_only', 'trivial'], true))
            && ($scopeStatus === 'ready' || $changedFiles === [])
            && $diffClean;

        return [
            'schema_version' => 'atlas.dev.completion_gate.v1',
            'status' => $ready ? 'ready' : 'needs_review',
            'diff_clean' => $diffClean,
            'tests_declared_or_skip' => $tests !== [] || in_array((string) ($packet['task_class'] ?? ''), ['read_only', 'trivial'], true),
            'evidence_present' => $evidence !== [],
            'scope_passed' => $scopeStatus === 'ready' || $changedFiles === [],
            'outcome_memory_present' => $outcome !== [],
            'reason' => $ready ? 'Run has minimum completion proof.' : 'Completion proof is incomplete or still needs review.',
        ];
    }

    /**
     * @param  array<string,mixed>  $packet
     * @param  array<string,mixed>  $signals
     * @param  array<string,mixed>  $outcome
     * @param  array<string,mixed>  $failure
     * @return array<string,mixed>
     */
    private function providerCapacityMemory(array $packet, array $signals, array $outcome, array $failure): array
    {
        return [
            'schema_version' => 'atlas.dev.provider_capacity_memory.v1',
            'status' => 'ready',
            'provider' => $signals['provider'] ?? null,
            'model' => $signals['model'] ?? null,
            'task_class' => $packet['task_class'] ?? null,
            'risk_band' => $packet['risk_band'] ?? null,
            'outcome_status' => $outcome['outcome_status'] ?? 'unknown',
            'failure_class' => $failure['failure_class'] ?? null,
            'evidence_based' => $outcome !== [] || $failure !== [],
            'routing_override_allowed' => false,
            'memory_action' => 'record_observation_only',
            'reason' => 'Provider learning records observed outcomes but cannot override routing without evidence.',
        ];
    }

    /**
     * @param  array<string,mixed>  $packet
     * @param  array<string,mixed>  $signals
     * @param  array<string,mixed>  $contextGate
     * @return array<string,mixed>
     */
    private function forgeEscalationPolicy(array $packet, array $signals, array $contextGate): array
    {
        $expected = AtlasDevStringListNormalizer::uniqueTrimmedScalarValues($packet['expected_files'] ?? []);
        $risk = (string) ($packet['risk_band'] ?? 'medium');
        $domains = AtlasDevStringListNormalizer::uniqueTrimmedScalarValues($signals['domains'] ?? []);
        $reasons = [];

        if (($contextGate['provider_safe'] ?? true) === false) {
            $reasons[] = 'context_gate_not_provider_safe';
        }
        if (count($expected) > 6) {
            $reasons[] = 'too_many_files_for_dev';
        }
        if (count($domains) > 1) {
            $reasons[] = 'multiple_domains';
        }
        if ((int) ($signals['repeat_failures'] ?? 0) >= 2) {
            $reasons[] = 'repeated_failures';
        }
        if (in_array($risk, ['critical'], true)) {
            $reasons[] = 'critical_risk';
        }

        return [
            'schema_version' => 'atlas.dev.forge_escalation_policy.v1',
            'status' => 'ready',
            'escalate' => $reasons !== [],
            'reasons' => $reasons,
            'escalation_packet_required' => $reasons !== [],
            'reason' => $reasons === [] ? 'Dev can continue locally.' : 'Dev should escalate to Forge rather than improvise a long Obra.',
        ];
    }

    /**
     * @param  array<string,mixed>  $packet
     * @param  list<string>  $changedFiles
     * @return list<string>
     */
    private function sensitiveReasons(array $packet, array $changedFiles): array
    {
        $haystack = strtolower(implode("\n", array_merge(
            $changedFiles,
            AtlasDevStringListNormalizer::uniqueTrimmedScalarValues($packet['expected_files'] ?? []),
            AtlasDevStringListNormalizer::uniqueTrimmedScalarValues($packet['context_refs'] ?? []),
            [(string) ($packet['objective'] ?? '')],
        )));

        $needles = [
            'auth' => 'auth',
            'migration' => 'migration',
            'payment' => 'payment',
            'provider' => 'provider',
            'docs/engineering-knowledge-base' => 'docs_canon',
            'cartografia' => 'cartography',
            'runtime' => 'runtime_critical',
            'security' => 'security',
            'database' => 'data',
        ];

        $reasons = [];
        foreach ($needles as $needle => $reason) {
            if (str_contains($haystack, $needle)) {
                $reasons[] = $reason;
            }
        }

        return AtlasDevStringListNormalizer::uniqueMergedStrings($reasons);
    }

    /**
     * @param  list<string>  $patterns
     */
    private function matchesAny(string $file, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern !== '' && (str_starts_with($file, $pattern) || str_contains($file, $pattern))) {
                return true;
            }
        }

        return false;
    }
}
