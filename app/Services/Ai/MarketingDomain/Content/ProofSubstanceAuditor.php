<?php

namespace App\Services\Ai\MarketingDomain\Content;

/**
 * ProofSubstanceAuditor — proof CONCRETENESS as structural truth (Eixo 7).
 *
 * Proof is the #1 conversion lever, and the difference between proof that converts and proof that does
 * nothing is CONCRETENESS: "Dr. Aronson ran this on 312 women; 9 of 10 dropped a dress size in 6 weeks"
 * converts; "studies show it works, thousands love it" does not. This measures, as a FACT, which CONCRETE
 * proof anchors a page carries (named authority, a specific count of people, a ratio/percentage, a
 * mechanism-of-action that explains WHY, a demonstration) and which VAGUE proof-tells it leans on instead
 * ("studies show", "experts agree", "clinically proven" with no number/name, "thousands of"). It does NOT
 * grade quality and is NOT a moral/compliance gate — vague proof is WEAK proof (converts worse), and
 * upgrading vague→concrete is pure conversion advice; what is true is the operator's call. Built with the
 * cycles-43/value-equation lesson baked in: word-boundary matching, negation-aware, placeholders stripped.
 * Provider-free, niche-agnostic.
 */
class ProofSubstanceAuditor
{
    private const NEGATORS = ['no', 'not', 'never', 'without', 'sem', 'não', 'nao', 'nenhum', 'nenhuma'];

    /** Concrete proof anchors — each is a real, checkable specific. key => regex. */
    private const CONCRETE = [
        'named_authority' => '/\b(?:dr\.?|doctor|professor|prof\.?|ph\.?d|m\.?d|university|universidade|clinic|cl[íi]nica|hospital|institute|instituto|laborat[óo]r(?:y|io)|journal|peer[- ]reviewed|nobel)\b/u',
        'specific_count' => '/\b\d{2,}[\d,.]*\s*(?:women|men|people|persons|students|customers|clients|users|patients|members|families|mulheres|homens|pessoas|alunos|clientes|pacientes|fam[íi]lias)\b/u',
        'ratio_or_percent' => '/\b\d+\s*(?:out of|in|de|em)\s*(?:cada\s*)?\d+\b|\b\d{1,3}\s?%/u',
        'mechanism_of_action' => '/\b(?:because it|works by|it works because|triggers|activates|switches on|blocks|targets the|porque|funciona ao|ativa|bloqueia|aciona)\b/u',
        'demonstration' => '/\b(?:watch (?:the|this|how|me)|see (?:the|it|for yourself)|in this video|on camera|live demo|veja (?:o|como)|assista)\b/u',
        'dated_result' => '/\b(?:in|by|within|em|at[ée])\s+(?:\d+|the\s+\w+)\s+(?:days?|weeks?|months?|dias?|semanas?|meses)\b/u',
        'guarantee_terms' => '/\b\d+[- ]?day\b[^.]{0,40}\b(?:guarantee|money[- ]?back|refund|garantia|reembolso)\b/u',
    ];

    /** Vague proof-tells — a proof CLAIM with no concrete anchor. These convert poorly; flag to upgrade. */
    private const VAGUE = [
        'studies_show' => '/\b(?:studies show|research shows|science says|studies have shown|estudos mostram|a ci[êe]ncia (?:diz|mostra)|pesquisas mostram)\b/u',
        'experts_agree' => '/\b(?:experts agree|doctors recommend|scientists say|especialistas (?:concordam|recomendam)|m[ée]dicos recomendam)\b/u',
        'clinically_proven_bare' => '/\b(?:clinically proven|scientifically proven|medically proven|clinicamente comprovado|cientificamente comprovado)\b/u',
        'mass_vague' => '/\b(?:thousands of|millions of|countless|so many|tons of|milhares de|milh[õo]es de|in[úu]meros)\s+(?:people|women|men|customers|users|pessoas|clientes)\b/u',
        'everyone_loves' => '/\b(?:everyone(?:\'s| is) (?:loving|raving)|people love|all our customers|todo mundo (?:ama|adora)|nossos clientes amam)\b/u',
    ];

    /**
     * @return array{concrete:array<int,string>,vague:array<int,string>,concrete_count:int,has_concrete:bool,note:string}
     */
    public function audit(string $copy): array
    {
        $text = mb_strtolower((string) preg_replace('/\[[^\]]*\]/u', ' ', $copy)); // strip producer placeholders

        $concrete = [];
        foreach (self::CONCRETE as $key => $re) {
            if ($this->matchesUnnegated($text, $re)) {
                $concrete[] = $key;
            }
        }
        $vague = [];
        foreach (self::VAGUE as $key => $re) {
            if ($this->matchesUnnegated($text, $re)) {
                $vague[] = $key;
            }
        }

        $note = match (true) {
            $concrete === [] && $vague !== [] => 'Prova só VAGA ('.implode(', ', $vague).') — não converte. Trocar por concreto: número específico, nome de autoridade, ratio, ou mecanismo-de-ação.',
            $concrete === [] => 'Sem prova concreta. A alavanca #1 está vazia — adicionar autoridade nomeada / contagem específica / ratio / mecanismo.',
            $vague !== [] => 'Tem prova concreta ('.implode(', ', $concrete).') mas ainda apoia em vaga ('.implode(', ', $vague).') — substituir a vaga, não somar.',
            default => 'Prova concreta: '.implode(', ', $concrete).'.',
        };

        return [
            'concrete' => $concrete,
            'vague' => $vague,
            'concrete_count' => count($concrete),
            'has_concrete' => $concrete !== [],
            'note' => $note,
        ];
    }

    private function matchesUnnegated(string $text, string $re): bool
    {
        if (! preg_match($re, $text, $m, PREG_OFFSET_CAPTURE)) {
            return false;
        }
        $offset = $m[0][1];
        $window = substr($text, max(0, $offset - 16), min(16, $offset));
        foreach (self::NEGATORS as $neg) {
            if (str_contains($window, $neg.' ') || str_ends_with(trim($window), $neg)) {
                return false;
            }
        }

        return true;
    }
}
