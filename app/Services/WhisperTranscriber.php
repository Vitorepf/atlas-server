<?php

namespace App\Services;

use App\Services\Ai\Transcription\TranscriptQualityGate;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

class WhisperTranscriber
{
    public function transcribe(string $audioPath, ?string $languageOverride = null): string
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

        try {
            $this->normalizeAudio($ffmpegPath, $audioPath, $normalizedAudioPath);

            // Quality-gated transcription: run, assess, and if the decoder degenerated (loops /
            // dropped audio) re-transcribe with a harder profile. NEVER return a poisoned transcript
            // silently — fail closed so the caller marks the asset failed instead of storing garbage.
            $durationSeconds = $this->estimateDurationSeconds($normalizedAudioPath);
            $gate = new TranscriptQualityGate;
            $profiles = ['normal', 'aggressive'];
            $lastQuality = null;

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

                $quality = $gate->assess($text, $durationSeconds);
                $lastQuality = $quality;
                if ($quality['passed']) {
                    return $text;
                }
                // not passed → loop to the harder profile (or fall through to fail-closed)
            }

            throw new RuntimeException(
                'Transcrição reprovou no gate de qualidade após retry — não foi salva pra não envenenar as métricas. Problemas: '
                .implode(' | ', (array) ($lastQuality['issues'] ?? ['desconhecido']))
            );
        } finally {
            $this->removeWorkDir($workDir);
        }
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
            '-otxt', '-of', $outputBase,
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
