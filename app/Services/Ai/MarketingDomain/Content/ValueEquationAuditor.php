<?php

namespace App\Services\Ai\MarketingDomain\Content;

/**
 * ValueEquationAuditor — Hormozi's Value Equation as a structural-SUBSTANCE check (Eixo 5).
 *
 * Value = (Dream Outcome × Perceived Likelihood) / (Time Delay × Effort & Sacrifice). A grand-slam offer
 * pushes all four levers. v1 was a vocabulary proxy: a brutal cross-niche panel proved it scored a real
 * grand-slam offer 0/100 (synonyms outside the word list) and a hollow/NEGATED page 100/100 (substring
 * collisions: fast∈breakfast; "no guarantee" counted as covered). That is the exact Goodhart trap the OS
 * forbids. v2 measures SUBSTANCE, not words:
 *   - time_delay      → a CONCRETE timeframe (number/ordinal + time unit, weekday, "overnight"), not "soon";
 *   - effort_sacrifice→ a NAMED removed effort ("no counting calories", "without the gym", "just N minutes");
 *   - perceived_likelihood → REAL proof substance (a count of people, a ratio/%, a named authority, a
 *     money-back guarantee) — NOT an empty "[PROOF SLOT]" placeholder;
 *   - dream_outcome   → vivid future-state language, word-boundary matched and negation-aware.
 * Placeholders ([...]) are stripped, negated markers ("no/not/never/sem") do NOT count. The only way to
 * score high is to ACTUALLY address each lever with substance — so it cannot be gamed by token-stuffing.
 * Still a structural FACT (is the lever answered?), not a quality grade. Provider-free, niche-agnostic.
 */
class ValueEquationAuditor
{
    private const NUM = '(?:\d+|one|two|three|four|five|six|seven|eight|nine|ten|first|second|third|fourth|fifth|sixth|seventh|eighth|ninth|tenth|um|uma|dois|duas|tr[eê]s|quatro|cinco|seis|sete|oito|nove|dez|primeir[oa]|segund[oa]|terceir[oa]|quart[oa]|quint[oa])';

    private const TIMEUNIT = '(?:seconds?|minutes?|min|hours?|days?|nights?|mornings?|evenings?|weeks?|months?|segundos?|minutos?|horas?|dias?|noites?|manh[ãa]s?|semanas?|m[eê]s|meses)';

    private const WEEKDAY = '(?:monday|tuesday|wednesday|thursday|friday|saturday|sunday|segunda|ter[çc]a|quarta|quinta|sexta|s[áa]bado|domingo)';

    /** Negators that, when they precede a marker, flip it to a MISS. */
    private const NEGATORS = ['no', 'not', 'never', 'without', "don't", 'dont', 'do not', 'isn\'t', 'cannot', 'cant', 'sem', 'não', 'nao', 'nunca', 'nenhum', 'nenhuma'];

    /** Concrete effort-domain words whose REMOVAL is a real ease claim (cross-niche). */
    private const EFFORT_DOMAIN = ['diet', 'diets', 'dieting', 'gym', 'exercise', 'exercising', 'workout', 'workouts',
        'calorie', 'calories', 'counting', 'starving', 'starvation', 'pills', 'surgery', 'injections', 'fasting',
        'cold calling', 'cold call', 'door', 'knocking', 'budget', 'budgeting', 'spreadsheet', 'spreadsheets',
        'experience', 'capital', 'willpower', 'sweat', 'restriction', 'restrictions', 'meetings', 'apps', 'texting',
        'dieta', 'academia', 'exerc[íi]cio', 'rem[ée]dio', 'rem[ée]dios', 'cirurgia', 'jejum', 'planilha', 'for[çc]a de vontade'];

    public function audit(string $copy): array
    {
        // Strip placeholder/producer-instruction brackets so an empty "[PROOF SLOT: …estudo…]" can never
        // satisfy a lever. Lowercase for matching.
        $text = mb_strtolower((string) preg_replace('/\[[^\]]*\]/u', ' ', $copy));

        $covered = [];
        $gaps = [];
        $check = [
            'dream_outcome' => [$this->hasDream($text), 'Dream outcome (↑)', 'o resultado dos sonhos está pintado vívido?'],
            'perceived_likelihood' => [$this->hasProof($text), 'Perceived likelihood (↑)', 'há PROVA concreta (número/autoridade/garantia), não placeholder?'],
            'time_delay' => [$this->hasTimeframe($text), 'Time delay (↓)', 'há um PRAZO concreto (número + unidade de tempo)?'],
            'effort_sacrifice' => [$this->hasEaseSubstance($text), 'Effort & sacrifice (↓)', 'nomeia o esforço que SOME (sem dieta/sem academia/X min por dia)?'],
        ];
        foreach ($check as $key => [$ok, $name, $asks]) {
            if ($ok) {
                $covered[] = $key;
            } else {
                $gaps[] = ['key' => $key, 'name' => $name, 'asks' => $asks, 'high_leverage' => in_array($key, ['perceived_likelihood', 'effort_sacrifice'], true)];
            }
        }
        usort($gaps, static fn (array $a, array $b): int => ($b['high_leverage'] ? 1 : 0) <=> ($a['high_leverage'] ? 1 : 0));

        return ['covered' => $covered, 'gaps' => $gaps, 'score' => (int) round(count($covered) / count($check) * 100)];
    }

    /** A concrete timeframe: number/ordinal + time unit, a weekday, or overnight/immediately (not negated). */
    private function hasTimeframe(string $text): bool
    {
        $patterns = [
            '/\b'.self::NUM.'\s+'.self::TIMEUNIT.'\b/u',
            '/\b'.self::NUM.'\s+'.self::WEEKDAY.'s?\b/u',                 // "Eight Sundays from today"
            '/\bby\s+(?:next\s+|this\s+|the\s+'.self::NUM.'\s+)?(?:'.self::WEEKDAY.'|'.self::TIMEUNIT.'|tomorrow|amanh[ãa])\b/u',
            '/\b(?:overnight|immediately|imediatamente|da noite pro dia|in minutes|em minutos)\b/u',
        ];

        return $this->anyMatch($text, $patterns);
    }

    /** Real proof substance: a count of people, a ratio/%, a named authority, or a money-back guarantee. */
    private function hasProof(string $text): bool
    {
        $patterns = [
            '/\b\d{2,}[\d,.]*\s+(?:women|men|people|persons|students|customers|clients|users|patients|members|mulheres|homens|pessoas|alunos|clientes|pacientes)\b/u',
            '/\b\d+\s*(?:out of|in)\s*\d+\b/u',
            '/\b\d+\s*(?:de|em)\s*(?:cada\s*)?\d+\b/u',
            '/\b\d{1,3}\s?%/u',
            '/\b(?:dr\.?|doctor|professor|prof\.?|ph\.?d|m\.?d|university|universidade|clinic|cl[íi]nica|hospital|institute|instituto|laborat[óo]r(?:y|io)|journal|peer[- ]reviewed|clinical trial|estudo cl[íi]nico|as seen on|featured in)\b/u',
            '/\b\d+[- ]?day\b[^.]{0,40}\b(?:guarantee|money[- ]?back|refund|garantia|reembolso)\b/u',
            '/\b(?:money[- ]?back guarantee|garantia de reembolso)\b/u',
        ];

        return $this->anyMatch($text, $patterns);
    }

    /** Named removed effort ("no gym", "without dieting") or a concrete low time-cost ("just 10 minutes a day"). */
    private function hasEaseSubstance(string $text): bool
    {
        // "just N minutes (a/per day)" — a concrete, small effort cost.
        if (preg_match('/\b(?:just|only|apenas|s[óo])\s+\d+\s+(?:minutes?|min|minutos?)\b/u', $text)) {
            return true;
        }
        // Removal of a CONCRETE effort: a negator immediately followed by an effort-domain word.
        $domain = implode('|', self::EFFORT_DOMAIN);
        if (preg_match('/\b(?:no|without|skip|stop|quit|forget|zero|sem)\s+(?:the\s+|a\s+|o\s+|os\s+|as\s+)?(?:'.$domain.')\b/u', $text)) {
            return true;
        }

        return false;
    }

    private function hasDream(string $text): bool
    {
        $markers = ['/\bimagine\b/u', '/\bpicture\b/u', '/\benvision\b/u', '/\bfinally\b/u', '/\bdream\b/u',
            '/\byou will\b/u', '/\byou\'ll\b/u', '/\bwake up\b/u', '/\bimagin[ae]\b/u', '/\bfinalmente\b/u',
            '/\bsonho\b/u', '/\bvoc[êe] vai\b/u', '/\ba vida que\b/u'];
        foreach ($markers as $re) {
            if (preg_match($re, $text, $m, PREG_OFFSET_CAPTURE) && ! $this->negatedAt($text, $m[0][1])) {
                return true;
            }
        }

        return false;
    }

    /** True if ANY pattern matches at a position that is not negated by a preceding negator. */
    private function anyMatch(string $text, array $patterns): bool
    {
        foreach ($patterns as $re) {
            if (preg_match($re, $text, $m, PREG_OFFSET_CAPTURE) && ! $this->negatedAt($text, $m[0][1])) {
                return true;
            }
        }

        return false;
    }

    /** Is there a negator within ~18 chars before $offset? (byte offset on a lowercased haystack). */
    private function negatedAt(string $text, int $offset): bool
    {
        $window = mb_strtolower(substr($text, max(0, $offset - 18), min(18, $offset)));
        foreach (self::NEGATORS as $neg) {
            if (str_contains($window, $neg.' ') || str_ends_with(trim($window), $neg)) {
                return true;
            }
        }

        return false;
    }
}
