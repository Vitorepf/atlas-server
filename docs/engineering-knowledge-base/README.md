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
decisions:
  - START_HERE.md e o ponto de entrada para humanos e IAs.
  - Docs versionados sao a fonte de verdade.
  - Postgres guarda indice operacional e estado consultavel.
  - O indice de codigo liga docs canonicos a implementacao real.
  - Obsidian pode ser espelho humano, nunca fonte primaria automatica.
maintenance:
  - Leia START_HERE.md antes de continuar implementacoes de memoria/contexto.
  - Rode atlas engineering knowledge sync --prune depois de alterar estes docs.
  - Rode atlas engineering knowledge index-code --prune depois de alterar docs ou codigo core.
  - Consulte memory-core-runbook.md antes de executar operacoes destrutivas ou provider projection apply.
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
```

## Docs Canonicos Principais

| Doc | Papel |
|---|---|
| `START_HERE.md` | Ponto de entrada para humanos e IAs |
| `atlas-ai-memory-context-core-open-brain.md` | Documento mestre versionado de memoria, contexto e recall |
| `architecture.md` | Arquitetura da Engineering Knowledge Base |
| `context-pack.md` | Como knowledge/code refs entram nos context packs |
| `code-intelligence.md` | Indice de codigo, modulos, simbolos e doc links |
| `capability-matrix.md` | Estado das capacidades do Harness |
| `maintenance-playbook.md` | Manutencao operacional do Harness |
| `memory-core-runbook.md` | Runbook diario de memoria, sync, privacy, projection e validacao |
| `memory-core-contracts.md` | Contratos de tabelas, refs, APIs, CLI e config |
| `memory-core-security-privacy.md` | Politica de privacy, redaction e provider-safety |
| `memory-core-failure-modes.md` | Diagnostico e recuperacao por camada |
| `memory-core-maturity-dod.md` | Maturity model, metricas e Definition of Done |

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
