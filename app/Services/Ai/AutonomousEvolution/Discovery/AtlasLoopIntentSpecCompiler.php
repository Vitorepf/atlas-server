<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

/**
 * NEXT-LEVER 4 — the INTENT→SPEC COMPILER (the autonomy capstone).
 *
 * For the loop to do BIG, complex work autonomously from a human's natural-language goal ("make the export
 * system handle 10x load"), it must turn that sentence into a STRUCTURED, falsifiable contract: a summary,
 * a checklist of acceptance CRITERIA, the implementation surface, and a decomposition hint. Today the
 * operator hand-writes the frozen test; this compiler is the seam that lets the human just state the goal
 * and the loop build the rigorous contract — and that contract is exactly what the other levers consume:
 * the criteria feed the COMPLETENESS gate (Next-Lever 1) + spec amplification (Lever 5), and the
 * decomposition hint feeds the PLANNER (Lever 1). It is the "linguagem humana natural → entrega completa"
 * thesis made concrete.
 *
 * ITERATE-TO-READY (like the planner): generate a spec, assess its quality (criteria present, required,
 * concrete, uniquely-identified), and on a weak spec feed the gaps back and regenerate — up to a budget.
 * Only an impeccable contract is returned ready; a vague spec is refused with its gaps. The provider
 * generation is an injected callable so the compiler is deterministically testable without a provider.
 */
final class AtlasLoopIntentSpecCompiler
{
    private const MIN_CRITERION_CHARS = 15;

    /**
     * @param  callable(string, list<string>): array<string,mixed>  $generateSpec  (goal, priorGaps) => raw spec
     *                                                                             {summary, acceptance_criteria:[{id, description, required?}], suggested_files?, decomposition_hint?}
     * @return array{ready:bool, spec:?array<string,mixed>, attempts:int, gaps:list<string>}
     */
    public function compile(string $goal, callable $generateSpec, int $maxAttempts = 3): array
    {
        $maxAttempts = max(1, $maxAttempts);
        $gaps = [];

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $spec = $this->normalize($generateSpec($goal, $gaps));
            $gaps = $this->assess($spec);
            if ($gaps === []) {
                return ['ready' => true, 'spec' => $spec, 'attempts' => $attempt, 'gaps' => []];
            }
        }

        return ['ready' => false, 'spec' => null, 'attempts' => $maxAttempts, 'gaps' => $gaps];
    }

    /**
     * @param  array<string,mixed>  $spec
     * @return list<string> gaps; empty means an impeccable, falsifiable contract
     */
    private function assess(array $spec): array
    {
        $gaps = [];
        if (trim((string) ($spec['summary'] ?? '')) === '') {
            $gaps[] = 'summary_missing';
        }
        $criteria = is_array($spec['acceptance_criteria'] ?? null) ? $spec['acceptance_criteria'] : [];
        if ($criteria === []) {
            $gaps[] = 'no_acceptance_criteria';

            return $gaps;
        }

        $ids = [];
        $hasRequired = false;
        foreach ($criteria as $i => $c) {
            $c = is_array($c) ? $c : [];
            $id = trim((string) ($c['id'] ?? ''));
            $desc = trim((string) ($c['description'] ?? ''));
            $tag = $id !== '' ? $id : ('#'.$i);
            if ($id === '') {
                $gaps[] = 'criterion_'.$tag.':id_missing';
            } elseif (isset($ids[$id])) {
                $gaps[] = 'criterion_'.$tag.':duplicate_id';
            }
            $ids[$id] = true;
            if (mb_strlen($desc) < self::MIN_CRITERION_CHARS) {
                $gaps[] = 'criterion_'.$tag.':description_too_vague';
            }
            if ((bool) ($c['required'] ?? true)) {
                $hasRequired = true;
            }
        }
        if (! $hasRequired) {
            $gaps[] = 'no_required_criterion'; // a spec where everything is optional pins nothing
        }

        return array_values(array_unique($gaps));
    }

    /**
     * @return array<string,mixed>
     */
    private function normalize(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $criteria = array_values(array_filter(
            is_array($raw['acceptance_criteria'] ?? null) ? $raw['acceptance_criteria'] : [],
            'is_array',
        ));

        return [
            'summary' => trim((string) ($raw['summary'] ?? '')),
            'acceptance_criteria' => $criteria,
            'suggested_files' => array_values(array_filter(
                is_array($raw['suggested_files'] ?? null) ? $raw['suggested_files'] : [],
                'is_string',
            )),
            'decomposition_hint' => trim((string) ($raw['decomposition_hint'] ?? '')),
        ];
    }
}
