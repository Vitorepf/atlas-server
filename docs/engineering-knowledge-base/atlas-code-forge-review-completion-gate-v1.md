---
id: atlas-code-forge-review-completion-gate-v1
type: engineering_knowledge
title: Atlas Code Forge Review & Completion Gate v1
status: active
category: programming-forge
priority: 100
summary: Review humano + Completion Claim canonicos para um Fast Path run. Sem provider externo; sem auto-completion; sem usar latest_forge_live_execution nao correlacionada ao run.
tags:
  - atlas
  - atlas-code
  - forge
  - review
  - completion
  - gate
capabilities:
  - atlas_code_forge_review_packet
  - atlas_code_forge_completion_claim
  - fast_path_run_review_endpoints
  - human_review_required
  - rollback_governed
decisions:
  - Atlas Code SCOR-1 nao pode declarar Obra concluida sem aprovacao humana explicita.
  - Review packet e completion claim sao SEMPRE correlacionados ao fast_path_run_id; nunca a latest_forge_live_execution avulsa.
  - Rollback so executa se houver promotion canonica vinculada ao review aprovado.
  - Decisoes (approve/reject/rollback) registram reviewer, reason, decision_hash, source_authority em audit history.
maintenance:
  - Atualize quando AtlasCodeForgeReviewController, governed promotion, completion gate ou Fast Path Run lifecycle mudarem.
related_paths:
  - docs/engineering-knowledge-base/atlas-code-forge-fast-path-v1.md
  - docs/engineering-knowledge-base/atlas-programming-forge-flow.md
  - app/Services/Ai/Programming/AtlasCodeForgeReviewCompletionService.php
  - app/Http/Controllers/AtlasCodeForgeReviewCompletionController.php
  - app/Console/Commands/AtlasCodeForgeReviewCommand.php
  - tests/Feature/Ai/Programming/AtlasCodeForgeReviewCompletionTest.php
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-code-forge-review-completion-gate-v1
graph_title: Atlas Code Forge Review & Completion Gate v1
graph_world: atlas
graph_layer: system
graph_kind: runbook
graph_parent: atlas-code-forge-fast-path-v1
graph_status: active
graph_source: repo
human_name: Atlas Code Forge Review & Completion Gate v1
canonical_name: Atlas Code Forge Review & Completion Gate v1
technical_name: atlas-code-forge-review-completion-gate-v1
cartography_type: runbook
canonical_source: docs/engineering-knowledge-base/atlas-code-forge-review-completion-gate-v1.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-code-forge-review-completion-gate-v1.md
  - app/Services/Ai/Programming/AtlasCodeForgeReviewCompletionService.php
  - app/Http/Controllers/AtlasCodeForgeReviewCompletionController.php
  - app/Console/Commands/AtlasCodeForgeReviewCommand.php
  - tests/Feature/Ai/Programming/AtlasCodeForgeReviewCompletionTest.php
allowed_changes:
  - Adicionar invariantes ao lifecycle_invariants do block forge_review_completion_certification.
forbidden_changes:
  - Permitir completion automatica sem human_approved.
  - Usar latest_forge_live_execution sem correlacao com run.
  - Liberar rollback sem promotion canonica vinculada.
  - Misturar este eixo com external_rivals_certification.
depends_on:
  - atlas-code-forge-fast-path-v1
  - atlas-programming-forge-flow
flows_to:
  - atlas-code-forge-fast-path-v1
unlocks:
  - atlas-code-forge-review-completion-gate
governs:
  - atlas_code_forge_review_completion
evidence:
  - docs/engineering-knowledge-base/atlas-code-forge-review-completion-gate-v1.md
  - app/Services/Ai/Programming/AtlasCodeForgeReviewCompletionService.php
  - tests/Feature/Ai/Programming/AtlasCodeForgeReviewCompletionTest.php
evidence_refs:
  - symbol: AtlasCodeForgeReviewCompletionService
  - command: atlas:code:forge-review
required_tests:
  - "php artisan test --filter AtlasCodeForgeReviewCompletionTest"
  - "php artisan atlas:code:forge-review --obra=<uuid> --run=<id> --json --strict"
requires_evidence: true
risk_level: high
visual_tags:
  - forge
  - review
  - completion
ai_entrypoints:
  - Leia este doc antes de alterar AtlasCodeForgeReviewCompletionService/Controller/Command ou pontos do review/completion no audit.
ai_usage_notes:
  - Nao declare completed sem human_approved + completion_claim.final_completion_allowed=true.
quality_gates:
  - review-packet-correlated-with-run
  - approval-blocks-without-runtime-and-evidence
  - completion-claim-only-after-human
  - rollback-blocks-without-promotion
failure_modes:
  - Aceitar completion automatica sem human_approved.
  - Usar review/forge_live_execution nao correlacionados ao run.
  - Habilitar rollback sem promotion vinculada.
observability_signals:
  - review_packet_id
  - completion_claim.completion_status
  - completion_claim.human_approved
  - completion_claim.final_completion_allowed
next_actions:
  - Manter este doc sincronizado com a evolucao de AtlasCodeForgeReviewCompletionService.
---
# Atlas Code Forge Review & Completion Gate v1

## Resumo

Transforma `review_required` num fluxo profissional com review humano,
approve/reject/rollback canonicos e completion claim explicito. Sem provider
externo, sem auto-completion. Cada decisao gera audit hash + reviewer + reason.

## Papel no Atlas

Complementa o Fast Path v2: depois do live execution passar, Atlas Code precisa
de uma decisao humana correlacionada ao `fast_path_run_id` antes de declarar
`completion_status=completed`.

## Onde Se Encaixa

Filho de `atlas-code-forge-fast-path-v1.md`. Reusa `AtlasCodeForgeReviewController`
(promotion canonica + rollback). Persiste packet/claim em metadata da Obra.

## Contratos

- **Review Packet** (`atlas.code.forge_review_packet.v1`): correlaciona
  `fast_path_run_id` com `forge_live_execution` por `execution_id`/`history_id`/
  `evidence_id`. Sem correlacao, `runtime_status=missing` e completion gate
  bloqueia.
- **Completion Claim** (`atlas.code.forge_completion_claim.v1`): so vira
  `completed` quando `human_approved=true` + runtime passou + evidence pack
  presente. `not_allowed → allowed → completed`. `rejected → blocked`.
- **Approve** bloqueia se runtime nao passou OU evidence pack ausente.
- **Reject** grava decisao + reason + decision_hash; nao promove; bloqueia
  completion claim.
- **Rollback** so executa se houver promotion canonica vinculada e
  `rollback_available=true`. Senao retorna `rollback_not_available`.

## Endpoints

```text
GET    /atlas-code/works/{project}/forge/fast-path/{run}/review
POST   /atlas-code/works/{project}/forge/fast-path/{run}/review/approve
POST   /atlas-code/works/{project}/forge/fast-path/{run}/review/reject
POST   /atlas-code/works/{project}/forge/fast-path/{run}/review/rollback
```

Payload (approve/reject/rollback):

```json
{ "reviewer": "<id>", "reason": "..." }
```

## CLI

```bash
php artisan atlas:code:forge-review --obra=<uuid> --run=<id> --json --strict
php artisan atlas:code:forge-review --obra=<uuid> --run=<id> --approve --reviewer=<id> --reason="..." --json --strict
php artisan atlas:code:forge-review --obra=<uuid> --run=<id> --reject --reviewer=<id> --reason="..." --json --strict
php artisan atlas:code:forge-review --obra=<uuid> --run=<id> --rollback --reviewer=<id> --reason="..." --json --strict
```

Exit non-zero em `blocked`/`rejected`/`rollback_failed`.

## State Projection

`GET /atlas-code/works/{obra}/state` expoe:

- `forge_review_packet` (reconstruido por `AtlasCodeForgeReviewCompletionService::packet`)
- `forge_completion_claim` (reconstruido por `AtlasCodeForgeReviewCompletionService::completionClaim`)

Persistencia em metadata:

- `latest_atlas_code_forge_review_packet`
- `atlas_code_forge_review_packet_history` (25 max)
- `latest_atlas_code_forge_completion_claim`
- `atlas_code_forge_completion_history` (25 max)

## Status Integration

`AtlasCodeForgeFastPathStatusService` agora reflete:

- `approved` + completion claim allowed → `completed`
- `rejected` → `failed` + `next_action=inspect_rejection`
- `rollback_required` → `next_action=run_rollback`

Sem correlacao do live execution com o run, `review_required` nao acende.

## Completion Audit

Bloco `forge_review_completion_certification`
(`atlas.code.forge_review_completion_certification.v1`):

- `status: available | missing_artifacts | requires_operator_run`
- `endpoints` (4 endpoints registrados)
- `artifacts` (service/controller/command/test/doc)
- `test_coverage.coverage_complete`
- `lifecycle_invariants`:
  - `review_packet_available`
  - `completion_claim_available`
  - `endpoints_registered`
  - `cli_registered`
  - `no_auto_completion_without_human_review=true`
  - `rollback_path_available=true`
  - `state_projection_available=true`
  - `correlated_live_execution_required=true`
- `separated_from_external_rivals=separated`

Continua **separado** de `external_rivals_certification`.

## Regras para IA

- Nao declare `completed` sem `final_completion_allowed=true`.
- Nao use `latest_forge_live_execution` sem correlacao com `fast_path_run_id`/
  `execution_id`/`history_id`/`evidence_id`.
- Nao permita rollback sem promotion canonica vinculada.
- Nao chame provider externo deste fluxo.

## Riscos

- Acompanhar runtime do live execution avulso (sem correlacao).
- Marcar `completed` automaticamente quando runtime passa.
- Esconder ausencia de evidence pack.

## Fluxo

```text
Fast Path run → live_execution passed → review_packet (pending)
  → operator approve|reject|rollback
    → completion_claim allowed/blocked/completed/rolled_back
    → audit history (decision_hash + reviewer + reason)
```

## Escopo de Implementacao

- Service: `AtlasCodeForgeReviewCompletionService` (packet/claim/decide).
- Controller: `AtlasCodeForgeReviewCompletionController` (4 endpoints).
- Command: `AtlasCodeForgeReviewCommand` (CLI canonico).
- State projection: `AtlasCodeWorkController::state` ganhou `forge_review_packet` e `forge_completion_claim`.

## Dependencias

- `AtlasCodeForgeReviewController` (promotion + rollback canonico).
- `AtlasCodeForgeFastPathService` (run lifecycle).
- `AtlasCodeForgeFastPathStatusService` (lifecycle reflection).
- `AtlasForgeGovernedPromotionService` (rollback execution).

## Evidencias

- `tests/Feature/Ai/Programming/AtlasCodeForgeReviewCompletionTest.php` (10+ cenarios).
- `php artisan atlas:code:forge-review --json --strict` (exit 1 sem args).
- `atlas:programming:completion-audit --json` expoe `forge_review_completion_certification` separado.

## Exemplos

Sem run:

```json
{"schema_version":"atlas.code.forge_review_packet.v1","review_status":"blocked","blocker":"fast_path_run_not_found"}
```

Approve com runtime + evidence:

```json
{"status":"approved","completion_claim":{"completion_status":"completed","human_approved":true,"final_completion_allowed":true}}
```

## Proximas Acoes

1. Manter este doc sincronizado com novas decisoes do review/completion gate.
2. Rodar `atlas:code:forge-review` apos qualquer alteracao em controller/service.
3. Continuar isolando `external_rivals_certification`.
