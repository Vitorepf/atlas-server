<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * ACDE Bloco C — the head-to-head command turns two frozen-panel JSON files into a machine-resolved verdict
 * and refuses to compare mismatched panels.
 */
final class AtlasLoopDeliveryQualityCommandTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/atlas-dqs-cmd-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0o755, true);
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            (new Process(['rm', '-rf', $this->dir]))->run();
        }
        parent::tearDown();
    }

    /** @param list<array<string,mixed>> $panel */
    private function write(string $name, array $panel): string
    {
        $path = $this->dir.'/'.$name;
        file_put_contents($path, json_encode($panel));

        return $path;
    }

    private function panel(int $n, int $refused, int $red): array
    {
        $p = [];
        for ($i = 0; $i < $n; $i++) {
            if ($i < $refused) {
                $p[] = ['attempted' => true, 'committed' => false];
            } elseif ($i < $refused + $red) {
                $p[] = ['attempted' => true, 'committed' => true, 'canary' => 'red'];
            } else {
                $p[] = ['attempted' => true, 'committed' => true, 'canary' => 'green', 'mutation_kill_ratio' => 0.85, 'completeness' => 1.0, 'cyclomatic_drop' => 4.0];
            }
        }

        return $p;
    }

    public function test_a_confident_2x_panel_reports_a_at_least_factor_better(): void
    {
        $ace = $this->write('ace.json', $this->panel(100, 5, 0));
        $opus = $this->write('opus.json', $this->panel(100, 0, 40));

        $this->artisan('atlas:loop:dqs-head-to-head', ['--ace' => $ace, '--opus' => $opus, '--factor' => '2.0', '--json' => true])
            ->expectsOutputToContain('a_at_least_factor_better')
            ->assertExitCode(0);
    }

    public function test_mismatched_panels_are_refused(): void
    {
        $ace = $this->write('ace.json', $this->panel(30, 1, 0));
        $opus = $this->write('opus.json', $this->panel(50, 1, 0));

        $this->artisan('atlas:loop:dqs-head-to-head', ['--ace' => $ace, '--opus' => $opus, '--json' => true])
            ->expectsOutputToContain('panel_mismatch')
            ->assertExitCode(0);
    }

    public function test_missing_panel_file_fails_cleanly(): void
    {
        $this->artisan('atlas:loop:dqs-head-to-head', ['--ace' => $this->dir.'/nope.json', '--opus' => $this->dir.'/nope2.json'])
            ->assertExitCode(1);
    }
}
