---
id: engineering-knowledge-maintenance-playbook
type: engineering_knowledge
title: Playbook De Manutencao Do Harness Legacy
status: deprecated
category: maintenance
priority: 35
summary: Playbook legado de manutencao do Harness; substituido por START_HERE, Engineering Blueprint Runbook, Memory Core Runbook e README da Knowledge Base.
tags:
  - maintenance
  - review
  - harness
capabilities:
  - maintenance_review
  - release_readiness
  - context_rehydration
  - engineering_blueprint
decisions:
  - Toda manutencao relevante deve comecar carregando docs canonicos e registry.
  - Validacao deve cobrir backend, app e docs quando a mudanca atravessa camadas.
  - Mudancas em planejamento, task contracts, QA, review ou Postgres gate seguem o Engineering Blueprint runbook.
  - Este documento nao e mais fonte primaria do fluxo de manutencao.
maintenance:
  - Nao expandir este documento; atualizar os docs em superseded_by.
superseded_by:
  - docs/engineering-knowledge-base/START_HERE.md
  - docs/engineering-knowledge-base/engineering-blueprint-runbook.md
  - docs/engineering-knowledge-base/memory-core-runbook.md
  - docs/engineering-knowledge-base/README.md
related_paths:
  - app/Console/Commands/AtlasEngineeringKnowledgeCommand.php
  - app/Services/Engineering/EngineeringContextPackService.php
  - docs/engineering-knowledge-base/engineering-blueprint-runbook.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: engineering-knowledge-maintenance-playbook

graph_title: Playbook De Manutencao Do Harness Legacy

graph_world: atlas

graph_layer: module

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: deprecated

graph_source: repo

owner: maintenance

repo_paths:
  - docs/engineering-knowledge-base/maintenance-playbook.md

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
  - maintenance

evidence:
  - docs/engineering-knowledge-base/maintenance-playbook.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: low

visual_tags:
  - module
  - module
  - maintenance

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
# Playbook De Manutencao

> Status: deprecated. O fluxo vivo de manutencao esta em `START_HERE.md`,
> `engineering-blueprint-runbook.md`, `memory-core-runbook.md` e `README.md`.

## Antes De Editar

1. Rode `atlas engineering knowledge status`.
2. Se os docs mudaram, rode `atlas engineering knowledge sync --prune`.
3. Leia a matriz de capacidades.
4. Identifique qual camada sera tocada: CLI, API, service, migration, app, docs ou tests.
5. Verifique se a mudanca precisa alterar context pack, Atlas-Bench ou release gate.
6. Se a mudanca tocar planejamento, task contracts, QA, review ou Postgres gate,
   leia `engineering-blueprint.md` e `engineering-blueprint-runbook.md`.

## Durante A Implementacao

- manter mudancas pequenas e auditaveis;
- preferir services existentes a novas abstracoes;
- preservar path-safety e limites de payload;
- nao colocar logs longos no registry;
- registrar decisao nova em ADR quando afetar politica ou arquitetura;
- manter comandos gratuitos/local-first quando possivel.

## Depois De Editar

Executar, no minimo, validacoes focadas:

```bash
/opt/homebrew/bin/php artisan test tests/Feature/AtlasEngineeringKnowledgeBaseTest.php
/opt/homebrew/bin/php artisan test tests/Feature/EngineeringHarnessRunnerTest.php --filter=context
```

Quando app/API forem alterados:

```bash
npm run test:front
npm run typecheck
```

Quando docs mudarem:

```bash
atlas engineering knowledge sync --prune
atlas engineering knowledge list --json
atlas engineering knowledge index-code --prune
```

## Quando Criar ADR

Crie ADR quando a decisao:

- muda fonte de verdade;
- muda politica de autonomia;
- muda criterio de release;
- adiciona dependencia operacional;
- afeta privacidade, seguranca, custo ou reprodutibilidade;
- altera como o Atlas monta contexto para providers.

## Definicao De Pronto

Uma melhoria do Harness so esta pronta quando:

- codigo passa em teste focado;
- docs canonicos explicam como manter;
- registry foi sincronizado;
- app/CLI/API refletem o fluxo quando necessario;
- o context pack consegue recuperar a referencia relevante.

## Resumo

Playbook legado de manutencao do Harness; substituido por START_HERE, Engineering Blueprint Runbook, Memory Core Runbook e README da Knowledge Base.

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
