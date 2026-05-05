---
id: atlas-ai-domain-specs-index
type: engineering_knowledge
title: Atlas AI Domain Specs Index
status: active
category: architecture
priority: 97
summary: Indice local das specs de dominio Atlas AI, separando implemented/ready, scaffold/catalog-ready e futuros dominios dedicados.
tags:
  - atlas-ai
  - domains
  - domain-specs
  - onboarding
capabilities:
  - domain_specs_index
  - domain_status_governance
decisions:
  - Domain specs nesta pasta documentam comportamento especifico de dominio; contratos kernel continuam em atlas-ai-kernel-architecture.md.
  - Apenas programming, finance, personal_development e self_improvement sao implemented/ready atualmente.
  - Marketing e demais scaffolds nao devem ser tratados como ready ate existirem runtime/orchestrator proprios e passarem onboarding.
maintenance:
  - Atualize este indice quando um dominio mudar de scaffold para implemented/ready ou quando uma spec nova for promovida.
  - Nao crie spec ready para scaffold sem atualizar canonical index, master architecture e onboarding status.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/atlas-ai-master-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-core-vs-domain.md
---

# Atlas AI Domain Specs Index

Esta pasta contem specs canonicas de dominio. Ela nao substitui o Kernel nem a
Master Architecture:

- Kernel define contratos executaveis, envelopes, receipts, ledgers, SDKs,
  failure domains e SLOs.
- Master Architecture define planes, roadmap, onboarding e estrategia de
  produto.
- Domain Specs definem semantica, flows, safety, sources, gates e status de
  dominios especificos.

## Implemented/Ready

| Domain | Spec | Status | Observacao |
|---|---|---|---|
| `programming` | `programming.md` | implemented/ready | Dev, repair, review, refactor, QA, security, database, visual e forge. |
| `finance` | `finance.md` | implemented/ready | Analysis/review-only; sem ordens, broker execution, rebalanceamento ou transferencia. |
| `personal_development` | `personal-development.md` | implemented/ready | Privado, non-clinical, plan-only e sem mutacao automatica de calendario/tarefas. |
| `self_improvement` | `self-improvement.md` | implemented/ready | Auditoria, docs drift, capability gaps, benchmark review, memory quality, provider performance e proposals. |

## Scaffold/Catalog-Ready

Estes dominios podem aparecer no catalogo para onboarding incremental, mas nao
devem ser expostos como implemented/ready:

| Domain | Status | Regra |
|---|---|---|
| `marketing` | scaffold/catalog-ready | Possui catalogo alvo com 15 flows; precisa runtime/orchestrator proprio antes de virar ready. |
| `research` | scaffold/catalog-ready | Manter como catalogo/planejamento ate existir runtime proprio. |
| `health` | scaffold/catalog-ready | Safety-critical; exige contrato separado, privacy e review humano antes de ready. |
| `learning` | scaffold/catalog-ready | Nao confundir com learning loop interno do Core. |
| `writing` | scaffold/catalog-ready | Nao promover sem policy de output, memory e surface. |
| `qa` | scaffold/catalog-ready | Hoje QA operacional de codigo vive em `programming.qa`. |
| `security` | scaffold/catalog-ready | Hoje security operacional de codigo vive em `programming.security`; dominio dedicado exige contrato proprio. |
| `operations` | scaffold/catalog-ready | Usar apenas como routing/catalogo ate runtime proprio. |
| `background` | scaffold/catalog-ready | Nao expor como autonomia pronta sem gates e scheduler contract. |
| `general` | scaffold/catalog-ready | Evitar virar fallback sem policy; usar catalogo apenas como entrada controlada. |

## Future Dedicated Domains

| Conceito | Estado | Regra |
|---|---|---|
| Curator dedicado | futuro | Hoje a curadoria operacional implementada vive em `self_improvement`; separar somente quando houver fronteira clara de produto/governanca. |
| Marketing ready | futuro | Promover apenas quando tiver orchestrator, runtime, gates, safety, surfaces e onboarding `ready 9/9`. |

## Promotion Gate

Um scaffold so vira implemented/ready quando:

- tem domain spec nesta pasta;
- tem orchestrator e runtime proprios ou adaptador explicitamente aprovado;
- aparece corretamente em `AtlasDomainProfileRegistry` e/ou migrations de
  profile;
- passa `AtlasDomainOnboardingScorecard`;
- declara sources, memory policy, gates, surfaces, autonomy e forbidden actions;
- atualiza `atlas-ai-canonical-architecture-index.md`, `START_HERE.md` e
  `README.md`;
- nao contradiz Kernel, Master Architecture ou Human Knowledge Surface policy.
