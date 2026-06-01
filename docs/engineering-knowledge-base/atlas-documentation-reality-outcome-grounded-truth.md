---
id: atlas-documentation-reality-outcome-grounded-truth
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas Documentation Reality Outcome Grounded Truth
slug: atlas-documentation-reality-outcome-grounded-truth
status: active
category: documentation-governance
priority: 97
summary: L2-O1 do ADRS. Primeiro incremento da verdade externa — o scorer outcome-grounding. L0/L1 provam que um doc esta implementado (drift zero); O1 pergunta a proxima coisa — foi usado / funcionou no mundo? — e gradua a capability por ter ou nao um sinal de outcome real ligado a ela. Read-only; nunca finge sinal.
human_summary: O L0/L1 prova que o codigo bate com o doc. O O1 pergunta a pergunta seguinte — alguem usou? funcionou de verdade? — e so pontua "validado pelo mundo" quando existe um sinal real ligado aquela capability. Sem sinal, ele diz honestamente "implementado, sem sinal de outcome": tecnicamente vivo, ainda nao validado pelo mundo.
human_what: Scorer L2-O1 que gradua cada doc implementado por verdade externa (sinal de outcome real), reusando a verdade interna do L0/L1 como porta.
human_purpose: Fechar o salto da verdade interna (codigo bate com doc) para a verdade externa (funcionou no mundo) sem nunca inventar resultado.
human_input: Recebe o ledger de verdade interna do L0/L1 (capability implementada? drift?) e a tabela de sinais de outcome explicitos (ai_outcome_links).
human_output: Entrega, por capability, um grau honesto — outcome_grounded (com refs do sinal), implemented_no_outcome_signal ou not_implemented — num envelope read-only com hash.
human_change_when: Mexa quando surgir uma nova fonte de sinal de outcome real ligavel a capability, ou quando o gate de verdade interna mudar.
human_block_when: Bloqueie se alguem tentar marcar outcome_grounded sem sinal real, inferir outcome por correlacao, ou abrir O2/O3 a partir deste doc.
canonical_name: Atlas Documentation Reality Outcome Grounded Truth
technical_name: AtlasDocumentationRealityOutcomeGroundingService
cartography_type: module
tags:
  - atlas-ai
  - documentation-governance
  - adrs
  - outcome-grounded
  - real-world-validity
capabilities:
  - outcome_grounding_scorer
  - real_world_validity_grading
  - internal_truth_gate_reuse
  - honest_no_signal_default
decisions:
  - Nome canonico obrigatorio - Atlas Documentation Reality Outcome Grounded Truth.
  - Acronimo tecnico - ADRS-O1.
  - outcome_grounded so existe com sinal de outcome real resolvido ligado a capability; sem sinal, o grau honesto e implemented_no_outcome_signal.
  - A verdade interna do L0/L1 (AtlasAaeosImplementationTruthService ledger) e a porta - so capability implementada (partial/verified, drift false) e elegivel para nota de outcome.
  - Doc spec/north-star (computed spec) e doc em drift recebem not_implemented e nunca sao outcome_grounded.
  - Fonte de sinal escolhida - ai_outcome_links (link explicito outcome<->target); tabelas de run/flow/telemetria NAO sao usadas porque ligar a capability exigiria inferencia.
  - Runtime read-only - nenhuma escrita, nenhuma execucao, nenhum outcome criado.
maintenance:
  - Manter abaixo de 520 linhas.
  - Atualizar quando o gate de verdade interna, a tabela ai_outcome_links ou as fontes de sinal mudarem.
  - Rodar docs-health, docs-authority-audit e architecture-validate apos alteracoes.
graph_id: atlas-documentation-reality-outcome-grounded-truth
graph_title: Atlas Documentation Reality Outcome Grounded Truth
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
  - atlas-aaeos-documentation-as-law-proposal
  - atlas-ai-knowledge-governance-system
flows_to:
  - atlas-documentation-reality-reflective-self-model
unlocks:
  - real_world_grounded_documentation
  - architecture_as_judged_not_just_verified
governs:
  - documentation-governance-outcome-truth
related_paths:
  - docs/engineering-knowledge-base/atlas-documentation-reality-outcome-grounded-leap.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-evolution-ladder.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-generative-leap.md
  - docs/engineering-knowledge-base/atlas-aaeos-documentation-as-law-proposal.md
repo_paths:
  - docs/engineering-knowledge-base/atlas-documentation-reality-outcome-grounded-truth.md
  - app/Services/Engineering/AtlasDocumentationRealityOutcomeGroundingService.php
  - app/Console/Commands/AtlasDocumentationRealityOutcomeGroundingCommand.php
  - tests/Feature/Engineering/AtlasDocumentationRealityOutcomeGroundingTest.php
allowed_changes:
  - Ligar novas fontes de sinal de outcome real que tiem explicitamente a capability (sem inferencia).
  - Refinar o envelope, os graus e os motivos mantendo a regra cardinal.
forbidden_changes:
  - Marcar outcome_grounded sem sinal real resolvido.
  - Inferir ou correlacionar outcome para preencher ausencia de sinal.
  - Tornar o scorer mutativo (escrever, executar, criar outcome).
  - Abrir O2 (intent co-formation) ou O3 (multi-estate) a partir deste doc.
evidence:
  - docs/engineering-knowledge-base/atlas-documentation-reality-outcome-grounded-truth.md
  - app/Services/Engineering/AtlasDocumentationRealityOutcomeGroundingService.php
evidence_refs:
  - symbol: AtlasDocumentationRealityOutcomeGroundingService
  - command: atlas:documentation-reality-outcome-grounding
  - test: AtlasDocumentationRealityOutcomeGroundingTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:docs-authority-audit --json"
  - "php artisan atlas:ai:architecture-validate --json"
requires_evidence: true
risk_level: critical
line_limit: 520
ai_entrypoints:
  - Leia este doc para entender como o ADRS gradua verdade externa (outcome) sem fingir sinal.
ai_usage_notes:
  - outcome_grounded exige sinal de outcome real resolvido; sem sinal, o grau e implemented_no_outcome_signal, nunca um pass falso.
  - So capability implementada (drift zero) e elegivel; spec/north-star e drift recebem not_implemented.
next_actions:
  - Inventariar e ligar fontes de sinal de outcome real que tiem explicitamente a capability.
  - So depois de O1 provado, abrir O2 (intent co-formation).
---

# Atlas Documentation Reality Outcome Grounded Truth

## Resumo

ADRS-O1 e o **primeiro incremento** do L2 (`atlas-documentation-reality-outcome-grounded-leap`).
O L0/L1 prova que um doc esta **implementado** (drift zero): "o codigo bate com o
doc?". O O1 pergunta a coisa seguinte — o salto da verdade **interna** para a
**externa**:

```text
Isso que ficou implementado foi USADO / funcionou no mundo?
```

A resposta so vira "validado pelo mundo" quando existe um **sinal de outcome
real** ligado aquela capability. Sem sinal, o grau honesto e
`implemented_no_outcome_signal`: tecnicamente vivo, ainda **nao** validado pelo
mundo. O scorer e read-only e **nunca inventa** um outcome.

## Papel no Atlas

Acertar a execucao (L1) nao basta se o alvo nao moveu nada. Uma IA pode
implementar com drift zero algo que ninguem usa. O O1 fecha esse buraco: gradua a
verdade documental pelo **mundo**, sem nunca fingir que houve resultado. Este
incremento entrega so o **scorer**; opinar sobre o alvo (O2) e propagar
aprendizado entre projetos (O3) sao incrementos posteriores.

## Onde Se Encaixa

```text
L0/L1 (verdade interna, drift zero) — AtlasAaeosImplementationTruthService.ledger()
  -> L2-O1 (este doc) — outcome-grounding scorer
       gate: so capability implementada (partial/verified, drift false) e elegivel
       grade: outcome_grounded | implemented_no_outcome_signal | not_implemented
  -> O2 Intent co-formation (incremento posterior)
  -> O3 Multi-estate compounding (incremento posterior)
```

## A Regra Cardinal

A regra que define o L2 inteiro (herdada de
`atlas-documentation-reality-outcome-grounded-leap.md`, absoluta):

```text
outcome-grounded exige sinal real;
sem sinal, o L2 nao pontua verdade externa e NAO PODE FINGIR que pontua.
```

Consequencia direta, gravada em codigo:

- `outcome_grounded` **somente** quando existe um sinal de outcome **real e
  resolvido** ligado explicitamente a capability.
- Ausencia de sinal => `implemented_no_outcome_signal` (honesto), **nunca** um
  pass falso.
- O scorer **jamais** infere, correlaciona ou fabrica um outcome.

## Contratos

`AtlasDocumentationRealityOutcomeGroundingService` (read-only):

| Metodo | O que faz |
|---|---|
| `gradeForDoc(string $ownerDoc): array` | gradua uma capability/doc (filtro passado igual ao ledger) |
| `gradeAll(): array` | gradua toda capability que declara evidence_refs |

Graus por capability:

| Grau | Quando |
|---|---|
| `not_implemented` | computed_state spec (north-star) OU em drift — nao elegivel; nunca outcome_grounded |
| `implemented_no_outcome_signal` | implementado (partial/verified, drift false) mas SEM sinal real — o default honesto |
| `outcome_grounded` | implementado E com sinal de outcome real resolvido ligado a ele (carrega as refs do sinal) |

Envelope `atlas.documentation_reality.outcome_grounded.v1` (com hash):

```text
schema_version, mode, level=L2-O1, increment, capability_filter,
summary { evaluated, outcome_grounded_count, implemented_no_outcome_count,
          not_implemented_count, outcome_signal_source_available:bool },
outcome_signal_source, grades[], writes:false,
claim_policy { read_only:true, writes:false, fabricates_outcome:false,
               infers_outcome:false, outcome_grounded_requires_real_signal:true },
outcome_grounding_hash
```

Comando: `atlas:documentation-reality-outcome-grounding {--capability=} {--json}`
(auto-descoberto, nao muta nada).

## Fonte de Sinal — ai_outcome_links

A fonte escolhida e `ai_outcome_links` (modelo `App\Models\AiOutcomeLink`): o
primitivo **explicito** e **nao-inferencial** de ligacao outcome<->alvo. Uma
linha conta como sinal real so quando:

1. **nomeia a capability** — por `target_type` em {capability, capability_id,
   owner_doc, doc, documentation} com `target_id` igual ao identificador, OU por
   `metadata.capability_id` / `metadata.owner_doc`; **e**
2. **ocorreu de fato** — `occurred_at` nao-nulo (outcome resolvido no tempo).

Sem linha, sem sinal, sem nota. As tabelas `ai_run_outcomes`, `ai_telemetry_events`
e afins ligam a um run/flow, **nao** a uma capability — liga-las a um doc exigiria
inferencia (a armadilha de atribuicao falsa que o leap proibe), entao **nao** sao
usadas como fonte de ligacao aqui.

Nota de runtime: em pgsql `target_id` e coluna UUID — so identificadores em
formato UUID sao comparados a ela (um slug humano nunca igualaria um UUID);
slugs/paths ligam pela JSON `metadata`, que e string.

## Fluxo

1. `ledger()` do L0/L1 da a verdade interna por capability (implementada? drift?).
2. **Gate**: so capability `partial`/`verified` com `drift=false` segue para nota
   de outcome; o resto e `not_implemented`.
3. Para as elegiveis, buscar sinal real em `ai_outcome_links` (ligado +
   `occurred_at`).
4. Tem sinal => `outcome_grounded` (com refs). Nao tem => `implemented_no_outcome_signal`.
5. Tabela ausente => degrade: todo doc implementado vira
   `implemented_no_outcome_signal` e `outcome_signal_source_available=false`.

## Regras para IA

- NUNCA marque `outcome_grounded` sem sinal real resolvido.
- NUNCA infira/correlacione outcome para preencher ausencia de sinal.
- NUNCA torne o scorer mutativo (escrever, executar, criar outcome).
- So capability implementada (drift zero) e elegivel; spec/north-star e drift sao
  `not_implemented`.
- NAO abra O2/O3 a partir deste doc — sao incrementos proprios.

## Escopo de Implementacao

Runtime read-only, este incremento. Entrega so o **scorer** (service + comando +
teste). A medicao/escrita de um outcome real, a co-formacao de intencao (O2) e o
compounding multi-estate (O3) sao deliberadamente fora de escopo.

## Dependencias

- `atlas-documentation-reality-outcome-grounded-leap` (L2, doc pai north-star).
- `AtlasAaeosImplementationTruthService.ledger()` (verdade interna L0/L1, a porta).
- `ai_outcome_links` (fonte de sinal de outcome explicito; degrade-safe se ausente).

## Evidencias

- symbol: `AtlasDocumentationRealityOutcomeGroundingService`
- command: `atlas:documentation-reality-outcome-grounding`
- test: `AtlasDocumentationRealityOutcomeGroundingTest`

Drift do doc filho deve ser `false` em
`atlas:aaeos:maturity --capability=atlas-documentation-reality-outcome-grounded-truth --json`.

## Riscos

- **Pass falso de outcome:** marcar `outcome_grounded` sem sinal. Mitigacao: a
  regra cardinal — `outcome_grounded` exige sinal real resolvido; ausencia =>
  `implemented_no_outcome_signal`, nunca um pass falso. Invariante em codigo:
  `outcome_grounded_count` sem fonte disponivel lanca excecao.
- **Atribuicao falsa por inferencia:** correlacionar run/telemetria a um doc.
  Mitigacao: so `ai_outcome_links` com ligacao explicita (target ou metadata) +
  `occurred_at`; nada de run/flow/telemetria como fonte de ligacao.
- **Outcome em doc nao implementado:** pontuar mundo sobre spec/north-star.
  Mitigacao: o gate de verdade interna — so capability implementada (drift zero)
  e elegivel; o resto e `not_implemented`.
- **Escopo inflado:** abrir O2 (intent co-formation) ou O3 (multi-estate) aqui.
  Mitigacao: este doc e so o scorer O1; O2 e O3 sao incrementos posteriores,
  cada um com doc filho/promocao propria, sinal real e drift zero.

## Exemplos

```text
L1 diz: "esse service ficou implementado, drift zero." (verdade interna ok)
O1 pergunta: "tem sinal real de outcome ligado a ele?"
  -> se ha uma linha em ai_outcome_links que nomeia a capability e ocorreu:
       grade = outcome_grounded (com as refs do sinal).
  -> se nao ha nenhuma:
       grade = implemented_no_outcome_signal (tecnicamente vivo, nao validado pelo mundo).
Doc north-star (computed spec) -> grade = not_implemented (nunca outcome_grounded).
```

No corpus vivo de hoje, `ai_outcome_links` existe mas nenhuma linha tia uma
capability — entao todo doc implementado e honestamente
`implemented_no_outcome_signal` e `outcome_grounded_count=0`. Esse e o resultado
**correto** do O1, nao uma falha.

## Proximas Acoes

- Inventariar e ligar fontes de sinal de outcome real que tiem explicitamente a
  capability (uso, producao, financeiro), sem inferencia.
- So depois de O1 provado e com sinal real fluindo, abrir O2 (intent co-formation).
