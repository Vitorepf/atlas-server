---
id: atlas-task-serving-runbook
type: engineering_knowledge
title: Atlas Task Serving Runbook
status: active
category: runbook
priority: 94
summary: Operational contract for Atlas Task Fabric bootstrap workers, shared local main execution, scoped commits, give-back learning and the final Atlas-native autonomy target.
tags:
  - atlas-ai
  - self-construction
  - task-fabric
  - task-serving
  - autonomous-engineering
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-task-serving-runbook
graph_title: Atlas Task Serving Runbook
graph_world: atlas
graph_layer: module
graph_kind: runbook
graph_parent: atlas-autonomous-engineering-government
graph_status: active
graph_source: repo
owner: atlas-ai
---

# Atlas Task Serving — Runbook (IAs buscam tasks e resolvem, hoje)

## Resumo

> **Arquitetura final:** Task serving e task packets agora pertencem ao
> `Task Fabric / Task Economy` dentro do `Atlas Autonomous Engineering
> Government` (`docs/engineering-knowledge-base/atlas-autonomous-engineering-government.md`).
> Eles vieram para ficar. O modo atual, em que Claude Code/Codex/Cursor
> externos resolvem packets, é o bootstrap operacional. Na forma final,
> workers são músculos substituíveis; Atlas verifica server-side, aprende com
> give-back e integra via Verification Court + Merge / Release Governor.

> O Atlas é o **servidor de tasks**. Qualquer IA (Claude Code, Codex, Cursor, KimiK2, MiniMax, Hermes — todas
> iguais, `client_id` opaco) pede uma task, implementa, e **resolve commitando na main**. As IAs trabalham
> TODAS na **mesma branch `main` local**: cada uma edita só os arquivos da sua task e commita **só os arquivos
> dela**. É seguro porque o serving entrega tasks com `allowed_files` **disjuntos** (conflict-free provado) —
> duas IAs nunca tocam o mesmo arquivo.

> **Lei de simplicidade:** a `main` local compartilhada não é gambiarra; é a
> topologia simples que funcionou. Worktrees/sandboxes não são o padrão. O
> Atlas deve manter conflict-free por `allowed_files`, commit escopado pelo
> próprio Atlas e gates server-side, em vez de resolver coordenação com
> complexidade extra.

> **Destino final:** Claude Code/Codex/Cursor são bootstrap. A forma final é o
> Atlas executar esse mesmo ciclo 100% sozinho e Atlas-native, sem depender do
> operador, de nenhum humano ou de plataformas externas. O Atlas deve
> criar/priorizar tasks, executar, verificar, integrar/rejeitar, aprender, fazer
> rollback e atualizar docs/conhecimento sem precisar que alguém mantenha a fila
> viva ou decida o próximo passo dentro de um escopo admitido.
> Enquanto houver workers externos, eles são músculos temporários e
> substituíveis; o contrato permanente é o Task Fabric simples, mainline local e
> verificação do Atlas.

> **Regra dura de autonomia:** o estado final aceito é `final_runtime_owner=atlas_native`
> e `steady_state_runtime_owner=atlas_server`. Operador, humano, Claude Code,
> Codex, Cursor ou provider externo podem ajudar no bootstrap, observabilidade ou
> emergência, mas não podem ser necessários para o progresso normal 24/7. Se a
> fila só anda porque alguém cria tasks manualmente, aprova gates ordinários,
> carrega contexto, roda testes ou mantém uma plataforma externa executando o
> coração do ciclo, o Atlas ainda não chegou no objetivo.

## Papel no Atlas

Este runbook é o contrato operacional do `Task Fabric` em modo bootstrap. Ele
mostra como workers externos pegam packets hoje, enquanto a arquitetura final
move execução, verificação, aprendizado e manutenção da fila para Atlas-native
workers no servidor Atlas.

## Onde Se Encaixa

```text
Atlas Autonomous Engineering Government
-> Atlas Self-Construction OS
-> Task Fabric / Task Economy
-> Maestro
-> Workers bootstrap ou Atlas-native
-> Verification Court
-> Merge / Release Governor
-> Receipts / Learning / Knowledge Sync
```

## Contratos

O contrato permanente é simples: `shared_local_main_with_scope_lock`,
`allowed_files` exato, commit escopado pelo Atlas, gates server-side e
`final_runtime_owner=atlas_native`. Workers externos são substituíveis; o Atlas
continua sendo o servidor, verificador, committer e dono do runtime final.

## Escopo de Implementacao

Este documento governa `atlas:task next`, `atlas:task report`,
`atlas:task:enqueue`, `atlas:task:replenish`, health/sweep do serving e o prompt
de worker. Ele não autoriza worktrees/sandboxes por padrão, não autoriza git
manual e não transforma o operador em dependência do ciclo normal.

## Dependencias

- `AtlasTaskPacketQualityInspector` bloqueia packets malformados antes do
  serving.
- `AgentControlPlaneTaskPacketBuilder` emite o contrato de simplicidade e
  autonomia Atlas-native.
- `AtlasTaskScopedCommitter` faz commit apenas do escopo permitido.
- `Atlas Autonomous Engineering Government` define a autoridade arquitetural
  acima deste runbook.

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

## 2b. Como Isto Vira Task Fabric Na Arquitetura Final

No `Atlas Autonomous Engineering Government`, este serving evolui para o
`Task Fabric / Task Economy` preservando a mesma simplicidade operacional:

```text
Control Plane / Strategy / Architecture
-> Task Fabric packetiza
-> Maestro agenda e roteia
-> Worker executa na main local compartilhada, só allowed_files
-> Verification Court revalida
-> Merge Governor integra ou rejeita
-> Learning Transfer atualiza futuras tasks
```

Cada packet final deve carregar:

- objetivo;
- owner scope;
- `allowed_files` e forbidden files/actions;
- dependências e wave;
- risk class;
- required context freshness;
- acceptance criteria;
- required evidence;
- rollback;
- server-side verification plan;
- blocker/give-back policy.

O packet final também deve declarar que não precisa de worktree/sandbox por
padrão. Se uma task exigir isolamento excepcional, isso precisa estar explícito
como risk exception, não como fluxo normal.

O gate vivo que aplica isso é `AtlasTaskPacketQualityInspector`. Ele deve
recusar, antes do serving, qualquer packet que tente transformar operador,
humano, Claude Code, Codex, Cursor ou outro provedor externo em dependência
permanente do runtime. Ele também deve recusar worktree/sandbox/branch como
política default de task. `AgentControlPlaneTaskPacketBuilder` deve continuar
emitindo `shared_local_main_with_scope_lock` e `atlas_native` como contrato
normal; isolamento só entra como exceção explícita de risco.

`give_back` é dado de aprendizado, não lixo: fora de escopo, capability já
existente, acceptance contraditório, teste impossível, dependência morta e
contexto insuficiente devem alimentar o Learning Transfer System. No bootstrap,
um operador pode observar e corrigir casos novos; no estado final, Atlas deve
quarentenar, classificar, reparar templates/packets e prevenir re-serves sem
depender de uma pessoa.

Packets novos devem carregar o contrato mecânico de autonomia:
`operator_dependency_allowed=false`, `human_dependency_allowed=false` e
`external_provider_dependency_allowed=false`. O inspector deve bloquear qualquer
packet que tente inverter isso para o estado estável.

No estado final, nem o replenisher nem o repair loop podem depender do operador.
Fila vazia, poison packet, dependência morta, acceptance contraditório e
allowed_files errado são eventos normais de runtime: Atlas deve detectar,
quarentenar, reparar ou recriar packets por conta própria. O operador pode
observar ou interromper; não pode ser o motor que mantém a fila viva.

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

## Fluxo

O prompt de worker executa este ciclo: puxar packet, validar se é real,
implementar somente `allowed_files`, provar, reportar success/give_back e repetir
até a fila secar ou o serving desativar.

```bash
# 1) PUXA a próxima task (id opaco seu; qualquer string)
php artisan atlas:task next --client="claude-code-1" --json
#   → { status: "served", task: { task_packet_id, lease_id, objective, allowed_files, acceptance_criteria, required_evidence } }
#   → status "no_claimable_task"      = fila vazia para o worker bootstrap; o replenisher Atlas-native deve manter a fila viva no estado final
#   → status "no_self_sufficient_task" = só sobrou task malformada; Task Fabric/Learning Transfer deve reparar a causa no estado final

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

## Regras para IA

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

## Evidencias

| Garantido (testado) | Cap honesto (não finjo) |
|---|---|
| Conflict-free: 2 IAs nunca pegam tasks com arquivos colidentes | Originação 100%-autônoma de alta alavancagem em código maduro ainda está em transição; enqueue manual é bootstrap, não destino |
| Cada IA commita só os seus arquivos (na main compartilhada) | A IA roda os testes; o Atlas não re-executa os testes server-side (confia no gate da IA + escopo do commit) |
| Give-back não drena a fila; reaper recupera lease morta | — |
| Cliente frio só recebe task implementável (quality gate) | — |
| Commit recusa alvo pétreo; lock serializa commits concorrentes | — |

## Riscos

Para o estágio final 24/7, os caps acima viram requisitos obrigatórios de
hardening sem abandonar a simplicidade: server-side verification default por
classe de risco, merge/release governor, rollback operacional, context
freshness gate e learning transfer. A evolução correta é internalizar os
workers no Atlas, não trocar a main compartilhada por branch/worktree theater.
O critério de conclusão é direto: se remover Claude Code, Codex, Cursor,
provedores externos e o operador do caminho normal ainda impedir progresso
ordinário, o Task Fabric ainda está em bootstrap, não em autonomia final.

## Exemplos

Os exemplos abaixo são a superfície mínima platform-free. O mesmo contrato pode
ser exposto por CLI, MCP ou worker Atlas-native, desde que preserve client id,
lease, task id, allowed files, evidence e report final.

- `atlas:task next --client=<id> [--tag=..]* [--json]`
- `atlas:task report --client=<id> --task=<id> --lease=<id> --outcome=success|failed|give_back [--commit] [--json]`
- MCP equivalente: `atlas_next_task` / `atlas_task_report` (mesmo serviço, outro transporte).

## Proximas Acoes

- Internalizar workers Atlas-native para executar packets sem depender de
  Claude Code, Codex, Cursor, operador ou provider externo.
- Mover verificação server-side, rollback, Learning Transfer e Merge Governor
  para o caminho padrão de 24/7.
- Manter a fila self-sufficient por sweep/health, bloqueando poison packets antes
  de servir.
