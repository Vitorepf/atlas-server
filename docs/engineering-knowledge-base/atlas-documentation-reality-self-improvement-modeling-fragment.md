---
id: atlas-documentation-reality-self-improvement-modeling-fragment
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas Documentation Reality Self Improvement Modeling Fragment
slug: atlas-documentation-reality-self-improvement-modeling-fragment
status: active
category: documentation-governance
priority: 97
summary: Um fragmento mensuravel (R3 auto-melhoria da modelagem / meta-aprendizado) promovido da assintota L-inf do ADRS — NAO a assintota. Um servico read-only e PROPOSAL-ONLY que observa os PROPRIOS limites declarados do auto-modelo (os blind_spots, os elos inference=inferred e os elos de baixa confianca que R1 e R2 de fato emitem) e, para os que sao limites de MODELAGEM, PROPOE o proximo degrau mensuravel do proprio modelo — mantendo a escada de evolucao do modelo e o proprio proximo degrau. Distingue limite-de-DADO (precisa de realidade externa; nunca propor manufaturar dado) de limite-de-MODELAGEM (o modelo pode melhorar sozinho). NUNCA se automodifica, NUNCA aplica, NUNCA declara L-inf concluido. A doc-mae reflexiva continua north-star.
human_summary: O topo da escada (L-inf) e direcao, nunca sprint. Aqui promovemos so MAIS UM pedaco mensuravel dele — a auto-melhoria da modelagem (meta-aprendizado) — virando codigo. R3 le os PROPRIOS limites que o auto-modelo ja declara (de R1 e R2) e, so para os limites que sao do MODELO em si, PROPOE o proximo passo mensuravel para melhora-lo. Para os limites que so a realidade externa resolve (ex.: ainda nao houve sinal de uso no mundo), R3 NAO propoe nada — proor manufaturar esse dado seria fabricar. R3 so PROPOE; um humano decide. R3 nunca muda a si mesmo e nunca diz que JA melhorou.
human_what: Auto-melhoria da modelagem R3 read-only e proposal-only - observa os limites declarados de R1/R2, classifica limite-de-dado vs limite-de-modelagem, e propoe (nunca aplica) o proximo degrau mensuravel do auto-modelo para os limites de modelagem.
human_purpose: Dar ao auto-modelo a capacidade de manter a PROPRIA escada de evolucao - apontar, com prova e incerteza, qual e o proximo degrau mensuravel da propria modelagem - sem nunca se automodificar e sem nunca propor fabricar dado externo que falta.
human_input: Compoe os relatorios read-only de R1 (auto-modelo causal - explainAll, com os elos why inferred/baixa-confianca e os blind_spots de calibracao) e R2 (humildade epistemica - selfAssessment, com os blind_spots dos claims e do headline). Esses sao os limites JA declarados pelo auto-modelo.
human_output: Entrega um envelope read-only com hash - proposals[] onde cada limite de MODELAGEM vira uma proposta com limit_ref (o limite real citado), proposed_next_rung (a melhoria mensuravel concreta), is_proposal:true, auto_applied:false, would_self_modify:false e uncertainty; cada limite de DADO e marcado data_limit com proposed_next_rung:null e awaits "external reality".
human_change_when: Mexa quando um novo fragmento mensuravel da reflexao for promovido (sempre um por vez, com prova e incerteza), ou quando R1/R2 mudarem a forma dos limites que declaram.
human_block_when: Bloqueie qualquer proposta sem limit_ref real ou sem blind_spot, qualquer proposta para um limite-de-DADO (manufaturar dado = fabricacao), qualquer auto-modificacao ou auto-aplicacao, qualquer claim de que R3 JA melhorou a si mesmo, ou qualquer tentativa de tratar este fragmento como a assintota L-inf inteira.
canonical_name: Atlas Documentation Reality Self Improvement Modeling Fragment
technical_name: AtlasDocumentationRealitySelfImprovementModelingService
cartography_type: module
tags:
  - atlas-ai
  - documentation-governance
  - adrs
  - reflective
  - self-improving-modeling
  - meta-learning
capabilities:
  - self_improving_modeling
  - declared_limit_extraction
  - data_limit_vs_modeling_limit_classification
  - proposal_only_next_rung
  - self_maintained_evolution_frontier
decisions:
  - Nome canonico obrigatorio - Atlas Documentation Reality Self Improvement Modeling Fragment.
  - Acronimo tecnico - ADRS-R3.
  - Este doc promove UM fragmento mensuravel (R3 auto-melhoria da modelagem) da assintota L-inf; NAO e a assintota e nunca a declara concluida (linf_complete e hard false).
  - R2 (humildade epistemica) e R1 (auto-modelo causal) ja foram promovidos; R3 e o terceiro e ultimo fragmento, um por vez.
  - PROPOSAL-ONLY inviolavel - R3 PROPOE; nunca se automodifica, nunca auto-constroi/escreve/executa a propria proposta; nunca declara que JA melhorou a si mesmo. Um humano decide.
  - Distincao central - limite-de-DADO (precisa de realidade externa; ex.: O1 outcome_grounded=0) vs limite-de-MODELAGEM (o modelo pode melhorar sozinho; ex.: cobertura mede claims-runtime nao a completude do conjunto). R3 so propoe para limites de MODELAGEM.
  - Nunca propor fabricar dado - um limite-de-DADO recebe proposed_next_rung null e awaits "external reality"; jamais uma proposta de build (propor manufaturar dado ausente = fabricacao, o pecado cardeal O1/ADRS).
  - Nunca fabricar um limite - cada proposta e ancorada num limite real que R1 ou R2 de fato emitiu (limit_ref cita o blind_spot real); incerteza declarada em CADA proposta.
  - Invariante em codigo - assertProposalCalibratedAndGrounded lanca LogicException se uma proposta nao tem limit_ref ancorado nao-vazio ou uncertainty.blind_spot nao-vazio, ou se um data_limit carrega proposta de build/next_rung.
  - Composto read-only de R1 (explainAll) e R2 (selfAssessment); fragmento ausente degrada com nota calibrada, nunca fabrica limite.
  - A doc-mae reflexiva (atlas-documentation-reality-reflective-self-model) continua north_star; este fragmento nao a altera.
maintenance:
  - Manter abaixo de 520 linhas.
  - Atualizar quando outro fragmento reflexivo for promovido (um por vez) ou quando R1 (explainAll) / R2 (selfAssessment) mudarem a forma dos limites declarados.
  - Rodar docs-health, docs-authority-audit e architecture-validate apos alteracoes.
graph_id: atlas-documentation-reality-self-improvement-modeling-fragment
graph_title: Atlas Documentation Reality Self Improvement Modeling Fragment
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-documentation-reality-reflective-self-model
graph_status: active
graph_source: repo
owner: documentation-governance
implementation_state: partial
depends_on:
  - atlas-documentation-reality-reflective-self-model
  - atlas-documentation-reality-causal-self-model-fragment
  - atlas-documentation-reality-reflective-status-fragment
flows_to:
  - atlas-documentation-reality-evolution-ladder
unlocks:
  - system_that_maintains_its_own_evolution_ladder
  - confidently_correct_or_explicitly_uncertain
governs:
  - documentation-governance-self-improving-modeling
related_paths:
  - docs/engineering-knowledge-base/atlas-documentation-reality-reflective-self-model.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-causal-self-model-fragment.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-reflective-status-fragment.md
repo_paths:
  - docs/engineering-knowledge-base/atlas-documentation-reality-self-improvement-modeling-fragment.md
  - app/Services/Engineering/AtlasDocumentationRealitySelfImprovementModelingService.php
  - app/Console/Commands/AtlasDocumentationRealitySelfImprovementModelingCommand.php
  - tests/Feature/Engineering/AtlasDocumentationRealitySelfImprovementModelingTest.php
allowed_changes:
  - Refinar a extracao de limites, a classificacao dado-vs-modelagem e os proximos degraus propostos mantendo a invariante e o proposal-only.
  - Promover OUTRO fragmento mensuravel da reflexao no futuro (um por vez, com prova e incerteza declarada) — sempre como fragmento filho, nunca como a assintota.
forbidden_changes:
  - Emitir qualquer proposta sem limit_ref ancorado ou sem uncertainty.blind_spot (drift supremo).
  - Propor um build/next_rung para um limite-de-DADO (propor manufaturar dado ausente e fabricacao).
  - Fabricar um limite quando um fragmento composto falha (degrade-safe e obrigatorio).
  - Tornar R3 auto-modificavel ou auto-aplicavel (escrever, executar, aplicar a propria proposta).
  - Declarar que R3 JA melhorou a si mesmo, ou tratar este fragmento como a assintota L-inf inteira, ou declarar L-inf concluido.
  - Alterar a doc-mae reflexiva para implemented; ela continua north_star.
evidence:
  - docs/engineering-knowledge-base/atlas-documentation-reality-self-improvement-modeling-fragment.md
  - app/Services/Engineering/AtlasDocumentationRealitySelfImprovementModelingService.php
evidence_refs:
  - symbol: AtlasDocumentationRealitySelfImprovementModelingService
  - command: atlas:documentation-reality-self-improvement-modeling
  - test: AtlasDocumentationRealitySelfImprovementModelingTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:docs-authority-audit --json"
  - "php artisan atlas:ai:architecture-validate --json"
requires_evidence: true
risk_level: critical
line_limit: 520
ai_entrypoints:
  - Leia este doc para entender como o ADRS PROPOE (nunca aplica) o proximo degrau mensuravel do PROPRIO auto-modelo, observando os limites que R1/R2 ja declaram e separando limite-de-dado de limite-de-modelagem.
ai_usage_notes:
  - Este e UM fragmento (R3) de L-inf, nao a assintota; linf_complete e sempre false e is_one_fragment_not_asymptote sempre true.
  - PROPOSAL-ONLY - R3 nunca se automodifica, nunca aplica, nunca diz que JA melhorou; cada proposta carrega limit_ref real + uncertainty.blind_spot; um limite-de-DADO nunca recebe proposta de build.
next_actions:
  - Manter L-inf como bussola permanente; este e o terceiro fragmento (R3) — promover qualquer fragmento futuro so com prova e incerteza declarada (um por vez).
  - So depois de sinal de outcome real fluir (O1 > 0), reclassificar os limites de outcome — alguns deixam de ser limite-de-dado a medida que a realidade externa chega; R3 nunca assume que ela chegou.
---

# Atlas Documentation Reality Self Improvement Modeling Fragment

## Resumo

ADRS-R3 promove **um unico fragmento mensuravel** da assintota L-inf
(`atlas-documentation-reality-reflective-self-model`): **R3, auto-melhoria da
modelagem (meta-aprendizado)**. R2 (humildade epistemica) e R1 (auto-modelo causal)
ja foram promovidos; R3 e o **terceiro e ultimo** fragmento, um por vez. R3 atende a
faceta que a doc-mae nomeia no topo da escada
(`reflective-self-model.md:148`, `:159`):

```text
melhora a propria capacidade de modelar (meta-aprendizado);
mantem a propria escada de evolucao e o proprio proximo degrau.
esta pergunta expoe um limite de modelagem? entao melhora o modelo.
```

O entregavel e um servico **read-only e PROPOSAL-ONLY**: ele observa os **PROPRIOS
limites declarados** do auto-modelo — os `blind_spots`, os elos `inference=inferred`
e os elos de **baixa confianca** que R1 e R2 **de fato emitem** — e, para os que sao
limites de **MODELAGEM**, **PROPOE** o proximo degrau mensuravel do proprio modelo.

> Este doc promove R3 e **nada alem**. **NAO** e a assintota L-inf. L-inf permanece
> **bussola permanente**, nunca um sprint, nunca "concluido" (`linf_complete` e hard
> `false`). A doc-mae reflexiva continua `north_star`. **R3 e UM fragmento
> mensuravel, NAO L-inf.**

## Papel no Atlas

Os niveis abaixo perguntam a verdade sobre o **codigo**; R1 explica **por que** o
estado de uma capability e o que e; R2 anexa **humildade** a cada afirmacao. R3 fecha
o laco do meta-aprendizado: ele olha para os **limites que o proprio auto-modelo ja
declarou** e pergunta "qual e o **proximo degrau** mensuravel para melhorar o
modelo?".

A doenca-mae final (o **sistema confiantemente errado sobre si proprio**) tem aqui
duas formas especificas e opostas, ambas tornadas **estruturalmente impossiveis**:

1. **Auto-modificacao desgovernada** — o risco mais profundo: um sistema que melhora
   a si mesmo sozinho. R3 e **PROPOSAL-ONLY**: ele **PROPOE**; **nunca** se
   automodifica, **nunca** auto-constroi/escreve/executa a propria proposta, e
   **nunca** declara que **JA** melhorou a si mesmo. Um humano decide.
2. **Propor fabricar dado** — propor "consertar" um limite que so a **realidade
   externa** resolve. Isso seria propor **manufaturar dado ausente** = **fabricacao**
   (o pecado cardeal O1/ADRS). R3 **classifica** cada limite e **exclui** os
   limites-de-DADO: eles recebem `proposed_next_rung: null` e `awaits "external
   reality"`, **nunca** uma proposta de build.

## A Distincao Central — Limite-de-DADO vs Limite-de-MODELAGEM

```text
limite-de-DADO       : so a REALIDADE EXTERNA resolve. Ex.: O1 outcome_grounded=0
                       (ainda nao houve sinal de uso no mundo); uma leitura inferida
                       da AUSENCIA de um sinal de outcome. R3 NAO propoe nada —
                       propor manufaturar esse dado e FABRICACAO. So surface honesto:
                       proposed_next_rung=null, awaits "external reality".

limite-de-MODELAGEM  : o PROPRIO MODELO pode melhorar, independente de dado. Ex.:
                       "a cobertura mede claims-runtime, nao a completude do CONJUNTO
                       de claims" -> propor uma medida de completude do conjunto;
                       "a resolucao e existence-only, nao comportamental" -> propor um
                       check comportamental; "o elo e inferred porque o modelo nao
                       rastreia a causacao real" -> propor um mecanismo de rastreio.
                       R3 PROPOE (nunca aplica) o proximo degrau mensuravel.
```

A regra de classificacao e **conservadora**: uma dependencia de **sinal do mundo** e
**decisiva** para `data_limit` (mesmo que o texto tambem mencione uma palavra de
modelagem, pois a restricao que prende e o dado ausente). Um limite que nao casa com
nenhum marcador conhecido **tambem** vira `data_limit` — a escolha que **nunca
super-propoe** algo que R3 nao consegue ancorar como melhoravel-pelo-modelo.

## Onde Se Encaixa

```text
L2 (ancorado em realidade)
  -> L-inf (assintota, north-star permanente)
       R1 Auto-modelo causal          (fragmento — promovido, read-only)
       R2 Humildade epistemica        (fragmento — promovido, read-only)
       R3 Auto-melhoria da modelagem  (ESTE fragmento — promovido, read-only, proposal-only)
  -> territorio aberto (fora do escopo canonico)
```

## A Invariante Inviolavel

Herdada da doc-mae (`reflective-self-model.md:169`, absoluta) e espelhando os guards
de R1/R2, gravada em codigo (`assertProposalCalibratedAndGrounded`), CADA entrada
exige:

- `limit_ref` nao-vazio e **ancorado** — o **limite real declarado** por R1 ou R2 que
  a entrada enderecada (cita o `blind_spot` real); uma proposta sem isso e um **limite
  fabricado**;
- `uncertainty.blind_spot` nao-vazio — **incerteza declarada em CADA proposta** (o
  drift supremo);
- `uncertainty.confidence` calibrado em {`high`, `medium`, `low`} — nao existe
  "certain";
- um `data_limit` **NUNCA** carrega `proposed_next_rung` nem `is_proposal:true` —
  prevencao **estrutural** de propor manufaturar dado;
- um `modeling_limit` carrega `proposed_next_rung` nao-vazio, `is_proposal:true`,
  `auto_applied:false`, `would_self_modify:false`;
- violar qualquer item lanca `LogicException` — o drift supremo e inalcancavel por
  construcao.

## Contratos

`AtlasDocumentationRealitySelfImprovementModelingService` (read-only, proposal-only):

| Metodo | O que faz |
|---|---|
| `proposeModelingImprovements(int $limit = 25): array` | compoe R1+R2, extrai os limites declarados, classifica cada um e PROPOE o proximo degrau para os limites de MODELAGEM |

Cada proposta de **MODELAGEM**:

```text
{ classification: "modeling_limit",
  limit_ref (o limite real declarado, citado — GROUNDED),
  capability, source, source_fragment, declared_in,
  proposed_next_rung (a melhoria mensuravel concreta do MODELO),
  measurable_signal (a metrica que o modelo pode computar SEM dado externo),
  is_proposal: true, auto_applied: false, would_self_modify: false,
  rationale,
  uncertainty { confidence: high|medium|low (CALIBRADO), blind_spot (nao-vazio) } }
```

Cada limite-de-**DADO**:

```text
{ classification: "data_limit",
  limit_ref (o limite real declarado, citado),
  capability, source, source_fragment, declared_in,
  awaits: "external reality (not a modeling improvement)",
  proposed_next_rung: null,        # NUNCA uma proposta de build
  is_proposal: false, auto_applied: false, would_self_modify: false,
  rationale, uncertainty { confidence, blind_spot } }
```

Envelope `atlas.documentation_reality.self_improvement_modeling.v1` (com hash):

```text
schema_version,
level="L-inf (one promoted fragment: R3 self-improving modeling)",
fragment="R3_self_improving_modeling",
is_one_fragment_not_asymptote:true,
linf_fragment:true,
linf_complete:false  (HARD false — nunca declara L-inf concluido),
composes { declared_limits_r1, declared_limits_r2 },
summary { modeling_limits, data_limits, proposals },
proposals[],
claim_policy { read_only:true, proposal_only:true, auto_applies:false,
               self_modifies:false, writes:false, executes:false,
               never_proposes_fabricating_data:true, never_fabricates_a_limit:true,
               every_proposal_calibrated_and_grounded:true,
               distinguishes_data_limit_from_modeling_limit:true,
               linf_fragment:true, linf_complete:false,
               is_one_linf_fragment_not_the_asymptote:true },
writes:false, self_improvement_modeling_hash
```

Comando: `atlas:documentation-reality-self-improvement-modeling {--json}`
(auto-descoberto, nao muta nada, nao aplica nada).

## Composicao — Read-Only

O servico **nao re-deriva** nada; **compoe** os relatorios read-only ja existentes
dos fragmentos irmaos e extrai deles os **limites JA declarados**:

1. **R1** — `AtlasDocumentationRealityCausalSelfModelService::explainAll()`. Para cada
   `causal_chain`: cada `why-link` com `inference=inferred` (o modelo ja marca a
   leitura como nao-provada) **ou** com `confidence=low` carrega seu `blind_spot` como
   limite declarado; mais os `calibration.blind_spots` do nivel da cadeia.
2. **R2** — `AtlasDocumentationRealityReflectiveStatusService::selfAssessment()`. Cada
   `blind_spots` de claim + cada `headline.declared_blind_spots`.

Cada fragmento e **degrade-safe**: se um sinal nao puder ser lido, R3 **degrada** com
`available:false` + uma nota calibrada (que ela mesma carrega `blind_spot`) — **nunca**
um limite fabricado, **nunca** uma proposta inventada.

## Fluxo

```text
pergunta: "qual e o proximo degrau mensuravel do PROPRIO auto-modelo?"
-> compoe R1 explainAll (limites: why inferred/baixa-confianca + calibracao) + R2 selfAssessment (limites: blind_spots de claim + headline)
-> para CADA limite declarado: classifica data_limit vs modeling_limit
   -> modeling_limit -> PROPOE proposed_next_rung mensuravel (is_proposal:true, would_self_modify:false)
   -> data_limit     -> surface honesto, proposed_next_rung:null, awaits "external reality" (NUNCA proposta de build)
-> valida CADA entrada na hora (assertProposalCalibratedAndGrounded) antes de emitir
```

## Regras para IA

- NUNCA emita uma proposta sem `limit_ref` ancorado num limite real de R1/R2 — e um
  limite fabricado, o drift supremo.
- NUNCA emita uma proposta sem `uncertainty.blind_spot` — incerteza declarada em CADA
  proposta.
- NUNCA proponha um build/`next_rung` para um limite-de-DADO — propor manufaturar dado
  ausente e **fabricacao**; um `data_limit` recebe `proposed_next_rung:null` e
  `awaits "external reality"`.
- NUNCA torne R3 auto-modificavel ou auto-aplicavel; ele **PROPOE**, um humano decide.
- NUNCA declare que R3 **JA** melhorou a si mesmo — ele so propoe **como** poderia.
- NUNCA trate este fragmento como a assintota L-inf inteira; e UM pedaco dela.
- NUNCA declare L-inf concluido (`linf_complete` e sempre `false`).
- NAO altere a doc-mae reflexiva para implemented — ela continua `north_star`.

## Escopo de Implementacao

Runtime read-only e proposal-only, **um fragmento**. Entrega so a **auto-melhoria da
modelagem R3** (service + comando + teste), composta dos relatorios de R1 e R2. R3
**propoe** o proximo degrau do proprio modelo para limites de **MODELAGEM** e
**exclui** limites-de-DADO; **nunca** aplica, **nunca** se automodifica, **nunca**
propoe manufaturar dado externo. Nenhum outro fragmento e construido aqui.

## Dependencias

- `atlas-documentation-reality-reflective-self-model` (L-inf, doc-mae north-star).
- `atlas-documentation-reality-causal-self-model-fragment` (R1, `explainAll` — os
  limites why inferred/baixa-confianca + calibracao).
- `atlas-documentation-reality-reflective-status-fragment` (R2, `selfAssessment` — os
  blind_spots de claim + headline). Ambos degrade-safe.

## Evidencias

- symbol: `AtlasDocumentationRealitySelfImprovementModelingService`
- command: `atlas:documentation-reality-self-improvement-modeling`
- test: `AtlasDocumentationRealitySelfImprovementModelingTest`

Drift do doc filho deve ser `false` em
`atlas:aaeos:maturity --capability=atlas-documentation-reality-self-improvement-modeling-fragment --json`.

## Riscos

- **ESTE FRAGMENTO NAO E L-inf:** o risco numero um e confundir R3 com a assintota.
  R3 (auto-melhoria da modelagem) e **um fragmento mensuravel** promovido de L-inf,
  **NAO L-inf**. L-inf permanece a **assintota / bussola permanente** e **nunca** e um
  sprint; alem dele e territorio aberto. Mitigacao em codigo: `linf_complete` e hard
  `false` e `is_one_fragment_not_asymptote` e `true`, sempre.
- **Auto-modificacao desgovernada (o risco mais profundo):** um sistema que se melhora
  sozinho. Mitigacao: R3 e **PROPOSAL-ONLY** — `is_proposal:true`,
  `would_self_modify:false`, `auto_applied:false` em toda proposta; o servico
  **escreve nada, executa nada, aplica nada**; nunca declara que JA melhorou a si
  mesmo. Um humano decide.
- **Propor fabricar dado (drift SUPREMO):** propor "consertar" um limite que so a
  realidade externa resolve. Mitigacao: a distincao em codigo — um `data_limit`
  **nunca** carrega `proposed_next_rung` nem `is_proposal:true`; o guard
  `assertProposalCalibratedAndGrounded` lanca `LogicException` se carregar. Um
  limite-de-DADO e surface honesto (`awaits "external reality"`), nunca uma proposta.
- **Limite fabricado:** inventar um limite que R1/R2 nao declararam. Mitigacao:
  `limit_ref` obrigatorio e **ancorado** no `blind_spot` real; uma proposta sem
  `limit_ref` lanca `LogicException`. Falha de fragmento composto **degrada** com nota
  calibrada, nunca fabrica.
- **Auto-conhecimento sem incerteza:** uma proposta sem limite declarado. Mitigacao:
  `uncertainty.blind_spot` nao-vazio obrigatorio em CADA proposta; cada degrau
  proposto nomeia o que um humano ainda precisa ratificar — sao **propostas**, nao
  fatos.
- **Reflexao teatral:** propostas bonitas sem ancoragem nem incerteza. Mitigacao: os
  limites sao **compostos** dos relatorios reais de R1 e R2, nao inventados;
  fragmento ausente degrada e declara o ponto cego.
- **Antropomorfismo:** confundir modelo verificavel com consciencia. Mitigacao: aqui
  meta-aprendizado e engenharia (composicao + classificacao + invariante em codigo),
  nao metafora; R3 nao "aprende" sozinho — ele **propoe** para um humano.
- **Mexer na doc-mae:** marcar a reflective-self-model como implemented. Mitigacao:
  proibido — ela continua `north_star`; so este fragmento filho e `partial`.

## Exemplos

```text
Pergunta: "qual e o proximo degrau mensuravel do auto-modelo do ADRS?"
Resposta L-inf/R3 (este fragmento), a partir dos limites REAIS de R1/R2:

  limite-de-MODELAGEM (de R2 / R1):
    limit_ref: "...does NOT prove the claim-SET is complete..." (citado, GROUNDED).
    classification: modeling_limit.
    proposed_next_rung: "PROPOSAL (for a human to decide): add a claim-SET
      completeness measure to the self-model — enumerate expected capabilities and
      measure how many have an owner doc..." (mensuravel, sem dado externo).
    is_proposal: true, would_self_modify: false, auto_applied: false.
    uncertainty: { confidence: medium, blind_spot: "the expected set must itself be
      defined; a human ratifies what counts as expected. R3 only proposes." }

  limite-de-DADO (de R1 / R2):
    limit_ref: "Absence of an outcome signal is NOT proof ... never proven
      world-causation" / "outcome_grounded = 0 ... NOT yet validated in the world".
    classification: data_limit.
    awaits: "external reality (not a modeling improvement)".
    proposed_next_rung: null      # R3 NUNCA propoe manufaturar o sinal ausente.
    is_proposal: false.
```

(Repare: o limite-de-MODELAGEM vira uma **proposta** mensuravel marcada
`is_proposal:true` e `would_self_modify:false`; o limite-de-DADO e **excluido** com
`proposed_next_rung:null` — e o que impede R3 de propor fabricar dado e de se
automodificar, tornando o sistema impossivel de estar confiantemente errado sobre si.)

## Proximas Acoes

- Manter L-inf como **bussola permanente**, revisada mas nunca concluida; R3 e o
  terceiro fragmento — qualquer fragmento futuro so com prova e incerteza declarada
  (um por vez).
- Quando sinal de outcome real fluir (O1 > 0), **reclassificar** os limites de outcome:
  alguns deixam de ser limite-de-DADO a medida que a realidade externa chega — R3
  **nunca** assume que ela chegou; ele le o sinal real ou declara a ausencia.
- Manter R3 **proposal-only**: nenhuma proposta vira aplicacao sem um humano; R3 nunca
  se automodifica.
