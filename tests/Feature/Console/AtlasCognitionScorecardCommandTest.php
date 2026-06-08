<?php

namespace Tests\Feature\Console;

use App\Services\Ai\Cognition\AtlasCognitionScoreCardService;
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

        // Validate it parses as JSON and reports the canonical subsystem count.
        $decoded = json_decode($text, true);
        $this->assertIsArray($decoded);
        $this->assertSame(
            AtlasCognitionScoreCardService::canonicalSubsystemCount(),
            $decoded['report']['subsystem_count']
        );
    }

    /**
     * HONEST update (anti-over-claim): doc_status/pipeline_status are no longer
     * hardcoded 'ready', so the overall is now BELOW 10/10 (resolved from real
     * evidence). --strict therefore correctly exits 3 — the honest gate firing
     * because the over-claim was removed. This is NOT a loosening: it asserts the
     * gate now BITES (strict surfaces the real gap), where the old test presumed a
     * self-declared 10/10. Renamed from test_strict_flag_exits_zero_when_overall_is_10.
     */
    public function test_strict_flag_exits_three_when_overall_below_10_after_evidence_resolution(): void
    {
        $output = new BufferedOutput;
        $code = Artisan::call('atlas:cognition:scorecard', ['--strict' => true], $output);

        // overall < 10 (real evidence resolution) -> strict exit 3, per the command's
        // documented contract. A 0 here would mean the over-claim 10/10 had crept back.
        $this->assertSame(3, $code);

        // Without --strict the command still succeeds (exit 0) — the scorecard builds
        // fine; strict is the only thing that gates on the honest number.
        $lenient = new BufferedOutput;
        $this->assertSame(0, Artisan::call('atlas:cognition:scorecard', [], $lenient));
    }
}
