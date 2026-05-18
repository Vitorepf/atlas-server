<?php

namespace App\Services\Ai\Programming\Forge;

use InvalidArgumentException;

class ForgeIntakeException extends InvalidArgumentException
{
    public static function emptyPrompt(): self
    {
        return new self('Forge intake requires a non-empty prompt or escalation packet intent.');
    }

    public static function invalidOrigin(string $origin): self
    {
        $allowed = implode(', ', ForgeIntakeCanon::ALLOWED_ORIGINS);

        return new self("Invalid Forge intake origin [{$origin}]; allowed: [{$allowed}].");
    }

    public static function invalidRecommendedForgeMode(string $mode): self
    {
        $allowed = implode(', ', ForgeIntakeCanon::ALLOWED_RECOMMENDED_FORGE_MODES);

        return new self("Invalid recommended_forge_mode [{$mode}]; allowed: [{$allowed}].");
    }

    public static function invalidRiskBand(string $band): self
    {
        $allowed = implode(', ', ForgeIntakeCanon::RISK_BANDS);

        return new self("Invalid risk_band [{$band}]; allowed: [{$allowed}].");
    }
}
