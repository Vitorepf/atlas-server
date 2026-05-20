<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Tests\TestCase;

final class AtlasSelfConstructionOperatorResumeAliasConsistencyTest extends TestCase
{
    public function test_operator_resume_surfaces_expose_same_resume_aliases(): void
    {
        $service = app(AtlasSelfConstructionReadinessService::class);
        $surfaces = [
            'os_handoff' => $this->statusSummary($service->atlasSelfConstructionOsHandoffStatus()),
            'runtime_gap_matrix_audit' => $this->statusSummary($service->atlasSelfConstructionOsRuntimeGapMatrixAuditStatus()),
            'operator_action_packet' => $this->statusSummary($service->atlasSelfConstructionOsCompletionOperatorActionPacketStatus()),
            'completion_evidence_submission_preflight' => $this->statusSummary($service->atlasSelfConstructionCompletionEvidenceSubmissionPreflightStatus()),
            'real_provider_smoke_draft' => $this->statusSummary($service->atlasSelfConstructionRealProviderSmokeDraftStatus()),
            'human_completion_receipt_endgame_verifier' => $this->statusSummary($service->atlasSelfConstructionHumanCompletionReceiptEndgameVerifierStatus()),
            'final_completion_human_gate' => $this->statusSummary($service->atlasSelfConstructionFinalCompletionHumanGateStatus()),
            'final_completion_dossier_exporter' => $this->statusSummary($service->atlasSelfConstructionFinalCompletionDossierExporterStatus()),
        ];

        foreach ($surfaces as $surfaceName => $summary) {
            $this->assertSame('runtime_promotion_receipt', (string) data_get($summary, 'current_required_operator_artifact'), $surfaceName);
            $this->assertStringContainsString('--atlas-self-construction-runtime-promotion-receipt-draft-status', (string) data_get($summary, 'next_required_command'), $surfaceName);
            $this->assertStringContainsString('--persist-runtime-promotion-receipt', (string) data_get($summary, 'next_required_persist_command'), $surfaceName);
            $this->assertFalse((bool) data_get($summary, 'completion_claim_allowed', true), $surfaceName);
            $this->assertFalse((bool) data_get($summary, 'self_programming_allowed', true), $surfaceName);
            $this->assertTrue((bool) data_get($summary, 'terminal_loop_operational_proof_required_before_completion_claim'), $surfaceName);
            $this->assertTrue((bool) data_get($summary, 'completion_audit_without_terminal_loop_operational_proof_is_diagnostic_only'), $surfaceName);

            foreach ([
                'runtime_gap_matrix_hash',
                'expected_runtime_gap_matrix_hash_for_promotion_receipt',
                'runtime_promotion_basis_hash',
                'runtime_promotion_closure_basis_hash',
            ] as $hashField) {
                $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($summary, $hashField), $surfaceName.' '.$hashField);
            }
        }
    }

    public function test_operator_readiness_rejects_external_completion_claims(): void
    {
        $service = app(AtlasSelfConstructionReadinessService::class);
        $summary = $this->statusSummary($service->atlasSelfConstructionOperatorEvidenceSubmissionReadinessStatus());

        $this->assertSame('runtime_promotion_receipt', (string) data_get($summary, 'current_required_operator_artifact'));
        $this->assertSame(
            'atlas_self_construction_os_completion_audit',
            (string) data_get($summary, 'external_completion_claim_policy_completion_authority'),
        );
        $this->assertSame(
            'completion_audit.status=complete AND completion_allowed=true AND failed_count=0',
            (string) data_get($summary, 'external_completion_claim_policy_required_completion_predicate'),
        );
        $this->assertFalse((bool) data_get($summary, 'external_completion_claim_policy_external_agent_claim_accepted', true));
        $this->assertFalse((bool) data_get($summary, 'external_completion_claim_policy_external_agent_claim_can_mark_os_complete', true));
        $this->assertFalse((bool) data_get($summary, 'external_completion_claim_policy_external_agent_claim_can_override_audit', true));
        $this->assertGreaterThan(0, (int) data_get($summary, 'external_completion_claim_policy_current_failed_count', 0));
        $this->assertGreaterThan(0, (int) data_get($summary, 'external_completion_claim_policy_missing_required_evidence_artifact_count', 0));
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            (string) data_get($summary, 'external_completion_claim_policy_hash'),
        );
        $this->assertFalse((bool) data_get($summary, 'completion_claim_allowed', true));
        $this->assertFalse((bool) data_get($summary, 'self_programming_allowed', true));
    }

    public function test_operator_runbook_surfaces_reject_external_completion_claims(): void
    {
        $service = app(AtlasSelfConstructionReadinessService::class);
        $surfaces = [
            'runtime_promotion_receipt_runbook' => $this->statusSummary($service->atlasSelfConstructionRuntimePromotionReceiptRunbookStatus()),
            'human_completion_receipt_runbook' => $this->statusSummary($service->atlasSelfConstructionHumanCompletionReceiptRunbookStatus()),
        ];

        foreach ($surfaces as $surfaceName => $summary) {
            $this->assertSame(
                'atlas_self_construction_os_completion_audit',
                (string) data_get($summary, 'completion_claim_authority'),
                $surfaceName,
            );
            $this->assertSame(
                'completion_audit.status=complete AND completion_allowed=true AND failed_count=0',
                (string) data_get($summary, 'completion_claim_required_completion_predicate'),
                $surfaceName,
            );
            $this->assertFalse((bool) data_get($summary, 'completion_claim_external_agent_claim_accepted', true), $surfaceName);
            $this->assertFalse((bool) data_get($summary, 'completion_claim_external_agent_claim_can_mark_os_complete', true), $surfaceName);
            $this->assertFalse((bool) data_get($summary, 'completion_claim_external_agent_claim_can_override_audit', true), $surfaceName);
            $this->assertSame('reject_external_completion_claim', (string) data_get($summary, 'external_completion_claim_policy_status'), $surfaceName);
            $this->assertSame(
                'atlas_self_construction_os_completion_audit',
                (string) data_get($summary, 'external_completion_claim_policy_completion_authority'),
                $surfaceName,
            );
            $this->assertSame(
                'completion_audit.status=complete AND completion_allowed=true AND failed_count=0',
                (string) data_get($summary, 'external_completion_claim_policy_required_completion_predicate'),
                $surfaceName,
            );
            $this->assertFalse((bool) data_get($summary, 'external_completion_claim_policy_external_agent_claim_accepted', true), $surfaceName);
            $this->assertFalse((bool) data_get($summary, 'external_completion_claim_policy_external_agent_claim_can_mark_os_complete', true), $surfaceName);
            $this->assertFalse((bool) data_get($summary, 'external_completion_claim_policy_external_agent_claim_can_override_audit', true), $surfaceName);
            $this->assertSame('runtime_promotion_receipt', (string) data_get($summary, 'external_completion_claim_policy_current_required_operator_artifact'), $surfaceName);
            $this->assertGreaterThan(0, (int) data_get($summary, 'external_completion_claim_policy_current_failed_count', 0), $surfaceName);
            $this->assertMatchesRegularExpression(
                '/^[a-f0-9]{64}$/',
                (string) data_get($summary, 'external_completion_claim_policy_hash'),
                $surfaceName,
            );
            $this->assertFalse((bool) data_get($summary, 'completion_claim_allowed', true), $surfaceName);
            $this->assertFalse((bool) data_get($summary, 'self_programming_allowed', true), $surfaceName);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function statusSummary(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && str_ends_with($key, '_status') && is_array($value)) {
                return $value;
            }
        }

        return $payload;
    }
}
