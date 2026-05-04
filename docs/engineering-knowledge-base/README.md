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
decisions:
  - START_HERE.md e o ponto de entrada para humanos e IAs.
  - Docs versionados sao a fonte de verdade.
  - Postgres guarda indice operacional e estado consultavel.
  - O indice de codigo liga docs canonicos a implementacao real.
  - Obsidian pode ser espelho humano, nunca fonte primaria automatica.
  - Obsidian/AtlasVault tem contrato proprio para import/export bidirecional seguro.
  - Engineering Blueprint define o contrato de produto e qualidade antes de execucao pelo Harness Runner.
  - Open Brain Context Injection define quando CLI/app devem usar memoria automaticamente em tarefas de codigo.
  - Programming Power Tools Catalog define a bancada operacional de ferramentas, tiers, autoridade e lacunas para programacao pesada.
  - Fair Claude e Atlas Supercharged separam prova cientifica com o mesmo Claude do produto real multi-provider.
maintenance:
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
4. Obsidian ou outras notas humanas apenas como espelho opcional.

O repositorio e a fonte de verdade porque muda junto com o codigo, passa por
review, entra no diff e acompanha migrations/testes. O Postgres e o indice vivo
porque pode ser consultado pelo app, API, CLI, memory layer e context packs.

O Atlas tambem mantem um Code Intelligence Index em Postgres. Essa camada le o
codigo real e registra modulos, simbolos, rotas, comandos, migrations, testes e
links docs->codigo. Assim, a IA recebe conceito e implementacao no mesmo context
pack.

Obsidian/AtlasVault e uma camada humana bidirecional de leitura, escrita,
curadoria e navegacao. O contrato canonico esta em
`obsidian-atlas-vault.md`. Ele permite import/export seguro, mas nao transforma
notas soltas em fonte primaria.

## Regra De Ouro

Se uma decisao orienta manutencao, revisao, release, arquitetura ou evolucao do
Atlas, ela precisa existir aqui ou em um ADR desta pasta.

## Como Sincronizar

```bash
atlas engineering knowledge sync --prune
atlas engineering knowledge index-code --prune
```

Para inspecionar:

```bash
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
| `START_HERE.md` | Ponto de entrada para humanos e IAs |
| `atlas-ai-memory-context-core-open-brain.md` | Documento mestre versionado de memoria, contexto e recall |
| `architecture.md` | Arquitetura da Engineering Knowledge Base |
| `context-pack.md` | Como knowledge/code refs entram nos context packs |
| `open-brain-context-injection.md` | Como CLI e app devem usar Open Brain automaticamente em dev, continue, chat, programming, review e debug |
| `obsidian-atlas-vault.md` | Contrato para Obsidian/AtlasVault como camada humana bidirecional, sem virar fonte primaria |
| `code-intelligence.md` | Indice de codigo, modulos, simbolos e doc links |
| `engineering-blueprint.md` | Produto final do Engineering Blueprint System |
| `engineering-blueprint-contracts.md` | Contratos de blueprint, task, inventory, scenarios, evidencias e findings |
| `engineering-blueprint-quality-gates.md` | QA, deep review, Postgres gate, thresholds e DoD de qualidade |
| `engineering-blueprint-runbook.md` | Operacao app/CLI/API para blueprint, run, QA, review e sync |
| `engineering-blueprint-maturity-dod.md` | Estado real, plano por fases e DoD final dos 7 itens |
| `super-tool-runtime-core.md` | Registry, politica, executor e evidence store genericos para ferramentas |
| `programming-power-tools-catalog.md` | Catalogo operacional de ferramentas para programacao pesada, tiers T0-T3, autoridade, lacunas e backlog |
| `capability-matrix.md` | Estado das capacidades do Harness |
| `maintenance-playbook.md` | Manutencao operacional do Harness |
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

## O Que Entra Aqui

- arquitetura de subsistemas de engenharia;
- ADRs;
- matriz de capacidades;
- playbooks de manutencao;
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
