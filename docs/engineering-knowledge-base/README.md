---
id: engineering-knowledge-base-overview
type: engineering_knowledge
title: Atlas Engineering Knowledge Base
status: active
category: architecture
priority: 100
summary: Fonte canonica versionada para conhecimento de engenharia do Atlas, sincronizada para Postgres e usada pelo Harness Runner em context packs.
tags:
  - atlas
  - engineering
  - knowledge
capabilities:
  - session_bootstrap
  - documentation_operating_system
  - runtime_language_boundaries
  - qualitative_levels_roadmap
  - canonical_architecture_index
  - atlas_ai_master_architecture
  - atlas_ai_vision
  - unified_pipeline
  - core_domain_boundary
  - resolver_corpus_governance
  - documentation_archive_governance
  - atlas_ai_operating_system
  - canonical_onboarding
  - canonical_docs
  - postgres_registry
  - context_pack_recall
  - code_intelligence_index
  - memory_core_preservation
  - memory_core_runbook
  - memory_core_contracts
  - memory_core_security
  - memory_core_maturity
  - open_brain_context_injection
  - obsidian_atlas_vault
  - engineering_blueprint
  - project_blueprint_pipeline
  - task_contracts
  - qa_evidence
  - review_gates
  - postgres_gate
  - programming_power_tools_catalog
  - fair_claude_benchmark
  - atlas_supercharged_routing
  - programming_domain
  - domain_specs_index
  - self_improvement_domain
  - finance_domain
  - personal_development_domain
  - atlas_ai_evolution_phase_0_audit
decisions:
  - Atlas AI Session Bootstrap e o primeiro pacote curto para novas sessoes responderem o que e Atlas, o que existe, o que falta e como evoluir.
  - Atlas AI Documentation Operating System define limites de tamanho, ownership, anti-hallucination, promocao e sync/index para documentacao de alta performance.
  - Atlas AI Runtime Language Boundaries separa Laravel Kernel, Python AI/Data Runtime e Go Edge/Concurrency Runtime.
  - Atlas AI Qualitative Levels Roadmap formaliza os patamares P1-P7 e a fila governada para co-estrategista, Rivals Strategy e Curator evolutivo.
  - Atlas AI Canonical Architecture Index define a hierarquia oficial entre Constitution, Kernel, Master Architecture, Topology e Domain Specs.
  - Atlas AI Master Architecture e a raiz enterprise para autoridade operacional, contratos canonicos, planes, dominios, runtimes, evidence, learning e estrategia contra Claude Code.
  - Atlas AI Vision, Pipeline e Core Vs Domain sao os tres documentos fundadores curtos da arquitetura-mae.
  - Atlas AI Resolver Corpus Audit classifica a pasta resolver-o-que-vale-a-pena e promove specs P0 para a arquitetura canonica.
  - Archive README define como ler source material preservado sem deixar docs legados competirem com a arquitetura canonica.
  - Atlas AI Operating System define a camada macro de orquestracao, dominios, pipeline comum e regras anti-duplicacao.
  - START_HERE.md e o ponto de entrada para humanos e IAs.
  - Docs versionados sao a fonte de verdade.
  - Postgres guarda indice operacional e estado consultavel.
  - O indice de codigo liga docs canonicos a implementacao real.
  - Obsidian/AtlasVault e a Human Knowledge Surface / Personal Knowledge Workspace: camada humana poderosa para escrita, revisao, pesquisa e navegacao, nunca fonte primaria automatica.
  - Obsidian/AtlasVault tem contrato proprio para import/export bidirecional seguro.
  - Engineering Blueprint define o contrato de produto e qualidade antes de execucao pelo Harness Runner.
  - Open Brain Context Injection define quando CLI/app devem usar memoria automaticamente em tarefas de codigo.
  - Programming Power Tools Catalog define a bancada operacional de ferramentas, tiers, autoridade e lacunas para programacao pesada.
  - Fair Claude e Atlas Supercharged separam prova cientifica com o mesmo Claude do produto real multi-provider.
maintenance:
  - Leia atlas-ai-session-bootstrap.md no inicio de qualquer sessao nova.
  - Leia atlas-ai-documentation-operating-system.md antes de criar, dividir, promover, arquivar ou expandir docs canonicos.
  - Leia atlas-ai-runtime-language-boundaries.md antes de propor Python, Go, microservico, worker externo, daemon ou runtime multi-linguagem.
  - Leia atlas-ai-qualitative-levels-roadmap.md antes de propor co-estrategista, patamar cognitivo, ambiente, Curator auto-mutavel ou memoria longitudinal.
  - Leia atlas-ai-canonical-architecture-index.md antes de escolher qual documento arquitetural tem autoridade.
  - Leia atlas-ai-master-architecture.md antes de alterar autoridade macro, Policy/Profile, Decide, Domain Orchestrator, Runtime, Evidence, Learning ou estrategia contra Claude Code.
  - Leia atlas-ai-vision.md, atlas-ai-pipeline.md e atlas-ai-core-vs-domain.md antes de reorganizar fluxos macro.
  - Leia atlas-ai-resolver-corpus-audit.md antes de alterar Atlas Decide, Policy/Profile, Programming Orchestrator, Forge ou Super Tool Runtime.
  - Leia archive/README.md antes de mover, arquivar ou apagar source material preservado.
  - Leia atlas-ai-operating-system.md antes de criar comando, fluxo, harness, dominio ou capability horizontal nova.
  - Leia START_HERE.md antes de continuar implementacoes de memoria/contexto.
  - Leia open-brain-context-injection.md antes de alterar atlas dev, atlas continue, atlas chat ou AtlasAiSheet.
  - Rode atlas engineering knowledge sync --prune depois de alterar estes docs.
  - Rode atlas engineering knowledge index-code --prune depois de alterar docs ou codigo core.
  - Consulte memory-core-runbook.md antes de executar operacoes destrutivas ou provider projection apply.
  - Consulte engineering-blueprint-runbook.md antes de alterar planejamento, task contracts, QA, review ou Postgres gate.
  - Consulte programming-power-tools-catalog.md antes de adicionar ferramenta, recipe, normalizer ou gate novo.
  - Consulte atlas-cli-5x-claude-code-plan.md e atlas-cli-fair-claude-benchmark.md antes de declarar superioridade contra Claude Code.
  - Revise indexed_at e content_hash antes de confiar em uma sessao longa.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-session-bootstrap.md
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md
  - docs/engineering-knowledge-base/atlas-ai-qualitative-levels-roadmap.md
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/atlas-ai-master-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/engineering-knowledge-base/kernel/failure-domain-taxonomy.md
  - docs/engineering-knowledge-base/atlas-ai-vision.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
  - docs/engineering-knowledge-base/atlas-ai-core-vs-domain.md
  - docs/engineering-knowledge-base/atlas-ai-resolver-corpus-audit.md
  - docs/engineering-knowledge-base/archive/README.md
  - docs/engineering-knowledge-base/atlas-ai-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-architecture-audit.md
  - app/Services/Engineering/EngineeringKnowledgeBaseService.php
  - app/Services/Engineering/EngineeringContextPackService.php
  - app/Services/Engineering/EngineeringCodeIntelligenceService.php
  - database/migrations/2026_05_02_009000_create_atlas_engineering_knowledge_items_table.php
  - database/migrations/2026_05_02_010000_create_atlas_engineering_code_intelligence_tables.php
  - docs/engineering-knowledge-base/START_HERE.md
  - docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md
  - docs/engineering-knowledge-base/memory-core-runbook.md
  - docs/engineering-knowledge-base/memory-core-contracts.md
  - docs/engineering-knowledge-base/memory-core-security-privacy.md
  - docs/engineering-knowledge-base/memory-core-failure-modes.md
  - docs/engineering-knowledge-base/memory-core-maturity-dod.md
  - docs/engineering-knowledge-base/open-brain-context-injection.md
  - docs/engineering-knowledge-base/obsidian-atlas-vault.md
  - docs/engineering-knowledge-base/engineering-blueprint.md
  - docs/engineering-knowledge-base/engineering-blueprint-contracts.md
  - docs/engineering-knowledge-base/engineering-blueprint-quality-gates.md
  - docs/engineering-knowledge-base/engineering-blueprint-runbook.md
  - docs/engineering-knowledge-base/engineering-blueprint-maturity-dod.md
  - docs/engineering-knowledge-base/programming-power-tools-catalog.md
  - docs/atlas-cli-5x-claude-code-plan.md
  - docs/atlas-cli-fair-claude-benchmark.md
---

# Atlas Engineering Knowledge Base

Esta pasta e a fonte canonica para o conhecimento de engenharia do Atlas.

Ela existe para evitar que decisoes importantes do Harness, Atlas-Bench,
runtime visual, quality scan, modelo de memoria e processos de manutencao
fiquem presos em uma conversa, em uma nota solta ou apenas na memoria de um
provider.

## Decisao

O Atlas usa uma arquitetura de conhecimento em camadas:

1. Docs canonicos versionados neste repositorio.
2. Registry operacional em Postgres.
3. Context packs do Atlas e do Harness consumindo esse registry.
4. Human Knowledge Surface / Personal Knowledge Workspace: Obsidian/AtlasVault e outras notas humanas como escrita, revisao, pesquisa, navegacao e espelho gerenciado.

O repositorio e a fonte de verdade porque muda junto com o codigo, passa por
review, entra no diff e acompanha migrations/testes. O Postgres e o indice vivo
porque pode ser consultado pelo app, API, CLI, memory layer e context packs.

O Atlas tambem mantem um Code Intelligence Index em Postgres. Essa camada le o
codigo real e registra modulos, simbolos, rotas, comandos, migrations, testes e
links docs->codigo. Assim, a IA recebe conceito e implementacao no mesmo context
pack.

Obsidian/AtlasVault e a Human Knowledge Surface / Personal Knowledge Workspace:
um workspace humano de leitura, escrita, curadoria, pesquisa, identidade,
navegacao e espelho gerenciado. O contrato canonico esta em
`obsidian-atlas-vault.md`. Ele permite import/export seguro, managed notes e
revisao humana rica, mas nao transforma notas soltas em fonte operacional
primaria.

## Regra De Ouro

Se uma decisao orienta manutencao, revisao, release, arquitetura ou evolucao do
Atlas, ela precisa existir aqui ou em um ADR desta pasta.

## Como Sincronizar

```bash
atlas engineering knowledge sync --prune
atlas engineering knowledge index-code --prune
atlas engineering knowledge docs-health
```

Para inspecionar:

```bash
atlas engineering knowledge docs-health
atlas engineering knowledge status
atlas engineering knowledge list
atlas engineering knowledge show engineering-knowledge-base-overview
atlas engineering knowledge code-status
atlas engineering knowledge modules
atlas engineering knowledge symbols --symbol-type=cli_command
```

No app, a superficie fica em `Home > Atlas Engineering > abrir`. O card
`Engineering knowledge` mostra sync, indexacao, knowledge items, modulos,
simbolos, filtros de Code Intelligence, painel `Code audit` sem escrita e
detalhe de modulo com docs/testes.

## Docs Canonicos Principais

| Doc | Papel |
|---|---|
| `atlas-ai-session-bootstrap.md` | Pacote curto para uma nova sessao entender o que e Atlas, o que existe, o que falta e como evoluir sem ler conversa antiga |
| `atlas-ai-documentation-operating-system.md` | Contrato de documentacao de alta performance: limites de tamanho, status, ownership, anti-hallucination, promocao e sync/index |
| `atlas-ai-runtime-language-boundaries.md` | Contrato de fronteira entre Laravel Kernel, Python AI/Data Runtime e Go Edge/Concurrency Runtime |
| `atlas-ai-qualitative-levels-roadmap.md` | Roadmap canonico curto dos patamares P1-P7, co-estrategista, Rivals Strategy e fila QL governada |
| `START_HERE.md` | Ponto de entrada para humanos e IAs |
| `atlas-ai-canonical-architecture-index.md` | Indice oficial da hierarquia entre Constitution, Kernel, Master Architecture, Topology e Domain Specs |
| `atlas-ai-layer-0-glossary.md` | Constituicao operacional enxuta e glossario canonico de Layer 0; impede que providers, prompts ou docs legados disputem a identidade do Atlas AI |
| `atlas-ai-kernel-architecture.md` | Especificacao kernel formal: OperationEnvelope, DecisionReceipt tipado, Evidence Ledger, SDKs, failure domains e SLOs |
| `kernel/failure-domain-taxonomy.md` | Taxonomia canonica de FailureDomain, FailureClassifier e FailureHandlerRegistry |
| `atlas-ai-master-architecture.md` | Especificacao enterprise da arquitetura-mae: planes, autoridade unica, contratos canonicos, dominios, runtimes, evidence, learning e estrategia para superar Claude Code |
| `atlas-ai-vision.md` | Documento fundador curto: Atlas AI como inteligencia unica do produto |
| `atlas-ai-pipeline.md` | Pipeline unico de qualquer requisicao Atlas AI |
| `atlas-ai-core-vs-domain.md` | Regra de decisao entre Core, Domain e Surface |
| `domains/README.md` | Indice local das Domain Specs e status implemented/ready vs scaffold |
| `domains/programming.md` | Spec canonica do dominio implemented/ready Programming |
| `domains/self-improvement.md` | Spec canonica do dominio implemented/ready Self-Improvement |
| `domains/finance.md` | Spec canonica do dominio implemented/ready Finance |
| `domains/personal-development.md` | Spec canonica do dominio implemented/ready Personal Development |
| `atlas-ai-resolver-corpus-audit.md` | Auditoria da pasta `resolver-o-que-vale-a-pena`: o que vira canonico, referencia, futuro ou arquivo historico |
| `legacy-documentation-cleanup-report.md` | Inventario e registro de limpeza de docs legados, duplicados, humanos, arquivados e pendentes de promocao |
| `legacy-documentation-cleanup-plan.md` | Plano seguro para futuras ondas de promocao, redirect, arquivo e delete candidate |
| `atlas-ai-operating-system.md` | Arquitetura macro do Atlas AI: dominios, pipeline comum, anti-duplicacao e ownership entre dev, forge, decide, memoria, tools e curadoria |
| `atlas-ai-continuity-session-state.md` | Contrato canonico para continuidade, compactacao, handoff de provider/surface e session snapshots |
| `atlas-ai-telemetry-evidence-performance.md` | Contrato canonico para telemetry, Evidence Ledger projections, aggregator_version, health gates, reports, custo e performance |
| `atlas-ai-mobile-surface-gateway.md` | Contrato canonico para mobile como surface, pairing, push, inbox, discussion handoff e domain catalog |
| `atlas-ai-cli-multimodal.md` | Contrato canonico para input multimodal no CLI, paste de imagem, anexos clicaveis e fallbacks por terminal |
| `atlas-ai-skill-system.md` | Contrato canonico para skills provider-neutral, lifecycle, evals, governance e traceability |
| `atlas-ai-runtime-packets.md` | Mapa canonico dos packets legados para envelopes, receipts, ledger, tool events, permissions, memory deltas e router decisions |
| `atlas-local-agent-surface.md` | Contrato canonico para Mac Agent/local automation como surface, readiness e background jobs |
| `atlas-ai-governed-backlog.md` | Contrato para preservar backlog legado sem transformar notas pessoais ou ideias cruas em runtime/roadmap automatico |
| `atlas-ai-architecture-audit.md` | Analise rigorosa de consolidacao dos docs: capacidades existentes, duplicacoes, lacunas e ordem recomendada para reorganizar o Atlas AI |
| `atlas-ai-evolution-roadmap.md` | Roadmap de evolucao do Atlas AI: Agentic RAG, Self-Reflection Gate, memoria episodica, Provider Strategy Matrix, Graph/Vector/Evidence routing e fases 0-4 |
| `atlas-ai-evolution-phase-0-audit.md` | Auditoria rigorosa da Fase 0/AP-99: provider usage/performance contract, ganhos esperados, lacunas, criterios de aceite e o que nao deve virar subsistema paralelo |
| `archive/README.md` | Regras do arquivo documental: como ler source material preservado sem deixar docs legados competirem com a arquitetura canonica |
| `atlas-ai-memory-context-core-open-brain.md` | Documento mestre versionado de memoria, contexto e recall |
| `open-brain-context-injection.md` | Como CLI e app devem usar Open Brain automaticamente em dev, continue, chat, programming, review e debug |
| `obsidian-atlas-vault.md` | Contrato para Obsidian/AtlasVault como Human Knowledge Surface / Personal Knowledge Workspace, sem virar fonte operacional primaria |
| `code-intelligence.md` | Indice de codigo, modulos, simbolos e doc links |
| `engineering-blueprint.md` | Produto final do Engineering Blueprint System |
| `engineering-blueprint-contracts.md` | Contratos de blueprint, task, inventory, scenarios, evidencias e findings |
| `engineering-blueprint-quality-gates.md` | QA, deep review, Postgres gate, thresholds e DoD de qualidade |
| `engineering-blueprint-runbook.md` | Operacao app/CLI/API para blueprint, run, QA, review e sync |
| `engineering-blueprint-maturity-dod.md` | Estado real, plano por fases e DoD final dos 7 itens |
| `super-tool-runtime-core.md` | Registry, politica, executor e evidence store genericos para ferramentas |
| `programming-power-tools-catalog.md` | Catalogo operacional de ferramentas para programacao pesada, tiers T0-T3, autoridade, lacunas e backlog |
| `memory-core-runbook.md` | Runbook diario de memoria, sync, privacy, projection e validacao |
| `memory-core-contracts.md` | Contratos de tabelas, refs, APIs, CLI e config |
| `memory-core-security-privacy.md` | Politica de privacy, redaction e provider-safety |
| `memory-core-failure-modes.md` | Diagnostico e recuperacao por camada |
| `memory-core-maturity-dod.md` | Maturity model, metricas e Definition of Done |

Docs canonicos de produto fora desta pasta, mas obrigatorios para trabalho em
Atlas CLI/Atlas Decide:

| Doc | Papel |
|---|---|
| `../atlas-cli-final-product.md` | Referencia operacional unica do Atlas CLI no Mac |
| `../atlas-cli-5x-claude-code-plan.md` | Plano ultra robusto para superar Claude Code com Fair Claude e Atlas Supercharged |
| `../atlas-cli-fair-claude-benchmark.md` | Protocolo pareado para comparar Atlas Fair Claude contra Claude Code com o mesmo Claude |
| `../atlas-cli-release-checklist.md` | Checklist de release, Fair Claude Gate e Atlas Supercharged Gate |

## Docs Legados Preservados

Estes arquivos nao devem ser usados como fonte primaria. Eles ficam no repo para
historico, auditoria e compatibilidade de links antigos.

| Doc | Status | Substituto |
|---|---|---|
| `architecture.md` | deprecated | `README.md`, `START_HERE.md`, `code-intelligence.md`, `atlas-ai-canonical-architecture-index.md` |
| `context-pack.md` | deprecated | `open-brain-context-injection.md`, `memory-core-contracts.md`, `code-intelligence.md`, `atlas-ai-pipeline.md` |
| `capability-matrix.md` | deprecated | `engineering-blueprint-maturity-dod.md`, `super-tool-runtime-core.md`, `programming-power-tools-catalog.md` |
| `maintenance-playbook.md` | deprecated | `START_HERE.md`, `engineering-blueprint-runbook.md`, `memory-core-runbook.md` |
| `mcp-tools-contract.md` | archived | `atlas-ai-memory-context-core-open-brain.md`, `open-brain-context-injection.md`, `memory-core-contracts.md` |
| `mcp-tools-rollout-report.md` | archived | Tests MCP, command describe e docs de Memory/Open Brain |
| `../superpowers/plans/*` e `../superpowers/specs/*` | archived/source material | `super-tool-runtime-core.md`, `programming-power-tools-catalog.md`, `paste-image-setup.md`, `atlas-cli-final-product.md` |
| `../../resolver-o-que-vale-a-pena/**` | governed legacy corpus | `atlas-ai-resolver-corpus-audit.md`, `legacy-documentation-cleanup-report.md`, docs canonicos por familia |

## O Que Entra Aqui

- arquitetura de subsistemas de engenharia;
- ADRs;
- contratos canonicos;
- runbooks vivos;
- politicas de release, rollback e qualidade;
- instrucoes de como o Atlas deve montar contexto para engenharia;
- decisoes que nao podem depender de memoria de conversa.

## O Que Nao Entra Aqui

- resultados individuais de runs;
- logs longos;
- traces de provider;
- artifacts de teste;
- notas privadas do operador;
- segredos, tokens ou credenciais.

Esses itens pertencem ao Postgres operacional, artifacts do Harness ou camada
de memoria com politica de privacidade.
