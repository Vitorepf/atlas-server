---
id: atlas-ai-runtime-packets
type: engineering_knowledge
title: Atlas AI Runtime Packets
status: active
category: runtime-contracts
priority: 81
summary: Mapa canonico dos packets tecnicos legados para contratos atuais de envelope, receipt, ledger, tool events, permission sessions, memory deltas e router decisions.
tags:
  - atlas-ai
  - packets
  - runtime
  - evidence
capabilities:
  - evidence_ledger
  - operation_envelope
  - runtime_contracts
decisions:
  - Packet final e projecao; fonte de verdade auditavel e Evidence Ledger + Operation Envelope + Decision Receipt.
  - Packets legados devem ser mapeados para contratos kernel atuais, nao reintroduzidos como arquitetura paralela.
maintenance:
  - Atualizar quando eventos de ledger, tool runtime, permission sessions ou memory delta mudarem schema.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/engineering-knowledge-base/super-tool-runtime-core.md
  - docs/engineering-knowledge-base/atlas-ai-telemetry-evidence-performance.md
  - resolver-o-que-vale-a-pena/root-md/Atlas_CLI_Packets_v1.md
---

# Atlas AI Runtime Packets

Este documento preserva o valor dos `Atlas_CLI_Packets_v1` sem ressuscitar um
contrato concorrente. No Atlas atual, packets sao projecoes ou DTOs; a verdade
operacional vem de envelope, receipt, ledger, trace e evidence refs.

## Mapping Canonico

| Packet legado | Contrato canonico atual |
|---|---|
| `dev_execution` | Operation Envelope + Decision Receipt + Engineering Blueprint run/evidence |
| `tool_event` | Evidence Ledger event + Super Tool Runtime evidence |
| `permission_session` | Policy/permission scope no receipt + tool gate/approval |
| `memory_delta` | Memory Core delta/proposal + provider-safe review |
| `router_decision` | Domain/flow selection + provider driver plan + Decision Receipt |

## Invariantes

- Todo packet/projecao deve ter `trace_id`, `envelope_id` ou evidence ref.
- Shell mutavel, file write, network ou tool T2/T3 precisam evidence e policy.
- Memory delta nao entra direto em memoria ativa sem review/provider-safety.
- Router decision deve distinguir domain/flow, provider, executor preference e
  safety/autonomy.
- Completion packet nao substitui ledger replay.

## Source Material

- `resolver-o-que-vale-a-pena/root-md/Atlas_CLI_Packets_v1.md`
