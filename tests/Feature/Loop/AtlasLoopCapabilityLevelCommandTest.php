<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the capability-ladder classifier is live at the operator surface: satisfying only the lowest
 * prerequisite yields a low level with a label; satisfying more consecutive prerequisites yields a strictly
 * higher level.
 */
final class AtlasLoopCapabilityLevelCommandTest extends TestCase
{
    private function classify(array $signals): array
    {
        $exit = Artisan::call('atlas:loop:capability-level', [
            '--signals' => (string) json_encode($signals),
            '--json' => true,
        ]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_lowest_prerequisite_only_is_low_level(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->classify(['has_canonical_doc' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame(1, $d['level'], (string) json_encode($d));
        $this->assertNotSame('', $d['label']);
        $this->assertSame(['has_canonical_doc'], $d['achieved_prerequisites']);
    }

    public function test_more_prerequisites_is_strictly_higher_level(): void
    {
        $low = $this->classify(['has_canonical_doc' => true])['d'];

        ['exit' => $exit, 'd' => $high] = $this->classify([
            'has_canonical_doc' => true,
            'has_spec' => true,
            'has_scaffold' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertGreaterThan($low['level'], $high['level'], (string) json_encode($high));
        $this->assertNotSame('', $high['label']);
    }

    public function test_empty_signals_option_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:capability-level', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }

    public function test_invalid_json_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:capability-level', ['--signals' => 'nope', '--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
