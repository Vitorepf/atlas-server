# LOOP — CONTEXTO COMPLETO (handoff para outro Claude)

> Arquivo de transferência de contexto. Objetivo: um Claude novo lê isto e entende
> **o que o Loop é, por que nasceu, qual o objetivo de vida dele, o estado real hoje
> e o que fazer a seguir** — sem repetir os erros que já queimaram dias.
>
> **Fonte de verdade canônica** (este arquivo é projeção/síntese; se divergir, a fonte vence):
> - `docs/loop-canonical-definition.md` (o documento-mãe; o operador repetiu 5× até travar)
> - Memórias `loop-*` em `~/.claude/projects/.../memory/` (ver §12)
> - CLAUDE.md (bloco "Loop — DEFINIÇÃO CANÔNICA")

---

## 0. A correção que abre tudo: MODELO ≠ FERRAMENTA EXTERNA

Este é o erro que um Claude (eu) cometeu e que custou a confiança do operador. **Grave antes de tudo:**

**O Loop SEMPRE foi para ser `Atlas + Hermes`. Essa é a orquestração mais poderosa que existe.**

- **Hermes** é o harness agêntico do Atlas: edita arquivo, roda teste, itera (max_turns 90), e — crucial — **se reaprende** (auto-melhora: memória, skills, procedures, evidência de sessão, tudo fluindo pela governança do Atlas via `atlas_adapter`). O Hermes **dirige QUALQUER modelo** por baixo: open-source (MiniMax-M3) **ou** frontier (Opus, Codex/gpt-5.x). O modelo é **trocável**; o Hermes é o substrato.
  - Por isso "duas evoluções compõem": o **Hermes melhora ao ser usado** + o **Atlas colhe/governa** esse aprendizado. Ver `loop-runs-on-hermes-minimax`.
  - Por isso **NUNCA se pina modelo no Atlas** (`hermes_cli_default`): pinar mata a cadeia de fallback nativa do Hermes. Ver `loop-hermes-native-no-model-pin`.

- **"Claude Code" e "Codex" como _plataformas_ são FERRAMENTAS EXTERNAS** — não confundir com os modelos. Um modelo frontier (Opus, gpt-5.x) pode rodar **dentro** do Hermes como engine; isso é arquitetura correta. Já usar o **Claude Code / Codex como plataforma externa** para fazer o trabalho do Loop (como está sendo feito AGORA, com este Claude Code) é a **forma BURRA / paliativa** — só se faz porque o Loop ainda é incapaz e o operador **precisa bater meta**.

- **O erro proibido:** dizer "o Hermes é o músculo fraco, o músculo deveria ser Claude Code/Codex". Isso confunde o **modelo** (trocável, pode ser frontier) com a **plataforma externa** (paliativo). O músculo correto é **o modelo que o Hermes dirige**; a plataforma externa é muleta temporária, não a arquitetura.

> Regra de ouro do handoff: **a arquitetura-alvo é Atlas+Hermes dirigindo o melhor modelo. Claude Code/Codex-plataforma é stopgap para destravar meta enquanto o Loop nativo não dá conta.**

---

## 1. O QUE É O LOOP

Você dá um **ESCOPO** (ex.: o próprio bloco do Loop; Atlas Dev; engenharia de contexto/memória). O Loop **mói nesse escopo 24/7 buscando EVOLUÍ-LO ao máximo patamar** — usando o cérebro do Atlas para entender o escopo **inteiro** e o **objetivo** dele (para que serve, o que deveria ser de verdade), e então entregando a **evolução mais exponencial possível**.

Entrega legítima é qualquer uma destas (e o Loop julga **onde** o impacto é maior):
- refatoração **DE VERDADE** (elimina métodos, simplifica, mais sólido/abstrato);
- tornar um fluxo probabilístico em **determinístico**;
- tirar **duplicações / encanamento**;
- adicionar **quality gates / QA / loops de auto-correção** (o que Codex/Claude Code crus não têm);
- novas funcionalidades / algoritmos;
- feature **multi-agente coordenada**;
- um salto de **alavancagem composta** (1 mês de loop ⇒ a engenharia do Atlas roda meses melhor).

**Pensa como SISTEMA** (alavancagem + 2ª ordem): julga ONDE/QUANDO a melhoria importa e antecipa o gargalo que a própria melhoria cria (deixou o código sair rápido demais → a REVISÃO vira gargalo → adiciona etapa de verificação).

**Régua de valor = TEMPO × NÍVEL.** 1h → avanço real; 5 dias 24/7 → extraordinário; 1 mês → multiplicado. Meta declarada: **a engenharia de qualidade mais avançada do mundo.**

---

## 2. POR QUE O LOOP NASCEU / OBJETIVO DE VIDA (o teto)

**O Loop nasceu para ser o ÚNICO que evolui o Atlas.** Estado final ("completo"):

> O Loop é o **único** que mexe no código do Atlas. Roda **24/7, sozinho**, fazendo **tudo**: implementa, melhora, **identifica e corrige bugs, refatora, atualiza docs**, ajusta — evoluindo o Atlas por inteiro. **Sem nenhum humano e sem nenhuma plataforma externa (Claude Code / Codex / Factory) revisando.** Ele mesmo garante a própria qualidade (validação cross-model, gates, QA, o rigor da fase de projeção). O Atlas vira um **sistema operacional que se autoaprimora.**

- **"Completo" = limiar de AUTONOMIA, não de descanso.** O Loop nunca "termina a lista e para". Pela **Faculdade de Ambição** (§3), o escopo não tem teto.
- **Operador fica hands-off:** no máximo **descreve um pedido → entra numa LISTA** que o Loop implementa. Foca nas empresas dele; o Atlas o ajuda nelas.
- **Escada de território (escopo é GANHO provando, não dado):**
  1. começa PEQUENO — o Loop melhora o **próprio Loop / ecossistema do Loop**;
  2. provou → sobe para **engenharia** (contexto, memória, compactação);
  3. sobe de nível e ganha mais território à medida que entrega com qualidade.

**A pergunta que sempre guia o Loop:** *"qual é a próxima coisa — da lista do operador OU que eu identifico — que é EXPONENCIALMENTE melhor e torna o Atlas um SO melhor?"*

Isto liga à tese-mãe do Atlas: **Self-Construction OS — o Atlas constrói e evolui o Atlas.**

---

## 3. A FACULDADE DE AMBIÇÃO (isto DEFINE o Loop)

Um escopo dado ao Loop **NÃO TEM TETO.** Melhorar o Loop é melhorar uma **inteligência** — recursivo e aberto. Um app estático tem teto; **uma mente que evolui, não.**

- **Loop BURRO:** para quando acaba o trabalho **reativo** óbvio (bug/refactor/gap/doc) — "terminei minha lista" → para. **ERRADO.**
- **Loop INTELIGENTE:** atinge o limite e o **ULTRAPASSA** — pergunta *"como eu poderia ser fundamentalmente mais poderoso / inteligente / resiliente / autônomo do que sou agora?"* e **ORIGINA o próximo salto de grandeza** (projeta → implementa → certifica).
- **Nunca proxy, nunca fake.** Ficar sem trabalho reativo trivial é o **GATILHO para elevar o horizonte** e achar o próximo salto REAL — jamais licença para inventar trabalho cosmético. Ver `loop-ambition-faculty`.
- **Recursivo:** os saltos que mais importam melhoram o **próprio cérebro/cert/arquitetura** do Loop → a curva sobe.

---

## 4. COMO ENTREGA (pipeline — contínuo, commit-por-pedaço, NUNCA one-shot)

1. **ENTENDER** o escopo + seu objetivo (cérebro do Atlas).
2. **IDENTIFICAR** a evolução mais exponencial.
3. **PROJEÇÃO / ARQUITETURA — a fase que DOMINA a qualidade.** Modelo frontier de projetação cria → **OUTRO modelo (não o criador)** critica → volta → detalha. **Fica em loop só nessa fase** até "definido / máximo possível". Quem estrutura tem que ser o mais avançado.
4. **ORQUESTRAÇÃO:** quais modelos, quantos agentes em paralelo e onde (multi-agente coordenado obrigatório).
5. **IMPLEMENTAÇÃO** multi-agente.
6. **TESTE** — garante.
7. **REVISÃO DE LÓGICA + WIRING** — tudo ligado, do começo ao fim, nada desconectado.

Entre 1–2 e 3, o Loop consulta o **`LoopPatternRegistry`** (repertório governado de padrões de execução: docs-sweep, ticket-to-PR, devil's-advocate, champion/challenger, fresh-clone, control-plane-orchestrator etc.). Pattern novo só vira padrão após gate fresco + comparação + verificação independente; **quem cria a estrutura não a aprova.**

---

## 5. COMO GARANTE QUALIDADE SOZINHO (o moat — substitui o revisor humano)

A única coisa entre uma mudança ruim e a `main`:
- **verificador frozen OUT-OF-PROCESS** (re-roda a aceitação sozinho);
- **diff-earned** (rejeita verde falso);
- **painel CROSS-MODEL** (quem ESCREVEU nunca julga a própria mudança — modelo diferente critica; é a vantagem real do Factory);
- **mutation kill-vector** + **merge fail-closed**;
- **núcleo FORBIDDEN/pétreo IMUTÁVEL** (impede o engenheiro auto-modificante de serrar o galho onde senta).

**Os 7 órgãos PROTECT (NUNCA quebrar comportamento):** `AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS` · `AtlasEvolutionFrozenJudge` · `AtlasLoopSemanticImplementationCertifier` · `AtlasEngineeringHonestyGate` · `AtlasLoopSignalAnalyzer` · `AtlasLoopMutationAdequacyGateService` · `AtlasLoopIntentVerifierFactory`. Esse moat é o ativo real do Atlas hoje (~8/10) — mas só foi exercido em **alvo único bounded**.

---

## 6. O QUE O LOOP NÃO É (anti-Goodhart — zero tolerância)

- NÃO é ferramenta que roda 24h removendo linhas/espaços/limpezas pequenas.
- NÃO é otimizador de **PROXY** (landing-rate, ciclomática, test-count — números que não deixam o Atlas mais capaz).
- NÃO é **one-shot** (`patience`/`best-of-N`/desistir = orçamento finito, errado para loop 24/7).
- Refactor que **preserva comportamento** (e o cert prova que preservou) = **melhoria ZERO**.

**Por que IAs erram:** viés de mensurabilidade → Goodhart. A IA colapsa o objetivo difícil ("Atlas exponencialmente melhor") no escalar fácil de certificar e debugga o **parâmetro** quando o operador corrige a **moldura**. **REGRA DURA:** antes de tocar qualquer coisa, responda *"isso evolui o escopo exponencialmente de verdade, deixando o Atlas mais capaz?"* — se for edição pequena / faxina / mover proxy → **PARE, não é trabalho de loop.** (Uma IA derivou para proxy por dias. Ver `loop-not-proxy-cleanup-feedback`.)

---

## 7. ESTADO ATUAL HONESTO (o que funciona, o que foi PROVADO que não funciona)

**Onde roda:** o Loop roda num **CLONE** isolado (`atlas-loop-run`, branch `atlas/loop/run`, DB próprio `atlas_loop`) — **nunca** em `atlas-server/main` (do operador, WIP de marketing). O refactor/evolução do bloco é feito num segundo clone (`atlas-loop-dev`, `atlas/loop/dev`) e entregue mergeando dev→run num momento quieto.

**Provider hoje:** `hermes_cli` → Hermes → **MiniMax-M3** (implementação/grind, barato) com **fallback Codex/gpt-5.4** (só quando MiniMax não dá conta). `reasoning_effort: medium`. Codex **não** é o default — queimar token de Codex em implementação é proibido. Ver `loop-provider-minimax-impl-codex-hard`.

**Master switch:** `ATLAS_LOOP_MASTER_ENABLED` (default FALSE, fail-closed). Liga com `php artisan atlas:loop:on`, desliga com `atlas:loop:off`. **Estado pétreo: o Loop nunca religa sozinho.** Ver `loop-master-switch`.

**O que FUNCIONA:** o moat de certificação (frozen judge + cross-model + mutation + diff-earned) é real e ~8/10 em **alvo único bounded**. O motor (Hermes→MiniMax) edita arquivo e itera de verdade (driver-level provado).

**O que foi PROVADO que NÃO funciona (2× ao vivo, 23/06) — não repetir o erro:** o Loop **não entrega no próprio código maduro** (`AutonomousEvolution`). Duas causas:
1. **Sem material FÁCIL** — código maduro não tem bugs → a lane que certifica bug-fix fica seca.
2. **O único material que sobra (orphan-wiring) é MULTI-FILE/estrutural** — e o grind do Loop é **single-file** (edita 1 target para passar 1 teste). Mecanismo ≠ tarefa. MiniMax (engine barato/fraco) menos ainda.

→ **Corolário:** a refatoração bruta do bloco (deletar farms sintéticos, splitar god-class, wirar lanes) é **obra do ENGENHEIRO** (Claude forte multi-file), **não terceirizável pro Loop** no estado atual. Ver `loop-cannot-deliver-on-own-mature-code` e `loop-material-fuel-gap`.

**Caps honestos (model-bound, gravar pra não prometer demais):** originação greenfield-novel, verificação de feature nova (input humano), correção semântica de comportamento novo — não são determinísticos num modelo fraco. Fechar o ciclo na `main` sozinho **nunca foi provado** (delivery ~3/10). O moat precisa **escalar** de alvo-único para todo o código sem virar self-declared/Goodhart. Recursive-safety (o Loop melhorar a própria máquina de qualidade) é o risco mais profundo — o pétreo é cerca, não solução.

---

## 8. AS PRIORIDADES (ordem do operador)

**Parte 1 — um Loop (Atlas+Hermes) extremamente eficiente e inteligente,** cumprindo o que sempre deveria: **refatorações complexas, auto-melhoria, faculdade de ambição, sempre a maior alavancagem no menor tempo possível.** O Loop **nunca** conseguiu isso — esta é a fundação a destravar primeiro. Dentro dela:
   - **(1a) Refatoração completa/absoluta do bloco** `app/Services/Ai/AutonomousEvolution/**` — enxuto, simples de entender, robusto, rápido, sem legado, sem os farms sintéticos que afogam material real. (Plano: `docs/loop-refactor-plan.md`, 12 slices; estado: `docs/loop-refactor-state.md`. Slices 12 e 3a já feitas pelo engenheiro: −2476L.)
   - **(1b) O CÉREBRO/MEMÓRIA — a fundação principal:** compreensão **onipresente** do escopo (sabe TUDO: cada método, dependência, objetivo, comportamento, meta). Seed feito (`AtlasLoopScopeComprehensionModel`: métodos+deps determinísticos). Falta torná-lo o cérebro completo.

**Parte 2 — com esse cérebro capaz de SERVIR TASKS,** dirigir Claude Code/Codex (ferramentas externas) fica **muito mais fácil e simples**: o cérebro identifica a maior evolução, decompõe em tasks **sem conflito** (dois agentes nunca pegam tasks que colidem), e serve para N agentes em loop (buscar → implementar → entregar). Com a parte 1 pronta, a parte 2 fica **mais rápida e concreta**. Há infra já **planejada/scaffolded** para isso no Atlas (Self-Construction OS / Agent Control Plane — ver §10) — alavancar, não reinventar.

> **Começar pela parte 1.** A parte 2 é consequência da parte 1.

---

## 9. O PARADOXO PRAGMÁTICO (por que estamos usando Claude Code AGORA)

O operador é explícito: usar **Claude Code/Codex como plataforma externa é a forma "burra"** de fazer — não é a arquitetura-alvo (que é Atlas+Hermes). Mas o Loop nativo **ainda não tem a capacidade**, e o operador **precisa atingir metas**. Então, **temporariamente**, um Claude forte (plataforma externa) faz o trabalho multi-file que o Loop não faz — a refatoração e a construção do cérebro — para destravar a parte 1.

Quando o cérebro (parte 1b) souber servir tasks limpas, dirigir esses agentes externos vira trivial — e isso é a ponte para a parte 2, até que o Atlas+Hermes nativo assuma. **Nunca tratar o stopgap como destino.**

---

## 10. INFRA EXISTENTE PARA A PARTE 2 (não reinventar)

O Atlas **já planejou** o padrão "vários agentes externos pedindo+implementando tasks" (antes mesmo de ser autônomo). Localização:
- `app/Services/Ai/Aaeos/Generated/` — `AtlasAgentControlPlane*` (SafetyInvariants, RuntimeReadinessGapMatrix, CertificationOutputMap), `AtlasSelfConstruction*`, e a família `AgentAutomaticDispatchSchedulerOneShotTick…Codex…ExternalProcessInvoker…` (spawn de Codex como processo externo).
- Docs: `docs/engineering-knowledge-base/self-construction/agent-control-plane-contract-part-02.md`, `…/agent-dispatch-planner-runtime-v1.md`.

**Caveat honesto:** está em `Generated/` com nomes auto-gerados gigantes — cheira a **scaffold/stub, não código vivo provado.** Antes de confiar, avaliar o que é real vs casca. É insumo para a parte 2, não fundação pronta.

---

## 11. ARQUITETURA OPERACIONAL + REGRAS DURAS

- **Clones:** dev = `/Users/vitorepf/develop/Atlas/atlas-loop-dev` (`atlas/loop/dev`); run = `/Users/vitorepf/develop/Atlas/atlas-loop-run` (`atlas/loop/run`, DB `atlas_loop`). **NUNCA tocar `atlas-server/main`.**
- **PHP:** `/opt/homebrew/bin/php`; testes `./vendor/bin/phpunit -d memory_limit=4096M` no escopo Loop/AutonomousEvolution.
- **Provider:** MiniMax (impl) + Codex (hard) via `hermes_cli_default`. **Nunca pinar modelo.**
- **Drift→main:** resolver branch atual (NÃO hardcode `git checkout main`) + `migrate:fresh` na `atlas_loop` **só** após travar 3× que o DB é `atlas_loop` (não o `atlas` do operador). Sem `git reset --hard`. Migrations idempotentes (nunca INSERT manual em `migrations`).
- **Commits "save" do operador** = só merge/ff, NUNCA untangle. Push só com OK explícito.
- **Honestidade > verde:** se algo só sai fingindo ou quebrando, PARA e relata. Única linha vermelha: não enfraquecer os 7 órgãos PROTECT.
- **Idioma:** português com o operador.

---

## 12. PONTEIROS (fonte de verdade viva)

**Docs canônicos:**
- `docs/loop-canonical-definition.md` — o documento-mãe.
- `docs/loop-refactor-plan.md` — plano de refatoração (12 slices, arquitetura-alvo 3 camadas, lista PROTECT).
- `docs/loop-refactor-state.md` — estado do ciclo (maturidade por slice/frente).

**Memórias (`~/.claude/projects/-Users-vitorepf-develop-Atlas-atlas-server/memory/`):**
- `loop-true-objective-canonical` · `loop-endgoal-sole-autonomous-self-engineer` · `loop-ambition-faculty` · `loop-final-state-vision` · `loop-delivery-pipeline-design-dominant` — o que/por quê/teto/pipeline.
- `loop-not-proxy-cleanup-feedback` — guardrail anti-Goodhart.
- `loop-runs-on-hermes-minimax` · `loop-hermes-native-no-model-pin` · `loop-provider-minimax-impl-codex-hard` — Hermes/provider.
- `loop-cannot-deliver-on-own-mature-code` · `loop-material-fuel-gap` — o gap provado.
- `loop-master-switch` · `loop-materializer-sandbox-floor` — safety/runtime.

**Código (escopo):** `app/Services/Ai/AutonomousEvolution/**` + tests + os 7 órgãos PROTECT (§5).

---

> **Resumo de uma linha para o Claude que assume:** o Loop é o Atlas+Hermes evoluindo um escopo 24/7 sem teto e sem revisor humano; a arquitetura-alvo dirige o melhor modelo por baixo do Hermes (Claude Code/Codex-plataforma é só stopgap para bater meta); o trabalho AGORA é a Parte 1 (refatorar o bloco + construir o cérebro de compreensão onipresente), porque o Loop ainda não entrega no próprio código maduro — isso é obra de engenheiro multi-file, provado 2×.
