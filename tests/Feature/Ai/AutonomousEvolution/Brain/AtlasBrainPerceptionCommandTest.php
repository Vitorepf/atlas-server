<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class AtlasBrainPerceptionCommandTest extends TestCase
{
    public function test_perception_dumps_bundle_payload(): void
    {
        config()->set('atlas.brain.scopes.loop', ['label' => 't', 'roots' => [], 'docs_roots' => [], 'meta_harness' => true]);
        config()->set('atlas.brain.default_scope', 'loop');

        $buf = new BufferedOutput;
        Artisan::call('atlas:brain:perception', [], $buf);
        $payload = json_decode(trim($buf->fetch()), true);

        self::assertSame('loop', $payload['scope']);
        self::assertArrayHasKey('brief_histogram', $payload);
        self::assertArrayHasKey('hint_entropy', $payload);
        self::assertArrayHasKey('path_starvation', $payload);
    }

    public function test_perception_command_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit('app/Console/Commands/AtlasBrainPerceptionCommand.php', true);
        self::assertSame('forbidden', $verdict);
    }
}
