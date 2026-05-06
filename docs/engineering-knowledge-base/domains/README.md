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
  - Programming, finance, personal_development, self_improvement, strategic_decision, marketing, research, writing, learning, qa, security, operations, background, general e health sao implemented/ready atualmente.
maintenance:
  - Atualize este indice quando um dominio mudar de scaffold para implemented/ready ou quando uma spec nova for promovida.
  - Nao crie spec ready para scaffold sem atualizar canonical index, master architecture e onboarding status.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/atlas-ai-master-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-core-vs-domain.md
  - docs/engineering-knowledge-base/domains/programming-specialist-profiles.md
  - docs/engineering-knowledge-base/domains/programming-frontend-superpower.md
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
| `programming` | `programming.md` + `programming-specialist-profiles.md` + `programming-frontend-superpower.md` | implemented/ready | Dev, repair, review, refactor, QA, security, database, visual, forge e specialist profiles internos. |
| `finance` | `finance.md` | implemented/ready | Analysis/review-only; sem ordens, broker execution, rebalanceamento ou transferencia. |
| `personal_development` | `personal-development.md` | implemented/ready | Privado, non-clinical, plan-only e sem mutacao automatica de calendario/tarefas. |
| `self_improvement` | `self-improvement.md` | implemented/ready | Auditoria, docs drift, capability gaps, benchmark review, memory quality, provider performance e proposals. |
| `strategic_decision` | `strategic-decision.md` | implemented/ready | Co-estrategia review-only com cool-down, valores, contraargumento, Decision Receipt dry-run, Rivals Strategy e revisit tracking. |
| `marketing` | `atlas-ai-master-architecture.md` | implemented/ready | Draft-and-review para estratégia, campanha, criativos, copy, experimentos e analytics; nunca publica nem gasta mídia sem aprovação explícita. |
| `research` | `atlas-ai-content-intelligence-curation.md` | implemented/ready | Pesquisa source-grounded com citações, incerteza, contradiction check e promoção de memória apenas por proposta revisável. |
| `writing` | `writing.md` | implemented/ready | Rascunho, edicao, voice review e publish review com pacote auditavel, sem auto-publicacao. |
| `learning` | `learning.md` | implemented/ready | Aprendizado humano, pratica deliberada, review e spaced review; nao altera Learning Plane do Core. |
| `qa` | `qa.md` | implemented/ready | Revisao transversal, acceptance review, evidence audit e release readiness; nao executa testes nem sobrepoe gates. |
| `security` | `security.md` | implemented/ready | Revisao defensiva, privacidade, compliance e incidente; nao executa exploit, scan, segredo ou acao operacional autonoma. |
| `operations` | `operations.md` | implemented/ready | Diagnostico operacional, runbook, incidente e readiness; nao faz deploy, restart, infra mutation ou delecao. |
| `background` | `background.md` | implemented/ready | Revisao de tarefas recorrentes, schedule, permissoes e stop conditions; nao inicia jobs nem muda schedules. |
| `general` | `general.md` | implemented/ready | Resposta simples e triagem governada; nao substitui dominios especializados nem burla Decide. |
| `health` | `health.md` | implemented/ready | Review nao clinico de bem-estar, rotina, recuperacao e seguranca; nao diagnostica nem prescreve. |

## Scaffold/Catalog-Ready

Estes dominios podem aparecer no catalogo para onboarding incremental, mas nao
devem ser expostos como implemented/ready:

Nao ha scaffold ativo no catalogo principal depois desta fase. Novos dominios
devem entrar como scaffold/catalog-ready somente com fronteira, owner e
promotion gate explicitos.

## Future Dedicated Domains

| Conceito | Estado | Regra |
|---|---|---|
| Curator dedicado | futuro | Hoje a curadoria operacional implementada vive em `self_improvement`; separar somente quando houver fronteira clara de produto/governanca. |

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
