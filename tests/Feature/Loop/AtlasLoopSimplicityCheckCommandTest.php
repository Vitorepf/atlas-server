<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the simplicity-contract sentinel is live at the operator surface: a record missing its simplicity
 * contract breaches the contract (non-empty violations, fail); a record carrying the default contract passes.
 */
final class AtlasLoopSimplicityCheckCommandTest extends TestCase
{
    private function check(array $records): array
    {
        $exit = Artisan::call('atlas:loop:simplicity-check', [
            '--records' => (string) json_encode($records),
            '--json' => true,
        ]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_missing_contract_breaches(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->check([
            ['task_packet' => ['task_packet_id' => 'p1']], // no simplicity_contract ⇒ missing
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame('fail', $d['status'], (string) json_encode($d));
        $this->assertFalse($d['passed']);
        $this->assertSame(1, $d['missing_count']);
        $this->assertContains('simplicity_contract_missing_in_claimable_packets', $d['blockers']);
    }

    public function test_default_contract_record_passes(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->check([
            ['task_packet' => ['simplicity_contract' => AgentControlPlaneTaskPacketBuilder::defaultSimplicityContract()]],
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame('pass', $d['status'], (string) json_encode($d));
        $this->assertTrue($d['passed']);
        $this->assertSame([], $d['blockers']);
        $this->assertSame(1, $d['inspected_count']);
    }

    public function test_missing_records_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:simplicity-check', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
