# Loop — DEFINIÇÃO CANÔNICA (fonte de verdade; ler ANTES de qualquer trabalho no loop)

> Este é o documento canônico do que o **Atlas Loop** é. O operador repetiu isto 5× até travar, porque IAs (incluindo Claude) erraram o rumo por dias. Espelha as memórias `loop-true-objective-canonical`, `loop-delivery-pipeline-design-dominant`, `loop-endgoal-sole-autonomous-self-engineer`, `loop-not-proxy-cleanup-feedback`. **Qualquer doc/código/IA que divergir disto está errado.**

## O que o Loop NÃO é (zero tolerância)
- NÃO é ferramenta que roda 24h queimando token removendo linhas/espaços/limpezas pequenas.
- NÃO é otimizador linear de **PROXY** (landing-rate, ciclomática, test-count — números que NÃO deixam o Atlas mais capaz).
- NÃO é **one-shot** (tenta N cenários, converge no `patience`, desiste). `patience`/`max-scenarios`/`attempt-cap`/best-of-N são lógica de **orçamento finito** — erradas pra um loop 24/7 de tempo infinito.
- Refactor que **preserva comportamento** (o cert prova que preservou) = **melhoria ZERO**, mesmo que limpe o código.

## O que o Loop É
Você dá um **ESCOPO** (ex.: Atlas Dev; Atlas Engineering Software OS; a feature de memória/contexto). O loop **mói nesse escopo 24/7 buscando EVOLUIR ele ao máximo patamar** — usa o cérebro do Atlas pra entender o escopo INTEIRO e o **OBJETIVO** dele (pra que serve, o que deveria ser de verdade).

Entrega = a evolução **mais EXPONENCIAL** possível. Pode ser: refatoração DE VERDADE (elimina vários métodos, simplifica, mais sólido/abstrato); tornar um fluxo probabilístico em **determinístico**; tirar **duplicações/encanações**; adicionar **quality gates / QA / loops de auto-correção** (o que Codex/Claude Code não têm); novas funcionalidades/algoritmos; feature **multi-agente coordenada**; um salto de **alavancagem composta** (1 mês de loop = Atlas-engenharia roda meses melhor).

**Pensa como SISTEMA** (julgamento de alavancagem + 2ª ordem): julga ONDE/QUANDO a melhoria é impactante (contexto? memória? revisão?) e antecipa o gargalo que a própria melhoria cria (ex.: deixou o Atlas Dev gerando código rápido demais → a REVISÃO vira gargalo → adiciona etapa de verificação de PR).

**Régua de valor = TEMPO × NÍVEL:** 1h → avanço real; 5 dias 24/7 → extraordinário; 1 mês → multiplicado. Meta: engenharia da qualidade mais avançada DO MUNDO.

## A FACULDADE DE AMBIÇÃO — o loop NÃO tem teto dentro do escopo (isto DEFINE o loop)
Um escopo definido para o loop **NÃO TEM TETO**. Melhorar o loop é melhorar uma **INTELIGÊNCIA** — e isso é recursivo e ABERTO (sempre dá pra deixar uma mente mais inteligente, uma defesa mais resiliente, uma arquitetura mais elegante, uma decisão mais afiada; cada melhoria abre horizontes que ele nem enxergava). Um app morto/estático tem teto; **o escopo do loop — uma mente que evolui — NÃO tem.** Se um escopo é dado ao loop, ele **VIVE esse escopo, SEMPRE melhorando, sempre elevando o nível.** O loop existe **para o escopo evoluir SEMPRE** — essa é a razão dele existir.

- **Loop BURRO:** para quando acaba o trabalho **REATIVO** óbvio (bug/refactor/gap/doc) — "terminei minha lista" → para. ERRADO.
- **Loop INTELIGENTE:** ao atingir o limite atual, **entende onde pode saltar de patamar e ELEVA a própria grandeza, poder, inteligência, resiliência e autonomia** — com trabalho real, provado, de qualidade. Atinge o limite e o **ULTRAPASSA**.
- **Mecanismo:** quando o trabalho reativo seca, o loop **NÃO PARA** — ele pergunta *"como eu poderia ser fundamentalmente mais poderoso / mais inteligente / mais resiliente / mais autônomo do que sou agora?"* e **ORIGINA o próximo salto de grandeza** (projeta → implementa → certifica, como qualquer evolução real).
- **NÃO confundir com proxy/fake:** o salto de ambição é trabalho REAL (certificado, comportamento provado, genuinamente mais poderoso). Ficar sem trabalho reativo trivial **NÃO é licença pra inventar trabalho fake** — é o **GATILHO pra elevar o horizonte** e achar o próximo salto REAL. Nunca proxy. Nunca cosmético. Sempre uma elevação real da grandeza do loop.
- **Recursivo:** os saltos que mais importam melhoram o **próprio cérebro/cert/arquitetura** do loop (ele fica melhor em perceber + arquitetar o próximo salto) → a curva sobe; o **músculo (motor) dá a inclinação, não uma parede próxima** (e o músculo melhora sozinho, capturado pela antifragilidade). Memória canônica: `loop-ambition-faculty`.

## Como entrega (pipeline; contínuo, commit-por-pedaço, NUNCA one-shot)
1. **ENTENDER** o escopo + seu objetivo (cérebro do Atlas).
2. **IDENTIFICAR** a evolução mais exponencial.
3. **PROJEÇÃO / ARQUITETURA — A FASE QUE DOMINA A QUALIDADE.** IA **frontier** de projetação (a mais top via Hermes / Atlas Code) cria → passa pra **OUTRA IA (não a criadora)** criticar → outra → **volta pra criadora** → detalha. Fica **em loop só nessa fase** até "definido / máximo possível". Quem estrutura tem que ser o mais avançado.
4. **ORQUESTRAÇÃO**: quais modelos, como coordenar, **quantos agentes em paralelo e onde** (estilo workflow dinâmico do Claude Code — multi-agente coordenado obrigatório).
5. **IMPLEMENTAÇÃO** multi-agente.
6. **TESTE** — testa, garante.
7. **REVISÃO DE LÓGICA + WIRING** — tudo ligado, funcionando do começo ao fim, nada desconectado; deixa tudo conectado e melhorado.

## LoopPatternRegistry (como o loop escolhe a estrutura certa)
Entre **ENTENDER/IDENTIFICAR** e **PROJEÇÃO**, o loop deve consultar o `LoopPatternRegistry`: um repertório governado de padrões de execução que transforma skills, loop catalogs e aprendizados internos em contratos Atlas. Ele não copia prompt cru. Ele escolhe a menor estrutura capaz de gerar o maior avanço comprovável: docs sweep, ticket-to-PR, devil's advocate, loop harness verification, self-improving champion, fresh clone, control-plane orchestrator, decision-ready handoff, live-proof/release gate, baseline, full eval etc.

O registry é também parte do trabalho do próprio loop: se nenhum padrão serve, o loop propõe um padrão novo; se um padrão existente falha ou perde para um challenger, ele propõe otimização. Mas pattern novo só vira padrão após gate fresco, comparação champion/challenger e verificação independente. O mesmo agente que cria a estrutura não pode aprová-la.

Skills externas de maintainer/orquestração, como o `maintainer-orchestrator`,
entram como source material para controle de workers, autorização separada,
handoff humano preparado e prova viva. Elas nunca autorizam merge, push,
release, uso de credencial ou escopo pessoal por si mesmas.

Harnesses externos de super-agente, como o DeerFlow, entram como source
material para disciplina de runtime: run journal, tools sob demanda, subagentes
nao-recursivos, sandbox virtual-path, output budget, memoria com hygiene e
config reload boundaries. Eles nunca importam memoria canonica, provider auth,
stream in-memory, UI/chat authority ou scanner LLM como seguranca do Atlas.

## O TETO (objetivo de vida do loop)
A implementação do loop **só termina** quando ele é o **ÚNICO** que mexe no código do Atlas: 24/7 sozinho, implementa, **identifica+corrige bugs, refatora, atualiza docs — tudo**, **sem humano e sem Claude Code/Codex/Factory revisando**, garantindo a qualidade com a **própria estrutura**. O Atlas vira um **SO que se autoaprimora**. O operador fica hands-off (no máximo **descreve um pedido → entra numa LISTA** que o loop implementa; só entra por escolha via Atlas Dev / Forge).

**Escada de território:** começa PEQUENO (o loop melhora o **próprio loop**) → prova → sobe pra engenharia (contexto, memória, compactação, o próprio loop) → ganha mais escopo **provando**, não recebendo.

**A pergunta que sempre guia:** *"qual é a próxima coisa — da lista ou que eu identifico — que é exponencialmente melhor e torna o Atlas um SO melhor?"*

## Como garante qualidade SOZINHO (substitui o revisor humano)
Verificador frozen **out-of-process** + **diff-earned** (mata verde falso) + painel **cross-model** (quem escreveu nunca julga a própria mudança; modelo diferente critica — a real vantagem do Factory) + mutation + merge **fail-closed** + núcleo **FORBIDDEN/pétreo imutável** (pra não serrar o galho onde senta).

## Problemas difíceis honestos (gravar pra NÃO prometer demais)
1. **Caps model-bound** (engine fraco): originação greenfield-novel, a barra de verificação de feature nova (input humano) e correção semântica/design de comportamento novo NÃO são determinísticos num modelo fraco → núcleo novel vai por **abstain-and-ask** (1 pergunta ao operador — bate com "descrevo→lista") OU **engine forte** sob o mesmo moat (aposta N×M).
2. **Fechar o ciclo na main sozinho NUNCA foi provado** (delivery ~3/10) — é o gargalo real.
3. O moat tem que **ESCALAR** de alvo-único pra todo o código (bug/refactor/docs sem barra frozen pré-existente).
4. **Recursive-safety:** o loop melhorar a própria máquina de qualidade é a etapa mais arriscada — pétreo hoje é cerca, não solução.

## Por que IAs erram (e a regra dura)
Viés de mensurabilidade → Goodhart: a IA colapsa o objetivo difícil ("Atlas exponencialmente melhor") no escalar fácil de certificar (landing-rate/ciclomática) e debugga o **parâmetro** (qual métrica) quando o operador corrige a **moldura** (o que o loop É). **REGRA:** antes de o loop tocar qualquer coisa, responda — *"isso evolui o escopo exponencialmente de verdade, deixando o Atlas mais capaz?"* Se for edição pequena / faxina / mover um proxy → **PARE, não é trabalho de loop.**
