<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainMasterSwitch;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * FROZEN proof of the single-line brain summary command (cron/status-line friendly).
 */
final class AtlasBrainSummaryCommandTest extends TestCase
{
    private string $envPath;

    protected function setUp(): void
    {
        parent::setUp();
        $base = sys_get_temp_dir().'/atlas-brain-summary-'.bin2hex(random_bytes(6));
        @mkdir($base, 0o775, true);
        config()->set('atlas.brain.done_set_root', $base.'/done-set');
        config()->set('atlas.brain.scopes.loop', [
            'label' => 'test', 'roots' => ['app/Services/Ai/AutonomousEvolution'], 'docs_roots' => [], 'meta_harness' => true,
        ]);
        config()->set('atlas.brain.default_scope', 'loop');

        $this->envPath = $base.'/.env';
        file_put_contents($this->envPath, "APP_ENV=testing\n".AtlasBrainMasterSwitch::KEY."=true\n");
        AtlasBrainMasterSwitch::$envPathOverride = $this->envPath;
    }

    protected function tearDown(): void
    {
        AtlasBrainMasterSwitch::$envPathOverride = null;
        parent::tearDown();
    }

    public function test_summary_emits_single_line_with_expected_fields(): void
    {
        $buf = new BufferedOutput;
        $exit = Artisan::call('atlas:brain:summary', [], $buf);
        $out = trim($buf->fetch());

        self::assertSame(0, $exit, 'airtight gates ⇒ exit 0');
        self::assertStringNotContainsString("\n", $out, 'single-line output ⇒ no embedded newlines after trim');
        self::assertStringContainsString('brain[scope=loop', $out);
        self::assertStringContainsString('master=ON', $out);
        self::assertStringContainsString('gates=AIRTIGHT', $out);
        self::assertStringContainsString('findings=', $out);
        self::assertStringContainsString('starv=', $out);
        self::assertStringContainsString('entropy=', $out);
        self::assertStringContainsString('trend=', $out);
        self::assertStringContainsString('score=', $out);
        self::assertMatchesRegularExpression('/score=\d+\/100/', $out);
        self::assertStringContainsString('age=', $out);
        self::assertStringContainsString('ledger=', $out);
        self::assertStringContainsString('next=', $out);
        self::assertStringContainsString('path=', $out);
        self::assertStringContainsString('starved_paths=', $out);
        self::assertStringContainsString('gap=', $out);
    }

    public function test_compact_mode_emits_3_field_line(): void
    {
        $buf = new BufferedOutput;
        Artisan::call('atlas:brain:summary', ['--compact' => true], $buf);
        $out = trim($buf->fetch());

        self::assertStringContainsString('brain[score=', $out);
        self::assertStringContainsString('gates=', $out);
        self::assertStringContainsString('next=', $out);
        // Compact line shouldn't contain the long-form fields.
        self::assertStringNotContainsString('starv=', $out);
        self::assertStringNotContainsString('starved_paths', $out);
    }

    public function test_summary_command_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit('app/Console/Commands/AtlasBrainSummaryCommand.php', true);
        self::assertSame('forbidden', $verdict);
    }
}
