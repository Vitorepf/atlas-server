---
title: AP-813 Atlas Compression Layer — CacheAligner + CCR-over-Evidence-Ledger + SmartCrusher
status: proposed
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

1. Implementar os 5 itens P0 (PHP, flag OFF), `dangerouslyDisableSandbox` não aplicável.
2. Provar com testes focados (inclui inércia flag-off) + uma medição real de ratio em
   tool-output do próprio Atlas (sem over-claim).
3. Review do operador → flip de flag (.env) → medir em execução real.
