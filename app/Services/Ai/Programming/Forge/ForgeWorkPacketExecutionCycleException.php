<?php

namespace App\Services\Ai\Programming\Forge;

use RuntimeException;

class ForgeWorkPacketExecutionCycleException extends RuntimeException
{
    public static function noEligiblePacket(string $intakeId): self
    {
        return new self('atlas.forge.work_packet_execution_cycle: no eligible packet for intake '.$intakeId);
    }

    public static function invalidMode(string $mode): self
    {
        return new self('atlas.forge.work_packet_execution_cycle: invalid execution_mode '.$mode);
    }

    public static function completionWithoutEvidence(string $cycleUuid): self
    {
        return new self('atlas.forge.work_packet_execution_cycle: cannot mark cycle '.$cycleUuid.' success without evidence_refs');
    }

    public static function completionWithoutGateResult(string $cycleUuid): self
    {
        return new self('atlas.forge.work_packet_execution_cycle: cannot mark cycle '.$cycleUuid.' success without gate_result');
    }

    public static function completionWithoutPassedGate(string $cycleUuid): self
    {
        return new self('atlas.forge.work_packet_execution_cycle: cannot mark cycle '.$cycleUuid.' success when no gate is passed');
    }

    public static function cycleAlreadyTerminal(string $cycleUuid, string $status): self
    {
        return new self('atlas.forge.work_packet_execution_cycle: cycle '.$cycleUuid.' is already in terminal status '.$status);
    }
}
