# EXECUTE START — implementar GOD Debulk (atlas-server)

> Preferência do operador após falha Sol: use **`atlas-server-god-debulk-EXECUTE-CLAUDE.md`**.  
> Este START ainda serve para Codex **sem** Meta/SelfConstruction dispatcher.  
> Cole com **Acesso completo** · cwd `atlas-server` · branch `main`.  
> **Gate:** `EXECUTE GOD-DEBULK` nesta mensagem.  
> **ANTI-TRAP:** ignore pacotes `AIP-*-DOCS-*` / `RES-*` / Goal `blocked` por scope validator.

---

## `/goal` (cole literal)

```
ATLAS-SERVER · EXECUTE GOD-DEBULK · GPT 5.6 Codex · até cancelar.

Você está em atlas-server (Laravel/PHP 8.4), branch main, acesso completo local.
Missão ÚNICA: IMPLEMENTAR o GOD Debulk a partir do plano + META-FINDINGS.
Há outro Codex (META) que só documenta. Você NÃO é o META. Você executa actions.

NÃO é reescrever em Swift. NÃO é feature/produto. NÃO é dual A/B. NÃO é vanity residual pass.
NÃO é Goal Done. NÃO é god_hold. Trabalhe até o operador cancelar.

BASE OBRIGATÓRIA (leia nesta ordem):
1) docs/evidence/2026-07-22-atlas-server-god-debulk/LAYOUT.md
2) docs/prompts/atlas-server-god-debulk-EXECUTE.md
3) docs/superpowers/plans/2026-07-22-atlas-server-god-debulk-INTENT.md
4) docs/superpowers/plans/2026-07-22-atlas-server-god-debulk-COMPLETE.md
5) docs/evidence/2026-07-22-atlas-server-god-debulk/META-FINDINGS/ (fonte de trabalho)
6) docs/evidence/2026-07-22-atlas-server-god-debulk/OWNERSHIP.md
7) docs/engineering-knowledge-base/atlas-autonomos-live-system.md (keep-list AtlasLoop*)
8) docs/evidence/2026-07-22-atlas-server-god-debulk/EXEC-DEBTS.md (crie se faltar)
9) docs/evidence/2026-07-22-atlas-server-god-debulk/EXEC-LEDGER.md (crie se faltar)

ALVO 10/10 (falsificável — COMPLETE §1):
A Achar · B Contexto · C Entender · D Editar · E Evoluir · F Provar · G Docs-mapa
Done do programa = A–G 10/10 + 0 PHP app/tests >2000 + unmapped=0 — com gates, não vibes.

PROTOCOLO (pétreo):
BOOT → SYNC_DEBTS a partir dos findings (s0→s1→SPLIT…) → PICK um foco → PREFLIGHT testes
→ ACT uma op → PROVE acceptance → COMMIT escopado → LEDGER → próximo. Não pare.

ORDEM DE OPS (default / ordered_worklist do finding):
TEST → BUGFIX → SPLIT → OWNER → EXTRACT → RENAME → FUSE → CODEMAP → PERF → DELETE
Lei: SPLIT monsters ANTES de fuse em massa. Nunca fuse→godfile (>800 hot / >2000 any).
Nunca criar novo *Section mega-file. Keep-list: NÃO deletar AtlasLoop* por prefixo.

BOOTSTRAP se EXEC-DEBTS vazio:
Comece em META-FINDINGS/A1--SelfConstruction.md · finding do
AtlasSelfConstructionReadinessService.php · worklist TEST→BUGFIX→SPLIT→…

COMMITS:
refactor(core)|test(core)|docs(core): GOD-DEBULK <wave> <focus>
main local. Stage explícito. Sem git add -A. Sem merge.

PROIBIDO:
- Goal Done / god_hold / residual pass vazio
- PHP→Swift / produto
- Ignorar acceptance do finding
- Inchar META-LEDGER / mega FINDINGS
- Declarar 10/10 sem gates

Não peça permissão. Não pare. Próximo ciclo agora.
```

---

## `/loop` (cole literal — rede 15–20m)

```
/loop 15m Continue EXECUTE GOD-DEBULK.
Abra EXEC-LEDGER + EXEC-DEBTS + o próximo action em META-FINDINGS.
PREFLIGHT testes do contrato → ACT uma op → PROVE acceptance (rg/wc/artisan test) → COMMIT escopado → atualize EXEC-LEDGER → próximo.
PROIBIDO Goal Done. PROIBIDO fuse→godfile. PROIBIDO delete AtlasLoop* por prefixo. PROIBIDO git add -A.
Não peça permissão. Não pare.
```

---

## Anexos `@` sugeridos

```
@docs/prompts/atlas-server-god-debulk-EXECUTE.md
@docs/evidence/2026-07-22-atlas-server-god-debulk/LAYOUT.md
@docs/superpowers/plans/2026-07-22-atlas-server-god-debulk-COMPLETE.md
@docs/evidence/2026-07-22-atlas-server-god-debulk/META-FINDINGS/A1--SelfConstruction.md
@docs/engineering-knowledge-base/atlas-autonomos-live-system.md
```

---

## Mensagem única (se a UI só tiver um campo)

Cole isto **como primeira mensagem** (equivale a goal+loop). Só use se o operador autorizou EXECUTE:

```
EXECUTE GOD-DEBULK · atlas-server · até cancelar.

Você é o Codex IMPLEMENTADOR (não o META).
Obedeça docs/prompts/atlas-server-god-debulk-EXECUTE.md + LAYOUT.md.
Consuma META-FINDINGS (comece A1--SelfConstruction.md).
Crie/continue EXEC-DEBTS + EXEC-LEDGER.
Ordem: TEST → BUGFIX → SPLIT → OWNER → EXTRACT → CODEMAP → PERF.
Densidade: 0 PHP >2000; hot ≤800. SPLIT before fuse. Sem *Section monstro novo.
Keep-list AtlasLoop* intacta. Commits refactor(core)|test(core)|docs(core): GOD-DEBULK …
main local · stage explícito · sem git add -A.
Não Goal Done. Não pare. Próximo ciclo: characterization tests do AtlasSelfConstructionReadinessService status/write honesty.
```

---

## Se o META ainda estiver varrendo

Pode executar **em paralelo** desde que:

1. O operador disse `EXECUTE GOD-DEBULK`  
2. Você só toca paths com finding YAML + `actions` + `acceptance`  
3. Você claims o path em `EXEC-DEBTS` (não dispute o mesmo arquivo com o META writer)

Se não houver nenhum finding acionável ainda: monte P0 tooling (audit/guard/codemap scripts + CODEMAP esqueleto) e espere o próximo YAML do META — **sem** inventar refactor cego.
