# L3 (Obra #19) — guard do reset do soak: JÁ SATISFEITO (verificação, sem código novo)

**Data:** 2026-07-07 · **Veredicto:** invariante já enforçada + testada adversarialmente na main. Nenhum código novo necessário (ponytail rung-1: não adicionar guard redundante).

## O hazard (do FACTS em `.claude/workflows/loop-heavywork-design.js:34`)
> "the soak's auto-merge does git reset/checkout on the real tree → uncommitted tracked edits are wiped."

## Por que L3 já está entregue
Os **únicos** dois `reset --hard` que tocam a árvore viva do repo já recusam sobre árvore suja, ANTES de resetar:

- `AtlasLoopObraAutoMergeService.php:141` — `working_tree_not_clean_refused (never reset --hard over uncommitted work)`; `isCleanTree()` (`:341`, `git status --porcelain`) é a pré-condição do `reset --hard` (`:335`).
- `AtlasLoopCycleGitContract.php:233` — `working_tree_not_clean_refused (never merge over uncommitted work)` sob lock exclusivo; o `reset --hard` de abort (`:253`) só volta ao snapshot in-lock (`$mainBefore`), main byte-idêntica.

A guarda existente é **mais forte** que o pedido de L3 ("porcelain fora do escopo ⇒ recusa"): recusa QUALQUER sujeira, não só a fora do escopo.

## Prova (teste adversarial que É o gate de L3)
`tests/Feature/Loop/AtlasLoopObraAutoMergeServiceTest.php:202`
`test_dirty_working_tree_is_refused_never_resets_over_uncommitted_work` — cria `UNCOMMITTED.txt`, chama `autoMerge`, assere `merged=false`, `reason` contém `working_tree_not_clean`, e **`UNCOMMITTED.txt` continua existindo** (WIP não clobberado). Passa na main atual (1/1, 4 assert).

Gate de L3 ("reset nunca varre WIP alheio — teste adversarial") ✅ já coberto.
