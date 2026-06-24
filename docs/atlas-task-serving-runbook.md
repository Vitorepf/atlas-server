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

**IMPORTANTE — fila dedicada (isola do lixo de certificação):** a fila do Agent Control Plane está afogada em
centenas de milhares de registros de probe de certificação. Pra o serving NÃO servir esse lixo, ponha no `.env`:
```
ATLAS_TASK_SERVING_QUEUE_DISK=atlas_serving
```
Isso dá ao serving (next/report/enqueue/replenish/health) a PRÓPRIA fila limpa em `storage/app/atlas/task-serving/`.
Sem isso (default `local`), o serving usa a fila compartilhada poluída.

O switch é independente do `atlas:loop:on` (que governa o loop autônomo). Pra desligar tudo: `atlas:task:serving off`.

## 2. Abastecer a fila — o ATLAS estrutura a lista sozinho (a Parte 2)

O Atlas **lê a sua compreensão completa de um escopo e estrutura a lista de tasks ele mesmo** — você NÃO descreve
task por task. Defina o escopo e deixe o runtime encher a fila e mantê-la cheia:

```bash
# LISTA COMPLETA (recomendado): doc-gaps + órfãos resolvíveis — o espelho completo do que o escopo precisa evoluir
php artisan -d memory_limit=4096M atlas:task:replenish --scope=app/Services/Ai/AutonomousEvolution --with-orphans --target=50

# manter cheia pra sempre (watch): re-deriva conforme o código evolui
php artisan -d memory_limit=4096M atlas:task:replenish --scope=app/Services/Ai/AutonomousEvolution --with-orphans --target=50 --watch --every=120

# ver o que ele estruturaria, sem enfileirar nada:
php artisan -d memory_limit=4096M atlas:task:replenish --scope=app/Services/Ai/AutonomousEvolution --with-orphans --dry-run
```

Cada task que ele estrutura é **fundada num símbolo REAL do escopo** (a compreensão é o oráculo — nunca cita algo
que não existe), auto-suficiente (objetivo + allowed_files exato + aceite + evidência). Tipos de evolução REAL que
ele extrai:
- **doc-gap** — capacidade que os docs canônicos exigem e nenhum símbolo provê. Task = classe + teste novos
  (single-file-resolvível). Filtro repo-wide mata o falso-positivo (classe que já existe fora do escopo ou sob
  nome mais completo) — sem give-back-bait.
- **orphan** — classe construída mas sem nenhum caller (a dívida de wiring real). Moldada **resolvível**: o
  `allowed_files` carrega o órfão **+ o sítio de integração aterrado** (os callers de produção do irmão análogo já
  ligado — "ligue do jeito que o irmão dele está ligado, aqui"). Conflict-safe: o serving serializa órfãos que
  miram o mesmo integrador. Órfão sem sítio aterrado é **deferido** (não vira give-back-bait).

**Anti-Goodhart:** ele NUNCA cria task de proxy/faxina (cobertura/ciclomática) — só evolução de capacidade real.
Dedup + watermark: re-rodar não duplica; para no alvo.

**Limite honesto:** a lista é tão vasta quanto o material real do escopo num snapshot (ex.: SelfConstruction →
74 tasks; AutonomousEvolution → dezenas), e se re-deriva conforme o código evolui. Não é literalmente infinita
sem o originador model-bound (um booster à parte) ou um escopo maior — mas é vasta e se reabastece sozinha.

### (Opcional) override manual — você descreve uma task específica
```bash
php artisan atlas:task:enqueue --objective="..." --allow=app/Services/Foo/Bar.php --accept="..." --evidence=tests_or_gates_result --json
```
Mesmo quality-gate. Use só quando quiser injetar uma task pontual fora do que o cérebro estrutura.

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
