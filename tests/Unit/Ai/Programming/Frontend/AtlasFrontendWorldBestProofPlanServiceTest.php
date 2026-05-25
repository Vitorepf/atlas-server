<?php

namespace Tests\Unit\Ai\Programming\Frontend;

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
        $this->assertSame('pending', data_get($payload, 'readiness.evidence_pack_readiness.status'));
        $this->assertSame(15, data_get($payload, 'readiness.evidence_pack_readiness.summary.missing'));
        $this->assertSame('pending', data_get($payload, 'workstreams.0.evidence_pack_readiness.status'));
        $this->assertCount(15, data_get($payload, 'workstreams.0.work_items'));
        $this->assertContains('php artisan atlas:frontend:replay runner-kit --output=<dir> --json', data_get($payload, 'workstreams.0.commands'));
        $this->assertContains('task_spec_ref', data_get($payload, 'workstreams.0.work_items.0.required_manifest_fields'));
        $this->assertContains('evidence_pack_ref', data_get($payload, 'workstreams.0.work_items.0.required_manifest_fields'));
        $this->assertSame('missing', data_get($payload, 'workstreams.0.work_items.0.evidence_pack_status'));
        $this->assertContains('evidence_pack_missing', data_get($payload, 'workstreams.0.work_items.0.evidence_pack_blockers'));
        $this->assertContains('generate_rival_replay_runner_kit', $payload['required_next_actions']);
        $this->assertContains('complete_external_rival_replay_manifests', $payload['required_next_actions']);
        $this->assertContains('fill_and_verify_rival_replay_evidence_packs', $payload['required_next_actions']);
        $this->assertContains('verify_public_product_proof_distribution', $payload['required_next_actions']);
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
        $this->assertSame([], data_get($payload, 'workstreams.0.work_items'));
        $this->assertSame(['claim_world_best_only_with_attached_proof_plan_hash'], $payload['required_next_actions']);
    }

    private function winningReplayDirectory(): string
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-world-best-replay-'.bin2hex(random_bytes(4));
        app(AtlasFrontendRivalReplayHarnessService::class)->writeTemplate($dir);

        foreach (['saas_dashboard_repair', 'ecommerce_product_page', 'mobile_app_onboarding', 'design_system_migration', 'live_mode_repair_loop'] as $case) {
            $taskSpec = json_decode(File::get($dir.'/'.$case.'/task-spec.json'), true);
            $taskSpecHash = (string) $taskSpec['task_spec_hash'];
            foreach (['atlas_frontend', 'pbakaus_impeccable', 'claude_design_plugin'] as $system) {
                $score = $system === 'atlas_frontend' ? 90 : 80;
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
                    'score_breakdown' => $this->scoreBreakdown($score),
                    'score_total' => $score,
                    'score_max' => 100,
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

        return $hashes;
    }
}
