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

    /**
     * O-5 (Criação ≠ Medição): the `code` dimension is RESOLVED from real class_exists,
     * not a self-declared constant. Freeze it: the code score must exactly match the
     * count of subsystems whose service_class actually exists. If anyone re-introduces a
     * hardcoded 'ready', this drifts and the test fails.
     */
    public function test_code_dimension_is_resolved_from_real_class_exists_not_declared(): void
    {
        $report = app(AtlasCognitionScoreCardService::class)->build();
        $rows = $report['subsystems'];
        $this->assertNotEmpty($rows);

        $expectedReady = 0;
        foreach ($rows as $row) {
            $cls = (string) ($row['service_class'] ?? '');
            $codeReady = $row['code_status'] === AtlasCognitionScoreCardService::STATUS_READY;
            // The prober's verdict must match ground truth: class exists <=> code ready.
            $this->assertSame(class_exists($cls), $codeReady, "code_status for {$cls} must mirror class_exists");
            $expectedReady += $codeReady ? 1 : 0;
        }

        // The aggregated code score (out of 10) is exactly the ready ratio — pure evidence.
        $codeScore = (float) $report['score']['dimensions']['code']['score_out_of_10'];
        $this->assertEqualsWithDelta(round(10 * $expectedReady / count($rows), 2), $codeScore, 0.01);
    }
}
