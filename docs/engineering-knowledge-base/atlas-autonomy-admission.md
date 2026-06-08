---
id: atlas-autonomy-admission
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas Autonomy Admission (Patamar 4 · 4.1)
slug: atlas-autonomy-admission
status: building
implementation_state: runtime_available_policy_stack_integration_partial
category: governance
priority: 95
summary: Composer fino que combina Constitutional Kernel, PolicyCanon e risco para decidir se uma mudanca pode seguir autonoma, com aprovacao ou negada.
tags: [atlas-ai, autonomy, governance, patamar-4, approval]
capabilities: [autonomy_admission_envelope, risk_to_autonomy_cap, admission_ticket_log, kernel_first_admission_gate]
decisions:
  - Autonomy Admission nao e fonte de verdade de policy; ele compoe Kernel e PolicyCanon.
  - Risk levels e autonomy levels devem vir do PolicyCanon, nao de novos enums paralelos.
  - Integracoes futuras com PermissionGate e ApprovalRequest devem preservar ownership desses services.
maintenance:
  - Atualizar antes de mudar decisions, risk mapping, ticket schema ou consumidores de admit().
  - Manter testes cobrindo kernel block, approval cap, determinismo e append-only tickets.
risk_level: high
owner: atlas-ai
graph_id: atlas-autonomy-admission
human_name: Atlas Autonomy Admission
canonical_name: Atlas Autonomy Admission
technical_name: AtlasAutonomyAdmissionService
cartography_type: policy
canonical_source: docs/engineering-knowledge-base/atlas-autonomy-admission.md
graph_title: Atlas Autonomy Admission
graph_world: atlas
graph_layer: module
graph_kind: policy
graph_parent: atlas-constitutional-kernel
graph_status: building
graph_source: repo
depends_on:
  - atlas-constitutional-kernel
  - atlas-cognition-operating-system
authority_class: composer
related_paths:
  - docs/engineering-knowledge-base/atlas-autonomy-admission.md
  - docs/engineering-knowledge-base/atlas-constitutional-kernel.md
  - docs/engineering-knowledge-base/atlas-cognition-operating-system.md
  - app/Services/Ai/Governance/AtlasAutonomyAdmissionService.php
  - app/Services/Ai/Policy/PolicyCanon.php
  - tests/Unit/Ai/Governance/AtlasAutonomyAdmissionServiceTest.php
repo_paths:
  - docs/engineering-knowledge-base/atlas-autonomy-admission.md
  - app/Services/Ai/Governance/AtlasAutonomyAdmissionService.php
flows_to: [atlas-autonomous-reconciliation-runtime, atlas-swarm-conductor, atlas-teos-i4-counterfactual-tree]
unlocks: [autonomy_preflight, governed_autonomous_change_admission]
governs: [autonomy_admission_envelopes, autonomy_admission_tickets]
evidence:
  - app/Services/Ai/Governance/AtlasAutonomyAdmissionService.php
  - tests/Unit/Ai/Governance/AtlasAutonomyAdmissionServiceTest.php
evidence_refs:
  - symbol: AtlasAutonomyAdmissionService
  - command: atlas:autonomy:admit
  - test: AtlasAutonomyAdmissionServiceTest
required_tests:
  - "php artisan test tests/Unit/Ai/Governance/AtlasAutonomyAdmissionServiceTest.php"
  - "php artisan atlas:engineering:knowledge docs-health --json"
next_actions:
  - Integrar PermissionGateService e ApprovalRequestService mantendo ownership desses services.
  - Adicionar consumidores Patamar 4 somente com teste que prove chamada a admit().
allowed_changes:
  - Integrar PermissionGateService e ApprovalRequestService sem mover ownership de policy.
forbidden_changes:
  - redefine_autonomy_levels
  - redefine_risk_levels
  - duplicate_approval_workflow
  - bypass_constitutional_kernel
requires_evidence: true
line_limit: 520
schema:
  - atlas.autonomy_admission.envelope.v1
  - atlas.autonomy_admission.ticket.v1
---

# Atlas Autonomy Admission — Patamar 4 · 4.1 (composer fino)

## Resumo

Composer fino de admissao autonoma. Ele consulta o Constitutional Kernel primeiro e usa PolicyCanon para limitar autonomia por risco.

## Papel no Atlas

Dar uma resposta unica para consumidores Patamar 4: pode agir autonomamente, precisa de aprovacao ou deve negar.

## Onde Se Encaixa

Fica entre consumidores autonomos e os gates de governanca/policy existentes.

## Contratos

Schemas `atlas.autonomy_admission.envelope.v1` e `atlas.autonomy_admission.ticket.v1`.

## Fluxo

Kernel -> risk/autonomy cap -> decision envelope -> ticket JSONL append-only.

## Regras para IA

Nao redefinir risk levels, autonomy levels ou workflow de aprovacao aqui. Reutilizar PolicyCanon e services de policy.

## Escopo de Implementacao

Service e testes unitarios existem; integracao profunda com PermissionGate/ApprovalRequest e proxima fatia.

## Dependencias

Constitutional Kernel, ACOS e PolicyCanon.

## Evidencias

Service `AtlasAutonomyAdmissionService` e teste `AtlasAutonomyAdmissionServiceTest`.

## Riscos

Duplicar logic de policy em consumidores ou tratar admission como fonte autoral de policy.

## Exemplos

Use `admit($change)` antes de qualquer consumidor Patamar 4 tentar executar uma mudanca.

## Proximas Acoes

Provar integracoes com PermissionGateService e ApprovalRequestService sem alterar ownership.

## Por que existe (e por que NÃO é um serviço novo no sentido tradicional)

Patamar 4 destrava 6 consumidores autônomos (ASCB, ADML, Reconciliation Runtime, Swarm Conductor, ACMF, TEOS-I4). Cada um precisa responder a mesma pergunta antes de agir:

> "Posso executar essa mudança autonomamente agora? Se não, qual o gap?"

Sem este composer, cada consumidor teria que chamar 5 serviços (Constitutional Kernel + PolicyCanon/PermissionGate + RiskAssessment + Approval + Trust Ledger) e compor manualmente. **Isso é o caminho da bagunça** num codebase escrito por IA: 6 consumidores × 5 chamadas = 30 sítios onde a lógica de composição pode derivar.

A solução **não é** criar um serviço novo com lógica de autonomy/risk/budget — isso seria duplicação massiva do stack `App\Services\Ai\Policy\*`. A solução é um **composer fino** com uma única responsabilidade: **delegar e compor**.

## Princípio de não-duplicação (canon)

| Conceito                | Fonte canon (NÃO duplicar)                                      |
|-------------------------|-----------------------------------------------------------------|
| Autonomy levels         | `App\Services\Ai\Policy\PolicyCanon::AUTONOMY_LEVELS`           |
| Risk levels             | `App\Services\Ai\Policy\PolicyCanon::RISK_LEVELS`               |
| Permission gates        | `App\Services\Ai\Policy\PermissionGateService`                  |
| Risk assessment         | `App\Services\Ai\Policy\RiskAssessmentService`                  |
| Budget envelope         | `App\Services\Ai\Policy\BudgetEnvelopeService`                  |
| Approval workflow       | `App\Services\Ai\Policy\ApprovalRequestService`                 |
| Pétreo invariants       | `App\Services\Ai\Governance\AtlasConstitutionalKernelService`   |
| Trust track record      | `AtlasSelfImprovementHumanTrustLedgerService` (futura integração) |
| Cross-domain privacy    | `AtlasRetrievalPrivacyTrustLayerService` (ARPTL)                |

**Regra absoluta:** se o composer "decidir" algo, é porque está combinando saídas dos canon services acima. Nunca contém lógica de risco/budget/autonomy própria.

## API

```php
public function admit(array $change): array     // envelope canon
public function listTickets(): array            // audit append-only
public function ticketsLogPath(): string
```

### Input shape (mesmo de Constitutional Kernel + `requested_autonomy`)

```json
{
  "change_kind": "subsystem_propose|policy_swap|provider_swap|schema_evolution|domain_bridge",
  "scope": { "privacy_class": "public|normal|sensitive|secret|cyber", "domain": "..." },
  "proposed_effect": "...",
  "claims": [],
  "outbound_data_classes": [],
  "actor": "ASCB|ADML|TEOS-I4|...",
  "requested_autonomy": "suggest|draft|execute_with_approval|autonomous",
  "risk_level": "low|medium|high|critical"   // opcional; senão derivado
}
```

### Output (envelope canônico)

```json
{
  "schema_version": "atlas.autonomy_admission.envelope.v1",
  "decision": "allow_autonomous|allow_with_approval|deny",
  "actor": "...",
  "change_kind": "...",
  "requested_autonomy": "...",
  "effective_autonomy": "...",
  "risk_level": "...",
  "max_autonomy_for_risk": "...",
  "kernel_decision": "allow|block|allow_with_human_approval",
  "kernel_violations": [...],
  "kernel_required_approvals": [...],
  "gaps": ["petreo"|"kernel_requires_approval"|"risk_exceeds_requested_autonomy"],
  "requires_human_approval": true|false,
  "kernel_hash": "sha256:...",
  "envelope_hash": "sha256:..."
}
```

## Regras de composição

1. **Constitutional Kernel é consultado SEMPRE primeiro.**
   - Kernel `block` ⇒ `deny` absoluto, gap=`petreo`.
   - Kernel `allow_with_human_approval` ⇒ teto = `execute_with_approval`, gap=`kernel_requires_approval`.
   - Kernel `allow` ⇒ segue para risco.

2. **Risk → max autonomy** (canon RISK_TO_MAX_AUTONOMY):
   - `low` → `autonomous`
   - `medium` → `execute_with_approval`
   - `high` → `draft`
   - `critical` → `suggest`

3. **Requested vs max:**
   - `requested ≤ max` e sem gaps ⇒ se requested=autonomous, `allow_autonomous`; senão `allow_with_approval`.
   - `requested > max` ⇒ downgrade, gap=`risk_exceeds_requested_autonomy`.

4. **Determinismo:** input idêntico produz envelope idêntico (sem random, sem timestamps no hash).

## Storage

- Append-only JSONL: `storage/atlas/governance/autonomy_admissions.jsonl`
- Nunca cria tabela nova. Quando integrar `PermissionGateService` / `ApprovalRequestService`, eles continuam donos dos próprios models (`AiPermissionGate`, `AiApprovalRequest`).

## Integrações futuras (escopo da próxima fatia, não desta)

- `PermissionGateService::evaluate` para gravar `AiPermissionGate` quando `decision=allow_with_approval`.
- `ApprovalRequestService::request` para abrir aprovação humana automaticamente.
- `AtlasSelfImprovementHumanTrustLedgerService` para puxar trust track record do ator (ASCB com track record limpo pode ter teto maior).

## Não-objetivos

- Não substitui Constitutional Kernel — **consome**.
- Não substitui Policy stack — **compõe**.
- Não decide sozinho nada que já tem fonte canon — apenas orquestra.
- Não persiste em DB — append-only JSONL local-first.

## Replay / audit

`listTickets()` é fonte canon do audit. Cada chamada de `admit` grava ticket com envelope completo + timestamp. Replay = ler o JSONL.
