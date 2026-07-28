<?php

namespace App\Services;

use App\Services\Ai\Transcription\TranscriptQualityGate;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

class WhisperTranscriber
{
    /**
     * Back-compat string API — returns just the transcript text.
     */
    public function transcribe(string $audioPath, ?string $languageOverride = null): string
    {
        return (string) $this->transcribeDetailed($audioPath, $languageOverride)['text'];
    }

    /**
     * Full-fidelity transcription: returns the text PLUS the decoder's own audited fidelity —
     * real per-segment timestamps, a 0-100 decoder confidence, real time-coverage of the audio, the
     * largest silence gap, and the exact low-confidence spans. Fail-closed on text loops/hallucination
     * AND on acoustic unreliability (low confidence / dropped coverage), retrying a harder profile
     * before refusing. This is the honest fidelity contract: never a silent error.
     *
     * @return array<string,mixed>  text, segments[], confidence, coverage_pct, max_gap_seconds,
     *                              low_confidence_segments[], duration_seconds, profile, quality, acoustic
     */
    public function transcribeDetailed(string $audioPath, ?string $languageOverride = null): array
    {
        $binPath = (string) config('atlas.transcription.bin_path');
        $modelPath = (string) config('atlas.transcription.model_path');
        $ffmpegPath = (string) config('atlas.transcription.ffmpeg_path', '/usr/bin/ffmpeg');
        $language = trim((string) ($languageOverride ?? config('atlas.transcription.language', 'pt')));
        $workDir = storage_path('framework/transcriptions/'.uniqid('atlas_', true));
        $normalizedAudioPath = $workDir.'/audio.wav';
        $outputBase = $workDir.'/transcript';

        if (! is_file($binPath) || ! is_executable($binPath)) {
            throw new RuntimeException('Whisper binary is not executable: '.$binPath);
        }

        if (! is_file($modelPath)) {
            throw new RuntimeException('Whisper model does not exist: '.$modelPath);
        }

        if (! is_file($audioPath)) {
            throw new RuntimeException('Audio file does not exist: '.$audioPath);
        }

        if (! is_file($ffmpegPath) || ! is_executable($ffmpegPath)) {
            throw new RuntimeException('FFmpeg binary is not executable: '.$ffmpegPath);
        }

        if (! is_dir($workDir)) {
            mkdir($workDir, 0775, true);
        }

        // The -ojf JSON for a long VSL decodes into a large PHP structure (every token is an object).
        // Give the parse headroom so a 2h transcript never dies with an OOM mid-pipeline.
        $this->ensureMemoryHeadroom();

        try {
            $this->normalizeAudio($ffmpegPath, $audioPath, $normalizedAudioPath);

            // Quality-gated transcription: run, assess (text loops + acoustic confidence/coverage), and
            // if the decoder degenerated re-transcribe with a harder profile. NEVER return a poisoned
            // transcript silently — fail closed so the caller marks the asset failed instead of storing garbage.
            $durationSeconds = $this->estimateDurationSeconds($normalizedAudioPath);
            $gate = new TranscriptQualityGate;
            $profiles = ['normal', 'aggressive'];
            $lastQuality = null;
            $lastAcoustic = null;
            $best = null;

            foreach ($profiles as $profile) {
                $args = $this->buildArgs($binPath, $modelPath, $normalizedAudioPath, $outputBase, $language, $profile);

                $process = new Process($args);
                $timeout = max(600, (int) config('atlas.transcription.timeout_seconds', 1800));
                $process->setTimeout($timeout);
                try {
                    $process->run();
                } catch (ProcessTimedOutException) {
                    throw new RuntimeException("Whisper transcription timed out after {$timeout}s.");
                }

                if (! $process->isSuccessful()) {
                    throw new RuntimeException($this->processError($process, 'Whisper transcription failed.'));
                }

                $textPath = $outputBase.'.txt';
                $text = is_file($textPath) ? trim((string) file_get_contents($textPath)) : '';
                $segments = $this->parseWhisperJson($outputBase.'.json');

                $quality = $gate->assess($text, $durationSeconds);
                $acoustic = $gate->assessAcoustic($segments, $durationSeconds);
                $lastQuality = $quality;
                $lastAcoustic = $acoustic;

                $payload = [
                    'text' => $text,
                    'segments' => $segments,
                    'confidence' => $acoustic['confidence'],
                    'coverage_pct' => $acoustic['coverage_pct'],
                    'max_gap_seconds' => $acoustic['max_gap_seconds'],
                    'low_confidence_segments' => $acoustic['low_confidence_segments'],
                    'duration_seconds' => $durationSeconds,
                    'profile' => $profile,
                    'quality' => $quality,
                    'acoustic' => $acoustic,
                ];
                // keep the best attempt (highest confidence) in case both fail → richer error
                if ($best === null || (($acoustic['confidence'] ?? -1) > ($best['confidence'] ?? -1))) {
                    $best = $payload;
                }

                if ($quality['passed'] && $acoustic['passed']) {
                    return $payload;
                }
                // not passed → loop to the harder profile (or fall through to fail-closed)
            }

            $issues = array_merge(
                (array) ($lastQuality['issues'] ?? []),
                (array) ($lastAcoustic['issues'] ?? []),
            );
            throw new RuntimeException(
                'Transcrição reprovou no gate de qualidade após retry — não foi salva pra não envenenar as métricas. Problemas: '
                .implode(' | ', $issues ?: ['desconhecido'])
            );
        } finally {
            $this->removeWorkDir($workDir);
        }
    }

    /**
     * Parse the whisper-cli -ojf JSON into segments with real timestamps and a mean token confidence.
     * Special/non-text tokens ("[_BEG_]", timestamp tokens) are excluded from the confidence average.
     *
     * @return array<int,array{from_ms:int,to_ms:int,text:string,confidence:float|null}>
     */
    private function parseWhisperJson(string $jsonPath): array
    {
        if (! is_file($jsonPath)) {
            return [];
        }
        // Guard against a pathologically large JSON (a multi-hour VSL) rather than risk an OOM.
        // 96MB of -ojf JSON is well beyond a 2h talk.
        //
        // O comentario anterior dizia "degrade gracefully to estimated segments". NAO e o que
        // acontece: devolve-se lista VAZIA, e lista vazia faz `assessAcoustic` responder
        // `verdict: unknown, passed: true`. Ou seja, a gravacao mais longa — a que tem mais
        // chance de decodificar mal — e exatamente a que passa sem checagem acustica. O
        // comportamento continua (derrubar por OOM seria pior), mas agora esta escrito o que
        // ele e, e o gate marca `acoustic_check: skipped` para o pulo ser contavel.
        if (filesize($jsonPath) > 96 * 1024 * 1024) {
            return [];
        }
        $raw = (string) file_get_contents($jsonPath);
        $data = json_decode($raw, true);
        unset($raw);
        if (! is_array($data)) {
            return [];
        }
        $rows = is_array($data['transcription'] ?? null) ? $data['transcription'] : [];
        if ($rows === []) {
            return [];
        }

        $segments = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $fromMs = (int) ($row['offsets']['from'] ?? 0);
            $toMs = (int) ($row['offsets']['to'] ?? $fromMs);
            $text = trim((string) ($row['text'] ?? ''));

            $confidence = null;
            if (is_array($row['tokens'] ?? null)) {
                $ps = [];
                foreach ($row['tokens'] as $tok) {
                    if (! is_array($tok)) {
                        continue;
                    }
                    $tt = trim((string) ($tok['text'] ?? ''));
                    if ($tt === '' || str_starts_with($tt, '[_') || (str_starts_with($tt, '<|') && str_ends_with($tt, '|>'))) {
                        continue; // special / timestamp token
                    }
                    if (isset($tok['p']) && is_numeric($tok['p'])) {
                        $ps[] = (float) $tok['p'];
                    }
                }
                if ($ps !== []) {
                    $confidence = array_sum($ps) / count($ps);
                }
            }

            $segments[] = [
                'from_ms' => $fromMs,
                'to_ms' => $toMs,
                'text' => $text,
                'confidence' => $confidence,
            ];
        }

        return $segments;
    }

    /**
     * Build whisper-cli args. The 'aggressive' profile lowers the entropy threshold (triggers the
     * temperature fallback sooner, which breaks the decoder out of loops) on top of the standard
     * anti-hallucination guards (-mc 0 carry-over cut, -sns non-speech suppression).
     *
     * @return array<int,string>
     */
    private function buildArgs(string $binPath, string $modelPath, string $audioPath, string $outputBase, string $language, string $profile): array
    {
        $entropy = $profile === 'aggressive' ? '2.2' : (string) config('atlas.transcription.entropy_thold', '2.4');

        $args = [
            $binPath,
            '-m', $modelPath,
            '-f', $audioPath,
            '-otxt', '-ojf', '-of', $outputBase,   // -ojf = JSON with per-token probabilities + real timestamps
            '-nt',
            '-mc', (string) config('atlas.transcription.max_context', 0),
            '-et', $entropy,
            '-nth', (string) config('atlas.transcription.no_speech_thold', '0.6'),
        ];
        if ((bool) config('atlas.transcription.suppress_non_speech', true)) {
            $args[] = '-sns';
        }
        if ($profile === 'aggressive') {
            // greedy-ish decode + bigger temperature steps to escape any residual loop
            array_push($args, '-tp', '0.0', '-tpi', '0.4');
        }
        if ($language !== '') {
            array_push($args, '-l', $language);
        }

        return $args;
    }

    /**
     * Estimate audio length from the normalized 16kHz mono pcm_s16le WAV (32000 bytes/sec).
     */
    private function estimateDurationSeconds(string $wavPath): ?int
    {
        if (! is_file($wavPath)) {
            return null;
        }
        $bytes = (int) filesize($wavPath);
        if ($bytes <= 44) { // WAV header only
            return null;
        }

        return (int) round(($bytes - 44) / 32000);
    }

    private function normalizeAudio(string $ffmpegPath, string $inputPath, string $outputPath): void
    {
        $process = new Process([
            $ffmpegPath,
            '-nostdin',
            '-hide_banner',
            '-loglevel',
            'error',
            '-y',
            '-i',
            $inputPath,
            '-vn',
            '-ac',
            '1',
            '-ar',
            '16000',
            '-c:a',
            'pcm_s16le',
            $outputPath,
        ]);
        $timeout = max(120, (int) config('atlas.transcription.normalize_timeout_seconds', 300));
        $process->setTimeout($timeout);
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new RuntimeException("Audio normalization timed out after {$timeout}s.");
        }

        if (! $process->isSuccessful()) {
            throw new RuntimeException($this->processError($process, 'Audio normalization failed.'));
        }

        if (! is_file($outputPath) || filesize($outputPath) === 0) {
            throw new RuntimeException('Audio normalization did not produce a readable WAV file.');
        }
    }

    private function processError(Process $process, string $fallback): string
    {
        $error = trim($process->getErrorOutput());
        $output = trim($process->getOutput());

        return $error !== '' ? $error : ($output !== '' ? $output : $fallback);
    }

    /**
     * Raise the memory limit (if currently lower) so decoding a large -ojf JSON never OOMs the
     * pipeline. Transcription is a heavy, single-purpose operation; ~1.5GB headroom is safe.
     */
    private function ensureMemoryHeadroom(int $floorBytes = 1610612736): void // 1536 MB
    {
        $current = trim((string) ini_get('memory_limit'));
        if ($current === '' || $current === '-1') {
            return; // already unlimited
        }
        $bytes = $this->parseBytes($current);
        if ($bytes > 0 && $bytes < $floorBytes) {
            @ini_set('memory_limit', (string) $floorBytes);
        }
    }

    private function parseBytes(string $value): int
    {
        $value = trim($value);
        $unit = strtolower((string) substr($value, -1));
        $num = (int) $value;

        return match ($unit) {
            'g' => $num * 1024 * 1024 * 1024,
            'm' => $num * 1024 * 1024,
            'k' => $num * 1024,
            default => (int) $value,
        };
    }

    private function removeWorkDir(string $workDir): void
    {
        if (! is_dir($workDir)) {
            return;
        }

        foreach (glob($workDir.'/*') ?: [] as $path) {
            try {
                is_file($path) && @unlink($path);
            } catch (Throwable) {
                //
            }
        }

        @rmdir($workDir);
    }
}
