<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasContractSchemaRegistryService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Contract Schema Registry gate CLI.
 *
 *   php artisan atlas:aaeos:contract-schema-registry
 *     [--schema-id=atlas.spec_pack.v1] [--json]
 *
 * Read-only, deterministic. With --schema-id it parses one schema id against the
 * canonical `atlas.<namespace>.<name>.v<int>` shape; otherwise it validates the
 * doc's initial registry snapshot against the documented quality gates
 * (owner/doc present, no duplicates) and reports the active/block verdict.
 *
 * @see docs/engineering-knowledge-base/atlas-contract-schema-registry.md
 */
class AtlasContractSchemaRegistryCommand extends Command
{
    protected $signature = 'atlas:aaeos:contract-schema-registry
        {--schema-id= : a single schema id to parse against the canonical shape}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Contract Schema Registry · validate schema ids and the registry snapshot (owner/doc gates, duplicate detection, breaking-change policy).';

    public function handle(AtlasContractSchemaRegistryService $service): int
    {
        try {
            $schemaId = $this->option('schema-id');

            if (is_string($schemaId) && trim($schemaId) !== '') {
                $payload = [
                    'ok' => true,
                    'schema_id' => $service->parseSchemaId($schemaId),
                ];
            } else {
                $payload = [
                    'ok' => true,
                    'registry' => $service->validateRegistry($this->snapshot()),
                ];
            }

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'contract_schema_registry_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }

    /**
     * A safe-default registry sample drawn from the doc's initial snapshot.
     *
     * @return list<array<string,mixed>>
     */
    private function snapshot(): array
    {
        return [
            ['schema_id' => 'atlas.spec_pack.v1', 'owner' => 'atlas-ai-spec-operating-system', 'canonical_doc' => 'atlas-ai-spec-operating-system.md', 'deprecated' => false],
            ['schema_id' => 'atlas.schema_registry.entry.v1', 'owner' => 'atlas-contract-schema-registry', 'canonical_doc' => 'atlas-contract-schema-registry.md', 'deprecated' => false],
            ['schema_id' => 'atlas.decision_receipt.v2', 'owner' => 'atlas-evidence-certification-runtime', 'canonical_doc' => 'atlas-evidence-certification-runtime.md', 'deprecated' => false],
            ['schema_id' => 'atlas.dev.repair_loop.v1', 'owner' => 'atlas-dev-patamares-runbook', 'canonical_doc' => 'atlas-dev-patamares-runbook.md', 'deprecated' => false],
        ];
    }
}
