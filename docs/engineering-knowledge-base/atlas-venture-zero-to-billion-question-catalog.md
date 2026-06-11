---
id: atlas-venture-zero-to-billion-question-catalog
title: Atlas Venture Zero-to-Billion Question Catalog
status: active
type: engineering_knowledge
category: architecture
priority: 90
summary: Doc-mae do cerebro de decisao do Venture Foundry - o catalogo canonico de TODAS as perguntas que o Atlas precisa fazer, responder com dados sinceros e agir para criar uma empresa do zero e leva-la a 1 bilhao de forma autonoma.
human_name: Atlas Venture Zero-to-Billion Question Catalog
canonical_name: Atlas Venture Zero-to-Billion Question Catalog
technical_name: VentureQuestionCatalog
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-venture-zero-to-billion-question-catalog.md
graph_id: atlas-venture-zero-to-billion-question-catalog
graph_title: Atlas Venture Zero-to-Billion Question Catalog
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-venture-foundry-operating-system
graph_status: active
graph_source: repo
owner: venture-foundry
doc_schema: atlas_canonical_module_doc.v1
tags:
  - atlas-ai
  - venture-foundry
  - assessment
  - decision-brain
  - zero-to-billion
capabilities:
  - question_catalog
  - question_answering_engine
  - focus_decision
  - data_readiness
decisions:
  - O cerebro de decisao pergunta, responde com o dado mais sincero disponivel e decide o foco; nunca finge saber o que nao pode.
  - Cada pergunta amarra a uma fonte de dado real (codigo, metrica) OU declara a fonte externa que falta - a fonte externa e a ponte explicita para autonomia.
  - O decisor de foco e deterministico e explicavel - risco existencial vence growth; em S0-S1 validacao vence escala; em S2+ growth e retencao sobem.
  - Este catalogo (VentureQuestionCatalog) e a fonte de verdade em codigo; esta doc e projecao gerada dele e nunca deve divergir.
maintenance:
  - Atualizar quando o VentureQuestionCatalog ganhar/alterar perguntas, dimensoes ou fontes externas.
  - Manter abaixo de 520 linhas; se crescer, dividir por familia de dimensoes.
related_paths:
  - app/Services/Ai/VentureFoundry/Assessment/VentureQuestionCatalog.php
  - app/Services/Ai/VentureFoundry/Assessment/VentureQuestionEngine.php
  - app/Services/Ai/VentureFoundry/Assessment/VentureFocusDecider.php
  - app/Services/Ai/VentureFoundry/Assessment/VentureAssessmentService.php
  - docs/engineering-knowledge-base/atlas-venture-foundry-operating-system.md
repo_paths:
  - docs/engineering-knowledge-base/atlas-venture-zero-to-billion-question-catalog.md
allowed_changes:
  - Evoluir o conjunto de perguntas, fontes de dado e gatilhos de acao quando codigo e testes mudarem juntos.
forbidden_changes:
  - Responder uma pergunta sem dado real (inventar) ou esconder que falta uma fonte externa.
  - Deixar a doc divergir do VentureQuestionCatalog em codigo.
depends_on:
  - atlas-venture-foundry-operating-system
  - atlas-ai-knowledge-governance-system
flows_to:
  - atlas-venture-foundry-operating-system
unlocks:
  - venture-decision-brain
governs:
  - venture-foundry
evidence:
  - app/Services/Ai/VentureFoundry/Assessment/VentureQuestionCatalog.php
  - tests/Feature/Ai/VentureFoundry/Assessment/VentureAssessmentTest.php
evidence_refs:
  - symbol: VentureQuestionCatalog
  - command: atlas:venture assess
  - test: VentureAssessmentTest
required_tests:
  - "php artisan test tests/Feature/Ai/VentureFoundry/Assessment"
requires_evidence: true
risk_level: high
next_actions:
  - Conectar fontes externas por prioridade para subir a data readiness e destravar perguntas bloqueadas.
---
# Atlas Venture Zero-to-Billion Question Catalog

## Resumo

Esta e a **doc-mae do cerebro de decisao** do Venture Foundry. Para o Atlas
criar uma empresa do zero e leva-la a 1 bilhao **sozinho**, ele precisa de um
loop que nunca para: **PERGUNTAR -> RESPONDER (com o dado mais sincero) ->
DECIDIR (o foco nº1) -> AGIR -> repetir**. Este documento lista todas as
perguntas que o Atlas precisa se fazer, por dimensao e por estagio
(0 -> 100k -> 1M -> 1B), cada uma com a fonte de dado que a responde e a acao
que ela dispara.

## Papel no Atlas

E o cerebro de decisao que fica acima da compreensao: a compreensao diz o que a
empresa E (codigo, regras, problemas, publico); este catalogo diz o que o Atlas
precisa SABER, RESPONDER e FAZER para crescer. Transforma dado em foco e foco
em acao.

## Onde Se Encaixa

Camada de avaliacao dentro do Venture Foundry, consumindo
`ai_venture_comprehension_*` (achados do codigo), `ai_venture_metric_observations`
(metricas) e o canon de regras. Entrega `ai_venture_assessment_runs` +
`ai_venture_question_answers` e o foco/decisao por venture.

## Contratos

- `atlas.ai.venture.question_catalog.v1` - o catalogo canonico (perguntas,
  dimensoes, fontes externas).
- `atlas.ai.venture.assessment_run.v1` - uma passada de avaliacao (foco, data
  readiness, lacunas).
- `atlas.ai.venture.question_answer.v1` - a resposta de uma pergunta com status
  (answered/partial/blocked_internal/blocked_external), evidencia e acao.

## Fluxo

1. **Pergunta** — `VentureQuestionCatalog` (`atlas:venture question-catalog`).
2. **Responde** — `VentureQuestionEngine` responde de achados de compreensao,
   metricas, ideia e regras; o que nao da vira blocked_internal/blocked_external,
   nunca resposta inventada.
3. **Decide** — `VentureFocusDecider` rankeia por
   severidade x peso-da-dimensao-no-estagio x acionabilidade e aponta a coisa nº1.
4. **Age** — cada resposta carrega um gatilho de decisao; a ponte de execucao
   vira gaps em missoes; fontes externas conectadas fecham o loop para autonomia.

Rode: `atlas:venture assess --venture=<id>` -> `focus` -> `questions` ->
`data-readiness`.

## Sinceridade dos dados e a ponte para autonomia

O Atlas so e tao bom quanto os dados que tem. Hoje responde com forca o que o
**codigo** revela (problema/risco/preco/dividas/saude) e o que as **metricas
registradas** dizem. Mercado, concorrencia, uso real e voz do cliente ficam
**honestamente bloqueados** ate as fontes externas serem conectadas. O relatorio
`data-readiness` mede quanto o Atlas ja responde hoje vs. o que falta conectar
para virar autonomo (Stripe, analytics, suporte, inteligencia de concorrencia).

# Catalogo de Perguntas

Catálogo vivo: **35 perguntas** em **12 dimensões**. Fonte de verdade no código: `VentureQuestionCatalog`; esta doc é projeção.

## Problema & Mercado

### 🔴 `Q-PROB-001` Qual problema o produto resolve e ele é real, frequente e doloroso?
- **Por quê**: Sem um problema real e doloroso, todo crescimento é construído sobre areia.
- **Estágios**: todos · **Dado**: internal_code
- **Fonte de resposta**: venture.idea(problem/icp/pain) + comprehension.business_rule
- **Ação (gatilho de decisão)**: Se o problema não está validado com evidência, focar discovery antes de escalar.

### 🟠 `Q-PROB-002` O mercado é grande o suficiente para sustentar 1B (TAM/SAM/SOM)?
- **Por quê**: Um problema real num mercado pequeno limita o teto; 1B exige mercado grande ou expansível.
- **Estágios**: S0,S1 · **Dado**: external_required · fonte externa: `market_research`
- **Fonte de resposta**: pesquisa de mercado
- **Ação (gatilho de decisão)**: Se o mercado é pequeno, redefinir o problema/segmento para um teto maior, ou pivotar.

### 🟠 `Q-PROB-003` Por que agora? Que mudança torna esse problema urgente neste momento?
- **Por quê**: Timing de mercado separa vencedores de quem chegou cedo ou tarde demais.
- **Estágios**: todos · **Dado**: external_required · fonte externa: `market_research`
- **Fonte de resposta**: pesquisa de mercado + escuta social
- **Ação (gatilho de decisão)**: Ancorar a narrativa e o GTM na mudança que cria urgência agora.

## Usuários & ICP

### 🔴 `Q-USER-001` Quem exatamente é o usuário (ICP) e quem paga?
- **Por quê**: Vender para todo mundo é vender para ninguém; foco no ICP multiplica eficiência.
- **Estágios**: todos · **Dado**: internal_code
- **Fonte de resposta**: venture.idea(icp) + comprehension.audience_usage(plan_tier/segment)
- **Ação (gatilho de decisão)**: Estreitar o ICP ao segmento com maior dor + disposição a pagar.

### 🔴 `Q-USER-002` O que os usuários realmente querem (não o que dizem querer)?
- **Por quê**: A demanda declarada engana; o comportamento real revela o valor.
- **Estágios**: todos · **Dado**: external_required · fonte externa: `web_analytics`
- **Fonte de resposta**: analytics de uso + entrevistas
- **Ação (gatilho de decisão)**: Priorizar o que os usuários USAM e pagam, não o que pedem em survey.

### 🔴 `Q-USER-003` O que de fato eleva o crescimento para outro nível (alavanca não-óbvia)?
- **Por quê**: Existe quase sempre 1 alavanca que destrava crescimento desproporcional; achá-la é o jogo.
- **Estágios**: S2,S3,S4,S5 · **Dado**: external_required · fonte externa: `web_analytics`
- **Fonte de resposta**: analytics de funil + experimentos
- **Ação (gatilho de decisão)**: Rodar experimentos para isolar a alavanca de maior elasticidade de crescimento.

### 🟠 `Q-USER-004` Qual o momento "aha" / valor entregue, e quão rápido o usuário chega nele?
- **Por quê**: Time-to-value curto é o maior preditor de ativação e retenção.
- **Estágios**: todos · **Dado**: external_required · fonte externa: `web_analytics`
- **Fonte de resposta**: analytics de ativação
- **Ação (gatilho de decisão)**: Encurtar o caminho até o primeiro valor (onboarding, defaults, fricção).

## Produto & Diferencial

### 🔴 `Q-PROD-001` Nosso produto tem diferencial real e defensável? Qual?
- **Por quê**: Sem diferencial defensável, vira commodity e a margem evapora.
- **Estágios**: todos · **Dado**: internal_code
- **Fonte de resposta**: comprehension.business_rule + audience_usage(integration) + venture.thesis
- **Ação (gatilho de decisão)**: Nomear e dobrar a aposta no diferencial; se não há, construir um (dado, rede, integração, velocidade).

### 🟠 `Q-PROD-002` Como nos tornamos o número 1 do mercado?
- **Por quê**: Liderança de categoria captura valor desproporcional (preço, talento, distribuição).
- **Estágios**: S3,S4,S5 · **Dado**: external_required · fonte externa: `competitor_intel`
- **Fonte de resposta**: inteligência de concorrência + market share
- **Ação (gatilho de decisão)**: Definir a fatia onde podemos ser inquestionavelmente nº1 e concentrar recursos.

### 🟡 `Q-PROD-003` O produto entrega valor de forma consistente (sem quebrar) no caminho crítico?
- **Por quê**: Um diferencial não importa se o fluxo principal falha.
- **Estágios**: todos · **Dado**: internal_code
- **Fonte de resposta**: comprehension.problem(critical/high no caminho crítico)
- **Ação (gatilho de decisão)**: Blindar o caminho crítico antes de adicionar features.

## Concorrência

### 🟠 `Q-COMP-001` Quem são os concorrentes (diretos e substitutos)?
- **Por quê**: Não conhecer os concorrentes é competir cego.
- **Estágios**: todos · **Dado**: external_required · fonte externa: `competitor_intel`
- **Fonte de resposta**: inteligência de concorrência
- **Ação (gatilho de decisão)**: Mapear concorrentes e substitutos; atualizar continuamente.

### 🟠 `Q-COMP-002` Os concorrentes estão melhores? Estamos ficando para trás?
- **Por quê**: Ficar para trás silenciosamente é como negócios morrem.
- **Estágios**: todos · **Dado**: external_required · fonte externa: `competitor_intel`
- **Fonte de resposta**: inteligência de concorrência + reviews comparativos
- **Ação (gatilho de decisão)**: Se há gap, fechar o gap crítico ou mudar o eixo de competição.

### 🟡 `Q-COMP-003` Em que eixo competimos onde podemos ganhar de forma sustentável?
- **Por quê**: Competir no eixo errado é perder mesmo executando bem.
- **Estágios**: S2,S3,S4,S5 · **Dado**: external_required · fonte externa: `competitor_intel`
- **Fonte de resposta**: inteligência de concorrência + voz do cliente
- **Ação (gatilho de decisão)**: Escolher o eixo (preço, velocidade, foco, dados) e comunicar com clareza.

## Crescimento & Aquisição

### 🔴 `Q-GROW-001` Qual canal de aquisição é repetível e escalável sem degradar o CAC?
- **Por quê**: Crescimento real precisa de pelo menos um motor de aquisição repetível.
- **Estágios**: S2,S3,S4,S5 · **Dado**: external_required · fonte externa: `ads_platform`
- **Fonte de resposta**: plataformas de anúncio + analytics + CRM
- **Ação (gatilho de decisão)**: Dobrar no canal com melhor CAC/payback; cortar os que não escalam.

### 🟠 `Q-GROW-002` Como pegar de 0 → 100k → 1M → 1B o mais rápido possível (plano por estágio)?
- **Por quê**: Cada salto de receita exige uma máquina diferente; o plano precisa ser explícito.
- **Estágios**: S2,S3,S4,S5 · **Dado**: internal_metric
- **Fonte de resposta**: venture.metric(arr) + trajetória + unit economics
- **Ação (gatilho de decisão)**: Definir a próxima meta de receita e a alavanca específica para alcançá-la.

### 🟠 `Q-GROW-003` Devemos focar em vender mais, melhorar o produto, ou corrigir o que quebra?
- **Por quê**: A alocação de esforço entre vender/melhorar/corrigir define a velocidade.
- **Estágios**: todos · **Dado**: internal_code
- **Fonte de resposta**: comprehension.problem + improvement + métricas
- **Ação (gatilho de decisão)**: Seguir o decisor de foco: risco crítico > validação > growth conforme estágio.

### 🟡 `Q-GROW-004` Existe loop de crescimento (viral, conteúdo, paid-recuperável) que se auto-alimenta?
- **Por quê**: Loops compõem; sem loop, o crescimento é linear e caro.
- **Estágios**: S2,S3,S4,S5 · **Dado**: external_required · fonte externa: `web_analytics`
- **Fonte de resposta**: analytics de referral + funil
- **Ação (gatilho de decisão)**: Instrumentar e otimizar o loop de maior coeficiente.

## Retenção & Engajamento

### 🔴 `Q-RET-001` Os usuários ficam? Qual a retenção/curva de churn?
- **Por quê**: Reter é a base de tudo; aquisição sem retenção é um balde furado.
- **Estágios**: S2,S3,S4,S5 · **Dado**: external_required · fonte externa: `web_analytics`
- **Fonte de resposta**: analytics de retenção + Stripe (churn pago)
- **Ação (gatilho de decisão)**: Se a curva não estabiliza, parar de escalar aquisição e consertar retenção.

### 🔴 `Q-RET-002` O que os usuários mais reclamam?
- **Por quê**: As queixas recorrentes apontam exatamente o que está travando o crescimento.
- **Estágios**: todos · **Dado**: external_required · fonte externa: `support_tickets`
- **Fonte de resposta**: tickets de suporte + reviews + escuta social
- **Ação (gatilho de decisão)**: Atacar a queixa nº1 por volume×impacto antes de novas features.

### 🟠 `Q-RET-003` Qual a Net Revenue Retention (expansão vs. contração)?
- **Por quê**: NRR > 100% é o que permite crescimento composto eficiente.
- **Estágios**: S3,S4,S5 · **Dado**: external_required · fonte externa: `payment_stripe`
- **Fonte de resposta**: Stripe + analytics de expansão
- **Ação (gatilho de decisão)**: Construir mecanismos de expansão (upsell, seats, uso) se NRR < 100%.

## Monetização & Unit Economics

### 🔴 `Q-MON-001` Quanto cobramos e por quê (modelo e preço de fato no código)?
- **Por quê**: O preço é a alavanca de maior impacto e a menos otimizada.
- **Estágios**: todos · **Dado**: internal_code
- **Fonte de resposta**: comprehension.business_rule(pricing/trial)
- **Ação (gatilho de decisão)**: Validar se o preço captura o valor; testar elasticidade.

### 🔴 `Q-MON-002` CAC, LTV e payback são saudáveis (LTV/CAC ≥ 3, payback curto)?
- **Por quê**: Unit economics negativos transformam crescimento em destruição de capital.
- **Estágios**: S2,S3,S4,S5 · **Dado**: internal_metric · fonte externa: `payment_stripe`
- **Fonte de resposta**: venture.metric(ltv_cac_ratio, cac, ltv) + Stripe
- **Ação (gatilho de decisão)**: Se LTV/CAC < 3, consertar economia antes de escalar gasto.

### 🟠 `Q-MON-003` Qual a receita real, MRR e crescimento mês a mês?
- **Por quê**: Receita observada é a verdade; o resto é hipótese.
- **Estágios**: S3,S4,S5 · **Dado**: internal_metric · fonte externa: `payment_stripe`
- **Fonte de resposta**: venture.metric(arr) + Stripe
- **Ação (gatilho de decisão)**: Reconciliar receita declarada com a fonte de pagamento real.

### 🟡 `Q-MON-004` A margem bruta sustenta o modelo em escala?
- **Por quê**: Margem baixa limita reinvestimento e o teto de avaliação.
- **Estágios**: S2,S3,S4,S5 · **Dado**: external_required · fonte externa: `payment_stripe`
- **Fonte de resposta**: custos + Stripe + infra
- **Ação (gatilho de decisão)**: Atacar os maiores centros de custo unitário se a margem comprime.

## Saúde do Produto (o que está quebrando)

### 🔴 `Q-HLT-001` O que está quebrando agora no produto?
- **Por quê**: Bugs no caminho crítico matam ativação, retenção e reputação.
- **Estágios**: todos · **Dado**: internal_code
- **Fonte de resposta**: comprehension.problem(bug/dangerous_code/swallowed_error)
- **Ação (gatilho de decisão)**: Corrigir primeiro o que está no caminho crítico e o que tem maior alcance.

### 🔴 `Q-HLT-002` O que está matando o negócio (risco existencial)?
- **Por quê**: Há sempre poucos riscos que podem encerrar a empresa; ignorá-los é fatal.
- **Estágios**: todos · **Dado**: internal_code
- **Fonte de resposta**: comprehension.problem(critical) + risco
- **Ação (gatilho de decisão)**: Tratar o risco existencial como prioridade absoluta, acima de growth.

### 🟠 `Q-HLT-003` O produto aguenta 10x o volume atual (escala técnica)?
- **Por quê**: Crescer e cair é pior do que não crescer; a escala precisa estar pronta.
- **Estágios**: S2,S3,S4,S5 · **Dado**: internal_code
- **Fonte de resposta**: comprehension.improvement(performance/reliability)
- **Ação (gatilho de decisão)**: Resolver gargalos de performance/confiabilidade antes do próximo salto.

## Priorização & Foco

### 🔴 `Q-FOC-001` O que é a coisa MAIS importante para esta empresa neste momento?
- **Por quê**: Foco é o multiplicador escasso; fazer a coisa errada bem é desperdício.
- **Estágios**: todos · **Dado**: internal_code
- **Fonte de resposta**: decisor de foco (síntese de todas as dimensões)
- **Ação (gatilho de decisão)**: Executar a única prioridade nº1 antes de dispersar esforço.

### 🟠 `Q-FOC-002` Qual o próximo marco de receita e a única alavanca para alcançá-lo?
- **Por quê**: Um marco claro + uma alavanca evita o espalhamento que mata startups.
- **Estágios**: todos · **Dado**: internal_metric
- **Fonte de resposta**: venture.metric + trajetória + escada de crescimento
- **Ação (gatilho de decisão)**: Comprometer-se com o próximo marco e a alavanca de maior elasticidade.

## Finanças & Runway

### 🟠 `Q-FIN-001` Qual o runway e a queima — quanto tempo até precisar de capital ou lucro?
- **Por quê**: Ficar sem caixa é a causa nº1 de morte; o runway dita a estratégia.
- **Estágios**: S2,S3,S4,S5 · **Dado**: external_required · fonte externa: `payment_stripe`
- **Fonte de resposta**: Stripe (receita) + custos/folha
- **Ação (gatilho de decisão)**: Se o runway é curto, priorizar caminho para caixa (receita ou corte).

### 🟡 `Q-FIN-002` A eficiência de capital melhora com a escala (caminho para lucratividade)?
- **Por quê**: Crescimento eficiente é o que sobrevive a ciclos e cria opções.
- **Estágios**: S3,S4,S5 · **Dado**: external_required · fonte externa: `payment_stripe`
- **Fonte de resposta**: Stripe + custos + unit economics
- **Ação (gatilho de decisão)**: Acompanhar a eficiência (magic number) e ajustar gasto à eficiência.

## Execução & Velocidade

### 🟠 `Q-EXE-001` Estamos executando rápido o suficiente (velocidade de iteração)?
- **Por quê**: Velocidade de aprendizado é a vantagem composta mais difícil de copiar.
- **Estágios**: todos · **Dado**: internal_code
- **Fonte de resposta**: comprehension.problem(test_gap) + cadência de entrega
- **Ação (gatilho de decisão)**: Reduzir o ciclo de aprendizado: cobertura de teste, automação, foco.

### 🟡 `Q-EXE-002` A base de código sustenta velocidade futura ou a dívida está travando?
- **Por quê**: Dívida não gerida transforma cada feature em um custo crescente.
- **Estágios**: todos · **Dado**: internal_code
- **Fonte de resposta**: comprehension.problem(debt/large_file) + improvement(maintainability)
- **Ação (gatilho de decisão)**: Pagar a dívida de maior leverage que está freando entregas.

## Risco & Compliance

### 🔴 `Q-RSK-001` Há risco de segurança/credencial vazada que pode derrubar a empresa?
- **Por quê**: Um secret vazado ou falha de segurança pode encerrar o negócio da noite para o dia.
- **Estágios**: todos · **Dado**: internal_code
- **Fonte de resposta**: comprehension.problem(secret/dangerous_code)
- **Ação (gatilho de decisão)**: Rotacionar credenciais vazadas e fechar a falha imediatamente — acima de tudo.

### 🟠 `Q-RSK-002` Estamos em conformidade (LGPD/dados/pagamentos) onde operamos?
- **Por quê**: Não-conformidade vira multa, bloqueio e perda de confiança em escala.
- **Estágios**: S2,S3,S4,S5 · **Dado**: external_required · fonte externa: `market_research`
- **Fonte de resposta**: revisão legal + dados de operação
- **Ação (gatilho de decisão)**: Mapear obrigações por jurisdição e fechar as lacunas de maior exposição.

## Fontes externas (a ponte para autonomia)

Cada fonte abaixo, ao ser conectada, transforma perguntas hoje bloqueadas em respondíveis — e, depois, em ações autônomas:

- `payment_stripe` — Stripe (receita real, MRR, churn de pagamento, planos)
- `web_analytics` — Analytics de produto (ativação, uso de features, funil, DAU/MAU)
- `support_tickets` — Suporte / tickets (o que os usuários reclamam)
- `product_reviews` — Reviews públicos / lojas (satisfação, queixas, elogios)
- `nps_survey` — Pesquisa NPS / CSAT (lealdade, voz do cliente)
- `market_research` — Pesquisa de mercado (TAM/SAM/SOM, tendências)
- `competitor_intel` — Inteligência de concorrência (posicionamento, preço, features rivais)
- `ads_platform` — Plataformas de anúncio (CAC por canal, ROAS, gasto)
- `sales_crm` — CRM / pipeline de vendas (conversão, ciclo, motivos de perda)
- `social_listening` — Escuta social (menções, sentimento, demanda emergente)

## Regras para IA

Nunca responder uma pergunta sem dado real; preferir blocked_internal/external a
inventar. O decisor de foco e deterministico: risco existencial e o que quebra o
caminho critico vencem growth. Nao promover acao externa (Stripe, gasto, deploy)
sem mandato do operador. A doc e projecao do catalogo em codigo; mudar o catalogo,
nao a doc isolada.

## Escopo de Implementacao

Permitido: evoluir perguntas, fontes e gatilhos com testes juntos; ligar novas
fontes de dado internas. Proibido: executar acao externa autonoma sem governanca,
ou responder sem evidencia.

## Dependencias

Venture Foundry (compreensao, metricas, regras, escada), Knowledge Governance e,
para acao externa futura, os conectores de fonte (Stripe, analytics, suporte).

## Evidencias

`php artisan test tests/Feature/Ai/VentureFoundry/Assessment` e
`atlas:venture assess --venture=<id> --json` (foco + data readiness live).

## Riscos

O maior risco e o teatro de resposta: fingir saber sem dado. O desenho bloqueia
isso (blocked_external honesto + data readiness). O segundo risco e agir sem
governanca; acao externa permanece gated.

## Exemplos

- Blackink (assess live): a pergunta de risco `Q-RSK-001` respondeu com 21
  achados de seguranca (incl. uma chave privada GCP commitada) e o decisor
  cravou o foco nº1 em **Risco & Compliance — rotacionar credenciais, acima de
  tudo**, com saude do produto (3 criticos / 18 high) em seguida; perguntas de
  mercado/concorrencia/uso ficaram honestamente bloqueadas (data readiness ~52%).
- Empresa em S0 sem compreensao: perguntas de codigo viram `blocked_internal`
  com a acao "rodar comprehend" — o Atlas diz o que precisa fazer para saber.

## Proximas Acoes

- Conectar as fontes externas por prioridade (data_gaps do assessment) para subir
  a data readiness e destravar as perguntas hoje bloqueadas.
- Expandir o catalogo: toda pergunta nova que provar importante entra no
  `VentureQuestionCatalog` com sua fonte e acao.
- Ligar acao autonoma: cada gatilho de decisao -> missao/obra governada com
  receipts, fechando o loop perguntar-responder-decidir-agir.
