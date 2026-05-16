<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasForgeRivalsProviderArenaCorpusCertification;
use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsProviderArenaCorpusService;
use PHPUnit\Framework\TestCase;

/**
 * Atlas Forge Rivals · Provider Arena Corpus Certification — unit tests.
 *
 * Evaluates the cert against the live repo so every invariant is exercised
 * end-to-end. Status must be `available` with all 15 invariants green.
 */
final class AtlasForgeRivalsProviderArenaCorpusCertificationTest extends TestCase
{
    public function test_certification_evaluates_available_with_twenty_two_invariants_green(): void
    {
        $cert = new AtlasForgeRivalsProviderArenaCorpusCertification(
            new AtlasForgeRivalsProviderArenaCorpusService,
        );

        $eval = $cert->evaluate(['workspace' => $this->repoRoot()]);

        $this->assertSame('available', $eval['status'], 'invariants/artifacts blockers: '.implode(',', $eval['blockers']));
        $this->assertTrue($eval['ok']);
        $this->assertSame([], $eval['blockers']);

        $this->assertSame(
            AtlasForgeRivalsProviderArenaCorpusCertification::REQUIRED_INVARIANTS,
            array_keys($eval['invariants']),
        );
        $this->assertCount(22, $eval['invariants']);
        $this->assertArrayHasKey('every_case_has_canonical_difficulty_block_l1_to_l5', $eval['invariants']);
        $this->assertArrayHasKey('release_matrix_has_exactly_forty_cases', $eval['invariants']);
        $this->assertArrayHasKey('release_matrix_fills_every_cell_8x5', $eval['invariants']);
        foreach ($eval['invariants'] as $name => $row) {
            $this->assertTrue((bool) $row['ok'], "invariant '{$name}' não está verde: ".json_encode($row));
        }
        $this->assertSame(40, $eval['corpus_count']);
        $this->assertSame('release_v1', $eval['release_version']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $eval['corpus_content_hash']);
        $this->assertFalse($eval['external_provider_call']);
        $this->assertSame('external_rivals_certification', $eval['separated_from']);
    }

    public function test_certification_evaluates_missing_artifacts_when_doc_absent(): void
    {
        $tempRoot = sys_get_temp_dir().'/atlas-corpus-cert-test-'.uniqid();
        mkdir($tempRoot, 0o755, true);

        try {
            $cert = new AtlasForgeRivalsProviderArenaCorpusCertification(
                new AtlasForgeRivalsProviderArenaCorpusService,
            );
            $eval = $cert->evaluate(['workspace' => $tempRoot]);
            $this->assertSame('missing_artifacts', $eval['status']);
            $missing = (array) $eval['missing_artifacts'];
            $this->assertNotEmpty($missing);
            $this->assertContains('canonical_doc_missing', $missing);
        } finally {
            @rmdir($tempRoot);
        }
    }

    private function repoRoot(): string
    {
        // tests/Unit/Ai/Kernel/Architecture → atlas-server/ is 5 levels up.
        return rtrim(dirname(__DIR__, 5), '/');
    }
}
