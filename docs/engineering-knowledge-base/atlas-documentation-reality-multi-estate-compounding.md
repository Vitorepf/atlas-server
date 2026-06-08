---
id: atlas-documentation-reality-multi-estate-compounding
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas Documentation Reality Multi Estate Compounding
slug: atlas-documentation-reality-multi-estate-compounding
status: active
category: documentation-governance
priority: 95
summary: L2-O3 do ADRS, primeiro incremento e a ULTIMA capability do L2. O proposer de propagacao de imunidade cruzando estates. Quando o P3 sintetiza um anticorpo (um padrao detector/gate) de um rot que escapou em UM projeto, o O3 propoe imunizar os OUTROS estates do operador com o mesmo anticorpo, para que um rot pego uma vez proteja todos. ABSOLUTO, soberania local-first - classes sensitive/secret/cyber NUNCA cruzam fronteira de estate; so o padrao abstrato cruza. Read-only proposer, nunca auto-propaga, nunca transmite, nunca instala.
human_summary: O P3 aprende uma defesa nova a partir de uma falha que escapou em um projeto. O O3 faz esse aprendizado COMPOR - propoe ligar a mesma defesa nos seus outros projetos, pra que um problema pego uma vez nunca mais pegue em lugar nenhum. Mas so o PADRAO abstrato da defesa atravessa; nada sensivel sai da maquina, e nada acontece sem voce aprovar.
human_what: Proposer L2-O3 que, a partir de um anticorpo do P3, propoe imunizar os outros estates/projetos do operador com o mesmo padrao detector - so o padrao abstrato cruza, sensivel fica local, e um humano aprova cada movimento.
human_purpose: Fazer um aprendizado de imunidade COMPOR entre todo o patrimonio do operador sem nunca vazar dado sensivel entre projetos; um rot pego uma vez protege todos.
human_input: Recebe um anticorpo (a forma do P3, ou {failure_kind, reproducing_test_outline, proposed_detector}), uma lista de estates/dominios alvo e a classe de dado de origem do anticorpo.
human_output: Entrega um envelope read-only com o que cruza (so o padrao abstrato, ou nada), o que fica local (so rotulos, nunca o conteudo), os estates alvo, um motivo de bloqueio quando aplicavel e um bloco de soberania obrigatorio.
human_change_when: Mexa quando a forma do anticorpo do P3 mudar, quando o registro de estates/dominios mudar, ou quando o vocabulario de soberania do Constitutional Kernel mudar.
human_block_when: Bloqueie se alguem tentar fazer classes sensitive/secret/cyber cruzarem, vazar conteudo bruto/path/secret/evidence, auto-propagar, transmitir entre maquinas, ou instalar o anticorpo sem aprovacao humana.
canonical_name: Atlas Documentation Reality Multi Estate Compounding
technical_name: AtlasDocumentationRealityMultiEstateCompoundingService
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-documentation-reality-multi-estate-compounding.md
tags:
  - atlas-ai
  - documentation-governance
  - adrs
  - multi-estate
  - local-first-sovereignty
capabilities:
  - cross_estate_immunity_propagation
  - abstract_pattern_only_crossing
  - sovereignty_local_first_gate
  - p3_antibody_compounding
decisions:
  - Nome canonico obrigatorio - Atlas Documentation Reality Multi Estate Compounding.
  - Acronimo tecnico - ADRS-O3.
  - O3 e um proposer read-only - nunca auto-propaga, nunca transmite entre maquinas, nunca instala o anticorpo; um humano aprova cada movimento cruzando estate.
  - ABSOLUTO - classes sensitive/secret/cyber NUNCA cruzam fronteira de estate; o anticorpo fica local e blocked_reason e sovereignty_class_must_not_leave_machine.
  - So o padrao ABSTRATO e domain-agnostic cruza (detector kind/description + a FORMA do teste que reproduz) - nunca conteudo bruto de falha, paths, secrets ou evidencia.
  - A lista de classes bloqueadas REUSA o Constitutional Kernel (SENSITIVE_CLASSES sensitive/secret/cyber); O3 nao inventa vocabulario de soberania.
  - O bloco de soberania e obrigatorio em todo envelope - sensitive_secret_cyber_never_cross true, only_abstract_pattern_crosses true, read_only true, auto_propagates false, human_gated true.
  - Este incremento completa o trio de capabilities do L2 - O1 + O2 + O3 (primeiros incrementos).
maintenance:
  - Manter abaixo de 520 linhas.
  - Atualizar quando a forma do anticorpo do P3, o registro de estates ou o vocabulario de soberania do Kernel mudarem.
  - Rodar docs-health, docs-authority-audit e architecture-validate apos alteracoes.
graph_id: atlas-documentation-reality-multi-estate-compounding
human_name: Atlas Documentation Reality Multi Estate Compounding
graph_title: Atlas Documentation Reality Multi Estate Compounding
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
  - atlas-documentation-reality-self-immunizing-antibody
  - atlas-constitutional-kernel
flows_to:
  - atlas-documentation-reality-reflective-self-model
unlocks:
  - cross_estate_immune_compounding
  - sovereignty_preserving_pattern_propagation
governs:
  - documentation-governance-multi-estate-compounding
related_paths:
  - docs/engineering-knowledge-base/atlas-documentation-reality-outcome-grounded-leap.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-self-immunizing-antibody.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-intent-coformation.md
  - docs/engineering-knowledge-base/atlas-constitutional-kernel.md
repo_paths:
  - docs/engineering-knowledge-base/atlas-documentation-reality-multi-estate-compounding.md
  - app/Services/Engineering/AtlasDocumentationRealityMultiEstateCompoundingService.php
  - app/Console/Commands/AtlasDocumentationRealityMultiEstateCompoundingCommand.php
  - tests/Feature/Engineering/AtlasDocumentationRealityMultiEstateCompoundingTest.php
allowed_changes:
  - Refinar o padrao abstrato, os rotulos do que fica local e o envelope mantendo a regra de soberania.
  - Ligar novos sinais de estate derivados do registro de dominios, sem nunca vazar dado sensivel.
forbidden_changes:
  - Permitir que classes sensitive/secret/cyber cruzem fronteira de estate.
  - Cruzar conteudo bruto de falha, paths, secrets ou evidencia em vez do padrao abstrato.
  - Auto-propagar, transmitir entre maquinas ou instalar o anticorpo sem aprovacao humana.
  - Inventar vocabulario de soberania em vez de reusar o Constitutional Kernel.
evidence:
  - docs/engineering-knowledge-base/atlas-documentation-reality-multi-estate-compounding.md
  - app/Services/Engineering/AtlasDocumentationRealityMultiEstateCompoundingService.php
evidence_refs:
  - symbol: AtlasDocumentationRealityMultiEstateCompoundingService
  - command: atlas:documentation-reality-multi-estate
  - test: AtlasDocumentationRealityMultiEstateCompoundingTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:docs-authority-audit --json"
  - "php artisan atlas:ai:architecture-validate --json"
requires_evidence: true
risk_level: critical
line_limit: 520
ai_entrypoints:
  - Leia este doc para entender como um anticorpo aprendido em um projeto se propaga (so o padrao abstrato) pros outros estates sem nunca vazar dado sensivel.
ai_usage_notes:
  - O3 e read-only proposer; a IA propoe, um humano aprova cada movimento cruzando estate. Nunca auto-propague nem transmita.
  - Classes sensitive/secret/cyber NUNCA cruzam; so o padrao abstrato cruza. A lista bloqueada vem do Constitutional Kernel.
next_actions:
  - Refinar o padrao abstrato e os rotulos conforme uso real, sem nunca vazar especifico sensivel.
  - Com O1 + O2 + O3 entregues, o trio de capabilities do L2 esta coberto em primeiro incremento; proxima fronteira e o L-infinito (auto-modelo reflexivo).
---

# Atlas Documentation Reality Multi Estate Compounding

## Resumo

ADRS-O3 e o **primeiro incremento** e a **ULTIMA capability** da co-formacao do L2
(`atlas-documentation-reality-outcome-grounded-leap`). O L0/L1 **verificam
execucao** ("o codigo bate com o doc?"). O O1 **gradua o outcome** ("funcionou no
mundo?"). O O2 pergunta **antes** do build ("isso e a coisa CERTA?"). O O3 faz um
aprendizado **COMPOR** entre todo o patrimonio do operador:

```text
Um rot escapou e foi pego em UM projeto. O P3 sintetizou o anticorpo
(o padrao do detector que o teria pego). E se esse mesmo anticorpo
imunizasse TODOS os outros estates do operador, pra que um rot pego
uma vez proteja todos?
```

A resposta e uma **proposta read-only**: o O3 propoe imunizar os outros
estates/projetos com o mesmo anticorpo — mas **so o padrao abstrato cruza**, o
sensivel fica local, e um **humano aprova cada movimento**.

## Papel no Atlas

Acertar a execucao (L1), o outcome (O1) e o alvo (O2) protege um projeto de cada
vez. O O3 fecha o ultimo buraco do L2: a **imunidade compoe** entre projetos. Um
anticorpo aprendido uma vez vira proposta de defesa pra todo o patrimonio — sem
nunca vazar dado sensivel entre projetos. Este incremento entrega so o **proposer**;
a instalacao real do gate em cada estate (write-bound) e o trabalho humano que vem
depois da aprovacao.

## Onde Se Encaixa

```text
rot escapou em UM projeto
  -> P3 sintetiza o anticorpo (detector + teste que reproduz) — atlas-documentation-reality-self-immunizing-antibody
  -> L2-O3 (este doc) — cross-estate immunity propagation proposer
       gate de soberania: classifica a classe de dado de origem
         sensitive|secret|cyber => NADA cruza, fica local (blocked)
         public|internal        => so o padrao ABSTRATO cruza
       propoe os estates alvo (do registro de dominios do operador)
  -> humano aprova cada movimento cruzando estate (SOBERANIA)
  -> instalacao do gate em cada estate (write-bound, fora deste doc)
```

Com O1 + O2 + O3 entregues em primeiro incremento, o **trio de capabilities do L2
esta coberto**; a proxima fronteira e o L-infinito (auto-modelo reflexivo).

## A Regra Cardinal — Soberania Local-First (ABSOLUTA)

A regra que define este incremento (herdada de
`atlas-documentation-reality-outcome-grounded-leap.md:81, :169, :192` — o doc nomeia
"Vazar verdade canonica sensivel entre projetos; classes sensitive/secret/cyber nao
saem da maquina"), **absoluta**:

```text
As classes de dado de soberania sensitive, secret, cyber NUNCA cruzam
uma fronteira de estate. So o PADRAO ABSTRATO e domain-agnostic (a logica
do detector — uma classe public/internal) pode ser proposto pra propagar;
qualquer especifico sensitive/secret/cyber FICA LOCAL.
```

Consequencias diretas, gravadas em codigo:

- A lista de classes bloqueadas **NAO e inventada aqui**: e a do **Constitutional
  Kernel** (`AtlasConstitutionalKernelService::SENSITIVE_CLASSES` =
  sensitive/secret/cyber), enforced pelo invariante petreo `sovereignty_local_first`
  ("sensitive/secret/cyber data never leaves the machine"). O O3 reusa essa fonte
  unica pra que a fronteira de soberania **nao possa driftar** do Kernel.
- Classe de origem sensitive/secret/cyber => `cross_estate_allowed=false`, o anticorpo
  **fica local**, `blocked_reason=sovereignty_class_must_not_leave_machine`,
  `target_estates=[]`, `what_crosses=null` (NADA cruza).
- Classe public/internal => so o **padrao abstrato** cruza: o detector kind/description
  + a **FORMA** do teste que reproduz, sem nenhum especifico. O payload que cruza
  carrega **so campos domain-agnostic** — nunca conteudo bruto de falha, paths,
  secrets ou trechos de evidencia.
- Todo envelope carrega o **bloco de soberania** obrigatorio
  (`sensitive_secret_cyber_never_cross:true, only_abstract_pattern_crosses:true,
  read_only:true, auto_propagates:false, human_gated:true`). O `claim_policy` espelha.
- O O3 e um **proposer**: nao auto-propaga, nao transmite entre maquinas, nao instala.
  Propor e o trabalho inteiro; um humano aprova cada movimento.

## Contratos

`AtlasDocumentationRealityMultiEstateCompoundingService` (read-only):

| Metodo | O que faz |
|---|---|
| `proposePropagation(array $antibody, array $estates = [], string $dataClass = 'internal'): array` | dado um anticorpo (forma do P3), estates alvo e a classe de dado de origem, devolve a proposta de propagacao |

A forma do `$antibody` e a do P3: `{failure_kind, reproducing_test_outline,
proposed_detector}` (a saida do `AtlasDocumentationRealityAntibodyProposerService`).

O gate de soberania (`source_data_class` => `cross_estate_allowed`):

| Classe de origem | Cruza? | Resultado |
|---|---|---|
| `sensitive` / `secret` / `cyber` | NAO | fica local; `blocked_reason=sovereignty_class_must_not_leave_machine`; `target_estates=[]`; `what_crosses=null` |
| `public` / `internal` | so o padrao abstrato | `what_crosses` = padrao abstrato; `target_estates` populado |
| qualquer outra (desconhecida) | NAO (fail-closed) | tratada conservadoramente como nao-cruzavel |

Envelope `atlas.documentation_reality.multi_estate.v1` (com hash):

```text
schema_version, mode=cross_estate_immunity_propagation_proposer, level=L2-O3,
increment, antibody_ref, source_data_class,
cross_estate_allowed:bool,
what_crosses { antibody_ref, detector_kind, failure_kind, pattern_description,
               plug_in_point, reproducing_test_shape { description, skeleton,
               must_fail_before_fix, carries_no_reproducing_specifics },
               is_abstract_pattern_only:true, carries_no_sensitive_specifics:true }
             | null,
what_stays_local[ rotulos apenas, nunca o conteudo ],
target_estates[ { estate, resolved } ], requested_estates[],
blocked_reason | null,
sovereignty { sensitive_secret_cyber_never_cross:true, only_abstract_pattern_crosses:true,
              read_only:true, auto_propagates:false, human_gated:true },
writes:false,
claim_policy { read_only:true, writes:false, auto_propagates:false,
               transmits_cross_machine:false, sensitive_secret_cyber_never_cross:true,
               only_abstract_pattern_crosses:true, human_gated:true,
               reuses_kernel_sovereignty_classes:true },
propagation_hash
```

Comando: `atlas:documentation-reality-multi-estate {--data-class=internal}
{--estate=*} {--json}` (auto-descoberto, nao muta/transmite nada, **sempre sai 0**).

## Composicao — Reuso do Anticorpo do P3 e do Kernel

O **input** e o anticorpo do P3 (`atlas-documentation-reality-self-immunizing-antibody`)
reusado como esta — o O3 nao re-sintetiza anticorpo. A **lista de classes bloqueadas**
e a do Constitutional Kernel reusada como esta — o O3 nao inventa vocabulario de
soberania. O O3 adiciona so a fina camada de **propagacao cruzando estate**:
abstrair o padrao + resolver os estates alvo no registro de dominios do operador.

## Fluxo

1. Receber o anticorpo + a lista de estates alvo + a classe de dado de origem.
2. **Classificar** a classe de origem (gate de soberania).
3. Se `sensitive|secret|cyber` (ou desconhecida): **bloquear** — `what_crosses=null`,
   `target_estates=[]`, `blocked_reason=sovereignty_class_must_not_leave_machine`.
   O anticorpo fica local.
4. Se `public|internal`: construir so o **padrao abstrato** (detector kind/description
   + a FORMA do teste que reproduz, sem especificos) e resolver os **estates alvo**
   no registro de dominios.
5. Anexar o **bloco de soberania** obrigatorio + `claim_policy` espelhado, hash,
   `writes:false`. Devolver.

## Regras para IA

- O3 e **read-only proposer**: a IA propoe, um **humano aprova** cada movimento
  cruzando estate. Nunca auto-propague, transmita entre maquinas ou instale.
- NUNCA permita que classes sensitive/secret/cyber cruzem; so o **padrao abstrato**
  cruza.
- NUNCA cruze conteudo bruto de falha, paths, secrets ou evidencia.
- REUSE o Constitutional Kernel pra lista de classes bloqueadas; nao invente
  vocabulario de soberania.
- REUSE o anticorpo do P3 como input; nao re-sintetize anticorpo.

## Escopo de Implementacao

Runtime read-only, este incremento. Entrega so o **proposer** (service + comando +
teste). A aprovacao humana, a instalacao do gate em cada estate (write-bound) e a
transmissao real entre maquinas/projetos sao deliberadamente fora de escopo — o O3
**so propoe**, nao envia nada pra lugar nenhum.

## Dependencias

- `atlas-documentation-reality-outcome-grounded-leap` (L2, doc pai north-star).
- `atlas-documentation-reality-self-immunizing-antibody` (P3, anticorpo reusado como input).
- `atlas-constitutional-kernel` (SENSITIVE_CLASSES, fonte unica das classes bloqueadas).
- `AtlasDomainProfileRegistry` (registro de estates/dominios do operador).

## Evidencias

- symbol: `AtlasDocumentationRealityMultiEstateCompoundingService`
- command: `atlas:documentation-reality-multi-estate`
- test: `AtlasDocumentationRealityMultiEstateCompoundingTest`

Drift do doc filho deve ser `false` em
`atlas:aaeos:maturity --capability=atlas-documentation-reality-multi-estate-compounding --json`.

## Riscos

- **Vazamento multi-estate (O risco central, ABSOLUTO):** dado sensivel de um projeto
  cruzar para outro. **Mitigacao:** as classes **sensitive/secret/cyber NUNCA saem da
  maquina** e **NUNCA cruzam um estate** — tal anticorpo fica local, `what_crosses` e
  null, `target_estates` e vazio, `blocked_reason=sovereignty_class_must_not_leave_machine`.
  So o **padrao abstrato** (a logica do detector, domain-agnostic) cruza, e o payload
  que cruza carrega **so campos domain-agnostic** — nunca conteudo bruto, path, secret
  ou evidencia. A lista bloqueada **reusa** o Constitutional Kernel pra nao driftar.
- **Auto-propagacao:** o sistema instalar/enviar o anticorpo por conta. **Mitigacao:**
  o O3 e um **read-only proposer** — **nunca auto-propaga, nunca transmite entre
  maquinas, nunca instala**; um humano aprova cada movimento. `auto_propagates:false`
  e `writes:false` em todo envelope.
- **Abstrair de menos:** deixar um especifico vazar no padrao que cruza. **Mitigacao:**
  o padrao abstrato so carrega detector kind/description + a FORMA do teste; o teste
  prova que nenhum conteudo bruto/path/secret/evidencia cruza, e os especificos ficam
  locais como **rotulos apenas, nunca o conteudo**.
- **Inventar vocabulario de soberania:** o O3 definir as proprias classes. **Mitigacao:**
  a lista bloqueada **e** a do Kernel (`SENSITIVE_CLASSES`); o O3 nao inventa.
- **Escopo do L2:** este incremento **completa o trio de capabilities do L2** — O1
  (outcome-grounded) + O2 (intent co-formation) + O3 (multi-estate compounding), todos
  em primeiro incremento. **Mitigacao:** nenhuma nova capability do L2 e aberta aqui; a
  proxima fronteira e o L-infinito, com doc/promocao propria.

## Exemplos

```text
Anticorpo de classe internal (padrao detector generico):
O3 propoe: cross_estate_allowed=true — so o padrao abstrato cruza
   (detector_kind + description + FORMA do teste), target_estates = todos os
   estates ativos do operador. Nenhum conteudo bruto/path/secret cruza.
   (PROPOSTA: um humano aprova cada movimento; nada e enviado ainda.)

Anticorpo de classe sensitive (ou secret, ou cyber):
O3 propoe: cross_estate_allowed=false — what_crosses=null, target_estates=[],
   blocked_reason=sovereignty_class_must_not_leave_machine. O anticorpo fica
   LOCAL. (Nem o ref nem os rotulos vazam especifico sensivel.)
```

Em todos os casos o envelope carrega o bloco de soberania e um humano aprova cada
movimento. O O3 nunca auto-propaga, nunca transmite, nunca instala, nunca escreve.

## Proximas Acoes

- Refinar o padrao abstrato e os rotulos do que fica local conforme uso real, sem
  nunca vazar especifico sensivel.
- Com O1 + O2 + O3 entregues em primeiro incremento, o trio de capabilities do L2
  esta coberto; a proxima fronteira e o L-infinito (auto-modelo reflexivo), com
  doc/promocao propria.
