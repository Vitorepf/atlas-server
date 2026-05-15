---
id: atlas-code-provider-arena-ui-v1
type: engineering_knowledge
title: Atlas Code Provider Arena UI v1
status: active
category: programming-forge
priority: 90
summary: Painel premium da Atlas Code RightRail para configurar e disparar comparações `arm_a vs arm_b` (Atlas Forge / Claude Code / Codex CLI / scripted / manual) via o entrypoint canônico `atlas:forge:rivals run-arena`. Snapshot read-only carrega registry + history + safety promises; o dispatch enforça as 3 confirmações para qualquer modo `fair`/`full_power` e mantém `external_rivals_certification` BLOCKED por construção.
tags:
  - atlas
  - atlas-code
  - forge
  - rivals
  - provider-arena
  - ui
  - desktop
capabilities:
  - atlas_code_provider_arena_ui_v1
  - atlas_code_provider_arena_snapshot
  - atlas_code_provider_arena_governed_run
decisions:
  - Provider Arena é um painel de primeiro nível no RightRail (tab `provider_arena`, priority 5) — nunca dentro de Avançado.
  - Snapshot é read-only e nunca chama provider; é a única fonte que a UI lê para renderizar registry/history.
  - `local_fake` é o modo default da UI; só ele aparece sem o bloco de confirmações.
  - Para `fair`/`full_power` a UI exige as 3 confirmações ANTES de habilitar o CTA primário — backend rejeita igualmente, defesa em profundidade.
  - `arm_a` e `arm_b` só listam runners do registry; nunca dropdown livre.
  - Histórico lê `evidence/scorecard.json` + `evidence/manifest.json` no diretório `Atlas-rivals/runs/<id>` e respeita null honesto quando não há dados.
  - UI nunca promove completion claim, nunca exibe `external_rivals_certification` como desbloqueável.
maintenance:
  - Atualize quando `AtlasForgeRivalsArmRegistryService`, `AtlasForgeRivalsModeRegistry`, `AtlasForgeRivalsCasesRegistry` ou `AtlasCodeProviderArenaSnapshotService` mudarem de shape.
  - Schema `atlas.code.provider_arena_snapshot.v1` é additive-only: novas chaves OK, renomes não.
  - Schema `atlas.forge.rivals.provider_arena_run.v1` (run envelope) é governado pelo Provider Arena Core e não muda aqui.
related_paths:
  - app/Http/Controllers/AtlasCodeProviderArenaController.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasCodeProviderArenaSnapshotService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsArenaRunService.php
  - app/Services/Ai/Programming/ForgeRivals/Arms/AtlasForgeRivalsArmRegistryService.php
  - routes/api.php
  - ../../../atlas-desktop/apps/desktop/src/surfaces/code/panels/ProviderArenaPanel.tsx
  - ../../../atlas-desktop/apps/desktop/src/surfaces/code/panels/rightRailRegistry.tsx
  - ../../../atlas-desktop/apps/desktop/src/lib/bridge.ts
  - ../../../atlas-desktop/apps/desktop/src/hooks/useBridge.ts
  - ../../../atlas-desktop/packages/atlas-domain/src/index.ts
  - ../../../atlas-desktop/crates/atlas-bridge/src/client.rs
  - ../../../atlas-desktop/crates/atlas-tauri/src/commands_bridge.rs
  - tests/Feature/Ai/Programming/AtlasCodeProviderArenaControllerTest.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-code-provider-arena-ui-v1
graph_title: Atlas Code Provider Arena UI v1
graph_world: atlas
graph_layer: system
graph_kind: runbook
graph_parent: atlas-forge-rivals-operator-battery-v2
graph_status: active
graph_source: repo
owner: programming_rivals
repo_paths:
  - app/Http/Controllers/AtlasCodeProviderArenaController.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasCodeProviderArenaSnapshotService.php
  - docs/engineering-knowledge-base/atlas-code-provider-arena-ui-v1.md
allowed_changes:
  - Adicionar novos arms / modes / presets via registries existentes (UI consome dinâmico).
  - Estender o snapshot com novos campos canônicos (additive).
  - Polir tipografia / spacing / status colors dentro dos tokens `--cc-*`.
forbidden_changes:
  - Listar arms/modelos via dropdown livre (sem registry).
  - Esconder safety strip ou pular as 3 confirmações para modos `fair`/`full_power`.
  - Disparar `run-arena` no boot da UI ou em refresh automático.
  - Renderizar `external_rivals_certification` como atalho desbloqueável.
  - Inventar winners, scores, claim_ready ou paths de evidência quando o backend não os expôs.
depends_on:
  - atlas-forge-rivals-operator-battery-v2
  - atlas-forge-rivals-provider-arena-core-v1
flows_to:
  - atlas-code
unlocks:
  - operator_can_configure_and_dispatch_arena_runs_from_desktop_ui
governs:
  - atlas_code_provider_arena_ui_v1
evidence:
  - docs/engineering-knowledge-base/atlas-code-provider-arena-ui-v1.md
  - tests/Feature/Ai/Programming/AtlasCodeProviderArenaControllerTest.php
required_tests:
  - tests/Feature/Ai/Programming/AtlasCodeProviderArenaControllerTest.php
  - tests/Feature/Ai/Programming/AtlasForgeRivalsProviderArenaCoreTest.php
requires_evidence: true
risk_level: medium
next_actions:
  - Subir o atlas-server local e validar visualmente o painel (RightRail → tab Arena).
  - Rodar `php artisan atlas:forge:rivals full-smoke --json` para confirmar end-to-end offline.
---

# Atlas Code Provider Arena UI v1

> Schema snapshot: `atlas.code.provider_arena_snapshot.v1`
> Schema run envelope: `atlas.forge.rivals.provider_arena_run.v1`
> Status: implementado · 2026-05-15

## Por que esta meta existe

O Provider Arena Core (Slice 8) entregou o entrypoint determinístico
`atlas:forge:rivals run-arena` que casa `arm_a vs arm_b` por categoria de
tarefa, com 7 runners declarados, 5 modos canônicos e safety contract
imutável. Mas isso era CLI puro: o operador humano precisava abrir um
terminal, lembrar 13 flags e decifrar envelopes JSON para entender quem
ganhou e por quê.

Esta meta entrega a cabine humana. Um painel premium no RightRail do
Atlas Code que responde, em uma olhada:

- **Quem está competindo?** — Arm A e Arm B lado a lado, com label humano
  do registry.
- **Em qual tarefa?** — Categoria selecionada (frontend / backend /
  bugfix / tests / refactor / architecture / docs / performance /
  security).
- **Qual modo?** — Local fake, Fair, Full power — com cor semântica
  indicando token spend.
- **Vai gastar token?** — Safety strip permanente + chip por arm
  indicando provider externo.
- **Quanto risco?** — Bloco de 3 confirmações aparece automaticamente
  para qualquer arm com `requires_external_provider_call=true` em modo
  não-`local_fake`.
- **Status agora?** — Bloco do último resultado (status, run_id, winner,
  blockers, next_command).
- **Histórico?** — Lista dos últimos 10 runs com badges (verdict, winner,
  claim_ready, comparable_score).
- **Quem venceu e por quê?** — Winner badge + comparable score; report.md
  fica sempre disponível no path declarado pelo backend.
- **External rivals?** — Sempre exibido como `blocked` no safety strip.
  Resposta canônica: NÃO, até approval explícito do operador.

## Contratos

### Backend

- `AtlasCodeProviderArenaSnapshotService::snapshot(int $historyLimit)` —
  composição read-only de `ArmRegistry::snapshot()` + `ModeRegistry`
  (apenas `local_fake/fair/full_power`) + `CasesRegistry::presets()` +
  history (lê `Atlas-rivals/runs/<id>` ordenado por mtime, lê manifest +
  scorecard + arena_context quando existem).
- `AtlasCodeProviderArenaController::show()` —
  `GET /atlas-code/forge/provider-arena/snapshot[?history_limit=N]` →
  retorna sempre 200; `external_provider_call=false`,
  `provider_tokens_spent=false`,
  `separated_from_external_rivals_certification=true`.
- `AtlasCodeProviderArenaController::run()` —
  `POST /atlas-code/forge/provider-arena/run` → forwarda para o
  dispatcher canônico (`atlas:forge:rivals run-arena`). Body em
  snake_case espelhando o CLI; confirmations vêm como
  `confirmations.runbook_reviewed` / `provider_cost` / `real_provider_call`.
  Status code: `ok=200`, `blocked=409`, `error=400`.

Rotas em `routes/api.php` (dentro do grupo `atlas-code/`):

```
GET  /atlas-code/forge/provider-arena/snapshot
POST /atlas-code/forge/provider-arena/run
```

### Bridge (Rust + TypeScript)

- `endpoints.rs`: `ATLAS_CODE_FORGE_PROVIDER_ARENA_SNAPSHOT`,
  `ATLAS_CODE_FORGE_PROVIDER_ARENA_RUN`.
- `client.rs`: `AtlasBridge::get_provider_arena_snapshot(history_limit)`,
  `AtlasBridge::run_provider_arena(payload)`.
- `commands_bridge.rs`: `bridge_get_provider_arena_snapshot`,
  `bridge_run_provider_arena` registrados em `tauri::generate_handler!`.
- `lib/bridge.ts`: `getProviderArenaSnapshot(historyLimit?)` +
  `runProviderArena(payload)` + adapters snake→camel
  (`adaptProviderArenaSnapshot`, `adaptProviderArenaRunResult`,
  `adaptProviderArenaArm`, `adaptProviderArenaHistoryEntry`).
- Tauri-first com HTTP fallback; offline mode é honesto (`null`).

### Domain (`@atlas/domain`)

- `AtlasCodeProviderArenaSnapshot` (raiz) com `armRegistry`, `modes`,
  `presets`, `history`, `lastRun`, `safetyPromises`,
  `separatedFromExternalRivalsCertification`.
- `AtlasCodeProviderArenaArm` (registry entry com `safetyContract`).
- `AtlasCodeProviderArenaHistoryEntry` (run + scorecard + arena context
  + paths de evidência).
- `AtlasCodeProviderArenaRunPayload` / `AtlasCodeProviderArenaRunResult`
  para o POST.

### Hook (`useBridge`)

- `providerArena` + `providerArenaLastResult` em `BridgeSnapshot`.
- `refreshProviderArena(historyLimit?)`, `runProviderArena(payload)`,
  `clearProviderArenaLastResult()` em `BridgeActions`.
- Snapshot é puxado no `refresh()` inicial e re-puxado após cada
  tentativa de `runProviderArena` (sucesso ou bloqueio).

### UI (`ProviderArenaPanel.tsx`)

Layout vertical, denso mas confortável, paleta `--cc-*` warm dark.

```
[PanelTitle · Provider Arena · v1 · 7 runners · N runs]
[SafetyStrip — provider:no · tokens:no · completion:no · review:on · external_rivals:blocked]

Arena ─ braços vão competir lado a lado
┌──────────────────┬────┬──────────────────┐
│ Arm A picker     │ vs │ Arm B picker     │
│  · runner select │    │  · runner select │
│  · model select  │    │  · model select  │
│  · status badge  │    │  · status badge  │
│  · description   │    │  · description   │
│  · safety chip   │    │  · safety chip   │
└──────────────────┴────┴──────────────────┘

Configuração ─ categoria · modo · preset
┌──────────────────┬──────────────────────┐
│ Task category    │ Preset (case count)  │
└──────────────────┴──────────────────────┘
[ Local fake ][ Fair ][ Full power ] ← mode selector
Note do modo selecionado (paid · tokens · same model / etc).

(Se modo ≠ local_fake e algum arm requer provider real)
┌──────────────────────────────────────────┐
│ ☐ Li o runbook                           │
│ ☐ Autorizo gasto de tokens               │
│ ☐ Confirmo provider externo de verdade   │
└──────────────────────────────────────────┘

[ CTA primário único ] [ Atualizar histórico ]
  — CTA muda de label segundo estado (Rodar simulação local /
    Confirmar para rodar bateria real / Rodar bateria real /
    Escolher runners / Escolher categoria).
  — Disabled state explica o motivo logo abaixo.

(Se providerArenaLastResult ≠ null)
┌─ Resultado do último run ────────────────┐
│ status badge · run id                    │
│ note (humano)                            │
│ winner badge (se houver)                 │
│ Bloqueios (lista mono)                   │
│ next_command (mono, copiável)            │
│ "não desbloqueia external_rivals"        │
└──────────────────────────────────────────┘

Últimos runs (limite N)
· run_id · timestamp
  arm A vs arm B · category · mode
  [verdict] [winner] [claim_ready] [score]

Garantias da Arena
· não promove completion claim
· não desbloqueia external_rivals_certification
· bateria real precisa de 3 confirmações
· local fake nunca chama provider
· replay + evidence obrigatórios antes de winner
```

### Estados da UI

| Estado | Trigger | UI |
|---|---|---|
| `empty/no_snapshot` | `providerArena === null` | Texto curto + botão "Tentar de novo". |
| `ready_to_configure` | snapshot OK, sem defaults explícitos | Defaults aplicados via `resolveConfig`. CTA: "Rodar simulação local". |
| `local_dry_run_ready` | mode=local_fake, runners ok | CTA: "Rodar simulação local". Bloco de confirmações escondido. |
| `provider_confirmations_required` | mode=fair/full_power + algum arm real, confirmações pendentes | CTA disabled: "Confirmar para rodar bateria real". |
| `running` | `pending===true` durante `runProviderArena` | CTA mostra "Executando…", `busy` lock dos botões. |
| `report_ready` | `providerArenaLastResult.status==='ok'` | Bloco verde + winner badge + scorecard. |
| `blocked` | `providerArenaLastResult.status==='blocked'` | Bloco danger + lista de blockers + next_command. |
| `invalid_evidence` | history entry com `verdict=invalid_*` | History row em tom danger. |

`replaying` / `adjudicating` aparecem como estados intermediários do
`status` retornado pelo dispatcher; tratamos sob o mesmo bloco "resultado
do último run" preservando o status text canônico.

## Regras imutáveis

- **NUNCA** liste runners fora do `AtlasForgeRivalsArmRegistryService::ARMS`.
- **NUNCA** habilite o CTA real-provider sem as 3 confirmações no UI;
  o backend recusa de qualquer forma, mas a UI não pode incentivar.
- **NUNCA** chame `runProviderArena` no boot/refresh; só por clique.
- **NUNCA** mostre `external_rivals_certification` como destravável.
- **NUNCA** invente winner/score/claim_ready quando o scorecard não os
  expôs (renderiza "sem winner" / "sem score").
- **NUNCA** dispare `runProviderArena` em testes — feature tests usam
  apenas `mode=local_fake` ou validam blockers; não há mock de provider.

## Cross-references

- `atlas-forge-rivals-operator-battery-v2` — entrypoint canônico que
  esta UI dirige.
- `atlas-forge-rivals-provider-arena-core-v1` — Slice 8 (run-arena +
  registry + safety contract).
- `atlas-forge-rivals-perfect-battery-and-adjudicator-v1` — adjudicador
  determinístico que produz o scorecard exibido.
- `atlas-code-forge-human-first-ux-orchestrator-v1` — irmã maior da
  cabine humana (Forge primary tab).

## Próximas ações

- `php artisan atlas:forge:rivals full-smoke --json` (validação offline
  end-to-end).
- Subir atlas-server local + Atlas Code Desktop e verificar o tab
  **Arena**.
- Acompanhar a evolução de scripted/manual/gemini drivers — quando
  saírem do `not_yet_executable` o painel libera o slot real automaticamente.
