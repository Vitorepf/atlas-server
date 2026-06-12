# Fable Campaign — Handoff Packet (O-7)

> Gerado durante a execução da campanha (sessão única, 2026-06-12). Este é o artefato de
> consolidação que o O-7 designa: estado exato, provas, e o que resta — escrito para uma
> sessão fria (GPT-5.5 ou Fable-API) operar sem re-derivar nada.

## Resumo executivo

A onda-1 buildável foi **concluída e capturada** (O-1→O-6, O-8, O-9). Padrão observado:
algumas obras (O-4, O-5, O-8) já estavam construídas por sessões anteriores — verificadas
e congeladas, não reconstruídas (a memória estava stale; corrigida). As demais (O-1, O-2,
O-3, O-6, O-9) receberam build novo + teste congelado + captura.

A onda-2 (O-10→O-14) tem **infraestrutura pré-construída substancial** mas é, por decisão
do operador no plano, **gated atrás de API paga pós-dia-22 + avaliação do soak**. Não foi
ligada autonomamente (governança do próprio plano + várias exigem runtime pago/soak real
que não existe nesta sessão). Mapa abaixo.

## Onda-1 — entregue (8 obras)

| Obra | Estado | Prova (teste congelado) |
|---|---|---|
| O-1 Sweep + Marco Zero | concluída | 10/13 achados corrigidos; ledger `01KTWCPTNZQDQ2NGMD8G8NP83K`; 243 verdes |
| O-2 Loop decente (4 slices) | concluída | enforce default, evidência-discovery, NumericSafetyGuard, universal_certification flag |
| O-3 Travessia merge-livre | concluída | contrato de acceptance persistido + reprove funcional + materializer limpa atlas.patch |
| O-4 Cérebro semântico | verificada (já live) | `AtlasSemanticRecallAntiFakeContractTest` (kitten→feline 0.82≫finance 0.013) |
| O-5 Medição honesta | verificada + reforçada | scorecard 7.86/10 resolved-evidence; `code` dimension = class_exists 1:1 |
| O-6 S50 (escolha do operador) | keystone | invariante single-source `WorkspaceMutatingProviders` congelado |
| O-8 Plan-DAG | verificada (já completa) | `AtlasConductorPlanGate` acíclico bounded; 12 verdes |
| O-9 Verification OS | keystone | `VerificationDepthPolicy` (prova ∝ risco + 2 exceções merge-livre) |

## Onda-2 — operator-gated (O-10→O-14): infra pré-construída + o gate exato de cada

| Obra | Infra existente | Gate p/ ligar |
|---|---|---|
| O-10 Loop em frota | `LoopWorkerPool` / `LoopWorkerSpawner` / `LoopWorkerCountPlanner` (parallel pool, gated OFF) | API paga + soak: ligar o pool exige campanhas reais simultâneas 24h — runtime pago + janela de soak. **Depende de O-2+O-3 (✅ prontos).** |
| O-11 Cérebro auto-ajustável | `atlas:aaeos:continuous-self-improvement-loop` + `atlas_context_feedback` (coleta used/noise/missed) + ponte skill-build (`atlas:ai:operator-patterns`) | Ajuste governado dos packs a partir do feedback acumulado precisa de volume de uso real (organic) — não fabricável numa sessão. |
| O-12 ADML auto-routing | `AtlasCognitiveFunctionSwarmRouterService` + conductor per-arm live-outcome feedback (hoje `forced_provider` bootstrapa) | Re-rotear por evidência muda o meta-provider vivo (custo real); decisão `[OPERADOR]` + dados de desempenho por classe. |
| O-13 Forge produção provada | `AtlasForgeLiveExecutionService` + runtime/continuum certification (memória: ainda fixture-core) | Provar fim-a-fim exige uma obra pesada REAL com provider pago + kill/resume ao vivo — runtime pago. Por último de propósito (teste full-stack). |
| O-14 Autonomia de pauta + compounding rate | `atlas:ai:operator-patterns` (propõe missão/skill, AUTONOMY_SUGGEST) + `atlas:aaeos:cognitive-antifragility-equation` (N×M / compounding rate) | Auto-execução acima de SUGGEST = `[OPERADOR]`; publicar a taxa exige o soak da onda-1 rodando (dados reais). |

## Decisões `[OPERADOR]` pendentes

1. **Autorizar API paga pós-dia-22** para a onda-2 (gatilho do plano: "se o Fable for realmente muito bom" — avaliar contra este packet).
2. **Ligar o soak contínuo** (O-2/O-3 prontos): `ATLAS_LOOP_UNIVERSAL_CERTIFICATION=true` + `atlas:loop:campaign` em background → gera os dados reais que O-11/O-14 precisam.
3. **O-13** escolher a obra pesada real para provar o Forge.
4. **O-12/merge-livre**: girar a chave `atlas.ai.loop.merge_to_source_enabled` quando quiser auto-merge (porta+fechadura re-provam de verdade após O-3).

## Como retomar

Qualquer sessão: `Leia e execute docs/fable-campanha-execution-prompt.md`. O ledger em
`docs/fable-campanha-11-dias-nxm.md` é o estado compartilhado. Memórias Atlas: buscar tag
`fable-campanha`.

## Riscos abertos / honestidade

- O-1 deixou **3 achados roteados** para obras (Goodhart juiz mesmo-provider → o slice O-2d
  endereçou via `universal_certification`; php-r/receipt Decide do Forge → ainda abertos,
  candidatos a sweep do O-13).
- O-6 entregou o **invariante**, não a unificação completa das stacks (obra multi-semana).
- Onda-2: os **keystones estruturais foram verificados + congelados** (default-safe, sem
  gastar API / sem flipar routing vivo / sem alargar autonomia): O-10 (4v), O-11 (6v),
  O-12 (23v), O-13 durabilidade (30v), O-14 (18v). O **único residual** é a prova-LIVE-a-escala
  (soak 24h da frota, obra pesada paga do Forge) — esta sim gated por API paga + janela de soak.
- Distinção que aprendi a fazer: **keystone estrutural default-safe** (entregável agora, congelado)
  ≠ **prova-live a escala** (operator-gated). Os 14 itens têm o keystone; o que resta é só
  ligar o runtime pago para a prova viva de O-10/O-13.
