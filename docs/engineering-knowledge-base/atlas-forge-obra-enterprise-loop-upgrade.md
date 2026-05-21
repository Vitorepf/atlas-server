---
id: atlas-forge-obra-enterprise-loop-upgrade
type: engineering_knowledge
title: Atlas Forge Obra Enterprise Loop Upgrade
status: active
category: programming-forge
priority: 100
summary: Upgrade canonico do Atlas Forge para absorver, em versao Obra enterprise, a musculatura operacional que Atlas Dev consolidou com audit, execution receipt, debug loop, failure capsule, learning handoff, strict mode e Desktop visibility.
tags:
  - atlas
  - forge
  - obra
  - enterprise-loop
  - upgrade
  - evidence
capabilities:
  - forge_obra_enterprise_loop
  - forge_enterprise_engineering_audit
  - forge_obra_execution_receipt
  - forge_repair_ledger
  - forge_failure_capsule
  - forge_command_center_visibility
decisions:
  - Forge deve estruturar sua propria versao das capacidades de Atlas Dev, sem copiar a identidade do Dev.
  - A versao Forge opera no nivel de Obra: SDD, fases, work packets, provider topology, repair, evidence pack, completion gate e continuidade longa.
  - `senior_engineer_loop_audit` inspira `forge_enterprise_engineering_audit`.
  - `senior_engineer_loop_execution` inspira `forge_obra_execution_receipt`.
  - Error ledger, failure capsule e learning handoff do Dev devem virar repair ledger, incident capsule e architecture learning proposals no Forge.
  - Strict mode do Dev deve virar certificacao Forge por Obra, com blockers honestos e evidence obrigatoria.
maintenance:
  - Atualize este doc quando Forge adicionar novos receipts, audit, repair, learning, Desktop panels ou certification commands.
  - Nao reduza Forge a executor tatico; este upgrade fortalece o sistema completo de Obras.
related_paths:
  - docs/engineering-knowledge-base/atlas-forge-continuum-os.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system-contracts.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system-runbook.md
  - docs/engineering-knowledge-base/atlas-dual-core-engineering-system.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-forge-obra-enterprise-loop-upgrade
graph_title: Atlas Forge Obra Enterprise Loop Upgrade
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-forge-continuum-os
graph_status: active
graph_source: repo
human_name: Atlas Forge Obra Enterprise Loop Upgrade
canonical_name: Atlas Forge Obra Enterprise Loop Upgrade
technical_name: atlas-forge-obra-enterprise-loop-upgrade
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-forge-obra-enterprise-loop-upgrade.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-forge-obra-enterprise-loop-upgrade.md
allowed_changes:
  - Refinar schemas de audit, execution receipt, repair ledger, failure capsule e learning proposals.
  - Adicionar comandos, endpoints e panels quando implementados.
forbidden_changes:
  - Tratar Forge como copia ampliada do Dev.
  - Remover SDD, Obra, provider topology, completion gate ou continuidade longa do escopo Forge.
  - Declarar Forge enterprise-ready sem strict audit, evidence pack e completion gate.
depends_on:
  - atlas-forge-continuum-os
  - atlas-forge-operating-system
  - atlas-dual-core-engineering-system
  - atlas-dev-efficient-programming-flow-v1
flows_to:
  - forge_enterprise_audit
  - forge_obra_execution
  - forge_repair_loop
  - forge_command_center
  - forge_certification
unlocks:
  - stronger_forge_enterprise_loop
  - obra_level_evidence_contracts
  - long_run_repair_and_learning
governs:
  - atlas_forge.obra_enterprise_loop_upgrade
  - forge.evidence_contract_upgrade
  - forge.repair_and_learning_upgrade
evidence:
  - docs/engineering-knowledge-base/atlas-forge-obra-enterprise-loop-upgrade.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
ai_entrypoints:
  - Leia este doc antes de implementar upgrades no Forge inspirados no Atlas Dev Senior Engineer Loop.
ai_usage_notes:
  - Use o Atlas Dev como referencia operacional, nao como arquitetura-mãe do Forge.
  - Preserve a escala de Obra: SDD, fases, packets, providers, evidence, review e release.
quality_gates:
  - forge-not-dev-copy
  - obra-level-evidence
  - strict-audit-before-claim
  - failure-capsule-on-blocker
  - repair-ledger-recorded
  - completion-gate-required
failure_modes:
  - Copiar schemas do Dev sem elevar para Obra.
  - Criar receipt sem fases, providers, SDD ou completion gate.
  - Aprendizado alterar arquitetura sem curator e approval gate.
observability_signals:
  - obra_id
  - forge_audit_status
  - forge_execution_status
  - phase_statuses
  - provider_decisions
  - repair_attempts
  - failure_capsules
  - completion_gate_status
  - learning_proposal_refs
next_actions:
  - Criar schema `atlas.forge.enterprise_engineering_audit.v1`.
  - Criar schema `atlas.forge.obra_execution_receipt.v1`.
  - Criar repair ledger e incident capsule do Forge.
  - Expor Forge Enterprise Loop no Command Center.
line_limit: 520
---
# Atlas Forge Obra Enterprise Loop Upgrade

## Resumo

Este documento define o upgrade canonico do Atlas Forge para absorver, em escala
de Obra enterprise, a musculatura operacional que Atlas Dev consolidou:
audit antes da execucao, execution receipt, debug/repair loop, failure capsule,
learning handoff, strict mode e visibilidade no Desktop.

Nao e copia do Dev. E a versao Forge: mais pesada, longa, governada e orientada
a SDD, fases, work packets, providers, evidence pack e completion gate.

## Papel no Atlas

Atlas Dev agora registra rastros de engenheiro senior. Atlas Forge deve registrar
rastros de Obra enterprise completa.

O upgrade transforma capacidades do Dev em contratos Forge:

| Atlas Dev | Atlas Forge upgrade |
| --- | --- |
| `senior_engineer_loop_audit` | `forge_enterprise_engineering_audit` |
| `senior_engineer_loop_execution` | `forge_obra_execution_receipt` |
| verification receipt | evidence pack + completion gate |
| error ledger | repair ledger + failure memory |
| failure capsule | incident capsule / blocked-work capsule |
| bounded debug loop | phased repair loop + rollback |
| learning handoff | architecture learning proposal |
| Desktop `senior_loop` | Forge Command Center enterprise loop |
| strict run/audit | Forge certification by Obra |

## Onde Se Encaixa

Fica abaixo de `atlas-forge-continuum-os.md` e ao lado dos contratos/runbooks do
Forge. O Dual-Core define a fronteira Dev/Forge; este documento define como o
Forge fica mais poderoso por dentro.

```text
Atlas Forge Continuum OS
-> Obra
-> SDD / Obra Intake
-> Forge Enterprise Engineering Audit
-> Forge Obra Execution Receipt
-> Repair Ledger / Incident Capsule
-> Evidence Pack / Completion Gate
-> Command Center / Certification
```

## Contratos

### `atlas.forge.enterprise_engineering_audit.v1`

Audit antes da execucao pesada. Deve responder se a Obra esta pronta.

Campos minimos:

- `obra_id`, `intent`, `sdd_status`, `risk_level`;
- `workspace_ready`, `provider_topology_ready`, `rollback_ready`;
- `work_packets_ready`, `completion_gate_ready`;
- `blockers`, `required_evidence`, `strict_status`.

### `atlas.forge.obra_execution_receipt.v1`

Recibo da execucao Forge. Deve contar a historia da Obra.

Campos minimos:

- `obra_id`, `run_id`, `status`, `phases`;
- `sdd_ref`, `work_packet_refs`, `provider_decision_refs`;
- `execution_receipts`, `fallback_events`, `repair_attempts`;
- `evidence_pack_ref`, `completion_gate_ref`, `release_decision`;
- `blockers`, `learning_proposals`, `execution_hash`.

### `atlas.forge.repair_ledger.v1`

Ledger de falhas e reparos, com causa, impacto, tentativa, resultado e proximo
passo. Deve sobreviver a sessoes longas.

### `atlas.forge.incident_capsule.v1`

Capsula quando uma fase bloqueia, falha ou exige humano. Deve conter contexto,
fase, provider, evidencia, impacto, rollback/mitigation e recomendacao.

## Fluxo

1. Receber prompt ou Obra Intake.
2. Gerar/validar SDD.
3. Rodar `forge_enterprise_engineering_audit`.
4. Bloquear se strict audit falhar.
5. Dividir em fases e work packets.
6. Decidir providers via Atlas Decide/topology.
7. Executar com checkpoints e receipts.
8. Registrar repair ledger e incident capsule em falha.
9. Montar evidence pack.
10. Passar completion gate.
11. Emitir release/promotion decision.
12. Enviar learning proposals para curator.

## Regras para IA

- Use o Dev como referencia de disciplina operacional, nao como molde literal.
- Tudo no Forge deve subir um nivel: task vira Obra, receipt vira evidence pack,
  debug vira repair, failure capsule vira incident capsule.
- Nunca declare completion sem evidence pack e completion gate.
- Nunca aplique learning arquitetural sem curator/approval.
- Nunca esconda fallback, blocker, provider failure ou repair attempt.

## Escopo de Implementacao

| Bloco | O que implementar | Horas |
| --- | --- | ---: |
| Enterprise audit | Schema, service, command strict e blockers | 8-14h |
| Obra execution receipt | Receipt por fases, providers, packets, evidence e release decision | 10-18h |
| Repair ledger | Falha, causa, tentativa, resultado e stop reason | 8-12h |
| Incident capsule | Capsulas de bloqueio/falha com impacto e mitigacao | 6-10h |
| Learning proposals | Propostas arquiteturais com curator e approval gate | 8-12h |
| Command Center | Painel com SDD, fases, providers, blockers, evidence e completion | 12-24h |
| Certification | `atlas:forge:enterprise-audit --strict` e `atlas:forge:obra:run --strict` | 8-14h |
| Test matrix | Happy path, blocked audit, repair, incident, completion gate e learning | 12-20h |

Estimativa:

| Nivel | Horas |
| --- | ---: |
| MVP forte | 40-60h |
| Enterprise-interna | 80-120h |
| Outro patamar Forge | 140-220h |

## Dependencias

- Atlas Forge Continuum OS.
- Atlas Forge Operating System.
- Atlas Dual-Core Engineering System.
- Atlas Dev Efficient Programming Flow.
- Atlas Decide/provider topology.
- Evidence Ledger e completion gate.

## Evidencias

Evidencia minima para considerar o upgrade implementado:

- audit strict passa ou bloqueia honestamente;
- execution receipt persiste fases, providers, evidence e release decision;
- falha gera repair ledger e incident capsule;
- completion claim exige evidence pack;
- Desktop/API mostram estado da Obra;
- docs-health e testes Forge passam.

## Riscos

- Copiar Dev sem elevar para Obra.
- Criar audit aspiracional sem command strict.
- Criar receipt sem SDD, work packets ou providers.
- Fazer learning auto-aplicar arquitetura sem curator.
- Declarar Forge enterprise antes do completion gate real.

## Exemplos

- Prompt: "reestruture billing enterprise" -> Forge cria SDD, audit, fases,
  provider topology, execution receipt, evidence pack e release decision.
- Falha em provider -> registra fallback event, repair ledger e incident capsule.
- Obra pronta -> completion gate valida evidence antes de claim.

## Proximas Acoes

1. Implementar `forge_enterprise_engineering_audit`.
2. Implementar `forge_obra_execution_receipt`.
3. Implementar `forge_repair_ledger` e `forge_incident_capsule`.
4. Expor tudo no Forge Command Center.
5. Criar comandos strict e matriz de testes.
