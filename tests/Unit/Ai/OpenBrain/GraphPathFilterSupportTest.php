<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\OpenBrain;

use App\Services\Ai\AtlasOpenBrainContextPackService;
use App\Services\Ai\AtlasOpenBrainWriteBackService;
use App\Services\Ai\OpenBrain\Support\GraphPathFilterSupport;
use PHPUnit\Framework\TestCase;

/**
 * Pure GraphPathFilterSupport peel: wiper-safe static helpers, zero DB.
 * BC: AtlasOpenBrainContextPackService static methods must match Support.
 */
final class GraphPathFilterSupportTest extends TestCase
{
    public function test_runtime_fingerprint_is_stable_and_order_independent(): void
    {
        $a = GraphPathFilterSupport::runtimeFingerprint(
            ['b_flag', 'a_flag'],
            'schema.v1',
            'runtime-v1',
        );
        $b = GraphPathFilterSupport::runtimeFingerprint(
            ['a_flag', 'b_flag'],
            'schema.v1',
            'runtime-v1',
        );

        self::assertSame(64, strlen($a));
        self::assertSame($a, $b);
        self::assertNotSame(
            $a,
            GraphPathFilterSupport::runtimeFingerprint(['a_flag'], 'schema.v1', 'runtime-v1'),
        );
        self::assertNotSame(
            $a,
            GraphPathFilterSupport::runtimeFingerprint(['a_flag', 'b_flag'], 'schema.v2', 'runtime-v1'),
        );
    }

    public function test_service_bc_wrappers_match_support(): void
    {
        $raw = "line1\n\nline2";
        self::assertSame(
            GraphPathFilterSupport::sanitizeGraphLabel($raw),
            AtlasOpenBrainContextPackService::sanitizeGraphLabel($raw),
        );

        self::assertSame(
            GraphPathFilterSupport::isSessionArtifactLabel('session capture'),
            AtlasOpenBrainContextPackService::isSessionArtifactLabel('session capture'),
        );

        $path = ['target' => 'mission:uuid', 'seed' => 'code:module:app'];
        $chain = [[
            'label' => 'Application Services',
            'origin' => AtlasOpenBrainWriteBackService::MISSION_ORIGIN_SESSION_CAPTURE,
        ]];
        self::assertSame(
            GraphPathFilterSupport::isSessionArtifactPath($path, $chain),
            AtlasOpenBrainContextPackService::isSessionArtifactPath($path, $chain),
        );

        $docPath = ['target' => 'mission:docs-cleanup'];
        $docChain = [
            ['label' => 'Atualizar docs canonicas', 'source_kind' => 'mission'],
        ];
        self::assertSame(
            GraphPathFilterSupport::isDocumentationMissionPath('fix bug', $docPath, $docChain),
            AtlasOpenBrainContextPackService::isDocumentationMissionPath('fix bug', $docPath, $docChain),
        );
    }

    public function test_service_runtime_profile_fingerprint_matches_support_hash(): void
    {
        $profile = AtlasOpenBrainContextPackService::runtimeProfile();
        $features = AtlasOpenBrainContextPackService::RUNTIME_FEATURE_FLAGS;
        $expected = GraphPathFilterSupport::runtimeFingerprint(
            $features,
            AtlasOpenBrainContextPackService::RUNTIME_SCHEMA,
            AtlasOpenBrainContextPackService::RUNTIME_VERSION,
        );

        self::assertSame($expected, $profile['runtime_fingerprint']);
    }
}
