<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Cognitive;

use App\Console\Commands\AtlasDreyfusCommand;
use App\Console\Commands\AtlasFailureAutoFeedCommand;
use App\Console\Commands\AtlasFailureCommand;
use App\Console\Commands\AtlasFailureWeeklyRedSnapshotCommand;
use App\Console\Commands\AtlasHarnessCommand;
use App\Console\Commands\AtlasPatternCommand;
use App\Console\Commands\AtlasPredictCommand;
use App\Console\Commands\AtlasPredictiveCodeIntelligenceGateCommand;
use App\Console\Commands\AtlasProductiveFailureCommand;
use App\Console\Commands\AtlasSRLCommand;
use App\Console\Commands\AtlasWorkedExampleCommand;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Tests\TestCase;

final class CognitiveNamespaceM2aCharacterizationTest extends TestCase
{
    public function test_cognitive_command_names_and_legacy_fqcns_are_frozen_before_m2b(): void
    {
        $commands = $this->app->make(ConsoleKernel::class)->all();

        foreach ([
            'atlas:srl' => AtlasSRLCommand::class,
            'atlas:predict' => AtlasPredictCommand::class,
            'atlas:harness' => AtlasHarnessCommand::class,
            'atlas:dreyfus' => AtlasDreyfusCommand::class,
            'atlas:failure' => AtlasFailureCommand::class,
            'atlas:failure:auto-feed' => AtlasFailureAutoFeedCommand::class,
            'atlas:failure:weekly-red-snapshot' => AtlasFailureWeeklyRedSnapshotCommand::class,
            'atlas:pattern' => AtlasPatternCommand::class,
            'atlas:productive-failure' => AtlasProductiveFailureCommand::class,
            'atlas:worked-example' => AtlasWorkedExampleCommand::class,
            'atlas:cognition:predictive-code-intelligence-gate' => AtlasPredictiveCodeIntelligenceGateCommand::class,
        ] as $name => $commandClass) {
            $this->assertArrayHasKey($name, $commands);
            $this->assertInstanceOf($commandClass, $commands[$name]);
            $this->assertSame($name, $commands[$name]->getName());
        }

        foreach ([
            'App\\Services\\Ai\\Cognitive\\SRL\\SRLOrchestrator',
            'App\\Services\\Ai\\Cognitive\\PredictiveFailure\\PredictiveFailureFlow',
            'App\\Services\\Ai\\Cognitive\\Failure\\FailureSignatureRepository',
            'App\\Services\\Ai\\Cognitive\\Harness\\AtlasHarnessSurface',
            'App\\Services\\Ai\\Cognitive\\WorkedExample\\WorkedExampleRenderer',
        ] as $legacyFqcn) {
            $this->assertTrue(class_exists($legacyFqcn), $legacyFqcn.' must remain autoloadable through M2b compatibility.');
        }
    }
}
