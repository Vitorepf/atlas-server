<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AutonomousEvolution\Campaign\AtlasLoopCampaignSupervisor;
use App\Services\Ai\AutonomousEvolution\AtlasLoopProviderSwapPolicy;
use App\Services\Ai\AutonomousEvolution\AtlasLoopProviderEffortPolicy;
use Closure;
use Tests\TestCase;

final class AtlasLoopCampaignSupervisorTest extends TestCase
{
    public function test_set_test_dependencies_unifies_all_seams(): void
    {
        $supervisor = $this->app->make(AtlasLoopCampaignSupervisor::class);

        $clock = fn (): int => 123;
        $sleeper = fn (int $ms): null => null;
        $gitHead = fn (): string => 'abc';
        $changedFiles = fn (): array => [];
        $origination = fn (string $r, string $s, array $p): array => [];
        $swapPolicy = new AtlasLoopProviderSwapPolicy;
        $effortPolicy = new AtlasLoopProviderEffortPolicy;

        $supervisor->setTestDependencies([
            'clock' => $clock,
            'sleeper' => $sleeper,
            'git_head_resolver' => $gitHead,
            'changed_files_resolver' => $changedFiles,
            'storage_root' => '/tmp/atlas-test',
            'provider_swap_policy' => $swapPolicy,
            'provider_effort_policy' => $effortPolicy,
            'origination_producer' => $origination,
        ]);

        $reflection = new \ReflectionClass($supervisor);
        $this->assertSame($clock, $reflection->getProperty('clock')->getValue($supervisor));
        $this->assertSame($sleeper, $reflection->getProperty('sleeper')->getValue($supervisor));
        $this->assertSame($gitHead, $reflection->getProperty('gitHeadResolver')->getValue($supervisor));
        $this->assertSame($changedFiles, $reflection->getProperty('changedFilesResolver')->getValue($supervisor));
        $this->assertSame('/tmp/atlas-test', $reflection->getProperty('storageRoot')->getValue($supervisor));
        $this->assertSame($swapPolicy, $reflection->getProperty('swapPolicy')->getValue($supervisor));
        $this->assertSame($effortPolicy, $reflection->getProperty('effortPolicy')->getValue($supervisor));
        $this->assertSame($origination, $reflection->getProperty('originationProducer')->getValue($supervisor));
    }

    public function test_legacy_setters_still_work(): void
    {
        $supervisor = $this->app->make(AtlasLoopCampaignSupervisor::class);

        $clock = fn (): int => 456;
        $sleeper = fn (int $ms): null => null;
        $gitHead = fn (): string => 'def';
        $changedFiles = fn (): array => [];
        $origination = fn (string $r, string $s, array $p): array => [];
        $swapPolicy = new AtlasLoopProviderSwapPolicy;
        $effortPolicy = new AtlasLoopProviderEffortPolicy;

        $supervisor->setClockForTesting($clock);
        $supervisor->setSleeperForTesting($sleeper);
        $supervisor->setGitHeadResolverForTesting($gitHead);
        $supervisor->setChangedFilesResolverForTesting($changedFiles);
        $supervisor->setStorageRootForTesting('/tmp/atlas-legacy');
        $supervisor->setProviderSwapPolicyForTesting($swapPolicy);
        $supervisor->setProviderEffortPolicyForTesting($effortPolicy);
        $supervisor->setOriginationProducerForTesting($origination);

        $reflection = new \ReflectionClass($supervisor);
        $this->assertSame($clock, $reflection->getProperty('clock')->getValue($supervisor));
        $this->assertSame($sleeper, $reflection->getProperty('sleeper')->getValue($supervisor));
        $this->assertSame($gitHead, $reflection->getProperty('gitHeadResolver')->getValue($supervisor));
        $this->assertSame($changedFiles, $reflection->getProperty('changedFilesResolver')->getValue($supervisor));
        $this->assertSame('/tmp/atlas-legacy', $reflection->getProperty('storageRoot')->getValue($supervisor));
        $this->assertSame($swapPolicy, $reflection->getProperty('swapPolicy')->getValue($supervisor));
        $this->assertSame($effortPolicy, $reflection->getProperty('effortPolicy')->getValue($supervisor));
        $this->assertSame($origination, $reflection->getProperty('originationProducer')->getValue($supervisor));
    }
}
