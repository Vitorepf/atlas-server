---
id: atlas-long-horizon-replay-manifest
type: engineering_knowledge
title: Atlas Long-Horizon Replay Manifest
status: active
category: long-horizon
priority: 91
summary: Manifest provider-independent para retomar scopes long-horizon sem reabrir chat bruto.
tags:
  - atlas-ai
  - long-horizon
  - replay
capabilities:
  - long_horizon_replay_manifest
decisions:
  - Replay Manifest indexa refs canonicas e nunca invoca provider.
maintenance:
  - Atualizar quando continuation packs ou replay reader mudarem.
related_paths:
  - docs/engineering-knowledge-base/cognitive-runtime/runbook.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-long-horizon-replay-manifest
graph_title: Atlas Long-Horizon Replay Manifest
graph_world: atlas
graph_layer: module
graph_kind: contract
graph_parent: atlas-ai-cognitive-runtime
graph_status: active
graph_source: repo
human_name: Atlas Long-Horizon Replay Manifest
canonical_name: Atlas Long-Horizon Replay Manifest
technical_name: atlas-long-horizon-replay-manifest
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-long-horizon-replay-manifest.md
owner: long-horizon-runtime
repo_paths:
  - docs/engineering-knowledge-base/atlas-long-horizon-replay-manifest.md
allowed_changes:
  - Atualizar contrato com codigo e testes de replay manifest.
forbidden_changes:
  - Ler transcript bruto ou invocar provider para completar manifest.
depends_on:
  - atlas-ai-cognitive-runtime
flows_to:
  - atlas-code
unlocks:
  - provider-independent-resume
governs:
  - long-horizon-replay
evidence:
  - docs/engineering-knowledge-base/atlas-long-horizon-replay-manifest.md
required_tests:
  - "php artisan test tests/Feature/Ai/LongHorizon"
requires_evidence: true
risk_level: medium
next_actions:
  - Manter reader independente de provider e chat bruto.
---
# Atlas Long-Horizon Replay Manifest + Provider-Independent Reader

## Resumo

Manifest para retomada long-horizon baseada em refs canonicas.

## Papel no Atlas

Permitir que outra IA retome escopo sem transcript bruto.

## Onde Se Encaixa

Acima de TEOS-I1 continuation pack e compaction receipt.

## Contratos

Nao invoca provider, nao le chat bruto e usa hashes.

## Fluxo

Build, show, read e list de manifests.

## Regras para IA

Consumir bundle e safety notes antes de continuar.

## Escopo de Implementacao

Replay manifest e reader bundle.

## Dependencias

Continuation packs, compaction receipts e freshness gates.

## Evidencias

Manifest hash, bundle hash e evidence refs.

## Riscos

Perder contexto critico ou reabrir transcript cru.

## Exemplos

`php artisan atlas:long-horizon:replay-manifest read --manifest=<uuid> --json`.

## Proximas Acoes

Manter integridade e recovery queries sincronizadas.

**Status:** ativo · entregue 2026-05-19 · TEOS-I2 Sprint 1 entry
**Schema raiz:** `atlas.long_horizon.replay_manifest.v1` + `atlas.long_horizon.replay_reader_bundle.v1`
**Camada:** acima de TEOS-I1 (continuation pack + compaction receipt + freshness gate + recovery planner).

---

## Por que existe

TEOS-I1 entregou os primitivos: `continuation_pack.v2`, `compaction_receipt.v1`, freshness gate, recovery planner, memory promotion guard. Mas faltava uma forma canônica de **outra IA / outro provider** retomar uma scope sem precisar do chat bruto.

O Replay Manifest fecha esse gap: indexa, por scope, **o que é necessário** para retomar, **o que está disponível** localmente e **o que está faltando** — sem reabrir transcript.

## Contract — `atlas.long_horizon.replay_manifest.v1`

| Campo | O que é |
|---|---|
| `schema_version`              | `atlas.long_horizon.replay_manifest.v1` |
| `uuid` (`replay_manifest_id`) | UUID canônico do manifest |
| `scope_type`                  | `mission` / `work_order` / `obra` / `dev_run` / etc. (canon `AtlasLongHorizonCanon::ALLOWED_SCOPE_TYPES`) |
| `scope_id`                    | identificador da scope (UUID/string) |
| `continuation_pack_id`        | FK do `atlas_long_horizon_continuation_packs` consumido |
| `compaction_receipt_id`       | FK opcional do receipt consumido |
| `required_refs`               | refs que o reader precisa (derivadas do pack `context_manifest` + receipt `must_keep_items` + `recovery_queries`) |
| `available_refs`              | refs locais já satisfeitas (do pack `evidence_refs` + receipt `evidence_refs`/`retained_items`) |
| `missing_refs`                | `required − available` (recovery_queries sempre listadas aqui) |
| `event_refs`                  | rótulos canônicos de decisões/blockers/risks/contradições do pack+receipt |
| `evidence_refs`               | união normalizada dos evidence refs |
| `context_pack_hash`           | herdado do continuation pack — usado para verificar integridade |
| `reader_instructions`         | passo-a-passo determinístico para o reader |
| `provider_independent_summary`| resumo curto truncado (≤1800 chars), sem chat bruto |
| `safety_notes`                | lista canônica de fences (sempre inclui `do_not_invoke_provider_to_complete_manifest`) |
| `replay_status`               | `ready` / `partial` / `blocked` / `requires_recovery` |
| `hash`                        | sha256 canônico determinístico (`AtlasLongHorizonReplayManifest::canonicalHash()`) |

`replay_status` é **derivado** (não user-supplied):

| Estado | Quando |
|---|---|
| `ready`              | sem missing_refs + pack em `execute` + não stale |
| `partial`            | há missing_refs, OU pack stale, OU pack em `read_only/repair/review` |
| `requires_recovery`  | receipt declara `loss_risk≠low` + `unresolved_loss` ou `recovery_queries` abertas |
| `blocked`            | pack em `blocked/ask_human/escalate_to_forge` |

## Reader contract — `atlas.long_horizon.replay_reader_bundle.v1`

`ReplayManifestReader::read(manifest)` retorna um **bundle** consumível por qualquer provider (Claude/Codex/Gemini/Atlas) com:

- `manifest_uuid`, `manifest_hash`, `manifest_status`
- `scope` (type+id), `context_pack_hash`
- `integrity` (`context_pack_hash_matches`, `continuation_pack_present`, `compaction_receipt_present`)
- `provider_independent_summary`, `reader_instructions`, `safety_notes`
- `required_refs`, `available_refs`, `missing_refs`, `event_refs`, `evidence_refs`
- `recommended_resume_mode` (mapeado do `manifest_status`)
- `next_action_hint`
- `claim_policy` (fences anti-claim: `invokes_provider=false`, `reads_raw_chat=false`, forbidden_uses)
- `bundle_hash` (sha256 sobre o bundle, determinístico)

O reader **nunca**:
- invoca provider
- lê transcript bruto
- copia campos do pack que não sejam canon
- declara superioridade externa

## CLI

```
php artisan atlas:long-horizon:replay-manifest build --continuation-pack=<uuid> [--compaction-receipt=<uuid>] [--json]
php artisan atlas:long-horizon:replay-manifest build --scope-type=mission --scope-id=<uuid> [--json]
php artisan atlas:long-horizon:replay-manifest show  --manifest=<uuid> [--json]
php artisan atlas:long-horizon:replay-manifest read  --manifest=<uuid> [--json]
php artisan atlas:long-horizon:replay-manifest list  [--scope-type=...] [--scope-id=...] [--limit=20] [--json]
```

Exit codes: `0` ok | `1` runtime/not-found | `2` usage error. Saída sempre JSON.

## TEOS readiness recognition

`AtlasTeosReadinessCertificationService` agora inclui o check **`replay_manifest_available`** (severity P1) que valida a existência de:

- `app/Models/AtlasLongHorizonReplayManifest.php`
- `database/migrations/2026_05_19_150000_create_atlas_long_horizon_replay_manifests_table.php`
- `app/Services/Ai/LongHorizon/Replay/LongHorizonReplayManifestBuilder.php`
- `app/Services/Ai/LongHorizon/Replay/ReplayManifestReader.php`
- `app/Console/Commands/AtlasLongHorizonReplayManifestCommand.php`

Quando todos estão presentes → check `pass` (TEOS-I2 entry desbloqueada). Ausência → `fail` P1.

## Persistência

Migration: `2026_05_19_150000_create_atlas_long_horizon_replay_manifests_table.php`. Tabela `atlas_long_horizon_replay_manifests` espelha o contract; `(continuation_pack_id, compaction_receipt_id)` permite upsert idempotente — mesmo conteúdo → mesmo hash → mesma row reutilizada.

## Hard rules

- **Provider-independent.** Nem o builder nem o reader invocam Claude/Codex/Gemini.
- **Sem chat bruto.** Só campos canônicos do pack/receipt. `state_summary` e `objective` já são sanitizados pelos composers a montante.
- **Hash determinístico.** Excluindo `id`, `hash`, `created_at`, `updated_at`. Mesmos inputs → mesmo hash → idempotência.
- **`replay_status` é derivado.** Caller não decide; a derivação inspeciona `safe_resume_mode` do pack + `loss_risk` do receipt + `missing_refs`.
- **Reader nunca degrada hash.** O `bundle_hash` é uma fingerprint da apresentação; o `manifest_hash` permanece autoritativo.
- **TEOS-I1 intocado.** Não altera continuation pack / compaction receipt / freshness gate / recovery planner.

## Limitações honestas

- **Sem rehidratação.** O reader sinaliza `missing_refs`, mas não busca conteúdo automaticamente — caller decide se rehidrata, downgrada ou pede humano.
- **Sem cross-scope chain.** Cada manifest indexa uma scope. Resumir múltiplas missions exige múltiplos manifests; agregação fica para um futuro `replay_manifest_set.v1`.
- **`event_refs` é normalizado, não rico.** São rótulos `kind:ref`, não payloads completos. Para detalhe, caller consulta `evidence_refs` e fetcha do storage canônico.
- **Sem garantia de freshness dinâmica.** Manifest reflete o estado do pack/receipt no momento do build. Se o pack ficar stale depois, o reader detecta `partial` baseado no `stale_after` original.
- **Sem worker de recompose.** Builder é síncrono e idempotente; não há queue para recompose periódico.
- **Não substitui o freshness gate.** Para decidir se um pack está fresco para execute, continue chamando `LongHorizonContextFreshnessGate`.

## Tests

29 testes verdes em `tests/Feature/Ai/LongHorizon/Replay/`:

- `LongHorizonReplayManifestBuilderTest` (14): shape canônico, missing_refs diff, status (ready/partial/blocked/requires_recovery), hash determinístico, hash muda com conteúdo, build by uuid, recovery_query refs, sumário sem chat bruto.
- `ReplayManifestReaderTest` (8): bundle shape, integrity, resume mode mapping, claim_policy fence, hash determinístico, lookup by uuid.
- `AtlasLongHorizonReplayManifestCommandTest` (7): build/show/read/list JSON, scope selector, usage errors.
- `TeosReadinessReplayManifestRecognitionTest`: TEOS readiness lista o check com evidência real.

Regressão verde: 119/119 testes LongHorizon.

## Pointers de código

| Arquivo | Papel |
|---|---|
| `app/Services/Ai/LongHorizon/AtlasLongHorizonCanon.php`                                  | Constantes (`REPLAY_*`, `ALLOWED_REPLAY_STATUSES`). |
| `app/Models/AtlasLongHorizonReplayManifest.php`                                          | Eloquent (`canonicalHash()`). |
| `app/Services/Ai/LongHorizon/Replay/LongHorizonReplayManifestBuilder.php`                | Builder canônico. |
| `app/Services/Ai/LongHorizon/Replay/ReplayManifestReader.php`                            | Reader provider-independent. |
| `app/Console/Commands/AtlasLongHorizonReplayManifestCommand.php`                         | CLI `atlas:long-horizon:replay-manifest`. |
| `database/migrations/2026_05_19_150000_create_atlas_long_horizon_replay_manifests_table.php` | Schema. |
| `app/Services/Ai/LongHorizon/AtlasTeosReadinessCertificationService.php`                 | Check `replay_manifest_available` adicionado. |
| `tests/Concerns/CreatesLongHorizonPersistenceTables.php`                                 | In-memory schema atualizado. |

## Próximos passos sugeridos (não-implementados)

- `replay_manifest_set.v1` para agregar múltiplos manifests por sessão long-horizon.
- Worker periódico que detecte `stale_after` e recompose manifests `partial` automaticamente.
- Bundle exporter para Open Brain MCP — surface o reader como tool consumível externamente, com mesma fence anti-provider.
