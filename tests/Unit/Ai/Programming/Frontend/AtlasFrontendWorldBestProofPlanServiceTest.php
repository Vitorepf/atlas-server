<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\Frontend\AtlasFrontendCompetitiveRubricService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendEvidencePackVerifierService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendProductProofRuntimeService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendPublicationVerifierService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendRivalReplayHarnessService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendWorldBestProofPlanService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendWorldBestProofPlanServiceTest extends TestCase
{
    public function test_plan_turns_missing_market_proof_into_executable_workstreams(): void
    {
        $payload = app(AtlasFrontendWorldBestProofPlanService::class)->plan();

        $this->assertSame(AtlasFrontendWorldBestProofPlanService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready_for_execution', $payload['status']);
        $this->assertFalse((bool) data_get($payload, 'claim_policy.world_best_claim_allowed'));
        $this->assertFalse((bool) data_get($payload, 'readiness.external_rival_replay_completed'));
        $this->assertSame('not_requested', data_get($payload, 'readiness.publication_attestation_status'));
        $this->assertSame('pending', data_get($payload, 'readiness.evidence_pack_readiness.status'));
        $this->assertSame(15, data_get($payload, 'readiness.evidence_pack_readiness.summary.missing'));
        $this->assertSame('pending', data_get($payload, 'workstreams.0.evidence_pack_readiness.status'));
        $this->assertSame('atlas.frontend.rival_replay_evidence_worklist.v1', data_get($payload, 'workstreams.0.evidence_worklist.schema_version'));
        $this->assertSame('pending', data_get($payload, 'workstreams.0.evidence_worklist.status'));
        $this->assertSame(15, data_get($payload, 'workstreams.0.evidence_worklist.work_item_count'));
        $this->assertFalse((bool) data_get($payload, 'workstreams.0.evidence_worklist.write_performed'));
        $this->assertTrue((bool) data_get($payload, 'workstreams.0.evidence_worklist.claim_policy.worklist_is_not_evidence'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($payload, 'evidence_hashes.evidence_worklist_hash'));
        $this->assertCount(15, data_get($payload, 'workstreams.0.work_items'));
        $this->assertContains('php artisan atlas:frontend:replay runner-kit --output=<dir> --json', data_get($payload, 'workstreams.0.commands'));
        $this->assertContains('php artisan atlas:frontend:replay evidence-worklist --evidence=<dir> --output=<worklist.json> --json', data_get($payload, 'workstreams.0.commands'));
        $this->assertContains('php artisan atlas:frontend:publish attest --bundle=<bundle> --receipt=<receipt> --json', data_get($payload, 'workstreams.1.work_items.0.commands'));
        $this->assertContains('task_spec_ref', data_get($payload, 'workstreams.0.work_items.0.required_manifest_fields'));
        $this->assertContains('evidence_pack_ref', data_get($payload, 'workstreams.0.work_items.0.required_manifest_fields'));
        $this->assertContains('score_attestation', data_get($payload, 'workstreams.0.required_artifacts'));
        $this->assertContains('external_rival_execution_receipts', data_get($payload, 'workstreams.0.required_artifacts'));
        $this->assertContains('score_attestation', data_get($payload, 'workstreams.0.work_items.0.required_manifest_fields'));
        $this->assertContains('score_attestation', data_get($payload, 'workstreams.0.work_items.0.required_receipts'));
        $this->assertContains('external_execution_receipt', data_get($payload, 'workstreams.0.work_items.1.required_manifest_fields'));
        $this->assertContains('external_execution_receipt', data_get($payload, 'workstreams.0.work_items.1.required_receipts'));
        $this->assertSame('missing', data_get($payload, 'workstreams.0.work_items.0.evidence_pack_status'));
        $this->assertContains('evidence_pack_missing', data_get($payload, 'workstreams.0.work_items.0.evidence_pack_blockers'));
        $this->assertContains('generate_rival_replay_runner_kit', $payload['required_next_actions']);
        $this->assertContains('complete_external_rival_replay_manifests', $payload['required_next_actions']);
        $this->assertContains('fill_and_verify_rival_replay_evidence_packs', $payload['required_next_actions']);
        $this->assertContains('verify_public_product_proof_distribution', $payload['required_next_actions']);
        $this->assertSame('not_requested', data_get($payload, 'workstreams.1.publication_attestation.status'));
        $this->assertTrue((bool) data_get($payload, 'workstreams.1.publication_attestation.claim_policy.local_bundle_is_not_public_distribution'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($payload, 'evidence_hashes.publication_attestation_hash'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $payload['proof_plan_hash']);
    }

    public function test_plan_allows_world_best_only_when_replay_and_public_distribution_are_verified(): void
    {
        $replayDir = $this->winningReplayDirectory();
        $bundle = sys_get_temp_dir().'/atlas-frontend-world-best-bundle-'.bin2hex(random_bytes(4));
        $manifest = app(AtlasFrontendProductProofRuntimeService::class)->buildStaticBundle($bundle);
        $receipt = $bundle.'/publication-receipt.json';
        File::put($receipt, json_encode([
            'schema_version' => AtlasFrontendPublicationVerifierService::RECEIPT_SCHEMA_VERSION,
            'status' => 'verified',
            'public_url' => 'https://example.com/atlas-frontend-proof/',
            'bundle_hash' => $manifest['bundle_hash'],
            'index_content_hash' => hash_file('sha256', $bundle.'/index.html'),
            'http_status' => 200,
            'checked_at' => '2026-05-25T00:00:00Z',
            'operator_approved' => true,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $payload = app(AtlasFrontendWorldBestProofPlanService::class)->plan([
            'rival_evidence' => $replayDir,
            'bundle' => $bundle,
            'publication_receipt' => $receipt,
        ]);

        $this->assertSame('ready', $payload['status']);
        $this->assertTrue((bool) data_get($payload, 'claim_policy.world_best_claim_allowed'));
        $this->assertTrue((bool) data_get($payload, 'readiness.external_rival_replay_completed'));
        $this->assertTrue((bool) data_get($payload, 'readiness.public_distribution_verified'));
        $this->assertSame('public_verified', data_get($payload, 'readiness.publication_attestation_status'));
        $this->assertSame('public_verified', data_get($payload, 'workstreams.1.publication_attestation.status'));
        $this->assertTrue((bool) data_get($payload, 'workstreams.1.publication_attestation.claim_policy.public_distribution_claim_allowed'));
        $this->assertNull(data_get($payload, 'workstreams.1.publication_attestation.public_url'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($payload, 'workstreams.1.publication_attestation.public_url_hash'));
        $this->assertSame([], data_get($payload, 'workstreams.0.work_items'));
        $this->assertSame(['claim_world_best_only_with_attached_proof_plan_hash'], $payload['required_next_actions']);
    }

    public function test_plan_attests_local_publication_bundle_as_pending_public_distribution(): void
    {
        $bundle = sys_get_temp_dir().'/atlas-frontend-world-best-local-bundle-'.bin2hex(random_bytes(4));
        app(AtlasFrontendProductProofRuntimeService::class)->buildStaticBundle($bundle);

        $payload = app(AtlasFrontendWorldBestProofPlanService::class)->plan([
            'bundle' => $bundle,
        ]);

        $this->assertSame('ready_for_execution', $payload['status']);
        $this->assertFalse((bool) data_get($payload, 'claim_policy.world_best_claim_allowed'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.local_publication_report_is_not_public_distribution'));
        $this->assertSame('local_bundle_ready_publication_pending', data_get($payload, 'readiness.publication_attestation_status'));
        $this->assertSame('local_bundle_ready_publication_pending', data_get($payload, 'workstreams.1.publication_attestation.status'));
        $this->assertFalse((bool) data_get($payload, 'workstreams.1.publication_attestation.claim_policy.public_distribution_claim_allowed'));
        $this->assertContains('provide_operator_approved_publication_receipt', data_get($payload, 'workstreams.1.publication_attestation.required_next_actions'));
    }

    public function test_plan_turns_completed_losing_replay_into_improvement_actions(): void
    {
        $payload = app(AtlasFrontendWorldBestProofPlanService::class)->plan([
            'rival_evidence' => $this->losingReplayDirectory(),
        ]);

        $this->assertSame('ready_for_execution', $payload['status']);
        $this->assertFalse((bool) data_get($payload, 'claim_policy.world_best_claim_allowed'));
        $this->assertTrue((bool) data_get($payload, 'readiness.external_rival_replay_completed'));
        $this->assertSame('atlas_needs_improvement', data_get($payload, 'readiness.competitive_diagnostics_status'));
        $this->assertSame(1, data_get($payload, 'readiness.competitive_losing_case_count'));
        $this->assertSame('atlas_needs_improvement', data_get($payload, 'workstreams.0.competitive_diagnostics.status'));
        $this->assertSame('atlas.frontend.repair_plan.v1', data_get($payload, 'workstreams.0.competitive_repair_plan.schema_version'));
        $this->assertSame('competitive_replay_repair', data_get($payload, 'workstreams.0.competitive_repair_plan.repair_strategy'));
        $repairStepIds = collect(data_get($payload, 'workstreams.0.competitive_repair_plan.repair_steps'))->pluck('id')->all();
        $this->assertContains('repair_competitive_replay_gap', $repairStepIds);
        $this->assertContains('repair_competitive_dimension_anti_slop_originality_and_brand_fit', $repairStepIds);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($payload, 'evidence_hashes.competitive_repair_plan_hash'));
        $this->assertContains('atlas_does_not_win_every_complete_case', $payload['warnings']);
        $this->assertContains('improve_atlas_frontend_until_replay_wins_every_case', $payload['required_next_actions']);
    }

    private function winningReplayDirectory(): string
    {
        return $this->replayDirectory(atlasScore: 90, rivalScore: 80);
    }

    private function losingReplayDirectory(): string
    {
        return $this->replayDirectory(atlasScore: 80, rivalScore: 90, losingCase: 'live_mode_repair_loop');
    }

    private function replayDirectory(int $atlasScore, int $rivalScore, ?string $losingCase = null): string
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-world-best-replay-'.bin2hex(random_bytes(4));
        app(AtlasFrontendRivalReplayHarnessService::class)->writeTemplate($dir);

        foreach (['saas_dashboard_repair', 'ecommerce_product_page', 'mobile_app_onboarding', 'design_system_migration', 'live_mode_repair_loop'] as $case) {
            $taskSpec = json_decode(File::get($dir.'/'.$case.'/task-spec.json'), true);
            $taskSpecHash = (string) $taskSpec['task_spec_hash'];
            foreach (['atlas_frontend', 'pbakaus_impeccable', 'claude_design_plugin'] as $system) {
                $score = $system === 'atlas_frontend'
                    ? $atlasScore
                    : ($case === $losingCase && $system === 'pbakaus_impeccable' ? $rivalScore : 80);
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
                    'score_breakdown' => $this->scoreBreakdown($score),
                    'score_total' => $score,
                    'score_max' => 100,
                    'score_attestation' => $this->scoreAttestation($dir, $case, $system, $this->scoreBreakdown($score), $score),
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
        if ($score === 90) {
            return [
                'product_intent_fit' => 12,
                'visual_hierarchy_and_information_architecture' => 12,
                'composition_layout_and_spacing' => 10,
                'interaction_states_and_workflow_ergonomics' => 10,
                'responsive_multi_viewport_quality' => 10,
                'accessibility_and_semantics' => 10,
                'implementation_integrity' => 10,
                'performance_and_runtime_budget' => 8,
                'anti_slop_originality_and_brand_fit' => 8,
                'evidence_completeness' => 0,
            ];
        }

        return [
            'product_intent_fit' => 12,
            'visual_hierarchy_and_information_architecture' => 12,
            'composition_layout_and_spacing' => 10,
            'interaction_states_and_workflow_ergonomics' => 10,
            'responsive_multi_viewport_quality' => 10,
            'accessibility_and_semantics' => 10,
            'implementation_integrity' => 10,
            'performance_and_runtime_budget' => 6,
            'anti_slop_originality_and_brand_fit' => 0,
            'evidence_completeness' => 0,
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
     * @param  array<string,int>  $breakdown
     * @return array<string,mixed>
     */
    private function scoreAttestation(string $dir, string $case, string $system, array $breakdown, int $score): array
    {
        $verification = app(AtlasFrontendEvidencePackVerifierService::class)
            ->verify($dir.'/'.$case.'/'.$system.'/evidence/evidence-pack.json');

        return [
            'schema_version' => AtlasFrontendRivalReplayHarnessService::SCORE_ATTESTATION_SCHEMA_VERSION,
            'status' => 'verified',
            'case_id' => $case,
            'system' => $system,
            'scoring_surface' => 'manual_competitive_review',
            'reviewer_ref_hash' => hash('sha256', 'world-best-reviewer-'.$case.'-'.$system),
            'reviewed_at' => '2026-05-25T00:00:00Z',
            'operator_approved' => true,
            'rubric_hash' => app(AtlasFrontendCompetitiveRubricService::class)->rubric()['rubric_hash'],
            'score_breakdown_hash' => MissionCanonicalHash::sha256($breakdown),
            'score_total' => $score,
            'score_max' => 100,
            'evidence_pack_verification_hash' => $verification['verification_hash'] ?? null,
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

        foreach ([
            'output_artifact',
            'screenshot_set',
            'design_5d_review',
            'quality_budget_report',
            'anti_slop_report',
            'verification_report',
            'console_report',
            'a11y_or_reason',
            'performance_or_reason',
            'receipt',
        ] as $kind) {
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
