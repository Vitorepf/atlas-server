<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\RepoWide;

use App\Services\Ai\AutonomousEvolution\RepoWide\AtlasLoopRepoWideCallerResolver;
use ReflectionClass;
use Tests\TestCase;

final class AtlasLoopRepoWideCallerResolverTest extends TestCase
{
    public function test_callers_of_finds_imported_and_used_fqcn_callers(): void
    {
        $resolver = new AtlasLoopRepoWideCallerResolver;

        $callers = $resolver->callersOf('App\\Domain\\TargetWorker', [
            'app/Callers/RealCaller.php' => <<<'PHP'
                <?php
                namespace App\Callers;

                use App\Domain\TargetWorker;

                final class RealCaller
                {
                    public function run(TargetWorker $worker): void {}
                }
                PHP,
            'app/Callers/UnusedImport.php' => <<<'PHP'
                <?php
                namespace App\Callers;

                use App\Domain\TargetWorker;

                final class UnusedImport {}
                PHP,
        ]);

        $this->assertSame(['app/Callers/RealCaller.php'], $callers);
    }

    public function test_unrelated_substring_does_not_match(): void
    {
        $resolver = new AtlasLoopRepoWideCallerResolver;

        $callers = $resolver->callersOf('App\\Domain\\TargetWorker', [
            'app/Callers/FalsePositive.php' => <<<'PHP'
                <?php
                namespace App\Callers;

                use App\Domain\TargetWorkerFactory;

                final class FalsePositive
                {
                    public function run(TargetWorkerFactory $factory): void {}
                }
                PHP,
            'app/Callers/Prose.php' => 'TargetWorkerish and App\\Domain\\TargetWorkerFactory are not the target.',
        ]);

        $this->assertSame([], $callers);
    }

    public function test_result_is_sorted_and_deduped_across_anchor_styles(): void
    {
        $resolver = new AtlasLoopRepoWideCallerResolver;

        $callers = $resolver->callersOf('\\App\\Domain\\TargetWorker', [
            'app/Zed.php' => <<<'PHP'
                <?php
                namespace App\Other;

                final class Zed
                {
                    public function run(): string
                    {
                        return \App\Domain\TargetWorker::class;
                    }
                }
                PHP,
            'app/Able.php' => <<<'PHP'
                <?php
                namespace App\Domain;

                final class Able
                {
                    public function run(TargetWorker $worker): void {}
                }
                PHP,
            'app/Middle.php' => <<<'PHP'
                <?php
                namespace App\Other;

                use App\Domain\TargetWorker;

                final class Middle
                {
                    public function run(TargetWorker $worker): void {}
                }
                PHP,
        ]);

        $this->assertSame([
            'app/Able.php',
            'app/Middle.php',
            'app/Zed.php',
        ], $callers);
    }

    public function test_source_performs_no_disk_io(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(AtlasLoopRepoWideCallerResolver::class))->getFileName());

        $this->assertStringNotContainsString('file_get_contents', $source);
        $this->assertStringNotContainsString('glob(', $source);
        $this->assertStringNotContainsString('Storage', $source);
    }
}
