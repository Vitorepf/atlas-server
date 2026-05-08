---
id: atlas-ai-session-bootstrap
type: engineering_knowledge
title: Atlas AI Session Bootstrap
status: active
category: onboarding
priority: 100
summary: Pacote curto para uma nova sessao humana ou IA entender o que e Atlas, o que existe, o que falta, onde verificar e como evoluir sem duplicar fluxos.
tags:
  - atlas-ai
  - bootstrap
  - onboarding
  - documentation-governance
capabilities:
  - session_bootstrap
  - knowledge_governance_system
  - anti_hallucination_context
  - implementation_status_navigation
  - evolution_navigation
  - runtime_language_boundaries
  - local_ai_performance_strategy
  - content_intelligence_curation
  - qualitative_levels_roadmap
decisions:
  - Toda sessao nova deve conseguir responder o que e Atlas, o que existe, o que falta e como evoluir lendo este bootstrap e os docs apontados.
  - Nenhuma IA deve declarar que algo nao existe sem verificar docs canonicos, indice de codigo e busca local.
  - Toda sessao nova deve tratar repo docs canonicos como fonte autoral, Postgres/Code Intelligence como read model, Obsidian como Human Knowledge Surface e AGENTS/CLAUDE como provider projection.
  - Este documento e intencionalmente curto; detalhes vivem nos docs donos de cada assunto.
maintenance:
  - Manter abaixo de 180 linhas.
  - Atualizar quando mudar o status macro de dominios, kernel, surfaces, memory, runtime ou roadmap.
  - Rodar atlas engineering knowledge sync --prune depois de alterar.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md
  - docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md
  - docs/engineering-knowledge-base/atlas-native-mac-agent.md
  - docs/engineering-knowledge-base/atlas-ai-local-performance-memory-strategy.md
  - docs/engineering-knowledge-base/atlas-ai-content-intelligence-curation.md
  - docs/engineering-knowledge-base/atlas-ai-qualitative-levels-roadmap.md
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/START_HERE.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-master-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-evolution-roadmap.md
  - docs/engineering-knowledge-base/domains/README.md
---

# Atlas AI Session Bootstrap

Este e o primeiro arquivo para uma sessao nova entender o Atlas sem depender de historico de chat.

## Atlas Em Uma Frase

Atlas AI e a inteligencia unica que orquestra surfaces, dominios, memoria,
ferramentas, modelos, evidencias, qualidade, aprendizado e evolucao continua.

`atlas dev`, `atlas forge`, `atlas ask`, `atlas decide`, app, API, mobile,
Obsidian e agentes locais sao portas de entrada ou surfaces. A logica real deve
passar pelo kernel e pelos dominios, nao ficar presa em uma surface.

## Perguntas Que Esta Sessao Deve Responder

| Pergunta | Onde responder |
|---|---|
| O que e Atlas? | este doc + `atlas-ai-master-architecture.md` |
| O que ja existe? | `atlas-ai-canonical-architecture-index.md` + indice de codigo |
| O que falta evoluir? | `atlas-ai-evolution-roadmap.md` + backlog governado |
| Como evoluir sem bagunca? | `atlas-ai-documentation-operating-system.md` + Kernel |
| Qual camada e fonte de verdade? | `atlas-ai-knowledge-governance-system.md` |
| Qual doc manda em caso de conflito? | `atlas-ai-canonical-architecture-index.md` |
| Uma ideia e core, domain ou surface? | `atlas-ai-core-vs-domain.md` |
| Python ou Go fazem sentido? | `atlas-ai-runtime-language-boundaries.md` |
| Swift/macOS faz sentido? | `atlas-native-mac-agent.md` |
| Como usar 48GB RAM para IA? | `atlas-ai-local-performance-memory-strategy.md` |
| Como curar conteudo/YouTube/fontes? | `atlas-ai-content-intelligence-curation.md` |
| Atlas mudou de patamar? | `atlas-ai-qualitative-levels-roadmap.md` |
| Uma feature ja existe no codigo? | `rg`, Code Intelligence e testes |

## Status Macro Atual

| Area | Status operacional |
|---|---|
| Kernel Architecture | Fundacao ativa: OperationEnvelope, DecisionReceipt, Evidence Ledger, FailureDomain, SLO, contracts de domain/surface/provider |
| Evidence Ledger | Ativo com eventos append-only, replay, projections, health, scheduler, MCP e action assistida |
| Atlas Decide | Base ativa para decidir dominio, fluxo, modelo, budget, gates e receipts |
| Programming | Dominio implemented/ready para dev, forge, fix, review, QA e refactor |
| Finance | Dominio implemented/ready inicial, ainda precisa runtime profundo de mercado |
| Personal Development | Dominio implemented/ready inicial, ainda precisa harness proprio e metricas reais |
| Self-Improvement | Dominio implemented/ready inicial com runtime, scheduler, findings e proposal inbox |
| Marketing | Scaffold/catalog-ready; nao tratar como mestre implementado ate ter runtime, gates e benchmarks |
| Super Tool Runtime | Infra madura: tools, recipes, policy tiers, authority, evidence e gates |
| Language Runtimes | Laravel e o Kernel; Python AI/Data, Go Edge e Swift Native Mac sao runtimes especializados futuros |
| Local AI Performance | Contrato ativo para hot context, RAG local, rerank, cache, modelos locais e precompute com 48GB RAM |
| Content Intelligence | Contrato ativo para curadoria, source quality, YouTube global, descarte e routing de conhecimento |
| Qualitative Levels | P1 atual com pecas de P3/P5; P4+ exige evidence, Rivals e agency gates |
| Memory/Open Brain | Infra ativa: context packs, MCP, code intelligence, auto injection e quality scoring |
| AtlasVault/Obsidian | Human Knowledge Surface poderosa, nao fonte operacional primaria crua |
| Mobile/Local Agent/Skills | Contratos canonicos existem; evolucao deve seguir surface adapters e kernel |

## O Que Nao Pode Acontecer

1. Criar capability dentro de `ask`, `dev`, `forge` ou app sem registrar no Core.
2. Criar repair loop novo sem passar por policy, receipt, evidence e limites.
3. Criar domain "mestre" sem manifest, orchestrator, gates, evidence, tests e maturity.
4. Declarar feature inexistente sem buscar no codigo e nos docs.
5. Declarar feature pronta quando ela e scaffold/catalog-ready.
6. Expandir doc gigante quando o correto e dividir em spec menor.
7. Criar Python/Go/Swift como cerebro paralelo fora do Laravel Kernel.
8. Implementar co-estrategista, ambiente ou self-mutation sem Rivals e agency gate.
9. Usar tela, microfone, Keychain, Touch ID ou Accessibility sem opt-in, policy, receipt e evidence.
10. Promover conteudo bruto, YouTube, RSS, scraping ou nota humana para memoria sem curadoria, privacy, source refs e review.

## Regra De Verificacao Antes De Opinar

Antes de dizer "nao tem" ou "ja tem", execute pelo menos:

```bash
php artisan atlas:ai:session-bootstrap --task="<task>" --json
php artisan atlas:ai:place-feature "<feature>" --json
rg "termo|classe|comando|capability" app config database routes docs tests
atlas engineering knowledge status
atlas engineering knowledge code-status
```

Quando a pergunta envolver implementacao real, confirme com:

```bash
atlas ai architecture-validate --json
atlas engineering knowledge index-code --prune
```
Use testes focados quando alterar codigo.
`place-feature` devolve `gate_status`, `implementation_contract`,
`blocked_when`, escopos permitidos/proibidos e proximas acoes canonicas.
`session-bootstrap` propaga `session_gate`, `docs_split_plan` (AP-173 Session Bootstrap Docs Split Plan Contract), `architecture_operations`, `ap_agent_workflow_registry` e um resumo `architecture_readiness` derivado de AP-176/AP-177 (AP-174 Session Bootstrap Architecture Operations Contract; AP-204 AP Agent Workflow Registry; `ap173_session_bootstrap_docs_split_plan_contract`; `ap174_session_bootstrap_architecture_operations_contract`).
Se a tarefa mencionar AP, arquitetura ou governanca, `read_first` deve incluir `docs/ap/AP-204-ap-agent-workflow-registry.md` antes de qualquer edicao.
CLI/API/MCP devem suportar strict gate para `gate_status=blocked`.

## Como Evoluir O Atlas

Todo trabalho novo deve seguir este caminho:

1. Definir se e Core, Domain, Surface, Runtime, Evidence, Learning ou Docs.
2. Encontrar o documento dono no `atlas-ai-canonical-architecture-index.md`.
3. Verificar se ja existe implementacao com `rg` e Code Intelligence.
4. Escrever ou atualizar spec curta com status, ownership e DoD.
5. Implementar codigo, migrations, config, tests e architectural scanner quando aplicavel.
6. Registrar eventos ou evidence quando a mudanca afeta runtime.
7. Rodar sync/index para a proxima sessao nao perder o que foi feito.

## Leitura Minima Para Uma Sessao Nova

1. `atlas-ai-session-bootstrap.md`
2. `atlas-ai-canonical-architecture-index.md`
3. `atlas-ai-documentation-operating-system.md`
4. `START_HERE.md`
5. O doc dono do assunto especifico

## Definicao De Pronto Documental

Uma mudanca importante so esta pronta quando:

1. O codigo existe ou a doc marca claramente que e scaffold/future.
2. O doc canonico aponta para o codigo, teste, comando ou migration.
3. O doc tem limite de tamanho adequado ou foi dividido.
4. `sync --prune` e `index-code --prune` foram executados quando necessario.
5. Uma sessao nova consegue descobrir a mudanca sem ler conversa antiga.

## Lei De Governanca

Antes de entregar alteracao estrutural, rode:

```bash
atlas engineering knowledge docs-health
php artisan atlas:ai:docs-split-plan --owner=<owner_area> --json
atlas ai architecture-validate --json
```

Se a governanca documental falhar, a arquitetura nao esta pronta.
