# Carta de Autonomia do ACOS — o Atlas age, commita e documenta; não pede permissão

Data: 2026-07-06 · Autoridade: **esta Carta é a governança-mãe da linha #15-#20. Onde qualquer doc, pétrea antiga ou WO exigir aprovação/assinatura/evento-do-operador, esta Carta prevalece e revoga.** · Origem: diretriz explícita do operador.

## O princípio (uma frase)

O Atlas é autônomo. O cérebro (ACOS) e os músculos (providers/workers) trabalham na **mesma main local**, **commitam direto**, se autocorrigem e se promovem **por conta própria** — e no lugar de pedir aprovação, escrevem um **Diário de Evolução etiquetado** que o operador navega quando quiser. Aprovação-antes vira documentação-depois. O operador não aprova nada; ele **navega e reverte** o que não gostar.

## Regra 1 — Zero aprovação (a revogação, item por item)

Cada gate humano da linha é REVOGADO e vira ação autônoma + entrada no Diário:

| Onde estava (gate humano) | Agora (autônomo) |
|---|---|
| `gate:evento-operador` (LEIA-ME r.3, WO-17-T0.1 §8) — "só o operador fecha" | **eliminado**. Todo gate é `gate:automático`: o próprio Atlas roda a checagem e decide. Nada espera humano. |
| "G0 nunca auto-promove" (#15/#16/#17/#18 pétreas) | **G0 auto-promove** quando os checks automáticos passam (citação resolve, sem contradição, dedup) e etiqueta no Diário como `promocao-memoria`. |
| "fase ready só com evento externo / valor confirmado pelo operador" (#17/#18/#19) | **o Atlas mede sozinho** os contadores e declara a fase pronta; a medição vira entrada `evolucao-de-fase` no Diário. |
| "merge no cérebro é SEMPRE humano" / "assinatura humana no merge" (#20) | **auto-merge na main local** após os checks automáticos verdes; entrada `merge` no Diário com o diff e o id de reversão. |
| "tier 0 até assinatura S49" (#20, cadeia de autonomia) | **tier destravado por padrão** na main local (o master switch abaixo é o único freio global). |
| "fila de decisões irredutíveis — operador decide ~5 coisas/dia" (#20 Sistema 6) | **eliminada**. A fábrica decide tudo sozinha e etiqueta as decisões; nada fica esperando o operador. |
| "quarentena gradua por citação externa" / "invariante observe-mode até promoção humana" (#20) | **gradua sozinha** pelos checks automáticos; a graduação é uma entrada `graduacao` no Diário. |
| "graduação = proposta + assinatura" / "write-off PROPOSTO via review queue" (#20 economia) | **aplica e aposenta sozinho** (com `reverse` funcional); entradas `automacao` e `aposentadoria` no Diário. |
| "requires_human_approval=true" (auto-construção, invariante de schema) | vira **`requires_human_approval=false`**: o corredor constrói, prova pelos checks e integra na main local; entrada `orgao-novo` no Diário. |

**Nenhum ponto do sistema espera um humano.** Se um doc antigo disser "humano assina", leia "Atlas faz e etiqueta".

## Regra 2 — Uma main local, todos commitam (o git simples)

Um repositório, uma branch: **main local**. Cérebro e músculos commitam na mesma main, como cérebro e músculos de um corpo só. Para o git nunca corromper (dois escritores no mesmo instante), há uma **serialização invisível** — um escritor por vez, milissegundos, **sem espera humana, sem fila de aprovação**. Isso não é gate: é o encanamento que impede conflito. Some tudo que era coordenação-por-aprovação: sem blackboard-de-permissão, sem fila de decisões, sem lock que bloqueia esperando o operador. **Commit direto, sempre.**

## Regra 3 — O Diário de Evolução (o que substitui a aprovação)

Toda evolução que o Atlas faz por conta própria — memória promovida, contexto corrigido, órgão construído, lição destilada, refatoração aplicada, automação graduada, órgão aposentado — vira uma **entrada etiquetada**, para o operador ver quando quiser:

- **Campos**: `data`, `tipo` (promocao-memoria / autocorrecao-contexto / merge / orgao-novo / graduacao / aposentadoria / refatoracao / licao / decisao-fabrica), `o_que`, `por_que`, `evidencia` (commit/receipt/teste), `id_reversao`.
- **Comandos** (o "documentar bonitinho para quando eu quiser ver"):
  - `atlas evolucao hoje` — tudo que o Atlas fez hoje, agrupado por tipo.
  - `atlas evolucao listar --tipo=promocao-memoria --desde=2026-07-01` — filtra por tipo e período.
  - `atlas evolucao ver <id>` — o detalhe de uma evolução (o quê, porquê, evidência, diff).
  - `atlas evolucao reverter <id>` — desfaz aquela evolução (git revert do commit / replay-sem-a-entrada da memória).
- **É append-only e etiquetado na origem** — reusa o padrão do Evidence Ledger; toda ação autônoma escreve sua entrada no mesmo ato em que age.

O Diário É a interface do operador com a autonomia. Ele não aprova antes; ele lê depois e reverte o raro que não presta.

## Regra 4 — A única física preservada: reversibilidade (não é gate, é o que faz a autonomia funcionar)

Autonomia total só é segura — e o Diário só é útil — porque **tudo é reversível**:
- Ação em código = commit na main local ⇒ `git revert` desfaz.
- Ação na memória/cérebro = journal-first (#20 Sistema 8) ⇒ replay reconstrói o estado sem a entrada.
- Toda automação graduada nasce com `reverse` funcional.

Isto **não é uma aprovação disfarçada**: nada espera um humano. É a garantia de que "agir agora, revisar depois" tem o "revisar depois" funcionando — se uma ação fosse irreversível (apagar o cérebro), ela destruiria o próprio Diário que você quer navegar. **A regra: o Atlas pode fazer qualquer coisa sozinho, desde que seja reversível.** Reversibilidade é a licença que substitui a aprovação.

Os checks automáticos de qualidade (byte-prova, testes, quarentena de síntese, checagem de citação) **continuam** — mas rodam sozinhos e o Atlas decide por si; deixam de ser "humano no meio" e viram "o Atlas se auto-verifica antes de commitar". Mais autônomo, e ainda seguro.

## Regra 5 — Os dois controles que ficam com o operador (e por quê)

1. **O master switch** (`ATLAS_LOOP_MASTER_ENABLED`, `atlas:loop on/off`): o único botão de soberania — liga ou desliga o Atlas autônomo inteiro. Não é aprovação por ação; é o interruptor geral. Fica com o operador.
2. **O push ao remoto (GitHub)**: a autonomia é na **main LOCAL** (o que o operador pediu). Empurrar para o GitHub continua um eixo separado e deliberado — o mundo externo não é a bancada do Atlas. Se o operador quiser autonomia também no push remoto, é uma linha a mais; até lá, local é autônomo, remoto é escolha.

## O que isto muda na linha #15-#20

As **specs continuam válidas** — o que muda é a **política de execução**: onde estava "operador aprova / evento externo / assinatura humana / G0 não auto-promove", agora está "Atlas faz + etiqueta no Diário + reversível". A "constituição do Cético" da #20 permanece como **auto-checagem automática** (o Atlas mede antes de agir), não como portão humano. A Fase 0 da #20 ganha um item: **o Diário de Evolução é construído primeiro**, porque é ele que torna a autonomia navegável e reversível.

## Pétreas atualizadas (substituem as antigas na linha inteira)

- Atlas age por conta própria na main local e commita direto; **nenhuma ação espera aprovação**.
- Toda ação autônoma é **reversível** e escreve uma **entrada etiquetada no Diário de Evolução** no mesmo ato.
- Checks de qualidade rodam **automáticos** (o Atlas se auto-verifica); não há humano no meio.
- Controles do operador: **master switch** (liga/desliga tudo) e **push remoto** (eixo separado). Nada mais.
- Vocabulário proibido segue valendo (Jarvis/Rivals/benchmark/superiority/concurrent).
