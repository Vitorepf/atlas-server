<?php

namespace App\Services\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\WhisperTranscriber;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Pillar 1 — VSL ingestion: take a media file, transcribe it in full with the
 * local Whisper engine, and persist it as an offer asset. Structure extraction
 * (niche, mechanisms, avatar, offer, funnel kit) is a separate pass.
 */
class VslAssetIngestionService
{
    /** @var array<int,string> */
    private const TERMINAL_OK = ['transcribed', 'analyzing', 'structured'];

    public function __construct(
        private readonly WhisperTranscriber $whisper,
    ) {}

    /**
     * Register a VSL file as an asset (status=pending). Dedupes by content hash.
     *
     * @param  array<string,mixed>  $options  niche|campaign|language|label|force
     */
    public function ingestFile(string $path, array $options = []): AiMarketingVslAsset
    {
        $path = $this->resolvePath($path);
        if (! is_file($path)) {
            throw new RuntimeException("VSL file not found: {$path}");
        }

        $hash = (string) hash_file('sha256', $path);
        $existing = AiMarketingVslAsset::query()->where('content_hash', $hash)->first();
        if ($existing !== null
            && in_array($existing->status, self::TERMINAL_OK, true)
            && ! (bool) ($options['force'] ?? false)) {
            return $existing;
        }

        $language = trim((string) ($options['language'] ?? config('atlas.transcription.language', 'pt')));
        $filename = basename($path);

        $asset = $existing ?? new AiMarketingVslAsset;
        $asset->fill([
            'content_hash' => $hash,
            'label' => (string) ($options['label'] ?? $this->deriveLabel($filename)),
            'source_type' => 'file',
            'source_ref' => $path,
            'source_filename' => $filename,
            'campaign_ref' => $options['campaign'] ?? $asset->campaign_ref,
            'niche' => $options['niche'] ?? $asset->niche,
            'language' => $language !== '' ? $language : 'pt',
            'duration_seconds' => $this->probeDurationSeconds($path),
            'status' => 'pending',
            'reason' => null,
            'last_ingested_at' => now(),
        ]);
        $asset->save();

        return $asset->refresh();
    }

    /**
     * Transcribe the asset's media in full (blocking). Safe to call from a queued job.
     */
    public function transcribe(AiMarketingVslAsset $asset): AiMarketingVslAsset
    {
        $path = (string) $asset->source_ref;
        if (! is_file($path)) {
            return $this->markFailed($asset, "Source file no longer exists: {$path}");
        }

        $asset->forceFill(['status' => 'processing', 'reason' => null, 'last_ingested_at' => now()])->save();

        $startedAt = microtime(true);
        // 'auto' (or empty) language → let Whisper detect it (omit -l).
        $language = (string) $asset->language;
        $language = in_array(strtolower(trim($language)), ['auto', ''], true) ? '' : $language;
        try {
            $result = $this->whisper->transcribeDetailed($path, $language);
            $text = trim((string) $result['text']);
        } catch (Throwable $e) {
            return $this->markFailed($asset, Str::limit($e->getMessage(), 480, ''));
        }

        if ($text === '') {
            return $this->markFailed($asset, 'Whisper returned an empty transcript.');
        }

        // Prefer the decoder's REAL per-segment timestamps; fall back to proportional estimate only
        // when the JSON had no segments (older binary / no -ojf support).
        $realSegments = $this->mapRealSegments(is_array($result['segments'] ?? null) ? $result['segments'] : []);
        $segments = $realSegments !== [] ? $realSegments : $this->segmentTranscript($text, $asset->duration_seconds);

        $diagnostics = is_array($asset->diagnostics) ? $asset->diagnostics : [];
        $diagnostics['transcription'] = [
            'confidence' => $result['confidence'] ?? null,
            'coverage_pct' => $result['coverage_pct'] ?? null,
            'max_gap_seconds' => $result['max_gap_seconds'] ?? null,
            'low_confidence_segments' => $result['low_confidence_segments'] ?? [],
            'segment_timestamps' => $realSegments !== [] ? 'real' : 'estimated',
            'profile' => $result['profile'] ?? null,
            'engine' => (string) config('atlas.transcription.engine', 'whisper'),
        ];

        $asset->forceFill([
            'status' => 'transcribed',
            'transcript' => $text,
            'transcript_chars' => mb_strlen($text),
            'transcript_segments' => $segments,
            'transcription_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'transcription_engine' => (string) config('atlas.transcription.engine', 'whisper'),
            'transcription_confidence' => $result['confidence'] ?? null,
            'transcription_coverage_pct' => $result['coverage_pct'] ?? null,
            'diagnostics' => $diagnostics,
            'reason' => null,
            'last_ingested_at' => now(),
        ])->save();

        return $asset->refresh();
    }

    private function markFailed(AiMarketingVslAsset $asset, string $reason): AiMarketingVslAsset
    {
        $asset->forceFill(['status' => 'failed', 'reason' => $reason, 'last_ingested_at' => now()])->save();

        return $asset->refresh();
    }

    private function resolvePath(string $path): string
    {
        $path = trim($path);
        if (str_starts_with($path, '~')) {
            $home = (string) (getenv('HOME') ?: '');
            if ($home !== '') {
                $path = $home.substr($path, 1);
            }
        }
        $real = realpath($path);

        return $real !== false ? $real : $path;
    }

    private function deriveLabel(string $filename): string
    {
        $base = (string) preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', $filename);
        $base = trim((string) preg_replace('/\s+/', ' ', str_replace(['_', '-'], ' ', $base)));

        return $base !== '' ? Str::limit($base, 280, '') : $filename;
    }

    private function probeDurationSeconds(string $path): ?int
    {
        $ffprobe = $this->ffprobeBinary();
        if ($ffprobe === null) {
            return null;
        }

        try {
            $process = new Process([
                $ffprobe, '-v', 'error',
                '-show_entries', 'format=duration',
                '-of', 'default=noprint_wrappers=1:nokey=1',
                $path,
            ]);
            $process->setTimeout(30);
            $process->run();
            if (! $process->isSuccessful()) {
                return null;
            }
            $seconds = (float) trim($process->getOutput());

            return $seconds > 0 ? (int) ceil($seconds) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function ffprobeBinary(): ?string
    {
        $ffmpeg = (string) config('atlas.transcription.ffmpeg_path', '');
        if ($ffmpeg !== '') {
            $candidate = preg_replace('/ffmpeg(\.exe)?$/', 'ffprobe$1', $ffmpeg);
            if (is_string($candidate) && is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return (new ExecutableFinder)->find('ffprobe');
    }

    /**
     * Best-effort paragraph segmentation with proportional timestamps. The
     * extraction passes refine this into true persuasion beats.
     *
     * @return array<int,array<string,mixed>>
     */
    /**
     * Map the decoder's real segments (from whisper -ojf) into the stored shape, with REAL per-segment
     * start/end seconds and confidence — replacing the proportional estimate.
     *
     * @param  array<int,array{from_ms:int,to_ms:int,text:string,confidence:float|null}>  $segments
     * @return array<int,array<string,mixed>>
     */
    private function mapRealSegments(array $segments): array
    {
        $out = [];
        $index = 0;
        foreach ($segments as $s) {
            $text = trim((string) ($s['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $out[] = [
                'index' => ++$index,
                'text' => $text,
                'start_seconds' => (int) round((int) ($s['from_ms'] ?? 0) / 1000),
                'end_seconds' => (int) round((int) ($s['to_ms'] ?? 0) / 1000),
                'confidence' => isset($s['confidence']) && $s['confidence'] !== null ? round((float) $s['confidence'], 3) : null,
            ];
        }

        return $out;
    }

    private function segmentTranscript(string $text, ?int $durationSeconds): array
    {
        $parts = preg_split('/(?<=[.!?。！？])\s+/u', trim($text)) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), fn (string $p): bool => $p !== ''));
        if ($parts === []) {
            return [];
        }

        $segments = [];
        $buffer = '';
        $index = 0;
        foreach ($parts as $part) {
            $candidate = trim($buffer === '' ? $part : $buffer.' '.$part);
            if (mb_strlen($candidate) < 900) {
                $buffer = $candidate;

                continue;
            }
            $segments[] = ['index' => ++$index, 'text' => $candidate];
            $buffer = '';
        }
        if ($buffer !== '') {
            $segments[] = ['index' => ++$index, 'text' => $buffer];
        }

        if ($durationSeconds !== null && $durationSeconds > 0 && $segments !== []) {
            $total = max(1, array_sum(array_map(fn (array $s): int => mb_strlen((string) $s['text']), $segments)));
            $cursor = 0.0;
            foreach ($segments as $i => $seg) {
                $segStart = (int) floor($cursor / $total * $durationSeconds);
                $cursor += mb_strlen((string) $seg['text']);
                $segEnd = (int) floor($cursor / $total * $durationSeconds);
                $segments[$i]['approx_start_seconds'] = $segStart;
                $segments[$i]['approx_end_seconds'] = $segEnd;
            }
        }

        return $segments;
    }
}
