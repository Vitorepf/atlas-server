<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\Frontend\AtlasFrontendCompetitiveRubricService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendEvidencePackVerifierService;
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

    public function test_evidence_worklist_turns_missing_score_attestation_into_actionable_work_item(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-replay-score-worklist-'.bin2hex(random_bytes(4));
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
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $payload = $service->writeEvidenceWorklist($dir);
        $scoreItem = collect($payload['work_items'])->firstWhere('id', 'fill_score_attestation_saas_dashboard_repair_atlas_frontend');

        $this->assertSame('pending', $payload['status']);
        $this->assertIsArray($scoreItem);
        $this->assertContains('score_attestation_required', $scoreItem['blockers']);
        $this->assertSame(AtlasFrontendRivalReplayHarnessService::SCORE_ATTESTATION_SCHEMA_VERSION, $scoreItem['score_attestation_schema_version']);
        $this->assertSame('canonical_sha256(manifest.score_breakdown)', data_get($scoreItem, 'score_attestation_hash_mapping.score_breakdown_hash'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($scoreItem, 'score_attestation_hash_mapping.evidence_pack_verification_hash'));
        $this->assertSame('php artisan atlas:frontend:replay score-template --evidence='.$dir.' --case=saas_dashboard_repair --system=atlas_frontend --json', data_get($scoreItem, 'commands.write_score_template'));
        $this->assertContains('run_score_template_command_for_provider_safe_manifest_patch', $scoreItem['completion_steps']);
        $this->assertContains('fill_score_attestation_without_raw_prompt_source_or_reviewer_identity', $scoreItem['completion_steps']);
    }

    public function test_evidence_worklist_turns_missing_external_receipt_into_actionable_work_item(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-replay-external-receipt-worklist-'.bin2hex(random_bytes(4));
        $service = app(AtlasFrontendRivalReplayHarnessService::class);
        $service->writeTemplate($dir);
        $taskSpecHash = $this->taskSpecHash($dir, 'saas_dashboard_repair');
        $hashes = $this->writeEvidencePack($dir, 'saas_dashboard_repair', 'pbakaus_impeccable', $taskSpecHash);
        $breakdown = $this->scoreBreakdown(9);

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
            'score_attestation' => $this->scoreAttestation($dir, 'saas_dashboard_repair', 'pbakaus_impeccable', $breakdown, 9),
            'completed_at' => '2026-05-25T00:00:00Z',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $payload = $service->writeEvidenceWorklist($dir);
        $receiptItem = collect($payload['work_items'])->firstWhere('id', 'fill_external_execution_receipt_saas_dashboard_repair_pbakaus_impeccable');

        $this->assertSame('pending', $payload['status']);
        $this->assertIsArray($receiptItem);
        $this->assertContains('external_execution_receipt_required', $receiptItem['blockers']);
        $this->assertSame(AtlasFrontendRivalReplayHarnessService::EXTERNAL_EXECUTION_RECEIPT_SCHEMA_VERSION, $receiptItem['external_execution_receipt_schema_version']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($receiptItem, 'external_execution_receipt_hash_mapping.evidence_pack_verification_hash'));
        $this->assertSame('php artisan atlas:frontend:replay external-receipt-template --evidence='.$dir.' --case=saas_dashboard_repair --system=pbakaus_impeccable --json', data_get($receiptItem, 'commands.write_external_receipt_template'));
        $this->assertContains('run_external_receipt_template_command_for_provider_safe_manifest_patch', $receiptItem['completion_steps']);
    }

    public function test_score_attestation_template_prefills_hashes_without_authorizing_claims(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-replay-score-template-'.bin2hex(random_bytes(4));
        $service = app(AtlasFrontendRivalReplayHarnessService::class);
        $service->writeTemplate($dir);
        $taskSpecHash = $this->taskSpecHash($dir, 'saas_dashboard_repair');
        $hashes = $this->writeEvidencePack($dir, 'saas_dashboard_repair', 'atlas_frontend', $taskSpecHash);
        $breakdown = $this->scoreBreakdown(9);

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

        $payload = $service->writeScoreAttestationTemplate(
            $dir,
            'saas_dashboard_repair',
            'atlas_frontend',
            reviewerRefHash: hash('sha256', 'reviewer-ref'),
        );

        $this->assertSame('atlas.frontend.rival_replay.score_attestation_template.v1', $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertTrue(File::isFile($dir.'/saas_dashboard_repair/atlas_frontend/score-attestation-template.json'));
        $this->assertSame('pending_operator_approval', data_get($payload, 'score_attestation.status'));
        $this->assertFalse((bool) data_get($payload, 'score_attestation.operator_approved'));
        $this->assertSame(MissionCanonicalHash::sha256($breakdown), data_get($payload, 'score_attestation.score_breakdown_hash'));
        $this->assertSame(hash('sha256', 'reviewer-ref'), data_get($payload, 'score_attestation.reviewer_ref_hash'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($payload, 'score_attestation.evidence_pack_verification_hash'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.score_template_is_not_evidence'));
        $this->assertContains('embed_manifest_patch_score_attestation', $payload['required_next_actions']);
    }

    public function test_score_attestation_template_blocks_until_evidence_pack_verifies(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-replay-score-template-blocked-'.bin2hex(random_bytes(4));
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
            'output_artifact_hash' => hash('sha256', 'missing-output'),
            'screenshot_hashes' => [hash('sha256', 'missing-screenshot')],
            'anti_slop_report_hash' => hash('sha256', 'missing-anti-slop'),
            'verification_hashes' => [hash('sha256', 'missing-verification')],
            'score_breakdown' => $this->scoreBreakdown(9),
            'score_total' => 9,
            'score_max' => 100,
            'completed_at' => '2026-05-25T00:00:00Z',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $payload = $service->writeScoreAttestationTemplate($dir, 'saas_dashboard_repair', 'atlas_frontend');

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('evidence_pack_ref_missing', $payload['blockers']);
        $this->assertFalse((bool) $payload['write_performed']);
        $this->assertFalse(File::isFile($dir.'/saas_dashboard_repair/atlas_frontend/score-attestation-template.json'));
    }

    public function test_score_attestation_template_blocks_unknown_case_or_system(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-replay-score-template-unknown-'.bin2hex(random_bytes(4));
        $service = app(AtlasFrontendRivalReplayHarnessService::class);
        $service->writeTemplate($dir);

        $payload = $service->writeScoreAttestationTemplate($dir, 'unknown_case', 'unknown_system');

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('unknown_case_id', $payload['blockers']);
        $this->assertContains('unknown_system_id', $payload['blockers']);
        $this->assertFalse((bool) $payload['write_performed']);
        $this->assertFalse(File::isFile($dir.'/unknown_case/unknown_system/score-attestation-template.json'));
    }

    public function test_external_execution_receipt_template_prefills_hashes_without_authorizing_claims(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-replay-external-receipt-template-'.bin2hex(random_bytes(4));
        $service = app(AtlasFrontendRivalReplayHarnessService::class);
        $service->writeTemplate($dir);
        $taskSpecHash = $this->taskSpecHash($dir, 'saas_dashboard_repair');
        $hashes = $this->writeEvidencePack($dir, 'saas_dashboard_repair', 'pbakaus_impeccable', $taskSpecHash);
        $breakdown = $this->scoreBreakdown(9);

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
            'score_attestation' => $this->scoreAttestation($dir, 'saas_dashboard_repair', 'pbakaus_impeccable', $breakdown, 9),
            'completed_at' => '2026-05-25T00:00:00Z',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $payload = $service->writeExternalExecutionReceiptTemplate($dir, 'saas_dashboard_repair', 'pbakaus_impeccable');

        $this->assertSame('atlas.frontend.rival_replay.external_execution_receipt_template.v1', $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertTrue(File::isFile($dir.'/saas_dashboard_repair/pbakaus_impeccable/external-execution-receipt-template.json'));
        $this->assertSame('pending_operator_approval', data_get($payload, 'external_execution_receipt.status'));
        $this->assertFalse((bool) data_get($payload, 'external_execution_receipt.operator_approved'));
        $this->assertSame($hashes['output_artifact'], data_get($payload, 'external_execution_receipt.manifest_hashes.output_artifact_hash'));
        $this->assertSame([$hashes['screenshot_set']], data_get($payload, 'external_execution_receipt.manifest_hashes.screenshot_hashes'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($payload, 'external_execution_receipt.manifest_hashes.evidence_pack_verification_hash'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.external_receipt_template_is_not_evidence'));
        $this->assertContains('embed_manifest_patch_external_execution_receipt', $payload['required_next_actions']);
    }

    public function test_external_execution_receipt_template_blocks_internal_system(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-replay-external-receipt-internal-'.bin2hex(random_bytes(4));
        $service = app(AtlasFrontendRivalReplayHarnessService::class);
        $service->writeTemplate($dir);

        $payload = $service->writeExternalExecutionReceiptTemplate($dir, 'saas_dashboard_repair', 'atlas_frontend');

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('external_rival_system_required', $payload['blockers']);
        $this->assertFalse((bool) $payload['write_performed']);
        $this->assertFalse(File::isFile($dir.'/saas_dashboard_repair/atlas_frontend/external-execution-receipt-template.json'));
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
                    'external_execution_receipt' => $system !== 'atlas_frontend' ? $this->externalExecutionReceipt($case, $system, $hashes) : null,
                    'score_breakdown' => $this->scoreBreakdown($score),
                    'score_total' => $score,
                    'score_max' => 100,
                    'score_attestation' => $this->scoreAttestation($dir, $case, $system, $this->scoreBreakdown($score), $score),
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

    public function test_external_rival_complete_manifest_requires_verified_execution_receipt(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-replay-external-receipt-'.bin2hex(random_bytes(4));
        $service = app(AtlasFrontendRivalReplayHarnessService::class);
        $service->writeTemplate($dir);
        $taskSpecHash = $this->taskSpecHash($dir, 'live_mode_repair_loop');
        $hashes = $this->writeEvidencePack($dir, 'live_mode_repair_loop', 'pbakaus_impeccable', $taskSpecHash);

        File::put($dir.'/live_mode_repair_loop/pbakaus_impeccable/manifest.json', json_encode([
            'case_id' => 'live_mode_repair_loop',
            'system' => 'pbakaus_impeccable',
            'status' => 'complete',
            'run_id' => 'live_mode_repair_loop-pbakaus_impeccable',
            'task_spec_hash' => $taskSpecHash,
            'task_spec_ref' => '../task-spec.json',
            'evidence_pack_ref' => 'evidence/evidence-pack.json',
            'output_artifact_ref' => 'artifact://live_mode_repair_loop/pbakaus_impeccable',
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
        $run = collect($payload['runs'])
            ->where('case_id', 'live_mode_repair_loop')
            ->firstWhere('system', 'pbakaus_impeccable');

        $this->assertSame('invalid', $run['status']);
        $this->assertContains('external_execution_receipt_required', $run['issues']);
        $this->assertFalse((bool) data_get($payload, 'claim_policy.may_claim_external_replay_completed'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.world_best_requires_external_execution_receipts'));
    }

    public function test_external_rival_execution_receipt_hashes_must_match_manifest(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-replay-external-receipt-hash-'.bin2hex(random_bytes(4));
        $service = app(AtlasFrontendRivalReplayHarnessService::class);
        $service->writeTemplate($dir);
        $taskSpecHash = $this->taskSpecHash($dir, 'live_mode_repair_loop');
        $hashes = $this->writeEvidencePack($dir, 'live_mode_repair_loop', 'claude_design_plugin', $taskSpecHash);
        $receipt = $this->externalExecutionReceipt('live_mode_repair_loop', 'claude_design_plugin', $hashes);
        $receipt['manifest_hashes']['output_artifact_hash'] = str_repeat('a', 64);

        File::put($dir.'/live_mode_repair_loop/claude_design_plugin/manifest.json', json_encode([
            'case_id' => 'live_mode_repair_loop',
            'system' => 'claude_design_plugin',
            'status' => 'complete',
            'run_id' => 'live_mode_repair_loop-claude_design_plugin',
            'task_spec_hash' => $taskSpecHash,
            'task_spec_ref' => '../task-spec.json',
            'evidence_pack_ref' => 'evidence/evidence-pack.json',
            'output_artifact_ref' => 'artifact://live_mode_repair_loop/claude_design_plugin',
            'output_artifact_hash' => $hashes['output_artifact'],
            'screenshot_hashes' => [$hashes['screenshot_set']],
            'anti_slop_report_hash' => $hashes['anti_slop_report'],
            'verification_hashes' => [$hashes['verification_report']],
            'external_execution_receipt' => $receipt,
            'score_breakdown' => $this->scoreBreakdown(9),
            'score_total' => 9,
            'score_max' => 100,
            'completed_at' => '2026-05-25T00:00:00Z',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $payload = $service->inspect($dir);
        $run = collect($payload['runs'])
            ->where('case_id', 'live_mode_repair_loop')
            ->firstWhere('system', 'claude_design_plugin');

        $this->assertSame('invalid', $run['status']);
        $this->assertContains('external_execution_receipt_output_artifact_hash_mismatch', $run['issues']);
        $this->assertFalse((bool) data_get($payload, 'claim_policy.may_claim_external_replay_completed'));
    }

    public function test_external_rival_execution_receipt_must_match_evidence_pack_verification_hash(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-replay-external-receipt-pack-hash-'.bin2hex(random_bytes(4));
        $service = app(AtlasFrontendRivalReplayHarnessService::class);
        $service->writeTemplate($dir);
        $taskSpecHash = $this->taskSpecHash($dir, 'live_mode_repair_loop');
        $hashes = $this->writeEvidencePack($dir, 'live_mode_repair_loop', 'pbakaus_impeccable', $taskSpecHash);
        $receipt = $this->externalExecutionReceipt('live_mode_repair_loop', 'pbakaus_impeccable', $hashes);
        $receipt['manifest_hashes']['evidence_pack_verification_hash'] = str_repeat('b', 64);

        File::put($dir.'/live_mode_repair_loop/pbakaus_impeccable/manifest.json', json_encode([
            'case_id' => 'live_mode_repair_loop',
            'system' => 'pbakaus_impeccable',
            'status' => 'complete',
            'run_id' => 'live_mode_repair_loop-pbakaus_impeccable',
            'task_spec_hash' => $taskSpecHash,
            'task_spec_ref' => '../task-spec.json',
            'evidence_pack_ref' => 'evidence/evidence-pack.json',
            'output_artifact_ref' => 'artifact://live_mode_repair_loop/pbakaus_impeccable',
            'output_artifact_hash' => $hashes['output_artifact'],
            'screenshot_hashes' => [$hashes['screenshot_set']],
            'anti_slop_report_hash' => $hashes['anti_slop_report'],
            'verification_hashes' => [$hashes['verification_report']],
            'external_execution_receipt' => $receipt,
            'score_breakdown' => $this->scoreBreakdown(9),
            'score_total' => 9,
            'score_max' => 100,
            'completed_at' => '2026-05-25T00:00:00Z',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $payload = $service->inspect($dir);
        $run = collect($payload['runs'])
            ->where('case_id', 'live_mode_repair_loop')
            ->firstWhere('system', 'pbakaus_impeccable');

        $this->assertSame('invalid', $run['status']);
        $this->assertContains('external_execution_receipt_evidence_pack_verification_hash_mismatch', $run['issues']);
        $this->assertFalse((bool) data_get($payload, 'claim_policy.may_claim_external_replay_completed'));
    }

    public function test_complete_manifest_requires_verified_score_attestation(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-replay-score-attestation-'.bin2hex(random_bytes(4));
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
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $payload = $service->inspect($dir);
        $run = collect($payload['runs'])->firstWhere('case_id', 'saas_dashboard_repair');

        $this->assertSame('invalid', $run['status']);
        $this->assertContains('score_attestation_required', $run['issues']);
        $this->assertFalse((bool) data_get($payload, 'claim_policy.may_claim_world_best_frontend_system'));
    }

    public function test_score_attestation_must_match_manifest_score_and_evidence(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-replay-score-attestation-mismatch-'.bin2hex(random_bytes(4));
        $service = app(AtlasFrontendRivalReplayHarnessService::class);
        $service->writeTemplate($dir);
        $taskSpecHash = $this->taskSpecHash($dir, 'saas_dashboard_repair');
        $hashes = $this->writeEvidencePack($dir, 'saas_dashboard_repair', 'atlas_frontend', $taskSpecHash);
        $attestation = $this->scoreAttestation($dir, 'saas_dashboard_repair', 'atlas_frontend', $this->scoreBreakdown(9), 9);
        $attestation['score_total'] = 10;

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
            'score_attestation' => $attestation,
            'completed_at' => '2026-05-25T00:00:00Z',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $payload = $service->inspect($dir);
        $run = collect($payload['runs'])->firstWhere('case_id', 'saas_dashboard_repair');

        $this->assertSame('invalid', $run['status']);
        $this->assertContains('score_attestation_total_mismatch', $run['issues']);
        $this->assertFalse((bool) data_get($payload, 'claim_policy.may_claim_world_best_frontend_system'));
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
            'reviewer_ref_hash' => hash('sha256', 'reviewer-'.$case.'-'.$system),
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
