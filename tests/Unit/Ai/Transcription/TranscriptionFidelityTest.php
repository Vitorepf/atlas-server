<?php

namespace Tests\Unit\Ai\Transcription;

use App\Services\Ai\Transcription\TranscriptQualityGate;
use App\Services\WhisperTranscriber;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Locks the AUDITABLE fidelity contract: the decoder's own token probabilities become a 0-100
 * confidence, real timestamps become time-coverage, low-confidence spans are surfaced (not hidden),
 * and the gate fails closed below the floors. Plus the whisper -ojf JSON parser. Deterministic; no
 * binary, no DB.
 */
class TranscriptionFidelityTest extends TestCase
{
    public function test_high_confidence_full_coverage_passes(): void
    {
        $segments = [
            ['from_ms' => 0, 'to_ms' => 5000, 'text' => 'opening hook', 'confidence' => 0.95],
            ['from_ms' => 5000, 'to_ms' => 10000, 'text' => 'the mechanism', 'confidence' => 0.90],
        ];
        $a = (new TranscriptQualityGate)->assessAcoustic($segments, 10);

        $this->assertTrue($a['passed']);
        $this->assertSame('pass', $a['verdict']);
        $this->assertGreaterThanOrEqual(90, $a['confidence']);
        $this->assertSame(100.0, $a['coverage_pct']);
        $this->assertSame([], $a['low_confidence_segments']);
    }

    public function test_low_decoder_confidence_fails_closed_and_surfaces_spans(): void
    {
        $segments = [
            ['from_ms' => 0, 'to_ms' => 6000, 'text' => 'muffled audio here', 'confidence' => 0.30],
            ['from_ms' => 6000, 'to_ms' => 10000, 'text' => 'still unclear', 'confidence' => 0.25],
        ];
        $a = (new TranscriptQualityGate)->assessAcoustic($segments, 10);

        $this->assertFalse($a['passed']);            // fail-closed → will retry harder
        $this->assertSame('retry', $a['verdict']);
        $this->assertLessThan(TranscriptQualityGate::MIN_DECODER_CONFIDENCE, $a['confidence']);
        $this->assertNotEmpty($a['low_confidence_segments']); // the uncertain spans are reported, not hidden
        $this->assertSame(0, $a['low_confidence_segments'][0]['from_s']);
    }

    public function test_dropped_audio_coverage_fails_closed(): void
    {
        // 12 seconds of speech across a 120s audio = only 10% covered → dropped audio
        $segments = [
            ['from_ms' => 0, 'to_ms' => 6000, 'text' => 'hello', 'confidence' => 0.9],
            ['from_ms' => 6000, 'to_ms' => 12000, 'text' => 'world', 'confidence' => 0.9],
        ];
        $a = (new TranscriptQualityGate)->assessAcoustic($segments, 120);

        $this->assertFalse($a['passed']);
        $this->assertLessThan(TranscriptQualityGate::MIN_TIME_COVERAGE * 100, $a['coverage_pct']);
        $this->assertGreaterThan(TranscriptQualityGate::MAX_SILENCE_GAP_SECONDS, $a['max_gap_seconds']);
    }

    public function test_no_segments_is_unknown_not_a_false_fail(): void
    {
        $a = (new TranscriptQualityGate)->assessAcoustic([], 600);

        $this->assertSame('unknown', $a['verdict']);
        $this->assertTrue($a['passed']);           // absence of JSON must not block a good text transcript
        $this->assertNull($a['confidence']);
    }

    public function test_parses_whisper_ojf_json_with_real_timestamps_and_confidence(): void
    {
        $json = json_encode([
            'transcription' => [
                [
                    'offsets' => ['from' => 0, 'to' => 5000],
                    'text' => ' Hello world',
                    'tokens' => [
                        ['text' => '[_BEG_]', 'p' => 0.9],     // special token → excluded
                        ['text' => ' Hello', 'p' => 0.95],
                        ['text' => ' world', 'p' => 0.85],
                    ],
                ],
                [
                    'offsets' => ['from' => 5000, 'to' => 9000],
                    'text' => ' low part',
                    'tokens' => [
                        ['text' => ' low', 'p' => 0.30],
                        ['text' => ' part', 'p' => 0.20],
                    ],
                ],
            ],
        ]);
        $path = sys_get_temp_dir().'/atlas_whisper_test_'.uniqid().'.json';
        file_put_contents($path, $json);

        try {
            $ref = new ReflectionClass(WhisperTranscriber::class);
            $m = $ref->getMethod('parseWhisperJson');
            $m->setAccessible(true);
            $segments = $m->invoke($ref->newInstanceWithoutConstructor(), $path);
        } finally {
            @unlink($path);
        }

        $this->assertCount(2, $segments);
        $this->assertSame(0, $segments[0]['from_ms']);
        $this->assertSame(5000, $segments[0]['to_ms']);
        $this->assertSame('Hello world', $segments[0]['text']);
        // confidence excludes the [_BEG_] special token: mean(0.95, 0.85) = 0.90
        $this->assertEqualsWithDelta(0.90, $segments[0]['confidence'], 0.001);
        $this->assertEqualsWithDelta(0.25, $segments[1]['confidence'], 0.001);
    }

    public function test_parse_returns_empty_when_json_missing(): void
    {
        $ref = new ReflectionClass(WhisperTranscriber::class);
        $m = $ref->getMethod('parseWhisperJson');
        $m->setAccessible(true);

        $this->assertSame([], $m->invoke($ref->newInstanceWithoutConstructor(), '/no/such/file.json'));
    }
}
