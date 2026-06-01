---
id: atlas-local-agent-surface
type: engineering_knowledge
title: Atlas Local Agent Surface
status: active
category: surface-architecture
priority: 80
summary: Contrato canonico para agentes locais do Mac como surface/automation de energia, wake, background jobs e readiness, separado do Atlas Native Mac Agent em Swift.
tags:
  - atlas
  - mac-agent
  - local-surface
  - automation
capabilities:
  - local_agent_surface
  - background_job_readiness
  - local_agent_mobile_bridge
decisions:
  - Mac Agent e surface/automation local, nao arquitetura-mae.
  - Atlas Native Mac Agent cobre Swift/macOS APIs sensiveis; este doc cobre readiness/background automation.
  - Readiness local pode bloquear claim de jobs, mas nao bypassa Kernel policy, receipt ou evidence.
  - Wake/caffeinate/power helper sao detalhes operacionais documentados no runbook externo.
maintenance:
  - Atualizar quando host agent, wake scheduling, mobile mac endpoints ou background job readiness mudarem.
related_paths:
  - docs/atlas-mac-agent.md
  - docs/engineering-knowledge-base/atlas-native-mac-agent.md
  - docs/engineering-knowledge-base/atlas-ai-mobile-surface-gateway.md
  - docs/engineering-knowledge-base/atlas-ai-operating-system.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-local-agent-surface

graph_title: Atlas Local Agent Surface

graph_world: atlas

graph_layer: module

graph_kind: surface

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas Local Agent Surface
canonical_name: Atlas Local Agent Surface
technical_name: atlas-local-agent-surface
cartography_type: surface
canonical_source: docs/engineering-knowledge-base/atlas-local-agent-surface.md

owner: surface-architecture

repo_paths:
  - docs/engineering-knowledge-base/atlas-local-agent-surface.md

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
  - surface-architecture

evidence:
  - docs/engineering-knowledge-base/atlas-local-agent-surface.md
evidence_refs:
  - symbol: AtlasLocalAgentSurfaceService
  - command: atlas:aaeos:local-agent-surface
  - test: AtlasLocalAgentSurfaceTest

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - module
  - surface
  - surface-architecture

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
# Atlas Local Agent Surface

O Atlas Local Agent Surface cobre automacoes locais do Mac usadas para manter o
servidor disponivel, acordar janelas de manutencao e proteger background jobs.

## Autoridade

| Assunto | Autoridade |
|---|---|
| Arquitetura local surface/automation | Este documento |
| Comandos de instalacao, validacao e uninstall | `../atlas-mac-agent.md` |
| Swift/macOS APIs, Keychain, Touch ID, FSEvents | `atlas-native-mac-agent.md` |
| Mobile gateway e endpoints mobile | `atlas-ai-mobile-surface-gateway.md` |
| Runtime AI, jobs e policy | Kernel/Operating System |

## Fronteira

O Mac Agent pode:

- manter heartbeat local;
- segurar o Mac acordado por sessao;
- reconciliar `caffeinate`;
- programar wake por helper root quando instalado;
- bloquear background jobs quando readiness local falha;
- expor estado para app/mobile.

O Mac Agent nao pode:

- executar provider ou tool sem receipt/policy;
- elevar permissao por estar local;
- esconder falha de readiness;
- depender de push/inbox como unico watchdog.

## Diferenca Para Native Mac Agent

`atlas-local-agent-surface.md` cobre disponibilidade local, wake, readiness e
background jobs. `atlas-native-mac-agent.md` cobre capacidades Swift sensiveis:
Keychain, Touch ID, notificacoes nativas, FSEvents, Accessibility,
ScreenCaptureKit e Menu Bar. Ambos sao subordinados ao Kernel.

## Source Material

- `docs/atlas-mac-agent.md`

## Resumo

Contrato canonico para agentes locais do Mac como surface/automation de energia, wake, background jobs e readiness, separado do Atlas Native Mac Agent em Swift.

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
