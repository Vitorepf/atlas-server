<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\Maestro\Health;

use App\Services\Ai\SelfConstruction\Maestro\Health\AtlasMaestroMalformedSweepRegressionGate;
use PHPUnit\Framework\TestCase;

final class AtlasMaestroMalformedSweepRegressionGateTest extends TestCase
{
    private AtlasMaestroMalformedSweepRegressionGate $gate;

    protected function setUp(): void
    {
        $this->gate = new AtlasMaestroMalformedSweepRegressionGate;
    }

    private function cleanInput(): array
    {
        return [
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'malformed_sweep' => [
                'dry_run' => true,
                'inspected_claimable' => 5,
                'blocked_count' => 0,
                'would_block_count' => 0,
                'blocked' => [],
                'would_block' => [],
            ],
        ];
    }

    // ── AC: zero malformed allows expansion ──

    public function test_zero_would_block_allows_expansion(): void
    {
        $result = $this->gate->evaluate($this->cleanInput());

        $this->assertSame(AtlasMaestroMalformedSweepRegressionGate::VERDICT_ALLOW, $result['verdict']);
        $this->assertTrue($result['allows_expansion']);
        $this->assertSame([], $result['blocked_reasons']);
        $this->assertSame([], $result['task_specs']);
    }

    // ── AC: nonzero malformed returns repair-first ──

    public function test_nonzero_would_block_returns_repair_first(): void
    {
        $input = $this->cleanInput();
        $input['malformed_sweep']['would_block_count'] = 2;
        $input['malformed_sweep']['would_block'] = [
            ['task_packet_id' => 'pkt-1', 'blocking_deficiencies' => ['missing_objective']],
            ['task_packet_id' => 'pkt-2', 'blocking_deficiencies' => ['missing_acceptance_criteria']],
        ];

        $result = $this->gate->evaluate($input);

        $this->assertSame(AtlasMaestroMalformedSweepRegressionGate::VERDICT_REPAIR_FIRST, $result['verdict']);
        $this->assertFalse($result['allows_expansion']);
        $this->assertSame(['malformed_sweep_would_block:2'], $result['blocked_reasons']);
    }

    // ── AC: blocked reasons are preserved for task specs ──

    public function test_blocked_reasons_are_preserved_in_task_specs(): void
    {
        $input = $this->cleanInput();
        $input['malformed_sweep']['would_block_count'] = 1;
        $input['malformed_sweep']['would_block'] = [
            ['task_packet_id' => 'pkt-bad', 'blocking_deficiencies' => ['missing_scope', 'not_self_sufficient']],
        ];

        $result = $this->gate->evaluate($input);

        $this->assertCount(1, $result['task_specs']);
        $spec = $result['task_specs'][0];
        $this->assertSame('pkt-bad', $spec['task_packet_id']);
        $this->assertSame(['missing_scope', 'not_self_sufficient'], $spec['blocking_deficiencies']);
        $this->assertSame('repair_packet_quality', $spec['proposed_action']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->gate->evaluate($this->cleanInput());

        $this->assertSame(AtlasMaestroMalformedSweepRegressionGate::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('verdict', $result);
        $this->assertArrayHasKey('would_block_count', $result);
        $this->assertArrayHasKey('blocked_reasons', $result);
        $this->assertArrayHasKey('task_specs', $result);
        $this->assertArrayHasKey('allows_expansion', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $input = [
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'malformed_sweep' => [
                'would_block_count' => 1,
                'would_block' => [
                    ['task_packet_id' => 'pkt-x', 'blocking_deficiencies' => ['bad']],
                ],
            ],
        ];

        $a = $this->gate->evaluate($input);
        $b = $this->gate->evaluate($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
