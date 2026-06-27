<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class AtlasBrainScopesCommandTest extends TestCase
{
    public function test_scopes_emits_snapshot_with_count_and_default(): void
    {
        config()->set('atlas.brain.scopes', [
            'alpha' => ['label' => 'Alpha', 'roots' => ['app/A'], 'meta_harness' => true],
            'beta' => ['label' => 'Beta', 'roots' => [], 'meta_harness' => false],
        ]);
        config()->set('atlas.brain.default_scope', 'alpha');

        $buf = new BufferedOutput;
        Artisan::call('atlas:brain:scopes', [], $buf);
        $payload = json_decode(trim($buf->fetch()), true);

        self::assertSame(2, $payload['count']);
        self::assertSame('alpha', $payload['default_scope']);
        self::assertSame('alpha', $payload['scopes'][0]['slug']);
    }

    public function test_scopes_command_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit('app/Console/Commands/AtlasBrainScopesCommand.php', true);
        self::assertSame('forbidden', $verdict);
    }
}
