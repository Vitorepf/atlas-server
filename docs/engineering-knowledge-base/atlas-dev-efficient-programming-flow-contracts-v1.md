---
id: atlas-dev-efficient-programming-flow-contracts-v1
type: engineering_knowledge
title: Atlas Dev Efficient Programming Flow Contracts v1
status: active
category: programming
priority: 105
summary: Schemas canonicos detalhados dos artefatos do Atlas Dev Efficient Programming Flow, agrupados em quatro camadas (Plano, Contexto, Receipt, Telemetria). Cada artefato vem com schema YAML, invariants enforced, regra de identidade/hash, exemplo valido, exemplos invalidos e signature PHP DTO. Doc filho do contrato principal; nao define passo-a-passo de implementacao.
tags:
  - atlas-dev
  - efficient-programming-flow
  - contracts
  - schemas
  - dto
  - hashing
  - versioning
capabilities:
  - atlas_dev_contracts_canonical
  - compact_sdd_schema
  - light_task_contract_schema
  - mini_programming_spec_schema
  - verification_receipt_schema
  - failure_capsule_schema
  - fast_path_telemetry_schema
  - fast_path_error_ledger_schema
decisions:
  - Schemas dos artefatos sao a fronteira contratual entre fatias do Atlas Dev. Mudanca de schema exige bump de versao e teste de compatibilidade.
  - Toda mensagem entre fatias usa hash determinstico (sha256) sobre payload canonico ordenado.
  - schema_version segue o padrao `atlas.dev.<artifact>.v<n>`; bump exige migrar leitores antes.
  - Camada Plano descreve o que vamos fazer; Camada Contexto descreve o que sabemos; Camada Receipt descreve o que aconteceu; Camada Telemetria descreve como aconteceu.
  - Provider prompt e projecao deterministica dos contratos (ProviderPromptProjection). Prompt artesanal e proibido no fast path.
maintenance:
  - Atualize este doc quando schema mudar, quando novo artefato entrar no fluxo, ou quando uma regra de invariant mudar.
  - Nao adicione passos de implementacao aqui; pertence ao runbook.
  - Nao adicione regras de medicao/benchmark; pertence a outra equipe.
related_paths:
  - docs/engineering-knowledge-base/atlas-dev-index.md
  - docs/engineering-knowledge-base/atlas-dev-glossary.md
  - docs/engineering-knowledge-base/atlas-dev-policy.md
  - docs/engineering-knowledge-base/atlas-dev-patamares.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1.md
  - docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1.md
  - docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md
  - docs/engineering-knowledge-base/atlas-programming-governance-system-contracts.md
  - docs/engineering-knowledge-base/open-brain-context-injection.md
  - docs/engineering-knowledge-base/code-intelligence.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-dev-efficient-programming-flow-contracts-v1
graph_title: Atlas Dev Efficient Programming Flow Contracts v1
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-dev-efficient-programming-flow-v1
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1.md
allowed_changes:
  - Adicionar artefato novo agrupado em camada existente, com schema + invariants + identidade + exemplos + DTO.
  - Bump de schema_version com regra de migracao.
forbidden_changes:
  - Remover invariant sem bump de versao + regra de migracao.
  - Mover schemas para o doc principal; pertencem aqui.
  - Inserir regras de benchmark, oraculos, scoring ou Rivals.
depends_on:
  - atlas-dev-efficient-programming-flow-v1
flows_to:
  - atlas-dev-efficient-programming-flow-runbook-v1
unlocks:
  - atlas_dev_dto_runtime
  - atlas_dev_schema_validation
governs:
  - atlas_dev.contracts.schemas
  - atlas_dev.contracts.hashing
  - atlas_dev.contracts.versioning
evidence:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
next_actions:
  - Implementar DTOs read-only PHP sob `app/Services/Ai/Programming/AtlasDev/Schemas/`.
  - Implementar serializadores deterministicos.
  - Implementar hashers canonicos (sha256 sobre JSON canonicalizado).
  - Adicionar testes de schema (round-trip, invariant enforcement, rejeicao de campos faltantes).
observability_signals:
  - schema_version
  - artifact_kind
  - artifact_hash
required_tests:
  - "php artisan test tests/Unit/Ai/Programming/AtlasDev/Schemas"
requires_evidence: true
risk_level: high
line_limit: 2080
---
# Atlas Dev Efficient Programming Flow Contracts v1

## Resumo

Este documento e o anexo de contratos do Atlas Dev Efficient Programming Flow. Ele preserva schemas, invariants e regras de hash para os artefatos do fluxo.

## Papel no Atlas

Serve como referencia contratual para DTOs, validadores, serializadores e hashers canonicos do Atlas Dev.

## Onde Se Encaixa

Fica abaixo do contrato principal `atlas-dev-efficient-programming-flow-v1.md` e ao lado do runbook de implementacao.

## Contratos

Os contratos detalhados continuam nas secoes numeradas abaixo; esta secao existe para manter cobertura canonica do modulo.

## Fluxo

Os artefatos fluem das camadas de plano e contexto para receipts e telemetria, sempre com identidade deterministica por hash.

## Regras para IA

IA deve tratar schemas como fronteira de compatibilidade, nao remover invariants sem bump e nao criar prompt provider artesanal fora da projecao contratual.

## Escopo de Implementacao

O escopo e documental/contratual: especificar schemas e exemplos, sem implementar runtime neste arquivo.

## Dependencias

Depende do contrato principal, do runbook e dos sistemas de Code Intelligence, Open Brain e governanca de programacao.

## Evidencias

Evidencia primaria: este doc, o contrato principal, o runbook e testes futuros de schema/round-trip/hash.

## Riscos

Risco principal: drift entre schema documentado e DTO/runtime. Mitigacao: hash canonico, versionamento e testes de compatibilidade.

## Exemplos

Os exemplos validos e invalidos permanecem nas secoes especificas de cada artefato abaixo.

## Proximas Acoes

Implementar DTOs read-only, serializacao deterministica e testes de rejeicao de payload invalido.

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
  - `failure_capsule.<attempt>.json` (uma por tentativa, se houver)
  - `escalation_decision.json` (se aplicavel)
  - `telemetry.json`
  - `error_ledger.json` (se aplicavel)
  - `attachments/<sha256>.<ext>` (multimodal blobs, quando `OperationEnvelope.attachments[].ref = sha256:<hash>`)

`provider_call_result.json` e artefato interno de auditoria/replay: pode carregar stdout/stderr redigidos do provider e nao deve ser serializado em HTTP sem reducao. Responses de surface recebem apenas hash, tamanhos, exit metadata e `persisted_receipt_refs`.

`diff_parse_result.json` preserva a decisao do parser (`patch | no_patch_needed | blocked | invalid`), changed files, hash do diff e erros de parse. Isso impede falhas opacas: se o provider responder fora do contrato, o run pode falhar honestamente e ainda deixar evidencia suficiente para reparar prompt/parser sem repetir chamada paga.

Migracao para Postgres/Obra: futura, mantem mesmo schema.

### 3.5.1 Confirmation Tokens (Run Endpoint) — DB+HMAC canon (P0 GO)

Storage canônico = banco de dados (não filesystem). O legado `ConfirmationTokenStore` em filesystem foi removido (F-05). Tabela obrigatória (migration `atlas_dev_confirmation_tokens`):

```sql
atlas_dev_confirmation_tokens
  - id                  bigint primary key
  - run_id              string indexed
  - task_contract_hash  string(64) indexed
  - surface_id          string
  - token_hash          string(64) unique  -- HMAC-SHA256(APP_KEY, plaintext)
  - issued_at           timestamp
  - expires_at          timestamp           -- issued_at + atlas_dev.confirmation_token.ttl_seconds (default 300s)
  - used_at             timestamp | null
  - business_context    jsonb              -- snapshot opcional no momento da emissão
```

Invariantes:

1. Plaintext do token aparece **apenas uma vez**, no response de `/atlas-dev/plan` (campo `confirmation.token`). Servidor nunca persiste plaintext.
2. `token_hash = hmac_sha256(APP_KEY, plaintext)`. APP_KEY base64 com ≥32 bytes é pré-requisito; sem isso Plan/Run falham fechado com `ATLAS_DEV_KEY_MISSING` (500).
3. TTL configurável via `atlas_dev.confirmation_token.ttl_seconds` (default 5min).
4. Token é single-use: `used_at` populado atomicamente no consume; reutilização rejeita 403 `CONFIRMATION_TOKEN_ALREADY_CONSUMED`.
5. Vinculado a `(run_id, task_contract_hash)`: token de um plan não redime outro (403 `CONFIRMATION_TOKEN_CONTRACT_MISMATCH`).
6. Token expirado/ausente/inválido bloqueia o Run com 403 e error.code específico (`CONFIRMATION_TOKEN_EXPIRED`, `CONFIRMATION_TOKEN_INVALID`, `CONFIRMATION_TOKEN_NOT_FOUND`).

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

### 4.1 OperationEnvelope

Entrada normalizada do intake. Toda execucao comeca aqui.

#### Schema

```yaml
OperationEnvelope:
  schema_version: atlas.dev.operation_envelope.v1
  run_id: string             # UUID v7
  surface_id: atlas_cli_dev|atlas_desktop_ai|atlas_app|atlas_api_interaction
  surface_context:
    product_surface: atlas_ai_desktop_mac|cli_dev|atlas_app|api_interaction
    thread_id: string|null
    conversation_id: string|null
    composer_mode: general|operational|programming|null
    composer_task: direct|plan|review|dev|debug|null
    provider_choice: auto|claude_cli|codex_cli|gemini_cli|claude_codex|null
  flow_id: atlas_dev                   # fluxo especializado que este contrato governa
  flow_origin: atlas_ai_router|direct   # Router decifrou pedido vs surface chamou direto
  command_intent: string|null           # slash command decifrado (fix|explain|research|debug|conversation|...) quando flow_origin=atlas_ai_router
  business_context:                      # Kernel estagio 6
    organization: string|null            # ex: blacklink|atlas|cliente_xyz
    project: string|null                 # ex: atlas-server, atlas-desktop, customer-app
    environment: dev|staging|production|null
    customer_id: string|null             # quando organization=cliente_xyz
    privacy_class: public|internal|confidential|restricted|null
  workspace: string          # absolute path
  workspace_hash: string     # sha256 do (git_root + "::" + git_head_sha)
  git_state:
    head_sha: string|null
    dirty: boolean
    untracked_count: integer
    pending_changes_count: integer
  raw_intent: string         # texto bruto do operador
  normalized_intent: string  # normalizacao sem trocar significado
  user_constraints: list     # restricoes explicitas (ex: ["nao tocar tests/", "max 3 arquivos"])
  intent_clarity_level: high|medium|low|blocking
  attachments:               # Kernel estagio 3 (Atlas Input multimodal)
    - kind: image|file|paste|audio
      ref: string            # path absoluto OU sha256 do conteudo inline
      content_inline: string|null   # base64 quando inline; preferir ref quando volumoso
      mime_type: string      # ex: image/png, text/plain
      hash: string           # sha256 do conteudo
      reason: string|null    # por que o operador anexou
  dirty_worktree_policy: preserve_user_changes
  preflight:
    workspace_resolved: boolean
    permission_mode: read|write|danger
    write_allowed: boolean
    operator_explicit: boolean
  provider_safe: true
  envelope_hash: string      # sha256 do payload canonicalizado sem este campo
```

#### Invariants

1. `run_id` e UUID v7 (timestamp embedded). Nao reutilizar entre runs.
2. `workspace` e path absoluto existente. Se nao existir, falha imediata (`blocked_no_workspace`).
3. `workspace_hash` muda quando `git_head_sha` muda; mesma sessao com commits novos = envelopes diferentes downstream.
4. `raw_intent` e o texto original; `normalized_intent` nao pode contradizer (so normaliza acentos, whitespace, capitalizacao).
5. `intent_clarity_level = blocking` impede progredir; runtime deve pedir esclarecimento ou marcar `blocked`.
6. `write_allowed = true` exige `permission_mode in (write, danger)` E `operator_explicit = true` para modo `danger`.
7. `surface_id = atlas_desktop_ai` exige `surface_context.product_surface = atlas_ai_desktop_mac`.
8. `surface_context.composer_mode = programming` exige `workspace` resolvido antes de write.
9. `flow_id` e sempre `atlas_dev`. Outro fluxo deve usar contrato proprio ou ser roteado pelo Atlas AI Router antes de chegar aqui.
10. `flow_origin = atlas_ai_router` significa que o Router ja decidiu que este envelope pertence ao Atlas Dev; Atlas Dev nao reinterpreta slash commands globais.
11. `flow_origin = atlas_ai_router` exige `command_intent != null` quando o Router decifrou slash command; senao `command_intent = null`.
12. `command_intent` aceita apenas o conjunto canonico (fix, explain, research, test, refactor, review, debug, plan, conversation); valor fora do conjunto rejeita envelope ou retorna `delegate_to_other_flow`.
12.1. `command_intent` e **hint roteado pelo Atlas AI Router**, nao autoridade de UI. Atlas Dev nao pode reinterpretar slash commands globais nem promover `command_intent` a decisao de produto: rota inter-fluxo continua sendo prerrogativa do Router; risco/escopo/provider continua sendo prerrogativa do core Atlas Dev.
13. `business_context` e obrigatorio a partir da Fatia 5 (paridade de surfaces). Em fatias anteriores pode vir todo `null`, mas a Fatia 5 enforces preenchimento minimo de `organization` + `project`.
14. `business_context.privacy_class = confidential|restricted` forca governanca extra: prompt projection oculta paths absolutos, telemetria omite excerpts crus.
15. `attachments[].kind = image` exige provider com vision capability; validator falha se `provider_lock.model_family` nao suporta multimodal.
16. `attachments[].ref` e path absoluto OU `sha256:<hash>` referenciando blob persistido em `storage/atlas-dev/attachments/<run_id>/`.
17. `envelope_hash` e auto-referente: calculado sobre todo o resto canonicalizado.

#### Identidade

- Chave: `run_id`.
- Hash: `envelope_hash`.

#### Exemplo Valido

```yaml
schema_version: atlas.dev.operation_envelope.v1
run_id: "0192b5d2-2fe0-7c4f-9d2a-1f3b8e9a4c2d"
surface_id: atlas_desktop_ai
surface_context:
  product_surface: atlas_ai_desktop_mac
  thread_id: "thread_01J..."
  conversation_id: "conversation_01J..."
  composer_mode: programming
  composer_task: dev
  provider_choice: auto
flow_id: atlas_dev
flow_origin: atlas_ai_router
command_intent: fix
business_context:
  organization: atlas
  project: atlas-server
  environment: dev
  customer_id: null
  privacy_class: internal
workspace: "/Users/op/code/atlas-server"
workspace_hash: "a1b2c3..."
git_state:
  head_sha: "f4e5d6..."
  dirty: false
  untracked_count: 0
  pending_changes_count: 0
raw_intent: "corrija o teste falhando em AtlasCliDevWorkflowServiceTest"
normalized_intent: "corrigir teste falhando em AtlasCliDevWorkflowServiceTest"
user_constraints: []
intent_clarity_level: high
attachments: []
dirty_worktree_policy: preserve_user_changes
preflight:
  workspace_resolved: true
  permission_mode: write
  write_allowed: true
  operator_explicit: false
provider_safe: true
envelope_hash: "deadbeef..."
```

#### Exemplos Invalidos

| Caso | Motivo |
| --- | --- |
| `run_id: "123"` | Nao e UUID v7 valido |
| `workspace: "./atlas-server"` | Nao e absoluto |
| `normalized_intent` diz "implementar feature X" quando `raw_intent` diz "explicar feature X" | Normalizacao mudou significado |
| `intent_clarity_level: blocking` + downstream prossegue | Violacao de invariant 5 |
| `permission_mode: danger` + `operator_explicit: false` + `write_allowed: true` | Violacao de invariant 6 |
| `surface_id: atlas_desktop_ai` + `product_surface: atlas_app` | Violacao de invariant 7 |
| `flow_id: atlas_research` | Este contrato governa apenas `atlas_dev` |
| `flow_origin: atlas_ai_router` + `command_intent: null` quando houve slash command roteado | Router perdeu o intent decifrado |
| `command_intent: brainstorm` | Fora do conjunto canonico |

#### PHP DTO Signature

```php
namespace App\Services\Ai\Programming\AtlasDev\Schemas;

final class OperationEnvelope
{
    public function __construct(
        public readonly string $runId,
        public readonly string $surfaceId,
        public readonly SurfaceContext $surfaceContext,
        public readonly string $flowId,
        public readonly string $flowOrigin,
        public readonly ?string $commandIntent,
        public readonly BusinessContext $businessContext,
        public readonly string $workspace,
        public readonly string $workspaceHash,
        public readonly GitState $gitState,
        public readonly string $rawIntent,
        public readonly string $normalizedIntent,
        public readonly array $userConstraints,
        public readonly string $intentClarityLevel,
        public readonly array $attachments,
        public readonly string $dirtyWorktreePolicy,
        public readonly Preflight $preflight,
        public readonly string $envelopeHash,
    ) {}

    public function schemaVersion(): string { return 'atlas.dev.operation_envelope.v1'; }
    public function toCanonicalArray(): array { /* canonical key order */ }
    public function isProviderSafe(): bool { return true; }
}
```

---

### 4.2 CompactSDD

Classificacao + risco + scope mode + context budget + hashes dos artefatos seguintes. E o **mapa do run**.

#### Schema

```yaml
CompactSDD:
  schema_version: atlas.dev.compact_sdd.v1
  run_id: string
  envelope_hash: string
  intent_raw: string
  intent_normalized: string
  task_kind: question|patch|repair|review|frontend|risky
  risk_level: R0|R1|R2|R3|R4|R5
  scope_mode: compact|structural
  mode: read_only|plan_only|patch|repair|escalate_preview
  context_budget:
    max_chars: integer        # decisao locked: chars
    max_docs: integer
    max_candidate_files: integer
    max_plan_steps: integer
    max_provider_calls: integer
    max_repair_attempts: integer
  doc_tiers_required: list    # subset de [core, code_intelligence, sdd, interface, forge, obras]
  verification_profile: php_laravel|ts_react|generic_no_test
  context_digest: string|null  # hash do contexto montado, preenchido apos retrieval
  escalation_triggers: list    # quais sinais ja indicam escalada potencial
  mini_spec_hash: string|null  # preenchido quando mini_spec fechar
  task_contract_hash: string|null  # preenchido quando task_contract fechar
  provider_safe: true
  compact_sdd_hash: string
```

#### Invariants

1. `risk_level = R4|R5` forca `mode = escalate_preview`. Nunca patch direto no fast path.
2. `task_kind = question` forca `mode = read_only`.
3. `task_kind = risky` forca `risk_level >= R4`.
4. `context_budget.max_chars` segue tabela do contrato principal (secao 11.2).
5. `verification_profile` e exigido se `task_kind in (patch, repair, frontend)`.
6. `mini_spec_hash` e `task_contract_hash` sao null no nascimento e preenchidos canonicamente quando os artefatos seguintes fecham. Mutacao monotônica.
7. `compact_sdd_hash` e calculado **excluindo** `mini_spec_hash` e `task_contract_hash` (que mudam depois). Ele garante identidade da decisao de classificacao, nao do conjunto inteiro.

#### Identidade

- Chave: `(run_id, compact_sdd_hash)`.
- Hash: `compact_sdd_hash`.

#### Exemplo Valido (Patch R2)

```yaml
schema_version: atlas.dev.compact_sdd.v1
run_id: "0192b5d2-..."
envelope_hash: "deadbeef..."
intent_raw: "corrija o teste falhando em AtlasCliDevWorkflowServiceTest"
intent_normalized: "corrigir teste falhando em AtlasCliDevWorkflowServiceTest"
task_kind: repair
risk_level: R2
scope_mode: compact
mode: repair
context_budget:
  max_chars: 12000
  max_docs: 4
  max_candidate_files: 6
  max_plan_steps: 4
  max_provider_calls: 1
  max_repair_attempts: 1
doc_tiers_required: [core, code_intelligence, sdd]
verification_profile: php_laravel
context_digest: null
escalation_triggers: []
mini_spec_hash: null
task_contract_hash: null
provider_safe: true
compact_sdd_hash: "feedcafe..."
```

#### Exemplos Invalidos

| Caso | Motivo |
| --- | --- |
| `task_kind: risky` + `risk_level: R2` | Violacao de invariant 3 |
| `risk_level: R4` + `mode: patch` | Violacao de invariant 1 |
| `task_kind: patch` + `verification_profile: null` | Violacao de invariant 5 |
| `context_budget.max_chars: 32000` + `task_kind: question` | Excede budget para read-only (4-6k chars) |

#### PHP DTO Signature

```php
final class CompactSdd
{
    public function __construct(
        public readonly string $runId,
        public readonly string $envelopeHash,
        public readonly string $intentRaw,
        public readonly string $intentNormalized,
        public readonly string $taskKind,
        public readonly string $riskLevel,
        public readonly string $scopeMode,
        public readonly string $mode,
        public readonly ContextBudget $contextBudget,
        public readonly array $docTiersRequired,
        public readonly string $verificationProfile,
        public readonly ?string $contextDigest,
        public readonly array $escalationTriggers,
        public readonly ?string $miniSpecHash,
        public readonly ?string $taskContractHash,
        public readonly string $compactSddHash,
    ) {}

    public function withMiniSpecHash(string $hash): self { /* monotonic */ }
    public function withTaskContractHash(string $hash): self { /* monotonic */ }
    public function schemaVersion(): string { return 'atlas.dev.compact_sdd.v1'; }
}
```

---

### 4.3 MiniProgrammingSpec

Behavior contract. **Obrigatorio para todo write**. Nasce do `CompactSDD` + retrieval, e a fonte canonica do que se espera do patch.

#### Schema

```yaml
MiniProgrammingSpec:
  schema_version: atlas.dev.mini_programming_spec.v1
  run_id: string
  compact_sdd_hash: string
  goal: string                    # 1-2 frases, imperativo
  non_goals: list                 # explicitamente fora de escopo
  canonical_context:              # docs/arquivos referenciados, nao texto bruto
    - kind: doc|file|symbol|test
      ref: string
      reason: string
  expected_behavior:              # comportamento observavel apos patch
    - description: string
      observable_by: test|cli|api|ui|log
  assumptions:
    - text: string
      confidence: confirmed|inference|hypothesis|blocking
  expected_files: list            # path absoluto, ate 6
  allowed_files: list             # superset de expected_files
  forbidden_files: list           # ex: vendor/*, node_modules/*, .env
  acceptance_criteria:            # cada criterio deve ser verificavel
    - id: string
      description: string
      verification: test|grep|cli_command|manual_review
      verification_ref: string|null
  verification_plan:
    profile: php_laravel|ts_react|generic_no_test
    commands: list                # comandos especificos derivados do profile
    no_test_reason: string|null   # obrigatorio se profile=generic_no_test
  rollback_or_containment: string # como reverter se algo der errado
  completion_criteria: list       # quando o run pode parar
  provider_safe: true
  mini_spec_hash: string
```

#### Invariants

1. `goal` nao vazio, max 240 chars, imperativo.
2. `non_goals` nao vazio para risk `>= R2`.
3. `canonical_context` exige pelo menos 1 ref se `task_kind != question`.
4. `expected_files` subset de `allowed_files`.
5. `forbidden_files` e `allowed_files` sao disjuntos.
6. `acceptance_criteria` nao vazio para todo write (`mode in patch, repair`).
7. Cada `acceptance_criteria.verification` tem `verification_ref` quando aplicavel (path do teste, comando, doc).
8. `verification_plan.profile` deve coincidir com `compact_sdd.verification_profile`.
9. `verification_plan.no_test_reason` so e valido para profile `generic_no_test`.
10. `assumptions[].confidence = blocking` impede progredir; runtime deve resolver antes.

#### Identidade

- Chave: `(run_id, mini_spec_hash)`.
- Hash: `mini_spec_hash`. Recalcula a cada mudanca.

#### Exemplo Valido (Repair R2)

```yaml
schema_version: atlas.dev.mini_programming_spec.v1
run_id: "0192b5d2-..."
compact_sdd_hash: "feedcafe..."
goal: "Corrigir AtlasCliDevWorkflowServiceTest::test_workspace_resolution para passar com git root null"
non_goals:
  - "nao mudar API publica do WorkflowService"
  - "nao mexer em outros testes"
canonical_context:
  - kind: file
    ref: "app/Services/Ai/Cli/AtlasCliDevWorkflowService.php"
    reason: "alvo do bug"
  - kind: test
    ref: "tests/Unit/AtlasCliDevWorkflowServiceTest.php"
    reason: "teste falhando"
expected_behavior:
  - description: "test_workspace_resolution passa com git root null sem disparar exception"
    observable_by: test
assumptions:
  - text: "git root nullable e caso valido e nao bug downstream"
    confidence: inference
expected_files:
  - "app/Services/Ai/Cli/AtlasCliDevWorkflowService.php"
allowed_files:
  - "app/Services/Ai/Cli/AtlasCliDevWorkflowService.php"
forbidden_files:
  - "vendor/*"
  - "node_modules/*"
acceptance_criteria:
  - id: ac_1
    description: "teste passa"
    verification: test
    verification_ref: "tests/Unit/AtlasCliDevWorkflowServiceTest.php::test_workspace_resolution"
verification_plan:
  profile: php_laravel
  commands:
    - "composer test -- --filter=AtlasCliDevWorkflowServiceTest::test_workspace_resolution"
  no_test_reason: null
rollback_or_containment: "git checkout app/Services/Ai/Cli/AtlasCliDevWorkflowService.php"
completion_criteria:
  - "teste passa"
  - "nenhum outro teste regrediu (composer test)"
provider_safe: true
mini_spec_hash: "babecafe..."
```

#### Exemplos Invalidos

| Caso | Motivo |
| --- | --- |
| `goal: ""` | Vazio |
| `expected_files` inclui path que nao esta em `allowed_files` | Violacao de invariant 4 |
| `allowed_files` e `forbidden_files` se sobrepoem | Violacao de invariant 5 |
| `verification_plan.profile: php_laravel` + `no_test_reason: "nao tem teste"` | Violacao de invariant 9 |
| `assumptions[0].confidence: blocking` + downstream cria task_contract | Violacao de invariant 10 |

#### PHP DTO Signature

```php
final class MiniProgrammingSpec
{
    public function __construct(
        public readonly string $runId,
        public readonly string $compactSddHash,
        public readonly string $goal,
        public readonly array $nonGoals,
        public readonly array $canonicalContext,
        public readonly array $expectedBehavior,
        public readonly array $assumptions,
        public readonly array $expectedFiles,
        public readonly array $allowedFiles,
        public readonly array $forbiddenFiles,
        public readonly array $acceptanceCriteria,
        public readonly VerificationPlan $verificationPlan,
        public readonly string $rollbackOrContainment,
        public readonly array $completionCriteria,
        public readonly string $miniSpecHash,
    ) {}

    public function hasBlockingAssumption(): bool { /* invariant 10 */ }
    public function schemaVersion(): string { return 'atlas.dev.mini_programming_spec.v1'; }
}
```

---

### 4.4 LightTaskContract

Execution contract. Define o que o agente pode fazer, onde, com quais tools, e como recuperar se falhar.

#### Schema

```yaml
LightTaskContract:
  schema_version: atlas.dev.light_task_contract.v1
  run_id: string
  task_id: string                # UUID v7, separado de run_id
  spec_hash: string              # = mini_spec_hash
  owner: atlas_dev_sonnet
  allowed_tools: list            # ex: [read, write, grep, run_test]
  blocked_actions:
    - production_write
    - migration_apply
    - secret_access
    - broad_refactor
    - council_invoke
    - forge_invoke_direct
  allowed_files: list            # copiado de mini_spec
  watched_files: list            # arquivos que nao sao allowed mas se mudarem geram needs_review
  forbidden_files: list          # copiado de mini_spec
  max_files_changed: integer
  validation_commands: list      # copiado de verification_plan.commands
  evidence_required:
    - diff_hash
    - changed_files
    - test_output_hash|no_test_reason
    - scope_guard_receipt
    - verification_receipt
  repair_policy:
    max_attempts: integer        # depende do R-level
    same_provider: true
    requires_failed_gate_output: true
    abort_on_same_signature_twice: true
  escalation_on:
    - same_signature_failure_twice
    - diff_grew_without_progress
    - new_scope_appeared
    - test_failure_requires_architecture
    - context_required_exceeds_budget
    - risk_escalated_to_r4_or_above
  provider_lock:
    provider: claude_cli
    model_family: sonnet
    fallback_allowed: false
  policy_profile:                # Kernel estagio 9 (Policy / Profile)
    autonomy_level: assist|auto_with_confirmation|auto
    privacy_class: public|internal|confidential|restricted
    cost_budget_usd: number|null  # null = sem teto explicito; default por R-level
    sandbox_required: boolean    # true para R3+, false R0-R2
    decision_mode: manual_override|auto_best_allowed|auto_best_available  # casa com Kernel estagio 10
  provider_safe: true
  task_contract_hash: string
```

#### Invariants

1. `task_id` e UUID v7 distinto de `run_id`.
2. `spec_hash` deve corresponder a um `MiniProgrammingSpec` valido com mesmo `run_id`.
3. `allowed_files` e `forbidden_files` herdados literalmente de `MiniProgrammingSpec`.
4. `watched_files` e disjunto de `allowed_files` e `forbidden_files`.
5. `max_files_changed` <= 6 no fast path. Acima disso vira R4.
6. `repair_policy.max_attempts` segue tabela R-level (secao 9 do contrato principal).
7. `provider_lock.fallback_allowed = false` (decisao locked 2026-05-16). Mudar para true exige bump major.
8. `blocked_actions` deve incluir pelo menos: `production_write`, `migration_apply`, `secret_access`, `broad_refactor`.
9. Qualquer tool em `allowed_tools` que toque write deve coincidir com permission no envelope.
10. `policy_profile.autonomy_level = auto` exige `business_context.environment in (dev, staging)`. Production sempre exige `auto_with_confirmation` minimo.
11. `policy_profile.privacy_class = restricted` proibe envio de excerpts crus ao provider; apenas refs por hash.
12. `policy_profile.sandbox_required = true` exige Tool Runtime executar comandos isolados; verification gate falha se sandbox nao disponivel.
13. `policy_profile.decision_mode = manual_override` e o default atual (provider_lock fixo). `auto_best_allowed` requer Atlas Decide ativado para Programming; `auto_best_available` ignora budget e e fora do escopo desta fase.

#### Identidade

- Chave: `(run_id, task_id)`.
- Hash: `task_contract_hash`.

#### Exemplo Valido (Repair R2)

```yaml
schema_version: atlas.dev.light_task_contract.v1
run_id: "0192b5d2-..."
task_id: "0192b5d2-3001-7c4f-..."
spec_hash: "babecafe..."
owner: atlas_dev_sonnet
allowed_tools: [read, write, grep, run_test]
blocked_actions:
  - production_write
  - migration_apply
  - secret_access
  - broad_refactor
  - council_invoke
  - forge_invoke_direct
allowed_files:
  - "app/Services/Ai/Cli/AtlasCliDevWorkflowService.php"
watched_files: []
forbidden_files:
  - "vendor/*"
  - "node_modules/*"
max_files_changed: 1
validation_commands:
  - "composer test -- --filter=AtlasCliDevWorkflowServiceTest::test_workspace_resolution"
evidence_required:
  - diff_hash
  - changed_files
  - test_output_hash
  - scope_guard_receipt
  - verification_receipt
repair_policy:
  max_attempts: 1
  same_provider: true
  requires_failed_gate_output: true
  abort_on_same_signature_twice: true
escalation_on:
  - same_signature_failure_twice
  - diff_grew_without_progress
  - new_scope_appeared
provider_lock:
  provider: claude_cli
  model_family: sonnet
  fallback_allowed: false
provider_safe: true
task_contract_hash: "ace5beef..."
```

#### Exemplos Invalidos

| Caso | Motivo |
| --- | --- |
| `max_files_changed: 12` + `risk_level: R2` | Violacao de invariant 5 |
| `provider_lock.fallback_allowed: true` | Violacao de invariant 7 |
| `blocked_actions` sem `secret_access` | Violacao de invariant 8 |
| `allowed_files` discordando de `mini_spec.allowed_files` | Violacao de invariant 3 |

#### PHP DTO Signature

```php
final class LightTaskContract
{
    public function __construct(
        public readonly string $runId,
        public readonly string $taskId,
        public readonly string $specHash,
        public readonly array $allowedTools,
        public readonly array $blockedActions,
        public readonly array $allowedFiles,
        public readonly array $watchedFiles,
        public readonly array $forbiddenFiles,
        public readonly int $maxFilesChanged,
        public readonly array $validationCommands,
        public readonly array $evidenceRequired,
        public readonly RepairPolicy $repairPolicy,
        public readonly array $escalationOn,
        public readonly ProviderLock $providerLock,
        public readonly string $taskContractHash,
    ) {}

    public function allowsWrite(): bool { /* check allowed_tools */ }
    public function schemaVersion(): string { return 'atlas.dev.light_task_contract.v1'; }
}
```

---

## 5. Camada Contexto

### 5.1 ContextRetrievalPlan

Output deterministico do `DocContextTierSelector`. Lista tiers + budget + sources requeridas.

#### Schema

```yaml
ContextRetrievalPlan:
  schema_version: atlas.dev.context_retrieval_plan.v1
  run_id: string
  compact_sdd_hash: string
  tiers_selected: list           # subset de [core, code_intelligence, sdd, interface, forge, obras]
  budget:
    max_chars: integer
    reserved_for_core: integer
    reserved_for_code_intelligence: integer
  required_sources: list         # refs que nao podem faltar
  optional_sources: list
  excluded_sources: list         # explicitamente excluidos
  selection_reasons:             # por que cada tier entrou
    - tier: string
      reason: string
  provider_safe: true
  plan_hash: string
```

#### Invariants

1. `tiers_selected` segue regras da tabela 11.1 do contrato principal.
2. `core` esta em `tiers_selected` se `task_kind != question`.
3. `code_intelligence` esta em `tiers_selected` se `workspace_resolved = true`.
4. `forge` em `tiers_selected` implica `risk_level >= R4`.
5. `required_sources` so vazio se `task_kind = question` em modo read-only.
6. `budget.reserved_for_core + budget.reserved_for_code_intelligence <= budget.max_chars`.

#### Identidade

- Chave: `(run_id, plan_hash)`.

#### Exemplo Valido

```yaml
schema_version: atlas.dev.context_retrieval_plan.v1
run_id: "0192b5d2-..."
compact_sdd_hash: "feedcafe..."
tiers_selected: [core, code_intelligence, sdd]
budget:
  max_chars: 12000
  reserved_for_core: 3000
  reserved_for_code_intelligence: 6000
required_sources:
  - "atlas-dev-efficient-programming-flow-v1.md#section-9"
  - "code_intelligence://symbol/AtlasCliDevWorkflowService"
optional_sources:
  - "atlas-programming-governance-system.md"
excluded_sources:
  - "atlas-forge-operating-system.md"  # task nao e Forge
selection_reasons:
  - tier: core
    reason: "task_kind != question"
  - tier: code_intelligence
    reason: "workspace presente, codigo envolvido"
  - tier: sdd
    reason: "task_kind = repair, multi-arquivo possivel"
provider_safe: true
plan_hash: "c0ffee..."
```

#### PHP DTO Signature

```php
final class ContextRetrievalPlan
{
    public function __construct(
        public readonly string $runId,
        public readonly string $compactSddHash,
        public readonly array $tiersSelected,
        public readonly ContextBudget $budget,
        public readonly array $requiredSources,
        public readonly array $optionalSources,
        public readonly array $excludedSources,
        public readonly array $selectionReasons,
        public readonly string $planHash,
    ) {}

    public function schemaVersion(): string { return 'atlas.dev.context_retrieval_plan.v1'; }
}
```

---

### 5.2 CodeDiscoveryManifest

Likely files/symbols/tests com niveis de confianca. Substitui "Opus alucina path" pelo Atlas com lacunas explicitas.

#### Schema

```yaml
CodeDiscoveryManifest:
  schema_version: atlas.dev.code_discovery_manifest.v1
  run_id: string
  compact_sdd_hash: string
  objective: string
  confidence: confirmed_fact|strong_inference|hypothesis|blocking_ambiguity
  likely_files:
    - path: string                # path absoluto validado por filesystem
      reason: string
      confidence: number          # 0.0 - 1.0
      symbols: list               # simbolos relevantes naquele arquivo
  related_symbols:
    - name: string
      kind: class|function|method|trait|interface|type
      file: string
      line: integer|null
  related_tests:
    - path: string
      reason: string
  forbidden_files: list           # paths que NAO podem ser tocados
  missing_refs:                   # lacunas honestas; nao inventar path
    - what: string
      why_missing: string
  provider_safe: true
  manifest_hash: string
```

#### Invariants

1. `likely_files[].path` deve existir no filesystem ao tempo da geracao (`is_file()` true).
2. `likely_files[].confidence` in [0.0, 1.0].
3. `manifest.confidence = blocking_ambiguity` impede progredir; runtime marca `blocked`.
4. `missing_refs` nao vazio se confidence < strong_inference.
5. `related_tests` nao vazio se `task_kind in (patch, repair)` e ha testes no workspace.
6. Nenhum path em `likely_files` aparece em `forbidden_files`.
7. Paths nao validados nao entram em `likely_files` — vao para `missing_refs`.

#### Identidade

- Chave: `(run_id, manifest_hash)`.

#### Exemplo Valido

```yaml
schema_version: atlas.dev.code_discovery_manifest.v1
run_id: "0192b5d2-..."
compact_sdd_hash: "feedcafe..."
objective: "Localizar codigo do bug em AtlasCliDevWorkflowService"
confidence: strong_inference
likely_files:
  - path: "/Users/op/code/atlas-server/app/Services/Ai/Cli/AtlasCliDevWorkflowService.php"
    reason: "nome direto no test name"
    confidence: 0.95
    symbols: ["AtlasCliDevWorkflowService", "resolveWorkspace"]
related_symbols:
  - name: "AtlasCliDevWorkflowService"
    kind: class
    file: "/Users/op/code/atlas-server/app/Services/Ai/Cli/AtlasCliDevWorkflowService.php"
    line: 45
related_tests:
  - path: "/Users/op/code/atlas-server/tests/Unit/AtlasCliDevWorkflowServiceTest.php"
    reason: "teste falhando explicito no intent"
forbidden_files:
  - "vendor/*"
  - "node_modules/*"
missing_refs: []
provider_safe: true
manifest_hash: "dec0ded0..."
```

#### Exemplos Invalidos

| Caso | Motivo |
| --- | --- |
| `likely_files[0].path` aponta para arquivo que nao existe | Violacao de invariant 1 |
| `likely_files[0].confidence: 1.5` | Violacao de invariant 2 |
| `confidence: blocking_ambiguity` + downstream gera task_contract | Violacao de invariant 3 |
| `missing_refs: []` + `confidence: hypothesis` | Violacao de invariant 4 |
| Path inventado sem `is_file()` check | Violacao de invariant 7 |

#### PHP DTO Signature

```php
final class CodeDiscoveryManifest
{
    public function __construct(
        public readonly string $runId,
        public readonly string $compactSddHash,
        public readonly string $objective,
        public readonly string $confidence,
        public readonly array $likelyFiles,
        public readonly array $relatedSymbols,
        public readonly array $relatedTests,
        public readonly array $forbiddenFiles,
        public readonly array $missingRefs,
        public readonly string $manifestHash,
    ) {}

    public function isBlocking(): bool { return $this->confidence === 'blocking_ambiguity'; }
    public function schemaVersion(): string { return 'atlas.dev.code_discovery_manifest.v1'; }
}
```

---

### 5.3 OpenBrainProgrammingProjection

Projection compacta do Open Brain. Refs preferidas a texto.

#### Schema

```yaml
OpenBrainProgrammingProjection:
  schema_version: atlas.open_brain.programming_projection.v1
  run_id: string
  surface: string
  mode: programming
  workspace_hash: string
  objective_hash: string          # sha256(normalized_intent)
  tier_selection: list
  context_pack_hash: string
  memory_refs:                    # refs, nao texto
    - kind: decision|learning|technical_context|harness_learning
      ref: string
      reason: string
  knowledge_refs:
    - kind: doc|section
      ref: string
      reason: string
  code_refs:
    - kind: symbol|file|route|command|test|doc_link
      ref: string
      reason: string
  selected_files: list
  required_sources: list
  missing_sources: list
  budget:
    chars_requested: integer
    chars_used: integer
  truncation:
    truncated: boolean
    reasons: list
  provider_safe: true
  projection_hash: string
```

#### Invariants

1. `schema_version` e fixo `atlas.open_brain.programming_projection.v1` (alinhado ao schema existente do Open Brain).
2. `mode` e sempre `programming` neste fluxo.
3. `objective_hash = sha256(normalized_intent)` para audit reproducible.
4. `budget.chars_used <= budget.chars_requested`.
5. `truncation.truncated = true` exige `truncation.reasons` nao vazio.
6. `missing_sources` nao vazio bloqueia progresso se `required_sources` faltar.
7. `provider_safe: true` sempre (Open Brain ja sanitiza); se houver risco, projection deve ser regenerada.

#### Identidade

- Chave: `(run_id, projection_hash)`.

#### PHP DTO Signature

```php
final class OpenBrainProgrammingProjection
{
    public function __construct(
        public readonly string $runId,
        public readonly string $surface,
        public readonly string $workspaceHash,
        public readonly string $objectiveHash,
        public readonly array $tierSelection,
        public readonly string $contextPackHash,
        public readonly array $memoryRefs,
        public readonly array $knowledgeRefs,
        public readonly array $codeRefs,
        public readonly array $selectedFiles,
        public readonly array $requiredSources,
        public readonly array $missingSources,
        public readonly Budget $budget,
        public readonly Truncation $truncation,
        public readonly string $projectionHash,
    ) {}

    public function schemaVersion(): string { return 'atlas.open_brain.programming_projection.v1'; }
}
```

---

### 5.4 ProviderPromptProjection

O prompt enviado ao provider nao pode ser improvisado. E projecao deterministica de todos os artefatos anteriores.

#### Schema

```yaml
ProviderPromptProjection:
  schema_version: atlas.dev.provider_prompt_projection.v1
  run_id: string
  task_contract_hash: string      # raiz da projecao
  provider: claude_cli
  model_family: sonnet
  sections:
    objective: string             # = mini_spec.goal
    operating_rules: list         # regras gerais do Atlas Dev
    mini_spec_ref: string         # hash + path do mini_spec
    task_contract_ref: string     # hash + path do task_contract
    context_refs: list            # refs do open_brain_projection
    code_discovery_ref: string    # hash + path do code_discovery_manifest
    allowed_files: list
    forbidden_files: list
    expected_tests: list
    acceptance_criteria: list
    stop_conditions: list
    escalation_conditions: list
    output_contract:              # como o provider deve responder
      - "diff em formato unified"
      - "lista de changed_files"
      - "razao se no_patch_needed"
  quality_checks:
    no_missing_required_sections: boolean
    no_unbounded_scope: boolean
    no_hidden_benchmark_instruction: boolean
    no_conflicting_file_rules: boolean
    no_forge_or_council_leakage: boolean
    provider_safe: boolean
  rendered_prompt_text: string       # obrigatorio; gerado pelo ProviderPromptBuilder
  prompt_projection_hash: string
```

#### Invariants

1. Toda secao obrigatoria nao vazia. Falta = `no_missing_required_sections: false` = bloqueia envio.
2. `allowed_files` e `forbidden_files` consistentes com `task_contract`.
3. `output_contract` exige resposta verificavel (diff/lista, nao texto livre).
4. `no_hidden_benchmark_instruction: false` bloqueia. Prompt nao pode conter referencias a Rivals/medicao.
5. `no_forge_or_council_leakage: false` bloqueia. Prompt nao pede council/topology/Forge direto.
6. `no_conflicting_file_rules: false` bloqueia. Allowed e forbidden disjuntos.
7. `rendered_prompt_text` e obrigatorio antes de qualquer provider call.
8. `prompt_projection_hash` muda a cada mudanca em qualquer secao ou no rendered prompt.

#### Identidade

- Chave: `(run_id, prompt_projection_hash)`.

#### PHP DTO Signature

```php
final class ProviderPromptProjection
{
    public function __construct(
        public readonly string $runId,
        public readonly string $taskContractHash,
        public readonly string $provider,
        public readonly string $modelFamily,
        public readonly PromptSections $sections,
        public readonly QualityChecks $qualityChecks,
        public readonly string $renderedPromptText,
        public readonly string $promptProjectionHash,
    ) {}

    public function isSendable(): bool { return $this->qualityChecks->allPassed(); }
    public function schemaVersion(): string { return 'atlas.dev.provider_prompt_projection.v1'; }
}
```

---

## 6. Camada Receipt

### 6.1 ScopeGuardReceipt

Diff observado vs escopo declarado. Bloqueia completion quando ha violacao.

#### Schema

```yaml
ScopeGuardReceipt:
  schema_version: atlas.dev.scope_guard_receipt.v1
  run_id: string
  task_contract_hash: string
  baseline:
    git_status_before: string     # output do git status pre-execucao
    git_diff_before_hash: string|null
  observed:
    git_diff_hash: string|null
    changed_files: list
    changed_files_count: integer
    file_diffs:
      - path: string
        added: integer
        removed: integer
        file_hash_after: string
  scope_contract:
    allowed_files: list
    watched_files: list
    forbidden_files: list
    expected_max_files: integer
  violations:
    - kind: forbidden_touch|watched_touch|unexpected_touch|exceeded_max_files|pre_existing_change
      path: string|null
      detail: string
  status: passed|failed|needs_review
  status_reason: string
  user_pre_existing_changes:      # mudancas que ja estavam no worktree
    - path: string
      preserved: boolean
  provider_safe: true
  receipt_hash: string
```

#### Invariants

1. `changed_files_count = len(changed_files)`.
2. Qualquer `violations[].kind = forbidden_touch` forca `status = failed`.
3. `violations[].kind = unexpected_touch` forca `status = needs_review` (nao failed).
4. `violations[].kind = exceeded_max_files` forca `status = failed`.
5. `user_pre_existing_changes` deve ser preservado; `preserved: false` em qualquer item forca `status = failed`.
6. `status = passed` exige `violations` vazio.
7. `receipt_hash` calculado sobre todo o payload (exclui o proprio).

#### Identidade

- Chave: `(run_id, receipt_hash)`.

#### PHP DTO Signature

```php
final class ScopeGuardReceipt
{
    public function __construct(
        public readonly string $runId,
        public readonly string $taskContractHash,
        public readonly ScopeBaseline $baseline,
        public readonly ScopeObserved $observed,
        public readonly ScopeContractView $scopeContract,
        public readonly array $violations,
        public readonly string $status,
        public readonly string $statusReason,
        public readonly array $userPreExistingChanges,
        public readonly string $receiptHash,
    ) {}

    public function isBlocking(): bool { return $this->status === 'failed'; }
    public function schemaVersion(): string { return 'atlas.dev.scope_guard_receipt.v1'; }
}
```

---

### 6.2 VerificationReceipt

Receipt final. Consolida gates, testes, custo, completion state, escalation.

#### Schema

```yaml
VerificationReceipt:
  schema_version: atlas.dev.verification_receipt.v1
  run_id: string
  task_contract_hash: string
  workspace_hash: string
  task_kind: question|patch|repair|review|frontend|risky
  risk_level: R0|R1|R2|R3|R4|R5
  provider: claude_cli
  model: string                     # ex: "claude-sonnet-4-6"
  context_pack_hash: string
  prompt_projection_hash: string
  scope_guard_receipt_hash: string|null
  diff_hash: string|null
  changed_files: list
  file_hashes: map                  # path -> sha256 do conteudo apos
  evidence_refs:                    # paths absolutos para storage + ref opcional do ledger Governance
    - kind: diff|test_log|lint_log|screenshot|manual_review|no_patch_reason
      path: string
      hash: string
      governance_ledger_ref: string|null
  gates:
    - name: string                  # ex: scope_guard_light
      status: passed|failed|needs_review|skipped|waived
      required: boolean
      evidence_ref: string|null
      fresh: boolean                # roda nesta run, nao cache
      waiver_reason: string|null
  tests:
    - command: string
      ok: boolean
      exit_code: integer
      duration_ms: integer
      output_hash: string
      output_path: string|null      # path do log persistido
  repair:
    attempt_count: integer
    failure_capsule_refs: list      # paths dos failure_capsule.json
    converted_to_green: boolean
  cost:
    provider_calls: integer
    tokens_in: integer|null
    tokens_out: integer|null
    estimated_cost_usd: number|null
    wall_time_ms: integer|null
  completion:
    status: passed|needs_review|failed|blocked|escalate_forge|no_patch_needed
    honesty_flags: list             # ex: ["test_skipped_no_reason", "scope_expanded"]
    residual_risks: list
  escalation:
    recommended: boolean
    target: null|forge|obra_candidate
    reasons: list
    decision_ref: string|null       # path do EscalationDecision se gerado
  provider_safe: true
  receipt_hash: string
```

#### Invariants

1. `completion.status = passed` exige:
   - todos `gates[].required = true` com `status = passed`;
   - `scope_guard_receipt.status = passed`;
   - se houver `tests`, pelo menos um com `ok = true` ou um `evidence_refs[].kind = no_patch_reason`.
2. `completion.status = passed` proibe `honesty_flags` nao vazio.
3. `completion.status = needs_review` se houver `honesty_flags` mas evidencia minima presente.
4. `completion.status = escalate_forge` forca `escalation.recommended = true` E `escalation.target != null`.
5. `provider` e `model` registrados literalmente; nao mascarar fallback.
6. `repair.attempt_count <= task_contract.repair_policy.max_attempts`.
7. `gates[].fresh = true` para todo gate `required = true` (nao confiar em cache).
8. `cost.provider_calls >= 1` exceto em `mode = read_only`.

#### Identidade

- Chave: `(run_id, receipt_hash)`.

#### Exemplo Valido (Patch R2 Passed)

```yaml
schema_version: atlas.dev.verification_receipt.v1
run_id: "0192b5d2-..."
task_contract_hash: "ace5beef..."
workspace_hash: "a1b2c3..."
task_kind: repair
risk_level: R2
provider: claude_cli
model: "claude-sonnet-4-6"
context_pack_hash: "c0ffee..."
prompt_projection_hash: "f00dface..."
scope_guard_receipt_hash: "ace0..."
diff_hash: "deadc0de..."
changed_files:
  - "app/Services/Ai/Cli/AtlasCliDevWorkflowService.php"
file_hashes:
  "app/Services/Ai/Cli/AtlasCliDevWorkflowService.php": "abcd1234..."
evidence_refs:
  - kind: diff
    path: "storage/atlas-dev/receipts/<run_id>/diff.patch"
    hash: "deadc0de..."
    governance_ledger_ref: "atlas_engineering_evidence:123"
  - kind: test_log
    path: "storage/atlas-dev/receipts/<run_id>/test.log"
    hash: "1234abcd..."
    governance_ledger_ref: "atlas_engineering_evidence:124"
gates:
  - name: scope_guard_light
    status: passed
    required: true
    evidence_ref: "storage/atlas-dev/receipts/<run_id>/scope_guard_receipt.json"
    fresh: true
    waiver_reason: null
  - name: verification_gate
    status: passed
    required: true
    evidence_ref: "storage/atlas-dev/receipts/<run_id>/test.log"
    fresh: true
    waiver_reason: null
tests:
  - command: "composer test -- --filter=AtlasCliDevWorkflowServiceTest::test_workspace_resolution"
    ok: true
    exit_code: 0
    duration_ms: 1342
    output_hash: "1234abcd..."
    output_path: "storage/atlas-dev/receipts/<run_id>/test.log"
repair:
  attempt_count: 0
  failure_capsule_refs: []
  converted_to_green: false
cost:
  provider_calls: 1
  tokens_in: 3420
  tokens_out: 412
  estimated_cost_usd: 0.018
  wall_time_ms: 8431
completion:
  status: passed
  honesty_flags: []
  residual_risks: []
escalation:
  recommended: false
  target: null
  reasons: []
  decision_ref: null
provider_safe: true
receipt_hash: "ledgerend..."
```

#### Exemplos Invalidos

| Caso | Motivo |
| --- | --- |
| `completion.status: passed` + `honesty_flags: ["scope_expanded"]` | Violacao de invariant 2 |
| `completion.status: passed` + `gates[].status: failed` (required true) | Violacao de invariant 1 |
| `repair.attempt_count: 3` + `task_contract.max_attempts: 1` | Violacao de invariant 6 |
| `completion.status: escalate_forge` + `escalation.target: null` | Violacao de invariant 4 |
| `provider: claude_cli` + na realidade rodou Codex | Violacao de invariant 5 (mascaramento) |

#### PHP DTO Signature

```php
final class VerificationReceipt
{
    public function __construct(
        public readonly string $runId,
        public readonly string $taskContractHash,
        public readonly string $workspaceHash,
        public readonly string $taskKind,
        public readonly string $riskLevel,
        public readonly string $provider,
        public readonly string $model,
        public readonly string $contextPackHash,
        public readonly string $promptProjectionHash,
        public readonly ?string $scopeGuardReceiptHash,
        public readonly ?string $diffHash,
        public readonly array $changedFiles,
        public readonly array $fileHashes,
        public readonly array $evidenceRefs,
        public readonly array $gates,
        public readonly array $tests,
        public readonly RepairSummary $repair,
        public readonly CostSummary $cost,
        public readonly CompletionSummary $completion,
        public readonly EscalationSummary $escalation,
        public readonly string $receiptHash,
    ) {}

    public function isPassed(): bool { return $this->completion->status === 'passed'; }
    public function schemaVersion(): string { return 'atlas.dev.verification_receipt.v1'; }
}
```

---

### 6.3 FailureCapsule

Input deterministico para repair. Uma por tentativa. Contem erro real, nao "falhou de novo".

#### Schema

```yaml
FailureCapsule:
  schema_version: atlas.dev.failure_capsule.v1
  run_id: string
  task_contract_hash: string
  attempt_index: integer
  gate: string                    # ex: verification_gate, scope_guard_light
  command: string|null
  exit_code: integer|null
  primary_error_excerpt: string   # max 4kb, suficiente para erro principal
  full_error_log_path: string|null # path para log completo se relevante
  failing_test: string|null
  diff_hash: string|null
  changed_files: list
  failure_signature: string       # hash estavel para detectar repeticao
  decision: retry|stop|escalate
  should_have_escalated: boolean|null  # preenchido post-hoc por revisor
  escalation_signal_delta: list   # quais sinais teriam disparado escalada
  post_hoc_reviewer: string|null
  post_hoc_reviewed_at: string|null  # ISO 8601
  provider_safe: true
  capsule_hash: string
```

#### Invariants

1. `failure_signature` e estavel sobre o mesmo erro: `sha256(gate + "::" + normalize(primary_error_excerpt))`. Repeticao detecta loop.
2. `decision = retry` exige `attempt_index < task_contract.repair_policy.max_attempts`.
3. `decision = escalate` exige preenchimento de `escalation_signal_delta`.
4. `primary_error_excerpt` nunca vazio quando `exit_code != 0` ou `failing_test != null`.
5. `should_have_escalated`, `post_hoc_reviewer`, `post_hoc_reviewed_at` sao null no nascimento; preenchidos depois por revisor (nao apaga, so add).
6. `capsule_hash` ignora os 4 campos post-hoc (eles podem mudar sem invalidar a capsule).

#### Identidade

- Chave: `(run_id, attempt_index)`.

#### Exemplo Valido (Tentativa 1 Falhou)

```yaml
schema_version: atlas.dev.failure_capsule.v1
run_id: "0192b5d2-..."
task_contract_hash: "ace5beef..."
attempt_index: 1
gate: verification_gate
command: "composer test -- --filter=AtlasCliDevWorkflowServiceTest::test_workspace_resolution"
exit_code: 1
primary_error_excerpt: |
  FAIL  Tests\Unit\AtlasCliDevWorkflowServiceTest
   ⨯ test workspace resolution
   InvalidArgumentException: workspace must not be null
   at app/Services/Ai/Cli/AtlasCliDevWorkflowService.php:78
full_error_log_path: "storage/atlas-dev/receipts/<run_id>/failure_capsule.1.log"
failing_test: "tests/Unit/AtlasCliDevWorkflowServiceTest.php::test_workspace_resolution"
diff_hash: "deadc0de..."
changed_files:
  - "app/Services/Ai/Cli/AtlasCliDevWorkflowService.php"
failure_signature: "sha256:verification_gate::InvalidArgumentException::workspace_null"
decision: retry
should_have_escalated: null
escalation_signal_delta: []
post_hoc_reviewer: null
post_hoc_reviewed_at: null
provider_safe: true
capsule_hash: "abadcafe..."
```

#### PHP DTO Signature

```php
final class FailureCapsule
{
    public function __construct(
        public readonly string $runId,
        public readonly string $taskContractHash,
        public readonly int $attemptIndex,
        public readonly string $gate,
        public readonly ?string $command,
        public readonly ?int $exitCode,
        public readonly string $primaryErrorExcerpt,
        public readonly ?string $fullErrorLogPath,
        public readonly ?string $failingTest,
        public readonly ?string $diffHash,
        public readonly array $changedFiles,
        public readonly string $failureSignature,
        public readonly string $decision,
        public readonly ?bool $shouldHaveEscalated,
        public readonly array $escalationSignalDelta,
        public readonly ?string $postHocReviewer,
        public readonly ?string $postHocReviewedAt,
        public readonly string $capsuleHash,
    ) {}

    public function withPostHocReview(string $reviewer, bool $shouldHaveEscalated, array $delta): self { /* monotonic append */ }
    public function schemaVersion(): string { return 'atlas.dev.failure_capsule.v1'; }
}
```

---

### 6.4 EscalationDecision

Quando o fast path para e gera preview para Forge.

#### Schema

```yaml
EscalationDecision:
  schema_version: atlas.dev.escalation_decision.v1
  run_id: string
  task_contract_hash: string
  triggered_at: string            # ISO 8601
  target: forge|obra_candidate
  reasons: list                   # quais condicoes acionaram
  signals:
    file_count: integer
    layers_touched: integer
    risk_keywords: list
    context_required_chars: integer|null
    thread_messages: integer|null
    prior_failure_count: integer
  score: integer                  # 0-10
  human_action_required: boolean
  preview_artifact_path: string|null  # path do Forge promotion preview
  was_correct: boolean|null       # post-hoc
  post_hoc_reviewer: string|null
  post_hoc_reviewed_at: string|null
  provider_safe: true
  decision_hash: string
```

#### Invariants

1. `target = forge` exige `score >= 7` OU `risk_level >= R4`.
2. `target = obra_candidate` exige `score >= 4`.
3. `reasons` nao vazio.
4. `human_action_required = true` quando `target = forge`.
5. `was_correct`, `post_hoc_reviewer`, `post_hoc_reviewed_at` sao null no nascimento; append-only depois.

#### Identidade

- Chave: `(run_id, decision_hash)`.

#### PHP DTO Signature

```php
final class EscalationDecision
{
    public function __construct(
        public readonly string $runId,
        public readonly string $taskContractHash,
        public readonly string $triggeredAt,
        public readonly string $target,
        public readonly array $reasons,
        public readonly EscalationSignals $signals,
        public readonly int $score,
        public readonly bool $humanActionRequired,
        public readonly ?string $previewArtifactPath,
        public readonly ?bool $wasCorrect,
        public readonly ?string $postHocReviewer,
        public readonly ?string $postHocReviewedAt,
        public readonly string $decisionHash,
    ) {}

    public function schemaVersion(): string { return 'atlas.dev.escalation_decision.v1'; }
}
```

---

## 7. Camada Telemetria

### 7.1 FastPathTelemetry

Sinal operacional desde dia 1. Permite inspecionar runs sem benchmark.

#### Schema

```yaml
FastPathTelemetry:
  schema_version: atlas.dev.fast_path_telemetry.v1
  run_id: string
  workspace_hash: string
  task_kind: question|patch|repair|review|frontend|risky
  risk_level: R0|R1|R2|R3|R4|R5
  prompt_projection_hash: string
  contract_completeness_status: passed|failed|needs_review
  doc_tiers_selected: list
  gates_activated: list
  provider: claude_cli
  model: string
  provider_calls: integer
  repair_attempts: integer
  cost_estimate_usd: number|null
  wall_time_ms: integer|null
  completion_state: passed|needs_review|failed|blocked|escalate_forge|no_patch_needed
  escalation_triggered: boolean
  escalation_was_correct: boolean|null
  receipt_persisted: boolean
  error_ledger_written: boolean
  provider_safe: true
  telemetry_hash: string
```

#### Invariants

1. Emitido **uma vez por run**, no fim (mesmo em `blocked` ou `failed`).
2. `receipt_persisted = false` indica bug operacional, nao falha legitima.
3. `error_ledger_written = true` quando `completion_state in (failed, needs_review, escalate_forge)`.
4. `escalation_was_correct` e null ate revisao post-hoc.
5. `telemetry_hash` calculado excluindo `escalation_was_correct` (post-hoc nao invalida telemetria do run).

#### Identidade

- Chave: `(run_id)`.

#### PHP DTO Signature

```php
final class FastPathTelemetry
{
    public function __construct(
        public readonly string $runId,
        public readonly string $workspaceHash,
        public readonly string $taskKind,
        public readonly string $riskLevel,
        public readonly string $promptProjectionHash,
        public readonly string $contractCompletenessStatus,
        public readonly array $docTiersSelected,
        public readonly array $gatesActivated,
        public readonly string $provider,
        public readonly string $model,
        public readonly int $providerCalls,
        public readonly int $repairAttempts,
        public readonly ?float $costEstimateUsd,
        public readonly ?int $wallTimeMs,
        public readonly string $completionState,
        public readonly bool $escalationTriggered,
        public readonly ?bool $escalationWasCorrect,
        public readonly bool $receiptPersisted,
        public readonly bool $errorLedgerWritten,
        public readonly string $telemetryHash,
    ) {}

    public function schemaVersion(): string { return 'atlas.dev.fast_path_telemetry.v1'; }
}
```

---

### 7.2 FastPathErrorLedgerEntry

Registro append-only de erros operacionais e missed escalations. Alimenta tuning futuro de thresholds.

#### Schema

```yaml
FastPathErrorLedgerEntry:
  schema_version: atlas.dev.fast_path_error_ledger.v1
  run_id: string
  failure_signature: string
  completion_state: passed|needs_review|failed|blocked|escalate_forge
  actual_failure_mode: wrong_file|wrong_scope|missed_test|bad_repair|missed_escalation|false_escalation|prompt_projection_error|context_error|other
  should_have_escalated: boolean|null
  missing_escalation_signals: list
  observed_signals:
    file_count: integer
    layers_touched: integer
    risk_keywords: list
    context_required_chars: integer|null
    prior_failure_in_area: boolean|null
    test_coverage_gap: boolean|null
  correction_recommendation: list
  reviewer_signed: boolean
  reviewer: string|null
  reviewed_at: string|null
  provider_safe: true
  entry_hash: string
```

#### Invariants

1. `reviewer_signed = true` exige `reviewer` e `reviewed_at` preenchidos.
2. `actual_failure_mode = missed_escalation` exige `should_have_escalated = true` E `missing_escalation_signals` nao vazio.
3. Append-only: entradas nao sao mutadas apos `reviewer_signed = true`.
4. Multiplas entradas por run sao permitidas (ex: missed escalation + wrong scope no mesmo run).

#### Identidade

- Chave: `(run_id, entry_hash)`.

#### PHP DTO Signature

```php
final class FastPathErrorLedgerEntry
{
    public function __construct(
        public readonly string $runId,
        public readonly string $failureSignature,
        public readonly string $completionState,
        public readonly string $actualFailureMode,
        public readonly ?bool $shouldHaveEscalated,
        public readonly array $missingEscalationSignals,
        public readonly ObservedSignals $observedSignals,
        public readonly array $correctionRecommendation,
        public readonly bool $reviewerSigned,
        public readonly ?string $reviewer,
        public readonly ?string $reviewedAt,
        public readonly string $entryHash,
    ) {}

    public function schemaVersion(): string { return 'atlas.dev.fast_path_error_ledger.v1'; }
}
```

---

## 8. PHP DTOs E Localizacao

### 8.1 Namespace

`App\Services\Ai\Programming\AtlasDev\Schemas\` (decisao locked 2026-05-16).

### 8.2 Mapa De Arquivos

```text
atlas-server/app/Services/Ai/Programming/AtlasDev/Schemas/
  OperationEnvelope.php
  CompactSdd.php
  MiniProgrammingSpec.php
  LightTaskContract.php
  ContextRetrievalPlan.php
  CodeDiscoveryManifest.php
  OpenBrainProgrammingProjection.php
  ProviderPromptProjection.php
  ScopeGuardReceipt.php
  VerificationReceipt.php
  FailureCapsule.php
  EscalationDecision.php
  FastPathTelemetry.php
  FastPathErrorLedgerEntry.php
  Components/
    GitState.php
    Preflight.php
    ContextBudget.php
    VerificationPlan.php
    RepairPolicy.php
    ProviderLock.php
    Budget.php
    Truncation.php
    PromptSections.php
    QualityChecks.php
    ScopeBaseline.php
    ScopeObserved.php
    ScopeContractView.php
    RepairSummary.php
    CostSummary.php
    CompletionSummary.php
    EscalationSummary.php
    EscalationSignals.php
    ObservedSignals.php
  Contracts/
    AtlasDevSchemaContract.php   # interface comum: schemaVersion(), toCanonicalArray(), hash()
```

### 8.3 Padroes De DTO

- `final class` para impedir extensao acidental.
- Todos os campos `public readonly` (PHP 8.1+).
- Construtor recebe **todos** os campos; nenhum opcional sem default explicito.
- `schemaVersion(): string` retorna constante.
- `toCanonicalArray(): array` retorna array com chaves ordenadas (alphabetical recursivo).
- `toJson(): string` retorna `json_encode(toCanonicalArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)`.
- `hash(): string` retorna `hash('sha256', $this->toJson())`, excluindo o proprio campo `<entity>_hash` durante o calculo.
- Construtores **nao** validam invariants. Validacao acontece em validators dedicados (ver `AtlasDevSchemaValidator` no runbook).

### 8.4 Hash Sem Recursao

Cada DTO calcula seu hash **sem** incluir o proprio campo de hash. Ordem:

```php
$canonical = $this->toCanonicalArrayWithoutHash();
$json = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$hash = hash('sha256', $json);
```

Quando o DTO referencia outro DTO por hash (ex: `task_contract_hash` em `VerificationReceipt`), o hash **e** incluido no payload — e referencia estavel, nao auto-referencia.

---

## 9. Regras De Uso

### 9.1 Sequencia Obrigatoria

```text
OperationEnvelope -> ContextRetrievalPlan -> CodeDiscoveryManifest
-> OpenBrainProgrammingProjection -> CompactSDD
-> MiniProgrammingSpec -> LightTaskContract -> ProviderPromptProjection
-> [provider call] -> ScopeGuardReceipt -> VerificationReceipt
-> FailureCapsule (se aplicavel) -> EscalationDecision (se aplicavel)
-> FastPathTelemetry -> FastPathErrorLedgerEntry (se aplicavel)
```

Pular etapa = bug operacional.

### 9.2 Hash Referencia Antes De Construir

Ao construir artefato downstream, **incluir hash do upstream**. Ex: `MiniProgrammingSpec.compact_sdd_hash` e referencia obrigatoria.

Se hash referenciado nao existe ou nao bate com artefato persistido, runtime falha (`receipt_gate` bloqueia).

### 9.3 Provider Safe E Filtros

`provider_safe: true` significa "pode ir no prompt sem revelar dado interno". Filtros aplicaveis ao construir `ProviderPromptProjection`:

- IDs internos viram refs;
- traces/system instructions internas omitidas;
- credenciais nunca aparecem;
- referencias a Rivals/medicao removidas (nao deveriam existir aqui, mas filtro defensivo).

### 9.4 Persistencia Antes Da Proxima Etapa

Cada artefato deve ser persistido em `storage/atlas-dev/receipts/<run_id>/` **antes** de a proxima etapa comecar. Crash entre etapas deve permitir resume baseado no que ja foi persistido.

### 9.5 Versionamento E Compatibilidade

- Leitores **nao** podem ler `vN+1` produzido por escritor mais novo.
- Escritor que sabe ler `vN` e produzir `vN+1` esta autorizado a fazer migracao explicita.
- `schema_version` em todo payload e obrigatorio. Payload sem `schema_version` e rejeitado.

### 9.6 Forbidden Cross-Contamination

- Schemas Atlas Dev **nao** importam schemas de Forge/Rivals/Open Brain alem do que esta declarado em `depends_on`.
- Schemas Atlas Dev **nao** definem regras de medicao/benchmark.
- Mudancas neste doc que adicionem campos relacionados a medicao competitiva sao rejeitadas.

---

## 10. Resumo Operacional

| Camada | Quem produz | Quem consome |
| --- | --- | --- |
| Plano | Intake + Classifier + Spec Composer | Pipeline e Provider Builder |
| Contexto | Tier Selector + Code Discovery + Open Brain Adapter + Prompt Builder | Provider chamado, validators |
| Receipt | Scope Guard + Verifier + Repair Loop + Escalation Engine | Completion gate, persistence |
| Telemetria | Telemetry Emitter + Error Ledger Writer | Sessao posterior, revisao operacional |

Schemas sao a fronteira contratual. Tudo o que cruza fatia cruza um schema deste doc.
