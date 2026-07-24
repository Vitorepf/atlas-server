---
id: atlas-forge-rivals-industrial-benchmark-suite-v1
type: engineering_knowledge
title: Atlas Forge Rivals Industrial Benchmark Suite v1
status: deprecated
superseded_by: docs/engineering-knowledge-base/atlas-rivals-product-v1.md
implementation_state: source_material_no_runtime_authority
category: programming-forge
priority: 93
summary: "SUPERSEDED by atlas-rivals-product-v1 (Rivals 2.0). Legacy: Canon industrial para transformar Rivals de release 40 casos em suite com presets 50/100/200, domínios enterprise, repetição estatística e claim gates fail-closed."
tags:
  - atlas
  - forge
  - rivals
  - industrial-benchmark
  - provider-arena
  - atlas-decide
capabilities:
  - industrial_50_benchmark
  - industrial_100_benchmark
  - industrial_200_benchmark
  - ambiguous_bugs_benchmark
  - multi_day_refactors_benchmark
  - incident_response_benchmark
  - product_security_migrations_benchmark
  - statistical_repeat_benchmark
decisions:
  - A suite industrial amplia a release battery de 40 casos sem promover completion claim.
  - Presets industriais devem ser planejáveis sem provider call e fail-closed para claims.
  - Provider Arena e corpus industrial continuam advisory-only para Atlas Decide.
  - Rivals emits measured evidence; Atlas Decide decides model routing.
maintenance:
  - Atualizar quando novos presets industriais forem adicionados ao corpus canonico.
  - Manter `industrial-suite --json` sincronizado com counts reais do registry.
  - Nao reduzir claim gates, replay obrigatorio ou evidence lock para obter score.
related_paths:
  - docs/engineering-knowledge-base/atlas-rivals-curriculum-ladder-and-anti-ceiling-fallacy.md
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsIndustrialBenchmarkSuiteService.php
  - app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsProviderArenaCorpusService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsArenaRunService.php
  - app/Services/Ai/Kernel/Architecture/AtlasForgeRivalsIndustrialBenchmarkSuiteCertification.php
  - tests/Feature/Ai/Programming/AtlasForgeRivalsIndustrialBenchmarkSuiteTest.php
  - tests/Feature/Ai/Programming/AtlasForgeRivalsProviderArenaCorpusTest.php
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
owner: programming_rivals
graph_id: atlas-forge-rivals-industrial-benchmark-suite-v1
graph_title: Atlas Forge Rivals Industrial Benchmark Suite v1
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-forge-rivals-provider-arena-v2
graph_status: deprecated
graph_source: repo
human_name: Atlas Forge Rivals Industrial Benchmark Suite v1
canonical_name: Atlas Forge Rivals Industrial Benchmark Suite v1
technical_name: atlas-forge-rivals-industrial-benchmark-suite-v1
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-forge-rivals-industrial-benchmark-suite-v1.md
repo_paths:
  - app/Services/Ai/Rivals/
allowed_changes:
  - Adicionar presets industriais com corpus validado, tests e claim gates fail-closed.
  - Evoluir executor industrial preservando evidence pack, replay, matrix lock e advisory-only.
forbidden_changes:
  - Destravar external_rivals_certification.
  - Emitir score quando evidence, replay ou matrix estiverem quebrados.
  - Fazer Rivals alterar provider topology do Atlas Decide.
depends_on:
  - atlas-forge-rivals-provider-arena-v2
  - atlas-forge-rivals-provider-arena-corpus-v1
  - atlas-forge-rivals-benchmark-strategy-v1
flows_to:
  - atlas-forge-rivals-intelligence-ledger-v1
  - atlas-decide
unlocks:
  - rivals_industrial_benchmark_planning
  - rivals_industrial_corpus_readiness
governs:
  - forge_rivals_industrial_benchmark_suite
evidence:
  - tests/Feature/Ai/Programming/AtlasForgeRivalsIndustrialBenchmarkSuiteTest.php
  - tests/Feature/Ai/Programming/AtlasForgeRivalsProviderArenaCorpusTest.php
evidence_refs:
  - test: AtlasForgeRivalsIndustrialBenchmarkSuiteTest
  - symbol: AtlasForgeRivalsIndustrialBenchmarkSuiteService
required_tests:
  - tests/Feature/Ai/Programming/AtlasForgeRivalsIndustrialBenchmarkSuiteTest.php
  - tests/Feature/Ai/Programming/AtlasForgeRivalsProviderArenaCorpusTest.php
requires_evidence: true
risk_level: high
next_actions:
  - Fechar fixtures reais e evidence/replay/matrix para execucoes industriais pagas.
  - Manter `run-arena --case-set=<preset industrial>` como caminho operacional principal ate a migracao total do preflight legado.
updated_at: 2026-05-17
---

> SUPERSEDED (2026-07-02 / docs overhaul 2026-07-09): Rivals 1.0 removido. Canon vivo: `atlas-rivals-product-v1.md` + `atlas-rivals-structure-v1.md`. Kill-map histórico: `atlas-rivals2-rebuild-map-v1.md`. Runtime: `atlas:rivals`.


# Atlas Forge Rivals Industrial Benchmark Suite v1

## PÉTRO — Curriculum ladder / anti-ceiling fallacy

**Canon:** `docs/engineering-knowledge-base/atlas-rivals-curriculum-ladder-and-anti-ceiling-fallacy.md`

- **80–100% num teste = aprovação de NÍVEL escolar**, nunca teto do modelo, do Atlas, nem “inteligência suprema”.
- Suite saturada → **S_sanity** (regressão/paridade). Multiplicador e claim forte → só em **S_frontier** não saturado.
- Raw ou Atlas dominou o frontier → **promover L_{k+1}** (próximo livro). **Proibido** dizer “50× / N×M impossível para sempre porque já faz 80%”.
- **2026 ≠ 2030 ≠ 2040 ≠ 2050**; o currículo **sobe**. Atlas 80% em teste medíocre = **inútil como meta final** — elevar o nível.
- Essência Rivals: medir com/sem Atlas e **sempre melhorar o Atlas no frontier**, não congelar tabuada.



## Resumo

Suite industrial de benchmark para elevar Rivals alem da release battery de 40 casos, com presets 50/100/200, domínios enterprise e repetição estatística.

## Papel no Atlas

Rivals mede desempenho real entre arms e emite evidência. Atlas Decide continua dono exclusivo de model routing. “Rivals emits measured evidence; Atlas Decide decides model routing.”

## Onde Se Encaixa

Fica acima do corpus Provider Arena v1 e do Provider Arena v2, usando arms/modelos resolvidos pelo registry canônico e mantendo saída advisory-only.

## Contratos

Cada preset industrial precisa resolver casos canônicos, não gastar provider em dry-run, manter `external_rivals_certification` bloqueado e impedir score quando evidence, replay ou matrix falham.

## Fluxo

Operador lista presets com `industrial-suite`, inspeciona casos com `cases --case-set=...`, planeja custo com `plan-real` e executa duelos industriais via `run-arena --case-set=<preset>`.

## Regras para IA

Não transformar `local_fake`, `dry-run`, quick battery ou planejamento em claim real. Não reduzir gates para produzir vencedor. Não alterar provider topology.

## Escopo de Implementacao

Escopo atual: presets industriais, corpus resolvível, dry-run multi-case, certificação fail-closed, doc canônico e testes focados.

## Dependencias

Depende de Provider Arena v2, corpus Provider Arena, evidence pack, replay obrigatório, matrix evidence lock, report v3 e ledgers advisory-only.

## Evidencias

Evidência mínima: testes do corpus industrial, testes da action `industrial-suite`, dry-run de `run-arena --case-set=industrial-50`, `docs-health` e auditoria Rivals.

## Riscos

Risco principal: confundir corpus planejável com benchmark real executado. Claims industriais fortes continuam bloqueados até evidence/replay/matrix reais.

## Exemplos

```bash
php artisan atlas:forge:rivals industrial-suite --json
php artisan atlas:forge:rivals cases --case-set=industrial-50 --json
php artisan atlas:forge:rivals run-arena --arm-a=claude_code --arm-a-model=sonnet --arm-b=claude_code --arm-b-model=opus --mode=provider_arena --case-set=industrial-50 --dry-run --json
```

## Proximas Acoes

Fechar execução real industrial com fixtures completas, receipts por arm, replay strict, matrix report e relatório consultivo antes de qualquer claim externo.

## Canon

Suite id: `atlas-forge-rivals-industrial-benchmark-suite-v1`.

Frase canônica: “Rivals emits measured evidence; Atlas Decide decides model routing.”

Rivals mede evidência. Atlas Decide decide roteamento. Toda saída machine-readable da suite industrial permanece:

- `advisory_only=true`
- `should_update_provider_topology=false`
- `never_changes_atlas_decide_topology=true`
- `owner_of_model_routing=atlas_decide`
- `routing_effect=none`

## Presets

Presets industriais canônicos:

- `industrial-50`: 50 casos.
- `industrial-100`: 100 casos.
- `industrial-200`: 200 casos.
- `ambiguous-bugs`: 50 casos com domínio `ambiguous_bug`.
- `multi-day-refactors`: 50 casos com domínio `multi_day_task`.
- `incident-response`: 50 casos com domínio `incident_rollback`.
- `product-security-migrations`: 50 casos cruzando `product`, `security` e `migration`.
- `statistical-repeat`: 60 execuções planejadas, 20 grupos x 3 repetições.
- `ceiling-360`: 120 casos L5 de teto pratico, todos com ambiguidade alta, risco critical e cobertura explicita das 7 capacidades 360 obrigatorias.

Os presets são expostos por:

```bash
php artisan atlas:forge:rivals industrial-suite --json
php artisan atlas:forge:rivals cases --case-set=industrial-50 --json
php artisan atlas:forge:rivals cases --case-set=industrial-200 --json
```

## Domínios

Domínios industriais:

- `ambiguous_bug`
- `incomplete_requirements`
- `large_refactor`
- `multi_day_task`
- `incident_rollback`
- `migration`
- `documentation`
- `test_design`
- `security`
- `product`
- `integration`
- `performance`
- `flakiness_repeat`

Cada caso industrial declara `industrial_suite`, `industrial_domains`, `task_type`, `oracle`, `hidden_oracle_metadata`, `scoring_dimensions`, `evidence_requirements`, `replay_requirements`, `expected_changed_files` e `invalid_if`.

## Claim Gates

Nenhum claim forte é permitido sem:

- mínimo de 50 casos válidos;
- evidence pack completo;
- replay verde;
- scorecard por caso;
- adjudicator verde;
- matrix report verde;
- repetição estatística quando exigida;
- confiança explícita;
- aprovação humana para qualquer claim externo.

`external_rivals_certification` continua bloqueado por design.

## Ceiling 360

`ceiling-360` e o preset de maior pressao do Rivals para quando `release`,
`industrial-50` ou `extreme-differentiator` ainda empatam. Ele nao existe para
produzir claim externo automatico; existe para mapear o teto pratico dos
runners por capacidade.

Contrato:

- 120 casos;
- todos `difficulty_level=L5`;
- todos `ambiguity_level=high`;
- todos `risk_level=critical`;
- `planning_weight >= 0.70`;
- cada caso mede `long_context_retention`, `multi_step_reasoning`,
  `rollback_safety`, `scope_boundary_discipline`,
  `replayable_evidence_quality`, `honest_blocker_behavior` e
  `ambiguous_human_prompt_handling`;
- toda saida permanece advisory-only para Atlas Decide.

## Execução

Planejamento local, sem provider:

```bash
php artisan atlas:forge:rivals plan-real --preset=industrial-50 --mode=local_fake --json
```

Provider Arena com corpus industrial:

```bash
php artisan atlas:forge:rivals run-arena \
  --arm-a=claude_code --arm-a-model=sonnet \
  --arm-b=claude_code --arm-b-model=opus \
  --mode=provider_arena \
  --case-set=industrial-50 \
  --dry-run --json
```

Run real exige as três confirmações:

```bash
php artisan atlas:forge:rivals run-arena \
  --arm-a=claude_code --arm-a-model=sonnet \
  --arm-b=claude_code --arm-b-model=opus \
  --mode=provider_arena \
  --case-set=industrial-50 \
  --confirm-runbook-reviewed \
  --confirm-provider-cost \
  --confirm-real-provider-call \
  --json
```

## Estado Operacional

`run-battery --preset=industrial-* --mode=local_fake --dry-run` deve planejar a bateria industrial sem provider call, sem token spend e com `case_set` resolvido para o preset industrial.

`run-arena --case-set=<industrial preset> --dry-run` deve planejar duelos Provider Arena v2 com os mesmos casos industriais.

Isso não autoriza claim forte; apenas torna a suite industrial planejável, auditável e fail-closed. Score real e claim forte continuam bloqueados sem evidence pack, replay, matrix evidence lock, scorecard por caso, adjudicator verde e confiança explícita.

## Execução Local

O próximo nível operacional está canonizado em `atlas-forge-rivals-industrial-execution-suite-v1.md`: `industrial-50` passa a ter readiness executável local, fixtures determinísticas e caminho `local_fake` sem provider/tokens.
