<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;

final class AtlasFrontendProviderInstructionPacketService
{
    public const SCHEMA_VERSION = 'atlas.frontend.provider_instruction_packet.v1';

    public const EXECUTION_GUARDRAILS_SCHEMA_VERSION = 'atlas.frontend.provider_execution_guardrails.v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function compile(array $input): array
    {
        $task = trim((string) ($input['task'] ?? ''));
        $workspace = trim((string) ($input['workspace'] ?? ''));
        $surface = AtlasFrontendSurface::fromInput($input);
        $provider = trim((string) ($input['provider'] ?? 'provider_neutral')) ?: 'provider_neutral';

        $gate = app(AtlasFrontendExecutionGateService::class)->evaluate($input + [
            'task' => $task,
            'workspace' => $workspace,
            'surface' => $surface,
        ]);
        $workOrder = app(AtlasFrontendWorkOrderService::class)->compile($input + [
            'task' => $task,
            'workspace' => $workspace,
            'surface' => $surface,
        ]);
        $runbook = app(AtlasFrontendExecutionRunbookService::class)->compile($input + [
            'task' => $task,
            'workspace' => $workspace,
            'surface' => $surface,
        ]);

        $blockers = array_values(array_unique(array_merge(
            $this->prefix('gate', (array) ($gate['blockers'] ?? [])),
            $this->prefix('work_order', (array) ($workOrder['blockers'] ?? [])),
            $this->prefix('runbook', (array) ($runbook['blockers'] ?? [])),
        )));
        $status = $blockers === [] && ($gate['status'] ?? null) === 'passed' && ($workOrder['status'] ?? null) === 'ready' && ($runbook['status'] ?? null) === 'ready'
            ? 'ready'
            : 'blocked';

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'source' => self::class,
            'packet_type' => 'provider_safe_frontend_execution_instruction_packet',
            'target_provider' => $provider,
            'surface' => $surface,
            'task_hash' => $task !== '' ? hash('sha256', $task) : null,
            'workspace_hash' => $workspace !== '' ? hash('sha256', $workspace) : null,
            'frontend_app_scope' => [
                'schema_version' => 'atlas.frontend.provider_instruction_packet.frontend_app_scope.v1',
                'status' => data_get($runbook, 'frontend_app_scope.status', 'repo_root'),
                'relative_name' => data_get($runbook, 'frontend_app_scope.relative_name'),
                'relative_name_hash' => data_get($runbook, 'frontend_app_scope.relative_name_hash'),
                'repo_workspace_remains_primary' => true,
                'raw_absolute_path_returned' => false,
            ],
            'upstream_hash_refs' => [
                'gate_hash' => $gate['hash'] ?? null,
                'work_order_hash' => $workOrder['work_order_hash'] ?? null,
                'runbook_hash' => $runbook['runbook_hash'] ?? null,
            ],
            'provider_mandates' => [
                'read_enterprise_bootstrap_and_work_order_before_editing',
                'preserve_repo_design_system_and_product_intent',
                'make_smallest_product_correct_ui_change_or_prototype',
                'run_repo_native_quality_tests_build_or_record_reason',
                'run_browser_and_static_anti_slop_detectors_or_record_blocker',
                'preserve_selected_repo_as_workspace_and_frontend_app_as_subscope',
                'collect_visual_quality_budget_review_and_evidence_pack',
                'record_outcome_memory_before_handoff_claim',
                'never_claim_world_best_or_done_without_certified_evidence',
            ],
            'forbidden_provider_behaviors' => [
                'generic_landing_page_when_product_context_exists',
                'decorative_ai_slop_without_product_purpose',
                'screenshot_only_completion_claim',
                'raw_customer_source_or_prompt_exfiltration',
                'design_system_drift_without_report',
                'world_best_claim_without_external_replay_receipts',
                'treating_frontend_app_subscope_as_selected_workspace_or_space',
            ],
            'provider_execution_guardrails' => $this->providerExecutionGuardrails($runbook),
            'execution_sequence' => $this->executionSequence($runbook),
            'required_evidence' => array_values(array_unique(array_merge(
                (array) data_get($gate, 'required_evidence', []),
                collect((array) ($workOrder['work_packets'] ?? []))->flatMap(fn (array $packet): array => (array) ($packet['required_evidence'] ?? []))->all(),
                collect((array) ($runbook['runbook_steps'] ?? []))->flatMap(fn (array $step): array => (array) ($step['required_evidence'] ?? []))->all(),
            ))),
            'required_next_actions' => $status === 'ready'
                ? ['dispatch_provider_with_this_packet_and_bind_outputs_to_run_certification']
                : array_values(array_unique(array_merge(
                    (array) ($gate['required_next_actions'] ?? []),
                    (array) ($workOrder['required_next_actions'] ?? []),
                    (array) ($runbook['required_next_actions'] ?? []),
                ))),
            'claim_policy' => [
                'provider_packet_is_not_execution_evidence' => true,
                'read_only_packet_does_not_write_evidence_kit' => ! (bool) ($runbook['write_evidence_kit'] ?? true),
                'provider_must_return_receipts_not_claims' => true,
                'completion_requires_run_certification_handoff_and_outcome' => true,
                'completion_claim_requires_guardrail_receipts' => true,
                'raw_customer_source_returned' => false,
                'world_best_claim_allowed' => false,
            ],
            'blockers' => $blockers,
            'warnings' => array_values(array_unique(array_merge(
                $this->prefix('gate', (array) ($gate['warnings'] ?? [])),
                $this->prefix('work_order', (array) ($workOrder['warnings'] ?? [])),
                $this->prefix('runbook', (array) ($runbook['warnings'] ?? [])),
            ))),
        ];
        $payload['provider_instruction_packet_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $runbook
     * @return array<string,mixed>
     */
    private function providerExecutionGuardrails(array $runbook): array
    {
        $frontendAppScope = (array) data_get($runbook, 'frontend_app_scope', []);

        return [
            'schema_version' => self::EXECUTION_GUARDRAILS_SCHEMA_VERSION,
            'selected_workspace_contract' => [
                'selected_repository_remains_primary_workspace' => true,
                'frontend_app_is_subscope_only' => true,
                'frontend_app_scope_status' => (string) ($frontendAppScope['status'] ?? 'repo_root'),
                'frontend_app_relative_name_hash' => $frontendAppScope['relative_name_hash'] ?? null,
                'space_runtime_required' => false,
                'raw_absolute_path_returned' => false,
            ],
            'mandatory_runtime_receipts' => [
                'pre_execution_gate_hash',
                'work_order_hash',
                'runbook_hash',
                'visual_quality_report',
                'quality_budget_report',
                'design_review_report',
                'evidence_pack_hash',
                'run_certification_hash',
                'outcome_memory_hash',
                'handoff_hash',
            ],
            'mandatory_detector_receipts' => [
                'atlas_frontend_static_anti_slop_detector',
                'atlas_frontend_browser_detector_event',
                'design_system_drift_gate',
            ],
            'forbidden_claims_until_receipts_exist' => [
                'delivery_done',
                'premium_refinement_done',
                'public_distribution',
                'best_in_market',
                'world_best_frontend_system',
            ],
            'world_best_claim_gate' => [
                'requires_verified_external_rival_replay' => true,
                'requires_decisive_lead_each_complete_case' => true,
                'minimum_decisive_lead_points' => AtlasFrontendRivalReplayHarnessService::DECISIVE_LEAD_MINIMUM_POINTS,
                'requires_no_tied_cases' => true,
                'requires_no_dimension_gaps_against_best_rival' => true,
                'requires_verified_publication_receipt' => true,
            ],
            'provider_return_contract' => [
                'return_receipt_refs_not_raw_private_source' => true,
                'return_changed_files_summary' => true,
                'return_tests_and_build_receipts_or_blocker_reasons' => true,
                'return_detector_findings_and_repairs' => true,
                'return_known_limitations' => true,
                'return_outcome_memory_payload' => true,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $runbook
     * @return array<int,array<string,mixed>>
     */
    private function executionSequence(array $runbook): array
    {
        return collect((array) ($runbook['runbook_steps'] ?? []))
            ->map(fn (array $step): array => [
                'step_id' => (string) ($step['id'] ?? 'unknown'),
                'sequence' => (int) ($step['sequence'] ?? 0),
                'objective' => (string) ($step['objective'] ?? ''),
                'commands' => array_values(array_filter((array) ($step['commands'] ?? []), 'is_string')),
                'required_evidence' => array_values(array_filter((array) ($step['required_evidence'] ?? []), 'is_string')),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int,mixed>  $items
     * @return array<int,string>
     */
    private function prefix(string $prefix, array $items): array
    {
        return collect($items)
            ->filter(fn (mixed $item): bool => is_string($item))
            ->map(fn (string $item): string => $prefix.'_'.$item)
            ->values()
            ->all();
    }
}
