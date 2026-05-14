---
id: atlas-forge-provider-topology-and-fallback-v1
type: engineering_knowledge
title: Atlas Forge Provider Topology e Governed Fallback v1
status: active
category: programming-forge
priority: 100
summary: Read-model canonico de Provider Topology + policy de governed fallback do Atlas Forge Continuum OS. Define papeis, fallback chain, classificador de falhas, blocker honesto `provider_capacity_exhausted` e regras anti silencioso.
tags:
  - atlas
  - forge
  - continuum
  - provider-topology
  - governed-fallback
  - atlas-decide
capabilities:
  - forge_provider_topology
  - governed_provider_fallback
  - provider_failure_classifier
  - no_silent_fallback
decisions:
  - Provider Topology e read-model canonico; Atlas Decide e quem materializa runtime.
  - Forge nao escolhe provider por preferencia local; segue Decision Receipt.
  - Fallback nunca silencioso; sempre gera evento + receipt + evidencia.
  - Fallback nao reduz quality gates; nao bypassa review/completion gate; nao auto-completa.
  - `provider_capacity_exhausted` e blocker honesto quando nao houver provider capaz.
  - Manual override de provider/model e futuro; sem backend governado, UI exibe disabled.
maintenance:
  - Atualize este doc antes de alterar AtlasForgeProviderTopologyService, AtlasForgeProviderFallbackPolicyService, AtlasForgeContinuumCertificationService ou os contratos de Atlas Decide referentes a Forge.
  - Mantenha sincronizado com `atlas-forge-continuum-os.md`.
related_paths:
  - docs/engineering-knowledge-base/atlas-forge-continuum-os.md
  - docs/engineering-knowledge-base/atlas-programming-forge-flow.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
  - docs/engineering-knowledge-base/system-graph/atlas-decide.md
  - docs/engineering-knowledge-base/atlas-code-forge-operator-cockpit-v1.md
  - docs/engineering-knowledge-base/atlas-code-forge-fast-path-v1.md
  - docs/engineering-knowledge-base/atlas-code-forge-review-completion-gate-v1.md
  - docs/engineering-knowledge-base/domains/programming-professional-completion-audit.md
  - app/Services/Ai/Programming/AtlasForgeProviderTopologyService.php
  - app/Services/Ai/Programming/AtlasForgeProviderFallbackPolicyService.php
  - app/Services/Ai/Programming/AtlasForgeContinuumCertificationService.php
  - app/Console/Commands/AtlasForgeContinuumCertifyCommand.php
  - app/Http/Controllers/AtlasCodeForgeProviderTopologyController.php
  - apps/desktop/src/surfaces/code/panels/ForgeProviderTopologyPanel.tsx
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-forge-provider-topology-and-fallback-v1
graph_title: Atlas Forge Provider Topology and Governed Fallback v1
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-forge-continuum-os
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-forge-provider-topology-and-fallback-v1.md
  - app/Services/Ai/Programming/AtlasForgeProviderTopologyService.php
  - app/Services/Ai/Programming/AtlasForgeProviderFallbackPolicyService.php
  - app/Services/Ai/Programming/AtlasForgeContinuumCertificationService.php
  - app/Console/Commands/AtlasForgeContinuumCertifyCommand.php
  - app/Http/Controllers/AtlasCodeForgeProviderTopologyController.php
  - tests/Feature/Ai/Programming/AtlasForgeContinuumCertificationTest.php
  - tests/Feature/Ai/Programming/AtlasForgeProviderTopologyTest.php
evidence:
  - docs/engineering-knowledge-base/atlas-forge-provider-topology-and-fallback-v1.md
  - app/Services/Ai/Programming/AtlasForgeProviderTopologyService.php
  - app/Services/Ai/Programming/AtlasForgeProviderFallbackPolicyService.php
  - app/Services/Ai/Programming/AtlasForgeContinuumCertificationService.php
  - tests/Feature/Ai/Programming/AtlasForgeContinuumCertificationTest.php
  - tests/Feature/Ai/Programming/AtlasForgeProviderTopologyTest.php
allowed_changes:
  - Adicionar papeis, providers, modelos e falhas governadas quando policy/receipt/UI/tests acompanharem.
  - Atualizar invariants ao adicionar novos blockers honestos.
forbidden_changes:
  - Permitir fallback silencioso de provider.
  - Reduzir quality gates como compensacao para fallback.
  - Promover completion claim ou bypassar review humano em consequencia de fallback.
  - Materializar topologia runtime fora de Atlas Decide.
depends_on:
  - atlas-forge-continuum-os
  - atlas-decide
  - atlas-programming-forge-flow
flows_to:
  - atlas-code-forge-operator-cockpit-v1
  - programming-professional-completion-audit
unlocks:
  - autonomous-provider-continuity
  - one-shot-software-construction
governs:
  - forge-provider-topology
  - governed-provider-fallback
  - forge-review-completion
required_tests:
  - "php artisan test --filter='AtlasForgeContinuumCertificationTest|AtlasForgeProviderTopologyTest'"
  - "php artisan atlas:forge:continuum-certify --json --strict --obra=<uuid>"
requires_evidence: true
risk_level: critical
visual_tags:
  - forge
  - provider-topology
  - governed-fallback
ai_entrypoints:
  - Leia este doc antes de tocar provider topology, fallback de provider, policy de capacidade, classificador de falha ou UI cockpit de provider/topologia.
ai_usage_notes:
  - Provider Topology e read-model; Atlas Decide e fonte runtime.
  - Fallback nunca silencioso; sempre receipt + evento + evidencia.
quality_gates:
  - obra-bound
  - atlas-decide-receipt-present
  - provider-topology-visible
  - no-silent-provider-fallback
  - fallback-or-capacity-blocker-recorded
  - review-completion-gate
  - evidence-ledger-complete
failure_modes:
  - Forge usa preferencia local em vez de Atlas Decide.
  - Fallback acontece sem receipt nem evidencia.
  - Fallback rebaixa quality gates.
  - Provider menos capaz assume tarefa critica sem motivo.
  - Sucesso sintetico mesmo sem provider capaz disponivel.
observability_signals:
  - obra_id
  - provider_topology_id
  - decision_receipt_id
  - provider_role_assignments
  - fallback_events
  - provider_capacity_status
  - evidence_refs
next_actions:
  - Quando Atlas Decide ganhar runtime real de provider topology, sincronizar o read-model com dispatch concreto.
  - Quando bateria provider real for habilitada, vincular `provider_capacity` ao monitoramento ativo.
---
# Atlas Forge Provider Topology e Governed Fallback v1

## Resumo

Este eixo define como o Atlas escolhe provider, modelo e papel para cada
ciclo Forge pesado e como reroteia governadamente quando um provider falha,
sem nunca degradar qualidade, bypassar review ou inventar sucesso.

Tudo aqui e read-model canonico. Nao chama provider externo, nao gasta token,
nao cria Obra silenciosa. Atlas Decide e a fonte runtime; este modulo expoe
contrato, defaults, classificador de falha e simulacao auditavel.

## Papel no Atlas

O Forge Continuum exige que o operador veja, antes de qualquer disparo:

- qual provider/modelo e o `primary_builder`;
- qual e o `critical_reviewer`;
- qual e o `context_scout`;
- qual e o `repair_agent`;
- qual e o `local_tool_runner`;
- qual e a `fallback_chain` por ordem capaz;
- qual a `provider_capacity` declarada por provider;
- qual o ultimo evento de fallback governado;
- se algum blocker honesto esta ativo, com destaque para
  `provider_capacity_exhausted`.

## Onde Se Encaixa

```text
Atlas Code Surface (SCOR-1)
└─ Atlas Forge Continuum OS
   ├─ Atlas Decide
   │  └─ Decision Receipt -> Provider Topology
   ├─ Forge Workspace -> Forge Execution
   ├─ Provider Topology (this doc)
   │  ├─ primary_builder
   │  ├─ critical_reviewer
   │  ├─ context_scout
   │  ├─ repair_agent
   │  ├─ local_tool_runner
   │  ├─ fallback_chain
   │  └─ provider_capacity
   ├─ Governed Fallback Policy (this doc)
   │  ├─ failure classifier
   │  ├─ reroute / retry_later / block
   │  ├─ no silent fallback
   │  └─ evidence event payload
   ├─ State Projection (AtlasCodeWorkController)
   ├─ Desktop Cockpit UI (ForgeProviderTopologyPanel)
   └─ Continuum Certification audit block
```

## Contratos

| Schema | Producer | Consumer |
|---|---|---|
| `atlas.forge.provider_topology.v1` | `AtlasForgeProviderTopologyService` | Cockpit, state projection, certification |
| `atlas.forge.provider_fallback_policy.v1` | `AtlasForgeProviderFallbackPolicyService` | Certification, audit block |
| `atlas.forge.provider_fallback_event.v1` | Fallback policy classifier | Cockpit, evidence ledger, audit |
| `atlas.forge_continuum_certification.v1` | `AtlasForgeContinuumCertificationService` | Completion audit, command CLI |

### Papeis canonicos

| Papel | Perfil |
|---|---|
| `primary_builder` | Implementacao one-shot pesada |
| `critical_reviewer` | Revisao critica, testes, contratos |
| `context_scout` | Long context, RAG, docs, code intelligence |
| `repair_agent` | Failure packets, patch minimo, retest |
| `local_tool_runner` | Lint, test, graph e evidence locais |

### Falhas reconhecidas

`rate_limit`, `quota_exhausted`, `auth_failed`, `timeout`, `context_limit`,
`model_unavailable`, `provider_error`, `insufficient_capability`,
`provider_capacity_exhausted`.

### Acoes possiveis

`reroute`, `retry_later`, `block`.

## Fluxo

```text
1. Atlas Decide gera Decision Receipt e Provider Topology declarativa.
2. Provider Topology Service prefere `live_atlas_decide`; usa `static_policy`
   apenas quando nao existe receipt real.
3. Forge Execution dispatcha papeis somente com topologia + receipt valido.
4. Se provider falha durante o run, a policy classifica a falha:
   - reroute       -> escolhe fallback capaz (evento + evidence).
   - retry_later   -> mesmo papel retorna apos backoff governado.
   - block         -> emite blocker honesto (`provider_capacity_exhausted`).
5. Reroute executavel exige child Decision Receipt; ate la,
   `fallback_child_receipt_required=true` e `runtime_dispatch_allowed=false`.
6. Atlas Code Cockpit mostra status, evento e blocker em tempo real.
7. Continuum Certification audita doc-mae + invariantes + UI.
```

## Regras para IA

- Nunca escolha provider/modelo por preferencia local.
- Nunca trate fallback como sucesso silencioso.
- Nunca reduza quality gates como compensacao.
- Nunca promova completion claim em consequencia de reroute.
- Nunca bypasse review humano apos fallback.
- Nunca use `static_policy` como autoridade runtime; ela e read-model de
  certificacao sem provider dispatch.
- Quando nao houver provider capaz, bloqueie com `provider_capacity_exhausted` e mostre o blocker.

## Escopo de Implementacao

Backend (Laravel):

- `AtlasForgeProviderTopologyService` — emite `atlas.forge.provider_topology.v1`.
- `AtlasForgeProviderFallbackPolicyService` — classifica falhas, decide acao, emite evidence event.
- `AtlasForgeContinuumCertificationService` — audita 25 invariantes canonicas.
- `AtlasForgeContinuumCertifyCommand` — `php artisan atlas:forge:continuum-certify --json [--strict] [--obra=] [--simulate-provider-failure=]`.
- `AtlasCodeForgeProviderTopologyController` — `GET /atlas-code/works/{project}/forge/provider-topology` + `/continuum-certification`.
- `AtlasCodeWorkController::state()` expoe `forge_provider_topology` e `forge_continuum_certification` (slim summary).
- `ProgrammingProfessionalCompletionAuditService` ganha bloco `atlas_forge_continuum_certification`.

Desktop:

- `packages/atlas-domain/src/index.ts` — `AtlasForgeProviderTopology`, `AtlasForgeProviderRole`, `AtlasForgeProviderFallbackEntry`, `AtlasForgeProviderFallbackEvent`, `AtlasForgeContinuumCertificationSummary`, novo campo no `WorkStateSnapshot`.
- `apps/desktop/src/lib/bridge.ts` — `getForgeProviderTopology` + adapters + integracao em `adaptWorkState`.
- `apps/desktop/src/hooks/useBridge.ts` — state + action `refreshForgeProviderTopology`.
- `apps/desktop/src/surfaces/code/panels/ForgeProviderTopologyPanel.tsx` — painel denso e operacional, sem hero, sem card-dentro-de-card, sem dropdown livre de provider.

## Dependencias

- `atlas-forge-continuum-os.md` (doc-mae)
- `system-graph/atlas-decide.md`
- `atlas-programming-forge-flow.md`
- `atlas-code-forge-operator-cockpit-v1.md`
- `atlas-code-forge-fast-path-v1.md`
- `atlas-code-forge-review-completion-gate-v1.md`
- `domains/programming-professional-completion-audit.md`

## Evidencias

- `tests/Feature/Ai/Programming/AtlasForgeContinuumCertificationTest.php`
- `tests/Feature/Ai/Programming/AtlasForgeProviderTopologyTest.php`
- `php artisan atlas:forge:continuum-certify --json --strict --obra=<uuid>`
- `GET /atlas-code/works/{project}/forge/provider-topology`
- `GET /atlas-code/works/{project}/state` → `forge_provider_topology` + `forge_continuum_certification`

## Riscos

- Sincronizacao runtime: Atlas Decide ja pode projetar `forge_provider_topology`
  no Decision Receipt; static policy continua como fallback honesto quando nao
  ha receipt real.
- Bateria de capacidade real: enquanto nao houver telemetry, `provider_capacity` ficara como `unknown` exceto para `atlas-local`.
- Cockpit não pode oferecer dropdown manual de provider antes de existir override governado.

## Exemplos

### Exemplo 1: fallback `rate_limit` no primary

`primary_builder=claude_cli/claude-opus-4-7` bate `rate_limit`. Policy:

- action `reroute`;
- selected_fallback_role `critical_reviewer`;
- evento `atlas.forge.provider_fallback_event.v1` registrado;
- Cockpit mostra evento + provider escolhido;
- Continuum status: `rerouted`.

### Exemplo 2: capacity exhausted

Nenhum provider capaz disponivel. Policy:

- action `block`;
- blocker `provider_capacity_exhausted`;
- evento `atlas.forge.provider_fallback_event.v1` com `action=block`;
- Cockpit mostra banner vermelho + nenhum reroute oculto;
- Continuum status: `blocked`.

## Runtime Dispatcher (v1, governado, read-model)

Entregue em 2026-05-14 como camada runtime sobre a Provider Topology, ainda
sem chamada provider externa real:

- Service: `AtlasForgeRuntimeDispatchService` emite schema `atlas.forge.runtime_dispatch_plan.v1`.
- CLI: `php artisan atlas:forge:runtime-dispatch --obra= --role= --json [--strict] [--simulate-provider-failure=] [--create-child-receipt]`.
- Endpoints: `POST /atlas-code/works/{project}/forge/runtime-dispatch` + `GET ...` (slim read-model).
- State projection: `forge_runtime_dispatch` em `AtlasCodeWorkController::state()`.
- Persistencia em Obra: `latest_atlas_forge_runtime_dispatch` + `atlas_forge_runtime_dispatch_history` cap 25.
- Cockpit UI: secao "Runtime dispatch" no `ForgeProviderTopologyPanel` com CTA `prepare dispatch plan` + checkbox `create child receipt`.

Regras duras runtime:

- `static_policy` topologies NUNCA podem rodar runtime dispatch.
- dispatch exige `live_atlas_decide` + `decision_receipt_id` + `decision_receipt_hash` + `runtime_dispatch_allowed=true`.
- role precisa existir na topologia com provider/model populados.
- `provider_capacity_exhausted` e blocker terminal.
- reroute por failure exige child Decision Receipt antes do dispatch continuar.
- dispatcher NUNCA chama provider externo, NUNCA promove completion claim, NUNCA bypassa review/completion gate.

Child Decision Receipt:

- gerado via `AtlasDecideService::operationalDecision()` com payload `child_receipt_reason=provider_fallback_reroute`.
- carrega `parent_decision_receipt_id`, `parent_decision_receipt_hash`, `parent_provider_topology_id`, `fallback_event_id`, `fallback_failure_type`, `requested_role/provider/model`.
- persistido em `latest_atlas_forge_child_decision_receipt` + `atlas_forge_child_decision_receipt_history` cap 25.
- nao apaga evidencia do parent.

## Governed Provider Invocation v1 (2026-05-14)

A camada de invocacao governada foi entregue como modulo separado, doc canonica
`atlas-forge-governed-provider-invocation-v1.md`. Schema principal
`atlas.forge.provider_invocation.v1`; receipt `atlas.forge.provider_invocation_receipt.v1`.
Plan-only por padrao; execute exige `confirm_provider_call` + `confirm_budget`
(externos) + `confirm_runtime_dispatch` + driver runtime configurado. Atlas-local
e o unico driver runtime atualmente disponivel; outros providers retornam
`provider_driver_missing` honestamente. Output e capturado com `stdout_hash`/
`stderr_hash`. Completion claim nunca e promovido.

## Proximas Acoes

- Conectar dispatcher executavel a invocacao provider real apenas com aprovacao explicita do operador.
- Adicionar telemetry real para `provider_capacity` (rate_limit, quota_state).
- Evoluir override manual governado sem dropdown livre de provider.

> Sinais locais de capacidade + failure memory (cooldown, status_before/after,
> snapshot_id) vivem em `atlas-forge-provider-capacity-continuity-v1.md` e
> alimentam este eixo automaticamente — nenhum probe ativo a provider externo.
