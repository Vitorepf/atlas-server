# AURG vivo — Salto 1 (F1–F5): o cérebro unificado de realidade, em produto

Estado: **PRODUTO, LIGADO NO DEV** (2026-06-09). Migrado, ingerido, consultável, injetando no prompt vivo, com compounding e trilha temporal. Não commitado (decisão do operador).

> Doc de operador. A árvore canônica (`docs/engineering-knowledge-base`) exige 21 campos de frontmatter; este doc vive em `dissecar/` de propósito.

## O que é

O AURG (Atlas Unified Reality Graph) é o grafo **CROSS-LAYER** prometido pelo docblock do builder canônico (`app/Services/Ai/Reality/AtlasRealityGraphSnapshotBuilderService.php` — "Phase 2: Persistencia (atlas_aurg_nodes + atlas_aurg_edges)"). Ele federa projeções compactas e provider-safe dos 5 read-models reais e materializa os **links entre camadas** — a única coisa que nenhuma camada sozinha tem.

Regra de arquitetura (lição do graphify, "no parallel graph beside Code Intelligence"): o detalhe intra-camada FICA nas fontes. O cérebro guarda refs (`source_kind + source_id + label provider-safe`) — centenas a poucos milhares de nós, nunca cópia (nunca os 113k símbolos, nunca payloads).

## As 5 fontes

| Fonte | Read-model | O que entra no cérebro |
|---|---|---|
| memory | `atlas_memory_entries` + `atlas_verbatim_memories` | Label SÓ da projeção já redigida (`AtlasMemoryPrivacyService`); verbatim bloqueado nem entra; entry bloqueada vira nó `provider_safe=false` (local-only) |
| code | `atlas_engineering_code_modules` + workspaces | 1 nó por workspace + módulos top-N (cap 300/workspace). Code-graph continua canônico para code→code |
| domains | `CrossDomainTaxonomyMap` (21 canônicos) + mesh M-8 | 21 nós de domínio (sensíveis ⇒ `provider_safe=false`) + 198 arestas allowed-crossing REUSADAS da topologia do mesh |
| evidence | `atlas_engineering_evidence` | Refs com ids/hashes APENAS — summary/command/output nunca entram |
| strategic | `atlas_reality_entities`/`_relationships` (ASRE) | Entidades ativas com decay de 14 dias honrado; relationships viram arestas |

Linkers cross-layer **determinísticos, cite-or-omit** (sem LLM, sem aresta inventada; cada aresta cita em `meta` o campo/valor que casou): memory→code (path exato 1.0 / prefixo-de-root ou token=slug 0.7), memory→domain (resolução exata na taxonomia, 1.0), evidence→memory/code (1.0 / 0.7), workspace→engineering (1.0 por construção). Escada de confiança fechada: só 1.0 e 0.7 existem.

## Comandos

```bash
php artisan atlas:aurg:ingest [--source=...] [--prune] [--json]   # sync idempotente + tick temporal
php artisan atlas:aurg:query "pergunta" [--depth=] [--provider-bound] [--json]
php artisan atlas:aurg:status [--json]                            # saúde: store + temporal + flags
```

- Query = seeds híbridos (pgvector REAL via `AtlasMemoryVectorSearchService` + lexical por termo) → BFS bounded (depth≤3 hard) → caminhos com proveniência completa → ranking no **Python networkx** via `GraphRankRuntimeClient` (acima de 12 nós; abaixo/falha = `unranked_*` honesto, nunca score fabricado em PHP).
- MCP: `atlas_aurg_query` com `provider_bound=true` FORÇADO.
- Schedule: sync diário `--prune` às 05:50 (`routes/console.php`), gateado por `atlas.aurg.schedule_enabled`.

## A flag (read-back no prompt vivo)

`.env`: `ATLAS_OPEN_BRAIN_INJECTION_INCLUDE_REALITY_GRAPH=true` (ligada no F5, logo após `..._INCLUDE_MEMORY_RECALL=true`).

Com a flag ON, `AtlasOpenBrainContextInjectionService` consulta o MESMO engine do `atlas:aurg:query` (não um segundo grafo) com `provider_bound` **hard-coded true** e renderiza até 6 chains no bloco `## Atlas Unified Reality Graph`. Flag OFF ⇒ zero resolução de serviço, zero DB, prompt byte-idêntico.

**Descoberta F5 (placement é load-bearing):** o orçamento da seção (`budget_chars`, default 20k) trunca pela CAUDA (`Str::limit`). No tail original, uma seção rotineira de ~80k (code-graph auto-context ON) matava o bloco TODA vez — flag ON virava no-op. Fix: o bloco renderiza ANTES das listas volumosas (knowledge/code/code-graph). Provado vivo depois do fix: `PROMPT_HAS_BRAIN_BLOCK=YES` com 6 chains do sentinel mesmo com a seção ainda truncando.

## Privacidade (estrutural, não filtro)

- Nó carrega `provider_safe` + `sensitive` como COLUNAS; leitor nenhum precisa decodificar meta.
- Memória: só projeção redigida; evidence: ids/hashes; domínios sensíveis: `provider_safe=false`.
- No caminho provider-bound, o BFS **nunca atravessa** nó excluído — o que só é alcançável via nó sensível fica estruturalmente inalcançável (exclusão por construção, não pós-filtro).
- Local-first: ingest lê read-models locais e escreve tabelas locais; nada sai da máquina.

## Compounding + temporal (F4)

- **Ingest-on-write:** todo `AtlasMemoryRegistryService::record/upsert` acresce o nó da entry + re-roda os linkers SÓ daquela linha (fail-open; nunca bloqueia a escrita; subset exato do próximo sync). Provado vivo no F5: a entry sentinela ganhou nó + 2 arestas citadas (path 0.7 → módulo; domain 1.0 → engineering) sem nenhum ingest manual.
- **Temporal:** cada sync completo appenda um tick REAL na chain AURG-4D com `snapshot_hash` derivado do estado (id+content_hash; estado igual ⇒ hash igual). `atlas:aurg:status` mostra chain intacta + deltas de crescimento honestos.

## Números do finalize (F5, dev pgsql real, 2026-06-09)

- Migrations: `create_atlas_aurg_graph_tables` [batch 16] + `add_embedding_to_atlas_memory_tables` [batch 17] Ran.
- Ingest vivo: memory 2 (dev tem exatamente 2 entries vivas — honesto), code 27 (2 workspaces + 25 módulos), domains 21+198, evidence 0 (tabela existe, 0 linhas), strategic 0 (0 linhas). Totais: **50 nós / 225 arestas / 44 provider-safe / 6 sensíveis**, 88ms, tick gravado.
- Query cross-layer real: `module→belongs_to→workspace→belongs_to→domain:engineering` (1.0/1.0), ranking `python_graph_rank`.
- Prova de prompt vivo: sentinel via registry → 6 chains no prompt do `AiPromptBuilder` real → limpeza `REMAINING=0`.
- Bateria: **252 testes / 2.122 assertions verdes** (injection 44; Context 114; registry 43; Unit/Reality 11; Feature/Reality 22; Memory 18).

## Limites conhecidos (honestos)

1. **Evidence/strategic vazios no dev** — o ledger e o ASRE deste banco têm 0 linhas hoje; os gathers estão prontos e testados com fixtures, mas nunca rodaram contra volume real.
2. **Memória magra no dev** (2 entries "t" sem tags/paths) ⇒ zero links memory→code/domain em dado real até memória real acumular via ingest-on-write.
3. **Semantic seeds = pgsql only** — no sqlite o score-map vem vazio e o seeding degrada para lexical (declarado, nunca falsificado).
4. **Truncamento do budget continua tail-cut** — o bloco do cérebro agora sobrevive, mas `## Atlas Memory Recall` (R4) ainda morre em seção >20k (buraco PRÉ-EXISTENTE do R4, fora do escopo F5; chip de follow-up aberto).
5. **Linker memory→code casa o PRIMEIRO módulo por ordem de id** quando vários roots prefixam o mesmo path (ex.: `app/Services` venceu `app/Services/Ai`) — citação real, mas não a mais específica.
6. **Cérebro ≠ busca** — ele responde "o que conecta X entre camadas", não "onde está X" (isso é dos read-models/code-graph).
