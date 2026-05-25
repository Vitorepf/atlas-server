<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\Frontend\AtlasFrontendCompetitiveRubricService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendEvidencePackVerifierService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendRivalReplayHarnessService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendRivalReplayCommandTest extends TestCase
{
    public function test_replay_inspect_command_emits_harness_contract(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-replay-command-missing-'.bin2hex(random_bytes(4));

        $exitCode = Artisan::call('atlas:frontend:replay', [
            'action' => 'inspect',
            '--evidence' => $dir,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('atlas.frontend.rival_replay_harness.v1', $output);
        $this->assertStringContainsString('external_rival_replay_artifacts_required_for_world_best_claim', $output);
    }

    public function test_replay_template_command_writes_pending_manifests(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-replay-command-template-'.bin2hex(random_bytes(4));

        $exitCode = Artisan::call('atlas:frontend:replay', [
            'action' => 'template',
            '--output' => $dir,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('atlas.frontend.rival_replay_template.v1', $output);
        $this->assertStringContainsString('atlas.frontend.rival_replay_task_spec.v1', $output);
        $this->assertTrue(File::isFile($dir.'/live_mode_repair_loop/task-spec.json'));
        $this->assertTrue(File::isFile($dir.'/live_mode_repair_loop/pbakaus_impeccable/manifest.json'));
    }

    public function test_replay_runner_kit_command_writes_operational_packets(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-replay-command-runner-kit-'.bin2hex(random_bytes(4));

        $exitCode = Artisan::call('atlas:frontend:replay', [
            'action' => 'runner-kit',
            '--output' => $dir,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('atlas.frontend.rival_replay_runner_kit.v1', $output);
        $this->assertStringContainsString('run_packet_count', $output);
        $this->assertTrue(File::isFile($dir.'/replay-runner-kit.json'));
    }

    public function test_replay_evidence_worklist_command_writes_actionable_completion_queue(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-replay-command-worklist-'.bin2hex(random_bytes(4));

        Artisan::call('atlas:frontend:replay', [
            'action' => 'runner-kit',
            '--output' => $dir,
            '--json' => true,
        ]);

        $exitCode = Artisan::call('atlas:frontend:replay', [
            'action' => 'evidence-worklist',
            '--evidence' => $dir,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('atlas.frontend.rival_replay_evidence_worklist.v1', $output);
        $this->assertStringContainsString('fill_evidence_pack_saas_dashboard_repair_atlas_frontend', $output);
        $this->assertStringContainsString('manifest_hash_mapping', $output);
        $this->assertTrue(File::isFile($dir.'/replay-evidence-worklist.json'));
    }

    public function test_replay_score_template_command_writes_provider_safe_patch(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-replay-command-score-template-'.bin2hex(random_bytes(4));
        $service = app(AtlasFrontendRivalReplayHarnessService::class);
        $service->writeTemplate($dir);
        $taskSpec = json_decode(File::get($dir.'/saas_dashboard_repair/task-spec.json'), true);
        $taskSpecHash = (string) $taskSpec['task_spec_hash'];
        $hashes = $this->writeEvidencePack($dir, 'saas_dashboard_repair', 'atlas_frontend', $taskSpecHash);
        $breakdown = [
            'product_intent_fit' => 9,
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
            'score_breakdown' => $breakdown,
            'score_total' => 9,
            'score_max' => 100,
            'completed_at' => '2026-05-25T00:00:00Z',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $exitCode = Artisan::call('atlas:frontend:replay', [
            'action' => 'score-template',
            '--evidence' => $dir,
            '--case' => 'saas_dashboard_repair',
            '--system' => 'atlas_frontend',
            '--reviewer-ref-hash' => hash('sha256', 'reviewer-ref'),
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('atlas.frontend.rival_replay.score_attestation_template.v1', $output);
        $this->assertStringContainsString('pending_operator_approval', $output);
        $this->assertStringContainsString('manifest_patch', $output);
        $this->assertTrue(File::isFile($dir.'/saas_dashboard_repair/atlas_frontend/score-attestation-template.json'));
    }

    public function test_replay_external_receipt_template_command_writes_provider_safe_patch(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-replay-command-external-receipt-template-'.bin2hex(random_bytes(4));
        $service = app(AtlasFrontendRivalReplayHarnessService::class);
        $service->writeTemplate($dir);
        $taskSpec = json_decode(File::get($dir.'/saas_dashboard_repair/task-spec.json'), true);
        $taskSpecHash = (string) $taskSpec['task_spec_hash'];
        $hashes = $this->writeEvidencePack($dir, 'saas_dashboard_repair', 'pbakaus_impeccable', $taskSpecHash);
        $breakdown = [
            'product_intent_fit' => 9,
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
        $verification = app(AtlasFrontendEvidencePackVerifierService::class)
            ->verify($dir.'/saas_dashboard_repair/pbakaus_impeccable/evidence/evidence-pack.json');

        File::put($dir.'/saas_dashboard_repair/pbakaus_impeccable/manifest.json', json_encode([
            'case_id' => 'saas_dashboard_repair',
            'system' => 'pbakaus_impeccable',
            'status' => 'complete',
            'run_id' => 'saas_dashboard_repair-pbakaus_impeccable',
            'task_spec_hash' => $taskSpecHash,
            'task_spec_ref' => '../task-spec.json',
            'evidence_pack_ref' => 'evidence/evidence-pack.json',
            'output_artifact_ref' => 'artifact://saas_dashboard_repair/pbakaus_impeccable',
            'output_artifact_hash' => $hashes['output_artifact'],
            'screenshot_hashes' => [$hashes['screenshot_set']],
            'anti_slop_report_hash' => $hashes['anti_slop_report'],
            'verification_hashes' => [$hashes['verification_report']],
            'score_breakdown' => $breakdown,
            'score_total' => 9,
            'score_max' => 100,
            'score_attestation' => [
                'schema_version' => AtlasFrontendRivalReplayHarnessService::SCORE_ATTESTATION_SCHEMA_VERSION,
                'status' => 'verified',
                'case_id' => 'saas_dashboard_repair',
                'system' => 'pbakaus_impeccable',
                'scoring_surface' => 'manual_competitive_review',
                'reviewer_ref_hash' => hash('sha256', 'reviewer-ref'),
                'reviewed_at' => '2026-05-25T00:00:00Z',
                'operator_approved' => true,
                'rubric_hash' => app(AtlasFrontendCompetitiveRubricService::class)->rubric()['rubric_hash'],
                'score_breakdown_hash' => MissionCanonicalHash::sha256($breakdown),
                'score_total' => 9,
                'score_max' => 100,
                'evidence_pack_verification_hash' => $verification['verification_hash'] ?? null,
            ],
            'completed_at' => '2026-05-25T00:00:00Z',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $exitCode = Artisan::call('atlas:frontend:replay', [
            'action' => 'external-receipt-template',
            '--evidence' => $dir,
            '--case' => 'saas_dashboard_repair',
            '--system' => 'pbakaus_impeccable',
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('atlas.frontend.rival_replay.external_execution_receipt_template.v1', $output);
        $this->assertStringContainsString('pending_operator_approval', $output);
        $this->assertStringContainsString('manifest_patch', $output);
        $this->assertTrue(File::isFile($dir.'/saas_dashboard_repair/pbakaus_impeccable/external-execution-receipt-template.json'));
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

        return $hashes;
    }
}
