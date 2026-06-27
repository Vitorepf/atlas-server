<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainFrontierIdeaSeed;
use Tests\TestCase;

final class AtlasBrainFrontierIdeaSeedTest extends TestCase
{
    public function test_seeds_non_empty(): void
    {
        self::assertGreaterThan(0, (new AtlasBrainFrontierIdeaSeed)->count());
    }

    public function test_each_seed_has_id_and_summary(): void
    {
        foreach ((new AtlasBrainFrontierIdeaSeed)->seeds() as $s) {
            self::assertNotEmpty($s['id']);
            self::assertNotEmpty($s['summary']);
        }
    }

    public function test_seed_ids_unique(): void
    {
        $ids = array_column((new AtlasBrainFrontierIdeaSeed)->seeds(), 'id');
        self::assertSame(count($ids), count(array_unique($ids)));
    }

    public function test_organ_is_petreo(): void
    {
        self::assertSame(
            'forbidden',
            app(AtlasLoopHarnessGuard::class)->admit(
                'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainFrontierIdeaSeed.php',
                true
            )
        );
    }
}
