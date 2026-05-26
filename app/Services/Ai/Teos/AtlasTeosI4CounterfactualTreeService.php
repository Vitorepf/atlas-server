<?php

declare(strict_types=1);

namespace App\Services\Ai\Teos;

use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * Atlas TEOS-I4 Counterfactual Tree — Patamar 4 · 4.4.
 *
 * Composer sobre TEOS-I3. NÃO reimplementa scoring nem alternative kinds.
 * Apenas compõe múltiplas chamadas de I-3 em árvore greedy breadth-first
 * com gates pétreos via Constitutional Kernel + Autonomy Admission.
 *
 * Authority doc:
 *   docs/engineering-knowledge-base/atlas-teos-i4-counterfactual-tree.md
 *
 * Invariantes:
 *   - is_counterfactual=true (delegate I-3);
 *   - max_breadth ≤ 5, max_depth ≤ 4 (defensive caps);
 *   - Kernel + Admission consultados antes de emitir tree;
 *   - append-only JSONL local.
 */
final class AtlasTeosI4CounterfactualTreeService
{
    public const TREE_SCHEMA = 'atlas.teos_i4.tree_envelope.v1';

    public const NODE_SCHEMA = 'atlas.teos_i4.node.v1';

    public const MAX_BREADTH = 5;

    public const MAX_DEPTH = 4;

    /**
     * Hard canonical cap on total nodes generated per tree expansion —
     * canon §2.4 promises "árvore inteira, poda por pareto, hard cap 1000
     * nós". Current breadth × depth (5⁴ = 625) is already below this cap,
     * but we surface MAX_TOTAL_NODES explicitly so an evolving caller
     * can never exceed it even if MAX_BREADTH/MAX_DEPTH change.
     *
     * Pruning discipline (canon §2.4 "pareto cost×improvement"):
     *   - Each layer expands `breadth` alternatives (already capped at
     *     MAX_BREADTH).
     *   - Alternatives are stored with projected_outcome_score; the caller
     *     reads `delta = projected − factual` and picks the highest-delta
     *     branch (greedy pareto by single objective: improvement).
     *   - Future evolution: multi-objective (cost AND improvement) pruning
     *     can be wired via the same array_slice path without expanding the
     *     cap.
     */
    public const MAX_TOTAL_NODES = 1000;

    private ?string $treesLogOverride = null;

    public function __construct(
        private readonly AtlasTeosI3CounterfactualService $i3,
        private readonly AtlasConstitutionalKernelService $kernel,
        private readonly AtlasAutonomyAdmissionService $admission,
    ) {}

    public function setTreesLogPathForTesting(?string $path): void
    {
        $this->treesLogOverride = $path;
    }

    public function treesLogPath(): string
    {
        if ($this->treesLogOverride !== null) {
            return $this->treesLogOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/teos_i4')
            : sys_get_temp_dir().'/atlas/teos_i4';

        return $base.DIRECTORY_SEPARATOR.'trees.jsonl';
    }

    /**
     * Expand counterfactual tree greedy breadth-first.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function expand(array $input): array
    {
        $anchor = (string) ($input['anchor_decision_id'] ?? '');
        if ($anchor === '') {
            throw new InvalidArgumentException('anchor_decision_id is required.');
        }
        $alternatives = (array) ($input['alternatives'] ?? []);
        if ($alternatives === []) {
            throw new InvalidArgumentException('alternatives must be non-empty.');
        }
        $breadth = $this->clampInt((int) ($input['max_breadth'] ?? 3), 1, self::MAX_BREADTH);
        $depth = $this->clampInt((int) ($input['max_depth'] ?? 2), 1, self::MAX_DEPTH);

        $scope = (array) ($input['scope'] ?? []);
        $factual = (float) ($input['factual_outcome_score'] ?? 0.5);
        $projected = (float) ($input['projected_outcome_score'] ?? $factual);

        // 1. Constitutional Kernel — pétreo gate sobre a operação inteira.
        $kernelEnv = $this->kernel->validateChange([
            'change_kind' => 'counterfactual_tree_expand',
            'proposed_effect' => "expand counterfactual tree from anchor {$anchor} breadth={$breadth} depth={$depth}",
            'scope' => $scope,
            'actor' => 'TEOS-I4',
        ]);

        $generatedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
        $treeId = 'cft_'.substr(hash('sha256', $anchor.'|'.$breadth.'|'.$depth.'|'.$generatedAt), 0, 12);

        if ($kernelEnv['decision'] === AtlasConstitutionalKernelService::DECISION_BLOCK) {
            $env = $this->buildEnvelope(
                treeId: $treeId,
                generatedAt: $generatedAt,
                anchor: $anchor,
                breadth: $breadth,
                depth: $depth,
                nodes: [],
                bestPath: [],
                bestImprovement: 0.0,
                kernelDecision: $kernelEnv['decision'],
                admissionDecision: AtlasAutonomyAdmissionService::DECISION_DENY,
            );
            $this->appendJsonl($this->treesLogPath(), $env);

            return $env;
        }

        // 2. Greedy BFS expansion. Each level picks best node so far and branches K alternatives.
        $nodes = [];
        $rootId = 'n_root_'.substr(hash('sha256', $treeId.'|root'), 0, 8);
        $nodes[] = [
            'schema_version' => self::NODE_SCHEMA,
            'node_id' => $rootId,
            'parent_node_id' => null,
            'depth' => 0,
            'branch_id' => null,
            'improvement_delta' => 0.0,
            'cumulative_improvement' => 0.0,
        ];
        $currentBest = $nodes[0];

        $altsPool = array_slice($alternatives, 0, $breadth);

        for ($d = 1; $d <= $depth; $d++) {
            $bestChild = null;
            foreach ($altsPool as $alt) {
                $branch = $this->i3->branch([
                    'scope' => $scope,
                    'anchor_decision_id' => $anchor,
                    'alternative' => $alt,
                    'factual_outcome_score' => $factual,
                    // Heurística: cada nível adiciona +0.05 ao projected até teto 1.0.
                    'projected_outcome_score' => min(1.0, $projected + 0.05 * ($d - 1)),
                    'depth' => 1,
                ]);
                $delta = (float) $branch['projected_outcome_score'] - (float) $branch['factual_outcome_score'];
                $cumulative = (float) $currentBest['cumulative_improvement'] + $delta;
                $nodeId = 'n_d'.$d.'_'.substr(hash('sha256', $branch['branch_id']), 0, 6);
                $node = [
                    'schema_version' => self::NODE_SCHEMA,
                    'node_id' => $nodeId,
                    'parent_node_id' => $currentBest['node_id'],
                    'depth' => $d,
                    'branch_id' => $branch['branch_id'],
                    'improvement_delta' => round($delta, 4),
                    'cumulative_improvement' => round($cumulative, 4),
                ];
                $nodes[] = $node;
                if ($bestChild === null || $node['cumulative_improvement'] > $bestChild['cumulative_improvement']) {
                    $bestChild = $node;
                }
                // Hard total-node cap per canon §2.4. Pareto-by-improvement
                // is already in place via $bestChild selection; this is the
                // last-resort failsafe so a future regression in caller
                // params cannot blow past the canonical 1000-node budget.
                if (count($nodes) >= self::MAX_TOTAL_NODES) {
                    break 2;
                }
            }
            if ($bestChild === null) {
                break;
            }
            $currentBest = $bestChild;
        }

        // 3. Compute best_path back from $currentBest.
        $bestPath = $this->reconstructPath($nodes, $currentBest['node_id']);
        $bestImprovement = (float) $currentBest['cumulative_improvement'];

        // 4. Autonomy Admission — caps autonomy for tree application.
        $admissionEnv = $this->admission->admit([
            'change_kind' => 'counterfactual_tree_apply',
            'proposed_effect' => "apply best path of counterfactual tree from anchor {$anchor}",
            'scope' => $scope,
            'actor' => 'TEOS-I4',
            'requested_autonomy' => 'execute_with_approval',
        ]);

        $env = $this->buildEnvelope(
            treeId: $treeId,
            generatedAt: $generatedAt,
            anchor: $anchor,
            breadth: $breadth,
            depth: $depth,
            nodes: $nodes,
            bestPath: $bestPath,
            bestImprovement: $bestImprovement,
            kernelDecision: $kernelEnv['decision'],
            admissionDecision: $admissionEnv['decision'],
        );
        $this->appendJsonl($this->treesLogPath(), $env);

        return $env;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listTrees(): array
    {
        return $this->readJsonl($this->treesLogPath());
    }

    public function bestPath(string $treeId): array
    {
        foreach ($this->listTrees() as $t) {
            if (($t['tree_id'] ?? null) === $treeId) {
                return (array) ($t['best_path'] ?? []);
            }
        }

        return [];
    }

    // ---------- internals ----------

    /**
     * @param  list<array<string,mixed>>  $nodes
     * @param  list<string>  $bestPath
     * @return array<string,mixed>
     */
    private function buildEnvelope(
        string $treeId,
        string $generatedAt,
        string $anchor,
        int $breadth,
        int $depth,
        array $nodes,
        array $bestPath,
        float $bestImprovement,
        string $kernelDecision,
        string $admissionDecision,
    ): array {
        $env = [
            'schema_version' => self::TREE_SCHEMA,
            'tree_id' => $treeId,
            'generated_at' => $generatedAt,
            'anchor_decision_id' => $anchor,
            'max_breadth' => $breadth,
            'max_depth' => $depth,
            'node_count' => count($nodes),
            'nodes' => $nodes,
            'best_path' => $bestPath,
            'best_path_improvement' => round($bestImprovement, 4),
            'kernel_decision' => $kernelDecision,
            'admission_decision' => $admissionDecision,
        ];
        $env['tree_hash'] = 'sha256:'.hash('sha256', json_encode([
            'schema' => self::TREE_SCHEMA,
            'anchor' => $anchor,
            'breadth' => $breadth,
            'depth' => $depth,
            'best_path' => $bestPath,
            'kernel_decision' => $kernelDecision,
        ], JSON_THROW_ON_ERROR));

        return $env;
    }

    /**
     * @param  list<array<string,mixed>>  $nodes
     * @return list<string>
     */
    private function reconstructPath(array $nodes, string $leafId): array
    {
        $byId = [];
        foreach ($nodes as $n) {
            $byId[(string) $n['node_id']] = $n;
        }
        $path = [];
        $cursor = $leafId;
        while ($cursor !== '') {
            if (! isset($byId[$cursor])) {
                break;
            }
            array_unshift($path, $cursor);
            $cursor = (string) ($byId[$cursor]['parent_node_id'] ?? '');
            if ($cursor === '' || $cursor === '0') {
                break;
            }
        }

        return $path;
    }

    private function clampInt(int $v, int $min, int $max): int
    {
        if ($v < $min) {
            return $min;
        }
        if ($v > $max) {
            return $max;
        }

        return $v;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readJsonl(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $out = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
    }

    private function appendJsonl(string $path, array $payload): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            if (function_exists('app')) {
                File::ensureDirectoryExists($dir);
            } else {
                @mkdir($dir, 0775, true);
            }
        }
        $fp = fopen($path, 'ab');
        if ($fp === false) {
            throw new \RuntimeException("Could not open {$path} for writing.");
        }
        try {
            if (flock($fp, LOCK_EX)) {
                fwrite($fp, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
                fflush($fp);
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }
    }
}
