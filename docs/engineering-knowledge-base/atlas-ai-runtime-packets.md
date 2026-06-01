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
  - runtime_packet_evidence_contract
  - runtime_operation_envelope_contract
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
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-runtime-packets

graph_title: Atlas AI Runtime Packets

graph_world: atlas

graph_layer: module

graph_kind: contract

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Runtime Packets
canonical_name: Atlas AI Runtime Packets
technical_name: atlas-ai-runtime-packets
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-ai-runtime-packets.md

owner: runtime-contracts

repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-runtime-packets.md

allowed_changes:
  - Atualizar este doc quando codigo, arquitetura, fluxo, evidencia ou decisao canonica mudar.

forbidden_changes:
  - Declarar runtime, maturidade ou prontidao sem evidencia verificavel e gates verdes.

depends_on:
  - atlas-ai-documentation-operating-system

flows_to:
  - atlas-cartography
  - atlas-code

unlocks:
  - ai-safe-implementation-context

governs:
  - runtime-contracts

evidence:
  - docs/engineering-knowledge-base/atlas-ai-runtime-packets.md
evidence_refs:
  - symbol: AtlasAiRuntimePacketsService
  - command: atlas:aaeos:atlas-ai-runtime-packets
  - test: AtlasAiRuntimePacketsTest

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

visual_tags:
  - module
  - contract
  - runtime-contracts

ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA, Evidencias e Riscos antes de implementar.

ai_usage_notes:
  - Use repo_paths, allowed_changes, forbidden_changes e required_tests como limites operacionais.

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"

failure_modes:
  - Contexto desatualizado entre doc, codigo, teste e evidencia.

observability_signals:
  - docs-health status ok

next_actions:
  - Manter este doc sincronizado com codigo, testes, evidencias e Cartografia.
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

## Resumo

Mapa canonico dos packets tecnicos legados para contratos atuais de envelope, receipt, ledger, tool events, permission sessions, memory deltas e router decisions.

## Papel no Atlas

Define a responsabilidade desta peca dentro da arquitetura Atlas.

## Onde Se Encaixa

Relaciona esta peca com seu sistema, camada, fluxo ou modulo pai.

## Contratos

Declara invariantes, entradas, saidas, limites e obrigacoes relevantes.

## Fluxo

Descreve o caminho operacional ou a sequencia de uso quando aplicavel.

## Regras para IA

Agentes devem respeitar escopo, evidencias, testes e proibicoes antes de alterar codigo.

## Escopo de Implementacao

Mudancas devem permanecer nos caminhos e limites declarados no frontmatter.

## Dependencias

Dependencias canonicas vivem em frontmatter e no corpo deste documento.

## Evidencias

Evidencias aceitas incluem docs, comandos, testes, receipts, reports e paths verificaveis.

## Riscos

Riscos principais devem ser tratados antes de promover status, runtime ou claims de prontidao.

## Exemplos

Exemplos concretos devem ser adicionados quando reduzirem ambiguidade para humanos ou IAs.

## Proximas Acoes

Proximas acoes devem ser concretas, verificaveis e ligadas a gates de qualidade.
