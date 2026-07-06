# Obra #14 (proposta) — Evolução Estruturada do ACOS: de esqueleto certificado a cérebro vivo

Data: 2026-07-06 · Base: medições reais do dia (Obras #8/#12/#13) · Status: aprovável

## Diagnóstico honesto (por que "parece fraco")

| Dimensão | Hoje | O gap real |
|---|---|---|
| Estrutura (código) | 10/10 | nenhum — 73/73 existem |
| Fiação local | ~9/10 | certificadores passam (APCR 14/14, ACIE 13/13) |
| Execução provada | **6.96/10** | 72 receipts cunhados HOJE são one-shot; 42 subsistemas ainda partial/building; o `acos-long-horizon-gate` exige ≥30 dias de série de deltas — hoje temos 1 dia |
| Inteligência entregue | **~4/10** (era 2-3 de manhã) | os 4 órgãos novos (#13) existem mas NÃO rodam sozinhos; packs ainda ruidosos (loop de feedback tem 0 ciclos de aprendizado); ACRS/ACFQ são advisory sem enforcement |
| Autonomia | **~1/10** | Loop em Tier-0 (S49); AAEOS Dev L1; nada executa ponta-a-ponta sem humano |

**Tese da evolução:** o dia provou que o caminho não é rewrite grande — é órgão pequeno + medição + cadência. Cada horizonte abaixo tem gate MENSURÁVEL de saída (número, não prosa).

## Horizonte 1 — PISO CONFIÁVEL (1-2 semanas de esteira)

| Slice | Ação | Gate de saída |
|---|---|---|
| H1.1 | **Obra #9 (vermelhos)**: censo JUnit por diretório → atribuir cada red (bug real / teste stale / flag não-wirada) → zerar. Primeiros insumos já diagnosticados: drift `needs_context` do AssistedExecutionQuality, quarteto de flags do comando-mãe, 440 do mega (89% = fix dos fatais em voo) | **0 reds não-atribuídos na suite**; scorecard não regride |
| H1.2 | **Receipts contínuos**: agendar `mint-pipeline-receipts` (scheduler diário já existe no Laravel) + série de deltas persistida | ≥30 dias de série → `acos-long-horizon-gate` L6-9 PASSA |
| H1.3 | Doc dimension: os 17 docs building → ready ou rebaixados com honestidade (protocolo verify-then-absorb) | doc ≥ 9.0 |

**Gate do H1: scorecard ≥ 9.0 overall com pipeline ≥ 8.5 e suite atribuída.**

## Horizonte 2 — INTELIGÊNCIA QUE RODA SOZINHA (2-4 semanas)

| Slice | Ação | Gate de saída |
|---|---|---|
| H2.1 | **Cadência dos órgãos da #13**: refactor-census semanal por módulo (agendado) com relatório versionado; harvester de lições disparado quando doc de obra muda (hook PostToolUse já existe como padrão); feedback-auto já roda por sessão (feito) | 4/4 órgãos com execução AGENDADA e receipts |
| H2.2 | **Enforcement do contexto**: ACRS/ACFQ deixam de ser advisory — persistência de receipts (ACOP) + gate obrigatório no pack final (a spec dos docs canônicos já define o que falta em cada bloco) | packs com receipt persistido; ACFQ bloqueando contexto stale em ≥1 fluxo real |
| H2.3 | **Ciclo de promoção das lições**: revisão semanal dos learning candidates G0 (comando de digest já há padrão) → promovidos viram memória que o AOBG entrega | ≥1 lição promovida consumida num pack (medível via feedback_scope) |
| H2.4 | **Relevância dos packs**: com 30+ dias de eventos ARFL, medir precision@k real via AREBA e ajustar pesos do ACRS | precision@k do golden set sobe vs baseline de hoje |

| H2.5 | **Anti-lixo do pack (as 4 fontes medidas em 06/07)**: (a) **prune do echo de sessão** no reality graph — mensagens brutas antigas do operador aparecem como "decisões" em cross-layer paths (gotcha do fix 6fe3da7b7c: falta marker no write-back + prune do DB) → higienizar o DB existente + marker definitivo; (b) entradas STALE do índice (ex.: "não foi para main" para código que está na main) → recheck contra git no momento da entrega ou TTL; (c) relevância de símbolos (coberto por H2.4); (d) recall de memória (coberto por H2.3) | pack de 10 turnos-teste SEM nenhuma linha de echo/stale (auditoria manual + teste automatizado do filtro) |

**Gate do H2: o ACOS aponta ≥1 refatoração/melhoria POR SEMANA sem humano pedir (census agendado + candidates G0), os packs melhoram com feedback medido, e ZERO echo/stale nos packs.**

## Horizonte 3 — AUTONOMIA GOVERNADA (4-8 semanas, decisões suas)

| Slice | Ação | Gate |
|---|---|---|
| H3.1 | **Obra #10** (reviver seções C-01/02/03, ~16k) — destravada pelo merge do fix dos fatais | goldens verdes + fatais zero |
| H3.2 | **AAEOS Fase 0/1: implementar S49→S50→S55** (cadeia de promoção de tier + provider real + 1º merge) — IMPLEMENTAR apenas; rodar só com sua ordem (mandato pétreo) | código+teste da cadeia prontos, tier continua 0 até seu OK |
| H3.3 | Scorecard como **gate de obra**: toda obra futura declara o delta esperado nas dimensões e o fechamento exige medição | obras com before/after de scorecard no commit de fechamento |

**Gate do H3: o Loop tem o caminho L0→L1 implementado e auditado, aguardando exclusivamente a sua assinatura (S49).**

## Regras herdadas (pétreas)
Byte-prova na execução (finders superestimam 3-10×); líquido ≤0 → refutar e registrar; tripla prova para deleção; G0 nunca auto-promove; push só com OK; lane do operador para HOT_SCOPE; harvester de lições roda no fechamento de TODA obra desta evolução.

## Ordem recomendada
H1.1 → H1.2 (paralelo) → H1.3 → H2.1 → H2.2 → H2.3/H2.4 → H3 conforme suas aprovações. A Obra #11 (green-run) fica ABSORVIDA por H1.2 (de one-shot para contínuo).
