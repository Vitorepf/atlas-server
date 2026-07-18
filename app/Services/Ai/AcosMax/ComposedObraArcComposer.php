<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainOrganDependencyGraph;
use App\Services\Ai\Support\AiValueNormalizer;

/**
 * MULTN17-02 — composed obra arc origination.
 *
 * Joins grounded neighbor candidates (via {@see AtlasBrainOrganDependencyGraph}) into one
 * serialized arc with a falsifiable thesis, ordered tasks, executable completion criterion,
 * and objective kill-gate. Reactive obra-cluster leads may seed grouping but never bypass gates.
 */
final class ComposedObraArcComposer
{
    public const SCHEMA_VERSION = 'atlas.originator.composed_obra_arc.v1';

    public const MIN_NEIGHBOR_CANDIDATES = 3;

    public const KILL_GATE_CONSECUTIVE_FAILURES = 3;

    public const DEFAULT_AUTHOR_ENGINE_ID = 'cursor-acos-max-multn1702';

    public const DEFAULT_JUDGE_ENGINE_ID = 'codex-independent-multn1702-judge';

    public const FIELD_ENABLED = 'enabled';
    public const FIELD_TARGET_PATH = 'target_path';
    public const FIELD_ORGAN_CLASS = 'organ_class';
    public const FIELD_LEVERAGE = 'leverage';
    public const FIELD_STATUS = 'status';
    public const FIELD_REASON = 'reason';
    public const FIELD_ARC_ID = 'arc_id';
    public const FIELD_OK = 'ok';
    public const FIELD_CANDIDATES = 'candidates';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_SOURCE = 'source';
    public const FIELD_COMPOSED = 'composed';
    public const FIELD_BASIS = 'basis';
    public const FIELD_ARCS = 'arcs';
    public const FIELD_ARC_COUNT = 'arc_count';
    public const FIELD_AUTHOR_ENGINE_ID = 'author_engine_id';
    public const FIELD_ARC_BUYS_GATE_WHOLESALE = 'arc_buys_gate_wholesale';
    public const FIELD_AUTO_MERGE = 'auto_merge';
    public const FIELD_EACH_TASK_REQUIRES_ARCHITECT_AND_SEED_GATE = 'each_task_requires_architect_and_seed_gate';
    public const FIELD_PROVIDER_CALLS_MADE = 'provider_calls_made';
    public const FIELD_ORDER = 'order';
    public const FIELD_ACTION_ON_TRIGGER = 'action_on_trigger';
    public const FIELD_ARCHITECT_PHASE_GATE = 'architect_phase_gate';
    public const FIELD_AUTHOR_NEQ_JUDGE = 'author_neq_judge';
    public const FIELD_CERTIFIER_ENGINE_ID = 'certifier_engine_id';
    public const FIELD_CHALLENGER_ADVISORY = 'challenger_advisory';
    public const FIELD_CHALLENGER_ENGINE_ID = 'challenger_engine_id';

    public const STATUS_FLAG_DISABLED = 'flag_disabled';

    public const STATUS_AUTHOR_JUDGE_INVARIANT_VIOLATION = 'author_judge_invariant_violation';

    public const STATUS_INVALID_DEPENDENCY_GRAPH = 'invalid_dependency_graph';

    public const STATUS_INSUFFICIENT_GROUNDED_CANDIDATES = 'insufficient_grounded_candidates';

    public const STATUS_NO_NEIGHBOR_CLUSTER = 'no_neighbor_cluster';

    /**
     * @param  list<array<string,mixed>>  $candidates  grounded origination candidates
     * @param  list<array<string,mixed>>  $clusterLeads  optional reactive obra-cluster leads
     * @param  array<string,mixed>  $context  {enabled:bool, author_engine_id?, judge_engine_id?, graph?:AtlasBrainOrganDependencyGraph}
     * @return array<string,mixed>
     */
    public static function compose(array $candidates, array $clusterLeads = [], array $context = []): array
    {
        if (($context[self::FIELD_ENABLED] ?? false) !== true) {
            return self::emptyResult(self::STATUS_FLAG_DISABLED);
        }

        $author = AiValueNormalizer::trimmedStringOrNull($context[self::FIELD_AUTHOR_ENGINE_ID] ?? null) ?? self::DEFAULT_AUTHOR_ENGINE_ID;
        $judge = AiValueNormalizer::trimmedStringOrNull($context['judge_engine_id'] ?? null) ?? self::DEFAULT_JUDGE_ENGINE_ID;
        if ($author === '' || $judge === '' || $author === $judge) {
            return self::emptyResult(self::STATUS_AUTHOR_JUDGE_INVARIANT_VIOLATION);
        }

        $graph = $context['graph'] ?? new AtlasBrainOrganDependencyGraph;
        if (! $graph instanceof AtlasBrainOrganDependencyGraph) {
            return self::emptyResult(self::STATUS_INVALID_DEPENDENCY_GRAPH);
        }

        $grounded = self::groundedCandidates($candidates);
        if (count($grounded) < self::MIN_NEIGHBOR_CANDIDATES) {
            return self::emptyResult(self::STATUS_INSUFFICIENT_GROUNDED_CANDIDATES);
        }

        $group = self::bestNeighborGroup($grounded, $graph, $clusterLeads);
        if ($group === null) {
            return self::emptyResult(self::STATUS_NO_NEIGHBOR_CLUSTER);
        }

        $arc = self::serializeArc($group, $author, $judge);

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_COMPOSED => true,
            self::FIELD_BASIS => 'organ_dependency_neighbors',
            self::FIELD_ARCS => [$arc],
            self::FIELD_ARC_COUNT => 1,
            // Observe-only ESP-09 advisory (composed_obra trigger); never vetoes compose.
            self::FIELD_CHALLENGER_ADVISORY => Esp09IndependentChallengerService::evaluate([
                self::FIELD_AUTHOR_ENGINE_ID => $author,
                self::FIELD_CHALLENGER_ENGINE_ID => $judge,
                'decision_kind' => 'composed_obra',
            ]),
            self::FIELD_SOURCE => [
                self::FIELD_ARC_BUYS_GATE_WHOLESALE => false,
                self::FIELD_AUTO_MERGE => false,
                self::FIELD_EACH_TASK_REQUIRES_ARCHITECT_AND_SEED_GATE => true,
                self::FIELD_PROVIDER_CALLS_MADE => false,
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @return list<array<string,mixed>>
     */
    private static function groundedCandidates(array $candidates): array
    {
        $out = [];
        foreach ($candidates as $index => $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $target = ltrim(AiValueNormalizer::trimmedStringOrNull($candidate[self::FIELD_TARGET_PATH] ?? null) ?? '', '/');
            $summary = AiValueNormalizer::trimmedStringOrNull($candidate['summary'] ?? null) ?? '';
            if ($target === '' || $summary === '') {
                continue;
            }
            $organ = self::organClass($candidate, $target);
            if ($organ === '') {
                continue;
            }
            $out[] = [
                'id' => AiValueNormalizer::trimmedStringOrNull($candidate['id'] ?? null) ?? 'cand-'.$index,
                self::FIELD_TARGET_PATH => $target,
                'summary' => $summary,
                self::FIELD_ORGAN_CLASS => $organ,
                self::FIELD_LEVERAGE => AiValueNormalizer::finiteFloatOrNull($candidate[self::FIELD_LEVERAGE] ?? null) ?? 0.0,
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string,mixed>>  $grounded
     * @param  list<array<string,mixed>>  $clusterLeads
     * @return list<array<string,mixed>>|null
     */
    private static function bestNeighborGroup(array $grounded, AtlasBrainOrganDependencyGraph $graph, array $clusterLeads): ?array
    {
        $seedPaths = self::clusterSeedPaths($clusterLeads);
        $byOrgan = [];
        foreach ($grounded as $candidate) {
            $byOrgan[$candidate[self::FIELD_ORGAN_CLASS]][] = $candidate;
        }

        $best = null;
        $bestScore = -1.0;
        foreach ($grounded as $anchor) {
            $neighbors = self::neighborSet($anchor[self::FIELD_ORGAN_CLASS], $graph);
            $group = [$anchor];
            foreach ($grounded as $other) {
                if ($other['id'] === $anchor['id']) {
                    continue;
                }
                if (in_array($other[self::FIELD_ORGAN_CLASS], $neighbors, true) || $other[self::FIELD_ORGAN_CLASS] === $anchor[self::FIELD_ORGAN_CLASS]) {
                    $group[] = $other;
                }
            }
            $group = self::dedupeById($group);
            if (count($group) < self::MIN_NEIGHBOR_CANDIDATES) {
                continue;
            }
            if ($seedPaths !== [] && ! self::groupTouchesSeed($group, $seedPaths)) {
                continue;
            }
            $score = self::groupLeverageScore($group);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = array_slice(self::orderTasks($group), 0, max(self::MIN_NEIGHBOR_CANDIDATES, count($group)));
            }
        }

        if ($best !== null) {
            return $best;
        }

        if ($seedPaths === []) {
            return null;
        }

        $seedGroup = [];
        foreach ($grounded as $candidate) {
            if (in_array($candidate[self::FIELD_TARGET_PATH], $seedPaths, true)) {
                $seedGroup[] = $candidate;
            }
        }
        $seedGroup = self::dedupeById($seedGroup);

        return count($seedGroup) >= self::MIN_NEIGHBOR_CANDIDATES ? self::orderTasks($seedGroup) : null;
    }

    /**
     * @param  list<array<string,mixed>>  $group
     * @return list<array<string,mixed>>
     */
    private static function orderTasks(array $group): array
    {
        usort($group, static function (array $a, array $b): int {
            $byLeverage = ($b[self::FIELD_LEVERAGE] ?? 0.0) <=> ($a[self::FIELD_LEVERAGE] ?? 0.0);

            return $byLeverage !== 0 ? $byLeverage : strcmp(AiValueNormalizer::trimmedScalarStringOrNull($a[self::FIELD_TARGET_PATH] ?? null) ?? '', AiValueNormalizer::trimmedScalarStringOrNull($b[self::FIELD_TARGET_PATH] ?? null) ?? '');
        });
        $ordered = [];
        foreach (array_values($group) as $index => $task) {
            $ordered[] = array_merge($task, [self::FIELD_ORDER => $index + 1]);
        }

        return $ordered;
    }

    /**
     * @param  list<array<string,mixed>>  $group
     */
    private static function serializeArc(array $group, string $author, string $judge): array
    {
        $targets = array_map(static fn (array $task): string => AiValueNormalizer::trimmedScalarStringOrNull($task[self::FIELD_TARGET_PATH] ?? null) ?? '', $group);
        sort($targets);
        $arcSeed = implode('|', $targets);
        $arcId = 'arc_'.substr(hash('sha256', $arcSeed), 0, 16);
        $obraId = 'obra_'.substr(hash('sha256', 'obra:'.$arcSeed), 0, 16);

        $tasks = [];
        foreach ($group as $task) {
            $tasks[] = [
                'task_id' => 'task_'.substr(hash('sha256', $arcId.':'.($task[self::FIELD_TARGET_PATH] ?? '')), 0, 12),
                self::FIELD_ORDER => (int) (AiValueNormalizer::finiteFloatOrNull($task[self::FIELD_ORDER] ?? null) ?? 0),
                self::FIELD_TARGET_PATH => AiValueNormalizer::trimmedScalarStringOrNull($task[self::FIELD_TARGET_PATH] ?? null) ?? '',
                'objective' => AiValueNormalizer::trimmedScalarStringOrNull($task['summary'] ?? null) ?? '',
                'individual_gate_required' => true,
                self::FIELD_ARCHITECT_PHASE_GATE => true,
                'seed_gate' => true,
            ];
        }

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_ARC_ID => $arcId,
            'obra_id' => $obraId,
            'thesis' => [
                'claim' => 'Wiring the neighbor organs '.implode(', ', array_map(static fn (array $t): string => basename(AiValueNormalizer::trimmedScalarStringOrNull($t[self::FIELD_TARGET_PATH] ?? null) ?? '', '.php'), $tasks)).' materially increases end-to-end leverage.',
                'falsified_when' => 'No task in the arc reaches proven_real landing within the arc TTL.',
                self::FIELD_AUTHOR_ENGINE_ID => $author,
            ],
            'tasks' => $tasks,
            'completion_criterion' => [
                'executable' => 'Every ordered arc task lands with proven_real outcome; partial completion leaves arc open.',
                self::FIELD_CERTIFIER_ENGINE_ID => $judge,
            ],
            'kill_gate' => [
                'consecutive_failures_k' => self::KILL_GATE_CONSECUTIVE_FAILURES,
                'consecutive_failures' => 0,
                self::FIELD_ACTION_ON_TRIGGER => 'archive_with_receipt',
            ],
            self::FIELD_SOURCE => [
                'neighbor_basis' => 'organ_dependency_graph',
                self::FIELD_AUTHOR_NEQ_JUDGE => $author !== $judge,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private static function neighborSet(string $organClass, AtlasBrainOrganDependencyGraph $graph): array
    {
        $neighbors = array_merge(
            $graph->dependsOn($organClass),
            $graph->consumersOf($organClass),
        );
        foreach ($graph->dependsOn($organClass) as $dep) {
            $neighbors = array_merge($neighbors, $graph->consumersOf($dep));
        }

        return array_values(array_unique($neighbors));
    }

    /**
     * @param  list<array<string,mixed>>  $clusterLeads
     * @return list<string>
     */
    private static function clusterSeedPaths(array $clusterLeads): array
    {
        $paths = [];
        foreach ($clusterLeads as $lead) {
            if (! is_array($lead)) {
                continue;
            }
            $block = is_array($lead['obra_cluster_candidate'] ?? null) ? $lead['obra_cluster_candidate'] : $lead;
            foreach (AiValueNormalizer::arrayOrEmpty($block['allowed_files'] ?? $block['member_paths'] ?? null) as $path) {
                $normalized = ltrim(str_replace('\\', '/', AiValueNormalizer::trimmedStringOrNull($path) ?? ''), '/');
                if ($normalized !== '') {
                    $paths[] = $normalized;
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param  list<array<string,mixed>>  $group
     * @param  list<string>  $seedPaths
     */
    private static function groupTouchesSeed(array $group, array $seedPaths): bool
    {
        foreach ($group as $candidate) {
            if (in_array((AiValueNormalizer::trimmedStringOrNull($candidate[self::FIELD_TARGET_PATH] ?? null) ?? ''), $seedPaths, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string,mixed>>  $group
     */
    private static function groupLeverageScore(array $group): float
    {
        $score = 0.0;
        foreach ($group as $candidate) {
            $score += AiValueNormalizer::finiteFloatOrNull($candidate[self::FIELD_LEVERAGE] ?? null) ?? 0.0;
        }

        return $score;
    }

    /**
     * @param  list<array<string,mixed>>  $group
     * @return list<array<string,mixed>>
     */
    private static function dedupeById(array $group): array
    {
        $seen = [];
        $out = [];
        foreach ($group as $candidate) {
            $id = (AiValueNormalizer::trimmedStringOrNull($candidate['id'] ?? null) ?? '');
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = $candidate;
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $candidate
     */
    private static function organClass(array $candidate, string $targetPath): string
    {
        $fqcn = AiValueNormalizer::trimmedStringOrNull($candidate['target_fqcn'] ?? $candidate['fqcn'] ?? null) ?? '';
        if ($fqcn !== '') {
            return ltrim(AiValueNormalizer::trimmedStringOrNull($fqcn) ?? '', '\\');
        }

        $base = basename($targetPath, '.php');
        if ($base === '') {
            return '';
        }

        $parts = explode('/', str_replace('\\', '/', $targetPath));
        if (count($parts) >= 2 && $parts[0] === 'app') {
            $namespace = 'App\\'.implode('\\', array_slice($parts, 1, -1)).'\\'.$base;

            return str_replace('/', '\\', $namespace);
        }

        return 'App\\'.$base;
    }

    /**
     * @return array<string,mixed>
     */
    private static function emptyResult(string $basis): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_COMPOSED => false,
            self::FIELD_BASIS => $basis,
            self::FIELD_ARCS => [],
            self::FIELD_ARC_COUNT => 0,
            self::FIELD_SOURCE => [
                self::FIELD_ARC_BUYS_GATE_WHOLESALE => false,
                self::FIELD_AUTO_MERGE => false,
                self::FIELD_EACH_TASK_REQUIRES_ARCHITECT_AND_SEED_GATE => true,
                self::FIELD_PROVIDER_CALLS_MADE => false,
            ],
        ];
    }
}
