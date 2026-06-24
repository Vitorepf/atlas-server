<?php

namespace App\Services\Ai\MarketingDomain\Content;

/**
 * ProofSubstanceAuditor — proof CONCRETENESS as structural truth (Eixo 7), the #1 conversion lever.
 *
 * v2, after a second brutal panel proved v1 was still a vocabulary proxy one level down: an OR of loose
 * tokens certified hype/discount/CTA/guarantee as "concrete" ("50% off", "Ships in 3 days", "60-day
 * money-back guarantee", "activates your inner confidence") while marking the proof that ACTUALLY converts
 * as weak (first-person transformation "lost 34 pounds" / "A1c 9.2 to 5.6", credentialed authority
 * "cardiac surgeon" / "Harvard-trained", written-out ratios). And it leaked: the composer used it as an
 * oracle and planted the guarantee while discarding the real testimonial.
 *
 * v2 fixes the core: a number is proof ONLY when tied to a RESULT/transformation in the same sentence
 * (kills discount/delivery/recipe/mailing-list); it recognizes first-person transformation, before/after,
 * income receipts, and authority by title OR credential; mechanism must name a concrete causal object (not
 * an abstract feeling); a guarantee is risk-reduction, NOT efficacy proof (dropped from concrete); negation
 * is sentence-scoped over ALL matches. Each anchor carries a STRENGTH tier so the composer can plant the
 * STRONGEST proof, not the first. Still structural truth, no moral gate. Provider-free, niche-agnostic.
 */
class ProofSubstanceAuditor
{
    /** A result/transformation word — the thing a real number must be tied to. */
    private const RESULT = '(?:lost|shed|dropped|drop|gained|regrew|regrow|reversed|reverse|cut|lowered|slashed|melted|shrank|shrunk|banked|earned|made|pulled|saved|cleared|healed|replaced|grew|doubled|tripled|booked|closed|kept off|came off|went from|down to|fell to|rose to|dress size|sizes?|pounds?|lbs?|kg|inches|a1c|cholesterol|blood sugar|glucose|salary|commission|income|profit|revenue|points?|regrowth|results?)';

    /** A concrete causal object — what a real mechanism acts on (vs an abstract feeling). */
    private const CAUSAL = '(?:hormones?|ghrelin|insulin|cortisol|leptin|metabolism|metabolic|inflammation|blood sugar|glucose|gut|microbiome|receptors?|enzymes?|nerves?|set point|fat|cells?|arter(?:y|ies)|plaque|thyroid|liver|nervous system|compound interest|interest|portfolio|allocation|capital|cravings?|appetite)';

    /** Negators that, at the head of a clause, mean the proof is ABSENT (not rhetorical "cannot ignore"). */
    private const CLAUSE_NEG = ['no ', 'not a', 'nobody', 'no one', "didn't", 'did not', 'failed', 'there is no', 'there are no', 'without ', 'never ', 'sem ', 'nenhum', 'nenhuma', 'não há', 'nao ha', 'não tem'];

    /** Vague proof-tells — a proof CLAIM with no concrete anchor. Convert poorly; flag to upgrade. */
    private const VAGUE = [
        'studies_show' => '/\b(?:studies show|research shows|science says|studies have shown|estudos mostram|a ci[êe]ncia (?:diz|mostra)|pesquisas mostram)\b/u',
        'experts_agree' => '/\b(?:experts agree|doctors recommend|scientists say|especialistas (?:concordam|recomendam)|m[ée]dicos recomendam)\b/u',
        'clinically_proven_bare' => '/\b(?:clinically proven|scientifically proven|medically proven|clinicamente comprovado|cientificamente comprovado)\b/u',
        'mass_vague' => '/\b(?:thousands of|millions of|countless|so many|tons of|milhares de|milh[õo]es de|in[úu]meros)\s+(?:people|women|men|customers|users|pessoas|clientes)\b/u',
        'everyone_loves' => '/\b(?:everyone(?:\'s| is) (?:loving|raving)|people love|all our customers|todo mundo (?:ama|adora)|nossos clientes amam)\b/u',
    ];

    /** Concrete anchor => [regex, strength tier]. Result-tied ones are validated in matchesAnchor(). */
    private function anchors(): array
    {
        return [
            // tier 5: transformation / before-after / income receipt
            'transformation' => ['/\b(?:went from|from)\b[^\n]{0,30}?\bto\b/u', 5], // validated: needs a digit/result
            'result_number' => ['/\b(?:lost|shed|dropped|regrew|reversed|cut|lowered|slashed|melted|banked|earned|made|pulled|saved|gained|doubled|tripled|replaced|grew)\b[^.?!]*?\d/u', 5],
            'income_receipt' => ['/\$\s?\d[\d,.]*/u', 5], // validated: needs earn/first-period context
            // tier 4: credentialed authority (title MUST be followed by a name/word — kills "Doctor. Clinic." stuffing) + studied count
            'credentialed_authority' => ['/\b(?:(?:dr\.?|doctor|professor|surgeon|cardiologist|biochemist|physician|researcher|scientist|nutritionist|endocrinologist|dermatologist|nurse|trader|analyst|strategist|coach|consultant|founder|ceo|manager)\s+[a-z]{2,}|board[- ]certified|harvard|yale|stanford|oxford|johns hopkins|cardiology|phd|m\.?d\.?|\d+\s+years\s+(?:in|of)\s+\w+)\b/u', 4],
            'study_count' => ['/\b\d+\s*(?:people|men|women|patients|participants|subjects|volunteers|users|students|customers|clients|members|moms|guys|adults|families)\b/u', 4],
            // tier 3: ratio tied to result + concrete mechanism
            'ratio_result' => ['/\b\d+\s*(?:out of|in|of)\s*\d+\b|\b\d{1,3}\s?%/u', 3],
            'mechanism_of_action' => ['/\b(?:works by|because it|the reason it works|triggers|activates|blocks|targets|shuts down|raises|lowers|resets)\b/u', 3],
            // tier 2: demonstration (proof by showing) — NOT the generic "watch the presentation" CTA
            'demonstration' => ['/\b(?:before and after|before-and-after|on camera|in this video|watch it work|see my photos|photos from)\b/u', 2],
        ];
    }

    /**
     * @return array{concrete:array<int,string>,vague:array<int,string>,concrete_count:int,has_concrete:bool,strength:int,note:string}
     */
    public function audit(string $copy): array
    {
        $text = mb_strtolower((string) preg_replace('/\[[^\]]*\]/u', ' ', $copy));
        // Protect abbreviation dots ("Dr." / "Prof.") so they don't split a sentence away from the name.
        $text = (string) preg_replace('/\b(dr|mr|mrs|ms|prof|vs|inc|st)\.\s*/u', '$1 ', $text);
        // Split on real sentence enders only — NOT a decimal point ("9.2") nor an abbreviation dot.
        $sentences = preg_split('/(?<![0-9])[.!?]+(?![0-9])|\n+/u', $text) ?: [];

        $concrete = [];
        $strength = 0;
        foreach ($sentences as $s) {
            $s = trim($s);
            if ($s === '') {
                continue;
            }
            foreach ($this->anchors() as $key => [$re, $tier]) {
                if (in_array($key, $concrete, true) || ! $this->matchesAnchor($key, $re, $s)) {
                    continue;
                }
                // Negation is CLAUSE-scoped to the anchor's own match — so "no gym" in a later clause does
                // not nuke "Dr. Aronson ... 312 women" earlier in the same sentence (panel-caught bug).
                if (preg_match($re, $s, $m, PREG_OFFSET_CAPTURE) && $this->clauseNegatedAt($s, (int) $m[0][1])) {
                    continue;
                }
                $concrete[] = $key;
                $strength = max($strength, $tier);
            }
        }

        $vague = [];
        foreach (self::VAGUE as $key => $re) {
            if (preg_match($re, $text)) {
                $vague[] = $key;
            }
        }

        $note = match (true) {
            $concrete === [] && $vague !== [] => 'Prova só VAGA ('.implode(', ', $vague).') — não converte. Trocar por concreto: transformação real, número AMARRADO a resultado, autoridade com credencial, ou mecanismo causal.',
            $concrete === [] => 'Sem prova concreta. A alavanca #1 está vazia — adicionar transformação/before-after, autoridade credenciada, contagem ligada a resultado, ou mecanismo causal.',
            $vague !== [] => 'Tem prova concreta ('.implode(', ', $concrete).') mas ainda apoia em vaga ('.implode(', ', $vague).') — substituir a vaga.',
            default => 'Prova concreta (força '.$strength.'): '.implode(', ', $concrete).'.',
        };

        return [
            'concrete' => $concrete,
            'vague' => $vague,
            'concrete_count' => count($concrete),
            'has_concrete' => $concrete !== [],
            'strength' => $strength,
            'note' => $note,
        ];
    }

    /** Strength tier (0 = no concrete proof) — lets the composer plant the STRONGEST proof, not the first. */
    public function strength(string $copy): int
    {
        return $this->audit($copy)['strength'];
    }

    /** Anchor matches in a sentence, with the result/substance co-occurrence each anchor requires. */
    private function matchesAnchor(string $key, string $re, string $sentence): bool
    {
        if (! preg_match($re, $sentence)) {
            return false;
        }

        return match ($key) {
            // A number alone is not proof — it must sit with a RESULT word in the same sentence.
            'study_count', 'ratio_result' => (bool) preg_match('/\b'.self::RESULT.'\b/u', $sentence)
                && ! preg_match('/\b(?:off|discount|save \d|coupon|desconto|ships?|shipping|delivery|entrega|mailing|newsletter|hotline|call \d)\b/u', $sentence),
            // Transformation: "from X to Y" only counts as proof with a number or a result word present.
            'transformation' => (bool) preg_match('/\d/u', $sentence) || (bool) preg_match('/\b'.self::RESULT.'\b/u', $sentence),
            // Income receipt: a $ figure tied to earning / a first period.
            'income_receipt' => (bool) preg_match('/\b(?:made|pulled|banked|earned|profit|in (?:my|the) first|per (?:month|week|day)|a (?:month|week|day))\b/u', $sentence),
            // Mechanism must act on a concrete causal object, not an abstract feeling.
            'mechanism_of_action' => (bool) preg_match('/\b'.self::CAUSAL.'\b/u', $sentence),
            default => true,
        };
    }

    /** True if a negator sits in the SAME clause as the anchor match (between the clause start and $offset). */
    private function clauseNegatedAt(string $sentence, int $offset): bool
    {
        $clauseStart = 0;
        foreach ([';', ','] as $sep) {
            $p = strrpos(substr($sentence, 0, $offset), $sep);
            if ($p !== false) {
                $clauseStart = max($clauseStart, $p + 1);
            }
        }
        $clause = ' '.trim(substr($sentence, $clauseStart, $offset - $clauseStart));
        foreach (self::CLAUSE_NEG as $neg) {
            if (str_contains($clause, ' '.$neg)) {
                return true;
            }
        }

        return false;
    }
}
