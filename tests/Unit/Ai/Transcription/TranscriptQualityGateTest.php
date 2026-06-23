<?php

namespace Tests\Unit\Ai\Transcription;

use App\Services\Ai\Transcription\TranscriptQualityGate;
use PHPUnit\Framework\TestCase;

class TranscriptQualityGateTest extends TestCase
{
    private TranscriptQualityGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gate = new TranscriptQualityGate;
    }

    /** The exact failure that slipped through on OT169 must now be caught. */
    public function test_catches_the_mix_loop_hallucination(): void
    {
        $clean = str_repeat('the protocol works by activating three hormones every single day without effort. ', 8);
        $loop = $clean.str_repeat('mix ', 60).$clean;

        $r = $this->gate->assess($loop);

        $this->assertSame('retry', $r['verdict']);
        $this->assertFalse($r['passed']);
        $this->assertNotEmpty($r['issues']);
        $this->assertStringContainsString('mix', strtolower(implode(' ', $r['issues'])));
    }

    public function test_catches_repeated_phrase_loop(): void
    {
        $base = str_repeat('she felt ashamed and invisible after years of failed diets and broken promises. ', 6);
        $phraseLoop = $base.str_repeat('taking care again ', 10);

        $r = $this->gate->assess($phraseLoop);
        $this->assertFalse($r['passed']);
    }

    public function test_clean_transcript_passes(): void
    {
        $clean = 'What if the real reason you cannot lose weight is a hormonal block nobody told you about. '
            .'A physician discovered that four natural compounds, taken before bed, activate the same pathway as the '
            .'expensive injection. Thousands of women followed the simple ritual at home and saw their belly shrink '
            .'within weeks, eating what they wanted, without the gym, without strict diets, and without side effects.';

        $r = $this->gate->assess($clean);
        $this->assertTrue($r['passed']);
        $this->assertSame('pass', $r['verdict']);
    }

    public function test_flags_dropped_audio_via_low_coverage(): void
    {
        // 200 words for a 78-minute video → ~2.6 wpm → way below coverage floor.
        $words = trim(str_repeat('alpha bravo charlie delta echo foxtrot golf hotel india juliet ', 20));
        $r = $this->gate->assess($words, 78 * 60);

        $this->assertFalse($r['passed']);
        $this->assertSame('retry', $r['verdict']);
        $this->assertStringContainsString('cobertura', strtolower(implode(' ', $r['issues'])));
    }

    public function test_too_short_fails_hard(): void
    {
        $r = $this->gate->assess('hello world');
        $this->assertSame('fail', $r['verdict']);
    }
}
