---
id: atlas-context-cache-compiler-runtime
type: engineering_knowledge
title: Atlas Context Cache Compiler Runtime
status: active
implementation_state: runtime_surface_context_cache_ready
blocker: Enforcement em Dev/Forge depende de shadow receipts reais e rollback; runtime ACCCR read-only ja gera Merkle pack, warmup receipt, delta request e drift gate.
category: intelligence-runtime
priority: 100
summary: Doc filha AQPES para contexto cacheavel, Merkle Context Pack, prompt warmup, delta context e bloqueio de cache stale/poisoned sem chamada a provider.
human_summary: Monta o contexto repetido de forma cacheavel e manda so o delta novo quando seguro.
tags: [atlas-ai, aqpes, acccr, context-cache, prompt-cache, merkle, delta-context]
capabilities: [merkle_context_pack, prompt_cache_warmup, delta_context_request, prefix_drift_gate, stale_cache_block]
decisions:
  - ACCCR reduz custo/latencia sem remover informacao; qualidade vem de must-keep coverage e freshness.
  - As zonas cacheaveis ficam antes do delta da tarefa e so mudam por hash real.
  - Cache hit nunca e declarado como ganho de qualidade, apenas como eficiencia.
maintenance:
  - Atualizar antes de mudar zonas cacheaveis, invalidation, prompt prefix ou delta context.
related_paths:
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-quality-preserving-efficiency-system.md
  - docs/engineering-knowledge-base/atlas-context-compiler-runtime.md
  - docs/engineering-knowledge-base/atlas-cognitive-memory-fabric.md
  - app/Services/Ai/Context/AtlasContextCacheCompilerRuntimeService.php
  - app/Console/Commands/AtlasContextCacheCompilerCommand.php
  - tests/Feature/Ai/Context/ContextCacheCompilerRuntimeTest.php
doc_schema: atlas_canonical_module_doc.v1
macro_layer: true
product_name: Atlas Context Cache Compiler Runtime
runtime_acronym: ACCCR
internal_product_name: Atlas Merkle Context Cache
technical_runtime: AtlasContextCacheCompilerRuntimeService
human_name: Atlas Context Cache Compiler Runtime
canonical_name: Atlas Context Cache Compiler Runtime
technical_name: AtlasContextCacheCompilerRuntimeService
cartography_type: runtime
canonical_source: docs/engineering-knowledge-base/atlas-context-cache-compiler-runtime.md
graph_id: atlas-context-cache-compiler-runtime
graph_title: Atlas Context Cache Compiler Runtime
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-quality-preserving-efficiency-system
graph_status: active
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-context-cache-compiler-runtime.md
allowed_changes:
  - Ajustar zonas, invalidation, delta request e freshness gate.
forbidden_changes:
  - Cachear fonte stale, poisoned ou sem autoridade.
  - Mover task delta para prefixo cacheavel.
  - Expor conteudo bruto em payload de cache.
depends_on: [atlas-quality-preserving-efficiency-system, atlas-context-compiler-runtime]
flows_to: [atlas-runtime-efficiency-governor, atlas-token-economy-runtime, atlas-dev, atlas-forge]
unlocks: [prompt_cache_warmup, delta_context, merkle_context_hash]
governs: [atlas.context_cache.compiler.v1, atlas.context_merkle_pack.v1, atlas.prompt_cache_warmup.v1, atlas.delta_context_request.v1]
evidence:
  - app/Services/Ai/Context/AtlasContextCacheCompilerRuntimeService.php
  - tests/Feature/Ai/Context/ContextCacheCompilerRuntimeTest.php
required_tests:
  - "php artisan atlas:context:cache-warm --json"
  - "php artisan test tests/Feature/Ai/Context/ContextCacheCompilerRuntimeTest.php"
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Persistir warmup receipts apenas depois de shadow real em Dev/Forge.
---

# Atlas Context Cache Compiler Runtime

## Resumo

ACCCR transforma contexto repetido em prefixo cacheavel e separa o pedido atual
como delta. O objetivo e economizar token/latencia sem alterar o conteudo logico
entregue ao modelo.

## Papel no Atlas

O Atlas tem muito contexto repetido: governanca, owner docs, schemas, tools,
mapa do projeto e policy. ACCCR organiza isso em Merkle pack deterministico para
cache e deixa apenas `task_delta_zone` variar por request.

## Onde Se Encaixa

```text
AUCRI -> ACCCR -> ACCR -> ATER -> AREG -> provider
```

ACCCR nao escolhe provider e nao executa ferramenta. Ele so monta hash, warmup e
delta request.

## Contratos

- `atlas.context_cache.compiler.v1`
- `atlas.context_merkle_pack.v1`
- `atlas.prompt_cache_warmup.v1`
- `atlas.delta_context_request.v1`

Campos minimos: `flow_id`, `provider`, `workspace`, `nodes[]`,
`zone_hashes`, `cacheable_prefix_hash`, `context_pack_hash`, `cache_status`,
`delta_node_hashes`, `must_keep_coverage`, `prompt_prefix_drift`.

## Fluxo

1. Separar contexto em cinco zonas.
2. Hashar cada node sem expor texto bruto.
3. Calcular zone hashes e cacheable prefix hash.
4. Emitir warmup receipt.
5. Emitir delta request com task, arquivos mudados e evidence refs.
6. Bloquear se houver prefix drift, cache poisoned ou must-keep stale.

## Regras para IA

- Nao colocar pedido atual no prefixo cacheavel.
- Nao cachear chat/Obsidian/projection como hard authority.
- Nao usar cache stale para economizar token.
- Nao tratar cache hit como prova de qualidade.
- Nao expor raw content; use hash, source path e autoridade.

## Escopo de Implementacao

Runtime ativo cobre Merkle Context Pack, prompt cache warmup, delta request,
drift detection, stale must-keep block, poisoned cache block, command JSON e
testes focados.

## Dependencias

Depende de AQPES para politica de eficiencia, ACCR para compilacao final, ACMF
para working memory e AREG para orcamento cognitivo.

## Evidencias

- `php artisan atlas:context:cache-warm --json`;
- teste de hash deterministico e cache hit;
- teste de prefix drift bloqueando cache;
- teste de must-keep stale bloqueando qualidade;
- teste que prova que raw content sensivel nao aparece.

## Riscos

- Prompt prefix drift silencioso.
- Cache poisoned.
- Fonte stale parecendo valida.
- Delta incompleto.
- Economia estimada sem cache hit real.

## Exemplos

Atlas Dev aquece governance, owner docs, tool schemas e code map; a tarefa atual
entra no delta com arquivos tocados e evidence refs.

## Proximas Acoes

1. Ligar ACCCR ao Dev/Forge em shadow.
2. Persistir cache receipts com rollback.
3. Medir cache hit real por flow/provider.
