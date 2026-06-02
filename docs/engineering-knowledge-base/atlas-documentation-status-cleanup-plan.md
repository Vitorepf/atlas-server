---
id: atlas-documentation-status-cleanup-plan
type: engineering_knowledge
title: Atlas Documentation Status Cleanup Plan
status: active
category: architecture
priority: 94
summary: Plano canon (2026-05-18) para reclassificar status das docs canonicas do Atlas sem apagar visao futura e sem quebrar rastreabilidade. Define taxonomia de 8 estados conceituais, mapeia cada doc critica para estado recomendado com motivo/evidencia/risco/quando, e sequencia P0-P3 de flips puramente de frontmatter sem mudanca de codigo.
tags:
  - atlas-ai
  - documentation
  - status-cleanup
  - cleanup-plan
  - 2026-05-18
capabilities:
  - status_taxonomy_definition
  - critical_doc_classification
  - migration_sequencing
  - never_remove_guardrail
  - audit_traceability
decisions:
  - Este plano NAO altera massa de docs agora. NAO deleta docs. NAO altera codigo. Apenas classifica e sequencia.
  - Status canonico do schema atlas_canonical_module_doc.v1 admite 5 valores (planned/future/building/active/deprecated). Plano usa 8 estados conceituais (active/building/planned/future/superseded/deprecated/historical/unknown) e explicita o mapeamento para o conjunto canonico no momento do flip.
  - Cada flip recomendado cita evidencia concreta (servico, migration, comando, teste) ou marca como hipotese a confirmar.
  - Visao futura valida (Sovereign OS, Epistemic OS, Cartographic Knowledge OS, Next Patamar, Thesis Multiplier, Resolver Corpus, Driver stubs, AtlasVault, CLAUDE.md/AGENTS.md) permanece intocada.
  - Diagnostico filesystem detalhado vive em atlas-canonical-cleanup-inventory.md. Este plano nao duplica — referencia.
maintenance:
  - Atualizar quando algum flip P1 ou P2 for executado (frontmatter de doc-alvo + entrada nesta tabela).
  - Regenerar tabela "Unknown / a auditar" quando novos audits derem evidencia.
  - Re-rodar docs-health a cada flip e anexar contagem na secao Evidencias.
related_paths:
  - docs/engineering-knowledge-base/atlas-canonical-cleanup-inventory.md
  - docs/engineering-knowledge-base/atlas-architecture-critical-judgment-report.md
  - docs/engineering-knowledge-base/atlas-dev-forge-relationship-critical-audit.md
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
  - docs/engineering-knowledge-base/atlas-programming-governance-system.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
  - docs/engineering-knowledge-base/atlas-hyperflow-operation.md
  - docs/engineering-knowledge-base/atlas-autonomous-intelligence-operating-system.md
  - docs/engineering-knowledge-base/atlas-domain-company-runtimes.md
  - docs/engineering-knowledge-base/atlas-ai-router-runtime-enterprise-upgrade.md
  - docs/engineering-knowledge-base/atlas-evidence-certification-runtime.md
  - docs/engineering-knowledge-base/atlas-compounding-engineering-intelligence.md
  - docs/engineering-knowledge-base/atlas-local-agent-memory-ingestion.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-documentation-status-cleanup-plan
graph_title: Atlas Documentation Status Cleanup Plan
graph_world: atlas
graph_layer: system
graph_kind: index
graph_parent: atlas-canonical-cleanup-inventory
graph_status: active
graph_source: repo
human_name: Atlas Documentation Status Cleanup Plan
canonical_name: Atlas Documentation Status Cleanup Plan
technical_name: atlas-documentation-status-cleanup-plan
cartography_type: index
canonical_source: docs/engineering-knowledge-base/atlas-documentation-status-cleanup-plan.md
owner: architecture
repo_paths:
  - docs/engineering-knowledge-base/atlas-documentation-status-cleanup-plan.md
allowed_changes:
  - Atualizar status recomendado quando nova evidencia for verificada.
  - Anexar entradas de docs novas que entrem no perimetro critico.
  - Registrar quando um flip P1/P2 for executado, com data e teste verde.
forbidden_changes:
  - Promover doc para `active` sem evidencia em codigo verificada (servico+teste ou migration+CLI).
  - Marcar doc da lista "Nunca Remover" como `deprecated` ou similar.
  - Sugerir delecao de qualquer doc — apenas reclassificacao.
  - Alterar codigo enquanto executa flips. Frontmatter only.
depends_on:
  - atlas-canonical-cleanup-inventory
  - atlas-architecture-critical-judgment-report
  - atlas-dev-forge-relationship-critical-audit
flows_to:
  - atlas-ai-canonical-architecture-index
  - atlas-ai-multi-domain-implementation-sequence
unlocks:
  - safe-status-flip-sequencing
  - audit-traceable-deprecation
governs:
  - canonical-status-taxonomy
evidence:
  - docs/engineering-knowledge-base/atlas-canonical-cleanup-inventory.md
  - app/Services/Engineering/EngineeringDocumentationHealthService.php
  - app/Services/Ai/Programming/AtlasForge/
  - app/Services/Ai/Programming/AtlasDev/
  - app/Services/Ai/Programming/AtlasProgrammingOrchestrator.php
  - app/Services/Ai/Programming/AtlasCode/DevToForgePromotionService.php
evidence_refs:
  - symbol: EngineeringDocumentationHealthService
  - command: atlas:engineering:knowledge
  - test: EngineeringDocumentationHealthServiceTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "git diff --check"
requires_evidence: true
risk_level: medium
ai_entrypoints:
  - Leia Resumo, Taxonomia, Tabelas por Grupo e Migration Order antes de propor mudanca em status de qualquer doc canonica.
  - Leia "Nunca Remover" antes de tocar qualquer doc com `status: future`.
quality_gates:
  - status-taxonomy-defined
  - critical-docs-classified
  - migration-ordered
  - never-remove-cross-checked
  - docs-health-green
failure_modes:
  - Promover doc para active sem evidencia.
  - Marcar visao futura legitima como deprecated.
  - Apagar referencia a doc que ainda governa decisao runtime.
  - Quebrar `depends_on`/`flows_to` ao alterar status sem revisar grafo.
observability_signals:
  - docs-health canonical_violation_count
  - docs-health oversized_count
  - git diff --check whitespace count
next_actions:
  - Operador escolhe P1 (Forge OS trio + Programming Governance trio + Dev efficient v1) para promover de future/draft -> active via flip de frontmatter.
  - Apos cada flip, rodar docs-health e anexar contagem nesta doc.
  - Auditar grupo "Unknown" antes de qualquer outro flip.
line_limit: 520
---
# Atlas Documentation Status Cleanup Plan
## Resumo
Distribuicao real de `^status:` em 2026-05-18 (~200 docs em
`docs/engineering-knowledge-base/`): 170 `active`, 10 `future`, 4 `draft`,
4 `deprecated`, 4 `building`, 3 `scaffold`, 2 `split_required`, 2
`archived`, 1 `source_material`, 1 `proposed`, 1 `canon`.
Audits 2026-05-18 (`atlas-architecture-critical-judgment-report.md` +
`atlas-dev-forge-relationship-critical-audit.md`) identificam **divergencia sistematica** status-vs-codigo:
- **Forge OS trio** em `future` mas tem 32 svcs + 46 ForgeRivals + 30-40
  endpoints HTTP + 81 testes em producao.
- **Programming Governance trio** em `building`/`future` mas tem 8
  migrations vivas + CLI + Programming domain enterprise.
- **Atlas Dev efficient pair** em `draft` mas tem 138 files / 20.7k LOC +
  feature flag em uso.
Ao mesmo tempo, **visao futura legitima** (Sovereign/Epistemic/Cartographic
OS, Next Patamar, Thesis Multiplier, Resolver Corpus) PRECISA permanecer
em `future` — `future` significa "tese estrategica nao construida", nao
"obsoleta".
Este plano: (1) define taxonomia de 8 estados conceituais com mapeamento
para os 5 canonicos do schema; (2) classifica docs criticas (Forge,
Dev legacy vs efficient, Hyperflow, AIOS, Programming Superiority,
Domain Company Runtimes, Router Runtime, Evidence/Certification,
Compounding, Local Agent Memory) em 7 grupos; (3) sequencia flips
P0-P3 — todos **frontmatter only**.
**NAO altera massa de docs agora**, **NAO deleta**, **NAO altera
codigo**. Cada flip futuro vira AP individual.
## Papel no Atlas
Camada de governanca **acima** do inventario filesystem
(`atlas-canonical-cleanup-inventory.md`). Inventario diz "o que existe e
onde diverge"; este plano diz "qual status passar a usar, em que ordem e
por que". Quatro funcoes: (1) taxonomy canon dos 5 status admissiveis +
mapeamento dos 8 conceituais; (2) status-flip sequencing; (3) never-remove
guardrail; (4) audit trail por flip executado.
## Onde Se Encaixa
Filho de `atlas-canonical-cleanup-inventory.md`. Cruza com
`atlas-ai-canonical-architecture-index.md`. Nao vence Layer -1 nem
Kernel. Instrumento operacional para preparar reconciliacao
status-vs-implementacao.

## Contratos

Este plano nao cria contratos novos. Cita:

- **`atlas_canonical_module_doc.v1`** — schema das docs canonicas
  (`app/Services/Engineering/EngineeringDocumentationHealthService.php:11`).
  Status admissiveis: `planned`, `future`, `building`, `active`,
  `deprecated`.
- **`atlas.dual_core.route_decision.v1`** e
  **`atlas.dev_to_forge.escalation_packet.v1`** — implementados, cobrem o
  caminho Dev->Forge.
- **`atlas.ai.mission.v1`**, **`atlas.ai.objective.v1`**,
  **`atlas.ai.work_order.v1`** — Meta 1.
- **`atlas.ai.router_decision.v1`**, **`atlas.ai.flow_route.v1`** — Meta 6.

## Fluxo

```
docs-health (baseline) -> classificar -> mapear conceitual -> canonico
  -> escolher P0/P1/P2/P3 -> AP individual (frontmatter only)
  -> docs-health + git diff --check green -> entry com data
```

## Taxonomia de Status

Plano usa **8 estados conceituais**. Schema canonico admite 5 valores
oficiais (`planned`/`future`/`building`/`active`/`deprecated`). Mapeamento
ocorre na hora do flip.

| Estado conceitual | Semantica | Status canonico no schema | Aplicar quando |
|---|---|---|---|
| **active** | Runtime/contrato vigente e implementado em producao | `active` | Codigo + teste + CLI/HTTP em uso real |
| **building** | Implementacao parcial em andamento dentro do ciclo atual | `building` | Algumas pecas em producao, outras pendentes mas com AP aberta |
| **planned** | Aprovado e priorizado, sem implementacao iniciada | `planned` | DoD pronto, sem servico/migration ainda |
| **future** | Tese estrategica nao construida (e talvez nao prevista para construir) | `future` | Visao de Layer 0.4x+ sem AP atual |
| **superseded** | Conceito foi substituido por outro doc/contrato vigente | `deprecated` + campo `summary` indicando o sucessor | Existe doc novo que cobre o mesmo escopo |
| **deprecated** | Nao usar para novo trabalho; pode ter codigo vivo legacy | `deprecated` | Doc explicitamente marcado para deixar de usar |
| **historical** | Registro de algo que aconteceu (audit, ADR fechado, missao entregue) | `deprecated` + `category: historical` quando aplicavel | Snapshot de momento; preserve historia, nao usar como referencia ativa |
| **unknown** | Precisa auditoria antes de decidir | mantem o que esta + entry na tabela "Unknown" | Sem evidencia clara em codigo OU sem prova de obsolescencia |

Regra dura: **superseded** e **historical** colapsam para `deprecated` no
schema. A distincao fica no `summary:` e no campo `category:`. Plano
sempre cita o sucessor (para superseded) ou a data/contexto (para
historical) na entrada da doc.

## Grupo 1 — Runtime Atual (confirmar `active`)

Estas docs ja estao `active` e a evidencia em codigo confirma. **Nao
precisam flip**. Listadas so para auditoria de continuidade. Risco
unico em comum: re-implementar conceito se IA assumir status errado.

| Doc | Evidencia |
|---|---|
| `atlas-autonomous-intelligence-operating-system.md` | Layer 0 — cita Metas 1/2/3/4/6/7 implementadas |
| `atlas-domain-company-runtimes.md` | Domain Runtime Meta 2 + 8 domains em producao |
| `atlas-ai-router-runtime-enterprise-upgrade.md` | `app/Services/Ai/RouterRuntime/` + 36 testes Meta 6 |
| `atlas-evidence-certification-runtime.md` | 11 tabelas + 14 services Meta 4 |
| `atlas-compounding-engineering-intelligence.md` | LearningPacket + NextCycleRecommendation + ResultLedger (Self-Improvement Closed Loop Level 7) |
| `atlas-local-agent-memory-ingestion.md` | Local Agent Memory + Open Brain projection + CLAUDE/AGENTS.md vivos |
| `atlas-hyperflow-operation.md` | Hyperflow runtime em uso |
| `atlas-hyperflow-completion-audit-v1.md` | Audit Hyperflow gerado e usado |
| `atlas-hyperflow-certification-runbook-v1.md` | Runbook Hyperflow operacional |
| `atlas-programming-superiority-architecture.md` | Programming Superiority entregue |
| `atlas-programming-superiority-contracts.md` | Contratos consumidos por orchestrator/policy |
| `atlas-programming-superiority-roadmap.md` | Roadmap em uso |

## Grupo 2 — Contratos Vigentes (confirmar `active`)

| Doc | Evidencia |
|---|---|
| `atlas-canonical-module-doc-v1.md` | Schema validado por `EngineeringDocumentationHealthService.php` |
| `atlas-kernel-mission-foundation.md` | Meta 1 — Mission/Objective/WorkOrder vivos |
| `atlas-dual-core-engineering-system.md` | Dual-core route decision em `app/Services/Ai/DualCore/` |
| `atlas-ai-canonical-architecture-index.md` | Index citado por inventario e este plano |

## Grupo 3 — Implementacao Parcial (manter ou amadurecer `building`)

Docs cujo escopo esta parcialmente entregue dentro do ciclo atual.

| Doc | Status atual | Recomendado | Evidencia | Risco se mis | Quando |
|---|---|---|---|---|---|
| `atlas-aiworker-kernel-integration-adr.md` | `active` (ADR design-only) | `active` | ADR descreve 6 wires Kernel<->AiWorker; codigo wire-by-wire pendente; ADR mesmo e canonico | acreditar que wires existem | manter; nao confundir ADR `active` com wire `active` |
| `atlas-ai-cyber-security-extension.md` | `scaffold` | `building` | Cyber Security Company Runtime implementado (8 tables, 8 models, 13 services, refusal matrix, 5 bug-bounty gates); doc scaffold subiu para extensao | quem le scaffold pensa que nao tem nada | P2 — flip apos audit de paridade scaffold-vs-codigo |
| `atlas-programming-governance-system.md` | `building` | `active` | Programming Governance entregue 2026-05-13 (tabelas `atlas_programming_work_items/gate_runs/reviews`, CLI `atlas:programming:*`) | continua a tratar como em construcao quando ja governa | **P1** |
| `atlas-programming-governance-system-runbook.md` | `building` | `active` | Runbook em uso por Programming Domain | operador segue rota errada | **P1** |
| `atlas-programming-governance-system-contracts.md` | `future` | `active` | Contratos consumidos por `ProgrammingPolicyBridge` + `AtlasProgrammingOrchestrator` | quebrar shape canonico ao tocar contrato | **P1** |

## Grupo 4 — Visao Futura Valida (manter `future` — Nunca Remover)

Lista canon **inviolavel**. `future` aqui = "tese estrategica nao
construida ainda" — manter intacta. Cleanup NUNCA deve marcar como
`deprecated` ou apagar.

| Doc | Tese |
|---|---|
| `atlas-sovereign-operating-system.md` | Layer 0.45+ — soberania |
| `atlas-epistemic-operating-system.md` | Layer 0.5+ — epistemica |
| `atlas-cartographic-knowledge-os.md` | Layer 0.55+ — cartografica (AtlasVault) |
| `atlas-next-patamar-operating-systems.md` | Layer 0.56+ — meta-patamares |
| `atlas-ai-thesis-multiplier-channel.md` | Canal estrategico |
| `atlas-resolver-corpus.md` | Corpus de resolvers |

Forge OS aparece tambem no Grupo 5 porque DEVE virar `active` via flip
P1 — sai da lista "future legitima" so pelo flip controlado.

## Grupo 5 — Status Invertido (precisam virar `active` — flip P1)

Docs com codigo em producao mas status declarado `future`/`draft`. Risco
direto: IA assume "nao existe" e re-implementa.

| Doc | Status atual | Recomendado | Evidencia | Risco se mis | Quando |
|---|---|---|---|---|---|
| `atlas-forge-operating-system.md` | `future` | `active` | 32 svcs `AtlasForge*` + 46 svcs `ForgeRivals/` + 30-40 endpoints HTTP + 81 testes | re-implementar Forge OS do zero | **P1** |
| `atlas-forge-operating-system-contracts.md` | `future` | `active` | 37 invariants implementados em `AtlasForgeContinuumCertificationService.php` | inventar shape de contrato | **P1** |
| `atlas-forge-operating-system-runbook.md` | `future` | `active` | Runbook real para Forge em producao via `atlas:forge:*` commands | seguir rota errada de operacao | **P1** |
| `atlas-dev-efficient-programming-flow-v1.md` | `draft` | `active` | `AtlasDevFastPathOrchestrator` em producao; 138 files / 20.7k LOC em `app/Services/Ai/Programming/AtlasDev/` | tratar fluxo Dev como hipotetico | **P1** |
| `atlas-dev-flow-map-and-product-options-v1.md` | `draft` | `active` | Feature flag `atlas_dev_efficient_plan_enabled` em uso; pipeline real | seguir map antigo | **P1** |

## Grupo 6 — Candidatos a Superseded (flip P2 condicionado)

Docs cujo escopo PODE ter sido absorvido por canon mais recente. Antes
de marcar como `deprecated` (mapeamento canonico para `superseded`),
comparar shape do contrato/v1 com canon vigente.

| Doc | Hipotese | Cross-check necessario |
|---|---|---|
| `atlas-ai-router-flow-routing-contract-v1.md` | superseded por Meta 6 RouterRuntime | Shape v1 vs `FlowRouterService`+`DomainRouterService`+`RuntimeDispatchService` |
| `atlas-code-scor-1-implementation-contract.md` | visao futura OU superseded | Cross-check com `app/Services/Ai/Programming/AtlasCode/` |
| `atlas-ai-voice-realtime-surface.md` | ambiguo | Verificar `app/Services/Ai/Voice/` |
| `atlas-code-long-session-programming-cockpit.md` | absorvido por Atlas Code Obra Command Center | Cross-check `AtlasCodeObraCommandCenterService` |
| `atlas-desktop-code-surface.md` | superseded ou complementar ao Atlas Desktop Design System | `atlas-desktop/docs/architecture/0007-*` |
| `atlas-forge-rivals-intelligence-ledger-v1.md` | superseded por Forge Rivals Provider Performance Ledger v1 | Cross-check ledger entregue |
| `atlas-native-mac-agent.md` | visao futura legitima | Verificar se ha codigo equivalente |
| `atlas-semantic-graph.md` | coberto por AtlasVault + Code Intelligence | Cross-check graph semantic vs vault sync |
| `surface-domain-catalog-integration-plan.md` | em integracao | Cross-check Domain Runtime catalog |

Regra para flip `deprecated`: (1) sucessor canonico identificado;
(2) `summary:` cita sucessor; (3) `flows_to:` aponta para sucessor;
(4) docs-health green.

## Grupo 7 — Historicas / Snapshots (manter `deprecated`)

Docs ja `deprecated` — preservar historia, nao usar como referencia ativa.

| Doc | Substituida por |
|---|---|
| `architecture.md` | `atlas-ai-master-architecture.md` + index canonico |
| `capability-matrix.md` | canon vigente (capability mapeada em index) |
| `context-pack.md` | Atlas Open Brain Context Pack runtime |
| `mcp-tools-contract.md` | MCP catalog canon vigente |
| `mcp-tools-rollout-report.md` | snapshot — apenas historia |
| `maintenance-playbook.md` | playbooks canonicas vigentes |

## Grupo 8 — Unknown (auditar antes de tocar — P3)

Status admissivel mas sem evidencia clara em codigo OU em prova de
obsolescencia. **Auditar** antes de qualquer flip.

| Doc | Status atual | Acao | Pergunta a responder |
|---|---|---|---|
| Docs com `status: scaffold` (3) | `scaffold` (nao canonico) | Audit individual | Existe codigo correspondente? Promover para `building`/`active` ou rebaixar |
| Docs com `status: split_required` (2) | `split_required` (grandfathered) | Audit individual | Ja foram divididas? Atualizar lista grandfathered |
| Docs com `status: archived` (2) | `archived` (nao canonico) | Audit individual | Mapear para `deprecated` ou manter como excecao explicita |
| Docs com `status: source_material`/`proposed`/`canon` (3) | nao canonico | Audit individual | Decidir entre `planned`/`future`/`active`/`deprecated` |

`status: scaffold`, `split_required`, `archived`, `source_material`,
`proposed`, `canon` **nao sao admissiveis** pelo schema
`atlas_canonical_module_doc.v1`. Em algum momento esses valores precisam
colapsar para um dos 5 canonicos.

## Nunca Remover

Lista canon cruzada com `atlas-canonical-cleanup-inventory.md`. Cleanup
NUNCA deve marcar como `deprecated` nem apagar referencia:

1. **`atlas-sovereign-operating-system.md`** — tese soberana.
2. **`atlas-epistemic-operating-system.md`** — tese epistemica.
3. **`atlas-cartographic-knowledge-os.md`** — tese cartografica (sucede
   graph view do Obsidian via AtlasVault).
4. **`atlas-next-patamar-operating-systems.md`** — meta-tese de patamares.
5. **`atlas-ai-thesis-multiplier-channel.md`** — canal estrategico.
6. **`atlas-resolver-corpus.md`** — corpus de resolvers.
7. **Stubs de Driver** em `app/Services/Ai/Programming/Drivers/` — sao
   contratos planted para futuros providers; deletar quebra schema.
8. **AtlasVault** (sync, cartografia, doc canonica em
   `atlas-server/docs/atlas-vault-cartografia.md`) — memoria humana
   intocavel.
9. **`CLAUDE.md`** e **`AGENTS.md`** — provider projections geradas por
   Atlas; deletar quebra bootstrap de novos modelos.

## Migration Order

Sequencia P0-P3. Cada P# vira AP individual. **Flips P1/P2 sao puramente
de frontmatter — alteram so o campo `status:` (e `graph_status:` quando
existir). Nao tocam codigo.**

### P0 — Zero Touch (baseline)

Rodar `php artisan atlas:engineering:knowledge docs-health --json` e
anexar contagem inicial na secao Evidencias. Confirmar lista "Nunca
Remover" intacta. **Nao alterar nenhuma doc.**

### P1 — Status Invertido (flip future/draft -> active)

Pre-condicoes: evidencia em codigo verificada (servico+teste OU
migration+CLI vivo); doc-alvo passa em docs-health antes do flip; flip
toca **apenas** `status:` e `graph_status:`.

Lista P1 (1 AP por flip): Programming Governance trio (system,
runbook, contracts), Forge OS trio (system, contracts, runbook), Dev
efficient v1 (`atlas-dev-efficient-programming-flow-v1.md` e
`atlas-dev-flow-map-and-product-options-v1.md`). Total: 8 flips.

Para `draft`: como `draft` nao e admissivel pelo schema, o flip alvo
e direto `active` (com evidencia) — nao passa por `building`
intermediario.

Gates por flip: `php artisan atlas:engineering:knowledge docs-health
--json`, `git diff --check`, entrada na tabela Evidencias.

### P2 — Auditar Candidatos a Superseded

Para cada doc do Grupo 6: cross-check contrato v1 vs canon vigente. Se
sucessor confirmado, flip para `deprecated` + `summary` cita sucessor +
`flows_to` aponta para sucessor. Se nao confirmado, manter `future`.
Gates = P1.

### P3 — Auditar Unknown

Para cada doc do Grupo 8: verificar admissibilidade do valor; se nao
admissivel, colapsar para um dos 5 canonicos com motivo registrado.
Atualizar grandfathered list em `EngineeringDocumentationHealthService.php`
apenas se justificavel. Gates = P1.

## Regras para IA

1. Nunca promover para `active` sem evidencia (servico+teste OU
   migration+CLI vivo) citada no AP.
2. Nunca marcar doc da lista "Nunca Remover" como `deprecated`.
3. Nunca tocar codigo no mesmo AP do flip. Frontmatter-only.
4. Nunca usar `superseded`/`historical`/`unknown` como valor de
   `status:` — schema nao admite. Colapse para `deprecated` ou audite.
5. Sempre rodar docs-health apos flip e anexar saida na tabela
   Evidencias.
6. No flip para `deprecated`, atualizar `summary:` + `flows_to:` para
   citar sucessor.
7. Doc `oversized` (`split_required`) — flip nao corrige; manter
   grandfathered ate split AP.
8. Um flip por AP. Audit trail individual.

## Escopo de Implementacao

Cobre **classificacao + sequencia**. NAO cobre: rename de docs (cluster
naming — inventario), consolidacao de services (overlap — inventario),
move de docs, alteracao de codigo, contratos novos, ou reorganizacao
ampla do grafo `flows_to:`/`depends_on:` alem do necessario para o flip.
Todo o resto = AP separado.

## Dependencias

- **Inventario** `atlas-canonical-cleanup-inventory.md` — diagnostico
  filesystem; este plano cita, nao duplica.
- **Audits** `atlas-architecture-critical-judgment-report.md` +
  `atlas-dev-forge-relationship-critical-audit.md` — fonte das
  divergencias status-vs-codigo.
- **Validator** `EngineeringDocumentationHealthService.php` — fonte dos
  5 status canonicos admissiveis.
- **Index** `atlas-ai-canonical-architecture-index.md` — estrutura de
  Layer 0..N.

## Evidencias

Baseline P0 anexa saida JSON de `docs-health` com `violation_count`,
`canonical_violation_count`, `oversized_count`. Tabela de flips:

| Data | Flip | docs-health antes | depois | AP/PR |
|---|---|---|---|---|
| 2026-05-18 | P0 baseline | — | status=ok, 0 canon/frontmatter/required-missing violations, 15 oversized, 623 docs | — |
| 2026-05-18 | `atlas-forge-operating-system.md` future→active (status + graph_status) | ok | ok | Claude 2 — Safe Doc Cleanup |
| 2026-05-18 | `atlas-forge-operating-system-contracts.md` future→active (status + graph_status) | ok | ok | Claude 2 — Safe Doc Cleanup |
| 2026-05-18 | `atlas-forge-operating-system-runbook.md` future→active (status + graph_status) | ok | ok | Claude 2 — Safe Doc Cleanup |
| 2026-05-18 | `atlas-programming-governance-system.md` building→active (status + graph_status) | ok | ok | Claude 2 — Safe Doc Cleanup |
| 2026-05-18 | `atlas-programming-governance-system-runbook.md` building→active (status + graph_status) | ok | ok | Claude 2 — Safe Doc Cleanup |
| 2026-05-18 | `atlas-programming-governance-system-contracts.md` future→active (status + graph_status) | ok | ok | Claude 2 — Safe Doc Cleanup |
| 2026-05-18 | `atlas-dev-efficient-programming-flow-v1.md` draft→active (status only; graph_status was already active) | ok | ok | Claude 2 — Safe Doc Cleanup |
| 2026-05-18 | `atlas-dev-flow-map-and-product-options-v1.md` draft→active (status only; graph_status was already active) | ok | ok | Claude 2 — Safe Doc Cleanup |

## Riscos

| Risco | Severidade | Mitigacao |
|---|---|---|
| Flip P1 promove para `active` sem evidencia | alto | Pre-condicao obrigatoria + evidencia citada no AP |
| Marcar visao futura legitima como `deprecated` | alto | Lista "Nunca Remover" + review humano |
| Alterar codigo no mesmo AP do flip | medio | Regra dura frontmatter-only |
| docs-health quebra apos flip | medio | Gate obrigatorio antes de merge |
| P2 marca superseded sem confirmar sucessor | medio | Pre-condicao "sucessor canonico identificado" |
| Doc grandfathered acumula divida | baixo | Grandfather list em `EngineeringDocumentationHealthService.php` |

## Exemplos

### Exemplo 1 — Flip P1 (Programming Governance System)

```diff
# atlas-programming-governance-system.md
- status: building
+ status: active
- graph_status: building
+ graph_status: active
```

Evidencia: tabelas `atlas_programming_work_items/gate_runs/reviews`,
CLI `atlas:programming:*` em uso, doc 2026-05-13.

### Exemplo 2 — Flip P2 (Router Flow Routing Contract v1)

```diff
# atlas-ai-router-flow-routing-contract-v1.md
- status: future
+ status: deprecated
- summary: Contrato v1 de flow routing.
+ summary: Superseded por Meta 6 RouterRuntime. Mantido para historia.
- flows_to: [atlas-ai-router-flow]
+ flows_to: [atlas-ai-router-runtime-enterprise-upgrade]
- graph_status: future
+ graph_status: deprecated
```

Pre-condicao: sucessor canonico identificado
(`atlas-ai-router-runtime-enterprise-upgrade.md` + `app/Services/Ai/RouterRuntime/`).

### Exemplo 3 — Audit P3 (`status: scaffold`)

```diff
# atlas-ai-cyber-security-extension.md
- status: scaffold
+ status: building
```

Evidencia parcial: 8 tables + 8 models + 13 services Cyber Security
entregues; refusal matrix + 5 bug-bounty gates em producao. Nao vai
direto para `active` porque scaffold doc ainda nao re-alinhada com
runtime entregue.

## Proximas Acoes

1. Operador roda P0 (baseline docs-health) e anexa saida.
2. Operador escolhe **1** flip P1 (recomendado:
   `atlas-programming-governance-system.md` `building` -> `active`).
3. Apos green dos gates, anexar entry na tabela Evidencias.
4. Repetir flip por flip ate completar P1 (8 flips totais).
5. So entao iniciar P2 (audits de sucessor canonico).
6. P3 (audit Unknown) e ultimo — depende de termos rebaixado divergencia total para perto de zero.

Cada flip executado deixa rastro: data + linha de diff + saida de docs-health. Nada de massa, nada de codigo, nada de deletar.
