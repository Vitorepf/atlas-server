---
id: atlas-forge-continuum-os
type: engineering_knowledge
title: Atlas Forge Continuum OS
status: active
category: programming-forge
priority: 101
summary: Doc-mae do sistema continuo de programacao pesada do Atlas: Atlas Code, Obra, Forge Workspace, Atlas Decide, provider topology, fallback governado, execucao one-shot, review, repair, evidence, Rivals e learning.
tags:
  - atlas
  - forge
  - continuum
  - programming
  - atlas-code
  - atlas-decide
  - provider-topology
capabilities:
  - atlas_forge_continuum
  - forge_provider_topology
  - governed_provider_fallback
  - one_shot_enterprise_programming
  - atlas_code_forge_surface
  - programming_continuity
decisions:
  - Atlas Forge Continuum OS e o nome canonico do sistema completo de programacao pesada, continuo e governado.
  - Atlas Code e surface; Forge Continuum e o sistema operacional de programacao por tras dela.
  - Atlas Decide escolhe provider, modelo, papel, autonomia, budget e fallback antes da execucao.
  - O fluxo mais poderoso disponivel deve ser escolhido por padrao quando a tarefa for pesada, enterprise ou de alto risco.
  - Fallback de provider nunca pode ser silencioso; precisa de Decision Receipt, evento, evidencia e UI visivel.
  - Limite, quota, timeout, auth failure ou model unavailable nao devem parar o desenvolvimento quando existir provider capaz disponivel.
  - Se nenhum provider capaz estiver disponivel, o Forge bloqueia honestamente com `provider_capacity_exhausted`.
  - Tempo e custo sao secundarios diante de entrega one-shot enterprise robusta, completa, testada e auditavel.
  - Quando o Forge evoluir o proprio Atlas, Proposal Power Gate, before/after delta, invariant lock e regression sentinel sao obrigatorios.
maintenance:
  - Atualize este doc antes de alterar Atlas Code Forge, Atlas Decide provider routing, fallback, provider topology, Rivals, review, repair ou completion claim.
  - Mantenha este doc como pagina-mae do sistema inteiro; docs filhos detalham runtime, UI, Rivals, work intake, review e execution.
related_paths:
  - docs/engineering-knowledge-base/atlas-programming-forge-flow.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system-contracts.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system-runbook.md
  - docs/engineering-knowledge-base/atlas-forge-provider-topology-and-fallback-v1.md
  - docs/engineering-knowledge-base/atlas-forge-governed-provider-invocation-v1.md
  - docs/engineering-knowledge-base/system-graph/atlas-decide.md
  - docs/engineering-knowledge-base/atlas-code-forge-work-intake-spec-governance-v1.md
  - docs/engineering-knowledge-base/atlas-code-forge-fast-path-v1.md
  - docs/engineering-knowledge-base/atlas-forge-live-execution-e2e-v1.md
  - docs/engineering-knowledge-base/atlas-code-forge-review-completion-gate-v1.md
  - docs/engineering-knowledge-base/atlas-code-forge-operator-cockpit-v1.md
  - docs/engineering-knowledge-base/atlas-code-enterprise-certification.md
  - docs/engineering-knowledge-base/atlas-forge-native-rivals-protocol-v1.md
  - docs/engineering-knowledge-base/atlas-rivals-one-shot-enterprise-evaluation-v1.md
  - docs/engineering-knowledge-base/obras/shared-workspace-and-forge.md
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/atlas-self-improvement-governance-ladder.md
  - docs/engineering-knowledge-base/self-construction/self-programming-safety-contract.md
  - docs/engineering-knowledge-base/self-construction/multi-provider-agent-orchestration-contract.md
  - app/Services/Ai/AiWorker.php
  - app/Services/Ai/Programming/ProgrammingProfessionalCompletionAuditService.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-forge-continuum-os
graph_title: Atlas Forge Continuum OS
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-programming-forge-flow
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-forge-continuum-os.md
allowed_changes:
  - Atualizar fluxo, nomes canonicos, fronteiras, provider topology, fallback e continuidade quando codigo, audit ou evidencia mudarem.
  - Promover novos papeis de provider apenas quando houver policy, receipt, UI/evidence e testes.
forbidden_changes:
  - Chamar Atlas Code de sistema inteiro.
  - Chamar Forge Workspace, Fast Path, Harness ou provider router de sistema inteiro.
  - Permitir fallback silencioso de provider.
  - Declarar Atlas vencedor no Rivals sem bateria real e score comparavel admitido.
  - Declarar completion sem review/evidence quando o contrato exigir humano.
depends_on:
  - atlas-programming-forge-flow
  - atlas-forge-operating-system
  - atlas-decide
  - obras-shared-workspace-and-forge
  - atlas-code-enterprise-certification
  - atlas-forge-native-rivals-protocol-v1
flows_to:
  - atlas-code
  - atlas-decide
  - decision-receipt
  - runtime-executor
  - evidence-ledger
  - rivals-learning
  - atlas-self-improvement-activation-cockpit-v1
unlocks:
  - enterprise-ai-programming
  - autonomous-provider-continuity
  - one-shot-software-construction
governs:
  - atlas-code-scor-1
  - programming.forge
  - forge-provider-topology
  - governed-provider-fallback
  - forge-review-completion
  - rivals-one-shot-enterprise-evaluation
evidence:
  - docs/engineering-knowledge-base/atlas-forge-continuum-os.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:programming:completion-audit --json"
requires_evidence: true
risk_level: critical
visual_tags:
  - forge
  - continuum
  - provider-topology
  - atlas-code
ai_entrypoints:
  - Leia este doc antes de planejar Atlas Code, Forge pesado, multiprovider, fallback, provider topology, Rivals ou programacao one-shot enterprise.
ai_usage_notes:
  - Este doc define o sistema completo e seus contratos. Implementacao executavel vive nos docs filhos e no completion audit.
  - Quando houver conflito, Evidence Ledger, Decision Receipt, Policy, tests e completion audit vencem texto aspiracional.
quality_gates:
  - obra-bound
  - work-intake-complete
  - atlas-decide-receipt-present
  - provider-topology-visible
  - no-silent-provider-fallback
  - fallback-or-capacity-blocker-recorded
  - context-pack-present
  - live-execution-evidence
  - review-completion-gate
  - repair-loop-bounded
  - evidence-ledger-complete
  - rivals-diagnostic-separated-from-claim
failure_modes:
  - UI escolhe modelo sem Atlas Decide.
  - Provider atinge limite e o desenvolvimento quebra em vez de rerotear.
  - Fallback acontece sem receipt, ledger ou visibilidade para operador.
  - Provider menos capaz assume tarefa critica sem policy.
  - Completion claim ignora review humano.
  - Rivals usa score sintetico como claim real.
observability_signals:
  - obra_id
  - fast_path_run_id
  - decision_receipt_id
  - provider_topology_id
  - provider_role_assignments
  - fallback_events
  - provider_capacity_status
  - evidence_refs
  - ledger_event_ids
  - completion_claim_status
  - rivals_evaluation_status
next_actions:
  - Implementar `atlas_forge_continuum_certification` no completion audit.
  - Implementar Provider Topology read model, UI e fallback classification caso ainda nao existam como eixo certificado.
---
# Atlas Forge Continuum OS

## Resumo

Atlas Forge Continuum OS e o sistema canonico de programacao pesada do Atlas.
Ele transforma uma intencao de software em uma entrega one-shot enterprise:
completa, robusta, testada, auditavel, revisavel, reparavel e continuamente
melhorada.

Ele nao e uma tela, um executor, um provider router ou um prompt grande. Ele e
o conjunto governado que une:

```text
Atlas Code Surface
-> Obra
-> Forge Workspace
-> Atlas Decide
-> Provider Topology
-> One-Shot Execution
-> Governed Fallback / Continuity
-> Review
-> Repair
-> Completion Claim
-> Evidence Ledger
-> Rivals / Learning
-> proxima execucao melhor
```

## Nome Canonico

Sistema: Atlas Forge Continuum OS. Modulo: `atlas.forge.continuum`. Audit
esperado: `atlas_forge_continuum_certification`. Doc-mae:
`atlas-forge-continuum-os.md`.

## Papel no Atlas

O objetivo do Atlas Code nao e apenas abrir uma tela de programacao. O objetivo
e automatizar ao maximo o melhor fluxo humano atual de construcao de software:

```text
meta clara
-> contexto longo
-> execucao one-shot por provider forte
-> revisao critica por outro perfil/modelo quando util
-> patch/reparo
-> testes e gates
-> evidence
-> proxima meta
```

O Continuum torna esse fluxo nativo do Atlas. O operador nao deve precisar
orquestrar manualmente Claude, Codex, Gemini, tools, testes, review e docs a
cada ciclo. O Atlas deve decidir, executar, continuar e mostrar o que aconteceu.

## Onde Se Encaixa

```text
Programming Domain
└─ programming.forge
   └─ Atlas Forge Continuum OS
      ├─ Atlas Code Surface
      │  └─ Atlas Code SCOR-1: Forge-only
      ├─ Obra System
      │  ├─ work intake
      │  ├─ spec / task contract
      │  ├─ long-context state
      │  ├─ run history
      │  └─ evidence timeline
      ├─ Forge Workspace
      │  ├─ context pack
      │  ├─ worktree / sandbox
      │  ├─ artifact bus
      │  ├─ integration queue
      │  ├─ rollback state
      │  └─ evidence normalization
      ├─ Atlas Decide Provider Topology
      │  ├─ role assignment
      │  ├─ model/provider selection
      │  ├─ capability and risk scoring
      │  ├─ fallback chain
      │  ├─ budget and quota awareness
      │  └─ Decision Receipt
      ├─ Forge Execution Loop
      │  ├─ one-shot implementation
      │  ├─ tests / gates
      │  ├─ review
      │  ├─ repair
      │  └─ completion claim
      ├─ Evidence and Governance
      │  ├─ receipts
      │  ├─ ledger events
      │  ├─ fail-closed blockers
      │  └─ audit blocks
      └─ Rivals / Learning
         ├─ one-shot enterprise evaluation
         ├─ provider comparison
         ├─ weakness detection
         └─ policy improvement
```

## Contratos

| Camada | Responsabilidade | Nao pode fazer |
|---|---|---|
| Atlas Code | Mostrar e operar o Forge | Escolher provider sem Atlas Decide |
| Obra | Ancorar contexto, intencao, estado, historico e evidencia | Ser substituida por chat solto |
| Forge Workspace | Isolar artefatos, worktree, patch, rollback e evidence | Virar o fluxo inteiro |
| Atlas Decide | Escolher estrategia, papeis, modelos, autonomia, custo e fallback | Ser contornado pela UI |
| Provider Topology | Mostrar quem faz o que e qual fallback existe | Ser invisivel ao operador |
| Execution Loop | Implementar, testar, revisar, reparar e concluir | Promover sucesso sem gates |
| Evidence Ledger | Tornar claims replayable/auditaveis | Aceitar claim sem prova |
| Rivals | Medir qualidade real e fragilidades | Inventar score ou vencedor |

## Atlas Decide como Cerebro de Topologia

Forge nao deve fixar "sempre Claude", "sempre Codex" ou "sempre Gemini".
Forge deve pedir ao Atlas Decide uma topologia de execucao.

Exemplo de papeis possiveis:

| Papel | Perfil esperado |
|---|---|
| primary_builder | Provider/modelo mais forte para implementacao one-shot pesada |
| critical_reviewer | Modelo excelente em revisao, testes, contratos e regressao |
| context_scout | Modelo/tooling bom em contexto longo, docs e pesquisa |
| repair_agent | Modelo focado em falhas, patch minimo e retest |
| local_tool_runner | Runtime local para testes, lint, graph, search e evidence |

Atlas Decide deve considerar dificuldade, risco, escopo, contexto, historico de
performance, disponibilidade, quota, rate limit, auth, budget, tool use,
necessidade de revisao, custo de intervencao humana e requisitos de Rivals.

## Continuidade e Fallback Governado

O desenvolvimento nao deve parar so porque um provider atingiu limite, quota,
timeout ou indisponibilidade. O Continuum deve classificar a falha e rerotear
quando houver provider capaz.

| Falha | Acao esperada |
|---|---|
| `rate_limit` | Esperar ou rerotear para fallback capaz |
| `quota_exhausted` | Rerotear se budget/policy permitir |
| `auth_failed` | Bloquear ou rerotear se houver credencial valida |
| `timeout` | Retry governado ou fallback |
| `context_limit` | Compactar/handoff ou usar modelo maior |
| `model_unavailable` | Escolher proximo modelo capaz |
| `provider_error` | Retry/fallback com evidence |
| `insufficient_capability` | Escalar para modelo mais capaz |
| `provider_capacity_exhausted` | Bloquear honestamente quando nao houver alternativa capaz |

Regras duras:

- fallback nunca e silencioso;
- fallback cria evento, receipt e evidence;
- fallback nao pode reduzir qualidade exigida;
- fallback nao pode bypassar review humano;
- provider menos capaz nao assume tarefa critica sem justificativa;
- se todos falham, o estado correto e blocker, nao sucesso sintetico.

## Fluxo

```text
1. Surface
   Atlas Code SCOR-1 recebe intencao do operador.

2. Obra Binding
   Sem Obra, Forge bloqueia fail-closed.

3. Work Intake
   Define objetivo, regra de negocio, acceptance, constraints, allowed/forbidden.

4. Context Pack
   Obra + docs + code intelligence + graph + history montam contexto provider-safe.

5. Atlas Decide
   Decide provider topology, papeis, modelos, fallback, budget e autonomia.

6. Decision Receipt
   Registra estrategia e contrato antes da execucao.

7. One-Shot Execution
   Primary builder executa com task contract e workspace governado.

8. Gates
   Testes, lint, quality, visual/API/security quando aplicavel.

9. Review
   Reviewer/modelo/operador valida escopo, regra de negocio, docs, tests e risk.

10. Repair
   Falhas viram failure packet, repair capsule, patch minimo e retest.

11. Completion Claim
   Completion so avanca com runtime, evidence, diff scope e review exigido.

12. Evidence Ledger
   Receipts, artifacts, ledger ids, hashes e replay path ficam persistidos.

13. Rivals / Learning
   Avalia qualidade one-shot, fragilidades e atualiza politica futura.
```

## Provider Topology Visivel no Atlas Code

Atlas Code deve mostrar a topologia atual, nao apenas "rodando".

Campos esperados:

- `provider_topology_id`;
- `decision_source` (`live_atlas_decide`, `static_policy`, `operator_override_pending`);
- `decision_receipt_id` e `decision_receipt_hash` quando houver receipt real;
- papel (`primary_builder`, `critical_reviewer`, `context_scout`, `repair_agent`);
- provider/modelo escolhido;
- status por papel;
- capacidade estimada;
- motivo da escolha;
- fallback chain;
- budget/quota/rate state quando disponivel;
- eventos de reroute;
- blockers;
- `runtime_dispatch_allowed` e `fallback_child_receipt_required`;
- evidence refs.

O operador precisa conseguir responder quem implementa, quem revisa, qual modelo
esta em uso, por que foi escolhido, qual fallback existe e se o desenvolvimento
continuou ou bloqueou honestamente.

Fronteira runtime: `live_atlas_decide` e a autoridade operacional. `static_policy`
existe apenas como fallback honesto de certificacao/read-model quando ainda nao
ha Decision Receipt. Fallback por `rate_limit`, `quota`, `timeout` ou falha de
provider pode gerar evento, mas qualquer reroute executavel exige child receipt
novo antes de continuar.

## Regras para IA

- Nao escolha provider/modelo por preferencia local; use Atlas Decide.
- Nao faça fallback silencioso; registre receipt, evento e evidence.
- Nao permita runtime dispatch sem Decision Receipt quando a topologia for estatica.
- Nao degrade qualidade para manter velocidade.
- Nao promova completion sem gates, evidence e review quando exigidos.
- Nao trate preflight, dry-run ou score sintetico como claim Rivals real.

## Qualidade One-Shot

Tempo e custo sao sinais secundarios. A metrica primaria e entrega one-shot
enterprise: regra de negocio correta, aderencia a doc canonica, completude,
robustez, testes reais, arquitetura enterprise, governanca Forge, safety,
experiencia do operador, evidence e baixa intervencao humana.

Um run rapido que cria divida, quebra contrato ou exige revisao pesada deve ser
punido por Rivals. Um run mais longo que entrega completo, solido e testado pode
ser melhor.

## Relacao com Self-Construction OS

Self-Construction OS decide como o Atlas evolui a si mesmo. Self-Programming OS
define o contrato de seguranca para o Atlas alterar seu proprio codigo. Forge
Continuum executa a programacao pesada quando o trabalho cai no dominio
`programming.forge`.

```text
Self-Construction OS
-> escolhe e prioriza evolucao do Atlas
-> cria/speca work packets
-> aciona Forge Continuum quando ha implementacao pesada
-> exige evidence, review e learning
```

Forge Continuum nao substitui Self-Construction OS; ele e a fabrica de
execucao de software que Self-Construction pode usar.

## Relacao com Rivals

Rivals mede se o Atlas esta cumprindo o objetivo real: construir software com
qualidade one-shot enterprise e menos intervencao humana, sem autoengano.

Regras:

- Atlas arm em Rivals deve usar Forge;
- dry-run/preflight nao promovem claim;
- score sintetico nao vira vencedor;
- provider externo exige aprovacao de custo;
- Rivals deve separar qualidade, regra de negocio, docs, testes, evidence,
  arquitetura, autonomia e tempo;
- tempo nao compensa baixa qualidade.

## Dependencias

- `atlas-programming-forge-flow.md`;
- `atlas-forge-operating-system.md`;
- `system-graph/atlas-decide.md`;
- `obras/shared-workspace-and-forge.md`;
- `atlas-code-forge-fast-path-v1.md`;
- `atlas-code-forge-review-completion-gate-v1.md`;
- `atlas-code-forge-operator-cockpit-v1.md`;
- `atlas-forge-native-rivals-protocol-v1.md`;
- `atlas-rivals-one-shot-enterprise-evaluation-v1.md`.

## Estados Esperados

| Estado | Significado |
|---|---|
| `idle` | Obra vinculada, sem run ativo |
| `intake_required` | Falta work intake/spec suficiente |
| `deciding` | Atlas Decide montando topologia |
| `running` | Execucao em andamento |
| `rerouting` | Fallback governado em andamento |
| `review_required` | Runtime passou, mas falta review/completion gate |
| `repair_required` | Falha reparavel com failure packet |
| `blocked` | Contrato fail-closed bloqueou |
| `provider_capacity_exhausted` | Nenhum provider capaz disponivel |
| `completed` | Completion claim final permitido |
| `rolled_back` | Rollback governado aplicado |

## Evidencias

Uma execucao Continuum deve produzir Obra id, work intake/spec hash, context
pack hash, Atlas Decide receipt, provider topology, role assignments, fallback
chain, provider failure events, live execution id, patch/diff artifacts,
test/gate results, review packet, completion claim, repair attempts, evidence
refs, ledger event ids e Rivals/learning signals quando aplicavel.

## Riscos

- Provider falha e o desenvolvimento para apesar de haver fallback capaz.
- UI escolhe modelo fora do Decision Receipt.
- Fallback invisivel mascara reducao de qualidade.
- Completion claim ignora review humano.
- Rivals promove score local como comparacao real.

## Exemplos

Exemplo correto: Claude atua como `primary_builder`, Codex como `critical_reviewer`, Gemini como `context_scout`; se Claude bater quota, o Forge reroteia com evidence ou bloqueia com `provider_capacity_exhausted`.

## Escopo de Implementacao

Implementado e certificado em 2026-05-14:

- doc-mae: `atlas-forge-continuum-os.md` (este arquivo);
- Provider Topology + Governed Fallback: `atlas-forge-provider-topology-and-fallback-v1.md`;
- Services: `AtlasForgeProviderTopologyService`, `AtlasForgeProviderFallbackPolicyService`, `AtlasForgeContinuumCertificationService`;
- Command: `php artisan atlas:forge:continuum-certify --json [--strict] [--obra=] [--simulate-provider-failure=]`;
- Endpoint: `GET /atlas-code/works/{project}/forge/provider-topology` + `GET /atlas-code/works/{project}/forge/continuum-certification`;
- State projection: `AtlasCodeWorkController::state()` expoe `forge_provider_topology` + `forge_continuum_certification`;
- Cockpit UI: `apps/desktop/src/surfaces/code/panels/ForgeProviderTopologyPanel.tsx` registrada como tab "Topology" no Right Rail;
- Audit block: `atlas_forge_continuum_certification` em `ProgrammingProfessionalCompletionAuditService`.
- Provider Capacity & Continuity: `atlas-forge-provider-capacity-continuity-v1.md` (sinais locais, failure memory, cooldown — alimenta Atlas Decide / Topology / Cockpit).
- Self-Improvement Governance Ladder: `atlas-self-improvement-governance-ladder.md` (Proposal Power Gate + Before/After Delta + Invariant Lock + Regression Sentinel + Capability Maturity + Trust Ledger + Strategy Portfolio — eixo separado, nunca promove Forge).
- Self-Improvement → Forge Activation v1: `atlas-self-improvement-forge-activation-v1.md` (closed loop proposta aprovada → Obra real, sem auto Fast Path).

Certificacao: `atlas_forge_continuum_certification`
(`atlas.forge_continuum_certification.v1`) audita 25 invariantes canonicas.

## Proximas Acoes
Conectar dispatcher executavel a invocacao provider real apenas com aprovacao explicita do operador, fechar `provider_capacity` com telemetry viva e expandir override governado sem dropdown livre de provider. Cabine humana central: [[atlas-code-obra-command-center-v1]] (lifecycle 8 fases, decision inbox, operational health honesto, schema `atlas.code.obra_command_center.v1`).

Historico:
- 2026-05-14 — entregue Provider Topology read model, Governed Fallback Policy, state projection/UI no Atlas Code, testes de no-silent-fallback e blocker `provider_capacity_exhausted`, mantendo Rivals como juiz honesto.
- 2026-05-14 — entregue Atlas Forge Runtime Dispatcher v1 (governado, read-model): `AtlasForgeRuntimeDispatchService`, comando `atlas:forge:runtime-dispatch`, endpoints `POST/GET /atlas-code/works/{project}/forge/runtime-dispatch`, child Decision Receipt para fallback reroute (schema `atlas.forge.child_decision_receipt.v1`), state projection `forge_runtime_dispatch`, Cockpit UI com CTA `prepare dispatch plan`, certification block expandido para 29 invariants (adicionando `runtime_dispatch_service_available`, `runtime_dispatch_endpoint_registered`, `runtime_dispatch_static_policy_blocked`, `runtime_dispatch_child_receipt_supported`). Dispatcher nunca chama provider externo, nunca promove completion claim, nunca bypassa review.
- 2026-05-14 — entregue Atlas Forge Governed Provider Invocation v1: `AtlasForgeProviderInvocationService` (schema `atlas.forge.provider_invocation.v1`), Driver Router (atlas-local seguro + outros providers retornando `provider_driver_missing`), Prompt Builder (schema `atlas.forge.provider_invocation_prompt.v1`), command `atlas:forge:provider-invoke`, endpoints `POST /forge/provider-invocations` + `GET /forge/provider-invocations/latest`, receipt persistido (schema `atlas.forge.provider_invocation_receipt.v1`), state projection `forge_provider_invocation` + `forge_provider_invocation_receipt`, audit block `atlas_forge_provider_invocation_certification` com 21 invariants. Certification continuum expandida para 37 invariants. Plan-only por padrao; execute exige `confirm_provider_call`+`confirm_budget`+`confirm_runtime_dispatch` + driver runtime configurado. Atlas-local executor seguro deterministico. Doc canonica em `atlas-forge-governed-provider-invocation-v1.md`. 19 testes; 1214+ assertions.
