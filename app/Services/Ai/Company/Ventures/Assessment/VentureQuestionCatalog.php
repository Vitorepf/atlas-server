<?php

namespace App\Services\Ai\Company\Ventures\Assessment;

use App\Models\AiVentureQuestionAnswer;

/**
 * The canonical catalog of questions Atlas must ASK, ANSWER (with honest data)
 * and ACT on to take a company from 0 to 1 billion — autonomously.
 *
 * This class is the SOURCE OF TRUTH; the "mother doc"
 * (atlas-venture-zero-to-billion-question-catalog.md) is generated from it so
 * the prose and the runtime never drift. Every question declares:
 *   - the dimension and the stages where it bites;
 *   - the kind of data that honestly answers it (internal code / internal
 *     metric / external source) — so the engine never pretends to know what it
 *     cannot; an external_source is the explicit bridge to autonomy (wire that
 *     source -> the question becomes answerable -> Atlas can act);
 *   - the decision the answer should trigger;
 *   - how dangerous it is to fly blind on it.
 */
class VentureQuestionCatalog
{
    public const DIM_PROBLEM = 'problem_market';

    public const DIM_USERS = 'users_icp';

    public const DIM_PRODUCT = 'product_differentiation';

    public const DIM_COMPETITION = 'competition';

    public const DIM_GROWTH = 'growth_acquisition';

    public const DIM_RETENTION = 'retention_engagement';

    public const DIM_MONETIZATION = 'monetization_economics';

    public const DIM_HEALTH = 'product_health';

    public const DIM_FOCUS = 'prioritization_focus';

    public const DIM_FINANCE = 'financials_runway';

    public const DIM_EXECUTION = 'execution_velocity';

    public const DIM_RISK = 'risk_compliance';

    /** Human labels for each dimension (for reports + the mother doc). */
    public const DIMENSION_LABELS = [
        self::DIM_PROBLEM => 'Problema & Mercado',
        self::DIM_USERS => 'Usuários & ICP',
        self::DIM_PRODUCT => 'Produto & Diferencial',
        self::DIM_COMPETITION => 'Concorrência',
        self::DIM_GROWTH => 'Crescimento & Aquisição',
        self::DIM_RETENTION => 'Retenção & Engajamento',
        self::DIM_MONETIZATION => 'Monetização & Unit Economics',
        self::DIM_HEALTH => 'Saúde do Produto (o que está quebrando)',
        self::DIM_FOCUS => 'Priorização & Foco',
        self::DIM_FINANCE => 'Finanças & Runway',
        self::DIM_EXECUTION => 'Execução & Velocidade',
        self::DIM_RISK => 'Risco & Compliance',
    ];

    /** External data sources that, once wired, unlock blocked questions (autonomy bridge). */
    public const EXTERNAL_SOURCES = [
        'payment_stripe' => 'Stripe (receita real, MRR, churn de pagamento, planos)',
        'web_analytics' => 'Analytics de produto (ativação, uso de features, funil, DAU/MAU)',
        'support_tickets' => 'Suporte / tickets (o que os usuários reclamam)',
        'product_reviews' => 'Reviews públicos / lojas (satisfação, queixas, elogios)',
        'nps_survey' => 'Pesquisa NPS / CSAT (lealdade, voz do cliente)',
        'market_research' => 'Pesquisa de mercado (TAM/SAM/SOM, tendências)',
        'competitor_intel' => 'Inteligência de concorrência (posicionamento, preço, features rivais)',
        'ads_platform' => 'Plataformas de anúncio (CAC por canal, ROAS, gasto)',
        'sales_crm' => 'CRM / pipeline de vendas (conversão, ciclo, motivos de perda)',
        'social_listening' => 'Escuta social (menções, sentimento, demanda emergente)',
    ];

    /**
     * The full question catalog.
     *
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        $q = [];

        // ── Problema & Mercado ─────────────────────────────────────────────
        $q[] = $this->q('Q-PROB-001', self::DIM_PROBLEM, 'all', 'critical',
            'Qual problema o produto resolve e ele é real, frequente e doloroso?',
            'Sem um problema real e doloroso, todo crescimento é construído sobre areia.',
            AiVentureQuestionAnswer::DATA_INTERNAL_CODE, 'venture.idea(problem/icp/pain) + comprehension.business_rule', null,
            'Se o problema não está validado com evidência, focar discovery antes de escalar.');
        $q[] = $this->q('Q-PROB-002', self::DIM_PROBLEM, ['S0', 'S1'], 'high',
            'O mercado é grande o suficiente para sustentar 1B (TAM/SAM/SOM)?',
            'Um problema real num mercado pequeno limita o teto; 1B exige mercado grande ou expansível.',
            AiVentureQuestionAnswer::DATA_EXTERNAL_REQUIRED, 'pesquisa de mercado', 'market_research',
            'Se o mercado é pequeno, redefinir o problema/segmento para um teto maior, ou pivotar.');
        $q[] = $this->q('Q-PROB-003', self::DIM_PROBLEM, 'all', 'high',
            'Por que agora? Que mudança torna esse problema urgente neste momento?',
            'Timing de mercado separa vencedores de quem chegou cedo ou tarde demais.',
            AiVentureQuestionAnswer::DATA_EXTERNAL_REQUIRED, 'pesquisa de mercado + escuta social', 'market_research',
            'Ancorar a narrativa e o GTM na mudança que cria urgência agora.');

        // ── Usuários & ICP ─────────────────────────────────────────────────
        $q[] = $this->q('Q-USER-001', self::DIM_USERS, 'all', 'critical',
            'Quem exatamente é o usuário (ICP) e quem paga?',
            'Vender para todo mundo é vender para ninguém; foco no ICP multiplica eficiência.',
            AiVentureQuestionAnswer::DATA_INTERNAL_CODE, 'venture.idea(icp) + comprehension.audience_usage(plan_tier/segment)', null,
            'Estreitar o ICP ao segmento com maior dor + disposição a pagar.');
        $q[] = $this->q('Q-USER-002', self::DIM_USERS, 'all', 'critical',
            'O que os usuários realmente querem (não o que dizem querer)?',
            'A demanda declarada engana; o comportamento real revela o valor.',
            AiVentureQuestionAnswer::DATA_EXTERNAL_REQUIRED, 'analytics de uso + entrevistas', 'web_analytics',
            'Priorizar o que os usuários USAM e pagam, não o que pedem em survey.');
        $q[] = $this->q('Q-USER-003', self::DIM_USERS, ['S2', 'S3', 'S4', 'S5'], 'critical',
            'O que de fato eleva o crescimento para outro nível (alavanca não-óbvia)?',
            'Existe quase sempre 1 alavanca que destrava crescimento desproporcional; achá-la é o jogo.',
            AiVentureQuestionAnswer::DATA_EXTERNAL_REQUIRED, 'analytics de funil + experimentos', 'web_analytics',
            'Rodar experimentos para isolar a alavanca de maior elasticidade de crescimento.');
        $q[] = $this->q('Q-USER-004', self::DIM_USERS, 'all', 'high',
            'Qual o momento "aha" / valor entregue, e quão rápido o usuário chega nele?',
            'Time-to-value curto é o maior preditor de ativação e retenção.',
            AiVentureQuestionAnswer::DATA_EXTERNAL_REQUIRED, 'analytics de ativação', 'web_analytics',
            'Encurtar o caminho até o primeiro valor (onboarding, defaults, fricção).');

        // ── Produto & Diferencial ──────────────────────────────────────────
        $q[] = $this->q('Q-PROD-001', self::DIM_PRODUCT, 'all', 'critical',
            'Nosso produto tem diferencial real e defensável? Qual?',
            'Sem diferencial defensável, vira commodity e a margem evapora.',
            AiVentureQuestionAnswer::DATA_INTERNAL_CODE, 'comprehension.business_rule + audience_usage(integration) + venture.thesis', null,
            'Nomear e dobrar a aposta no diferencial; se não há, construir um (dado, rede, integração, velocidade).');
        $q[] = $this->q('Q-PROD-002', self::DIM_PRODUCT, ['S3', 'S4', 'S5'], 'high',
            'Como nos tornamos o número 1 do mercado?',
            'Liderança de categoria captura valor desproporcional (preço, talento, distribuição).',
            AiVentureQuestionAnswer::DATA_EXTERNAL_REQUIRED, 'inteligência de concorrência + market share', 'competitor_intel',
            'Definir a fatia onde podemos ser inquestionavelmente nº1 e concentrar recursos.');
        $q[] = $this->q('Q-PROD-003', self::DIM_PRODUCT, 'all', 'medium',
            'O produto entrega valor de forma consistente (sem quebrar) no caminho crítico?',
            'Um diferencial não importa se o fluxo principal falha.',
            AiVentureQuestionAnswer::DATA_INTERNAL_CODE, 'comprehension.problem(critical/high no caminho crítico)', null,
            'Blindar o caminho crítico antes de adicionar features.');

        // ── Concorrência ───────────────────────────────────────────────────
        $q[] = $this->q('Q-COMP-001', self::DIM_COMPETITION, 'all', 'high',
            'Quem são os concorrentes (diretos e substitutos)?',
            'Não conhecer os concorrentes é competir cego.',
            AiVentureQuestionAnswer::DATA_EXTERNAL_REQUIRED, 'inteligência de concorrência', 'competitor_intel',
            'Mapear concorrentes e substitutos; atualizar continuamente.');
        $q[] = $this->q('Q-COMP-002', self::DIM_COMPETITION, 'all', 'high',
            'Os concorrentes estão melhores? Estamos ficando para trás?',
            'Ficar para trás silenciosamente é como negócios morrem.',
            AiVentureQuestionAnswer::DATA_EXTERNAL_REQUIRED, 'inteligência de concorrência + reviews comparativos', 'competitor_intel',
            'Se há gap, fechar o gap crítico ou mudar o eixo de competição.');
        $q[] = $this->q('Q-COMP-003', self::DIM_COMPETITION, ['S2', 'S3', 'S4', 'S5'], 'medium',
            'Em que eixo competimos onde podemos ganhar de forma sustentável?',
            'Competir no eixo errado é perder mesmo executando bem.',
            AiVentureQuestionAnswer::DATA_EXTERNAL_REQUIRED, 'inteligência de concorrência + voz do cliente', 'competitor_intel',
            'Escolher o eixo (preço, velocidade, foco, dados) e comunicar com clareza.');

        // ── Crescimento & Aquisição ────────────────────────────────────────
        $q[] = $this->q('Q-GROW-001', self::DIM_GROWTH, ['S2', 'S3', 'S4', 'S5'], 'critical',
            'Qual canal de aquisição é repetível e escalável sem degradar o CAC?',
            'Crescimento real precisa de pelo menos um motor de aquisição repetível.',
            AiVentureQuestionAnswer::DATA_EXTERNAL_REQUIRED, 'plataformas de anúncio + analytics + CRM', 'ads_platform',
            'Dobrar no canal com melhor CAC/payback; cortar os que não escalam.');
        $q[] = $this->q('Q-GROW-002', self::DIM_GROWTH, ['S2', 'S3', 'S4', 'S5'], 'high',
            'Como pegar de 0 → 100k → 1M → 1B o mais rápido possível (plano por estágio)?',
            'Cada salto de receita exige uma máquina diferente; o plano precisa ser explícito.',
            AiVentureQuestionAnswer::DATA_INTERNAL_METRIC, 'venture.metric(arr) + trajetória + unit economics', null,
            'Definir a próxima meta de receita e a alavanca específica para alcançá-la.');
        $q[] = $this->q('Q-GROW-003', self::DIM_GROWTH, 'all', 'high',
            'Devemos focar em vender mais, melhorar o produto, ou corrigir o que quebra?',
            'A alocação de esforço entre vender/melhorar/corrigir define a velocidade.',
            AiVentureQuestionAnswer::DATA_INTERNAL_CODE, 'comprehension.problem + improvement + métricas', null,
            'Seguir o decisor de foco: risco crítico > validação > growth conforme estágio.');
        $q[] = $this->q('Q-GROW-004', self::DIM_GROWTH, ['S2', 'S3', 'S4', 'S5'], 'medium',
            'Existe loop de crescimento (viral, conteúdo, paid-recuperável) que se auto-alimenta?',
            'Loops compõem; sem loop, o crescimento é linear e caro.',
            AiVentureQuestionAnswer::DATA_EXTERNAL_REQUIRED, 'analytics de referral + funil', 'web_analytics',
            'Instrumentar e otimizar o loop de maior coeficiente.');

        // ── Retenção & Engajamento ─────────────────────────────────────────
        $q[] = $this->q('Q-RET-001', self::DIM_RETENTION, ['S2', 'S3', 'S4', 'S5'], 'critical',
            'Os usuários ficam? Qual a retenção/curva de churn?',
            'Reter é a base de tudo; aquisição sem retenção é um balde furado.',
            AiVentureQuestionAnswer::DATA_EXTERNAL_REQUIRED, 'analytics de retenção + Stripe (churn pago)', 'web_analytics',
            'Se a curva não estabiliza, parar de escalar aquisição e consertar retenção.');
        $q[] = $this->q('Q-RET-002', self::DIM_RETENTION, 'all', 'critical',
            'O que os usuários mais reclamam?',
            'As queixas recorrentes apontam exatamente o que está travando o crescimento.',
            AiVentureQuestionAnswer::DATA_EXTERNAL_REQUIRED, 'tickets de suporte + reviews + escuta social', 'support_tickets',
            'Atacar a queixa nº1 por volume×impacto antes de novas features.');
        $q[] = $this->q('Q-RET-003', self::DIM_RETENTION, ['S3', 'S4', 'S5'], 'high',
            'Qual a Net Revenue Retention (expansão vs. contração)?',
            'NRR > 100% é o que permite crescimento composto eficiente.',
            AiVentureQuestionAnswer::DATA_EXTERNAL_REQUIRED, 'Stripe + analytics de expansão', 'payment_stripe',
            'Construir mecanismos de expansão (upsell, seats, uso) se NRR < 100%.');

        // ── Monetização & Unit Economics ───────────────────────────────────
        $q[] = $this->q('Q-MON-001', self::DIM_MONETIZATION, 'all', 'critical',
            'Quanto cobramos e por quê (modelo e preço de fato no código)?',
            'O preço é a alavanca de maior impacto e a menos otimizada.',
            AiVentureQuestionAnswer::DATA_INTERNAL_CODE, 'comprehension.business_rule(pricing/trial)', null,
            'Validar se o preço captura o valor; testar elasticidade.');
        $q[] = $this->q('Q-MON-002', self::DIM_MONETIZATION, ['S2', 'S3', 'S4', 'S5'], 'critical',
            'CAC, LTV e payback são saudáveis (LTV/CAC ≥ 3, payback curto)?',
            'Unit economics negativos transformam crescimento em destruição de capital.',
            AiVentureQuestionAnswer::DATA_INTERNAL_METRIC, 'venture.metric(ltv_cac_ratio, cac, ltv) + Stripe', 'payment_stripe',
            'Se LTV/CAC < 3, consertar economia antes de escalar gasto.');
        $q[] = $this->q('Q-MON-003', self::DIM_MONETIZATION, ['S3', 'S4', 'S5'], 'high',
            'Qual a receita real, MRR e crescimento mês a mês?',
            'Receita observada é a verdade; o resto é hipótese.',
            AiVentureQuestionAnswer::DATA_INTERNAL_METRIC, 'venture.metric(arr) + Stripe', 'payment_stripe',
            'Reconciliar receita declarada com a fonte de pagamento real.');
        $q[] = $this->q('Q-MON-004', self::DIM_MONETIZATION, ['S2', 'S3', 'S4', 'S5'], 'medium',
            'A margem bruta sustenta o modelo em escala?',
            'Margem baixa limita reinvestimento e o teto de avaliação.',
            AiVentureQuestionAnswer::DATA_EXTERNAL_REQUIRED, 'custos + Stripe + infra', 'payment_stripe',
            'Atacar os maiores centros de custo unitário se a margem comprime.');

        // ── Saúde do Produto (o que está quebrando) ────────────────────────
        $q[] = $this->q('Q-HLT-001', self::DIM_HEALTH, 'all', 'critical',
            'O que está quebrando agora no produto?',
            'Bugs no caminho crítico matam ativação, retenção e reputação.',
            AiVentureQuestionAnswer::DATA_INTERNAL_CODE, 'comprehension.problem(bug/dangerous_code/swallowed_error)', null,
            'Corrigir primeiro o que está no caminho crítico e o que tem maior alcance.');
        $q[] = $this->q('Q-HLT-002', self::DIM_HEALTH, 'all', 'critical',
            'O que está matando o negócio (risco existencial)?',
            'Há sempre poucos riscos que podem encerrar a empresa; ignorá-los é fatal.',
            AiVentureQuestionAnswer::DATA_INTERNAL_CODE, 'comprehension.problem(critical) + risco', null,
            'Tratar o risco existencial como prioridade absoluta, acima de growth.');
        $q[] = $this->q('Q-HLT-003', self::DIM_HEALTH, ['S2', 'S3', 'S4', 'S5'], 'high',
            'O produto aguenta 10x o volume atual (escala técnica)?',
            'Crescer e cair é pior do que não crescer; a escala precisa estar pronta.',
            AiVentureQuestionAnswer::DATA_INTERNAL_CODE, 'comprehension.improvement(performance/reliability)', null,
            'Resolver gargalos de performance/confiabilidade antes do próximo salto.');

        // ── Priorização & Foco ─────────────────────────────────────────────
        $q[] = $this->q('Q-FOC-001', self::DIM_FOCUS, 'all', 'critical',
            'O que é a coisa MAIS importante para esta empresa neste momento?',
            'Foco é o multiplicador escasso; fazer a coisa errada bem é desperdício.',
            AiVentureQuestionAnswer::DATA_INTERNAL_CODE, 'decisor de foco (síntese de todas as dimensões)', null,
            'Executar a única prioridade nº1 antes de dispersar esforço.');
        $q[] = $this->q('Q-FOC-002', self::DIM_FOCUS, 'all', 'high',
            'Qual o próximo marco de receita e a única alavanca para alcançá-lo?',
            'Um marco claro + uma alavanca evita o espalhamento que mata startups.',
            AiVentureQuestionAnswer::DATA_INTERNAL_METRIC, 'venture.metric + trajetória + escada de crescimento', null,
            'Comprometer-se com o próximo marco e a alavanca de maior elasticidade.');

        // ── Finanças & Runway ──────────────────────────────────────────────
        $q[] = $this->q('Q-FIN-001', self::DIM_FINANCE, ['S2', 'S3', 'S4', 'S5'], 'high',
            'Qual o runway e a queima — quanto tempo até precisar de capital ou lucro?',
            'Ficar sem caixa é a causa nº1 de morte; o runway dita a estratégia.',
            AiVentureQuestionAnswer::DATA_EXTERNAL_REQUIRED, 'Stripe (receita) + custos/folha', 'payment_stripe',
            'Se o runway é curto, priorizar caminho para caixa (receita ou corte).');
        $q[] = $this->q('Q-FIN-002', self::DIM_FINANCE, ['S3', 'S4', 'S5'], 'medium',
            'A eficiência de capital melhora com a escala (caminho para lucratividade)?',
            'Crescimento eficiente é o que sobrevive a ciclos e cria opções.',
            AiVentureQuestionAnswer::DATA_EXTERNAL_REQUIRED, 'Stripe + custos + unit economics', 'payment_stripe',
            'Acompanhar a eficiência (magic number) e ajustar gasto à eficiência.');

        // ── Execução & Velocidade ──────────────────────────────────────────
        $q[] = $this->q('Q-EXE-001', self::DIM_EXECUTION, 'all', 'high',
            'Estamos executando rápido o suficiente (velocidade de iteração)?',
            'Velocidade de aprendizado é a vantagem composta mais difícil de copiar.',
            AiVentureQuestionAnswer::DATA_INTERNAL_CODE, 'comprehension.problem(test_gap) + cadência de entrega', null,
            'Reduzir o ciclo de aprendizado: cobertura de teste, automação, foco.');
        $q[] = $this->q('Q-EXE-002', self::DIM_EXECUTION, 'all', 'medium',
            'A base de código sustenta velocidade futura ou a dívida está travando?',
            'Dívida não gerida transforma cada feature em um custo crescente.',
            AiVentureQuestionAnswer::DATA_INTERNAL_CODE, 'comprehension.problem(debt/large_file) + improvement(maintainability)', null,
            'Pagar a dívida de maior leverage que está freando entregas.');

        // ── Risco & Compliance ─────────────────────────────────────────────
        $q[] = $this->q('Q-RSK-001', self::DIM_RISK, 'all', 'critical',
            'Há risco de segurança/credencial vazada que pode derrubar a empresa?',
            'Um secret vazado ou falha de segurança pode encerrar o negócio da noite para o dia.',
            AiVentureQuestionAnswer::DATA_INTERNAL_CODE, 'comprehension.problem(secret/dangerous_code)', null,
            'Rotacionar credenciais vazadas e fechar a falha imediatamente — acima de tudo.');
        $q[] = $this->q('Q-RSK-002', self::DIM_RISK, ['S2', 'S3', 'S4', 'S5'], 'high',
            'Estamos em conformidade (LGPD/dados/pagamentos) onde operamos?',
            'Não-conformidade vira multa, bloqueio e perda de confiança em escala.',
            AiVentureQuestionAnswer::DATA_EXTERNAL_REQUIRED, 'revisão legal + dados de operação', 'market_research',
            'Mapear obrigações por jurisdição e fechar as lacunas de maior exposição.');

        return $q;
    }

    /**
     * @return array<string,list<array<string,mixed>>> questions grouped by dimension
     */
    public function byDimension(): array
    {
        $grouped = [];
        foreach ($this->all() as $question) {
            $grouped[$question['dimension']][] = $question;
        }

        return $grouped;
    }

    /**
     * Questions relevant at a given ladder stage ('all' always included).
     *
     * @return list<array<string,mixed>>
     */
    public function forStage(string $stage): array
    {
        return array_values(array_filter($this->all(), function (array $question) use ($stage): bool {
            $stages = $question['stages'];

            return $stages === 'all' || (is_array($stages) && in_array($stage, $stages, true));
        }));
    }

    /**
     * @param  string|list<string>  $stages
     * @return array<string,mixed>
     */
    private function q(
        string $id,
        string $dimension,
        string|array $stages,
        string $severity,
        string $question,
        string $why,
        string $dataKind,
        string $answerSource,
        ?string $externalSource,
        string $decisionTrigger,
    ): array {
        return [
            'id' => $id,
            'dimension' => $dimension,
            'stages' => $stages,
            'severity_if_blind' => $severity,
            'question' => $question,
            'why' => $why,
            'data_kind' => $dataKind,
            'answer_source' => $answerSource,
            'external_source' => $externalSource,
            'decision_trigger' => $decisionTrigger,
        ];
    }
}
