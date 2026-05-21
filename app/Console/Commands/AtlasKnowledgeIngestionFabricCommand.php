<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Context\AtlasKnowledgeIngestionFabricService;
use Illuminate\Console\Command;

final class AtlasKnowledgeIngestionFabricCommand extends Command
{
    protected $signature = 'atlas:context:knowledge-ingestion
        {--source-type=text : text|pdf|image|youtube|repo_file|spreadsheet|url}
        {--origin-uri= : Source URI/path}
        {--content= : Optional inline content}
        {--language= : Source language}
        {--confidence= : Extraction confidence}
        {--provider-target=external : local|external|mixed}
        {--risk=low : Risk level}
        {--json : Emit canonical JSON}';

    protected $description = 'Normalize AUCRI AKIF source packet with lineage, privacy gate and receipt hashes.';

    public function handle(AtlasKnowledgeIngestionFabricService $service): int
    {
        $payload = $service->normalize([
            'source_type' => (string) $this->option('source-type'),
            'origin_uri' => (string) ($this->option('origin-uri') ?? ''),
            'content' => (string) ($this->option('content') ?? ''),
            'language' => (string) ($this->option('language') ?? ''),
            'confidence' => $this->option('confidence') === null ? null : (float) $this->option('confidence'),
            'provider_target' => (string) $this->option('provider-target'),
            'risk_level' => (string) $this->option('risk'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return $payload['status'] === 'blocked' ? self::FAILURE : self::SUCCESS;
        }

        $this->components->twoColumnDetail('Atlas Knowledge Ingestion Fabric', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('Status', (string) $payload['status']);
        $this->components->twoColumnDetail('Source type', (string) data_get($payload, 'source_packet.source_type', 'unknown'));
        $this->components->twoColumnDetail('Language', (string) data_get($payload, 'source_packet.language', 'unknown'));
        $this->components->twoColumnDetail('Confidence', (string) data_get($payload, 'source_packet.confidence', 0));

        return $payload['status'] === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}
