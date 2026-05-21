---
id: atlas-dev-efficient-programming-flow-runbook-v1-part-08
type: engineering_knowledge
title: Atlas Dev Efficient Programming Flow Runbook v1 · Parte 8
status: active
category: programming
priority: 104
summary: Recorte focado do runbook Atlas Dev Efficient Programming Flow v1: 12.2 PRs Sugeridos ate 15.1.9 Identidade do fluxo (intake invariants).
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
graph_id: atlas-dev-efficient-programming-flow-runbook-v1-part-08
graph_title: Atlas Dev Efficient Programming Flow Runbook v1 Parte 8
graph_world: atlas
graph_layer: module
graph_kind: runbook
graph_parent: atlas-dev-efficient-programming-flow-runbook-v1
graph_status: active
graph_source: repo
human_name: Atlas Dev Efficient Programming Flow Runbook v1 Parte 8
canonical_name: Atlas Dev Efficient Programming Flow Runbook v1 Parte 8
technical_name: atlas-dev-efficient-programming-flow-runbook-v1-part-08
cartography_type: runbook
canonical_source: docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-08.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-08.md
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
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: high
next_actions:
  - Manter este recorte alinhado ao índice canônico e ao runbook operacional.
---
# Atlas Dev Efficient Programming Flow Runbook v1 · Parte 8

## Resumo

Este recorte preserva uma parte operacional do runbook Atlas Dev Efficient Programming Flow v1: 12.2 PRs Sugeridos ate 15.1.9 Identidade do fluxo (intake invariants).

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
### 12.2 PRs Sugeridos

#### PR 5.1 — EscalationDecisionEngine

Arquivo:

```text
app/Services/Ai/Programming/AtlasDev/Escalation/EscalationDecisionEngine.php
```

Signature:

```php
final class EscalationDecisionEngine
{
    public function evaluate(
        OperationEnvelope $envelope,
        CompactSdd $compactSdd,
        CodeDiscoveryManifest $discovery,
        ?VerificationReceipt $receipt = null,
        ?array $failureCapsules = null,
    ): ?EscalationDecision;
}
```

Algoritmo:

1. coletar sinais (file_count, layers_touched, risk_keywords, context_required_chars, thread_messages, prior_failure_count);
2. calcular escalation_score (0-10) por heuristica documentada no contrato principal secao 16;
3. decidir target:
   - score >= 7 OU risk_level >= R4 -> `forge`;
   - score >= 4 -> `obra_candidate`;
   - senao -> retorna null (sem escalada).
4. preencher reasons e human_action_required.

DoD do PR 5.1:

- [x] 3 cenarios cobertos: sem escalada, obra_candidate, forge.
- [x] Score determinístico.

#### PR 5.2 — Integrar Escalation no Pipeline

`AtlasDevFastPathOrchestrator` ganha:

```php
private function maybeEscalate(...): ?EscalationDecision
```

Chamado:

- antes da provider call se R4+ no CompactSdd;
- depois de cada repair attempt;
- antes de retornar PatchResult.

DoD do PR 5.2:

- [x] Cenario R4 -> escalation antes de provider call, sem patch.
- [x] Cenario repair gerando escalate -> EscalationDecision persistido.
- [x] EscalationDecision aparece no VerificationReceipt.

#### PR 5.3 — Evoluir AtlasCliDevWorkflowService

Arquivo:

```text
app/Services/Ai/Cli/AtlasCliDevWorkflowService.php   # editar
```

Adicionar:

```php
public function runAtlasDevEfficient(
    string $rawIntent,
    string $workspace,
    array $options = [],
): mixed;  // PatchResult | PlanOnlyResult
```

Que delega para `AtlasDevFastPathOrchestrator`.

DoD do PR 5.3:

- [x] Service existente nao quebra (testes existentes passam).
- [x] Novo metodo testado.
- [x] `AtlasCliDevCommand` ganha flag `--efficient` para usar o novo path (default permanece comportamento atual ate desbloqueio).

#### PR 5.4a — Atlas AI Desktop Escalation Preview

Arquivo:

```text
app/Services/Ai/Programming/AtlasDev/Surface/AtlasDesktopAiAdapter.php # editar
```

Adicionar endpoint/acao:

```text
POST /ai/interactions/atlas-dev/runs/{run_id}/escalation-preview
```

DoD do PR 5.4a:

- [x] Botao `promover` cria preview Forge via `DevToForgePromotionService`.
- [x] Preview exige acao humana; nenhuma Obra e criada automaticamente.
- [x] Durante run ativo, botao `promover` fica indisponivel.

#### PR 5.4b — Surface Adapters De Paridade

Arquivos:

```text
app/Services/Ai/Programming/AtlasDev/Surface/AtlasCliDevAdapter.php
app/Services/Ai/Programming/AtlasDev/Surface/AtlasAppAdapter.php
app/Services/Ai/Programming/AtlasDev/Surface/AtlasApiInteractionAdapter.php
```

Cada adapter mapeia payload da surface -> `runAtlasDevEfficient()` E formata o resultado de volta para o tipo de resposta da surface.

DoD do PR 5.4b:

- [x] Adapters CLI/App/API implementados.
- [x] Feature test `tests/Feature/AtlasDev/EndToEndSurfaceTest.php` cobre Desktop first + 3 surfaces de paridade.

#### PR 5.5 — Doc Atualizacao + Cert

- Marcar status do `atlas-dev-efficient-programming-flow-v1.md` para `building` (de `draft`).
- Atualizar `atlas-dev-flow-map-and-product-options-v1.md` com referencia ao runtime real.
- Registrar `decision_locked` no doc principal: `atlas_dev_efficient_flow_runtime` -> `available`.
- Adicionar cert local (engenharia, nao competitiva):
  - `atlas:engineering:cert atlas-dev-efficient-flow --register-invariants`;
  - 10-15 invariants (todos os DoDs de fatia) listados.

DoD do PR 5.5:

- [x] Doc principal atualizado.
- [x] Doc caderno atualizado.
- [x] Cert local registrado (`available`).
- [x] **Memoria provider-safe atualizada** se necessario.

### 12.3 DoD Operacional Da Fatia 5

- Atlas AI Desktop Mac responde usando o fluxo novo como surface primaria.
- CLI Dev, App e API respondem usando o fluxo novo como paridade.
- `composer test --filter=AtlasDev` + features verde.
- Comando `atlas:dev:run` em produzao local funciona em todas as surfaces.
- EscalationDecision aparece corretamente em receipts R4+.
- **Esta equipe termina aqui.** Codigo entregue, contrato `available`. Medicao = outra equipe.

---

## 13. Risk Register De Implementacao

| Risco | Severidade | Mitigacao |
| --- | --- | --- |
| Duplicar service em vez de evoluir | alta | Code review enforces reuse map (secao 3). Cert verifica que nao ha `AtlasDevWorkflowServiceV2`. |
| Quality check do prompt nao detectar contaminacao | alta | Testes explicitos de injection ("rivals", "benchmark", "opus") + cert. |
| Receipt nao persistido por crash mid-write | media | Atomic write (tmpfile + rename), verificado em PR 1.5.4. |
| Fallback escondido para outro modelo durante repair | alta | `provider_lock.fallback_allowed=false` enforced em adapter, testado. |
| Validators rejeitando casos validos por bug | media | Cada validator tem case valido completo no teste antes dos cases invalidos. |
| Hashing nao determinístico (chaves nao ordenadas) | alta | Round-trip test em todo DTO. |
| Discovery alucinar path | alta | `is_file()` check enforced + path nao validado vai para missing_refs. |
| Surface adapter quebrar surfaces existentes | media | Feature tests por surface + manter caminho antigo ate `--efficient` ser opt-in. |
| Outro agente sobrescrever este doc enquanto equipe trabalha | media | Doc bem versionado; PRs nao devem editar este runbook em paralelo. |

## 14. Testing Strategy (Engenharia, Nao Competitiva)

Esta secao define **testes mecanicos** que validam o codigo. Nao define benchmark, scoring, oraculos competitivos ou avaliacao Sonnet vs Opus — isso e de outra equipe.

### 14.1 Unit Tests

- Cobertura minima: 95% nas pastas `AtlasDev/`.
- Cada DTO: 5 testes obrigatorios (ver secao 5.5).
- Cada validator: 1 valid + N invariants.
- Cada service: cenario feliz + 2-3 edge cases.

### 14.2 Feature Tests

- 4 end-to-end (plan-only, one-call, repair, surface).
- Gateway mock que simula `claude_cli` retornando diff valido/invalido controladamente.
- Workspace fixture em `tests/Fixtures/AtlasDev/workspace_atlas_server_clone/` (mini-replica de estrutura).

### 14.3 Manual Smoke (uma vez por fatia)

Apos cada fatia ficar verde em CI, **smoke test manual**:

- Fatia 0: nao tem; e so codigo de schema.
- Fatia 1: rodar `php artisan atlas:cli:dev "test" --efficient --json` em modo plan-only e inspecionar JSON.
- Fatia 1.5: rodar plan + inspecionar `storage/atlas-dev/receipts/<run_id>/`.
- Fatia 2: rodar plan-only em workspace real.
- Fatia 3: rodar one-call com Sonnet real em caso seguro (typo em comentario).
- Fatia 4: induzir failure (mexer no teste) e rodar repair.
- Fatia 5: cada surface (CLI, Desktop, App, API) executando o fluxo.

Smoke test **nao** e medicao. E sanidade operacional.

### 14.4 Memoria Provider-Safe E Cert

Ao final de cada fatia, atualizar memoria do Atlas (`php artisan atlas:memory:upsert ...`) com:

- decisao de implementacao da fatia;
- evidence path do PR;
- estado da fatia (`completed`).

E cert engineering:

- `php artisan atlas:engineering:cert atlas-dev-efficient-flow --slice=<n> --status=passed`.

## 15. Definicoes E Glossary

- **Fast path**: caminho default Atlas Dev, baixo custo, governanca compacta.
- **Heavy path**: Forge, governanca pesada, evidence/replay/topology.
- **R-level**: classificacao de risco R0-R5; define gates minimos e estado maximo sem Forge.
- **Mode**: `read_only | plan_only | patch | repair | escalate_preview`.
- **Receipt**: payload deterministico que prova o que aconteceu (ScopeGuardReceipt, VerificationReceipt).
- **Capsule**: `FailureCapsule`, input para repair.
- **DoD**: definition of done; lista verificavel mecanicamente.
- **Provider lock**: contrato que impede troca de provider/model durante run.
- **Hash de identidade**: sha256 sobre JSON canonical, identifica artefato de forma estavel.

## 15.1 Seção Operacional GO (P0/P1 fechados)

Estado canônico do fluxo apos fechamento dos P0/P1. Operador habilita Atlas Dev em produção/staging seguindo a checklist abaixo.

### 15.1.1 Config canônica

- Arquivo único: `config/atlas_dev.php`. `config/atlas.php` **não é fonte** do fluxo.
- Chaves canônicas:
  - `atlas_dev.efficient.plan_enabled` (env `ATLAS_DEV_EFFICIENT_PLAN_ENABLED`) — default `false`.
  - `atlas_dev.efficient.run_enabled` (env `ATLAS_DEV_EFFICIENT_RUN_ENABLED`) — default `false`.
  - `atlas_dev.efficient.desktop_enabled` (env `ATLAS_DEV_EFFICIENT_DESKTOP_ENABLED`, quando presente) — gate da surface Desktop.
  - `atlas_dev.confirmation_token.ttl_seconds` — TTL do confirmation_token (default 300s).
  - `atlas_dev.receipts_path` — diretório de receipts no filesystem.
- Desktop envia o slug do Projeto selecionado; o endpoint Plan resolve esse slug por `config/atlas_projects.php` para `workspace_path` existente antes de chamar o core. CLI/App/API podem enviar path absoluto diretamente. Slug sem path acessivel falha 422 e nao gera token de run.

### 15.1.2 Pré-requisitos antes de habilitar

1. **APP_KEY**: base64 com ≥32 bytes. Sem isso, Plan/Run falham fechado com `ATLAS_DEV_KEY_MISSING` (500). Rotacionar via `php artisan key:generate` e confirmar comprimento decodificado ≥32 bytes.
2. **Migrations obrigatórias** (já em `database/migrations/`):
   - `atlas_dev_confirmation_tokens` — tokens DB+HMAC, single-use, vinculados a `(run_id, task_contract_hash)`.
   - `atlas_dev_run_index` — cache de status/completion_state usado pelo Show REST e por dashboards.
   Verificar com `php artisan migrate:status | grep atlas_dev`.
3. **Storage**: `storage/atlas-dev/receipts/` precisa existir e ser gravável pelo processo PHP.
4. **Surface adapter**: para Desktop, `surface_id = atlas_desktop_ai` é canônico; `flow_id = atlas_dev`; `flow_origin = atlas_ai_router` (vindo do Router) ou `direct` (chamadas técnicas).

### 15.1.3 Habilitar Plan e Run

Ordem segura:

1. Subir backend com migrations aplicadas + APP_KEY válida.
2. `ATLAS_DEV_EFFICIENT_PLAN_ENABLED=true` (Plan zero-provider, seguro habilitar primeiro).
3. Confirmar smoke publico: `php artisan atlas:cli:dev "corrija teste X" --efficient --json` retorna `routing.kind` esperado e refs relativos. Para diagnostico local isolado, existe o comando oculto `php artisan atlas:dev:debug:smoke --intent="..." --workspace=/abs/path --json`.
4. `ATLAS_DEV_EFFICIENT_RUN_ENABLED=true` apenas depois de Plan verde em produção.
5. Para a surface Desktop: `ATLAS_DEV_EFFICIENT_DESKTOP_ENABLED=true` (quando o flag existir no ambiente).

Desabilitação rápida: setar a flag correspondente para `false` — o controller retorna `ATLAS_DEV_PLAN_DISABLED` / `ATLAS_DEV_RUN_DISABLED` (503) sem efeitos colaterais.

### 15.1.4 Confirmation token (DB+HMAC, single-use)

- Plan emite token plaintext **apenas uma vez** na response (campo `confirmation.token`); nunca persistido em plaintext.
- DB guarda `token_hash = hmac_sha256(APP_KEY, plaintext)`.
- TTL = `atlas_dev.confirmation_token.ttl_seconds` (default 300s).
- Vinculo `(run_id, task_contract_hash)` enforced: token de outro plan é rejeitado com 403 `CONFIRMATION_TOKEN_CONTRACT_MISMATCH`.
- Single-use via marca atômica no DB; reutilização rejeita 403 `CONFIRMATION_TOKEN_ALREADY_CONSUMED`.
- APP_KEY ausente/curta → 500 `ATLAS_DEV_KEY_MISSING` (fail-closed).

### 15.1.5 Run exige operator_confirmed + token

Body obrigatório:

```json
{ "run_id": "...", "task_contract_hash": "...", "confirmation_token": "...", "operator_confirmed": true }
```

Erros canônicos:

- `operator_confirmed` ausente, falsy, truthy-string ou `1` → 400 `OPERATOR_NOT_CONFIRMED`.
- `task_contract_hash` mismatch → 422 `TASK_CONTRACT_HASH_MISMATCH`.
- `confirmation_token` ausente/inválido/expirado/consumido/contract-mismatch → 403 `CONFIRMATION_TOKEN_*`.

### 15.1.6 Stream snapshot-close + REST fallback

- `GET /runs/{run_id}/stream` faz **snapshot-replay-then-close**: replay deterministico de `phase:`, `receipt:` (se houver) e `stream_closed:`, depois fecha. Não há long-lived keepalive nesta fase.
- Cliente assume `stream_closed` como fim canônico; para qualquer estado intermediário ou retomada, consulta `GET /runs/{run_id}` (REST, fonte de verdade).
- Live async (tail de log) é evolução futura — o contrato do cliente já está estável.

### 15.1.7 Path redaction nas respostas HTTP (F-04 fechado)

- Toda response HTTP usa:
  - `workspace_label` = basename do workspace (sem `/Users/...`);
  - `workspace_hash` = identifier provider-safe;
  - `persisted_artifact_refs` = `receipts/<run_id>/<file>` (refs relativos, não paths absolutos);
  - `persisted_receipt_refs` no Run response (idem).
- Paths absolutos permanecem **só** internamente (storage, discovery, telemetria local).
- Desktop e telemetria/Sentry não devem logar paths absolutos desnecessários.

### 15.1.8 Run index (REST fallback rápido)

- Tabela `atlas_dev_run_index` espelha o estado por `run_id`: `surface_id`, `workspace_hash`, `flow_id`, `flow_origin`, `command_intent`, `completion_state`, hashes, timestamps.
- Show REST consulta primeiro o índice para reduzir IO no filesystem; receipts JSON continuam fonte de verdade.

### 15.1.9 Identidade do fluxo (intake invariants)

- Atlas AI = **produto** (entrypoint do usuário); Atlas Dev = **fluxo** workspace-dev.
- `flow_id = atlas_dev` sempre.
- `flow_origin` aceito: `atlas_ai_router` (payload vindo do Atlas AI Router) ou `direct` (chamadas técnicas/CLI).
- `command_intent` opcional, preenchido quando o payload já trouxer `routing_task/slash/mode` resolvido pelo Router.

