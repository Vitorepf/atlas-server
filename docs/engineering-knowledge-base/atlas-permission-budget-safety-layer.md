---
id: atlas-permission-budget-safety-layer
type: engineering_knowledge
title: Atlas Permission Budget Safety Layer
status: active
category: atlas-ai
priority: 100
summary: Camada de permissao, orcamento e seguranca que define o que o Atlas pode fazer sozinho, quando precisa aprovacao, como lidar com custo, login, credenciais, risco legal, reputacional e comandos sensiveis.
tags:
  - atlas-ai
  - permissions
  - budget
  - safety
  - risk
capabilities:
  - permission_gates
  - budget_limits
  - credential_safety
  - legal_risk_gate
  - destructive_action_guard
decisions:
  - Autonomia sem limite de permissao e risco inaceitavel.
  - O Atlas deve pedir aprovacao para custo externo, credencial, login, risco legal ou acao destrutiva.
  - Meta 3 entrega backend canonico: PolicyProfile, PermissionGate, ApprovalRequest, BudgetEnvelope, RiskAssessment, SafetyDecision, ForbiddenAction com readiness e control-plane verdes.
  - Policy decide permissao; runtime executa depois. Esta camada NUNCA dispara acao externa por conta propria.
maintenance:
  - Atualize este doc antes de alterar gates de permissao, custo ou safety.
related_paths:
  - docs/engineering-knowledge-base/atlas-autonomous-intelligence-operating-system.md
  - docs/engineering-knowledge-base/atlas-domain-company-runtimes.md
  - docs/engineering-knowledge-base/atlas-kernel-mission-foundation.md
  - docs/engineering-knowledge-base/atlas-ai-multi-domain-implementation-sequence.md
  - database/migrations/2026_05_18_020000_create_ai_policy_safety_tables.php
  - app/Models/AiPolicyProfile.php
  - app/Models/AiPermissionGate.php
  - app/Models/AiApprovalRequest.php
  - app/Models/AiBudgetEnvelope.php
  - app/Models/AiRiskAssessment.php
  - app/Models/AiSafetyDecision.php
  - app/Models/AiForbiddenAction.php
  - app/Services/Ai/Policy/PolicyCanon.php
  - app/Services/Ai/Policy/PolicyProfileRegistryService.php
  - app/Services/Ai/Policy/PermissionGateService.php
  - app/Services/Ai/Policy/ApprovalRequestService.php
  - app/Services/Ai/Policy/BudgetEnvelopeService.php
  - app/Services/Ai/Policy/RiskAssessmentService.php
  - app/Services/Ai/Policy/SafetyDecisionService.php
  - app/Services/Ai/Policy/ForbiddenActionService.php
  - app/Services/Ai/Policy/PolicyReadinessService.php
  - app/Services/Ai/Policy/PolicyControlPlaneService.php
  - app/Console/Commands/AtlasAiPolicyCommand.php
  - tests/Concerns/CreatesPolicySafetyTables.php
  - tests/Feature/Ai/Policy/PolicyReadinessTest.php
  - tests/Feature/Ai/Policy/PolicySeedDefaultsTest.php
  - tests/Feature/Ai/Policy/ForbiddenActionBlockedTest.php
  - tests/Feature/Ai/Policy/LowRiskAllowTest.php
  - tests/Feature/Ai/Policy/ApprovalRequiredTest.php
  - tests/Feature/Ai/Policy/BudgetEnvelopeExceededTest.php
  - tests/Feature/Ai/Policy/RiskAssessmentHashTest.php
  - tests/Feature/Ai/Policy/SafetyDecisionReceiptTest.php
  - tests/Feature/Ai/Policy/CyberOffensiveBlockedTest.php
  - tests/Feature/Ai/Policy/MarketingPublishApprovalTest.php
  - tests/Feature/Ai/Policy/PolicyControlPlaneTest.php
  - tests/Feature/Ai/Policy/PolicyCommandSmokeTest.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-permission-budget-safety-layer
graph_title: Atlas Permission Budget Safety Layer
graph_world: atlas
graph_layer: system
graph_kind: policy
graph_parent: atlas-autonomous-intelligence-operating-system
graph_status: active
graph_source: repo
human_name: Atlas Permission Budget Safety Layer
canonical_name: Atlas Permission Budget Safety Layer
technical_name: atlas-permission-budget-safety-layer
cartography_type: policy
canonical_source: docs/engineering-knowledge-base/atlas-permission-budget-safety-layer.md
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-permission-budget-safety-layer.md
allowed_changes:
  - Adicionar novos gates e politicas de risco.
forbidden_changes:
  - Permitir gasto, login ou acao destrutiva sem permissao aplicavel.
depends_on:
  - atlas-mission-mode
flows_to:
  - atlas-tool-economy
  - atlas-autonomous-control-plane
unlocks:
  - safe-autonomous-execution
governs:
  - atlas_ai.permission_budget_safety
evidence:
  - docs/engineering-knowledge-base/atlas-permission-budget-safety-layer.md
evidence_refs:
  - symbol: AiPolicyProfile
  - command: atlas:ai:policy
  - test: PolicyReadinessTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: critical
ai_entrypoints:
  - Leia Gate Matrix e Regras para IA antes de qualquer automacao externa.
quality_gates:
  - permission-classified
  - budget-checked
  - safety-reviewed
  - approval-recorded
failure_modes:
  - Gasto nao autorizado.
  - Credencial exposta.
  - Automacao viola politica do site.
  - Comando destrutivo roda sem gate.
observability_signals:
  - permission_gate_id
  - approval_status
  - budget_spent
  - risk_level
next_actions:
  - Criar policy engine.
line_limit: 520
---
# Atlas Permission Budget Safety Layer

## Resumo

Permission Budget Safety Layer define limites da autonomia. Ele decide o que o
Atlas pode executar sozinho e o que exige aprovacao humana, budget, credencial,
login, contrato legal ou ambiente seguro.

## Papel no Atlas

Fica antes de tool use, browser automation, APIs, compras, clones de repos e
comandos sensiveis. Sem esse gate, o Intelligence OS vira arriscado.

## Onde Se Encaixa

```text
Mission -> Tool/Execution Plan -> Permission/Budget/Safety -> Execute/Ask/Block
```

## Contratos

- `atlas.ai.safety.permission_gate.v1`
- `atlas.ai.safety.budget_gate.v1`
- `atlas.ai.safety.credential_gate.v1`
- `atlas.ai.safety.legal_gate.v1`
- `atlas.ai.safety.approval_receipt.v1`

## Fluxo

1. Classificar acao planejada.
2. Verificar custo externo.
3. Verificar login/credencial.
4. Verificar risco legal/reputacional.
5. Verificar risco destrutivo.
6. Decidir permitir, pedir aprovacao, sandboxar ou bloquear.
7. Registrar receipt.

## Gate Matrix

- `safe_autonomous`: leitura local, analise, docs, comandos read-only.
- `approval_required`: custo, login, API paga, publicacao, envio, compra.
- `sandbox_required`: repo desconhecido, script externo, CLI nova.
- `blocked`: exfiltracao, bypass indevido, acao destrutiva sem rollback.

## Regras para IA

- Nao usar credencial sem necessidade clara.
- Nao publicar, comprar, enviar mensagem ou anunciar sem aprovacao.
- Nao contornar protecao anti-bot.
- Nao rodar script externo fora de sandbox.
- Nao mascarar risco legal como problema tecnico.
- Nao armazenar segredo em receipt.

## Status De Implementacao (Meta 3)

Meta 3 do Multi-Domain Implementation Sequence esta implementada como backend
canonical e governa permissao, aprovacao, risco e orcamento horizontalmente.
Esta camada decide; runtime executa depois. Nada aqui dispara acao externa.

Persistencia (7 tabelas):

- `ai_policy_profiles` (`atlas.ai.policy_profile.v1`) — perfis com scope,
  autonomy, risk_tolerance, approval_rules, tool_permissions, budget_defaults.
- `ai_permission_gates` (`atlas.ai.permission_gate.v1`) — registro de avaliacoes
  com `decision in {allow, deny, require_approval, blocked}` e `receipt_hash`.
- `ai_approval_requests` (`atlas.ai.approval_request.v1`) — fila pending /
  approved / rejected / expired / cancelled com `expires_at` e `receipt_hash`.
- `ai_budget_envelopes` (`atlas.ai.budget_envelope.v1`) — limites e consumo
  por scope (cost, tokens, runtime, tool_calls, external_calls).
- `ai_risk_assessments` (`atlas.ai.risk_assessment.v1`) — risk_level derivado
  das factors, residual_risk pos-mitigacao, `assessment_hash` deterministico.
- `ai_safety_decisions` (`atlas.ai.safety_decision.v1`) — decisao final
  orquestrada com link para policy_profile e risk_assessment, `receipt_hash`.
- `ai_forbidden_actions` (`atlas.ai.forbidden_action.v1`) — bloqueios duros
  por action_key, com severity e escopo opcional.

Services (`App\Services\Ai\Policy`):

- `PolicyCanon` — enums canonicos (scopes, autonomy, risk, decisions, approval).
- `PolicyProfileRegistryService` — `seedDefaults()`, `findForRequest()`,
  `get(policyId)`, `defaults()`.
- `PermissionGateService` — `evaluate(request)` retorna AiPermissionGate.
- `ApprovalRequestService` — `request`, `approve`, `reject`, `cancel`,
  `expireDue`.
- `BudgetEnvelopeService` — `open`, `check`, `consume`, `close` com
  `STATUS_OPEN | STATUS_EXHAUSTED | STATUS_CLOSED`.
- `RiskAssessmentService` — `assess(target, factors, mitigations, context)`.
- `SafetyDecisionService` — orquestra gate + risk + approval e grava decisao.
- `ForbiddenActionService` — `block(actionKey, ...)`, `isForbidden(actionKey)`.
- `PolicyReadinessService` — `report()` agrega tabelas, models, services,
  defaults seeded e enum canon, schema `atlas.ai.policy.readiness.v1`.
- `PolicyControlPlaneService` — `snapshot()` com profiles, gates, approvals,
  budgets, risks, decisions, forbidden_actions; schema
  `atlas.ai.policy.control_plane.v1`.

Default Policy Profiles seeded por `seedDefaults()`:

- `global.default`
- `programming.default`
- `finance.research_only`
- `finance.live_trade_blocked_by_default`
- `cyber.defensive_only`
- `cyber.offensive_requires_authorization`
- `marketing.publish_requires_approval`
- `automation.external_action_requires_policy`
- `tool.external_cost_requires_approval`

Comando Artisan:

```bash
/opt/homebrew/bin/php artisan atlas:ai:policy --action=readiness --json
/opt/homebrew/bin/php artisan atlas:ai:policy --action=seed-defaults --json
/opt/homebrew/bin/php artisan atlas:ai:policy --action=evaluate \
    --action-key=finance.live_trade --risk-level=high --json
/opt/homebrew/bin/php artisan atlas:ai:policy --action=request-approval \
    --action-key=marketing.publish --approval-type=permission --json
/opt/homebrew/bin/php artisan atlas:ai:policy --action=control-plane --json
```

## Escopo de Implementacao

Policy engine, approval receipts, budget ledger, credential redaction, risk
classifier, command allowlist/denylist e integração com Control Plane.

Fora de escopo de Meta 3 (continua em Metas futuras):

- Tool Runtime que efetivamente executa apos `decision=allow` (Meta 5).
- Evidence Ledger imutavel apos decisao (Meta 4).
- Domain Runtime que consome `SafetyDecisionService::decide` (Meta 7+).
- UI de Control Plane para aprovacao humana (Meta 14).

## Dependencias

- Mission Mode.
- Tool Economy.
- Evidence & Truth Layer.

## Evidencias

Gate decision, motivo, risco, approval id, budget estimado/real, redacoes e
blockers.

## Riscos

- Safety frouxo causa dano.
- Safety agressivo bloqueia autonomia. O equilibrio e permitir baixo risco e
  exigir aprovacao para custo, credencial e impacto externo.

## Exemplos

Automacao Instagram exige gate por login, ToS, anti-bot, privacidade e risco de
conta. Pode sugerir API oficial ou fluxo manual assistido.

## Proximas Acoes

1. Ligar Mission Foundation transition `running` ao `SafetyDecisionService` para
   bloquear missoes em violacao de policy antes de executar.
2. Ligar futuro Tool Runtime (Meta 5) ao `PermissionGateService::evaluate` antes
   de invocar tools externas.
3. Ligar Domain Company Runtimes (Meta 7+) ao `SafetyDecisionService` no inicio
   de cada work order para gravar decisao + receipt_hash auditavel.
4. Endpoint REST `/atlas-ai/policy/*` para Control Plane no Desktop (Meta 14).
5. Background job para `ApprovalRequestService::expireDue` periodico.

## Definition of Done

Esta pronto quando toda acao externa/cara/sensivel passa por gate e gera
permit/ask/block com receipt auditavel.

Meta 3 entregue quando:

- readiness retorna `ok=true` apos `seed-defaults` e `failed` antes.
- nove default profiles existem com `forbidden_actions` materializadas em
  `ai_forbidden_actions`.
- `finance.live_trade`, `cyber.offensive_without_authorization` e
  `automation.anti_bot_bypass` retornam `decision=blocked`.
- `marketing.publish`, `marketing.paid_media_spend` retornam
  `decision=require_approval` e criam `AiApprovalRequest` pending.
- `programming.read` low-risk retorna `decision=allow`.
- budget envelope rejeita request acima do limite via `check()` e via
  `consume()` (throws InvalidArgumentException).
- control-plane retorna `schema=atlas.ai.policy.control_plane.v1` com sete
  secoes preenchidas.
- `atlas:ai:policy` artisan command roda os cinco actions.
- `php artisan test --filter=Policy` verde para os testes de Meta 3.
- `php artisan atlas:engineering:knowledge docs-health --json` sem violacao no
  arquivo `atlas-permission-budget-safety-layer.md`.

