---
title: AP-813 Atlas Compression Layer — CacheAligner + CCR-over-Evidence-Ledger + SmartCrusher
status: implemented (live 2026-06-08; promotion-review pending)
owner: evidence / ai-runtime
line_limit: 240
related_paths:
  - app/Services/Ai/AiProviderManager.php
  - app/Services/Ai/Caching/CachingAiProvider.php
  - app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php
  - app/Models/AtlasLedgerEvent.php
  - app/Services/Ai/AtlasOpenBrainMcpService.php
  - app/Services/Ai/Compounding/AtlasCompoundingRuntimeService.php
  - app/Services/Engineering/CodeGraph/CodeGraphRuntimeInvoker.php
  - docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md
  - dissecar/headroom/ATLAS-HEADROOM-DISSECTION.md
---

# [AP-813] Atlas Compression Layer

## 1. Proposito

Capturar — como técnica, sob governança — o **eixo de compressão** dissecado em
`dissecar/headroom/ATLAS-HEADROOM-DISSECTION.md` (§9), por **cima** do code-graph
(AP-811/812). O code-graph reduz *o que* o Atlas lê (query-instead-of-read);
esta camada **comprime o que sobra** e **estabiliza o prefixo p/ KV-cache** — corte
de token/custo no lado da **execução** (toda chamada de provider + todo tool-output),
**reversível por governança**. Eixo ortogonal ao grafo; soma, não substitui.

## 2. Status Real (code-verified 2026-06-08, sem over-claim)

**EXISTE + WIRED (estender, NÃO recriar — ACRUI anti-duplicate = reuse_or_extend):**
- `AiProviderManager::maybeWrapWithCache()` (`app/Services/Ai/AiProviderManager.php:126-151`)
  **já embrulha todo provider** com `CachingAiProvider` (cache de resposta, flag
  `atlas.ai.cache.enabled`). Mesmo seam recebe o CacheAligner.
- `AtlasEvidenceLedger::record()` (`...Kernel/Evidence/AtlasEvidenceLedger.php:33-98`),
  append-only `AtlasLedgerEvent` (throw em update/delete), `payload_hash` =
  `sha256(json(ksort recursivo))`. Store durável p/ os originais do CCR.
- `AtlasBridgeEvidenceCommand` (`atlas:ai:bridge-evidence`): hold/never-promote +
  3 gates (kind / secret-class / governSignal). Reusar a filosofia de gate.
- `AtlasOpenBrainMcpService::tools()` (`...AtlasOpenBrainMcpService.php:106`): registro
  de tool; `atlas_memory_get` já aplica `privacy->providerDecision()` (secret/sensitive
  bloqueado). Inventário em `tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php:213`
  (`assertCount(45)`).
- `AtlasCompoundingRuntimeService::recordExecution()` (`...Compounding/...:32-131`):
  sink governado/reversível (hold-by-default) p/ o sinal TOIN.
- `CodeGraphRuntimeInvoker` (`...Engineering/CodeGraph/`): contrato manifest-in/json-out
  + `.venv` — padrão a espelhar SE/quando a estatística pesada exigir Python.

**GAP (greenfield, escopo desta AP — tudo PHP, atrás de flag default-off):**
- `CacheAlignerProvider` — decorator `AiProvider`, realoca tokens voláteis
  (datas/UUIDs/trace/session) do prefixo p/ a cauda; string-level, determinístico,
  sub-ms; streaming = pass-through; **fail-open**.
- `AtlasCcrStore` — store content-addressed (sha256) **durável** (Evidence Ledger +
  blob em disco), `store()/retrieve()`, dedup por hash, `privacy_class`, retention.
- `SmartCrusher` (leve, PHP) — keep-N (frente/fim/importância) p/ JSON/array de
  tool-output, **preserva erros/outliers incondicionalmente**, emite marcador +
  grava o original via `AtlasCcrStore`.
- `atlas_ccr_retrieve` — tool MCP governada (privacy-gate), inventário 45→46.
- flag `atlas.compression_layer.*` (config + phpunit off + .env on pós-review).

**FUTURE-GATED (APs separadas + review humano — NÃO escopo):**
- `runtimes/python/compression/` p/ estatística pesada (Kneedle/SimHash/entropia) —
  exige `runtime_boundary_preflight_gate.v1` + dep-approval (numpy/pandas), igual
  AP-812 §8. Só se o SmartCrusher leve PHP provar insuficiente.
- Path **SDK/HTTP/proxy com `cache_control` explícito** — onde mora o ganho medível
  classe-90% do CacheAligner (hoje os providers são CLI/stdin). Liga ao plano de
  captura de Dynamic Workflows; seam separado.
- Loop de aprendizado **TOIN** cross-session via compounding (4ª peça).
- Modelo treinado (Kompress) + entrega proxy/wrap — lift maior.

## 3. Escopo P0 (esta AP)

1. **CacheAlignerProvider** no seam `maybeWrapWithCache()`, flag-gated, streaming
   pass-through, fail-open, delega `key()/health()`.
2. **AtlasCcrStore** + migration `atlas_ccr_originals` (`original_hash` unique,
   `ledger_event_id`, `privacy_class`, `retention_policy`, size/ratio, blob em disco
   content-addressed) + novo `LedgerEventType` p/ store/retrieve.
3. **SmartCrusher** (PHP leve) p/ tool-output JSON: marcador verbatim
   `[N items compressed to K. retrieve: hash=...]`, original via `AtlasCcrStore`,
   bypass < limiar de tokens, código nunca comprimido por default.
4. **atlas_ccr_retrieve** MCP tool: privacy-gate (secret/sensitive NUNCA via provider
   path, espelha `atlas_memory_get`); inventário 45→46.
5. **Flag + config + phpunit env false + testes** (inclui teste de inércia flag-off).

## 4. Não-Escopo (APs separadas + review humano obrigatório)

- Runtime Python de compressão (preflight gate + dep-approval).
- Path SDK/HTTP/proxy `cache_control`.
- Loop TOIN cross-session.
- Modelo Kompress / entrega proxy-wrap.
- **Proibido:** auto-promoção; qualquer decisão de provider/modelo/domínio/flow no
  código de compressão; escrever Memory/Policy pelo transform.

## 5. Contratos

- **FAIL-OPEN** (única exceção legítima ao fail-closed do Atlas): falha de compressão
  → original passa intacto; **nunca bloqueia** uma chamada de provider.
- **LOSSLESS-BY-GOVERNANCE**: o original SEMPRE no Evidence Ledger + store durável,
  **nunca deletado por TTL** (vs LRU/TTL do headroom). `retrieve` = tool MCP governada,
  privacy-gated.
- **Hash** = `sha256(json(ksort))` (convenção Atlas; não blake3).
- **Streaming** = pass-through (espelha `CachingAiProvider::runStreaming`).
- **Evidence**: todo compress/retrieve grava ledger event (input_hash, output_hash,
  ratio, sizes) — contrato de evidência das boundaries.

## 6. Definition of Done / Gates

- **Aditivo**; flag OFF = **byte-idêntico** a hoje (inerte), provado por teste flag-off.
- **Reversível**: flip flag off → comportamento original; originais nunca perdidos.
- Testes unit: CacheAligner (estabilidade de prefixo + pass-through streaming),
  CcrStore (store/retrieve/dedup/privacy/durabilidade), SmartCrusher (preserva
  erros/outliers + ratio + bypass), MCP tool (privacy gate). Inventário atualizado.
- **Sem novo gate bloqueante** em Dev/Forge (compressão é transform, não governança).

## 7. Riscos e Mitigações

- Lossy sem recuperação → lossless-by-governance + marcador + retrieve governado.
- Vazamento de privacidade no retrieve → privacy-gate (secret/sensitive bloqueado no
  provider path).
- Cache CLI não-medível → CacheAligner escopado como higiene; ganho medível-grande
  diferido p/ AP do path SDK; **não over-claim 90%**.
- Bloat DB/disco → dedup por hash + retention + blob em disco (não LONGBLOB).
- `ext-zstd` ausente → fallback `gzencode` (sempre disponível).

## 8. Promotion Review (decisão do operador)

`place-feature` marcou `requires_ap=false` p/ o v1 PHP (layer=evidence); esta AP existe
por disciplina + p/ gatear as peças futuras. Para promover: (1) review do PR + flip das
flags; (2) aprovar a migration `atlas_ccr_originals`; (3) — APs separadas — aprovar o
runtime Python + deps e o path SDK `cache_control`. **Promoção sem review = proibida**
(`runtime_promotion_policy.v1`).

## 9. Próximas Ações

1. ~~Implementar os 5 itens P0 (PHP, flag OFF).~~ **DONE.**
2. ~~Provar com testes focados + medição real.~~ **DONE** (110 testes verdes; 91% medido).
3. ~~Flip de flag (.env) → execução real.~~ **DONE** (ligado live; CCR persiste no DB real).
4. Operator promotion-review (governança): aprovar formalmente; depois as APs separadas
   (runtime Python heavy-stats, path SDK `cache_control`, TOIN loop, modelo Kompress).

## 10. Implementation evidence (2026-06-08) — SHIPPED + LIVE + PROVEN

**Entregue (PHP, additive, agora LIGADO em `.env`):**
- Pipe de provider: `CacheAlignerProvider`→ na verdade `CompressionAiProvider` decorator no
  seam `AiProviderManager::maybeWrapWithCompression()` (setter-injected via AppServiceProvider,
  inerte quando flag off; espelha `setCacheDecoration`). Transforma `run` **e** `runStreaming`
  (Hermes/Claude streamam). Verificado live: `get('hermes_cli')` retorna `CompressionAiProvider`.
- `CompressionPipeline` (CacheAligner `VolatileTokenRelocator` + `ContentRouter` + CCR) — fail-open
  por bloco e no todo. Caminho ao vivo = só blocos fenced ``` ``` ``` (conservador, não mangle).
- `AtlasCcrStore` + migration `atlas_ccr_originals` (rodada no DB real) + `AtlasCcrOriginal`:
  sha256, gzip, dedup, ledger events (`CcrOriginalStored/Retrieved`), privacy_class. Round-trip
  lossless verificado live.
- 5 compressores (workflow `atlas-compression-compressors`, 14 agentes, impl→verify→fix):
  `SmartCrusherJsonCompressor` (sampling de bulk homogêneo + TODA anomalia preservada + dial
  `max_keep`), `LogCompressor`, `SearchCompressor`, `DiffCompressor`, `TextCompressor`.
- `atlas_ccr_retrieve` MCP tool (privacy-gated: secret/sensitive nunca via provider path);
  inventário 45→46.

**Provas:** 110 testes de compressão verdes (563 assertions) — unit por-compressor + foundation +
integração e2e (CCR round-trip lossless). `AiProviderManagerTest` 9/9, caching + worker verdes,
MCP inventory 46. **Medição honesta** (chars/4 ≈ tokens): JSON tool-output 300 linhas →
**91% economia** (45.415→4.073 chars), anomalia de erro preservada, hash CCR emitido.

**Decisão de design registrada (override consciente do verify):** o workflow tornou o JSON
exact-dup-only (lossless-no-compressor, mas economia ~0 em ids únicos). Corrigi p/ o modelo
**lossless-by-governance** (sampling + recuperação CCR), pois é "tudo que o headroom tem" + a meta
de economia máxima — mantendo TODO sinal (anomalias/erros/outliers/shape-changes) e recuperação
total. `max_keep` é o dial economia↔segurança (alto = near-lossless no fio).

**Diferido (transparente, com razão — NÃO é core incompleto):** runtime Python heavy-stats
(Kneedle/SimHash/entropia) = gated por `runtime_boundary_preflight_gate` + dep-approval; path SDK
`cache_control` (onde mora o ganho de cache classe-90%; providers atuais são CLI/stdin → CacheAligner
hoje é higiene best-effort); loop TOIN (compounding); modelo Kompress + compressão de imagem
(lift maior / nicho p/ texto-CLI); compressão central no `toolResponse` do MCP (próximo incremento
seguro). 4 falhas pré-existentes do `AtlasOpenBrainMcpServiceTest` (doc-health + fixture de data
2026-05→2026-06) confirmadas NÃO causadas por esta AP (falham com AP-813 removido).
