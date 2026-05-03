---
id: atlas-memory-core-start-here
type: engineering_knowledge
title: START HERE - Atlas Memory And Engineering Knowledge
status: active
category: onboarding
priority: 99
summary: Ordem canonica de leitura para humanos e IAs entenderem memoria, contexto, Knowledge Base e Code Intelligence do Atlas sem depender de conversa anterior.
tags:
  - atlas
  - memory
  - start-here
  - onboarding
capabilities:
  - canonical_onboarding
  - memory_core_preservation
  - engineering_knowledge_navigation
  - engineering_blueprint
  - project_blueprint_pipeline
decisions:
  - Toda IA nova deve ler este arquivo antes de alterar Memory Core, Knowledge Base ou Code Intelligence.
  - Toda IA nova deve ler Engineering Blueprint antes de alterar planejamento, task contracts, QA, review ou Postgres gate.
  - O documento mestre preservado no atlas-server e a fonte versionada de direcao.
  - A raiz do workspace pode ter copias auxiliares, mas o conteudo que precisa sobreviver deve estar dentro do repo.
maintenance:
  - Atualize este arquivo quando a ordem de leitura ou os docs canonicos mudarem.
  - Antes de trocar de maquina, confirme que este arquivo e os docs relacionados foram commitados e enviados ao remoto.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md
  - docs/engineering-knowledge-base/README.md
  - docs/engineering-knowledge-base/memory-core-runbook.md
  - docs/engineering-knowledge-base/memory-core-contracts.md
  - docs/engineering-knowledge-base/memory-core-security-privacy.md
  - docs/engineering-knowledge-base/memory-core-failure-modes.md
  - docs/engineering-knowledge-base/memory-core-maturity-dod.md
  - docs/engineering-knowledge-base/code-intelligence.md
  - docs/engineering-knowledge-base/engineering-blueprint.md
  - docs/engineering-knowledge-base/engineering-blueprint-contracts.md
  - docs/engineering-knowledge-base/engineering-blueprint-quality-gates.md
  - docs/engineering-knowledge-base/engineering-blueprint-runbook.md
  - docs/engineering-knowledge-base/engineering-blueprint-maturity-dod.md
---

# START HERE - Atlas Memory And Engineering Knowledge

Este e o ponto de entrada canonico para qualquer humano ou IA que precise
continuar o sistema de memoria, contexto, Knowledge Base ou Code Intelligence do
Atlas.

## Ordem De Leitura

1. `atlas-ai-memory-context-core-open-brain.md`
   Documento mestre: arquitetura, fases, status real e limites do Memory Core.

2. `README.md`
   Visao da Engineering Knowledge Base e lista dos docs canonicos.

3. `memory-core-runbook.md`
   Procedimentos operacionais para sync, index, privacy, governance, verbatim,
   provider projection e validacao.

4. `memory-core-contracts.md`
   Contratos de tabelas, APIs, CLI, refs e configuracao.

5. `memory-core-security-privacy.md`
   Politica de privacy, redaction e provider-safety.

6. `memory-core-failure-modes.md`
   Diagnostico e recuperacao por camada.

7. `memory-core-maturity-dod.md`
   Maturity model e Definition of Done.

8. `code-intelligence.md`
   Como o Atlas entende o codigo real via modulos, simbolos, rotas, comandos,
   migrations, testes e doc links.

9. `engineering-blueprint.md`
   Produto final do Engineering Blueprint System: intencao de produto,
   blueprint, task contracts, Harness, QA, review, Postgres gate e memory delta.

10. `engineering-blueprint-contracts.md`
    Schemas e invariantes de project blueprint, task blueprint, task contract,
    inventory, scenarios, evidencias e review findings.

11. `engineering-blueprint-quality-gates.md`
    Gates de aceite, QA manual, visual smoke, deep review, Postgres review,
    telemetry e Definition of Done de qualidade.

12. `engineering-blueprint-runbook.md`
    Como operar e implementar blueprint pelo app, CLI e API.

13. `engineering-blueprint-maturity-dod.md`
    Estado real dos 7 itens, fases faltantes e criterio final de conclusao.

## Regras Para IAs

- Nao assumir contexto de conversa anterior.
- Ler os docs canonicos antes de alterar Memory Core.
- Nao implementar embeddings, ChromaDB, vector search ou Open Brain remoto sem
  fase propria e DoD explicito.
- Nao tratar `CLAUDE.md`, `AGENTS.md`, Obsidian ou chat como fonte primaria.
- Nao alterar planejamento, task contracts, QA, review ou Postgres gate sem ler
  a familia `engineering-blueprint*.md`.
- Atualizar docs e rodar sync/index-code quando alterar arquitetura ou regras.
- Preservar mudancas existentes; nunca reverter trabalho de outro operador sem
  pedido explicito.

## Preservacao Antes De Trocar De Maquina

Checklist minimo:

```bash
git status --short
/opt/homebrew/bin/php artisan atlas:engineering:knowledge sync --prune --json
/opt/homebrew/bin/php artisan atlas:engineering:knowledge index-code --prune --workspace=/Users/vitorepf/Develop/atlas/atlas-server --json
/opt/homebrew/bin/php artisan test --filter=AtlasEngineeringKnowledgeBaseTest
```

Depois disso, commit e push no remoto do `atlas-server`.

Sem commit/push, estar dentro da pasta local nao garante sobrevivencia durante
troca de MacBook.
