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
printf "esta gravacao de teste existe para provar que o audio foi normalizado antes de chamar o decodificador e nao para avaliar conteudo algum. o transcritor recebe um arquivo em outro formato converte para wav monofonico e so entao invoca o binario responsavel pela decodificacao. qualquer texto suficientemente longo serve aqui desde que atravesse o piso de palavras exigido pelo portao de qualidade textual.\n" > "$out.txt"
SH);

        chmod($ffmpeg, 0755);
        chmod($whisper, 0755);

        config()->set('atlas.transcription.ffmpeg_path', $ffmpeg);
        config()->set('atlas.transcription.bin_path', $whisper);
        config()->set('atlas.transcription.model_path', $model);
        config()->set('atlas.transcription.language', 'pt');

        // O assunto deste teste e a NORMALIZACAO — provada pelo proprio stub, que sai
        // com codigo 4 se o arquivo entregue nao for `audio.wav`. O conteudo do texto e
        // incidental, mas precisa atravessar `TranscriptQualityGate::assess`, que exige
        // 50 palavras. A fixture antiga tinha DUAS e ficou vermelha quando o portao
        // entrou, cobrando deste teste uma coisa que nunca foi o assunto dele.
        $saida = app(WhisperTranscriber::class)->transcribe($input);

        $this->assertStringStartsWith('esta gravacao de teste existe', $saida);
        $this->assertGreaterThanOrEqual(50, str_word_count($saida), 'a fixture tem de atravessar o piso do gate de texto');
    }
}
