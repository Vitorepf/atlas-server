---
id: atlas-forge-rivals-provider-arena-corpus-v1
type: engineering_knowledge
title: Atlas Forge Rivals · Provider Arena Release Matrix Corpus v1
status: active
category: rivals
priority: 90
summary: Corpus canon de 40 casos reais (Release Matrix v1, 8 categorias × 5 níveis L1..L5) que a Provider Arena usa para medir Atlas Forge vs Claude Code vs Codex CLI vs Gemini CLI, com 29 campos por caso, 6 case sets, fixture seeds reais, replay manifest determinístico e cert de 22 invariants — sem chamar provider e sem destravar external_rivals_certification.
tags:
  - atlas-forge-rivals
  - provider-arena
  - corpus
  - release-v1
  - quality-gates
  - replayable
  - evidence-pack
capabilities:
  - provider_arena_corpus_resolution
  - corpus_case_filtering
  - corpus_fixture_isolation
  - corpus_replay_manifest
  - corpus_content_hashing
decisions:
  - Doze casos canon Release v1, um por necessidade real, distribuídos nas 8 categorias canon (backend_logic, frontend_ui, realistic_bugfix, refactor, test_design, architecture, integration, performance_edge_case).
  - Schema de 22 campos por caso (case_id, title, category, secondary_categories, difficulty, objective, business_rule, acceptance_criteria, allowed_files_scope, forbidden_files_scope, fixture_seed_path, quick_test_command, full_test_command, expected_changed_files, quality_weights, invalid_if, timeout_policy, evidence_requirements, replay_requirements, fairness_notes, human_review_notes, claim_level).
  - Seis case sets canon (quick, release, frontend, backend, bugfix, architecture) com regras de filtro determinísticas baseadas em case_id prefix e/ou category.
  - Fixture seed mora em storage/forge-rivals-corpus/<case_id>/seed; cada seed inclui código quebrado + teste falhando ou faltando, exigindo edição real.
  - claim_level == case_result_only em todos os casos — corpus nunca emite global_claim, nunca destrava external_rivals_certification.
  - Schema legacy (task_category, role_focus, setup_fixture, quality_gates, expected_signal, expected_evidence) é auto-populado por adaptCase() para preservar RunRealService.adaptCorpusCase e o pipeline run-battery sem coordenação cruzada.
  - Adjudicator NÃO consome quality_weights por caso nesta slice; weights ficam armazenados para plug futuro.
  - Real multi-case fica honestly pending; apenas local_fake (dry-run) é wired neste slice.
maintenance:
  - Adicionar caso novo exige 22 campos declarados + seed dir real + atualizar a tabela canon + rodar test --filter='ProviderArenaCorpus'.
  - Toda alteração em corpus exige atualizar AtlasForgeRivalsProviderArenaCorpusCertification (20 invariants).
  - Seeds novos precisam de README.md + pelo menos um arquivo de código + um teste falhando ou faltando.
  - Manter cobertura igualmente distribuída entre as 8 categorias canon; cobertura por primary category é cert invariant 12.
related_paths:
  - docs/engineering-knowledge-base/atlas-forge-rivals-battery-modes-and-human-prompts-v1.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-benchmark-strategy-v1.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-perfect-battery-and-adjudicator-v1.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-provider-arena-core-v1.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-provider-performance-ledger-v1.md
  - app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsProviderArenaCorpusService.php
  - app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsCorpusFixtureRunnerService.php
  - app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsCorpusPlannerService.php
  - app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsCorpusCasesActionService.php
  - app/Services/Ai/Kernel/Architecture/AtlasForgeRivalsProviderArenaCorpusCertification.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsArenaRunService.php
  - app/Console/Commands/AtlasForgeRivalsCommand.php
  - storage/forge-rivals-corpus
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-forge-rivals-provider-arena-corpus-v1
graph_title: Atlas Forge Rivals · Provider Arena Corpus Release v1
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-forge-rivals-perfect-battery-and-adjudicator-v1
graph_status: active
graph_source: repo
human_name: "Atlas Forge Rivals · Provider Arena Corpus Release v1"
canonical_name: "Atlas Forge Rivals · Provider Arena Corpus Release v1"
technical_name: atlas-forge-rivals-provider-arena-corpus-v1
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-forge-rivals-provider-arena-corpus-v1.md
owner: rivals
repo_paths:
  - app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsProviderArenaCorpusService.php
  - app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsCorpusFixtureRunnerService.php
  - app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsCorpusPlannerService.php
  - app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsCorpusCasesActionService.php
  - app/Services/Ai/Kernel/Architecture/AtlasForgeRivalsProviderArenaCorpusCertification.php
  - storage/forge-rivals-corpus
allowed_changes:
  - Adicionar caso novo declarando os 22 campos canon e seed dir mínimo.
  - Atualizar a tabela de casos quando novo caso entrar.
  - Atualizar os 20 invariants quando o contrato canon mudar.
forbidden_changes:
  - Adicionar caso que dispare provider em quick_test_command ou full_test_command.
  - Adicionar caso que toque atlas-desktop/src/voice/* ou atlas-cartografia/* no allowed_files_scope.
  - Declarar caso válido sem allowed_files_scope, invalid_if, fixture_seed_path ou quality_weights preenchidos.
  - Promover modo real multi-case sem evidence pack completo e replay verde.
  - Emitir claim_level != case_result_only por algum caso.
depends_on:
  - atlas-forge-rivals-benchmark-strategy-v1
  - atlas-forge-rivals-perfect-battery-and-adjudicator-v1
  - atlas-forge-rivals-provider-arena-core-v1
flows_to:
  - atlas-forge-rivals-perfect-battery-and-adjudicator-v1
unlocks:
  - provider-arena-quality-measurement
governs:
  - rivals-corpus
evidence:
  - docs/engineering-knowledge-base/atlas-forge-rivals-provider-arena-corpus-v1.md
  - app/Services/Ai/Kernel/Architecture/AtlasForgeRivalsProviderArenaCorpusCertification.php
evidence_refs:
  - symbol: AtlasForgeRivalsProviderArenaCorpusCertification
  - command: atlas:forge:rivals
required_tests:
  - "php artisan test --filter='ProviderArenaCorpus'"
  - "php artisan atlas:forge:rivals cases --case-set=release --json --strict"
requires_evidence: true
risk_level: medium
visual_tags:
  - system
  - corpus
  - rivals
  - release-v1
ai_entrypoints:
  - Leia Por que este corpus existe, Release Matrix v1, Schema do case manifest e Safety contract antes de adicionar caso novo ou rodar arena com corpus.
ai_usage_notes:
  - Use --case-set para baterias completas; --case para depurar um caso isolado.
  - Em local_fake o resultado é o plano multi-case replayable; o quick_test_command NÃO é executado pelo runner — quem executa é o arm.
  - Adicionar caso novo exige declarar 22 campos, criar seed dir com README + código quebrado + teste, atualizar este doc, rodar test --filter='ProviderArenaCorpus'.
quality_gates:
  - "php artisan test --filter='ProviderArenaCorpus'"
  - "php artisan atlas:forge:rivals cases --case-set=release --json --strict"
failure_modes:
  - Caso novo dispara provider via curl/claude/codex/gemini no quick_test_command.
  - allowed_files_scope vaza para Voice ou Cartografia.
  - quality_weights não somam 1.0 e validador deixa passar.
  - fixture_seed_path aponta para diretório inexistente.
  - claim_level != case_result_only.
observability_signals:
  - atlas_forge_rivals_provider_arena_corpus_certification status available
  - artisan test --filter='ProviderArenaCorpus' verde (>= 49 testes)
  - corpus_content_hash determinístico entre dois hosts
next_actions:
  - Plugar quality_weights por caso no adjudicator (slice futura).
  - Implementar loop real multi-case em run-arena (atualmente honestly pending).
  - Adicionar deep_set (25+ casos) para slice futura.
---
# Atlas Forge Rivals · Provider Arena Corpus Release v1
> Strategy canon: `atlas-forge-rivals-benchmark-strategy-v1.md`
> Schema canon: `atlas.forge.rivals.provider_arena_corpus.v1` (release_v1)
> Certificação: `atlas_forge_rivals_provider_arena_corpus_certification` (20 invariants)
> Comando canon: `php artisan atlas:forge:rivals cases --case-set=release --json --strict`
## 1. Por quê este corpus existe
A Provider Arena já sabe pôr `arm_a vs arm_b` (Atlas Forge, Claude Code, Codex CLI, Gemini CLI, scripted_runner, manual_runner, future_runner) frente a frente para uma `task_category`. Faltava o **instrumento de medição**: um corpus canon, real, multi-categoria, replayable, que permita afirmar com honestidade:
- Atlas Forge vs Claude Code em **frontend_ui** com a mesma régua.
- Sonnet vs Opus em **backend_logic** com a mesma régua.
- Forge fair vs Forge full_power em **realistic_bugfix**.
- Atlas Forge usando Sonnet vs usando Codex em **architecture**.
- Claude Code vs Codex CLI em **integration** e **performance_edge_case**.
Release v1 é esse instrumento. Resultado sintético nunca vira claim. Evidence/replay/scope-guard são invioláveis. `external_rivals_certification` permanece **blocked**.
## 2. Escopo
- **40 casos canon** Release Matrix v1.
- **8 categorias canon** como primary category (todas cobertas).
- **6 case sets** com regras de filtro determinísticas (`quick`, `release`, `frontend`, `backend`, `bugfix`, `architecture`).
- **22 campos** declarativos por caso, validados por schema.
- **Seed dir real** em `storage/forge-rivals-corpus/<case_id>/seed/` com código quebrado + teste falhando.
- **Replay manifest determinístico** com `plan_hash` reprodutível byte a byte (não depende de `generated_at`).
- **`content_hash`** sha256 do corpus inteiro (mesmo bytes => mesmo hash).
- **Modo real multi-case** está **honestly pending** — somente `local_fake` é wired neste slice.
## 3. Release Matrix v1 — 40 casos (8 categorias × 5 níveis)
### Matriz canônica
| Categoria              | L1                                       | L2                                       | L3                                       | L4                                       | L5                                       |
| ---------------------- | ---------------------------------------- | ---------------------------------------- | ---------------------------------------- | ---------------------------------------- | ---------------------------------------- |
| `planning`             | planning-l1-acceptance-checklist         | planning-l2-incremental-slices           | planning-l3-risk-register                | planning-l4-contract-first-spec          | planning-l5-phased-migration-plan        |
| `frontend_ui`          | frontend-l1-button-loading-state         | frontend-execution-status-panel          | frontend-form-validation-accessibility   | frontend-filterable-table                | frontend-l5-virtualized-keyboard-grid    |
| `backend_logic`        | backend-l1-string-normalizer             | backend-l2-currency-formatter            | backend-cache-invalidation               | backend-permission-policy-leak           | backend-l5-state-machine-transitions     |
| `realistic_bugfix`     | backend-pagination-off-by-one            | bugfix-l2-timezone-double-utc            | bugfix-l3-counter-race-condition         | bugfix-l4-flaky-time-dependent-test      | bugfix-l5-cascade-failure-fanout         |
| `refactor`             | refactor-l1-extract-method               | refactor-l2-rename-symbol-safely         | refactor-controller-to-service           | refactor-l4-replace-switch-with-strategy | refactor-l5-decompose-god-class          |
| `test_design`          | testdesign-l1-add-edge-case-tests        | test-regression-before-fix               | testdesign-l3-property-based-parser      | testdesign-l4-contract-test-between-modules | testdesign-l5-mutation-baseline       |
| `architecture`         | architecture-l1-public-api-readme        | architecture-l2-module-boundary-namespace| architecture-l3-adr-document             | architecture-l4-versioned-contract-strategy | architecture-schema-versioned-receipt  |
| `integration_performance` | intperf-l1-eager-load-relation        | performance-n-plus-one-query             | backend-idempotent-webhook               | integration-fake-provider-timeout-retry  | intperf-l5-circuit-breaker-state-machine |
Cada célula da matriz tem **exatamente 1 caso** (cert invariant 2 `release_matrix_fills_every_cell_8x5`). Total = 8 × 5 = 40 cases. Distribuição de difficulty L1×8, L2×8, L3×8, L4×8, L5×8.
### Calibração de dificuldade (régua canônica)
| Level | Score | Multiplier (score/3.0) | Quando aplicar                                                                                                                                                                                       |
| ----- | ----- | ---------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| L1    | 1.0   | 0.3333                 | **Rápido e objetivo**. Patch ≤ 20 linhas, 1 arquivo, ambiguidade zero, risco low. Exemplos: extract method, eager load mecânica, button loading state, lista de critérios numerada.                  |
| L2    | 2.0   | 0.6667                 | **Pequeno mas com disciplina**. Múltiplos passos sequenciais (TDD red-then-green, rename + alias deprecated, locale fallback). Ambiguidade low, risco low/medium.                                    |
| L3    | 3.0   | 1.0 (**neutral**)      | **Produto ou integração real**. Cruza módulos, exige decisão técnica não-trivial mas com playbook claro. Cache invalidation, idempotency, ADR, property-based test, refator controller→service.    |
| L4    | 4.0   | 1.3333                 | **Contrato, arquitetura, fail-closed**. Versionamento de contrato, deny-by-default, circuit breaker, retry policy. Decisões com impacto cross-module e compatibilidade explícita.                  |
| L5    | 5.0   | 1.6667                 | **Planejamento, decomposição, tradeoffs**. State machine canon, god-class decomposition, cascade-failure root cause, plano de migração com rollback, virtualização com budget. Ambiguity high.     |
**Como cada caso foi calibrado**:
- **L1** = a tarefa cabe em 1 PR de 1-2 commits, escopo "óbvio" para um engenheiro júnior depois de ver o teste falhando.
- **L2** = exige sequência ou disciplina (TDD, deprecation, fallback documentado); ainda 1 arquivo, mas o "como" é onde a régua aperta.
- **L3** = atravessa pelo menos 2 conceitos (cache + write-through; webhook + idempotência; refator + contract preservation). Playbook conhecido, sem decisão arquitetural.
- **L4** = contrato explícito ou fail-closed obrigatório; arm precisa documentar tradeoff (versão N suporta v1+v2; circuit breaker tem upper bound; strategy pattern aceita unknown como blocker).
- **L5** = planejamento real antes de código; decomposição em sub-problemas; falar sobre rollback/migração/risco; risk_level normalmente high ou critical.
### Distribuição por ambiguidade × risco
```
ambiguity:  low → 17 cases   medium → 18 cases   high → 5 cases
risk:       low → 11 cases   medium → 15 cases   high → 10 cases   critical → 4 cases
```
Cobertura honesta: a maior parte dos casos é low/medium ambiguity (régua mecânica), enquanto L4/L5 concentram o high/critical (ambiente real).
## 4. Schema do case manifest (29 campos canônicos: 22 do release v1 + 7 do difficulty block L1..L5)
Cada caso declara **exatamente** estes 29 campos (validados por `AtlasForgeRivalsProviderArenaCorpusService::validateManifest` que delega o bloco de dificuldade ao `AtlasForgeRivalsSchemaContractService`):
```
case_id                : string, kebab (ex: 'backend-pagination-off-by-one')
title                  : string, título humano curto
category               : enum, ∈ 8 categorias canon
secondary_categories   : list<enum>, ⊆ 8 categorias canon (pode ser vazio)
difficulty             : enum, 'easy' | 'medium' | 'hard' (alias legado coexistente)
difficulty_level       : enum canon, 'L1' | 'L2' | 'L3' | 'L4' | 'L5'
difficulty_score       : float, 1.0..5.0 (precisa casar com difficulty_level)
difficulty_reason      : string, ≥ 16 chars — por que esse nível e não outro
planning_weight        : float, 0..1 — fração do esforço em planejar
execution_weight       : float, 0..1 — fração do esforço em executar (sum=1.0±0.01)
ambiguity_level        : enum, 'low' | 'medium' | 'high'
risk_level             : enum, 'low' | 'medium' | 'high' | 'critical'
objective              : string, o que o arm precisa entregar
business_rule          : string, por que essa entrega importa
acceptance_criteria    : list<string>, checklist objetiva
allowed_files_scope    : list<glob>, paths writeable
forbidden_files_scope  : list<glob>, deny-first
fixture_seed_path      : string, storage/forge-rivals-corpus/<case_id>/seed
quick_test_command     : string, comando local (sem provider, sem rede)
full_test_command      : string, comando exaustivo
expected_changed_files : list<string>, patches esperados
quality_weights        : {dimensions:list<string>, weights:map<string,float> soma=1.0±0.01}
invalid_if             : list<string>, hard gates (inclui os 3 canônicos)
timeout_policy         : {wall_clock_seconds_max:int, per_stage_seconds_max:int, hard_kill_after_seconds:int}
evidence_requirements  : list<string>, artefatos que o evidence pack deve conter
replay_requirements    : list<string>, artefatos do replay manifest
fairness_notes         : string, por que comparação é justa entre arms
human_review_notes     : string, o que o operador audita
claim_level            : 'case_result_only', único valor admitido
```
### Hard gates obrigatórios em `invalid_if`
Todo caso precisa listar (cert invariants 8 + 10 + 11):
- `synthetic_score_admitted`
- `touched_forbidden_files`
- `external_rivals_unlock_attempted`
### Back-compat aliases (auto-populados, nunca declarados à mão)

`adaptCase()` deriva automaticamente:

- `task_category` (mapa legacy: backend_logic→backend, frontend_ui→frontend, realistic_bugfix→bugfix, refactor→refactor, test_design→tests, architecture→architecture, integration→backend, performance_edge_case→performance).
- `role_focus` (default por primary category).
- `setup_fixture.seed_dir` = `fixture_seed_path`; `setup_fixture.base_files` = derivado de `expected_changed_files`.
- `quality_gates` = `quality_weights` (mesma estrutura, alias legacy).
- `expected_signal` = primeiro item de `acceptance_criteria`.
- `expected_evidence` = `evidence_requirements`.

Esses aliases preservam `AtlasForgeRivalsRunRealService::adaptCorpusCase`, o pipeline `run-battery`, o arm contract validator e os snapshots downstream sem coordenação cruzada.

## 5. Case sets

| Case set       | Resolução                                                                                                  | Tamanho |
| -------------- | ---------------------------------------------------------------------------------------------------------- | ------- |
| `quick`        | `[backend-pagination-off-by-one, frontend-form-validation-accessibility, performance-n-plus-one-query]`.   | 3       |
| `release`      | Todos os 40 casos da matriz 8 categorias x 5 niveis.                                                       | 40      |
| `frontend`     | `case_id` começa com `frontend-` (category=frontend_ui).                                                   | 3       |
| `backend`      | `case_id` começa com `backend-` (backend/infra).                                                           | 4       |
| `bugfix`       | `category == realistic_bugfix` OU `secondary` inclui `realistic_bugfix`.                                   | 2       |
| `architecture` | `category ∈ {architecture, refactor}`.                                                                     | 2       |

## 6. Fixture runner

`AtlasForgeRivalsCorpusFixtureRunnerService` prepara workspace **isolado por run e por case**:

```
<runs_root>/<run_id>/corpus/<case_id>/workspace/
```

Reusa `WorkspaceHygieneService` (canônico) para detectar `.pyc` / `__pycache__` tracked via `git ls-files`. Hard blockers honestos:

- `seed_dir_missing:<rel-path>` — seed dir não foi entregue.
- `unknown_case_id:<id>` — case não existe no corpus.
- `invalid_manifest:<reason>` — schema do case violado em runtime.
- `blocked_tracked_python_bytecode:<sample>` — repo tem `.pyc` tracked.
- `scope_violation:<path>` — caminho fora do `allowed_files_scope`.
- `forbidden_path_touched:<path>` — caminho casa com `forbidden_files_scope`.

`cleanup($runId, $caseId)` remove apenas o workspace do caso; evidence digest fica preservado pelo battery layer.

## 7. Integração com `cases` e `run-arena`

### Action `cases` (canon)

```bash
# Default = case_set=quick
php artisan atlas:forge:rivals cases --json --strict

# Release completo (40 casos)
php artisan atlas:forge:rivals cases --case-set=release --json --strict

# Manifest de um caso específico
php artisan atlas:forge:rivals cases --case=backend-pagination-off-by-one --json --strict

# Filtra por categoria primária
php artisan atlas:forge:rivals cases --task-category=performance_edge_case --json --strict
```

Resposta inclui:

- `snapshot` — contagens por categoria, case ids, case sets, release_version.
- `applied_filters` — registro auditável do que foi aplicado.
- `cases` — lista de manifests (campos canônicos + aliases legacy).
- `replay_manifest` — manifest declarativo com `plan_hash` determinístico + `corpus_content_hash`.

### Action `run-arena` com corpus

```bash
# Dry-run multi-case (NUNCA chama provider)
php artisan atlas:forge:rivals run-arena \
  --arm-a=atlas_forge --arm-a-model=sonnet \
  --arm-b=claude_code --arm-b-model=sonnet \
  --mode=local_fake \
  --case-set=quick \
  --json --strict
```

Em `local_fake` o resultado é o **plano multi-case com replay manifest** — sem battery, sem provider. Em `fair` ou `full_power` o serviço bloqueia honestamente com `real_multi_case_pending_implementation:use_mode=local_fake_for_corpus_dry_run` em vez de fingir suporte.

## 8. Quality weights

Cada caso declara `quality_weights.dimensions` (lista priorizada) e `quality_weights.weights` (soma 1.0). Exemplo:

```json
{
  "dimensions": ["ui_correctness", "accessibility", "visual_polish", "maintainability"],
  "weights": {
    "ui_correctness": 0.40,
    "accessibility": 0.30,
    "visual_polish": 0.15,
    "maintainability": 0.15
  }
}
```

**Nesta slice o adjudicator não consome `quality_weights` por caso** — ele continua usando a constante `WEIGHTS` da Perfect Battery. Os weights ficam armazenados no manifest para plug futuro. Mantém o blast radius zero contra a regressão de 220+ rivals tests.

## 9. Safety contract (invioláveis)

1. **No provider call** — `quick_test_command` / `full_test_command` rodam local. Sem `claude/codex/gemini/curl/wget/http(s)` admitidos (cert invariant 19).
2. **Voice + Cartografia intocados** — `forbidden_files_scope` deny-first; validador rejeita allowed scopes que toquem `atlas-desktop/src/voice/` ou `atlas-cartografia/` (cert invariant 20).
3. **`.pyc` tracked = blocker** — via `WorkspaceHygieneService` (canônico).
4. **Scope guard** — qualquer write fora do `allowed_files_scope` é hard blocker.
5. **External rivals continua locked** — esta cert nunca destrava `external_rivals_certification` (cert invariant 11).
6. **`claim_level == 'case_result_only'`** — corpus nunca emite global_claim (cert invariants 10 + 11 + 16).
7. **Dry-run nunca chama provider** — modo `local_fake` é a única superfície wired desta slice; real multi-case é honestly pending.
8. **Replay obrigatório** — cada plan emite `replay_manifest` reproduzível com `plan_hash` determinístico.
9. **Nenhuma alteração no Adjudicator** — Perfect Battery v1 continua intocado.

## 10. Certificação (22 invariants)

`AtlasForgeRivalsProviderArenaCorpusCertification::evaluate` avalia:

1. `release_has_exactly_twelve_cases`
2. `all_case_ids_unique`
3. `every_case_has_valid_primary_category`
4. `every_case_has_objective_business_rule_acceptance`
5. `every_case_has_allowed_and_forbidden_files_scope`
6. `every_case_has_quick_and_full_test_command`
7. `every_case_quality_weights_sum_to_one`
8. `every_case_invalid_if_has_hard_gates`
9. `every_case_fixture_seed_path_exists_on_disk`
10. `no_case_admits_synthetic_score`
11. `no_case_unlocks_external_rivals_certification`
12. `release_covers_all_eight_canonical_categories`
13. `frontend_case_set_returns_only_frontend_cases`
14. `backend_case_set_returns_only_backend_cases`
15. `architecture_case_set_returns_only_architecture_or_refactor`
16. `quick_case_set_has_exactly_three_cases_and_no_global_claim`
17. `individual_case_id_resolves_to_one_manifest`
18. `aggregate_manifest_is_deterministic_and_hashable`
19. `no_case_command_invokes_external_provider`
20. `no_case_scope_leaks_to_voice_or_cartografia`

Status `available` ⇔ todos os 20 verdes **e** artifacts presentes (service classes, command, fixture runner, planner, cases action, este doc, seed root).

## 11. Comandos canon

```bash
# Cert + smoke
php artisan test --filter='ProviderArenaCorpus'

# Lista corpus
php artisan atlas:forge:rivals cases --case-set=release --json --strict
php artisan atlas:forge:rivals cases --case-set=quick --json --strict
php artisan atlas:forge:rivals cases --case=backend-pagination-off-by-one --json --strict
php artisan atlas:forge:rivals cases --task-category=integration --json --strict

# Arena dry-run corpus
php artisan atlas:forge:rivals run-arena \
  --arm-a=atlas_forge --arm-a-model=sonnet \
  --arm-b=claude_code --arm-b-model=sonnet \
  --mode=local_fake --case-set=quick \
  --json --strict
```

## 12. Limites explícitos desta slice

- **Modo real multi-case** (`fair` / `full_power` com corpus): **honestly blocked**. Slice futura.
- **Adjudicator consumindo quality_weights por caso**: armazenados, ainda não plugged.
- **UI desktop**: corpus é backend-only nesta slice.
- **Sub-corpus de Voice / Cartografia**: fora — DNA inviolável.
- **Tabela Postgres para corpus runs**: file-based, alinhado com o resto do Rivals atual.
- **Auto-execução do `quick_test_command`** pelo runner: o runner prepara o workspace; quem dispara o test é o arm (esta slice só faz dry-run).
- **Deep set (25+ casos)**: fora — slice futura.

## 13. Como adicionar um caso novo (passo a passo)

1. Crie a fixture em `storage/forge-rivals-corpus/<case_id>/seed/` com README.md + arquivos seed (código quebrado + teste falhando ou faltando).
2. Adicione o manifest em `AtlasForgeRivalsProviderArenaCorpusService::corpus()` respeitando os 22 campos.
3. Garanta que `claim_level == 'case_result_only'` e que `invalid_if` inclui os 3 hard gates canônicos.
4. Se o caso entra no preset `quick`, atualize `QUICK_CASE_IDS` (cuidado: cert invariant 16 trava em 3 casos).
5. Atualize a tabela de casos nesta doc.
6. Rode `php artisan test --filter='ProviderArenaCorpus'` — todos os 49+ tests devem ficar verdes.
7. Rode `php artisan atlas:forge:rivals cases --case=<id> --json --strict` para conferir o envelope.

## 14. Related docs

- `docs/engineering-knowledge-base/atlas-forge-rivals-perfect-battery-and-adjudicator-v1.md` — orquestrador `run-battery` + adjudicator determinístico que o corpus alimenta.
- `docs/engineering-knowledge-base/atlas-forge-rivals-provider-arena-core-v1.md` — `run-arena` e o arm registry.
- `docs/engineering-knowledge-base/atlas-forge-rivals-benchmark-strategy-v1.md` — estratégia que orienta o desenho do corpus.
- `docs/engineering-knowledge-base/atlas-forge-rivals-battery-modes-and-human-prompts-v1.md` — canon de `spec-perfect`, `human-normal`, `messy-real`, `enterprise-change`, `fair-mode`, `power-mode`, `provider-arena`, `atlas-power`, `category-battery` e `difficulty-ladder`.
- `docs/engineering-knowledge-base/atlas-forge-rivals-provider-performance-ledger-v1.md` — ledger de performance que consome os resultados do corpus.

## 15. Schema source of truth · Atlas Forge Rivals Schema Contract

`app/Services/Ai/Programming/ForgeRivals/Schema/AtlasForgeRivalsSchemaContractService.php` é o **único validador** que aprova um payload como "schema-conformant" no pipeline Provider Arena. Cinco serviços downstream (corpus, run-battery, evidence collector, adjudicator, report) podem manter seus próprios `SCHEMA_VERSION` strings para back-compat — mas quando aceitam ou emitem payload externo precisam invocar o contract.

### Schemas centralizados

| Schema           | Versão                                              | REQUIRED_FIELDS                                                                                         |
| ---------------- | --------------------------------------------------- | ------------------------------------------------------------------------------------------------------- |
| `corpus_case`    | `atlas.forge.rivals.provider_arena_corpus.v1`       | 22 release v1 + 7 difficulty block (29 totais).                                                         |
| `run_result`     | `atlas.forge.rivals.run_battery.v1`                 | `action, run_battery_schema_version, status, mode, external_provider_call, provider_tokens_spent`.     |
| `evidence_pack`  | `atlas.forge.rivals.evidence_pack.v2` (v1 aceito)   | `schema_version, run_id, manifest_path, atlas_paths, rival_paths`.                                      |
| `adjudication`   | `atlas.forge.rivals.adjudication.v2` (v1 aceito)    | `schema_version, run_id, winner, hard_failures, atlas_score, rival_score`.                              |
| `report`         | `atlas.forge.rivals.report.v3`                      | `schema_version, run_id, winner, atlas_score, rival_score, case_results, claim_status`.                 |
| `report.case[]`  | (subschema de report)                               | `case_id, task_category, atlas_score, rival_score, raw_score, difficulty_multiplier, difficulty_weighted_score`. |

### Difficulty canon L1..L5

| Level | Score | Multiplier (score/3.0) | Legacy alias | Uso típico                                  |
| ----- | ----- | ---------------------- | ------------ | ------------------------------------------- |
| L1    | 1.0   | 0.3333                 | easy         | Bug trivial, 1-2 linhas, ambiguidade zero.  |
| L2    | 2.0   | 0.6667                 | easy         | Mudança pequena, TDD ou variação UI simples.|
| L3    | 3.0   | 1.0 (**neutral**)      | medium       | Caso "padrão" — adjudicator default.        |
| L4    | 4.0   | 1.3333                 | medium       | Multi-arquivo com retry/timeout/coordenação.|
| L5    | 5.0   | 1.6667                 | hard         | Arquitetural com replay+hash+schema.        |

`ambiguity_level ∈ {low, medium, high}`. `risk_level ∈ {low, medium, high, critical}`. `planning_weight + execution_weight = 1.0 ± 0.01`.

### Fórmulas

- `difficulty_multiplier = difficulty_score / 3.0` (L3 == neutral 1.0).
- `difficulty_weighted_score = raw_score * difficulty_multiplier`.

### Fail-closed contract

- Real cases (proveniência `provider_arena_corpus`) **PRECISAM** ter difficulty block declarado em todos os 7 campos. `SchemaContractService::validateDifficultyBlock` recusa o caso senão.
- Manifests sem difficulty_score (cases legados / synthetic) caem em fallback L3=neutral no report, com flag observável; nunca poluem o weighted aggregate com valor inventado.

### Report integration

Cada entrada em `report.case_results` agora carrega:

```json
{
  "raw_score": {"atlas": 80.0, "rival": 60.0},
  "difficulty_multiplier": 0.3333,
  "difficulty_weighted_score": {"atlas": 26.66, "rival": 20.0}
}
```

Os campos legados `atlas_score` e `rival_score` continuam presentes (back-compat). O Adjudicator emite o difficulty block junto da adjudication quando o input traz `difficulty_block` (futuro plug); por enquanto, somente o report consome o multiplier — manter o blast radius previsível.

---

## Resumo

Corpus declarativo de 40 casos Release Matrix v1 cobrindo 8 categorias x 5 niveis L1..L5, 6 case sets, schema canonico expandido por caso, seeds reais com codigo quebrado + teste, replay manifest deterministico e cert de 22 invariants — tudo escopado para construir o instrumento de medicao da Provider Arena sem invocar provider.

## Papel no Atlas

Corpus é a régua que torna a Provider Arena uma medição reproduzível. Sem ele, comparações entre arms são apenas anedotas.

## Onde Se Encaixa

Sobre a Provider Arena Core (Slice 8) e sob o Perfect Battery (Slice 7). Não substitui nenhum, complementa. Cartografia e Voice são DNA inviolável — corpus jamais toca.

## Contratos

Schema canon `atlas.forge.rivals.provider_arena_corpus.v1` (release_v1). 22 campos por caso, validados via `validateManifest`. Detalhe completo nas seções 4 e 5.

## Fluxo

`cases` → planner resolve filtros → manifest declarativo → (opcional) fixture runner prepara workspace por caso → arm dispara seu `quick_test_command` → evidence pack ↔ replay manifest. Em dry-run o ciclo termina no plano com `plan_hash` determinístico.

## Regras para IA

Adicionar caso novo só com `allowed_files_scope` distantes de Voice/Cartografia, `quick_test_command` 100% local, `quality_weights` somando 1.0, `claim_level == 'case_result_only'`, hard gates canônicos em `invalid_if`. Validador bloqueia o que escapar.

## Escopo de Implementacao

40 casos + 6 case sets + difficulty ladder L1..L5 + fixture runner + planner + cases action + cert (22 invariants) + tests + doc canônica. Loop real multi-case fica governado pelo run-battery e pelo report/replay multi-case.

## Dependencias

`atlas-forge-rivals-perfect-battery-and-adjudicator-v1`, `atlas-forge-rivals-provider-arena-core-v1`, `atlas-forge-rivals-benchmark-strategy-v1`. `WorkspaceHygieneService` canônico. Arms registry existente (`atlas_forge`, `claude_code`, `codex_cli`, `gemini_cli`, `scripted_runner`, `manual_runner`, `future_runner`).

## Evidencias

`AtlasForgeRivalsProviderArenaCorpusCertification::evaluate` retorna `available` (20/20) com artefatos presentes. 49+ tests verdes em `php artisan test --filter='ProviderArenaCorpus'`. `corpus_content_hash` sha256 reproduzível byte a byte entre hosts.

## Riscos

Risco médio: o corpus declara intenções; arms reais podem violar `allowed_files_scope`. Mitigação: `checkScope` no fixture runner + `forbidden_files_scope` deny-first + cert validates a cada alteração canônica + hard gates obrigatórios em `invalid_if` (cert invariant 8).

## Exemplos

```bash
php artisan atlas:forge:rivals cases --case-set=release --json --strict
php artisan atlas:forge:rivals cases --case=performance-n-plus-one-query --json --strict
php artisan atlas:forge:rivals run-arena --arm-a=atlas_forge --arm-a-model=sonnet --arm-b=claude_code --arm-b-model=sonnet --mode=local_fake --case-set=quick --json --strict
```

## Proximas Acoes

1. Plugar `quality_weights` por caso no adjudicator (slice futura).
2. Implementar loop real multi-case no `run-arena` (atualmente honestly pending).
3. Adicionar deep_set (25+ casos) para slice futura, com bias controlado para `integration` e `performance_edge_case`.
