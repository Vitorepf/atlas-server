<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets\ApprovedShape;
use App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets\AtlasMaestroPacketShapeOperatorReviewGate;
use App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets\RejectedShape;
use App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets\ReviewGateRefusal;
use App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets\ReviewGateTamperException;
use PHPUnit\Framework\TestCase;

/**
 * Proves the QUAT-W330-P02 operator review gate is fail-closed: forwardToQueue without approve() refuses with
 * NO_OPERATOR_APPROVAL; on-disk tamper between present() and approve() throws ReviewGateTamperException with
 * both hashes; approve() and reject() each write a JSON file with exactly the canonical key set; and a STALE
 * (>30d) proposed shape still requires explicit approve() — no default-yes by age.
 */
final class MaestroPacketShapeOperatorReviewGateTest extends TestCase
{
    private string $storageRoot;

    private int $clock = 1_700_000_000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageRoot = sys_get_temp_dir().'/atlas_review_gate_'.bin2hex(random_bytes(6));
        mkdir($this->storageRoot.'/atlas/maestro/dialogue/proposed', 0775, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storageRoot)) {
            $cmd = 'rm -rf '.escapeshellarg($this->storageRoot);
            shell_exec($cmd);
        }
        parent::tearDown();
    }

    private function gate(): AtlasMaestroPacketShapeOperatorReviewGate
    {
        return new AtlasMaestroPacketShapeOperatorReviewGate($this->storageRoot, fn (): int => $this->clock);
    }

    /**
     * @return string  the shape id written
     */
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

    public function test_forward_to_queue_without_approval_refuses_no_operator_approval(): void
    {
        $id = $this->writeProposal();

        $result = $this->gate()->forwardToQueue($id);

        $this->assertInstanceOf(ReviewGateRefusal::class, $result);
        $this->assertSame(ReviewGateRefusal::CODE_NO_OPERATOR_APPROVAL, $result->code);
        $this->assertFileDoesNotExist($this->storageRoot.'/atlas/maestro/dialogue/approved/'.$id.'.json');
    }

    public function test_tamper_between_present_and_approve_throws_with_both_hashes(): void
    {
        $id = $this->writeProposal();
        $gate = $this->gate();
        $bundle = $gate->present($id);

        // Mutate the proposed file AFTER present() recorded its hash.
        $path = $this->storageRoot.'/atlas/maestro/dialogue/proposed/'.$id.'.json';
        $current = (array) json_decode((string) file_get_contents($path), true);
        $current['objective'] = $current['objective'].' (TAMPERED)';
        file_put_contents($path, (string) json_encode($current, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        try {
            $gate->approve($id, 'op-sig-1', 'rec-1');
            $this->fail('expected ReviewGateTamperException');
        } catch (ReviewGateTamperException $e) {
            $this->assertSame($bundle->proposalHash, $e->recordedProposalHash);
            $this->assertNotSame($bundle->proposalHash, $e->currentProposalHash);
        }
    }

    public function test_approve_writes_json_with_exact_canonical_key_set(): void
    {
        $id = $this->writeProposal();
        $gate = $this->gate();
        $gate->present($id);

        $approved = $gate->approve($id, 'op-sig-A', 'receipt-A');

        $this->assertInstanceOf(ApprovedShape::class, $approved);
        $path = $this->storageRoot.'/atlas/maestro/dialogue/approved/'.$id.'.json';
        $this->assertFileExists($path);
        $row = (array) json_decode((string) file_get_contents($path), true);
        $this->assertSame(ApprovedShape::REQUIRED_KEYS, array_keys($row), 'approved JSON has exactly the canonical key set');
    }

    public function test_reject_writes_json_with_exact_canonical_key_set_including_reason(): void
    {
        $id = $this->writeProposal();
        $gate = $this->gate();
        $gate->present($id);

        $rejected = $gate->reject($id, 'op-sig-R', 'wrong scope');

        $this->assertInstanceOf(RejectedShape::class, $rejected);
        $path = $this->storageRoot.'/atlas/maestro/dialogue/rejected/'.$id.'.json';
        $this->assertFileExists($path);
        $row = (array) json_decode((string) file_get_contents($path), true);
        $this->assertSame(RejectedShape::REQUIRED_KEYS, array_keys($row));
        $this->assertSame('wrong scope', $row['reason']);
    }

    public function test_forward_to_queue_after_approve_succeeds_and_carries_approval(): void
    {
        $id = $this->writeProposal('shape-fwd');
        $gate = $this->gate();
        $gate->present($id);
        $gate->approve($id, 'op-sig-F', 'rec-F');

        $result = $gate->forwardToQueue($id);
        $this->assertIsArray($result);
        $this->assertTrue($result['queued']);
        $this->assertSame($id, $result['shape_id']);
        $this->assertArrayHasKey('approval_hash', $result['approval']);
    }

    public function test_forward_to_queue_refuses_a_rejected_shape(): void
    {
        $id = $this->writeProposal('shape-rej');
        $gate = $this->gate();
        $gate->present($id);
        $gate->reject($id, 'op-sig', 'no');

        $result = $gate->forwardToQueue($id);
        $this->assertInstanceOf(ReviewGateRefusal::class, $result);
        $this->assertSame(ReviewGateRefusal::CODE_REJECTED, $result->code);
    }

    public function test_stale_proposal_is_not_auto_promoted_no_default_yes_by_age(): void
    {
        $id = $this->writeProposal('shape-stale');
        // Backdate the proposed file's mtime to 60 days ago.
        $sixtyDaysAgo = $this->clock - (60 * 86_400);
        touch($this->storageRoot.'/atlas/maestro/dialogue/proposed/'.$id.'.json', $sixtyDaysAgo);

        $gate = $this->gate();
        $bundle = $gate->present($id);

        $this->assertTrue($bundle->stale, 'present() marks an old proposal STALE');

        $result = $gate->forwardToQueue($id);
        $this->assertInstanceOf(ReviewGateRefusal::class, $result, 'STALE shape STILL refused without approve()');
        $this->assertSame(ReviewGateRefusal::CODE_NO_OPERATOR_APPROVAL, $result->code);
    }

    public function test_forward_to_queue_refuses_when_proposal_mutated_after_approval(): void
    {
        $id = $this->writeProposal('shape-mut');
        $gate = $this->gate();
        $gate->present($id);
        $gate->approve($id, 'op', 'rec');

        // Mutate AFTER approval — forwardToQueue must refuse because approved.proposal_hash no longer matches.
        $path = $this->storageRoot.'/atlas/maestro/dialogue/proposed/'.$id.'.json';
        $current = (array) json_decode((string) file_get_contents($path), true);
        $current['objective'] = 'changed!';
        file_put_contents($path, (string) json_encode($current, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $result = $gate->forwardToQueue($id);
        $this->assertInstanceOf(ReviewGateRefusal::class, $result);
        $this->assertSame(ReviewGateRefusal::CODE_NO_OPERATOR_APPROVAL, $result->code);
        $this->assertStringContainsString('mismatch', strtolower($result->detail));
    }
}
