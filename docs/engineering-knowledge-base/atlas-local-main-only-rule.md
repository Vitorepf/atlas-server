---
id: atlas-local-main-only-rule
type: engineering_knowledge
title: Atlas — Branch local main ONLY (regra pétrea para todas as IAs)
status: active
category: governance
priority: 100
doc_schema: atlas_canonical_module_doc.v1
summary: "Toda IA (Cursor, Claude Code, Codex, Composer, cloud agent, subagent) trabalha exclusivamente na branch local main deste repo. Zero branch de obra, zero merge, zero pull-merge."
tags: [atlas-ai, git, main-only, governance, acos-max]
---

# Branch local `main` ONLY

## Regra

Neste repo (`atlas-server`), **toda IA trabalha exclusivamente na branch local `main`.**

## Obrigatório

1. Antes de qualquer commit: `git branch --show-current` deve imprimir `main`. Se não for → `git checkout main` (sem criar branch).
2. Commits escopados: `git add -- <só arquivos do slice>` + `git commit`. **NUNCA** `git add -A`.
3. **NUNCA** criar branch de feature/obra (`git checkout -b`, worktree de obra, cloud-agent branch para esta obra).
4. **NUNCA** merge de obra (`git merge`, Pull que cria merge commit, “Commit and Merge” da UI).
5. **NUNCA** `git pull` / `git pull --rebase` sem o operador pedir. Se `main` local e `origin/main` divergirem durante ACOS Max: **a main local é a fonte da verdade**; reportar e pedir OK explícito para `git push --force-with-lease origin main`. Não “resolver” com merge.
6. Se a UI/git entrar em `MERGE_IN_PROGRESS` por pull acidental: `git merge --abort` imediatamente e reportar.
7. Subagents herdam esta regra.

## Espelho Cursor

`.cursor/rules/local-main-only.mdc` (`alwaysApply: true`) — mesma regra para o Cursor.

## Espelho playbook

`atlas-acos-max-implementation-playbook-v1.md` regra **A1**.
