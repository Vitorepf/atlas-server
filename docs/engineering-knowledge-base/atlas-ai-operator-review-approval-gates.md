---
id: atlas-ai-operator-review-approval-gates
type: engineering_knowledge
title: Atlas AI Operator Review Approval Gates
status: active
category: governance
priority: 92
summary: Camada deterministica de review e aprovacao humana para acoes do Atlas.
tags:
  - atlas-ai
  - approval
  - governance
capabilities:
  - operator_approval_gates
decisions:
  - Acoes sensiveis passam por modos allow, confirmation, review, block ou forge escalation.
maintenance:
  - Atualizar quando risk policy ou OperatorApprovalGateService mudarem.
related_paths:
  - docs/engineering-knowledge-base/atlas-permission-budget-safety-layer.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-ai-operator-review-approval-gates
graph_title: Atlas AI Operator Review Approval Gates
graph_world: atlas
graph_layer: system
graph_kind: policy
graph_parent: atlas-ai-canonical-architecture-index
graph_status: active
graph_source: repo
human_name: Atlas AI Operator Review Approval Gates
canonical_name: Atlas AI Operator Review Approval Gates
technical_name: atlas-ai-operator-review-approval-gates
cartography_type: policy
canonical_source: docs/engineering-knowledge-base/atlas-ai-operator-review-approval-gates.md
owner: policy-governance
repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-operator-review-approval-gates.md
allowed_changes:
  - Atualizar gates com policy, codigo e testes correspondentes.
forbidden_changes:
  - Permitir bypass humano para acoes block ou critical.
depends_on:
  - atlas-permission-budget-safety-layer
flows_to:
  - atlas-code
unlocks:
  - operator-review-gates
governs:
  - operator-approval
evidence:
  - docs/engineering-knowledge-base/atlas-ai-operator-review-approval-gates.md
evidence_refs:
  - symbol: OperatorApprovalGateService
  - command: atlas:ai:approval
  - test: OperatorApprovalGateServiceTest
required_tests:
  - "php artisan test tests/Feature/Ai"
requires_evidence: true
risk_level: high
next_actions:
  - Manter policy fail-closed para acoes sensiveis.
---
# Atlas AI Operator Review & Approval Gates

## Resumo

Gate deterministico para decidir quando Atlas pode agir, pedir confirmacao ou bloquear.

## Papel no Atlas

Governar acoes operacionais acima de Mission, Follow-Through e Hyperflow.

## Onde Se Encaixa

Acima de Policy/Permission Gate de baixo nivel.

## Contratos

Acoes criticas elevam para review ou block conforme risk policy.

## Fluxo

Classifica acao, persiste approval e consome decisao do operador.

## Regras para IA

Nao contornar approval gates para acelerar execucao.

## Escopo de Implementacao

Risk policy, approval persistence e consumo por mission/follow-through.

## Dependencias

Mission runtime, permission gates e evidence refs.

## Evidencias

Approval records, receipt hashes e testes de policy.

## Riscos

Auto-execucao de acoes financeiras, cyber ou destrutivas.

## Exemplos

`finance.trade.*` deve exigir review ou block.

## Proximas Acoes

Manter regras sincronizadas com novos dominios.

**Status:** ativo · entregue 2026-05-19
**Schema raiz:** `atlas.ai.operator_approval.v1` · `atlas.ai.operator_approval.decision.v1` · `atlas.ai.operator_approval.control_plane.v1`
**Camada:** governança acima de Mission/Follow-Through/Hyperflow. Decide se Atlas pode agir sozinho.

---

## O que é

Camada de governança que classifica cada ação que Atlas pretende executar e decide um de cinco modos canônicos:

| Modo | Significado |
|---|---|
| `allow_auto`           | Atlas pode agir sem perguntar. |
| `require_confirmation` | Operador precisa confirmar antes (yes/no rápido). |
| `require_review`       | Operador precisa revisar contexto antes (decisão mais cara). |
| `block`                | Ação proibida mesmo com aprovação. |
| `escalate_to_forge`    | Handoff para Atlas Forge (obras grandes). |

A decisão é **determinística** (zero LLM), persistida em `AiOperatorApproval`, com `hash` canônico e `receipt_hash` em cada decisão de operador.

## Diferença para Policy/Permission Gate existente

| Camada | Responsabilidade |
|---|---|
| **Policy / Permission Gate** (existente, `ai_permission_gates` + `ai_approval_requests`) | Avalia ações de baixo nível (`finance.publish`, `tool.X`) contra `AiPolicyProfile`. |
| **Operator Approval Gate** (esta entrega, `ai_operator_approvals`) | Camada acima: decide o **modo de governança** do ciclo de Atlas (Mission cycle, follow-through cycle, etc.). |

Esta camada **não substitui** a anterior — usa o léxico de risco compartilhado (`low/medium/high/critical`) e fica acima, traduzindo ação técnica em decisão operacional.

## Risk policy canon

| Categoria de ação | Modo default | Risco crítico |
|---|---|---|
| `tool.destructive.*`, `shell.destructive.*`, `fs.delete.*` | `require_confirmation` | `block` |
| `tool.file_edit_mass.*`, `fs.edit_mass.*`, `fs.bulk_edit.*` | `require_review` | `require_review` |
| `finance.trade.*`, `finance.transfer.*`, `finance.live_trade`, `finance.publish`, `finance.allocate` | `require_review` | `block` |
| `cyber.active_scan.*`, `cyber.exploit.*`, `cyber.exfil` | `require_review` | `block` |
| `forge.obra.*`, `mission.handoff_forge`, `forge.handoff` | `escalate_to_forge` | `escalate_to_forge` |
| `mission.certify` sem evidence | `require_review` | `require_review` |
| `mission.certify` com evidence | `allow_auto` | `require_review` |
| `mission.handoff_dev` | `allow_auto` | `require_review` (override critical) |
| `mission.simulated_dispatch` | `allow_auto` | `require_review` (override critical) |
| `explain.*`, `research.*`, `conversation.*` | `allow_auto` | `require_review` (override critical) |
| **default fail-safe** | `require_confirmation` | `require_confirmation` |

**Risk override:** se `requested_action` declarar `risk_level=critical`, qualquer categoria que normalmente seria `allow_auto` é elevada para `require_review`.

## Contract

`AiOperatorApproval` (schema `atlas.ai.operator_approval.v1`):

- `uuid` — identidade canônica
- `mission_id`, `work_order_id`, `trace_id`, `job_id` — referências opcionais
- `requested_action` — categoria de ação (ex: `mission.handoff_forge`)
- `risk_level` — `low | medium | high | critical`
- `gate_mode` — `allow_auto | require_confirmation | require_review | block | escalate_to_forge`
- `approval_required` — boolean (atalho de "preciso parar?")
- `reason` — texto sanitizado, capado em 480 chars (sem leak de raw input)
- `options` — opções oferecidas ao operador (default `[approve, deny]`)
- `status` — `auto_approved | pending | approved | denied | expired | cancelled`
- `operator_decision` — `approve | deny | null` (até decidir)
- `operator` — quem decidiu (capado em 160 chars)
- `operator_note` — anotação opcional, sanitizada
- `expires_at` — TTL (default 60min, review = 240min, block/auto = `null`)
- `decided_at`, `consumed_at` — timestamps de decisão e consumo (gating reuse)
- `evidence_refs` — refs sanitizadas (formato `type:value`)
- `receipt_hash` — sha256 da decisão de operador (preenchido em approve/deny)
- `hash` — sha256 do contract canônico (determinístico para inputs idênticos)

## Integração com Mission / Follow-Through

`MissionFollowThroughService.runNext()` chama `OperatorApprovalGateService.evaluateForFollowThrough()` antes de executar o ciclo:

1. Gate retorna `allow_auto` → ciclo prossegue normalmente.
2. Gate retorna `require_confirmation`/`require_review`/`escalate_to_forge` → mission transita `running → waiting_approval`, evento `follow_through.cycle.approval_required` é gravado, ciclo retorna `OUTCOME_WAITING_APPROVAL`.
3. Gate retorna `block` → mission transita `running → blocked`, evento `follow_through.cycle.approval_blocked` é gravado, ciclo retorna `OUTCOME_BLOCKED_BY_APPROVAL`.

**Resume após decisão.** Em chamada subsequente ao `runNext`:

- approval `approved` → mission transita `waiting_approval → running`, evento `follow_through.cycle.approval_resumed`, ciclo prossegue e marca approval como `consumed_at`.
- approval `denied` → mission transita `waiting_approval → blocked`, retorna `OUTCOME_BLOCKED_BY_APPROVAL`.
- approval `expired` → mesmo que `denied`.
- approval ainda `pending` → noop awaiting human.

A transição `WAITING_APPROVAL → BLOCKED` foi adicionada ao `MissionLifecycleService::ALLOWED_TRANSITIONS` para suportar deny/expired.

**Reuse de aprovações.** Quando o gate é re-avaliado para a mesma (mission, action) e existe uma approval `approved` + `consumed_at IS NULL`, ela é consumida (curto-circuito para `allow_auto`). Garante que o próximo ciclo não cria um novo gate.

## Commands

```
php artisan atlas:ai:approval list   [--status=pending] [--gate-mode=...] [--mission=<uuid>] [--limit=20] [--json]
php artisan atlas:ai:approval show   --approval=<uuid> [--json]
php artisan atlas:ai:approval decide --approval=<uuid> --decision=approve|deny [--operator=<name>] [--note="..."] [--json]
php artisan atlas:ai:approval expire [--json]                     # run expireDue (idempotente)
php artisan atlas:ai:approval control-plane [--limit=20] [--json] # aggregated snapshot
```

Toda saída é JSON. Exit codes: `0` ok, `1` runtime/not-found, `2` usage error.

## Control Plane

`AtlasControlPlaneSnapshotService::snapshot()` expõe bloco `operator_approvals_summary`:

```json
{
  "operator_approvals_summary": {
    "schema_version": "atlas.ai.operator_approval.v1",
    "status": "ready",
    "total": 7,
    "pending": 2, "approved": 3, "denied": 1, "expired": 1, "auto_approved": 0,
    "by_status": {...},
    "by_mode": {"allow_auto": 0, "require_confirmation": 2, ...},
    "risk_distribution": {"low": 1, "medium": 4, "high": 1, "critical": 1}
  }
}
```

`MissionFollowThroughService::snapshot()` agora também expõe `pending_approvals` para a mission corrente.

`OperatorApprovalGateService::controlPlaneSnapshot()` retorna view focada com listas de `pending`, `expired`, `recent_decisions`, `blockers` (cada item já sanitizado pelo `serialize()` — nenhum raw payload sensível).

## Hard rules

- **Hash determinístico.** Mesmos inputs canônicos → mesmo `hash`. `receipt_hash` em cada operator decision.
- **Sem leak de raw text.** `reason` capado em 480 chars; `operator_note` em 480; `operator` em 160; `evidence_refs` no formato `type:value` capado em 240 chars/ref.
- **Idempotente.** `decide` duas vezes na mesma approval lança `InvalidArgumentException`. `expire_due` é seguro de re-rodar.
- **Inert se persistência ausente.** `MissionFollowThroughService` checa `Schema::hasTable('ai_operator_approvals')` antes de chamar o gate (compat com testes legados / runtimes parciais).
- **Risk override sempre prevalece.** `critical` impede `allow_auto`, mesmo em categorias permissivas.
- **Block é hard.** `block` não tem `expires_at`, não pode ser aprovado, só observado.

## Limitações reais (não-vazadas)

- A taxonomia é baseada em prefixo (`startsWith`). Ações fora dos prefixos canônicos caem no fail-safe `require_confirmation` — bom para segurança, mas exige extensão da policy se uma nova categoria virar comum.
- O reuse de approvals olha por `(mission_id, requested_action)` exato. Aprovar `mission.handoff_forge` não cobre `mission.handoff_dev`. Granularidade boa, mas requer múltiplas aprovações em cycles com flows variados — operador percebe.
- `expireDue()` roda em best-effort dentro do snapshot / resume — não há job dedicado de expiração. Aceitável para o volume atual; promover a worker quando contagem subir.
- Gate não consulta `AiPolicyProfile`. Deliberado: esta camada é operator-facing, não tool-facing. Para tool-level enforcement, continuar usando `SafetyDecisionService`. As duas camadas convivem.
- Não há UI nesta entrega — apenas CLI + Control Plane JSON. UI virá em fatia separada quando a frente humana for evoluída.
- Aprovações não são versionadas além do `hash`. Mudar a policy não invalida approvals antigas; comportamento histórico fica congelado no `gate_mode` da row.

## Tests

41 testes verdes:

- `OperatorApprovalGateServiceTest` — 23 testes (cada categoria de risco + hash + receipt + reuse + sanitização).
- `OperatorApprovalFollowThroughIntegrationTest` — 8 testes (waiting/approve/deny/expired/reuse/snapshot).
- `AtlasAiApprovalCommandTest` — 8 testes (list/show/decide/control-plane + erros).
- `OperatorApprovalControlPlaneTest` — 2 testes (snapshot agregado + missing table).

Regressão: 79 testes em `tests/Feature/Ai/Mission/` continuam verdes; 64 testes em `Policy/` + `ControlPlane/` continuam verdes.

## Pointers de código

| Arquivo | Papel |
|---|---|
| `app/Services/Ai/OperatorApproval/OperatorApprovalCanon.php` | Enums canônicos (modes, status, decisions, actions). |
| `app/Services/Ai/OperatorApproval/OperatorApprovalRiskPolicy.php` | Resolver `(action, risk) → (mode, risk, reasons)`. |
| `app/Services/Ai/OperatorApproval/OperatorApprovalDecision.php` | DTO de decisão (read-only). |
| `app/Services/Ai/OperatorApproval/OperatorApprovalGateService.php` | Orquestrador: evaluate / approve / deny / expire / snapshot. |
| `app/Models/AiOperatorApproval.php` | Eloquent. |
| `app/Console/Commands/AtlasAiApprovalCommand.php` | CLI `atlas:ai:approval ...`. |
| `database/migrations/2026_05_19_120000_create_ai_operator_approvals_table.php` | Schema canônico. |
| `app/Services/Ai/Mission/MissionFollowThroughService.php` | Integração: gate antes do cycle + resume após approval. |
| `app/Services/Ai/Mission/MissionLifecycleService.php` | Transition `WAITING_APPROVAL → BLOCKED` habilitada. |
| `app/Services/Ai/ControlPlane/AtlasControlPlaneSnapshotService.php` | `operator_approvals_summary` adicionado ao snapshot agregado. |
