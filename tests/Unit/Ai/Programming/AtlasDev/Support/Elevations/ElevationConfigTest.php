<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Support\Elevations;

use App\Services\Ai\Programming\AtlasDev\Gate\VerificationGateResult;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CompletionSummary;
use App\Services\Ai\Programming\AtlasDev\Schemas\ReviewReceipt;
use App\Services\Ai\Programming\AtlasDev\Support\Elevations\ElevationConfig;
use App\Services\Ai\Programming\AtlasDev\Support\Elevations\ElevationMode;
use PHPUnit\Framework\TestCase;

/**
 * Covers VAL-M0-009: the atlas_dev.elevations.<eN>.mode config-flag tri-state
 * (off|advisory|hard) is honored with a documented safe default. The helper
 * resolves a given elevation's mode and routes to the correct channel:
 *   - off      => no surfacing (the elevation is a no-op, byte-identical).
 *   - advisory => honesty flag only (downgrade PASSED->needs_review; never
 *                 STATUS_FAILED for the flag alone).
 *   - hard     => block (STATUS_FAILED gate or STATUS_ESCALATE critic).
 *
 * Unknown/missing values resolve to the safe default (advisory for landed
 * code) without crashing. All assertions are model-irrelevant and structural.
 */
final class ElevationConfigTest extends TestCase
{
    /**
     * The complete set of elevations governed by the convention. Adding a new
     * elevation here is intentional and propagates the contract forward.
     */
    private const ALL_ELEVATIONS = ['e1', 'e2', 'e3', 'e4', 'e5', 'e6'];

    // -- VAL-M0-009: tri-state recognition -----------------------------------

    public function test_off_mode_is_recognized_and_routes_to_no_surfacing(): void
    {
        foreach (self::ALL_ELEVATIONS as $elevation) {
            $config = ElevationConfig::for($elevation, ['mode' => 'off']);

            $this->assertSame(ElevationMode::OFF, $config->mode(), "[$elevation] mode must be OFF");
            $this->assertTrue($config->isOff(), "[$elevation] isOff()");
            $this->assertFalse($config->isAdvisory(), "[$elevation] isAdvisory()");
            $this->assertFalse($config->isHard(), "[$elevation] isHard()");

            // off => the elevation MUST NOT surface through either channel.
            $this->assertFalse(
                $config->shouldAppendHonestyFlag(),
                "[$elevation] off must not append an honesty flag"
            );
            $this->assertFalse(
                $config->shouldBlock(),
                "[$elevation] off must not block"
            );
        }
    }

    public function test_advisory_mode_is_recognized_and_routes_to_honesty_flag_only(): void
    {
        foreach (self::ALL_ELEVATIONS as $elevation) {
            $config = ElevationConfig::for($elevation, ['mode' => 'advisory']);

            $this->assertSame(ElevationMode::ADVISORY, $config->mode(), "[$elevation] mode must be ADVISORY");
            $this->assertFalse($config->isOff(), "[$elevation] isOff()");
            $this->assertTrue($config->isAdvisory(), "[$elevation] isAdvisory()");
            $this->assertFalse($config->isHard(), "[$elevation] isHard()");

            // advisory => honesty flag channel ONLY (never STATUS_FAILED for the flag alone).
            $this->assertTrue(
                $config->shouldAppendHonestyFlag(),
                "[$elevation] advisory must route to an honesty flag"
            );
            $this->assertFalse(
                $config->shouldBlock(),
                "[$elevation] advisory must NOT block (STATUS_FAILED/ESCALATE) for the flag alone"
            );

            // The advisory channel is the honesty-flag -> CompletionStateGate downgrade.
            // It never produces STATUS_FAILED (gate) or STATUS_ESCALATE (critic) for the flag alone.
            $this->assertNotSame(
                VerificationGateResult::STATUS_FAILED,
                $config->advisoryGateStatus(),
                "[$elevation] advisory must never report STATUS_FAILED for the flag alone"
            );
        }
    }

    public function test_hard_mode_is_recognized_and_routes_to_block(): void
    {
        foreach (self::ALL_ELEVATIONS as $elevation) {
            $config = ElevationConfig::for($elevation, ['mode' => 'hard']);

            $this->assertSame(ElevationMode::HARD, $config->mode(), "[$elevation] mode must be HARD");
            $this->assertFalse($config->isOff(), "[$elevation] isOff()");
            $this->assertFalse($config->isAdvisory(), "[$elevation] isAdvisory()");
            $this->assertTrue($config->isHard(), "[$elevation] isHard()");

            // hard => block via a sanctioned channel (STATUS_FAILED gate OR
            // STATUS_ESCALATE critic). The helper exposes the gate channel;
            // the critic channel is a documented alternative.
            $this->assertFalse(
                $config->shouldAppendHonestyFlag(),
                "[$elevation] hard must not settle for an honesty flag"
            );
            $this->assertTrue(
                $config->shouldBlock(),
                "[$elevation] hard must block"
            );
        }
    }

    public function test_hard_mode_block_targets_a_sanctioned_channel_only(): void
    {
        $config = ElevationConfig::for('e1', ['mode' => 'hard']);

        // The block channel is one of the two sanctioned mechanisms.
        $gateStatus = $config->hardGateStatus();
        $criticStatus = $config->hardCriticStatus();

        $this->assertTrue(
            $gateStatus === VerificationGateResult::STATUS_FAILED,
            'hard gate channel must be STATUS_FAILED'
        );
        $this->assertTrue(
            $criticStatus === ReviewReceipt::STATUS_ESCALATE,
            'hard critic channel must be STATUS_ESCALATE'
        );
    }

    // -- VAL-M0-009: unknown/missing resolves to the safe default ------------

    public function test_unknown_mode_resolves_to_the_safe_default_advisory_without_crashing(): void
    {
        foreach (['', 'true', '1', 'disable', 'warn', 'strict', 'ADVISORY', 'Off', null] as $badValue) {
            $config = ElevationConfig::for('e1', ['mode' => $badValue]);

            $this->assertSame(
                ElevationMode::ADVISORY,
                $config->mode(),
                'Unexpected mode for invalid value: '.var_export($badValue, true)
            );
            $this->assertTrue($config->isAdvisory(), 'invalid value must fall back to advisory');
            $this->assertFalse($config->shouldBlock(), 'invalid value must not block');
        }
    }

    public function test_missing_mode_key_resolves_to_the_safe_default_advisory(): void
    {
        $config = ElevationConfig::for('e1', []);

        $this->assertSame(ElevationMode::ADVISORY, $config->mode());
        $this->assertTrue($config->isAdvisory());
    }

    public function test_missing_whole_block_resolves_to_the_safe_default_advisory(): void
    {
        $config = ElevationConfig::for('e1', null);

        $this->assertSame(ElevationMode::ADVISORY, $config->mode());
        $this->assertTrue($config->isAdvisory());
    }

    public function test_non_string_mode_resolves_to_the_safe_default_advisory(): void
    {
        foreach ([0, 1, 1.5, true, false, ['advisory']] as $badValue) {
            $config = ElevationConfig::for('e1', ['mode' => $badValue]);

            $this->assertSame(
                ElevationMode::ADVISORY,
                $config->mode(),
                'Non-string mode must resolve to advisory, got: '.var_export($badValue, true)
            );
        }
    }

    // -- VAL-M0-009: advisory never STATUS_FAILED for the flag alone ---------

    public function test_advisory_channel_surfaces_only_via_honesty_flag_downgrade(): void
    {
        // The advisory channel's effect is: PASSED -> needs_review via the
        // CompletionStateGate honesty-flag downgrade (passed forbids flags).
        // It is never a STATUS_FAILED/STATUS_BLOCKED for the flag alone.
        $config = ElevationConfig::for('e3', ['mode' => 'advisory']);

        $this->assertContains(
            $config->advisoryGateStatus(),
            [VerificationGateResult::STATUS_PASSED, VerificationGateResult::STATUS_NEEDS_REVIEW],
            'advisory leaves the gate at passed/needs_review; the flag downgrades passed->needs_review'
        );

        // The honesty flag the elevation appends forces the CompletionStateGate
        // to downgrade a passed gate to needs_review (never failed for the flag).
        $this->assertTrue($config->shouldAppendHonestyFlag());
        $this->assertFalse($config->shouldBlock());

        // The CompletionDecision invariant (passed forbids flags) guarantees
        // the advisory flag can never coexist with a green completion.
        $this->assertTrue(
            in_array(CompletionSummary::STATUS_PASSED, CompletionSummary::ALLOWED_STATUSES, true),
            'sanity: passed is a valid completion status'
        );
    }

    // -- VAL-M0-009: each elevation is independently addressable -------------

    public function test_each_elevation_e1_through_e6_is_independently_addressable(): void
    {
        // Distinct modes per elevation do not bleed into each other.
        $modes = [
            'e1' => 'off',
            'e2' => 'advisory',
            'e3' => 'hard',
            'e4' => 'off',
            'e5' => 'advisory',
            'e6' => 'hard',
        ];

        foreach ($modes as $elevation => $mode) {
            $config = ElevationConfig::for($elevation, ['mode' => $mode]);
            $this->assertSame(
                ElevationMode::from($mode),
                $config->mode(),
                "[$elevation] expected mode $mode"
            );
        }
    }

    public function test_elevation_identifier_is_exposed_for_audit(): void
    {
        $config = ElevationConfig::for('e3', ['mode' => 'advisory']);

        $this->assertSame('e3', $config->elevation());
    }

    // -- VAL-M0-009: honesty-flag name is stable and per-elevation -----------

    public function test_advisory_honesty_flag_name_is_non_empty_and_stable(): void
    {
        $config = ElevationConfig::for('e2', ['mode' => 'advisory']);

        $flag = $config->honestyFlagName();
        $this->assertIsString($flag);
        $this->assertNotEmpty($flag, 'advisory honesty flag name must be non-empty');
        $this->assertStringContainsString('e2', $flag, 'flag name should reference the elevation');
    }

    public function test_off_and_hard_do_not_produce_an_advisory_honesty_flag_name(): void
    {
        $off = ElevationConfig::for('e2', ['mode' => 'off']);
        $hard = ElevationConfig::for('e2', ['mode' => 'hard']);

        $this->assertNull($off->honestyFlagName(), 'off must not produce a flag name');
        $this->assertNull($hard->honestyFlagName(), 'hard settles via block, not the advisory flag');
    }

    // -- VAL-M0-009: advisory->hard promotion mapping is consistent ----------

    public function test_advisory_to_hard_promotion_keeps_detection_consistent_no_green(): void
    {
        // The same tripping diff maps consistently across advisory/hard, and
        // neither mode ever yields green (advisory => flag+needs_review; hard
        // => block). This is the per-elevation seed of VAL-CROSS-013.
        $advisory = ElevationConfig::for('e6', ['mode' => 'advisory']);
        $hard = ElevationConfig::for('e6', ['mode' => 'hard']);

        // Neither mode is a silent pass.
        $this->assertFalse($advisory->isOff());
        $this->assertFalse($hard->isOff());

        // advisory: flag only, never block.
        $this->assertTrue($advisory->shouldAppendHonestyFlag());
        $this->assertFalse($advisory->shouldBlock());

        // hard: block, never settles for the flag.
        $this->assertFalse($hard->shouldAppendHonestyFlag());
        $this->assertTrue($hard->shouldBlock());
    }
}
