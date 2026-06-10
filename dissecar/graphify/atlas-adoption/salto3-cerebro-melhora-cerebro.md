# Salto 3 — o cérebro melhora o cérebro (loop de auto-melhoria governado, recursivo)

Estado: **PRODUTO, PROVADO VIVO NO DEV** (2026-06-10). Migrado, recursão provada ponta-a-ponta contra o pgsql real + cérebro real, custo-zero (LLM stubbado). Não commitado (decisão do operador).

> Doc de operador. A árvore canônica (`docs/engineering-knowledge-base`) exige frontmatter de cartografia; este doc vive em `dissecar/` de propósito — é narrativa de adoção, não fonte canônica.

## O que é

Salto 3 é a **síntese** do trabalho da sessão (Salto 1 = cérebro AURG, Salto 2 = mãos governadas), não uma reconstrução. É o loop fechado:

```
detecta melhoria do Atlas  (AtlasSelfConstructionDetector: TODO/FIXME + gaps do operador, file-anchored)
  → missão BRAIN-ANCHORED       (AtlasMissionService.run: contexto AURG do sinal entra no prompt → entrega governada → desfecho gravado DE VOLTA no cérebro)
  → GATE DE RELEVÂNCIA           (AtlasSelfImprovementRelevanceGate: OUT-OF-PROCESS, não-gameável pelo prompt)
  → RE-CHECK ADVERSARIAL         (AtlasSelfImprovementAdversarialRecheck: default-refute, independente)
  → branch  (NUNCA merge; o operador revisa + faz merge)
  → desfecho no cérebro → o PRÓXIMO ciclo VÊ os anteriores  (compounding recursivo)
  → meta-métrica HONESTA         (AtlasSelfImprovementMetaMetricService: a aceleração é EMERGENTE, NÃO afirmada)
```

A propriedade "recursiva / a taxa-de-melhoria que melhora" é **emergente sobre ciclos reais** — o que foi construído é a MECÂNICA + a MEDIÇÃO honesta. Não se afirma aceleração que não foi medida.

## O problema concreto que ele resolve (o lixo de 412 linhas)

Uma run real do self-construct gerou **412 linhas de lixo irrelevante** — um driver de Kanban Hermes — para um TODO sobre "tarefas agendadas/em execução", porque o prompt não estava ancorado no sinal e **nada checava** se o que voltou tinha qualquer relação com o que foi pedido. Salto 3 fecha isso por **dois mecanismos independentes** (cinto e suspensório):

1. **MIRA** — a missão recebe o contexto AURG do sinal real, e o request NOMEIA o `file:line` + a preocupação + passa `target_file`, então a geração mira no LUGAR certo (`AtlasSelfConstructionLoopService::enrichedRequest`, `app/Services/Ai/SelfConstruction/AtlasSelfConstructionLoopService.php:352`).
2. **CHECK** — mesmo que o provider derive, o gate out-of-process rejeita uma geração que não tocou o arquivo que o sinal nomeou. O provider não fala mais alto que uma comparação determinística de dois conjuntos de fatos que ele não controla (`AtlasSelfImprovementRelevanceGate::evaluate`, `AtlasSelfImprovementRelevanceGate.php:103`).

## O gate de relevância (a segurança load-bearing)

Duas dimensões, **ambas** precisam passar (AND, não OR):

- **target_match** (S3.F1) — os arquivos gerados TOCARAM o arquivo/área que o sinal nomeou? Path overlap: arquivo exato = 1.0 forte, irmão no mesmo dir = 0.5 fraco, path não-relacionado = 0.0. O caso de 412 linhas gerou um arquivo genérico para um TODO em OUTRO arquivo → target_match ~0 → REJEITADO só nessa dimensão.
- **content_relevance** (S3.F2) — o CONTEÚDO gerado de fato sobrepõe a PREOCUPAÇÃO do sinal? Mesmo que o arquivo caia no dir certo, conteúdo que nada tem a ver com a preocupação (um driver de Kanban onde se pediu uma guarda de scheduling) pontua BAIXO e é rejeitado. Usa embeddings REAIS (`EmbeddingService`/semantic_rag) para cosseno semântico no pgsql, com fallback determinístico HONESTO de token-overlap (Jaccard) no sqlite / quando o engine não está disponível. O método usado é reportado (`content_method`) — o gate NUNCA rotula um score de token como "semantic".

Por que é **não-gameável pelo prompt de geração**: o gate roda DEPOIS da entrega, sobre os FATOS do que foi produzido (paths tocados + conteúdo) versus os FATOS do sinal (arquivo nomeado, linha, termos da preocupação). Nunca lê o prompt de geração, nunca pergunta ao provider "isso é relevante?". E não é gameável por ECOAR o texto do sinal num comentário no arquivo ERRADO: target_match roda primeiro e é 0 para path off-target, então nenhuma mímica de conteúdo resgata um arquivo no lugar errado.

## Os pisos de governança (seguro para deixar rodando — S3.F4)

- **NEVER-MERGE** — toda auto-melhoria é um branch que o OPERADOR revisa + faz merge. O loop nunca dá merge, nunca dá push, nunca toca em main. O materializer (`GovernedBranchMaterializationService`) faz worktree privado em branch `atlas/materialize/<id>` a partir do base limpo; o tree principal fica intocado.
- **RE-CHECK ADVERSARIAL** — um branch que passou no gate ainda NÃO é confiável: um re-check independente, default-refute, re-avalia os MESMOS fatos com um gate FRESCO (sem engine = a função de decisão determinística) + confirma a métrica de sucesso (o teste focado que o materializer rodou). Um branch que passa no gate mas falha no re-check vira `needs_review` (segurado para o operador, NUNCA reivindicado como melhoria vetada). Default-refute em qualquer incerteza.
- **CAP POR RUN** — uma única run entrega no máximo `max_branches_per_run` branches (default 3, clampado a `[1, max_signals]`). Uma run não-atendida nunca pode fan-out trabalho auto-modificante ilimitado.
- **KILL-SWITCH mid-run** — o arquivo `storage/app/atlas-self-construct.stop` é checado ANTES de cada sinal, então uma run para limpa no instante em que o operador a aciona (não só entre ciclos `--watch`).
- **RECEIPT / EVIDENCE** — toda decisão (accepted, rejected, needs_review, blocked) escreve um receipt JSONL append-only auditável (`AtlasSelfImprovementReceiptLog`). Só ids/paths/labels/scores/decisões/branch-ref cruzam — NUNCA o código gerado, NUNCA um diff. Sem ação silenciosa. Fail-open: uma falha de log nunca quebra o ciclo, mas `receipt_written=false` aparece no desfecho.

## A meta-métrica honesta (anti-Goodhart — S3.F3)

Uma linha durável por ciclo em `atlas_self_construct_cycles` (migration `2026_06_10_120000`), escrita DEPOIS do ciclo. Cada coluna é uma contagem MEDIDA (o veredito do gate, o delta de nós do cérebro) — nunca uma flag de sucesso auto-declarada.

`atlas:self-construct --status [--json]` deriva LIVE do histórico real: série por-ciclo + a tendência da taxa-de-aprovação-de-relevância + o crescimento do cérebro. A taxa é DERIVADA na leitura (passed / generated), nunca armazenada como escalar lisonjeiro. Com menos de 2 ciclos: `insufficient_history` (sem slope fabricado). Uma rejeição é reportada COMO rejeição — nunca escondida, nunca re-rotulada como progresso. O payload se auto-desmente: `"the recursive/accelerating property is emergent over real cycles and is NOT asserted here"`.

## Comandos

```bash
php artisan atlas:self-construct --status --json        # meta-métrica honesta (leitura pura, zero spend)
php artisan atlas:self-construct --max=1 [--request=...] # UMA run governada (faz chamada ao provider = spend)
```

## O comando autônomo real (com spend) — DEFAULT-OFF

O modo `--watch` (auto-modificação contínua não-atendida) é a capacidade de mais alto risco. Ele **recusa começar** a menos que o operador habilite explicitamente a flag de config — a flag `--watch` SOZINHA não basta (um typo / um cron stale não pode rodar um loop auto-modificante por acidente). Provado vivo: `--watch` sem o opt-in sai com exit code 1 e imprime a mensagem de gating.

Para realmente rodar o modo autônomo (gasta tokens):

```bash
# 1) habilita a capacidade (no .env ou no ambiente da sessão)
export ATLAS_SELF_CONSTRUCTION_AUTONOMOUS_ENABLED=true

# 2) roda UM ciclo bounded por intervalo, até o kill-switch
php artisan atlas:self-construct --watch --max=1 --interval=3600

# 3) MATA a qualquer momento (honrado antes do ciclo 0 também)
touch storage/app/atlas-self-construct.stop
```

Um pre-existente stop file NÃO é limpo no startup — se o kill estava acionado, o loop autônomo o honra ANTES do primeiro ciclo.

## Config (`config/atlas.php` → `self_construction`)

| Chave | Default | O que faz |
|---|---|---|
| `use_brain_context` | `true` | injeta contexto AURG no prompt (a metade MIRA do fix de 412) |
| `max_signals` | `5` | teto duro de sinais por run |
| `relevance_min_target` | `0.5` | piso da dimensão target (arquivo exato 1.0 / irmão-no-dir 0.5; off-dir 0.0 = rejeitado) |
| `relevance_min_content` | `0.15` | piso da dimensão conteúdo (cobertura do vocabulário da preocupação / proximidade semântica) |
| `relevance_semantic_enabled` | `true` | usa embeddings reais no pgsql; senão o fallback token-overlap honesto |
| `autonomous_enabled` | **`false`** | opt-in do `--watch` (a repetição autônoma é gateada) |
| `max_branches_per_run` | `3` | cap de branches mantidos por run (clampado a `[1, max_signals]`) |
| `adversarial_recheck_enabled` | `true` | o guard de Goodhart out-of-process (nullable para as construções legadas F1-F3) |
| `receipt_log_path` | storage default | trilha JSONL append-only de TODA decisão |

## Prova viva (custo-zero, 2026-06-10)

Contra o pgsql dev real + cérebro real + materializer real + gate real, com o LLM stubbado (entrega fake certificada, o MESMO seam dos testes provados):

```
BASELINE  brain_nodes=52  brain_edges=227  cycle_rows=0
REJECTED_OFF_TARGET:YES  (reason=off_target_generation, branch=NULL)
ON_TARGET_ACCEPTED:YES  branch='atlas/materialize/selfconstruct-caa02476b2'  BRANCH_CREATED:YES
OUTCOME_RECORDED_IN_BRAIN:YES  mission_node_exists=YES  refs_modules=[application_services,app_misc]
SHARED_MODULES:[application_services]
RECURSION_CYCLE2_SEES_CYCLE1:YES  (cycle2_seeded=YES, bridge_path=YES via application_services)
MAIN_UNTOUCHED:YES  (head_before==head_after=YES, current_branch=main)
REMAINING_BRANCHES:0
FINAL  brain_nodes=52  brain_edges=227  cycle_rows=0  BRAIN_RESTORED_TO_BASELINE:YES
PROOF_OVERALL:PASS
```

O caminho `cycle#2 → módulo compartilhado → cycle#1` É a recursão: a query do cérebro do ciclo #2 (com termos ÚNICOS de #2, "pathfinding traversal cursor") ALCANÇA a missão do ciclo #1, atravessando PURAMENTE pela aresta do módulo compartilhado — "a própria auto-melhoria anterior do sistema informa a próxima", não uma co-ocorrência casual.

A meta-métrica live, com a rejeição contada honestamente (run separada, ambas restauradas ao baseline):

```
totals: generated=2  passed=1  rejected=1  branches=1  relevance_pass_rate=0.5
trend:  series=[1,0]  direction=declining  delta=-1.0
brain_growth: nodes_added_total=4  size_delta=+2
```

## Testes (custo-zero, sqlite-safe)

- `tests/Feature/Ai/SelfConstruction/AtlasSelfImprovementRecursiveLoopTest.php` — a recursão (cycle#2→módulo→cycle#1) + meta-métrica + rejeição honesta (3 testes)
- `tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionGovernanceHardeningTest.php` — re-check adversarial + cap + kill-switch mid-run + receipts (11 testes)
- `tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionLoopServiceTest.php` — roteamento brain-anchored + rejeição off-target/off-concern (6 testes)
- `tests/Feature/Ai/SelfConstruction/AtlasSelfConstructSafeRunnerTest.php` — `--watch` recusa default-off + status (3 testes)
- `tests/Unit/Ai/SelfConstruction/AtlasSelfImprovementRelevanceGateTest.php` — as duas dimensões do gate, incl. "412 line garbage is rejected" (14 testes)

## Limites honestos (não esquecer)

- **A aceleração é emergente e NÃO-PROVADA** até runs reais. A mecânica + a medição existem; a propriedade "taxa-de-melhoria que melhora" só aparece sobre ciclos reais com spend real. A meta-métrica reporta a tendência MEDIDA e se recusa a fabricar slope com histórico insuficiente.
- **O cérebro precisa acumular missões reais primeiro.** A prova viva semeia + restaura ao baseline (52/227). O compounding recursivo composto só fica visível quando o operador roda runs reais que deixam desfechos no cérebro permanentemente.
- **Custo:** toda entrega real faz uma chamada ao provider (spend do operador). Os testes e as provas vivas STUBAM o LLM — zero tokens. A run autônoma real é o comando do operador, gateado.
- **Não commitado.** Como Salto 1 e 2, fica no working tree até o operador decidir o merge.
