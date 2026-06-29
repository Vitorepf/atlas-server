<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Quaternity\CortexIntentMeaning;

use App\Services\Ai\AutonomousEvolution\Quaternity\CortexIntentMeaning\AtlasCortexIntentMeaningTriangulator;
use PHPUnit\Framework\TestCase;

/**
 * Proves the Cortex intent-meaning triangulator: real-symbol anchoring via cortex roles, fail-closed empty on
 * unknown tokens (no guessing), multi-match preserved + byte-stable ordering for the downstream ambiguity
 * detector, and evidence-kind merging when one site is reported by more than one collaborator.
 */
final class AtlasCortexIntentMeaningTriangulatorTest extends TestCase
{
    private const ROLES_FQCN = 'App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopGroundedProjectionRoles';

    private const ROLES_FILE = 'app/Services/Ai/AutonomousEvolution/AtlasLoopGroundedProjectionRoles.php';

    private const CENSUS_FQCN = 'App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopChangedSymbolCoverageCensus';

    private const CENSUS_FILE = 'app/Services/Ai/AutonomousEvolution/AtlasLoopChangedSymbolCoverageCensus.php';

    /**
     * @param  list<array<string,mixed>>  $sites
     */
    private function provider(array $sites): object
    {
        return new class($sites)
        {
            /** @param list<array<string,mixed>> $sites */
            public function __construct(private readonly array $sites)
            {
            }

            /** @return list<array<string,mixed>> */
            public function groundedSites(): array
            {
                return $this->sites;
            }
        };
    }

    private function triangulator(?object $roles = null, ?object $originator = null, ?object $census = null): AtlasCortexIntentMeaningTriangulator
    {
        return new AtlasCortexIntentMeaningTriangulator(
            $roles ?? $this->provider([['symbol' => self::ROLES_FQCN, 'file' => self::ROLES_FILE]]),
            $originator ?? $this->provider([]),
            $census ?? $this->provider([['symbol' => self::CENSUS_FQCN, 'file' => self::CENSUS_FILE]]),
        );
    }

    public function test_anchors_intent_to_a_real_cortex_role_symbol(): void
    {
        $rows = $this->triangulator()->triangulate('refator no AtlasLoopGroundedProjectionRoles');

        $this->assertCount(1, $rows, 'exactly one grounded site matches the intent');
        $this->assertStringEndsWith('AtlasLoopGroundedProjectionRoles', (string) $rows[0]['symbol']);
        $this->assertContains('cortex_role', $rows[0]['evidence'], 'evidence is the grounding cortex role');
        $this->assertSame('refator no AtlasLoopGroundedProjectionRoles', $rows[0]['intent']);
    }

    public function test_returns_empty_array_on_zero_matches_never_guesses(): void
    {
        $rows = $this->triangulator()->triangulate('xyzzy nonexistent token plugh');

        $this->assertSame([], $rows, 'no grounded site ⇒ empty, never a guess');
    }

    public function test_returns_all_distinct_matches_sorted_byte_stably(): void
    {
        $rows = $this->triangulator()->triangulate('AtlasLoopGroundedProjectionRoles e AtlasLoopChangedSymbolCoverageCensus juntos');

        $this->assertGreaterThanOrEqual(2, count($rows), 'multi-match preserved for the ambiguity detector');
        $symbols = array_column($rows, 'symbol');
        $this->assertContains(self::ROLES_FQCN, $symbols);
        $this->assertContains(self::CENSUS_FQCN, $symbols);
        // Sorted by file: ...ChangedSymbolCoverageCensus.php < ...GroundedProjectionRoles.php (C < G).
        $files = array_column($rows, 'file');
        $sorted = $files;
        sort($sorted);
        $this->assertSame($sorted, $files, 'rows are byte-stable by file');
        $this->assertSame(self::CENSUS_FILE, $files[0]);
    }

    public function test_merges_evidence_kinds_when_a_site_is_reported_by_two_collaborators(): void
    {
        // Same symbol/file reported by BOTH the cortex roles and the changed-symbol census.
        $roles = $this->provider([['symbol' => self::ROLES_FQCN, 'file' => self::ROLES_FILE]]);
        $census = $this->provider([['symbol' => self::ROLES_FQCN, 'file' => self::ROLES_FILE]]);

        $rows = $this->triangulator($roles, $this->provider([]), $census)->triangulate('AtlasLoopGroundedProjectionRoles');

        $this->assertCount(1, $rows, 'one site even when two collaborators report it');
        $this->assertContains('cortex_role', $rows[0]['evidence']);
        $this->assertContains('changed_symbol', $rows[0]['evidence']);
    }

    public function test_generator_based_collaborator_sites_are_collected(): void
    {
        $fqcn = self::ROLES_FQCN;
        $file = self::ROLES_FILE;
        $generatorProvider = new class($fqcn, $file) {
            public function __construct(private string $fqcn, private string $file) {}

            /** @return \Generator<array<string,mixed>> */
            public function groundedSites(): \Generator
            {
                yield ['symbol' => $this->fqcn, 'file' => $this->file];
            }
        };

        $rows = $this->triangulator($generatorProvider)->triangulate('AtlasLoopGroundedProjectionRoles');

        $this->assertNotEmpty($rows, 'generator collaborator must contribute its sites');
        $this->assertContains(self::ROLES_FQCN, array_column($rows, 'symbol'));
    }

    public function test_strips_stopwords_and_short_tokens(): void
    {
        // 'no' is a stopword and 'e' is below the length floor + a stopword — neither should match anything.
        $rows = $this->triangulator($this->provider([['symbol' => null, 'file' => 'app/no.php']]), $this->provider([]), $this->provider([]))
            ->triangulate('no e de');

        $this->assertSame([], $rows, 'stopwords/short tokens never match a grounded site');
    }
}
