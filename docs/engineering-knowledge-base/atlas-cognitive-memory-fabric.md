---
id: atlas-cognitive-memory-fabric
type: engineering_knowledge
title: Atlas Cognitive Memory Fabric
status: active
implementation_state: runtime_surface_cognitive_memory_ready
blocker: Cache fisico persistente ainda depende de ACCR/ATER; runtime ACMF read-only ja calcula budget, modo, working set, delta e spillover preservando 3GB.
category: intelligence-runtime
priority: 98
summary: Doc filha AUCRI para usar RAM como cognitive working memory: reduzir tokens, melhorar selecao de contexto, sustentar sessoes longas e preservar 3GB minimos livres para o usuario.
tags: [atlas-ai, aucri, acmf, ram, working-memory, context-cache]
capabilities: [cognitive_working_memory, hot_context_cache, memory_pressure_governance, context_delta, spillover]
decisions:
  - ACMF usa RAM para qualidade de contexto, nao apenas velocidade.
  - AMPG governa qualquer crescimento de cache e preserva no minimo 3GB livres.
  - Must-keep ledger nunca e removido; cache reconstruivel e o primeiro a sair.
maintenance:
  - Atualizar antes de criar cache RAM, prewarming, delta engine ou resident graph.
related_paths:
  - docs/engineering-knowledge-base/atlas-unified-context-retrieval-intelligence.md
  - docs/engineering-knowledge-base/atlas-runtime-efficiency-governor.md
  - docs/engineering-knowledge-base/atlas-persistent-context-runtime.md
  - docs/engineering-knowledge-base/atlas-context-intelligence-engine.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - app/Services/Ai/Context/AtlasCognitiveMemoryFabricService.php
  - app/Console/Commands/AtlasCognitiveMemoryFabricCommand.php
  - tests/Feature/Ai/Context/CognitiveMemoryFabricTest.php
doc_schema: atlas_canonical_module_doc.v1
macro_layer: true
product_name: Atlas Cognitive Memory Fabric
runtime_acronym: ACMF
internal_product_name: Atlas Hot Context Fabric
technical_runtime: AtlasCognitiveMemoryFabricService
graph_id: atlas-cognitive-memory-fabric
graph_title: Atlas Cognitive Memory Fabric
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-unified-context-retrieval-intelligence
graph_status: planned
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-cognitive-memory-fabric.md
allowed_changes:
  - Definir services, policies, memory tiers, RAM budgets, spillover e tests.
forbidden_changes:
  - Criar cache RAM sem Memory Pressure Governor.
  - Deixar o sistema abaixo de 3GB de RAM disponivel/reclamavel segura.
  - Manter dado sensivel em RAM sem privacy/trust policy.
depends_on: [atlas-unified-context-retrieval-intelligence, atlas-runtime-efficiency-governor]
flows_to: [atlas-context-ranking-system, atlas-context-freshness-quality-gate, atlas-context-observability-plane]
unlocks: [hot_context_fabric, token_reduction, long_horizon_working_memory]
governs: [ram_context_cache, context_prewarming, context_delta, memory_spillover]
evidence:
  - docs/engineering-knowledge-base/atlas-cognitive-memory-fabric.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:context:cognitive-memory --json"
  - "php artisan test tests/Feature/Ai/Context/CognitiveMemoryFabricTest.php"
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Implementar AMPG primeiro, depois working memory e hot context cache.
---

# Atlas Cognitive Memory Fabric

## Resumo

ACMF e o bloco 15 da AUCRI. Ele usa RAM como memoria operacional cognitiva para
reduzir tokens, evitar contexto repetido, melhorar selecao de fontes e sustentar
trabalhos longos sem depender da memoria de um chat ou provider.

## Papel no Atlas

O papel do ACMF nao e apenas responder mais rapido. O papel e manter o estado
vivo da tarefa em uma working memory governada, para que Atlas Dev, Forge,
Research, Finance e outros flows recebam contexto menor, melhor e mais estavel.

## Onde Se Encaixa

```text
AUCRI retrieval/graph/quality
  -> ACMF working memory RAM
  -> context pack menor e melhor
  -> LLM/provider
```

ACMF fica depois de retrieval/ranking e antes da montagem final do context pack.
AMPG, dentro do ACMF, manda sobre qualquer uso de RAM.

## Contratos

- `atlas.cognitive_memory.budget.v1`
- `atlas.cognitive_memory.working_set.v1`
- `atlas.cognitive_memory.hot_context_item.v1`
- `atlas.cognitive_memory.delta_receipt.v1`
- `atlas.cognitive_memory.spillover_receipt.v1`
- `atlas.cognitive_memory.pressure_event.v1`

Campos minimos: `scope_type`, `scope_id`, `mode`, `ram_budget_bytes`,
`safe_reserve_min_bytes`, `safe_reserve_target_bytes`, `items_kept`,
`items_evicted`, `must_keep_refs`, `token_savings_estimate`, `receipt_hash`.

## Fluxo

1. AMPG le memoria fisica, disponivel, swap, CPU, bateria e processos pesados.
2. Define modo: `emergency_trim`, `minimal`, `balanced`, `performance`,
   `deep_work`.
3. AWSM define o working set da tarefa atual.
4. ACHR calcula heat score de cada item.
5. ACWM mantem must-keep e contexto ativo.
6. AHCC guarda context packs, chunks, refs e summaries quentes.
7. ACDE calcula delta para nao reenviar contexto repetido.
8. ASMS faz spillover para disco quando RAM aperta.

## Regras para IA

- Nao usar ACMF para encher RAM com cache recente sem valor cognitivo.
- Nao remover decisions, blockers, constraints, DoD ou receipts ativos.
- Nao fazer prewarming quando swap estiver alto ou RAM disponivel abaixo de 6GB.
- Abaixo de 3GB disponiveis, executar `emergency_trim`.
- Toda economia de token deve preservar sufficiency, authority e freshness.

## Escopo de Implementacao

Sub-blocos obrigatorios cobertos pelo runtime read-only:

- ACWM: Atlas Cognitive Working Memory.
- AMPG: Atlas Memory Pressure Governor.
- AHCC: Atlas Hot Context Cache.
- AWSM: Atlas Working Set Model.
- ACPE: Atlas Context Prewarming Engine.
- ACDE: Atlas Context Delta Engine.
- ARRI: Atlas RAM-Resident Retrieval Index.
- ARCG: Atlas RAM Causal Graph.
- ACHR: Atlas Context Heat Ranker.
- ASMS: Atlas Snapshot & Memory Spillover.

Orcamento inicial para MacBook 48GB:

- `minimal`: 256MB-1GB.
- `balanced`: 2GB-4GB.
- `performance`: 4GB-8GB.
- `deep_work`: 8GB-14GB.
- reserva absoluta do usuario: 3GB.
- reserva alvo confortavel: 6GB.

## Dependencias

Depende de AUCRI para retrieval, AREG para governanca cognitiva, APCR para
persistencia de contexto, ACIE para sufficiency e ARPTL para privacy/trust.

## Evidencias

Evidencia atual:

- `atlas:context:cognitive-memory --json` com modo de RAM atual;
- teste que bloqueia crescimento abaixo de 3GB livres;
- teste que remove cache reconstruivel antes de must-keep;
- delta receipt provando reducao de contexto repetido;
- spillover receipt com hashes e refs preservados.

## Riscos

- Otimizar velocidade sem reduzir token.
- Cache quente virar fonte de verdade.
- RAM competir com Docker, Xcode, Claude, Codex ou navegador.
- Prewarming especulativo consumir recursos quando o sistema esta pressionado.
- Privacy leak por dado sensivel mantido em RAM.

## Exemplos

Atlas Dev em repo ativo mantem arquivos tocados, testes relevantes, blockers e
decisoes em ACWM. Na proxima rodada, ACDE envia so o delta e refs necessarias.
Se swap subir, ASMS derruba prewarming e AHCC, mas preserva must-keep ledger.

Atlas Forge em Obra longa mantem milestones, SDD, work packets, riscos e
outcomes quentes. O LLM recebe contexto menor, mas com a cadeia causal correta.

## Proximas Acoes

1. Conectar ACMF ao ACCR/ATER.
2. Promover budget ACMF para leitura do Control Plane.
3. Implementar cache fisico somente depois do compiler.
4. Preservar tests de 3GB, must-keep, delta e spillover.
5. Integrar primeiro com Atlas Dev e Forge.
