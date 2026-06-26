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
            ['key' => 'stage_sells_next_step', 'name' => 'Cada estágio vende o próximo passo', 'category' => 'match', 'weight' => 5,
                'trigger' => 'Cada estágio vende SÓ o próximo passo: anúncio→clique, bridge→watch da VSL, VSL→produto no fechamento. Mostrar oferta/preço/garantia/checkout no anúncio ou na bridge é ERRO DE FASE — a oferta só converte depois da crença+mecanismo+prova; revelada cedo, queima a alavanca, quebra o funil e mata a conversão.',
                'lever' => 'Anúncio vende o CLIQUE; bridge vende o VÍDEO (KPI = watch-start, NUNCA compra) e tease o mecanismo sem entregar a receita; a oferta (preço/garantia/escassez/checkout) aparece SÓ no fim da VSL. O mecanismo PRECEDE a oferta.',
                'markers' => ['watch the free', 'assista à apresentação', 'apresentação gratuita', 'free presentation', 'press play', 'aperte o play', 'no vídeo abaixo', 'continue assistindo', 'sells the next step', 'vende o próximo passo', 'phase error', 'erro de fase']],

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

            // ── Aprofundamento Volta 2: padrões raros de jornada multistep ────────────────────
            ['key' => 'tripwire_to_continuity', 'name' => 'Tripwire → continuity bridge', 'category' => 'ladder', 'weight' => 5,
                'trigger' => 'O tripwire só vale se o segundo passo for assinatura recorrente — sem ponte, o LTV morre.',
                'lever' => '"Você comprou o starter; o sistema completo é uma assinatura. Aqui está como ela funciona…"',
                'markers' => ['after your starter', 'após o seu starter', 'continue with', 'continue com', 'upgrade to monthly', 'evoluir para mensal', 'unlock the full', 'desbloqueie o completo', 'bridge to subscription']],
            ['key' => 'oto_stack', 'name' => 'Pilha de OTOs (3-5 em sequência)', 'category' => 'cart', 'weight' => 5,
                'trigger' => 'Um OTO converte 30%; três OTOs em sequência (cada um relacionado ao anterior) duplicam AOV.',
                'lever' => 'OTO1 (upgrade do core) → OTO2 (acelerador) → OTO3 (concierge/serviço) — cada um aceito ou recusado independente.',
                'markers' => ['oto 1', 'oto 2', 'oto 3', 'next offer', 'próxima oferta', 'one more offer', 'mais uma oferta', 'second exclusive', 'segunda exclusiva', 'last add-on', 'último adicional']],
            ['key' => 'refund_saver', 'name' => 'Salvador de reembolso (refund flow)', 'category' => 'retention', 'weight' => 4,
                'trigger' => 'Quem pede reembolso é resgatável — fluxo de salvamento (vídeo do CEO + extensão + downgrade) recupera 20-40%.',
                'lever' => 'Botão "quero meu dinheiro de volta" abre vídeo de 90s + oferta de 30 dias extras + downgrade pra plano básico.',
                'markers' => ['before you cancel', 'antes de cancelar', 'wait — give us 30 more', 'espera — nos dê mais 30', 'extra month free', 'mês extra grátis', 'downgrade option', 'opção de downgrade', 'refund flow', 'fluxo de reembolso']],
            ['key' => 'vsl_to_checkout_choreography', 'name' => 'Coreografia VSL → checkout', 'category' => 'match', 'weight' => 5,
                'trigger' => 'O CTA aparece no momento certo da VSL (após o "agora você entende por que funciona") — não antes, não depois.',
                'lever' => 'Pitch starts at X:YZ; botão de compra revela no mesmo segundo + escassez visível.',
                'markers' => ['pitch starts at', 'oferta começa em', 'reveal at minute', 'revelar no minuto', 'cta unlocks', 'cta desbloqueia', '/pitch.{0,20}\d+:\d{2}/iu', 'synchronized cta', 'cta sincronizado']],
            ['key' => 'sms_followup_layer', 'name' => 'Camada SMS de re-engajamento', 'category' => 'email', 'weight' => 3,
                'trigger' => 'SMS tem 98% open rate vs 20% do email — adicionar SMS na sequência multiplica recuperação.',
                'lever' => 'SMS 1h pós-abandono ("vi que você esqueceu") + SMS 24h ("Maria ganhou 41 lbs") + SMS 48h ("último dia").',
                'markers' => ['text message', 'mensagem de texto', 'sms reminder', 'lembrete sms', 'we will text', 'vamos enviar sms', 'phone optional', 'telefone opcional', 'mobile alert', 'alerta no celular']],
            ['key' => 'paid_retargeting_sequence', 'name' => 'Sequência de retargeting pago', 'category' => 'email', 'weight' => 3,
                'trigger' => 'Lead morno responde melhor a anúncio de retargeting com depoimento + objeção (não com mais oferta).',
                'lever' => 'Dia 1 retarget: depoimento. Dia 3: objeção quebrada. Dia 7: escassez.',
                'markers' => ['retargeting', 'retargeting', 'pixel fired', 'pixel disparou', 'remarketing audience', 'audiência de remarketing', 'lookback window', 'janela de lookback', 'warm traffic', 'tráfego morno']],
            ['key' => 'community_milestone', 'name' => 'Milestone na comunidade (D+30/60/90)', 'category' => 'retention', 'weight' => 3,
                'trigger' => 'Marcos celebrados (D+30 perdeu 5 lbs, D+60 entrou no grupo elite) criam retenção identitária.',
                'lever' => 'Email de marco + badge no grupo + convite pra contar a história — virou pertencimento.',
                'markers' => ['day 30 ', 'dia 30 ', 'day 60', 'dia 60', 'congratulations', 'parabéns', 'milestone reached', 'marco atingido', 'level up', 'evolua de nível', 'graduation', 'formatura', 'elite tier']],
            ['key' => 'reverse_funnel', 'name' => 'Funil reverso (high-ticket primeiro)', 'category' => 'ladder', 'weight' => 4,
                'trigger' => 'Vende high-ticket primeiro pra os 5% prontos — depois desce a escada pros 95%. AOV explode.',
                'lever' => '"Programa premium $1997. Não cabe? Aqui está a versão essencial por $97."',
                'markers' => ['premium tier first', 'tier premium primeiro', 'high ticket', 'high-ticket', '\$1,997', '\$2,997', 'flagship', 'carro-chefe', "if that's too much", 'se for muito', 'lite version', 'versão lite']],
            ['key' => 'cross_sell_at_peak', 'name' => 'Cross-sell no pico de felicidade', 'category' => 'retention', 'weight' => 3,
                'trigger' => 'Cross-sell (produto complementar) feito no pico de felicidade do cliente (após 1º resultado) converte 5-10×.',
                'lever' => '"Você perdeu 10 lbs — agora que o metabolismo está ativo, o próximo passo é colágeno."',
                'markers' => ['now that you', 'agora que você', 'next product', 'próximo produto', 'companion to', 'complemento de', 'pair with', 'combina com', 'often bought together', 'frequentemente comprados juntos', 'natural next step']],
            ['key' => 'win_back_with_story', 'name' => 'Winback narrativo (não desconto)', 'category' => 'email', 'weight' => 3,
                'trigger' => 'Winback que oferece só desconto é commodity — winback com história de cliente que voltou + resultado é 3× mais eficaz.',
                'lever' => '"Sentimos sua falta. Aqui está o que Karen fez quando voltou — 38 lbs em 90 dias."',
                'markers' => ['returned and ', 'voltou e ', 'came back to', 'voltou para', "here's what she did", 'eis o que ela fez', 'second chance', 'segunda chance', 'comeback story', 'história de retorno', 'gave it another shot']],
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
