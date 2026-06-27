<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasBrainCatalogCommandTest extends TestCase
{
    public function test_dump_lists_all_paths_by_default(): void
    {
        config()->set('atlas.brain.paths', [
            ['id' => 'a', 'intent' => 'self_improvement', 'objective_kind' => 'research', 'executor_organ' => 'App\\A', 'lens' => 'l1'],
            ['id' => 'b', 'intent' => 'self_improvement', 'objective_kind' => 'refactor', 'executor_organ' => 'App\\B', 'lens' => 'l2'],
        ]);

        Artisan::call('atlas:brain:catalog', ['--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        self::assertSame(2, $payload['count']);
        self::assertSame('a', $payload['paths'][0]['id']);
        self::assertSame('App\\A', $payload['paths'][0]['executor_organ']);
    }

    public function test_filters_by_intent_and_kind(): void
    {
        config()->set('atlas.brain.paths', [
            ['id' => 'a', 'intent' => 'self_improvement', 'objective_kind' => 'research'],
            ['id' => 'b', 'intent' => 'self_improvement', 'objective_kind' => 'refactor'],
            ['id' => 'c', 'intent' => 'maintenance', 'objective_kind' => 'refactor'],
        ]);

        Artisan::call('atlas:brain:catalog', ['--intent' => 'self_improvement', '--kind' => 'refactor', '--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        self::assertSame(1, $payload['count']);
        self::assertSame('b', $payload['paths'][0]['id']);
    }

    public function test_catalog_command_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit('app/Console/Commands/AtlasBrainCatalogCommand.php', true);
        self::assertSame('forbidden', $verdict);
    }
}
