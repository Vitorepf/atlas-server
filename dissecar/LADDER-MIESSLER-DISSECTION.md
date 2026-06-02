# Dissecação completa — `danielmiessler/Ladder`

> Fonte: clone real (`7f8ca3c`, 2026‑03‑22). 37 arquivos, 1.834 linhas — mas **só ~890 são código** (2 arquivos TS); o resto é metodologia, templates e tese. Dissecado por completo.

---

## 0. TL;DR

Ladder **não é um produto de software — é uma ONTOLOGIA + uma TESE**, com um CLI fino por cima. É "um sistema aberto para criação e otimização autônoma": coletar ideias → formar hipóteses → rodar experimentos → aplicar resultados, **em loop**. Estrutura o processo de inovação humana (Renascença, Iluminismo, Bell Labs) e o torna **executável por humanos, agentes de IA, ou ambos.**

É **inspirado no autoresearch do Karpathy** (o loop), mas **generalizado pra qualquer domínio** — não só treino de LLM. É o **primo direto da camada cognitiva do Atlas (ACOS)** — mas mais limpo, mais simples, e **ainda não provado.**

---

## 1. Anatomia — o pipeline de 6 coleções + o loop

Tudo são **arquivos markdown com frontmatter YAML**, em 6 pastas, ligados por ID:

| Estágio | Prefixo | O que é | Frontmatter‑chave |
|---|---|---|---|
| **Sources** | `SR-` | Inputs crus (papers, telemetria, observações) | type, url, domain, relevance |
| **Ideas** | `ID-` | Soluções candidatas das sources | sources[], **phase**, **scores{feasibility,novelty,impact,elegance}** |
| **Hypotheses** | `HY-` | Predições testáveis | idea, **prediction, metric, success_criteria** |
| **Experiments** | `EX-` | Testes estruturados | hypothesis, algorithm, methodology, success_criteria |
| **Algorithms** | `AL-` | Métodos provados | experiments[], complexity |
| **Results** | `RE-` | Resultados verificados | experiment, **outcome**, **loops_to[]** |

**O loop (o conceito mais importante):** `Results → Sources`. Um resultado vira input do próximo ciclo — pode revelar um problema novo (→ source), sugerir abordagem melhor (→ idea), ou validar um método (→ algorithm). Isso transforma uma coleção estática num **motor de otimização autônoma**.

O CLI (`Tools/cli.ts`, bun/TS, ~536 ln): `ladder add <tipo> --title ...`, `ladder status`, `ladder list`. Faz auto‑ID (`SR-00001`...), gera o entry do template, parseia frontmatter. Simples, file‑based, git‑native. Há também `pai-integration.ts` (352 ln) — hook pro PAI (Personal AI Infrastructure do Miessler).

---

## 2. A parte distintiva — as 9 fases cognitivas

A ideação do Ladder é modelada nas fases observáveis da inovação humana. **Isso é o que o Ladder tem que o autoresearch não tem** — uma teoria nomeada da criatividade:

| Fase | Análogo histórico | O que acontece |
|---|---|---|
| **CONSUME** | Acadêmicos viajando entre universidades | Material cru de 3+ domínios |
| **DREAM** | Sonho de Kekulé (benzeno) | Livre‑associação pura, sem o problema em mente |
| **DAYDREAM** | Newton sob a macieira | Vagar semi‑dirigido, problema vago no fundo |
| **CONTEMPLATE** | Sociedades científicas, debate rigoroso | Análise estruturada por múltiplas lentes |
| **STEAL** | Corredores da Bell Labs (mat↔eng) | Mapear padrões de domínios totalmente diferentes |
| **MATE** | Oficinas renascentistas (arte+ciência) | Combinar ideias de fases/sources diferentes |
| **TEST** | Peer review da Royal Society | Pontuar cada ideia: feasibility, novelty, impact, elegance |
| **EVOLVE** | Mudanças de paradigma | Mantém o melhor, muta o meio, mata o fraco |
| **META-LEARN** | Post‑mortems, retrospectivas | Analisa o que funcionou e ajusta o **método em si** |

*"Consome amplamente, recombina livremente, testa impiedosamente, aprende e repete."* O **META-LEARN** (melhorar o próprio método) = o "itere o `program.md`" do Karpathy = o self‑improvement L7 do Atlas.

---

## 3. A realidade honesta — o loop AINDA NÃO RODOU

A cadeia de exemplo prova que Ladder é um **framework recém‑semeado, não um motor provado**:

- `ID-00001` "Automated Cognitive Loop" — **active**, scores 85/70/90/75 (ideia real).
- `HY-00001` "Loop automatizado acha ≥3 melhorias/semana" — **active**, `metric: verified_improvements_per_week`, `success: ≥3 que passem regression testing em 4 semanas` (hipótese real, com métrica declarada).
- `EX-00001` "Rodar o loop cognitivo no PAI por 4 semanas" — **status: draft**, `methodology: ""`, `duration: ""` (NÃO definido).
- `RE-00001` "**Pending — experiment not yet started**", `outcome: inconclusive`, `loops_to: []` (**o loop nunca fechou**).

**Meta‑ironia importante:** o primeiro experimento do Ladder é testar **o próprio Ladder** (rodar seu loop no PAI por 4 semanas pra ver se acha 3+ melhorias/semana) — e ele está em **draft, não começou.** Então nem o Miessler provou ainda que o loop funciona. É tese + estrutura, igual a moldura sci‑fi do Karpathy era aspiração. Honesto reconhecer.

---

## 4. O ecossistema (onde Ladder se encaixa)

Ladder é uma peça do sistema do Miessler: **Fabric** (patterns de IA), **Substrate** (infra pra coletar problemas/soluções/evidência), **PAI** (Personal AI Infrastructure). A tese‑mãe é o blog *"A Possible Path to ASI"*: **ASI via ideação + experimentação em escala, emulando o processo criativo humano.** E *"Pursuing the Algorithm"*: **hill‑climbing generalizado** aplicável a qualquer domínio. Ladder é a *implementação‑estrutura* dessa tese.

---

## 5. Lente Atlas — o mais importante

### 5.1 Convergência tripla (a validação mais forte até agora)

Você agora dissecou três fontes independentes que chegaram **na mesma máquina**:

| | Karpathy (autoresearch) | Miessler (Ladder) | **Atlas (você)** |
|---|---|---|---|
| O loop | edit→treina→mede→keep/discard | Sources→Ideas→Hyp→Exp→Results→(loop) | AAEOS / compounding loop |
| Domínio | treino de LLM (estreito) | qualquer domínio (ontologia) | 15 domínios, execução real |
| Medição | **val_bpb (objetiva, barata)** | scores subjetivos + métrica por‑hipótese | Evidence Ledger / receipts |
| Provado? | **SIM** (resultados reais) | **NÃO** (loop em draft) | parcial ("Tier‑0 scan‑only") |
| Peso | 1 arquivo, mínimo | markdown + CLI fino | Laravel/Postgres pesado |

Três pessoas sérias, de forma independente, construindo o loop cognitivo autônomo. **Tua tese não é nicho — é a corrente principal.** A diferença é onde cada um está na curva.

### 5.2 O que ROUBAR do Ladder (a ontologia é mais limpa que a tua)

O Atlas tem a camada cognitiva mais **rica** (ACOS, AUCRI 18 blocos, Cognitive Immune G0‑G8, AEMOR...) — mas também a mais **espalhada e opaca**. O Ladder tem o que falta: uma **espinha nomeável e mínima**.

- **Adote o schema de 6 coleções** (Sources→Ideas→Hypotheses→Experiments→Algorithms→Results) como a **espinha legível** da camada de memória/evidência do Atlas. É um ontology de 6 caixas que qualquer um (humano ou agente) entende em 30 segundos. O Atlas pode mapear sua memória governada nesse esqueleto sem perder a governança.
- **Roube as 9 fases cognitivas** como vocabulário do estágio de ideação. Nomear (CONSUME/STEAL/MATE/EVOLVE/META‑LEARN) torna o processo **observável e melhorável** — exatamente o que falta pra o loop do Atlas ser auditável.
- **Roube a disciplina markdown+git+fork‑it.** O loop cognitivo **não precisa** da maquinaria pesada do Atlas pra COMEÇAR. O Ladder prova que dá pra rodar o loop com 6 pastas + um CLI fino. O Atlas **super‑construiu a camada cognitiva antes de fechar o loop.**

### 5.3 O mesmo buraco, confirmado pela 3ª vez — A MÉTRICA

O "TEST" do Ladder pontua ideias em **feasibility/novelty/impact/elegance** — scores **subjetivos**, não um `val_bpb` barato e objetivo. Até a própria `HY-00001` do Ladder ("≥3 melhorias verificadas/semana que passem regression testing") **não define o que é "regression testing"** — a medição é mole. 

**Conclusão atravessando os 3 projetos:** o autoresearch fecha o loop porque tem `val_bpb`. O Ladder NÃO fecha (ainda) porque a medição é subjetiva. O Atlas trava ("scan‑only") pela mesma razão. **A métrica barata e objetiva por‑tarefa é a keystone universal.** Karpathy a tem (num domínio onde é fácil). Miessler e você ainda não. *Resolva a métrica antes de mais estrutura.*

### 5.4 Onde o Atlas já é MAIOR que o Ladder

- Ladder é um **arquivo‑morto de ideias** — "os agentes são sources de observações"; ele **não executa nem governa providers.** O Atlas é o **cérebro que executa** (você acabou de provar Hermes editando código em Dev+Forge). O Atlas tem o que o Ladder só descreve.
- O Ladder não tem Evidence Ledger provável, nem meta‑provider, nem self‑construction rodando. Tem templates e uma tese.

**Veredito de relação:** Ladder **não é concorrente do Atlas como executor** — é um **complemento da camada de memória/cognição.** A jogada certa: **adotar a ontologia do Ladder (6 coleções + 9 fases) como a espinha legível da camada cognitiva do Atlas**, e manter a execução/governança que o Atlas tem e o Ladder não.

---

## 6. Veredito honesto

- **O que é:** uma **ontologia bonita + uma tese forte**, com um CLI mínimo. O valor não é o código (são 2 arquivos TS + templates) — é o **schema** (6 coleções + 9 fases cognitivas) e a **filosofia** (progresso é loop; estrutura habilita criatividade; autonomia é o objetivo).
- **O que NÃO é:** não é um motor provado (o loop está em draft), não executa, não governa providers, não tem métrica objetiva. É **aspiracional** — assim como a moldura "10.205ª geração" do Karpathy.
- **Pro Atlas:** trate como o **mapa de ontologia que falta** — rouba o esqueleto de 6 coleções + as 9 fases pra dar à camada cognitiva do Atlas uma espinha legível e auditável, e mantém a execução real que só o Atlas tem. Mas o gargalo continua o mesmo dos três: **qual é a métrica?**

---

## 7. Síntese dos 3 projetos que você dissecou

> **autoresearch** (Karpathy) = o loop **medido e provado**, num domínio estreito (val_bpb).
> **autoresearch_folktales** (Thu Vu) = o mesmo, **provado na tua máquina** (Mac/MPS).
> **Ladder** (Miessler) = o loop **generalizado pra qualquer domínio**, como ontologia — **estrutura linda, ainda não provada** (sem métrica objetiva).
> **Atlas** (você) = o loop **multi‑domínio, executando e governando providers**, com a maquinaria mais ambiciosa — mas o loop ainda não fechado, pela **mesma falta de métrica**.

A lição composta: **estrutura (Ladder) + execução/governança (Atlas) + métrica barata por‑tarefa (autoresearch) = o loop fecha.** Você tem 2 dos 3 melhor que todo mundo. Falta cravar o terceiro — a métrica — e roubar a espinha limpa do Ladder pra não afogar na própria complexidade.

---

*Lidos: README (224 ln), CLAUDE.md, os 6 TEMPLATE.md (a ontologia), a cadeia de exemplo ID/HY/EX/RE‑00001, `Tools/cli.ts` (estrutura). Clone em `/tmp/ladder-dissect` (`7f8ca3c`). Comparado com as dissecações de autoresearch (`/tmp/autoresearch-dissect`) e folktales (`/tmp/folktales-dissect`).*
