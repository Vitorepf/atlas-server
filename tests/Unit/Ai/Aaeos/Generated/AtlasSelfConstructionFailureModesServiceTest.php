<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasSelfConstructionFailureModesService;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionFailureModesServiceTest extends TestCase
{
    private function service(): AtlasSelfConstructionFailureModesService
    {
        return new AtlasSelfConstructionFailureModesService;
    }

    private function completePacket(): array
    {
        return [
            'operation_id' => 'op-1',
            'failure_mode' => 'drift',
            'root_cause' => 'spec and code diverged',
            'affected_docs' => ['docs/x.md'],
            'affected_files' => ['app/Foo.php'],
            'failed_gates' => ['phpunit'],
            'rollback' => 'revert commit abc',
            'prevention_proposal' => 'add drift detector test',
        ];
    }

    public function test_any_raised_red_flag_returns_stop_construction_with_concrete_reason(): void
    {
        $result = $this->service()->evaluateAutonomousRunDecision(
            redFlags: ['docs_code_disagree'],
        );

        $this->assertSame('stop_construction', $result['autonomous_run_decision']);
        $this->assertStringContainsString('red_flag_raised:docs_code_disagree', $result['stop_reason']);
    }

    public function test_incomplete_incident_packet_blocks_resume_even_without_red_flags(): void
    {
        $result = $this->service()->evaluateAutonomousRunDecision(
            redFlags: [],
            incidentOccurred: true,
            incidentPacket: ['operation_id' => 'op-1'], // missing most fields
        );

        $this->assertSame('stop_construction', $result['autonomous_run_decision']);
        $this->assertStringContainsString('incomplete_incident_packet', $result['stop_reason']);
        $this->assertFalse($result['incident_packet']['complete']);
    }

    public function test_out_of_order_recovery_blocks_resume(): void
    {
        $result = $this->service()->evaluateAutonomousRunDecision(
            redFlags: [],
            incidentOccurred: true,
            incidentPacket: $this->completePacket(),
            completedRecoverySteps: ['resume_with_smaller_receipt'], // done before earlier steps
        );

        $this->assertSame('stop_construction', $result['autonomous_run_decision']);
        $this->assertSame('recovery_order_violated', $result['stop_reason']);
    }

    public function test_no_red_flags_no_incident_returns_continue(): void
    {
        $result = $this->service()->evaluateAutonomousRunDecision(redFlags: []);

        $this->assertSame('continue', $result['autonomous_run_decision']);
        $this->assertNull($result['stop_reason']);
    }

    public function test_no_red_flags_complete_packet_and_in_order_recovery_returns_continue(): void
    {
        $result = $this->service()->evaluateAutonomousRunDecision(
            redFlags: [],
            incidentOccurred: true,
            incidentPacket: $this->completePacket(),
            completedRecoverySteps: ['stop_writes', 'preserve_evidence', 'identify_failure_mode'],
        );

        $this->assertSame('continue', $result['autonomous_run_decision']);
        $this->assertNull($result['stop_reason']);
        $this->assertTrue($result['incident_packet']['complete']);
        $this->assertTrue($result['recovery_order']['ordered']);
    }
}
