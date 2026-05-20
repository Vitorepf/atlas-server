<?php

namespace App\Services\Ai\Voice;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class AtlasVoiceTtsService
{
    /**
     * @param array{text:string,voice_id?:string|null,response_text_hash?:string|null} $input
     * @return array<string,mixed>
     */
    public function synthesize(array $input): array
    {
        $text = trim((string) $input['text']);
        $apiKey = trim((string) config('atlas.voice.elevenlabs.api_key', ''));
        $voiceId = trim((string) ($input['voice_id'] ?? config('atlas.voice.elevenlabs.voice_id', '')));
        $modelId = trim((string) config('atlas.voice.elevenlabs.model_id', 'eleven_multilingual_v2'));
        $outputFormat = trim((string) config('atlas.voice.elevenlabs.output_format', 'mp3_44100_128'));
        $timeoutSeconds = max(3, (int) config('atlas.voice.elevenlabs.timeout_seconds', 20));

        if ($apiKey === '' || $voiceId === '') {
            throw new RuntimeException('elevenlabs_not_configured');
        }

        $startedAt = microtime(true);

        try {
            $response = Http::timeout($timeoutSeconds)
                ->withHeaders([
                    'Accept' => 'audio/mpeg',
                    'xi-api-key' => $apiKey,
                ])
                ->asJson()
                ->post("https://api.elevenlabs.io/v1/text-to-speech/{$voiceId}", [
                    'text' => $text,
                    'model_id' => $modelId,
                    'output_format' => $outputFormat,
                    'voice_settings' => [
                        'stability' => 0.56,
                        'similarity_boost' => 0.82,
                        'style' => 0.18,
                        'use_speaker_boost' => true,
                    ],
                ]);
        } catch (Throwable $error) {
            throw new RuntimeException('elevenlabs_request_failed', 0, $error);
        }

        if (! $response->successful()) {
            throw new RuntimeException('elevenlabs_response_'.$response->status());
        }

        $audio = $response->body();
        if ($audio === '') {
            throw new RuntimeException('elevenlabs_empty_audio');
        }

        $audioHash = hash('sha256', $audio);

        return [
            'schema_version' => 'atlas.voice_realtime.tts_synthesis.v1',
            'status' => 'synthesized',
            'provider' => 'elevenlabs',
            'voice_id' => $voiceId,
            'model_id' => $modelId,
            'mime_type' => 'audio/mpeg',
            'audio_base64' => base64_encode($audio),
            'audio_hash' => $audioHash,
            'byte_length' => strlen($audio),
            'latency_ms' => max(0, (int) round((microtime(true) - $startedAt) * 1000)),
            'response_text_hash' => $input['response_text_hash'] ?? null,
        ];
    }
}
