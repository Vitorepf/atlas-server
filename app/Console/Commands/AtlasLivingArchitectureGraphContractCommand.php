<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasLivingArchitectureGraphContractService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Living Architecture Graph (L1) Definition-of-Done CLI.
 *
 *   php artisan atlas:aaeos:living-architecture-graph-contract [--json]
 *
 * Runs the Definition-of-Done evaluation over a reference L1 graph and emits the
 * pass|fail evidence document. Read-only and deterministic; it never writes a
 * Vault note, runs a provider or promotes a node status.
 *
 * @see docs/engineering-knowledge-base/system-graph/living-architecture-graph-contract.md
 */
class AtlasLivingArchitectureGraphContractCommand extends Command
{
    protected $signature = 'atlas:aaeos:living-architecture-graph-contract {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Living Architecture Graph L1 · checks a candidate Obsidian graph against the documented node-update and Definition-of-Done rules.';

    public function handle(AtlasLivingArchitectureGraphContractService $service): int
    {
        try {
            // Safe default graph: a minimal, conformant reference set covering the
            // Definition-of-Done required nodes plus one top-level system. Each
            // node carries the required frontmatter, a next action (or terminal
            // status), and evidence where it claims `implemented`. Operators can
            // wire the real catalog later.
            $node = static function (string $id, string $status, array $extra = []): array {
                return array_merge([
                    'id' => $id,
                    'status' => $status,
                    'frontmatter' => [
                        'type' => 'module',
                        'status' => $status,
                        'owner' => 'atlas-ai',
                        'parent' => 'atlas-ai-canonical-architecture-index',
                        'canonical_doc' => 'docs/engineering-knowledge-base/system-graph/living-architecture-graph-contract.md',
                    ],
                    'next_actions' => ['Manter node sincronizado com doc e evidencia.'],
                    'evidence' => [],
                ], $extra);
            };

            $graph = [
                'top_level_systems' => ['atlas-system-graph'],
                'nodes' => [
                    $node('atlas-system-graph', 'active'),
                    $node('self-construction-os', 'building'),
                    $node('self-programming-os', 'future'),
                    $node('agent-control-plane', 'planned'),
                    $node('work-splitter', 'planned'),
                    $node('scope-validator', 'planned'),
                    $node('ai-implementation-packet', 'implemented', [
                        'next_actions' => [],
                        'evidence' => [[
                            'kind' => 'doc',
                            'ref' => 'docs/engineering-knowledge-base/self-construction/ai-implementation-packet-contract.md',
                        ]],
                    ]),
                ],
            ];

            $result = $service->checkDefinitionOfDone($graph);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return $result['status'] === AtlasLivingArchitectureGraphContractService::STATUS_PASS
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'living_architecture_graph_contract_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
