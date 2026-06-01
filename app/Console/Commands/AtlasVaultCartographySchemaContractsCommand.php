<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasVaultCartographySchemaContractsService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Vault Cartography Schema Contracts decider CLI.
 *
 *   php artisan atlas:aaeos:atlas-vault-cartography-schema-contracts [--json]
 *
 * Validates a safe reference frontmatter map against the documented contract
 * surfaces (source authority, required semantic fields, visual-field defaults,
 * source rules and compatibility / anti-canon) and also shows a deliberately
 * invalid sample so the violation reasons are visible. Read-only and
 * deterministic; it never reads a file, walks the vault, opens a source, writes
 * a doc or emits evidence.
 *
 * @see docs/engineering-knowledge-base/vault/atlas-vault-cartography-schema-contracts.md
 */
class AtlasVaultCartographySchemaContractsCommand extends Command
{
    protected $signature = 'atlas:aaeos:atlas-vault-cartography-schema-contracts {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Vault Cartography Schema Contracts · validates frontmatter source authority, required semantic fields, graph_* visual defaults, source rules and anti-canon against the documented contract.';

    public function handle(AtlasVaultCartographySchemaContractsService $service): int
    {
        try {
            // A valid repo-sourced pipeline step (mirrors the doc's "Repo pipeline
            // step" example): explicit graph_source, canonical_doc under repo,
            // next_actions present, graph_id matches filename.
            $valid = $service->validate(
                [
                    'graph_id' => 'atlas-decide',
                    'status' => 'active',
                    'graph_status' => 'active',
                    'graph_kind' => 'step',
                    'graph_source' => 'repo',
                    'canonical_doc' => 'docs/engineering-knowledge-base/atlas-ai-master-architecture.md',
                    'next_actions' => ['Add mandatory dry-run above budget threshold X.'],
                ],
                'atlas-decide',
            );

            // A deliberately invalid piece: architectural kind sourced from vault,
            // missing canonical_doc, missing next_actions, a forbidden field and a
            // status/graph_status mismatch — each must surface a reason.
            $invalid = $service->validate(
                [
                    'graph_id' => 'broken-step',
                    'status' => 'active',
                    'graph_status' => 'building',
                    'graph_kind' => 'system',
                    'graph_source' => 'vault',
                    'sync_status' => 'ok',
                ],
                'broken-step',
            );

            $payload = [
                'ok' => true,
                'schema' => AtlasVaultCartographySchemaContractsService::SCHEMA,
                'valid_sample' => [
                    'verdict' => $valid['verdict'],
                    'violations' => $valid['violations'],
                    'resolved_visual' => $valid['resolved_visual'],
                ],
                'invalid_sample' => [
                    'verdict' => $invalid['verdict'],
                    'violations' => $invalid['violations'],
                ],
            ];

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => $e->getMessage(),
                'schema' => AtlasVaultCartographySchemaContractsService::SCHEMA,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
