<?php

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsProviderArenaCorpusService;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Hard gate for the Atlas Forge Rivals release corpus: every canonical case
 * must ship a seed directory that contains at least one non-README payload.
 * A README-only seed contaminates the head-to-head score because the
 * competing arm receives no real artefact to operate on.
 *
 * This test fails if any seed is empty, missing, or README-only, so authors
 * cannot accidentally publish a synthetic corpus.
 */
final class AtlasForgeRivalsCorpusFixtureCompletenessTest extends TestCase
{
    private const CANONICAL_ROOT = 'storage/forge-rivals-corpus';

    /** @return iterable<string, array{0: array<string,mixed>}> */
    public static function releaseCaseProvider(): iterable
    {
        $catalog = (new AtlasForgeRivalsProviderArenaCorpusService)->cases();
        foreach ($catalog as $case) {
            $caseId = (string) ($case['case_id'] ?? '');
            yield $caseId => [$case];
        }
    }

    /**
     * @param  array<string,mixed>  $case
     */
    #[DataProvider('releaseCaseProvider')]
    public function test_seed_has_real_non_readme_files(array $case): void
    {
        $caseId = (string) ($case['case_id'] ?? '');
        $fixturePath = (string) ($case['fixture_seed_path'] ?? '');
        $this->assertNotSame('', $fixturePath, "case {$caseId} declares no fixture_seed_path");

        $absolute = base_path($fixturePath);
        $this->assertDirectoryExists(
            $absolute,
            "case {$caseId} seed dir missing at {$fixturePath}; arms cannot reproduce setup_fixture",
        );

        $allFiles = $this->listFiles($absolute);
        $this->assertNotEmpty($allFiles, "case {$caseId} seed dir is empty");

        $nonReadme = array_values(array_filter(
            $allFiles,
            static fn (string $relative): bool => strtolower(basename($relative)) !== 'readme.md',
        ));
        $this->assertNotEmpty(
            $nonReadme,
            "case {$caseId} ships only README.md — arms have no real artefact to act on",
        );

        foreach ($nonReadme as $relative) {
            $size = (int) filesize($absolute.DIRECTORY_SEPARATOR.$relative);
            $this->assertGreaterThan(
                0,
                $size,
                "case {$caseId} seed file {$relative} is empty",
            );
        }
    }

    public function test_release_case_set_has_no_readme_only_seed(): void
    {
        $catalog = (new AtlasForgeRivalsProviderArenaCorpusService)->cases();
        $readmeOnly = [];
        $missing = [];
        foreach ($catalog as $case) {
            $caseId = (string) ($case['case_id'] ?? '');
            $absolute = base_path((string) ($case['fixture_seed_path'] ?? ''));
            if (! is_dir($absolute)) {
                $missing[] = $caseId;

                continue;
            }
            $nonReadme = array_values(array_filter(
                $this->listFiles($absolute),
                static fn (string $relative): bool => strtolower(basename($relative)) !== 'readme.md',
            ));
            if ($nonReadme === []) {
                $readmeOnly[] = $caseId;
            }
        }

        $this->assertSame([], $missing, 'cases missing fixture seed dir: '.implode(',', $missing));
        $this->assertSame([], $readmeOnly, 'cases shipping README-only seed: '.implode(',', $readmeOnly));
    }

    public function test_release_case_set_seed_total_matches_case_count(): void
    {
        $catalog = (new AtlasForgeRivalsProviderArenaCorpusService)->cases();
        $caseIds = array_map(static fn (array $c): string => (string) $c['case_id'], $catalog);
        $this->assertCount(40, $caseIds, 'release corpus must declare exactly 40 cases');

        foreach ($caseIds as $caseId) {
            $absolute = base_path(self::CANONICAL_ROOT.DIRECTORY_SEPARATOR.$caseId.DIRECTORY_SEPARATOR.'seed');
            $this->assertDirectoryExists($absolute, "case {$caseId} seed dir missing");
        }
    }

    public function test_expected_changed_files_are_not_preexisting_in_base_workspace(): void
    {
        $catalog = (new AtlasForgeRivalsProviderArenaCorpusService)->cases();
        $preexisting = [];

        foreach ($catalog as $case) {
            $caseId = (string) ($case['case_id'] ?? '');
            foreach ((array) ($case['expected_changed_files'] ?? []) as $path) {
                $relative = str_replace('\\', '/', (string) $path);
                if ($relative === '' || ! is_file(base_path($relative)) || ! $this->isTrackedByGit($relative)) {
                    continue;
                }

                $preexisting[] = "{$caseId}:{$relative}";
            }
        }

        $this->assertSame(
            [],
            $preexisting,
            'expected_changed_files already exist in the base workspace, so providers can pass with zero diff: '.implode(', ', $preexisting),
        );
    }

    private function isTrackedByGit(string $relative): bool
    {
        $proc = new Process(['git', '-C', base_path(), 'ls-files', '--error-unmatch', $relative]);
        $proc->setTimeout(10);
        $proc->run();

        return $proc->isSuccessful();
    }

    /** @return list<string> relative paths under $dir, recursive */
    private function listFiles(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        $relatives = [];
        foreach ($iterator as $entry) {
            if (! $entry->isFile()) {
                continue;
            }
            $relative = substr((string) $entry->getPathname(), strlen($dir) + 1);
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
            $relatives[] = $relative;
        }
        sort($relatives);

        return $relatives;
    }
}
