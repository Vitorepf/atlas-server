---
id: atlas-ai-kernel-static-scans
type: engineering_knowledge
title: Atlas AI Kernel Static Scans
status: active
category: architecture
priority: 99
summary: Architectural static scans and compliance tests that turn Kernel doctrine into enforceable CI behavior.
tags:
  - atlas-ai
  - kernel
  - static-scans
  - compliance
capabilities:
  - architectural_test_doctrine
  - capability_registry_enforcement
  - surface_adapter_contract
decisions:
  - Doctrine is not accepted unless a scan, test, gate or runtime guard can enforce it.
  - Static scans prevent bypasses before runtime.
  - Compliance tests must include at least one negative regression case when possible.
maintenance:
  - Add new scan IDs to architecture validation output.
  - Keep failures actionable and tied to owner docs.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-session-bootstrap.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-kernel-static-scans

graph_title: Atlas AI Kernel Static Scans

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Kernel Static Scans
canonical_name: Atlas AI Kernel Static Scans
technical_name: atlas-ai-kernel-static-scans
cartography_type: module
canonical_source: docs/engineering-knowledge-base/kernel/static-scans.md

owner: kernel

repo_paths:
  - docs/engineering-knowledge-base/kernel/static-scans.md

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
  - kernel

evidence:
  - docs/engineering-knowledge-base/kernel/static-scans.md
evidence_refs:
  - symbol: AtlasKernelStaticScansService
  - command: atlas:aaeos:kernel-static-scans
  - test: AtlasKernelStaticScansTest

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - module
  - kernel

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
# Kernel Static Scans

## Scan Families

| Family | Prevents |
|---|---|
| Surface/provider bypass | surfaces calling providers or tools directly |
| Context bypass | surfaces building privileged context outside Context Builder |
| Receipt propagation | runtime execution without Decision Receipt |
| Capability parity | capability supported in one surface but missing in required peers |
| Provider identity | provider calls without Atlas identity fragment |
| Ledger projection drift | read models diverging from append-only event truth |
| Inbox/proposal parity | Curator proposals missing review surfaces |
| Documentation health | oversized or malformed docs entering canonical KB |
| Runtime language boundary (AP-201) | Python/Go/Swift scope drifting into the wrong layer; `EmbeddingService`, controllers, jobs and commands cannot become RAG/ML or direct native runtime shortcuts through shell, Laravel Process or Symfony Process; focused check: `php artisan atlas:ai:runtime-boundary --json`, `/ai/runtime-boundary`, `atlas_runtime_boundary` |
| Provider release anti-wrapper (AP-178) | provider launches changing defaults, domain maturity, credentials or direct channels without AP-99/Rivals/review |
| Voice runtime certification (AP-185) | certification artifacts leaking tokens, raw audio, raw text, tool calls or provider secrets; Rivals-Voice running without sanitized certificate |
| Voice Python runtime boundary (AP-686) | LiveKit/Python voice runtime importing provider SDKs, executing tools/shell, persisting raw audio, accepting nested secret metadata or bypassing Kernel-only callback contract |
| Voice production promotion (AP-687) | voice runtime being promoted to production without real SDK certification, LiveKit token issuer readiness, namespace-safe bootstrap, SDK handler blueprint, Kernel event normalizer, smoke proof and human review |
| Local RAG promotion review (AP-683) | Local RAG benchmark being treated as Graph RAG/Python promotion permission |
| External Graph Harness (AP-684) | Graphify or external graph candidates jumping into Memory, Context, Constelacao, Decide or runtime |
| Constelacao Lente 1 usage review (AP-685) | Lente 1 being promoted to Command Sky, Graph RAG, lineage or operational UI before usage review |
| AP-168 Productive Failure governance | Keeps Productive Failure explicit, operator-led, storage-backed and prediction-error based; blocks random frustration and sessions without `productive_failure_sessions`. |
| AP-169 Personal Worked Examples privacy | Raw personal source content leaking into Ledger summaries or the extraction read model |
| AP-170 Predictive Failure governance | Keeps `atlas predict failure` operator-led with alvo explicito, calibration/safety gates, outcome tracking and Ledger events; blocks random frustration plus daily-plan/UX/KG maduro promotion before future APs. |

## Required Scan Behavior

Each scan should return:

1. stable scan id;
2. pass/fail;
3. violation count;
4. paths and symbols involved;
5. owner area;
6. remediation hint;
7. whether failure blocks merge.

## Negative Regression Examples

- Paste-image only in `atlas ask` must fail capability parity.
- `atlas dev` provider call without receipt must fail runtime guard.
- Curator proposal without inbox action must fail proposal parity.
- Heavy RAG/ML libraries or direct Go/Swift native runtime shortcuts in Laravel `app/`, including services, semantic adapters, controllers, jobs and commands, must fail runtime language boundary.
- The same AP-201 boundary also covers shell helpers, Laravel Process and Symfony Process so runtime escapes cannot hide behind process wrappers.
- Provider vertical launch promoted to default model/domain-ready must fail AP-178.
- Voice runtime certification exposing `access_token`, `raw_audio`, raw response text, tool calls or provider secrets must fail AP-185.
- Voice Python runtime importing provider SDKs, shelling out, logging raw audio/tokens, accepting nested SDK secret/authority metadata, omitting runtime return `evidence_refs`, or reporting `runtime_failed` before a Kernel-accepted turn must fail AP-686.
- Voice runtime production promotion without SDK-ready, token issuer-ready, namespace-safe bootstrap, product-loop check exposed to Rivals, SDK handler blueprint, `sdk_kernel_normalizer_required`, production-loop smoke and human review must fail AP-687.
- Local RAG benchmark promoted to Graph RAG/Python without `proposal_only`, review and future AP must fail AP-683.
- External graph candidates promoted beyond read-only Architecture Operations review must fail AP-684.
- Constelacao Lente 1 promoted to Command Sky, lineage, Graph RAG or operational chrome before 30-day usage review must fail AP-685.
- Productive Failure started without explicit topic, `productive_failure_sessions`, prediction-error delta, review-only transfer test or with random frustration must fail AP-168.
- Personal worked examples persisting raw email, secret, token, `source_metadata` or `quality_signals` into Ledger summaries or read model de extracao must fail AP-169.
- Predictive failure inserted without opt-in, specific target, calibration/safety gates, outcome tracking or with random frustration / daily-plan/UX/KG maduro promotion must fail AP-170.
- New doc without required frontmatter must fail docs-health.

## Validation Surface

`php artisan atlas:ai:architecture-validate --json` is the primary machine
surface. Human summaries can exist, but CI and agents should consume JSON.

## Resumo

Architectural static scans and compliance tests that turn Kernel doctrine into enforceable CI behavior.

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
