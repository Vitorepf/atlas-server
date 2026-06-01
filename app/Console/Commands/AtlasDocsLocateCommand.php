<?php

namespace App\Console\Commands;

use App\Models\AtlasDocsAuthorityGraph;
use App\Services\Ai\Aaeos\AtlasDocsAuthorityGraphService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * R1 — resolve the canonical owner doc for any needle in one deterministic step.
 * `atlas:docs:locate <needle>` answers "where does X live"; `--rebuild`
 * regenerates the authority graph from doc frontmatter; `--strict` exits
 * non-zero when only a low-confidence keyword fallback is available.
 *
 * @see docs/engineering-knowledge-base/atlas-aaeos-documentation-as-law-proposal.md
 */
class AtlasDocsLocateCommand extends Command
{
    protected $signature = 'atlas:docs:locate
        {needle? : The topic, capability, symbol or doc id to resolve to its owner doc}
        {--rebuild : Rebuild the authority graph from doc frontmatter before resolving}
        {--strict : Exit non-zero when no authoritative (non-fallback) owner is found}
        {--json : Print machine-readable JSON}';

    protected $description = 'Resolve the canonical owner doc for a needle from the docs authority graph (R1).';

    public function handle(AtlasDocsAuthorityGraphService $graph): int
    {
        $rebuilt = null;
        if ((bool) $this->option('rebuild') || AtlasDocsAuthorityGraph::query()->count() === 0) {
            $rebuilt = $graph->build();
        }

        $needle = $this->argument('needle');
        if (! is_string($needle) || trim($needle) === '') {
            // No needle: report the graph build status.
            $payload = [
                'schema_version' => 'atlas.docs.authority_graph.v1',
                'graph' => $rebuilt ?? [
                    'rows' => AtlasDocsAuthorityGraph::query()->count(),
                    'docs' => AtlasDocsAuthorityGraph::query()->distinct('owner_doc_path')->count('owner_doc_path'),
                ],
                'hint' => 'Pass a needle, e.g. atlas:docs:locate "<capability or doc id>" --json',
            ];
            if ((bool) $this->option('json')) {
                $this->line($this->encode($payload));

                return self::SUCCESS;
            }
            $this->components->twoColumnDetail('authority graph rows', (string) data_get($payload, 'graph.rows', 0));
            $this->components->twoColumnDetail('owner docs', (string) data_get($payload, 'graph.docs', 0));
            $this->warn('Pass a needle to resolve an owner doc.');

            return self::SUCCESS;
        }

        $result = $graph->locate(trim($needle));
        if ($rebuilt !== null) {
            $result['rebuilt'] = $rebuilt;
        }

        $authoritative = ($result['resolved'] ?? false) === true
            && ($result['owner_basis'] ?? null) !== 'keyword_fallback';
        $exit = ((bool) $this->option('strict') && ! $authoritative) ? self::FAILURE : self::SUCCESS;

        if ((bool) $this->option('json')) {
            $this->line($this->encode($result));

            return $exit;
        }

        if (($result['resolved'] ?? false) !== true) {
            $this->error("No owner doc found for [{$needle}].");

            return $exit;
        }

        $this->components->twoColumnDetail('needle', (string) $result['needle']);
        $this->components->twoColumnDetail('owner doc', (string) $result['owner_doc_path']);
        $this->components->twoColumnDetail('basis', (string) $result['owner_basis']);
        $this->components->twoColumnDetail('confidence', (string) $result['confidence']);
        $this->components->twoColumnDetail('owner implementation_state', (string) ($result['owner_implementation_state'] ?? '-'));

        if (count($result['candidates'] ?? []) > 1) {
            $this->table(
                ['owner doc', 'needle', 'basis', 'conf'],
                collect($result['candidates'])->map(fn (array $c): array => [
                    Str::limit((string) $c['owner_doc_path'], 60),
                    Str::limit((string) $c['needle'], 36),
                    $c['basis'],
                    $c['confidence'],
                ])->all(),
            );
        }

        return $exit;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
