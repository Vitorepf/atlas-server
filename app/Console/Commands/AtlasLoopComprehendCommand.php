<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModelBuilder;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionQuery;
use Illuminate\Console\Command;
use Throwable;

/**
 * PART 2 · B4 — observability of the Part-1 brain. Dumps the grounded comprehension FACTS for a scope:
 * inventory / orphans / clone clusters / forbidden / doc-stated gaps, plus per-unit `level_vector` (six
 * booleans) and the NAMED `transitionsFor`. Read-only. NEVER a score/rank — the pétreo invariant flows
 * straight through (a unit is a vector of facts + named transitions, never a number).
 */
class AtlasLoopComprehendCommand extends Command
{
    protected $signature = 'atlas:loop:comprehend {scope : repo-relative scope root (e.g. app/Services/Ai/AutonomousEvolution)}
        {--repo= : repo root the scope resolves against (default: base_path)}
        {--docs=* : repo-relative doc roots for doc-stated-gap detection}
        {--json : Print machine-readable JSON}';

    protected $description = 'Dump the grounded scope-comprehension FACTS (inventory/orphans/clones/gaps + per-unit level_vector + transitions). Read-only, never a score.';

    public function handle(): int
    {
        $repoRoot = rtrim((string) ($this->option('repo') ?: base_path()), '/');
        $scope = (string) $this->argument('scope');
        $opts = ['docs_roots' => array_values(array_filter((array) $this->option('docs'), 'is_string'))];

        try {
            $query = new AtlasLoopScopeComprehensionQuery(new AtlasLoopScopeComprehensionModelBuilder, $repoRoot, $opts);
            $model = $query->model($scope);

            $units = [];
            foreach ($model->inventory as $item) {
                $fqcn = (string) $item['fqcn'];
                $units[] = $this->unitFacts($query, $fqcn);
            }
            foreach ($model->docStatedGaps as $gap) {
                $units[] = $this->unitFacts($query, (string) $gap); // gaps are "named but not built" units
            }

            $payload = [
                'schema' => 'atlas.loop.comprehend.v1',
                'scope_root' => $scope,
                'snapshot_id' => $model->snapshotId,
                'inventory_count' => count($model->inventory),
                'orphans' => $model->orphans,
                'clone_clusters' => array_map(static fn (array $c): string => (string) $c['cluster_id'], $model->cloneClusters),
                'forbidden' => $model->forbidden,
                'doc_stated_gaps' => $model->docStatedGaps,
                'staleness' => $query->staleness($scope),
                'units' => $units,
            ];
        } catch (Throwable $e) {
            $payload = ['schema' => 'atlas.loop.comprehend.v1', 'ok' => false, 'error' => $e->getMessage()];
            $this->emit($payload);

            return self::FAILURE;
        }

        $this->emit($payload);

        return self::SUCCESS;
    }

    /**
     * @return array{fqcn:string, level_vector:array<string,bool>, transitions:list<string>}
     */
    private function unitFacts(AtlasLoopScopeComprehensionQuery $query, string $fqcn): array
    {
        return [
            'fqcn' => $fqcn,
            'level_vector' => $query->levelVector($fqcn),
            'transitions' => array_map(
                static fn (array $t): string => (string) $t['transition'],
                $query->transitionsFor($fqcn),
            ),
        ];
    }

    /** @param array<string, mixed> $payload */
    private function emit(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return;
        }
        if (($payload['ok'] ?? true) === false) {
            $this->error((string) ($payload['error'] ?? 'comprehend failed'));

            return;
        }
        $this->info(sprintf(
            'scope=%s  inventory=%d  orphans=%d  clones=%d  doc_gaps=%d  stale=%s',
            $payload['scope_root'],
            $payload['inventory_count'],
            count($payload['orphans']),
            count($payload['clone_clusters']),
            count($payload['doc_stated_gaps']),
            ($payload['staleness']['stale'] ?? false) ? 'yes' : 'no',
        ));
    }
}
