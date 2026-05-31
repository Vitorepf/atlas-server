<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

interface OwnerRuntimeFailureStateContract
{
    public function isInFailureState(): bool;

    public function getFailureType(): ?string;

    public function clearFailure(): void;
}