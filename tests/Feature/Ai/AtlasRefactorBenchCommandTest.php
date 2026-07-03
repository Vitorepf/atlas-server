<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** Freezes the bench envelope: ok path yields a median, a failing command fail-closes. */
final class AtlasRefactorBenchCommandTest extends TestCase
{
    public function test_ok_path_reports_median_wall_time(): void
    {
        $exit = Artisan::call('atlas:refactor:bench', ['cmd' => 'true', '--runs' => 2, '--label' => 'before', '--json' => true]);
        $out = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.refactor.bench.v1', $out['schema']);
        $this->assertSame('ok', $out['status']);
        $this->assertSame('before', $out['label']);
        $this->assertSame(2, $out['runs']);
        $this->assertIsFloat($out['median_ms'] + 0.0);
    }

    public function test_failing_command_fail_closes(): void
    {
        $exit = Artisan::call('atlas:refactor:bench', ['cmd' => 'exit 7', '--runs' => 2, '--json' => true]);
        $out = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $this->assertSame('command_failed', $out['status']);
        $this->assertSame(7, $out['failed']['exit_code']);
    }
}
