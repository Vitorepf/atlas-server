<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Spec;

/**
 * Engineering Kernel mechanism: the DETERMINISTIC, provider-free PRODUCER of ambiguity findings.
 *
 * Owns: emitting the findings that today's Atlas has a consumer for but no producer of — so the
 * ambiguity gate stops being a channel nothing writes (a permanent silent pass). Pure functions over
 * the draft + intent text: no provider, no I/O, wiper-safe.
 * Must never own: deciding the verdict (the floor) or asking the operator (a thin adapter/queue).
 *
 * Findings (each a stable string the floor routes to HOLD unless the operator answered it):
 *   - verb_collision:<verb>   a recognized verb that appears only as a SUBSTRING, never as a whole
 *                             word, in the intent — the exact IntentActionExtractor collision vector.
 *   - vacuous_criterion:<id>  a behavioral criterion too thin to specify anything (short description
 *                             or missing verification_ref).
 */
final class SpecAmbiguityProducer
{
    private const MIN_DESCRIPTION_CHARS = 15;

    /**
     * @return list<string>
     */
    public static function findings(SpecDraft $draft, IntentEnvelope $intent): array
    {
        $findings = [];

        // zero-anchor vagueness: no path/*.php, no CamelCase symbol, no ::/-> member, no quoted id
        if (! self::hasConcreteAnchor($intent->rawGoal) && ! self::hasConcreteAnchor($draft->intentText)) {
            $findings[] = 'goal_has_no_concrete_anchor';
        }

        foreach ($intent->recognizedVerbs as $verb) {
            $verb = (string) $verb;
            if ($verb === '') {
                continue;
            }
            // substring present (how the composer extracted it) but NO whole-word occurrence => collision
            $substringHit = mb_stripos($draft->intentText, $verb) !== false || mb_stripos($intent->rawGoal, $verb) !== false;
            $wordHit = self::wordBoundaryHit($draft->intentText, $verb) || self::wordBoundaryHit($intent->rawGoal, $verb);
            if ($substringHit && ! $wordHit) {
                $findings[] = 'verb_collision:'.$verb;
            }
        }

        foreach ($draft->behavioralCriteria() as $ac) {
            $id = (string) ($ac['id'] ?? 'unknown');
            $description = trim((string) ($ac['description'] ?? ''));
            $ref = trim((string) ($ac['verification_ref'] ?? ''));
            if (mb_strlen($description) < self::MIN_DESCRIPTION_CHARS || $ref === '') {
                $findings[] = 'vacuous_criterion:'.$id;
            }
        }

        return array_values(array_unique($findings));
    }

    private static function wordBoundaryHit(string $haystack, string $needle): bool
    {
        $quoted = preg_quote($needle, '/');

        return (bool) preg_match('/(?<![\p{L}\p{N}_])'.$quoted.'(?![\p{L}\p{N}_])/iu', $haystack);
    }

    /** Concrete anchor: a file path, a CamelCase symbol, a ::/-> member ref, or a quoted identifier. */
    private static function hasConcreteAnchor(string $text): bool
    {
        return (bool) preg_match('/\S+\.\w{1,5}\b|[A-Z][a-z]+[A-Z]\w*|::|->|`[^`]+`|"[^"]+"|\'[^\']+\'/u', $text);
    }
}
