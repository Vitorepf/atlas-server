<?php

namespace App\Console\Commands;

use App\Jobs\ProcessVslAssetIngestionJob;
use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\VslAssetIngestionService;
use App\Services\Ai\MarketingDomain\VslIntelligenceExtractorService;
use Illuminate\Console\Command;
use Throwable;

class AtlasAiMarketingVslCommand extends Command
{
    protected $signature = 'atlas:ai:marketing:vsl
        {action=ingest : ingest | show | list}
        {--file= : Path to the VSL media file (mp4/mov/mp3/wav...) — required for ingest}
        {--niche= : Optional niche label}
        {--campaign= : Optional campaign tag}
        {--language=pt : Transcription language}
        {--consensus=2 : Multi-run consensus on the critical market pass (2-3 = more reliable)}
        {--sync : Transcribe inline now instead of queueing (blocks)}
        {--force : Re-ingest even if this file was already transcribed}
        {--id= : VSL asset id (show)}
        {--hash= : VSL content hash (show)}
        {--limit=20 : Rows for list}
        {--json : Machine-readable JSON output}';

    protected $description = 'Atlas Marketing VSL intelligence: ingest a VSL file, transcribe it in full, and store the offer asset.';

    public function handle(VslAssetIngestionService $service, VslIntelligenceExtractorService $extractor): int
    {
        try {
            return match ((string) $this->argument('action')) {
                'ingest' => $this->ingest($service),
                'extract' => $this->extract($extractor),
                'show' => $this->show(),
                'list' => $this->list(),
                default => $this->respondError('invalid action ['.$this->argument('action').'] — use ingest|extract|show|list'),
            };
        } catch (Throwable $e) {
            return $this->respondError($e->getMessage(), $e::class);
        }
    }

    private function extract(VslIntelligenceExtractorService $extractor): int
    {
        $asset = $this->resolveAsset();
        if ($asset === null) {
            return $this->respondError('no asset found — pass --id=, --hash=, or --campaign=');
        }
        if (trim((string) $asset->transcript) === '') {
            return $this->respondError("asset [{$asset->id}] has no transcript yet (status={$asset->status})");
        }

        $asset = $extractor->extract($asset, max(1, (int) $this->option('consensus')));

        return $this->emitAsset($asset, in_array($asset->structure_status, ['ready', 'partial'], true) ? 'structured' : 'extract_failed');
    }

    private function resolveAsset(): ?AiMarketingVslAsset
    {
        $query = AiMarketingVslAsset::query();
        if ($id = trim((string) $this->option('id'))) {
            return $query->where('id', $id)->first();
        }
        if ($hash = trim((string) $this->option('hash'))) {
            return $query->where('content_hash', $hash)->first();
        }
        if ($campaign = trim((string) $this->option('campaign'))) {
            return $query->where('campaign_ref', $campaign)->latest('last_ingested_at')->first();
        }

        return $query->latest('last_ingested_at')->first();
    }

    private function ingest(VslAssetIngestionService $service): int
    {
        $file = trim((string) $this->option('file'));
        if ($file === '') {
            return $this->respondError('ingest requires --file="/path/to/vsl.mp4"');
        }

        $asset = $service->ingestFile($file, array_filter([
            'niche' => $this->option('niche'),
            'campaign' => $this->option('campaign'),
            'language' => $this->option('language'),
            'force' => $this->option('force') ? true : null,
        ], static fn ($v): bool => $v !== null && $v !== ''));

        if (in_array($asset->status, ['transcribed', 'analyzing', 'structured'], true) && ! $this->option('force')) {
            return $this->emitAsset($asset, 'already_ingested');
        }

        if ($this->option('sync')) {
            $asset = $service->transcribe($asset);

            return $this->emitAsset($asset, $asset->status === 'failed' ? 'failed' : 'transcribed_sync');
        }

        ProcessVslAssetIngestionJob::dispatch($asset->id);

        return $this->emitAsset($asset, 'queued');
    }

    private function show(): int
    {
        $query = AiMarketingVslAsset::query();
        if ($id = trim((string) $this->option('id'))) {
            $query->where('id', $id);
        } elseif ($hash = trim((string) $this->option('hash'))) {
            $query->where('content_hash', $hash);
        } else {
            return $this->respondError('show requires --id= or --hash=');
        }

        $asset = $query->first();
        if ($asset === null) {
            return $this->respondError('asset not found');
        }

        return $this->emitAsset($asset, 'show');
    }

    private function list(): int
    {
        $assets = AiMarketingVslAsset::query()
            ->orderByDesc('last_ingested_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        if ((bool) $this->option('json')) {
            $this->line($this->encode([
                'ok' => true,
                'count' => $assets->count(),
                'assets' => $assets->map(fn (AiMarketingVslAsset $a): array => $this->summary($a))->all(),
            ]));

            return self::SUCCESS;
        }

        foreach ($assets as $asset) {
            $this->components->twoColumnDetail($asset->status.' · '.$asset->label, (string) $asset->id);
        }
        $this->components->info($assets->count().' asset(s)');

        return self::SUCCESS;
    }

    private function emitAsset(AiMarketingVslAsset $asset, string $event): int
    {
        if ((bool) $this->option('json')) {
            $this->line($this->encode([
                'ok' => $asset->status !== 'failed',
                'event' => $event,
                'asset' => $this->summary($asset),
            ]));

            return $asset->status === 'failed' ? self::FAILURE : self::SUCCESS;
        }

        $this->components->twoColumnDetail('id', (string) $asset->id);
        $this->components->twoColumnDetail('label', (string) $asset->label);
        $this->components->twoColumnDetail('niche', (string) ($asset->niche ?? '—'));
        $this->components->twoColumnDetail('status', $asset->status);
        $this->components->twoColumnDetail('event', $event);
        $this->components->twoColumnDetail('duration', $asset->duration_seconds ? $asset->duration_seconds.'s' : '—');
        $this->components->twoColumnDetail('transcript_chars', (string) $asset->transcript_chars);
        if ($asset->status === 'failed') {
            $this->components->twoColumnDetail('reason', (string) $asset->reason);
        }
        if ($event === 'queued') {
            $this->newLine();
            $this->components->info('Queued. Run a worker: php artisan queue:work --stop-when-empty');
            $this->components->info('Or re-run with --sync to transcribe inline.');
        }

        return $asset->status === 'failed' ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function summary(AiMarketingVslAsset $asset): array
    {
        return [
            'id' => $asset->id,
            'content_hash' => $asset->content_hash,
            'label' => $asset->label,
            'niche' => $asset->niche,
            'status' => $asset->status,
            'language' => $asset->language,
            'duration_seconds' => $asset->duration_seconds,
            'transcript_chars' => $asset->transcript_chars,
            'structure_status' => $asset->structure_status,
            'reason' => $asset->reason,
            'last_ingested_at' => $asset->last_ingested_at?->toIso8601String(),
        ];
    }

    private function respondError(string $message, ?string $type = null): int
    {
        if ((bool) $this->option('json')) {
            $this->line($this->encode(array_filter(['ok' => false, 'error' => $message, 'type' => $type])));
        } else {
            $this->components->error($message);
        }

        return self::FAILURE;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
