---
id: engineering-knowledge-context-pack
type: engineering_knowledge
title: Knowledge Refs No Context Pack Legacy
status: deprecated
category: context_pack
priority: 35
summary: Documento legado sobre refs compactas no context pack; substituido por Open Brain Context Injection, Memory Core Contracts, Code Intelligence e Atlas AI Pipeline.
tags:
  - context-pack
  - harness
  - recall
capabilities:
  - compact_recall
  - provider_portability
  - maintenance_context
  - engineering_blueprint
  - task_contracts
  - open_brain_context_injection
decisions:
  - Context pack carrega referencias compactas por padrao.
  - O markdown completo permanece versionado no repo.
  - Task contract e blueprint snapshot sao entradas obrigatorias para execucao autonoma de engenharia.
  - Open Brain Context Injection define quando esse contexto deve entrar automaticamente em CLI/app.
  - Este documento nao e mais a fonte primaria do contrato de contexto.
maintenance:
  - Nao expandir este documento; atualizar os docs em superseded_by.
superseded_by:
  - docs/engineering-knowledge-base/open-brain-context-injection.md
  - docs/engineering-knowledge-base/memory-core-contracts.md
  - docs/engineering-knowledge-base/code-intelligence.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
related_paths:
  - app/Services/Engineering/EngineeringContextPackService.php
  - app/Services/Engineering/EngineeringKnowledgeBaseService.php
  - app/Services/Engineering/EngineeringTaskContractService.php
  - app/Services/Engineering/EngineeringBlueprintService.php
  - docs/engineering-knowledge-base/engineering-blueprint.md
  - docs/engineering-knowledge-base/open-brain-context-injection.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: engineering-knowledge-context-pack

graph_title: Knowledge Refs No Context Pack Legacy

graph_world: atlas

graph_layer: module

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: deprecated

graph_source: repo

owner: context-pack

repo_paths:
  - docs/engineering-knowledge-base/context-pack.md

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
  - context-pack

evidence:
  - docs/engineering-knowledge-base/context-pack.md

evidence_refs:
  - symbol: AtlasContextPackContractService
  - command: atlas:aaeos:context-pack-contract
  - test: AtlasContextPackContractTest

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: low

visual_tags:
  - module
  - module
  - context-pack

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
# Knowledge Refs No Context Pack

> Status: deprecated. O contrato vivo de injecao de contexto agora esta em
> `open-brain-context-injection.md`, `memory-core-contracts.md`,
> `code-intelligence.md` e `atlas-ai-pipeline.md`.

O context pack do Harness deve lembrar o provider de quais fontes canonicas
importam para a tarefa. Ele nao precisa colar todos os documentos no prompt.

Cada referencia inclui:

- id;
- slug;
- titulo;
- categoria;
- prioridade;
- path canonico;
- content hash;
- resumo;
- motivo da inclusao.

## Blueprint Refs

Para tarefas de engenharia, o context pack deve tratar estes dados como nucleo,
nao como contexto opcional:

- `task_contract`;
- `engineering_blueprint`;
- `engineering_blueprint_snapshot` quando existir;
- `contingency_policy`;
- `review_gates`;
- `acceptance_matrix`;
- `scenario_inventory`;
- `knowledge_refs` da familia `engineering-blueprint*.md` quando a task tocar
  planejamento, QA, review, Postgres ou geracao de tasks;
- `code_refs` para services, rotas, comandos, migrations, app surfaces e testes
  relacionados.

Sem esses dados, o provider pode ajudar a investigar, mas nao deve receber
autonomia total para concluir task de risco medio/alto.

## Beneficio

Isso melhora manutencao porque o Atlas consegue reidratar contexto de engenharia
sem depender da conversa atual. Codex, Claude, GPT ou outro motor recebem a mesma
lista de referencias canonicas.

## Relacao Com Open Brain Context Injection

Este documento explica o formato compacto dos refs. O documento
`open-brain-context-injection.md` define quando e como esses refs devem entrar
automaticamente no prompt de `atlas dev`, `atlas continue`, `atlas chat` e Atlas
AI App em modos de programacao, review e debug.

Regra: nao duplique context pack. Se o Open Brain ja renderizou uma secao com o
mesmo `context_pack_hash`, o prompt final deve usar uma unica secao auditavel.

## Politica De Tamanho

Por padrao, o Harness usa ate 8 referencias. O ranking prioriza arquitetura,
manutencao e matriz de capacidades, com boost para categorias relacionadas a
tags da task quando existirem.

## Falha Segura

Se a migration ainda nao rodou ou a tabela nao existe, o context pack continua
funcionando sem knowledge refs. Isso evita quebrar o Runner durante deploy ou
ambientes parciais.

## Resumo

Documento legado sobre refs compactas no context pack; substituido por Open Brain Context Injection, Memory Core Contracts, Code Intelligence e Atlas AI Pipeline.

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
