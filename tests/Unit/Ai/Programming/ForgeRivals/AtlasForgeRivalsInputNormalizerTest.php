<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsEvidencePackVerifierService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsInputNormalizer;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsModeRegistry;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsModelMatrix;
use Tests\TestCase;

final class AtlasForgeRivalsInputNormalizerTest extends TestCase
{
    public function test_battery_mode_preserves_legacy_aliases(): void
    {
        $this->assertSame(AtlasForgeRivalsModeRegistry::MODE_FAIR, AtlasForgeRivalsInputNormalizer::batteryMode(''));
        $this->assertSame(AtlasForgeRivalsModeRegistry::MODE_FULL_POWER, AtlasForgeRivalsInputNormalizer::batteryMode('power'));
        $this->assertSame(AtlasForgeRivalsModeRegistry::MODE_FULL_POWER, AtlasForgeRivalsInputNormalizer::batteryMode('full-power'));
        $this->assertSame('local_fake', AtlasForgeRivalsInputNormalizer::batteryMode('LOCAL_FAKE'));
    }

    public function test_arena_mode_has_arena_default_and_provider_aliases(): void
    {
        $this->assertSame(AtlasForgeRivalsModeRegistry::MODE_PROVIDER_ARENA, AtlasForgeRivalsInputNormalizer::arenaMode(''));
        $this->assertSame(AtlasForgeRivalsModeRegistry::MODE_PROVIDER_ARENA, AtlasForgeRivalsInputNormalizer::arenaMode('arena'));
        $this->assertSame(AtlasForgeRivalsModeRegistry::MODE_PROVIDER_PURE, AtlasForgeRivalsInputNormalizer::arenaMode('pure'));
        $this->assertSame(AtlasForgeRivalsModeRegistry::MODE_FULL_POWER, AtlasForgeRivalsInputNormalizer::arenaMode('full-power'));
    }

    public function test_evidence_mode_preserves_verifier_aliases(): void
    {
        $this->assertSame(AtlasForgeRivalsEvidencePackVerifierService::MODE_DRY_RUN, AtlasForgeRivalsInputNormalizer::evidenceVerificationMode('plan_only'));
        $this->assertSame(AtlasForgeRivalsEvidencePackVerifierService::MODE_FAKE_RUN, AtlasForgeRivalsInputNormalizer::evidenceVerificationMode('local_fake'));
        $this->assertSame(AtlasForgeRivalsEvidencePackVerifierService::MODE_REAL_RUN, AtlasForgeRivalsInputNormalizer::evidenceVerificationMode('fair'));
        $this->assertSame(AtlasForgeRivalsEvidencePackVerifierService::MODE_REPLAY, AtlasForgeRivalsInputNormalizer::evidenceVerificationMode('unknown'));
    }

    public function test_model_alias_methods_preserve_distinct_legacy_semantics(): void
    {
        $this->assertSame(AtlasForgeRivalsModelMatrix::MODEL_CLAUDE_SONNET, AtlasForgeRivalsInputNormalizer::lowercaseModelAlias('SONNET'));
        $this->assertSame('SONNET', AtlasForgeRivalsInputNormalizer::trimmedModelAlias(' SONNET '));
        $this->assertSame(AtlasForgeRivalsModelMatrix::MODEL_CLAUDE_OPUS, AtlasForgeRivalsInputNormalizer::trimmedModelAlias(' opus '));
        $this->assertSame('custom-model', AtlasForgeRivalsInputNormalizer::lowercaseModelAlias('Custom-Model'));
    }
}
