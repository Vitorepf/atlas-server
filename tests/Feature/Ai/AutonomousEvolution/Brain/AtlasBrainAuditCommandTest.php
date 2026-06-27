<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainMasterSwitch;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class AtlasBrainAuditCommandTest extends TestCase
{
    private string $envPath;

    protected function setUp(): void
    {
        parent::setUp();
        $base = sys_get_temp_dir().'/atlas-brain-audit-'.bin2hex(random_bytes(6));
        @mkdir($base, 0o775, true);
        config()->set('atlas.brain.done_set_root', $base.'/done-set');
        config()->set('atlas.brain.scopes.loop', [
            'label' => 'test', 'roots' => ['app/Services/Ai/AutonomousEvolution'], 'docs_roots' => [], 'meta_harness' => true,
        ]);
        config()->set('atlas.brain.default_scope', 'loop');
        $this->envPath = $base.'/.env';
        file_put_contents($this->envPath, "APP_ENV=testing\n");
        AtlasBrainMasterSwitch::$envPathOverride = $this->envPath;
    }

    protected function tearDown(): void
    {
        AtlasBrainMasterSwitch::$envPathOverride = null;
        parent::tearDown();
    }

    public function test_audit_combines_state_doctor_and_adversarial(): void
    {
        $buf = new BufferedOutput;
        Artisan::call('atlas:brain:audit', ['--json' => true], $buf);
        $payload = json_decode(trim($buf->fetch()), true);

        self::assertArrayHasKey('state', $payload);
        self::assertArrayHasKey('doctor', $payload);
        self::assertArrayHasKey('adversarial', $payload);
        self::assertArrayHasKey('inspector', $payload['adversarial']);
        self::assertArrayHasKey('seed_gate', $payload['adversarial']);
        self::assertArrayHasKey('findings', $payload['doctor']);
        self::assertSame('loop', $payload['state']['scope']['slug']);
        self::assertSame('airtight', $payload['gate_health_status']);
        self::assertSame(0, $payload['gate_health_total_holes']);
    }

    public function test_audit_command_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit('app/Console/Commands/AtlasBrainAuditCommand.php', true);
        self::assertSame('forbidden', $verdict);
    }
}
