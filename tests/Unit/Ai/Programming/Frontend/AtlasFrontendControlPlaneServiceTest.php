<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\Frontend\AtlasFrontendCompetitiveRubricService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendControlPlaneService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendEvidencePackVerifierService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendProductProofRuntimeService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendRivalReplayHarnessService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendControlPlaneServiceTest extends TestCase
{
    public function test_control_plane_allows_governed_runtime_claim_but_blocks_world_best_without_real_replay_and_public_receipt(): void
    {
        $payload = app(AtlasFrontendControlPlaneService::class)->snapshot();

        $this->assertSame(AtlasFrontendControlPlaneService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('warning', $payload['status']);
        $this->assertTrue((bool) data_get($payload, 'readiness_levels.runtime_contract_ready'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.may_claim_more_complete_than_impeccable'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.may_claim_more_complete_than_claude_design_plugin'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.world_best_claim_allowed'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.local_publication_report_is_not_public_distribution'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.world_best_requires_decisive_lead_each_replay_case'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.world_best_requires_no_tied_replay_cases'));
        $this->assertSame('pending_replay', data_get($payload, 'readiness_levels.competitive_diagnostics_status'));
        $this->assertSame(0, data_get($payload, 'readiness_levels.competitive_tied_case_count'));
        $this->assertSame(0, data_get($payload, 'readiness_levels.competitive_dimension_gap_case_count'));
        $this->assertFalse((bool) data_get($payload, 'readiness_levels.competitive_decisive_lead_ready'));
        $this->assertSame('not_requested', data_get($payload, 'readiness_levels.publication_attestation_status'));
        $this->assertSame('not_requested', data_get($payload, 'signals.publication_attestation.status'));
        $this->assertSame('pending_replay', data_get($payload, 'signals.rival_replay.competitive_diagnostics.status'));
        $this->assertTrue((bool) data_get($payload, 'signals.publication_attestation.claim_policy.local_bundle_is_not_public_distribution'));
        $this->assertContains('external_rival_replay_not_completed', $payload['warnings']);
        $this->assertContains('public_distribution_receipt_not_verified', $payload['warnings']);
        $this->assertContains('do_not_claim_world_best_until_replay_and_public_distribution_are_verified', $payload['required_next_actions']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $payload['control_plane_hash']);
    }

    public function test_control_plane_attests_local_bundle_without_public_distribution_claim(): void
    {
        $bundle = sys_get_temp_dir().'/atlas-frontend-control-plane-bundle-'.bin2hex(random_bytes(4));
        app(AtlasFrontendProductProofRuntimeService::class)->buildStaticBundle($bundle);

        $payload = app(AtlasFrontendControlPlaneService::class)->snapshot([
            'bundle' => $bundle,
        ]);

        $this->assertSame('warning', $payload['status']);
        $this->assertFalse((bool) data_get($payload, 'claim_policy.public_distribution_claim_allowed'));
        $this->assertSame('local_bundle_ready_publication_pending', data_get($payload, 'readiness_levels.publication_attestation_status'));
        $this->assertSame('local_bundle_ready_publication_pending', data_get($payload, 'signals.publication_attestation.status'));
        $this->assertFalse((bool) data_get($payload, 'signals.publication_attestation.claim_policy.public_distribution_claim_allowed'));
        $this->assertContains('provide_operator_approved_publication_receipt', data_get($payload, 'signals.publication_attestation.required_next_actions'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($payload, 'signals.publication_attestation.attestation_hash'));
    }

    public function test_control_plane_passes_rival_evidence_directory_into_benchmark_signal(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-control-plane-rival-evidence-'.bin2hex(random_bytes(4));
        app(AtlasFrontendRivalReplayHarnessService::class)->writeTemplate($dir);

        $payload = app(AtlasFrontendControlPlaneService::class)->snapshot([
            'rival_evidence' => $dir,
        ]);

        $this->assertSame(hash('sha256', $dir), data_get($payload, 'input_scope.rival_evidence_directory_hash'));
        $this->assertTrue((bool) data_get($payload, 'signals.benchmark.scope.rival_evidence_directory_supplied'));
        $this->assertSame(hash('sha256', $dir), data_get($payload, 'signals.benchmark.scope.rival_evidence_directory_hash'));
        $this->assertSame('ready_for_replay', data_get($payload, 'signals.rival_replay.status'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.world_best_claim_allowed'));
    }

    public function test_control_plane_turns_tied_rival_replay_into_decisive_lead_action(): void
    {
        $payload = app(AtlasFrontendControlPlaneService::class)->snapshot([
            'rival_evidence' => $this->tiedReplayDirectory(),
        ]);

        $this->assertSame('warning', $payload['status']);
        $this->assertFalse((bool) data_get($payload, 'claim_policy.world_best_claim_allowed'));
        $this->assertSame('atlas_needs_decisive_lead', data_get($payload, 'readiness_levels.competitive_diagnostics_status'));
        $this->assertSame(5, data_get($payload, 'readiness_levels.competitive_tied_case_count'));
        $this->assertFalse((bool) data_get($payload, 'readiness_levels.competitive_decisive_lead_ready'));
        $this->assertSame('atlas_needs_decisive_lead', data_get($payload, 'signals.rival_replay.competitive_diagnostics.status'));
        $this->assertContains('atlas_does_not_lead_every_complete_case', $payload['warnings']);
        $this->assertContains('improve_atlas_frontend_until_replay_leads_every_case', $payload['required_next_actions']);
    }

    public function test_control_plane_projects_competitive_repair_plan_for_dimension_gaps(): void
    {
        $payload = app(AtlasFrontendControlPlaneService::class)->snapshot([
            'rival_evidence' => $this->dimensionGapReplayDirectory(),
        ]);

        $this->assertSame('warning', $payload['status']);
        $this->assertSame('atlas_needs_dimension_lead', data_get($payload, 'readiness_levels.competitive_diagnostics_status'));
        $this->assertSame(5, data_get($payload, 'readiness_levels.competitive_dimension_gap_case_count'));
        $this->assertContains('atlas_has_dimension_gaps_against_best_rival', $payload['warnings']);
        $this->assertContains('improve_atlas_frontend_until_replay_closes_dimension_gaps', $payload['required_next_actions']);
        $this->assertSame('atlas.frontend.repair_plan.v1', data_get($payload, 'signals.competitive_repair_plan.schema_version'));
        $this->assertSame('competitive_replay_repair', data_get($payload, 'signals.competitive_repair_plan.repair_strategy'));
        $this->assertSame('repair_competitive_dimension_product_intent_fit', data_get($payload, 'signals.competitive_repair_plan.repair_steps.1.id'));
        $this->assertSame(2, data_get($payload, 'signals.competitive_repair_plan.repair_steps.1.competitive_gap.points_to_lead'));
        $this->assertSame(11, data_get($payload, 'signals.competitive_repair_plan.repair_steps.1.competitive_gap.target_score_to_lead'));
        $this->assertTrue((bool) data_get($payload, 'signals.competitive_repair_plan.repair_steps.1.competitive_gap.lead_possible_within_rubric'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($payload, 'evidence_hashes.competitive_repair_plan_hash'));
    }

    public function test_control_plane_blocks_company_dispatch_when_gauntlet_fails(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-control-plane-missing-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($workspace);

        $payload = app(AtlasFrontendControlPlaneService::class)->snapshot([
            'task' => 'Criar redesign premium SaaS novo',
            'workspace' => $workspace,
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertFalse((bool) data_get($payload, 'claim_policy.provider_dispatch_allowed'));
        $this->assertContains('company_repo_gauntlet_blocked', $payload['blockers']);
        $this->assertContains('fill_company_design_dossier_docs', $payload['required_next_actions']);
        $this->assertContains('generate_or_write_product_blueprint', $payload['required_next_actions']);
    }

    public function test_control_plane_carries_frontend_app_scope_into_gauntlet_signal(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-control-plane-monorepo-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($workspace.'/apps/web');

        $payload = app(AtlasFrontendControlPlaneService::class)->snapshot([
            'task' => 'Ajustar checkout web',
            'workspace' => $workspace,
            'frontend_app' => 'apps/web',
        ]);

        $this->assertSame(hash('sha256', 'apps/web'), data_get($payload, 'input_scope.frontend_app_hash'));
        $this->assertSame('subscope_selected', data_get($payload, 'signals.gauntlet.frontend_app_scope.status'));
        $this->assertSame('apps/web', data_get($payload, 'signals.gauntlet.frontend_app_scope.relative_name'));
        $this->assertTrue((bool) data_get($payload, 'signals.gauntlet.frontend_app_scope.repo_workspace_remains_primary'));
        $this->assertStringNotContainsString($workspace.'/apps/web', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function tiedReplayDirectory(): string
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-control-plane-tied-replay-'.bin2hex(random_bytes(4));
        app(AtlasFrontendRivalReplayHarnessService::class)->writeTemplate($dir);

        foreach (['saas_dashboard_repair', 'ecommerce_product_page', 'mobile_app_onboarding', 'design_system_migration', 'live_mode_repair_loop'] as $case) {
            $taskSpec = json_decode(File::get($dir.'/'.$case.'/task-spec.json'), true);
            $taskSpecHash = (string) $taskSpec['task_spec_hash'];
            foreach (['atlas_frontend', 'pbakaus_impeccable', 'claude_design_plugin'] as $system) {
                $score = 80;
                $breakdown = $this->scoreBreakdown($score);
                $hashes = $this->writeEvidencePack($dir, $case, $system, $taskSpecHash);
                File::put($dir.'/'.$case.'/'.$system.'/manifest.json', json_encode([
                    'case_id' => $case,
                    'system' => $system,
                    'status' => 'complete',
                    'run_id' => $case.'-'.$system,
                    'task_spec_hash' => $taskSpecHash,
                    'task_spec_ref' => '../task-spec.json',
                    'evidence_pack_ref' => 'evidence/evidence-pack.json',
                    'output_artifact_ref' => 'artifact://'.$case.'/'.$system,
                    'output_artifact_hash' => $hashes['output_artifact'],
                    'screenshot_hashes' => [$hashes['screenshot_set']],
                    'anti_slop_report_hash' => $hashes['anti_slop_report'],
                    'verification_hashes' => [$hashes['verification_report']],
                    'external_execution_receipt' => $system !== 'atlas_frontend' ? $this->externalExecutionReceipt($case, $system, $hashes) : null,
                    'score_breakdown' => $breakdown,
                    'score_total' => $score,
                    'score_max' => 100,
                    'score_attestation' => $this->scoreAttestation($case, $system, $hashes, $breakdown, $score),
                    'completed_at' => '2026-05-25T00:00:00Z',
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
            }
        }

        return $dir;
    }

    private function dimensionGapReplayDirectory(): string
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-control-plane-dimension-gap-replay-'.bin2hex(random_bytes(4));
        app(AtlasFrontendRivalReplayHarnessService::class)->writeTemplate($dir);

        foreach (['saas_dashboard_repair', 'ecommerce_product_page', 'mobile_app_onboarding', 'design_system_migration', 'live_mode_repair_loop'] as $case) {
            $taskSpec = json_decode(File::get($dir.'/'.$case.'/task-spec.json'), true);
            $taskSpecHash = (string) $taskSpec['task_spec_hash'];
            foreach (['atlas_frontend', 'pbakaus_impeccable', 'claude_design_plugin'] as $system) {
                $score = match ($system) {
                    'atlas_frontend' => 90,
                    'pbakaus_impeccable' => 89,
                    default => 80,
                };
                $breakdown = match ($system) {
                    'atlas_frontend' => $this->dimensionGapBreakdown(9, 3),
                    'pbakaus_impeccable' => $this->dimensionGapBreakdown(10, 1),
                    default => $this->scoreBreakdown($score),
                };
                $hashes = $this->writeEvidencePack($dir, $case, $system, $taskSpecHash);
                File::put($dir.'/'.$case.'/'.$system.'/manifest.json', json_encode([
                    'case_id' => $case,
                    'system' => $system,
                    'status' => 'complete',
                    'run_id' => $case.'-'.$system,
                    'task_spec_hash' => $taskSpecHash,
                    'task_spec_ref' => '../task-spec.json',
                    'evidence_pack_ref' => 'evidence/evidence-pack.json',
                    'output_artifact_ref' => 'artifact://'.$case.'/'.$system,
                    'output_artifact_hash' => $hashes['output_artifact'],
                    'screenshot_hashes' => [$hashes['screenshot_set']],
                    'anti_slop_report_hash' => $hashes['anti_slop_report'],
                    'verification_hashes' => [$hashes['verification_report']],
                    'external_execution_receipt' => $system !== 'atlas_frontend' ? $this->externalExecutionReceipt($case, $system, $hashes) : null,
                    'score_breakdown' => $breakdown,
                    'score_total' => $score,
                    'score_max' => 100,
                    'score_attestation' => $this->scoreAttestation($case, $system, $hashes, $breakdown, $score),
                    'completed_at' => '2026-05-25T00:00:00Z',
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
            }
        }

        return $dir;
    }

    /**
     * @return array<string,int>
     */
    private function scoreBreakdown(int $score): array
    {
        return [
            'product_intent_fit' => 12,
            'visual_hierarchy_and_information_architecture' => 12,
            'composition_layout_and_spacing' => 10,
            'interaction_states_and_workflow_ergonomics' => 10,
            'responsive_multi_viewport_quality' => 10,
            'accessibility_and_semantics' => 10,
            'implementation_integrity' => 10,
            'performance_and_runtime_budget' => max(0, $score - 74),
            'anti_slop_originality_and_brand_fit' => 0,
            'evidence_completeness' => 0,
        ];
    }

    /**
     * @return array<string,int>
     */
    private function dimensionGapBreakdown(int $productIntentFit, int $evidenceCompleteness): array
    {
        return [
            'product_intent_fit' => $productIntentFit,
            'visual_hierarchy_and_information_architecture' => 12,
            'composition_layout_and_spacing' => 10,
            'interaction_states_and_workflow_ergonomics' => 10,
            'responsive_multi_viewport_quality' => 10,
            'accessibility_and_semantics' => 10,
            'implementation_integrity' => 10,
            'performance_and_runtime_budget' => 8,
            'anti_slop_originality_and_brand_fit' => 8,
            'evidence_completeness' => $evidenceCompleteness,
        ];
    }

    /**
     * @param  array<string,string>  $hashes
     * @return array<string,mixed>
     */
    private function externalExecutionReceipt(string $case, string $system, array $hashes): array
    {
        return [
            'schema_version' => AtlasFrontendRivalReplayHarnessService::EXTERNAL_EXECUTION_RECEIPT_SCHEMA_VERSION,
            'status' => 'verified',
            'case_id' => $case,
            'system' => $system,
            'execution_surface' => 'external_rival_system',
            'captured_at' => '2026-05-25T00:00:00Z',
            'operator_approved' => true,
            'manifest_hashes' => [
                'output_artifact_hash' => $hashes['output_artifact'],
                'screenshot_hashes' => [$hashes['screenshot_set']],
                'anti_slop_report_hash' => $hashes['anti_slop_report'],
                'verification_hashes' => [$hashes['verification_report']],
                'evidence_pack_verification_hash' => $hashes['evidence_pack_verification'],
            ],
        ];
    }

    /**
     * @param  array<string,string>  $hashes
     * @param  array<string,int>  $breakdown
     * @return array<string,mixed>
     */
    private function scoreAttestation(string $case, string $system, array $hashes, array $breakdown, int $score): array
    {
        return [
            'schema_version' => AtlasFrontendRivalReplayHarnessService::SCORE_ATTESTATION_SCHEMA_VERSION,
            'status' => 'verified',
            'case_id' => $case,
            'system' => $system,
            'scoring_surface' => 'manual_competitive_review',
            'reviewer_ref_hash' => hash('sha256', 'control-plane-reviewer-'.$case.'-'.$system),
            'reviewed_at' => '2026-05-25T00:00:00Z',
            'operator_approved' => true,
            'rubric_hash' => app(AtlasFrontendCompetitiveRubricService::class)->rubric()['rubric_hash'],
            'score_breakdown_hash' => MissionCanonicalHash::sha256($breakdown),
            'score_total' => $score,
            'score_max' => 100,
            'evidence_pack_verification_hash' => $hashes['evidence_pack_verification'],
            'reviewed_manifest_hashes' => [
                'output_artifact_hash' => $hashes['output_artifact'],
                'screenshot_hashes' => [$hashes['screenshot_set']],
                'anti_slop_report_hash' => $hashes['anti_slop_report'],
                'verification_hashes' => [$hashes['verification_report']],
                'run_packet_hash' => null,
                'evidence_pack_verification_hash' => $hashes['evidence_pack_verification'],
            ],
        ];
    }

    /**
     * @return array<string,string>
     */
    private function writeEvidencePack(string $dir, string $case, string $system, string $taskSpecHash): array
    {
        $root = $dir.'/'.$case.'/'.$system;
        $artifactDir = $root.'/evidence/artifacts';
        File::ensureDirectoryExists($artifactDir);
        $hashes = [];

        foreach (app(AtlasFrontendEvidencePackVerifierService::class)->requiredArtifactKinds() as $kind) {
            $path = $artifactDir.'/'.$kind.'.json';
            File::put($path, json_encode([
                'kind' => $kind,
                'case_id' => $case,
                'system' => $system,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
            $hashes[$kind] = hash_file('sha256', $path);
        }

        File::put($root.'/evidence/evidence-pack.json', json_encode([
            'schema_version' => 'atlas.frontend.evidence_pack.v1',
            'pack_id' => $case.'-'.$system,
            'case_id' => $case,
            'system' => $system,
            'task_spec_hash' => $taskSpecHash,
            'artifacts' => array_map(fn (string $kind): array => [
                'kind' => $kind,
                'path' => 'artifacts/'.$kind.'.json',
                'sha256' => $hashes[$kind],
            ], array_keys($hashes)),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $verification = app(AtlasFrontendEvidencePackVerifierService::class)
            ->verify($root.'/evidence/evidence-pack.json');
        $hashes['evidence_pack_verification'] = (string) ($verification['verification_hash'] ?? '');

        return $hashes;
    }
}
