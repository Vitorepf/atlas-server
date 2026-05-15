---
id: atlas-programming-forge-flow
type: engineering_knowledge
title: Atlas Programming Forge Flow
status: active
category: programming-forge
priority: 100
summary: Mapa canonico do fluxo de programacao pesada do Atlas, unindo Programming Domain, programming.forge, Forge OS, Forge Workspace, Engineering Harness Runner, Agentic RAG, grafos, tools, repair, evidence, learning e cartografia.
tags:
  - atlas
  - programming
  - forge
  - heavy-programming
  - engineering-harness
  - agentic-rag
capabilities:
  - programming_forge_flow
  - heavy_programming_flow
  - forge_operating_system
  - forge_workspace
  - engineering_harness
  - programming_agentic_rag
  - semantic_code_graph
  - repair_loop
  - tool_runtime_gateway
decisions:
  - Atlas Forge Continuum OS e o nome canonico do sistema completo que amarra Atlas Code, Obra, Forge Workspace, Atlas Decide, provider topology, fallback, review, evidence, Rivals e learning.
  - Atlas Programming Forge Flow e o nome canonico do fluxo inteiro de programacao pesada.
  - Programming Domain e o setor/dominio de codigo; Atlas Code e surface; Atlas Code SCOR-1 e versao da surface.
  - Atlas Code SCOR-1 e surface Forge-only: toda intencao sai como `atlas_code` + `programming.forge`.
  - Obra e unidade produtiva obrigatoria do Forge; Atlas Code nao dispara Forge solto.
  - Forge Workspace e especializacao do Obras Shared Workspace para `programming.forge`.
  - programming.forge e o flow profile pesado dentro do Programming Domain.
  - Atlas Forge Operating System e a fabrica operacional que governa trabalho longo, multiagente ou multiprovider.
  - Forge Workspace e o ambiente/escritorio compartilhado de programacao dentro do Forge OS.
  - Engineering Harness Runner e o motor executor; ele nao substitui Forge OS, Forge Workspace, Agentic RAG, Tool Runtime ou Governance.
  - Graphs diferentes nao podem ser colapsados em um unico termo chamado graph.
  - Repair loop e parte estrutural do fluxo pesado, nao retry informal.
maintenance:
  - Atualize este doc antes de alterar Forge OS, programming.forge, Engineering Harness, repair loop, Agentic RAG, Semantic Code Graph, tools, Evidence ou cartografia de programacao pesada.
  - Mantenha este doc como pagina-mae; detalhes persistentes ficam nos docs filhos.
related_paths:
  - docs/engineering-knowledge-base/atlas-forge-continuum-os.md
  - docs/engineering-knowledge-base/atlas-code-programming-obras-operating-system.md
  - docs/engineering-knowledge-base/domains/programming.md
  - docs/engineering-knowledge-base/domains/programming-professional-rag-operating-standard.md
  - docs/engineering-knowledge-base/domains/programming-agentic-rag-professional-spec.md
  - docs/engineering-knowledge-base/domains/programming-repair-contract.md
  - docs/engineering-knowledge-base/atlas-programming-governance-system.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system-contracts.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system-runbook.md
  - docs/engineering-knowledge-base/atlas-forge-runtime-certification-one-shot.md
  - docs/engineering-knowledge-base/obras/shared-workspace-and-forge.md
  - docs/engineering-knowledge-base/engineering-blueprint.md
  - docs/engineering-knowledge-base/super-tool-runtime-core.md
  - docs/engineering-knowledge-base/code-intelligence.md
  - docs/engineering-knowledge-base/atlas-system-graph.md
  - docs/engineering-knowledge-base/atlas-cartographic-knowledge-os.md
  - app/Services/Ai/Programming/AtlasProgrammingOrchestrator.php
  - app/Services/Ai/Programming/ProgrammingGraphRagRuntime.php
  - app/Services/Ai/Programming/ProgrammingSemanticCodeGraphService.php
  - app/Services/Ai/Programming/ProgrammingRepairExecutor.php
  - app/Services/Engineering/EngineeringHarnessExecutionService.php
  - app/Services/Engineering/EngineeringHarnessRunnerService.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-programming-forge-flow
graph_title: Atlas Programming Forge Flow
graph_world: atlas
graph_layer: system
graph_kind: flow
graph_parent: atlas-ai-programming-domain
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-programming-forge-flow.md
allowed_changes:
  - Atualizar a taxonomia, ordem operacional, fronteiras e links quando o fluxo pesado de programacao mudar.
  - Promover termos somente quando o doc filho, codigo, evidencia e gates confirmarem a mudanca.
forbidden_changes:
  - Usar Forge Workspace, Engineering Harness Runner, Agentic RAG, Tool Runtime ou Atlas Code como sinonimos do fluxo inteiro.
  - Declarar Forge OS completo sem runtime, evidence ledger, testes, docs, cartografia e gates verdes.
  - Chamar qualquer grafo de autoridade operacional sem nomear qual grafo e qual camada decide.
depends_on:
  - atlas-forge-continuum-os
  - atlas-ai-programming-domain
  - atlas-programming-governance-system
  - atlas-forge-operating-system
  - engineering-blueprint
  - super-tool-runtime-core
  - code-intelligence
flows_to:
  - atlas-forge-operating-system
  - atlas-forge-operating-system-runbook
  - atlas-code
  - atlas-cartographic-knowledge-os
  - atlas-self-improvement-activation-cockpit-v1
  - atlas-self-improvement-closed-loop-level7-v1
unlocks:
  - heavy-programming-canonical-context
  - forge-documentation-alignment
  - programming-agentic-execution-map
governs:
  - programming.forge
  - forge-workspace
  - engineering-harness
  - programming-repair-loop
  - programming-agentic-rag
  - programming-graph-rag
  - tool-runtime-gateway
evidence:
  - docs/engineering-knowledge-base/atlas-programming-forge-flow.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
visual_tags:
  - forge
  - programming
  - flow
  - harness
  - graph
ai_entrypoints:
  - Leia este doc antes de explicar, alterar ou executar programacao pesada, Forge, atlas forge, multiagente, multiprovider, repair loop, Agentic RAG, graphs ou Engineering Harness.
ai_usage_notes:
  - Este doc e autoridade de taxonomia e fluxo. Ele nao prova que todos os modulos do Forge OS estao implementados.
  - Para runtime executavel, siga os docs filhos e a evidencia do codigo.
quality_gates:
  - programming-domain-selected
  - atlas-decide-receipt-present
  - agentic-rag-context-pack
  - semantic-code-graph-or-fallback
  - programming-governance-contract
  - forge-intake-decision
  - packet-contract-scoped
  - dependency-dag-valid
  - tool-runtime-evidence
  - harness-run-or-blocker
  - repair-loop-bounded
  - evidence-ledger-complete
  - learning-and-cartography-reviewed
failure_modes:
  - IA chama Atlas Code de setor de programacao.
  - IA chama Engineering Harness Runner de fluxo inteiro.
  - IA ignora repair loop, tools, graphs, Agentic RAG ou Evidence Ledger.
  - IA mistura System Graph, Semantic Code Graph, Dependency DAG e execution_graph.
  - Doc filho muda sem atualizar esta pagina-mae.
observability_signals:
  - engineering_run id
  - context_pack hash
  - decision_receipt id
  - stage_receipts
  - tool_run ids
  - evidence_refs
  - repair_decision
  - integration_queue status
  - cartography publication
next_actions:
  - Manter esta pagina sincronizada com Forge OS, Engineering Harness, Programming RAG e docs de cartografia.
---
# Atlas Programming Forge Flow

## Resumo

Este e o mapa canonico do fluxo de programacao pesada do Atlas.

Leia este documento quando aparecer qualquer um destes termos: `atlas forge`,
`programming.forge`, Forge OS, Forge Workspace, programacao pesada,
multiagente, multiprovider, Engineering Harness, repair loop, Agentic RAG,
Semantic Code Graph, graph RAG, tools, evidence ou cartografia de codigo.

## Papel no Atlas

```text
Programming Domain = setor de programacao.
programming.forge = flow pesado dentro do setor.
Obra = unidade produtiva / production graph.
Obras Shared Workspace = workspace persistente da Obra.
Atlas Forge Operating System = fabrica operacional.
Forge Workspace = especializacao de Obras Shared Workspace para programacao pesada.
Engineering Harness Runner = motor executor.
Atlas Code = surface desktop.
Atlas Code SCOR-1 = versao da surface desktop.
Atlas Code SCOR-1 mode = Forge only.
```

Nenhum desses nomes substitui os outros.

## Contratos

| Nome | Papel canonico | Nao e |
|---|---|---|
| Programming Domain | Dominio/setor de codigo do Atlas AI | Tela, harness ou produto separado |
| `programming.forge` | Flow profile para programacao pesada | Dominio paralelo |
| Obra | Unidade produtiva/production graph que ancora intencao, estado, artefatos, evidencia e entrega | Chat solto ou projeto decorativo |
| Obras Shared Workspace | Workspace persistente compartilhado dentro da Obra | Surface ou runtime executor |
| Atlas Forge Operating System | Sistema/fabrica operacional de software por IA | Apenas workspace ou executor |
| Forge Workspace | Especializacao de Obras Shared Workspace para `programming.forge` | O fluxo inteiro |
| Engineering Harness Runner | Executor que roda tentativas, patch, testes, artifacts e score | Forge OS inteiro |
| Super Tool Runtime | Camada governada de tools, runs, artifacts, findings e gates | Planejador ou decisor |
| Agentic RAG | Planejador/critic de busca e contexto | Memoria livre ou grafo visual |
| Semantic Code Graph | Grafo tecnico de arquivos, simbolos, deps, testes, docs e impacto | Cartografia visual |
| Programming Graph RAG Runtime | Runtime local read-only que combina graph traversal e rerank sem escrever | Cerebro paralelo |
| Kernel `execution_graph` | Plano de execucao do Atlas Decide | System Graph ou Code Graph |
| Dependency DAG | Grafo de dependencias entre packets Forge | Runtime graph global |
| System Graph / Cartography | Mapa visual/navegavel para humano e IA | Autoridade operacional primaria |
| Atlas Code | Surface desktop de programacao | Programming Domain |
| Atlas Code SCOR-1 | Primeira versao/categoria da surface desktop; modo unico Forge | Flow, harness ou Forge OS |

## Onde Se Encaixa

```text
Atlas AI
└─ Domain Plane
   └─ Programming Domain
      ├─ Surfaces: Atlas Code/SCOR-1 (`atlas_code` -> `programming.forge` only), atlas forge/dev/fix/continue, chat, API, MCP
      ├─ Obra: unidade produtiva obrigatoria (`obra_id`) com production graph
      ├─ Obras Shared Workspace: estado persistente, artefatos, fila, evidencia
      │  e colaboracao da Obra
      ├─ Flow: programming.forge
      ├─ Kernel Decision: Envelope, Intent, Business Context, Policy, Decide,
      │  execution_graph, Decision Receipt
      ├─ Intelligence: Memory/Open Brain, Engineering KB, Code Intelligence,
      │  Semantic Code Graph, Programming Graph RAG, Agentic RAG, gap critic,
      │  context pack, test impact, stage receipts
      ├─ Governance: placement, spec before code, task contract, allowed/forbidden
      │  files, acceptance, proportional gates, evidence, completion gate
      ├─ Atlas Forge Operating System: intake, constitution, mother spec, spec
      │  anchor, code loader, splitter, Dependency DAG, packet contract,
      │  reservation/scope, role/model/context router, permission, dry run,
      │  Tool Runtime Gateway, patch/diff guard, validator, verification, events,
      │  checkpoint/resume, branch/worktree/CI, review, rollback, evals, learning,
      │  cartography
      ├─ Forge Workspace: especializacao do Obras Shared Workspace com mother
      │  spec, packets, claims/reservations, provider context packs, artifact
      │  bus, integration queue, completion reports e normalized evidence
      ├─ Engineering Harness Runner: task contract, harnessability, worktree/docker,
      │  provider runtime, patch artifacts, tests, quality, visual smoke,
      │  API/security/SBOM gates, score, decision
      ├─ Repair Loop: failure packet, AtlasRepairOrchestrator, Agentic RAG,
      │  Semantic Code Graph, repair capsule, patch, retest, verifier, max attempts,
      │  no-progress blocker, human review/replan/escalation
      └─ Finalization: Evidence Ledger, Tool Evidence Store, Code Intelligence
         refresh, docs, System Graph/Cartography, Learning, Output
```

## Fluxo

```text
Surface (`atlas_code` / Atlas Code SCOR-1)
-> Obra selected/created (`obra_id`)
-> Obras Shared Workspace / Forge Workspace binding
-> Kernel Pipeline
-> Programming Domain
-> programming.forge
-> Atlas Decide / execution_graph / Decision Receipt
-> Agentic RAG
-> Semantic Code Graph / Programming Graph RAG
-> context pack provider-safe
-> Programming Governance
-> Forge Intake / WorkItem binding
-> Spec Compiler / Mother Spec / Spec delta
-> Plan Compiler / Task Compiler / Forge Task Queue
-> Code Intelligence Loader
-> Work Splitter
-> Dependency DAG
-> Packet Contract
-> Reservation / Scope Guard
-> Role + Model + Context Budget Router
-> Permission / Capability / Dry Run
-> Tool Runtime Gateway
-> Engineering Harness Runner
-> Patch / Tests / Gates / Score
-> Repair Loop quando blocked ou partial exigir reparo
-> Completion Evidence
-> Integration Queue
-> Review / Quality / CI
-> Rollback / Migration
-> Release Gate
-> Evidence Ledger
-> Learning
-> Code Intelligence refresh
-> Docs / System Graph / Cartography
-> Output
```

## Os Grafos

Nao diga apenas "o graph". Nomeie a camada.

| Grafo | Autoridade | Uso |
|---|---|---|
| Kernel `execution_graph` | Atlas Decide / Policy | Planeja provider, runtime, quality gates e ativacao de execucao |
| Semantic Code Graph | Programming / Code Intelligence | Liga arquivos, simbolos, imports, dependencias, testes, docs, owners e impacto |
| Programming Graph RAG Runtime | Programming RAG | Usa graph traversal mais rerank local para montar contexto provider-safe |
| Dependency DAG | Forge OS | Ordena packets por dependencia, prioridade, risco, custo e paralelismo |
| System Graph / Cartography | Cartographic Knowledge OS | Navegacao visual, estado, dependencias e evidence para humano/IA |

O System Graph nao substitui repo docs, codigo, Postgres, testes ou Evidence
Ledger. Ele torna a verdade navegavel.

## Tool Runtime E Harness

Tools nao decidem. Tools produzem evidence.

O caminho correto e:

```text
Capability / permission policy
-> Tool Runtime Gateway
-> tool run ou managed harness
-> normalized output
-> findings / artifacts / metrics / blocking failures
-> gate / release gate
-> Evidence Ledger
-> repair, review, curator ou release decision
```

O Engineering Harness Runner pode chamar qualidade, visual smoke, code
intelligence, API contract, security, SBOM, testes e patch artifacts. Mesmo
assim, o Harness nao decide sozinho o significado do resultado; Kernel, Policy,
Gates, Receipt e review governam a conclusao.

## Repair Loop Canonico

Repair pesado nunca e retry solto. O loop canonico e:

```text
failed gate / blocked run / partial run
-> failure packet
-> AtlasRepairOrchestrator decision
-> repair policy: max attempts, allowed strategies, evidence required
-> Agentic RAG busca docs, simbolos, testes, diffs e falhas parecidas
-> Semantic Code Graph aponta dependencias e teste de impacto
-> repair capsule
-> patch minimo
-> targeted retest
-> patch verifier
-> new evidence
-> resolved, partial, blocked_no_progress, blocked_max_attempts ou human review
```

Regras:

- mesma falha repetida sem progresso bloqueia;
- repair nao amplia escopo sem replan/review;
- high-risk repair exige evidencia e pode exigir humano;
- rerun usa o mesmo packet contract ou cria repair delta governado;
- toda tentativa deixa receipt e evidence refs.

## Escopo de Implementacao

Este doc e ativo como taxonomia e fluxo canonico. Ele nao declara que toda a
fabrica Forge esta pronta.

Estado resumido:

| Area | Estado |
|---|---|
| Programming Domain e `programming.*` | implemented/ready |
| AtlasProgrammingOrchestrator | implemented |
| Engineering Harness Runner task-level | implementado forte |
| Code Intelligence | implementado e indexavel |
| Super Tool Runtime foundation | implementado como camada transversal de evidence/tools |
| Programming Agentic RAG / Semantic Code Graph | em implementacao ativa com contratos profissionais |
| Forge OS multiagente completo | future/building |
| Forge Workspace persistente completo | building/future conforme modulo |
| Integration Queue / Release Gate Forge completo | future/building |

Quando houver conflito:

```text
Kernel contracts vencem execucao.
Programming Domain vence roteamento de codigo.
Este doc vence taxonomia do fluxo pesado.
Forge OS docs vencem contratos internos de fabrica.
Engineering Blueprint vence contrato tecnico de task/harness.
Tool Runtime vence contrato de tools/evidence.
Evidence Ledger vence claim sem prova.
System Graph ajuda navegacao, mas nao sobrescreve repo docs/codigo/testes.
```

## Dependencias

| Assunto | Documento |
|---|---|
| Dominio de programacao e flows | `domains/programming.md` |
| Agentic RAG, Semantic Code Graph, receipts, verifier, repair e learning | `domains/programming-professional-rag-operating-standard.md` |
| Repair Loop de Programming | `domains/programming-repair-contract.md` |
| Gates de programacao | `atlas-programming-governance-system.md` |
| Indice do Forge OS | `atlas-forge-operating-system.md` |
| Contratos persistentes do Forge | `atlas-forge-operating-system-contracts.md` |
| Runbook operacional do Forge | `atlas-forge-operating-system-runbook.md` |
| Certificacao one-shot do runtime Forge | `atlas-forge-runtime-certification-one-shot.md` |
| Forge Workspace / Obras Shared Workspace | `obras/shared-workspace-and-forge.md` |
| Engineering Blueprint e Harness Runner | `engineering-blueprint.md` |
| Tool Runtime | `super-tool-runtime-core.md` |
| Code Intelligence | `code-intelligence.md` |
| System Graph / Cartography | `atlas-system-graph.md` |

## Regras para IA

- Comece pelo `Programming Domain`; nao invente dominio paralelo de codigo.
- Use `programming.forge` quando complexidade, risco, duracao, multiagente,
  multiprovider, repair ou evidence forte exigirem; em Atlas Code SCOR-1, ele
  e tambem o unico flow permitido pela surface.
- Nao rode Forge sem Obra. `source_id` sozinho e compatibilidade; o payload
  canonico deve carregar `obra_id` e `forge_workspace.obra_id`.
- Antes de executar, gere contexto por Agentic RAG, Code Intelligence e graph
  traversal quando disponivel.
- Diga qual grafo esta usando: `execution_graph`, Semantic Code Graph,
  Programming Graph RAG, Dependency DAG ou System Graph.
- Nao chame `Forge Workspace` de fluxo inteiro.
- Nao chame `Engineering Harness Runner` de Forge OS.
- Nao chame `Atlas Code` de setor de programacao.
- Toda tool usada precisa virar evidence ou blocker explicito.
- Toda falha de gate vira failure packet, repair decision, replan ou escalation.
- Toda conclusao pesada precisa Evidence Ledger, learning e cartografia quando
  afetar arquitetura, docs ou mapa.

## Riscos

- Chamar Atlas Code de setor e perder o Domain Plane.
- Chamar Forge Workspace de fluxo inteiro e apagar Kernel/Governance/Harness.
- Chamar Engineering Harness Runner de Forge OS e perder packets/integration.
- Misturar execution_graph, Semantic Code Graph, Dependency DAG e System Graph.
- Fazer repair como retry solto sem failure packet, receipt ou blocker.

## Evidencias

Uma execucao Forge pesada precisa, quando aplicavel:

- operation envelope e decision receipt;
- `obra_id` e binding do Forge Workspace;
- `execution_graph`;
- context pack hash;
- retrieval receipt;
- Semantic Code Graph ou fallback declarado;
- task/packet contract;
- allowed/forbidden files;
- model/context budget decision;
- permission/capability decision;
- tool run ids;
- harness run id;
- patch artifact/diff;
- selected tests e test evidence;
- quality/security/visual/API/SBOM evidence quando exigido;
- repair decision e attempt receipts quando houver falha;
- completion evidence report;
- integration queue status;
- release gate result;
- Evidence Ledger refs;
- learning proposal;
- Code Intelligence refresh;
- docs/cartography update quando afetado.

## Exemplos

Pedido pequeno:

```text
programming.dev
-> governanca compacta
-> patch
-> teste proporcional
-> evidence final
```

Pedido pesado:

```text
programming.forge
-> Agentic RAG + Code Graph
-> Forge Intake
-> Mother Spec
-> packets + DAG
-> Harness/Tools
-> Repair Loop se necessario
-> Integration Queue
-> Release Gate
-> Evidence/Learning/Cartography
```

Erro comum:

```text
"Forge Workspace e o nome do fluxo pesado"
```

Correto:

```text
Forge Workspace e o ambiente compartilhado.
Atlas Programming Forge Flow e o fluxo inteiro.
Atlas Forge Operating System e a fabrica.
Engineering Harness Runner e o executor.
```

## Certificacao Runtime

Dois niveis de certificacao replayable, sem provider externo. **Obra e obrigatoria nos dois.**

- **Nivel 1** — contratos: `php artisan atlas:forge:runtime-certify --obra=<uuid> --json` (schema `atlas.forge_runtime_certification.v1`).
- **Nivel 2** — execucao real: `php artisan atlas:forge:live-execute --obra=<uuid> --json --strict` (schema `atlas.forge_live_execution_certification.v1`). Sem `--obra` em strict, o exit code e non-zero e nenhum sandbox e provisionado. Detalhes em `atlas-forge-live-execution-e2e-v1.md`.

`atlas:programming:completion-audit --json` retorna `forge_runtime_certification`, `forge_live_execution_certification`, `atlas_code_enterprise_certification`, `forge_fast_path_certification`, `forge_native_rivals_certification` e `external_rivals_certification` separados. Nivel 2/Fast Path distinguem `available`, `requires_operator_run` e `missing_artifacts` — nunca substituem Rivals nem liberam `completion_allowed`.

**Forge-Native Rivals (Atlas arm = Forge obrigatorio):** Rivals avalia o runtime Forge do Atlas contra um baseline externo isolado — runs Atlas non-Forge sao invalidos para score. Canon em `atlas-forge-native-rivals-protocol-v1.md`. Comandos: `atlas:programming:rivals-forge-preflight --json --strict` (read-only) e `atlas:programming:rivals-forge-dry-run --case=<id> --json --strict` (plano sem provider). `forge_native_rivals_certification` no completion audit fica separado de `external_rivals_certification`; nem preflight nem dry-run promovem claim. Avaliacao diagnostica de qualidade one-shot vive em `atlas-rivals-one-shot-enterprise-evaluation-v1.md` (`atlas:programming:rivals-one-shot-evaluate`).

### Forge Operator Fast Path v2

`php artisan atlas:code:forge-fast-path --obra=<uuid> --mode=execute_async --json --strict` (schema `atlas.code.forge_fast_path.v1`) dispara o run; cada Fast Path emite `fast_path_run_id` ULID com lifecycle: prepared → queued → running → passed → review_required → completed (ou degraded → repair → failed). `GET /atlas-code/works/{obra}/forge/fast-path/{run}/status` (schema `atlas.code.forge_fast_path_run_status.v1`) e `POST .../resume` reconstroem o estado real. Review e completion seguem o gate canonico (`atlas.code.forge_review_packet.v1` + `atlas.code.forge_completion_claim.v1`) via `GET .../review` + `POST .../review/{approve|reject|rollback}` e `atlas:code:forge-review`. Detalhes em `atlas-code-forge-fast-path-v1.md` e `atlas-code-forge-review-completion-gate-v1.md`.

`external_rivals_certification` expoe `blocked_until_invalid_battery_triaged`, `blocked_until_clean_worktree`, `ready_for_operator_paid_rerun` ou `claim_ready` com quarentena, preflight e policy de gasto provider.

Na surface Atlas Code, o operador vincula WorkItem, compila Spec/Plan/Tasks (`POST /atlas-code/works/{obra}/programming/work-items/{wi}/spec`) e roda `POST /atlas-code/works/{obra}/forge/live-executions`. Com task contract real, a execucao inclui `governed_execution`: patch dry-run em workspace sombra, diff artifact, validation command, promotion artifact, hardened receipt e `scope-guard` contra `allowed_files`. Aprovacao humana promove o patch para o workspace vivo somente se hash, scope e completion gate continuarem verdes; rollback restaura backup com drift/hash check, evidence propria, review `rolled_back` e state/history sincronizados.
## Provider Topology e Governed Fallback
Forge pesado nao escolhe provider por preferencia local: Atlas Decide emite Decision Receipt com a topologia (papeis + provider + modelo + fallback chain) e o runtime segue o contrato. Detalhes em `atlas-forge-provider-topology-and-fallback-v1.md`; sinais locais (capacity + failure memory) em `atlas-forge-provider-capacity-continuity-v1.md`; certificacao em `atlas_forge_continuum_certification` (schema `atlas.forge_continuum_certification.v1`). Fallback nunca silencioso, `provider_capacity_exhausted` e blocker honesto. Para closed-loop self-improvement → Obra Forge ver `atlas-self-improvement-forge-activation-v1.md`.
## Proximas Acoes
Manter como primeira leitura do Forge pesado e rodar docs-health/certificacoes apos mudancas em runtime/Atlas Code/review/promotion/rollback/checkpoint. Leitura humana canonica do fluxo: [[atlas-code-obra-command-center-v1]] (lifecycle 8 fases, decision inbox, operational health honesto, schema `atlas.code.obra_command_center.v1`; CLI `php artisan atlas:code:obra-command-center --json --strict`).
