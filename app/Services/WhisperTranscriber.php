<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class WhisperTranscriber
{
    public function transcribe(string $audioPath): string
    {
        $binPath = (string) config('atlas.transcription.bin_path');
        $modelPath = (string) config('atlas.transcription.model_path');
        $ffmpegPath = (string) config('atlas.transcription.ffmpeg_path', '/usr/bin/ffmpeg');
        $language = (string) config('atlas.transcription.language', 'pt');
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

            $process = new Process([
                $binPath,
                '-m',
                $modelPath,
                '-f',
                $normalizedAudioPath,
                '-l',
                $language,
                '-otxt',
                '-of',
                $outputBase,
                '-nt',
            ]);
            $process->setTimeout(600);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new RuntimeException($this->processError($process, 'Whisper transcription failed.'));
            }

            $textPath = $outputBase.'.txt';

            if (! is_file($textPath)) {
                return '';
            }

            return trim((string) file_get_contents($textPath));
        } finally {
            $this->removeWorkDir($workDir);
        }
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
        $process->setTimeout(120);
        $process->run();

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
