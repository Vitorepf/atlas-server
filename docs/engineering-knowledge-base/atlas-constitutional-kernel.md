---
id: atlas-constitutional-kernel
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas Constitutional Kernel
status: active
implementation_state: runtime_available_integration_partial
category: governance
priority: 99
summary: Kernel constitucional local-first que valida mudancas autonomas contra invariantes petreos, bloqueia claims proibidos, exige aprovacao humana para scopes sensiveis e registra tickets append-only.
tags: [atlas-ai, governance, constitutional, self-construction, human-approval]
capabilities: [invariant_validation, petreo_policy_gate, violation_ticket_log, kernel_hash, sensitive_scope_approval_gate]
decisions:
  - Invariantes petreos vivem em codigo e nao sao mutaveis por runtime, provider ou UI.
  - Constitutional Kernel valida propostas autonomas, mas nao substitui ARPTL, Evidence Ledger, ADER ou owner docs.
  - Integracao de cada consumidor Patamar 4 precisa ser provada por teste antes de claim de enforcement total.
maintenance:
  - Atualizar antes de mudar invariantes, schemas, CLI ou consumidores autonomos.
  - Manter fail-closed, hash deterministico e log append-only cobertos por teste.
risk_level: critical
graph_parent: atlas-cognition-operating-system
graph_id: atlas-constitutional-kernel
graph_title: Atlas Constitutional Kernel
graph_world: atlas
graph_layer: module
graph_kind: policy
graph_status: active
graph_source: repo
depends_on:
  - atlas-cognition-operating-system
  - atlas-ai-knowledge-governance-system
flows_to:
  - atlas-ai-self-construction-os
  - atlas-cross-domain-mesh-arptl
  - atlas-cognition-operating-system
unlocks: [petreo_change_validation, autonomous_change_preflight, constitutional_violation_receipts]
governs: [petreo_invariants, autonomous_change_validation, constitutional_violation_tickets]
related_paths:
  - docs/engineering-knowledge-base/atlas-cognition-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md
  - docs/engineering-knowledge-base/self-construction/constitution.md
  - app/Services/Ai/Governance/AtlasConstitutionalKernelService.php
  - app/Console/Commands/AtlasConstitutionalKernelCommand.php
  - tests/Unit/Ai/Governance/AtlasConstitutionalKernelServiceTest.php
repo_paths:
  - docs/engineering-knowledge-base/atlas-constitutional-kernel.md
  - app/Services/Ai/Governance/AtlasConstitutionalKernelService.php
owner: atlas-ai
authority_class: petreo
forbidden_changes:
  - drop_petreo_invariant
  - flip_external_rivals_blocked
  - flip_sovereignty_local_first
  - flip_cognitive_immune_law
  - allow_silent_invariant_mutation
schema:
  - atlas.constitutional_kernel.invariant.v1
  - atlas.constitutional_kernel.validation_envelope.v1
  - atlas.constitutional_kernel.violation_ticket.v1
evidence:
  - docs/engineering-knowledge-base/atlas-constitutional-kernel.md
  - app/Services/Ai/Governance/AtlasConstitutionalKernelService.php
  - app/Console/Commands/AtlasConstitutionalKernelCommand.php
  - tests/Unit/Ai/Governance/AtlasConstitutionalKernelServiceTest.php
required_tests:
  - "php artisan test tests/Unit/Ai/Governance/AtlasConstitutionalKernelServiceTest.php"
  - "php artisan atlas:constitutional:kernel --action=list-invariants --json"
  - "php artisan atlas:engineering:knowledge docs-health --json"
allowed_changes:
  - Adicionar consumidores com teste que prove chamada fail-closed ao gate.
  - Evoluir invariantes somente por source change revisado e testes.
forbidden_runtime_claims:
  - Dizer que todo Patamar 4 ja atravessa este gate sem prova de integracao por consumidor.
  - Permitir provider, UI ou runtime mutar invariante petreo.
requires_evidence: true
line_limit: 520
next_actions:
  - Adicionar testes de comando CLI.
  - Integrar ASCB, ACDM, TEOS e Swarm/Reconciliation quando esses consumidores forem promovidos.
  - Registrar em scorecard/readiness quando a integracao cross-consumer estiver completa.
---

# Atlas Constitutional Kernel — Patamar 4 Foundation (4.0)

## Por que existe

Patamar 4 destrava auto-evolução autônoma (Self-Construction + Reconciliation + Swarm Conductor). Sem um vault pétreo de invariantes, "evolução autônoma" vira "evolução descontrolada". O Constitutional Kernel é o gate pétreo que mudanças autônomas devem atravessar antes de serem aplicadas. O runtime e CLI existem; a integração completa de cada consumidor Patamar 4 continua exigindo teste próprio antes de qualquer claim de enforcement total.

É a fronteira que distingue **Atlas em Patamar 4** ("evolui sozinho dentro do perímetro autorizado") de **risco existencial** ("evolui contra o operador").

## Três classes de invariante

| Classe   | Definição                                                                   | Quem pode alterar                                              |
|----------|-----------------------------------------------------------------------------|----------------------------------------------------------------|
| `petreo` | Pétreo. Imutável. Nem o operador altera em runtime.                         | Ninguém. Mudança exige edição manual do source + redeploy.     |
| `elastic`| Elástico. O operador pode flipar com `--check` + `--confirm` + receipt.     | Apenas o operador, via comando explícito.                      |
| `runtime`| Auto-tune dentro de range pré-declarado (ex.: budget tier delta ≤ 0.05).    | ADML/Reconciliation, dentro do range, com receipt obrigatório. |

## Invariantes pétreos canon (carregados no boot)

| Invariant ID                          | Statement                                                                                          |
|---------------------------------------|----------------------------------------------------------------------------------------------------|
| `claim_policy_provider_safe`          | Nunca permitir claim de benchmark/rivals/superiority em código, doc ou output de provider.         |
| `sovereignty_local_first`             | Classes sensitive/secret/cyber NUNCA saem da máquina; nenhum dado dessas classes em provider call. |
| `cognitive_immune_law`                | Raw Capture ≠ Evidence ≠ Learning Signal ≠ Memory ≠ Context ≠ Decision. Fronteiras imutáveis.    |
| `external_rivals_certification_blocked` | A cert `external_rivals_certification` permanece bloqueada para sempre.                          |
| `no_jarvis_vocabulary`                | Vocabulário proibido: "Jarvis", "Rivals", "benchmark", "superiority", "concurrent".               |
| `atlas_is_substrato_not_wrapper`      | Atlas é substrato de soberania pessoal — nunca chamar de "wrapper de IA" / "ferramenta de produtividade" / "sistema de memória". |
| `evidence_append_only`                | Evidence Ledger é append-only. Nenhuma operação retroativa.                                        |
| `human_approval_for_high_risk`        | Mudanças cruzando domínios sensitive/secret/cyber exigem aprovação humana dura.                    |
| `no_silent_invariant_mutation`        | Nenhuma mudança em invariante (pétreo, elastic ou runtime) sem registro append-only no ledger.    |

## API

```
validateChange(array $change): array        // schema atlas.constitutional_kernel.validation_envelope.v1
listInvariants(?string $class = null): array
listViolations(): array
recordViolation(array $payload): array      // schema atlas.constitutional_kernel.violation_ticket.v1
kernelHash(): string                         // sha256 sobre o set canônico de invariantes
```

### `validateChange` input shape

```json
{
  "change_kind": "subsystem_propose|policy_swap|provider_swap|schema_evolution|domain_bridge|...",
  "scope": { "domain": "engineering|finance|...", "subsystem": "...", "privacy_class": "public|normal|sensitive|secret|cyber" },
  "proposed_effect": "free-form string describing what the change would do",
  "claims": ["benchmark"|"rivals"|"superiority"|...],
  "outbound_data_classes": ["public"|"normal"|"sensitive"|"secret"|"cyber"],
  "actor": "ASCB|ADML|TEOS-I3|operator|...",
  "requires_human_approval_hint": true|false
}
```

### `validateChange` output

```json
{
  "schema_version": "atlas.constitutional_kernel.validation_envelope.v1",
  "decision": "allow|block|allow_with_human_approval",
  "violations": [ { "invariant_id": "...", "reason": "..." } ],
  "required_approvals": ["operator"],
  "kernel_hash": "sha256:..."
}
```

## Invariantes de runtime (sobre o próprio Kernel)

- **append-only**: cada mudança em invariante (mesmo elastic) vira ticket no log.
- **deterministic hash**: `kernelHash()` retorna sha256 sobre `{id, class, statement, enabled}` ordenado por id — qualquer drift detectável.
- **fail-closed**: input malformado → decision=block (nunca allow por default).

## Storage

- Append-only JSONL local-first:
  - `storage/atlas/governance/violations.jsonl`
- Set de invariantes vive em código (constante) — qualquer mudança exige PR + redeploy. Isso é por design (não é DB).

## CLI

```bash
php artisan atlas:constitutional:kernel --action=list-invariants [--class=petreo|elastic|runtime] [--json]
php artisan atlas:constitutional:kernel --action=validate --change-json='{...}' [--json]
php artisan atlas:constitutional:kernel --action=list-violations [--json]
php artisan atlas:constitutional:kernel --action=kernel-hash [--json]
```

## Como o restante de Patamar 4 deve usar

| Consumer                           | Quando chama                                                         |
|------------------------------------|----------------------------------------------------------------------|
| `AtlasSelfConstructionSubsystemBuilderService::propose` | Antes de gravar proposta.                                            |
| `AtlasTrustBudgetService::canActAutonomously`            | Ao decidir se autonomia é permitida em dado scope.                    |
| `AtlasAutonomousReconciliationRuntimeService::tick`      | Antes de aplicar qualquer reconciliation step.                        |
| `AtlasTeosI4CounterfactualTreeService::expand`           | Antes de simular branches em domínios sensitive/secret.               |
| `AtlasSwarmConductorService::dispatch`                   | Antes de cada dispatch multi-arm cross-domain.                        |
| `AtlasCrossDomainMeshService::bridge`                    | Já chamado indiretamente via decision rules; Kernel cobre o pétreo. |

Status de integração: a tabela acima é contrato de integração. Só declare um consumidor como enforced depois de teste focado provar a chamada ao `AtlasConstitutionalKernelService`.

## Não-objetivos

- Não é DB. Não é editável em runtime via UI. Não é configurável por provider.
- Não substitui ARPTL (Cross-Domain Mesh) — Kernel é **pétreo geral**; ARPTL é **decisão por privacidade/domínio**.
- Não substitui claim_policy do scorecard — Kernel **enforça** claim_policy; scorecard **mede** se está enforçada.

## Replay / audit

`listViolations()` é fonte canon de auditoria — cada `block` ou `allow_with_human_approval` vira ticket no JSONL. Operador inspeciona via CLI ou via futuro painel Cartografia.

## Resumo

Atlas Constitutional Kernel valida mudanças autônomas contra invariantes pétreos locais e registra decisões sensíveis em log append-only.

## Papel no Atlas

Ele impede que self-construction, routing, reconciliation ou runtime cognitivo relaxem regras fundamentais sem source change revisado, teste e evidência.

## Onde Se Encaixa

Fica abaixo de Patamar 4 e acima dos consumidores autônomos. Não substitui docs canônicos, ADER, ARPTL, Evidence Ledger ou scorecards.

## Contratos

Schemas `atlas.constitutional_kernel.invariant.v1`, `atlas.constitutional_kernel.validation_envelope.v1` e `atlas.constitutional_kernel.violation_ticket.v1`.

## Fluxo

Caller envia envelope de mudança, o serviço valida claims, privacy class, vocabulário proibido e aprovação humana, então retorna `allow`, `block` ou `allow_with_human_approval`.

## Regras para IA

Não declarar enforcement total sem teste por consumidor. Não editar invariantes pétreos por runtime. Não usar este doc para bypassar ARPTL, ADER ou Evidence Ledger.

## Escopo de Implementacao

Implementado como serviço local, CLI e testes unitários de invariantes, hash, fail-closed e tickets append-only.

## Dependencias

- `AtlasConstitutionalKernelService`
- `AtlasConstitutionalKernelCommand`
- `AtlasCognitionScoreCardService`
- `self-construction/constitution.md`

## Evidencias

- `php artisan test tests/Unit/Ai/Governance/AtlasConstitutionalKernelServiceTest.php`
- `php artisan atlas:constitutional:kernel --action=list-invariants --json`
- `php artisan atlas:engineering:knowledge docs-health --json`

## Riscos

O risco principal é superclaim: dizer que todo consumidor Patamar 4 já é protegido quando apenas o runtime constitucional está pronto.

## Exemplos

Uma proposta que tente enviar `cyber` para provider remoto retorna `block`; uma mudança em scope `cyber` local pode retornar `allow_with_human_approval`.

## Proximas Acoes

Adicionar prova CLI e integrar consumidores Patamar 4 um por um, sempre com teste focado e receipt.
