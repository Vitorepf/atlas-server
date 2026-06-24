# Atlas Task Serving — Runbook (IAs buscam tasks e resolvem, hoje)

> O Atlas é o **servidor de tasks**. Qualquer IA (Claude Code, Codex, Cursor, KimiK2, MiniMax, Hermes — todas
> iguais, `client_id` opaco) pede uma task, implementa, e **resolve commitando na main**. As IAs trabalham
> TODAS na **mesma branch `main` local**: cada uma edita só os arquivos da sua task e commita **só os arquivos
> dela**. É seguro porque o serving entrega tasks com `allowed_files` **disjuntos** (conflict-free provado) —
> duas IAs nunca tocam o mesmo arquivo.

## 1. Operador — ligar o serving (uma vez)

```bash
# Liga SÓ o serving de tasks. NÃO arma o loop autônomo (campanha/keepalive/auto-merge ficam off → zero token-burn).
php artisan atlas:task:serving on
php artisan atlas:task:serving status     # confirma: task-serving: ON
```

O switch é independente do `atlas:loop:on` (que governa o loop autônomo). Pra desligar tudo: `atlas:task:serving off`.

## 2. Operador — abastecer a fila com tasks reais

Uma task por comando:
```bash
php artisan atlas:task:enqueue \
  --objective="implementar X em Y" \
  --allow=app/Services/Foo/Bar.php \
  --allow=app/Services/Foo/Baz.php \
  --read=app/Services/Foo/Contract.php \
  --accept="o teste FooBarTest passa" \
  --evidence=tests_or_gates_result \
  --json
```

Ou em lote (`tasks.json` = `[{objective, allowed_files, acceptance_criteria, required_evidence, scope_in?}]`):
```bash
php artisan atlas:task:enqueue --file=tasks.json --json
```

**Quality gate:** uma task sem objetivo/`allow`/aceite/evidência, ou com diretório no `--allow` (em vez de arquivo
concreto), é **rejeitada na porta** com as deficiências — você corrige o spec em vez de encalhar uma IA depois.

> **Fonte complementar (model-bound):** o cérebro também pode originar tasks de material óbvio do escopo
> (orphans/clones/doc-gaps). Em código maduro isso **seca rápido** — por isso o enqueue manual é a fonte
> principal. Não conte com fila infinita automática.

## 3. Cada IA — VOCÊ SÓ COLA UM PROMPT

A sua única ação numa sessão Claude Code / Codex / qualquer: **colar um prompt**. Gere-o (client id único por sessão):

```bash
php artisan atlas:task:worker-prompt                 # imprime o prompt pronto pra colar (id auto-único)
php artisan atlas:task:worker-prompt --client=codex-1 # ou com um id seu
php artisan atlas:task:worker-prompt --keep-polling   # worker espera 60s e tenta de novo quando a fila esvazia
```

Cole a saída numa sessão de IA. Ela passa a rodar o loop sozinha: puxa task → implementa só os `allowed_files` →
roda os testes → `report --commit` (o Atlas commita o escopo dela) → próxima — até a fila secar. Rode o comando
1× por sessão que você abrir (cada uma recebe um id distinto, nunca colidem).

## 3b. O loop que o prompt executa (referência — a IA faz isso sozinha)

```bash
# 1) PUXA a próxima task (id opaco seu; qualquer string)
php artisan atlas:task next --client="claude-code-1" --json
#   → { status: "served", task: { task_packet_id, lease_id, objective, allowed_files, acceptance_criteria, required_evidence } }
#   → status "no_claimable_task"      = fila vazia (espere/peça pro operador enfileirar)
#   → status "no_self_sufficient_task" = só sobrou task malformada (o operador conserta o spec)

# 2) IMPLEMENTA — edite SOMENTE os arquivos em allowed_files. Rode os testes/gates do acceptance.

# 3) RESOLVE — o Atlas commita EXATAMENTE os seus allowed_files como o SEU commit:
php artisan atlas:task report --client="claude-code-1" \
  --task=<task_packet_id> --lease=<lease_id> --outcome=success --commit --json
#   → { status: "resolved", lease_closed: true, commit_sha, files_committed: [...] }
#   → { status: "commit_failed", reason } = nada mudou no escopo OU conflito de lock; a lease FICA com você, corrija e repita

# 4) Não conseguiu? Devolve a task pro pool (outra IA pega):
php artisan atlas:task report --client="claude-code-1" --task=<id> --lease=<lease> --outcome=give_back --json

# 5) Pega a próxima. Repete.
```

## 4. Regras DURAS pra cada IA (branch compartilhada)

- **NUNCA rode `git` você mesma** — nem `git add -A`, `git commit`, `git reset`, `git checkout`, `git stash`,
  `git pull/push`. O `--commit` do Atlas faz o commit escopado (só os seus `allowed_files`, sob lock serializado).
  Se você rodar `git add -A`, leva o trabalho não-commitado das outras IAs junto. **Proibido.**
- **Edite SOMENTE os `allowed_files`** da sua task. Não toque em nenhum outro arquivo (outra IA está nele).
- **Rode os gates/testes** antes do `report --commit` (o `acceptance_criteria`/`required_evidence` é seu contrato).
- O Atlas **recusa** commitar um arquivo da lista pétrea (juiz/guard/master switch do loop) — nunca peça isso.

## 5. Operador — observar

```bash
php artisan atlas:task:health            # distribuição da fila, leases, lease-leak, flags de integridade
php artisan atlas:task:swarm-proof --scenario=mixed --clients=8   # prova conflict-free sob N IAs reais
```

## 6. O que está garantido (provado) e o que não está

| Garantido (testado) | Cap honesto (não finjo) |
|---|---|
| Conflict-free: 2 IAs nunca pegam tasks com arquivos colidentes | Origação 100%-autônoma de alta alavancagem em código maduro (model-bound) — use enqueue manual |
| Cada IA commita só os seus arquivos (na main compartilhada) | A IA roda os testes; o Atlas não re-executa os testes server-side (confia no gate da IA + escopo do commit) |
| Give-back não drena a fila; reaper recupera lease morta | — |
| Cliente frio só recebe task implementável (quality gate) | — |
| Commit recusa alvo pétreo; lock serializa commits concorrentes | — |

## 7. Contrato (2 verbos, schema fixo, platform-free)

- `atlas:task next --client=<id> [--tag=..]* [--json]`
- `atlas:task report --client=<id> --task=<id> --lease=<id> --outcome=success|failed|give_back [--commit] [--json]`
- MCP equivalente: `atlas_next_task` / `atlas_task_report` (mesmo serviço, outro transporte).
