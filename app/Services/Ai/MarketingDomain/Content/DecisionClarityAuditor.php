<?php

namespace App\Services\Ai\MarketingDomain\Content;

/**
 * DecisionClarityAuditor — structural truth at the decision point (where conversion actually happens).
 *
 * Two facts decide whether a page can convert at all, independent of copy quality:
 *   - NO ASK: a page with no recognizable call-to-action cannot convert — there is nothing to click.
 *   - CHOICE OVERLOAD: direct-response converts best with ONE dominant action. A page that asks for many
 *     DIFFERENT things (buy AND subscribe AND download AND call AND follow) splits the decision and
 *     depresses action (Hick's law / Iyengar's choice-overload). Note: the SAME ask repeated is good
 *     (one action, reinforced) — only DISTINCT competing action CATEGORIES are friction.
 *
 * These are facts (the ask is present or not; N distinct action categories appear or not), not a quality
 * score — so, like the leak/provenance detectors, it emits warnings only and cannot be gamed. Provider-
 * free, niche-agnostic. Per the cycles 43-45 meta-lesson, deterministic = structural truth only.
 */
class DecisionClarityAuditor
{
    /** Distinct ACTION CATEGORIES — counting categories (not repetitions) is what reveals overload. */
    private const ACTIONS = [
        'purchase' => '/\b(?:buy now|order now|add to cart|checkout|purchase|place your order|compre agora|comprar|finalizar compra|adicionar ao carrinho)\b/iu',
        'optin' => '/\b(?:sign up|subscribe|join (?:now|free|the)|create (?:your )?account|get instant access|enter your email|inscreva|cadastr[ae]|assine|criar conta)\b/iu',
        'watch' => '/\b(?:watch the (?:video|presentation|free)|assista|veja (?:o v[ií]deo|a apresenta))\b/iu',
        'call' => '/\b(?:call (?:now|us|today)|book a call|schedule a call|ligue (?:agora|para)|agende uma (?:ligação|chamada))\b/iu',
        'download' => '/\b(?:download (?:now|the|your)|get the (?:free )?(?:pdf|guide|report|ebook|cheat ?sheet)|baixe (?:agora|o|seu)|baixar)\b/iu',
        'schedule' => '/\b(?:book (?:now|your)|schedule (?:now|your)|reserve your (?:spot|seat)|agende|reserve (?:sua|seu)|marque)\b/iu',
        'social' => '/\b(?:follow (?:us|me) on|like (?:us|our page)|join our (?:facebook|telegram|whatsapp|group)|siga (?:nos|a gente)|curta|entre no (?:grupo|telegram|whatsapp))\b/iu',
    ];

    /** A generic CTA exists (even if uncategorized) — so a page with "click below" isn't called ask-less. */
    private const GENERIC_CTA = '/\b(?:click (?:here|below|the button)|tap (?:here|below)|get started|claim your|clique (?:aqui|abaixo|no bot[aã]o)|comece agora|garanta|quero)\b/iu';

    /** At/after this many DISTINCT action categories, the decision is split. */
    private const OVERLOAD_AT = 3;

    /**
     * @return array{flaws:array<int,array{key:string,name:string,detail:string}>,action_categories:array<int,string>,assessed:bool}
     */
    public function audit(string $copy): array
    {
        $text = (string) $copy;
        $categories = [];
        foreach (self::ACTIONS as $cat => $re) {
            if (preg_match($re, $text) === 1) {
                $categories[] = $cat;
            }
        }
        $hasGeneric = preg_match(self::GENERIC_CTA, $text) === 1;

        $flaws = [];
        if ($categories === [] && ! $hasGeneric) {
            $flaws[] = [
                'key' => 'no_cta',
                'name' => 'Sem call-to-action',
                'detail' => 'a página não tem nenhuma ação reconhecível — não dá pra converter o que não pede. '
                    .'Adicione um CTA dominante claro.',
            ];
        }
        if (count($categories) >= self::OVERLOAD_AT) {
            $flaws[] = [
                'key' => 'competing_ctas',
                'name' => 'Choice overload (ações competindo)',
                'detail' => count($categories).' tipos de ação distintos ('.implode(', ', $categories).') disputam '
                    .'a decisão. Resposta direta converte com UMA ação dominante — colapse o resto em secundário ou corte.',
            ];
        }

        return ['flaws' => $flaws, 'action_categories' => $categories, 'assessed' => true];
    }
}
