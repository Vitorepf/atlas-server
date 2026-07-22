<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Context\Retrieval\AtlasKnowledgeItemEmbeddingCoverageService;
use Illuminate\Console\Command;

final class AtlasMemoryKbEmbeddingCoverageCommand extends Command
{
    protected $signature = 'atlas:memory:kb-embedding-coverage
        {--json : Emit machine-readable JSON}';

    protected $description = 'MAXA-06 fase 1 reader: semantic-coverage ratio over atlas_engineering_knowledge_items using MAXA-03 provenance.';

    public function handle(AtlasKnowledgeItemEmbeddingCoverageService $service): int
    {
        $payload = $service->report();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
            ));

            return self::SUCCESS;
        }

        $this->line(sprintf(
            '[atlas:memory:kb-embedding-coverage] %s active=%d covered=%d stale=%d missing=%d ratio=%s',
            (string) ($payload['status'] ?? 'unknown'),
            (int) data_get($payload, 'aggregate.active_items', 0),
            (int) data_get($payload, 'aggregate.covered_count', 0),
            (int) data_get($payload, 'aggregate.stale_count', 0),
            (int) data_get($payload, 'aggregate.missing_count', 0),
            data_get($payload, 'aggregate.coverage_ratio') === null
                ? 'n/a'
                : (string) data_get($payload, 'aggregate.coverage_ratio'),
        ));

        return self::SUCCESS;
    }
}
