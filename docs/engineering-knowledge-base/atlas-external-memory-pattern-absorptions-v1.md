---
id: atlas-external-memory-pattern-absorptions-v1
type: engineering_knowledge
title: Atlas External Memory Pattern Absorptions v1
status: building
implementation_state: planned_absorption_roadmap_no_code_yet
blocker: Quatro absorcoes mapeadas a partir das dissecacoes claude-mem, engram e mem0; cada absorcao precisa de seu proprio AP antes de virar codigo, schema canon e teste.
category: architecture
priority: 88
summary: Doc mae das quatro absorcoes selecionadas das dissecacoes claude-mem, engram e mem0 que entram no Atlas (integer ID mapping anti-halucinacao, seis verbos canonicos de conflito de memoria, doctor + repair modes 3-tier, e progressive disclosure 3-layer no MCP). Resto das ideias dissecadas foi rejeitado por violar governance, duplicar capability ja existente ou ser decisao de distribuicao de produto.
tags: [atlas-ai, memory, context, absorption, external-patterns, mcp, doctor, anti-hallucination, conflict-relations]
capabilities:
  - integer_id_mapping_anti_hallucination
  - memory_relation_canonical_verbs
  - command_doctor_three_tier_modes
  - mcp_progressive_disclosure_three_layer
decisions:
  - Absorver quatro padroes selecionados dos tres sistemas dissecados; rejeitar captura passiva (viola G0 cognitive immune), compressao LLM via 2o observador (viola immune), single Go binary (conflito arquitetural), 27 vector stores (Atlas decidiu pgvector), TUI Bubbletea (Atlas tem Mac native), Mode system pluggable (Atlas Domains ja cobrem), MD5 dedup (Atlas tem SHA256), procedural memory (Atlas tem continuity.active_state).
  - Doc mae agrupa as quatro absorcoes em status `building`. Quando cada uma shipar, doc dono original (causal_graph_lite, ACPFR, open-brain MCP, command pattern Atlas) absorve a capability e esta doc remove-a do roadmap.
  - Nenhuma absorcao destrava external_rivals_certification, benchmark, claim de superioridade ou auto-aplicacao de learning. claim_policy: provider_safe_only=true, raw_capture_passive=false, llm_compression_observer=false.
  - Cada absorcao requer AP proprio (placement, spec, plan, receipt, verify) antes de codigo. Sem AP, fica somente neste doc como roadmap canon.
maintenance:
  - Atualizar `implementation_state` por absorcao quando AP shipar.
  - Quando absorcao virar codigo, mover capability para doc dono e remover daqui.
  - Esta doc desaparece quando as quatro absorcoes shiparem; substituida pelo authority-map atualizado.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md
  - docs/engineering-knowledge-base/atlas-context-pareto-frontier-runtime.md
  - docs/engineering-knowledge-base/atlas-retrieval-cost-latency-governor.md
  - docs/engineering-knowledge-base/memory/contracts.md
  - docs/engineering-knowledge-base/memory/cognitive-immune-learning-kernel.md
  - docs/engineering-knowledge-base/atlas-long-horizon-intelligence-layer.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/canonical-index/authority-map.md
  - dissecar/claude-mem/13-ideias-portaveis-atlas.md
  - dissecar/engram/13-ideias-portaveis-atlas.md
  - dissecar/mem0/13-ideias-portaveis-atlas.md
  - dissecar/mem0/14-comparacao-tripla.md
  - dissecar/engram/14-comparacao-claude-mem-engram.md
doc_schema: atlas_canonical_module_doc.v1
macro_layer: false
product_name: Atlas External Memory Pattern Absorptions
runtime_acronym: AEMPA
internal_product_name: Atlas External Memory Pattern Absorptions
technical_runtime: AtlasExternalPatternAbsorptionRoadmapService
graph_id: atlas-external-memory-pattern-absorptions-v1
graph_title: Atlas External Memory Pattern Absorptions v1
graph_world: atlas
graph_layer: flow
graph_kind: module
graph_parent: atlas-ai-memory-context-core-open-brain
graph_status: planned
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-external-memory-pattern-absorptions-v1.md
allowed_changes:
  - Atualizar `implementation_state` por absorcao quando AP correspondente shipar.
  - Adicionar referencia ao doc dono quando capability migrar para canon definitivo.
  - Registrar decisao de cancelar absorcao especifica com rationale.
forbidden_changes:
  - Promover absorcao para `active`/`available` sem AP shipado, codigo, schema canon, testes verdes e doc dono atualizado.
  - Adicionar quinta absorcao sem nova auditoria de governance (cognitive immune, sobreposicao com canon existente).
  - Tratar este doc como autoridade unica de uma capability que ja tem doc dono.
  - Documentar absorcao que viola G0-G8 promotion gates ou cognitive immune law.
  - Permitir absorcao que destrava external_rivals_certification.
depends_on: [atlas-ai-memory-context-core-open-brain, memory-cognitive-immune-learning-kernel, atlas-long-horizon-intelligence-layer]
flows_to: [atlas-context-pareto-frontier-runtime, atlas-retrieval-cost-latency-governor, memory-contracts]
unlocks: [anti_hallucination_id_mapping, canonical_relation_verbs, three_tier_doctor_modes, progressive_disclosure_mcp]
governs: [external_pattern_absorption_roadmap]
evidence:
  - docs/engineering-knowledge-base/atlas-external-memory-pattern-absorptions-v1.md
  - dissecar/claude-mem/13-ideias-portaveis-atlas.md
  - dissecar/engram/13-ideias-portaveis-atlas.md
  - dissecar/mem0/13-ideias-portaveis-atlas.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: medium
line_limit: 520
next_actions:
  - Abrir AP para Absorcao 1 (Integer ID Mapping) com escopo em AiContextPackBuilder, ProviderPromptProjection e Open Brain MCP serializer.
  - Abrir AP para Absorcao 2 (Conflict Verbs) com schema enum em atlas_memory_entry_relations.relation, judge method em AtlasMemoryRegistryService, comando atlas:memory:judge, integracao com TEOS-I2 causal_graph_lite edge_kind.
  - Abrir AP para Absorcao 3 (Doctor 3-Tier) com refactor base de Command pattern Atlas Console e adocao incremental.
  - Abrir AP para Absorcao 4 (Progressive Disclosure MCP) com redesign Open Brain MCP em tres tiers explicitos custo-crescente e wiring a ACPFR/ARCLG.
claim_policy:
  benchmark_claim_allowed: false
  rivals_claim_allowed: false
  superiority_claim_allowed: false
  external_rivals_certification_touched: false
  raw_capture_passive_allowed: false
  llm_compression_observer_allowed: false
  auto_apply_learning_allowed: false
---

# Atlas External Memory Pattern Absorptions v1

## Resumo

Doc mae que registra quatro absorcoes selecionadas das dissecacoes externas
claude-mem, engram e mem0 que entram no Atlas. Cada absorcao tem origem
explicita, gap real no Atlas, ponto de docking canon, esforco estimado e
caveat. Resto das aproximadamente trinta ideias mapeadas nas dissecacoes
foi rejeitado por violar cognitive immune law, duplicar capability ja
existente em Atlas, ou ser decisao estrategica de distribuicao em vez de
absorcao tecnica.

## Papel no Atlas

Atlas e conceitualmente mais avancado que os tres sistemas dissecados:
governanca em doze camadas, G0-G8 promotion gates, hierarquia de autoridade
explicita, AWIS como primitivo de workspace, Evidence Ledger append-only,
APCR/AEMOR como par operacional. Mesmo assim, leitura integral dos quarenta
e cinco docs dissecados revelou quatro padroes que Atlas hoje nao cobre
formalmente e que valem absorver. Esta doc registra a decisao consolidada
e o roadmap.

## Onde Se Encaixa

```text
Doc mae (este)
  -> orquestra quatro absorcoes
  -> cada uma referencia doc dono canon existente
  -> quando absorcao shipa, doc dono incorpora capability
  -> esta doc desaparece quando todas shiparem
```

Pai canon: `atlas-ai-memory-context-core-open-brain.md`.
Irmaos canon referenciados: `atlas-context-pareto-frontier-runtime.md`,
`atlas-retrieval-cost-latency-governor.md`, `memory/contracts.md`,
`memory/cognitive-immune-learning-kernel.md`,
`atlas-long-horizon-intelligence-layer.md`.

## Contratos

### Absorcao 1 — Integer ID Mapping Anti-Halucinacao

- **Fonte canonica**: `dissecar/mem0/02-algoritmo-memoria.md` + `dissecar/mem0/13-ideias-portaveis-atlas.md` (item Anti-hallucination ID mapping).
- **Tese**: LLM ve `[0]`, `[1]`, `[2]` em vez de UUIDs reais nos prompts. Reduz alucinacao porque inteiros sequenciais sao impossiveis de inventar credivelmente.
- **Estado Atlas hoje**: `AiContextPack::toPromptSection()`, `ProviderPromptProjection`, Open Brain MCP serializer e demais surfaces injetam UUIDs canonicos direto no prompt. LLM ve `uuid-abc-123-def-...` e pode alucinar UUID parecido que nao existe.
- **Estado alvo**: mapping interno `internal_id -> real_uuid` que vive somente no processo Atlas que monta o prompt. Provider recebe somente `internal_id` (inteiro sequencial ou hash curto de 6 chars). Parser reverso le `[N]` do response e remapa para UUID real antes de qualquer persistencia (Decision Receipt, Evidence Ledger, AiMemoryDelta).
- **Schema canon novo**: `atlas.context.id_remap.v1` com campos `mapping_hash` (sha256 deterministico), `internal_to_real` (mapa), `real_to_internal` (inverso para parser), `created_at`, `scope` (context_pack_id), `provider_safe: true`.
- **Pontos de docking**:
  - `AiContextPackBuilder` aplica remap antes de `toPromptSection()`.
  - `ProviderPromptProjection` injeta tabela de remap no rendered_prompt_text (zona cacheable).
  - `AtlasOpenBrainService::contextPack()` retorna context_pack ja remapeado.
  - `AtlasOpenBrainMcpService` aplica remap em todas tools que retornam UUIDs.
  - Parser reverso obrigatorio antes de `DecisionReceiptService::record()`, `AtlasEvidenceLedger::record()` e `AiMemoryDelta` persist.
- **Caveat critico**: parser reverso e ponto unico de falha. Sem ele, audit trail quebra. Teste obrigatorio: round-trip UUID -> internal_id -> UUID assert equal.
- **Esforco**: 1-2 dias core + testes de round-trip + integracao em parsers existentes.
- **Status**: planned.

### Absorcao 2 — Seis Verbos Canonicos de Conflito de Memoria

- **Fonte canonica**: `dissecar/engram/11-conflict-resolution.md` + `dissecar/engram/03-mcp-tools-19.md` (mem_judge, mem_compare) + `dissecar/engram/13-ideias-portaveis-atlas.md` (item conflict verbs).
- **Tese**: agente salva "Auth via JWT" hoje, "Auth via sessions" amanha. Sem semantica explicita de relacao, search retorna ambos contradizendo. Os seis verbos resolvem isso com auditoria multi-actor.
- **Verbos canonicos** (enum `relation`):
  - `related` — temas conectados, sem conflito.
  - `compatible` — coexistem sem contradicao.
  - `scoped` — verdade em escopos diferentes.
  - `conflicts_with` — contradizem; ambos visiveis em search com flag.
  - `supersedes` — uma substitui outra; superseded aparece com `superseded_by`.
  - `not_conflict` — auditoria explicita de que nao ha conflito (apenas audit, nao insere row).
- **Estado Atlas hoje**: tabela `atlas_memory_entry_relations` existe com colunas `source_id`/`target_id`, mas campo `relation` e string livre ou tipo generico. Nao ha enum tipado, nao ha semantica em search, nao ha multi-actor disagreement formal.
- **Estado alvo**: `relation` vira enum com os seis verbos. Sem UNIQUE em `(source_id, target_id)` para permitir agente A e humano B discordarem. Search exibe `supersedes`/`conflicts_with` com flag visual. `mem_judge` resolve pending; `mem_compare` faz auditoria proativa.
- **Schema canon novo**: estende `atlas.long_horizon.causal_graph_lite.v1` (TEOS-I2) com `edge_kind` enum dos seis verbos. Novo schema `atlas.memory.relation_verdict.v1` com campos `verdict` (enum), `confidence` (0.0-1.0), `marked_by_actor` (agent|engram|human|atlas), `marked_by_model`, `reason`, `judgment_status` (pending|judged|orphaned|ignored), `evidence_refs`.
- **Pontos de docking**:
  - Migration adiciona enum em `atlas_memory_entry_relations.relation`.
  - `AtlasMemoryRegistryService` ganha `judge(source_id, target_id, verdict, ...)` method.
  - Comando novo `atlas:memory:judge {--source --target --verdict --confidence --reason}`.
  - `AtlasMemoryContextComposer` usa `supersedes` para suprimir superseded e `conflicts_with` para marcar pares contradizendo.
  - `LongHorizonCausalDecisionGraphService` estende `edge_kind` com os seis verbos.
- **Caveat**: heuristica "ask vs silent" do engram (`confidence < 0.7` OU `verdict in {supersedes, conflicts_with}` E `memory_type in {decision, architecture, policy}` -> escalar para humano) deve ser portada para `AtlasMemoryRegistryService::shouldEscalate()` para evitar promotion silenciosa de conflito de alto risco.
- **Esforco**: 3-5 dias (migration + service + comando + integracao composer + integracao causal_graph_lite + testes).
- **Status**: planned. Ja registrado como porting candidate em `MEMORY.md` ([[project_atlas_teos_i2_causal_graph_lite]]).

### Absorcao 3 — Doctor + Repair Modes 3-Tier (Plan / Dry-Run / Apply)

- **Fonte canonica**: `dissecar/engram/12-doctor-diagnostics.md` + `dissecar/engram/13-ideias-portaveis-atlas.md` (item doctor pattern).
- **Tese**: comando mutativo nasce read-only. Tres modos canonicos:
  - `plan` — mostra exatamente o que faria sem tocar nada.
  - `dry-run` — executa em transacao com rollback obrigatorio no fim.
  - `apply` — executa de verdade. Exige `--check CODE` especifico + flag explicito.
- **Estado Atlas hoje**: existem comandos read-only bem feitos (`atlas:engineering:knowledge code-gate`, `atlas:ai:docs-authority-audit`, `atlas:ai:runtime-readiness --strict`). Existem comandos que mutam direto sem dry-run (varios `atlas:memory:*`, `atlas:vault sync`, `atlas:aemor:*`). O padrao tres-tier nao esta sistematizado.
- **Estado alvo**: padrao base de Command pattern Atlas Console. Cada comando mutativo recebe `--mode={plan|dry-run|apply}` (default `plan`). `apply` exige `--check CODE` matching uma das checks registradas + flag explicito tipo `--confirm` ou `--apply`.
- **Schema canon novo**: `atlas.command.three_tier_envelope.v1` com `mode` (enum), `check_code`, `plan_payload`, `dry_run_payload`, `apply_payload`, `rollback_ref`, `executed_at`, `provider_safe`.
- **Pontos de docking**:
  - Refactor `AtlasBaseConsoleCommand` (ou criar `AtlasMutativeCommand` abstrato) que materializa o tres-tier.
  - Sistematizar em areas alto risco primeiro: AEMOR promotion, Self-Improvement L7 ResultLedger, Vault sync, Memory delta promotion, Forge Obra mutations.
  - Cada subclasse declara `planActions()`, `dryRunActions()`, `applyActions()` e `checkCodes()`.
  - Audit trail registra `mode` usado em `atlas_ledger_events`.
- **Caveat**: refactor caro de retrofitar todos comandos antigos. Estrategia recomendada: impor padrao em comandos NOVOS e migrar antigos por janelas (alto risco primeiro). Documentar lista de comandos legacy ainda sem padrao.
- **Esforco**: 1-2 semanas para abstracao base + cinco comandos alto risco. Resto incremental.
- **Status**: planned.

### Absorcao 4 — Progressive Disclosure 3-Layer no MCP

- **Fonte canonica**: `dissecar/claude-mem/05-retrieval-3-layers.md` + `dissecar/claude-mem/09-mcp-21-tools.md` + `dissecar/claude-mem/13-ideias-portaveis-atlas.md` (item progressive disclosure).
- **Tese**: tres camadas custo-crescente:
  - `search` — lista IDs + metadata minima, aproximadamente 50 tokens por linha.
  - `timeline` — cronologia ao redor de anchor (depth_before + 1 + depth_after).
  - `get_full` — full payload por ID, aproximadamente 500-1000 tokens cada.
  Claim claude-mem: dez vezes economia de token versus full dump.
- **Estado Atlas hoje**: `AtlasOpenBrainMcpService` expoe trinta e oito tools, mas nao esta estruturado em tres tiers custo-crescente explicitos. Cada chamada pode trazer payload pesado (full memory entries, full evidence packs, full code symbols).
- **Estado alvo**: redesign Open Brain MCP em tres camadas explicitas:
  - **Tier 1 Discovery** (custo minimo, ~50 tokens/item): `atlas_memory_search_brief`, `atlas_code_search_brief`, `atlas_evidence_search_brief`, `atlas_docs_search_brief`. Retornam IDs + metadata minima.
  - **Tier 2 Context** (custo medio): `atlas_memory_timeline`, `atlas_evidence_timeline`, `atlas_decision_timeline`. Recebem anchor ID + depth e retornam cronologia provider-safe.
  - **Tier 3 Detail** (custo alto, sob demanda): `atlas_memory_get_full`, `atlas_evidence_get_full`, `atlas_code_get_full`. Retornam payload completo por ID. Batch obrigatorio (recebem lista de IDs).
- **Schema canon novo**: `atlas.mcp.tier.v1` com campos `tier` (1|2|3), `cost_class` (low|medium|high), `token_estimate`, `provider_safe`, `requires_anchor` (boolean). Cada tool MCP declara seu tier no manifest.
- **Pontos de docking**:
  - `AtlasOpenBrainMcpService` reorganiza tools em tiers e documenta no manifest MCP.
  - Tool `__IMPORTANT` (estilo claude-mem) explica workflow tier 1 -> tier 2 -> tier 3 ao cliente MCP.
  - ACPFR (`AtlasContextParetoFrontierRuntimeService`) usa tier como sinal de cost em utility function.
  - ARCLG (`AtlasRetrievalCostLatencyGovernorService`) tracks tier consumption por flow + budget.
- **Caveat**: tools legadas continuam funcionando (back-compat); novas chamadas devem preferir tiers. Documentar deprecation graceful.
- **Esforco**: 1-2 dias (organizacao logica, manifest update, doc do workflow). Reorganizacao, nao codigo pesado.
- **Status**: planned.

## Fluxo

```text
Doc mae (este) lista quatro absorcoes
  -> AP por absorcao (placement + spec + plan + receipt + verify)
  -> Codigo + schema + testes
  -> Doc dono canon atualizado (causal_graph_lite, ACPFR, open-brain, command pattern)
  -> Esta doc remove absorcao do roadmap
  -> Quando todas as quatro shiparem, esta doc e arquivada
```

## Regras para IA

- Nao implementar absorcao sem AP shipado.
- Nao tratar este doc como autoridade unica; cada absorcao tem doc dono futuro.
- Nao introduzir quinta absorcao sem nova auditoria de governance (cognitive immune law, sobreposicao canon existente, claim policy compliance).
- Nao copiar literal prompts ou estruturas dos sistemas dissecados; adaptar tecnicas para canon Atlas.
- Nao absorver captura passiva (viola G0), compressao LLM via 2o observador (viola immune), single Go binary (conflito arquitetural).
- Nao prometer ganhos numericos especificos (10x token saving, etc) sem AREBA golden-set evaluation pos-implementacao.
- Nao destravar external_rivals_certification.

## Escopo de Implementacao

Cada absorcao tem escopo proprio definido em Contratos acima. Doc mae nao
introduz codigo; somente registra decisao e roadmap. AP de cada absorcao
declara repo_paths exatos, allowed_changes, forbidden_changes,
required_tests e gates de qualidade.

## Dependencias

Depende de:
- `memory/cognitive-immune-learning-kernel.md` (G0-G8 nao violados).
- `atlas-ai-memory-context-core-open-brain.md` (Open Brain MCP e doc dono).
- `atlas-long-horizon-intelligence-layer.md` (causal_graph_lite e dono dos edge kinds).
- `atlas-context-pareto-frontier-runtime.md` (ACPFR consome tier).
- `atlas-retrieval-cost-latency-governor.md` (ARCLG tracks tier consumption).
- `memory/contracts.md` (schemas de memoria canon).

## Evidencias

- Dissecacoes integrais lidas: `dissecar/claude-mem/00-INDEX.md` ate `14-pegadinhas-debt.md`, `dissecar/engram/00-INDEX.md` ate `14-comparacao-claude-mem-engram.md`, `dissecar/mem0/00-INDEX.md` ate `14-comparacao-tripla.md` (quarenta e cinco docs).
- Triagem de absorcao registrada na conversa de design 2026-05-25.
- Esta doc captura a decisao final e roadmap.

## Riscos

- Risco 1: implementar absorcao sem AP -> viola governance, regressao silenciosa.
- Risco 2: copiar literal estrutura dos dissecados -> conflito com canon Atlas (memory_type enum, scope_type enum, privacy classes).
- Risco 3: parser reverso de Absorcao 1 quebrar -> audit trail corrompido. Mitigacao: round-trip test obrigatorio.
- Risco 4: enum de Absorcao 2 sem migration cuidadosa -> dados existentes em `atlas_memory_entry_relations.relation` ficam invalidos. Mitigacao: backfill plan no AP.
- Risco 5: refactor de Absorcao 3 quebrar comandos legacy -> mitigacao: rollout incremental por janelas.
- Risco 6: tiers de Absorcao 4 confundirem clientes MCP existentes -> mitigacao: back-compat + deprecation graceful documentado.

## Exemplos

### Absorcao 1 em pratica

Antes:
```text
Memory recall:
- uuid-abc-123-def-456 [decision][project] Auth via JWT
- uuid-xyz-789-aaa-bbb [decision][project] Auth via sessions
```

Depois:
```text
Memory recall:
- [0] [decision][project] Auth via JWT
- [1] [decision][project] Auth via sessions

(Atlas internal mapping. Refer to entries by [0], [1].)
```

Provider responde "supersede [1] with [0]"; parser remapa para
`uuid-abc-123-def-456 supersedes uuid-xyz-789-aaa-bbb` antes de gravar
Decision Receipt.

### Absorcao 2 em pratica

```text
atlas:memory:judge \
  --source=uuid-abc-123-def-456 \
  --target=uuid-xyz-789-aaa-bbb \
  --verdict=supersedes \
  --confidence=0.92 \
  --reason="Migracao para JWT em 2026-05-20 substitui sessions" \
  --evidence-refs=evidence-uuid-1,evidence-uuid-2
```

Search posterior por "auth" retorna:
```text
- [decision] Auth via JWT (active)
- [decision] Auth via sessions (superseded_by uuid-abc-123-def-456)
```

### Absorcao 3 em pratica

```text
# Plan (default, read-only)
atlas:vault:sync

# Dry-run (executa, transacao rollback)
atlas:vault:sync --mode=dry-run --check=vault-sync-readiness

# Apply (executa de verdade)
atlas:vault:sync --mode=apply --check=vault-sync-readiness --confirm
```

### Absorcao 4 em pratica

```text
# Tier 1 Discovery (~50 tokens/item, cheap)
atlas_memory_search_brief("auth jwt")
=> [{ id: "uuid-1", title: "Auth via JWT", type: "decision", ts: "..." }, ...]

# Tier 2 Context (depth around anchor)
atlas_memory_timeline(anchor="uuid-1", depth_before=3, depth_after=3)
=> cronologia de seis itens ao redor

# Tier 3 Detail (full payload, batch)
atlas_memory_get_full(ids=["uuid-1", "uuid-3"])
=> dois payloads completos com body + evidence_refs + metadata
```

## Proximas Acoes

1. Abrir AP Absorcao 1 (Integer ID Mapping) com escopo concreto: `AiContextPackBuilder::toPromptSection()`, `ProviderPromptProjection`, `AtlasOpenBrainService::contextPack()`, `AtlasOpenBrainMcpService`. Round-trip test obrigatorio.
2. Abrir AP Absorcao 2 (Conflict Verbs) com migration enum + `AtlasMemoryRegistryService::judge()` + comando `atlas:memory:judge` + integracao com `LongHorizonCausalDecisionGraphService` (TEOS-I2).
3. Abrir AP Absorcao 3 (Doctor 3-Tier) com `AtlasMutativeCommand` abstrato + rollout em cinco comandos alto risco primeiro (AEMOR promotion, Self-Improvement L7 ResultLedger, Vault sync, Memory delta promotion, Forge Obra mutations).
4. Abrir AP Absorcao 4 (Progressive Disclosure MCP) com reorganizacao Open Brain MCP em tres tiers explicitos + integracao com ACPFR + ARCLG.
5. Apos cada AP shipar, atualizar doc dono canon (causal_graph_lite, ACPFR, open-brain MCP, command pattern) e remover absorcao desta doc.
6. Apos as quatro absorcoes shiparem, arquivar esta doc e atualizar `canonical-index/authority-map.md`.
7. Rodar `php artisan atlas:engineering:knowledge sync --prune` e `php artisan atlas:engineering:knowledge docs-health --json` apos criar esta doc.
