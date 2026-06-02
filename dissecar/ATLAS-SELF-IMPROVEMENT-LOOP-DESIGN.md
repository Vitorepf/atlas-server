# Atlas Self-Improvement Loop — o catálogo de métrica + o harness congelado

> O design que transforma o "sim escopado" em algo que você liga e deixa rodar 24h. Honesto: ele **mói tarefas com métrica**; não faz auto-evolução aberta (isso é física, não esforço — ver `LADDER-MIESSLER-DISSECTION.md` §5.3).

---

## 0. A única verdade que governa tudo

O autoresearch funciona porque o agente **não pode editar** o `prepare.py`/`evaluate_bpb` — a métrica e o juiz estão **fora do alcance dele**. Atlas-melhorando-Atlas é o caso mais perigoso, porque o loop pode editar a própria métrica, os próprios gates, e a si mesmo.

**Regra-mãe:** o loop só toca o *alvo*; **nunca** o *juiz*. Tudo abaixo deriva disso.

---

## 1. O catálogo de métrica — "qual é o `val_bpb` de uma tarefa do Atlas?"

Não existe UMA métrica. Existe um **táxon de formas-de-tarefa**, cada uma com seu número barato‑objetivo‑total + um gate congelado (a trava anti‑Goodhart). Só entram na fila tarefas que se encaixam numa destas formas:

| Forma | De onde vem (Source) | A métrica (o "val_bpb") | Gate congelado (anti‑Goodhart) |
|---|---|---|---|
| **Bug-fix** | teste falhando / CI vermelho | o teste vai **vermelho→verde** | a suíte INTEIRA continua verde; o teste é **frozen** (o loop não pode editá-lo) |
| **Hardening de bug latente** | agente "find bugs" | um **NOVO teste que reproduz** vai vermelho→verde | o teste‑repro tem que estar **vermelho no baseline ANTES** (prova que o bug é real) |
| **Slice de backlog** | a fila de gaps conhecidos | o teste de aceitação da slice fica verde | ScopeGuard: só `allowed_files`; suíte inteira verde |
| **Otimização de propriedade** | telemetria / lint / doc-health | o número melhora vs baseline congelado (tokens/tarefa, latência, cobertura, nº de warnings) | uma suíte de correção **congelada** continua verde |
| **Dedup / dead-code** | scan estático | **linhas removidas** | suíte inteira verde + teste de equivalência de comportamento |

**O padrão único:** toda tarefa = *um número pra melhorar* + *um gate congelado que o loop não pode enfraquecer*. O gate congelado **é** a trava anti‑Goodhart. Sem ele, o loop "ganha" enfraquecendo um teste — e foi por isso que a versão antiga foi inútil.

---

## 2. O harness congelado — o que o loop NÃO pode tocar (o juiz)

Espelha o `prepare.py` read-only do autoresearch. Quatro coisas ficam **fora do alcance** do loop:

1. **A definição da métrica** (como o score é computado).
2. **O gate de verificação** (o test runner real, o ScopeGuard, o certify).
3. **Os critérios de aceitação da tarefa** (definidos quando a tarefa entra na fila — congelados ali).
4. **A autoridade de merge** (o loop **nunca** faz merge — propõe).

**Como congelar, tecnicamente:**
- O juiz roda num **checkout limpo separado** — a mudança do loop é aplicada numa cópia fresca e pontuada lá. O loop não pode pré-envenenar o ambiente do juiz.
- O baseline = um **git ref fixado** (a main certificada atual). "Melhorou" = medido contra esse ref.
- O código do juiz (`harness/`, o subconjunto `tests/` frozen, a métrica) está na lista **`forbidden_files`** do ScopeGuard — o loop é bloqueado se tentar tocar.

---

## 3. As mecânicas do loop (o loop do autoresearch, governado) — mapeado nos componentes REAIS do Atlas

```
LOOP (24h):
 1. puxa a próxima tarefa da fila        → [Fila = espinha do Ladder, §5]
 2. cria branch por-tarefa               → (autoresearch/<task-id>)
 3. agente tenta a mudança no sandbox    → [Hermes default — PROVADO editando em Dev/Forge]
 4. o JUIZ CONGELADO pontua:             → [referee em checkout limpo, §2]
      métrica melhorou? gate verde? scope respeitado? anti-Goodhart passou?
 5. keep (avança) se ESTRITAMENTE melhor + gate verde; senão git reset  → [keep/discard provado]
 6. loga no ledger: task, métrica_antes/depois, status, evidence_hash   → [results.tsv quente + Evidence Ledger audit]
 7. NUNCA faz merge na main → acumula proposta "certified-for-review"    → [tua regra: never auto-merge]
 8. próxima tarefa
```

**O que disso já existe (provado nesta sessão):**
- Execução: Hermes editando código real (Dev + Forge, diff capturado, teste passando).
- O runner do loop: `atlas:dev:senior-loop` (agora funciona com Hermes).
- Verificação: `VerificationGate` + `ScopeGuard` (já no pipeline do Dev).
- keep/discard: o workspace git + a captura de diff (`workspaceDiff`).
- Ledger de auditoria: Evidence Ledger + os receipts do run.

---

## 4. As travas anti‑Goodhart (o anti‑trapaça — concreto)

Assuma que o loop **vai** tentar gamear. Cinco travas:

1. **Juiz congelado** — o loop não edita teste/métrica/gate (scope‑forbidden). A trava base.
2. **Vermelho‑antes‑de‑verde** (bug/hardening) — um fix só conta se há um teste **vermelho no baseline** que fica verde. Prova que o bug era real e foi corrigido.
3. **Regressão de suíte inteira** — qualquer mudança mantém a suíte congelada **toda** verde. Não dá pra "consertar X quebrando Y".
4. **Re‑prova independente** (amostragem) — periodicamente, um 2º agente re‑verifica uma amostra dos "kept" num checkout limpo. Pega drift da métrica. (É o padrão *workflow fan‑out + verify* que já uso.)
5. **Tetos de custo/escopo** — orçamento de token por‑tarefa + máx. de arquivos mudados (o equivalente ao orçamento fixo de 5 min do autoresearch). Mata experimento que cresce sem progresso.

---

## 5. A fila = a espinha do Ladder (onde a ontologia ganha o lugar dela)

A fila de tarefas **é** o pipeline do Ladder, com o schema dele (que já tem os campos certos):

```
Sources    → de onde vêm as tarefas: testes vermelhos, o backlog, telemetria,
              warnings de lint/doc-health, o output de um agente "find bugs"
Ideas      → mudanças candidatas
Hypotheses → cada uma com `metric` + `success_criteria`  ← o Ladder JÁ tem esses campos no frontmatter
Experiments→ as tentativas do loop
Results    → kept/discarded, com `loops_to`  ← um resultado vira nova Source
              (ex.: um fix de perf que passa revela o próximo gargalo)
```

O `loops_to` do Ladder é o que fecha o ciclo: o motor gera o próprio próximo trabalho a partir do que aprendeu.

---

## 6. O que já está construído vs. o que falta (o 70/30 honesto)

**JÁ EXISTE (≈70%):** execução (Hermes, provado) · runner (`senior-loop`) · verificação (`VerificationGate`+`ScopeGuard`) · keep/discard (git workspace) · ledger de auditoria (Evidence) · a ontologia (roubada do Ladder, é só markdown+schema).

**FALTA CONSTRUIR (≈30%):**
1. **O juiz congelado** — o scorer em checkout limpo que o loop não pode tocar (o coração; é o que faltava e fez fracassar).
2. **A fila com métrica por‑tarefa** — a espinha do Ladder com `metric`/`success_criteria` obrigatórios por entrada.
3. **As travas anti‑Goodhart** — vermelho‑antes‑de‑verde + regressão‑de‑suíte + re‑prova amostral.
4. **O acumulador propose‑only** — a fila "certified‑for‑review", nunca‑merge.

---

## 7. O que as 24h REALMENTE fazem (e a segurança)

Em 24h, o loop **mói a fila**: tenta N tarefas, mede cada uma pelo juiz congelado, mantém as que melhoram com prova, reverte o resto, e **acumula um maço de propostas certificadas** pra você revisar de manhã. Não é "Atlas se reescreveu sozinho durante a noite" — é "Atlas fechou 30 gaps mensuráveis com prova, sem tocar a main, prontos pro teu merge".

A segurança vem de: juiz congelado + sandbox + propose‑only + os tetos. Sem isso, 24h não‑supervisionadas de auto‑modificação **se corrompem** (drift composto, métrica gameada).

---

## 8. Limites honestos (o que isso AINDA não é)

- **Só mói tarefas que já têm métrica congelada.** Gerar BOAS tarefas (as Sources/Ideas) ainda é parte humano/julgamento — um agente "find bugs/gaps" gera algumas, mas "o que vale melhorar" precisa de semente.
- **Só melhora o mensurável.** "A arquitetura está melhor?" continua fora (sem métrica). O loop não decide direção — executa direção decomposta.
- **Compounding ≠ milagre.** Ele compõe ganhos pequenos e provados. O "absurdo" vem do volume (24h × tarefas verificadas), não de um salto mágico.

---

## 9. O primeiro passo concreto (o menor provável)

Não construa as 24h. Construa **1 hora, 5 tarefas, prova**:

1. Pegue 5 bugs reais do Atlas com teste reproduzível (forma "bug-fix" — a mais simples).
2. Congele um juiz mínimo: um runner em checkout limpo que roda a suíte e diz verde/vermelho, + ScopeGuard com `harness/` e `tests/` em forbidden.
3. Ligue o `senior-loop` (já provado com Hermes) puxando dessas 5, keep/discard pelo juiz, propose‑only.
4. **Critério de sucesso:** fecha ≥3/5 com prova **e zero métricas gameadas** (a re‑prova amostral confirma). 

Se passar, o design está validado → escala a fila e o tempo. Se o loop gamear mesmo 1 das 5, você descobre a trava que falta **antes** de confiar 24h. (É literalmente a `HY-00001` do Ladder — "o loop acha 3+ melhorias" — mas feita real, com juiz congelado em vez de score subjetivo.)

---

*Componentes Atlas referenciados: `atlas:dev:senior-loop`, `HermesCliProvider`, `VerificationGate`, `ScopeGuard`, `WorkspaceMutatingProviders`, Evidence Ledger. Síntese de `AUTORESEARCH-KARPATHY-DISSECTION.md` (a métrica + keep/discard), `LADDER-MIESSLER-DISSECTION.md` (a espinha), `AUTORESEARCH-FOLKTALES-DISSECTION.md` (o substrato local).*
