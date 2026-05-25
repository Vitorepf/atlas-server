<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\File;

final class AtlasFrontendBenchmarkRuntimeService
{
    public const SCHEMA_VERSION = 'atlas.frontend.benchmark_runtime.v1';

    /**
     * @return array<string,mixed>
     */
    public function run(): array
    {
        $scenarios = $this->scenarios();
        $systems = ['atlas_frontend', 'pbakaus_impeccable', 'claude_design_plugin'];
        $totals = [];
        $replay = app(AtlasFrontendRivalReplayHarnessService::class)->inspect();

        foreach ($systems as $system) {
            $totals[$system] = [
                'score' => array_sum(array_map(fn (array $scenario): int => (int) $scenario['scores'][$system], $scenarios)),
                'max' => array_sum(array_column($scenarios, 'weight')),
            ];
            $totals[$system]['percent'] = round(($totals[$system]['score'] / max(1, $totals[$system]['max'])) * 100, 2);
        }

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ready',
            'benchmark_type' => 'documentation_backed_static_runtime_matrix',
            'source' => self::class,
            'scope' => [
                'uses_live_external_rival_execution' => false,
                'rival_replay_harness_present' => true,
                'external_rival_replay_completed' => (bool) data_get($replay, 'summary.external_replay_completed'),
                'uses_paid_provider_accounts' => false,
                'uses_local_atlas_runtime_evidence' => true,
                'raw_prompts_or_customer_source_returned' => false,
            ],
            'systems' => $systems,
            'scenarios' => $scenarios,
            'totals' => $totals,
            'claims' => [
                'atlas_more_complete_than_impeccable_on_governed_delivery_contract' => $totals['atlas_frontend']['score'] > $totals['pbakaus_impeccable']['score'],
                'atlas_more_complete_than_claude_design_plugin_on_governed_delivery_contract' => $totals['atlas_frontend']['score'] > $totals['claude_design_plugin']['score'],
                'atlas_live_mode_superior_to_impeccable' => false,
                'atlas_world_best_frontend_system' => false,
            ],
            'remaining_gaps' => [
                'external_rival_replay_artifacts_required_for_world_best_claim',
                'public_product_demos_required_for_distribution_superiority',
            ],
            'rival_replay' => [
                'schema_version' => AtlasFrontendRivalReplayHarnessService::SCHEMA_VERSION,
                'status' => $replay['status'],
                'summary' => $replay['summary'],
                'claim_policy' => $replay['claim_policy'],
                'replay_hash' => $replay['replay_hash'],
            ],
            'evidence' => $this->evidence(),
        ];
        $payload['benchmark_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function scenarios(): array
    {
        return [
            $this->scenario('governed_delivery_runtime', 10, [
                'atlas_frontend' => 10,
                'pbakaus_impeccable' => 5,
                'claude_design_plugin' => 4,
            ], 'Atlas has Dev/Forge/certification hooks; rivals are primarily skill/plugin workflows.'),
            $this->scenario('anti_slop_detection', 10, [
                'atlas_frontend' => 9,
                'pbakaus_impeccable' => 9,
                'claude_design_plugin' => 5,
            ], 'Atlas now has deterministic detector; Impeccable has mature detector/reference implementation.'),
            $this->scenario('live_visual_iteration', 10, [
                'atlas_frontend' => 9,
                'pbakaus_impeccable' => 10,
                'claude_design_plugin' => 6,
            ], 'Atlas has picker, framework adapter contract, preview relay, source patch and recovery; rival replay is still required.'),
            $this->scenario('production_patch_evidence', 10, [
                'atlas_frontend' => 10,
                'pbakaus_impeccable' => 6,
                'claude_design_plugin' => 5,
            ], 'Atlas requires tests, visual smoke, receipts, Dev/Forge evidence and honest certification.'),
            $this->scenario('multi_company_design_system_adaptation', 10, [
                'atlas_frontend' => 10,
                'pbakaus_impeccable' => 7,
                'claude_design_plugin' => 6,
            ], 'Atlas models multi-company profiles, local design-system inventory, component/token evidence and drift gates.'),
            $this->scenario('provider_neutrality_and_portability', 10, [
                'atlas_frontend' => 10,
                'pbakaus_impeccable' => 8,
                'claude_design_plugin' => 3,
            ], 'Atlas runtime is provider-neutral; Claude Design is provider-bound.'),
            $this->scenario('product_distribution_and_public_proof', 10, [
                'atlas_frontend' => 8,
                'pbakaus_impeccable' => 9,
                'claude_design_plugin' => 8,
            ], 'Atlas has local product proof catalog, demo manifests and static publishable bundle; hosted public demos are still required for distribution superiority.'),
            $this->scenario('outcome_memory_and_learning', 10, [
                'atlas_frontend' => 9,
                'pbakaus_impeccable' => 4,
                'claude_design_plugin' => 4,
            ], 'Atlas carries outcome memory and certification hooks as product runtime primitives.'),
        ];
    }

    /**
     * @param  array<string,int>  $scores
     * @return array<string,mixed>
     */
    private function scenario(string $id, int $weight, array $scores, string $reason): array
    {
        return [
            'id' => $id,
            'weight' => $weight,
            'scores' => $scores,
            'winner' => array_search(max($scores), $scores, true),
            'reason' => $reason,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function evidence(): array
    {
        $paths = [
            'frontend_contract' => 'app/Services/Ai/Programming/Frontend/AtlasFrontendDesignRuntimeService.php',
            'task_spec_compiler' => 'app/Services/Ai/Programming/Frontend/AtlasFrontendTaskSpecCompilerService.php',
            'anti_slop_detector' => 'app/Services/Ai/Programming/Frontend/AtlasFrontendAntiSlopDetectorService.php',
            'browser_bridge' => 'app/Services/Ai/Programming/Frontend/AtlasFrontendBrowserBridgeService.php',
            'framework_adapter' => 'app/Services/Ai/Programming/Frontend/AtlasFrontendFrameworkAdapterRuntimeService.php',
            'design_system_inventory' => 'app/Services/Ai/Programming/Frontend/AtlasFrontendDesignSystemInventoryService.php',
            'execution_gate' => 'app/Services/Ai/Programming/Frontend/AtlasFrontendExecutionGateService.php',
            'repair_planner' => 'app/Services/Ai/Programming/Frontend/AtlasFrontendRepairPlannerService.php',
            'run_certification' => 'app/Services/Ai/Programming/Frontend/AtlasFrontendRunCertificationService.php',
            'live_preview_relay' => 'app/Services/Ai/Programming/Frontend/AtlasFrontendLivePreviewRelayService.php',
            'source_patch_runtime' => 'app/Services/Ai/Programming/Frontend/AtlasFrontendLiveSourcePatchRuntimeService.php',
            'product_proof' => 'app/Services/Ai/Programming/Frontend/AtlasFrontendProductProofRuntimeService.php',
            'company_design_profile' => 'app/Services/Ai/Programming/Frontend/AtlasFrontendCompanyDesignProfileService.php',
            'design_direction_advisor' => 'app/Services/Ai/Programming/Frontend/AtlasFrontendDesignDirectionAdvisorService.php',
            'asset_pack_verifier' => 'app/Services/Ai/Programming/Frontend/AtlasFrontendAssetPackService.php',
            'design_review' => 'app/Services/Ai/Programming/Frontend/AtlasFrontendDesignReviewService.php',
            'visual_quality_gate' => 'app/Services/Ai/Programming/Frontend/AtlasFrontendVisualQualityGateService.php',
            'design_system_drift_gate' => 'app/Services/Ai/Programming/Frontend/AtlasFrontendDesignSystemDriftGateService.php',
            'publication_verifier' => 'app/Services/Ai/Programming/Frontend/AtlasFrontendPublicationVerifierService.php',
            'evidence_pack_verifier' => 'app/Services/Ai/Programming/Frontend/AtlasFrontendEvidencePackVerifierService.php',
            'outcome_memory' => 'app/Services/Ai/Programming/Frontend/AtlasFrontendOutcomeMemoryService.php',
            'competitive_rubric' => 'app/Services/Ai/Programming/Frontend/AtlasFrontendCompetitiveRubricService.php',
            'rival_replay_harness' => 'app/Services/Ai/Programming/Frontend/AtlasFrontendRivalReplayHarnessService.php',
            'frontend_doc' => 'docs/engineering-knowledge-base/domains/programming-frontend-superpower.md',
            'impeccable_coverage_audit' => 'docs/engineering-knowledge-base/domains/programming-frontend-impeccable-coverage-audit.md',
        ];

        return collect($paths)
            ->map(fn (string $path): array => [
                'path' => $path,
                'present' => File::isFile(base_path($path)),
                'content_hash' => File::isFile(base_path($path)) ? hash('sha256', File::get(base_path($path))) : null,
            ])
            ->all();
    }
}
