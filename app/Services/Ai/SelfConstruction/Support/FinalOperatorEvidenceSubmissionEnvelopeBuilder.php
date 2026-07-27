<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Support;

use App\Services\Ai\SelfConstruction\FinalOperatorClosureCorridor\ClosureCorridorCanonicalHasher;

/**
 * ITEM8 — the operator-submission-envelope cluster extracted from
 * {@see \App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorService}
 * (god-class). Builds the three operator submission envelopes (runtime-promotion-receipt /
 * real-provider-smoke / human-completion-receipt) the closure corridor needs to drive the
 * "three real artifacts present" check, plus a per-envelope summary + the next-required-envelope
 * selector for the corridor's `next_required_submission` field.
 *
 * Three methods migrated verbatim from the god-class (and its old static helper
 * `FinalOperatorClosureCorridor\OperatorSubmissionEnvelopeBuilder`):
 *  - {@see self::operatorSubmissionEnvelopes}: aggregate the three envelopes with their statuses
 *    + the next-required envelope + the audit-derived stable hash.
 *  - {@see self::operatorEnvelopeSummary}: per-envelope summary (schema version, status, payload
 *    hash, persist command, detailed endgame command, non-execution guarantees, envelope hash).
 *  - {@see self::nextRequiredEnvelope}: given the three envelope statuses, return the key of the
 *    next envelope the operator must produce (or `rerun_completion_audit` once all three are
 *    persisted).
 *
 * Instance form (NOT static) so the god-class can hold it via a constructor-injected / lazy
 * property with the default-to-fresh-instance pattern; all internal logic is pure / stateless so
 * the behaviour is byte-identical to the previous static helper.
 */
class FinalOperatorEvidenceSubmissionEnvelopeBuilder
{
    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $completionAudit
     * @param  array<string, mixed>  $completionEvidence
     * @param  array<string, mixed>  $runtimeReceipt
     * @param  array<string, mixed>  $realProviderSmoke
     * @param  array<string, mixed>  $completionReceipt
     * @return array<string, mixed>
     */
    public function operatorSubmissionEnvelopes(
        array $options,
        array $completionAudit,
        array $completionEvidence,
        array $runtimeReceipt,
        array $realProviderSmoke,
        array $completionReceipt,
        bool $runtimeReceiptReady,
        bool $realProviderSmokeReady,
        bool $realProviderSmokePersistedBeforeHumanReceiptCommand,
        bool $humanReceiptReady,
    ): array {
        $runtimeEnvelope = $this->operatorEnvelopeSummary(
            schemaVersion: 'atlas.self_construction.runtime_promotion_operator_submission_envelope.v1',
            artifact: 'runtime_promotion_receipt',
            status: $runtimeReceiptReady ? 'persisted_runtime_promotion_receipt' : ($runtimeReceipt === [] ? 'blocked_until_operator_receipt_or_draft_exists' : 'operator_payload_supplied_verify_with_runtime_promotion_endgame'),
            payload: $runtimeReceipt,
            hashField: 'receipt_hash',
            persistCommand: 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --persist-runtime-promotion-receipt --json',
            detailedEndgameCommand: 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-endgame-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --json',
        );
        $smokeEnvelope = $this->operatorEnvelopeSummary(
            schemaVersion: 'atlas.self_construction.real_provider_smoke_operator_submission_envelope.v1',
            artifact: 'real_provider_smoke',
            status: $realProviderSmokeReady ? 'persisted_real_provider_smoke' : ($realProviderSmoke === [] ? 'blocked_until_operator_smoke_payload_exists' : 'operator_payload_supplied_verify_with_real_provider_smoke_endgame'),
            payload: $realProviderSmoke,
            hashField: 'smoke_hash',
            persistCommand: 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --real-provider-smoke-json=@/path/to/real-provider-smoke.json --persist-completion-evidence --json',
            detailedEndgameCommand: 'php artisan atlas:ai:self-construction --atlas-self-construction-real-provider-smoke-endgame-status --real-provider-smoke-json=@/path/to/real-provider-smoke.json --json',
        );
        $humanBlockedByPrereqs = ! $runtimeReceiptReady || ! $realProviderSmokeReady || ! $realProviderSmokePersistedBeforeHumanReceiptCommand;
        $humanEnvelope = $this->operatorEnvelopeSummary(
            schemaVersion: 'atlas.self_construction.human_completion_receipt_operator_submission_envelope.v1',
            artifact: 'human_completion_receipt',
            status: $humanReceiptReady ? 'persisted_human_completion_receipt' : ($humanBlockedByPrereqs ? 'blocked_until_runtime_smoke_prior_persistence_and_evidence_context_are_green' : ($completionReceipt === [] ? 'blocked_until_human_completion_receipt_payload_exists' : 'operator_payload_supplied_verify_with_human_completion_receipt_closure_pack')),
            payload: $completionReceipt,
            hashField: 'receipt_hash',
            persistCommand: 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --completion-receipt-json=@/path/to/completion-receipt.json --persist-completion-evidence --json',
            detailedEndgameCommand: 'php artisan atlas:ai:self-construction --atlas-self-construction-human-completion-receipt-closure-execution-pack-status --completion-receipt-json=@/path/to/completion-receipt.json --json',
            currentEvidenceContext: [
                'completion_audit_hash' => (string) data_get($completionAudit, 'completion_audit_hash', ''),
                'runtime_gap_matrix_hash' => (string) data_get($completionEvidence, 'runtime_gap_matrix.runtime_gap_matrix_hash', ''),
                'runtime_promotion_receipt_hash' => (string) data_get($completionEvidence, 'runtime_gap_matrix.runtime_promotion_receipt.receipt_hash', ''),
                'real_provider_smoke_hash' => (string) data_get($completionEvidence, 'real_provider_smoke.smoke_hash', ''),
                'real_provider_smoke_persisted_before_human_receipt_command' => $realProviderSmokePersistedBeforeHumanReceiptCommand,
            ],
        );
        $statuses = [
            'runtime_promotion_receipt' => (string) data_get($runtimeEnvelope, 'status', 'missing'),
            'real_provider_smoke' => (string) data_get($smokeEnvelope, 'status', 'missing'),
            'human_completion_receipt' => (string) data_get($humanEnvelope, 'status', 'missing'),
        ];
        $ready = array_values(array_filter(
            $statuses,
            static fn (string $status): bool => str_starts_with($status, 'persisted_'),
        ));

        $payload = [
            'schema_version' => 'atlas.self_construction.final_operator_evidence_submission_envelopes.v1',
            'mode' => 'read_only_aggregated_operator_submission_envelopes',
            'status' => count($ready) === 3 ? 'all_required_operator_evidence_persisted' : 'blocked_until_all_required_operator_envelopes_are_ready',
            'runtime_promotion_receipt' => $runtimeEnvelope,
            'real_provider_smoke' => $smokeEnvelope,
            'human_completion_receipt' => $humanEnvelope,
            'envelope_statuses' => $statuses,
            'ready_envelope_count' => count($ready),
            'persisted_envelope_count' => count($ready),
            'required_envelope_count' => 3,
            'next_required_envelope' => $this->nextRequiredEnvelope($statuses),
            'source_option_keys' => array_values(array_filter([
                $runtimeReceipt !== [] ? 'runtime_promotion_receipt' : null,
                $realProviderSmoke !== [] ? 'real_provider_smoke' : null,
                $completionReceipt !== [] ? 'completion_receipt' : null,
                ($options['runtime_promotion_receipt_json'] ?? null) !== null ? 'runtime_promotion_receipt_json' : null,
                ($options['real_provider_smoke_json'] ?? null) !== null ? 'real_provider_smoke_json' : null,
                ($options['completion_receipt_json'] ?? null) !== null ? 'completion_receipt_json' : null,
            ])),
            'non_execution_guarantees' => [
                'aggregated_envelopes_do_not_sign_for_operator',
                'aggregated_envelopes_do_not_persist_receipts',
                'aggregated_envelopes_do_not_call_provider',
                'aggregated_envelopes_do_not_spend_tokens',
                'aggregated_envelopes_do_not_dispatch',
                'aggregated_envelopes_do_not_enable_runtime',
                'aggregated_envelopes_do_not_promote_completion',
            ],
        ];
        $payload['operator_submission_envelopes_hash'] = ClosureCorridorCanonicalHasher::stableHash($payload);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $currentEvidenceContext
     * @return array<string, mixed>
     */
    public function operatorEnvelopeSummary(
        string $schemaVersion,
        string $artifact,
        string $status,
        array $payload,
        string $hashField,
        string $persistCommand,
        string $detailedEndgameCommand,
        array $currentEvidenceContext = [],
    ): array {
        $json = $payload === []
            ? ''
            : (string) json_encode(ClosureCorridorCanonicalHasher::ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $envelope = [
            'schema_version' => $schemaVersion,
            'mode' => 'read_only_operator_submission_envelope_summary',
            'artifact' => $artifact,
            'status' => $status,
            'payload_present' => $payload !== [],
            'payload_json_sha256' => $json === '' ? '' : hash('sha256', $json),
            'payload_hash' => (string) ($payload[$hashField] ?? ''),
            'hash_field' => $hashField,
            'current_evidence_context' => $currentEvidenceContext,
            'persist_command' => $persistCommand,
            'detailed_endgame_command' => $detailedEndgameCommand,
            'can_persist_from_corridor' => false,
            'non_execution_guarantees' => [
                'envelope_summary_does_not_verify_authority' => true,
                'envelope_summary_does_not_sign_for_operator' => true,
                'envelope_summary_does_not_persist_receipts' => true,
                'envelope_summary_does_not_call_provider' => true,
                'envelope_summary_does_not_spend_tokens' => true,
                'envelope_summary_does_not_dispatch' => true,
                'envelope_summary_does_not_enable_runtime' => true,
                'envelope_summary_does_not_promote_completion' => true,
            ],
        ];
        $envelope['envelope_summary_hash'] = ClosureCorridorCanonicalHasher::stableHash($envelope);

        return $envelope;
    }

    /** @param array<string, string> $statuses */
    public function nextRequiredEnvelope(array $statuses): string
    {
        foreach (['runtime_promotion_receipt', 'real_provider_smoke', 'human_completion_receipt'] as $key) {
            if (! str_starts_with(($statuses[$key] ?? ''), 'persisted_')) {
                return $key;
            }
        }

        return 'rerun_completion_audit';
    }
}
