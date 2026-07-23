# Atlas Server GOD Debulk — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **This is a PROGRAM plan.** Phases 0–2 are executable now. Phases 3–8 spawn child plans with the same header when entered. Do not invent product features inside this program.

**Goal:** Bring `atlas-server` to the same *structural* GOD level reached in `atlas-native`: code an AI can find, understand, maintain, and evolve — by eliminating peel-forest *and* godfile obesity — **without** rewriting PHP→Swift.

**Architecture:** Keep Laravel/PHP 8.4 as the brain runtime. Apply the native restructure *pattern*: closed vocabulary, density budgets, honesty renames, same-domain fuse, mandatory split above ceilings, true CODEMAP, delete-dead with proof, continuous gates. Invert the late-native failure mode: **SPLIT godfiles before mass-fuse**. Collapse overlapping OS folders by ownership map, not by deleting capability.

**Tech Stack:** PHP 8.4 · Laravel 13 · PHPUnit/ParaTest · Artisan · Postgres · existing Atlas gates (`php artisan test`, targeted feature tests, `atlas` CLI). No new Composer deps unless operator records a decision.

## Global Constraints

- Branch: local `main` only in `atlas-server` (no obra branches, no merge to “fix” divergence).
- Language rewrite forbidden: **no** PHP→Swift big-bang; Swift only as additive local runtime later, out of scope here.
- Product features forbidden during debulk: no new domain, no new public API surface, no Rivals/benchmark claims in code/docs.
- Keep-list: do **not** delete the 26 live `AtlasLoop*` classes solely by prefix; follow `docs/engineering-knowledge-base/atlas-autonomos-live-system.md`.
- ACDE/`atlas:loop:*` family is dead to *operate*, but migration of callers is a phased task — never silent break.
- Every App/code commit: relevant tests green; no commit that only bumps a vanity `pass:` counter in docs.
- Density (PHP services): target 150–800 LOC; soft warn >1000; **mandatory split** >2000; emergency split >5000.
- One concern per class; one OS ownership per capability (see Phase 1 map).
- Commits: `refactor(core): GOD-DEBULK <foco>` or `test(core): GOD-DEBULK <foco>` or `docs(core): GOD-DEBULK <foco>` — never `feat` for this program.
- Evidence: `docs/evidence/2026-07-22-atlas-server-god-debulk/LEDGER.md` + `DEBTS.md` (create in Phase 0).

---

## 0. O que fizemos no app nativo (análogo — cravado)

### O que foi (e o que NÃO foi)

Fizemos **GOD RESTRUCTURE da casca Swift**, não troca de stack:

1. **Vocabulário fechado** — sufixos (`View/Shell/Surface/Judgment/Chrome/Body/…`) + famílias de método (`spoken*`, `packFacts`, `rank*`).
2. **Guerra à floresta de peels** — milhares de arquivos ~50–100 LOC → hosts coesos por domínio.
3. **Rename honesty** — matar `Grammar`, `Peel`, `conversationPresence*`, `Sections`/`States` soltos.
4. **Orçamentos de densidade** — rota `*View`/`*Shell` ≤600; qualquer casca ≤2000.
5. **CODEMAP verdadeiro** — “onde muda X” → host/`Type.method`.
6. **Gates sempre verdes** — `AtlasCoreChecks` + `make build` (+ device quando UI).
7. **Zero produto** na missão de estrutura — WAVE/instrument proibidos no modo restructure.
8. **Protocolo que aguenta 24h** — Goal “até cancelar”, fila mecânica `DEBTS`, loop que reacorda.

### Lições que o server **deve** herdar (incluindo fracassos)

| Native | Server deve |
|---|---|
| Fuse cego → hosts 1500–1900 | **SPLIT primeiro** nos godfiles; fuse só peel same-concern |
| `god_hold` / Goal Done cedo | Goal até cancelar; soft debts contam |
| `docs(evidence) residual pass 8xx` spam | **PROIBIDO** commit sem diff de código/teste (exceto CODEMAP/ledger schema real) |
| Contar arquivos como vitória | Vitória = hops↓ · godfiles↓ · overlap OS↓ · mediana saudável preservada |
| Soft opcional | Soft = actionable |

### Tradução Native → Server

| Native | Server |
|---|---|
| `*Peel.swift` / `+Foo+Bar.swift` | services/commands one-method / `*Section.php` fragmentado sem boundary |
| `*Judgment.swift` | pure policy/decision services (sem I/O) |
| `*Surface` / `*Host` | façade/runtime entry de um domínio |
| `*Chrome` / `*Body` | shared presentation-adjacent helpers **ou** HTTP/CLI adapters finos |
| `spoken*` / `packFacts` | APIs públicas estáveis: `decide*` / `pack*Context` / `rank*` / `certify*` |
| `CODEMAP.md` | `docs/engineering-knowledge-base/` index + `app/Services/Ai/CODEMAP.md` (novo) |
| `make build` + CoreChecks | `php artisan test --parallel` (alvo) + testes do pacote tocado |
| Casca only | `app/Services/Ai/**`, Commands, Http finos; **não** inventar schema novo |

### Baseline medido (2026-07-22)

- `app/` PHP: **7 011** files · **~1 807 000** LOC  
- `app/Services/Ai`: **5 056** files · **~1 489 000** LOC (**82%** do app)  
- Top 5 pastas Ai = **~60%** do Ai: SelfConstruction, Aaeos, Programming, SoftwareCompanyStewardship, AutonomousEvolution  
- Godfiles: 8 arquivos >10k (**~7.8%** do app); campeão Readiness **29 744** LOC  
- Readiness sozinha: 32 files · **107k** LOC  
- Holding: 6 files · **44k** LOC  
- Commands: **937** · Models: **407** (models OK)  
- Loop-named Ai: **568** · ACDE mentions: **95**  
- Mediana Ai ~**174** LOC → a massa mediana está saudável; a **cauda** é o tumor

**Nota de inchaço alvo:** estrutural **8→≤3**; godfiles >5k **17→0**; overlap top-5 OS **explicitamente mapeado com um owner**.

---

## File structure (artefatos do programa)

| Path | Responsibility |
|---|---|
| `docs/superpowers/plans/2026-07-22-atlas-server-god-debulk.md` | Este plano-mestre |
| `docs/evidence/2026-07-22-atlas-server-god-debulk/LEDGER.md` | Estado + provas por ciclo |
| `docs/evidence/2026-07-22-atlas-server-god-debulk/DEBTS.md` | Fila mecânica (domain/OS rotation) |
| `docs/evidence/2026-07-22-atlas-server-god-debulk/OWNERSHIP.md` | Mapa “uma capability → um owner folder” |
| `docs/prompts/atlas-server-god-debulk.md` | Prompt de missão (análogo ao native restructure) |
| `docs/prompts/atlas-server-god-debulk-START.md` | Copy-paste Goal/Loop 24h |
| `docs/prompts/atlas-server-god-code-canon.md` | Canon de código PHP agent-optimal |
| `app/Services/Ai/CODEMAP.md` | Índice “onde muda X” → `Class::method` |
| `scripts/god-debulk-guard.sh` | Gate densidades + proíbe docs-only pass + proíbe Core-schema drift |
| `scripts/god-debulk-audit.php` | Emite contagens: godfiles, folders, orphans |

Child plans (criar ao entrar na fase):

- `docs/superpowers/plans/2026-07-22-god-debulk-p2-readiness-split.md`
- `docs/superpowers/plans/2026-07-22-god-debulk-p2-holding-split.md`
- `docs/superpowers/plans/2026-07-22-god-debulk-p2-gates-split.md`
- `docs/superpowers/plans/2026-07-22-god-debulk-p3-os-ownership.md`
- … (um por fase grande)

---

## Phase map (programa)

```
P0 Instrument + Canon + Guard
P1 Ownership map (OS layers) — docs + enforcement hooks
P2 SPLIT godfiles >5k (Readiness, Holding, Gates, Scanner, …)
P3 Collapse duplicate OS entrypoints (façade única por capability)
P4 Fuse micro-peels / twin services (same concern only)
P5 Command surface debulk (937 → families)
P6 Test monster split + delete obsolete mirrors
P7 Legacy ACDE/loop vocabulary hygiene (keep-list safe)
P8 CODEMAP completeness + deepen pass (APIs públicas unificadas)
```

**Done do programa (mensurável):**

1. 0 PHP em `app/Services` >5000 LOC  
2. 0 PHP em `app/Services` >2000 LOC sem issue aberta no DEBTS com owner  
3. Top-5 Ai folders com **OWNERSHIP** sem overlap de entrypoint público  
4. `app/Services/Ai/CODEMAP.md` cobre hot paths → `Class::method`  
5. Guard script verde no CI local pré-commit da missão  
6. `php artisan test --parallel` verde no subset tocado + smoke full semanal  
7. 0 commits `docs(evidence): … pass N` sem diff PHP/tests  
8. Commands: plano de famílias com ≤1 god-command >2000 (AiChat etc. split)  
9. Ledger: `godfiles_gt_5k: 0`, `structural_bloat_score` anotado ≤3  

---

### Task 1: Phase 0 — Evidence harness + audit script

**Files:**
- Create: `docs/evidence/2026-07-22-atlas-server-god-debulk/LEDGER.md`
- Create: `docs/evidence/2026-07-22-atlas-server-god-debulk/DEBTS.md`
- Create: `scripts/god-debulk-audit.php`
- Create: `scripts/god-debulk-guard.sh`

**Interfaces:**
- Consumes: baseline counts from plan header
- Produces: audit JSON/stdout with `godfiles_gt_5k`, `ai_folder_loc`, `docs_only_commit_smell`

- [ ] **Step 1: Create LEDGER.md** with schema:

```yaml
phase: boot|audit|act|prove|deepen
focus: <string>
actionable: <int>
last_commit: <hash|null>
godfiles_gt_5k: <int>
godfiles_gt_2k: <int>
ai_files: <int>
ai_loc: <int>
notes: |
  ...
commands: |
  <paste audit/guard>
```

- [ ] **Step 2: Create DEBTS.md** with rotation queue:

```yaml
pass: 1
queue_index: 0
```

Queue order (fixed):

0. SelfConstruction/Readiness godfiles  
1. Holding godfiles  
2. AgenticEngineeringOs / UniversalGates  
3. Kernel/Architecture scanners  
4. SoftwareCompanyStewardship entrypoints  
5. AutonomousEvolution entrypoints  
6. Aaeos entrypoints  
7. Programming façade overlap  
8. Commands godlist (>1000 LOC)  
9. Test monsters (>5000 LOC)  
10. Cross-cut naming (`decide*`/`pack*`/`certify*`)  
11. CODEMAP holes  

- [ ] **Step 3: Write `scripts/god-debulk-audit.php`**

```php
#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$ai = $root.'/app/Services/Ai';

function loc(string $path): int {
    $c = 0;
    $f = fopen($path, 'r');
    if ($f === false) return 0;
    while (fgets($f) !== false) $c++;
    fclose($f);
    return $c;
}

$god5k = [];
$god2k = [];
$byFolder = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ai));
foreach ($rii as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $path = $file->getPathname();
    $n = loc($path);
    $rel = substr($path, strlen($root)+1);
    $parts = explode('/', $rel);
    $folder = $parts[3] ?? '(root)';
    $byFolder[$folder] = ($byFolder[$folder] ?? 0) + $n;
    if ($n > 5000) $god5k[] = [$n, $rel];
    if ($n > 2000) $god2k[] = [$n, $rel];
}
rsort($god5k); rsort($god2k);
arsort($byFolder);

echo "godfiles_gt_5k=".count($god5k).PHP_EOL;
echo "godfiles_gt_2k=".count($god2k).PHP_EOL;
foreach (array_slice($god5k, 0, 20) as [$n, $rel]) {
    echo "GT5K\t$n\t$rel".PHP_EOL;
}
$i = 0;
foreach ($byFolder as $folder => $n) {
    echo "FOLDER\t$n\t$folder".PHP_EOL;
    if (++$i >= 15) break;
}
```

- [ ] **Step 4: Run audit and paste into LEDGER**

Run: `php scripts/god-debulk-audit.php | tee docs/evidence/2026-07-22-atlas-server-god-debulk/audit-baseline.txt`  
Expected: `godfiles_gt_5k` ≥ 14; SelfConstruction tops FOLDER list.

- [ ] **Step 5: Write `scripts/god-debulk-guard.sh`**

```bash
#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
FAIL=0
fail() { echo "GUARD FAIL: $*"; FAIL=1; }

# no service >5000 after Phase 2 starts enforcing; baseline mode warns
while IFS=$'\t' read -r tag n path; do
  [[ "$tag" == "GT5K" ]] || continue
  if [[ "${GOD_DEBULK_ENFORCE:-0}" == "1" && "$n" -gt 5000 ]]; then
    fail "godfile $path has $n lines (>5000)"
  fi
done < <(php scripts/god-debulk-audit.php | awk '/^GT5K/{print $1"\t"$2"\t"$3}')

# forbid docs-only residual pass commits as last commit during mission
subj=$(git log -1 --pretty=%s || true)
if echo "$subj" | rg -q 'residual pass [0-9]+'; then
  fail "vanity docs pass commit forbidden: $subj"
fi

if [[ "$FAIL" -ne 0 ]]; then exit 1; fi
echo "GUARD OK (god-debulk)"
```

- [ ] **Step 6: Commit harness**

```bash
chmod +x scripts/god-debulk-guard.sh scripts/god-debulk-audit.php
git add -- docs/evidence/2026-07-22-atlas-server-god-debulk scripts/god-debulk-audit.php scripts/god-debulk-guard.sh
git commit -m "$(cat <<'EOF'
docs(core): GOD-DEBULK Phase 0 evidence harness + audit/guard

EOF
)"
```

---

### Task 2: Phase 0 — Canon + START prompts (24h, sem Goal Done)

**Files:**
- Create: `docs/prompts/atlas-server-god-code-canon.md`
- Create: `docs/prompts/atlas-server-god-debulk.md`
- Create: `docs/prompts/atlas-server-god-debulk-START.md`

**Interfaces:**
- Consumes: native lessons in §0 of this plan
- Produces: `/goal` and `/loop 15m` literals with **até cancelar**

- [ ] **Step 1: Write canon** — mirror native sections adapted to PHP:

Mandatory sections in `atlas-server-god-code-canon.md`:
1. Norte (agent-optimal)  
2. Vocabulário fechado (suffixes): `Runtime`, `Service`, `Policy`, `Projector`, `Scanner`, `Gateway`, `Command`, `Facade`  
3. Method families: `decide*`, `pack*Context`, `rank*`, `certify*`, `project*`, `run*`  
4. Density table (150–800 target; split >2000; emergency >5000)  
5. Anti-patterns: docs-only pass++, fuse across OS owners, twin façades  
6. Gates commands  

- [ ] **Step 2: Write mission prompt** with state machine:

`BOOT → AUDIT → PICK(DEBTS) → ACT → PROVE → COMMIT → AUDIT`  
ACT order: Delete-dead → Rename honesty → **SPLIT godfile** → Unify public API → Fuse same-concern peel → CODEMAP  

- [ ] **Step 3: Write START** with copy-paste blocks:

`/goal` must contain exactly: `até cancelar`, `PROIBIDO marcar Goal Done`, `PROIBIDO residual pass docs`, `SPLIT before fuse`, `Não pare`.  
`/loop 15m` must reacorda and continue DEBTS — never park.

- [ ] **Step 4: Commit prompts**

```bash
git add -- docs/prompts/atlas-server-god-code-canon.md docs/prompts/atlas-server-god-debulk.md docs/prompts/atlas-server-god-debulk-START.md
git commit -m "$(cat <<'EOF'
docs(core): GOD-DEBULK Phase 0 canon + 24h START prompts

EOF
)"
```

---

### Task 3: Phase 1 — OWNERSHIP map (OS layers)

**Files:**
- Create: `docs/evidence/2026-07-22-atlas-server-god-debulk/OWNERSHIP.md`
- Modify: `docs/engineering-knowledge-base/atlas-cognition-operating-system.md` only if a factual ownership sentence is missing (prefer evidence doc first)
- Read: `docs/engineering-knowledge-base/atlas-ai-self-construction-os.md`
- Read: `docs/engineering-knowledge-base/atlas-autonomos-live-system.md`

**Interfaces:**
- Produces: table `capability → owner_folder → public_facade_class → forbidden_duplicate_entrypoints`

- [ ] **Step 1: Draft OWNERSHIP.md** with at least these rows:

| Capability | Owner folder | Public façade (target) | Absorb/alias from |
|---|---|---|---|
| Self-construction readiness projection | `SelfConstruction` | one ReadinessFacade (after split) | Readiness*Section monsters |
| Universal gates evaluation | `AgenticEngineeringOs` | `AtlasUniversalGatesEvaluator` (split internals) | duplicate gate runners |
| Autonomous evolution sessions | `AutonomousEvolution` | session runner façade | Stewardship AreaFocusLoop twins where duplicate |
| Software company stewardship | `SoftwareCompanyStewardship` | stewardship façade | overlapping Product Mode packets only if twin |
| AAEOS orchestration | `Aaeos` | AAEOS façade | AutonomousEngineering naming twins |
| Programming professional runtime | `Programming` / `ProgrammingRuntime` | single programming façade | pick one owner in doc |
| Holding / enterprise mandate | `Holding` | Holding façade | keep tiny file count, split monsters |
| Brain/task live system | per autonomos live canon | `atlas:brain:*` / `atlas:task:*` | do not delete AtlasLoop* keep-list |

- [ ] **Step 2: Mark each row `status: proposed|accepted`** — operator accepts before mass moves.

- [ ] **Step 3: Add DEBTS rule** — no fuse/move across two owners without OWNERSHIP update.

- [ ] **Step 4: Commit**

```bash
git add -- docs/evidence/2026-07-22-atlas-server-god-debulk/OWNERSHIP.md docs/evidence/2026-07-22-atlas-server-god-debulk/DEBTS.md
git commit -m "$(cat <<'EOF'
docs(core): GOD-DEBULK Phase 1 OS ownership map

EOF
)"
```

---

### Task 4: Phase 2a — SPLIT Readiness godfile #1 (vertical slice)

**Files:**
- Modify: `app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php` (29 744 LOC)
- Create: extracted collaborators under `app/Services/Ai/SelfConstruction/Readiness/` (names locked in steps)
- Test: `tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php` (run filtered)

**Interfaces:**
- Consumes: public methods currently called by Artisan/self-construction commands (discover via `rg`)
- Produces: façade thin; sections become real classes with single responsibility

- [ ] **Step 1: Inventory public API**

Run:

```bash
rg -n "function " app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php | head -80
rg -n "AtlasSelfConstructionReadinessService" app tests --glob '*.php' | head -60
```

Paste top callers into LEDGER.

- [ ] **Step 2: Write failing characterization test** (if missing) that calls the highest-traffic public method and freezes JSON shape / keys.

- [ ] **Step 3: Extract first slice** — move one cohesive private region (e.g. projection merge **or** provider dispatch — pick the largest `ReadinessProjection*Section` include pattern) into:

`app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionMerger.php`

Façade keeps method signature; delegates.

- [ ] **Step 4: Run filtered tests**

```bash
php artisan test --filter=AtlasAiSelfConstruction --parallel
```

Expected: PASS (or fix until PASS).

- [ ] **Step 5: Re-audit LOC**

```bash
wc -l app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php
php scripts/god-debulk-audit.php | rg 'Readiness|godfiles_gt_5k'
```

- [ ] **Step 6: Commit**

```bash
git add -- app/Services/Ai/SelfConstruction/Readiness tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php docs/evidence/2026-07-22-atlas-server-god-debulk
git commit -m "$(cat <<'EOF'
refactor(core): GOD-DEBULK SPLIT readiness projection merger slice

EOF
)"
```

Repeat Task 4 pattern until façade <2000 LOC (child plan tracks remaining slices).

---

### Task 5: Phase 2b — SPLIT Holding monsters

**Files:**
- `app/Services/Ai/Holding/ExternalActionMandateRegistryService.php` (22 832)
- `app/Services/Ai/Holding/AutonomousHoldingEnterpriseBuildoutService.php` (10 060)
- `app/Services/Ai/Holding/EnterpriseFlowFixtureActionRuntimeService.php` (7 057)
- Tests under `tests/Feature/Ai/Holding/`

**Interfaces:**
- Produces: `MandateRegistry` (data/policy) · `HoldingBuildoutRuntime` · `FixtureActionRuntime` with stable façades

- [ ] **Step 1: `rg` callers + classify pure registry vs runtime side-effects**
- [ ] **Step 2: Characterization tests for mandate lookup + one buildout path**
- [ ] **Step 3: Extract registry tables/maps first (usually biggest safe slice)**
- [ ] **Step 4: `php artisan test --filter=AutonomousHolding --parallel`**
- [ ] **Step 5: Commit `refactor(core): GOD-DEBULK SPLIT holding mandate registry`**

---

### Task 6: Phase 2c — SPLIT UniversalGates + Kernel scanner

**Files:**
- `app/Services/Ai/AgenticEngineeringOs/AtlasUniversalGatesEvaluator.php` (21 662)
- `app/Services/Ai/Kernel/Architecture/KernelArchitectureStaticScanner.php` (15 566)
- Tests: `tests/Unit/Ai/AgenticEngineeringOs/AtlasUniversalGatesEvaluatorTest.php`

**Interfaces:**
- Produces: gate families as `Gates/*Evaluator.php`; scanner languages/rules as `Scanner/*Rule.php`

- [ ] **Step 1: Split by gate family / language rule with façade unchanged**
- [ ] **Step 2: Keep test file green; later Task 10 splits the 17k test**
- [ ] **Step 3: Commit per family (`GOD-DEBULK SPLIT gates <family>`)**

---

### Task 7: Phase 3 — Entrypoint collapse (façades)

**Files:**
- Create façades listed in OWNERSHIP.md
- Modify callers gradually (alias old class extends façade or service provider binding)

**Interfaces:**
- Produces: one `*Facade`/`*Runtime` public entry per capability; old classes become `@deprecated` thin wrappers for one pass, then deleted when `rg` = 0

- [ ] **Step 1: For each OWNERSHIP row `accepted`, introduce façade + bind in Laravel provider**
- [ ] **Step 2: Redirect primary Artisan commands to façade**
- [ ] **Step 3: Delete wrappers only with `rg ClassName` → 0**
- [ ] **Step 4: Commit per capability**

---

### Task 8: Phase 4 — Fuse micro-peels (only after splits)

**Files:**
- Same-folder services <80 LOC with single caller inside same owner

**Rules (from native failure):**
- Same owner only  
- Net LOC ↓ or hops ≤2  
- Never fuse across SelfConstruction × Aaeos × AutonomousEvolution × Stewardship  
- Never push result >1200 if avoidable; hard fail >2000  

- [ ] **Step 1: Generate peel candidates**

```bash
php scripts/god-debulk-audit.php
# plus:
find app/Services/Ai -name '*.php' -print0 | xargs -0 wc -l | awk '$1<80 {print}' | head
```

- [ ] **Step 2: Fuse one cluster · prove · commit**
- [ ] **Step 3: Stop fuse session if last 3 fuses each net <30 LOC**

---

### Task 9: Phase 5 — Command surface debulk

**Files:**
- Start with: `app/Console/Commands/AiChatCommand.php` (4 737)
- `AtlasSoftwareCompanyStewardshipCommand.php` (3 909)
- `AtlasAiAutonomousHoldingCommand.php` (2 148)
- `AtlasAaeosCommand.php` (1 836)

**Interfaces:**
- Produces: command → thin CLI adapter calling façade; subcommands as dedicated classes

- [ ] **Step 1: Extract parser/IO from domain calls**
- [ ] **Step 2: One command family per commit**
- [ ] **Step 3: `php artisan list` smoke + filtered tests**

---

### Task 10: Phase 6 — Test monster split

**Files:**
- `tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php` (31 813)
- `tests/Unit/Ai/AgenticEngineeringOs/AtlasUniversalGatesEvaluatorTest.php` (17 206)
- `tests/Feature/Console/AtlasAaeosCommandTest.php` (12 678)

- [ ] **Step 1: Split by describe/region into `tests/.../SelfConstruction/*Test.php` without behavior change**
- [ ] **Step 2: Ensure ParaTest still green**
- [ ] **Step 3: Delete duplicated assertions only with proof**

---

### Task 11: Phase 7 — Legacy vocabulary hygiene

**Files:**
- Call sites mentioning `atlas:loop:` / ACDE (95 files) — migrate to `atlas:brain:*` / `atlas:task:*` per live canon
- Do **not** delete keep-list `AtlasLoop*` classes blindly

- [ ] **Step 1: Inventory operate-paths vs keep-list**
- [ ] **Step 2: Migrate docs+commands that still instruct `atlas:loop:*`**
- [ ] **Step 3: Commit `refactor(core): GOD-DEBULK retire atlas:loop operate paths`**

---

### Task 12: Phase 8 — CODEMAP + public API unify

**Files:**
- Create/update: `app/Services/Ai/CODEMAP.md`
- Unify method families across façades: `decide*`, `pack*Context`, `rank*`, `certify*`

- [ ] **Step 1: For each mobile/desktop hot path, add row Host → `Class::method`**
- [ ] **Step 2: Rename dishonest public methods (characterization tests first)**
- [ ] **Step 3: Guard check: CODEMAP paths exist (`rg`/php script)**

---

### Task 13: Operator 24h launch pack (after Phase 0)

**Files:** use START prompt only

- [ ] **Step 1: Kill any residual-pass Grok in atlas-native if still docs-spamming**
- [ ] **Step 2: Fresh terminal in `atlas-server` with START `@` attachments**
- [ ] **Step 3: Paste `/goal` + `/loop 15m` from START**
- [ ] **Step 4: Operator rule: if last 10 commits are docs-only → STOP and paste SPLIT redirect**

---

## Anti-patterns (instant halt)

1. PHP→Swift rewrite proposals inside this program  
2. `docs(evidence): residual pass N` without PHP/test diff  
3. Fuse that creates a new file >2000 LOC  
4. Deleting `AtlasLoop*` by prefix without keep-list check  
5. Goal Done / god_hold  
6. New product WAVE / new domain folder “while debulking”  
7. Chasing file-count down while LOC and hops stay flat or worsen  

---

## Success scoreboard (track in DEBTS)

| Metric | Baseline | Target |
|---|---|---|
| `godfiles_gt_5k` | ~17 | **0** |
| `godfiles_gt_2k` | ~50+ | **≤10** with owners |
| Ai folder ownership overlaps (entrypoints) | high | **0 undocumented** |
| Structural bloat score | ~8/10 | **≤3/10** |
| Vanity pass commits | native lesson | **0** |
| Median Ai service LOC | ~174 | **keep 120–250** |

---

## Self-review (plan author)

1. **Spec coverage:** Native analog, SPLIT-first, ownership, commands, tests, legacy, CODEMAP, 24h harness, anti-pass-spam — all have tasks.  
2. **Placeholders:** none intentional; Phase 2+ uses repeatable slice pattern with exact first files.  
3. **Type consistency:** façade/ownership terms reused across tasks.  
4. **Scope honesty:** full 1.8M LOC cannot be one linear 2–5 min checklist — child plans required at Phase 2+; master plan locks law + order.

---

## Execution handoff

Plan complete and saved to `docs/superpowers/plans/2026-07-22-atlas-server-god-debulk.md`.

**Two execution options:**

1. **Subagent-Driven (recommended)** — fresh subagent per task, review between tasks  
2. **Inline Execution** — this session executes Task 1→2→… with checkpoints  

**Which approach?**
