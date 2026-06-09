---
id: atlas-forge-rivals-benchmark-strategy-v1
type: engineering_knowledge
title: Atlas Forge Rivals Benchmark Strategy v1
status: source_material
implementation_state: source_material_no_runtime_authority
category: programming-forge
priority: 96
summary: Estrategia canonica para transformar Rivals em uma bateria real de comparacao entre Atlas Forge, Claude Code, Codex, Gemini e futuros runners por categoria, preset, confianca e evidencia replayable.
tags:
  - atlas
  - forge
  - rivals
  - provider-arena
  - benchmark-strategy
  - atlas-decide
capabilities:
  - rivals_benchmark_presets
  - rivals_provider_arena_strategy
  - rivals_real_quality_corpus
  - rivals_difficulty_ladder
  - rivals_confidence_ladder
  - rivals_decide_signal_source
decisions:
  - Rivals mede realidade por baterias reais, nao por smoke isolado.
  - `quick` prova harness; `release` permite comparacao seria; `deep` permite ranking confiavel.
  - Provider Arena compara qualquer arm canonico contra qualquer outro arm canonico, com fairness explicita.
  - Todo case real declara dificuldade L1-L5, peso de planejamento e peso de execucao.
  - Resultados alimentam Atlas Decide como sinal consultivo, nunca como autoridade runtime isolada.
  - Resultado estranho, especialmente Claude muito baixo, e suspeito ate triage provar que o harness esta correto.
maintenance:
  - Atualizar esta doc quando presets, categorias, pesos, confianca ou runners canonicos mudarem.
  - Toda nova categoria deve ter casos reais, acceptance gates e evidence requirements.
  - Toda mudanca na escala de dificuldade exige atualizar corpus, adjudicator e report.
  - Manter em sincronia com corpus, adjudicator, performance ledger e Provider Arena UI.
related_paths:
  - docs/engineering-knowledge-base/atlas-forge-rivals-battery-modes-and-human-prompts-v1.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-perfect-battery-and-adjudicator-v1.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-provider-arena-core-v1.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-provider-arena-v2.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-industrial-benchmark-suite-v1.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-provider-arena-corpus-v1.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-provider-performance-ledger-v1.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-intelligence-ledger-v1.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-reporting-v1.md
  - docs/engineering-knowledge-base/atlas-code-provider-arena-ui-v1.md
  - docs/engineering-knowledge-base/system-graph/atlas-decide.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
owner: programming_rivals
graph_id: atlas-forge-rivals-benchmark-strategy-v1
graph_title: Atlas Forge Rivals Benchmark Strategy v1
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-forge-rivals-perfect-battery-and-adjudicator-v1
graph_status: active
graph_source: repo
human_name: Atlas Forge Rivals Benchmark Strategy v1
canonical_name: Atlas Forge Rivals Benchmark Strategy v1
technical_name: atlas-forge-rivals-benchmark-strategy-v1
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-forge-rivals-benchmark-strategy-v1.md
repo_paths:
  - docs/engineering-knowledge-base/atlas-forge-rivals-benchmark-strategy-v1.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-provider-arena-corpus-v1.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-provider-performance-ledger-v1.md
allowed_changes:
  - Adicionar presets, case sets ou task categories quando houver evidence/replay/scoring canonico.
  - Ajustar thresholds de confianca quando o corpus real crescer.
  - Adicionar runners futuros apenas via Provider Arena registry.
forbidden_changes:
  - Declarar vencedor global a partir de `quick`.
  - Aceitar synthetic score, missing receipt, missing replay ou evidence incompleto como resultado valido.
  - Desbloquear `external_rivals_certification` automaticamente.
  - Tratar score baixo improvavel de Claude/Codex/Opus como verdade sem triage.
depends_on:
  - atlas-forge-rivals-perfect-battery-and-adjudicator-v1
  - atlas-forge-rivals-provider-arena-core-v1
  - atlas-forge-rivals-provider-arena-corpus-v1
flows_to:
  - atlas-forge-rivals-provider-performance-ledger-v1
  - atlas-decide
unlocks:
  - rivals_real_provider_ranking_by_task_category
  - atlas_decide_provider_choice_from_rivals_evidence
governs:
  - rivals_benchmark_corpus_strategy
  - rivals_difficulty_ladder
  - rivals_confidence_ladder
  - rivals_provider_arena_modes
evidence:
  - docs/engineering-knowledge-base/atlas-forge-rivals-benchmark-strategy-v1.md
  - "php artisan atlas:engineering:knowledge docs-health --json"
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:architecture-validate --json"
requires_evidence: true
risk_level: high
next_actions:
  - Rodar `messy-real` e `enterprise-change` como baterias reais dedicadas com hidden oracle.
  - Implementar `deep` com 25+ casos por dominio e confidence interval por categoria.
  - Evoluir Provider Performance Ledger para Intelligence Ledger historico e segmentado.
---
# Atlas Forge Rivals Benchmark Strategy v1

## Resumo

Rivals existe para responder uma pergunta operacional: **quem deve programar
esta tarefa agora?** A resposta pode ser Atlas Forge, Claude Code, Codex,
Gemini, Opus, Sonnet, ou um modo especifico do Atlas. Para isso, Rivals deve
medir execucoes reais, com provider real quando a bateria for real, evidence
pack, replay, score deterministico e report verificavel.

Um smoke isolado prova apenas que o harness liga. Uma bateria real prova
capacidade comparavel. Um ranking confiavel exige varias categorias e varias
rodadas.

## Papel no Atlas

Rivals nao e marketing, nao e UI demo e nao e certificacao externa automatica.
Ele e a bancada de teste que produz sinal para:

- escolher provider/modelo por categoria;
- comparar Atlas Forge contra runners puros;
- detectar regressao de modelo ou estrategia;
- alimentar o Provider Performance Ledger;
- dar ao Atlas Decide uma fonte local de evidencia, sempre consultiva.

`external_rivals_certification` continua separado e bloqueado por design.

## Onde Se Encaixa

Esta estrategia fica acima do adjudicator, do corpus, do harness e do
Provider Performance Ledger. Ela define **o que medir**, **como agrupar as
baterias** e **quando um resultado pode ser usado**. O adjudicator calcula o
score; o corpus fornece os casos; o ledger agrega historico; Atlas Decide
consome apenas o sinal consultivo derivado.

## Contratos

### Presets canonicos

| Preset | Casos | Uso | Claim permitido |
| --- | ---: | --- | --- |
| `quick` | 3 | Provar que harness, provider, evidence e replay funcionam. | Nao declara superioridade. |
| `release` | 40 | Comparacao seria por 8 categorias x 5 niveis. | Pode declarar vencedor da bateria se trusted. |
| `industrial-50` | 50 | Primeiro benchmark industrial amplo. | Claim forte bloqueado sem evidence/replay/scorecard/matrix/confidence. |
| `industrial-100` | 100 | Benchmark industrial com maior estabilidade. | Claim forte bloqueado sem gates verdes. |
| `industrial-200` | 200 | Suite industrial completa versionada. | Claim forte bloqueado sem gates verdes. |
| `ambiguous-bugs` | 50 | Bugs ambiguos e requisitos incompletos. | Claim forte bloqueado sem hidden-oracle/evidence. |
| `multi-day-refactors` | 50 | Refactors longos e multi-dia. | Claim forte bloqueado sem plano/replay. |
| `incident-response` | 50 | Incidentes, rollback e postmortem. | Claim forte bloqueado sem evidence completo. |
| `product-security-migrations` | 50 | Produto, seguranca e migrations. | Claim forte bloqueado sem safety gates. |
| `statistical-repeat` | 50 | Variancia e flakiness. | Claim forte bloqueado sem repeticao estatistica. |
| `ceiling-360` | 120 | Teto pratico L5 por capacidade 360 obrigatoria. | Claim forte bloqueado sem evidence/replay/matrix/confidence e repeticoes. |
| `deep` | 25+ | Ranking confiavel e tendencia por dominio. | Pode alimentar ranking com confianca alta. |
| `frontend` | 5+ | Medir UI, acessibilidade, estados e polish. | Vencedor por frontend. |
| `backend` | 5+ | Medir logica, integracao, estado, policy e dados. | Vencedor por backend. |
| `architecture` | 5+ | Medir boundary, schema, fail-closed e extensibilidade. | Vencedor por arquitetura. |
| `provider-arena` | variavel | Claude vs Codex vs Opus vs Gemini vs futuros runners. | Ranking por provider/modelo. |
| `atlas-power` | 8+ | Atlas Forge full_power contra baseline puro. | Delta Atlas-vs-provider puro. |

### Modos de prompt

O canon completo dos modos vive em
`atlas-forge-rivals-battery-modes-and-human-prompts-v1.md`.

| Modo | O que mede |
| --- | --- |
| `spec-perfect` | Execucao quando a spec ja esta completa, com escopo e criterios claros. |
| `human-normal` | Pedido humano comum, sem schema perfeito, para medir produto real. |
| `messy-real` | Ambiguidade, ruido e informacao faltando; mede investigacao e fail-closed. |
| `enterprise-change` | Mudanca longa com docs, risco, rollback, migracao e evidencia. |

### Categorias obrigatorias

A bateria `release` deve cobrir pelo menos:

1. `backend_logic`
2. `frontend_ui`
3. `realistic_bugfix`
4. `refactor`
5. `test_design`
6. `architecture`
7. `integration`
8. `performance_edge_case`

Cada categoria precisa de fixture, escopo permitido, arquivos proibidos,
acceptance criteria, comando de teste, timeout, weights, dificuldade e
invalid_if.

### Taxonomia de dificuldade

Rivals mede profundidade, nao apenas quantidade. Todo case real precisa
declarar:

- `difficulty_level`: `L1` a `L5`.
- `difficulty_score`: numero de `1.0` a `5.0`.
- `difficulty_reason`: por que o caso e dificil.
- `planning_weight`: quanto planejamento decide a qualidade.
- `execution_weight`: quanto implementacao direta decide a qualidade.
- `ambiguity_level`: `low`, `medium` ou `high`.
- `risk_level`: `low`, `medium`, `high` ou `critical`.

| Nivel | Nome | O que mede | Multiplicador inicial |
| --- | --- | --- | ---: |
| `L1` | Mechanical | Mudanca local, bug obvio, escopo pequeno. | 1.00 |
| `L2` | Local Reasoning | Entender poucos arquivos, corrigir e testar bem. | 1.20 |
| `L3` | Product/Integration | Regra de negocio, estado, edge cases e integracao. | 1.50 |
| `L4` | Architectural | Boundary, schema, compatibilidade, fail-closed. | 2.00 |
| `L5` | Strategic Planning | Decompor trabalho, prever riscos e escolher arquitetura. | 2.50 |

Planejamento deve ser medido explicitamente em casos onde ele decide a
qualidade: `software_planning`, `execution_planning`, `system_design`,
`risk_analysis` e `implementation_strategy`. Vencer varios L1 nao deve
parecer mais importante do que vencer poucos L4/L5.

### Scoring canonico

O adjudicator deve ser local e deterministico. Dimensoes canonicas:

- `correctness`
- `test_coverage`
- `minimality`
- `maintainability`
- `scope_discipline`
- `architecture_fit`
- `ux_quality` quando frontend;
- `performance` quando aplicavel;
- `evidence_quality`
- `cost_time`

Hard gates obrigatorios: testes passam, patch diff existe, provider receipt
existe, workspace limpo antes/depois, sem arquivo fora de escopo, sem bytecode
ou lixo, replay strict passa, evidence completo.

Score agregado deve registrar `raw_score`, `difficulty_multiplier` e
`difficulty_weighted_score`. O adjudicator pode usar a formula
`raw_score * difficulty_multiplier * confidence_factor`, mas precisa mostrar
os tres valores para nao esconder distorcao por dificuldade.

Hard fail nunca vira vencedor. Tie abaixo do threshold vira
`human_review_required_tie`.

## Regras para IA

- Nao declarar vencedor global a partir de `quick`.
- Nao aceitar synthetic score, missing receipt, missing replay ou evidence
  incompleto como resultado valido.
- Nao desbloquear `external_rivals_certification` automaticamente.
- Nao tratar score baixo improvavel de Claude, Codex ou Opus como verdade sem
  triage.
- Nao usar dropdown livre de runner; todo arm precisa existir no registry.
- Nao permitir hard fail com score numerico ou vencedor.

## Escopo de Implementacao

### Casos iniciais recomendados

| Case | Categoria | Mede |
| --- | --- | --- |
| `backend-pagination-off-by-one` | realistic_bugfix | Correcao minima + teste de regressao. |
| `backend-permission-policy-leak` | backend_logic | Deny-first, tenant safety, policy clara. |
| `backend-idempotent-webhook` | integration | Idempotencia, retry, event log. |
| `backend-cache-invalidation` | backend_logic | Atualizacao consistente e teste. |
| `frontend-execution-status-panel` | frontend_ui | Feedback de processo longo e estados. |
| `frontend-filterable-table` | frontend_ui | Busca, sort, empty state, acessibilidade. |
| `frontend-form-validation-accessibility` | frontend_ui | Erro inline, keyboard, labels. |
| `refactor-controller-to-service` | refactor | Separacao sem mudar comportamento. |
| `test-regression-before-fix` | test_design | Teste que falha antes e passa depois. |
| `integration-fake-provider-timeout-retry` | integration | Timeout, retry e blocker honesto. |
| `architecture-schema-versioned-receipt` | architecture | Versionamento, hash e replay. |
| `performance-n-plus-one-query` | performance_edge_case | Reduzir custo sem regressao. |

### Provider Arena

Provider Arena deve permitir comparar:

- Atlas Forge vs Claude Code;
- Atlas Forge vs Codex;
- Claude Code vs Codex;
- Sonnet vs Opus;
- Codex vs Gemini;
- Atlas Forge `fair` vs Atlas Forge `full_power`;
- Atlas Forge usando Sonnet vs Atlas Forge usando Codex;
- Codex para frontend vs Claude para frontend;
- Claude para arquitetura vs Codex para bugfix;
- qualquer runner futuro registrado no arm registry.

Dropdown livre e proibido. Todo runner precisa existir no registry, declarar
provider, modelo, modo de execucao, categorias suportadas, evidence support,
streaming support, replay support e safety contract.

## Fluxo

1. Operador escolhe preset, case set, arms e modo.
2. Preflight valida workspace, policy, runners e corpus.
3. Runner executa os arms com streaming e receipts.
4. Evidence pack coleta diff, logs, hashes, testes e replay manifest.
5. Adjudicator aplica hard gates e score deterministico.
6. Report emite resultado, confianca, suspeitas e proxima acao.
7. Provider Performance Ledger registra somente runs validos ou invalidos com
   motivo explicito.

### Modos de comparacao

| Modo | Regra |
| --- | --- |
| `fair` | Mesmo modelo/familia quando a pergunta e arquitetura vs baseline sob igualdade. |
| `full_power` | Atlas pode usar sua topologia completa e o rival usa runner declarado. |
| `provider_pure` | Runner A vs runner B sem Atlas Forge. |
| `role_specific` | Provider por funcao: frontend, bugfix, reviewer, architect. |
| `cost_quality` | Ranking pondera custo, tempo e qualidade. |

`fair` e o modo de claim mais conservador. `full_power` mede o valor real do
Atlas como sistema, nao apenas o modelo subjacente.

## Evidencias

Evidencia minima de resultado valido: provider receipts dos arms, patch diffs,
test logs, quality logs, workspace hash antes/depois, after-clean-check,
timeline com timestamps, replay manifest e report do adjudicator.

### Confianca

| Nivel | Condicao minima | Uso |
| --- | --- | --- |
| `flow_validated` | `quick` com 3 casos, replay verde. | O harness funciona. |
| `directional_signal` | 5+ casos, 2+ categorias, sem suspicious result. | Tendencia inicial. |
| `trusted_battery` | `release` 40 casos, 8 categorias x 5 niveis, evidence completo. | Declarar vencedor da bateria. |
| `provider_ranking` | 25+ casos, varias rodadas, por categoria. | Alimentar ranking. |
| `decide_signal` | Ledger com historico e freshness. | Atlas Decide usa como sinal consultivo. |

Resultado com Claude/Codex/Opus muito baixo e suspeito por padrao. Se um
provider forte pontuar abaixo de 70, a bateria deve rodar triage antes de
emitir trusted result.

## Riscos

Risco central: confundir falha do harness com inferioridade real de um
provider. Por isso resultado suspeito invalida claim ate triage.

### Triage de resultado suspeito

Triage obrigatoria quando:

- Claude, Codex ou Opus pontua abaixo de 70;
- vencedor tem margem grande em case simples;
- um arm tem `stdout` vazio ou patch muito pequeno;
- timeout, stall, dirty workspace ou missing receipt aparecem;
- score diverge muito do historico do Provider Performance Ledger.

A triage verifica prompt, receipt, diff, logs, timeout, scope, test command,
fixture, replay, hashes e se o case favorece um arm por conhecimento interno.
Se a causa for harness/setup/case injusto, o resultado vira invalid.

## Dependencias

Depende de Provider Arena registry, corpus versionado, run-battery, evidence
pack, adjudicator, report v2, performance ledger e Atlas Decide receipt.

### Ligacao com Atlas Decide

Rivals nao decide sozinho. O Provider Performance Ledger transforma scorecards
em sinal por:

- provider;
- modelo;
- categoria;
- role;
- modo (`fair`, `full_power`, `provider_pure`);
- custo;
- tempo;
- freshness;
- confidence.

Atlas Decide pode consumir esse sinal para escolher provider/modelo, mas deve
continuar emitindo decision receipt proprio. Rivals e evidence source, nao
runtime authority.

## Adjudicator v2 (per-category)

Entregue 2026-05-15. Detalhe canon em
`atlas-forge-rivals-perfect-battery-and-adjudicator-v1.md` §4b.

- Schema `atlas.forge.rivals.adjudication.v2` (superset de v1, v1 intacto em disco).
- Hard gates expandidos (18 codes operator-friendly). Hard fail ⇒ `score=null`,
  `winner=null`, `valid=false`. NUNCA aceita synthetic score, NUNCA mascara
  hard fail, NUNCA promove `claim_ready`.
- Confidence ladder: `flow_validated < directional_signal < trusted_battery <
  provider_ranking < decide_signal`. `release` (40 casos, 8 categorias x 5 niveis) =
  `trusted_battery`; `quick` (3 casos) jamais declara superioridade global.
- Triage automatizada: Claude/Codex/Opus < 70 sem hard fail ⇒
  `rival_underperformed_unexpectedly` com `affects_winner=true`; Atlas vence
  case simples com margem > 35 ⇒ `atlas_won_easy_case_by_huge_margin`.
- Suspicious result que afeta winner colapsa categoria/overall para
  `no_trusted_winner` e exige human review.
- `ledger_projection_ready[]` no envelope é consumível direto por `ledger-record`.
- CLI: `php artisan atlas:forge:rivals adjudicate --run-id=<id>` (single-run)
  ou `--input=<path>` (batch multi-case) com `--json --strict`.

## Proximas Acoes

1. `quick`: 3 casos, prova de harness, provider real e replay.
2. `release`: 40 casos, oito categorias x cinco niveis, report agregado, v2 adjudicator
   habilitado ⇒ `trusted_battery` confidence.
3. `human-normal`: bateria paralela ao spec-perfect com prompt publico humano comum via `--prompt-mode=human-normal`.
4. `messy-real`: bateria com ruido/ambiguidade + hidden oracle + triage.
5. `provider-arena`: Claude, Codex, Opus, Gemini e runners futuros.
6. `frontend/backend/architecture`: packs especializados.
7. `deep`: 25+ casos por dominio, confidence interval e ranking por categoria,
   `provider_ranking` confidence quando o corpus crescer.
8. `atlas-power`: medir delta Atlas Forge full_power vs provider puro.
9. Intelligence Ledger: historico segmentado por provider, modelo, modo,
   categoria, dificuldade, custo, tempo, estabilidade e confianca estatistica.
10. Atlas Decide consome decide-signal do ledger, sempre advisory-only.

## Exemplos

Quando esta estrategia estiver completa, o Atlas podera responder:

- Claude ou Codex e melhor em frontend?
- Atlas Forge melhora bugfix em relacao ao Claude puro?
- Opus compensa custo em arquitetura?
- Qual provider deve ser `primary_builder` para esta tarefa?
- Qual provider deve ser reviewer?
- Quando usar `fair`, `full_power` ou provider puro?

Essa e a funcao final do Rivals: produzir uma base empirica, replayable e
auditavel para otimizar continuamente a construcao de software assistida por IA.
