---
id: atlas-dev-efficient-programming-flow-contracts-v1-part-01
type: engineering_knowledge
title: Atlas Dev Efficient Programming Flow Contracts v1 · Parte 1
status: active
category: programming
priority: 104
summary: Recorte focado de Atlas Dev Efficient Programming Flow Contracts v1: 1. Resumo ate 4. Camada Plano.
tags:
  - atlas-dev
  - split-doc
  - cartography-readable
capabilities:
  - atlas_documentation_split
  - atlas_cartography_readable_docs
decisions:
  - Este recorte preserva uma parte operacional do documento maior sem ampliar responsabilidade do índice canônico.
maintenance:
  - Manter abaixo de 520 linhas.
  - Atualizar junto com docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1.md quando o documento dono mudar.
related_paths:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-dev-efficient-programming-flow-contracts-v1-part-01
graph_title: Atlas Dev Efficient Programming Flow Contracts v1 Parte 1
graph_world: atlas
graph_layer: module
graph_kind: contract
graph_parent: atlas-dev-efficient-programming-flow-contracts-v1
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1-part-01.md
allowed_changes:
  - Atualizar somente a parte descrita neste recorte.
forbidden_changes:
  - Adicionar nova responsabilidade que pertença ao índice ou a outro recorte.
depends_on:
  - atlas-dev-efficient-programming-flow-contracts-v1
flows_to:
  - atlas-dev-efficient-programming-flow-contracts-v1
unlocks:
  - atlas_cartography_readable_documentation
governs:
  - atlas.documentation.split_docs
evidence:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: high
next_actions:
  - Manter este recorte alinhado ao índice canônico e ao contrato de documentação.
---
# Atlas Dev Efficient Programming Flow Contracts v1 · Parte 1

## Resumo

Este recorte preserva uma parte focada de Atlas Dev Efficient Programming Flow Contracts v1: 1. Resumo ate 4. Camada Plano.

## Papel no Atlas

Mantém detalhe canônico fora do índice principal para que a cartografia e o modal humano continuem legíveis.

## Onde Se Encaixa

É filho canônico de `docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1.md` e deve ser lido apenas quando a pessoa precisar deste detalhe.

## Contratos

Segue o documento dono, o glossário canônico e o modelo obrigatório de documentação do Atlas.

## Fluxo

Índice canônico → recorte focado → decisão, implementação ou revisão correspondente.

## Regras para IA

Não inferir responsabilidade nova. Não misturar patamar, versão, fonte, risco, regra ou prova. Preservar backlink para o índice.

## Escopo de Implementacao

Este arquivo só guarda o detalhe extraído do documento maior.

## Dependencias

Depende do índice `atlas-dev-efficient-programming-flow-contracts-v1` e da documentação canônica relacionada.

## Evidencias

A evidência de origem é o documento principal e o `docs-health` verde depois da divisão.

## Riscos

Risco principal: alguém editar este recorte como se fosse novo dono de fluxo, duplicando contrato.

## Exemplos

Os exemplos abaixo são o conteúdo extraído, preservado sem perda semântica.

## Proximas Acoes

Atualizar este recorte quando a parte correspondente mudar e rodar docs-health.

## Conteudo Extraido
## 1. Resumo

Este doc define **schemas canonicos** dos artefatos que circulam pelo Atlas Dev Efficient Programming Flow. Cada artefato e tratado como **dado deterministico identificavel por hash**, validado por invariants e versionado.

Doc principal: `atlas-dev-efficient-programming-flow-v1.md` (contrato de alto nivel).
Doc runbook: `atlas-dev-efficient-programming-flow-runbook-v1.md` (sequencia de implementacao).

Este doc **nao** explica como implementar, **nao** propoe testes de produto e **nao** define medicao competitiva.

## 2. Camadas De Artefato

Dezessete artefatos operacionais principais, agrupados em quatro camadas:

| Camada | Pergunta que responde | Artefatos |
| --- | --- | --- |
| Plano | O que vamos fazer? | `OperationEnvelope`, `CompactSDD`, `MiniProgrammingSpec`, `LightTaskContract` |
| Contexto | O que sabemos? | `ContextRetrievalPlan`, `CodeDiscoveryManifest`, `OpenBrainProgrammingProjection`, `ProviderPromptProjection` |
| Receipt | O que aconteceu? | `ProviderCallResult`, `DiffParseResult`, `PatchApplyResult`, `ScopeGuardReceipt`, `VerificationReceipt`, `FailureCapsule`, `EscalationDecision` |
| Telemetria | Como aconteceu? | `FastPathTelemetry`, `FastPathErrorLedgerEntry` |

Ordem temporal canonica:

```text
OperationEnvelope
-> ContextRetrievalPlan -> CodeDiscoveryManifest -> OpenBrainProgrammingProjection
-> CompactSDD -> MiniProgrammingSpec -> LightTaskContract
-> ProviderPromptProjection
-> [chamada provider]
-> ProviderCallResult
-> DiffParseResult
-> PatchApplyResult (quando ha patch)
-> ScopeGuardReceipt
-> VerificationReceipt
-> FailureCapsule (se aplicavel) -> repair loop
-> EscalationDecision (se aplicavel)
-> FastPathTelemetry
-> FastPathErrorLedgerEntry (se aplicavel, possivelmente post-hoc)
```

Wrappers de retorno:

| Wrapper | Uso | Persistencia |
| --- | --- | --- |
| `PlanOnlyResult` | resposta canonica do plan-only para qualquer surface | referencia artefatos persistidos |
| `PatchResult` | resposta canonica de execucao/repair para qualquer surface | referencia artefatos persistidos |
| `SurfaceUiHints` | projecao opcional para render de surface | nao e fonte de decisao |

`SurfaceUiHints` nunca substitui artefato canonico. Se houver divergencia entre `ui_hints` e DTO persistido, o DTO persistido vence e o hint deve ser descartado.

## 3. Padroes Globais

### 3.1 Versionamento

- Toda artefato declara `schema_version` no formato `atlas.dev.<artifact_kind>.v<n>`.
- Exemplo: `atlas.dev.verification_receipt.v1`.
- Mudar campo obrigatorio: **bump major** (`v1` -> `v2`) + regra de migracao + leitores atualizados antes do bump.
- Adicionar campo opcional com default sensivel: **patch silencioso** dentro do mesmo `v<n>`.

### 3.2 Hashing E Identidade

- Hash canonico: `sha256` sobre **JSON canonicalizado** (RFC 8785 ou equivalente: chaves ordenadas, sem espacos extras, escaping consistente).
- Campos de hash sempre nomeados `<entity>_hash` (ex: `spec_hash`, `task_contract_hash`).
- `run_id`: UUID v7 gerado no `OperationEnvelope`, propagado em todo artefato downstream.
- `workspace_hash`: `sha256(git_root_path + "::" + git_head_sha)`. Reproducible por sessao.
- Hashes nao se misturam com hashes externos (Open Brain, Code Intelligence) — cada um vive em seu campo.

### 3.3 Estados Comuns

- `gate_status`: `passed | failed | needs_review | skipped | waived`
- `completion_state`: `passed | needs_review | failed | blocked | escalate_forge | no_patch_needed`
- `confidence_level`: `confirmed | inference | hypothesis | blocking`
- `task_kind`: `question | patch | repair | review | frontend | risky`
- `risk_level`: `R0 | R1 | R2 | R3 | R4 | R5`
- `mode`: `read_only | plan_only | patch | repair | escalate_preview`

### 3.4 Provider Safe Rule

Todo artefato carrega `provider_safe: bool`. Se `true`, o conteudo pode ser injetado em prompt provider sem revelar:

- IDs internos do Atlas;
- traces internos;
- prompts/system instructions internas;
- credenciais, secrets, paths absolutos sensiveis;
- referencias a Rivals/benchmark/medicao.

Artefatos que misturam dado interno + dado seguro devem ter duas faces: a versao persistida (interna) e a projecao provider-safe (filtrada). A projecao filtrada **nunca** mente — campos sensiveis viram referencias por hash, nao desaparecem em silencio.

Contrato de duas faces:

```php
public function toCanonicalArray(): array;     // face persistida interna
public function toProviderSafeArray(): array;  // face filtrada para prompt/provider
```

Default permitido: `toProviderSafeArray() === toCanonicalArray()` somente quando todos os campos forem provider-safe. Se qualquer campo carregar path sensivel, trace interno, secret pattern, prompt/system interno ou payload grande demais, `toProviderSafeArray()` deve substituir o campo por hash/ref e registrar o motivo. Validator falha se `provider_safe=true` e a face provider-safe ainda vazar dado interno.

### 3.5 Persistencia

Default desta fase (decisao locked 2026-05-16):

- Path: `atlas-server/storage/atlas-dev/receipts/<run_id>/`
- Formato: JSON pretty-print (legivel humano + parseavel maquina)
- Arquivos por run:
  - `operation_envelope.json`
  - `context_retrieval_plan.json`
  - `code_discovery_manifest.json`
  - `open_brain_projection.json`
  - `compact_sdd.json`
  - `mini_programming_spec.json`
  - `task_contract.json`
  - `prompt_projection.json`
  - `routing_decision.json`
  - `provider_call_result.json`
  - `diff_parse_result.json`
  - `patch_apply_result.json` (sempre persistido no run; `status=skipped` quando nao ha patch)
  - `scope_guard_receipt.json`
  - `verification_receipt.json`
  - `senior_engineer_loop_audit.json` (audit plan-time do patamar Senior Engineer)
  - `senior_engineer_loop_execution.json` (receipt operacional apos Run/worker)
  - `failure_capsule.<attempt>.json` (uma por tentativa, se houver)
  - `escalation_decision.json` (se aplicavel)
  - `telemetry.json`
  - `error_ledger.json` (se aplicavel)
  - `attachments/<sha256>.<ext>` (multimodal blobs, quando `OperationEnvelope.attachments[].ref = sha256:<hash>`)

`provider_call_result.json` e artefato interno de auditoria/replay: pode carregar stdout/stderr redigidos do provider e nao deve ser serializado em HTTP sem reducao. Responses de surface recebem apenas hash, tamanhos, exit metadata e `persisted_receipt_refs`.

`senior_engineer_loop_audit.json` prova, no Plan, a cobertura de resolucao de
ambiguidade, plano multi-step, architecture-aware editing, cockpit Desktop,
learning handoff e hardening enterprise. `senior_engineer_loop_execution.json`
prova, depois do Run/worker, a execução operacional: provider ou fast path
deterministico, diff/patch, scope guard, verification, debug loop e handoff para
ErrorLedger/Programming Curator sem auto-aplicar aprendizado.

`diff_parse_result.json` preserva a decisao do parser (`patch | no_patch_needed | blocked | invalid`), changed files, hash do diff e erros de parse. Isso impede falhas opacas: se o provider responder fora do contrato, o run pode falhar honestamente e ainda deixar evidencia suficiente para reparar prompt/parser sem repetir chamada paga.

Migracao para Postgres/Obra: futura, mantem mesmo schema.

### 3.5.1 Confirmation Tokens (Run Endpoint) — DB+HMAC canon (P0 GO)

Storage canônico = banco de dados (não filesystem). O legado `ConfirmationTokenStore` em filesystem foi removido (F-05). Tabela obrigatória (migration `atlas_dev_confirmation_tokens`):

```sql
atlas_dev_confirmation_tokens
  - id                  bigint primary key
  - run_id              string indexed
  - task_contract_hash  string(64) indexed
  - compact_sdd_hash    string(128) nullable indexed  -- F-03 server-side pin
  - surface_id          string
  - token_hash          string(64) unique  -- HMAC-SHA256(APP_KEY, plaintext)
  - issued_at           timestamp
  - expires_at          timestamp           -- issued_at + atlas_dev.confirmation_token.ttl_seconds (default 300s)
  - used_at             timestamp | null
  - business_context    jsonb              -- snapshot opcional no momento da emissão
```

> A coluna `compact_sdd_hash` é adicionada pela migration
> `2026_05_16_020000_add_compact_sdd_hash_to_atlas_dev_confirmation_tokens.php`.
> Ela carrega o `compact_sdd_hash` canônico produzido pelo Plan, fica somente
> no servidor (cliente nunca vê) e é HMAC-pinned indiretamente — quem altera
> a linha precisa de acesso DB, e quem reescrever `compact_sdd.json` entre
> Plan e Run sem ter `APP_KEY` produz mismatch (`COMPACT_SDD_TAMPERED`).

Invariantes:

1. Plaintext do token aparece **apenas uma vez**, no response de `/atlas-dev/plan` (campo `confirmation.token`). Servidor nunca persiste plaintext.
2. `token_hash = hmac_sha256(APP_KEY, plaintext)`. APP_KEY base64 com ≥32 bytes é pré-requisito; sem isso Plan/Run falham fechado com `ATLAS_DEV_KEY_MISSING` (500).
3. TTL configurável via `atlas_dev.confirmation_token.ttl_seconds` (default 5min).
4. Token é single-use: `used_at` populado atomicamente no consume; reutilização rejeita 403 `CONFIRMATION_TOKEN_ALREADY_CONSUMED`.
5. Vinculado a `(run_id, task_contract_hash)`: token de um plan não redime outro (403 `CONFIRMATION_TOKEN_CONTRACT_MISMATCH`).
6. Token expirado/ausente/inválido bloqueia o Run com 403 e error.code específico (`CONFIRMATION_TOKEN_EXPIRED`, `CONFIRMATION_TOKEN_INVALID`, `CONFIRMATION_TOKEN_NOT_FOUND`).
7. `compact_sdd_hash` é gravado no Plan e validado no Run **antes** da chamada ao provider. O executor compara três camadas: (a) self-hash do `compact_sdd.json` recomputado, (b) `mini_programming_spec.compact_sdd_hash` no disco, (c) pin server-side desta tabela. Qualquer divergência rejeita 422 `COMPACT_SDD_TAMPERED` sem custo de token. A coluna é `nullable` apenas para linhas legadas; novos Plans sempre preenchem.

### 3.5.2 Run Index (REST fallback cache)

Tabela espelho `atlas_dev_run_index` é migration obrigatória junto com tokens. Cacheia campos de Show REST para reduzir IO sem trocar fonte de verdade:

```sql
atlas_dev_run_index
  - run_id              string primary key
  - surface_id          string indexed
  - workspace_hash      string indexed
  - flow_id             string                 -- sempre `atlas_dev`
  - flow_origin         string                 -- `atlas_ai_router` | `direct`
  - command_intent      string | null
  - routing_kind        string
  - completion_state    string | null
  - task_contract_hash  string | null
  - envelope_hash       string | null
  - receipt_hash        string | null
  - created_at, updated_at
```

Receipts JSON em `storage/atlas-dev/receipts/<run_id>/` continuam fonte canônica; o index é projeção rápida.

### 3.5.3 HTTP boundary — refs relativos (F-04 fechado)

Toda response HTTP devolve refs relativos, nunca paths absolutos:

- `persisted_artifact_refs[name] = "receipts/<run_id>/<file>"` no Plan e Show.
- `persisted_receipt_refs[name]` no Run, mesmo formato.
- `workspace_label` (basename) + `workspace_hash` substituem `workspace` (absoluto).
- Paths absolutos permanecem **apenas** internamente (storage, discovery, telemetria local).
- `OperationEnvelope.workspace` persistido continua absoluto; a projeção HTTP `operation_envelope` (Surface formatter) troca por `workspace_label` antes de cruzar a wire.

### 3.6 Boundary Surface-Agnostic

`OperationEnvelope.surface_id` e `surface_context` identificam a origem, mas nenhum DTO de core pode carregar estrutura especifica de UI. A unica excecao permitida e `SurfaceUiHints`, que vive como projection de resposta em adapters sob `AtlasDev/Surface/`.

Regras:

- `Schemas`, `Discovery`, `PromptProjection`, `Pipeline`, `Provider`, `Gate`, `Repair`, `Escalation`, `Persistence` e `Telemetry` nao dependem de tipos Desktop/CLI/App/API.
- `PlanOnlyResult` e `PatchResult` carregam DTOs canonicos, nao componentes React, strings de UI ou nomes de tabs.
- Surface adapter pode adicionar `ui_hints.panel_contexto`, `ui_hints.panel_plano`, `ui_hints.inline_indicators`, mas esses campos sao derivados e descartaveis.
- Prompt final nunca vem da surface; vem de `ProviderPromptProjection`.

---

## 4. Camada Plano

