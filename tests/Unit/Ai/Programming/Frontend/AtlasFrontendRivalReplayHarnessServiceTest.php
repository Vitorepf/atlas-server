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
        $this->assertSame('pending', data_get($payload, 'evidence_pack_readiness.status'));
        $this->assertSame(15, data_get($payload, 'evidence_pack_readiness.summary.missing'));
        $this->assertContains('external_rival_replay_artifacts_required_for_world_best_claim', $payload['remaining_gaps']);
        $this->assertContains('rival_replay_evidence_packs_incomplete', $payload['remaining_gaps']);
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
        $this->assertSame('atlas.frontend.rival_replay_task_spec.v1', $template['task_spec_schema_version']);
        $this->assertTrue(File::isFile($dir.'/saas_dashboard_repair/atlas_frontend/manifest.json'));
        $this->assertTrue(File::isFile($dir.'/saas_dashboard_repair/task-spec.json'));
        $this->assertSame('ready_for_replay', $inspect['status']);
        $this->assertFalse((bool) data_get($inspect, 'claim_policy.may_claim_external_replay_completed'));
    }

    public function test_template_preloads_same_task_spec_hash_for_each_system_in_case(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-replay-template-task-spec-'.bin2hex(random_bytes(4));
        $service = app(AtlasFrontendRivalReplayHarnessService::class);

        $service->writeTemplate($dir);

        $taskSpec = json_decode(File::get($dir.'/live_mode_repair_loop/task-spec.json'), true);
        $atlas = json_decode(File::get($dir.'/live_mode_repair_loop/atlas_frontend/manifest.json'), true);
        $impeccable = json_decode(File::get($dir.'/live_mode_repair_loop/pbakaus_impeccable/manifest.json'), true);
        $claude = json_decode(File::get($dir.'/live_mode_repair_loop/claude_design_plugin/manifest.json'), true);

        $this->assertSame('atlas.frontend.rival_replay_task_spec.v1', $taskSpec['schema_version']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $taskSpec['task_spec_hash']);
        $this->assertSame($taskSpec['task_spec_hash'], $atlas['task_spec_hash']);
        $this->assertSame($taskSpec['task_spec_hash'], $impeccable['task_spec_hash']);
        $this->assertSame($taskSpec['task_spec_hash'], $claude['task_spec_hash']);
        $this->assertSame('../task-spec.json', $atlas['task_spec_ref']);
        $this->assertContains('same_task_spec_hash_required_for_all_systems', array_keys($taskSpec['fairness_policy']));
    }

    public function test_runner_kit_writes_operational_replay_packets_without_authorizing_claims(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-replay-runner-kit-'.bin2hex(random_bytes(4));
        $service = app(AtlasFrontendRivalReplayHarnessService::class);

        $payload = $service->writeRunnerKit($dir);

        $this->assertSame('atlas.frontend.rival_replay_runner_kit.v1', $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame(15, $payload['run_packet_count']);
        $this->assertTrue(File::isFile($dir.'/replay-runner-kit.json'));
        $this->assertTrue(File::isFile($dir.'/live_mode_repair_loop/task-spec.json'));
        $this->assertTrue(File::isFile($dir.'/live_mode_repair_loop/pbakaus_impeccable/manifest.json'));
        $this->assertTrue(File::isFile($dir.'/live_mode_repair_loop/pbakaus_impeccable/evidence/evidence-pack.json'));
        $this->assertContains('live_mode_repair_loop/pbakaus_impeccable/evidence/evidence-pack.json', $payload['created_evidence_pack_refs']);
        $this->assertSame('live_mode_repair_loop/pbakaus_impeccable/evidence/evidence-pack.json', data_get($payload, 'run_packets.13.evidence_pack_ref'));
        $evidencePack = json_decode(File::get($dir.'/live_mode_repair_loop/pbakaus_impeccable/evidence/evidence-pack.json'), true);
        $taskSpec = json_decode(File::get($dir.'/live_mode_repair_loop/task-spec.json'), true);
        $this->assertSame('atlas.frontend.evidence_pack.v1', $evidencePack['schema_version']);
        $this->assertSame('live_mode_repair_loop', $evidencePack['case_id']);
        $this->assertSame('pbakaus_impeccable', $evidencePack['system']);
        $this->assertSame($taskSpec['task_spec_hash'], $evidencePack['task_spec_hash']);
        $this->assertCount(10, $evidencePack['artifacts']);
        $this->assertTrue((bool) data_get($payload, 'claim_policy.runner_kit_is_not_replay_evidence'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.raw_prompts_or_customer_source_returned'));
        $this->assertContains('evidence_pack_ref_verified', data_get($payload, 'run_packets.0.evidence_checklist'));
        $this->assertContains('no_raw_prompt_source_customer_data_tokens_or_cookies', data_get($payload, 'run_packets.0.evidence_checklist'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $payload['runner_kit_hash']);

        $inspect = $service->inspect($dir);
        $this->assertSame('pending', data_get($inspect, 'evidence_pack_readiness.status'));
        $this->assertSame(15, data_get($inspect, 'evidence_pack_readiness.summary.present'));
        $this->assertSame(15, data_get($inspect, 'evidence_pack_readiness.summary.blocked'));
        $this->assertSame(0, data_get($inspect, 'evidence_pack_readiness.summary.passed'));
        $this->assertContains('artifact_file_missing', data_get($inspect, 'evidence_pack_readiness.packs.0.blockers'));
    }

    public function test_evidence_worklist_turns_blocked_packs_into_actionable_hash_work_items(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-replay-worklist-'.bin2hex(random_bytes(4));
        $service = app(AtlasFrontendRivalReplayHarnessService::class);
        $service->writeRunnerKit($dir);

        $payload = $service->writeEvidenceWorklist($dir);

        $this->assertSame('atlas.frontend.rival_replay_evidence_worklist.v1', $payload['schema_version']);
        $this->assertSame('pending', $payload['status']);
        $this->assertSame(15, $payload['work_item_count']);
        $this->assertTrue(File::isFile($dir.'/replay-evidence-worklist.json'));
        $this->assertSame('pending', data_get($payload, 'evidence_pack_readiness.status'));
        $this->assertSame('saas_dashboard_repair/atlas_frontend/evidence/evidence-pack.json', data_get($payload, 'work_items.0.pack_manifest_ref'));
        $this->assertSame('saas_dashboard_repair/atlas_frontend/manifest.json', data_get($payload, 'work_items.0.run_manifest_ref'));
        $this->assertSame('shasum -a 256 saas_dashboard_repair/atlas_frontend/evidence/artifacts/output_artifact.json', data_get($payload, 'work_items.0.artifact_slots.0.hash_command'));
        $this->assertSame('sha256(output_artifact)', data_get($payload, 'work_items.0.manifest_hash_mapping.output_artifact_hash'));
        $this->assertContains('mirror_required_hashes_into_run_manifest', data_get($payload, 'work_items.0.completion_steps'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.worklist_is_not_evidence'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $payload['worklist_hash']);
    }

    public function test_complete_manifest_is_invalid_without_competitive_score_breakdown(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-replay-invalid-'.bin2hex(random_bytes(4));
        $service = app(AtlasFrontendRivalReplayHarnessService::class);
        $service->writeTemplate($dir);
        $taskSpecHash = $this->taskSpecHash($dir, 'saas_dashboard_repair');
        $hashes = $this->writeEvidencePack($dir, 'saas_dashboard_repair', 'atlas_frontend', $taskSpecHash);

        File::put($dir.'/saas_dashboard_repair/atlas_frontend/manifest.json', json_encode([
            'case_id' => 'saas_dashboard_repair',
            'system' => 'atlas_frontend',
            'status' => 'complete',
            'run_id' => 'atlas-run',
            'task_spec_hash' => $taskSpecHash,
            'task_spec_ref' => '../task-spec.json',
            'evidence_pack_ref' => 'evidence/evidence-pack.json',
            'output_artifact_ref' => 'artifact://atlas',
            'output_artifact_hash' => $hashes['output_artifact'],
            'screenshot_hashes' => [$hashes['screenshot_set']],
            'anti_slop_report_hash' => $hashes['anti_slop_report'],
            'verification_hashes' => [$hashes['verification_report']],
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
            $taskSpecHash = $this->taskSpecHash($dir, $case);
            foreach (['atlas_frontend', 'pbakaus_impeccable', 'claude_design_plugin'] as $system) {
                $score = $system === 'pbakaus_impeccable' && $case === 'live_mode_repair_loop' ? 10 : 9;
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

        $payload = $service->inspect($dir);

        $this->assertSame('ready', $payload['status']);
        $this->assertTrue((bool) data_get($payload, 'summary.external_replay_completed'));
        $this->assertSame('ready', data_get($payload, 'evidence_pack_readiness.status'));
        $this->assertSame(15, data_get($payload, 'evidence_pack_readiness.summary.passed'));
        $this->assertSame('passed', data_get($payload, 'fairness.status'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.may_claim_world_best_frontend_system'));
        $this->assertContains('atlas_does_not_win_every_complete_case', $payload['remaining_gaps']);
        $this->assertSame('atlas_needs_improvement', data_get($payload, 'competitive_diagnostics.status'));
        $this->assertSame(1, data_get($payload, 'competitive_diagnostics.losing_case_count'));
        $losingCase = collect(data_get($payload, 'competitive_diagnostics.cases'))->firstWhere('status', 'atlas_loses');
        $this->assertSame('live_mode_repair_loop', $losingCase['case_id']);
        $this->assertSame('pbakaus_impeccable', $losingCase['best_rival_system']);
        $this->assertSame(2, $losingCase['minimum_points_to_lead_best_rival']);
        $this->assertSame(1, $losingCase['dimension_gap_count']);
        $this->assertSame('product_intent_fit', data_get($losingCase, 'dimension_gaps.0.dimension'));
        $this->assertSame('live_mode_repair_loop', data_get($losingCase, 'dimension_gaps.0.case_id'));
        $this->assertSame('pbakaus_impeccable', data_get($losingCase, 'dimension_gaps.0.best_rival_system'));
        $this->assertSame(1, data_get($losingCase, 'dimension_gaps.0.points_to_match'));
        $this->assertSame('improve_product_intent_fit', data_get($losingCase, 'dimension_gaps.0.next_action'));
        $this->assertSame('php artisan atlas:frontend:repair-plan --dimension-gap=product_intent_fit:1:-1:10:live_mode_repair_loop:pbakaus_impeccable --json', data_get($losingCase, 'dimension_gaps.0.repair_plan_command'));
        $this->assertContains('php artisan atlas:frontend:repair-plan --dimension-gap=product_intent_fit:1:-1:10:live_mode_repair_loop:pbakaus_impeccable --json', $losingCase['recommended_repair_plan_commands']);
        $this->assertSame('improve_atlas_frontend_case_and_rerun_replay', $losingCase['next_action']);
        $this->assertSame(45, data_get($payload, 'scoreboard.atlas_frontend.score'));
        $this->assertSame(46, data_get($payload, 'scoreboard.pbakaus_impeccable.score'));
    }

    public function test_complete_manifests_are_invalid_when_task_spec_hash_differs_across_systems(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-replay-unfair-'.bin2hex(random_bytes(4));
        $service = app(AtlasFrontendRivalReplayHarnessService::class);
        $service->writeTemplate($dir);
        $taskSpecHash = $this->taskSpecHash($dir, 'saas_dashboard_repair');

        foreach (['atlas_frontend', 'pbakaus_impeccable', 'claude_design_plugin'] as $system) {
            $manifestTaskSpecHash = $system === 'atlas_frontend' ? str_repeat('a', 64) : $taskSpecHash;
            $hashes = $this->writeEvidencePack($dir, 'saas_dashboard_repair', $system, $manifestTaskSpecHash);
            File::put($dir.'/saas_dashboard_repair/'.$system.'/manifest.json', json_encode([
                'case_id' => 'saas_dashboard_repair',
                'system' => $system,
                'status' => 'complete',
                'run_id' => 'saas_dashboard_repair-'.$system,
                'task_spec_hash' => $manifestTaskSpecHash,
                'task_spec_ref' => '../task-spec.json',
                'evidence_pack_ref' => 'evidence/evidence-pack.json',
                'output_artifact_ref' => 'artifact://saas_dashboard_repair/'.$system,
                'output_artifact_hash' => $hashes['output_artifact'],
                'screenshot_hashes' => [$hashes['screenshot_set']],
                'anti_slop_report_hash' => $hashes['anti_slop_report'],
                'verification_hashes' => [$hashes['verification_report']],
                'score_breakdown' => $this->scoreBreakdown(9),
                'score_total' => 9,
                'score_max' => 100,
                'completed_at' => '2026-05-25T00:00:00Z',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        }

        $payload = $service->inspect($dir);
        $caseRuns = collect($payload['runs'])->where('case_id', 'saas_dashboard_repair');

        $this->assertSame('ready_for_replay', $payload['status']);
        $this->assertSame('failed', data_get($payload, 'fairness.status'));
        $this->assertSame(3, $caseRuns->where('status', 'invalid')->count());
        $this->assertContains('task_spec_hash_mismatch_across_systems', $caseRuns->first()['issues']);
        $this->assertFalse((bool) data_get($payload, 'summary.external_replay_completed'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.may_claim_world_best_frontend_system'));
    }

    public function test_complete_manifest_is_invalid_when_task_spec_hash_does_not_match_referenced_task_spec(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-replay-task-spec-ref-mismatch-'.bin2hex(random_bytes(4));
        $service = app(AtlasFrontendRivalReplayHarnessService::class);
        $service->writeTemplate($dir);
        $hashes = $this->writeEvidencePack($dir, 'saas_dashboard_repair', 'atlas_frontend', str_repeat('b', 64));

        File::put($dir.'/saas_dashboard_repair/atlas_frontend/manifest.json', json_encode([
            'case_id' => 'saas_dashboard_repair',
            'system' => 'atlas_frontend',
            'status' => 'complete',
            'run_id' => 'saas_dashboard_repair-atlas_frontend',
            'task_spec_hash' => str_repeat('b', 64),
            'task_spec_ref' => '../task-spec.json',
            'evidence_pack_ref' => 'evidence/evidence-pack.json',
            'output_artifact_ref' => 'artifact://saas_dashboard_repair/atlas_frontend',
            'output_artifact_hash' => $hashes['output_artifact'],
            'screenshot_hashes' => [$hashes['screenshot_set']],
            'anti_slop_report_hash' => $hashes['anti_slop_report'],
            'verification_hashes' => [$hashes['verification_report']],
            'score_breakdown' => $this->scoreBreakdown(9),
            'score_total' => 9,
            'score_max' => 100,
            'completed_at' => '2026-05-25T00:00:00Z',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $payload = $service->inspect($dir);
        $run = collect($payload['runs'])->firstWhere('case_id', 'saas_dashboard_repair');

        $this->assertSame('invalid', $run['status']);
        $this->assertContains('task_spec_hash_mismatch_with_task_spec_ref', $run['issues']);
        $this->assertFalse((bool) data_get($payload, 'claim_policy.may_claim_world_best_frontend_system'));
    }

    public function test_complete_manifest_is_invalid_without_verified_evidence_pack(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-replay-evidence-pack-missing-'.bin2hex(random_bytes(4));
        $service = app(AtlasFrontendRivalReplayHarnessService::class);
        $service->writeTemplate($dir);
        $taskSpecHash = $this->taskSpecHash($dir, 'saas_dashboard_repair');

        File::put($dir.'/saas_dashboard_repair/atlas_frontend/manifest.json', json_encode([
            'case_id' => 'saas_dashboard_repair',
            'system' => 'atlas_frontend',
            'status' => 'complete',
            'run_id' => 'saas_dashboard_repair-atlas_frontend',
            'task_spec_hash' => $taskSpecHash,
            'task_spec_ref' => '../task-spec.json',
            'evidence_pack_ref' => 'evidence/evidence-pack.json',
            'output_artifact_ref' => 'artifact://saas_dashboard_repair/atlas_frontend',
            'output_artifact_hash' => hash('sha256', 'artifact'),
            'screenshot_hashes' => [hash('sha256', 'screenshot')],
            'anti_slop_report_hash' => hash('sha256', 'anti-slop'),
            'verification_hashes' => [hash('sha256', 'verification')],
            'score_breakdown' => $this->scoreBreakdown(9),
            'score_total' => 9,
            'score_max' => 100,
            'completed_at' => '2026-05-25T00:00:00Z',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $payload = $service->inspect($dir);
        $run = collect($payload['runs'])->firstWhere('case_id', 'saas_dashboard_repair');

        $this->assertSame('invalid', $run['status']);
        $this->assertContains('evidence_pack_ref_missing', $run['issues']);
        $this->assertFalse((bool) data_get($payload, 'summary.external_replay_completed'));
    }

    public function test_complete_manifest_is_invalid_with_nested_raw_prompt_or_source_fields(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-replay-nested-raw-'.bin2hex(random_bytes(4));
        $service = app(AtlasFrontendRivalReplayHarnessService::class);
        $service->writeTemplate($dir);
        $taskSpecHash = $this->taskSpecHash($dir, 'saas_dashboard_repair');
        $hashes = $this->writeEvidencePack($dir, 'saas_dashboard_repair', 'atlas_frontend', $taskSpecHash);

        File::put($dir.'/saas_dashboard_repair/atlas_frontend/manifest.json', json_encode([
            'case_id' => 'saas_dashboard_repair',
            'system' => 'atlas_frontend',
            'status' => 'complete',
            'run_id' => 'saas_dashboard_repair-atlas_frontend',
            'task_spec_hash' => $taskSpecHash,
            'task_spec_ref' => '../task-spec.json',
            'evidence_pack_ref' => 'evidence/evidence-pack.json',
            'output_artifact_ref' => 'artifact://saas_dashboard_repair/atlas_frontend',
            'output_artifact_hash' => $hashes['output_artifact'],
            'screenshot_hashes' => [$hashes['screenshot_set']],
            'anti_slop_report_hash' => $hashes['anti_slop_report'],
            'verification_hashes' => [$hashes['verification_report']],
            'score_breakdown' => $this->scoreBreakdown(9),
            'score_total' => 9,
            'score_max' => 100,
            'completed_at' => '2026-05-25T00:00:00Z',
            'debug' => [
                'capture' => [
                    'raw_source' => 'secret source excerpt',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $payload = $service->inspect($dir);
        $run = collect($payload['runs'])->firstWhere('case_id', 'saas_dashboard_repair');

        $this->assertSame('invalid', $run['status']);
        $this->assertContains('forbidden_raw_prompt_or_source_field_present', $run['issues']);
        $this->assertFalse((bool) data_get($payload, 'summary.external_replay_completed'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.may_claim_world_best_frontend_system'));
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

    private function taskSpecHash(string $dir, string $case): string
    {
        $taskSpec = json_decode(File::get($dir.'/'.$case.'/task-spec.json'), true);

        return (string) $taskSpec['task_spec_hash'];
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
