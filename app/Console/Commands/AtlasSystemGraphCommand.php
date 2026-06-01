<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasSystemGraphService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas System Graph (L0) Definition-of-Done CLI.
 *
 *   php artisan atlas:aaeos:system-graph [--json]
 *
 * Runs the L0 Definition-of-Done evaluation over a reference graph that carries
 * all 12 canonical top-level systems plus the documented Vault paths, and emits
 * the pass|fail evidence document. Read-only and deterministic; it never writes
 * a Vault note, runs a provider or promotes a node status.
 *
 * @see docs/engineering-knowledge-base/atlas-system-graph.md
 */
class AtlasSystemGraphCommand extends Command
{
    protected $signature = 'atlas:aaeos:system-graph {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas System Graph L0 · checks a candidate System Graph against the documented node/relationship/status taxonomy and Definition-of-Done.';

    public function handle(AtlasSystemGraphService $service): int
    {
        try {
            // Safe default graph: a minimal conformant reference set that names
            // every canonical top-level system as a `system` node with a sound
            // status and a canonical-doc backlink, plus the documented Vault
            // paths. Operators can wire the real Vault catalog later.
            $node = static function (string $name): array {
                return [
                    'name' => $name,
                    'type' => 'system',
                    'status' => 'active',
                    'parents' => ['Atlas'],
                    'speculative' => false,
                    'is_vault_node' => true,
                    'canonical_doc' => 'docs/engineering-knowledge-base/atlas-system-graph.md',
                ];
            };

            $nodes = [];
            foreach (AtlasSystemGraphService::CANONICAL_TOP_SYSTEMS as $system) {
                $nodes[] = $node($system);
            }

            $graph = [
                'nodes' => $nodes,
                'vault_root_note' => AtlasSystemGraphService::VAULT_ROOT_NOTE,
                'vault_module_template' => AtlasSystemGraphService::VAULT_MODULE_TEMPLATE,
            ];

            $result = $service->checkDefinitionOfDone($graph);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return $result['status'] === AtlasSystemGraphService::STATUS_PASS
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'system_graph_contract_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
