<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness;

use App\Services\Ai\SelfConstruction\ReadinessHash;

/**
 * Builds the certification-workbench quartet payload (contract / preflight /
 * implementation_packet) for any Agent Control Plane certification surface.
 *
 * Extracted from AtlasSelfConstructionReadinessService to reduce the god-class.
 * The only dependency is ReadinessHash::stable() — no instance state, no
 * constructor arguments, fully deterministic.
 */
final class ReadinessCertificationWorkbenchQuartetBuilder
{
    public const READINESS_QUARTET_SCHEMA = 'atlas.self_construction.readiness_certification_workbench_quartet.v1';

    public const STATUS_PASS = 'pass';

    public const STATUS_BLOCKER = 'blocker';

    public const STATUS_MISSING = 'missing';

    /** @var list<string> */
    private const QUARTET_SECTIONS = ['context_check', 'queue_check', 'proof_check', 'worker_execution_check'];

    /**
     * Assembles the four MINIMAL proof surfaces needed for an autonomous engineering decision —
     * code, queue, evidence, and knowledge-sync readiness — and never more. Each section is
     * independently evaluated pass/blocker/missing; a missing or failing section always names a
     * repair_action and a source_expectation instead of silently defaulting to ready.
     *
     * Input facts shape (each key optional; an absent section is reported missing):
     *   code:           {tests_green?: bool, lint_clean?: bool}
     *   queue:          {claimable_depth?: int, give_back_rate?: float}
     *   evidence:       {receipts_present?: bool, receipts_verified?: bool}
     *   knowledge_sync: {docs_synced?: bool, code_index_fresh?: bool}
     *
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public static function buildReadinessQuartet(array $facts): array
    {
        $sections = [];
        foreach (self::QUARTET_SECTIONS as $sectionKey) {
            $sections[$sectionKey] = self::evaluateSection($sectionKey, is_array($facts[$sectionKey] ?? null) ? $facts[$sectionKey] : null);
        }

        $failedChecks = array_values(array_filter(
            self::QUARTET_SECTIONS,
            static fn (string $key): bool => $sections[$key]['status'] !== self::STATUS_PASS,
        ));
        $quartetReady = $failedChecks === [];

        $payload = [
            'schema_version' => self::READINESS_QUARTET_SCHEMA,
            'quartet_ready' => $quartetReady,
            'sections' => $sections,
            'blocking_sections' => $failedChecks,
            'failed_checks' => $failedChecks,
            'overall_status' => $quartetReady ? 'ready' : 'blocked',
        ];
        $payload['quartet_hash'] = ReadinessHash::stable($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>|null  $sectionFacts
     * @return array<string,mixed>
     */
    private static function evaluateSection(string $sectionKey, ?array $sectionFacts): array
    {
        [$repairAction, $sourceExpectation, $evidenceRefs] = self::sectionExpectations($sectionKey);

        if ($sectionFacts === null) {
            return [
                'section' => $sectionKey,
                'status' => self::STATUS_MISSING,
                'repair_action' => $repairAction,
                'source_expectation' => $sourceExpectation,
                'evidence_refs' => $evidenceRefs,
                'checked_facts' => [],
            ];
        }

        $pass = self::sectionPasses($sectionKey, $sectionFacts);

        return [
            'section' => $sectionKey,
            'status' => $pass ? self::STATUS_PASS : self::STATUS_BLOCKER,
            'repair_action' => $pass ? null : $repairAction,
            'source_expectation' => $pass ? null : $sourceExpectation,
            'evidence_refs' => $evidenceRefs,
            'checked_facts' => $sectionFacts,
        ];
    }

    /**
     * @param  array<string,mixed>  $sectionFacts
     */
    private static function sectionPasses(string $sectionKey, array $sectionFacts): bool
    {
        return match ($sectionKey) {
            'context_check' => (bool) ($sectionFacts['tests_green'] ?? false) && (bool) ($sectionFacts['lint_clean'] ?? false),
            'queue_check' => (int) ($sectionFacts['claimable_depth'] ?? 0) > 0 && (float) ($sectionFacts['give_back_rate'] ?? 1.0) < 0.3,
            'proof_check' => (bool) ($sectionFacts['receipts_present'] ?? false) && (bool) ($sectionFacts['receipts_verified'] ?? false),
            'worker_execution_check' => (bool) ($sectionFacts['docs_synced'] ?? false) && (bool) ($sectionFacts['code_index_fresh'] ?? false),
            default => false,
        };
    }

    /** @return array{0:string,1:string,2:list<string>} */
    private static function sectionExpectations(string $sectionKey): array
    {
        return match ($sectionKey) {
            'context_check' => ['run_tests_and_lint_then_fix_failures', 'php_artisan_test_and_lint_tool_output', ['code_coverage_report', 'lint_results']],
            'queue_check' => ['repair_or_replenish_the_task_queue', 'atlas_task_health_histogram_snapshot', ['task_queue_histogram', 'give_back_rate_metric']],
            'proof_check' => ['generate_and_verify_evidence_receipts', 'evidence_ledger_receipts', ['receipt_verification_log', 'evidence_ledger_status']],
            'worker_execution_check' => ['run_engineering_knowledge_sync_and_index_code_with_prune', 'knowledge_sync_command_output', ['knowledge_sync_timestamp', 'code_index_age']],
            default => ['unknown_section', 'unknown_source', []],
        };
    }

    /**
     * @param  string  $keyPrefix
     * @param  string  $label
     * @param  string  $schemaVersion
     * @param  string  $serviceClass
     * @param  string  $stage  contract|preflight|implementation_packet
     * @return array<string, mixed>
     */
    public static function build(string $keyPrefix, string $label, string $schemaVersion, string $serviceClass, string $stage): array
    {
        $stageReady = "agent_control_plane_{$keyPrefix}_{$stage}_ready";
        $stageUpper = strtoupper(str_replace('_', '-', $keyPrefix));
        $stageContract = strtoupper($stage);
        $payload = [
            'status' => $stage === 'implementation_packet'
                ? "ready_for_scoped_agent_control_plane_{$keyPrefix}_implementation"
                : $stageReady,
            'stage' => $stage,
            'workbench_layer' => 'certification',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'schema_version' => $schemaVersion,
            'service_class' => $serviceClass,
            'invariants' => [
                "{$keyPrefix}_is_read_only" => true,
                "{$keyPrefix}_does_not_advance_pointer" => true,
                "{$keyPrefix}_does_not_call_codex_cli_or_app" => true,
                "{$keyPrefix}_does_not_start_codex" => true,
                "{$keyPrefix}_does_not_invoke_adapter" => true,
                "{$keyPrefix}_does_not_execute_adapter" => true,
                "{$keyPrefix}_does_not_call_provider" => true,
                "{$keyPrefix}_does_not_dispatch_work" => true,
                "{$keyPrefix}_does_not_spend_tokens" => true,
                "{$keyPrefix}_does_not_enable_self_programming" => true,
                "{$keyPrefix}_does_not_write_ledger" => true,
                "{$keyPrefix}_does_not_promote_completion_claim" => true,
            ],
            'forbidden_even_after' => [
                'mutate_agent_control_plane',
                'advance_next_required_slice',
                'invoke_codex',
                'dispatch_work',
                'spawn_subprocess',
                'enable_self_programming',
                'declare_atlas_self_construction_os_complete',
                'promote_completion_claim',
            ],
            'next_required_slice' => $stage === 'contract'
                ? "activate_agent_control_plane_{$keyPrefix}_preflight"
                : ($stage === 'preflight'
                    ? "activate_agent_control_plane_{$keyPrefix}_implementation_packet"
                    : ($stage === 'implementation_packet'
                        ? "activate_agent_control_plane_{$keyPrefix}_service"
                        : '')),
            'identifier' => "AGENT-CONTROL-PLANE-{$stageUpper}-{$stageContract}-SELF-CONSTRUCTION-0001",
        ];

        if ($stage === 'preflight') {
            $payload['preflight_checks'] = [
                'service_class_exists' => class_exists($serviceClass),
            ];
            $payload['blocking_count'] = $payload['preflight_checks']['service_class_exists'] ? 0 : 1;
            $payload['blocking_reasons'] = $payload['preflight_checks']['service_class_exists'] ? [] : ['service_class_missing'];
        } elseif ($stage === 'implementation_packet') {
            $payload['allowed_files'] = [
                'app/Services/Ai/SelfConstruction/'.class_basename($serviceClass).'.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ];
            $payload['acceptance_criteria'] = [
                "{$keyPrefix}_returns_schema_v1",
                "{$keyPrefix}_is_read_only",
                "{$keyPrefix}_emits_stable_hash",
                "{$keyPrefix}_does_not_advance_pointer",
                "{$keyPrefix}_does_not_write_ledger",
                "{$keyPrefix}_does_not_start_runtime",
                'readiness_service_exposes_quartet_methods',
                'cli_exposes_quartet_options',
                'capabilities_registered_in_agent_control_plane',
                'documentation_describes_workbench_layer',
                'dedicated_test_suite_covers_workbench_layer',
            ];
            $payload['implementation_policy'] = [
                "{$keyPrefix}_is_read_only" => true,
                'pointer_mutation_allowed_by_packet' => false,
                'runtime_flag_mutation_allowed_by_packet' => false,
                'provider_invocation_allowed_by_packet' => false,
                'adapter_invocation_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
                'completion_claim_allowed_by_packet' => false,
            ];
        }

        return [
            'schema_version' => "atlas.self_construction_agent_control_plane_{$keyPrefix}_{$stage}.v1",
            'status' => (string) $payload['status'],
            'mode' => "read_only_agent_control_plane_{$keyPrefix}_{$stage}",
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            "agent_control_plane_{$keyPrefix}_{$stage}" => $payload,
            "agent_control_plane_{$keyPrefix}_{$stage}_hash" => ReadinessHash::stable($payload),
            'non_execution_guarantees' => [
                "agent_control_plane_{$keyPrefix}_{$stage}_does_not_start_codex",
                "agent_control_plane_{$keyPrefix}_{$stage}_does_not_advance_pointer",
                "agent_control_plane_{$keyPrefix}_{$stage}_does_not_dispatch_work",
                "agent_control_plane_{$keyPrefix}_{$stage}_does_not_execute_adapter",
                "agent_control_plane_{$keyPrefix}_{$stage}_does_not_enable_self_programming",
            ],
            'human_summary' => "Agent Control Plane {$label} {$stage} is ready and read-only.",
        ];
    }
}
