<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasSystemGraphDocOsService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Documentation Operating System (system-graph `doc-os`) decider CLI.
 *
 *   php artisan atlas:aaeos:atlas-system-graph-doc-os [--json]
 *
 * Exercises the repo-source admission decider on two safe reference frontmatters:
 * a fully canonical v1 doc that is admitted as a `graph_source: repo` node, and a
 * drifted doc (wrong schema, vault source, missing evidence) that is rejected.
 * Read-only and deterministic; it never reads a file, walks the repo or emits
 * evidence — it only decides.
 *
 * @see docs/engineering-knowledge-base/system-graph/doc-os.md
 */
class AtlasSystemGraphDocOsCommand extends Command
{
    protected $signature = 'atlas:aaeos:atlas-system-graph-doc-os {--json : machine-readable JSON output (default true)}';

    protected $description = 'Documentation Operating System · decides whether a doc may enter the Cartography as a validated repo source: v1 schema gate, repo-first invariant, evidence and macro-naming gates.';

    public function handle(AtlasSystemGraphDocOsService $service): int
    {
        try {
            // A fully canonical v1 doc: passes every gate, admitted as a repo source.
            $ready = $service->admit([
                'doc_schema' => AtlasSystemGraphDocOsService::CANONICAL_DOC_SCHEMA,
                'graph_id' => 'doc-os',
                'graph_title' => 'Documentation Operating System',
                'graph_world' => 'atlas',
                'graph_layer' => 'module',
                'graph_kind' => 'module',
                'graph_parent' => 'atlas-ai-kernel-pipeline',
                'graph_status' => 'active',
                'graph_source' => 'repo',
                'owner' => 'atlas-documentation',
                'repo_paths' => ['docs/engineering-knowledge-base/system-graph/doc-os.md'],
                'allowed_changes' => ['Evoluir regras de doc canonica com docs-health.'],
                'forbidden_changes' => ['Criar cartografia paralela.'],
                'evidence' => ['docs/engineering-knowledge-base/atlas-canonical-module-doc-v1.md'],
                'required_tests' => ['php artisan atlas:engineering:knowledge docs-health --json'],
                'risk_level' => 'high',
                'next_actions' => ['Adicionar gate visual de schema na Cartografia.'],
                'requires_evidence' => true,
            ]);

            // A drifted doc: not v1, claims a vault source, requires evidence yet has
            // none. Rejected; must not enter the Cartography as a repo source.
            $rejected = $service->admit([
                'doc_schema' => 'legacy_freeform',
                'graph_id' => 'mystery-node',
                'graph_source' => 'vault',
                'graph_status' => 'active',
                'requires_evidence' => true,
            ]);

            $payload = [
                'ok' => true,
                'schema' => AtlasSystemGraphDocOsService::SCHEMA,
                'ready_sample' => [
                    'verdict' => $ready['verdict'],
                    'admitted' => $ready['admitted'],
                    'repo_first' => $ready['repo_first'],
                    'breaches' => $ready['breaches'],
                ],
                'rejected_sample' => [
                    'verdict' => $rejected['verdict'],
                    'admitted' => $rejected['admitted'],
                    'repo_first' => $rejected['repo_first'],
                    'breaches' => $rejected['breaches'],
                ],
                'contract' => $service->contract(),
            ];

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => $e->getMessage(),
                'schema' => AtlasSystemGraphDocOsService::SCHEMA,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
