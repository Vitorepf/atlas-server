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
}
