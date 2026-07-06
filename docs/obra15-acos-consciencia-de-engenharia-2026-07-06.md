# Obra #15 (proposta) — ACOS: de recuperador higiênico a consciência de engenharia

Data: 2026-07-06 · Régua: a do operador (hoje **4/10**) · Status: aprovável

## A régua certa (o que 9/10 significa)

A Obra #14 deixou o **mecanismo** saudável (9.43 no instrumento de mecanismo). A régua desta obra é outra:
**quanto o ACOS carrega compreensão viva do projeto e torna a entrega de engenharia complexa e de longo prazo melhor** — refatorações complexas, implementações grandes, obras de meses, em QUALQUER projeto do operador. Quanto maior o horizonte de tempo, mais o ACOS importa.

**Métrica-norte (CPR — Context Provenance Ratio):** fração do contexto load-bearing de uma entrega complexa que veio do ACOS vs. exploração própria do provider. Medível pelo loop de feedback já vivo (used refs vs. arquivos explorados por conta). **Hoje: <20%. Meta: ≥70%.**
Métricas de apoio: cold-start em projeto novo (tempo até primeiro brief útil), continuidade (retomar obra após 7+ dias sem re-derivar — medida em turns até a primeira edição correta), precision@k do recall (AREBA), acerto do aviso proativo.

## Diagnóstico honesto do 4/10 (medido em 06/07, noite)

| Sintoma | Prova |
|---|---|
| Recall casa PALAVRA, não intenção | pergunta sobre "qualidade" → 24 classes com "Quality" no nome; pergunta profunda sobre o ACOS → 1 ref + memória irrelevante |
| Memória do trabalho recente invisível | Obra #14 inteira aconteceu no dia; duas perguntas à noite voltaram com recall vazio |
| Compreensão de projeto vive no provider, não no ACOS | o estado das obras #8-#14 está nos arquivos de memória da sessão do Fable — o inverso do certo |
| Zero proatividade | o guard avisa conflito de arquivo; não avisa conflito de DECISÃO |
| Mono-projeto | atlas-server-cêntrico; projetos novos recebem quase nada |

## Fases (cada uma com gate mensurável na régua do operador)

### F1 — Modelo Vivo de Projeto (a espinha)
Não top-K de símbolos: um **project brief canônico por workspace**, sintetizado em cadência a partir do que JÁ existe (code graph, docs canônicos, evidence ledger, specs de obra, refutation memories): camadas e responsabilidades, invariantes/regras pétreas, decisões COM razões, zonas quentes/perigosas, o que foi tentado e refutado. Versionado, consultável por seção, injetado no pack quando a tarefa toca a zona.
**Gate:** golden set de perguntas de engenharia ("onde implemento X?", "por que Y é assim?", "o que NÃO fazer em Z?") respondíveis só com o brief — ≥80% de acerto avaliado contra o código real.

### F2 — Memória de Obra de Longo Prazo
Estado canônico de obra persistido no ACOS (fase, slices, provas, refutações, pendências, arquivos quentes, decisões intermediárias), **auto-atualizado no fechamento de cada sessão/commit** (estender o harvester de lições para harvester de ESTADO). Pack de retomada: "você estava aqui, provou X, falta Y, cuidado com Z".
**Gate:** retomar uma obra 7+ dias depois com ≤3 turns até a primeira edição correta, sem re-derivar contexto (baseline hoje: dezenas de turns/re-exploração completa).

### F3 — Recall que entende (semântico de verdade)
Embeddings reais no caminho quente do recall (pgsql + venv, embed-on-write já existe), re-rank por intenção da tarefa usando o brief da F1 como expansor de consulta, precision@k medida pela AREBA com o golden set, ARFL fechando o ajuste de pesos (H2.4 da #14 — os 30 dias de eventos começaram a contar hoje).
**Gate:** precision@k ≥0.8 no golden set; ZERO packs vazios para perguntas sobre trabalho das últimas 2 semanas.

### F4 — Consciência proativa (o aviso na hora certa)
O guard (PreToolUse) evolui de conflito de ARQUIVO para conflito de DECISÃO: editar código governado por decisão/refutação/invariante da F1 → aviso com a razão e o link; refatoração que colide com veto registrado → warn forte (nunca hard-block sem histórico de acerto).
**Gate:** em edições de teste tocando zonas com decisão registrada: aviso correto ≥90%, falso-positivo <10%.

### F5 — Multi-projeto (qualquer repo do operador)
`atlas aobg workspace activate` num repo novo dispara a síntese F1 automaticamente (code graph + docs do repo). O ACOS deixa de ser atlas-server-cêntrico.
**Gate:** cold-start em repo desconhecido → brief útil em ≤1h de cadência; CPR ≥40% na primeira semana de trabalho nesse repo.

### F6 — Compounding de engenharia (fecha o ciclo)
Toda obra fechada alimenta F1/F2/F3 automaticamente; refutações e calibrações viram regra consultável e MATCHÁVEL (nunca re-propor refutado — matcher contra refutation_memory na admissão de tarefa/spec).
**Gate:** zero re-propostas de item refutado em obras subsequentes (medível pelo matcher).

## Medida contínua

Estender o `atlas:cognition:evolution-score` com a dimensão **valor_de_engenharia** (a régua do operador): CPR + continuidade + precision@k + aviso proativo + cold-start. Evidence-resolved como as demais — a nota só sobe quando o valor entregue sobe. **Partida honesta: ~4/10. Meta da obra: ≥9/10 nessa dimensão.**

## Ordem recomendada e por quê

**F3 + F2 primeiro** (retorno mais rápido: recall que funciona + retomada de obra atacam a dor imediata do trabalho de meses) → **F1** (espinha; F3 melhora de novo quando F1 existe como expansor) → **F4** → **F5** → **F6** (transversal, começa junto e fecha por último).
Pré-requisitos já entregues pela #14: pack sem lixo, cadência viva, ciclo de lições, feedback loop coletando (CPR medível), índice multi-classe correto.

## Regras herdadas (pétreas)
Byte-prova na execução; líquido/valor medido antes de declarar entregue; G0 nunca auto-promove; verify-then-absorb em todo doc; vocabulário proibido; push só com OK; lane do operador.
