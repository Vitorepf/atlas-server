<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class AtlasCognitionScorecardCommandTest extends TestCase
{
    public function test_command_runs_and_returns_zero_without_strict(): void
    {
        $output = new BufferedOutput;
        $code = Artisan::call('atlas:cognition:scorecard', [], $output);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('atlas.cognition.scorecard.v3', $output->fetch());
    }

    public function test_command_json_output_contains_canonical_envelope(): void
    {
        $output = new BufferedOutput;
        $code = Artisan::call('atlas:cognition:scorecard', ['--json' => true], $output);
        $text = $output->fetch();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('atlas.cognition.scorecard.v3', $text);
        $this->assertStringContainsString('"subsystem_count"', $text);
        $this->assertStringContainsString('"scorecard_hash"', $text);
        $this->assertStringContainsString('"claim_policy"', $text);

        // Validate it parses as JSON and has 31 subsystems.
        $decoded = json_decode($text, true);
        $this->assertIsArray($decoded);
        $this->assertSame(31, $decoded['report']['subsystem_count']);
    }

    public function test_strict_flag_exits_zero_when_overall_is_10(): void
    {
        $output = new BufferedOutput;
        $code = Artisan::call('atlas:cognition:scorecard', ['--strict' => true], $output);
        $this->assertSame(0, $code);
    }
}
