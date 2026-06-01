<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasNodeCatalogAndBuildContractService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas System Graph — Node Catalog And Build Contract CLI.
 *
 *   php artisan atlas:aaeos:node-catalog-and-build-contract [--json]
 *
 * Audits a candidate build packet (graph nodes + dependency edges) against the
 * documented node catalog: expected parent/status per node, the Self-Programming
 * `future` rule, required frontmatter, the next-action obligation and the Core
 * Dependencies edge set. Read-only and deterministic; never writes a Vault note,
 * runs a provider or promotes a status.
 *
 * @see docs/engineering-knowledge-base/system-graph/node-catalog-and-build-contract.md
 */
class AtlasNodeCatalogAndBuildContractCommand extends Command
{
    protected $signature = 'atlas:aaeos:node-catalog-and-build-contract {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas System Graph node catalog · audits a candidate build packet against the documented parent/status catalog, frontmatter rules and Core Dependencies.';

    public function handle(AtlasNodeCatalogAndBuildContractService $service): int
    {
        try {
            // Safe default packet: a minimal, conformant reference set. Each node
            // declares the catalog's parent + status and carries the mandatory
            // frontmatter; non-terminal nodes carry a next action. One canonical
            // edge is included. Operators can wire the real catalog later.
            $node = static function (string $name, string $parent, string $status, array $extra = []): array {
                return array_merge([
                    'name' => $name,
                    'parent' => $parent,
                    'status' => $status,
                    'frontmatter' => [
                        'graph_id' => str_replace(' ', '-', strtolower($name)),
                        'type' => 'system',
                        'status' => $status,
                        'parent' => $parent,
                        'canonical_doc' => 'docs/engineering-knowledge-base/system-graph/node-catalog-and-build-contract.md',
                    ],
                    'next_actions' => ['Manter node sincronizado com doc e evidencia.'],
                ], $extra);
            };

            $packet = [
                'nodes' => [
                    $node('Atlas', 'none', 'active'),
                    $node('Atlas Evolution System', 'Atlas', 'active'),
                    $node('Atlas Self-Construction OS', 'Atlas Evolution System', 'building'),
                    $node('Atlas Self-Programming OS', 'Atlas Evolution System', 'future'),
                    $node('Autonomous Repair Loop', 'Atlas Self-Programming OS', 'future'),
                ],
                'edges' => [
                    ['from' => 'Atlas Self-Programming OS', 'relation' => 'depends_on', 'to' => 'Atlas Self-Construction OS'],
                ],
                'completed_steps' => [],
            ];

            $result = $service->auditBuildPacket($packet);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return $result['status'] === AtlasNodeCatalogAndBuildContractService::STATUS_PASS
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'node_catalog_and_build_contract_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
