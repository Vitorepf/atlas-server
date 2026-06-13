<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\Obra\AtlasObraReceiptStamp;
use App\Services\Ai\Programming\AtlasDevBeatTestReportService;
use App\Services\Ai\Programming\AtlasFableFinalCaptureService;
use App\Services\Ai\Programming\AtlasFableFinalReportService;
use App\Services\Ai\Programming\AtlasForgeMultiNodeL410ProofService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class AtlasFableFinalCaptureTest extends TestCase
{
    private string $baselinePath;

    private string $seriesPath;

    private string $reportPath;

    private string $packetPath;

    private string $capturePath;

    private string $operatorProofRequestPath;

    private string $devBeatEvidencePath;

    private string $forgeEvidencePath;

    /** @var array<string,string> */
    private array $receiptPaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        $id = (string) Str::uuid();
        $root = storage_path("framework/testing/fable-l4-14-{$id}");
        $this->baselinePath = "{$root}/baseline.json";
        $this->seriesPath = "{$root}/series.jsonl";
        $this->reportPath = "{$root}/report.json";
        $this->packetPath = "{$root}/packet.json";
        $this->capturePath = "{$root}/capture.json";
        $this->operatorProofRequestPath = "{$root}/operator-proof-request.json";
        $this->devBeatEvidencePath = "{$root}/dev-beat-evidence.json";
        $this->forgeEvidencePath = "{$root}/forge-evidence.json";

        File::ensureDirectoryExists($root);
        File::put($this->baselinePath, json_encode([
            'schema_version' => 'atlas.fable_campaign.marco_zero.v1',
            'recorded_at' => '2026-06-11',
            'baseline' => [
                'maturity_scorecard' => ['acos_overall' => 7.86],
                'learning_capture_quality_7d' => ['gate_mode' => 'observe'],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $out = new BufferedOutput;
        Artisan::call('atlas:fable:final-report', [
            '--baseline' => $this->baselinePath,
            '--series' => $this->seriesPath,
            '--date' => '2026-06-12',
            '--write-report' => true,
            '--report-path' => $this->reportPath,
            '--write-packet' => true,
            '--packet-path' => $this->packetPath,
            '--json' => true,
            '--strict' => true,
        ], $out);

        foreach ([
            'knowledge_sync',
            'code_index',
            'projection_write',
            'projection_status',
            'docs_health',
            'context_pack',
        ] as $key) {
            $this->receiptPaths[$key] = "{$root}/{$key}.json";
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->baselinePath));

        parent::tearDown();
    }

    public function test_final_capture_accepts_ritual_receipts_and_keeps_completion_claim_gated(): void
    {
        $this->writeReadyReceipts();

        $out = new BufferedOutput;
        $exit = Artisan::call('atlas:fable:final-capture', [
            '--final-report' => $this->reportPath,
            '--packet' => $this->packetPath,
            '--knowledge-sync-receipt' => $this->receiptPaths['knowledge_sync'],
            '--code-index-receipt' => $this->receiptPaths['code_index'],
            '--projection-write-receipt' => $this->receiptPaths['projection_write'],
            '--projection-status-receipt' => $this->receiptPaths['projection_status'],
            '--docs-health-receipt' => $this->receiptPaths['docs_health'],
            '--context-pack-receipt' => $this->receiptPaths['context_pack'],
            '--write' => true,
            '--capture-path' => $this->capturePath,
            '--write-operator-proof-request' => true,
            '--operator-proof-request-path' => $this->operatorProofRequestPath,
            '--json' => true,
            '--strict' => true,
        ], $out);
        $raw = $out->fetch();
        $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, $raw);
        $this->assertSame(AtlasFableFinalCaptureService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready_with_operator_gated_external_proofs', $payload['status']);
        $this->assertSame('ready', data_get($payload, 'final_report.status'));
        $this->assertSame('ready', data_get($payload, 'ritual.status'));
        $this->assertSame('ready', data_get($payload, 'cold_session_recovery.status'));
        $this->assertTrue((bool) data_get($payload, 'cold_session_recovery.recoverable_from_recorded_artifacts_only'));
        $this->assertSame('pending_operator_evidence', data_get($payload, 'operator_gated_external_proofs.status'));
        $this->assertContains(
            'L4-9:atlas_dev_external_benchmark_receipt_missing_or_not_winning',
            data_get($payload, 'operator_gated_external_proofs.pending'),
        );
        $this->assertContains(
            'L4-10:real_multi_node_obra_receipt_missing_or_rejected',
            data_get($payload, 'operator_gated_external_proofs.pending'),
        );
        $this->assertFalse((bool) data_get($payload, 'claim_policy.provider_dispatches_now'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.recorded_artifact_capture_claim_allowed'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.operator_proof_request_claim_allowed'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.completion_claim_allowed'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.list_4_completion_claim_allowed'));
        $this->assertSame(AtlasFableFinalCaptureService::OPERATOR_PROOF_REQUEST_SCHEMA_VERSION, data_get($payload, 'operator_proof_request.schema_version'));
        $this->assertSame('pending_operator_evidence', data_get($payload, 'operator_proof_request.status'));
        $this->assertSame(
            data_get($payload, 'operator_gated_external_proofs.l4_9.status'),
            data_get($payload, 'operator_proof_request.items.L4-9.current_status'),
        );
        $this->assertSame('not_loaded_or_missing', data_get($payload, 'operator_proof_request.items.L4-9.internal_evidence.status'));
        $this->assertSame(['bug', 'feature', 'refactor'], data_get($payload, 'operator_proof_request.items.L4-9.required_external_baseline.required_task_types'));
        $l49RequiredFields = data_get($payload, 'operator_proof_request.items.L4-9.required_external_baseline.required_fields_per_case');
        $this->assertContains('validation_commands with command + exit_code=0', $l49RequiredFields);
        $this->assertContains('evidence_refs', $l49RequiredFields);
        $this->assertTrue((bool) data_get($payload, 'operator_proof_request.items.L4-9.template_payload.template_only'));
        $this->assertSame(
            'atlas.programming.dev_beat_test_evidence.v1',
            data_get($payload, 'operator_proof_request.items.L4-9.template_payload.schema_version'),
        );
        $this->assertSame(
            ['bug', 'feature', 'refactor'],
            array_column(data_get($payload, 'operator_proof_request.items.L4-9.template_payload.tasks'), 'task_type'),
        );
        $this->assertSame(
            '<exact command run by external runner validation>',
            data_get($payload, 'operator_proof_request.items.L4-9.template_payload.tasks.0.baseline.validation_commands.0.command'),
        );
        $this->assertFalse((bool) data_get($payload, 'operator_proof_request.items.L4-9.claim_policy.internal_measurement_claim_allowed'));
        $this->assertFalse((bool) data_get($payload, 'operator_proof_request.items.L4-9.claim_policy.external_comparison_claim_allowed'));
        $this->assertSame('real_execution_blocked', data_get($payload, 'operator_proof_request.items.L4-10.current_status'));
        $l410RequiredFields = data_get($payload, 'operator_proof_request.items.L4-10.required_receipt.required_fields');
        $this->assertContains('model=gpt-5.5', $l410RequiredFields);
        $this->assertContains('delivered_files include AtlasLoopMorningDigestService, AtlasLoopMorningDigestCommand, AtlasLoopMorningDigestTest', $l410RequiredFields);
        $this->assertContains('executor resume result includes resumed=true and resume_count>=1', $l410RequiredFields);
        $this->assertContains('kill_resume.evidence_refs includes separate kill and resume refs', $l410RequiredFields);
        $this->assertTrue((bool) data_get($payload, 'operator_proof_request.items.L4-10.template_payload.template_only'));
        $this->assertSame(
            'atlas.forge.l4_10.real_execution_receipt.v1',
            data_get($payload, 'operator_proof_request.items.L4-10.template_payload.schema_version'),
        );
        $this->assertSame(
            '<hermes_cli>',
            data_get($payload, 'operator_proof_request.items.L4-10.template_payload.provider.name'),
        );
        $this->assertSame(
            '<real resume event receipt ref>',
            data_get($payload, 'operator_proof_request.items.L4-10.template_payload.kill_resume.evidence_refs.1'),
        );
        $this->assertSame(
            '<true from AtlasObraExecutor after live resume>',
            data_get($payload, 'operator_proof_request.items.L4-10.template_payload.resumed'),
        );
        $this->assertSame(
            '<integer >=1 from AtlasObraExecutor runtime>',
            data_get($payload, 'operator_proof_request.items.L4-10.template_payload.resume_count'),
        );
        $this->assertFalse((bool) data_get($payload, 'operator_proof_request.items.L4-10.claim_policy.synthetic_or_simulated_receipt_allowed'));
        $this->assertTrue((bool) data_get($payload, 'operator_proof_request.claim_policy.operator_decision_required'));
        $this->assertFalse((bool) data_get($payload, 'operator_proof_request.claim_policy.provider_dispatches_now'));
        $this->assertFalse((bool) data_get($payload, 'operator_proof_request.claim_policy.template_payloads_are_evidence'));
        $this->assertFalse((bool) data_get($payload, 'operator_proof_request.claim_policy.list_4_completion_claim_allowed'));
        $this->assertFileExists($this->capturePath);
        $this->assertFileExists($this->operatorProofRequestPath);
        $l49TemplatePath = data_get($payload, 'written_operator_proof_template_paths.L4-9');
        $l410TemplatePath = data_get($payload, 'written_operator_proof_template_paths.L4-10');
        $this->assertIsString($l49TemplatePath);
        $this->assertIsString($l410TemplatePath);
        $this->assertFileExists($l49TemplatePath);
        $this->assertFileExists($l410TemplatePath);

        $written = json_decode((string) File::get($this->capturePath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('ready_with_operator_gated_external_proofs', $written['status']);
        $this->assertContains('AGENTS.md', data_get($written, 'cold_session_recovery.required_starting_points'));
        $this->assertContains(
            'storage/app/atlas/evidence/fable-l4-14-operator-proof-request.json',
            data_get($written, 'cold_session_recovery.required_starting_points'),
        );
        $this->assertSame('Lista 5 re-validation against measured Lista 4 evidence', data_get($written, 'cold_session_recovery.next_item_after_l4_14'));

        $proofRequest = json_decode((string) File::get($this->operatorProofRequestPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('pending_operator_evidence', $proofRequest['status']);
        $this->assertSame($this->operatorProofRequestPath, data_get($proofRequest, 'artifact.path'));
        $this->assertSame($l49TemplatePath, data_get($proofRequest, 'artifact.template_paths.L4-9'));
        $this->assertSame($l410TemplatePath, data_get($proofRequest, 'artifact.template_paths.L4-10'));
        $l49Template = json_decode((string) File::get($l49TemplatePath), true, flags: JSON_THROW_ON_ERROR);
        $l410Template = json_decode((string) File::get($l410TemplatePath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue((bool) ($l49Template['template_only'] ?? false));
        $this->assertTrue((bool) ($l410Template['template_only'] ?? false));
        $this->assertSame('atlas.programming.dev_beat_test_evidence.v1', $l49Template['schema_version']);
        $this->assertSame('atlas.forge.l4_10.real_execution_receipt.v1', $l410Template['schema_version']);

        $beatOut = new BufferedOutput;
        $beatExit = Artisan::call('atlas:dev:beat-test', [
            '--evidence' => $l49TemplatePath,
            '--json' => true,
        ], $beatOut);
        $beatPayload = json_decode($beatOut->fetch(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $beatExit);
        $this->assertSame('evidence_missing', $beatPayload['status']);
        $this->assertFalse((bool) data_get($beatPayload, 'claim_policy.external_comparison_claim_allowed'));
        $this->assertFalse((bool) data_get($beatPayload, 'claim_policy.external_superiority_claim_allowed'));

        $forgeOut = new BufferedOutput;
        $forgeExit = Artisan::call('atlas:forge:l4-10-proof', [
            '--evidence' => $l410TemplatePath,
            '--strict' => true,
            '--json' => true,
        ], $forgeOut);
        $forgePayload = json_decode($forgeOut->fetch(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $forgeExit);
        $this->assertSame('real_execution_evidence_rejected', $forgePayload['status']);
        $this->assertFalse((bool) $forgePayload['certified']);
        $this->assertContains('real_receipt_done_status_missing', $forgePayload['blockers']);
        $this->assertFalse((bool) data_get($forgePayload, 'claim_policy.completion_claim_allowed'));
    }

    public function test_strict_final_capture_fails_when_a_required_projection_receipt_is_missing(): void
    {
        $this->writeReadyReceipts();
        @File::delete($this->receiptPaths['projection_status']);

        $out = new BufferedOutput;
        $exit = Artisan::call('atlas:fable:final-capture', [
            '--final-report' => $this->reportPath,
            '--packet' => $this->packetPath,
            '--knowledge-sync-receipt' => $this->receiptPaths['knowledge_sync'],
            '--code-index-receipt' => $this->receiptPaths['code_index'],
            '--projection-write-receipt' => $this->receiptPaths['projection_write'],
            '--projection-status-receipt' => $this->receiptPaths['projection_status'],
            '--docs-health-receipt' => $this->receiptPaths['docs_health'],
            '--context-pack-receipt' => $this->receiptPaths['context_pack'],
            '--json' => true,
            '--strict' => true,
        ], $out);
        $raw = $out->fetch();
        $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('ritual:ritual_receipt_not_ready:projection_status', $payload['blockers']);
        $this->assertContains('cold_session_recovery:projection_status_receipt_not_ready', $payload['blockers']);
    }

    public function test_require_list_4_complete_fails_while_operator_gated_proofs_are_pending(): void
    {
        $this->writeReadyReceipts();

        $out = new BufferedOutput;
        $exit = Artisan::call('atlas:fable:final-capture', [
            '--final-report' => $this->reportPath,
            '--packet' => $this->packetPath,
            '--knowledge-sync-receipt' => $this->receiptPaths['knowledge_sync'],
            '--code-index-receipt' => $this->receiptPaths['code_index'],
            '--projection-write-receipt' => $this->receiptPaths['projection_write'],
            '--projection-status-receipt' => $this->receiptPaths['projection_status'],
            '--docs-health-receipt' => $this->receiptPaths['docs_health'],
            '--context-pack-receipt' => $this->receiptPaths['context_pack'],
            '--json' => true,
            '--strict' => true,
            '--require-list-4-complete' => true,
        ], $out);
        $raw = $out->fetch();
        $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit, $raw);
        $this->assertSame('ready_with_operator_gated_external_proofs', $payload['status']);
        $this->assertSame('ready', data_get($payload, 'cold_session_recovery.status'));
        $this->assertSame('pending_operator_evidence', data_get($payload, 'operator_gated_external_proofs.status'));
        $this->assertSame(2, data_get($payload, 'operator_gated_external_proofs.pending_count'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.list_4_completion_claim_allowed'));
    }

    public function test_final_capture_refreshes_final_report_from_operator_evidence_paths_before_capture(): void
    {
        $this->writeReadyReceipts();
        $this->writeDevBeatEvidence([
            $this->devBeatTask('bug', 74),
            $this->devBeatTask('feature', 152),
            $this->devBeatTask('refactor', 95),
        ]);

        $out = new BufferedOutput;
        $exit = Artisan::call('atlas:fable:final-capture', [
            '--baseline' => $this->baselinePath,
            '--series' => $this->seriesPath,
            '--date' => '2026-06-12',
            '--dev-beat-evidence' => $this->devBeatEvidencePath,
            '--final-report' => $this->reportPath,
            '--packet' => $this->packetPath,
            '--knowledge-sync-receipt' => $this->receiptPaths['knowledge_sync'],
            '--code-index-receipt' => $this->receiptPaths['code_index'],
            '--projection-write-receipt' => $this->receiptPaths['projection_write'],
            '--projection-status-receipt' => $this->receiptPaths['projection_status'],
            '--docs-health-receipt' => $this->receiptPaths['docs_health'],
            '--context-pack-receipt' => $this->receiptPaths['context_pack'],
            '--write' => true,
            '--capture-path' => $this->capturePath,
            '--json' => true,
            '--strict' => true,
        ], $out);
        $raw = $out->fetch();
        $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, $raw);
        $this->assertSame('refreshed_final_report', data_get($payload, 'operator_evidence_ingest.status'));
        $this->assertTrue((bool) data_get($payload, 'operator_evidence_ingest.requested'));
        $this->assertSame($this->devBeatEvidencePath, data_get($payload, 'operator_evidence_ingest.dev_beat_evidence_path'));
        $this->assertSame('external_claim_blocked', data_get($payload, 'operator_evidence_ingest.l4_9_status'));
        $this->assertFalse((bool) data_get($payload, 'operator_evidence_ingest.l4_9_external_comparison_claim_allowed'));
        $this->assertSame('real_execution_blocked', data_get($payload, 'operator_evidence_ingest.l4_10_status'));
        $this->assertSame('external_claim_blocked', data_get($payload, 'operator_gated_external_proofs.l4_9.status'));
        $this->assertSame('recorded', data_get($payload, 'operator_proof_request.items.L4-9.internal_evidence.status'));
        $this->assertSame(3, data_get($payload, 'operator_proof_request.items.L4-9.internal_evidence.known_result.atlas_dev_passed_count'));
        $this->assertSame(0, data_get($payload, 'operator_proof_request.items.L4-9.internal_evidence.known_result.comparable_external_count'));
        $this->assertSame('pending_operator_evidence', data_get($payload, 'operator_gated_external_proofs.status'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.list_4_completion_claim_allowed'));

        $writtenReport = json_decode((string) File::get($this->reportPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('external_claim_blocked', data_get($writtenReport, 'source_reports.dev_beat_test.status'));
        $this->assertSame(3, data_get($writtenReport, 'source_reports.dev_beat_test.summary.atlas_dev_passed_count'));
    }

    public function test_final_capture_does_not_request_l4_10_again_when_real_receipt_is_certified(): void
    {
        $this->writeReadyReceipts();
        $this->writeForgeEvidence();

        $out = new BufferedOutput;
        $exit = Artisan::call('atlas:fable:final-capture', [
            '--baseline' => $this->baselinePath,
            '--series' => $this->seriesPath,
            '--date' => '2026-06-12',
            '--forge-evidence' => $this->forgeEvidencePath,
            '--final-report' => $this->reportPath,
            '--packet' => $this->packetPath,
            '--knowledge-sync-receipt' => $this->receiptPaths['knowledge_sync'],
            '--code-index-receipt' => $this->receiptPaths['code_index'],
            '--projection-write-receipt' => $this->receiptPaths['projection_write'],
            '--projection-status-receipt' => $this->receiptPaths['projection_status'],
            '--docs-health-receipt' => $this->receiptPaths['docs_health'],
            '--context-pack-receipt' => $this->receiptPaths['context_pack'],
            '--write' => true,
            '--capture-path' => $this->capturePath,
            '--write-operator-proof-request' => true,
            '--operator-proof-request-path' => $this->operatorProofRequestPath,
            '--json' => true,
            '--strict' => true,
        ], $out);
        $raw = $out->fetch();
        $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, $raw);
        $this->assertSame('pending_operator_evidence', data_get($payload, 'operator_gated_external_proofs.status'));
        $this->assertSame(1, data_get($payload, 'operator_gated_external_proofs.pending_count'));
        $this->assertSame(
            ['L4-9:atlas_dev_external_benchmark_receipt_missing_or_not_winning'],
            data_get($payload, 'operator_gated_external_proofs.pending'),
        );
        $this->assertSame('certified', data_get($payload, 'operator_gated_external_proofs.l4_10.status'));
        $this->assertTrue((bool) data_get($payload, 'operator_gated_external_proofs.l4_10.certified'));
        $this->assertStringStartsWith(
            'not_required:',
            (string) data_get($payload, 'operator_proof_request.items.L4-10.operator_action_required'),
        );
        $this->assertNull(data_get($payload, 'operator_proof_request.items.L4-10.template_payload'));
        $this->assertTrue((bool) data_get($payload, 'operator_proof_request.items.L4-10.claim_policy.completion_claim_allowed'));
        $this->assertStringContainsString('L4-10 is already certified', data_get($payload, 'claim_policy.honesty_note'));
        $this->assertArrayHasKey('L4-9', data_get($payload, 'written_operator_proof_template_paths'));
        $this->assertArrayNotHasKey('L4-10', data_get($payload, 'written_operator_proof_template_paths'));

        $proofRequest = json_decode((string) File::get($this->operatorProofRequestPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('L4-9', data_get($proofRequest, 'artifact.template_paths'));
        $this->assertArrayNotHasKey('L4-10', data_get($proofRequest, 'artifact.template_paths'));
    }

    public function test_final_capture_stays_gated_when_l4_9_is_honestly_comparable_but_not_superior(): void
    {
        // THE LAUNDERING GUARD. The capture machinery must NOT turn an honest
        // "comparable but not superior" L4-9 result into a Lista-4 completion claim.
        // Stage the exact 13/14 + 1-honest-comparable end-state: L4-10 is genuinely
        // certified (executor self-stamped receipt), and L4-9 has REAL comparable
        // external evidence for all three medium tasks, but Atlas only wins one of them
        // (Atlas faster on bug, external faster on feature + refactor). The dev beat-test
        // status is comparable_report_ready_no_superiority with external_comparison=true
        // but external_superiority=false. The capture must keep L4-9 pending, keep the
        // completion + list-4 claims false, and refuse --require-list-4-complete.
        $this->writeReadyReceipts();
        $this->writeForgeEvidence();
        $this->writeDevBeatEvidence([
            $this->comparableDevBeatTask('bug', 48, 73),       // Atlas wins (faster)
            $this->comparableDevBeatTask('feature', 152, 74),  // external wins (faster)
            $this->comparableDevBeatTask('refactor', 95, 48),  // external wins (faster)
        ]);

        $out = new BufferedOutput;
        $exit = Artisan::call('atlas:fable:final-capture', [
            '--baseline' => $this->baselinePath,
            '--series' => $this->seriesPath,
            '--date' => '2026-06-12',
            '--dev-beat-evidence' => $this->devBeatEvidencePath,
            '--forge-evidence' => $this->forgeEvidencePath,
            '--final-report' => $this->reportPath,
            '--packet' => $this->packetPath,
            '--knowledge-sync-receipt' => $this->receiptPaths['knowledge_sync'],
            '--code-index-receipt' => $this->receiptPaths['code_index'],
            '--projection-write-receipt' => $this->receiptPaths['projection_write'],
            '--projection-status-receipt' => $this->receiptPaths['projection_status'],
            '--docs-health-receipt' => $this->receiptPaths['docs_health'],
            '--context-pack-receipt' => $this->receiptPaths['context_pack'],
            '--write' => true,
            '--capture-path' => $this->capturePath,
            '--write-operator-proof-request' => true,
            '--operator-proof-request-path' => $this->operatorProofRequestPath,
            '--json' => true,
            '--strict' => true,
            '--require-list-4-complete' => true,
        ], $out);
        $raw = $out->fetch();
        $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        // --require-list-4-complete must FAIL because L4-9 is not superior.
        $this->assertSame(1, $exit, $raw);

        // The capture artifact itself is still legitimately recorded (strict-ready),
        // but completion stays operator-gated — recording != claiming.
        $this->assertSame('ready_with_operator_gated_external_proofs', $payload['status']);
        $this->assertSame('ready', data_get($payload, 'cold_session_recovery.status'));

        // L4-9 honest split surfaced from the dev beat-test source of truth.
        $this->assertSame(
            'comparable_report_ready_no_superiority',
            data_get($payload, 'operator_evidence_ingest.l4_9_status'),
        );
        $this->assertTrue((bool) data_get($payload, 'operator_evidence_ingest.l4_9_external_comparison_claim_allowed'));
        $this->assertFalse((bool) data_get($payload, 'operator_evidence_ingest.l4_9_external_superiority_claim_allowed'));

        // L4-10 is genuinely certified here, so the ONLY thing blocking Lista 4 is the
        // absence of an L4-9 SUPERIORITY claim — comparable is not enough.
        $this->assertSame('certified', data_get($payload, 'operator_gated_external_proofs.l4_10.status'));
        $this->assertTrue((bool) data_get($payload, 'operator_gated_external_proofs.l4_10.certified'));
        $this->assertSame('comparable_report_ready_no_superiority', data_get($payload, 'operator_gated_external_proofs.l4_9.status'));
        $this->assertTrue((bool) data_get($payload, 'operator_gated_external_proofs.l4_9.external_comparison_claim_allowed'));
        $this->assertFalse((bool) data_get($payload, 'operator_gated_external_proofs.l4_9.external_superiority_claim_allowed'));

        // The gate stays pending on L4-9 ONLY (not L4-10), proving comparable-without-
        // superiority does NOT clear the gate.
        $this->assertSame('pending_operator_evidence', data_get($payload, 'operator_gated_external_proofs.status'));
        $this->assertSame(1, data_get($payload, 'operator_gated_external_proofs.pending_count'));
        $this->assertSame(
            ['L4-9:atlas_dev_external_benchmark_receipt_missing_or_not_winning'],
            data_get($payload, 'operator_gated_external_proofs.pending'),
        );

        // NO laundering: every completion claim stays false even though comparable
        // external evidence exists and L4-10 is certified.
        $this->assertFalse((bool) data_get($payload, 'claim_policy.completion_claim_allowed'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.list_4_completion_claim_allowed'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.operator_gated_external_proof_claim_allowed'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.operator_proof_request_claim_allowed'));
        $this->assertFalse((bool) data_get($payload, 'operator_proof_request.claim_policy.list_4_completion_claim_allowed'));
        $this->assertSame('pending_operator_evidence', data_get($payload, 'operator_proof_request.status'));

        // The persisted artifact carries the same gated truth (cold-session safe). The
        // honesty note must say L4-9 needs a WINNING comparable receipt (comparable alone
        // is not enough) while acknowledging L4-10 is already certified.
        $written = json_decode((string) File::get($this->capturePath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse((bool) data_get($written, 'claim_policy.list_4_completion_claim_allowed'));
        $honestyNote = strtolower((string) data_get($written, 'claim_policy.honesty_note'));
        $this->assertStringContainsString('winning comparable external benchmark receipt', $honestyNote);
        $this->assertStringContainsString('l4-10 is already certified', $honestyNote);
    }

    private function writeReadyReceipts(): void
    {
        $this->writeReceipt('knowledge_sync', [
            'ok' => true,
            'summary' => ['created' => 0, 'updated' => 1, 'failed' => 0],
        ]);
        $this->writeReceipt('code_index', [
            'ok' => true,
            'status' => 'indexed',
            'summary' => ['modules' => 10, 'symbols' => 100],
        ]);
        $this->writeReceipt('projection_write', [
            'projections' => [
                ['target' => 'agents', 'path' => base_path('AGENTS.md'), 'written' => true],
                ['target' => 'claude', 'path' => base_path('CLAUDE.md'), 'written' => true],
            ],
        ]);
        $this->writeReceipt('projection_status', [
            'status' => 'ready',
            'projections' => [
                ['target' => 'agents', 'path' => base_path('AGENTS.md'), 'status' => 'ready'],
                ['target' => 'claude', 'path' => base_path('CLAUDE.md'), 'status' => 'ready'],
            ],
        ]);
        $this->writeReceipt('docs_health', [
            'status' => 'ok',
            'summary' => ['doc_count' => 3],
        ]);
        $this->writeReceipt('context_pack', [
            'schema_version' => 'atlas.aobg.context_pack_command.v1',
            'provider_bound' => true,
            'workspace' => 'atlas-server',
            'counts' => ['code_graph' => 1, 'reality_graph_paths' => 1, 'memory' => 1],
        ]);
    }

    /**
     * @param  array<string,mixed>  $parsed
     */
    private function writeReceipt(string $key, array $parsed): void
    {
        File::put($this->receiptPaths[$key], json_encode([
            'schema_version' => AtlasFableFinalCaptureService::RECEIPT_SCHEMA_VERSION,
            'key' => $key,
            'command' => 'fixture',
            'exit_code' => 0,
            'captured_at' => '2026-06-12T00:00:00+00:00',
            'parsed' => $parsed,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  list<array<string,mixed>>  $tasks
     */
    private function writeDevBeatEvidence(array $tasks): void
    {
        File::put($this->devBeatEvidencePath, json_encode([
            'schema_version' => AtlasDevBeatTestReportService::EVIDENCE_SCHEMA_VERSION,
            'tasks' => $tasks,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function writeForgeEvidence(): void
    {
        // L4-10 provenance hardening: the proof now rejects a hand-assembled receipt and
        // requires an EXECUTOR SELF-STAMPED core (HMAC over the load-bearing facts). Build
        // the signed core through the real production stamper so the fixture cannot launder
        // a green — a hand-edit of any sealed fact would break the recomputed signature.
        $executorReceipt = app(AtlasObraReceiptStamp::class)->stamp([
            'obra_id' => 'obra-l4-10-real-capture-20260612',
            'branch' => 'atlas/obra/obra-l4-10-real-capture-20260612',
            'base_head' => 'deadbeef',
            'status' => 'done',
            'certified' => true,
            'node_count' => 6,
            'delivered_nodes' => 6,
            'provider' => 'hermes_cli',
            'model' => 'gpt-5.5',
            'resumed' => true,
            'resume_count' => 1,
            'main_untouched' => true,
            'never_merged' => true,
            'receipt_hash' => 'f3-receipt-hash-l4-10',
            'delivered_item_id' => 'L4-6',
            'delivered_files' => [
                'app/Services/Ai/AutonomousEvolution/AtlasLoopMorningDigestService.php',
                'app/Console/Commands/AtlasLoopMorningDigestCommand.php',
                'tests/Feature/Loop/AtlasLoopMorningDigestTest.php',
            ],
        ]);

        File::put($this->forgeEvidencePath, json_encode([
            'schema_version' => AtlasForgeMultiNodeL410ProofService::REAL_RECEIPT_SCHEMA_VERSION,
            'status' => 'done',
            'certified' => true,
            'execution_mode' => 'real_provider_obra_run',
            'obra_id' => 'obra-l4-10-real-capture-20260612',
            'node_count' => 6,
            'resumed' => true,
            'resume_count' => 1,
            'provider_calls_made' => true,
            'external_provider_call' => true,
            'provider' => [
                'name' => 'hermes_cli',
                'provider' => 'hermes_cli',
                'model' => 'gpt-5.5',
            ],
            'delivered_item_id' => 'L4-6',
            'delivered_files' => [
                'app/Services/Ai/AutonomousEvolution/AtlasLoopMorningDigestService.php',
                'app/Console/Commands/AtlasLoopMorningDigestCommand.php',
                'tests/Feature/Loop/AtlasLoopMorningDigestTest.php',
            ],
            'kill_resume' => [
                'kill_exercised' => true,
                'resume_exercised' => true,
                'evidence_refs' => ['ledger:kill-real', 'ledger:resume-real'],
            ],
            'command_results' => [
                ['command' => '/opt/homebrew/bin/php artisan atlas:loop:morning-digest --json', 'exit_code' => 0],
            ],
            // The provenance-hardened core — the executor's own signed output.
            'executor_receipt' => $executorReceipt,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<string,mixed>
     */
    private function devBeatTask(string $type, int $duration): array
    {
        return [
            'id' => $type.'-medium-live',
            'task_type' => $type,
            'title' => ucfirst($type).' medium Atlas Dev task',
            'difficulty' => 'medium',
            'target_path' => 'app/Services/Ai/Programming/AtlasFableFinalCaptureService.php',
            'atlas_dev' => [
                'executed' => true,
                'provider' => 'hermes_cli',
                'model' => 'gpt-5.5',
                'duration_seconds' => $duration,
                'tests_passed' => true,
                'scope_passed' => true,
                'changed_files' => ['app/Services/Ai/Programming/AtlasFableFinalCaptureService.php'],
                'validation_commands' => [
                    ['command' => 'php artisan test tests/Feature/Ai/Programming/AtlasFableFinalCaptureTest.php', 'exit_code' => 0],
                ],
                'evidence_refs' => ['receipt:'.$type],
            ],
            'baseline' => [
                'executed' => false,
                'provider' => 'claude_code',
            ],
        ];
    }

    /**
     * Build a beat-test task with a COMPLETE, real-shaped external baseline (allowed
     * runner + model + changed files + passing validation + evidence refs, no template
     * markers) so the scorer treats the external comparison as real. The external
     * duration vs the Atlas duration decides who wins that single task — letting the
     * caller stage an honest comparable-but-not-superior split.
     *
     * @return array<string,mixed>
     */
    private function comparableDevBeatTask(string $type, int $atlasDuration, int $externalDuration): array
    {
        $task = $this->devBeatTask($type, $atlasDuration);
        $task['baseline'] = [
            'executed' => true,
            'provider' => 'cursor',
            'runner' => 'cursor',
            'model' => 'cursor-auto',
            'duration_seconds' => $externalDuration,
            'tests_passed' => true,
            'scope_passed' => true,
            'changed_files' => ['app/Services/Ai/Programming/AtlasFableFinalCaptureService.php'],
            'validation_commands' => [
                ['command' => 'php artisan test tests/Feature/Ai/Programming/AtlasFableFinalCaptureTest.php', 'exit_code' => 0],
            ],
            'evidence_refs' => ['cursor-receipt:'.$type, 'cursor-validation:'.$type],
        ];

        return $task;
    }
}
