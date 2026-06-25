<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\FrozenContracts\AtlasLoopFrozenContractCoverageReporter;
use App\Services\Ai\AutonomousEvolution\FrozenContracts\AtlasLoopFrozenContractFactExtractor;
use PHPUnit\Framework\TestCase;

/**
 * A fixture class WITH a frozen-contract docblock. Used as a known input to the reporter.
 *
 * INVARIANT: this class emits FACTS only.
 * PETREO: never relax thresholds at runtime.
 */
final class FrozenContractCoverageFixtureWithContract
{
}

/** A plain fixture class — its docblock carries no contract markers. */
final class FrozenContractCoverageFixtureWithoutContract
{
}

/**
 * Proves the frozen-contract coverage reporter: emits FACT buckets keyed by class FQCN, classes move
 * between with_contract / without_contract by their real docblock content, and the output carries ZERO
 * Goodhart-prone keys (percent, score, total, ratio).
 */
final class AtlasLoopFrozenContractCoverageReporterTest extends TestCase
{
    private function reporter(array $classes, ?string $sentinelDir = null): AtlasLoopFrozenContractCoverageReporter
    {
        return new AtlasLoopFrozenContractCoverageReporter(
            classListSource: static fn (): array => $classes,
            sentinelTestsDirResolver: static fn (): string => $sentinelDir ?? '',
        );
    }

    public function test_class_with_frozen_docblock_lands_in_with_contract_bucket(): void
    {
        $report = $this->reporter([
            FrozenContractCoverageFixtureWithContract::class,
            FrozenContractCoverageFixtureWithoutContract::class,
        ])->report();

        $this->assertContains(FrozenContractCoverageFixtureWithContract::class, $report['with_contract']);
        $this->assertContains(FrozenContractCoverageFixtureWithoutContract::class, $report['without_contract']);
        $this->assertNotContains(FrozenContractCoverageFixtureWithContract::class, $report['without_contract']);
    }

    public function test_class_with_sentinel_test_on_disk_lands_in_with_sentinel(): void
    {
        $tmpDir = sys_get_temp_dir().'/atlas_coverage_sentinels_'.bin2hex(random_bytes(6));
        mkdir($tmpDir, 0775, true);
        try {
            file_put_contents(
                $tmpDir.'/FrozenContractCoverageFixtureWithContractFrozenContractSentinelTest.php',
                "<?php\n"
            );

            $report = $this->reporter([FrozenContractCoverageFixtureWithContract::class], $tmpDir)->report();

            $this->assertContains(FrozenContractCoverageFixtureWithContract::class, $report['with_sentinel']);
            $this->assertSame([], $report['orphan_contracts_without_sentinel']);
        } finally {
            shell_exec('rm -rf '.escapeshellarg($tmpDir));
        }
    }

    public function test_contract_class_without_a_sentinel_test_appears_in_orphan_bucket(): void
    {
        $tmpDir = sys_get_temp_dir().'/atlas_coverage_empty_'.bin2hex(random_bytes(6));
        mkdir($tmpDir, 0775, true);
        try {
            $report = $this->reporter([FrozenContractCoverageFixtureWithContract::class], $tmpDir)->report();

            $this->assertContains(FrozenContractCoverageFixtureWithContract::class, $report['orphan_contracts_without_sentinel']);
            $this->assertNotContains(FrozenContractCoverageFixtureWithContract::class, $report['with_sentinel']);
        } finally {
            @rmdir($tmpDir);
        }
    }

    public function test_report_carries_no_goodhart_prone_keys(): void
    {
        $report = $this->reporter([FrozenContractCoverageFixtureWithContract::class])->report();

        foreach (array_keys($report) as $key) {
            $this->assertDoesNotMatchRegularExpression(
                '/percent|^score$|total|ratio/i',
                (string) $key,
                'report must not carry Goodhart-prone key: '.$key,
            );
        }
    }

    public function test_buckets_are_byte_stable_sort_order(): void
    {
        $report = $this->reporter([
            'ZZZ\\Alpha',
            'AAA\\Beta',
            FrozenContractCoverageFixtureWithContract::class,
        ])->report();

        $merged = array_merge($report['with_contract'], $report['without_contract']);
        $sorted = $merged;
        sort($sorted, SORT_STRING);
        // Each bucket is independently sorted; the union may not be sorted overall, but each bucket is.
        $this->assertSame($report['without_contract'], array_values($this->sortedCopy($report['without_contract'])));
        $this->assertSame($report['with_contract'], array_values($this->sortedCopy($report['with_contract'])));
    }

    public function test_unknown_class_in_seed_lands_in_without_contract_safely(): void
    {
        $report = $this->reporter(['Some\\NonExistent\\Class'])->report();

        $this->assertContains('Some\\NonExistent\\Class', $report['without_contract']);
    }

    public function test_extractor_picks_up_the_same_invariants_the_reporter_sees(): void
    {
        // Cross-check: the FactExtractor sees the same invariant prefixes the reporter detects on the
        // fixture, so the coverage answer ties back to the FACTS the sentinel generator consumes.
        $facts = (new AtlasLoopFrozenContractFactExtractor)->extract(FrozenContractCoverageFixtureWithContract::class);
        $this->assertNotEmpty($facts['invariants']);
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function sortedCopy(array $values): array
    {
        sort($values, SORT_STRING);

        return $values;
    }
}
