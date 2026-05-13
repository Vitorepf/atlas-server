---
id: atlas-code-scor-1-implementation-contract
type: engineering_knowledge
title: Atlas Code SCOR-1 Implementation Contract
status: building
category: surface
priority: 100
summary: Contrato one-shot para implementar a primeira fatia real do Atlas Code SCOR-1 no Atlas Desktop, conectando WorkItem, SDD, Spec, Plan, Tasks, Verify e Evidence a dados reais do Programming Governance sem mocks.
tags:
  - atlas-code
  - scor-1
  - implementation-contract
  - atlas-desktop
  - programming-governance
capabilities:
  - atlas_code_scor_1
  - one_shot_implementation
  - governed_ai_coding_cockpit
  - programming_governance_ui
decisions:
  - Esta fatia implementa SCOR-1 Thin Slice, nao o Forge OS completo.
  - A UI deve evoluir a surface existente em atlas-desktop, nao criar tela paralela.
  - A fonte primaria da fatia e /atlas-code/works/{id}/state, expandida com programming_governance quando existir.
  - Ausencia de dado real vira empty/degraded state honesto; mock e proibido.
maintenance:
  - Atualizar antes de mudar bridge, WorkStateSnapshot, RightRail, MainStage ou endpoints /atlas-code/works/{id}/state.
  - Atualizar junto com atlas-code-long-session-programming-cockpit.md quando novos artefatos SCOR-1 virarem runtime.
related_paths:
  - docs/engineering-knowledge-base/atlas-code-long-session-programming-cockpit.md
  - docs/engineering-knowledge-base/atlas-desktop-code-surface.md
  - docs/engineering-knowledge-base/atlas-desktop-backend-contract.md
  - docs/engineering-knowledge-base/atlas-programming-governance-system-runbook.md
  - ../atlas-desktop/packages/atlas-domain/src/index.ts
  - ../atlas-desktop/apps/desktop/src/lib/bridge.ts
  - ../atlas-desktop/apps/desktop/src/hooks/useBridge.ts
  - ../atlas-desktop/apps/desktop/src/surfaces/code/CodeSurface.tsx
  - ../atlas-desktop/apps/desktop/src/surfaces/code/stage/MainStage.tsx
  - ../atlas-desktop/apps/desktop/src/surfaces/code/panels/RightRail.tsx
  - ../atlas-desktop/apps/desktop/src/surfaces/code/panels/rightRailRegistry.tsx
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-code-scor-1-implementation-contract
graph_title: Atlas Code SCOR-1 Implementation Contract
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-code-long-session-programming-cockpit
graph_status: building
graph_source: repo
owner: atlas-ai
layer: 1-surfaces
line_limit: 520

repo_paths:
  - docs/engineering-knowledge-base/atlas-code-scor-1-implementation-contract.md
  - ../atlas-desktop/packages/atlas-domain/src/index.ts
  - ../atlas-desktop/apps/desktop/src/lib/bridge.ts
  - ../atlas-desktop/apps/desktop/src/hooks/useBridge.ts
  - ../atlas-desktop/apps/desktop/src/surfaces/code/

allowed_changes:
  - Evoluir tipos de dominio, bridge, hook useBridge e paineis da surface Code para exibir dados reais SCOR-1.
  - Adicionar componentes pequenos e registries para WorkItem, Spec, Plan, Tasks, Gates e Evidence.
  - Adicionar estados vazios/degradados honestos quando backend ainda nao expuser um artefato.

forbidden_changes:
  - Criar mock data, obras fake, gates fake, evidence fake ou work items inventados.
  - Criar nova tela paralela fora de surfaces/code.
  - Implementar Forge OS, multi-provider reservations, collision matrix ou DSL completa nesta fatia.
  - Fazer o Desktop virar fonte de verdade de Programming Governance.

depends_on:
  - atlas-code-long-session-programming-cockpit
  - atlas-desktop-backend-contract
  - atlas-programming-governance-system

flows_to:
  - atlas-desktop-code-surface
  - atlas-code

unlocks:
  - scor-1-thin-slice
  - programming-governance-visual-runtime

governs:
  - atlas-code.scor-1.thin-slice

evidence:
  - docs/engineering-knowledge-base/atlas-code-scor-1-implementation-contract.md

required_tests:
  - "cd ../atlas-desktop && npm run typecheck"
  - "cd ../atlas-desktop && npm run lint"
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true
risk_level: high

visual_tags:
  - scor-1
  - atlas-code
  - implementation

ai_entrypoints:
  - Leia este doc, depois atlas-code-long-session-programming-cockpit.md, atlas-desktop-code-surface.md e atlas-desktop-backend-contract.md antes de implementar.

ai_usage_notes:
  - Este doc e intencionalmente prescritivo para one-shot. Se uma rota real faltar, implemente empty/degraded state honesto e registre gap visual.

quality_gates:
  - typecheck
  - lint
  - docs-health
  - no-mock-data
  - real-bridge-only

failure_modes:
  - Claude criar paineis bonitos com dados inventados.
  - Claude duplicar estado em vez de usar WorkStateSnapshot.
  - Verify/Evidence continuar textual e nao rastreavel.

observability_signals:
  - WorkItem inspector visivel
  - SDD stage derivado de work state
  - Gate list com estados reais/degradados
  - Evidence list com receipts reais/degradados

next_actions:
  - Implementar esta fatia no Atlas Desktop e validar com typecheck, lint e screenshots.
---
# Atlas Code SCOR-1 Implementation Contract

## Resumo

Este documento e o contrato one-shot para uma IA implementar a primeira fatia
real do Atlas Code SCOR-1. Ele transforma o canon do SCOR-1 em escopo fechado
de codigo: mostrar Programming Governance dentro da surface Code existente,
sem runtime paralelo e sem mock.

## Papel no Atlas

Esta fatia faz Atlas Code deixar de ser apenas conversa com receipt e passar a
ser cockpit visivel de programacao governada:

```text
Obra -> WorkItem -> SDD -> Spec -> Plan -> Tasks -> Verify -> Evidence -> Review
```

Ela nao conclui Forge OS, Cartografia automatica, DSL completa ou execucao
multiagente. Ela prepara a UI para esses sistemas.

## Onde Se Encaixa

Implementar somente dentro da surface existente:

```text
../atlas-desktop/apps/desktop/src/surfaces/code/
../atlas-desktop/apps/desktop/src/lib/bridge.ts
../atlas-desktop/apps/desktop/src/hooks/useBridge.ts
../atlas-desktop/packages/atlas-domain/src/index.ts
```

Nao criar uma nova pagina, shell ou rota visual. `CodeSurface.tsx` continua
sendo o composition boundary.

## Contratos

### 1. Fonte De Verdade

A fonte primaria do cockpit e:

```text
GET /atlas-code/works/{workId}/state
```

O bridge ja possui `getWorkState(workId)` e `adaptWorkState(raw)`. A fatia deve
expandir esse snapshot quando o backend retornar `programming_governance`.

Se o backend ainda nao retornar a chave, a UI mostra:

```text
Programming Governance: sem work item vinculado
```

Nunca inventar WorkItem, Spec, Plan, Tasks, Gates ou Evidence.

### 2. Shape Backend Esperado

O backend pode retornar snake_case ou camelCase. O bridge deve aceitar ambos.

```json
{
  "work": { "id": "obra-id", "title": "Atlas Code", "objective": "...", "status": "active" },
  "sdd": { "stage": "spec", "steps": [] },
  "receipt": null,
  "gates": [],
  "evidence": [],
  "programming_governance": {
    "work_item": {
      "id": "uuid",
      "code": "REF-ABC12345",
      "intent_text": "Refatorar...",
      "intent_type": "refactor",
      "scope_mode": "structural",
      "risk_level": "medium",
      "status": "executing",
      "current_stage": "execution",
      "spec_hash": "sha256",
      "plan_hash": "sha256",
      "required_gates": ["feature-placement", "code-intelligence-context"],
      "gaps": []
    },
    "spec": {},
    "plan": {},
    "tasks": [],
    "gate_runs": [],
    "reviews": [],
    "evidence_refs": [],
    "artifacts": [],
    "degraded": false,
    "degraded_reason": null
  }
}
```

### 3. Tipos TypeScript Obrigatorios

Adicionar em `../atlas-desktop/packages/atlas-domain/src/index.ts`:

```ts
export type ProgrammingScopeMode = 'compact' | 'structural'
export type ProgrammingWorkStatus =
  | 'open' | 'spec_required' | 'plan_required' | 'executing'
  | 'verifying' | 'review' | 'closed' | 'blocked'

export interface ProgrammingWorkItemSnapshot {
  id: string
  code: string
  intentText: string
  intentType: string
  scopeMode: ProgrammingScopeMode
  riskLevel: string
  status: ProgrammingWorkStatus | string
  currentStage: string
  specHash: string | null
  planHash: string | null
  requiredGates: string[]
  gaps: Array<{ name: string; reason?: string | null; recordedAt?: string | null }>
}

export interface ProgrammingTaskContract {
  owner?: string | null
  allowedFiles: string[]
  forbiddenFiles: string[]
  expectedFiles: string[]
  dependencies: string[]
  riskLevel?: string | null
  validationCommands: string[]
  acceptanceCriteria: string[]
  rollback?: string | null
  evidenceRequired: string[]
  docsRequired: string[]
  cartographyRequired: boolean
}

export interface ProgrammingGateRunSnapshot {
  id?: string
  gateName: string
  status: 'passed' | 'failed' | 'skipped' | 'waived' | string
  blocking: boolean
  reason?: string | null
  waiverReason?: string | null
  payload?: unknown
  createdAt?: string | null
}

export interface ProgrammingEvidenceReceiptSnapshot {
  receiptId?: string
  evidenceType: string
  status: string
  command?: string | null
  output?: string | null
  files: string[]
  tests: string[]
  diffPath?: string | null
  artifactUrl?: string | null
  summary?: string | null
  storage?: { persisted: boolean; table?: string; reason?: string; id?: string }
  recordedAt?: string | null
}

export interface ProgrammingGovernanceSnapshot {
  workItem: ProgrammingWorkItemSnapshot | null
  spec: Record<string, unknown> | null
  plan: Record<string, unknown> | null
  tasks: ProgrammingTaskContract[]
  gateRuns: ProgrammingGateRunSnapshot[]
  reviews: Array<Record<string, unknown>>
  evidenceRefs: ProgrammingEvidenceReceiptSnapshot[]
  artifacts: Array<Record<string, unknown>>
  degraded: boolean
  degradedReason: string | null
}
```

Expandir `WorkStateSnapshot` com:

```ts
programmingGovernance: ProgrammingGovernanceSnapshot | null
```

### 4. Bridge

Em `bridge.ts`, expandir `adaptWorkState(raw)`:

- ler `programming_governance` ou `programmingGovernance`;
- normalizar snake_case para camelCase;
- preservar `null` quando ausente;
- nunca preencher arrays com exemplos inventados;
- quando `degraded=true`, preservar `degradedReason`.

### 5. useBridge

Em `useBridge.ts`, carregar `programmingGovernance` junto com o snapshot da
Obra selecionada e expor no `BridgeSnapshot`.

Estado inicial:

```ts
programmingGovernance: null
```

Ao selecionar Obra sem governance:

```text
UI mostra empty state honesto.
```

### 6. Main Stage

`MainStage` deve continuar com conversa como modo inicial, mas precisa ganhar
modo visual para objetos SCOR-1:

```ts
MainStageMode = 'conversation' | 'spec' | 'plan' | 'diff' | 'replay' | 'repair'
```

Se o modo `spec` ou `plan` ja existir como tipo, implementar render basico:

- `spec`: cards de objective, context, expected_behavior, likely_files, risks,
  tests, evidence_required, rollback, completion_criteria.
- `plan`: phases e task contracts.

Sem spec/plan real:

```text
aguardando spec governada
aguardando plan governado
```

### 7. Right Rail

RightRail deve receber `programmingGovernance`.

Painel `Plan`:

- mostrar `WorkItem Inspector` acima do receipt;
- mostrar status, stage, type/mode/risk, spec_hash, plan_hash;
- mostrar gaps.

Painel `Verify`:

- mostrar `programmingGovernance.gateRuns` quando existir;
- fallback para `gates` legado quando gateRuns estiver vazio;
- cada linha mostra gate, status, blocking, reason;
- failed blocking deve ter destaque visual.

Painel `Evidence`:

- mostrar `programmingGovernance.evidenceRefs` quando existir;
- cada receipt mostra command, tests, files, diff_path, artifact_url, storage;
- se `storage.persisted=false`, mostrar degraded state, nao sucesso.

### 8. SDD Mini

A barra SDD nao pode marcar etapa como concluida por texto de chat. Ela deve
usar:

| Stage | Regra |
|---|---|
| context | existe Obra selecionada e/ou context pack real |
| spec | `workItem.specHash` existe |
| plan | `workItem.planHash` existe e tasks.length > 0 |
| execute | status `executing`, `verifying`, `review` ou `closed` |
| verify | existe gate run real |
| learn | existe review, learning artifact ou workItem closed |

Quando faltar governance, manter pipeline atual/idle, mas mostrar no inspector
que SCOR-1 ainda nao esta vinculado.

### 9. Estados Vazios E Degradados

Obrigatorios:

```text
sem work item vinculado
aguardando spec governada
aguardando plan governado
sem gate runs reais
sem evidence receipts reais
evidence nao persistiu no ledger
backend degradado: <reason>
```

Proibido:

```text
OK fake
receipt fake
gate passed fake
example files como se fossem reais
```

## Fluxo

Implementar nesta ordem:

1. Tipos em `@atlas/domain`.
2. Normalizacao em `bridge.ts`.
3. Exposicao em `useBridge.ts`.
4. Passar props por `CodeSurface.tsx`.
5. `WorkItemInspector` no Plan panel.
6. `Spec/Plan/Tasks` render basico no MainStage ou Plan panel.
7. `VerifyPanel` com gate runs reais.
8. `EvidencePanel` com receipts reais e degraded storage.
9. Ajustar SDD mini para hashes/gate runs quando governance existir.
10. Rodar typecheck/lint e capturar screenshot se app abrir.

## Regras para IA

- Ler os arquivos atuais antes de editar.
- Preservar layout existente: `CodeSurfaceLayout`, `LeftRail`, `MainStage`,
  `RightRail`, `TerminalDock`.
- Criar componentes pequenos sob `surfaces/code/`.
- Atualizar READMEs locais se adicionar subpasta nova.
- Nao adicionar dependencias novas sem necessidade.
- Nao implementar backend novo nesta fatia, exceto adapter minimo se ja existir
  controller/rota clara.

## Escopo de Implementacao

Dentro do escopo:

- UI e tipos para visualizar Programming Governance.
- Bridge adapter para `programming_governance`.
- Estados vazios/degradados.
- SDD derivado de dados reais quando disponiveis.

Fora do escopo:

- criar WorkItem pela UI;
- gerar spec/plan automaticamente;
- rodar `atlas:programming:*` pela UI;
- streaming SSE completo;
- Atlas Operational Artifact DSL completa;
- Forge OS/multi-provider.

## Dependencias

- `atlas-code-long-session-programming-cockpit.md`
- `atlas-desktop-code-surface.md`
- `atlas-desktop-backend-contract.md`
- `atlas-programming-governance-system-runbook.md`

## Evidencias

Ao terminar, a IA deve reportar:

- arquivos alterados;
- se `programming_governance` ausente renderiza empty state;
- se gate runs/evidence reais renderizam quando presentes;
- resultado de `npm run typecheck`;
- resultado de `npm run lint`;
- resultado de `php artisan atlas:engineering:knowledge docs-health --json`.

## Riscos

- A UI parecer mais pronta que o backend.
- Arrays vazios serem confundidos com sucesso.
- Evidence sem persistencia ser exibida como prova.
- SDD voltar a ser decorativa.

## Exemplos

Exemplo de receipt degradado que deve aparecer como alerta:

```json
{
  "receipt_id": "abc",
  "command": "vendor/bin/phpunit",
  "output": "OK",
  "storage": {
    "persisted": false,
    "reason": "engineering_evidence_write_failed"
  }
}
```

Exemplo visual correto: `WORKITEM REF-ABC12345` mostra mode/risk/status,
spec/plan hashes, gates com blocking/reason e receipts com persistencia real.

## Proximas Acoes

1. Entregar esta thin slice.
2. Depois criar AP para comandos `atlas:programming:*` via UI.
3. Depois criar AP para streaming SSE de artefatos.
4. Depois criar AP para Scope Guard/Diff real.
