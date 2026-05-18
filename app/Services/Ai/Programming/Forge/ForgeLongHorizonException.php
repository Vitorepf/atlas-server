<?php

namespace App\Services\Ai\Programming\Forge;

use RuntimeException;

class ForgeLongHorizonException extends RuntimeException
{
    public static function intakeNotPersisted(): self
    {
        return new self('atlas.forge.long_horizon_state: intake must be persisted before initializing state');
    }

    public static function stateAlreadyInitialized(string $intakeId): self
    {
        return new self('atlas.forge.long_horizon_state: state already exists for intake '.$intakeId);
    }

    public static function obraAlreadyCompleted(string $obraId): self
    {
        return new self('atlas.forge.long_horizon_state: obra '.$obraId.' is already completed');
    }

    public static function certificationMissing(string $obraId): self
    {
        return new self('atlas.forge.long_horizon_state: cannot complete obra '.$obraId.' without certification evidence');
    }

    public static function certificationGateNotPassed(string $obraId): self
    {
        return new self('atlas.forge.long_horizon_state: cannot complete obra '.$obraId.' without certification milestone gates passing');
    }

    public static function invalidStatus(string $status): self
    {
        return new self('atlas.forge.long_horizon_state: invalid status '.$status);
    }
}
