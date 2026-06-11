<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

final class AtlasForgeRivalsInputNormalizer
{
    public static function batteryMode(string $mode): string
    {
        return match (strtolower($mode)) {
            'power', 'full-power' => AtlasForgeRivalsModeRegistry::MODE_FULL_POWER,
            '' => AtlasForgeRivalsModeRegistry::MODE_FAIR,
            default => strtolower($mode),
        };
    }

    public static function arenaMode(string $mode): string
    {
        return match (strtolower($mode)) {
            'power', 'full-power' => AtlasForgeRivalsModeRegistry::MODE_FULL_POWER,
            'provider-arena', 'arena' => AtlasForgeRivalsModeRegistry::MODE_PROVIDER_ARENA,
            'provider-pure', 'pure' => AtlasForgeRivalsModeRegistry::MODE_PROVIDER_PURE,
            '' => AtlasForgeRivalsModeRegistry::MODE_PROVIDER_ARENA,
            default => strtolower($mode),
        };
    }

    public static function evidenceVerificationMode(?string $mode): string
    {
        $mode = is_string($mode) ? strtolower(trim($mode)) : '';

        return match ($mode) {
            AtlasForgeRivalsEvidencePackVerifierService::MODE_DRY_RUN, 'dryrun', 'plan_only' => AtlasForgeRivalsEvidencePackVerifierService::MODE_DRY_RUN,
            AtlasForgeRivalsEvidencePackVerifierService::MODE_FAKE_RUN, 'local_fake', 'fake' => AtlasForgeRivalsEvidencePackVerifierService::MODE_FAKE_RUN,
            AtlasForgeRivalsEvidencePackVerifierService::MODE_REAL_RUN, 'real', 'fair', 'full_power' => AtlasForgeRivalsEvidencePackVerifierService::MODE_REAL_RUN,
            AtlasForgeRivalsEvidencePackVerifierService::MODE_REPLAY, 'integrity', 'check' => AtlasForgeRivalsEvidencePackVerifierService::MODE_REPLAY,
            default => AtlasForgeRivalsEvidencePackVerifierService::MODE_REPLAY,
        };
    }

    public static function lowercaseModelAlias(string $model): string
    {
        return match (strtolower($model)) {
            'sonnet' => AtlasForgeRivalsModelMatrix::MODEL_CLAUDE_SONNET,
            'opus' => AtlasForgeRivalsModelMatrix::MODEL_CLAUDE_OPUS,
            default => strtolower($model),
        };
    }

    public static function trimmedModelAlias(string $model): string
    {
        $model = trim($model);

        return match ($model) {
            'sonnet' => AtlasForgeRivalsModelMatrix::MODEL_CLAUDE_SONNET,
            'opus' => AtlasForgeRivalsModelMatrix::MODEL_CLAUDE_OPUS,
            default => $model,
        };
    }
}
