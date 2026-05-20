---
id: atlas-dev-efficient-programming-flow-v1-part-02
type: engineering_knowledge
title: Atlas Dev Efficient Programming Flow v1 · Parte 2
status: active
category: programming
priority: 105
summary: Recorte focado de Atlas Dev Efficient Programming Flow v1: 9. State Machine ate 18. Scope Guard.
tags:
  - atlas-dev
  - efficient-programming-flow
  - split-doc
  - cartography-readable
capabilities:
  - atlas_dev_efficient_programming_flow
  - atlas_documentation_split
decisions:
  - Este recorte preserva uma parte normativa do Atlas Dev Efficient Programming Flow sem ampliar responsabilidade do índice canônico.
maintenance:
  - Manter abaixo de 520 linhas.
  - Atualizar junto com docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md quando o contrato alto-nivel mudar.
related_paths:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1-contracts-v1.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1-runbook-v1.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-dev-efficient-programming-flow-v1-part-02
graph_title: Atlas Dev Efficient Programming Flow v1 Parte 2
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-dev-efficient-programming-flow-v1
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1-part-02.md
allowed_changes:
  - Atualizar somente a parte descrita neste recorte.
forbidden_changes:
  - Misturar este recorte com contracts detalhados, runbook executável, benchmark, Rivals ou Forge production.
depends_on:
  - atlas-dev-efficient-programming-flow-v1
flows_to:
  - atlas-dev-efficient-programming-flow-v1
unlocks:
  - atlas_cartography_readable_documentation
governs:
  - atlas_dev.efficient_programming_flow
evidence:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Manter este recorte alinhado ao índice canônico e ao contrato de documentação.
---
# Atlas Dev Efficient Programming Flow v1 · Parte 2

## Resumo

Este recorte preserva uma parte focada de Atlas Dev Efficient Programming Flow v1: 9. State Machine ate 18. Scope Guard.

## Papel no Atlas

Mantém uma fatia normativa do fluxo de programação diária do Atlas Dev fora do índice principal para que a cartografia continue legível.

## Onde Se Encaixa

É filho canônico de `docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md` e deve ser lido quando a pessoa precisar do detalhe desta parte do fluxo.

## Contratos

Segue o documento dono, o glossário canônico, o contracts doc e o modelo obrigatório de documentação do Atlas.

## Fluxo

Índice canônico → recorte focado → contrato, decisão ou regra operacional correspondente.

## Regras para IA

Não transformar este recorte em benchmark, Rivals, Forge production ou implementação sem evidence. Não misturar patamar, versão, fonte, risco, regra ou prova.

## Escopo de Implementacao

Este arquivo só guarda o detalhe extraído do documento maior.

## Dependencias

Depende do índice `atlas-dev-efficient-programming-flow-v1`, do contracts doc e do runbook.

## Evidencias

A evidência de origem é o documento principal e o `docs-health` verde depois da divisão.

## Riscos

Risco principal: alguém confundir contrato alto-nível com schema detalhado, runbook executável ou benchmark competitivo.

## Exemplos

Os exemplos abaixo são o conteúdo extraído, preservado sem perda semântica.

## Proximas Acoes

Atualizar este recorte quando a parte correspondente mudar e rodar docs-health.

## Conteudo Extraido
## 9. State Machine

```text
idle
-> intake_received
-> preflight_ready | blocked_no_workspace | blocked_permissions
-> classified
-> context_budgeted
-> context_selected
-> code_discovery_ready
-> compact_sdd_ready | structural_spec_required
-> mini_spec_ready
-> task_contract_ready
-> prompt_projected
-> route_decided
   -> read_only_answering
   -> fast_path_planning
   -> forge_promotion_preview
-> provider_selected
-> executing
-> patch_projected | no_patch_needed
-> scope_guarding
-> verifying
   -> passed                 -> completed
   -> needs_review           -> completed_with_risk | repair_planned | forge_promotion_preview
   -> failed                 -> repair_planned | forge_promotion_preview | failed_closed
-> repair_executing
-> verifying (loop limitado)
-> escalate_forge
   -> promotion_preview_created
   -> awaiting_human_decision
-> telemetry_emitted
```

Estados finais:

- `completed`;
- `completed_with_risk`;
- `needs_human_review`;
- `failed_closed`;
- `promotion_preview_created`;
- `blocked`.

Estados de gate:

- `passed`;
- `failed`;
- `needs_review`;
- `skipped`;
- `waived`.

## 10. Classificacao De Tarefa

| `task_kind` | Exemplos | Modo inicial |
| --- | --- | --- |
| `question` | explicar fluxo, localizar arquivo, ler diff | read-only |
| `patch` | bug pequeno, ajuste local | fast path |
| `repair` | teste/gate falhando | fast path repair |
| `review` | revisar diff/codigo | read-only ou fast path |
| `frontend` | tela, screenshot, UI pontual | fast path com visual gate |
| `risky` | auth, billing, migration, prod, security | plan-only + Forge preview |

## 11. Risk Levels R0-R5

| Risco | Exemplos | Gates minimos | Tentativas | Estado maximo sem Forge |
| --- | --- | --- | --- | --- |
| R0 | pergunta/read-only | context refs, no-patch receipt | 1 call, 0 repair | `passed` ou `needs_review` |
| R1 | typo, docs, 1 arquivo reversivel | git status, scope guard, diff receipt | 1 call, 0-1 repair | `passed` |
| R2 | bug pequeno 1-2 arquivos | R1 + teste focado se codigo | 1 call + 1 repair | `passed` ou `failed` |
| R3 | 3-5 arquivos, frontend, refactor leve | R2 + lint/typecheck barato | 1 call + ate 2 repairs | `passed` ou `needs_review` |
| R4 | auth, billing, migration, db/API/UI, >5-6 arquivos | plan-only, risk receipt, rollback | 0 patch Dev por default | `escalate_forge` ou `needs_human_review` |
| R5 | multiagente, replay, auditoria, longa duracao | Forge/Obra | Forge policy | Forge-only |

R-level e **sinal tecnico de risco**, nao a unica fronteira de produto. Atlas Dev cobre o trabalho diario do programador, inclusive pesquisa, debug, review e refactors pontuais que podem parecer tecnicamente complexos. Forge e acionado primariamente por **Obra declarada** ou por sinais fortes de Obra.

Regras:

- R0-R3 sao territorio nativo do Atlas Dev.
- R4 sem Obra pode permanecer em Atlas Dev como plan/review/debug ou patch excepcional pequeno, reversivel e confirmado pelo operador.
- R4/R5 com Obra declarada, duracao longa, multiagente, auditoria pesada ou release gate viram Forge.
- R5 e Forge-only.
- Nenhum R-level permite burlar mini-spec, task contract, scope guard, verification e receipt.

## 12. Artefatos (Visao Geral)

Os artefatos canonicos operacionais sao **17**, agrupados em quatro camadas. Schemas detalhados, invariants, exemplos e signatures PHP DTO vivem em `atlas-dev-efficient-programming-flow-contracts-v1.md`.

### 12.1 Camada Plano (o que vamos fazer)

| Artefato | Funcao |
| --- | --- |
| `OperationEnvelope` | entrada normalizada do intake (surface, workspace, intent, constraints, preflight) |
| `CompactSDD` | classificacao + risco + scope mode + context budget + hashes |
| `MiniProgrammingSpec` | behavior contract (goal, assumptions, expected files, acceptance, rollback) — **obrigatorio para todo write** |
| `LightTaskContract` | execution contract (tools allowed, files allowed/forbidden, validation commands, repair policy, escalation triggers, provider lock) |

### 12.2 Camada Contexto (o que sabemos)

| Artefato | Funcao |
| --- | --- |
| `ContextRetrievalPlan` | tier selection + budget + missing sources (output do `DocContextTierSelector`) |
| `CodeDiscoveryManifest` | likely files/symbols/tests com confidence levels (`confirmed_fact`, `strong_inference`, `hypothesis`, `blocking_ambiguity`) |
| `OpenBrainProgrammingProjection` | projection compacta do Open Brain (schema `atlas.open_brain.programming_projection.v1`), refs preferidas a texto |
| `ProviderPromptProjection` | prompt deterministico projetado dos artefatos anteriores; nasce do contrato, nao improvisa |

### 12.3 Camada Receipt (o que aconteceu)

| Artefato | Funcao |
| --- | --- |
| `ProviderCallResult` | receipt seguro da chamada ao provider (provider/model, exit, duration, hashes e erros; sem stdout/stderr cru em surface) |
| `DiffParseResult` | resultado deterministico do parser de diff (`patch`, `no_patch_needed`, `blocked`, `invalid`) antes de aplicar patch |
| `PatchApplyResult` | receipt da aplicacao do patch no workspace antes de scope guard e verification |
| `ScopeGuardReceipt` | diff vs `LightTaskContract.allowed_files` + watched/forbidden |
| `VerificationReceipt` | gates + tests + cost + completion + escalation (schema `atlas.dev.verification_receipt.v1`) |
| `SeniorEngineerLoopAudit` | audit plan-time de ambiguidade, plano multi-step, architecture-aware editing, cockpit, learning handoff e hardening |
| `SeniorEngineerLoopExecution` | receipt operacional pós-Run/worker para execução, debug loop, verification e handoff para curator/error ledger |
| `FailureCapsule` | input determinístico para repair (gate, command, exit_code, primary_error_excerpt, failure_signature, decision) |
| `EscalationDecision` | quando vira Forge (target, reasons, signals, score) |

### 12.4 Camada Telemetria (como aconteceu)

| Artefato | Funcao |
| --- | --- |
| `FastPathTelemetry` | sinal operacional emitido uma vez por run, mesmo em failed/blocked |
| `FastPathErrorLedgerEntry` | registro append-only de falhas operacionais e missed escalations (alimenta tuning futuro) |

## 13. Contratos De Qualidade Do Runtime

Contratos adicionais obrigatorios para construir o Atlas Dev:

- `ProviderPromptProjectionContract`;
- `FastPathTelemetrySchema`;
- `FastPathErrorLedger`;
- `CertInvariantRegistry`.

Contratos de benchmark como `PrivateOracleContract`, `DifficultyClassifierContract`, `NeedsReviewScoringPolicy` e `CleanVsMessyClassifierContract` ficam explicitamente diferidos para outro fluxo. Eles nao bloqueiam a Fatia 0 de construcao do runtime.

## 14. Invariantes De Cert

Cada item novo do fast path precisa de cert antes de entrar no fluxo real:

| Item | Cert minimo |
| --- | --- |
| `fast_path_contract` | estados, transicoes, invariants e forbidden paths testados |
| `compact_sdd_schema` | schema exige acceptance, evidence e escalation triggers |
| `context_budget_policy` | budget, truncation e missing required source testados |
| `adaptive_gate_policy` | gates nao-negociaveis e gates por risco testados |
| `forge_escalation_thresholds` | thresholds derivados de sinais observaveis |
| `provider_prompt_projection` | prompt gerado de contratos, sem campos obrigatorios ausentes |
| `verification_receipt` | evidence minima, gates e completion state testados |
| `senior_engineer_loop` | audit e execução operational provam Plan -> Run -> gates -> learning handoff sem auto-aplicar |
| `fast_path_error_ledger` | falha, missed escalation e aprendizado operacional registrados |

Gates **nao-negociaveis** para qualquer write:

- `mini_spec_before_code_gate`;
- `light_task_contract_gate`;
- `scope_guard_light`;
- `verification_gate`;
- `receipt_gate`;
- `completion_state_gate`;
- `forge_escalation_gate`.

Adaptive gate pode adicionar ou endurecer gate. **Nao pode remover** esses gates para tarefa com write.

## 15. Context Strategy (Alto Nivel)

Contexto e **selecao deterministica, nao dump**.

Todo item de contexto carrega: `reason`, `source`, `hash`, `freshness`, `provider_safe`, `budget_cost`.

Refs sao preferidas a texto bruto. Excerpts entram apenas quando a confianca exige.

### 15.1 Doc Context Tiers

| Tier | Quando carregar | Forma no prompt |
| --- | --- | --- |
| `core` | sempre em programming estrutural | projecao compacta de Atlas Dev, Open Brain, retrieval, Governance |
| `code_intelligence` | sempre que houver workspace/codigo | `code_refs`, simbolos, rotas, comandos, testes |
| `sdd` | patch, ambiguidade, multi-arquivo, risco medio | `compact_sdd`, task contract, gates |
| `interface` | UI, Desktop/App, Atlas Code, screenshot | regras de surface e verificacao visual |
| `forge` | risco alto, >5-6 arquivos, security/migration/billing/auth | preview de escalada, nao full Forge |
| `obras` | sessao longa, handoff, Obra/Workspace | refs persistentes de workspace/evidence |

### 15.2 Context Budget (Chars)

| Modo | Budget Open Brain |
| --- | ---: |
| pergunta/read-only | 4k-6k |
| bug pequeno 1-2 arquivos | 8k-12k |
| patch medio/review/debug | 12k-20k |
| frontend visual | 16k-24k |
| Forge preview | 20k-32k |
| full Forge/Obra | fora do fast path |

Se exceder o budget:

- preservar `core + code_intelligence`;
- cortar refs de menor prioridade;
- marcar `truncated=true`;
- registrar `missing_sources`;
- escalar se fonte obrigatoria nao couber.

Unidade do budget e **chars** (decisao locked 2026-05-16).

### 15.3 Retrieval Barato

```text
intent
-> risk
-> tier selector
-> cheap lexical/code discovery
-> compact Open Brain
-> provider call
-> focused gates
-> repair capsule
```

Ordem operacional:

1. metadata da task, workspace, git root, thread/resume;
2. Knowledge DB por docs canonicos e tiers;
3. Code Intelligence por modulo/simbolo/rota/comando/teste/doc link;
4. `rg` lexical para confirmar paths reais;
5. AST/ctags/tree-sitter apenas quando custo compensa;
6. excerpts so para top candidates ou baixa confianca.

Vector search pode sugerir candidatos, mas **nao bypassa** ranking, freshness, privacidade, escopo ou contradicao.

## 16. Provider Policy

Default do fast path:

- provider: `claude_cli`;
- model: Sonnet configurado;
- **uma** chamada principal;
- ate **uma** tentativa de repair quando ha gate deterministico (mais por R-level);
- mesmo provider/model no repair (sem fallback escondido);
- sem council;
- sem fallback automatico para Forge;
- `gemini_cli` proibido em write;
- override manual respeitado mas registrado em `decision_mode=manual_override`.

Provider lock e enforced no `LightTaskContract`. Receipt registra provider/model utilizado.

Variantes futuras (fora do escopo desta fase):

- `atlas_dev_codex` pode reutilizar o mesmo contrato;
- Atlas Decide pode escolher Sonnet/Codex depois que o contrato estiver estavel;
- execucoes governadas devem registrar provider/model sem fallback oculto.

## 17. Gates Adaptativos

| Gate | Bloqueia | Funcao |
| --- | --- | --- |
| `intake_risk_gate` | sim | classifica kind, risco e permissao de write |
| `context_budget_gate` | sim | corta contexto ou escala se obrigatorio faltar |
| `mini_spec_before_code_gate` | sim para write | exige mini-spec antes de patch |
| `light_task_contract_gate` | sim para write | exige files, teste, rollback e evidence |
| `scope_guard_light` | sim | diff so pode tocar escopo permitido |
| `verification_gate` | sim | roda teste/comando proporcional ou no-test reason |
| `receipt_gate` | sim | completion exige evidence verificavel |
| `completion_state_gate` | sim | impede sucesso narrativo |
| `forge_escalation_gate` | sim | para quando virou Forge |

Adaptive significa **proporcional ao risco**, nao "skip por preguica". Cada gate define quando se aplica em funcao do R-level; nenhum gate e opcional dentro do seu R-level.

### 17.1 Mapeamento Programming Governance Para Fast Path

Atlas Dev nao cria um segundo sistema de governanca. Ele e a **fast lane governada** do Atlas Programming Governance System.

Cada gate do Atlas Dev e uma destas duas coisas:

1. projecao compacta de um gate universal de Programming Governance;
2. gate Dev-only necessario para operar o fast path.

Gates Dev-only existem porque o fast path precisa de contratos operacionais que o runner universal nao modela diretamente. Eles podem adicionar controle; nao podem relaxar ou contradizer uma lei do Governance.

Projecoes compactas:

| Atlas Dev gate | Projecao de Programming Governance | Regra |
| --- | --- | --- |
| `intake_risk_gate` | `ProgrammingPlacementGate` | decide se a tarefa pertence ao fast path, read-only ou Forge preview |
| `context_budget_gate` | `ProgrammingCodeIntelligenceGate` | seleciona contexto real, aplica budget e bloqueia se fonte obrigatoria faltar |
| `mini_spec_before_code_gate` | `ProgrammingSpecBeforeCodeGate` | exige spec compacta antes de qualquer write |
| `scope_guard_light` | `ProgrammingScopeGuardGate` | diff so passa dentro do contrato de escopo |
| `receipt_gate` | `ProgrammingEvidenceGate` | completion exige receipt persistido e evidence refs |
| `completion_state_gate` | `ProgrammingCompletionGate` | impede sucesso narrativo sem gates verdes |

Gates Dev-only justificados:

| Atlas Dev gate | Por que existe | Invariante |
| --- | --- | --- |
| `light_task_contract_gate` | O fast path precisa de allowed/forbidden files, commands, rollback, repair policy e provider lock antes da chamada | nao pode permitir write sem spec, escopo e evidence |
| `verification_gate` | O fast path precisa executar comandos focados e gerar status verificavel antes de completion | nao pode converter `unverified` em `passed` |
| `forge_escalation_gate` | O fast path precisa parar R4/R5 antes de patch e gerar preview humano | nao pode criar Obra automaticamente |

Invariant de revisao: todo gate Atlas Dev deve ser (a) projecao de Governance ou (b) Dev-only documentado aqui. Nenhum gate Dev pode remover placement, spec-before-code, scope guard, evidence ou completion.

## 18. Scope Guard

O contrato de escopo nasce **antes** da chamada ao provider.

Campos minimos (schema completo no contracts doc):

- `allowed_files`;
- `allowed_paths`;
- `watched_files`;
- `forbidden_files`;
- `expected_max_files`;
- baseline de `git status`;
- baseline de `git diff`.

Regras:

- tocar arquivo proibido bloqueia completion;
- tocar arquivo nao previsto mas defensavel vira `needs_review`;
- tocar mais de 5-6 arquivos ou 3+ camadas tende a `escalate_forge`;
- mudancas pre-existentes do usuario sao marcadas no receipt;
- scope expansion exige novo receipt/proposta, nao tag de "ajustei mais".
