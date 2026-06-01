---
id: atlas-dev-efficient-programming-flow-runbook-v1-part-01
type: engineering_knowledge
title: Atlas Dev Efficient Programming Flow Runbook v1 · Parte 1
status: active
category: programming
priority: 104
summary: Recorte focado do runbook Atlas Dev Efficient Programming Flow v1: 1. Resumo ate 6.1 Objetivo.
tags:
  - atlas-dev
  - efficient-programming-flow
  - runbook
  - split-doc
capabilities:
  - atlas_dev_implementation_runbook
  - atlas_dev_efficient_programming_flow
decisions:
  - Este recorte preserva uma parte operacional do runbook sem ampliar responsabilidade do indice canônico.
maintenance:
  - Manter abaixo de 520 linhas.
  - Atualizar junto com docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1.md quando o runbook Atlas Dev mudar.
related_paths:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-dev-efficient-programming-flow-runbook-v1-part-01
graph_title: Atlas Dev Efficient Programming Flow Runbook v1 Parte 1
graph_world: atlas
graph_layer: module
graph_kind: runbook
graph_parent: atlas-dev-efficient-programming-flow-runbook-v1
graph_status: active
graph_source: repo
human_name: Atlas Dev Efficient Programming Flow Runbook v1 Parte 1
canonical_name: Atlas Dev Efficient Programming Flow Runbook v1 Parte 1
technical_name: atlas-dev-efficient-programming-flow-runbook-v1-part-01
cartography_type: runbook
canonical_source: docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-01.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-01.md
allowed_changes:
  - Atualizar somente a parte operacional descrita neste recorte.
forbidden_changes:
  - Adicionar nova responsabilidade que pertença ao índice ou a outro recorte.
depends_on:
  - atlas-dev-efficient-programming-flow-runbook-v1
flows_to:
  - atlas_dev_efficient_flow_runtime
unlocks:
  - atlas_dev_operational_execution
governs:
  - atlas_dev.implementation.slices
evidence:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1.md
evidence_refs:
  - symbol: AtlasDevEffProgFlowRunbookV1Part01Service
  - command: atlas:aaeos:atlas-dev-eff-prog-flow-runbook-v1-part01
  - test: AtlasDevEffProgFlowRunbookV1Part01Test
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: high
next_actions:
  - Manter este recorte alinhado ao índice canônico e ao runbook operacional.
---
# Atlas Dev Efficient Programming Flow Runbook v1 · Parte 1

## Resumo

Este recorte preserva uma parte operacional do runbook Atlas Dev Efficient Programming Flow v1: 1. Resumo ate 6.1 Objetivo.

## Papel no Atlas

Mantém o detalhe executável fora do índice principal para que a cartografia e o modal humano continuem legíveis.

## Onde Se Encaixa

É filho canônico de `docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1.md` e deve ser lido apenas quando a pessoa precisar do detalhe desta fatia.

## Contratos

Segue o contrato do runbook principal, o glossário canônico e o modelo obrigatório de documentação do Atlas.

## Fluxo

Índice canônico → recorte focado → execução ou revisão da fatia correspondente.

## Regras para IA

Não inferir responsabilidade nova. Não misturar patamar, versão, fonte, risco, regra ou prova. Preservar backlink para o índice.

## Escopo de Implementacao

Este arquivo só guarda o detalhe operacional extraído do runbook maior.

## Dependencias

Depende do índice `atlas-dev-efficient-programming-flow-runbook-v1` e da documentação canônica relacionada.

## Evidencias

A evidência de origem é o runbook principal e o `docs-health` verde depois da divisão.

## Riscos

Risco principal: alguém editar este recorte como se fosse novo dono de fluxo, duplicando contrato.

## Exemplos

Os exemplos abaixo são o conteúdo operacional extraído, preservado sem perda semântica.

## Proximas Acoes

Atualizar este recorte quando a fatia correspondente do Atlas Dev mudar e rodar docs-health.

## Conteudo Extraido
## 1. Resumo

Este runbook traduz o contrato canonico (`atlas-dev-efficient-programming-flow-v1.md`) e os schemas (`atlas-dev-efficient-programming-flow-contracts-v1.md`) em **sequencia executavel de fatias**. Cada fatia tem:

- objetivo concreto;
- arquivos a criar/editar com paths absolutos;
- signatures dos services/classes;
- ordem interna de implementacao (PRs sugeridos);
- testes obrigatorios;
- **DoD operacional** verificavel mecanicamente.

Ordem das fatias **e rigida**:

```text
Fatia 0  -> Schemas (DTOs read-only)
Fatia 1  -> Context Layer (Discovery + Tier Selector + Open Brain Projection)
Fatia 1.5 -> Runtime Quality Foundations (Prompt Projection + Telemetry + Error Ledger + Persistence)
Fatia 2  -> Plan-only Pipeline (CompactSDD + MiniSpec + LightTaskContract end-to-end, sem provider)
Fatia 3  -> One-call Sonnet + ScopeGuard + VerificationReceipt
Fatia 4  -> Repair Loop (FailureCapsule + retry policy)
Fatia 5  -> Atlas AI Desktop Mac wire-up + EscalationDecision + surface parity
```

Fatia nao comeca sem DoD anterior verde. Apos Fatia 5, esta equipe **encerra**. Medicao/Rivals e responsabilidade de outra equipe.

A surface de produto inicial e a aba `Atlas AI` do aplicativo Mac. O Atlas AI Router decide o fluxo antes do core. Quando o pedido cai neste contrato, o payload canonico usa `surface_id=atlas_desktop_ai`, `atlas_mode=programming`, `flow_id=atlas_dev`, `flow_origin=atlas_ai_router|direct`, `command_intent=fix|explain|research|test|refactor|review|debug|plan|conversation|null` e `programming_harness.workspace_required=true`.

Atlas Dev Efficient sucede o `programming.dev` classico apenas quando as flags canônicas em `config/atlas_dev.php` estiverem ligadas (`atlas_dev.efficient.plan_enabled=true` e/ou `atlas_dev.efficient.run_enabled=true`, env `ATLAS_DEV_EFFICIENT_PLAN_ENABLED` / `ATLAS_DEV_EFFICIENT_RUN_ENABLED`), workspace presente e surface suportada. Em produção, ambas iniciam `false`; ligar Plan primeiro, depois Run, conforme §15.1. Caso contrário, o fluxo `programming.dev` clássico continua.

### 1.1 Entrega Vertical Desktop-First

As fatias acima sao a ordem de dependencias de engenharia. A entrega de produto e vertical:

| Marco | Entrega | Fatias envolvidas | Valor visivel |
| --- | --- | --- | --- |
| 1 | Foundation backend | 0 + 1 + 1.5 | artefatos persistidos, sem UI |
| 2 | Plan-only no Atlas AI Desktop | 2 + adapter desktop plan | tabs Contexto/Plano preenchidas |
| 3 | One-call no Atlas AI Desktop | 3 + endpoint run + stream | diff/tests/receipt visiveis |
| 4 | Repair no Atlas AI Desktop | 4 + stream repair | attempts e failure capsule visiveis |
| 5 | Surface parity | 5 | CLI/App/API usam o mesmo core |

Desktop-first nao muda a arquitetura. O core continua surface-agnostic: recebe `OperationEnvelope` e retorna `PlanOnlyResult|PatchResult`. Apenas `AtlasDev/Surface/` conhece Desktop, CLI, App ou API.

> **Framing canonico (nao confundir):** o produto e **Atlas AI** (a aba `Atlas AI` do desktop, futuras surfaces CLI/App/API). O **core** entregue por este runbook e **Atlas Dev**, fluxo especializado de desenvolvimento em workspace dentro do Atlas AI. Atlas Dev **nao** absorve responsabilidades do **Atlas AI Router** — quem decide se um pedido vai para Atlas Dev, Research, Explain, Debug, Review, Conversation ou Forge e o Router, antes do `OperationEnvelope`. O runbook entrega o fluxo workspace-bound; o Router e construido em outro contrato e nao deve aparecer como dependencia de implementacao das Fatias 0-5.

## 2. Pre-Requisitos Globais

Antes de qualquer PR:

1. PHP 8.4+ via Homebrew (`/opt/homebrew/bin/php`) ativo em `atlas-server`.
2. `composer install` rodado.
3. Banco de dev resetado se houver migration pendente (`php artisan migrate:fresh` quando autorizado).
4. `git status` limpo no `atlas-server/` ou worktree dedicada.
5. Acesso de leitura/escrita em `atlas-server/storage/atlas-dev/`.
6. Branch atual identificada e protegida (PR contra `main`).

## 3. Reuso Obrigatorio (Mapa)

Os services abaixo **ja existem** e devem ser **evoluidos**, nunca duplicados.

| Service / artefato existente | Path | Como sera reusado |
| --- | --- | --- |
| `AtlasCliDevWorkflowService` | `app/Services/Ai/Cli/AtlasCliDevWorkflowService.php` | Driver principal do fluxo. Recebera as fases novas em metodos compostos. (Decisao locked.) |
| `AtlasProgrammingOrchestrator` | `app/Services/Ai/Programming/AtlasProgrammingOrchestrator.php` | `sessionPlan()` continua montando `programming_orchestration_contract`. Novas fatias plugam pre/pos. |
| `KernelPipelineDevPlanBuilder` | `app/Services/Ai/Programming/KernelPipelineDevPlanBuilder.php` | Base existente do pipeline. Novos artefatos (CompactSDD etc.) anexam ao `dev_execution_plan`. |
| `AtlasDevRuntimeService` | `app/Services/Ai/Programming/AtlasDevRuntimeService.php` | Continua aplicando runtime em payloads de surfaces. Ganha exposicao dos novos hashes. |
| `AtlasDesktopAiSurfaceAdapter` | `app/Services/Ai/Surface/Adapters/AtlasDesktopAiSurfaceAdapter.php` | Surface primaria do produto. Mapeia `atlas_desktop_ai` para flows de programacao e capacidades de contexto/workspace. |
| Atlas AI Desktop contract | `../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/contract.ts` | Payload canonico da aba Atlas AI. Deve passar apenas raw intent + modo/tarefa/workspace; nao injeta prompt artesanal. |
| Skill `dev-quality-gate` | `app/Skills/DevQualityGate/...` | Continua sendo gate pos-execucao. `verification_gate` do Atlas Dev se acopla a ela. |
| `repair_execution_contract` | gerado pelo orchestrator | `FailureCapsule` formaliza o que ja flui informalmente. |
| Open Brain context injection | services em `app/Services/Ai/OpenBrain/...` | `OpenBrainProgrammingProjection` consome a saida deles. |
| Code Intelligence | services em `app/Services/Ai/CodeIntelligence/...` | `CodeDiscoveryManifest` consome a saida deles. |

Adicoes **nao** podem:

- Substituir nenhum item acima.
- Criar `AtlasDevWorkflowServiceV2` ou similar.
- Introduzir um segundo driver com mesma funcao.

## 4. Estrutura De Pastas (Locked)

Todo codigo novo do Atlas Dev Efficient Flow vive sob:

```text
atlas-server/app/Services/Ai/Programming/AtlasDev/
  Schemas/                  # DTOs read-only (Fatia 0)
    Components/             # value objects internos
    Contracts/              # interfaces comuns
  Discovery/                # Code Discovery + Tier Selector + Open Brain projection (Fatia 1)
  PromptProjection/         # Provider prompt builder (Fatia 1.5)
  Telemetry/                # FastPathTelemetry emitter + ErrorLedger writer (Fatia 1.5)
  Persistence/              # Receipt writer/reader (Fatia 1.5)
  Pipeline/                 # Orchestrador de fases (Fatia 2)
  Provider/                 # Adaptador para claude_cli locked (Fatia 3)
  Gate/                     # ScopeGuard + VerificationGate + CompletionStateGate (Fatia 3)
  Repair/                   # FailureCapsule builder + RepairOrchestrator (Fatia 4)
  Escalation/               # EscalationDecisionEngine (Fatia 5)
  Surface/                  # Adapters thin; unica pasta autorizada a conhecer Desktop/CLI/App/API
```

Testes correspondentes:

```text
atlas-server/tests/Unit/Ai/Programming/AtlasDev/
  Schemas/
  Discovery/
  PromptProjection/
  Telemetry/
  Persistence/
  Pipeline/
  Gate/
  Repair/
  Escalation/
atlas-server/tests/Feature/AtlasDev/
  EndToEndPlanOnlyTest.php   # Fatia 2
  EndToEndOneCallTest.php    # Fatia 3
  EndToEndRepairTest.php     # Fatia 4
  EndToEndSurfaceTest.php    # Fatia 5
```

## 5. Padroes De Codigo

### 5.1 DTOs (Camada Schema)

- `final class`;
- `public readonly` em todos os campos;
- construtor recebe todos os campos;
- `schemaVersion(): string` constante;
- `toCanonicalArray(): array` com chaves ordenadas alfabeticamente recursivo;
- `toJson(): string` usa `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`;
- `hash(): string` `sha256` sobre `toJson()` sem o proprio campo de hash;
- **nao** valida invariants no construtor.

### 5.2 Validators

- Vivem em `Schemas/Validators/<Dto>Validator.php`.
- Recebem o DTO + contexto (DTOs upstream) e retornam `ValidationResult` (struct: `valid: bool`, `violations: list<string>`).
- Nao lancam excecao; retornam estrutura.
- Sao puros: mesma entrada = mesma saida.

### 5.3 Persisters

- Vivem em `Persistence/<Dto>Persister.php`.
- Metodos:
  - `persist(<Dto> $dto): string` retorna path absoluto;
  - `read(string $runId): <Dto>` recupera por run;
  - `exists(string $runId): bool`.
- Path: `storage/atlas-dev/receipts/<run_id>/<artifact>.json`.
- Pretty JSON (`JSON_PRETTY_PRINT`).
- Atomic write: tmpfile + rename.

### 5.4 Services Operacionais

- Constructor injection (Laravel container).
- Sem estado mutavel (services sao stateless; estado fica nos DTOs).
- Logs estruturados via `Log::channel('atlas_dev')` (canal novo).
- Excecoes domain-specific em `AtlasDev\Exceptions\` (ex: `BlockingAmbiguityException`, `ScopeViolationException`).

### 5.5 Testes

- `php artisan test --filter=<class>` deve passar isoladamente.
- Cada DTO tem: round-trip serializacao, hash determinismo, hash muda quando campo muda, hash ignora campo `<entity>_hash`.
- Cada validator tem: caso valido + N casos invalidos.
- Feature tests usam fixtures em `tests/Fixtures/AtlasDev/`.

---

## 6. Fatia 0 — Schemas (DTOs Read-Only)

### 6.1 Objetivo

Construir os **17 DTOs** read-only operacionais descritos no contracts doc, com:

- serializacao canonica;
- hashing determinístico;
- testes de invariants;
- zero acoplamento com runtime.

Saida: codigo PHP que serializa/deserializa schemas. Sem provider, sem pipeline.
