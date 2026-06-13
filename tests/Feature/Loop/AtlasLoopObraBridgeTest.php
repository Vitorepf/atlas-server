<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopObraBridgeService;
use App\Services\Ai\Programming\AtlasForgeMultiNodeL410ProofService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class AtlasLoopObraBridgeTest extends TestCase
{
    private string $evidencePath;

    private string $receiptPath;

    private string $deliveryReceiptPath;

    protected function setUp(): void
    {
        parent::setUp();

        $id = (string) Str::uuid();
        $this->evidencePath = storage_path("framework/testing/l5-2-real-evidence-{$id}.json");
        $this->receiptPath = storage_path("framework/testing/l5-2-bridge-{$id}.json");
        $this->deliveryReceiptPath = storage_path("framework/testing/l5-2-delivery-{$id}.json");
    }

    protected function tearDown(): void
    {
        @File::delete($this->evidencePath);
        @File::delete($this->receiptPath);
        @File::delete($this->deliveryReceiptPath);

        parent::tearDown();
    }

    public function test_bridge_packages_multi_file_intent_but_fails_closed_without_l4_10_real_evidence(): void
    {
        $payload = app(AtlasLoopObraBridgeService::class)->bridge([
            'intent' => 'Implement a multi-file Loop feature',
            'files' => $this->targetFiles(),
        ]);

        $this->assertSame(AtlasLoopObraBridgeService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('blocked_by_l4_10_real_execution', $payload['status']);
        $this->assertTrue((bool) data_get($payload, 'bridge_packet.multi_file_detected'));
        $this->assertCount(3, data_get($payload, 'bridge_packet.target_files'));
        $this->assertSame('ready', data_get($payload, 'bridge_packet.forge_schedule.status'));
        $this->assertSame('parallel_no_overlap', data_get($payload, 'bridge_packet.forge_schedule.integration_plan'));
        $this->assertContains('l4_10_real_execution_receipt_required', $payload['blockers']);
        $this->assertFalse((bool) data_get($payload, 'operator_approval.auto_execute_allowed'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.provider_dispatches_now'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.obra_created'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.l5_2_completion_claim_allowed'));
    }

    public function test_real_l4_10_receipt_allows_operator_review_but_still_does_not_execute(): void
    {
        Storage::fake('local');
        $this->writeRealL410Evidence();

        $out = new BufferedOutput;
        $exit = Artisan::call('atlas:loop:obra-bridge', [
            '--intent' => 'Deliver a multi-file governed Loop feature',
            '--file' => $this->targetFiles(),
            '--l4-10-evidence' => $this->evidencePath,
            '--create-proposal' => true,
            '--write' => true,
            '--path' => $this->receiptPath,
            '--json' => true,
            '--strict' => true,
        ], $out);
        $payload = json_decode($out->fetch(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ready_for_operator_review', $payload['status']);
        $this->assertTrue((bool) data_get($payload, 'l4_10_gate.certified'));
        $this->assertNotEmpty(data_get($payload, 'operator_approval.created_proposal_id'));
        $this->assertNull(data_get($payload, 'created_backlog_proposal.linked_obra_id'));
        $this->assertFalse((bool) data_get($payload, 'created_backlog_proposal.external_provider_call'));
        $this->assertFalse((bool) data_get($payload, 'created_backlog_proposal.provider_tokens_spent'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.auto_execution_started'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.l5_2_completion_claim_allowed'));
        $this->assertFileExists($this->receiptPath);
    }

    public function test_real_multi_file_delivery_receipt_allows_l5_2_completion_claim(): void
    {
        Storage::fake('local');
        $this->writeRealL410Evidence();
        $intent = 'Deliver a multi-file governed Loop feature';
        $preflight = app(AtlasLoopObraBridgeService::class)->bridge([
            'intent' => $intent,
            'files' => $this->targetFiles(),
            'l4_10_evidence_path' => $this->evidencePath,
        ]);
        $this->writeRealL52DeliveryReceipt($preflight);

        $out = new BufferedOutput;
        $exit = Artisan::call('atlas:loop:obra-bridge', [
            '--intent' => $intent,
            '--file' => $this->targetFiles(),
            '--l4-10-evidence' => $this->evidencePath,
            '--delivery-receipt' => $this->deliveryReceiptPath,
            '--json' => true,
            '--strict' => true,
            '--require-delivered' => true,
        ], $out);
        $payload = json_decode($out->fetch(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('delivered', $payload['status']);
        $this->assertTrue((bool) data_get($payload, 'delivery_receipt.certified'));
        $this->assertSame([], data_get($payload, 'delivery_receipt.blockers'));
        $this->assertSame($this->targetFiles(), data_get($payload, 'delivery_receipt.checks.bridge_target_file_intersection'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.provider_dispatches_now'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.obra_created'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.auto_execution_started'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.l5_2_completion_claim_allowed'));
    }

    /**
     * @return list<string>
     */
    private function targetFiles(): array
    {
        return [
            'app/Services/Ai/AutonomousEvolution/AtlasLoopMorningDigestService.php',
            'app/Console/Commands/AtlasLoopMorningDigestCommand.php',
            'tests/Feature/Loop/AtlasLoopMorningDigestTest.php',
        ];
    }

    private function writeRealL410Evidence(): void
    {
        File::ensureDirectoryExists(dirname($this->evidencePath));
        File::put($this->evidencePath, json_encode($this->realL410Receipt(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * The full L4-10 receipt: surrounding live run-evidence + the executor's SELF-STAMPED
     * signed core (provenance-hardened, L4-10). Mirrors AtlasForgeMultiNodeL410ProofTest's
     * green shape so the strict proof's verifyExecutorProvenance() passes (the gate added
     * in L4-10 rejects hand-assembled evidence with no executor stamp).
     *
     * @return array<string,mixed>
     */
    private function realL410Receipt(): array
    {
        $files = $this->targetFiles();
        $executorReceipt = (new \App\Services\Ai\Obra\AtlasObraReceiptStamp)->stamp([
            'obra_id' => 'obra-l5-2-real-20260612',
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
            'delivered_item_id' => 'L4-6',
            'delivered_files' => $files,
        ]);

        return [
            'schema_version' => AtlasForgeMultiNodeL410ProofService::REAL_RECEIPT_SCHEMA_VERSION,
            'status' => 'done',
            'certified' => true,
            'execution_mode' => 'real_provider_obra_run',
            'obra_id' => 'obra-l5-2-real-20260612',
            'node_count' => 6,
            'provider_calls_made' => true,
            'external_provider_call' => true,
            'provider' => [
                'name' => 'hermes_cli',
                'model' => 'gpt-5.5',
            ],
            'delivered_item_id' => 'L4-6',
            'delivered_files' => $files,
            'resumed' => true,
            'resume_count' => 1,
            'kill_resume' => [
                'kill_exercised' => true,
                'resume_exercised' => true,
                'evidence_refs' => ['ledger:kill-real', 'ledger:resume-real'],
            ],
            'command_results' => [
                ['command' => 'php artisan atlas:loop:morning-digest --json', 'exit_code' => 0],
            ],
            // L4-10 provenance-hardened core — the executor's own signed output.
            'executor_receipt' => $executorReceipt,
        ];
    }

    /**
     * @param  array<string,mixed>  $bridge
     */
    private function writeRealL52DeliveryReceipt(array $bridge): void
    {
        $files = $this->targetFiles();
        $nodes = [
            [
                'id' => 'l5-2-node-01',
                'seq' => 1,
                'status' => 'done',
                'commit' => '111111111111',
                'files_changed' => [$files[0]],
                'provider' => 'hermes_cli',
            ],
            [
                'id' => 'l5-2-node-02',
                'seq' => 2,
                'status' => 'done',
                'commit' => '222222222222',
                'files_changed' => [$files[1]],
                'provider' => 'hermes_cli',
            ],
            [
                'id' => 'l5-2-node-03',
                'seq' => 3,
                'status' => 'done',
                'commit' => '333333333333',
                'files_changed' => [$files[2]],
                'provider' => 'hermes_cli',
            ],
        ];

        File::ensureDirectoryExists(dirname($this->deliveryReceiptPath));
        File::put($this->deliveryReceiptPath, json_encode([
            'schema_version' => AtlasLoopObraBridgeService::DELIVERY_RECEIPT_SCHEMA_VERSION,
            'bridge_packet_hash' => data_get($bridge, 'bridge_packet.packet_hash'),
            'bridge_intent_hash' => data_get($bridge, 'bridge_packet.intent_hash'),
            'bridge_work_order_id' => data_get($bridge, 'bridge_packet.forge_schedule.work_order_id'),
            'execution_mode' => 'real_provider_obra_run',
            'provider' => [
                'name' => 'hermes_cli',
                'model' => 'gpt-5.5',
            ],
            'output' => [
                'schema' => 'atlas.obra.commission.v1',
                'obra_id' => 'obra-l5-2-real-20260613',
                'status' => 'done',
                'certified' => true,
                'node_count' => 3,
                'delivered_nodes' => 3,
                'branch' => 'atlas/obra/obra-l5-2-real-20260613',
                'main_untouched' => true,
                'never_merged' => true,
                'never_pushed' => true,
                'plan' => $nodes,
                'evidence' => [
                    'schema' => 'atlas.obra.certification.v1',
                    'certified' => true,
                    'nodes' => $nodes,
                    'integrated_test_result' => [
                        'supplied' => true,
                        'ran' => true,
                        'passed' => true,
                        'exit_code' => 0,
                    ],
                ],
                'integrated_test_result' => [
                    'supplied' => true,
                    'ran' => true,
                    'passed' => true,
                    'exit_code' => 0,
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
