---
id: atlas-dev-efficient-programming-flow-runbook-v1-part-07
type: engineering_knowledge
title: Atlas Dev Efficient Programming Flow Runbook v1 · Parte 7
status: active
category: programming
priority: 104
summary: Recorte focado do runbook Atlas Dev Efficient Programming Flow v1: 10.4 Marco 3 — One-Call Visivel No Atlas AI Desktop ate 12.1 Objetivo.
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
graph_id: atlas-dev-efficient-programming-flow-runbook-v1-part-07
graph_title: Atlas Dev Efficient Programming Flow Runbook v1 Parte 7
graph_world: atlas
graph_layer: module
graph_kind: runbook
graph_parent: atlas-dev-efficient-programming-flow-runbook-v1
graph_status: active
graph_source: repo
human_name: Atlas Dev Efficient Programming Flow Runbook v1 Parte 7
canonical_name: Atlas Dev Efficient Programming Flow Runbook v1 Parte 7
technical_name: atlas-dev-efficient-programming-flow-runbook-v1-part-07
cartography_type: runbook
canonical_source: docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-07.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-07.md
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
  - symbol: AtlasDevEffProgFlowRunbookV1Part07Service
  - command: atlas:aaeos:atlas-dev-eff-prog-flow-runbook-v1-part07
  - test: AtlasDevEffProgFlowRunbookV1Part07Test
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: high
next_actions:
  - Manter este recorte alinhado ao índice canônico e ao runbook operacional.
---
# Atlas Dev Efficient Programming Flow Runbook v1 · Parte 7

## Resumo

Este recorte preserva uma parte operacional do runbook Atlas Dev Efficient Programming Flow v1: 10.4 Marco 3 — One-Call Visivel No Atlas AI Desktop ate 12.1 Objetivo.

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
### 10.4 Marco 3 — One-Call Visivel No Atlas AI Desktop

Backend:

```text
POST /ai/interactions/atlas-dev/run
GET  /ai/interactions/atlas-dev/runs/{run_id}/stream
GET  /ai/interactions/atlas-dev/runs/{run_id}
```

`run` exige:

```json
{
  "run_id": "uuid-v7",
  "task_contract_hash": "sha256",
  "confirmation_token": "single-use-token-issued-by-plan",
  "operator_confirmed": true
}
```

Sem `operator_confirmed=true`, `task_contract_hash` valido e `confirmation_token` single-use, o backend rejeita a execucao. Plan-only e run ficam separados para impedir provider call acidental.

Confirmation token (GO P0):

- Emitido por `POST /ai/interactions/atlas-dev/plan` apenas quando `routing_decision = atlas_dev_fast_path`.
- Implementação canônica = **DB + HMAC**. Não há mais filesystem store: o legado `ConfirmationTokenStore` foi removido (F-05).
- Persistido como `token_hash` HMAC-SHA256 (chave = APP_KEY base64) em `atlas_dev_confirmation_tokens` com colunas `run_id`, `task_contract_hash`, `surface_id`, `token_hash`, `issued_at`, `used_at`, `expires_at`.
- TTL = 5min (configurável via `atlas_dev.confirmation_token.ttl_seconds`).
- Vinculado a `(run_id, task_contract_hash)`: token emitido para um plan não redime outro.
- Invalidado no primeiro `consume()` atômico (DB unique constraint protege contra corrida).
- Pré-requisito: APP_KEY base64 com ≥32 bytes. Sem isso, Plan/Run falham fechado com `ATLAS_DEV_KEY_MISSING` (500).
- Feature test cobre 400 sem `operator_confirmed`, 422 com `task_contract_hash` invalido, 403 com token ausente/invalido/expirado/reutilizado/contract-mismatch.
- Migrations obrigatórias antes de habilitar Run: `atlas_dev_confirmation_tokens` + `atlas_dev_run_index`.

Streaming policy locked (GO P0):

- Modo atual: **snapshot-replay-then-close**. Backend escreve, em ordem deterministica, um `phase:` por artefato ja persistido + `receipt:` final (se houver) + `stream_closed:` marker, depois fecha. Sem long-lived keepalive nesta fase.
- REST status fallback é a fonte de verdade e o contrato de retomada: cliente que perdeu SSE, recarregou o Desktop ou não suporta stream consulta `GET /runs/{run_id}` (campos `completion_state`, `has_receipt`, `routing`, `persisted_artifact_refs`, `workspace_label`/`workspace_hash`).
- Receipt persistido (DB + filesystem) é a fonte de verdade; SSE e polling apenas transportam snapshots/fases.
- Live async (tail de log de execução) é evolução futura — o contrato do cliente fica estável assumindo `stream_closed` como fim canônico.

SSE minimo:

```text
phase: executing
phase: scope_guarding
phase: verifying
test_started
test_finished
phase: complete
receipt
```

Frontend:

| Path | Mudanca |
| --- | --- |
| `../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/client.ts` | adicionar `runAtlasDevPlan()` e stream |
| `../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/components/AtlasAiLiveActivity.tsx` | mostrar fases do run |
| `../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/components/rich-artifacts/AtlasAiDiff.tsx` | renderizar diff do result |
| `../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/components/AtlasAiToolReceipts.tsx` | renderizar verification receipt |

DoD do Marco 3:

- [x] Botao executar aparece somente apos plan-only valido.
- [x] Stream mostra fases sem travar conversa.
- [x] Fallback REST recupera status/receipt quando SSE falha ou o operador recarrega a tela.
- [x] Diff, tests e receipt aparecem na mensagem/thread.
- [x] Provider lock Sonnet/Claude CLI confirmado no receipt.

---

## 11. Fatia 4 — Repair Loop

### 11.1 Objetivo

Implementar repair barato baseado em `FailureCapsule`, com limites por R-level. Mesma provider/model, sem fallback.

### 11.2 PRs Sugeridos

#### PR 4.1 — FailureCapsuleBuilder

Arquivo:

```text
app/Services/Ai/Programming/AtlasDev/Repair/FailureCapsuleBuilder.php
```

Signature:

```php
final class FailureCapsuleBuilder
{
    public function buildFromVerification(
        LightTaskContract $taskContract,
        VerificationGateResult $verificationResult,
        int $attemptIndex,
    ): FailureCapsule;
}
```

Algoritmo:

1. identificar primeiro gate failed;
2. extrair `primary_error_excerpt` (max 4kb) do log;
3. truncar para preservar erro principal;
4. computar `failure_signature = sha256(gate + "::" + normalize(error))`;
5. decidir `retry|stop|escalate`:
   - retry se `attempt_index < max_attempts` E nao e mesma signature repetida;
   - escalate se signature repetida OU diff growing OU new scope;
   - stop se max_attempts atingido.

DoD do PR 4.1:

- [x] Failure capsule com excerpt nao vazio quando ha erro.
- [x] failure_signature determinístico para mesmo erro.
- [x] Decision retry/stop/escalate cobertos.

#### PR 4.2 — RepairOrchestrator

Arquivo:

```text
app/Services/Ai/Programming/AtlasDev/Repair/RepairOrchestrator.php
```

Signature:

```php
final class RepairOrchestrator
{
    public function attempt(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
        ProviderPromptProjection $originalPrompt,
        VerificationGateResult $previousResult,
        FailureCapsule $capsule,
    ): RepairAttemptResult;
}

final class RepairAttemptResult
{
    public function __construct(
        public readonly ProviderCallResult $callResult,
        public readonly ScopeGuardReceipt $scopeReceipt,
        public readonly VerificationGateResult $verificationResult,
        public readonly FailureCapsule $newCapsule,  // null se passed
        public readonly string $decision,  // continue | stop | escalate
    ) {}
}
```

Comportamento:

- gera repair prompt baseado em `originalPrompt + capsule`;
- envia para mesma provider/model (sonnet via claude_cli);
- roda scope guard + verification gate;
- atualiza receipt acumulando attempts;
- se mesma signature aparece -> decision = escalate;
- respeita `repair_policy.abort_on_same_signature_twice`.

DoD do PR 4.2:

- [x] Repair que vira green -> `decision = continue`, `newCapsule = null`.
- [x] Repair que falha mesma signature 2x -> `decision = escalate`.
- [x] Repair que excede max_attempts -> `decision = stop`.
- [x] Diff growing -> `decision = escalate`.

#### PR 4.3 — Integracao no Pipeline

`AtlasDevFastPathOrchestrator.patch()` ganha loop:

```php
$attempt = 0;
$maxAttempts = $taskContract->repairPolicy->maxAttempts;
while ($attempt < $maxAttempts && $completionState === 'failed') {
    $capsule = $this->capsuleBuilder->buildFromVerification(...);
    $persist($capsule);
    $repairResult = $this->repairOrchestrator->attempt(...);
    $persist($repairResult);
    if ($repairResult->decision === 'continue') break;
    if ($repairResult->decision === 'escalate') break;
    $attempt++;
}
```

DoD do PR 4.3:

- [x] Feature test `tests/Feature/AtlasDev/EndToEndRepairTest.php` cobre:
  - cenario "first attempt fail, repair pass";
  - cenario "two fails same signature -> escalate";
  - cenario "first pass without repair".
- [x] Receipt final tem `repair.attempt_count` correto.
- [x] Todas as capsules persistidas como `failure_capsule.<n>.json`.

### 11.3 DoD Operacional Da Fatia 4

- `composer test --filter=AtlasDev/Repair` verde.
- Feature test repair verde.
- Receipt com loop de repair persiste corretamente.
- Telemetria registra `repair_attempts > 0` quando aplicavel.
- **Repair nunca muda provider. Nunca expande escopo silenciosamente.**

### 11.4 Marco 4 — Repair Visivel No Atlas AI Desktop

SSE ganha eventos:

```text
repair_planned
repair_executing
repair_finished
```

Frontend:

| Path | Mudanca |
| --- | --- |
| `../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/components/AtlasAiLiveActivity.tsx` | segmento `repair · tentativa N/M` |
| `../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/components/AtlasAiToolReceipts.tsx` | failure capsule expandivel |
| `../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/components/AtlasAiQualityBadge.tsx` | badges `repair`, `needs_review`, `failed_closed` |

DoD do Marco 4:

- [x] Desktop mostra cada attempt em tempo real.
- [x] Failure capsule aparece sem vazar prompt interno.
- [x] Mesma signature duas vezes gera escalation preview, nao retry infinito.

---

## 12. Fatia 5 — Surface Parity + EscalationDecision

### 12.1 Objetivo

Completar paridade nas surfaces CLI Dev, App e API depois que Desktop ja provou plan-only, run e repair. Implementar `EscalationDecision` engine e ligar o botao `promover` do Desktop a preview, sem criar Obra automaticamente.

Surface inicial locked:

| Camada | Path | Papel |
| --- | --- | --- |
| Desktop UI | `../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/AtlasAiSurface.tsx` | Workbench principal do operador: conversa, composer, plano e side panel |
| Desktop payload | `../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/contract.ts` | Constroi payload `atlas_desktop_ai` com modo/tarefa/workspace |
| Desktop client | `../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/client.ts` | Envia `/ai/interactions` e acompanha trace/thread |
| Backend surface adapter | `app/Services/Ai/Surface/Adapters/AtlasDesktopAiSurfaceAdapter.php` | Declara capacidades e mapeia tarefas para `programming.*` |
| Runtime atual | `app/Services/Ai/Programming/AtlasDevRuntimeService.php` | Normaliza payload programming e injeta `atlas_dev_runtime` |

O adapter desktop deve receber intencao crua, workspace e selecoes de UX. Ele nao recebe prompt final, nao monta `ProviderPromptProjection` no frontend e nao permite prompt customizado da surface.

