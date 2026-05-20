<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Tests\TestCase;

final class AtlasSelfConstructionFinalResumeSurfaceConsistencyTest extends TestCase
{
    public function test_final_resume_surfaces_keep_operator_next_action_and_claim_guards_aligned(): void
    {
        $service = app(AtlasSelfConstructionReadinessService::class);

        $surfaces = [
            'completion_evidence' => [
                'payload' => fn (): array => $service->atlasSelfConstructionOsCompletionEvidenceStatus(),
                'expected_command_fragment' => '--atlas-self-construction-runtime-promotion-receipt-draft-status',
                'persist_required' => true,
            ],
            'operator_evidence_submission_readiness' => [
                'payload' => fn (): array => $service->atlasSelfConstructionOperatorEvidenceSubmissionReadinessStatus(),
                'expected_command_fragment' => '--atlas-self-construction-runtime-promotion-receipt-draft-status',
                'persist_required' => true,
            ],
            'completion_audit_blocker_explainer' => [
                'payload' => fn (): array => $service->atlasSelfConstructionCompletionAuditBlockerExplainerStatus(),
                'expected_command_fragment' => '--atlas-self-construction-runtime-promotion-receipt-draft-status',
                'persist_required' => true,
            ],
            'final_evidence_bundle' => [
                'payload' => fn (): array => $service->atlasSelfConstructionFinalEvidenceBundleStatus(),
                'expected_command_fragment' => '--atlas-self-construction-runtime-promotion-receipt-draft-status',
                'persist_required' => true,
            ],
            'final_operator_evidence_closure_corridor' => [
                'payload' => fn (): array => $service->atlasSelfConstructionFinalOperatorEvidenceClosureCorridorStatus(),
                'expected_command_fragment' => '--atlas-self-construction-runtime-promotion-receipt-draft-status',
                'persist_required' => false,
            ],
            'runtime_promotion_endgame' => [
                'payload' => fn (): array => $service->atlasSelfConstructionRuntimePromotionEndgameStatus(),
                'expected_command_fragment' => '--atlas-self-construction-runtime-promotion-receipt-draft-status',
                'persist_required' => true,
            ],
            'final_completion_readiness_gate' => [
                'payload' => fn (): array => $service->atlasSelfConstructionFinalCompletionReadinessGateStatus(),
                'expected_command_fragment' => '--atlas-self-construction-runtime-promotion-receipt-draft-status',
                'persist_required' => true,
            ],
            'self_programming_transition_readiness' => [
                'payload' => fn (): array => $service->atlasSelfProgrammingOsTransitionReadinessStatus(),
                'expected_command_fragment' => '--atlas-self-construction-runtime-promotion-receipt-draft-status',
                'persist_required' => true,
            ],
            'completion_finalization_gate' => [
                'payload' => fn (): array => $service->atlasSelfConstructionCompletionFinalizationGateStatus(),
                'expected_command_fragment' => '--atlas-self-construction-operator-evidence-submission-readiness-status',
                'persist_required' => false,
            ],
        ];

        foreach ($surfaces as $surfaceName => $surface) {
            $summary = $this->statusSummary($surface['payload']());

            $this->assertSame(
                'runtime_promotion_receipt',
                (string) data_get($summary, 'current_required_operator_artifact'),
                $surfaceName.' must keep the live next operator artifact aligned.',
            );
            $this->assertStringContainsString(
                (string) $surface['expected_command_fragment'],
                (string) data_get($summary, 'next_required_command'),
                $surfaceName.' must expose the copy-safe next command for the current artifact.',
            );
            $this->assertFalse(
                (bool) data_get($summary, 'completion_claim_allowed', true),
                $surfaceName.' must not authorize completion claims before the canonical audit is complete.',
            );
            $this->assertFalse(
                (bool) data_get($summary, 'self_programming_allowed', true),
                $surfaceName.' must not unlock Self-Programming during Self-Construction closure.',
            );
            $this->assertTrue(
                (bool) data_get($summary, 'terminal_loop_operational_proof_required_before_completion_claim'),
                $surfaceName.' must require terminal-loop operational proof before any completion claim.',
            );
            $this->assertTrue(
                (bool) data_get($summary, 'completion_audit_without_terminal_loop_operational_proof_is_diagnostic_only'),
                $surfaceName.' must mark audits without terminal-loop proof as diagnostic only.',
            );
            $this->assertRuntimePromotionHashesPresent($summary, $surfaceName);

            if ((bool) $surface['persist_required']) {
                $this->assertStringContainsString(
                    '--persist-runtime-promotion-receipt',
                    (string) data_get($summary, 'next_required_persist_command'),
                    $surfaceName.' must expose the explicit runtime promotion persistence command.',
                );
            }
        }
    }

    public function test_final_resume_summary_surfaces_reject_external_completion_claims(): void
    {
        $service = app(AtlasSelfConstructionReadinessService::class);
        $surfaces = [
            'completion_audit_blocker_explainer' => $this->statusSummary($service->atlasSelfConstructionCompletionAuditBlockerExplainerStatus()),
            'final_evidence_bundle' => $this->statusSummary($service->atlasSelfConstructionFinalEvidenceBundleStatus()),
            'final_completion_dossier_exporter' => $this->statusSummary($service->atlasSelfConstructionFinalCompletionDossierExporterStatus()),
        ];

        foreach ($surfaces as $surfaceName => $summary) {
            $this->assertSame('runtime_promotion_receipt', (string) data_get($summary, 'current_required_operator_artifact'), $surfaceName);
            $this->assertSame('reject_external_completion_claim', (string) data_get($summary, 'external_completion_claim_policy_status'), $surfaceName);
            $this->assertSame('atlas_self_construction_os_completion_audit', (string) data_get($summary, 'completion_claim_authority'), $surfaceName);
            $this->assertSame('atlas_self_construction_os_completion_audit', (string) data_get($summary, 'external_completion_claim_policy_completion_authority'), $surfaceName);
            $this->assertSame(
                'completion_audit.status=complete AND completion_allowed=true AND failed_count=0',
                (string) data_get($summary, 'completion_claim_required_completion_predicate'),
                $surfaceName,
            );
            $this->assertFalse((bool) data_get($summary, 'completion_claim_external_agent_claim_accepted', true), $surfaceName);
            $this->assertFalse((bool) data_get($summary, 'completion_claim_external_agent_claim_can_mark_os_complete', true), $surfaceName);
            $this->assertFalse((bool) data_get($summary, 'completion_claim_external_agent_claim_can_override_audit', true), $surfaceName);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($summary, 'external_completion_claim_policy_hash'), $surfaceName);
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

    /**
     * @param  array<string, mixed>  $summary
     */
    private function assertRuntimePromotionHashesPresent(array $summary, string $surfaceName): void
    {
        foreach ([
            'runtime_gap_matrix_hash',
            'expected_runtime_gap_matrix_hash_for_promotion_receipt',
            'runtime_promotion_basis_hash',
            'runtime_promotion_closure_basis_hash',
        ] as $field) {
            $this->assertMatchesRegularExpression(
                '/^[a-f0-9]{64}$/',
                (string) data_get($summary, $field),
                $surfaceName.' must expose '.$field.' as a top-level resume hash.',
            );
        }
    }
}
