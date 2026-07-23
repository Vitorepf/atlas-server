---
id: atlas-documentation-reality-intent-coformation
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas Documentation Reality Intent Coformation
slug: atlas-documentation-reality-intent-coformation
status: active
category: documentation-governance
priority: 96
summary: L2-O2 do ADRS. Primeiro incremento da co-formacao de intencao — a intent advisory. L0/L1 verificam execucao; O1 gradua o outcome; O2 pergunta ANTES de construir — isto e a coisa CERTA pro seu objetivo, ou ha algo de maior alavanca? Estritamente advisory e human-gated; opina, nunca decide, nunca sobrescreve o operador.
human_summary: O L0/L1 prova que o codigo bate com o doc; o O1 pergunta se funcionou no mundo. O O2 vem antes de tudo isso e pergunta a coisa mais importante — voce esta mandando construir a coisa certa, ou existe algo de maior alavanca? Ele so da uma opiniao e perguntas; quem decide e sempre voce.
human_what: Intent advisory L2-O2 que, antes de construir, opina se o spec proposto e o alvo certo dado o objetivo — sempre advisory, sempre human-gated, nunca uma decisao.
human_purpose: Fazer o Atlas perguntar a coisa certa antes do build sem nunca tirar a decisao do operador; opina sobre alavanca, o operador decide.
human_input: Recebe o spec proposto (mesma forma que o P1 simulate aceita) e um objetivo opcional do operador.
human_output: Entrega um envelope advisory read-only com o sinal tecnico do P1, consideracoes de intencao, perguntas de alavanca, uma recomendacao advisory e um bloco de soberania obrigatorio.
human_change_when: Mexa quando o P1 simulate mudar a forma do sinal, ou quando novas consideracoes/perguntas de alavanca forem uteis sem virar decisao.
human_block_when: Bloqueie se alguem tentar tornar o O2 uma decisao/gate, sobrescrever o operador, auto-agir, ou abrir O3 (multi-estate) a partir deste doc.
canonical_name: Atlas Documentation Reality Intent Coformation
technical_name: AtlasDocumentationRealityIntentAdvisoryService
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-documentation-reality-intent-coformation.md
tags:
  - atlas-ai
  - documentation-governance
  - adrs
  - intent-forming
  - operator-sovereignty
capabilities:
  - intent_advisory
  - leverage_questioning
  - operator_sovereignty_guardrail
  - p1_predictive_signal_reuse
decisions:
  - Nome canonico obrigatorio - Atlas Documentation Reality Intent Coformation.
  - Acronimo tecnico - ADRS-O2.
  - O2 e estritamente advisory e human-gated - produz opiniao/consideracoes, NUNCA uma decisao; nunca sobrescreve o operador, nunca auto-age.
  - O bloco de soberania e obrigatorio em todo envelope - advisory_only, human_gated, never_overrides_operator, operator_decides, is_a_decision false, auto_acts false.
  - O sinal tecnico REUSA o P1 simulate (AtlasSoftwareTwinRuntimeService) - O2 nao re-implementa predicao.
  - Recomendacao conservadora - would_duplicate => reconsider_advisory; objetivo vazio => needs_operator_judgment; senao proceed_advisory; toda recomendacao carrega o bloco de soberania.
  - Nenhum campo de decisao binaria (allow/block/deny/gate) existe no envelope; um veredito vinculante transformaria advice em decisao.
  - Runtime read-only - nenhuma escrita, execucao, mutacao ou autorizacao.
maintenance:
  - Manter abaixo de 520 linhas.
  - Atualizar quando o P1 simulate, as consideracoes ou as perguntas de alavanca mudarem.
  - Rodar docs-health, docs-authority-audit e architecture-validate apos alteracoes.
graph_id: atlas-documentation-reality-intent-coformation
human_name: Atlas Documentation Reality Intent Coformation
graph_title: Atlas Documentation Reality Intent Coformation
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-documentation-reality-outcome-grounded-leap
graph_status: active
graph_source: repo
owner: documentation-governance
implementation_state: partial
depends_on:
  - atlas-documentation-reality-outcome-grounded-leap
  - atlas-documentation-reality-outcome-grounded-truth
  - atlas-software-twin-verified-evolution-runtime
flows_to:
  - atlas-documentation-reality-reflective-self-model
unlocks:
  - intent_co_formation_advisory
  - higher_leverage_questioning_before_build
governs:
  - documentation-governance-intent-advisory
related_paths:
  - docs/engineering-knowledge-base/atlas-documentation-reality-outcome-grounded-leap.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-outcome-grounded-truth.md
  - docs/engineering-knowledge-base/atlas-software-twin-verified-evolution-runtime.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-evolution-ladder.md
repo_paths:
  - docs/engineering-knowledge-base/atlas-documentation-reality-intent-coformation.md
  - app/Services/Engineering/AtlasDocumentationRealityIntentAdvisoryService.php
  - app/Console/Commands/AtlasDocumentationRealityIntentAdvisoryCommand.php
  - tests/Feature/Engineering/AtlasDocumentationRealityIntentAdvisoryTest.php
allowed_changes:
  - Refinar consideracoes, perguntas de alavanca e o envelope mantendo a regra da soberania.
  - Ligar novos sinais estruturais derivados do P1 simulate, sem virar decisao.
forbidden_changes:
  - Tornar o O2 uma decisao, gate ou veredito vinculante (allow/block/deny).
  - Sobrescrever o operador, auto-agir ou autorizar mutacao.
  - Re-implementar predicao em vez de reusar o P1 simulate.
  - Abrir O3 (multi-estate compounding) a partir deste doc.
evidence:
  - docs/engineering-knowledge-base/atlas-documentation-reality-intent-coformation.md
  - app/Services/Engineering/AtlasDocumentationRealityIntentAdvisoryService.php
evidence_refs:
  - symbol: AtlasDocumentationRealityIntentAdvisoryService
  - command: atlas:documentation-reality-intent-advisory
  - test: AtlasDocumentationRealityIntentAdvisoryTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:docs-authority-audit --json"
  - "php artisan atlas:ai:architecture-validate --json"
requires_evidence: true
risk_level: critical
line_limit: 520
ai_entrypoints:
  - Leia este doc para entender como o ADRS opina sobre o alvo (intencao) antes do build sem nunca decidir pelo operador.
ai_usage_notes:
  - O2 e advisory + human-gated; a IA opina, o operador decide. Nunca trate a recomendacao como um gate.
  - O sinal tecnico vem do P1 simulate; O2 nao re-implementa predicao.
next_actions:
  - Refinar consideracoes/perguntas conforme uso real, sem virar decisao.
  - So depois de O2 provado, abrir O3 (multi-estate compounding).
---

# Atlas Documentation Reality Intent Coformation

## Resumo

ADRS-O2 e o **primeiro incremento** da co-formacao de intencao do L2
(`atlas-documentation-reality-outcome-grounded-leap`). O L0/L1 **verificam
execucao** ("o codigo bate com o doc?"). O O1 **gradua o outcome** ("funcionou no
mundo?"). O O2 vem **antes** de tudo isso e pergunta a coisa que vem antes do
build:

```text
Isso e a coisa CERTA de construir pro seu objetivo —
ou existe algo de maior alavanca?
```

A resposta e uma **opiniao + consideracoes + perguntas de alavanca**, nunca uma
decisao. O O2 e **estritamente advisory e human-gated**: ele opina, o **operador
decide**. O envelope e read-only e carrega, em toda resposta, um bloco de
soberania que torna isso inequivoco.

## Papel no Atlas

Acertar a execucao (L1) e o outcome (O1) nao basta se o **alvo** estiver errado.
Uma IA pode implementar com drift zero, e ate ver uso real, de uma coisa que nao
era a de maior alavanca. O O2 fecha esse buraco **antes** do build: faz a pergunta
de intencao e devolve sinais + perguntas pro operador — sem nunca tirar dele a
decisao. Este incremento entrega so a **advisory**; propagar aprendizado entre
projetos (O3) e incremento posterior.

## Onde Se Encaixa

```text
tarefa proposta
  -> L1/P1 preve impacto tecnico (duplica? drift? owner?) — AtlasSoftwareTwinRuntimeService::simulate
  -> L2-O2 (este doc) — intent advisory
       wrap: sinal tecnico do P1 + camada de intencao
       opina: proceed_advisory | reconsider_advisory | needs_operator_judgment
  -> operador decide (SOBERANIA)
  -> implementacao (write-bound, fora deste doc)
  -> L2-O1 outcome-grounded (funcionou no mundo?)
  -> O3 Multi-estate compounding (incremento posterior)
```

## A Regra Cardinal — Soberania do Operador

A regra que define este incremento (herdada de
`atlas-documentation-reality-outcome-grounded-leap.md:38, :80, :167, :191 — o doc
nomeia "co-formacao que invade soberania" como O risco do L2 inteiro), **absoluta**:

```text
O2 e ESTRITAMENTE advisory + human-gated.
Produz uma OPINIAO/CONSIDERACOES, NUNCA uma decisao.
NUNCA sobrescreve o operador, NUNCA auto-age, NUNCA finge saber
"a coisa certa" com falsa certeza. Surfaca sinais + perguntas e
EXPLICITAMENTE defere ao operador, que decide.
```

Consequencias diretas, gravadas em codigo:

- Todo envelope carrega um **bloco de soberania** obrigatorio:
  `advisory_only:true, human_gated:true, never_overrides_operator:true,
  operator_decides:true, is_a_decision:false, auto_acts:false`. O `claim_policy`
  espelha as mesmas garantias.
- **Nao existe** campo de decisao binaria (allow/block/deny/gate) em lugar nenhum
  do envelope. Um veredito vinculante transformaria advice em decisao — proibido.
- O comando **sempre sai 0** (sucesso), qualquer que seja a recomendacao;
  bloquear faria do advisory um gate.

## Contratos

`AtlasDocumentationRealityIntentAdvisoryService` (read-only):

| Metodo | O que faz |
|---|---|
| `adviseProposal(array $proposed, string $objective = ''): array` | dado o spec proposto (mesma forma do P1 simulate) e um objetivo opcional, devolve a advisory |

A forma do `$proposed` e a mesma que o P1 `simulate` aceita:
`{kind, slug, graph_id, owner, capabilities, governs, implementation_state, symbol}`.

Recomendacao advisory (`advisory_recommendation.value`), **conservadora**:

| Valor | Quando | Natureza |
|---|---|---|
| `reconsider_advisory` | o P1 preve `would_duplicate` | opiniao - reconsidere/reuse; o operador pode prosseguir deliberadamente |
| `needs_operator_judgment` | objetivo vazio (nao da pra avaliar alavanca) | honesto - sem o objetivo, nao se finge saber o alvo certo |
| `proceed_advisory` | sem duplicacao prevista E com objetivo | opiniao verde - confirma que da pra construir, nao que e a maior alavanca |

Envelope `atlas.documentation_reality.intent_advisory.v1` (com hash):

```text
schema_version, mode=intent_advisory, level=L2-O2, increment,
proposal { kind, slug, graph_id, owner, symbol, capabilities[], governs[],
           implementation_state, objective, objective_stated:bool },
technical_signal { source, verdict, would_duplicate:bool, duplicate_reason,
                   graph_id_collisions[], capability_overlap[], would_drift:bool,
                   owner_resolved:bool, owner_doc_id, degraded:bool, ... },
considerations[ { kind, consideration } ],
leverage_questions[],
advisory_recommendation { value, is_advisory:true, is_a_decision:false,
                          label, rationale },
sovereignty { advisory_only:true, human_gated:true, never_overrides_operator:true,
              operator_decides:true, is_a_decision:false, auto_acts:false },
writes:false,
claim_policy { read_only:true, writes:false, advisory_only:true, human_gated:true,
               never_overrides_operator:true, is_a_decision:false, auto_acts:false,
               reuses_p1_predictive_simulator:true },
intent_advisory_hash
```

Comando: `atlas:documentation-reality-intent-advisory {--kind=doc} {--slug=}
{--graph-id=} {--capability=*} {--symbol=} {--objective=} {--json}`
(auto-descoberto, nao muta nada, **sempre sai 0**).

## Composicao — Reuso do P1 Simulate

O **sinal tecnico** e o P1 `AtlasSoftwareTwinRuntimeService::simulate` reusado
como esta — a mesma previsao de duplicacao / drift / owner / blast-radius. O O2
**nao re-implementa predicao**: le o veredito e os fatos estruturais que o P1 ja
computou e adiciona so uma fina **camada de intencao** por cima (consideracoes +
perguntas de alavanca). O P1 preve; ele nunca autoriza a escrita — e este wrapper
tambem nao.

## Fluxo

1. Receber o spec proposto + objetivo opcional.
2. **(a)** Rodar o P1 `simulate(proposed)` e projetar o `technical_signal`.
3. **(b)** Derivar `considerations[]` **so** dos sinais + fatos estruturais (cada
   uma e uma pergunta/sugestao, nunca uma instrucao).
4. **(c)** Montar `leverage_questions[]` pro operador responder (alavanca).
5. **(d)** Recomendar de forma conservadora (`reconsider`/`needs_judgment`/`proceed`).
6. Anexar o **bloco de soberania** obrigatorio + `claim_policy` espelhado, hash,
   `writes:false`. Devolver.

## Regras para IA

- O2 e **advisory + human-gated**: a IA opina, o **operador decide**. Nunca trate
  a recomendacao como um gate.
- NUNCA produza um campo de decisao vinculante (allow/block/deny/gate).
- NUNCA sobrescreva o operador, auto-aja ou autorize mutacao.
- REUSE o P1 `simulate` para o sinal tecnico; nao re-implemente predicao.
- NAO abra O3 (multi-estate) a partir deste doc — e incremento proprio.

## Escopo de Implementacao

Runtime read-only, este incremento. Entrega so a **advisory** (service + comando +
teste). A decisao do operador, o build (write-bound), o outcome-grounding (O1, ja
existe) e o compounding multi-estate (O3) sao deliberadamente fora de escopo.

## Dependencias

- `atlas-documentation-reality-outcome-grounded-leap` (L2, doc pai north-star).
- `atlas-documentation-reality-outcome-grounded-truth` (L2-O1, increment irmao).
- `AtlasSoftwareTwinRuntimeService::simulate` (P1, sinal tecnico reusado).

## Evidencias

- symbol: `AtlasDocumentationRealityIntentAdvisoryService`
- command: `atlas:documentation-reality-intent-advisory`
- test: `AtlasDocumentationRealityIntentAdvisoryTest`

Drift do doc filho deve ser `false` em
`atlas:aeos:maturity --capability=atlas-documentation-reality-intent-coformation --json`.

## Riscos

- **Co-formacao que invade a soberania (O risco central):** a IA decidir o alvo
  por conta, ou a advisory ser lida como um gate/decisao. **Mitigacao:** O2 e
  **estritamente advisory + human-gated** e **NUNCA sobrescreve a soberania do
  operador**; **NAO e um gate nem uma decisao**. Todo envelope carrega o bloco de
  soberania (`is_a_decision:false`, `never_overrides_operator:true`,
  `auto_acts:false`); nao existe campo allow/block/deny; o comando sempre sai 0.
- **Falsa certeza:** a IA afirmar "a coisa certa" sem o objetivo. **Mitigacao:**
  objetivo vazio => `needs_operator_judgment`; sem a meta, a advisory nao finge
  saber o alvo certo.
- **Re-implementar predicao:** duplicar a logica do P1. **Mitigacao:** o sinal
  tecnico **reusa** o P1 `simulate`; O2 so adiciona a camada de intencao.
- **Escopo inflado:** abrir O3 (multi-estate compounding) aqui. **Mitigacao:**
  este doc e so a advisory O2; **O3 e incremento posterior**, com doc
  filho/promocao propria, e nao deve ser aberto a partir daqui.

## Exemplos

```text
P1 diz: "esse graph_id ja existe — would_duplicate." (sinal tecnico)
O2 opina: reconsider_advisory — "isso duplicaria uma capability existente;
   da pra reusar/estender o que ja existe?" + perguntas de alavanca.
   (ADVISORY: o operador ainda pode prosseguir deliberadamente.)

Proposta nova, sem objetivo:
O2 opina: needs_operator_judgment — "sem o objetivo nao da pra avaliar alavanca;
   isto precisa do seu julgamento." (nunca finge saber o alvo)

Proposta nova, com objetivo:
O2 opina: proceed_advisory — "nenhuma razao estrutural pra pausar; isso confirma
   que da pra construir, NAO que e o passo de maior alavanca — esse julgamento e
   seu." (ainda advisory; nunca uma autorizacao)
```

Em todos os casos o envelope carrega o bloco de soberania e o operador decide. O
O2 nunca bloqueia, nunca escreve, nunca age.

## Proximas Acoes

- Refinar consideracoes e perguntas de alavanca conforme uso real, sem nunca
  virar decisao.
- So depois de O2 provado, abrir O3 (multi-estate compounding) como incremento
  proprio.
