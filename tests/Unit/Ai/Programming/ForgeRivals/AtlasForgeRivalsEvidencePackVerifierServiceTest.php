<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsEventStream;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsEvidencePackVerifierService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsReplayService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsRunPathResolver;
use Tests\TestCase;

/**
 * Focused contract tests for {@see AtlasForgeRivalsEvidencePackVerifierService}.
 */
final class AtlasForgeRivalsEvidencePackVerifierServiceTest extends TestCase
{
    private string $tmpRoot;

    private AtlasForgeRivalsRunPathResolver $paths;

    private AtlasForgeRivalsEvidencePackVerifierService $verifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fr-pack-verifier-'.bin2hex(random_bytes(6));
        @mkdir($this->tmpRoot, 0o755, true);
        config(['atlas_rivals.runs_root' => $this->tmpRoot]);

        $this->paths = new AtlasForgeRivalsRunPathResolver;
        $events = new AtlasForgeRivalsEventStream($this->paths);
        $replay = new AtlasForgeRivalsReplayService($this->paths, $events);
        $this->verifier = new AtlasForgeRivalsEvidencePackVerifierService($this->paths, $replay);
    }

    protected function tearDown(): void
    {
        $this->purge($this->tmpRoot);
        parent::tearDown();
    }

    public function test_normalize_mode_preserves_canonical_modes(): void
    {
        foreach (AtlasForgeRivalsEvidencePackVerifierService::ALL_MODES as $mode) {
            $this->assertSame($mode, AtlasForgeRivalsEvidencePackVerifierService::normalizeMode($mode));
            $this->assertSame($mode, AtlasForgeRivalsEvidencePackVerifierService::normalizeMode('  '.$mode.'  '));
        }
    }

    public function test_normalize_mode_maps_operator_aliases(): void
    {
        $this->assertSame(
            AtlasForgeRivalsEvidencePackVerifierService::MODE_DRY_RUN,
            AtlasForgeRivalsEvidencePackVerifierService::normalizeMode('plan_only'),
        );
        $this->assertSame(
            AtlasForgeRivalsEvidencePackVerifierService::MODE_FAKE_RUN,
            AtlasForgeRivalsEvidencePackVerifierService::normalizeMode('local_fake'),
        );
        $this->assertSame(
            AtlasForgeRivalsEvidencePackVerifierService::MODE_REAL_RUN,
            AtlasForgeRivalsEvidencePackVerifierService::normalizeMode('fair'),
        );
        $this->assertSame(
            AtlasForgeRivalsEvidencePackVerifierService::MODE_REPLAY,
            AtlasForgeRivalsEvidencePackVerifierService::normalizeMode('integrity'),
        );
    }

    public function test_normalize_mode_defaults_unknown_to_replay(): void
    {
        $this->assertSame(
            AtlasForgeRivalsEvidencePackVerifierService::MODE_REPLAY,
            AtlasForgeRivalsEvidencePackVerifierService::normalizeMode(null),
        );
        $this->assertSame(
            AtlasForgeRivalsEvidencePackVerifierService::MODE_REPLAY,
            AtlasForgeRivalsEvidencePackVerifierService::normalizeMode(''),
        );
        $this->assertSame(
            AtlasForgeRivalsEvidencePackVerifierService::MODE_REPLAY,
            AtlasForgeRivalsEvidencePackVerifierService::normalizeMode('unknown-mode'),
        );
    }

    public function test_canonical_context_refs_include_focused_unit_test_evidence(): void
    {
        $paths = AtlasForgeRivalsEvidencePackVerifierService::canonicalContextRefPaths();

        $this->assertContains(
            'tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsEvidencePackVerifierServiceTest.php',
            $paths,
        );
        $this->assertContains(
            'tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsEvidencePackReplayHardeningV2Test.php',
            $paths,
        );
        $this->assertContains(
            'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsEvidencePackVerifierService.php',
            $paths,
        );
    }

    public function test_schema_version_and_modes_are_stable(): void
    {
        $this->assertSame(
            'atlas.forge.rivals.evidence_verification.v2',
            AtlasForgeRivalsEvidencePackVerifierService::SCHEMA_VERSION,
        );
        $this->assertSame(
            [
                AtlasForgeRivalsEvidencePackVerifierService::MODE_DRY_RUN,
                AtlasForgeRivalsEvidencePackVerifierService::MODE_FAKE_RUN,
                AtlasForgeRivalsEvidencePackVerifierService::MODE_REAL_RUN,
                AtlasForgeRivalsEvidencePackVerifierService::MODE_REPLAY,
            ],
            AtlasForgeRivalsEvidencePackVerifierService::ALL_MODES,
        );
    }

    public function test_verify_blocks_when_run_id_missing(): void
    {
        $result = $this->verifier->verify([]);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('blocked', $result['verification_status']);
        $this->assertContains('run_id_required', $result['blockers']);
        $this->assertFalse($result['claim_ready']);
    }

    public function test_verify_blocks_when_run_not_found(): void
    {
        $result = $this->verifier->verify(['run_id' => 'nonexistent-run']);

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('run_not_found:nonexistent-run', $result['blockers']);
        $this->assertFalse($result['claim_ready']);
    }

    public function test_verify_response_carries_v2_schema_and_never_calls_provider(): void
    {
        $result = $this->verifier->verify(['run_id' => '   ', 'mode' => 'fair']);

        $this->assertSame(AtlasForgeRivalsEvidencePackVerifierService::SCHEMA_VERSION, $result['schema_version']);
        $this->assertSame(AtlasForgeRivalsEvidencePackVerifierService::MODE_REAL_RUN, $result['mode']);
        $this->assertFalse($result['external_provider_call']);
        $this->assertTrue($result['no_provider_call']);
        $this->assertTrue($result['verifier_blocks_fake_evidence']);
        $this->assertSame('blocked', $result['external_rivals_certification_status']);
        $this->assertTrue($result['separated_from_external_rivals_certification']);
    }

    private function purge(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path)) {
                $this->purge($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
