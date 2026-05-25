<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendRivalReplayHarnessService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendRivalReplayHarnessServiceTest extends TestCase
{
    public function test_replay_matrix_is_ready_for_replay_without_external_evidence(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-replay-missing-'.bin2hex(random_bytes(4));

        $payload = app(AtlasFrontendRivalReplayHarnessService::class)->inspect($dir);

        $this->assertSame('atlas.frontend.rival_replay_harness.v1', $payload['schema_version']);
        $this->assertSame('ready_for_replay', $payload['status']);
        $this->assertFalse((bool) data_get($payload, 'summary.external_replay_completed'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.may_claim_world_best_frontend_system'));
        $this->assertCount(15, $payload['runs']);
        $this->assertContains('external_rival_replay_artifacts_required_for_world_best_claim', $payload['remaining_gaps']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $payload['replay_hash']);
    }

    public function test_template_writes_pending_manifests_without_authorizing_claims(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-replay-template-'.bin2hex(random_bytes(4));
        $service = app(AtlasFrontendRivalReplayHarnessService::class);

        $template = $service->writeTemplate($dir);
        $inspect = $service->inspect($dir);

        $this->assertSame('atlas.frontend.rival_replay_template.v1', $template['schema_version']);
        $this->assertSame(15, $template['created_count']);
        $this->assertTrue(File::isFile($dir.'/saas_dashboard_repair/atlas_frontend/manifest.json'));
        $this->assertSame('ready_for_replay', $inspect['status']);
        $this->assertFalse((bool) data_get($inspect, 'claim_policy.may_claim_external_replay_completed'));
    }

    public function test_complete_manifest_is_invalid_without_competitive_score_breakdown(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-replay-invalid-'.bin2hex(random_bytes(4));
        $service = app(AtlasFrontendRivalReplayHarnessService::class);
        $service->writeTemplate($dir);

        File::put($dir.'/saas_dashboard_repair/atlas_frontend/manifest.json', json_encode([
            'case_id' => 'saas_dashboard_repair',
            'system' => 'atlas_frontend',
            'status' => 'complete',
            'run_id' => 'atlas-run',
            'task_spec_hash' => hash('sha256', 'task'),
            'output_artifact_ref' => 'artifact://atlas',
            'output_artifact_hash' => hash('sha256', 'artifact'),
            'screenshot_hashes' => [hash('sha256', 'screenshot')],
            'anti_slop_report_hash' => hash('sha256', 'anti-slop'),
            'verification_hashes' => [hash('sha256', 'verification')],
            'score_total' => 90,
            'score_max' => 100,
            'completed_at' => '2026-05-25T00:00:00Z',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $payload = $service->inspect($dir);
        $run = collect($payload['runs'])->firstWhere('case_id', 'saas_dashboard_repair');

        $this->assertSame('invalid', $run['status']);
        $this->assertContains('score_breakdown_required', $run['issues']);
    }

    public function test_complete_manifests_allow_external_replay_but_not_world_best_when_atlas_loses(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-replay-complete-'.bin2hex(random_bytes(4));
        $service = app(AtlasFrontendRivalReplayHarnessService::class);
        $service->writeTemplate($dir);

        foreach (['saas_dashboard_repair', 'ecommerce_product_page', 'mobile_app_onboarding', 'design_system_migration', 'live_mode_repair_loop'] as $case) {
            foreach (['atlas_frontend', 'pbakaus_impeccable', 'claude_design_plugin'] as $system) {
                $score = $system === 'pbakaus_impeccable' && $case === 'live_mode_repair_loop' ? 10 : 9;
                File::put($dir.'/'.$case.'/'.$system.'/manifest.json', json_encode([
                    'case_id' => $case,
                    'system' => $system,
                    'status' => 'complete',
                    'run_id' => $case.'-'.$system,
                    'task_spec_hash' => hash('sha256', $case),
                    'output_artifact_ref' => 'artifact://'.$case.'/'.$system,
                    'output_artifact_hash' => hash('sha256', $case.$system.'artifact'),
                    'screenshot_hashes' => [hash('sha256', $case.$system.'screenshot')],
                    'anti_slop_report_hash' => hash('sha256', $case.$system.'anti-slop'),
                    'verification_hashes' => [hash('sha256', $case.$system.'verification')],
                    'score_breakdown' => $this->scoreBreakdown($score),
                    'score_total' => $score,
                    'score_max' => 100,
                    'completed_at' => '2026-05-25T00:00:00Z',
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
            }
        }

        $payload = $service->inspect($dir);

        $this->assertSame('ready', $payload['status']);
        $this->assertTrue((bool) data_get($payload, 'summary.external_replay_completed'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.may_claim_world_best_frontend_system'));
        $this->assertSame(45, data_get($payload, 'scoreboard.atlas_frontend.score'));
        $this->assertSame(46, data_get($payload, 'scoreboard.pbakaus_impeccable.score'));
    }

    /**
     * @return array<string,int>
     */
    private function scoreBreakdown(int $score): array
    {
        return [
            'product_intent_fit' => $score,
            'visual_hierarchy_and_information_architecture' => 0,
            'composition_layout_and_spacing' => 0,
            'interaction_states_and_workflow_ergonomics' => 0,
            'responsive_multi_viewport_quality' => 0,
            'accessibility_and_semantics' => 0,
            'implementation_integrity' => 0,
            'performance_and_runtime_budget' => 0,
            'anti_slop_originality_and_brand_fit' => 0,
            'evidence_completeness' => 0,
        ];
    }
}
