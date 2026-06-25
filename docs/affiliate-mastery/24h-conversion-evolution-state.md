# Ledger — Missão 24h: Salto Exponencial do Cérebro de Conversão do Atlas

> Objetivo: o MAIOR salto REAL de inteligência e assertividade de conversão do cérebro de
> direct-response do Atlas (funil/bridge/handoff, dissecação de VSL, rede de pesquisa + Keyword OS,
> libraries de persuasão, Conversion Pattern OS). Vender qualquer coisa, mais barato e mais certeiro.
>
> Piso pétreo: anti-Goodhart (alavanca exponencial real, nunca proxy/faxina/refactor); honestidade
> (nunca fabricar prova; teste verde + fonte real ou não conta); provider-free no caminho crítico;
> calibração/flywheel DORMANT até dado de venda real. NÃO rodar campanha/gasto ao vivo. Blackink/Nivor
> read-only. Commit por pedaço (NÃO push).

## Princípio de seleção de alavanca
A maior alavanca é tipicamente **conhecimento construído mas NÃO consumido** (dead knowledge): uma
inteligência que vive como memória/library mas não dirige nenhuma decisão/geração. Transformar isso em
**motor determinístico consumido** é o salto composto — porque passa a multiplicar toda entrega, sem
depender do provider fraco.

## Mapa de ciclos

| Ciclo | Alavanca | Projeção/Crítica | Implementação | Prova (testes/commit) | Próximo |
|---|---|---|---|---|---|
| 0 | Compreensão + seleção da alavanca #1 | ✅ workflow 13-agentes | — | seleção: Conversion Critic | C1 |
| 1 | Conversion Critic (gate provider-free) — Slice 1: `ConversionCriticGate` | ✅ adversarial (todos candidatos proxyRisk; SearchNetworkDecider tinha wiring FALSO) | ✅ classe + 7 unit tests | 7/7 verde; commit ⬇ | Slice 2: wiring no `compose()` |

## Log detalhado

### Ciclo 0 — Compreensão + seleção (em andamento)
- Disparada varredura grounded multi-agente (`wkxiueu95`): mapear o caminho de construção de campanha, o
  caminho de geração+julgamento de copy, o caminho de dissecação→geração de VSL, e o inventário de
  dead-knowledge; projetar 4 candidatas a alavanca-#1; crítica adversarial; selecionar + spec de build.

- **Sonda manual de dead-knowledge (cross-check independente):** o domínio Campaign tem ~60 classes.
  `SearchNetworkPlanner` JÁ consome `KeywordIntentMapper`/`BroadMatchStrategist`/`NegativeListMiner` e é
  chamado no caminho vivo (BridgePageComposerService) → o motor de decisão de rede existe PARCIALMENTE.
  Probe de referências fora do próprio arquivo (app/) achou ÓRFÃS (0 refs):
  `FunnelSequenceDecider`, `SmartBiddingReadinessDiagnostic`, `TrackingStackDecider`,
  `ConversionPipelineValidator`. Baixíssima (1 ref): `KeywordMindState`, `MarketSophisticationSignal`,
  `KeywordPageRouter`, `KeywordPainModifierSignal`, `FewShotConfidenceMeter`, `ReFinderFabricationPlanner`.
  → Hipótese: a alavanca composta é ARMAR conhecimento morto num orquestrador consumido (não construir
  do zero). Confirmar/refinar com a seleção do workflow antes de implementar.
- **Dois seams candidatos aterrados (cross-check independente, linha a linha):**
  - *Alavanca A — armar deciders órfãos no blueprint:* `CampaignBlueprintService.generate()` injeta 8 peças
    mas não consome `ConversionPipelineValidator` (gate de tracking = PASSO 0 "dá pra medir a venda?"),
    `FunnelSequenceDecider`, `MarketSophisticationSignal`. Seam = entre `$firstTest` e `create([...])`
    (CampaignBlueprintService.php ~63-71); persistir `funnel_plan`/`tracking_readiness` no blueprint.
  - *Alavanca B — Conversion Critic gate:* `ConversionAuditor.audit()` retorna score-soft (auto-rotulado
    "bússola, não verdade") e está só no bloco `grounding` (BridgePageComposerService:219) — NÃO é gate. O
    attempt-loop do `compose()` não recusa copy fraca por qualidade de conversão. Seam = a break-condition +
    `correctionNote` do loop (BridgePageComposerService ~115-137).
  - Ambas: exponenciais, provider-free, wireable+testáveis agora. A seleção adversarial do workflow crava a #1.
- Resultado e escolha do workflow entram aqui quando a varredura voltar.
