<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

final class AtlasSelfConstructionRealProviderSmokeOperatorRunbookExporterService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.real_provider_smoke_operator_runbook_exporter.v1';

    public const MODE = 'read_only_real_provider_smoke_operator_runbook_exporter';

    private const STORAGE_DISK = 'local';

    private const STORAGE_PREFIX = 'atlas/self-construction/os-completion/real-provider-smoke-runbooks';

    /**
     * @param  array{persist_export?: bool}  $options
     * @return array<string, mixed>
     */
    public function build(array $options = []): array
    {
        $phases = [
            $this->beforeExecution(),
            $this->duringExecution(),
            $this->afterExecution(),
            $this->payloadSubmission(),
            $this->persistence(),
            $this->auditRerun(),
        ];

        $stopConditions = $this->stopConditions();
        $markdown = $this->renderMarkdown($phases, $stopConditions);
        $machineJson = $this->renderMachineJson($phases, $stopConditions);

        $persistExport = (bool) ($options['persist_export'] ?? false);
        $exportPath = '';
        $exported = false;
        if ($persistExport) {
            $exportHash = hash('sha256', $markdown);
            $exportPath = self::STORAGE_PREFIX.'/'.$exportHash.'.md';
            Storage::disk(self::STORAGE_DISK)->put($exportPath, $markdown);
            $exported = true;
        }

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => 'available',
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'phases' => $phases,
            'phase_ids' => array_map(static fn (array $phase): string => (string) $phase['id'], $phases),
            'stop_conditions' => $stopConditions,
            'markdown' => $markdown,
            'machine_json' => $machineJson,
            'persist_export_requested' => $persistExport,
            'export_persisted' => $exported,
            'export_path' => $exportPath,
            'evidence_persistence_allowed_here' => false,
            'non_execution_guarantees' => [
                'does_not_call_provider' => true,
                'does_not_spend_tokens' => true,
                'does_not_dispatch' => true,
                'does_not_persist_real_provider_smoke_evidence' => true,
                'does_not_promote_completion' => true,
                'does_not_sign_for_operator' => true,
                'persists_only_runbook_artifact_when_flag_is_true' => true,
            ],
        ];
        $payload['exporter_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /** @return array<string, mixed> */
    private function beforeExecution(): array
    {
        return [
            'id' => 'before_execution',
            'summary' => 'Capture explicit operator approval and scope before any provider call.',
            'items' => [
                [
                    'id' => 'review_offline_harness',
                    'rule' => 'Operator reviews the offline harness payload and confirms the smoke scope.',
                    'evidence_field' => 'offline_harness',
                    'stop_condition' => 'stop_if_offline_harness_not_reviewed',
                ],
                [
                    'id' => 'sign_operator_approval_receipt',
                    'rule' => 'Operator signs an approval receipt for exactly one bounded real provider smoke.',
                    'evidence_field' => 'operator_approval_receipt_hash',
                    'stop_condition' => 'stop_if_operator_approval_receipt_hash_missing',
                ],
                [
                    'id' => 'select_single_task_packet',
                    'rule' => 'Select exactly one task packet for claim-to-completion exercise.',
                    'evidence_field' => 'task_packet_id',
                    'stop_condition' => 'stop_if_more_than_one_packet_selected',
                ],
                [
                    'id' => 'declare_observer_identity',
                    'rule' => 'Declare who is observing the smoke. Never a placeholder, codex or assistant.',
                    'evidence_field' => 'observed_by',
                    'stop_condition' => 'stop_if_observer_is_placeholder_or_codex',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function duringExecution(): array
    {
        return [
            'id' => 'during_execution',
            'summary' => 'Run the real provider path outside this read-only surface and capture run identity in real time.',
            'items' => [
                [
                    'id' => 'run_real_provider_outside_atlas_kernel',
                    'rule' => 'Atlas must not call the provider. Operator runs the provider path manually.',
                    'evidence_field' => 'provider_run_id',
                    'stop_condition' => 'stop_if_atlas_kernel_is_calling_provider',
                ],
                [
                    'id' => 'capture_provider_run_id',
                    'rule' => 'Capture provider_run_id assigned by the real provider during the live call.',
                    'evidence_field' => 'provider_run_id',
                    'stop_condition' => 'stop_if_provider_run_id_missing',
                ],
                [
                    'id' => 'confirm_provider_call_observed',
                    'rule' => 'Confirm provider_call_observed=true with a human-visible artifact (response, log, screenshot).',
                    'evidence_field' => 'provider_call_observed',
                    'stop_condition' => 'stop_if_provider_call_not_observed',
                ],
                [
                    'id' => 'confirm_token_spend_observed',
                    'rule' => 'Confirm token_spend_observed=true using provider response usage metadata.',
                    'evidence_field' => 'token_spend_observed',
                    'stop_condition' => 'stop_if_no_token_spend_visible',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function afterExecution(): array
    {
        return [
            'id' => 'after_execution',
            'summary' => 'Confirm claim-to-completion happened and lock the observation flags.',
            'items' => [
                [
                    'id' => 'confirm_claim_to_completion_observed',
                    'rule' => 'Confirm the packet went from claim to completion through the smoke.',
                    'evidence_field' => 'claim_to_completion_observed',
                    'stop_condition' => 'stop_if_packet_did_not_reach_completion',
                ],
                [
                    'id' => 'confirm_work_product_collected',
                    'rule' => 'Confirm work_product_collected=true after capturing the manifest.',
                    'evidence_field' => 'work_product_collected',
                    'stop_condition' => 'stop_if_no_work_product_captured',
                ],
                [
                    'id' => 'reject_atlas_runtime_flags',
                    'rule' => 'Ensure provider_called_by_atlas, token_spent_by_atlas, dispatch_allowed, adapter_execution_allowed, self_programming_allowed and completion_claim_promoted_without_receipt are all false.',
                    'evidence_field' => 'forbidden_flags',
                    'stop_condition' => 'stop_if_any_forbidden_flag_true',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function payloadSubmission(): array
    {
        return [
            'id' => 'payload_submission',
            'summary' => 'Hash every artifact deterministically and build the smoke preimage.',
            'items' => [
                [
                    'id' => 'hash_provider_response',
                    'rule' => 'Hash the provider response payload to 64-hex.',
                    'evidence_field' => 'provider_response_hash',
                    'stop_condition' => 'stop_if_provider_response_hash_invalid',
                ],
                [
                    'id' => 'hash_cost_event',
                    'rule' => 'Hash the cost event from the operator-side ledger.',
                    'evidence_field' => 'cost_event_hash',
                    'stop_condition' => 'stop_if_cost_event_hash_invalid',
                ],
                [
                    'id' => 'hash_work_product_manifest',
                    'rule' => 'Hash the work product manifest captured during the run.',
                    'evidence_field' => 'work_product_manifest_hash',
                    'stop_condition' => 'stop_if_work_product_manifest_hash_invalid',
                ],
                [
                    'id' => 'hash_continuation_summary',
                    'rule' => 'Hash the continuation summary written after the run.',
                    'evidence_field' => 'continuation_summary_hash',
                    'stop_condition' => 'stop_if_continuation_summary_hash_invalid',
                ],
                [
                    'id' => 'hash_evidence_ledger',
                    'rule' => 'Hash the evidence ledger entry that records the smoke.',
                    'evidence_field' => 'evidence_ledger_hash',
                    'stop_condition' => 'stop_if_evidence_ledger_hash_invalid',
                ],
                [
                    'id' => 'compute_smoke_hash',
                    'rule' => 'Compute smoke_hash via AtlasSelfConstructionCompletionEvidenceHashService::realProviderSmokeHash.',
                    'evidence_field' => 'smoke_hash',
                    'stop_condition' => 'stop_if_smoke_hash_mismatch',
                ],
                [
                    'id' => 'run_endgame_verifier',
                    'rule' => 'Run AtlasSelfConstructionRealProviderSmokeEndgameVerifierService::verify before submitting to the certifier.',
                    'evidence_field' => 'endgame_verifier_diagnostics',
                    'stop_condition' => 'stop_if_endgame_verifier_diagnostics_non_empty',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function persistence(): array
    {
        return [
            'id' => 'persistence',
            'summary' => 'Persist the smoke only through the existing certifier and only with explicit flag.',
            'items' => [
                [
                    'id' => 'persist_via_explicit_flag',
                    'rule' => 'Use --persist-completion-evidence and only after the endgame verifier passes.',
                    'evidence_field' => 'smoke_persistence',
                    'stop_condition' => 'never_persist_without_explicit_flag',
                ],
                [
                    'id' => 'reject_implicit_persistence',
                    'rule' => 'No service in the endgame pack may persist real provider smoke evidence by itself.',
                    'evidence_field' => 'persistence_allowed_here',
                    'stop_condition' => 'stop_if_any_endgame_service_persists_evidence',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function auditRerun(): array
    {
        return [
            'id' => 'audit_rerun',
            'summary' => 'Re-run completion evidence status and completion audit so the new smoke is counted.',
            'items' => [
                [
                    'id' => 'rerun_completion_evidence_status',
                    'rule' => 'Refresh completion evidence status so the new smoke is counted.',
                    'evidence_field' => 'completion_evidence_status',
                    'stop_condition' => 'stop_if_status_not_refreshed',
                ],
                [
                    'id' => 'refresh_terminal_loop_operational_proof',
                    'rule' => 'Refresh Terminal Loop Operational Proof before final completion audit.',
                    'evidence_field' => 'terminal_loop_operational_proof_audit_binding_packet',
                    'stop_condition' => 'stop_if_terminal_loop_operational_proof_not_passed',
                ],
                [
                    'id' => 'rerun_completion_audit',
                    'rule' => 'Re-run completion audit with Terminal Loop Operational Proof binding. Confirm end_to_end_real_provider_smoke_green is no longer in failed_criteria.',
                    'evidence_field' => 'completion_audit',
                    'stop_condition' => 'stop_if_blocker_still_failing',
                ],
            ],
        ];
    }

    /** @return list<string> */
    private function stopConditions(): array
    {
        return [
            'stop_if_offline_harness_not_reviewed',
            'stop_if_operator_approval_receipt_hash_missing',
            'stop_if_more_than_one_packet_selected',
            'stop_if_observer_is_placeholder_or_codex',
            'stop_if_atlas_kernel_is_calling_provider',
            'stop_if_provider_run_id_missing',
            'stop_if_provider_call_not_observed',
            'stop_if_no_token_spend_visible',
            'stop_if_packet_did_not_reach_completion',
            'stop_if_no_work_product_captured',
            'stop_if_any_forbidden_flag_true',
            'stop_if_provider_response_hash_invalid',
            'stop_if_cost_event_hash_invalid',
            'stop_if_work_product_manifest_hash_invalid',
            'stop_if_continuation_summary_hash_invalid',
            'stop_if_evidence_ledger_hash_invalid',
            'stop_if_smoke_hash_mismatch',
            'stop_if_endgame_verifier_diagnostics_non_empty',
            'never_persist_without_explicit_flag',
            'stop_if_any_endgame_service_persists_evidence',
            'stop_if_status_not_refreshed',
            'stop_if_blocker_still_failing',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $phases
     * @param  list<string>  $stopConditions
     */
    private function renderMarkdown(array $phases, array $stopConditions): string
    {
        $lines = [];
        $lines[] = '# Atlas Self-Construction OS · Real Provider Smoke Operator Runbook';
        $lines[] = '';
        $lines[] = '> This runbook is read-only. Atlas will not call the provider, spend tokens, dispatch, persist real evidence or claim completion.';
        $lines[] = '';
        foreach ($phases as $phase) {
            $lines[] = '## '.ucfirst(str_replace('_', ' ', (string) $phase['id']));
            $lines[] = '';
            $lines[] = (string) $phase['summary'];
            $lines[] = '';
            foreach ((array) $phase['items'] as $item) {
                $lines[] = '- **'.(string) $item['id'].'** — '.(string) $item['rule'].' _(stop: '.(string) $item['stop_condition'].')_';
            }
            $lines[] = '';
        }
        $lines[] = '## Stop conditions';
        $lines[] = '';
        foreach ($stopConditions as $condition) {
            $lines[] = '- `'.$condition.'`';
        }
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @param  list<array<string, mixed>>  $phases
     * @param  list<string>  $stopConditions
     * @return array<string, mixed>
     */
    private function renderMachineJson(array $phases, array $stopConditions): array
    {
        return [
            'phases' => $phases,
            'stop_conditions' => $stopConditions,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['generated_at'], $payload['exporter_hash']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $value */
    private function ksortRecursive(array $value): array
    {
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = $this->ksortRecursive($entry);
            }
        }
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
        }

        return $value;
    }
}
