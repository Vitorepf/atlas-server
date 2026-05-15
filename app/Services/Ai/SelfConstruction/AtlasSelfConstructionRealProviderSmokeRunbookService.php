<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;

final class AtlasSelfConstructionRealProviderSmokeRunbookService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.real_provider_smoke_runbook.v1';

    public const MODE = 'read_only_real_provider_smoke_runbook';

    /** @return array<string, mixed> */
    public function build(array $realProviderSmokeTemplate): array
    {
        $requiredEvidenceFields = [
            'provider_run_id',
            'task_packet_id',
            'observed_by',
            'approval_reason',
            'smoke_hash',
            'operator_approval_receipt_hash',
            'evidence_ledger_hash',
            'work_product_manifest_hash',
            'cost_event_hash',
            'continuation_summary_hash',
            'provider_response_hash',
        ];
        $requiredObservationFlags = [
            'provider_call_observed',
            'token_spend_observed',
            'claim_to_completion_observed',
            'work_product_collected',
            'operator_supplied_evidence',
            'real_provider_run_observed_by_operator',
        ];
        $forbiddenFlags = [
            'provider_called_by_atlas',
            'token_spent_by_atlas',
            'dispatch_allowed',
            'adapter_execution_allowed',
            'self_programming_allowed',
            'completion_claim_promoted_without_receipt',
        ];
        $steps = [
            [
                'id' => 'operator_approval',
                'summary' => 'Operator approves a bounded real provider claim-to-completion smoke before any provider call.',
                'evidence_required' => ['operator_approval_receipt_hash'],
            ],
            [
                'id' => 'run_single_packet',
                'summary' => 'Run exactly one scoped task packet through the real provider path and record provider run identity.',
                'evidence_required' => ['provider_run_id', 'task_packet_id', 'provider_response_hash'],
            ],
            [
                'id' => 'collect_work_product_and_cost',
                'summary' => 'Collect work product manifest, cost event and continuation summary from the observed run.',
                'evidence_required' => ['work_product_manifest_hash', 'cost_event_hash', 'continuation_summary_hash'],
            ],
            [
                'id' => 'write_evidence_ledger',
                'summary' => 'Write or attach the evidence ledger hash proving the smoke observations.',
                'evidence_required' => ['evidence_ledger_hash', 'smoke_hash'],
            ],
            [
                'id' => 'persist_certification_payload',
                'summary' => 'Persist the completed smoke payload only through the verifier.',
                'evidence_required' => ['all_required_fields_present', 'all_required_observations_true', 'operator_acknowledgements_true', 'forbidden_flags_false'],
            ],
        ];
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => 'operator_real_provider_smoke_required',
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'required_evidence_fields' => $requiredEvidenceFields,
            'required_observation_flags' => $requiredObservationFlags,
            'forbidden_flags' => $forbiddenFlags,
            'template_field_count' => count($realProviderSmokeTemplate),
            'template_hash' => $this->stableHash($realProviderSmokeTemplate),
            'steps' => $steps,
            'commands' => [
                'persist_real_provider_smoke' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --real-provider-smoke-json=@/path/to/real-provider-smoke.json --persist-completion-evidence --json',
                'verify_completion_evidence' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --json',
                'run_completion_audit' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
            ],
            'non_execution_guarantees' => [
                'real_provider_smoke_runbook_does_not_call_provider',
                'real_provider_smoke_runbook_does_not_spend_tokens',
                'real_provider_smoke_runbook_does_not_dispatch_work',
                'real_provider_smoke_runbook_does_not_start_codex',
                'real_provider_smoke_runbook_does_not_promote_completion',
            ],
        ];
        $payload['runbook_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['generated_at'], $payload['runbook_hash']);

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
