<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Quaternity\DialogueToPackets;

use App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets\ApprovedShape;
use App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets\AtlasMaestroPacketShapeOperatorReviewGate;
use App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets\InvalidDecisionReceiptException;
use App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets\ReviewGateTamperException;
use PHPUnit\Framework\TestCase;

final class AtlasMaestroPacketShapeOperatorReviewGateTest extends TestCase
{
    private string $storageRoot;

    private int $clock = 1_700_000_000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageRoot = sys_get_temp_dir().'/atlas_review_gate_ac_'.bin2hex(random_bytes(6));
        mkdir($this->storageRoot.'/atlas/maestro/dialogue/proposed', 0775, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storageRoot)) {
            shell_exec('rm -rf '.escapeshellarg($this->storageRoot));
        }
        parent::tearDown();
    }

    private function gate(): AtlasMaestroPacketShapeOperatorReviewGate
    {
        return new AtlasMaestroPacketShapeOperatorReviewGate($this->storageRoot, fn (): int => $this->clock);
    }

    private function writeProposal(string $shapeId = 'shape-1', array $overrides = []): string
    {
        $proposal = array_merge([
            'task_packet_id' => $shapeId,
            'objective' => 'Evolve AtlasLoopX (grounded at App\\Foo in app/Foo.php)',
            'allowed_files' => ['app/Foo.php'],
            'scope_in' => ['app/Foo.php'],
            'acceptance_criteria' => ['verb:evolve'],
            'required_evidence' => 'cortex_id:c1',
            'depends_on' => [],
            'wave' => 'w330',
            'anchor_symbol' => 'App\\Foo',
            'anchor_file' => 'app/Foo.php',
        ], $overrides);
        $path = $this->storageRoot.'/atlas/maestro/dialogue/proposed/'.$shapeId.'.json';
        file_put_contents($path, (string) json_encode($proposal, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $shapeId;
    }

    // ── AC: approving a modified shape raises tamper exception ────────────────

    public function test_approving_a_modified_shape_raises_tamper_exception(): void
    {
        $id = $this->writeProposal();
        $gate = $this->gate();
        $gate->present($id);

        $path = $this->storageRoot.'/atlas/maestro/dialogue/proposed/'.$id.'.json';
        $current = (array) json_decode((string) file_get_contents($path), true);
        $current['allowed_files'] = ['app/Bar.php'];
        file_put_contents($path, (string) json_encode($current, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->expectException(ReviewGateTamperException::class);
        $gate->approve($id, 'op-sig-1', 'rec-1');
    }

    // ── AC: stale or missing decision receipts are rejected ────────────────────

    public function test_missing_decision_receipt_is_rejected(): void
    {
        $id = $this->writeProposal();
        $gate = $this->gate();
        $gate->present($id);

        $this->expectException(InvalidDecisionReceiptException::class);
        $gate->approve($id, 'op-sig-1', '');
    }

    public function test_blank_decision_receipt_is_rejected(): void
    {
        $id = $this->writeProposal();
        $gate = $this->gate();
        $gate->present($id);

        $this->expectException(InvalidDecisionReceiptException::class);
        $gate->approve($id, 'op-sig-1', '   ');
    }

    public function test_stale_presented_shape_is_rejected_on_approve(): void
    {
        $id = $this->writeProposal('shape-stale-approve');
        $sixtyDaysAgo = $this->clock - (60 * 86_400);
        touch($this->storageRoot.'/atlas/maestro/dialogue/proposed/'.$id.'.json', $sixtyDaysAgo);

        $gate = $this->gate();
        $bundle = $gate->present($id);
        $this->assertTrue($bundle->stale);

        $this->expectException(InvalidDecisionReceiptException::class);
        $gate->approve($id, 'op-sig-1', 'rec-1');
    }

    public function test_fresh_presented_shape_with_valid_receipt_approves_successfully(): void
    {
        $id = $this->writeProposal('shape-fresh');
        $gate = $this->gate();
        $gate->present($id);

        $approved = $gate->approve($id, 'op-sig-1', 'rec-1');

        $this->assertInstanceOf(ApprovedShape::class, $approved);
    }

    // ── AC: valid approvals include shape hash and operator signature ──────────

    public function test_valid_approval_includes_proposal_hash_and_operator_signature(): void
    {
        $id = $this->writeProposal('shape-valid');
        $gate = $this->gate();
        $bundle = $gate->present($id);

        $approved = $gate->approve($id, 'operator-alice', 'receipt-xyz');

        $this->assertNotEmpty($approved->proposalHash);
        $this->assertSame($bundle->proposalHash, $approved->proposalHash);
        $this->assertSame('operator-alice', $approved->operatorSignature);

        $array = $approved->toArray();
        $this->assertArrayHasKey('proposal_hash', $array);
        $this->assertArrayHasKey('operator_signature', $array);
        $this->assertSame($approved->proposalHash, $array['proposal_hash']);
        $this->assertSame('operator-alice', $array['operator_signature']);
    }
}
