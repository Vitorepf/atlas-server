<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\FinalOperatorEvidenceSubmissionEnvelopeBuilder;
use PHPUnit\Framework\TestCase;

/**
 * ITEM8 — proves the cohesive operator-submission-envelope cluster extracted from
 * {@see \App\Services\Ai\SelfConstruction\AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorService}
 * into FinalOperatorEvidenceSubmissionEnvelopeBuilder. Three methods migrated verbatim:
 *  - operatorSubmissionEnvelopes (10-arg aggregate of the three envelopes + statuses + hash).
 *  - operatorEnvelopeSummary (8-arg per-envelope summary).
 *  - nextRequiredEnvelope (3-key status map → first-non-persisted envelope key).
 *
 * Behaviour is byte-identical to the previous static helper, so we assert:
 *  - exact key sets (proves the extraction preserved the schema);
 *  - stable SHA-256 hashes (proves no incidental reordering);
 *  - the well-known canonical mapping rules (all three envelopes persisted → `rerun_completion_audit`;
 *    only runtime persisted → `real_provider_smoke`, etc.).
 *
 * Pure / stateless / zero Laravel surface — pure PHPUnit suffices (the underlying ClosureCorridorCanonicalHasher
 * dependency is itself pure / stateless).
 */
final class FinalOperatorEvidenceSubmissionEnvelopeBuilderTest extends TestCase
{
    private FinalOperatorEvidenceSubmissionEnvelopeBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new FinalOperatorEvidenceSubmissionEnvelopeBuilder;
    }

    // --- operatorSubmissionEnvelopes -----------------------------------

    public function test_aggregated_envelopes_emits_canonical_top_level_keys(): void
    {
        $out = $this->builder->operatorSubmissionEnvelopes(
            $this->options(),
            $this->completionAudit(),
            $this->completionEvidence(),
            [], [], [],
            false, false, false, false,
        );

        $expectedTop = [
            'schema_version',
            'mode',
            'status',
            'runtime_promotion_receipt',
            'real_provider_smoke',
            'human_completion_receipt',
            'envelope_statuses',
            'ready_envelope_count',
            'persisted_envelope_count',
            'required_envelope_count',
            'next_required_envelope',
            'source_option_keys',
            'non_execution_guarantees',
            'operator_submission_envelopes_hash',
        ];
        $this->assertSame($expectedTop, array_keys($out));
    }

    public function test_aggregated_envelopes_schema_version_is_canonical(): void
    {
        $out = $this->builder->operatorSubmissionEnvelopes(
            $this->options(),
            $this->completionAudit(),
            $this->completionEvidence(),
            [], [], [],
            false, false, false, false,
        );

        $this->assertSame('atlas.self_construction.final_operator_evidence_submission_envelopes.v1', $out['schema_version']);
        $this->assertSame('read_only_aggregated_operator_submission_envelopes', $out['mode']);
    }

    public function test_aggregated_envelopes_status_is_blocked_when_no_envelopes_persisted(): void
    {
        $out = $this->builder->operatorSubmissionEnvelopes(
            $this->options(),
            $this->completionAudit(),
            $this->completionEvidence(),
            [], [], [],
            false, false, false, false,
        );

        $this->assertSame('blocked_until_all_required_operator_envelopes_are_ready', $out['status']);
        $this->assertSame(0, $out['ready_envelope_count']);
        $this->assertSame(0, $out['persisted_envelope_count']);
        $this->assertSame(3, $out['required_envelope_count']);
    }

    public function test_aggregated_envelopes_status_is_all_persisted_when_all_three_ready(): void
    {
        $out = $this->builder->operatorSubmissionEnvelopes(
            $this->options(),
            $this->completionAudit(),
            $this->completionEvidence(),
            $this->runtimeReceipt(),
            $this->realProviderSmoke(),
            $this->completionReceipt(),
            true, true, true, true,
        );

        $this->assertSame('all_required_operator_evidence_persisted', $out['status']);
        $this->assertSame(3, $out['ready_envelope_count']);
        $this->assertSame(3, $out['persisted_envelope_count']);
    }

    public function test_aggregated_envelopes_next_required_is_runtime_when_none_persisted(): void
    {
        $out = $this->builder->operatorSubmissionEnvelopes(
            $this->options(),
            $this->completionAudit(),
            $this->completionEvidence(),
            [], [], [],
            false, false, false, false,
        );

        $this->assertSame('runtime_promotion_receipt', $out['next_required_envelope']);
    }

    public function test_aggregated_envelopes_next_required_skips_persisted_envelopes(): void
    {
        $out = $this->builder->operatorSubmissionEnvelopes(
            $this->options(),
            $this->completionAudit(),
            $this->completionEvidence(),
            $this->runtimeReceipt(),
            [], [],
            true, false, false, false,
        );

        $this->assertSame('real_provider_smoke', $out['next_required_envelope']);
    }

    public function test_aggregated_envelopes_source_option_keys_lists_only_supplied_payloads(): void
    {
        $out = $this->builder->operatorSubmissionEnvelopes(
            $this->options(),
            $this->completionAudit(),
            $this->completionEvidence(),
            $this->runtimeReceipt(),
            [], [],
            true, false, false, false,
        );

        $this->assertContains('runtime_promotion_receipt', $out['source_option_keys']);
        $this->assertNotContains('real_provider_smoke', $out['source_option_keys']);
        $this->assertNotContains('completion_receipt', $out['source_option_keys']);
    }

    public function test_aggregated_envelopes_emits_seven_non_execution_guarantees(): void
    {
        $out = $this->builder->operatorSubmissionEnvelopes(
            $this->options(),
            $this->completionAudit(),
            $this->completionEvidence(),
            [], [], [],
            false, false, false, false,
        );

        $this->assertCount(7, $out['non_execution_guarantees']);
        $this->assertContains('aggregated_envelopes_do_not_sign_for_operator', $out['non_execution_guarantees']);
        $this->assertContains('aggregated_envelopes_do_not_promote_completion', $out['non_execution_guarantees']);
    }

    public function test_aggregated_envelopes_hash_is_64_hex_characters(): void
    {
        $out = $this->builder->operatorSubmissionEnvelopes(
            $this->options(),
            $this->completionAudit(),
            $this->completionEvidence(),
            [], [], [],
            false, false, false, false,
        );

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $out['operator_submission_envelopes_hash']);
    }

    public function test_aggregated_envelopes_hash_is_stable_for_same_input(): void
    {
        $args = [
            $this->options(),
            $this->completionAudit(),
            $this->completionEvidence(),
            [], [], [],
            false, false, false, false,
        ];

        $a = $this->builder->operatorSubmissionEnvelopes(...$args);
        $b = $this->builder->operatorSubmissionEnvelopes(...$args);

        $this->assertSame($a['operator_submission_envelopes_hash'], $b['operator_submission_envelopes_hash']);
    }

    public function test_aggregated_envelopes_includes_three_per_envelope_summaries_with_canonical_keys(): void
    {
        $out = $this->builder->operatorSubmissionEnvelopes(
            $this->options(),
            $this->completionAudit(),
            $this->completionEvidence(),
            $this->runtimeReceipt(),
            $this->realProviderSmoke(),
            $this->completionReceipt(),
            true, true, true, true,
        );

        $expectedKeys = [
            'schema_version', 'mode', 'artifact', 'status', 'payload_present',
            'payload_json_sha256', 'payload_hash', 'hash_field',
            'current_evidence_context', 'persist_command', 'detailed_endgame_command',
            'can_persist_from_corridor', 'non_execution_guarantees', 'envelope_summary_hash',
        ];
        foreach (['runtime_promotion_receipt', 'real_provider_smoke', 'human_completion_receipt'] as $key) {
            $this->assertSame($expectedKeys, array_keys($out[$key]), "envelope '$key' must emit canonical keys");
        }
    }

    public function test_human_envelope_blocked_until_runtime_smoke_persistence_when_prereqs_off(): void
    {
        $out = $this->builder->operatorSubmissionEnvelopes(
            $this->options(),
            $this->completionAudit(),
            $this->completionEvidence(),
            [], [], $this->completionReceipt(),
            false, false, false, false,
        );

        $this->assertSame(
            'blocked_until_runtime_smoke_prior_persistence_and_evidence_context_are_green',
            $out['human_completion_receipt']['status'],
            'human receipt must be blocked until runtime+smoke prereqs are green',
        );
    }

    public function test_human_envelope_persisted_when_all_prereqs_green_and_receipt_supplied(): void
    {
        $out = $this->builder->operatorSubmissionEnvelopes(
            $this->options(),
            $this->completionAudit(),
            $this->completionEvidence(),
            [], [], $this->completionReceipt(),
            true, true, true, true,
        );

        $this->assertSame('persisted_human_completion_receipt', $out['human_completion_receipt']['status']);
    }

    // --- operatorEnvelopeSummary ----------------------------------------

    public function test_envelope_summary_emits_canonical_keys(): void
    {
        $out = $this->builder->operatorEnvelopeSummary(
            schemaVersion: 'atlas.self_construction.test_envelope.v1',
            artifact: 'test_artifact',
            status: 'persisted_test',
            payload: ['receipt_hash' => 'abc123'],
            hashField: 'receipt_hash',
            persistCommand: 'cmd persist',
            detailedEndgameCommand: 'cmd endgame',
        );

        $expectedKeys = [
            'schema_version', 'mode', 'artifact', 'status', 'payload_present',
            'payload_json_sha256', 'payload_hash', 'hash_field',
            'current_evidence_context', 'persist_command', 'detailed_endgame_command',
            'can_persist_from_corridor', 'non_execution_guarantees', 'envelope_summary_hash',
        ];
        $this->assertSame($expectedKeys, array_keys($out));
    }

    public function test_envelope_summary_payload_present_is_false_for_empty_payload(): void
    {
        $out = $this->builder->operatorEnvelopeSummary(
            schemaVersion: 's',
            artifact: 'a',
            status: 'blocked',
            payload: [],
            hashField: 'receipt_hash',
            persistCommand: 'p',
            detailedEndgameCommand: 'd',
        );

        $this->assertFalse($out['payload_present']);
        $this->assertSame('', $out['payload_json_sha256']);
        $this->assertSame('', $out['payload_hash']);
    }

    public function test_envelope_summary_payload_present_is_true_and_hash_set_for_non_empty_payload(): void
    {
        $out = $this->builder->operatorEnvelopeSummary(
            schemaVersion: 's',
            artifact: 'a',
            status: 'persisted',
            payload: ['receipt_hash' => 'ABC', 'note' => 'x'],
            hashField: 'receipt_hash',
            persistCommand: 'p',
            detailedEndgameCommand: 'd',
        );

        $this->assertTrue($out['payload_present']);
        $this->assertNotSame('', $out['payload_json_sha256']);
        $this->assertSame('ABC', $out['payload_hash']);
        $this->assertSame('receipt_hash', $out['hash_field']);
    }

    public function test_envelope_summary_can_persist_from_corridor_is_always_false(): void
    {
        $out = $this->builder->operatorEnvelopeSummary(
            schemaVersion: 's', artifact: 'a', status: 'x', payload: ['receipt_hash' => 'h'],
            hashField: 'receipt_hash', persistCommand: 'p', detailedEndgameCommand: 'd',
        );

        $this->assertFalse($out['can_persist_from_corridor'], 'envelope summary must NEVER say it can persist');
    }

    public function test_envelope_summary_emits_eight_non_execution_guarantees(): void
    {
        $out = $this->builder->operatorEnvelopeSummary(
            schemaVersion: 's', artifact: 'a', status: 'x', payload: [],
            hashField: 'h', persistCommand: 'p', detailedEndgameCommand: 'd',
        );

        $this->assertCount(8, $out['non_execution_guarantees']);
        $this->assertTrue($out['non_execution_guarantees']['envelope_summary_does_not_verify_authority']);
        $this->assertTrue($out['non_execution_guarantees']['envelope_summary_does_not_sign_for_operator']);
        $this->assertTrue($out['non_execution_guarantees']['envelope_summary_does_not_persist_receipts']);
        $this->assertTrue($out['non_execution_guarantees']['envelope_summary_does_not_call_provider']);
        $this->assertTrue($out['non_execution_guarantees']['envelope_summary_does_not_spend_tokens']);
        $this->assertTrue($out['non_execution_guarantees']['envelope_summary_does_not_dispatch']);
        $this->assertTrue($out['non_execution_guarantees']['envelope_summary_does_not_enable_runtime']);
        $this->assertTrue($out['non_execution_guarantees']['envelope_summary_does_not_promote_completion']);
    }

    public function test_envelope_summary_default_current_evidence_context_is_empty_array(): void
    {
        $out = $this->builder->operatorEnvelopeSummary(
            schemaVersion: 's', artifact: 'a', status: 'x', payload: [],
            hashField: 'h', persistCommand: 'p', detailedEndgameCommand: 'd',
        );

        $this->assertSame([], $out['current_evidence_context']);
    }

    // --- nextRequiredEnvelope -------------------------------------------

    public function test_next_required_returns_runtime_when_no_statuses_provided(): void
    {
        $this->assertSame('runtime_promotion_receipt', $this->builder->nextRequiredEnvelope([]));
    }

    public function test_next_required_returns_runtime_when_runtime_not_persisted(): void
    {
        $this->assertSame(
            'runtime_promotion_receipt',
            $this->builder->nextRequiredEnvelope([
                'runtime_promotion_receipt' => 'blocked',
            ]),
        );
    }

    public function test_next_required_returns_smoke_when_runtime_persisted_and_smoke_not(): void
    {
        $this->assertSame(
            'real_provider_smoke',
            $this->builder->nextRequiredEnvelope([
                'runtime_promotion_receipt' => 'persisted_runtime_promotion_receipt',
                'real_provider_smoke' => 'blocked',
            ]),
        );
    }

    public function test_next_required_returns_human_when_runtime_and_smoke_persisted(): void
    {
        $this->assertSame(
            'human_completion_receipt',
            $this->builder->nextRequiredEnvelope([
                'runtime_promotion_receipt' => 'persisted_runtime_promotion_receipt',
                'real_provider_smoke' => 'persisted_real_provider_smoke',
                'human_completion_receipt' => 'blocked',
            ]),
        );
    }

    public function test_next_required_returns_rerun_when_all_three_persisted(): void
    {
        $this->assertSame(
            'rerun_completion_audit',
            $this->builder->nextRequiredEnvelope([
                'runtime_promotion_receipt' => 'persisted_runtime_promotion_receipt',
                'real_provider_smoke' => 'persisted_real_provider_smoke',
                'human_completion_receipt' => 'persisted_human_completion_receipt',
            ]),
        );
    }

    public function test_next_required_treats_non_persisted_prefix_as_still_required(): void
    {
        // Only the literal `persisted_*` prefix counts as "ready"; anything else (incl. `blocked_*`,
        // `operator_payload_supplied_*`) keeps the envelope in the required-set.
        $this->assertSame(
            'human_completion_receipt',
            $this->builder->nextRequiredEnvelope([
                'runtime_promotion_receipt' => 'persisted_runtime_promotion_receipt',
                'real_provider_smoke' => 'persisted_real_provider_smoke',
                'human_completion_receipt' => 'operator_payload_supplied_verify_with_human_completion_receipt_closure_pack',
            ]),
        );
    }

    // --- helpers ----------------------------------------------------------

    private function options(): array
    {
        return [];
    }

    private function completionAudit(): array
    {
        return [
            'completion_audit_hash' => 'audit-hash-123',
        ];
    }

    private function completionEvidence(): array
    {
        return [
            'runtime_gap_matrix' => [
                'runtime_gap_matrix_hash' => 'gap-hash-456',
                'runtime_promotion_receipt' => ['receipt_hash' => 'rpr-hash-789'],
            ],
            'real_provider_smoke' => [
                'smoke_hash' => 'smoke-hash-012',
            ],
        ];
    }

    private function runtimeReceipt(): array
    {
        return ['receipt_hash' => 'rpr-1'];
    }

    private function realProviderSmoke(): array
    {
        return ['smoke_hash' => 'smoke-1'];
    }

    private function completionReceipt(): array
    {
        return ['receipt_hash' => 'hr-1'];
    }
}
