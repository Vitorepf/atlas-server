<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Adaptive;

use App\Services\Ai\SelfConstruction\Maestro\Adaptive\AtlasMaestroGiveBackPatternMiner;
use App\Services\Ai\SelfConstruction\Maestro\Adaptive\AtlasMaestroPacketReshaper;
use Tests\TestCase;

final class AtlasMaestroPacketReshaperTest extends TestCase
{
    public function test_flag_off_returns_packet_fields_byte_identical_with_passthrough_receipt(): void
    {
        config(['atlas.maestro.adaptive.reshape_enabled' => false]);
        $packet = $this->packet();

        $result = (new AtlasMaestroPacketReshaper($this->miner()))->reshape($packet);

        $receipt = $result['reshape_receipt'];
        unset($result['reshape_receipt']);
        $this->assertSame($packet, $result);
        $this->assertSame('passthrough', $receipt['kind']);
        $this->assertSame($receipt['original_hash'], $receipt['reshaped_hash']);
    }

    public function test_flag_on_narrows_allowed_files_without_mutating_packet_semantics(): void
    {
        config(['atlas.maestro.adaptive.reshape_enabled' => true]);
        $packet = $this->packet();

        $result = (new AtlasMaestroPacketReshaper($this->miner()))->reshape($packet);

        $this->assertNotSame($packet['allowed_files'], $result['allowed_files']);
        $this->assertNotEmpty($result['allowed_files']);
        foreach ($result['allowed_files'] as $path) {
            $this->assertContains($path, $packet['allowed_files']);
        }
        $this->assertSame($packet['objective'], $result['objective']);
        $this->assertSame($packet['acceptance_criteria'], $result['acceptance_criteria']);
        $this->assertSame($packet['required_evidence'], $result['required_evidence']);
        $this->assertSame($packet['scope_in'], $result['scope_in']);
        $this->assertSame($packet['depends_on'], $result['depends_on']);
        $this->assertStringStartsWith('app/Services/Ai/SelfConstruction', $result['allowed_files'][0]);
    }

    public function test_reshape_receipt_always_contains_hashes_and_miner_facts_used(): void
    {
        config(['atlas.maestro.adaptive.reshape_enabled' => true]);

        $receipt = (new AtlasMaestroPacketReshaper($this->miner()))->reshape($this->packet())['reshape_receipt'];

        $this->assertArrayHasKey('original_hash', $receipt);
        $this->assertArrayHasKey('reshaped_hash', $receipt);
        $this->assertArrayHasKey('miner_facts_used', $receipt);
        $this->assertNotSame('', $receipt['original_hash']);
        $this->assertNotSame('', $receipt['reshaped_hash']);
        $this->assertNotSame([], $receipt['miner_facts_used']);
    }

    public function test_default_flag_fallback_is_false_without_config_file_write(): void
    {
        config(['atlas.maestro.adaptive.reshape_enabled' => null]);

        $result = (new AtlasMaestroPacketReshaper($this->miner()))->reshape($this->packet());

        $this->assertSame('passthrough', $result['reshape_receipt']['kind']);
    }

    // -----------------------------------------------------------------------
    // propose() — poison-pattern → respec proposals
    // -----------------------------------------------------------------------

    public function test_missing_impl_file_pattern_emits_repair_proposal(): void
    {
        $packet = $this->packetWithAcceptance();
        $patterns = [['type' => 'missing_impl_file', 'missing_files' => ['app/Services/Ai/SelfConstruction/Maestro/Adaptive/Missing.php']]];

        $result = (new AtlasMaestroPacketReshaper)->propose($packet, $patterns);

        $this->assertArrayHasKey('proposals', $result);
        $this->assertCount(1, $result['proposals']);

        $proposal = $result['proposals'][0];
        $this->assertSame('repair', $proposal['action']);
        $this->assertSame('missing_impl_file_added', $proposal['reason']);
        $this->assertSame('missing_impl_file', $proposal['pattern']);
        $this->assertContains(
            'app/Services/Ai/SelfConstruction/Maestro/Adaptive/Missing.php',
            $proposal['respec']['allowed_files'],
        );
        // Original files are preserved.
        foreach ($packet['allowed_files'] as $f) {
            $this->assertContains($f, $proposal['respec']['allowed_files']);
        }
    }

    public function test_contradictory_acceptance_pattern_emits_retire_proposal(): void
    {
        $packet = $this->packetWithAcceptance();
        $patterns = [['type' => 'contradictory_acceptance', 'reason' => 'criterion A requires X; criterion B forbids X']];

        $result = (new AtlasMaestroPacketReshaper)->propose($packet, $patterns);

        $proposal = $result['proposals'][0];
        $this->assertSame('retire', $proposal['action']);
        $this->assertSame('contradictory_acceptance_unrepairable', $proposal['reason']);
        $this->assertSame('contradictory_acceptance', $proposal['pattern']);
        $this->assertArrayHasKey('detail', $proposal);
        $this->assertStringContainsString('X', $proposal['detail']);
    }

    public function test_missing_impl_file_without_acceptance_retires_never_repairs(): void
    {
        $packet = array_merge($this->packetWithAcceptance(), ['acceptance_criteria' => []]);
        $patterns = [['type' => 'missing_impl_file', 'missing_files' => ['app/Services/Foo.php']]];

        $result = (new AtlasMaestroPacketReshaper)->propose($packet, $patterns);

        $proposal = $result['proposals'][0];
        $this->assertSame('retire', $proposal['action']);
        $this->assertSame('repair_would_leave_no_runnable_acceptance', $proposal['reason']);
        $this->assertArrayNotHasKey('respec', $proposal);
    }

    public function test_repair_proposal_always_carries_runnable_acceptance(): void
    {
        $packet = $this->packetWithAcceptance();
        $patterns = [['type' => 'missing_impl_file', 'missing_files' => ['app/Services/Extra.php']]];

        $result = (new AtlasMaestroPacketReshaper)->propose($packet, $patterns);

        $proposal = $result['proposals'][0];
        $this->assertSame('repair', $proposal['action']);
        $this->assertNotEmpty($proposal['respec']['acceptance_criteria']);
        foreach ($proposal['respec']['acceptance_criteria'] as $criterion) {
            $this->assertNotSame('', trim($criterion));
        }
    }

    public function test_multiple_patterns_produce_multiple_proposals(): void
    {
        $packet = $this->packetWithAcceptance();
        $patterns = [
            ['type' => 'missing_impl_file',       'missing_files' => ['app/A.php']],
            ['type' => 'contradictory_acceptance', 'reason' => 'conflict'],
        ];

        $result = (new AtlasMaestroPacketReshaper)->propose($packet, $patterns);

        $this->assertCount(2, $result['proposals']);
        $actions = array_column($result['proposals'], 'action');
        $this->assertContains('repair', $actions);
        $this->assertContains('retire', $actions);
    }

    public function test_proposals_include_original_packet_id(): void
    {
        $packet = array_merge($this->packetWithAcceptance(), ['task_packet_id' => 'test-packet-123']);
        $patterns = [
            ['type' => 'missing_impl_file', 'missing_files' => ['app/X.php']],
            ['type' => 'contradictory_acceptance'],
        ];

        $result = (new AtlasMaestroPacketReshaper)->propose($packet, $patterns);

        foreach ($result['proposals'] as $p) {
            $this->assertSame('test-packet-123', $p['original_packet_id']);
        }
    }

    public function test_propose_empty_patterns_returns_empty_proposals(): void
    {
        $result = (new AtlasMaestroPacketReshaper)->propose($this->packetWithAcceptance(), []);

        $this->assertSame([], $result['proposals']);
        $this->assertSame(AtlasMaestroPacketReshaper::SCHEMA, $result['schema']);
    }

    /** @return array<string,mixed> */
    private function packetWithAcceptance(): array
    {
        return [
            'task_packet_id'      => 'packet-xyz',
            'objective'           => 'Implement something.',
            'allowed_files'       => ['app/Services/Ai/SelfConstruction/Maestro/Adaptive/Svc.php'],
            'acceptance_criteria' => ['/opt/homebrew/bin/php artisan test --filter=SvcTest'],
            'required_evidence'   => ['tests_or_gates_result'],
        ];
    }

    private function miner(): AtlasMaestroGiveBackPatternMiner
    {
        return new AtlasMaestroGiveBackPatternMiner([
            ...$this->minerRows(5, 4),
        ]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function minerRows(int $served, int $giveBack): array
    {
        $rows = [];
        for ($i = 0; $i < $served; $i++) {
            $rows[] = [
                'task_class' => 'wide-scope',
                'served_delta' => 1,
                'give_back_delta' => $i < $giveBack ? 1 : 0,
                'allowed_files' => $this->packet()['allowed_files'],
                'scope_in' => $this->packet()['scope_in'],
                'required_evidence' => ['tests_or_gates_result'],
            ];
        }

        return $rows;
    }

    /**
     * @return array<string,mixed>
     */
    private function packet(): array
    {
        return [
            'objective' => 'Implement a wide packet safely.',
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/Maestro/Adaptive/Foo.php',
                'app/Services/Ai/SelfConstruction/Maestro/Adaptive/Bar.php',
                'tests/Unit/Ai/SelfConstruction/Maestro/Adaptive/FooTest.php',
            ],
            'acceptance_criteria' => ['green focused tests'],
            'required_evidence' => ['tests_or_gates_result'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/Maestro/Adaptive/Foo.php'],
            'depends_on' => ['upstream-ledger'],
        ];
    }

    // ── forbidden_target, schema_mismatch, duplicate_or_noop ─────────────────

    public function test_forbidden_target_retires_instead_of_faking_repair(): void
    {
        $r = (new AtlasMaestroPacketReshaper($this->miner()))->propose($this->packet(), [
            ['type' => 'forbidden_target', 'detail' => 'file is pétreo'],
        ]);

        $this->assertSame('retire', $r['proposals'][0]['action']);
        $this->assertSame('forbidden_target_unrepairable', $r['proposals'][0]['reason']);
    }

    public function test_schema_mismatch_repairs_with_expected_schema(): void
    {
        $r = (new AtlasMaestroPacketReshaper($this->miner()))->propose($this->packet(), [
            ['type' => 'schema_mismatch', 'expected_schema' => 'atlas.task_serving.envelope.v2'],
        ]);

        $this->assertSame('repair', $r['proposals'][0]['action']);
        $this->assertSame('atlas.task_serving.envelope.v2', $r['proposals'][0]['expected_schema']);
        $this->assertSame('atlas.task_serving.envelope.v2', $r['proposals'][0]['respec']['schema']);
    }

    public function test_duplicate_or_noop_emits_behavior_proof_not_rename(): void
    {
        $r = (new AtlasMaestroPacketReshaper($this->miner()))->propose($this->packet(), [
            ['type' => 'duplicate_or_noop'],
        ]);

        $this->assertSame('repair', $r['proposals'][0]['action']);
        $respec = $r['proposals'][0]['respec'];
        $this->assertStringContainsString('measurable behavior change', $respec['objective']);
        $this->assertStringContainsString('artisan test', $respec['acceptance_criteria'][0]);
        $this->assertContains('tests_or_gates_result', $respec['required_evidence']);
    }
}
