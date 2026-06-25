<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskQuality;

/**
 * Pure detector. Looks for HIDDEN poison patterns in a task packet that the upstream blocking_deficiencies
 * pipeline does NOT already catch. Emits a FACT per detected pattern; never mutates the packet or queue.
 *
 * Patterns:
 *   - removed_target_referenced_outside_acceptance : the objective text references a target path that was
 *     REMOVED from allowed_files (the classic "scope_repair" leftover where the brief still asks for it).
 *   - contradictory_acceptance                     : acceptance criteria mutually exclusive (e.g. one
 *     requires X and another forbids X, OR criteria require unavailable_dependency).
 *   - unavailable_dependency                       : required_evidence_kinds names a tool/class that the
 *     packet quality_facts marks unavailable=true.
 *   - duplicate_canonical_symbol_hint              : allowed_files contains two files that both target the
 *     SAME canonical class name (heuristic: same basename).
 *   - ambiguous_worker_instruction                 : objective contains both `do X` and `do not X` patterns
 *     or known-ambiguous markers (TBD, FIXME-ME, "if applicable").
 *   - permanent_autonomy_dependency_wording        : objective requires "operator approval", "human
 *     intervention", or "external provider" as a STEADY-STATE step (poisons autonomy).
 *
 * Output: {schema_version, found_patterns:list<{pattern_id, evidence}>, clean:bool}
 */
final class AtlasTaskHiddenPoisonDetector
{
    public const SCHEMA = 'atlas.task_quality.hidden_poison.v1';

    public const PATTERN_REMOVED_TARGET = 'removed_target_referenced_outside_acceptance';

    public const PATTERN_CONTRADICTORY_ACCEPTANCE = 'contradictory_acceptance';

    public const PATTERN_UNAVAILABLE_DEPENDENCY = 'unavailable_dependency';

    public const PATTERN_DUPLICATE_CANONICAL_SYMBOL = 'duplicate_canonical_symbol_hint';

    public const PATTERN_AMBIGUOUS_INSTRUCTION = 'ambiguous_worker_instruction';

    public const PATTERN_PERMANENT_AUTONOMY_DEP = 'permanent_autonomy_dependency_wording';

    private const AUTONOMY_DEP_NEEDLES = [
        'operator approval',
        'operator must',
        'human intervention',
        'human approves',
        'external provider call',
        'requires provider',
        'manual review by operator',
    ];

    private const AMBIGUOUS_NEEDLES = ['TBD', 'FIXME-ME', 'if applicable', 'maybe', '?'];

    /**
     * @param  array<string,mixed>  $packet  {objective, acceptance_criteria, required_evidence_kinds,
     *                                         allowed_files, forbidden_files, quality_facts:{
     *                                             removed_targets?:list<string>, unavailable_deps?:list<string>,
     *                                             contradiction_pairs?:list<{a:string,b:string}>,
     *                                         }}
     * @return array<string,mixed>
     */
    public function detect(array $packet): array
    {
        $found = [];
        $objective = (string) ($packet['objective'] ?? '');
        $acceptance = array_values((array) ($packet['acceptance_criteria'] ?? []));
        $allowed = array_values((array) ($packet['allowed_files'] ?? []));
        $required = array_values((array) ($packet['required_evidence_kinds'] ?? []));
        $quality = (array) ($packet['quality_facts'] ?? []);

        $removedTargets = array_values((array) ($quality['removed_targets'] ?? []));
        foreach ($removedTargets as $t) {
            $t = (string) $t;
            if ($t === '') {
                continue;
            }
            if (! in_array($t, $allowed, true) && str_contains($objective, $t)) {
                $found[] = [
                    'pattern_id' => self::PATTERN_REMOVED_TARGET,
                    'evidence' => ['target' => $t, 'reason' => 'in_objective_text_but_outside_allowed_files'],
                ];
            }
        }

        $contradictions = array_values((array) ($quality['contradiction_pairs'] ?? []));
        foreach ($contradictions as $pair) {
            if (! is_array($pair)) {
                continue;
            }
            $a = (string) ($pair['a'] ?? '');
            $b = (string) ($pair['b'] ?? '');
            if ($a !== '' && $b !== '') {
                $found[] = [
                    'pattern_id' => self::PATTERN_CONTRADICTORY_ACCEPTANCE,
                    'evidence' => ['a' => $a, 'b' => $b],
                ];
            }
        }

        $unavailable = array_values((array) ($quality['unavailable_deps'] ?? []));
        foreach (array_intersect($required, $unavailable) as $u) {
            $found[] = [
                'pattern_id' => self::PATTERN_UNAVAILABLE_DEPENDENCY,
                'evidence' => ['dependency' => $u],
            ];
        }

        $basenames = [];
        foreach ($allowed as $file) {
            $base = basename((string) $file);
            $basenames[$base] = ($basenames[$base] ?? 0) + 1;
        }
        foreach ($basenames as $base => $count) {
            if ($count >= 2) {
                $found[] = [
                    'pattern_id' => self::PATTERN_DUPLICATE_CANONICAL_SYMBOL,
                    'evidence' => ['basename' => $base, 'count' => $count],
                ];
            }
        }

        foreach (self::AMBIGUOUS_NEEDLES as $n) {
            if ($n !== '' && str_contains($objective, $n)) {
                $found[] = [
                    'pattern_id' => self::PATTERN_AMBIGUOUS_INSTRUCTION,
                    'evidence' => ['marker' => $n],
                ];
                break; // one ambiguous marker is enough to flag the packet
            }
        }
        if (preg_match('/\bdo not\b.*\bdo\b|\bdo\b.*\bdo not\b/i', $objective)) {
            $found[] = [
                'pattern_id' => self::PATTERN_AMBIGUOUS_INSTRUCTION,
                'evidence' => ['marker' => 'do_and_do_not_both_in_objective'],
            ];
        }

        $objLower = strtolower($objective);
        foreach (self::AUTONOMY_DEP_NEEDLES as $needle) {
            if (str_contains($objLower, $needle)) {
                $found[] = [
                    'pattern_id' => self::PATTERN_PERMANENT_AUTONOMY_DEP,
                    'evidence' => ['phrase' => $needle],
                ];
                break;
            }
        }

        return [
            'schema_version' => self::SCHEMA,
            'found_patterns' => $found,
            'clean' => $found === [],
        ];
    }
}
