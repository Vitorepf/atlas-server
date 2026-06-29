<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Defect\AtlasLoopDefectFalsificationGate;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the defect falsification gate is live at the operator surface: a candidate lacking a reproducing
 * red_command is NOT admitted (fail-closed); a candidate with a genuinely-failing red_command and a positive
 * fix delta IS admitted.
 */
final class AtlasLoopDefectFalsifyCommandTest extends TestCase
{
    private function admit(array $candidate): array
    {
        $exit = Artisan::call('atlas:loop:defect-falsify', [
            '--candidate' => (string) json_encode($candidate),
            '--json' => true,
        ]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_unreproduced_candidate_is_not_admitted(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->admit([
            'hypothesis' => 'maybe the parser breaks on empty input',
            // no red_command, no red_observed
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasLoopDefectFalsificationGate::SCHEMA, $d['schema']);
        $this->assertFalse($d['admitted'], (string) json_encode($d));
        $this->assertContains('no_red_command', $d['blocking_reasons']);
    }

    public function test_reproduced_red_candidate_is_admitted(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->admit([
            'hypothesis' => 'parser throws on empty input',
            'red_command' => 'php artisan test --filter=ParserEmptyInput',
            'red_observed' => true,
            'behavior_delta_after_fix' => 3,
        ]);

        $this->assertSame(0, $exit);
        $this->assertTrue($d['admitted'], (string) json_encode($d));
        $this->assertSame([], $d['blocking_reasons']);
    }

    public function test_missing_candidate_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:defect-falsify', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
