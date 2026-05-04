# Atlas — Gaps Achamos e Não Pode Esquecer

> Funcionalidades que foram **specificadas** nos Documentos Mestre v3, v5 e v6 mas **nunca chegaram a ser implementadas** no código. Algumas o operador (Vitor) achava que estavam prontas. Não estão.
>
> **Data da auditoria:** 1 de maio de 2026
> **Fontes:** `Atlas_Documento_Mestre_v3.md` (27.IV), `_v5.md` (28.IV), `_v6.md` (30.IV) + estado real do schema PostgreSQL
> **Critério de inclusão:** apenas gaps com ROI ≥ 3. Itens ROI 2 ou menos foram descartados como "não vale a pena".

---

## Como ler este documento

Cada gap traz três campos curtos:

- **O que é** — descrição direta do que falta
- **Por que importa** — qual lei ou conceito do Atlas depende disso
- **Benefício** — o que muda na prática quando for implementado

Os gaps estão **agrupados por ROI** (5 → 4 → 3) e, dentro de cada ROI, ordenados por dependência (o que precisa ser feito antes vem primeiro).

---

# 🔥 ROI 5 — Núcleo do que Atlas prometeu ser

> *"Sem isso, Atlas não é Atlas. É só app de notas com IA."*

## 1. `cognitive_metrics` (tabela + serviço)

- **O que é:** tabela que mede como Vitor pensa ao longo do tempo, em **5 dimensões** (articulação, profundidade de raciocínio, aplicação, padrões, curadoria). Computada periodicamente via LLM analisando capturas, decisões e weekly reviews.
- **Por que importa:** é o **único instrumento que torna a Lei 9 (Cognição Aumentada Não-Negociável) auditável**. Sem ela, "Atlas fortalece cognição" vira aspiração não-mensurável.
- **Benefício:** Atlas pela primeira vez consegue responder honestamente *"Vitor de hoje é mensuravelmente melhor pensador que Vitor de 6 meses atrás?"*. Detecta atrofia cognitiva (queda em 2+ dimensões por 2+ meses → Lei 9 violada).

## 2. `decisions` estruturadas com `revisit_at`

- **O que é:** tabela onde cada decisão importante é capturada formalmente (contexto, alternativas, critérios, premortem, hipótese sobre resultado, prazo de revisitação).
- **Por que importa:** **Lei 7 (Propósito Revisitado) hoje é só texto** — não há mecanismo que lembre Vitor de revisitar decisões antigas pra aprender com elas. Decisões grandes morrem na timeline.
- **Benefício:** 90 dias após cada decisão, Atlas notifica no briefing matinal *"decisão de 27.IV — revisitar?"*. Você marca se ainda é relevante ou ficou obsoleta. **Métrica `Decision Revisit Rate` ganha vida** — sinaliza se Vitor está aprendendo com decisões passadas.

## 3. Snapshot Cognitivo Trimestral

- **O que é:** relatório auto-gerado a cada 3 meses comparando o operador com versões anteriores de si mesmo, em todas as 5 dimensões cognitivas (depende do gap #1 existir).
- **Por que importa:** é a **manifestação concreta da Lei 9** que o operador efetivamente vê e lê. Sem ele, métricas existem no banco mas não viram consciência.
- **Benefício:** trimestralmente, Atlas mostra *"sua articulação subiu 11% desde Q1, vocabulário +4%, mas ratio flash/articulado/estruturado ainda mostra pouco estruturado"*. Com sugestão concreta de onde focar no trimestre seguinte.

## 4. Apple Watch Action Button

- **O que é:** botão lateral do Apple Watch Ultra programado para iniciar gravação de áudio do Atlas em ≤1 segundo.
- **Por que importa:** **captura sub-10s é Lei 3** — qualquer fricção mata captura. O Action Button é o ponto de captura mais próximo possível de "pensei → registrei", especialmente em movimento.
- **Benefício:** capturas em situações que hoje se perdem — caminhada, treino, conversas, reuniões. Multiplica volume de captura passiva sem aumentar custo cognitivo.

---

# 🟢 ROI 4 — Segunda onda, transforma Atlas em coach real

## Dados / Schema

### 5. `life_hypotheses` + ciclo N-of-1

- **O que é:** tabela que captura hipóteses testáveis sobre seu próprio comportamento (ex: *"café após 14h piora meu sono"*) com período de validação, métrica e resultado. Conectado à Bitácula via ciclo Fator → Correlação → Hipótese → Experimento.
- **Por que importa:** sem isso, a Bitácula gera correlações estatísticas que **morrem como observação** — nunca viram teste, nunca viram aprendizado validado. Lei 4 (Aplicar Antes de Consumir) sem mecanismo.
- **Benefício:** correlação fraca repetida vira proposta de experimento N-of-1. Resultado validado vira princípio operacional documentado. Sistema deixa de coletar fatos e começa a **gerar conhecimento operável**.

### 6. `mental_models`

- **O que é:** tabela com modelos mentais documentados explicitamente (nome, descrição, origem, exemplos de aplicação, limites).
- **Por que importa:** alimenta `mental_model_usage_count` no `cognitive_metrics`. Sem isso, *"você documentou modelo X há 4 meses e nunca aplicou"* não acontece.
- **Benefício:** modelos mentais deixam de ser ideias soltas em capturas e viram ativos rastreáveis. Atlas confronta quando modelo fica dormente.

## Inteligência / Análise

### 7. Briefing matinal gerado por LLM

- **O que é:** hoje briefing é estático. v5 quer briefing gerado durante a noite com base em: capturas das 48h, métricas HealthKit (sono, HRV, mood), padrões detectados, estado do BlackInk, missão da semana — adaptado ao estado fisiológico atual.
- **Por que importa:** **Lei 2 (Adaptação a Estado)** exige que sistema se ajuste ao operador, não o contrário. Briefing genérico viola.
- **Benefício:** dia ruim → briefing simples passo-a-passo; dia pico → briefing com pergunta esticada; HRV baixo → menos demanda. Inicia o dia calibrado.

### 8. Análises noturnas em batch

- **O que é:** infraestrutura que roda LLM no dataset todas as noites, gerando insights, detectando correlações simples, preparando o briefing do dia seguinte.
- **Por que importa:** é a **Camada 1 do roadmap v5** (V1.5). Sem ela, todas as outras features dependentes de análise (briefing LLM, correlações, snapshot cognitivo) não existem.
- **Benefício:** Atlas trabalha enquanto Vitor dorme. Acorda com o sistema já mais inteligente sobre você.

### 9. Detecção de correlações tri-sensoriais

- **O que é:** queries cruzando os 3 sensores (Pensamento + Saúde + Cognição). Exemplos do v5: *"HRV abaixo de 45ms × reasoning depth 22% menor"*, *"sono <6h × application ratio cai 18% no dia seguinte"*.
- **Por que importa:** **é a razão fundamental do Atlas existir** segundo v5 [Cap 10.9]. Nenhum app de notas cruza assim. Nenhum app de saúde sabe o que você pensou.
- **Benefício:** insights únicos no mercado pessoal — coisas que nenhuma outra ferramenta consegue produzir.

## Detecção / Confronto

### 10. Articulação forçada

- **O que é:** quando captura é rasa ou vaga, o LLM responde com *"o que você quis dizer? articula o porquê. qual o raciocínio?"*. Pede reformulação.
- **Por que importa:** **mecanismo central da Lei 9**. Sem isso, Atlas vira coleta passiva e atrofia o operador em vez de fortalecer.
- **Benefício:** cada captura rasa vira oportunidade de pensamento mais profundo. Vocabulário e profundidade crescem com uso.

### 11. Detecção de muleta cognitiva

- **O que é:** quando Vitor pede *"decide por mim"* ou *"qual a resposta certa"* em decisões importantes, Atlas se recusa, força articulação de opções e prós/contras antes de validar.
- **Por que importa:** **falha catastrófica da Lei 9 disfarçada**. Operador terceirizando pensamento é exatamente o que o Atlas existe pra evitar.
- **Benefício:** Vitor mantém autoria. Atlas vira validador, não decisor — exatamente o que a Lei 1 (Composição Cognitiva) exige.

### 12. Hyperfocus detection

- **O que é:** Rize sinaliza 90+ minutos sem context switch significativo → Atlas silencia notificações, bloqueia briefings, aceita só captura passiva.
- **Por que importa:** **hyperfocus TDAH é o ativo cognitivo mais valioso** do operador (output 5x). Lei 2 trata como sagrado. Sistema que interrompe destrói o ativo.
- **Benefício:** Atlas para de ser fonte potencial de fragmentação durante os momentos de maior produtividade.

## Rituais / UX

### 13. Curadoria Semanal (Lei 10)

- **O que é:** ritual de 15-20 min no fim de semana onde Vitor revisa as 10-15 capturas mais ricas — para cada uma: manter, articular melhor, marcar como insight, ou descartar conscientemente.
- **Por que importa:** **Lei 10 (Curadoria de Input) sem ritual = sem enforcement**. Capturas brutas viram dataset com lixo.
- **Benefício:** dataset cresce em qualidade, não em volume. Métrica `Capture-to-Curated Ratio` vira realidade.

### 14. Weekly Review estruturado completo

- **O que é:** ritual semanal contemplativo (não só funcional): tela editorial, métricas das 4 dimensões com delta da semana, identificação de 1-3 insights reais, definição da missão da próxima semana, reflexão livre em italic Frau.
- **Por que importa:** **âncora temporal do sistema**. Sem ritual semanal, Atlas vira ferramenta. Com ele, vira prática.
- **Benefício:** loop de revisão ativa. Cada domingo Vitor sabe o que aprendeu, o que vai fazer, o que ficou.

## Bitácula avançada

### 15. Estados automáticos da Bitácula

- **O que é:** transição automática entre estados de fator: `active` (aparece no briefing), `experiment` (com hipótese ativa), `baseline` (estável demais, não pergunta), `dormant` (sem ocorrência por muito tempo). Hoje só tem `lifecycle_status` manual.
- **Por que importa:** sem isso, fatores estáveis viram pergunta repetitiva (fricção), e fatores dormentes viram ruído no briefing.
- **Benefício:** briefing pergunta só o que importa. Sistema **se auto-poda** sem operador intervir.

## Importação

### 16. Importação histórica HealthKit

- **O que é:** absorver os 2+ anos de dados acumulados no HealthKit do iPhone (sono, HRV, atividade, treinos) como `health_snapshots` históricos.
- **Por que importa:** **Atlas começa enriquecido em vez de vazio**. Análise longitudinal possível desde antes do Atlas existir.
- **Benefício:** correlações Saúde × Cognição aparecem com 2 anos de baseline em vez de esperar 6 meses pra acumular.

---

# 🟡 ROI 3 — Terceira onda, completa o sistema

## Dados / Schema

### 17. `cost_brl` + `applied_in_decision` em ai_jobs/traces

- **O que é:** extensão das tabelas existentes — adicionar custo financeiro real (BRL) por job e flag se aquela resposta da IA foi aplicada em decisão concreta.
- **Por que importa:** **Lei 3 (Custo Cognitivo) hoje só tem latência técnica**, perde o orçamento financeiro. E sem `applied_in_decision`, métrica *"insights acionados / gerados"* não existe.
- **Benefício:** dashboard mensal de custo IA + retorno (quantas decisões foram informadas). Lei 3 ganha braço financeiro.

### 18. `perceived_value` pós-rituais

- **O que é:** depois de cada ritual (briefing, weekly, decisão), aparece **3 botõezinhos** *"valeu? 1/2/3"*. Persistido em `cognitive_cost_signals` ou similar.
- **Por que importa:** sem isso, *"componente é descontinuado quando custo > valor"* (Lei 3) vira lei sem instrumento. **Único jeito de medir o "valor" subjetivo**.
- **Benefício:** auto-poda. Após 4 semanas de média < 2, Atlas sugere refatorar ou cortar o componente.

## Detecção / Confronto

### 19. Pattern Recognition Test

- **O que é:** Atlas detecta padrão real no dataset, **não mostra**, e pergunta no Weekly Review *"você notou alguma coisa em X?"*. Mede ratio de auto-detecção.
- **Por que importa:** se o ratio cai com o tempo, é sinal de muleta cognitiva (operador depende do sistema pra ver o óbvio). **Auditoria da Lei 9 por método indireto**.
- **Benefício:** Vitor cresce em capacidade de ler o próprio dataset, não fica refém do Atlas pra perceber padrões.

### 20. Detecção de atrofia cognitiva

- **O que é:** Atlas monitora `cognitive_metrics` e detecta queda sustentada — 2+ dimensões em queda por 2+ meses, ou plateau total por 6+ meses sem causa explicativa.
- **Por que importa:** **Lei 9 declara isso como falha catastrófica**. Sem detecção, falha não é detectada.
- **Benefício:** quando Atlas começa a virar muleta, o próprio sistema avisa antes da perda virar permanente.

### 21. Crisis detection

- **O que é:** queda abrupta em 3+ métricas simultaneamente (sono, focus, mood) → Atlas entra em modo cuidado: mensagem suave, sem demanda, sem confronto.
- **Por que importa:** **Lei 2 — adaptação a estado**. Em crise, sistema que continua exigindo performance é hostil.
- **Benefício:** Atlas vira aliado em momento difícil em vez de fonte adicional de cobrança.

### 22. 4-dimension tradeoff alert

- **O que é:** quando dimensão Performance Financeira sobe + qualquer outra (Saúde / Relacionamentos / Integridade) cai por 30+ dias → revisão estruturada *"Você está sacrificando X por Y. Isso é deliberado e por quanto tempo?"*.
- **Por que importa:** **Lei 5** existe pra prevenir bilionário-divorciado-doente-desonrado. Sem o alerta, a lei é decorativa.
- **Benefício:** gatilho automático para conversa que Vitor evitaria sozinho. Stakeholders primários protegidos.

## Captura / Sensores

### 23. Apple Watch Complication

- **O que é:** Watch Face mostra missão do dia + métrica primária (HRV ou energia subjetiva).
- **Por que importa:** **informação ambiente sem fricção**. Levantar pulso já mostra o que importa do dia.
- **Benefício:** consciência do dia constante e silenciosa, sem precisar abrir o app.

### 24. iOS Shortcuts

- **O que é:** atalhos Siri pra captura instantânea via voz (*"Atlas, captura: ..."*) e widgets na home do iPhone.
- **Por que importa:** captura sub-10s tem que estar em todos os pontos onde pensamento acontece.
- **Benefício:** captura sem abrir app, sem desbloquear telefone.

### 25. Rize.io integração completa

- **O que é:** hoje há captura passiva básica. v5 quer queries cruzadas — *"em que estado fisiológico Vitor faz seu trabalho mais profundo?"*, *"quais domínios consomem tempo desproporcional ao valor?"*.
- **Por que importa:** Rize tem dado riquíssimo sub-utilizado. **Sensor de atividade digital + saúde + cognição é tríade única**.
- **Benefício:** insights tipo *"sua melhor janela de código é após sono ≥7h, antes das 11h"*.

## Rituais / UX

### 26. Check-in 3x/dia automático

- **O que é:** push lembrete às 10h, 14h e 17h pra registrar estado (foco / disperso / bloqueado / pausa) + energia (1-5). Hoje captura só on-demand.
- **Por que importa:** **resolução temporal do estado cognitivo**. Sem 3 pontos diários, padrões intra-dia não aparecem.
- **Benefício:** dataset granular suficiente pra correlações tipo *"manhã produtiva, tarde dispersa"* virarem visíveis.

## Bitácula / Compliance

### 27. Family causal canonicalization

- **O que é:** fatores agrupados por **família causal** ("café", "tereré", "mate", "energético" → família `cafeína`), com timing relevante ("após 14h"). Análise pela família, não palavra literal.
- **Por que importa:** sem isso, dataset fica fragmentado — três logs do mesmo fenômeno (cafeína em formatos diferentes) não correlacionam.
- **Benefício:** correlações reais aparecem. *"cafeína após 14h × sono ruim"* deixa de ser invisível porque não há "café" todo dia.

### 28. Anti-pseudoscience prompt middleware

- **O que é:** todo prompt de IA que toca behavior/correlation **prefixa com regras de linguagem** v6 [1612]: permitidas (*"aparece associado", "indício fraco", "vale testar"*) vs proibidas (afirmações causais sem experimento).
- **Por que importa:** sem isso, Atlas pode soltar afirmação causal infundada. **Quebra a confiança no sistema**.
- **Benefício:** linguagem de Atlas calibrada cientificamente. Confiança aumenta com uso.

### 29. Privacidade Relacional

- **O que é:** quando captura envolve outra pessoa (Carol, sócio, amigo), **redação automática** antes de o conteúdo ir pra IA externa. v6 [1608].
- **Por que importa:** **dados de terceiros não pertencem ao Atlas**. Princípio ético + legal.
- **Benefício:** Vitor pode capturar livremente sobre relações sem expor terceiros a IAs externas.

### 30. Tier 1-4 privacy classification + redaction

- **O que é:** todo dado classificado em 4 tiers — L1 (público) → L4 (crítico, nunca sai do device). L3 redacted antes de IA externa. v3 [10.6].
- **Por que importa:** **soberania de dados** (Lei 6) sem mecanismo concreto vira só intenção.
- **Benefício:** saúde, finanças, conteúdo íntimo do casal protegidos por arquitetura, não por boa vontade.

## Camada 3 — Ação Executiva

### 31. EventKit (Calendar) function calling

- **O que é:** Atlas cria/move eventos no calendário via function calling, com confirmação humano-na-loop antes de ação irreversível.
- **Por que importa:** **substituição funcional do Néctar**. Atlas não só recomenda, age — quando autorizado.
- **Benefício:** *"bloqueia 2h amanhã pra deep work em X"* vira ação real, não recomendação.

### 32. Reminders function calling

- **O que é:** Atlas cria lembretes no app Reminders baseado em padrões detectados.
- **Por que importa:** lembretes baseados em padrão (*"você revisita decisões em média 30d depois — quer lembrete agendado?"*) só funcionam se Atlas conseguir criar o lembrete real.
- **Benefício:** sistema fecha o loop de "detectou padrão → criou lembrete → você reviu na hora certa".

---

# Resumo executivo

| ROI | Quantos | Foco |
|---|---|---|
| **5** | 4 | Núcleo: cognitive_metrics, decisions, snapshot trimestral, watch button |
| **4** | 12 | Coach real: hipóteses, mental models, briefing LLM, análises noturnas, articulação forçada, muleta detection, hyperfocus, weekly review, curadoria semanal, bitácula states, importação HealthKit |
| **3** | 16 | Completa: cost tracking, perceived value, pattern recognition test, atrofia/crisis/tradeoff detection, complication, shortcuts, Rize integração, check-in automático, family causal, anti-pseudoscience, privacidade tiers, EventKit, Reminders |

**Total: 32 gaps com ROI ≥ 3** que estavam specificados em v3/v5/v6 e nunca foram pro código.

# Roadmap sugerido em ondas

**Onda 1 — Auditabilidade (Lei 9):** gaps 1, 3, 17 → cognitive_metrics + snapshot + cost tracking. Atlas ganha o instrumento de auto-medir.

**Onda 2 — Decisão como ato persistido:** gaps 2, 6, 5 → decisions + mental_models + life_hypotheses. Atlas vira repositório de inteligência operável.

**Onda 3 — Atlas vira coach (Camada 1+2):** gaps 7, 8, 10, 11, 19 → briefing LLM + análises noturnas + articulação forçada + muleta detection + pattern test. Atlas para de ser reativo.

**Onda 4 — Sensores cheios:** gaps 4, 16, 23, 24, 25, 26 → Watch button + import HealthKit + complication + shortcuts + Rize completa + check-in 3x. Captura completa.

**Onda 5 — Confronto + Compliance:** gaps 12, 13, 14, 15, 18, 20, 21, 22, 27, 28, 29, 30 → o resto da Camada 2 + privacidade.

**Onda 6 — Camada 3 (ação executiva):** gaps 31, 32 → Atlas age, não só recomenda.

---

**Última atualização:** 1 de maio de 2026
**Origem da auditoria:** sessão Atlas com Claude (estado do DB ao vivo cruzado com docs Mestre v3, v5, v6)
**Não esquecer:** este documento existe porque o operador descobriu que features que ele achava estarem prontas, não estavam. Voltar aqui antes de planejar próxima fase do Atlas.
