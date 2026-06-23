<?php

namespace App\Services\Ai\MarketingDomain\Knowledge;

/**
 * FunnelSequenceLibrary — the construction that isn't of the page, but of the JOURNEY. The slippery
 * slide doesn't end at the headline; it ends at retention/LTV. These are the patterns of the entire
 * sequence: ad → bridge → VSL → offer → cart → upsell → email → re-engage → re-sell. The funnel-as-a-
 * whole has its own conversion shape — message-match across steps, micro-commitments, the value
 * ladder, tripwire, OTO, abandoned-cart rescue, email re-engage cadence, post-purchase upsell,
 * winback. Content-independent: any niche has the same journey shape.
 */
class FunnelSequenceLibrary implements PatternLibrary
{
    public function name(): string
    {
        return 'funnel_sequence';
    }

    /**
     * @return array<int,array{key:string,name:string,category:string,weight:int,trigger:string,lever:string,markers:array<int,string>}>
     */
    public function all(): array
    {
        return [
            // ── Match & continuity across steps ───────────────────────────────────────────────
            ['key' => 'message_match', 'name' => 'Message-match anúncio→página', 'category' => 'match', 'weight' => 5,
                'trigger' => 'A página tem que ecoar a promessa exata do anúncio — senão a expectativa quebra e ela sai.',
                'lever' => 'Headline da página = continuação da headline do anúncio; mesmas palavras-chave; mesma promessa.',
                'markers' => ['as advertised', 'como anunciado', 'you clicked', 'você clicou', 'you came here', 'você veio aqui', 'the protocol you', 'o protocolo que', 'matched headline']],
            ['key' => 'continuity_ad_to_page', 'name' => 'Continuidade visual ad→bridge', 'category' => 'match', 'weight' => 3,
                'trigger' => 'Mesma estética/cor/imagem do anúncio na página remove a fricção cognitiva.',
                'lever' => 'Mesma paleta, mesma imagem-herói, mesma palavra grifada.',
                'markers' => ['same hero', 'continuity', 'consistent', 'consistente', 'matched visual', 'brand continuity', 'pixel-matched']],
            ['key' => 'awareness_alignment', 'name' => 'Alinhamento awareness→step', 'category' => 'match', 'weight' => 4,
                'trigger' => 'Cada etapa do funil pega a awareness ANTERIOR e leva pra próxima — não pula nível.',
                'lever' => 'Ad pega problem-aware → bridge eleva pra solution-aware → VSL fecha em product-aware.',
                'markers' => ['next step', 'próxima etapa', 'now that you understand', 'agora que você entendeu', 'in the video below', 'no vídeo abaixo', 'this is why']],

            // ── Micro-commitment ladder ────────────────────────────────────────────────────────
            ['key' => 'micro_commit', 'name' => 'Micro-compromisso', 'category' => 'ladder', 'weight' => 4,
                'trigger' => 'Pequenos "sim" iniciais (quiz, opt-in, watch) levam ao "sim" grande (compra).',
                'lever' => 'Comece pedindo só o vídeo/quiz — não a compra.',
                'markers' => ['watch the', 'assista', 'take the quiz', 'faça o quiz', 'see if', 'veja se', 'check your', 'verifique seu', 'free presentation', 'apresentação grátis']],
            ['key' => 'value_ladder', 'name' => 'Escada de valor', 'category' => 'ladder', 'weight' => 4,
                'trigger' => 'Tripwire → core → mid → high — cada degrau prepara o próximo, aumenta LTV.',
                'lever' => 'Mostre o caminho: kit básico → upgrade → programa premium → coaching.',
                'markers' => ['starter', 'básico', 'upgrade to', 'evolua para', 'premium tier', 'plano premium', 'next level', 'próximo nível', 'after this', 'após isso', 'continue with']],
            ['key' => 'tripwire_offer', 'name' => 'Tripwire', 'category' => 'ladder', 'weight' => 3,
                'trigger' => 'Oferta quase-grátis converte tráfego frio em comprador — o 1º sim destrava todo o resto.',
                'lever' => '$1-$7-$27 só pra entrar, depois upsell.',
                'markers' => ['$7', '$1 trial', '$27', 'just $1', 'no-brainer', 'starter offer', 'try for', 'experimente por', 'getting started']],

            // ── Order page architecture (cart → bump → OTO) ────────────────────────────────────
            ['key' => 'order_bump', 'name' => 'Order bump no checkout', 'category' => 'cart', 'weight' => 4,
                'trigger' => 'Bump no checkout pega 20-35% das vendas com 1 clique — quase grátis em CAC.',
                'lever' => 'Caixa marcada por padrão antes do botão: "Adicione X por +$Y".',
                'markers' => ['add to your order', 'adicione ao pedido', 'order bump', 'one-time offer', 'check this box', 'marque esta caixa', "yes! also add", 'sim, também quero', 'for an extra']],
            ['key' => 'oto_upsell', 'name' => 'One-time offer (upsell)', 'category' => 'cart', 'weight' => 4,
                'trigger' => 'Após o sim inicial, a guarda está baixa — upsell aqui converte 30-50%.',
                'lever' => 'Pós-checkout: "antes de você sair, oferta única, só agora".',
                'markers' => ['one-time offer', 'oferta única', 'wait!', 'espera!', "don't miss this", 'não perca', 'before you go', 'antes de sair', 'only on this page', 'só nesta página', 'last chance']],
            ['key' => 'downsell', 'name' => 'Downsell', 'category' => 'cart', 'weight' => 2,
                'trigger' => 'Quem recusou o caro pode dizer sim pro barato — captura quem ia embora.',
                'lever' => 'Após "não obrigada" no OTO, oferta menor: "talvez isso então?".',
                'markers' => ['no thanks', 'não obrigado', 'maybe this', 'talvez isso', 'lighter option', 'opção mais simples', 'starter pack', 'pacote inicial', "if that's too much"]],

            // ── Email/SMS sequence (the recovery) ───────────────────────────────────────────────
            ['key' => 'abandoned_cart', 'name' => 'Recuperação de carrinho', 'category' => 'email', 'weight' => 4,
                'trigger' => 'Quem chegou no checkout e saiu é o lead mais quente — sequência de 3 emails recupera 10-30%.',
                'lever' => 'Email 1h depois: "esqueceu algo?" + Email 24h: prova + Email 48h: bônus.',
                'markers' => ['you left', 'você deixou', 'in your cart', 'no seu carrinho', 'still available', 'ainda disponível', 'forgot something', 'esqueceu algo', 'come back', 'volte']],
            ['key' => 'reengage_sequence', 'name' => 'Sequência de re-engajamento', 'category' => 'email', 'weight' => 4,
                'trigger' => 'Lead que viu mas não comprou: sequência curiosidade → autoridade → escassez recupera fatia.',
                'lever' => '3-5 emails escalando alavancas — exatamente o EmailFollowupForge já feito.',
                'markers' => ['email 1', 'email 2', 'follow-up sequence', 'sequência de email', '3-email', 'drip campaign', 'cadence', 'cadência', 'nurture']],
            ['key' => 'post_purchase_upsell', 'name' => 'Upsell pós-compra', 'category' => 'email', 'weight' => 3,
                'trigger' => 'Quem acabou de comprar tem maior probabilidade de comprar de novo — janela de 7-14 dias.',
                'lever' => 'Email D+3: "agora que você começou, próximo passo é X" + recomendação personalizada.',
                'markers' => ['now that you', 'agora que você', 'next step', 'próximo passo', 'recommended for you', 'recomendado pra você', 'continue with', 'level up']],
            ['key' => 'winback', 'name' => 'Winback de inativos', 'category' => 'email', 'weight' => 2,
                'trigger' => 'Clientes inativos (30/60/90 dias) podem ser reativados com gancho específico + desconto.',
                'lever' => 'Sequência winback: "sentimos sua falta" + caso de uso novo + 20% off.',
                'markers' => ['we miss you', 'sentimos sua falta', "it's been a while", 'faz um tempo', 'come back with', 'volte com', 'special for you', 'especial pra você', 'reactivate']],

            // ── Retention & LTV ────────────────────────────────────────────────────────────────
            ['key' => 'onboarding_quick_win', 'name' => 'Quick-win no onboarding', 'category' => 'retention', 'weight' => 3,
                'trigger' => 'Cliente que vê resultado rápido nos primeiros 7 dias retém 3-5× mais — fecha o ciclo de compra.',
                'lever' => 'Email D+1 com 1 passo simples pra resultado imediato; reforço visual de progresso.',
                'markers' => ['day 1', 'dia 1', 'first 7 days', 'primeiros 7 dias', 'quick win', 'resultado rápido', 'first result', 'primeiro resultado', 'in 24 hours', 'em 24 horas']],
            ['key' => 'subscription_lock', 'name' => 'Trava de subscription', 'category' => 'retention', 'weight' => 3,
                'trigger' => 'Recorrência (subscribe & save) multiplica LTV e estabiliza receita.',
                'lever' => '15-20% off pra assinar; "envio automático a cada 30 dias", cancelamento fácil.',
                'markers' => ['subscribe and save', 'assine e economize', 'auto-ship', 'envio automático', 'monthly delivery', 'entrega mensal', 'cancel anytime', 'cancele a qualquer momento', 'recurring', 'recorrente']],
            ['key' => 'referral_loop', 'name' => 'Loop de referência', 'category' => 'retention', 'weight' => 2,
                'trigger' => 'Cliente referindo cliente reduz CAC e aumenta confiança — viral natural.',
                'lever' => '"Dê $20, ganhe $20" + link único; gatilho no momento de maior felicidade (D+14).',
                'markers' => ['refer a friend', 'indique um amigo', 'give $', 'dê r$', 'get $', 'ganhe r$', 'share your link', 'compartilhe seu link', 'invite', 'convide']],
        ];
    }

    /**
     * @return array<int,string>
     */
    public function categories(): array
    {
        return ['match', 'ladder', 'cart', 'email', 'retention'];
    }
}
