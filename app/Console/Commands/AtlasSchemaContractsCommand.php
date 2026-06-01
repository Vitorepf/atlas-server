<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasSchemaContractsService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Engineering Blueprint Schema Contracts gate CLI.
 *
 *   php artisan atlas:aaeos:schema-contracts
 *     [--schema=atlas.engineering.blueprint.v1] [--json]
 *
 * Read-only, deterministic. With --schema it classifies one schema_version /
 * payload kind against the documented family taxonomy; otherwise it validates a
 * safe-default frozen blueprint record against the documented invariants
 * (explicit schema_version, deterministic + matching content_hash, frozen
 * immutability) and reports the verdict.
 *
 * @see docs/engineering-knowledge-base/engineering-blueprint/schema-contracts.md
 */
class AtlasSchemaContractsCommand extends Command
{
    protected $signature = 'atlas:aaeos:schema-contracts
        {--schema= : a single schema_version or payload kind to classify}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Engineering Blueprint Schema Contracts · classify schema families and validate record invariants (schema_version, content_hash, frozen immutability, supersede, gates/evidence).';

    public function handle(AtlasSchemaContractsService $service): int
    {
        try {
            $schema = $this->option('schema');

            if (is_string($schema) && trim($schema) !== '') {
                $payload = [
                    'ok' => true,
                    'classify' => $service->classifyFamily($schema),
                ];
            } else {
                $record = $this->sampleRecord($service);

                $payload = [
                    'ok' => true,
                    'families' => AtlasSchemaContractsService::SCHEMA_FAMILIES,
                    'record' => $service->validateRecord($record),
                ];
            }

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'schema_contracts_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }

    /**
     * A safe-default frozen blueprint record whose content_hash is computed to
     * match its own payload, so the default run reports a contract-valid record.
     *
     * @return array<string,mixed>
     */
    private function sampleRecord(AtlasSchemaContractsService $service): array
    {
        $payload = [
            'goal' => 'ship the schema contracts runtime',
            'scope' => ['service', 'command', 'test'],
            'acceptance' => ['test green'],
        ];

        return [
            'schema_version' => 'atlas.engineering.blueprint.v1',
            'status' => AtlasSchemaContractsService::STATUS_FROZEN,
            'content_hash' => $service->contentHash($payload),
            'payload' => $payload,
        ];
    }
}
