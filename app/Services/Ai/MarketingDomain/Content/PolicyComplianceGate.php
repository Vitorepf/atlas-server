<?php

namespace App\Services\Ai\MarketingDomain\Content;

/**
 * PolicyComplianceGate — the gatekeeper that turns the aggressive BASE page (max conversion) into a
 * Google-safe GREENLIGHT page. It scans copy (or a rendered page) for the violations that get a
 * weight-loss affiliate account DISAPPROVED or SUSPENDED on Google Ads: prescription-drug terms
 * (restricted; suspension-risk), speculative/experimental "at-home drug" framing, guaranteed/
 * unrealistic results, miracle/cure language, Big-Pharma conspiracy + "censored/taken-down"
 * sensationalism, fake urgency/countdown/scarcity theatre, fabricated live social proof, and
 * unverified celebrity endorsement. Grounded in Google's Healthcare-and-medicines + Misrepresentation
 * policies (researched live). Provider-free. Verdict: clean = ready to run; flags = fix before launch.
 */
class PolicyComplianceGate
{
    /** Each rule: regex (on lowercased text) → {category, severity, fix}. Severity critical = suspension-risk. */
    private const RULES = [
        // Prescription-drug terms — restricted in keyword/ad/landing for non-certified advertisers.
        ['re' => '/\b(retatrutide|semaglutide|tirzepatide|ozempic|wegovy|mounjaro|zepbound)\b/iu',
            'cat' => 'rx_drug_term', 'sev' => 'critical',
            'fix' => 'Remova o nome da droga de prescrição. Restrito a farmácias certificadas; arrisca SUSPENSÃO da conta. Use a linguagem do mecanismo ("three-hormone approach"), nunca a droga.'],
        ['re' => '/\bglp[-\s]?1\b|\bgip\b|\bglucagon\b/iu',
            'cat' => 'rx_mechanism_term', 'sev' => 'high',
            'fix' => 'Termos farmacológicos (GLP-1/GIP/glucagon) em página promocional puxam revisão de saúde. Trocar por linguagem leiga ("hormônios ligados ao metabolismo").'],
        // Speculative / experimental "at-home drug replication" — banned (egregious).
        ['re' => '/\bat[-\s]?home\s+(retatrutide|ozempic|injection|drug)|home[-\s]?made\s+(retatrutide|ozempic)|diy\s+(drug|injection|retatrutide)/iu',
            'cat' => 'speculative_experimental', 'sev' => 'critical',
            'fix' => 'Replicar uma droga em casa = tratamento especulativo/experimental (proibido, egregious). Remover totalmente.'],
        // Guaranteed / unrealistic specific weight-loss results.
        // "guaranteed" só é violação como PROMESSA — não na frase compliant "not guaranteed"/"no guarantee".
        ['re' => '/(?<!not )(?<!no )\b(guaranteed|guarantee)\b|\b\d{2,3}\s?(lbs|pounds|kg)\b|\blose\s+\d+\s?(lbs|pounds|kg)\b|\b\d+\s?(lbs|pounds)\s+in\s+\d+\s+(days?|weeks?|months?)/iu',
            'cat' => 'unrealistic_claim', 'sev' => 'critical',
            'fix' => 'Sem resultado garantido nem número dramático de perda. Trocar por linguagem hedge ("pode apoiar", "alguns relatam") + disclaimer "resultados variam".'],
        // Miracle / cure / breakthrough.
        // "cure" só conta como promessa (não no disclaimer padrão "treat, cure, or prevent any disease").
        ['re' => '/\bmiracle\b|\bcures\b|\bthe cure\b|cure for (your |the )?(weight|fat|obesity)|melt(s|ing)?\s+fat|burns?\s+fat\s+(overnight|while you sleep)|breakthrough\s+cure|fountain of youth/iu',
            'cat' => 'miracle_cure', 'sev' => 'high',
            'fix' => 'Remover linguagem de milagre/cura. Educacional e hedge.'],
        // Conspiracy / sensational / censorship.
        ['re' => '/\bbig pharma\b|\bthey (don.?t|do not) want you\b|taken (offline|down)|censored|banned|leaked|before it.?s (pulled|gone|removed)|cover[-\s]?up|hidden cure/iu',
            'cat' => 'conspiracy_sensational', 'sev' => 'high',
            'fix' => 'Remover teoria da conspiração / "censurado" / "antes que tirem do ar". É sensacionalismo enganoso (Misrepresentation).'],
        // Fake urgency / countdown / scarcity theatre.
        ['re' => '/\bcountdown\b|expires? (in|soon)|only \d+ (left|remaining)|\d+ bottles? (left|remaining)|act now|limited time offer|scheduled to be (taken|removed)/iu',
            'cat' => 'fake_urgency', 'sev' => 'high',
            'fix' => 'Remover countdown/escassez falsa/"agendado pra sair do ar". Pressão artificial é Misrepresentation.'],
        // Unverified celebrity endorsement.
        ['re' => '/\b(melania|melania trump|oprah|dr\.?\s?oz|kelly clarkson|rfk|robert f\.? kennedy)\b/iu',
            'cat' => 'celebrity_endorsement', 'sev' => 'critical',
            'fix' => 'Remover nome de celebridade + endosso não-verificado (false endorsement / right-of-publicity). Não citar a pessoa.'],
        // Negative self-image / body-shaming (sensitive-category personalization).
        ['re' => '/\b(obese|fat and ugly|disgusting|hate your body|ashamed of your)\b/iu',
            'cat' => 'body_shaming', 'sev' => 'high',
            'fix' => 'Remover linguagem de body-shaming (categoria sensível — proibido personalizar por imagem corporal negativa).'],
    ];

    /**
     * @return array{violations:array<int,array<string,mixed>>,n:int,verdict:string,worst:string,by_category:array<string,int>}
     */
    public function scan(string $copy): array
    {
        $text = ' '.mb_strtolower((string) preg_replace('/\s+/u', ' ', $copy)).' ';
        $violations = [];
        $byCat = [];

        foreach (self::RULES as $rule) {
            if (preg_match_all($rule['re'], $copy, $m) && $m[0] !== []) {
                $hits = array_values(array_unique(array_map('mb_strtolower', $m[0])));
                $violations[] = [
                    'category' => $rule['cat'],
                    'severity' => $rule['sev'],
                    'evidence' => array_slice($hits, 0, 5),
                    'count' => count($m[0]),
                    'fix' => $rule['fix'],
                ];
                $byCat[$rule['cat']] = count($m[0]);
            }
        }

        $rank = ['critical' => 0, 'high' => 1, 'medium' => 2];
        usort($violations, fn ($a, $b) => ($rank[$a['severity']] ?? 9) <=> ($rank[$b['severity']] ?? 9));
        $worst = $violations[0]['severity'] ?? 'none';

        return [
            'violations' => $violations,
            'n' => count($violations),
            'by_category' => $byCat,
            'worst' => $worst,
            'verdict' => $violations === [] ? 'greenlight'
                : ($worst === 'critical' ? 'suspension_risk' : 'needs_fixes'),
        ];
    }

    /** Convenience: scan rendered HTML (tags → spaces so words aren't glued). */
    public function scanHtml(string $html): array
    {
        return $this->scan(HtmlCopyExtractor::plainText($html));
    }
}
