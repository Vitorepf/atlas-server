---
id: atlas-dev-efficient-programming-flow-v1-part-03
type: engineering_knowledge
title: Atlas Dev Efficient Programming Flow v1 · Parte 3
status: active
category: programming
priority: 105
summary: Recorte focado de Atlas Dev Efficient Programming Flow v1: 19. Verification E Repair ate 26.3 Atlas Dev Como Template Para Outras Verticais.
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
graph_id: atlas-dev-efficient-programming-flow-v1-part-03
graph_title: Atlas Dev Efficient Programming Flow v1 Parte 3
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-dev-efficient-programming-flow-v1
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1-part-03.md
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
# Atlas Dev Efficient Programming Flow v1 · Parte 3

## Resumo

Este recorte preserva uma parte focada de Atlas Dev Efficient Programming Flow v1: 19. Verification E Repair ate 26.3 Atlas Dev Como Template Para Outras Verticais.

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
## 19. Verification E Repair

### 19.1 Completion States

| Estado | Significado | Sucesso cheio |
| --- | --- | --- |
| `passed` | gates verdes, scope ok, receipt presente, teste/motivo valido | sim |
| `needs_review` | patch plausivel, mas evidence incompleta ou risco residual | nao |
| `failed` | provider/comando/teste/gate falhou e repair nao resolveu | nao |
| `blocked` | workspace, permissao, ambiguidade ou decisao humana faltando | nao |
| `escalate_forge` | risco/complexidade/budget excedido | nao |
| `no_patch_needed` | read-only/review sem patch com refs suficientes | depende |

`unverified` **nunca** vira `passed`. Esta regra e enforced no `completion_state_gate`.

### 19.2 Repair Loop

```text
gate falha
-> FailureCapsule
-> mesma provider/model
-> menor patch possivel
-> rerun do gate/teste falho
-> receipt atualizado
-> passed | failed | needs_review | escalate_forge
```

Parar repair quando:

- mesma `failure_signature` falha duas vezes;
- diff cresce sem necessidade;
- surge escopo novo;
- teste falho pede arquitetura;
- contexto necessario excede budget;
- risco vira R4/R5.

Limites por R-level estao na tabela de Risk Levels (secao 11).

### 19.3 Verification Command Profiles

Tres perfis pre-cabeados (decisao locked 2026-05-16):

| Perfil | Lint | Test | Quando |
| --- | --- | --- | --- |
| `php_laravel` | `composer lint` ou `vendor/bin/pint --test` | `composer test -- --filter=<class>` | atlas-server |
| `ts_react` | `eslint <files>` + `tsc -b` | `pnpm test --filter=<glob>` | atlas-desktop, atlas-app |
| `generic_no_test` | `git diff --stat` | `no_test_reason` obrigatorio | docs, scripts, configs |

O `LightTaskContract` declara qual perfil aplicar. O `verification_gate` falha se o perfil exigir teste e nem teste nem `no_test_reason` aparecer.

## 20. Forge Escalation

Forge escalation tem dois gatilhos distintos.

### 20.1 Gatilho Primario: Obra Declarada

Se o operador declara Obra, ou se o trabalho claramente exige producao longa, multiagente, persistente e auditavel, Forge e o caminho primario. Isso inclui:

- implementacao prevista de semanas ou mes;
- multiplas frentes independentes;
- necessidade de handoff entre sessoes/operadores;
- review/replay/evidence pesada;
- release gate ou compliance forte;
- pedido explicito de Forge, Obra, RFC de arquitetura ou execucao automatizada longa.

Neste caso, Atlas Dev nao tenta "resolver grande". Ele monta contexto, plano, riscos, contrato inicial e **promotion preview** para Forge/Obra.

### 20.2 Gatilho Secundario: Complexidade Detectada

Escalar **antes** de patch quando houver:

- security, auth, billing, PII, compliance, production;
- migration, rollback, data loss;
- alteracao esperada >5-6 arquivos;
- 3+ camadas (db + API + UI, por exemplo);
- contexto >40k chars;
- thread >=24 mensagens;
- file breadth >=6;
- necessidade de replay, auditoria, review humano ou release gate.

Gerar `obra_candidate`/preview quando houver sinais medios:

- thread >=12 mensagens;
- contexto >=12k chars;
- file breadth >=3;
- falha recorrente >=2;
- escalation signal score >=4.

Escalation signal score >=7 recomenda `forge_obra`, mas **nao cria Obra automaticamente**. A criacao de Obra exige decisao humana, exceto quando a surface ja estiver dentro de uma Obra ativa e o operador tiver autorizado execucao Forge por contrato.

`EscalationDecision` registra: `target`, `reasons`, `signals`, `score`, `human_action_required`.

## 21. Evidence Minima Por Modo

| Modo | Evidence minima |
| --- | --- |
| read-only | docs/files lidos, resposta, limites de confianca, zero write |
| plan-only | `CompactSDD`, `MiniProgrammingSpec`, `LightTaskContract`, motivo de nao executar |
| patch | diff/hash, changed files, scope guard, teste/comando, output hash |
| repair | receipt da falha, `FailureCapsule`, patch pequeno, rerun do gate |
| frontend | patch + screenshot/verificacao visual ou `needs_review` |

## 22. Fora Do Escopo Desta Fase

Esta fase **nao** define nem implementa:

- Rivals;
- Opus challenge;
- benchmark competitivo;
- battery de prompts;
- oracle privado de avaliacao;
- score custo-normalizado;
- arms locais `sonnet_pure`/`opus_pure`;
- claim de vitoria.

Esses itens pertencem a outro Codex/Claude e a outro contrato. Aqui, o unico objetivo e construir o Atlas Dev robusto.

## 23. Quality Build Gates

Gates de qualidade para a **construcao** do Atlas Dev (engenharia, nao benchmark):

| Gate | Bloqueia | Evidencia |
| --- | --- | --- |
| `contract_schema_tests` | sim | schemas serializam, validam e rejeitam campos faltantes |
| `prompt_projection_tests` | sim | prompt contem objetivo, contrato, contexto, escopo, tests e stop conditions |
| `context_selection_tests` | sim | tiers corretos, paths existentes, missing refs honestos |
| `scope_guard_tests` | sim | diffs fora de escopo falham |
| `verification_receipt_tests` | sim | receipts persistem status, gates, testes e evidence |
| `repair_capsule_tests` | sim | repair usa erro real e nao amplia escopo |
| `escalation_policy_tests` | sim | R4/R5 nao fazem patch Dev |
| `no_rivals_leakage_tests` | sim | nenhum fluxo chama Rivals/arm/benchmark |
| `no_template_pollution_tests` | sim | doc principal nao pode ter template canonico duplicado antes das secoes numeradas |

Esses testes sao de **engenharia do runtime**, nao benchmark competitivo.

## 24. Implementacao (Sumario)

Sequencia operacional de fatias (detalhe em `atlas-dev-efficient-programming-flow-runbook-v1.md`):

| Fatia | Entrega | Valor user-facing |
| --- | --- | --- |
| 0 | Schemas (DTOs read-only dos 17 artefatos operacionais) + validators + testes de serializacao/hash | fundacao, sem efeito user-facing |
| 1 | `DocContextTierSelector` + `CodeDiscoveryEngine` + `OpenBrainProjectionAdapter` | Atlas passa a achar arquivos certos antes de chamar provider |
| 1.5 | `ProviderPromptBuilder` + `TelemetryEmitter` + `ErrorLedgerWriter` + `ReceiptStorage` | prompt nasce de contratos + persistencia + telemetria desde dia 1 |
| 2 | Plan-only pipeline end-to-end (Intake, Classifier, RiskScorer, SpecComposer, Orchestrator) + CLI `atlas:cli:dev --efficient` | operador ve plano curado antes de gastar token |
| 3 | One-call Sonnet via `claude_cli` + `ScopeGuard` + `VerificationGate` + `CompletionStateGate` + `VerificationReceipt` + comando `atlas:dev:run` | write real com evidence determinística |
| 4 | `FailureCapsuleBuilder` + `RepairOrchestrator` + limites por R-level | recovery loop completo |
| 5 | `EscalationDecisionEngine` + Atlas AI Desktop Mac first + Surface Adapters de paridade (CLI Dev, App, API) + evolucao de `AtlasCliDevWorkflowService` | fluxo disponivel primeiro na surface primaria e depois nas demais surfaces |

Empacotamento de entrega vertical:

| Marco | Entrega | Surface |
| --- | --- | --- |
| 1 | Foundation backend: schemas, discovery, prompt projection, persistence, telemetry | sem UI |
| 2 | Plan-only visivel: Contexto + Plano no Atlas AI Desktop | Desktop first |
| 3 | One-call visivel: diff, tests, receipt e stream de fases | Desktop first |
| 4 | Repair visivel: attempts, failure capsule, receipt acumulado | Desktop first |
| 5 | Surface parity: CLI Dev, App e API adapters thin + escalation preview | todas |

Apos Fatia 5, esta equipe **encerra**. Outra equipe (Medicao / Rivals) toma o fluxo pronto e desenha benchmark separadamente.

## 25. Decisoes Fechadas

| # | Decisao | Valor | Locked At |
| ---: | --- | --- | --- |
| 1 | Driver alvo | evoluir `AtlasCliDevWorkflowService` | 2026-05-16 |
| 2 | Persistencia de receipts | `atlas-server/storage/atlas-dev/receipts/<run_id>/*.json` | 2026-05-16 |
| 3 | Budget unit | chars | 2026-05-16 |
| 4 | Verification command profiles | `php_laravel`, `ts_react`, `generic_no_test` | 2026-05-16 |
| 5 | Code namespace | `app/Services/Ai/Programming/AtlasDev/{Schemas,Discovery,Gate,Persistence,...}` | 2026-05-16 |
| 6 | Provider lock | `claude_cli` + Sonnet, sem fallback | 2026-05-16 |
| 7 | Schema de receipt | `atlas.dev.verification_receipt.v1` | 2026-05-16 |
| 8 | Artefatos canonicos | Artefatos operacionais em 4 camadas (Plano/Contexto/Receipt/Telemetria), incluindo receipts Senior Engineer Loop | 2026-05-16 |
| 9 | Ordem de fatias | 0 -> 1 -> 1.5 -> 2 -> 3 -> 4 -> 5; nao pular | 2026-05-16 |
| 10 | Surface inicial | Atlas AI Desktop Mac via `surface_id=atlas_desktop_ai` | 2026-05-16 |
| 11 | Relacao com Governance | Atlas Dev gates sao projecoes de Governance ou Dev-only justificados que nao contradizem Governance | 2026-05-16 |
| 12 | Core surface-agnostic | Somente adapters em `AtlasDev/Surface/` conhecem surfaces | 2026-05-16 |
| 13 | Entrega | Marcos verticais Desktop-first, depois paridade CLI/App/API | 2026-05-16 |
| 14 | Rotas HTTP | `plan` e `run` separados; `run` exige `operator_confirmed=true` + `task_contract_hash` | 2026-05-16 |
| 15 | Realtime | SSE snapshot-replay-then-close em `/ai/interactions/atlas-dev/runs/{run_id}/stream` + fallback REST canonico em `/ai/interactions/atlas-dev/runs/{run_id}` | 2026-05-16 |
| 16 | Feature flags | `atlas_dev.efficient.plan_enabled` e `atlas_dev.efficient.run_enabled` em `config/atlas_dev.php` (env `ATLAS_DEV_EFFICIENT_PLAN_ENABLED` / `ATLAS_DEV_EFFICIENT_RUN_ENABLED`); workspace presente e surface suportada | 2026-05-16 |
| 17 | Run confirmation | `confirmation_token` single-use emitido pelo `plan`, persistido como hash HMAC-SHA256 em `atlas_dev_confirmation_tokens` (DB, não filesystem), expira em 5min, invalida apos uso, vinculado a `(run_id, task_contract_hash)` | 2026-05-16 |
| 18 | Evidence bridge | `VerificationReceipt.evidence_refs[]` carrega path local e `governance_ledger_ref` opcional | 2026-05-16 |
| 19 | Prompt rendering | `ProviderPromptBuilder` produz `rendered_prompt_text` obrigatorio via template Blade em Fatia 1.5 | 2026-05-16 |
| 20 | Stream lifecycle | snapshot-replay-then-close: backend replay artefatos persistidos + `stream_closed` final; sem long-lived keepalive; cliente continua via REST `/runs/{run_id}` | 2026-05-16 |
| 21 | Config canonica | `config/atlas_dev.php` é o root canônico do fluxo (flags, paths, timeouts, ttl token, redaction). `config/atlas.php` não é fonte | 2026-05-16 |
| 22 | APP_KEY obrigatorio | Plan/Run falham fechado com `ATLAS_DEV_KEY_MISSING` (500) se APP_KEY não for base64 com ≥32 bytes — pré-requisito do HMAC de confirmation_token | 2026-05-16 |
| 23 | Migrations obrigatorias | `atlas_dev_confirmation_tokens` (tokens DB+HMAC) e `atlas_dev_run_index` (cache de status REST) precisam estar migradas antes de habilitar plan/run | 2026-05-16 |
| 24 | Path redaction | Toda response HTTP usa `workspace_label`/`workspace_hash` e `persisted_artifact_refs` (refs `receipts/<run_id>/<file>`); paths absolutos so internos | 2026-05-16 |

Decisoes adiadas (nao bloqueiam Fatia 0):

- UI no Atlas Code sem duplicar SCOR-1 (Fatia 5 ou posterior);
- Migracao receipts JSON -> Postgres/Obra (apos Fatia 5);
- Suporte a Codex/outro modelo no mesmo contrato (futuro);
- Precificacao de chamadas CLI quando tokens/custo nao vierem do provider (futuro);

## 26. Regras Para IA Implementadora

Quando uma IA pegar este conjunto de docs para implementar:

1. Leia **este doc** primeiro (contrato).
2. Leia `atlas-dev-efficient-programming-flow-contracts-v1.md` (schemas).
3. Leia `atlas-dev-efficient-programming-flow-runbook-v1.md` (sequencia).
4. Comece pela Fatia 0, PR 0.1. **Nao pule**.
5. Nao discuta claim, vitoria, benchmark, Rivals ou Opus challenge como proxima acao deste fluxo.
6. Nao busque, crie, cure ou proponha prompts de teste.
7. Nao puxe council/topology/Obra automatica para dentro do fast path.
8. Nao introduza prompt artesanal; prompt vem de `ProviderPromptProjection`.
9. Cada fatia tem DoD operacional verificavel; **nao avance** sem DoD verde.
10. A primeira surface completa e Atlas AI Desktop Mac; CLI/App/API sao paridade.
11. Apos Fatia 5 verde, transfira para a equipe de medicao com cert local `available`.

## 26.1 Endpoints HTTP Canonicos (Locked 2026-05-16)

Toda surface entra no core via dois endpoints separados e um canal de streaming. Core e surface-agnostic; surfaces sao adapters thin sob `app/Services/Ai/Programming/AtlasDev/Surface/`.

### Plan

```text
POST /ai/interactions/atlas-dev/plan
Headers: X-Atlas-Token
```

Invariantes:

- Nunca chama provider. Custo de token = 0.
- Nunca aplica patch.
- Falha fechado com `ATLAS_DEV_KEY_MISSING` (500) se APP_KEY ausente ou não-base64 ≥32 bytes (pré-requisito do HMAC de token).
- Falha fechado com `ATLAS_DEV_PLAN_DISABLED` (503) se `atlas_dev.efficient.plan_enabled=false`.
- Para `surface_id=atlas_desktop_ai`, falha fechado com `ATLAS_DEV_DESKTOP_DISABLED` (503) se `atlas_dev.efficient.desktop_enabled=false`.
- Para Desktop, `workspace` pode ser slug de Projeto (`atlas`, `blackink`, etc.); o boundary HTTP resolve via `config/atlas_projects.php` para `workspace_path` existente antes de criar `OperationEnvelope`. O core persiste apenas o path resolvido. Slug sem path acessivel falha 422 antes de token/run.
- Produz `OperationEnvelope`, `CompactSDD`, `ContextRetrievalPlan`, `CodeDiscoveryManifest`, `OpenBrainProgrammingProjection`, `MiniProgrammingSpec`, `LightTaskContract`, `ProviderPromptProjection` e `routing_decision`.
- Persiste artefatos em `storage/atlas-dev/receipts/<run_id>/` (path interno absoluto). Response HTTP devolve `persisted_artifact_refs` relativos (`receipts/<run_id>/<file>`), `workspace_label` (basename) e `workspace_hash`; nunca path absoluto.
- Quando `routing_decision = atlas_dev_fast_path`, retorna `task_contract_hash` + `confirmation_token` em `confirmation.token` (single-use, expira em 5min, vinculado a `(run_id, task_contract_hash)`).

### Run

```text
POST /ai/interactions/atlas-dev/run
Headers: X-Atlas-Token
Body: { "run_id": "...", "task_contract_hash": "...", "confirmation_token": "...", "operator_confirmed": true }
```

Invariantes:

- Falha fechado com `ATLAS_DEV_RUN_DISABLED` (503) se `atlas_dev.efficient.run_enabled=false`.
- Para runs cujo `OperationEnvelope.surface_id=atlas_desktop_ai`, falha fechado com `ATLAS_DEV_DESKTOP_DISABLED` (503) se `atlas_dev.efficient.desktop_enabled=false`.
- Falha fechado com `ATLAS_DEV_KEY_MISSING` (500) se APP_KEY ausente/invalida.
- Exige `operator_confirmed = true` literal. Truthy strings, 1, "true" rejeitam 400 (`OPERATOR_NOT_CONFIRMED`).
- Exige `task_contract_hash` referenciando plan persistido. Mismatch rejeita 422 (`TASK_CONTRACT_HASH_MISMATCH`).
- Exige `confirmation_token` HMAC valido vinculado a `(run_id, task_contract_hash)`, single-use, dentro do TTL. Ausente, invalido, expirado, ja consumido ou contract-mismatch rejeitam 403 com error.code especifico (`CONFIRMATION_TOKEN_*`).
- Executa Execution + Repair + Receipt.
- Provider lock `claude_cli` + Sonnet; sem fallback.
- Persiste `ProviderCallResult`, `DiffParseResult`, `PatchApplyResult`, `ScopeGuardReceipt`, `VerificationReceipt`, `FailureCapsule` (se aplicavel), `EscalationDecision` (se aplicavel), `FastPathTelemetry`, `FastPathErrorLedgerEntry` (se aplicavel) e atualiza `atlas_dev_run_index`.
- Se provider responder fora do output contract, `DiffParseResult.mode=invalid` registra erros (`no_unified_diff_detected`, `no_no_patch_needed_marker`, `no_blocked_marker`, etc.) e `ProviderCallResult.raw_response_hash` preserva rastreabilidade sem repetir chamada paga.
- Response devolve `persisted_receipt_refs` relativos + resumo sem stdout/stderr bruto (`raw_response_hash`, byte counts, exit metadata); nunca path absoluto.

### Stream (SSE, canal primario)

```text
GET /ai/interactions/atlas-dev/runs/{run_id}/stream
Headers: X-Atlas-Token, Accept: text/event-stream
```

Modo locked: **snapshot-replay-then-close**. Backend escreve, em ordem deterministica, um evento `phase:` por artefato ja persistido + `receipt:` (se houver) + `stream_closed:` final, depois fecha a conexao. Sem long-lived keepalive nesta fase. Eventos canonicos: `phase`, `test_started`, `test_finished`, `repair_planned`, `repair_executing`, `repair_finished`, `escalation_triggered`, `receipt`, `stream_closed`.

Receipt sempre persiste no DB+filesystem antes do `stream_closed`. Live async (tail de log de execução) e evolucao futura — clientes ja devem assumir `stream_closed` como sinal canônico de fim, com fallback REST cobrindo qualquer estado intermediário. Falta de artefato no momento da conexao = 404 (`RUN_NOT_FOUND`).

### Status REST (fallback canonico)

```text
GET /ai/interactions/atlas-dev/runs/{run_id}
Headers: X-Atlas-Token
```

Fonte de verdade do estado atual: `completion_state`, `has_receipt`, `has_scope_guard_receipt`, `has_plan`, `routing`, `workspace_label` (sem absoluto), `workspace_hash`, `task_contract_hash`, `envelope_hash`, `*_receipt_hash`, `persisted_artifact_refs`. Cliente que nao suporta SSE ou perdeu o stream deve consultar este endpoint — é a base do contrato de retomada.

### Regra surface-agnostic

Esses 4 endpoints sao a UNICA fronteira HTTP do Atlas Dev. Toda surface (Desktop, CLI, App, API) consome esses endpoints via seu adapter thin. Core `AtlasDevFastPathOrchestrator` nunca conhece a surface chamadora.

## 26.2 Handoff Para Self-Improvement Curator

Atlas Dev **nunca auto-aplica** mudancas operacionais com base em seus proprios sinais. Telemetria e error ledger sao **dados**, nao decisao.

Fluxo de handoff canonico:

```text
FastPathTelemetry + FastPathErrorLedgerEntry
  -> Programming Curator (dono do dominio Self-Improvement para Programming)
  -> Proposal Inbox (Human Review queue)
  -> humano revisa, aprova/rejeita/refina
  -> patch governado aos thresholds/heuristicas via fluxo proprio
```

Atlas Dev escreve, Curator analisa, humano decide. Esse loop e enforced no estagio 16 do Kernel ("Learning nao altera comportamento critico sem proposal/review").

## 26.3 Atlas Dev Como Template Para Outras Verticais

Atlas Dev e o **primeiro fluxo** especializado do Atlas AI a ser implementado com governanca completa. O padrao operacional (Discovery -> CompactSDD -> MiniSpec -> TaskContract -> PromptProjection -> ScopeGuard -> Verification -> Receipt -> Repair -> Escalation -> Telemetry -> ErrorLedger) e **replicavel** em outras verticais do Atlas AI: Research, Explain, Debug, Review, Marketing, Finance, Cyber Security, etc.

Princípio canonico do Kernel: **"Tudo repetido vira Core"**. Quando outros fluxos replicarem este padrao, os componentes genericos sobem para `app/Services/Ai/Atlas/Kernel/...` e Atlas Dev passa a usar essa versao Core. Hoje, todos os componentes vivem em `app/Services/Ai/Programming/AtlasDev/...` porque ainda nao ha replicacao real.

Implicacoes para esta equipe:

- ao desenhar artefatos, separar mentalmente o que e **especifico de Programming** vs o que pode virar **Core compartilhado** com outras verticais;
- naming, schemas e contratos devem ser **suficientemente genericos** para futura promocao a Core sem rename forcado;
- evitar acoplar Atlas Dev a `programming.*` em nomes de schema quando o conceito for genérico (ex: `OperationEnvelope` e generico; `MiniProgrammingSpec` e Programming-specific).
