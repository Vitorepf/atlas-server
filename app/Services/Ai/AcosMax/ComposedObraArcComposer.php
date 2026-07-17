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

    public const STATUS_FLAG_DISABLED = 'flag_disabled';

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

        $author = AiValueNormalizer::trimmedStringOrNull($context['author_engine_id'] ?? null) ?? self::DEFAULT_AUTHOR_ENGINE_ID;
        $judge = AiValueNormalizer::trimmedStringOrNull($context['judge_engine_id'] ?? null) ?? self::DEFAULT_JUDGE_ENGINE_ID;
        if ($author === '' || $judge === '' || $author === $judge) {
            return self::emptyResult('author_judge_invariant_violation');
        }

        $graph = $context['graph'] ?? new AtlasBrainOrganDependencyGraph;
        if (! $graph instanceof AtlasBrainOrganDependencyGraph) {
            return self::emptyResult('invalid_dependency_graph');
        }

        $grounded = self::groundedCandidates($candidates);
        if (count($grounded) < self::MIN_NEIGHBOR_CANDIDATES) {
            return self::emptyResult('insufficient_grounded_candidates');
        }

        $group = self::bestNeighborGroup($grounded, $graph, $clusterLeads);
        if ($group === null) {
            return self::emptyResult('no_neighbor_cluster');
        }

        $arc = self::serializeArc($group, $author, $judge);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'composed' => true,
            'basis' => 'organ_dependency_neighbors',
            'arcs' => [$arc],
            'arc_count' => 1,
            // Observe-only ESP-09 advisory (composed_obra trigger); never vetoes compose.
            'challenger_advisory' => Esp09IndependentChallengerService::evaluate([
                'author_engine_id' => $author,
                'challenger_engine_id' => $judge,
                'decision_kind' => 'composed_obra',
            ]),
            'source' => [
                'arc_buys_gate_wholesale' => false,
                'auto_merge' => false,
                'each_task_requires_architect_and_seed_gate' => true,
                'provider_calls_made' => false,
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
            $target = ltrim(AiValueNormalizer::trimmedStringOrNull($candidate['target_path'] ?? null) ?? '', '/');
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
                'target_path' => $target,
                'summary' => $summary,
                'organ_class' => $organ,
                'leverage' => AiValueNormalizer::finiteFloatOrNull($candidate['leverage'] ?? null) ?? 0.0,
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
            $byOrgan[$candidate['organ_class']][] = $candidate;
        }

        $best = null;
        $bestScore = -1.0;
        foreach ($grounded as $anchor) {
            $neighbors = self::neighborSet($anchor['organ_class'], $graph);
            $group = [$anchor];
            foreach ($grounded as $other) {
                if ($other['id'] === $anchor['id']) {
                    continue;
                }
                if (in_array($other['organ_class'], $neighbors, true) || $other['organ_class'] === $anchor['organ_class']) {
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
            if (in_array($candidate['target_path'], $seedPaths, true)) {
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
            $byLeverage = ($b['leverage'] ?? 0.0) <=> ($a['leverage'] ?? 0.0);

            return $byLeverage !== 0 ? $byLeverage : strcmp(AiValueNormalizer::trimmedScalarStringOrNull($a['target_path'] ?? null) ?? '', AiValueNormalizer::trimmedScalarStringOrNull($b['target_path'] ?? null) ?? '');
        });
        $ordered = [];
        foreach (array_values($group) as $index => $task) {
            $ordered[] = array_merge($task, ['order' => $index + 1]);
        }

        return $ordered;
    }

    /**
     * @param  list<array<string,mixed>>  $group
     */
    private static function serializeArc(array $group, string $author, string $judge): array
    {
        $targets = array_map(static fn (array $task): string => AiValueNormalizer::trimmedScalarStringOrNull($task['target_path'] ?? null) ?? '', $group);
        sort($targets);
        $arcSeed = implode('|', $targets);
        $arcId = 'arc_'.substr(hash('sha256', $arcSeed), 0, 16);
        $obraId = 'obra_'.substr(hash('sha256', 'obra:'.$arcSeed), 0, 16);

        $tasks = [];
        foreach ($group as $task) {
            $tasks[] = [
                'task_id' => 'task_'.substr(hash('sha256', $arcId.':'.($task['target_path'] ?? '')), 0, 12),
                'order' => (int) (AiValueNormalizer::finiteFloatOrNull($task['order'] ?? null) ?? 0),
                'target_path' => AiValueNormalizer::trimmedScalarStringOrNull($task['target_path'] ?? null) ?? '',
                'objective' => AiValueNormalizer::trimmedScalarStringOrNull($task['summary'] ?? null) ?? '',
                'individual_gate_required' => true,
                'architect_phase_gate' => true,
                'seed_gate' => true,
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'arc_id' => $arcId,
            'obra_id' => $obraId,
            'thesis' => [
                'claim' => 'Wiring the neighbor organs '.implode(', ', array_map(static fn (array $t): string => basename(AiValueNormalizer::trimmedScalarStringOrNull($t['target_path'] ?? null) ?? '', '.php'), $tasks)).' materially increases end-to-end leverage.',
                'falsified_when' => 'No task in the arc reaches proven_real landing within the arc TTL.',
                'author_engine_id' => $author,
            ],
            'tasks' => $tasks,
            'completion_criterion' => [
                'executable' => 'Every ordered arc task lands with proven_real outcome; partial completion leaves arc open.',
                'certifier_engine_id' => $judge,
            ],
            'kill_gate' => [
                'consecutive_failures_k' => self::KILL_GATE_CONSECUTIVE_FAILURES,
                'consecutive_failures' => 0,
                'action_on_trigger' => 'archive_with_receipt',
            ],
            'source' => [
                'neighbor_basis' => 'organ_dependency_graph',
                'author_neq_judge' => $author !== $judge,
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
            if (in_array((AiValueNormalizer::trimmedStringOrNull($candidate['target_path'] ?? null) ?? ''), $seedPaths, true)) {
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
            $score += AiValueNormalizer::finiteFloatOrNull($candidate['leverage'] ?? null) ?? 0.0;
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
            'schema_version' => self::SCHEMA_VERSION,
            'composed' => false,
            'basis' => $basis,
            'arcs' => [],
            'arc_count' => 0,
            'source' => [
                'arc_buys_gate_wholesale' => false,
                'auto_merge' => false,
                'each_task_requires_architect_and_seed_gate' => true,
                'provider_calls_made' => false,
            ],
        ];
    }
}
