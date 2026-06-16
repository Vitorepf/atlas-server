<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

/**
 * ACDE Leap 8 (greenfield ceiling) — the REUSABLE archetype boundary-rule library.
 *
 * The per-goal boundary-oracle (Leap 2) names exact files a human froze for ONE objective; it does nothing
 * for a novel greenfield goal nobody froze. This imports the single-target moat into greenfield via REUSE:
 * a human freezes a small library of decomposition ARCHETYPES — each a deterministic token CLASSIFIER plus
 * the structural INVARIANTS every obra of that shape must honour (min node count, a mandatory create-class
 * file matching a suffix, a minimum distinct-target count). A greenfield goal that classifies into an
 * archetype is then held to its invariants for ANY novel objective in that family — no per-goal fixture.
 *
 * Ungameable + honest: the classifier + invariants are authored by a HUMAN (the model cannot relax them),
 * the check is a deterministic structural test, and a mis-applied predicate only ADDS cheap REPLAN pressure
 * (never a silent pass). RESIDUAL (stated): a goal matching NO archetype degrades to structural-only exactly
 * as today; within a matched archetype the split's SEMANTIC correctness beyond the named invariants is
 * unproven. It raises the greenfield floor; it cannot ORIGINATE the correct decomposition.
 *
 * Library: <dir>/*.json, each {id, match_all?:[token], match_any?:[token], min_nodes?, required_create_suffixes?:
 * [suffix], min_distinct_targets?}. Ships EMPTY (operators add archetypes). Pure aside from the directory read.
 */
final class AtlasLoopDecompositionArchetypeOracle
{
    public function __construct(private readonly ?string $dir = null) {}

    /**
     * Classify the goal into the FIRST matching archetype (filename-sorted for determinism), or null. A goal
     * matches iff ALL match_all tokens are present AND (match_any empty OR ANY match_any token is present),
     * case-insensitive substring match on the normalized objective.
     *
     * @return array{id:string, min_nodes:int, required_create_suffixes:list<string>, min_distinct_targets:int}|null
     */
    public function classify(string $goal): ?array
    {
        $goal = mb_strtolower(trim($goal));
        if ($goal === '') {
            return null;
        }
        foreach ($this->archetypes() as $arch) {
            $allOk = true;
            foreach ($arch['match_all'] as $t) {
                if (! str_contains($goal, $t)) {
                    $allOk = false;
                    break;
                }
            }
            if (! $allOk) {
                continue;
            }
            $anyOk = $arch['match_any'] === [];
            foreach ($arch['match_any'] as $t) {
                if (str_contains($goal, $t)) {
                    $anyOk = true;
                    break;
                }
            }
            if ($anyOk) {
                return [
                    'id' => $arch['id'],
                    'min_nodes' => $arch['min_nodes'],
                    'required_create_suffixes' => $arch['required_create_suffixes'],
                    'min_distinct_targets' => $arch['min_distinct_targets'],
                ];
            }
        }

        return null;
    }

    /**
     * The archetype-invariant violations for a plan given its goal, or [] when no archetype matches (degrade
     * to structural-only — no false-reject).
     *
     * @param  array<string,mixed>  $plan
     * @return list<string>
     */
    public function violations(array $plan, string $goal): array
    {
        $arch = $this->classify($goal);
        if ($arch === null) {
            return [];
        }
        $nodes = array_values(is_array($plan['nodes'] ?? null) ? (array) $plan['nodes'] : []);

        $targets = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $t = ltrim(trim((string) ($node['target_area'] ?? '')), '/');
            if ($t !== '') {
                $targets[$t] = true;
            }
            foreach ((array) ($node['allowed_files'] ?? []) as $f) {
                if (is_string($f) && trim($f) !== '') {
                    $targets[ltrim(trim($f), '/')] = true;
                }
            }
        }

        $gaps = [];
        $id = $arch['id'];

        if (count($nodes) < $arch['min_nodes']) {
            $gaps[] = 'decomposition_archetype_violation:'.$id.':min_nodes:'.count($nodes).'<'.$arch['min_nodes'];
        }
        if (count($targets) < $arch['min_distinct_targets']) {
            $gaps[] = 'decomposition_archetype_violation:'.$id.':min_distinct_targets:'.count($targets).'<'.$arch['min_distinct_targets'];
        }
        if ($arch['required_create_suffixes'] !== []) {
            $hasCreate = false;
            foreach (array_keys($targets) as $file) {
                foreach ($arch['required_create_suffixes'] as $suffix) {
                    if (str_ends_with(mb_strtolower($file), mb_strtolower($suffix))) {
                        $hasCreate = true;
                        break 2;
                    }
                }
            }
            if (! $hasCreate) {
                $gaps[] = 'decomposition_archetype_violation:'.$id.':missing_create_class:'.implode('|', $arch['required_create_suffixes']);
            }
        }

        return $gaps;
    }

    /**
     * The normalized archetype library, filename-sorted for deterministic classification.
     *
     * @return list<array{id:string, match_all:list<string>, match_any:list<string>, min_nodes:int, required_create_suffixes:list<string>, min_distinct_targets:int}>
     */
    private function archetypes(): array
    {
        $dir = $this->dir();
        if (! is_dir($dir)) {
            return [];
        }
        $files = glob(rtrim($dir, '/').'/*.json') ?: [];
        sort($files);

        $out = [];
        foreach ($files as $path) {
            $raw = @file_get_contents($path);
            if (! is_string($raw) || trim($raw) === '') {
                continue;
            }
            $d = json_decode($raw, true);
            if (! is_array($d)) {
                continue;
            }
            $id = trim((string) ($d['id'] ?? basename($path, '.json')));
            $matchAll = $this->tokens($d['match_all'] ?? []);
            $matchAny = $this->tokens($d['match_any'] ?? []);
            if ($id === '' || ($matchAll === [] && $matchAny === [])) {
                continue; // an archetype with no classifier would match everything — refuse to load it
            }
            $out[] = [
                'id' => $id,
                'match_all' => $matchAll,
                'match_any' => $matchAny,
                'min_nodes' => max(0, (int) ($d['min_nodes'] ?? 0)),
                'required_create_suffixes' => $this->strList($d['required_create_suffixes'] ?? []),
                'min_distinct_targets' => max(0, (int) ($d['min_distinct_targets'] ?? 0)),
            ];
        }

        return $out;
    }

    private function dir(): string
    {
        if ($this->dir !== null && trim($this->dir) !== '') {
            return $this->dir;
        }
        try {
            $app = function_exists('app') ? app() : null;
            if ($app !== null && $app->bound('config')) {
                $configured = config('atlas.loop.decomposition_archetype_dir');
                if (is_string($configured) && trim($configured) !== '') {
                    return $configured;
                }

                return base_path('frozen/obra-archetypes');
            }
        } catch (\Throwable) {
            // fall through
        }

        return 'frozen/obra-archetypes';
    }

    /** @return list<string> lowercased classifier tokens */
    private function tokens(mixed $list): array
    {
        $out = [];
        foreach ((array) $list as $v) {
            if (is_string($v) && trim($v) !== '') {
                $out[mb_strtolower(trim($v))] = true;
            }
        }

        return array_keys($out);
    }

    /** @return list<string> */
    private function strList(mixed $list): array
    {
        $out = [];
        foreach ((array) $list as $v) {
            if (is_string($v) && trim($v) !== '') {
                $out[trim($v)] = true;
            }
        }

        return array_keys($out);
    }
}
