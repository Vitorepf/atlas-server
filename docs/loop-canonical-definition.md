---
id: loop-canonical-definition
type: engineering_knowledge
title: Loop Legacy Definition / Autonomos Evolution Capability
status: active
category: autonomous-evolution
priority: 100
doc_schema: atlas_canonical_module_doc.v1
graph_layer: system
---
# Loop — DEFINIÇÃO LEGADA E MIGRAÇÃO PARA AUTÔNOMOS

> Este documento preserva o aprendizado que fez o antigo **Atlas Loop** parar de
> otimizar proxy e buscar evolução real. Ele não define mais o produto final.
> O nome e a arquitetura final são `Autonomos / Self-Construction` dentro do
> `Atlas Autonomous Engineering Government`.

> **REGRA ATUAL:** se uma seção antiga abaixo disser "Loop", leia como
> capacidade legada a migrar para Autonomos, Spec Court, Verification Court,
> Engineering Kernel, Governor ou Learning-Application Controller. Não crie novo
> runtime chamado Loop e não trate Loop como OS, governo ou autoridade final.

## REALIDADE OPERANTE (o que já roda hoje) vs. o ALVO deste doc

Este documento mistura duas coisas — separe-as ao ler:

- **VIVO (roda hoje, provado):** o **Autônomos** = cérebro externo cria tasks
  (`atlas:brain:next` origina+projeta spec author≠judge → `atlas:brain:seed` gate-and-enqueue,
  seed-gate refusa ~50%) + músculo externo implementa (`atlas:task next` → provider →
  **commit escopado na main** via `SelfConstruction/AtlasTaskScopedCommitter`, `git add -- <arquivos>`,
  nunca `add -A`, nunca merge). **Já produziu 4.841 landings.** Doc canônico do vivo:
  `docs/engineering-knowledge-base/atlas-autonomos-live-system.md`.
- **ALVO / aspiração (arquitetura v3, ainda não é o runtime do dia-a-dia):** o
  `Atlas Autonomous Engineering Government` com Spec Court, Verification Court, Governor,
  Engineering Kernel e separação plena de poderes descrita abaixo.
- **MORTO:** o antigo runtime monolítico "Loop" (ACDE, `AutonomousEvolution/` raiz). Onde as seções
  abaixo dizem "delivery ~3/10" ou "fechar o ciclo na main NUNCA foi provado", isso descreve o
  **loop-morto**, não o autônomo vivo — que fecha o ciclo na main diariamente por task escopada.

## Resumo

O antigo Loop foi o piloto de autopoiese/evolução que ensinou o Atlas a buscar
saltos reais de capacidade dentro de um escopo. A régua de ambição continua
válida, mas o produto final agora é Autonomos / Self-Construction governado
pelo Atlas Autonomous Engineering Government.

## Papel no Atlas

Alimentar Autonomos / Self-Construction com evolução recursiva de alto impacto:
detectar gargalos, propor saltos, gerar verificadores, aprender com outcomes e
melhorar o próprio mecanismo de evolução. O nome Loop deve desaparecer da
arquitetura de produto conforme a migração avançar.

## Onde Se Encaixa

`Atlas Autonomous Engineering Government -> User-space runtimes -> Autonomos /
Self-Construction`. Capacidades legadas do Loop devem ser realocadas para
Autonomos, Spec Court, Verification Court, Engineering Kernel, Governor ou
Learning-Application Controller. Execução paralela, verificação, merge,
rollback, receipts e learning promotion pertencem aos órgãos separados do
governo.

## Contratos

Autonomos deve preservar a lei que o Loop aprendeu: perseguir valor real, não
proxy; operar por evidência; usar workers como músculos substituíveis; e manter
o destino final 100% Atlas-native sem dependência normal de operador, humano ou
provider.

## Fluxo

Entender escopo, identificar salto de alavancagem, projetar criticamente,
passar por Spec Court, decompor via Task Fabric, executar por workers, verificar
em Verification Court, land/revert pelo Governor, registrar evidência, aplicar
aprendizado e repetir.

## Regras para IA

Não tratar Loop como governo inteiro, produto final ou runtime novo. Não aceitar
proxy como valor, não reintroduzir dependência humana/provider no caminho normal
e não enfraquecer a separação entre criar, executar, julgar, mergear e aprender.

## Escopo de Implementacao

Este documento define a régua histórica que deve ser migrada para Autonomos. A
implementação concreta pode estar em AutonomousEvolution legado, Task Fabric,
Maestro, Spec Court, Verification Court, Governor e Self-Construction runtimes.
O objetivo de manutenção é reduzir dependência conceitual do nome Loop.

## Dependencias

Depende de Atlas Autonomous Engineering Government v3: Constitution, Mission
Control, Policy Plane, Engineering Kernel, Spec Court, Verification Court,
Governor, ReceiptLedger, Learning-Application Controller e Knowledge Sync.

## Evidencias

Evidência aceitável inclui gates server-side, verificadores frozen, holdouts,
receipts, task outcomes, rollback, docs sync, KB sync e provas de que o escopo
ficou mais capaz de verdade.

## Riscos

Goodhart por métricas fáceis, overengineering, falso verde, autoaprovação,
dependência permanente de provider/humano e Loop monolítico voltando a decidir
tudo sozinho.

## Exemplos

Exemplo válido: transformar give-backs repetidos em reparo automático do Task
Fabric. Exemplo inválido: aumentar contagem de testes ou remover linhas sem
melhorar capacidade real.

## Proximas Acoes

Manter este doc alinhado ao Governo, reforçar autonomia Atlas-native e usar as
tasks/receipts para transformar regras canônicas em runtime.

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

## Onde A Capacidade Legada Do Loop Fica Na Arquitetura Final

A capacidade evolutiva que nasceu no Loop migra para Autonomos e para os
tribunais/controladores v3. A versão final separa poderes:

```text
Atlas Autonomous Engineering Government
  -> Constitution
  -> Mission Control / AWEOS
  -> Policy Plane
  -> Engineering Kernel
  -> Spec Court
  -> Verification Court
  -> Governor
  -> Learning-Application Controller
  -> User-space runtimes
      -> Autonomos / Self-Construction
```

O antigo Loop contribui como material de migração: identificação de saltos,
ambição sem teto, anti-proxy, padrões de execução, frozen verification e
aprendizado por outcomes. Essas capacidades devem morar em Autonomos, Spec
Court, Verification Court, Learning-Application Controller, Task Fabric e
Maestro. A aprovação pertence a `Verification Court + Governor`. A autoridade
de escopo, risco e prioridade pertence a `Mission Control + Policy Plane`.

Regra dura: se um doc antigo diz "o Loop sozinho decide, executa, julga e
mergeia tudo", leia isso como histórico/aspiração pré-separação-de-poderes. A
arquitetura final exige separação entre observar, decidir, arquitetar,
decompor, executar, verificar, mergear e aprender.

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

## O TETO (objetivo de vida na arquitetura final)
A implementação final **não termina no Loop monolítico nem no nome Loop**. Ela
termina quando o
`Atlas Autonomous Engineering Government` consegue cuidar de Atlas 24/7:
entende o estado vivo, decide a maior alavancagem, arquiteta, decompõe em
tasks, distribui workers, verifica independentemente, mergeia/rejeita com
rollback, atualiza docs, transfere aprendizado e aumenta escopo por prova.

Autonomos, nesse teto, é o runtime 24/7 de Atlas construindo Atlas. Ele pode
usar workers internos/externos durante bootstrap, mas workers são músculos
substituíveis. O operador fica fora do fluxo normal: pode descrever intenção de
produto, observar evidência e acionar emergência, mas não é o aprovador
permanente de risco nem o motor que mantém o ciclo vivo. Risco alto deve ser
decidido por autonomia Atlas-native: Constitution, Policy Plane, Spec Court,
Verification Court, Governor, rollback e níveis de autorização.

**Escada de território:** começa PEQUENO (o loop melhora o **próprio loop**) → prova → sobe pra engenharia (contexto, memória, compactação, o próprio loop) → ganha mais escopo **provando**, não recebendo.

Na arquitetura multi-projeto, a mesma escada vale para qualquer projeto
admitido: começa pequeno no escopo mais seguro daquele projeto, prova valor
real, aumenta território e pode rodar uma steward lane 24/7 separada da lane do
Atlas.

**A pergunta que sempre guia:** *"qual é a próxima coisa — da lista ou que eu identifico — que é exponencialmente melhor e torna o Atlas um SO melhor?"*

## Como garante qualidade SOZINHO (substitui o revisor humano)
Verificador frozen **out-of-process** + **diff-earned** (mata verde falso) + painel **cross-model** (quem escreveu nunca julga a própria mudança; modelo diferente critica — a real vantagem do Factory) + mutation + merge **fail-closed** + núcleo **FORBIDDEN/pétreo imutável** (pra não serrar o galho onde senta).

## Problemas difíceis honestos (gravar pra NÃO prometer demais)
1. **Caps model-bound** (engine fraco): originação greenfield-novel, a barra de verificação de feature nova e correção semântica/design de comportamento novo NÃO são determinísticos num modelo fraco → núcleo novel deve **abster, repacketizar, buscar evidência, acionar worker/engine Atlas-native mais forte ou reduzir escopo**. Perguntar ao operador é exceção de intenção de produto/bootstrap, não dependência permanente.
2. **Fechar o ciclo na main sozinho NUNCA foi provado** (delivery ~3/10) — é o gargalo real.
3. O moat tem que **ESCALAR** de alvo-único pra todo o código (bug/refactor/docs sem barra frozen pré-existente).
4. **Recursive-safety:** o loop melhorar a própria máquina de qualidade é a etapa mais arriscada — pétreo hoje é cerca, não solução.

## Por que IAs erram (e a regra dura)
Viés de mensurabilidade → Goodhart: a IA colapsa o objetivo difícil ("Atlas exponencialmente melhor") no escalar fácil de certificar (landing-rate/ciclomática) e debugga o **parâmetro** (qual métrica) quando o operador corrige a **moldura** (o que o loop É). **REGRA:** antes de o loop tocar qualquer coisa, responda — *"isso evolui o escopo exponencialmente de verdade, deixando o Atlas mais capaz?"* Se for edição pequena / faxina / mover um proxy → **PARE, não é trabalho de loop.**
