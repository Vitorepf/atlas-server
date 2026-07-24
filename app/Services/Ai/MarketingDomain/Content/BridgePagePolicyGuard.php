<?php

namespace App\Services\Ai\MarketingDomain\Content;

/**
 * BridgePagePolicyGuard — the policy-guard skill made executable, framed as UPTIME = PERFORMANCE
 * (never a moral gate). A bridge page only earns money while the account is live, so the things that
 * get an account suspended are treated as hard performance failures (fail-closed), and the things
 * that merely raise risk are warnings that inform without refusing.
 *
 * Hard fails (BLOCK — these zero out uptime):
 *  - thin/no original content → Google "Insufficient Original Content" → suspension.
 *  - a raw affiliate/HopLink in the page body (ad → page must route to the VSL, never straight to checkout).
 *  - more than one conversion goal competing for the click (the page must push ONE next step: watch).
 *
 * Soft warns (durability/uptime risk — informational, aligned to "compliance is not a gate"):
 *  - strong health/earnings claims on the bridge itself without a hedge/attribution + a typical-result disclosure.
 *  - missing advertorial/affiliate disclosure.
 *
 * Deterministic; no LLM, no DB.
 */
class BridgePagePolicyGuard
{
    private const MIN_BODY_WORDS = 350;          // below this the page reads as a thin pre-sell.

    private const MIN_BODY_SECTIONS = 3;

    /** Absolute health/earnings claims that MUST be hedged on the bridge (the VSL can be aggressive; the bridge stays durable). */
    private const ABSOLUTE_CLAIM_MARKERS = [
        'cura', 'cure', 'guaranteed', 'garantido', 'garante que você', '100% guarant',
        'sem exceção', 'todo mundo perde', 'funciona para todos', 'resultado garantido',
        'aprovado pelo fda', 'fda approved', 'aprovado pelo governo',
    ];

    /** A hedge/attribution near a claim keeps it durable (typical-result framing, "estudos sugerem", "segundo", etc.). */
    private const HEDGE_MARKERS = [
        'pode', 'podem', 'até', 'em média', 'resultados variam', 'resultado típico', 'typical result',
        'segundo', 'de acordo com', 'estudo', 'estudos', 'relataram', 'relatou', 'alegadamente',
        'muitas', 'algumas', 'não é garantia', 'individual results', 'sugere', 'sugerem',
    ];

    private const AFFILIATE_LINK_MARKERS = [
        'hop.clickbank', 'hoplink', '.hop.', 'buygoods.com/checkout', 'digistore24.com/redir',
        '/checkout', 'add-to-cart', 'order-now-secure', 'redir/', '?aff=', '&aff=', 'affid=',
    ];

    /**
     * Offer/close-stage COPY that must NEVER appear on the bridge (the funnel-handoff law). The bridge
     * sells the VSL WATCH; the product is closed ONLY at the end of the VSL, after belief+mechanism+proof.
     * Price, money-back guarantee, bonus stacking, free shipping, discount, buy/checkout CTA and
     * buy-scarcity are CLOSE-stage elements — surfaced here they burn the lever and break the funnel.
     * (Distinct from AFFILIATE_LINK_MARKERS, which only catch the LINK; these catch the offer being SOLD.)
     * Kept to specific multi-word/price phrases so a legit watch-CTA ("assistir à apresentação gratuita")
     * or a common-enemy mention ("a indústria de $2 bilhões") never false-positives.
     */
    private const OFFER_CLOSE_MARKERS = [
        // money-back / refund guarantee (offer-stage; the health "cura garantida" is handled separately)
        'money-back', 'money back', 'satisfação garantida', 'satisfaction guaranteed',
        'dias de garantia', 'day money-back', 'day money back', 'risk-free', 'risk free',
        'risco zero', 'reembolso', 'refund', 'devolução do dinheiro', 'garantia incondicional',
        // buy / checkout CTAs (selling the PRODUCT, not the next click)
        'order now', 'buy now', 'order today', 'comprar agora', 'compre agora', 'compre já',
        'add to cart', 'adicionar ao carrinho', 'finalizar compra', 'finalize sua compra',
        'place your order', 'claim your bottle', 'secure your order', 'get yours now',
        'garanta seu frasco', 'garanta seu kit', 'garanta o seu frasco',
        // stacked value / shipping bonus (offer-stage)
        'free shipping', 'frete grátis', 'free bonus', 'bônus grátis', 'free bottle', 'frasco grátis',
        'buy 3 get', 'compre 3 leve', 'leve 3 pague',
        // price-scarcity / discount used as a BUY trigger
        'today only $', 'lowest price', 'menor preço', '% off', '% de desconto', 'discount code',
        'cupom de desconto', 'special price', 'only $', 'apenas r$',
    ];

    /**
     * @param  array<string,mixed>  $bridge  the structured bridge produced by BridgePageComposerService
     * @return array<string,mixed>
     */
    public function evaluate(array $bridge): array
    {
        $sections = $this->bodySections($bridge);
        $bodyText = $this->bodyText($bridge, $sections);
        $words = $this->wordCount($bodyText);

        $blocks = [];
        $warns = [];

        // --- HARD: original content (the bridge test) -----------------------------------------
        if ($words < self::MIN_BODY_WORDS || count($sections) < self::MIN_BODY_SECTIONS) {
            $blocks[] = [
                'rule' => 'original_content',
                'why' => "Conteúdo original insuficiente ({$words} palavras / ".count($sections).' seções; piso '.self::MIN_BODY_WORDS.'w/'.self::MIN_BODY_SECTIONS.' seções). Página fina = "Insufficient Original Content" = suspensão = uptime ZERO = ROI zero.',
                'uptime' => 'fatal',
            ];
        }

        // --- HARD: single conversion goal = watch the VSL -------------------------------------
        $cta = $this->ctaTargets($bridge);
        if ($cta['distinct_goals'] > 1) {
            $blocks[] = [
                'rule' => 'single_goal',
                'why' => "A página tem {$cta['distinct_goals']} objetivos de conversão competindo. Bridge converte com UM próximo passo: assistir a VSL. Múltiplos goals derrubam o watch-through (o KPI da bridge).",
                'uptime' => 'conversion',
            ];
        }
        if (! $cta['points_to_video']) {
            $blocks[] = [
                'rule' => 'cta_routes_to_video',
                'why' => 'Nenhum CTA aponta para a VSL/vídeo. A bridge existe para EMPURRAR pro vídeo aquecido — o CTA tem que levar a assistir, não a outro lugar.',
                'uptime' => 'conversion',
            ];
        }

        // --- HARD: no raw affiliate link in the page body ------------------------------------
        $haystack = strtolower($bodyText.' '.json_encode($cta['raw'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $found = array_values(array_filter(self::AFFILIATE_LINK_MARKERS, static fn (string $m): bool => str_contains($haystack, $m)));
        if ($found !== []) {
            $blocks[] = [
                'rule' => 'no_raw_affiliate_link',
                'why' => 'Link de afiliado/checkout cru na bridge ('.implode(', ', $found).'). Ad → bridge → VSL; nunca ad → checkout. HopLink cru no destino = reprovação/suspensão.',
                'uptime' => 'fatal',
            ];
        }

        // --- HARD: the product is NEVER closed on the bridge (funnel-handoff law) -------------
        // The bridge sells the VSL WATCH, not the product. Offer/price/guarantee/checkout copy — or a
        // buy CTA — is a PHASE ERROR: the offer only converts AFTER the VSL builds belief+mechanism+proof.
        // Closing here breaks the funnel and kills conversion. (no_raw_affiliate_link catches the LINK;
        // this catches the offer being SOLD in the copy/CTA even with no link.)
        $offerHaystack = strtolower(implode(' ', [
            $bodyText,
            (string) ($bridge['headline'] ?? ''),
            (string) ($bridge['subheadline'] ?? ''),
            (string) ($bridge['kicker'] ?? ''),
            (string) ($bridge['ps'] ?? ''),
            (string) (json_encode($cta['raw'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''),
        ]));
        $offerHits = array_values(array_filter(self::OFFER_CLOSE_MARKERS, static fn (string $m): bool => str_contains($offerHaystack, $m)));
        $buyGoal = in_array('buy', $cta['goals'], true);
        if ($offerHits !== [] || $buyGoal) {
            $reasons = $buyGoal ? ['CTA de compra/checkout'] : [];
            $reasons = array_merge($reasons, array_slice($offerHits, 0, 4));
            $blocks[] = [
                'rule' => 'no_offer_on_bridge',
                'why' => 'Oferta/preço/garantia fechando o PRODUTO na bridge ('.implode(', ', $reasons).'). ERRO DE FASE: a bridge vende o WATCH da VSL, não o produto — preço, garantia, bônus, frete, desconto, escassez-de-compra e checkout vivem SÓ no fechamento da VSL, depois do mecanismo+prova. Fechar aqui queima a alavanca, quebra o funil e mata a conversão. Todo CTA = assistir a VSL.',
                'uptime' => 'conversion',
            ];
        }

        // --- SOFT: claim substantiation (aggressive is fine IF hedged on the bridge) ----------
        $lower = strtolower($bodyText.' '.strtolower((string) ($bridge['headline'] ?? '').' '.($bridge['subheadline'] ?? '')));
        $absoluteHits = array_values(array_filter(self::ABSOLUTE_CLAIM_MARKERS, static fn (string $m): bool => str_contains($lower, $m)));
        $hasHedge = $this->hasAny($lower, self::HEDGE_MARKERS);
        if ($absoluteHits !== [] && ! $hasHedge) {
            $warns[] = [
                'rule' => 'claim_substantiation',
                'why' => 'Claim absoluto sem hedge na própria bridge ('.implode(', ', array_slice($absoluteHits, 0, 3)).'). Mantenha a agressividade no ângulo/curiosidade; ancore claims fortes com atribuição + "resultados variam" para durar mais no ar.',
                'uptime' => 'risk',
            ];
        }

        // --- SOFT: typical-result / advertorial disclosure -----------------------------------
        if (! $this->hasDisclosure($bridge, $lower)) {
            $warns[] = [
                'rule' => 'disclosure',
                'why' => 'Sem disclosure de resultado típico / natureza advertorial. Disclosure curto no rodapé aumenta o uptime (passa melhor na revisão) sem custar conversão.',
                'uptime' => 'risk',
            ];
        }

        $verdict = $blocks !== [] ? 'block' : ($warns !== [] ? 'warn' : 'ok');

        return [
            'verdict' => $verdict,
            'safe_to_publish' => $blocks === [],
            'blocks' => $blocks,
            'warnings' => $warns,
            'metrics' => [
                'body_words' => $words,
                'body_sections' => count($sections),
                'cta_count' => $cta['count'],
                'cta_distinct_goals' => $cta['distinct_goals'],
                'cta_points_to_video' => $cta['points_to_video'],
                'has_hedge' => $hasHedge,
            ],
            'note' => $verdict === 'block'
                ? 'BLOQUEIO de uptime: corrigir antes de publicar — página assim derruba a conta (uptime zero = ROI zero).'
                : ($verdict === 'warn'
                    ? 'Publicável; avisos elevam durabilidade no ar (mais dias = mais lucro).'
                    : 'Forte: conteúdo original + 1 goal pro vídeo + sem link cru. Converte E mantém a conta no ar.'),
        ];
    }

    /**
     * @param  array<string,mixed>  $bridge
     * @return array<int,array<string,mixed>>
     */
    private function bodySections(array $bridge): array
    {
        $sections = $bridge['body_sections'] ?? $bridge['sections'] ?? [];

        return is_array($sections) ? array_values(array_filter($sections, 'is_array')) : [];
    }

    /**
     * @param  array<string,mixed>  $bridge
     * @param  array<int,array<string,mixed>>  $sections
     */
    private function bodyText(array $bridge, array $sections): string
    {
        $parts = [
            (string) ($bridge['lead_paragraph'] ?? $bridge['lead'] ?? ''),
            (string) ($bridge['mechanism_tease'] ?? ''),
        ];
        foreach ($sections as $s) {
            $parts[] = (string) ($s['heading'] ?? $s['title'] ?? '');
            $parts[] = (string) ($s['body'] ?? $s['text'] ?? $s['copy'] ?? '');
            $parts[] = (string) ($s['open_loop'] ?? '');
        }
        $proof = $bridge['proof_block'] ?? $bridge['proof'] ?? [];
        if (is_array($proof)) {
            array_walk_recursive($proof, static function ($v) use (&$parts): void {
                if (is_string($v)) {
                    $parts[] = $v;
                }
            });
        }
        $objections = $bridge['objection_flips'] ?? [];
        if (is_array($objections)) {
            array_walk_recursive($objections, static function ($v) use (&$parts): void {
                if (is_string($v)) {
                    $parts[] = $v;
                }
            });
        }

        return trim(implode(' ', array_filter($parts)));
    }

    /**
     * @param  array<string,mixed>  $bridge
     * @return array{count:int,distinct_goals:int,points_to_video:bool,raw:array<int,mixed>}
     */
    private function ctaTargets(array $bridge): array
    {
        $ctas = $bridge['cta_blocks'] ?? $bridge['ctas'] ?? [];
        if (! is_array($ctas)) {
            $ctas = [];
        }
        // a single cta_block string/array is also accepted
        if (isset($bridge['cta']) && ! isset($bridge['cta_blocks'])) {
            $ctas[] = $bridge['cta'];
        }

        $targets = [];
        $pointsToVideo = false;
        foreach ($ctas as $c) {
            $target = is_array($c) ? strtolower((string) ($c['target'] ?? $c['action'] ?? $c['goal'] ?? '')) : strtolower((string) $c);
            $label = is_array($c) ? strtolower((string) ($c['label'] ?? $c['text'] ?? '')) : '';
            $blob = $target.' '.$label;
            if ($blob !== ' ') {
                $targets[] = $this->normalizeGoal($blob);
            }
            if ($this->mentionsVideo($blob)) {
                $pointsToVideo = true;
            }
        }
        $distinct = array_values(array_unique(array_filter($targets)));

        return [
            'count' => count($ctas),
            'distinct_goals' => count($distinct) ?: ($ctas === [] ? 0 : 1),
            'points_to_video' => $pointsToVideo,
            'goals' => $distinct,
            'raw' => is_array($ctas) ? $ctas : [],
        ];
    }

    private function normalizeGoal(string $blob): string
    {
        // A buy/checkout CTA is a BUY even if it also says "watch" — buy wins. This closes the loophole
        // where "Comprar agora — assista ao vídeo" was misread as a watch CTA, hiding a product close on
        // the bridge (the funnel-handoff phase error).
        if (str_contains($blob, 'checkout') || str_contains($blob, 'comprar') || str_contains($blob, 'compre')
            || str_contains($blob, 'buy') || str_contains($blob, 'order') || str_contains($blob, 'cart')
            || str_contains($blob, 'carrinho') || str_contains($blob, 'finalizar compra')) {
            return 'buy';
        }
        if ($this->mentionsVideo($blob)) {
            return 'watch_video';
        }
        if (str_contains($blob, 'quiz') || str_contains($blob, 'lead') || str_contains($blob, 'email') || str_contains($blob, 'cadastr')) {
            return 'optin';
        }

        return 'watch_video';
    }

    private function mentionsVideo(string $blob): bool
    {
        foreach (['vsl', 'video', 'vídeo', 'assist', 'watch', 'play', 'apresenta', '#vsl', 'continuar assistindo'] as $m) {
            if (str_contains($blob, $m)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $bridge
     */
    private function hasDisclosure(array $bridge, string $lowerBody): bool
    {
        if (! empty($bridge['disclosure']) || ! empty($bridge['disclaimer'])) {
            return true;
        }
        foreach (['resultado típico', 'resultados variam', 'resultados podem variar', 'individual results', 'publieditorial', 'advertorial', 'conteúdo publicitário', 'não substitui', 'consulte um médico'] as $m) {
            if (str_contains($lowerBody, $m)) {
                return true;
            }
        }

        return false;
    }

    private function hasAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $n) {
            if (str_contains($haystack, $n)) {
                return true;
            }
        }

        return false;
    }

    private function wordCount(string $text): int
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return $text === '' ? 0 : count(explode(' ', $text));
    }
}
