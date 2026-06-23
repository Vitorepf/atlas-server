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
| 3 | **Verdade-runtime** (sinais reais) | 55 | `has_gate_block` + `last_merge_clean` (grátis); **trend de capacidade VIVO** (lê `atlas_loop_proposals.merged_to_main`+`quality._canary` reais gravados pelo `AtlasLoopAutoMergeService` sob escopo governado; engine-result PROIBIDO de setar `merged_to_main` por `AtlasLoopRunPersister`) | line-coverage real (custeado); o caminho de SERVING (dry-run) honestamente não gera sinal de merge real até `completeReal`/B3 (model-bound). **B2 "wire recordOutcome→trend" = NÃO-gap: o trend já lê merges reais; fabricar sinal de dry-run seria Goodhart** |
| 4 | **Poder de consulta** (FATOS, nunca scalar) | 70 | `transitionsFor` + `level_vector` estrutural + comprehend CLI; invariante pétrea "FACTS not scalar" intacta | superfície de consulta que a Part 2 puxa por critério (sem ranquear) |
| 5 | **R1 — fila nunca seca** | 52 | abstain-and-ask honesto (`no_claimable_task`+escalation); `QueueFillSentinel` DETECTOR (A8); **give-backs NÃO drenam mais a fila (ciclo 2: released→reclaimable no pre-sweep+reaper)** | **JOIN/ARMS NÃO wired** (cérebro→fila); cap R1 model-bound honesto; `BrainLeverageSourceProvider` |
| 6 | **R2 — serving nunca falha** | 72 | claim atômico flock (A1) + CAS (A2) + reaper agendado+pre-sweep (A3); `ServingSlaSentinel` (A8); **pull provado sob concorrência real (ciclo 1)**; **lifecycle give-back→reclaim provado multi-cliente (ciclo 2)** | abandono-via-TTL (reaper, MIN_TTL 60s) só em soak; heartbeat de liveness wired ao lease; guard anti-loop de give-back repetido |
| 7 | **Conflict-free PERFEITO** | **70** | `WriteSetOverlap::conflict` central prefix-aware + read-write (A4/A5); CAS claimable→claimed; **PROVADO sob concorrência REAL (ciclo 1): swarm-proof N-cliente, 30 processos, 0.166ms spread, 0 double-claim/overlap, rejeição-de-conflito firando**` | provar o lifecycle completo (report/reclaim) sob contenção; fuzz de lease/TTL |
| 8 | **Qualidade do task-packet** | 72 | envelope auto-suficiente; **inspetor de auto-suficiência (ciclo 3, FATOS): objetivo/allowed_files/aceite/required_evidence/bare-dir; `next` quarentena packet condenado (claimed→blocked) ⇒ cliente frio só recebe task implementável**; **fix: `required_evidence` agora chega ao cliente (era bug de projeção — lia chave errada)** | contexto embutido (snippets do código-alvo); scorer de clareza do objetivo |
| 9 | **Main sempre limpa** | 15 | — | **`completeReal()` (B3) NÃO wired ao serving** (MF-03/04); re-arquitetura do contrato de segurança (obra de semanas, gated OFF) |
| 10 | **Coordenação impecável + contrato `next\|report`** | 56 | A7 `atlas:task next\|report` (o CORAÇÃO) + CLI front-door; `client_id` opaco platform-free; **raio-X de coordenação multi-cliente (ciclo 1)** | **MCP wrapper pendente**; painel de saúde da coordenação contínuo; prova do lifecycle completo |

## GATE bloqueante pendente (arquitetura Parte 6, passo 3)
- **A6 — reconciliação para UMA fila**: NÃO feito. 4 stacks vivas (A orchestrator / B god-class 104k `claimNextPacket` / C `AtlasLoopStore` / D `DeliveryPipeline`). A7 elegeu Stack A como canônica e serve, **mas sem o gate binário que prova "exatamente 1 stack viva + 1 bomba viva"** e **sem o MasterSwitch alcançar o caminho de claim/replenish** (`grep AtlasLoopMasterSwitch SelfConstruction/` = só o wrapper A7 → MF-14: "OFF=byte-identical" é FALSO no claim direto). Extrair `claimNextPacket`/reservation da god-class é pré-requisito (obra própria).

## Caps honestos (model-bound — NÃO fingir destravados)
- **R1**: originação 100%-autônoma de ALTA alavancagem em código maduro = model-bound. Sirvo a fila que EXISTE; abstain honesto é o modo normal.
- **Merge real cross-cliente**: certificação hoje prova NÃO-execução; `completeReal` é gated default-OFF; flip ≠ destrava (re-arquitetura do contrato de segurança).
- **Verifier multi-arquivo**: single-file hoje; multi-file = obra custeada.

## Log de ciclos (o que originei, provado end-to-end)
- _(início)_ Baseline: 15/15 verde (`AtlasTaskServingContractTest`, `…SentinelTest`, `WriteSetOverlap*Test`). Stacks/gap A6 mapeados acima.
- **Ciclo 1 (eixo 7: 50→70)** — `AtlasTaskSwarmProofService` (analisador frozen do raio-X conflict-free) + `atlas:task:swarm-proof` (ferramenta própria: spawna N processos `php artisan` reais contendo no MESMO flock/fila isolada, barreira wall-clock sub-ms). PROVA AO VIVO: 30 processos, spread 0.166ms, 0 double-claim/overlap/breach/phantom, rejeição-de-conflito do lease firando. 18/18 verde. Commit `5d9eb05fd`. A ferramenta vira a JUÍZA de todo ciclo de serving futuro.
- **Ciclo 2 (eixo 6: 60→72, eixo 5: 45→52)** — FURO REAL achado e consertado test-first (RED→GREEN): `report give_back/failed` punha a task em `released`, mas o caminho de serving vivo (pre-sweep do `claimNext` + reaper agendado) só recuperava expired/orphaned, NUNCA released ⇒ **todo give-back drenava a fila pra sempre**. Fix: `recoverReleasedTasks` wired no pre-sweep + no `atlas:acp:reap-leases`. PROVA multi-cliente real: cenário `give-back-reclaim` → 3 dados-de-volta, 3 reclamados, 0 encalhados, Fase B conflict-free. 28/28 verde. Commit `06bbfa8b9`.
- **Ciclo 3 (eixo 8: 55→72)** — GAP REAL: o builder/validator barram objetivo-vazio e bare-dir-only, mas NÃO aceite/evidência ausentes ⇒ packet sem `acceptance_criteria`/`required_evidence` era servido a um cliente frio que não sabe quando terminou nem como provar. `AtlasTaskPacketQualityInspector` (frozen, FATOS: missing_objective/empty_allowed_files/missing_acceptance/missing_required_evidence/bare_directory; scope_incoherent=advisory) + `AgentControlPlaneTaskQueueOrchestrator::quarantineClaimed` (claimed→blocked, nunca re-ofertado) + loop bounded no `next` (claim→inspect→serve-se-OK-senão-quarentena-e-próximo) ⇒ **cliente frio só recebe task implementável**; fila só-condenada = honesto `no_self_sufficient_task`. **BÔNUS: achei+consertei bug de projeção pré-existente** — `projectTask` lia `required_evidence` mas o builder guarda em `evidence_requirements.required` ⇒ o envelope servido SEMPRE teve `required_evidence: []` (cliente nunca recebia o que provar). 40/40 verde (8 frozen do inspetor + 3 serving quarantine + swarm/contrato/give-back/mcp intactos). Também consertei vazamento de config-disk do swarm coordinator (higiene de teste). Commit pendente.

## Próximo alvo (menor maturidade × maior alavancagem)
~~Eixo 3 (B2)~~ — descartado. ~~Eixo 6~~ ✓ ciclo 2. ~~Eixo 8~~ ✓ ciclo 3.

Candidatos: **Eixo 2 (vivacidade, 55)** = invalidação/freshness incremental honesta do read-model (Part 1). **Eixo 10 (coordenação, 56)** = painel de saúde da coordenação (read-only, FATOS: distribuição de status da fila, lease leaks, taxa de serve, recuperações) — o goal nomeia "painel de saúde da coordenação". **Eixo 4 (poder de consulta, 70)**. Próximo ciclo decide pelo piso × alavancagem; rotacionar pra fora do serving-claim (já 3 ciclos nele).
