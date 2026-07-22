<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopFeatureObjectiveBuilder;
use Tests\TestCase;

/**
 * SEED-FEATURE → FRAMEWORK GRIND was broken: the objective payload set allowed_files but NOT
 * target_relative_path, so EVERY seeded feature died at the framework materializer
 * ("payload requires target_relative_path (existing) + acceptance.commands"). These pin the fix: the payload
 * now carries the primary EXISTING impl file as target_relative_path, so a seeded feature is materializable.
 */
final class AtlasLoopFeatureObjectiveBuilderTargetTest extends TestCase
{
    public function test_payload_carries_an_existing_target_relative_path_and_command(): void
    {
        $built = (new AtlasLoopFeatureObjectiveBuilder)->build(
            'feat',
            'spec',
            'tests/Feature/Loop/AtlasLoopAbstainAndAskTest.php',
            ['app/Services/Ai/AutonomousEvolution/AtlasLoopAbstainAndAsk.php'],
        );

        $this->assertSame('framework', $built['payload']['materializer']);
        $this->assertSame('app/Services/Ai/AutonomousEvolution/AtlasLoopAbstainAndAsk.php', $built['payload']['target_relative_path']);
        $this->assertNotEmpty($built['payload']['acceptance']['commands'], 'acceptance.commands present');
    }

    public function test_picks_the_first_EXISTING_impl_file_as_target(): void
    {
        // a non-existent file declared first must be skipped for the existing one (the materializer needs an
        // existing target).
        $built = (new AtlasLoopFeatureObjectiveBuilder)->build(
            'feat',
            'spec',
            'tests/x.php',
            ['app/Does/Not/ExistZZZ.php', 'app/Services/Ai/AutonomousEvolution/AtlasLoopAbstainAndAsk.php'],
        );

        $this->assertSame('app/Services/Ai/AutonomousEvolution/AtlasLoopAbstainAndAsk.php', $built['payload']['target_relative_path']);
    }
}
