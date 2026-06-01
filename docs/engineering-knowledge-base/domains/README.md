---
id: atlas-ai-domain-specs-index
type: engineering_knowledge
title: Atlas AI Domain Specs Index
status: source_material
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
  - docs/engineering-knowledge-base/atlas-autonomous-intelligence-operating-system.md
  - docs/engineering-knowledge-base/atlas-domain-company-runtimes.md
  - docs/engineering-knowledge-base/atlas-ai-multi-domain-implementation-sequence.md
  - docs/engineering-knowledge-base/domains/domain-routing-governance.md
  - docs/engineering-knowledge-base/atlas-ai-master-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-core-vs-domain.md
  - docs/engineering-knowledge-base/domains/programming-specialist-profiles.md
  - docs/engineering-knowledge-base/domains/programming-professional-rag-operating-standard.md
  - docs/engineering-knowledge-base/domains/programming-enterprise-implementation-plan.md
  - docs/engineering-knowledge-base/domains/programming-frontend-superpower.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-domain-specs-index

graph_title: Atlas AI Domain Specs Index

graph_world: atlas

graph_layer: system

graph_kind: index

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Domain Specs Index
canonical_name: Atlas AI Domain Specs Index
technical_name: atlas-ai-domain-specs-index
cartography_type: index
canonical_source: docs/engineering-knowledge-base/domains/README.md

owner: domains

repo_paths:
  - docs/engineering-knowledge-base/domains/README.md

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
  - docs/engineering-knowledge-base/domains/README.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - index
  - domains

ai_entrypoints:
  - Leia Resumo, Domain Routing Governance, Status Dos Dominios, Promotion Gate e Regras Para IA antes de implementar ou criar dominio.

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
# Atlas AI Domain Specs Index

Esta pasta contem specs canonicas de dominio. Ela nao substitui o Kernel nem a
Master Architecture:

- Kernel define contratos executaveis, envelopes, receipts, ledgers, SDKs,
  failure domains e SLOs.
- Autonomous Intelligence OS define Atlas AI como sistema operacional
  multi-dominio.
- Domain Company Runtimes define quando uma spec de dominio deve evoluir para
  empresa digital com departamentos, workflows, gates, artifacts e certification.
- Multi-Domain Implementation Sequence (`atlas-ai-multi-domain-implementation-sequence.md`)
  define em que ORDEM cada dominio entra, o que paraleliza, quais arquivos cada
  meta nao deve tocar e como evitar colisao entre Claudes/Codex em paralelo.
- Master Architecture define planes, roadmap, onboarding e estrategia de
  produto.
- Domain Specs definem semantica, flows, safety, sources, gates e status de
  dominios especificos.

## Domain Routing Governance

Antes de criar, alterar ou rotear dominio, leia
`domain-routing-governance.md`. Ele define:

- matriz prompt -> dominio;
- quando usar dominio existente;
- quando criar flow/profile/capability;
- quando dominio novo e permitido;
- Domain Creation Gate;
- regras anti-confusao para IAs.

Resumo operacional:

```text
Prompt -> intent -> dominio existente -> flow/profile/capability
       -> Domain Creation Gate somente se nada existente couber
```

Nao crie dominio para ferramenta, provider, linguagem, surface, agent/persona ou
departamento interno. Esses conceitos pertencem a Tool Runtime, Model Selection,
Surface, Specialist Profile ou Department dentro de um dominio.

## Status Dos Dominios

`implemented/ready` nesta pasta significa que existe spec/capacidade governada
para o dominio atual. Isso **nao** significa automaticamente que o dominio ja foi
promovido ao novo padrao completo de **Domain Company Runtime** com manifest,
departments, capabilities, policy, evidence, certification e control-plane.

Use esta leitura:

| Status | Significado |
|---|---|
| `implemented/ready` | dominio atual e reconhecido e governado |
| `company-runtime-target` | deve evoluir para empresa digital plugavel |
| `review-only` | pode analisar/planejar/revisar, mas nao executar acao externa |
| `execution-gated` | execucao depende de Policy/Evidence/Approval |

## Implemented/Ready

| Domain | Spec | Status | Observacao |
|---|---|---|---|
| `programming` | `programming.md` + `programming-professional-rag-operating-standard.md` + `programming-agentic-rag-professional-spec.md` + `programming-enterprise-implementation-plan.md` + `programming-specialist-profiles.md` + `programming-frontend-superpower.md` | implemented/ready | Dev, repair, review, refactor, QA, security, database, visual, forge, RAG/Agentic RAG profissional e specialist profiles internos. |
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

## Company Runtime Upgrade Targets

| Target | Base atual | Upgrade esperado |
|---|---|---|
| Software Company | `programming.md` + adapters | ja e o primeiro runtime pesado; manter Dev/Forge separados e auditaveis. |
| Research Company | `atlas-ai-content-intelligence-curation.md` + runtime novo | source plan, source quality, citations, contradiction, claims, synthesis. |
| Strategy / Venture Studio | `strategic-decision.md` + runtime novo | oportunidade, mercado, venture blueprint, unit economics, GTM, experimentos. |
| Finance / Investment | `finance.md` | research, valuation, portfolio, risk, compliance, reporting; live trade bloqueado. |
| Marketing / Growth | `atlas-ai-master-architecture.md` | ICP, campanha, copy, funil, analytics, experimentos, approval de budget/publicacao. |
| Cyber Security | `security.md` + cyber docs | AppSec, GRC, remediation, defensive; ofensivo apenas autorizado com RoE. |
| Personal Development / Learning | `personal-development.md` + `learning.md` | metas, habitos, estudo, pratica deliberada, professor nao clinico. |
| Automation / Tool Factory | `atlas-tool-economy.md` | automacao segura, tool selection, tool builder/evolution, receipts. |

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

## Regras Para IA

- Leia `domain-routing-governance.md` antes de decidir dominio.
- Se o pedido couber em dominio existente, crie flow/profile/capability, nao
  dominio novo.
- Se o pedido precisar de ferramenta, use Tool Runtime; ferramenta nao e dominio.
- Se o pedido precisar de provider/modelo, use Model Selection; provider nao e
  dominio.
- Se o pedido for tela/app/CLI, use Surface; surface nao e dominio.
- Se o pedido for especialidade interna, use specialist profile ou department.
- Registre primary_domain, secondary_domains, selected_flow, reason, risk e
  next_action.
- Em caso de duvida, use `general` apenas para triagem e handoff, nao para fazer
  trabalho especializado.

## Resumo

Indice local das specs de dominio Atlas AI, separando implemented/ready, scaffold/catalog-ready e futuros dominios dedicados.

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
