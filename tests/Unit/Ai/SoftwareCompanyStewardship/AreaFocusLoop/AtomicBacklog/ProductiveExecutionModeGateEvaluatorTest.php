<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog\ProductiveExecutionModeGateEvaluator;
use PHPUnit\Framework\TestCase;

final class ProductiveExecutionModeGateEvaluatorTest extends TestCase
{
    private ProductiveExecutionModeGateEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new ProductiveExecutionModeGateEvaluator();
    }

    /**
     * @return array<string,mixed>
     */
    private function sixGreenGates(): array
    {
        return [
            'slice_plan_locked' => true,
            'branch_worktree_ready' => true,
            'validation_commands_resolved' => true,
            'receipt_before_provider' => true,
            'budget_available' => true,
            'kill_switch_clear' => true,
        ];
    }

    public function testAllSixGatesPlusProviderAuthProduceExecute(): void
    {
        $result = $this->evaluator->evaluate(
            $this->sixGreenGates(),
            ['authorized' => true],
            ['mode' => 'productive'],
        );

        $this->assertSame('atlas.productive_execution.mode_gate.v1', $result['schema_version']);
        $this->assertSame('execute', $result['mode']);
        $this->assertTrue($result['execute_allowed']);
        $this->assertSame([], $result['blocking_reasons']);
        $this->assertSame(6, $result['gate_summary']['required_gates']);
        $this->assertSame(6, $result['gate_summary']['passed_gates']);
        $this->assertSame([], $result['gate_summary']['failed_gates']);
        $this->assertTrue($result['gate_summary']['all_gates_passed']);
        $this->assertTrue($result['gate_summary']['provider_authorized']);
    }

    public function testAnyFailedConfirmGateBlocks(): void
    {
        $gates = $this->sixGreenGates();
        $gates['validation_commands_resolved'] = false;

        $result = $this->evaluator->evaluate(
            $gates,
            ['authorized' => true],
            ['mode' => 'productive'],
        );

        $this->assertSame('blocked', $result['mode']);
        $this->assertFalse($result['execute_allowed']);
        $this->assertSame(['confirm_gate_failed:validation_commands_resolved'], $result['blocking_reasons']);
        $this->assertSame(5, $result['gate_summary']['passed_gates']);
        $this->assertSame(['validation_commands_resolved'], $result['gate_summary']['failed_gates']);
        $this->assertFalse($result['gate_summary']['all_gates_passed']);
    }

    public function testFailedKillSwitchBlocksEvenWithSmokeWork(): void
    {
        $gates = $this->sixGreenGates();
        $gates['kill_switch_clear'] = false;

        $result = $this->evaluator->evaluate(
            $gates,
            ['authorized' => true],
            ['smoke_only' => true],
        );

        // Governance precedes the fixture lane: a tripped gate blocks even smoke.
        $this->assertSame('blocked', $result['mode']);
        $this->assertFalse($result['execute_allowed']);
        $this->assertSame(['confirm_gate_failed:kill_switch_clear'], $result['blocking_reasons']);
    }

    public function testSmokeOnlyWorkProducesFixtureOnly(): void
    {
        $result = $this->evaluator->evaluate(
            $this->sixGreenGates(),
            ['authorized' => true],
            ['smoke_only' => true],
        );

        $this->assertSame('fixture_only', $result['mode']);
        $this->assertFalse($result['execute_allowed']);
        $this->assertSame([], $result['blocking_reasons']);
        $this->assertTrue($result['gate_summary']['smoke_only']);
    }

    public function testSmokeOnlyViaModeStringProducesFixtureOnlyWithoutProviderAuth(): void
    {
        // Fixture lane must not require provider authorization.
        $result = $this->evaluator->evaluate(
            $this->sixGreenGates(),
            [],
            ['mode' => 'smoke_only'],
        );

        $this->assertSame('fixture_only', $result['mode']);
        $this->assertFalse($result['execute_allowed']);
        $this->assertSame([], $result['blocking_reasons']);
        $this->assertTrue($result['gate_summary']['smoke_only']);
        $this->assertFalse($result['gate_summary']['provider_authorized']);
    }

    public function testMissingProviderAuthBlocksWithExplicitReason(): void
    {
        $result = $this->evaluator->evaluate(
            $this->sixGreenGates(),
            [],
            ['mode' => 'productive'],
        );

        $this->assertSame('blocked', $result['mode']);
        $this->assertFalse($result['execute_allowed']);
        // Fallback reason is explicit, not an empty list.
        $this->assertSame(['provider_authorization_missing'], $result['blocking_reasons']);
        $this->assertNotSame([], $result['blocking_reasons']);
        $this->assertFalse($result['gate_summary']['provider_authorized']);
        $this->assertTrue($result['gate_summary']['all_gates_passed']);
    }

    public function testRevokedProviderAuthBlocks(): void
    {
        $result = $this->evaluator->evaluate(
            $this->sixGreenGates(),
            ['authorized' => true, 'revoked' => true],
            ['mode' => 'productive'],
        );

        $this->assertSame('blocked', $result['mode']);
        $this->assertFalse($result['execute_allowed']);
        $this->assertSame(['provider_authorization_missing'], $result['blocking_reasons']);
    }

    public function testMissingGatesCountAsFailuresAndBlockInOrder(): void
    {
        // Only two of the six gates are supplied; the four missing ones must
        // surface as failures in canonical gate order (generalises beyond fixtures).
        $result = $this->evaluator->evaluate(
            [
                'slice_plan_locked' => true,
                'budget_available' => true,
            ],
            ['authorized' => true],
            ['mode' => 'productive'],
        );

        $this->assertSame('blocked', $result['mode']);
        $this->assertFalse($result['execute_allowed']);
        $this->assertSame(2, $result['gate_summary']['passed_gates']);
        $this->assertSame(
            ['branch_worktree_ready', 'validation_commands_resolved', 'receipt_before_provider', 'kill_switch_clear'],
            $result['gate_summary']['failed_gates'],
        );
        $this->assertSame(
            [
                'confirm_gate_failed:branch_worktree_ready',
                'confirm_gate_failed:validation_commands_resolved',
                'confirm_gate_failed:receipt_before_provider',
                'confirm_gate_failed:kill_switch_clear',
            ],
            $result['blocking_reasons'],
        );
    }

    public function testStringGateSignalsArePassedOnlyWhenGreen(): void
    {
        $gates = [
            'slice_plan_locked' => 'pass',
            'branch_worktree_ready' => 'green',
            'validation_commands_resolved' => 'ok',
            'receipt_before_provider' => 'passed',
            'budget_available' => 'true',
            'kill_switch_clear' => 'fail',
        ];

        $result = $this->evaluator->evaluate($gates, ['authorized' => true], ['mode' => 'productive']);

        $this->assertSame('blocked', $result['mode']);
        $this->assertSame(['kill_switch_clear'], $result['gate_summary']['failed_gates']);
        $this->assertSame(5, $result['gate_summary']['passed_gates']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $gates = $this->sixGreenGates();
        $auth = ['authorized' => true];
        $work = ['mode' => 'productive'];

        $first = $this->evaluator->evaluate($gates, $auth, $work);
        $second = $this->evaluator->evaluate($gates, $auth, $work);

        $this->assertSame($first, $second);
        $this->assertSame('execute', $first['mode']);
    }
}
