# Loop Brain — ESTADO VIVO (maturidade por eixo + o que falta + o que originei)

> O estado de onde EU CONTINUO. Não reconstruo o que já existe; parto daqui.
> Fonte de verdade da arquitetura: `docs/loop-brain-architecture.md` (v2, os 7 fatais). Build order: `docs/loop-night-backlog.md`.
> Régua: 0-100 mede contra a arquitetura CONHECIDA; **acima de 100 = fronteira original** (evolução real, provada, fora da arquitetura atual).
> Métrica sagrada: **excelência PROVADA do fluxo** end-to-end com MÚLTIPLOS clientes — nunca contagem, nunca maturidade auto-declarada.

## Régua-piso atual: **40** (todos os eixos precisam ≥ 40 antes de subir a régua para 45)

## Os 10 eixos (PART 1 compreensão · PART 2 serving)

| # | Eixo | Mat. | O que EXISTE (provado) | O que FALTA (próximo nível) |
|---|------|------|------------------------|-----------------------------|
| 1 | **Completude** (compreensão sabe tudo) | 70 | `AtlasLoopScopeComprehensionModel`+Builder (determinístico), `…Query` memoizado (P1-A), `atlas:loop:comprehend` (B4), read-model persist por snapshot (B1) | objetivos/ambições/comportamento por unidade; níveis-por-unidade além do `level_vector` |
| 2 | **Vivacidade** (sempre atual) | 55 | `staleness()`; read-model JSON por `snapshotId` (B1); edges re-derivam por full-grep | invalidação incremental honesta por-arquivo; freshness com TTL/dirty-bit |
| 3 | **Verdade-runtime** (sinais reais) | 40 | `has_gate_block` + `last_merge_clean` (grátis) | **B2 NÃO wired**: `recordOutcome`→`CapabilityTrend` (deadlock Fibonacci MF-18 aberto); line-coverage real |
| 4 | **Poder de consulta** (FATOS, nunca scalar) | 70 | `transitionsFor` + `level_vector` estrutural + comprehend CLI; invariante pétrea "FACTS not scalar" intacta | superfície de consulta que a Part 2 puxa por critério (sem ranquear) |
| 5 | **R1 — fila nunca seca** | 45 | abstain-and-ask honesto (`no_claimable_task`+escalation); `QueueFillSentinel` DETECTOR (A8) | **JOIN/ARMS NÃO wired** (cérebro→fila); cap R1 model-bound honesto; `BrainLeverageSourceProvider` |
| 6 | **R2 — serving nunca falha** | 55 | claim atômico flock (A1) + CAS (A2) + reaper agendado+pre-sweep (A3); `ServingSlaSentinel` (A8) | **NÃO provado sob concorrência real**; heartbeat de liveness wired ao lease |
| 7 | **Conflict-free PERFEITO** | 50 | `WriteSetOverlap::conflict` central prefix-aware + read-write (A4/A5); CAS claimable→claimed | **prova é SEQUENCIAL** — N-clientes simultâneos forçando colisão NÃO existe ⇒ propriedade não-provada |
| 8 | **Qualidade do task-packet** | 55 | envelope auto-suficiente (id/lease/scope/aceite/required_evidence/régua) | **scorer de auto-suficiência** (packet implementável sem contexto?); contexto embutido |
| 9 | **Main sempre limpa** | 15 | — | **`completeReal()` (B3) NÃO wired ao serving** (MF-03/04); re-arquitetura do contrato de segurança (obra de semanas, gated OFF) |
| 10 | **Coordenação impecável + contrato `next\|report`** | 50 | A7 `atlas:task next\|report` (o CORAÇÃO) + CLI front-door; `client_id` opaco platform-free | **MCP wrapper pendente**; painel de saúde da coordenação; **prova end-to-end multi-cliente** |

## GATE bloqueante pendente (arquitetura Parte 6, passo 3)
- **A6 — reconciliação para UMA fila**: NÃO feito. 4 stacks vivas (A orchestrator / B god-class 104k `claimNextPacket` / C `AtlasLoopStore` / D `DeliveryPipeline`). A7 elegeu Stack A como canônica e serve, **mas sem o gate binário que prova "exatamente 1 stack viva + 1 bomba viva"** e **sem o MasterSwitch alcançar o caminho de claim/replenish** (`grep AtlasLoopMasterSwitch SelfConstruction/` = só o wrapper A7 → MF-14: "OFF=byte-identical" é FALSO no claim direto). Extrair `claimNextPacket`/reservation da god-class é pré-requisito (obra própria).

## Caps honestos (model-bound — NÃO fingir destravados)
- **R1**: originação 100%-autônoma de ALTA alavancagem em código maduro = model-bound. Sirvo a fila que EXISTE; abstain honesto é o modo normal.
- **Merge real cross-cliente**: certificação hoje prova NÃO-execução; `completeReal` é gated default-OFF; flip ≠ destrava (re-arquitetura do contrato de segurança).
- **Verifier multi-arquivo**: single-file hoje; multi-file = obra custeada.

## Log de ciclos (o que originei, provado end-to-end)
- _(início)_ Baseline: 15/15 verde (`AtlasTaskServingContractTest`, `…SentinelTest`, `WriteSetOverlap*Test`). Stacks/gap A6 mapeados acima.

## Próximo alvo (menor maturidade × maior alavancagem)
Eixo **7 (conflict-free, 50)** + **6 (R2, 55)** + **10 (coordenação/prova, 50)** convergem num único instrumento que o goal exige (step 3): **um simulador de N-clientes CONCORRENTES reais que força colisão + o raio-X conflict-free**. Sem ele, "conflict-free" é alegação não-provada (o teste atual é sequencial). É também a ferramenta-juiz de todo ciclo futuro. **Em construção.**
