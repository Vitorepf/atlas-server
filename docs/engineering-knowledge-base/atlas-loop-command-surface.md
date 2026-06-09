---
id: atlas-loop-command-surface
human_name: Atlas Loop Command Surface
canonical_name: Atlas Loop Command Surface
technical_name: AreaFocusLoopCommandController
cartography_type: surface
canonical_source: docs/engineering-knowledge-base/atlas-loop-command-surface.md
type: engineering_knowledge
title: Atlas Loop Command Surface
status: active
category: agentic-engineering
priority: 20
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-loop-command-surface
graph_title: Atlas Loop Command Surface
graph_world: atlas
graph_layer: module
graph_kind: surface
graph_parent: atlas-software-company-stewardship-stack
graph_status: active
graph_source: repo
owner: atlas-ai
implementation_state: implemented_runtime_surface_with_future_desktop_projection; mobile Loop dossier in OPERACAO ATLAS (app/loop.tsx) over five backend endpoints (AreaFocusLoopCommandController) composing existing read models and owner services; reads are live + honest, writes wrap AP-724 decision, the AP-790 runner signal files and the real operational inbox. No new selection/execution/merge logic; never invokes a provider. Desktop projection of the same surface remains future.
summary: The single human-to-loop surface. The Loop dossier "O DIARIO DO LOOP" is the only place the operator talks to the Atlas Continuous Stewardship Loop (the autonomous 24h loop) from mobile. It is a thin, honest seam over existing capability: it READS live run state and the append-only cycle ledger, and it WRITES exactly four operator-owned commands (decision, run-control, directive) without ever fabricating a run, a cycle, a merge or provider-proof. Six reading-ordered functions encode the ethic alive -> what it did -> what it asks -> what you say -> the lever -> audit.
human_summary: A unica tela por onde o operador fala com o loop autonomo de 24h. Le o estado vivo, o diario de ciclos, mostra as decisoes que esperam por voce, deixa voce mandar uma diretiva em linguagem natural, e da o controle (pausar/encerrar) por ultimo. Tudo honesto: nunca finge um run, um merge ou uma prova.
human_what: Define a superficie humano-loop unica (Loop dossier no mobile + cinco endpoints backend), as seis funcoes, os data hooks e a linguagem de design.
human_purpose: Dar ao operador um unico ponto de comando vivo sobre o loop autonomo, com honestidade estrutural (real-or-blocked, proposal-only, honest-stop, directive-not-autonomous).
human_input: Estado vivo do cockpit Product Mode + run state do runner AP-790, ledger append-only de ciclos, decisoes do operador, sinais de run-control e diretivas em linguagem natural.
human_output: Dossie vivo (sinais vitais, diario de ciclos, decisoes pendentes, diretiva, controle, confianca) + recibos honestos de decisao/run-control/diretiva.
human_change_when: Mexa quando AreaFocusLoopCommandController, o runner AP-790, o cockpit AP-739, a decisao AP-724 ou o contrato do loopClient/useLoopCommand mudarem.
human_block_when: Bloqueie se a tela passar a afirmar um merge, um run, uma prova de provider ou um consumo autonomo de diretiva que nao aconteceu.
tags:
  - atlas-ai
  - loop
  - loop-command-surface
  - continuous-stewardship-loop
  - area-focus-loop
  - stewardship-stack
  - mobile
  - command-surface
capabilities:
  - loop_command_surface
  - continuous_stewardship_loop
  - area_focus_loop
  - software_company_stewardship_stack
  - operator_decision_surface
  - run_control_signal_surface
  - operator_directive_intake
decisions:
  - The Loop dossier is the ONLY human-to-loop surface; it is a Product Mode/command surface inside the Atlas Software Company Stewardship Stack, not a new OS.
  - The surface is a thin composition seam over EXISTING owner services and read models; it adds no selection, execution or merge logic and never invokes a provider.
  - Reads are honest live state - live composes the AP-739 Product Mode cockpit + the AP-790 reliable 24h runner read/path-only accessors; cycles tails the AP-790 append-only cycle ledger.
  - operator-decision wraps AreaFocusOperatorDecisionService::decide (AP-724); an accept unlocks the next owner stage under operator review and NEVER executes (executed=false, requires_owner_execution=true).
  - run-control is a SIGNAL only - it writes/deletes ONLY the runner's own pause/kill signal files, which the loop already checks with is_file() on each iteration boundary; it never starts/stops a process, merges or invokes a provider.
  - directive is HONEST - the 24h loop has no durable free-text directive intake, so a directive is persisted into the real operational inbox (AtlasInboxService) as an operator-review item with a machine-readable recipe; loop_autonomously_consumable_now is always false.
  - A single derived loopState is the one truth word - it feeds the masthead pill, the Vitals "estado" row and the run-control top line identically; they can never disagree.
  - kill beats all - a no-signal/blocked loop never renders alive and never offers stop controls.
  - The Loop entry sits FIRST in OPERACAO ATLAS (numeral ii) on app/edicao.tsx because it is the live command surface; everything else in that section is a dossier.
maintenance:
  - Atualize quando AreaFocusLoopCommandController ganhar/alterar endpoints, schemas ou invariants.
  - Atualize quando o runner AP-790 (Reliable24hLoopRunnerService) mudar lock/pause/kill/recovery/backlog accessors ou o schema do ledger de ciclos.
  - Atualize quando AP-739 (ProductModeCockpitSurfaceService) ou AP-724 (AreaFocusOperatorDecisionService) mudarem o shape que a tela consome.
  - Atualize quando lib/api/loopClient.ts, lib/loop/useLoopCommand.ts ou components/loop/* mudarem o contrato ou a linguagem de design.
  - Mantenha este doc alinhado com o canon do Product Mode (atlas-autonomous-software-company-night-shift-product-mode.md) e da Stewardship Stack (atlas-software-company-stewardship-stack.md).
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift-product-mode.md
  - docs/ap/AP-790-reliable-24h-autonomous-loop-runner-contract.md
repo_paths:
  - app/Http/Controllers/Ai/SoftwareCompanyStewardship/AreaFocusLoopCommandController.php
  - routes/api.php
  - tests/Feature/Ai/SoftwareCompanyStewardship/LoopCommandSurfaceTest.php
  - tests/Feature/Ai/SoftwareCompanyStewardship/AreaFocusLoopCommandRunControlTest.php
allowed_changes:
  - Update endpoint/schema descriptions when AreaFocusLoopCommandController changes.
  - Update mobile surface contract when app/loop.tsx or loop client hooks change.
forbidden_changes:
  - Do not add provider execution, merge authority, or autonomous directive consumption to this surface.
  - Do not create a second human-to-loop command surface without an owner decision.
depends_on:
  - atlas-software-company-stewardship-stack
  - atlas-autonomous-software-company-night-shift-product-mode
  - AP-790
flows_to:
  - area-focus-loop-command-controller
  - atlas-mobile-loop-dossier
unlocks:
  - governed_operator_loop_control
governs:
  - loop_command_surface
  - operator_decision_surface
evidence:
  - docs/engineering-knowledge-base/atlas-loop-command-surface.md
  - app/Http/Controllers/Ai/SoftwareCompanyStewardship/AreaFocusLoopCommandController.php
  - tests/Feature/Ai/SoftwareCompanyStewardship/LoopCommandSurfaceTest.php
required_tests:
  - php artisan test tests/Feature/Ai/SoftwareCompanyStewardship/LoopCommandSurfaceTest.php
  - php artisan test tests/Feature/Ai/SoftwareCompanyStewardship/AreaFocusLoopCommandRunControlTest.php
requires_evidence: true
risk_level: high
next_actions:
  - Keep the mobile Loop dossier aligned with AP-790 runner receipts and AP-724 operator decisions.
  - Keep this surface read-mostly and honest; run-control remains signal-only.
---

# Atlas Loop Command Surface

> The single human <-> loop surface. **"O DIÁRIO DO LOOP" (The Loop Dossier)** is the
> only place the operator talks to the **Atlas Continuous Stewardship Loop** — the
> autonomous, always-on 24h loop — from mobile. It is a thin, honest seam over
> existing capability. It never fabricates a run, a cycle, a merge, or provider-proof.

This is a **Product Mode / command surface inside the Atlas Software Company
Stewardship Stack**, not a new OS. It composes existing read models and owner
services; it adds no selection, execution, or merge logic, and it **never invokes a
provider**. Canonical context lives in
[`atlas-autonomous-software-company-night-shift-product-mode.md`](atlas-autonomous-software-company-night-shift-product-mode.md)
and [`atlas-software-company-stewardship-stack.md`](atlas-software-company-stewardship-stack.md);
this doc owns the human <-> loop surface specifically.

---

## Resumo

Atlas Loop Command Surface e a surface humana unica para observar e comandar o loop autonomo 24h no mobile.

## Papel no Atlas

Compoe Product Mode, AP-790, AP-724 e inbox operacional sem criar selecao, execucao, merge ou provider runtime novo.

## Onde Se Encaixa

Fica entre o operador mobile, `AreaFocusLoopCommandController`, a Stewardship Stack e o runner AP-790.

## Contratos

Contratos principais: endpoints `/loop/*`, schemas do controller, receipts AP-790, decisoes AP-724 e itens reais do inbox operacional.

## Fluxo

Ler estado vivo, ler ledger, mostrar decisoes pendentes, aceitar diretiva, emitir run-control signal e preservar auditoria.

## Regras para IA

Nao tratar a surface como executor; nao afirmar merge, provider call ou consumo autonomo de diretiva sem evidence real.

## Escopo de Implementacao

Implementado como surface mobile/backend fina; desktop projection e expansoes de UX permanecem futuras.

## Dependencias

Stewardship Stack, Product Mode, AP-790 Reliable 24h Loop Runner, AP-724 Operator Decision e AtlasInboxService.

## Evidencias

Controller, rotas API, testes LoopCommandSurface/RunControl e ledger AP-790 citado nas secoes detalhadas abaixo.

## Riscos

Maior risco e transformar controle humano honesto em runtime paralelo que executa, mergeia ou consome provider por fora do loop.

## Exemplos

Um operador pausa o loop por signal file; a resposta re-le o estado real e nao finge que parou processo ou mergeou codigo.

## Proximas Acoes

Manter os testes de surface verdes e atualizar este doc sempre que endpoints, schemas ou o mobile Loop dossier mudarem.

## 1. The surface, in one breath

The operator opens **OPERAÇÃO ATLAS** (the `edicao` archive, section numeral **ii**),
taps **Loop**, and lands on a single weighted vertical reading whose **order encodes
an ethic**:

```
alive  ->  what it did  ->  what it asks  ->  what you say  ->  the lever  ->  audit
```

That order is not decorative. The operator first sees that the loop is alive and
healthy, then what it actually did, then what it needs from them, then says something
back, and only then — last — touches the lever that stops it. Trust comes before
control.

The surface is **read-mostly**. It reads two things honestly (live state, the cycle
ledger) and writes exactly **three** operator-owned commands (a decision, a
run-control signal, a directive). Every write is honest by construction: an accept
never executes, a run-control is only a signal the runner already obeys, and a
directive is never silently consumed.

---

## 2. The Loop dossier in OPERAÇÃO ATLAS

**Entry point.** `app/edicao.tsx`, section `SectionHead numeral="ii" title="Operação
Atlas"`. The Loop entry is a `TocRow` that sits **first** in the dossier list — before
Memory, Open Brain, Engineering, etc. — because it is the **live command surface**,
not an archived dossier. It carries the `live` flag (value rendered in bronze, not
placeholder ink) and routes to `/loop`.

**Route.** `app/loop.tsx` is the expo-router file-based route `/loop`. Because
expo-router is file-based, the file alone registers a working route; the
`Stack.Screen name="loop"` entry in `app/_layout.tsx` only sets the
`slide_from_right` / 320ms transition to match its primary-navigation siblings
(`engineering`, `rivals`, `memory`).

**The dossier body.** A single `Screen` scroll with `CodexReveal`-staggered sections.
The masthead is `LOOP` with a live `LoopStatusPill`; tapping the masthead returns to
`/edicao`. The six functions render as numbered sections (see §3). When the surface
cannot read the loop at all, the whole body collapses to one honest `BlockedNote`
under section **i SINAL** — masthead, dateline, and pill stay, but nothing below
pretends to know the loop's state.

---

## 3. The six functions

The dossier is six reading-ordered functions. Each is a section with a Roman numeral
and a one-line deck derived purely from the single `loopState`.

### i — SINAIS VITAIS (Vital signs) · HERO #1

*"Is it alive, and is it healthy?"* The first thing the operator sees. Renders the
single derived `loopState` (see §6) as the truth word, plus the live run-state
(lock/lease, pause, kill, stewardship recovery, scheduler backlog) and the newest
cycle timestamp. The "kill armado" row, when present, scrolls the operator to section
**v** (the lever). Honest: a crashed run (expired lease or dead PID) never reads as
alive.

### ii — DIÁRIO DE CICLOS (Cycle diary) · HERO #2

*"What did it actually do?"* The append-only cycle ledger, newest first, capped inline
at 8 entries (calm under load). A `24H | TUDO` segmented control switches the window.
Each `CycleEntry` opens a `CycleReceiptSheet` with the verbatim cycle receipt
(outcome, merge_performed, merge_hash, blockers, repaired/retried/quarantined). When
more cycles exist than are shown, a `mostrando N de M` note is honest about the tail —
the dossier never implies more than the ledger holds.

### iii — DECISÕES PENDENTES (Pending decisions)

*"What does it ask of me?"* The review queue mapped from the live cockpit. Each
`DecisionCard` offers accept / reject / defer / request_changes. **Proposal-only is
structural**: an accept shows *"NÃO executa · roteia ao dono"* and produces an AP-724
receipt whose `executed` stays `false` and `requires_owner_execution` is `true`. The
sealed decision opens a receipt sheet rendering those guarantees verbatim, so the
audit is never a hole. When nothing waits, the empty state is **positive**: *"Nada
aguarda sua decisão. O loop está autônomo dentro do que você já permitiu."*

### iv — DIRETIVA (Directive)

*"What do I say back?"* A natural-language composer. The operator types an instruction;
it is persisted into the **real operational inbox** as an operator-review item.
**Honest by construction**: a permanent honesty band + the exact recipe make clear
that the 24h loop does **not** autonomously consume inbox items
(`loop_autonomously_consumable_now` is always `false`). The recipe names the only
durable free-text finding source the loop actually reads — canonical-doc frontmatter
(`next_actions` / `allowed_changes`) under env flags — and the operator step to make a
directive loop-consumable. Recently sent directives are prepended locally so the
operator sees their just-sent order before the refetch, never optimistically lying.

### v — CONTROLE (Control) · the lever, last

*"Stop it / let it run."* The lever comes **last**, on purpose. `RunControlBar` offers
pause / resume / kill / clear-kill. **Honest-stop is structural**: each action writes
or deletes **only the runner's own signal file** (`pausePath` / `killSwitchPath`),
which the loop already checks with `is_file()` on each iteration boundary. The
response re-reads the **true post-state from disk** — the bar never shows an optimistic
state. A kill propagates within `<=5s` mid-sleep (the runner's responsive sleep). This
never starts/stops a process, never merges, never invokes a provider.

### vi — CONFIANÇA (Trust) · audit colophon

*"What does the autonomy prove?"* The `TrustColophon` closes the reading with the audit
truth: surface hash, generated-at, and the proof the live + ledger data carries. The
closing folio stamps the short surface hash and a relative generated-at. Audit is the
last word, not a footnote.

---

## 4. Backend endpoints

All five live behind the `atlas.token` middleware (operator token, `X-Atlas-Token`)
inside the existing `ai/software-company-stewardship` route group, served by
`App\Http\Controllers\Ai\SoftwareCompanyStewardship\AreaFocusLoopCommandController`.
The controller is a **thin composition seam** — every read composes an existing read
model and every write wraps an existing owner service or writes the runner's own
signal files atomically.

| Verb | Path (`/ai/software-company-stewardship/...`) | Method | Composes | Honesty contract |
|---|---|---|---|---|
| GET | `loop/{area}/live` | `live` | AP-739 `ProductModeCockpitSurfaceService::project` + AP-790 `Reliable24hLoopRunnerService` read/path-only accessors (lock / kill / pause / stewardship recovery / scheduler backlog) | Unknown area mirrors the AP-721 stable 404. Deterministic `surface_hash` ETag over the body minus volatile timestamps; `Cache-Control: private, max-age=5`. |
| GET | `loop/{area}/cycles` | `cycles` | AP-790 append-only cycle ledger (`readLedgerRecords`), `?tail=N` (default 20, hard cap 200) and `?hours=H` (applied **before** tail) | Read-only tail of real cycle receipts (`ap790_reliable_24h_loop_cycle.v1`); reports `ledger_record_count_total` honestly. |
| POST | `loop/{area}/operator-decision` | `operatorDecision` | AP-724 `AreaFocusOperatorDecisionService::decide` | Receipt returned verbatim. An accept unlocks the next owner stage under operator review; **`executed=false`, `requires_owner_execution=true`**. The controller dispatches **no** owner runtime. 422 with a stable machine reason on invalid input. |
| POST | `loop/{area}/run-control` | `runControl` | The AP-790 runner's **own** `pausePath()` / `killSwitchPath()` | Signal only (`pause` / `resume` / `kill` / `clear-kill`). Writes are atomic (temp-then-rename). The response re-reads **true** kill/pause state from disk. Requires `operator_actor`; never executes/merges/invokes a provider. |
| POST | `loop/{area}/directive` | `directive` | The **real** operational inbox (`AtlasInboxService::create`) | Persists a natural-language directive as an operator-review item with a machine-readable `to_make_loop_consumable` recipe. **`loop_autonomously_consumable_now=false`**, `executed=false`, `provider_invoked=false`, `mutates_target_repo=false`, `auto_consumed=false`. |

**Defaults** mirror the read-model + runner: focus `dev_forge`, portfolio
`atlas_software_company`. The mobile client defaults `area` to
`agentic_engineering_os`.

**Schemas** (controller constants):
- `atlas.software_company_stewardship.loop_command_live.v1`
- `atlas.software_company_stewardship.loop_command_cycles.v1`
- `atlas.software_company_stewardship.loop_command_run_control.v1`
- `atlas.software_company_stewardship.loop_command_directive.v1`
- operator-decision returns AP-724 `area_focus_operator_decision_receipt.v1` (and `.error.v1` on 422).

**Why these exact wraps and not new logic.** No existing service writes the
pause/kill files — the runner only *reads* them — so writing the runner's own paths is
the correct, non-duplicating composition (honest-stop intact). The 24h loop has no
durable free-text directive intake, and writing a doc commit from an HTTP handler
would violate the proposal-only / no-scaffold posture, so the directive lands in the
real inbox with an honest recipe instead of a fabricated autonomous pickup.

---

## 5. The data hooks (mobile)

A single frozen contract, verified field-for-field against the controller source, not
invented.

- **`lib/api/loopClient.ts`** — typed client. Five functions (`fetchAtlasLoopLive`,
  `fetchAtlasLoopCycles`, `submitAtlasLoopOperatorDecision`, `submitAtlasLoopRunControl`,
  `sendAtlasLoopDirective`) over the operator-token helpers `apiGet`/`apiPost` (NOT the
  device-bearer `mobileApi*`, which 401s if unpaired). The TypeScript interfaces are the
  frozen contract the screen renders against; the proposal-only guarantees
  (`executed: false`, `requires_owner_execution`, `loop_autonomously_consumable_now: false`)
  are encoded as literal types so they can never be softened in the UI.
- **`lib/loop/index.ts`** — the react-query layer (`useLoopState`, `useLoopCycles`,
  `useSubmitDecision`, `useRunControl`, `useSendDirective`) over a `QueryClient`
  configured once in `app/_layout.tsx`.
- **`lib/loop/useLoopCommand.ts`** — the single state owner for `app/loop.tsx`. Thin
  orchestration over the react-query hooks. It derives `loopState` strictly (see §6),
  exposes `{ live, cycles, cyclesReturned, cyclesTotal, loopState, loading, refreshing,
  reasonCode, cyclesBlocked, cyclesReasonCode, window, setWindow, refresh, decide,
  runControl, sendDirective, postedDirectives, busyAction, lastReachability }`, patches
  run-control state ONLY from the true backend response (the backend re-reads the disk),
  and prepends a just-sent directive locally — never optimistic-lying.
- **`components/loop/*`** — the presentational vocabulary (`VitalLedger`, `CycleEntry`,
  `CycleReceiptSheet`, `DecisionCard`, `DirectiveComposer`/`DirectiveRow`/`DirectiveHonestyBand`,
  `RunControlBar`/`RunControlConfirmStrip`, `TrustColophon`, `BlockedNote`, `LoopStatusPill`,
  `StatusDot`, `Segmented`, …). Every component resolves color via `usePalette`, uses
  **static** dots, and is honest by construction.

---

## 6. The design language

**Editorial, not SaaS.** The dossier is a bound-book table-of-contents / daily-briefing
vocabulary (Penguin Classics / Monocle), staggered like pages turned one at a time, not
a cascading dashboard. The masthead, dateline, Roman-numeral section heads, and folio
footer are the editorial grid (`components/editorial`). Loading and empty states use
`ink3` `·····` placeholders that hold layout — **no spinners, no shimmer**.

**A single truth word.** `deriveLoopState(live, loading)` in
`components/loop/loopTone.ts` is THE single source of the loop's state. It feeds three
renderings identically — the masthead pill, the Vitals "estado" row, and the
run-control top line — so they **can never disagree**. The strict derivation order,
**kill beats all**:

```
loading & live===null                 -> loading
live===null after load                -> no_signal   (whole-surface BLOCKED render)
run_state.kill_switch.active           -> killed
run_state.pause.active                 -> paused
lock.held & health healthy/ok/ready    -> alive
lock.held & health blocked             -> blocked
lock.held & health bug/crash           -> bug
lock.held & health unknown             -> alive (else blocked) — never fake-healthy
lock.available & !held                 -> idle
```

The eight states and their words: `alive` → **VIVO**, `paused` → **PAUSADO**, `killed`
→ **ENCERRADO**, `blocked` → **EM BLOQUEIO**, `bug` → **DEFEITO**, `idle` → **EM
REPOUSO**, `no_signal` → **SEM SINAL**, `loading` → **…**.

**One tone map, ported DNA.** `statusColor` / `statusLabel` are ported **verbatim** from
`app/engineering.tsx` (proven same-codebase DNA, not a parallel style), extended with
the loop run-state tones: moss = alive/healthy/merged/ok; amber =
paused/blocked-cycle/progress/drift; recRed = killed/bug/critical/quarantined; prussian
= info/request_changes/idle; bronze = tier/live-datum/proof/accent. Risk escalates
honestly (low ink3 → medium amber → high bronzeDeep → critical recRed) and never
celebrates a high risk.

**Honesty is the aesthetic.** The four invariants are not copy — they are structure:

- **real-or-blocked** — no fabricated loop, cycle, merge, or proof. `merge_performed` /
  `provider_invoked` are honest booleans the loop emits; when the surface can't read the
  loop, it shows a `BlockedNote` with the raw reason code, not an empty-but-fine screen.
- **proposal-only** — an accept shows *"NÃO executa · roteia ao dono"* and the receipt's
  `executed=false` / `requires_owner_execution=true` are rendered verbatim.
- **honest-stop** — run-control writes the signal files the runner already obeys and
  re-reads the true post-write disk state; never optimistic.
- **directive-not-autonomous** — a permanent honesty band + the exact recipe; the inbox
  is not a loop finding source.

These compose the larger constitution untouched: RSI and EarnedAutonomy remain
default-off; no-scaffold and provider-proof gates are not weakened anywhere in this
surface.

---

## 7. Source map

| Concern | File |
|---|---|
| Backend controller | `app/Http/Controllers/Ai/SoftwareCompanyStewardship/AreaFocusLoopCommandController.php` |
| Routes | `routes/api.php` — `ai/software-company-stewardship` group, `loop/{area}/...` |
| Owner services composed | `Reliable24hLoopRunnerService` (AP-790), `ProductModeCockpitSurfaceService` (AP-739), `AreaFocusOperatorDecisionService` (AP-724), `AtlasInboxService` |
| Mobile route / dossier | `atlas-app/app/loop.tsx` |
| Mobile entry (TocRow) | `atlas-app/app/edicao.tsx` — OPERAÇÃO ATLAS (numeral ii), first row |
| Mobile route transition | `atlas-app/app/_layout.tsx` — `Stack.Screen name="loop"` |
| Typed client | `atlas-app/lib/api/loopClient.ts` |
| React-query hooks | `atlas-app/lib/loop/index.ts` |
| State owner | `atlas-app/lib/loop/useLoopCommand.ts` |
| Tone / single truth | `atlas-app/components/loop/loopTone.ts` |
| Presentational vocabulary | `atlas-app/components/loop/*` |

**Tests (all green).** Backend: 42 tests / 333 assertions across
`tests/Feature/AreaFocusLoopCommandControllerTest.php`,
`tests/Feature/Ai/SoftwareCompany/AreaFocusLoopCommandControllerTest.php`,
`tests/Feature/Ai/SoftwareCompany/AreaFocusLoopCommandRunControlTest.php`,
`tests/Feature/Ai/SoftwareCompanyStewardship/AreaFocusLoopCommandOperatorDecisionTest.php`,
`tests/Feature/Ai/SoftwareCompanyStewardship/LoopCommandSurfaceTest.php`. Mobile: the
Loop screen, client, hooks, and tone are covered by strict `tsc --noEmit` (clean) and
the `test:front` battery (green).
