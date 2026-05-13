---
id: atlas-ai-obras-ai-harness-governance-and-quality
type: engineering_knowledge
title: Atlas Obras - AI Harness Governance And Quality
status: active
category: architecture
priority: 100
summary: AI harness, governance, quality gates, review flows and policy rules for Obras.
tags:
  - atlas-ai
  - obras
  - ai-harness
  - governance
capabilities:
  - obras_operating_system
  - quality_gates
  - governed_ai_execution
decisions:
  - AI inside Obras must operate against Obra context, not loose chat context.
  - Multi-provider AI inside Obras must use Obras Shared Workspace and artifact exchange, not provider-to-provider loose handoff.
  - Critical Obras require evidence, gates, permissions and human checkpoints.
maintenance:
  - Update before changing Obra AI actions, gates, policy, providers, evidence or approval rules.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-obras-operating-system.md
  - docs/engineering-knowledge-base/obras/shared-workspace-and-forge.md
  - docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-cognitive-runtime.md
owner: atlas-ai
layer: 2-product-primitive
line_limit: 240
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-obras-ai-harness-governance-and-quality

graph_title: Atlas Obras - AI Harness Governance And Quality

graph_world: atlas

graph_layer: system

graph_kind: policy

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo

repo_paths:
  - docs/engineering-knowledge-base/obras/ai-harness-governance-and-quality.md

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
  - obras

evidence:
  - docs/engineering-knowledge-base/obras/ai-harness-governance-and-quality.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - policy
  - obras

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
# Atlas Obras - AI Harness Governance And Quality

## Obra Context Pack

Every AI action inside an Obra must build the smallest sufficient context:

- Obra summary;
- current objective;
- current phase/status;
- relevant node/section;
- relevant sources;
- relevant decisions;
- recent feedback;
- applicable gates;
- operator preferences;
- domain policies.

The AI must not treat "review my methodology" or "build Atlas Educacional MVP"
as loose chat. It must build an Operation Envelope with `obra_id`, domain,
section, task type, sources, decisions, deadline, quality gate and risk.

## AI Harness Components

- Intent Parser;
- Context Builder;
- Skill System;
- Model Router;
- Policy Engine;
- Quality Gate Runner;
- Reviewer;
- Repair Loop;
- Memory Delta;
- Learning Loop;
- Trace System;
- Output Renderer.

## Execution Runtimes

ObraOS may call specialized runtimes:

- Research Runtime;
- Writing Runtime;
- Code Runtime;
- Document Runtime;
- Planning Runtime;
- Review Runtime;
- Output Runtime.

Each runtime must emit evidence and must respect policy.

## Governance

Required governance components:

- permissions;
- audit;
- approvals;
- risk policy;
- data policy;
- provider policy;
- human checkpoints.

Human checkpoints are required for:

- scope changes;
- strategic decisions;
- dubious sources;
- external publication;
- financial action;
- sensitive action;
- irreversible change.

## Provider Policy

Before selecting a model, Atlas must ask:

- does this Obra contain sensitive data?
- may data leave local environment?
- should data be anonymized?
- is local model required?
- what is the budget?
- is human review required?

## Universal Gates

Universal gates:

- objective clarity;
- definition of done;
- structure coherence;
- next step;
- decision coverage;
- evidence coverage;
- risk clarity;
- tradeoff clarity;
- current version;
- output definition;
- learning capture;
- strategic relationship.

## Domain Gates

Technical gates:

- spec clarity;
- testable requirements;
- architecture documented;
- tradeoffs registered;
- tests passed;
- technical risks mapped;
- documentation updated.

Strategic gates:

- greater objective;
- opportunity cost;
- value thesis;
- human integrity/health/relationship risk;
- success metric.

Educational gates:

- learning objective;
- progression;
- exercises;
- review;
- evaluation;
- feedback.

Academic gates:

- theme delimited;
- problem clear;
- objective answers the problem;
- methodology compatible;
- citations have references;
- references are cited;
- unsupported claims detected;
- plagiarism risk reviewed;
- institution manual followed;
- conclusion answers objective.

## Review And Repair

Reviewer must search for:

- contradictions;
- gaps;
- loose scope;
- unsupported claims;
- undeclared risk;
- unjustified decisions;
- deliverables without done criteria.

Repair Loop must attempt correction when a gate fails. Example:

```text
Gate failed: objective is generic.
Repair: refine objective into a measurable, scoped objective.
```

## AI Session Trace

Every AI session inside an Obra records:

- request;
- Obra and node;
- context used;
- model/provider used;
- sources used;
- answer generated;
- gates applied;
- corrections made;
- human decision;
- output created.

This is what separates Obras from a chat.

## Shared Workspace Rule

For long work, programming work or multi-provider work, AI sessions inside an
Obra must coordinate through Obras Shared Workspace.

The workspace owns:

- canonical context;
- provider-specific context packs;
- work packets;
- artifact bus;
- scope/collision map;
- integration queue;
- evidence normalization.

Providers may specialize, but they must not pass authority through informal
chat. Gemini may scout, Claude may plan or review, Codex may implement and a
local agent may validate, but each output must return as a workspace artifact.

## Resumo

AI harness, governance, quality gates, review flows and policy rules for Obras.

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
