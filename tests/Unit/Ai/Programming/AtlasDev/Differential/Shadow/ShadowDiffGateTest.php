<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Differential\Shadow;

use App\Services\Ai\Programming\AtlasDev\Differential\Shadow\ShadowDiffGate;
use App\Services\Ai\Programming\AtlasDev\Differential\Shadow\ShadowDiffResult;
use App\Services\Ai\Programming\AtlasDev\Support\Elevations\ElevationConfig;
use PHPUnit\Framework\TestCase;

/**
 * E4 -- ShadowDiffGate unit tests.
 *
 * VAL-E4-006 (shadow_diff_regression is a regression verdict, never silently
 * passed): advisory => flag + needs_review downgrade; hard => STATUS_FAILED.
 * VAL-E4-007 (impure => no flag), VAL-E4-008 (agreement => no flag),
 * VAL-E4-011 (newly-added skip => no flag, no exception), VAL-E4-010
 * (off-mode byte-identical no-op).
 */
final class ShadowDiffGateTest extends TestCase
{
    // -- VAL-E4-006 / VAL-E4-010: mode routing on divergence ----------------

    public function test_gate_off_mode_is_noop_even_on_divergence(): void
    {
        $gate = new ShadowDiffGate(ElevationConfig::for('e4', ['mode' => 'off']));

        $result = ShadowDiffResult::divergence([
            ['symbol' => 'calculate', 'file' => 'app/Math.php', 'input' => '[1, 2]', 'oldOutput' => '3', 'newOutput' => '2'],
        ]);

        $verdict = $gate->evaluate($result);

        $this->assertFalse($verdict->tripped, 'VAL-E4-010: off never trips');
        $this->assertFalse($verdict->shouldFailGate, 'off never fails the gate');
        $this->assertSame([], $verdict->honestyFlags, 'off raises no flag');
        $this->assertTrue($verdict->isNoOp, 'off is a no-op');
        $this->assertNotEmpty($verdict->noOpReason, 'no-op carries a reason');
    }

    public function test_gate_advisory_mode_appends_flag_on_divergence(): void
    {
        $gate = new ShadowDiffGate(ElevationConfig::for('e4', ['mode' => 'advisory']));

        $result = ShadowDiffResult::divergence([
            ['symbol' => 'calculate', 'file' => 'app/Math.php', 'input' => '[1, 2]', 'oldOutput' => '3', 'newOutput' => '2'],
        ]);

        $verdict = $gate->evaluate($result);

        $this->assertTrue($verdict->tripped, 'advisory trips on divergence');
        $this->assertFalse($verdict->shouldFailGate, 'advisory never forces STATUS_FAILED');
        $this->assertContains(
            ShadowDiffGate::FLAG_SHADOW_DIFF_REGRESSION,
            $verdict->honestyFlags,
            'advisory appends the shadow_diff_regression flag',
        );
        $this->assertNotEmpty($verdict->reason, 'tripped verdict carries a reason');
    }

    public function test_gate_hard_mode_fails_gate_on_divergence(): void
    {
        $gate = new ShadowDiffGate(ElevationConfig::for('e4', ['mode' => 'hard']));

        $result = ShadowDiffResult::divergence([
            ['symbol' => 'calculate', 'file' => 'app/Math.php', 'input' => '[1, 2]', 'oldOutput' => '3', 'newOutput' => '2'],
        ]);

        $verdict = $gate->evaluate($result);

        $this->assertTrue($verdict->tripped, 'hard trips on divergence');
        $this->assertTrue($verdict->shouldFailGate, 'hard forces STATUS_FAILED');
        $this->assertContains(
            ShadowDiffGate::FLAG_SHADOW_DIFF_REGRESSION,
            $verdict->honestyFlags,
            'hard retains the flag for auditability',
        );
    }

    // -- VAL-E4-008: agreement never trips -----------------------------------

    public function test_gate_agreement_never_trips_in_any_mode(): void
    {
        foreach (['advisory', 'hard'] as $mode) {
            $gate = new ShadowDiffGate(ElevationConfig::for('e4', ['mode' => $mode]));
            $result = ShadowDiffResult::agreement(
                [['symbol' => 'calculate', 'file' => 'app/Math.php']],
            );
            $verdict = $gate->evaluate($result);

            $this->assertFalse($verdict->tripped, "agreement never trips in {$mode} mode");
            $this->assertSame([], $verdict->honestyFlags, "agreement raises no flag in {$mode} mode");
            $this->assertTrue($verdict->isNoOp, "agreement is a no-op in {$mode} mode");
        }
    }

    // -- VAL-E4-007 / VAL-E4-011: skip never trips ---------------------------

    public function test_gate_skip_never_trips(): void
    {
        $gate = new ShadowDiffGate(ElevationConfig::for('e4', ['mode' => 'advisory']));
        $result = ShadowDiffResult::skipped('all symbols impure or newly-added');
        $verdict = $gate->evaluate($result);

        $this->assertFalse($verdict->tripped, 'skip never trips');
        $this->assertTrue($verdict->isNoOp, 'skip is a no-op');
        $this->assertSame([], $verdict->honestyFlags, 'skip raises no flag');
        $this->assertStringContainsString(
            'impure',
            $verdict->noOpReason,
            'skip reason carries the service-level reason',
        );
    }

    public function test_gate_divergence_carries_symbol_evidence(): void
    {
        $gate = new ShadowDiffGate(ElevationConfig::for('e4', ['mode' => 'advisory']));
        $divergent = [
            ['symbol' => 'calculate', 'file' => 'app/Math.php', 'input' => '[1, 2]', 'oldOutput' => '3', 'newOutput' => '2'],
        ];
        $result = ShadowDiffResult::divergence($divergent);

        $verdict = $gate->evaluate($result);

        $this->assertSame($divergent, $verdict->divergentSymbols, 'verdict echoes divergent symbols as evidence');
    }
}
