<?php

namespace App\Services\Ai\MarketingDomain\Knowledge;

/**
 * MarketingPlaybook — the deterministic knowledge core of the affiliate / Google-Ads /
 * VSL marketing domain. This is the master file (28 skills, sourced) encoded as PURE
 * PHP data + pure lookup functions. Same input → same output, no LLM, no I/O.
 *
 * WHY deterministic: the playbook is rules, numbers and checklists — encoding it as data
 * makes Atlas's marketing judgment reproducible and certain. The LLM only fills the
 * creative slot (the actual copy line); the STRUCTURE, the levers, the numeric guardrails
 * and the diagnosis are all decided here, the same way every time.
 *
 * COMPLIANCE STANCE (operator directive — do NOT violate): Atlas never judges or gates what
 * "can or cannot be run". Policy/compliance lives here ONLY as PERFORMANCE/UPTIME knowledge
 * (advertorial with original content converts better AND keeps the account alive = more
 * profit). See accountUptimeKnowledge(): it is informational context the agent USES to do
 * better, never a portão that refuses.
 *
 * Numbers are research-directional (sources in sources()); reconcile with the live account.
 */
final class MarketingPlaybook
{
    public const VERSION = '2026-06-22';

    /**
     * The operator's canonical action space (Stage-1 decision engine). Every recommendation
     * resolves to exactly one of these — they are the moves a paid-traffic affiliate can make.
     */
    public const ACTION_CLOSE_AUDIENCE = 'close_audience';

    public const ACTION_OPEN_AUDIENCE = 'open_audience';

    public const ACTION_EDIT_VSL_HEADLINE = 'edit_vsl_headline';

    public const ACTION_EDIT_BRIDGE_HEADLINE = 'edit_bridge_headline';

    public const ACTION_RAISE_BID = 'raise_bid';

    public const ACTION_LOWER_BID = 'lower_bid';

    public const ACTION_EDIT_HOOK = 'edit_hook';

    public const ACTION_REFRESH_VSL = 'refresh_vsl';

    public const ACTION_STRENGTHEN_CLOSE = 'strengthen_close';

    public const ACTION_SPACE = [
        self::ACTION_CLOSE_AUDIENCE,
        self::ACTION_OPEN_AUDIENCE,
        self::ACTION_EDIT_VSL_HEADLINE,
        self::ACTION_EDIT_BRIDGE_HEADLINE,
        self::ACTION_RAISE_BID,
        self::ACTION_LOWER_BID,
        self::ACTION_EDIT_HOOK,
        self::ACTION_REFRESH_VSL,
        self::ACTION_STRENGTHEN_CLOSE,
    ];

    // ─────────────────────────────────────────────────────────────────────────────
    // PSYCHOLOGY & PERSUASION
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Cialdini's 7 principles of influence — the persuasion-auditor checklist. Each VSL/page
     * is audited for which are present and where the missing ones go.
     *
     * @return array<int,array<string,string>>
     */
    public function persuasionPrinciples(): array
    {
        return [
            ['key' => 'reciprocity', 'name' => 'Reciprocidade', 'lever' => 'dar valor antes de pedir (lead magnet, conteúdo do advertorial)', 'vsl_insertion' => 'entregue uma micro-vitória/insight grátis no início'],
            ['key' => 'commitment', 'name' => 'Compromisso/Coerência', 'lever' => 'micro-sins (quiz, "sim" pequenos) antes do pedido grande', 'vsl_insertion' => 'perguntas retóricas de "sim" cedo; quiz de qualificação'],
            ['key' => 'social_proof', 'name' => 'Prova Social', 'lever' => 'depoimentos específicos, números de usuários, reviews', 'vsl_insertion' => 'prova logo após o mecanismo; perto do CTA'],
            ['key' => 'authority', 'name' => 'Autoridade', 'lever' => 'credenciais, endossos de terceiros, citações de estudo', 'vsl_insertion' => 'estabeleça autoridade do "herói relutante" no bloco história'],
            ['key' => 'liking', 'name' => 'Afinidade', 'lever' => 'herói relutante igual à audiência; linguagem do prospect', 'vsl_insertion' => 'hook + história espelham a vida do prospect'],
            ['key' => 'scarcity', 'name' => 'Escassez', 'lever' => 'urgência/escassez REAL (estoque, prazo, bônus)', 'vsl_insertion' => 'bloco escassez antes do CTA final'],
            ['key' => 'unity', 'name' => 'Unidade', 'lever' => 'identidade compartilhada ("nós, que…")', 'vsl_insertion' => 'enquadre como tribo/identidade no fechamento'],
        ];
    }

    /**
     * Life Force 8 (Cashvertising) — 8 hardwired desires. Strong copy attacks ≥1.
     *
     * @return array<int,array<string,string>>
     */
    public function lifeForce8(): array
    {
        return [
            ['key' => 'survival', 'desire' => 'Sobrevivência / extensão da vida', 'angle' => 'longevidade, saúde, evitar a morte'],
            ['key' => 'food_drink', 'desire' => 'Comida e bebida', 'angle' => 'prazer, satisfação, dieta sem sacrifício'],
            ['key' => 'freedom_from_fear', 'desire' => 'Livre de medo/dor/perigo', 'angle' => 'segurança, alívio de dor, remover a ameaça'],
            ['key' => 'sexual', 'desire' => 'Companhia sexual', 'angle' => 'atração, confiança, desejabilidade'],
            ['key' => 'comfort', 'desire' => 'Condições de vida confortáveis', 'angle' => 'conforto, conveniência, sem esforço'],
            ['key' => 'superiority', 'desire' => 'Ser superior / vencer (Joneses)', 'angle' => 'status, ganhar, ser melhor que os outros'],
            ['key' => 'protect_loved', 'desire' => 'Proteger entes queridos', 'angle' => 'família, filhos, prover/defender'],
            ['key' => 'social_approval', 'desire' => 'Aprovação social', 'angle' => 'pertencer, ser aceito, admirado'],
        ];
    }

    /**
     * Blair Warren's One Sentence Persuasion (5 emotional moves) + Robert Collier's
     * "enter the conversation already in the prospect's mind". The emotional mold of
     * highest-converting VSL/advertorial.
     *
     * @return array<string,mixed>
     */
    public function emotionalFrames(): array
    {
        return [
            'blair_warren_one_sentence' => [
                'encourage_dreams' => 'encoraje os sonhos deles',
                'justify_failures' => 'justifique os fracassos deles (a culpa não é sua)',
                'allay_fears' => 'acalme os medos deles',
                'confirm_suspicions' => 'confirme as suspeitas deles',
                'throw_rocks' => 'ajude a jogar pedras nos inimigos deles (o vilão comum)',
            ],
            'robert_collier' => 'Entre na conversa que JÁ está rodando na cabeça do prospect — enderece a preocupação/desejo que já existe (casa com Schwartz).',
            'sugarman_slippery_slide' => 'Cada frase só serve pra fazer ler a próxima — primeira frase curta; fluência > completude.',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // AWARENESS ROUTING (Schwartz × Great Leads) — the awareness-router skill
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Eugene Schwartz's 5 awareness states, each mapped to the recommended Great-Leads
     * lead type, the opening move, and the keyword/traffic intent that signals it. This
     * is the deterministic core of the awareness-router.
     *
     * @return array<string,array<string,string>>
     */
    public function awarenessStates(): array
    {
        return [
            'unaware' => [
                'lead_type' => 'story', 'opening_move' => 'história/narrativa que entra pela dor latente',
                'keyword_intent' => 'tráfego de descoberta (YouTube/Discovery frio); termos amplos de problema',
                'sophistication_default' => 'unique_mechanism',
            ],
            'problem_aware' => [
                'lead_type' => 'problem_solution', 'opening_move' => 'agite o problema vívido, depois revele o caminho',
                'keyword_intent' => '"how to fix [problema]", sintomas, "por que [problema]"',
                'sophistication_default' => 'unique_mechanism',
            ],
            'solution_aware' => [
                'lead_type' => 'big_secret', 'opening_move' => 'lidere com o MECANISMO único (o "como" diferente)',
                'keyword_intent' => '"melhor [tipo de solução]", "[categoria] que funciona"',
                'sophistication_default' => 'mechanism',
            ],
            'product_aware' => [
                'lead_type' => 'promise', 'opening_move' => 'lidere com a promessa/prova específica do produto',
                'keyword_intent' => '"[produto] review", "[produto] funciona", "[produto] vs"',
                'sophistication_default' => 'amplified_claim',
            ],
            'most_aware' => [
                'lead_type' => 'offer', 'opening_move' => 'lidere com a OFERTA (preço/bônus/garantia)',
                'keyword_intent' => '"[produto] official", "[produto] desconto/cupom", marca exata',
                'sophistication_default' => 'claim',
            ],
        ];
    }

    /**
     * Great Leads — the 6 lead types (Masterson/Ford + Forde): how to open ANY sales message,
     * chosen by awareness.
     *
     * @return array<string,string>
     */
    public function greatLeads(): array
    {
        return [
            'offer' => 'Abre direto com a oferta — só p/ most-aware.',
            'promise' => 'Abre com a maior promessa/benefício — product-aware.',
            'problem_solution' => 'Agita o problema e revela a solução — problem-aware.',
            'big_secret' => 'Abre com um segredo/mecanismo intrigante — solution-aware.',
            'proclamation' => 'Abre com uma declaração ousada/contrária — desperta.',
            'story' => 'Abre com narrativa dramática (reluctant hero) — unaware/frio.',
        ];
    }

    /**
     * Market sophistication (Schwartz) — how tired the market is; dictates what you lead with.
     *
     * @return array<string,string>
     */
    public function sophisticationStages(): array
    {
        return [
            'claim' => 'Estágio 1: mercado virgem — lidere com o claim direto.',
            'amplified_claim' => 'Estágio 2: lidere com claim ampliado/maior.',
            'mechanism' => 'Estágio 3: claims cansados — lidere com o mecanismo (o "como").',
            'unique_mechanism' => 'Estágio 4: mecanismos cansados — lidere com mecanismo ÚNICO/nomeado (destrava nichos saturados: emagrecimento/ED/finanças).',
            'identification' => 'Estágio 5: lidere com identidade/identificação do prospect.',
        ];
    }

    /**
     * Deterministic awareness-router: given an awareness key, return the prescribed lead.
     * Unknown/empty → safest default for cold paid traffic (problem_aware + unique mechanism).
     *
     * @return array<string,string>
     */
    public function routeAwareness(?string $awareness): array
    {
        $key = strtolower(trim((string) $awareness));
        $states = $this->awarenessStates();
        $state = $states[$key] ?? $states['problem_aware'];
        $leadType = $state['lead_type'];

        return [
            'awareness' => $states[$key] ?? null ? $key : 'problem_aware',
            'lead_type' => $leadType,
            'lead_how' => $this->greatLeads()[$leadType] ?? '',
            'opening_move' => $state['opening_move'],
            'keyword_intent' => $state['keyword_intent'],
            'sophistication' => $state['sophistication_default'],
            'sophistication_how' => $this->sophisticationStages()[$state['sophistication_default']] ?? '',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // VSL ANATOMY — the vsl-architect skill
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Canonical VSL blocks in order. Each block carries the rule that makes it convert and
     * the funnel symptom that appears when it is weak — this is what ties the anatomy to the
     * symptom→action tree (which block to edit for which symptom).
     *
     * @return array<int,array<string,string>>
     */
    public function vslAnatomy(): array
    {
        return [
            ['block' => 'hook', 'purpose' => 'parar o skip nos primeiros 5s (VSL) / 3s', 'rule' => 'pattern-interrupt: stat surpreendente, claim ousado ou pergunta provocativa; lidere com a maior dor OU o maior benefício', 'weak_symptom' => 'high VSL plays, low watch-through'],
            ['block' => 'agitate', 'purpose' => 'tornar o problema vívido', 'rule' => 'descreva a dor tão específica que "parece que leu o histórico do navegador dele"', 'weak_symptom' => 'low watch-through no 1º terço'],
            ['block' => 'problem_mechanism', 'purpose' => 'explicar a CAUSA real', 'rule' => 'nomeie a causa-raiz que ninguém mais aponta', 'weak_symptom' => 'desengajamento no meio'],
            ['block' => 'solution_mechanism', 'purpose' => 'o mecanismo único', 'rule' => 'NOMEIE o mecanismo, reivindique como o ÚNICO próximo passo lógico (Schwartz sofisticação)', 'weak_symptom' => 'assiste mas não acredita / não chega no pitch'],
            ['block' => 'proof', 'purpose' => 'credibilidade', 'rule' => 'prova específica de terceiros: estudos, depoimentos, prints reais', 'weak_symptom' => 'high watch, low order (cético)'],
            ['block' => 'offer', 'purpose' => 'value stack', 'rule' => 'empilhe valor ancorado (Grand Slam); o preço parece pequeno ao lado do valor', 'weak_symptom' => 'chega no pitch, não compra (oferta fraca)'],
            ['block' => 'guarantee', 'purpose' => 'inversão de risco', 'rule' => 'garantia que tira o medo de perda (risk reversal)', 'weak_symptom' => 'hesitação no checkout'],
            ['block' => 'scarcity', 'purpose' => 'urgência real', 'rule' => 'escassez verdadeira (prazo/estoque/bônus que some)', 'weak_symptom' => 'adia a decisão / carrinho frio'],
            ['block' => 'cta', 'purpose' => 'UM próximo passo', 'rule' => 'um único CTA claro, não três', 'weak_symptom' => 'cliques dispersos, baixa conversão de checkout'],
            ['block' => 'ps_objections', 'purpose' => 'derrubar objeções restantes', 'rule' => 'P.S. que responde a maior objeção remanescente', 'weak_symptom' => 'abandono pós-pitch'],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // OFFER — offer-doctor + grand-slam-builder
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Hormozi Value Equation — score an offer on 4 terms; the leverage is in the DENOMINATOR.
     *
     * @return array<string,mixed>
     */
    public function valueEquation(): array
    {
        return [
            'formula' => 'Valor = (Resultado Sonhado × Probabilidade Percebida) ÷ (Tempo até o Resultado × Esforço/Sacrifício)',
            'terms' => [
                'dream_outcome' => ['side' => 'numerador', 'raise' => 'pinte o resultado maior/mais vívido'],
                'perceived_likelihood' => ['side' => 'numerador', 'raise' => 'mais prova, garantia, casos, mecanismo crível'],
                'time_delay' => ['side' => 'denominador', 'shrink' => 'torne o primeiro resultado IMEDIATO (quick win)'],
                'effort_sacrifice' => ['side' => 'denominador', 'shrink' => 'remova atrito/passos/esforço — "done for you"'],
            ],
            'insight' => 'As melhores ofertas focam o DENOMINADOR (imediato + sem esforço). O numerador é fácil de inflar; o denominador é a vantagem real.',
        ];
    }

    /**
     * Grand Slam Offer stack + pricing psychology — raise CVR without touching traffic.
     *
     * @return array<string,mixed>
     */
    public function grandSlam(): array
    {
        return [
            'stack' => [
                'bonuses' => 'bônus com valor ancorado (cada um resolve uma objeção/obstáculo)',
                'guarantee' => 'garantia que inverte o risco (incondicional > condicional > "melhor que dinheiro de volta")',
                'scarcity' => 'escassez/urgência reais (quantidade, prazo, bônus que some)',
                'naming' => 'nome MAGIC pro mecanismo/oferta (memorável, proprietário)',
            ],
            'pricing_psychology' => [
                'decoy' => 'Decoy/dominância assimétrica: 3ª opção faz uma das 2 parecer melhor (order bump/pricing table).',
                'charm' => 'Charm pricing ($9,99 = viés do dígito esquerdo).',
                'anchoring' => 'Ancoragem: mostre o preço alto/valor total ANTES do preço real.',
                'risk_reversal' => 'Risk reversal: a garantia tira o medo de perda — alavanca direta de CVR.',
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // PAGES & BRIDGE — bridge-builder, page-architect, quiz-funnel-builder, funnel-architect
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Advertorial / presell bridge spec — the #1 cold-traffic format (listicle).
     *
     * @return array<string,mixed>
     */
    public function advertorialSpec(): array
    {
        return [
            'format' => 'listicle advertorial',
            'title_pattern' => '"X Razões Por Que [público] Está Trocando Para [produto]" / "X Formas Que [produto] Resolve [problema]"',
            'items' => '5–10 itens',
            'length' => '500–1.500 palavras, layout de artigo',
            'ctr_edge' => '~70% dos títulos listicle têm CTR maior que não-listicle',
            'why_converts' => 'leitor frio está em modo CONSUMIR, não comprar; lidera com informação e ganha confiança antes de pedir → remove atrito',
            'elements' => ['bullets/números/ícones simples', 'prova social (depoimentos, reviews, logos)', 'conteúdo original que ajuda mesmo sem links de saída'],
        ];
    }

    /**
     * Message match / ad scent — the #1 page conversion lever (4 dimensions).
     *
     * @return array<string,mixed>
     */
    public function messageMatch(): array
    {
        return [
            'dimensions' => [
                'visual_match' => 'o design da página ecoa o criativo do anúncio',
                'message_match' => 'o H1 da página espelha a HEADLINE do anúncio (alavanca nº1)',
                'information_scent' => 'mesmas keywords/dores visíveis no 1º viewport',
                'tone_consistency' => 'parece escrito pela mesma pessoa do anúncio',
            ],
            'rule' => 'H1 da página = headline do anúncio. 68% dos anunciantes mandam pago pra home genérica — erro que joga conversão fora.',
        ];
    }

    /**
     * High-converting page anatomy + the 5 highest-impact levers + benchmarks.
     *
     * @return array<string,mixed>
     */
    public function pageAnatomy(): array
    {
        return [
            'above_the_fold' => 'value-prop ESPECÍFICO e numérico (3-5s pra convencer) + CTA claro + screenshot/demo/vídeo',
            'cta' => '2–4 colocações (topo/meio/fim); sem cor mágica — regra é CONTRASTE',
            'trust' => 'logos/star-rating/métrica-chave/depoimentos ABAIXO do hero (antes de rolar)',
            'top_levers' => [
                'headline = anúncio (message match)',
                'menos campos no form',
                'load < 2,5s (mobile = 83% do tráfego)',
                'prova social perto do CTA',
                '1 objetivo de conversão por página',
            ],
            'benchmarks' => ['median_cvr' => '6,6% (Unbounce Q4 2024)', 'top_quartile' => '>10%', 'speed' => '<2s converte +47%'],
        ];
    }

    /**
     * Quiz funnel — the highest-converting capture page (30%+).
     *
     * @return array<string,mixed>
     */
    public function quizFunnel(): array
    {
        return [
            'conversion' => 'quiz funnels veem 30%+ (vs 2-3% média); ~40% de quem começa vira lead',
            'mechanic' => 'capture o lead CEDO — opt-in (email/telefone) ANTES de revelar o resultado; exit-intent recupera abandono',
            'length' => '3–7 perguntas = 65-85% de conclusão (máx 7-10 com promessa clara no título)',
        ];
    }

    /**
     * Value Ladder (Brunson) — funnel page types in sequence. For affiliates, you own the
     * advertorial/quiz/squeeze; the product is the producer's checkout.
     *
     * @return array<string,string>
     */
    public function valueLadder(): array
    {
        return [
            'squeeze' => '1 objetivo (email), sem navegação/distração',
            'advertorial' => 'pré-venda editorial (sua página, conteúdo original)',
            'sales_page' => 'a VSL / sales page da oferta',
            'order_bump' => 'checkbox no checkout, margem alta',
            'oto_upsell' => 'One-Time Offer / upsell / downsell pós-compra',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // VIDEO CREATIVE — video-ad-architect + creative-pipeline
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * YouTube AD structure (not the whole VSL) + retention rules.
     *
     * @return array<string,mixed>
     */
    public function videoAdStructure(): array
    {
        return [
            'blocks' => [
                'hook' => '0-5s — para o skip; SEM logo/marca nos primeiros 2s (sinaliza "ad")',
                'amplify' => '5-20s — aprofunda dor/desejo',
                'bridge' => '20-45s — entra o produto + prova',
                'cta' => '10-15s final — um próximo passo',
            ],
            'retention_rules' => [
                'double hook (2º pico de curiosidade em 5-10s contra o drop)',
                'pattern interrupt no 4s reduz skip 15-25%',
                'mudança visual a cada poucos segundos',
                'UGC-style (handheld, 1ª pessoa) bate polido em até 3× de completion',
                'DR com arco de persuasão claro converte 2-3× mais que awareness',
            ],
        ];
    }

    /**
     * AI creative tooling for test velocity (the #1 scale lever on YouTube).
     *
     * @return array<string,string>
     */
    public function creativeTools(): array
    {
        return [
            'HeyGen' => 'avatares multilíngue + lip-sync (ótimo p/ VSL em PT-BR)',
            'Arcads' => '1.000+ atores IA + vozes ElevenLabs; batch UGC p/ testar 50+ hooks/semana (feito p/ DR)',
            'Creatify' => 'URL→vídeo (cola a página → variações em lote)',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // GOOGLE ADS STRUCTURE — keyword-intent-mapper, account-structurer, rsa-writer
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Named account structures for Smart Bidding (consolidate to feed the algorithm).
     *
     * @return array<string,string>
     */
    public function accountStructures(): array
    {
        return [
            'hagakure' => 'Consolidar: 1 campanha por oferta, ad groups por tema — alimenta o Smart Bidding com volume.',
            'alpha_beta' => 'Beta = broad de descoberta; Alpha = exatas vencedoras migradas do Beta (com Alpha como exact-negatives no Beta).',
            'stag' => 'Single-Theme Ad Group (sucessor do SKAG) — temas, não keyword única.',
            'n_gram' => 'Análise n-gram dos search terms pra minerar/agrupar/negativar.',
            'meta_today' => 'Broad match + Smart Bidding é o meta atual (com conversão de VENDA bem medida).',
        ];
    }

    /**
     * Keyword intent buckets → which lead the bridge/VSL should use for each.
     *
     * @return array<string,array<string,string>>
     */
    public function keywordIntentBuckets(): array
    {
        return [
            'brand_official' => ['intent' => 'most-aware', 'bridge_lead' => 'oferta/preço (offer lead)'],
            'review' => ['intent' => 'product-aware', 'bridge_lead' => 'promessa/prova (promise lead)'],
            'scam_complaint' => ['intent' => 'objection-stage', 'bridge_lead' => 'confirmar suspeita + prova (Blair Warren)'],
            'solution_category' => ['intent' => 'solution-aware', 'bridge_lead' => 'mecanismo único (big secret)'],
            'problem_symptom' => ['intent' => 'problem-aware', 'bridge_lead' => 'agita problema → solução'],
        ];
    }

    /**
     * RSA spec + Quality Score levers.
     *
     * @return array<string,mixed>
     */
    public function rsaSpec(): array
    {
        return [
            'headlines' => '15 headlines por tema (≤30 caracteres)',
            'descriptions' => '4 descriptions (≤90 caracteres)',
            'pinning' => 'pinar só o que for obrigatório por estrutura/política',
            'quality_score' => '3 fatores: expected CTR + ad relevance + landing page experience → QS alto baixa o CPC',
        ];
    }

    /**
     * Audience signals usable on Search/Demand Gen.
     *
     * @return array<string,string>
     */
    public function audienceSignals(): array
    {
        return [
            'rlsa' => 'Remarketing Lists for Search Ads (carrinho/abandoners)',
            'customer_match' => 'listas de clientes (first-party)',
            'custom_segments' => 'custom/in-market/affinity por tema',
            'optimized_targeting' => 'expande além do alvo: +20% conversões ao mesmo custo (Demand Gen)',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // BIDDING knowledge (VBB / seasonality / data exclusions) — feeds BidStrategyDecider
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Value-Based Bidding knowledge.
     *
     * @return array<string,string>
     */
    public function valueBasedBidding(): array
    {
        return [
            'what' => 'tROAS otimiza por VALOR da conversão (não contagem) — mande o valor real da venda de volta.',
            'edge' => 'trocar tCPA→tROAS = +14% conversion value no mesmo ROAS médio.',
            'requires' => 'conversões com valor (postback financeiro / Enhanced Conversions com valor).',
        ];
    }

    /**
     * Seasonality adjustments knowledge (with the common-error warning).
     *
     * @return array<string,string>
     */
    public function seasonalityAdjustments(): array
    {
        return [
            'when' => 'eventos curtos de 1–7 dias (sale, lançamento) com mudança esperada de conv-rate.',
            'common_error' => 'ERRO comum: calibrar pra conv-rate em vez do CPC esperado → drena budget.',
            'not_for' => 'NÃO usar pra tendências longas — só picos curtos previsíveis.',
        ];
    }

    /**
     * Data exclusions knowledge (critical for affiliates when the postback breaks).
     *
     * @return array<string,string>
     */
    public function dataExclusions(): array
    {
        return [
            'what' => 'mande o Smart Bidding IGNORAR dias com tracking quebrado.',
            'why' => 'crítico p/ afiliado quando o postback/Data Manager cai — evita o algoritmo aprender com dado falso.',
            'how' => 'estenda a janela pelo seu conversion-delay ao excluir.',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // DEMAND GEN (YouTube) — demand-gen-architect (was 🔲)
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Demand Gen 2025/2026 best practices. Adopting ≥3 of the 4 = +40% conversions avg.
     *
     * @return array<string,mixed>
     */
    public function demandGen(): array
    {
        return [
            'bidding' => 'tROAS é a estratégia primária (mudança 2025 — migrou de tCPA).',
            'best_practices' => [
                'troas_bidding' => 'usar tROAS',
                'consolidated_campaign' => '1 campanha consolidada (aprende mais rápido; quebre em ad groups só p/ insight)',
                'audience_signals' => 'lookalikes + customer lists + optimized targeting (+20% conv ao mesmo custo)',
                'creative_volume' => 'até 5 vídeos por anúncio, buscar Ad Strength "Excellent"',
            ],
            'new_customer_acquisition' => 'meta NCA prioriza/licita diferente p/ quem nunca converteu (first-party audience).',
            'expected_lift' => 'adotar ≥3 das 4 best-practices = +40% conversões em média.',
            'creative_is_lever_1' => 'no YouTube o criativo é a alavanca nº1 de escala — pipeline de hooks (HeyGen/Arcads).',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // CONVERSION PIPELINE — conversion-pipeline + tracking-setup
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * The conversion loop: send the REAL SALE back so Smart Bidding optimizes for profit.
     * NOTE the deadline is already in effect (today > 2026-06-15): build on Data Manager API.
     *
     * @return array<string,mixed>
     */
    public function conversionPipeline(): array
    {
        return [
            'principle' => 'Mande a VENDA real (não o lead) de volta pro Google — senão o Smart Bidding compra volume, não lucro. É o teto silencioso da escala.',
            'oci_gclid' => 'Ligue auto-tagging → capture o GCLID na URL do clique → guarde GCLID + dados do lead → suba a venda real depois.',
            'enhanced_conversions' => 'Enhanced Conversions for leads = GCLID + first-party data (email/telefone hash SHA-256) → +10% conversões (median) vs OCI puro.',
            'data_manager_api' => [
                'status' => 'EM VIGOR desde 2026-06-15 — OCI + Enhanced Conversions for leads migraram pro Data Manager API e estão BLOQUEADOS na Google Ads API.',
                'action' => 'Construir/operar no Data Manager API (a integração legada Salesforce encerrou 2025-05-31).',
            ],
            'postback' => 'ClickBank S2S postback (cookieless, financeiro): macros {tid}/{click_id}/{fbclid}/{extclid} → otimizar por receita/comissão, não contagem.',
            'attribution_stack' => 'pós-iOS14: Hyros (determinístico/high-ticket), TripleWhale/Polar (DTC), Wicked, Northbeam. GA4 é suplementar, NÃO fonte de bid. Parear sempre com CAPI + server-side (GTM SS) + Consent Mode v2.',
            'trackers_by_volume' => 'Binom (self-hosted ~10M cliques/dia, ~$119/mo), Voluum, RedTrack, ClickMagick.',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // TEST MATH & SCALE — cro-tester + scale-operator
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Statistical test/decision math — when to kill/scale (anti-Goodhart: don't act on noise).
     *
     * @return array<string,mixed>
     */
    public function testMath(): array
    {
        return [
            'confidence' => 'decidir só com ≥95% de confiança (5% de chance de ruído)',
            'min_sample' => '~50 conversões/variação + 7 dias',
            'sample_for_lift' => ['lift_20pct' => '~400 conv/variante', 'lift_10pct' => '~1.600 conv/variante'],
            'rules' => [
                'vencedor 95% → aplicar em 3-5 dias (atraso destrói ganho composto)',
                'perdedor/inconclusivo → descartar e testar a próxima hipótese',
                'NUNCA cortar o teste cedo (= agir em ruído)',
            ],
            'prioritization' => 'ICE/PIE: testar 1 elemento de alto impacto por vez (headline, hero, CTA, oferta)',
        ];
    }

    /**
     * Scale rules — ramp without breaking learning.
     *
     * @return array<string,mixed>
     */
    public function scaleRules(): array
    {
        return [
            'vertical' => 'subir budget só +10-20% por passo, espaçado 7-14 dias (<10% é o mais seguro). Pulo grande sobe CPA 25-50% + reseta learning.',
            'horizontal' => 'duplicar pra novas audiências/geos/criativos em vez de chocar o vencedor.',
            'creative_velocity' => 'criativo é a alavanca nº1 de escala no YouTube — pipeline de hooks/ângulos.',
            'billing_caps' => 'Google gasta até 2× o budget diário num dia; teto mensal = 30,4× o diário.',
            'what_breaks' => ['saturação de audiência', 'fadiga de criativo', 'learning reset', 'CPA creep', 'queda de qualidade do tráfego escalado'],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // EMAIL ARC — email-arc (was 🔲)
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Backend email arc (multiplies EPC without more traffic): Soap Opera → Seinfeld + deliverability.
     *
     * @return array<string,mixed>
     */
    public function emailArc(): array
    {
        return [
            'soap_opera_sequence' => 'André Chaperon: 7-10 dias de onboarding com "personagem atraente" + open loops entre emails.',
            'seinfeld' => 'Ben Settle: daily broadcast (entretém + vende) DEPOIS do SOS.',
            'arc' => 'SOS (7-10 dias) → daily Seinfeld',
            'why' => 'captura o lead que não comprou na 1ª e monetiza de novo — multiplica o EPC sem mais tráfego.',
            'deliverability' => [
                'auth' => 'SPF + DKIM + DMARC obrigatório (Gmail bulk desde 01/02/2024); full auth = 2,7× inbox; faltar DKIM custa 10-15%',
                'warmup' => '2-3 semanas por domínio: comece 3 emails/dia, suba 2-3/dia, cap 30/dia',
                'metrics' => 'bounce <2%, hard bounce ≤1%, spam complaints <0,1%, volume diário consistente',
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // ACCOUNT UPTIME — compliance reframed as PERFORMANCE, NEVER a gate
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Account-survival knowledge as PERFORMANCE/UPTIME context — informational only.
     *
     * IMPORTANT (operator directive): this is NOT a gate and NEVER refuses an action. Atlas
     * does not judge what can be run. This block exists so the agent makes choices that
     * convert AND keep the account alive (uptime = more profit). It is advice the operator
     * USES, never a portão.
     *
     * @return array<string,mixed>
     */
    public function accountUptimeKnowledge(): array
    {
        return [
            'is_gate' => false,
            'framing' => 'Conhecimento de PERFORMANCE/uptime, não julgamento. Atlas não recusa nada — informa o que converte E mantém a conta no ar (uptime = lucro).',
            'advertorial_uptime' => 'Advertorial com conteúdo ORIGINAL (ajuda mesmo sem links de saída) converte melhor E é o formato que sobrevive à política de "Insufficient Original Content" → mais dias no ar = mais lucro.',
            'bridge_test' => 'Heurística de qualidade (não bloqueio): "a página ajudaria o usuário se TODOS os links de saída sumissem?" Se sim, é uma página forte — converte mais e dura mais.',
            'circumventing_is_zero_uptime' => 'Cloaking/redirect enganoso/nova conta após enforcement = suspensão sem aviso, permanente, ligada por payment/IP/email/domínio. Uptime ZERO = ROI zero. É fato de performance, não moral.',
            'appeal' => 'Se suspender: appeal DOCUMENTADO = 85-90% sucesso (vs <30% genérico), janela de 6 meses → guardar evidência (página, substanciação) antes ajuda o uptime.',
            'ftc_substantiation' => 'Claims de saúde/finanças com substanciação e disclosure do resultado típico = anúncio que NÃO é reprovado → mais uptime. (Conhecimento de durabilidade, não recusa.)',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // PROVENANCE
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Source provenance for the major numeric claims (research-directional; confirm live).
     *
     * @return array<string,string>
     */
    public function sources(): array
    {
        return [
            'smart_bidding_thresholds' => 'groas.com (tCPA≥30/30d, tROAS≥15/30d, learning ~50 conv)',
            'budget_ramp' => 'dilate.com.au / optmyzr.com (10-20%/7-14d; jump = +25-50% CPA)',
            'value_based_bidding' => 'optmyzr.com (tCPA→tROAS = +14% conversion value)',
            'conversion_pipeline' => 'developers.google.com (OCI/Enhanced/Data Manager API), support.clickbank.com (postback)',
            'demand_gen' => 'support.google.com 14693848 + searchengineland/definedigital (tROAS 2025, +40% conv)',
            'advertorial' => 'convertibles.dev (listicle ~70% CTR), adbeat.com',
            'message_match' => 'cxl.com / adalign.io (H1=ad headline)',
            'page_anatomy' => 'cxl.com / apexure.com / unbounce (median 6,6%, top >10%)',
            'quiz_funnel' => 'personizely.net / typebot.com (30%+ conv)',
            'test_math' => 'convert.com / thread-transfer.com (95%, 50/var+7d, 400/var @20% lift)',
            'email_deliverability' => 'smartlead.ai / trulyinbox.com (SPF/DKIM/DMARC = 2,7× inbox)',
            'value_equation' => '$100M Offers (Hormozi)',
            'awareness' => 'Breakthrough Advertising (Schwartz) + Great Leads (Masterson/Ford/Forde)',
            'account_survival' => 'support.google.com adspolicy 15938075/9841640 + ftc.gov (uptime knowledge, not a gate)',
        ];
    }
}
