<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

/**
 * ACDE lever U4 — the cost-free deterministic vagueness pre-screener.
 *
 * A goal with NO concrete anchor ("improve robustness", "make it cleaner") cannot be planned correctly by a
 * weak model — it has nothing to ground against. This pure function flags such goals BEFORE the expensive
 * intent->spec->DAG planner spends budget on them, so the loop abstains/short-circuits instead of grinding a
 * vague directive. Deterministic, provider-free, no DB — never an LLM judge.
 *
 * CONSERVATIVE by design (the false-abstain risk the triage flagged): a goal is "vague" ONLY when it carries no
 * concrete anchor at all — a path-like token, a quoted identifier, a `::`/`->` member reference, or a CamelCase
 * symbol. A terse-but-concrete goal ("simplify Foo::bar") is NOT vague. The extra reasons (too_short,
 * unbounded_qualifier_without_anchor) are colour, never the sole trigger.
 */
final class AtlasLoopVaguenessPreScreener
{
    /** @var list<string> qualifiers that promise an improvement without naming a concrete target */
    private const UNBOUNDED_QUALIFIERS = [
        'better', 'faster', 'robust', 'robustness', 'clean', 'cleaner', 'improve', 'improved', 'improvement',
        'optimize', 'optimise', 'optimized', 'nicer', 'simpler', 'enhance', 'polish', 'refine', 'modernize', 'tidy',
    ];

    private const ANCHOR_PATTERN = '/(?:[A-Za-z0-9_\/]+\.php\b|\b[A-Z][a-z0-9]+[A-Z][A-Za-z0-9]*\b|::|->|[`\'"][A-Za-z_][A-Za-z0-9_]{2,}[`\'"])/';

    private const UNBOUNDED_QUALIFIER_PATTERN = '/\b(?:better|faster|robust|robustness|clean|cleaner|improve|improved|improvement|optimize|optimise|optimized|nicer|simpler|enhance|polish|refine|modernize|tidy)\b/';

    private const MIN_WORDS = 4;

    /**
     * @return array{vague:bool, score:float, reasons:list<string>}
     */
    public function screen(string $goal): array
    {
        $g = trim($goal);
        $reasons = [];

        $words = array_values(array_filter(preg_split('/\s+/', $g) ?: []));
        if (count($words) < self::MIN_WORDS) {
            $reasons[] = 'too_short';
        }

        // A concrete anchor the planner can ground against: a path/.php token, a CamelCase symbol, a member
        // reference (::/->), or a quoted identifier. ANY of these means the goal is NOT vague.
        $hasAnchor = preg_match(self::ANCHOR_PATTERN, $g) === 1;
        if (! $hasAnchor) {
            $reasons[] = 'no_concrete_anchor';
        }

        if (! $hasAnchor) {
            $lower = mb_strtolower($g);
            if (preg_match(self::UNBOUNDED_QUALIFIER_PATTERN, $lower) === 1) {
                $reasons[] = 'unbounded_qualifier_without_anchor';
            }
        }

        // The SOLE trigger is the absence of a concrete anchor (conservative — never abstain on a concrete goal).
        $vague = ! $hasAnchor;

        return [
            'vague' => $vague,
            'score' => $vague ? round(min(1.0, count($reasons) / 3.0), 4) : 0.0,
            'reasons' => $reasons,
        ];
    }
}
