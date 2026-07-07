# Obra #17 — ACOS 3×: plano mestre (síntese de 3 especialistas adversariais)

Data: 2026-07-06 · Método: 3 agentes (Frontier / Crítico / Valor) pensando em paralelo + síntese · Status: aprovável
Supersede a ORDEM das Obras #15/#16 (que ficam como anexos de design); nenhuma fase delas é executada como escrita — cada uma entra aqui cortada, corrigida ou re-sequenciada com evidência.

> **Governança:** Carta de Autonomia — o Atlas age sozinho na main local, commita e etiqueta no Diário de Evolução; **sem aprovação**. Onde este doc disser aprovação/assinatura/evento-operador/G0-não-auto-promove, vale o autônomo.

## Por que este plano é 3× melhor que #15+#16

1. **Ataca a causa provada, não o sintoma.** O Crítico provou NO CÓDIGO: `AtlasMemoryRegistryService::relevantForContext()` (linhas 251-309) seleciona candidatos de memória **sem usar a pergunta** — só escopo + `priority DESC` + LIMIT; os vetores apenas reordenam esse conjunto cego (`AtlasHybridMemoryRetrievalService::registryItems()`); o filtro final do pack é overlap lexical; e o re-rank semântico é flag default OFF (`ATLAS_AOBG_SEMANTIC_RETRIEVAL`). **Nenhuma quantidade de re-rank conserta um candidate set que já veio errado.** #15/#16 construiriam catedrais de compreensão sobre essa fundação. Aqui, o retrieval-por-query é o item 0 — dias de trabalho que destravam de graça o guard de decisão que JÁ EXISTE (`decisionViolationCheck` wirado no PreToolUse — F4 da #15 era diagnóstico falso) e o matcher de refutação.
2. **Valor sentido em cada degrau, medido por eventos que o operador vive.** Manchete deixa de ser CPR (mecanismo, gameável) e vira os **3 contadores da semana**: TPE (turns até a 1ª edição correta em início/retomada), perguntas evitáveis (resposta já existia no registry → falha do ACOS), colisões tardias (retrabalho por decisão/refutação que deveria ter aparecido na spec). Auditáveis em 5 min (`atlas valor semana`); nenhum sobe sem a entrega real melhorar.
3. **Os saltos de fronteira entram como camada composta, condicionados à fundação provada** — com substrato verificado no código (TEOS ocioso, AURG-4D pronto sem produtor, ARFL coletando sem consumidor), nunca como aposta paralela.

## Regras transversais (aprendidas com prova nesta casa)

- **Baseline ANTES do código**: toda fase mede o estado atual real antes de prometer gate (ex.: medir hoje quantos turns custou retomar a #8; sem baseline, "≤3 turns" é vitrine).
- **O Atlas declara pronto pelos próprios checks + etiqueta no Diário**: instrumento auto-construído certifica `building`; o Atlas mede sozinho, declara a fase pronta e etiqueta 'evolucao-de-fase' no Diário (o dia 06/07 provou: mecanismo 9.43 ≠ valor 4/10).
- **Nada depende de LLM noturno autônomo** até existir caminho provado (assassino silencioso da F1/P1 originais).
- **Anti-morte-silenciosa**: heartbeat com alerta ATIVO (no hook de toda sessão, não em comando que ninguém roda); flags viradas no mesmo commit do fix; staleness sempre visível ("BRIEF STALE desde X" em vez de brief velho mudo); contador de skips do embed-on-write no maintenance-status.
- Pétreas de sempre: byte-prova; G0 auto-promove após os checks automáticos verdes + etiqueta no Diário de Evolução (reversível); verify-then-absorb; vocabulário proibido; push ao remoto só com OK (eixo separado; a main local é autônoma); estado fora da working tree dos workers (`storage/atlas/`, lembrar `rg --no-ignore`).

## T0 — FUNDAÇÃO (dias): o fix que muda tudo

| # | Entrega | Gate (com baseline) |
|---|---|---|
| 0.1 | **Retrieval por query** em `relevantForContext`: vetorial via `AtlasMemoryVectorSearchService` (existe, pgsql/pgvector) + fallback FTS/LIKE em sqlite (suite não degrada); flag semântica **ON por default no mesmo commit**; `retrieval_mode` auditado no evolution-score (lexical em pgsql ⇒ nota cai) | recall sobre trabalho das últimas 2 semanas NUNCA vazio; teste cego (pergunta sobre decisão de 2+ semanas) passa |
| 0.2 | **Medir o guard que já existe**: 20 edições-teste em zonas com decisão registrada; hit-rate do `decisionViolationCheck` antes/depois do 0.1 | hit-rate publicado; esperado: salto grande só com 0.1 (prova que o gargalo era retrieval) |
| 0.3 | **Alerta ativo de heartbeat**: UserPromptSubmit injeta 1 linha se `heartbeat.jsonl` >24h (~5 linhas no hook) | scheduler nunca mais morre 8 dias em silêncio |
| 0.4 | **Baselines reais**: TPE de retomada da obra ativa, perguntas evitáveis da última semana, colisões tardias da #8 (=10) | tabela de partida publicada |

## T1 — "RETOMEI E ELE SABIA" (semanas 1-2) · nota-alvo 4→6

- **Estado por obra** (F2 estreita): arquivo canônico em `storage/atlas/obras/` escrito pelo Stop hook (fase, slices, provas, pendências, decisões intermediárias, delta de drift da main) + **pack de retomada** no UserPromptSubmit ("você estava no slice N, provou X, falta Y, cuidado com Z"). Nada de "estender o harvester" (é parser de regex manual — o Crítico provou); é órgão novo pequeno no canal vivo. Aproveitar o `LongHorizonContinuityPackEmitterService` órfão (existe, agendado, **não consumido pelo pack** — religar em vez de construir).
- **Matcher de refutação na admissão** (F6 adiantada): spec/task nova casa contra `refutation_memory` ANTES de implementar (pipeline já existe até o matcher; recall consertado no 0.1 o torna viável).
- **3 contadores rodando** desde o dia 1 + `atlas valor semana` (3 números + 3 recibos com link).
- **Gate:** retomada real da obra ativa em ≤3 turns (vs. baseline 0.4), o Atlas mede sozinho e etiqueta 'evolucao-de-fase' no Diário.

## T2 — "LEMBRA POR QUÊ E AVISA ANTES" (semanas 3-5) · 6→8

- **Brief determinístico v1** (F1 sem LLM): template gerado por comando — módulos por churn, invariantes = decisões pétreas do registry, refutações, arquivos quentes por git log, `generated_at`+hash do HEAD (stale ⇒ pack injeta "BRIEF STALE", nunca brief velho mudo). Atualização pelo P6 (sessão aberta), não por cadência autônoma.
- **Delta de surpresa** (P6 mínimo): campo estruturado no Stop hook — "o que o pack não tinha e a sessão descobriu"; vira candidato G0 com prioridade.
- **Crítica adversarial de spec** (P3 — a fase mais sólida da #16): evento-driven (por spec, imune a scheduler), crítico municiado com brief+refutações; na #8, 10 refutações só apareceram NA execução — o alvo é pegá-las antes.
- **Gate:** perguntas evitáveis caem ≥50% vs. baseline; ≥1 aviso de decisão correto/semana; próxima spec grande pega ≥1 defeito antes da implementação.

## T3 — QUALQUER REPO + RAIO DETERMINÍSTICO (semanas 6-8) · 8→9

- **Multi-projeto real**: `workspace activate` → index-code + brief mínimo automático (retriever já é workspace-scoped por construção — só falta o disparo). Gate: repo novo → brief útil ≤1h; TPE do primeiro dia comparável ao do atlas-server.
- **Raio de explosão determinístico** (P2 sem a fantasia): call graph afetado + testes que cobrem + decisões tocadas, como consulta ao code graph existente. Gate: cobre ≥85% dos arquivos realmente tocados em 10 slices reais.

## T4 — SALTOS FRONTIER (condicionados: T0-T2 declarados prontos pelos próprios checks do Atlas + etiqueta no Diário)

Ordem por retorno/custo, cada um com substrato verificado:

| Salto | Essência | Substrato ocioso que religa | Gate |
|---|---|---|---|
| **S7 Consolidação noturna do retrieval** | misses do dia viram hard negatives; re-ranker local re-treina; golden set cresce de casos reais; auto-revert por métrica (mecânica do harness autopilot, provada) | ARFL capture + AREBA + semantic_rag venv | precision@k no controle congelado nunca regride; conjunto vivo sobe 4 semanas sem mão humana |
| **S2 Surpresa medida como gate de gravação** | predição barata pré-sessão (aposta registrada) vs. realizado; o que surpreendeu grava com prioridade, o previsto quase não grava — memória por informação nova, não harvest plano | TEOS-I3 (registrador de apostas sem consumidor), ProductiveFailureComparisonEngine, G0-G8 | memórias de alta surpresa usadas ≥2× mais em packs (30d); candidatos G0 caem ≥40% sem perda de precision |
| **S4 Recall conversacional** | Open Brain MCP vira interlocutor com estado de sessão (arquivos tocados realimentam o recall) + mapa de incerteza ("não sei X; se descobrir, devolva") | MCP + PostToolUse (estado já capturado) + ACMF + ARCLG (p95<2s, nunca bloqueia) | ≥50% das consultas mid-session usadas; ≥30% do mapa de incerteza volta preenchido |
| **S5 Motor de contradição dialético** | tensões memória×memória/×código como objeto persistido com workflow de resolução — o imune cognitivo apontado para dentro | refutation matcher + Memory Relations (Absorção 2 aprovada, subusada) + embeddings | precisão ≥70% no backlog auditado; zero packs entregando os 2 lados de tensão aberta sem marcar |
| **S1 Modelo bi-temporal** | conhecimento com dois eixos de tempo (verdade no código × quando o ACOS soube) — mata a causa do stale | AURG-4D (`stateAt`/`traverseTime` prontos, sem produtor) + code graph | 30 perguntas temporais ≥85% contra o git real |
| **S3 Memória procedural** | know-how vira playbook testado (o checklist cirúrgico, não o diário) injetado por classe de tarefa | corpus de reparos/RepairBrain + AEMOR distill + Self-Construction builder | ≥5 playbooks; −40% turns na classe com playbook (AEMOR) |
| **S6 Replay contrafactual de fechamento** | ao fechar obra, re-julga as alternativas descartadas contra o outcome real — calibra o crítico do P3 (senão ele deriva como os finders 3-10×) | TEOS-I3 branches + Evidence Ledger + receipts | curva de calibração publicada após 5 obras; ≥1 refutação revertida por evidência |

Adiados com razão registrada: P1 briefing noturno completo (LLM headless não provado; o delta de drift entra no pack de retomada do T1), P4 risco causal aprendido (N pequeno — heurística churn×reds dá 80% hoje), P5 hierarquia episódica (prematura até a camada plana provar 4+ semanas), P7 A/B de composição (otimizar motor antes do carburador), metade contrafactual do P2 (receipt-machines nunca provaram previsão real).

## Medição-manchete (a régua do operador)

`atlas valor semana`: **TPE | perguntas evitáveis | colisões tardias** + 3 recibos com link + teste cego de 1 minuto (pergunta sobre decisão de 2+ semanas a uma sessão fresca). CPR, precision@k e golden sets viram instrumentação interna de slice. A nota da obra sai desses eventos reais que o Atlas mede sozinho e etiqueta no Diário — nunca de instrumento auto-construído gameável.

## Uma linha

Conserte a fundação em dias (retrieval que usa a pergunta), meça o que já existe antes de reconstruir (guard de decisão), entregue valor sentido a cada degrau (retomada → porquês → qualquer repo), e só então componha os saltos que fazem o cérebro melhorar dormindo — cada um religando substrato que já está construído e ocioso.

## ADENDO DE IMPLEMENTABILIDADE (vinculante — 06/07, pós-auditoria do Agente B + verificação cruzada)

Este adendo corrige/precisa os slices para execução por modelo barato. Em conflito com o corpo acima, o adendo vence. Nenhum slice se implementa sem ordem de trabalho (ver `docs/obra-linha-acos-leia-me-implementador.md`).

**T0.1 (retrieval por query):**
- ADITIVO-ONLY: a assinatura `relevantForContext(array $context, array $filters = [], int $limit = 12)` (AtlasMemoryRegistryService.php:251) NÃO muda. A query entra como chave opcional `$context['query']`; ausente ⇒ comportamento byte-idêntico ao atual.
- Callers congelados (verificados por rg em 06/07; nenhum muda): AtlasHybridMemoryRetrievalService.php:179, AiContextPackBuilder.php:249, AtlasProviderProjectionService.php:730, EngineeringContextPackService.php:178. (AtlasVerbatimMemoryService:96 é MÉTODO PRÓPRIO homônimo de outra classe — não tocar.)
- Flag: `config/atlas.php:4068` → `'semantic_retrieval' => env('ATLAS_AOBG_SEMANTIC_RETRIEVAL', false)` — virar o DEFAULT no config para true no mesmo commit (não no .env). Efeito colateral a corrigir junto: AobgSemanticRetrievalLiftService imprime instruções assumindo OFF.
- Fallback sqlite: detectar driver pela CONNECTION do model (`AtlasMemoryEntry::query()->getConnection()->getDriverName()`), nunca por `config('database.default')`. pgsql ⇒ vetorial via `AtlasMemoryVectorSearchService`; sqlite ⇒ LIKE/FTS sobre title+summary+body.
- `retrieval_mode` auditado: campo novo no sinal `feedback_loop_vivo` de `app/Services/Ai/Cognition/AtlasAcosEvolutionScoreService.php` (serviço EXISTE — não criar comando novo).
- Ordem de trabalho pronta: `docs/work-orders/WO-17-T0.1-retrieval-por-query.md` (teste de aceitação pré-escrito incluso).

**T0.2 (medir o guard existente):** os símbolos EXISTEM — `AtlasOpenBrainGuardService::decisionViolationCheck()` (app/Services/Ai/AtlasOpenBrainGuardService.php:243, chamado em :160) via hook `.claude/hooks/atlas-pretooluse-guard.sh`. (A auditoria B acusou fantasma por grep no lugar errado — re-verificado em 06/07.) O protocolo de 20 edições roda como gate:automático (o Atlas roda e fecha; passou → etiqueta no Diário); o hit-rate publica no doc da obra.

**T0.3 (alerta de heartbeat):** o artefato EXISTE: `storage/atlas/scheduler/heartbeat.jsonl` (verificado, escrito pelo scheduler; também lido por AtlasAcosEvolutionScoreService::heartbeatFresh). Landing site: `.claude/hooks/` (o hook UserPromptSubmit). Regras: fail-OPEN sempre (check quebrado nunca degrada a sessão); dedup da linha injetada (hooks registrados 2× no settings.json); `rg` em storage/ exige `--no-ignore`.

**T0.4 (baselines):** o Atlas mede sozinho a partir de eventos reais e etiqueta no Diário — nunca fabricado (proibido inventar TPE; o número sai do trabalho real ou fica vazio).

**T1:** estado de obra em `storage/atlas/obras/<obra-id>.json` (schema na futura WO); identidade da obra ativa via arquivo `storage/atlas/obras/current` escrito por comando explícito (nunca inferida); "religar" o `LongHorizonContinuityPackEmitterService` só com call site consumidor NOMINAL no pack + teste que prova a seção presente; matcher de refutação exige antes o inventário nominal do pipeline (pendência atribuída ao scaffolder K2 da #18).

**Regra geral:** toda sigla em ordem de trabalho vem com path absoluto no glossário; ordem com sigla não-resolvida é inválida (linter K3).
