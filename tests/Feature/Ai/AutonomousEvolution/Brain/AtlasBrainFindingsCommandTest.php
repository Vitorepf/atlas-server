<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class AtlasBrainFindingsCommandTest extends TestCase
{
    public function test_findings_emits_code_to_path_mapping(): void
    {
        $buf = new BufferedOutput;
        Artisan::call('atlas:brain:findings', [], $buf);
        $payload = json_decode(trim($buf->fetch()), true);

        self::assertGreaterThan(15, $payload['count']);
        self::assertNotEmpty($payload['mapping']);
        // Spot-check one well-known mapping.
        $codes = array_column($payload['mapping'], 'code');
        self::assertContains('gate_regression', $codes);
        self::assertContains('frontier_empty', $codes);
    }

    public function test_findings_csv_format_outputs_header_and_rows(): void
    {
        $buf = new BufferedOutput;
        Artisan::call('atlas:brain:findings', ['--csv' => true], $buf);
        $out = trim($buf->fetch());

        $lines = explode("\n", $out);
        self::assertSame('code,recommended_path', $lines[0]);
        self::assertGreaterThan(10, count($lines), 'should have header + many rows');
        // Spot-check format on a known mapping line.
        self::assertNotFalse(strpos($out, 'gate_regression,adversarial-critique'));
    }

    public function test_findings_command_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit('app/Console/Commands/AtlasBrainFindingsCommand.php', true);
        self::assertSame('forbidden', $verdict);
    }
}
