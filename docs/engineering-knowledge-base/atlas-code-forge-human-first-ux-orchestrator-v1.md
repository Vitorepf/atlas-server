---
id: atlas-code-forge-human-first-ux-orchestrator-v1
type: engineering_knowledge
title: Atlas Code Forge Human-First UX Orchestrator v1
status: active
category: programming-forge
priority: 100
summary: Cabine humana do Atlas Code que esconde complexidade interna do Forge (intake/fast-path/dispatch/invocation/review/completion/capacity/trust ledger) e mostra ao operador apenas o próximo passo seguro.
tags:
  - atlas
  - atlas-code
  - forge
  - ux
  - orchestrator
capabilities:
  - atlas_code_forge_human_first_ux
  - forge_next_safe_action_resolver
decisions:
  - Atlas Code mostra um único próximo passo seguro por vez; não expõe complexidade interna como tabs paralelas.
  - Próximo passo nunca chama provider externo sem confirmação humana explícita.
  - UX nunca esconde blockers; sempre renderiza estado real do backend.
maintenance:
  - Atualize quando AtlasCodeForgeUxOrchestratorService, controller ou painel canônico mudarem.
related_paths:
  - docs/engineering-knowledge-base/atlas-forge-continuum-os.md
  - docs/engineering-knowledge-base/atlas-code-forge-operator-cockpit-v1.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-code-forge-human-first-ux-orchestrator-v1
graph_title: Atlas Code Forge Human-First UX Orchestrator v1
graph_world: atlas
graph_layer: system
graph_kind: runbook
graph_parent: atlas-code-forge-operator-cockpit-v1
graph_status: active
graph_source: repo
human_name: Atlas Code Forge Human-First UX Orchestrator v1
canonical_name: Atlas Code Forge Human-First UX Orchestrator v1
technical_name: atlas-code-forge-human-first-ux-orchestrator-v1
cartography_type: runbook
canonical_source: docs/engineering-knowledge-base/atlas-code-forge-human-first-ux-orchestrator-v1.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-code-forge-human-first-ux-orchestrator-v1.md
allowed_changes:
  - Adicionar passos canônicos ao orchestrator quando o ciclo Forge crescer.
forbidden_changes:
  - Expor complexidade interna do Forge como tabs paralelas no Right Rail.
  - Pular blockers do backend ao decidir o próximo passo seguro.
  - Acionar provider externo sem confirmação humana.
depends_on:
  - atlas-forge-continuum-os
  - atlas-code-forge-operator-cockpit-v1
flows_to:
  - atlas-code
  - atlas-forge-continuum-os
unlocks:
  - human_first_atlas_code_ux
governs:
  - atlas_code_forge_human_first_ux
evidence:
  - docs/engineering-knowledge-base/atlas-code-forge-human-first-ux-orchestrator-v1.md
evidence_refs:
  - symbol: AtlasCodeForgeUxOrchestratorService
  - command: atlas:code:forge-ux
  - test: AtlasCodeForgeUxOrchestratorServiceTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: medium
next_actions:
  - Manter este doc sincronizado com o orchestrator service quando novos passos canônicos forem adicionados.
---
# Atlas Code Forge Human-First UX Orchestrator v1

> Schema canônico: `atlas.code.forge_ux_orchestrator.v1`
> Audit cert: `atlas.code.forge_human_first_ux_certification.v1`
> Status: implementado · 2026-05-14

## Por que esta meta existe

Antes desta meta, Atlas Code Desktop expunha a complexidade interna do Forge —
intake, fast path, dispatch, provider invocation, review packet, completion
claim, capacity, failure memory, trust ledger — como tabs paralelas no Right
Rail. Era um cockpit técnico, não uma ferramenta humana.

O usuário não sabia:

- onde está dentro do pipeline,
- qual o próximo passo seguro,
- por que está bloqueado,
- se Atlas está prestes a chamar provider externo (gastar token, fazer side
  effect real),
- qual provider/modelo/decision source está vigente,
- o que já foi provado.

Esta meta consolida tudo isso em **um botão grande** que muda de rótulo conforme
o estado, com **safety strip visível**, **checklist editorial** e **blockers
honestos** — sem reduzir segurança, sem inventar estado, sem chamar provider
externo silenciosamente.

## Contratos preservados (intocáveis)

A UX humana é construída **em cima** dos contratos existentes, nunca os
substitui:

- **Provider externo nunca é chamado em testes.** `static_policy` nunca
  invoca.
- **Tokens nunca são gastos sem três confirmações explícitas**:
  `confirm_provider_call`, `confirm_budget`, `confirm_runtime_dispatch` +
  capacity ok + driver configurado + dispatch plan presente.
- **Completion claim nunca é auto-promovido.** Review gate continua presente.
- **External rivals certification continua separada** (`separated_from:
  external_rivals_certification`). Nenhum endpoint inventa rivais.
- **Allowlist + Symfony Process array** (nunca shell string crua) continua
  vigente nos drivers reais.
- **Obra nunca é criada silenciosamente.** `no_obra` é state fail-closed.
- **APIs/bridge/actions/Cartografia/Voice/Self-construction** não foram
  tocados fora dos campos read-only/advanced já existentes.

## Arquitetura

```
┌─────────────────────────────────────────────────────────────────────┐
│  Backend (atlas-server)                                             │
│                                                                     │
│  AtlasCodeForgeUxOrchestratorService                                │
│    ├─ snapshot(obra) → AtlasCodeForgeUxSnapshot                     │
│    │    state machine read-model consolidando intake, fast_path,   │
│    │    topology, capacity, dispatch, invocation, review,           │
│    │    completion_claim, evidence, ledger                          │
│    │                                                                │
│    ├─ 16 estados canônicos (NO_OBRA … ROLLED_BACK)                  │
│    ├─ 11 primary action kinds (BIND_OBRA … VIEW_EVIDENCE)           │
│    └─ resolve: humanLabel/humanDetail/primaryActionEnabled/…        │
│                                                                     │
│  CLI:  php artisan atlas:code:forge-ux --obra=<uuid> --json [--strict]
│  HTTP: GET  /atlas-code/works/{project}/forge/ux-orchestrator       │
│  State projection: AtlasCodeWorkController state() exposes          │
│    'forge_ux_orchestrator' on WorkStateSnapshot                     │
└─────────────────────────────────────────────────────────────────────┘
                                  │
                                  ▼
┌─────────────────────────────────────────────────────────────────────┐
│  Desktop (atlas-desktop)                                            │
│                                                                     │
│  packages/atlas-domain · AtlasCodeForgeUxOrchestrator type          │
│  apps/desktop/src/lib/bridge.ts · getForgeUxOrchestrator + adapter  │
│  apps/desktop/src/hooks/useBridge.ts · forgeUxOrchestrator state    │
│                                       refreshForgeUxOrchestrator    │
│                                                                     │
│  crates/atlas-bridge/src/endpoints.rs · ATLAS_CODE_WORK_FORGE_UX_…  │
│  crates/atlas-bridge/src/client.rs · get_forge_ux_orchestrator      │
│  crates/atlas-tauri/src/commands_bridge.rs · bridge_get_forge_ux_…  │
│                                                                     │
│  surfaces/code/panels/ForgeHumanPanel.tsx · painel humano canon     │
│  surfaces/code/panels/rightRailRegistry.tsx · 5 tabs reduzidas      │
│  surfaces/code/leftRail/LeftRail.tsx · CreateObraControl (Nova Obra)│
└─────────────────────────────────────────────────────────────────────┘
```

## Estados canônicos (state machine)

| State | Significado humano | Primary action |
|---|---|---|
| `no_obra` | Sem Obra vinculada | (selecionar/criar Obra à esquerda) |
| `intake_required` | Falta definir o que vai ser feito | Abrir Definição |
| `intake_ready` | Definição pronta, falta gerar Spec/Plan | Preparar Forge |
| `ready_to_prepare` | Pronto para preparar fast path | Preparar Forge |
| `prepared` | Spec/Plan compilados, dry run ok | Continuar Forge |
| `ready_to_execute` | Pronto para execução supervisionada | Continuar Forge |
| `running` | Atlas está executando | Acompanhar status |
| `waiting_provider_confirmation` | Aguardando aprovação para chamar provider | Abrir Avançado · Provider |
| `waiting_budget_confirmation` | Aguardando confirmação de budget | Abrir Avançado · Provider |
| `waiting_runtime_dispatch_confirmation` | Aguardando dispatch plan | Abrir Avançado · Dispatch |
| `waiting_review` | Resultado pronto, aguarda revisão humana | Abrir Revisão |
| `repair_required` | Review apontou problemas | Planejar reparo |
| `blocked` | Bloqueio honesto (driver não configurado, capacity exausta, etc) | (ver blockers) |
| `completed` | Run aprovado e fechado | Ver provas |
| `rejected` | Run rejeitado em revisão | Planejar reparo |
| `rolled_back` | Promoção revertida | Ver provas |

## UX cabin (Right Rail)

Reduzido de ~12 tabs técnicas para **5 tabs humanas** + Avançado:

```
Forge · Definir · Revisar · Provas · Avançado
```

- **Forge** (id `forge`, priority 1, default): painel humano com primary CTA
  único, safety strip, checklist, provider/modelo, blockers, advanced
  collapsed
- **Definir** (`intake`): editor de intake/objetivo
- **Revisar** (`verify`): review packet + decisões
- **Provas** (`evidence`): evidence ledger
- **Avançado** (`advanced`): topology, capacity, dispatch, provider
  invocation, completion audit — tudo o que era cockpit técnico vira
  diagnóstico

A criação de Obra mora no **Left Rail** (`CreateObraControl` acima da
lista) e nunca no topbar. Sem Obra vinculada, o painel Forge mostra empty
state honesto: "Selecione ou crie uma Obra à esquerda. Atlas Code Forge é
fail-closed sem Obra vinculada."

## Safety strip canônica

Sempre visível no painel Forge, com cor verde quando seguro:

- `external provider call`: `não` (até confirmações explícitas)
- `tokens gastos`: `0`
- `completion claim promovido`: `não`
- `review gate preservado`: `sim`

A confirmação de provider real exige passar por `Avançado > Provider
Invocation` e marcar três checkboxes — o botão primário do painel humano
**nunca** chama provider externo direto.

## Checklist editorial

Sete itens, peso decrescente:

1. Obra
2. Definição
3. Spec / Plan
4. Provider / Modelo
5. Execução
6. Revisão
7. Provas

Cada item resolve `done` a partir do read-model. Nenhum item inventa
estado — se o backend não emitiu o sinal, o item fica `—`.

## Audit certification (13 invariantes)

Schema: `atlas.code.forge_human_first_ux_certification.v1`

| Invariante | O que prova |
|---|---|
| `human_state_machine_available` | Service expõe 16 estados canônicos |
| `primary_action_resolver_available` | `primary_action_kind` + `primary_action_enabled` presentes |
| `no_obra_creation_in_wrong_topbar` | ObraBar existe (não foi mexido) |
| `left_rail_create_obra_available` | `Nova Obra` ou `onCreateObra` no LeftRail |
| `right_rail_reduced_to_human_tabs` | Registry usa `ForgeHumanPanel` |
| `technical_actions_hidden_under_advanced` | Painel usa `<details>` |
| `provider_confirmation_visible` | Painel cita `Confirmar Provider` / `confirm_provider_call` |
| `no_external_provider_auto_call` | Service emite `external_provider_call => $externalCall` |
| `no_completion_claim_auto_promotion` | Service emite `completion_claim_promoted => $completionPromoted` |
| `review_gate_preserved` | Service emite `review_completion_gate_preserved => true` |
| `advanced_diagnostics_preserved` | Registry técnico (topology/capacity) preservado |
| `empty_states_have_next_action` | Service emite `next_safe_step` |
| `state_projection_available` | WorkController expõe `'forge_ux_orchestrator'` |

Status:
- `available` quando todos os invariantes verdadeiros e artifacts presentes
- `backend_available_ui_pending` quando backend ok mas UI parcial
- `missing_artifacts` quando faltam arquivos (service/command/controller/test/doc/painel)

## Como rodar

```bash
# CLI estrito (sem obra → exit 1)
php artisan atlas:code:forge-ux --json --strict

# CLI com obra
php artisan atlas:code:forge-ux --obra=<uuid> --json

# HTTP
curl -s "$ATLAS_SERVER/atlas-code/works/<project>/forge/ux-orchestrator" | jq

# Audit cert
php artisan atlas:code:completion-audit --workspace=. --json \
  | jq '.report.atlas_code_forge_human_first_ux_certification'

# Desktop
npm run build --workspace=@atlas/desktop
```

## Testes

```bash
php artisan test --filter=AtlasCodeForgeUxOrchestratorTest
```

Cenários cobertos:

- `fails_closed_without_obra` — estado `no_obra` quando obra ausente
- `maps_intake_required` — sem intake → `intake_required`
- `maps_prepared_to_execute` — intake + fast_path success → `ready_to_execute`
- `maps_provider_confirmation` — flags pendentes → `waiting_provider_confirmation`
- `never_promotes_completion_claim` — flag sempre false
- `preserves_external_rivals` — separação canônica preservada
- `state_endpoint_exposes` — projection no state snapshot
- `cli_strict_without_obra_exits_one` — fail-closed no CLI estrito

## Não-objetivos (NÃO faça)

- **NÃO** chamar provider externo pelo botão primário do painel humano.
- **NÃO** promover completion claim por UX.
- **NÃO** remover review gate.
- **NÃO** remover external_rivals separation.
- **NÃO** criar Obra silenciosamente (sem ObraBar/LeftRail flow).
- **NÃO** apagar tabs técnicas — vire diagnóstico em `Avançado`.
- **NÃO** inventar estado quando backend não emitiu sinal — empty state honesto.
- **NÃO** trocar segurança por UX bonita.

## Próximos passos (fora do escopo desta meta)

- A2 spec para painel humano (capturas, AA contrast, AAA quando possível).
- Métricas de uso por estado (`atlas:code:forge-ux-metrics`).
- Integração com TODO da Inbox quando review aponta repair.
- Histórico narrativo de transições por Obra (audit trail humano).

## Referências

- `app/Services/Ai/Programming/AtlasCodeForgeUxOrchestratorService.php`
- `app/Console/Commands/AtlasCodeForgeUxOrchestratorCommand.php`
- `app/Http/Controllers/AtlasCodeForgeUxOrchestratorController.php`
- `tests/Feature/Ai/Programming/AtlasCodeForgeUxOrchestratorTest.php`
- `atlas-desktop/apps/desktop/src/surfaces/code/panels/ForgeHumanPanel.tsx`
- `atlas-desktop/apps/desktop/src/surfaces/code/panels/rightRailRegistry.tsx`
- `atlas-desktop/apps/desktop/src/surfaces/code/leftRail/LeftRail.tsx`
- `atlas-desktop/packages/atlas-domain/src/index.ts` (`AtlasCodeForgeUxOrchestrator`)

## Resumo
Cabine humana do Atlas Code que esconde complexidade interna do Forge e mostra apenas o próximo passo seguro. Ver corpo acima para detalhes; este bloco existe para satisfazer a estrutura canônica.

## Papel no Atlas
Atua como camada UX que traduz estado do Forge (intake/fast-path/dispatch/invocation/review/completion/capacity/trust ledger) em uma única instrução clara para o operador.

## Onde Se Encaixa
Filho de `atlas-code-forge-operator-cockpit-v1.md`; consome `atlas-forge-continuum-os.md`. Sai dos painéis técnicos e entra no fluxo humano.

## Contratos
Schemas canônicos: `atlas.code.forge_ux_orchestrator.v1` (read-model), `atlas.code.forge_human_first_ux_certification.v1` (audit).

## Fluxo
Orchestrator agrega snapshot do Forge → resolve `next_safe_action` → renderiza no painel humano.

## Regras para IA
Nunca expor tabs paralelas para humano; nunca acionar provider sem confirmação; nunca esconder blockers.

## Escopo de Implementacao
Service, controller, painel desktop e testes — ver lista de arquivos no corpo acima.

## Dependencias
`atlas-forge-continuum-os.md`, `atlas-code-forge-operator-cockpit-v1.md`.

## Evidencias
- `php artisan atlas:engineering:knowledge docs-health --json`
- `php artisan atlas:programming:completion-audit --json`

## Riscos
Esconder estado real do backend; gerar próximo passo sem checar capacity/invocation; promover completion silenciosamente.

## Exemplos
Ver corpo acima para fluxo completo do orchestrator e capturas do painel humano.

## Proximas Acoes
Manter sincronizado com novos passos canônicos do Forge conforme outros eixos evoluírem.

## Evolução

Estendido por [[atlas-code-human-interface-upgrade-v2]] (estado humano priorizado, blocker translation, completion gating, evidence separation, chat com papel) e promovido a cabine central por [[atlas-code-obra-command-center-v1]] (lifecycle de 8 fases, decision inbox, operational health, trust summary, safety strip).
