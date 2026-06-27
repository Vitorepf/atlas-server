<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * FROZEN proof of the brain plan command — single-purpose wrapper over the doctor's recommended_action.
 */
final class AtlasBrainPlanCommandTest extends TestCase
{
    public function test_plan_returns_recommended_block(): void
    {
        $base = sys_get_temp_dir().'/atlas-brain-plan-'.bin2hex(random_bytes(4));
        @mkdir($base.'/done-set', 0o775, true);
        config()->set('atlas.brain.done_set_root', $base.'/done-set');
        config()->set('atlas.brain.scopes.loop', ['label' => 'test', 'roots' => [], 'docs_roots' => [], 'meta_harness' => true]);
        config()->set('atlas.brain.default_scope', 'loop');

        $buf = new BufferedOutput;
        Artisan::call('atlas:brain:plan', [], $buf);
        $payload = json_decode(trim($buf->fetch()), true);

        self::assertSame('loop', $payload['scope']);
        self::assertArrayHasKey('recommended', $payload);
        self::assertArrayHasKey('rationale', $payload);
    }

    public function test_plan_command_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit('app/Console/Commands/AtlasBrainPlanCommand.php', true);
        self::assertSame('forbidden', $verdict);
    }
}
