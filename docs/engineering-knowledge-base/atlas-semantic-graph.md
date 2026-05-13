---
id: atlas-semantic-graph
type: engineering_knowledge
title: Atlas Semantic Graph
status: building
category: cartography
priority: 98
summary: Contrato da Cartografia como grafo semantico real que le docs oficiais e AtlasVault para navegar mundo, sistema, fluxo, modulo e engrenagem.
tags:
  - atlas
  - cartography
  - semantic-graph
  - atlas-vault
capabilities:
  - atlas_semantic_graph
  - atlas_cartography
  - source_authority
  - live_docs
decisions:
  - Cartografia nao e projecao manual; e interface de leitura da verdade em arquivos reais.
  - Repo docs e AtlasVault aparecem como uma experiencia unica, com source visivel apenas como metadado.
  - O grafo usa graph_id, graph_parent, flows_to, depends_on e unlocks como contrato visual e operacional.
maintenance:
  - Atualizar quando shape do endpoint, schema visual ou autoridade de fonte mudar.
  - Rodar docs-health e verificar /atlas-cartography/graph apos alteracoes.
related_paths:
  - docs/engineering-knowledge-base/vault/atlas-vault-cartography-schema.md
  - docs/engineering-knowledge-base/vault/atlas-vault-cartography-schema-contracts.md
  - docs/engineering-knowledge-base/atlas-canonical-module-doc-v1.md
  - app/Services/Vault/GraphAssembler.php
  - app/Services/Vault/RepoVaultReader.php
  - app/Services/Vault/ObsidianVaultReader.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-semantic-graph
graph_title: Atlas Semantic Graph
graph_world: atlas
graph_layer: system
graph_kind: system
graph_parent: atlas
graph_status: building
graph_source: repo
owner: atlas-cartography
repo_paths:
  - docs/engineering-knowledge-base/atlas-semantic-graph.md
  - docs/engineering-knowledge-base/vault/atlas-vault-cartography-schema.md
  - docs/engineering-knowledge-base/vault/atlas-vault-cartography-schema-contracts.md
  - app/Services/Vault/GraphAssembler.php
  - app/Services/Vault/RepoVaultReader.php
  - app/Services/Vault/ObsidianVaultReader.php
allowed_changes:
  - Evoluir montagem do grafo para ler relacoes reais dos frontmatters.
  - Ajustar shape do endpoint quando preservar compatibilidade com Desktop.
forbidden_changes:
  - Reintroduzir mock como fonte normal.
  - Separar visualmente repo e Vault como se fossem dois produtos.
  - Fazer Cartografia escrever arquivos.
depends_on:
  - atlas-canonical-module-doc-v1
  - atlas-desktop-backend-contract
flows_to:
  - atlas-desktop-code-surface
unlocks:
  - atlas-code-operating-room
  - atlas-vault-cockpit
governs:
  - atlas-cartography
  - atlas-vault
  - engineering-knowledge-base
evidence:
  - app/Services/Vault/GraphAssembler.php
  - app/Services/Vault/RepoVaultReader.php
  - app/Services/Vault/ObsidianVaultReader.php
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
  - php artisan tinker --execute='app(\App\Services\Vault\GraphAssembler::class)->assemble(); echo "graph-ok\n";'
requires_evidence: true
risk_level: high
next_actions:
  - Fazer /atlas-cartography/graph expor hierarquia e relacoes derivadas dos docs reais.
visual_tags:
  - module
  - module
  - cartography

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
---
# Atlas Semantic Graph

## Resumo

Atlas Semantic Graph e o contrato da Cartografia como mapa real do conhecimento do Atlas. Ele deve permitir sair do mundo inteiro e chegar em uma engrenagem pequena sem perder contexto.

## Papel no Atlas

Ele transforma arquivos `.md` em imagem viva: docs oficiais para arquitetura tecnica e AtlasVault para memoria humana, filosofia, livros, historias e insights.

## Onde Se Encaixa

Pai: `atlas`. Irmaos: Atlas Code, AtlasVault e Documentation OS. Ele consome `atlas-canonical-module-doc-v1` para docs tecnicos e frontmatter do Vault para notas humanas.

## Contratos

O grafo deve usar `graph_id`, `graph_parent`, `flows_to`, `depends_on`, `unlocks`, `governs`, `risk_level` e `graph_status`. Cada node deve apontar para fonte real.

## Fluxo

Reader do repo indexa docs oficiais. Reader do Vault indexa notas Obsidian. Assembler une tudo, resolve canon esperado, adiciona relacoes e retorna mapa navegavel.

## Regras para IA

IA nao deve criar mapa paralelo. Se uma peca precisa aparecer, deve atualizar o arquivo fonte com frontmatter correto.

## Escopo de Implementacao

Permitido: leitura, indexacao, relacoes, endpoint e render. Proibido: escrita pela Cartografia e dados inventados.

## Dependencias

- `atlas-canonical-module-doc-v1`
- `atlas-desktop-backend-contract`
- `vault/atlas-vault-cartography-schema`

## Evidencias

- `app/Services/Vault/GraphAssembler.php`
- `app/Services/Vault/RepoVaultReader.php`
- `app/Services/Vault/ObsidianVaultReader.php`

## Riscos

- Grafo ficar bonito mas nao navegavel.
- Conexoes demais virarem nuvem ilegivel.
- Fonte oficial e Vault parecerem sistemas separados.

## Exemplos

Ao clicar em Atlas, o usuario ve sistemas. Ao entrar no Kernel, ve fluxo. Ao clicar em Atlas Decide, ve dependencias, unlocks, evidencia, risco e proxima acao.

## Proximas Acoes

Fazer o frontend usar `semantic_graph` para cenas de zoom semantico alem do pipeline inicial.
