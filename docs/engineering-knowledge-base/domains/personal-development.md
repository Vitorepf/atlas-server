---
id: atlas-ai-personal-development-domain
type: engineering_knowledge
title: Atlas AI Personal Development Domain
status: active
category: architecture
priority: 96
summary: Spec canonica do dominio implemented/ready Personal Development para reflexao, rotina, foco, energia, aprendizado, objetivos, recuperacao e forge plan-only.
tags:
  - atlas-ai
  - domains
  - personal-development
  - privacy
  - non-clinical
capabilities:
  - personal_development_domain
  - private_reflection
  - non_clinical_safety
  - plan_only_runtime
decisions:
  - Personal Development e dominio implemented/ready, privado por default e explicitamente non-clinical.
  - O runtime retorna planos e artefatos estruturados, mas nao muta calendario, tarefas, habit trackers ou sistemas externos automaticamente.
  - Conteudo sensivel exige privacy/redaction e review humano quando necessario.
maintenance:
  - Atualize este documento quando flows, gates, runtime, memory policy ou safety policy de Personal Development mudarem.
  - Leia junto de atlas-ai-master-architecture.md e atlas-ai-kernel-architecture.md antes de alterar runtime Personal Development.
related_paths:
  - app/Services/Ai/PersonalDevelopment/AtlasPersonalDevelopmentOrchestrator.php
  - app/Services/Ai/PersonalDevelopment/PersonalDevelopmentRuntime.php
  - app/Services/Ai/PersonalDevelopment/PersonalDevelopmentSafetyPolicy.php
  - app/Services/Ai/PersonalDevelopment/PersonalDevelopmentMemoryPolicy.php
  - docs/engineering-knowledge-base/atlas-ai-master-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-personal-development-domain

graph_title: Atlas AI Personal Development Domain

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Personal Development Domain
canonical_name: Atlas AI Personal Development Domain
technical_name: atlas-ai-personal-development-domain
cartography_type: module
canonical_source: docs/engineering-knowledge-base/domains/personal-development.md

owner: domains

repo_paths:
  - docs/engineering-knowledge-base/domains/personal-development.md

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
  - domains

evidence:
  - docs/engineering-knowledge-base/domains/personal-development.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - module
  - domains

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
# Personal Development Domain

Personal Development is an isolated Atlas AI domain for habits, routine, focus, energy, learning, personal performance, and life review. It is private by default and explicitly non-clinical: the domain may help organize reflection, evidence, plans, routine experiments, and review checkpoints, but it must not diagnose psychological conditions, prescribe medical treatment, or present itself as a therapeutic workflow.

## Scope

Supported flows:

- `personal_development.reflect`
- `personal_development.daily_review`
- `personal_development.weekly_review`
- `personal_development.habit_design`
- `personal_development.focus_plan`
- `personal_development.learning_plan`
- `personal_development.energy_review`
- `personal_development.goal_decomposition`
- `personal_development.recovery_plan`
- `personal_development.forge`

The runtime returns structured plans and artifacts only. It does not mutate calendars, tasks, habit trackers, external systems, or user records automatically.

## Safety Contract

- Use operational language: plan, reflection, evidence, routine, experiment, checkpoint.
- Keep all memory private by default.
- Mark provider context as provider-safe only after redaction.
- Route sensitive recommendations to human review.
- Require approval for `personal_development.forge`.
- Do not diagnose psychological states.
- Do not provide medical treatment guidance.
- Do not make automatic schedule or task changes.

## Architecture

Implementation lives under `app/Services/Ai/PersonalDevelopment/`:

- `PersonalDevelopmentFlowCatalog` is the isolated source of truth for flow IDs, cadence, risk level, focus areas, and primary artifact IDs.
- `PersonalDevelopmentInputNormalizer` accepts only operational context keys for artifacts and records ignored keys without retaining raw input.
- `AtlasPersonalDevelopmentOrchestrator` normalizes and validates flows, resolves the domain/flow profile, and produces a plan contract.
- `PersonalDevelopmentRuntime` creates the structured plan and artifacts, checks sensitive markers, and reports side-effect flags.
- `PersonalDevelopmentSafetyPolicy` owns non-clinical safety rules, sensitive marker review, blocked actions, and side-effect invariants.
- `PersonalDevelopmentMemoryPolicy` classifies memory as private and permits provider-safe payloads only when redacted content is available.

The database contract is seeded by `database/migrations/2026_05_05_080000_expand_personal_development_domain_contract.php`. This keeps the domain contract out of shared config and registry files while preparing the profile for the main domain catalog integration.

## Integration Status

Personal Development is now centrally registered as a first-class Atlas AI domain.

- `config/atlas_ai.php` maps `AtlasPersonalDevelopmentOrchestrator` to `App\Services\Ai\PersonalDevelopment\AtlasPersonalDevelopmentOrchestrator`.
- `AtlasDomainProfileRegistry` exposes all 10 Personal Development flows in static fallback mode.
- `database/migrations/2026_05_05_080000_expand_personal_development_domain_contract.php` seeds the active database profiles.
- `atlas:ai:domains --json` reports Personal Development as `ready 9/9` with 10 flows.
- `atlas:ai:architecture-validate --json` includes Personal Development in the ready domain count.

Validation:

- `php artisan test tests/Unit/Ai/PersonalDevelopment tests/Feature/Ai/PersonalDevelopment`
- `php artisan test tests/Feature/Architecture/DomainProfileComplianceTest.php tests/Feature/Ai/AtlasAiDomainsCommandTest.php`
- `php artisan atlas:ai:domains --json`
- `php artisan atlas:ai:architecture-validate --json`

## Resumo

Spec canonica do dominio implemented/ready Personal Development para reflexao, rotina, foco, energia, aprendizado, objetivos, recuperacao e forge plan-only.

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
