<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog\ValidationCommandCoveragePlanner;
use PHPUnit\Framework\TestCase;

final class ValidationCommandCoveragePlannerTest extends TestCase
{
    private ValidationCommandCoveragePlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new ValidationCommandCoveragePlanner();
    }

    public function testPhpFileWithPhpstanAvailableAddsPhpstanCommand(): void
    {
        $result = $this->planner->plan(
            ['app/Services/Foo.php'],
            [],
            ['phpstan' => true],
        );

        $this->assertSame('atlas.validation.command_coverage_plan.v1', $result['schema_version']);
        $this->assertContains('phpstan analyse', $result['commands']);
        $this->assertContains('php', $result['covered_extensions']);
        $this->assertSame([], $result['missing_toolchain']);
        $this->assertSame('full', $result['coverage_status']);
    }

    public function testDeclaredDuplicateCommandIsNotDuplicated(): void
    {
        $result = $this->planner->plan(
            ['app/Services/Foo.php'],
            ['phpstan analyse'],
            ['phpstan' => true],
        );

        $occurrences = array_keys($result['commands'], 'phpstan analyse', true);

        $this->assertCount(1, $occurrences);
        $this->assertSame(['phpstan analyse'], $result['commands']);
    }

    public function testTsFileWithoutTscAddsMissingToolchainTs(): void
    {
        $result = $this->planner->plan(
            ['resources/js/app.ts'],
            [],
            ['phpstan' => true],
        );

        $this->assertContains('ts', $result['missing_toolchain']);
        $this->assertNotContains('tsc --noEmit', $result['commands']);
        $this->assertNotContains('ts', $result['covered_extensions']);
    }

    public function testSecretScanAvailableAddsSecretCommandOnce(): void
    {
        $result = $this->planner->plan(
            ['app/Services/Foo.php', 'app/Services/Bar.php'],
            [],
            ['phpstan' => true, 'secret_scan' => true],
        );

        $occurrences = array_keys($result['commands'], 'secret-scan --staged', true);

        $this->assertCount(1, $occurrences);
        $this->assertContains('secret-scan --staged', $result['commands']);
        $this->assertContains('phpstan analyse', $result['commands']);
    }

    public function testChangedFilesWithNoValidationProduceBlocked(): void
    {
        $result = $this->planner->plan(
            ['resources/js/app.ts'],
            [],
            ['phpstan' => false],
        );

        $this->assertSame('blocked', $result['coverage_status']);
        $this->assertSame([], $result['commands']);
        $this->assertContains('ts', $result['missing_toolchain']);
    }

    public function testMixedAvailabilityProducesPartialCoverage(): void
    {
        $result = $this->planner->plan(
            ['app/Services/Foo.php', 'resources/js/app.ts'],
            [],
            ['phpstan' => true],
        );

        $this->assertSame('partial', $result['coverage_status']);
        $this->assertContains('phpstan analyse', $result['commands']);
        $this->assertContains('php', $result['covered_extensions']);
        $this->assertContains('ts', $result['missing_toolchain']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $changedFiles = ['app/Services/Foo.php', 'resources/js/app.ts'];
        $declaredCommands = ['composer test'];
        $toolchain = ['phpstan' => true, 'secret_scan' => true];

        $first = $this->planner->plan($changedFiles, $declaredCommands, $toolchain);
        $second = $this->planner->plan($changedFiles, $declaredCommands, $toolchain);

        $this->assertSame($first, $second);
        $this->assertSame(
            ['composer test', 'phpstan analyse', 'secret-scan --staged'],
            $first['commands'],
        );
    }
}
