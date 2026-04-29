<?php

namespace Tests\Unit;

use App\Services\WhisperTranscriber;
use Tests\TestCase;

class WhisperTranscriberTest extends TestCase
{
    public function test_it_normalizes_audio_before_calling_whisper(): void
    {
        $workDir = storage_path('framework/testing/whisper-test-'.uniqid());
        mkdir($workDir, 0775, true);

        $ffmpeg = $workDir.'/ffmpeg';
        $whisper = $workDir.'/whisper-cli';
        $model = $workDir.'/ggml-base.bin';
        $input = $workDir.'/input.m4a';

        file_put_contents($model, 'model');
        file_put_contents($input, 'audio');
        file_put_contents($ffmpeg, <<<'SH'
#!/bin/sh
out=""
for arg in "$@"; do
  out="$arg"
done
printf "wav" > "$out"
SH);
        file_put_contents($whisper, <<<'SH'
#!/bin/sh
out=""
prev=""
input=""
for arg in "$@"; do
  if [ "$prev" = "-of" ]; then
    out="$arg"
  fi
  if [ "$prev" = "-f" ]; then
    input="$arg"
  fi
  prev="$arg"
done
test "$(basename "$input")" = "audio.wav" || exit 4
printf "texto transcrito\n" > "$out.txt"
SH);

        chmod($ffmpeg, 0755);
        chmod($whisper, 0755);

        config()->set('atlas.transcription.ffmpeg_path', $ffmpeg);
        config()->set('atlas.transcription.bin_path', $whisper);
        config()->set('atlas.transcription.model_path', $model);
        config()->set('atlas.transcription.language', 'pt');

        $this->assertSame('texto transcrito', app(WhisperTranscriber::class)->transcribe($input));
    }
}
