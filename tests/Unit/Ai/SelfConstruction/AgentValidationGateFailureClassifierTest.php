<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentValidationGateFailureClassifier;
use PHPUnit\Framework\TestCase;

final class AgentValidationGateFailureClassifierTest extends TestCase
{
    private function classifier(): AgentValidationGateFailureClassifier
    {
        return new AgentValidationGateFailureClassifier;
    }

    public function test_supply_blocking_gate_ids_return_queue_supply_blocker_with_high_or_critical_severity(): void
    {
        foreach (AgentValidationGateFailureClassifier::SUPPLY_BLOCKING_GATE_IDS as $gateId) {
            $result = $this->classifier()->classify([
                'gate_id' => $gateId,
                'gate_type' => 'admission',
                'observed_status' => 'fail',
                'severity' => 'low',
            ]);

            $this->assertSame('queue_supply_blocker', $result['supply_impact']);
            $this->assertContains($result['severity'], ['high', 'critical']);
        }
    }

    public function test_unknown_gate_id_with_fail_status_is_terminal_manual_review(): void
    {
        $result = $this->classifier()->classify([
            'gate_id' => 'some_unrecognized_gate',
            'gate_type' => 'custom',
            'observed_status' => 'fail',
            'severity' => 'medium',
        ]);

        $this->assertSame('unknown', $result['category']);
        $this->assertTrue($result['is_terminal']);
        $this->assertSame('manual_review', $result['recovery_class']);
    }

    public function test_pass_and_skip_results_are_non_failure_and_not_applicable(): void
    {
        $pass = $this->classifier()->classify([
            'gate_id' => 'php_lint',
            'observed_status' => 'pass',
            'severity' => 'low',
        ]);
        $skip = $this->classifier()->classify([
            'gate_id' => 'php_lint',
            'observed_status' => 'skip',
            'severity' => 'low',
        ]);

        $this->assertSame('non_failure', $pass['category']);
        $this->assertSame('not_applicable', $pass['supply_impact']);
        $this->assertFalse($pass['is_failure_classification']);

        $this->assertSame('non_failure', $skip['category']);
        $this->assertSame('not_applicable', $skip['supply_impact']);
        $this->assertFalse($skip['is_failure_classification']);
    }
}
