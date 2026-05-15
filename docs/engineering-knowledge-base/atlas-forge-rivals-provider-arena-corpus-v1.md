---
id: atlas-forge-rivals-provider-arena-corpus-v1
type: engineering_knowledge
title: Atlas Forge Rivals · Provider Arena Corpus & Real Battery Cases v1
status: active
category: rivals
priority: 90
summary: Corpus canon de 12 casos de programacao reais para a Provider Arena medir qualidade por categoria comparando arms (Atlas Forge, Claude Code, Codex CLI, Gemini CLI, etc.) sem invocar provider em dry-run.
tags:
  - atlas-forge-rivals
  - provider-arena
  - corpus
  - quality-gates
  - replayable
  - evidence-pack
capabilities:
  - provider_arena_corpus_resolution
  - corpus_case_filtering
  - corpus_fixture_isolation
  - corpus_replay_manifest
decisions:
  - Doze casos canon distribuidos em nove task categories cobrindo frontend, backend, bugfix, tests, refactor, architecture, docs e security.
  - Seis case sets canonical (quick, release, frontend, backend, bugfix, architecture) com regras de filtro deterministicas.
  - Fixture seed mora em storage/forge-rivals-corpus/<case_id>/seed para escalar sem inflar PHP.
  - Flag --case-set coexiste com --preset legacy; ganha quando ambos passados; zero quebra de back-compat.
  - Adjudicator NAO consome weights por caso nesta slice; weights ficam armazenados para plug futuro.
  - Modo real multi-case fica honestly pending; apenas local_fake dry-run e wired.
maintenance:
  - Manter cobertura igualmente distribuida quando casos novos sao adicionados.
  - Toda alteracao em corpus exige atualizar AtlasForgeRivalsProviderArenaCorpusCertification (15 invariants).
  - Seed dirs novos precisam de README.md e de pelo menos um stub plausivel.
  - Adicionar caso novo nao quebra os 32 tests automaticamente; rodar test --filter='ProviderArenaCorpus' antes do commit.
related_paths:
  - app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsProviderArenaCorpusService.php
  - app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsCorpusFixtureRunnerService.php
  - app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsCorpusPlannerService.php
  - app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsCorpusCasesActionService.php
  - app/Services/Ai/Kernel/Architecture/AtlasForgeRivalsProviderArenaCorpusCertification.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsArenaRunService.php
  - app/Console/Commands/AtlasForgeRivalsCommand.php
  - storage/forge-rivals-corpus
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-forge-rivals-provider-arena-corpus-v1
graph_title: Atlas Forge Rivals · Provider Arena Corpus v1
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-forge-rivals-perfect-battery-and-adjudicator-v1
graph_status: active
graph_source: repo
owner: rivals
repo_paths:
  - app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsProviderArenaCorpusService.php
  - app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsCorpusFixtureRunnerService.php
  - app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsCorpusPlannerService.php
  - app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsCorpusCasesActionService.php
  - app/Services/Ai/Kernel/Architecture/AtlasForgeRivalsProviderArenaCorpusCertification.php
  - storage/forge-rivals-corpus
allowed_changes:
  - Adicionar caso novo declarando os 16 campos canon e seed_dir minimo.
  - Atualizar tabela de casos quando novo caso entrar.
  - Atualizar 15 invariants quando contrato canon mudar.
forbidden_changes:
  - Adicionar caso que dispare provider em quick_test_command ou full_test_command.
  - Adicionar caso que toque atlas-desktop/src/voice/* ou atlas-cartografia/* no allowed_files_scope.
  - Declarar caso valido sem allowed_files_scope, invalid_if ou quick_test_command preenchidos.
  - Promover modo real multi-case sem evidence pack completo e replay verde.
depends_on:
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
required_tests:
  - "php artisan test --filter='ProviderArenaCorpus'"
  - "php artisan atlas:forge:rivals cases --json --strict"
requires_evidence: true
risk_level: medium
visual_tags:
  - system
  - corpus
  - rivals
ai_entrypoints:
  - Leia Por que este corpus existe, Os 12 casos, Schema do case manifest e Safety contract antes de adicionar caso novo ou rodar arena com corpus.
ai_usage_notes:
  - Use --case-set para baterias; --case para depurar um caso isolado.
  - Em local_fake o resultado e o plano multi-case replayable; nao tenta executar quick_test_command automaticamente.
quality_gates:
  - "php artisan test --filter='ProviderArenaCorpus'"
  - "php artisan atlas:forge:rivals cases --case-set=release --json --strict"
failure_modes:
  - Caso novo dispara provider via curl/claude/codex/gemini no quick_test_command.
  - allowed_files_scope vaza para Voice ou Cartografia.
  - Weights nao somam 1.0 e validador deixa passar.
observability_signals:
  - atlas_forge_rivals_provider_arena_corpus_certification status available
  - artisan test --filter=ProviderArenaCorpus verde com >=22 testes
next_actions:
  - Plugar quality_gates.weights por caso no adjudicator em slice futura.
  - Implementar loop real multi-case em run-arena (atualmente honestly pending).
---

# Atlas Forge Rivals · Provider Arena Corpus & Real Battery Cases v1

> Schema canon: `atlas.forge.rivals.provider_arena_corpus.v1`
> Certificação: `atlas_forge_rivals_provider_arena_corpus_certification` (15 invariants)
> Comando canon: `php artisan atlas:forge:rivals cases --json --strict`

## 1. Por quê este corpus existe

A Provider Arena hoje (Slice 8) sabe pôr `arm_a vs arm_b` (Atlas Forge, Claude Code, Codex CLI, Gemini CLI…) frente a frente para uma `task_category`, mas só tinha **um caso fixo** (`atlas-fair-claude-baseline-case-01`). Sem corpus real não dá pra comparar:

- Atlas Forge vs Claude Code em **frontend** sem usar os mesmos casos.
- Sonnet vs Opus em **backend** com a mesma régua.
- Forge fair vs Forge full_power em **bugfix**.
- Forge usando Sonnet vs Forge usando Codex em **architecture**.
- Codex CLI vs Gemini CLI em **security**.

Este corpus é o **instrumento de medição**, não um benchmark com vencedor pré-definido. Resultado sintético nunca vira claim. Evidence/replay/scope-guard são invioláveis. `external_rivals_certification` permanece blocked.

## 2. Escopo

- 12 casos canon distribuídos em 9 task categories.
- 6 case sets (`quick`, `release`, `frontend`, `backend`, `bugfix`, `architecture`).
- Cada caso tem **16 campos** declarativos validados por schema.
- Cada caso tem seed dir em `storage/forge-rivals-corpus/<case_id>/seed/`.
- Integração com `cases`, `run-arena` via flags `--case`, `--case-set`, `--task-category`.
- Dry-run / `--mode=local_fake` emite plano multi-case replayable sem provider.
- Modo real multi-case está **explicitamente fora de escopo desta slice** (bloqueio honesto).

## 3. Os 12 casos

| Case ID                                          | Categoria     | Role focus              | Resumo                                                                |
| ------------------------------------------------ | ------------- | ----------------------- | --------------------------------------------------------------------- |
| `arena-frontend-button-loading-state`            | frontend      | ui_correctness          | Estado loading no botão Submit sem regressão.                         |
| `arena-frontend-list-empty-state`                | frontend      | accessibility           | Empty state acessível anunciado por aria-live.                        |
| `arena-backend-pagination-cursor`                | backend       | api_correctness         | Substituir paginação offset por cursor opaco.                         |
| `arena-backend-rate-limit-window`                | backend       | reliability             | Sliding-window rate limit 60 req/min.                                 |
| `arena-bugfix-off-by-one-paginator`              | bugfix        | minimal_diff            | Off-by-one no total_pages.                                            |
| `arena-bugfix-null-pointer-in-formatter`         | bugfix        | regression_prevention   | TypeError em formatter de timestamp nullable.                         |
| `arena-tests-missing-edge-case`                  | tests         | coverage_quality        | Borda faltante no parser sem mexer produção.                          |
| `arena-tests-flaky-time-dependent`               | tests         | determinism             | Eliminar flaky time-dependent via clock injetável.                    |
| `arena-refactor-extract-pure-function`           | refactor      | cohesion                | Extrair priority calculator do controller.                            |
| `arena-architecture-module-boundary-leak`        | architecture  | boundary_integrity      | Eliminar leak de Eloquent model entre módulos.                        |
| `arena-docs-readme-quickstart`                   | docs          | clarity                 | Seção Quickstart no README do módulo Captures.                        |
| `arena-security-input-validation-traversal`      | security      | safety                  | Bloquear 6 payloads de path traversal no download de evidence.        |

## 4. Schema do case manifest

Cada caso declara **exatamente** estes 16 campos (validados por `AtlasForgeRivalsProviderArenaCorpusService::validateManifest`):

```php
[
    'case_id'              => string,                     // arena-<category>-<slug>
    'task_category'        => string,                     // frontend|backend|bugfix|tests|refactor|architecture|docs|performance|security
    'role_focus'           => string,                     // dimensão dominante do scoring
    'objective'            => string,                     // o que o arm precisa entregar
    'business_rule'        => string,                     // por que essa entrega importa (incidente real / dor reportada)
    'acceptance_criteria'  => list<string>,               // checklist objetiva
    'allowed_files_scope'  => list<string>,               // globs writeable
    'forbidden_files_scope'=> list<string>,               // globs deny-first (defence in depth)
    'setup_fixture'        => ['seed_dir' => string, 'base_files' => list<string>],
    'expected_signal'      => string,                     // o que indica que o arm acertou
    'quick_test_command'   => string,                     // comando local (php artisan test --filter=… / vitest run …)
    'full_test_command'    => string,                     // comando exaustivo
    'quality_gates'        => [
        'dimensions' => list<string>,
        'weights'    => array<string,float>,              // soma == 1.0 ± 0.01
    ],
    'timeout_policy'       => [
        'wall_clock_seconds_max'      => int,
        'per_stage_seconds_max'       => int,
        'hard_kill_after_seconds'     => int,
    ],
    'expected_evidence'    => list<string>,               // artefatos que o evidence pack deve conter
    'invalid_if'           => list<string>,               // condições terminais de invalidação
]
```

## 5. Case sets

| Case set       | Resolução                                                           |
| -------------- | ------------------------------------------------------------------- |
| `quick`        | 3 casos curtos (button-loading + pagination-cursor + off-by-one).    |
| `release`      | Todos os 12 casos.                                                  |
| `frontend`     | Apenas `task_category=frontend` (2 casos).                          |
| `backend`      | Apenas `task_category=backend` (2 casos).                           |
| `bugfix`       | Apenas `task_category=bugfix` (2 casos).                            |
| `architecture` | `architecture` + `refactor` + `docs` (3 casos).                     |

## 6. Fixture runner

`AtlasForgeRivalsCorpusFixtureRunnerService` prepara workspace **isolado por run e por case**:

```
<runs_root>/<run_id>/corpus/<case_id>/workspace/
```

Reusa `WorkspaceHygieneService` (canônico) para detectar `.pyc` / `__pycache__` tracked via `git ls-files`. Hard blockers honestos:

- `seed_dir_missing:<rel-path>` — seed dir não foi entregue ainda.
- `unknown_case_id:<id>` — case não existe no corpus.
- `invalid_manifest:<reason>` — schema do case violado em runtime.
- `blocked_tracked_python_bytecode:<sample>` — repo tem `.pyc` tracked.
- `scope_violation:<path>` — caminho fora do `allowed_files_scope`.
- `forbidden_path_touched:<path>` — caminho casa com `forbidden_files_scope`.

`cleanup($runId, $caseId)` remove apenas o workspace do caso; evidence digest fica preservado pelo battery layer.

## 7. Integração com `cases` e `run-arena`

### Action `cases` (canon)

```bash
# Lista corpus inteiro (default = quick preset)
php artisan atlas:forge:rivals cases --json --strict

# Filtra por case set
php artisan atlas:forge:rivals cases --case-set=release --json --strict

# Manifest de um caso específico
php artisan atlas:forge:rivals cases --case=arena-bugfix-off-by-one-paginator --json --strict

# Filtra por categoria
php artisan atlas:forge:rivals cases --task-category=security --json --strict
```

Resposta inclui:

- `snapshot` — contagens por categoria, case ids, case sets.
- `applied_filters` — registro auditável do que foi aplicado.
- `cases` — lista de manifests resumidos (16 campos cada).
- `replay_manifest` — manifest declarativo para reproduzir a query.

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

## 8. Quality gates e weights

Cada caso declara `quality_gates.dimensions` (lista priorizada) e `quality_gates.weights` (soma 1.0). Por exemplo:

```json
{
  "dimensions": ["ui_correctness", "accessibility", "visual_polish", "maintainability"],
  "weights": {
    "ui_correctness": 0.40,
    "accessibility": 0.25,
    "visual_polish": 0.20,
    "maintainability": 0.15
  }
}
```

**Nesta slice o adjudicator não consome os weights por caso** — ele continua usando a constante `WEIGHTS` da Perfect Battery. Os weights ficam armazenados no manifest para plug futuro. Mantém o blast radius zero contra a regressão de 186 tests do baseline rivals.

## 9. Safety contract (invioláveis)

1. **No provider call** — `quick_test_command` / `full_test_command` rodam local. Sem `claude/codex/gemini/curl/wget/http(s)` admitidos.
2. **Voice + Cartografia intocados** — `forbidden_files_scope` deny-first; validador rejeita allowed scopes que toquem `atlas-desktop/src/voice/` ou `atlas-cartografia/`.
3. **`.pyc` tracked = blocker** — via `WorkspaceHygieneService` (canônico).
4. **Scope guard** — qualquer write fora do `allowed_files_scope` é hard blocker.
5. **External rivals continua locked** — esta cert nunca destrava `external_rivals_certification`.
6. **Dry-run nunca chama provider** — modo `local_fake` é a única superfície wired desta slice; real multi-case é honestly pending.
7. **Replay obrigatório** — cada plan emite `replay_manifest` reproduzível byte a byte.
8. **Nenhuma alteração no Adjudicator** — Perfect Battery v1 continua intocado.

## 10. Certificação (15 invariants)

`AtlasForgeRivalsProviderArenaCorpusCertification` evaluate avalia:

1. `corpus_has_min_twelve_cases`
2. `every_case_has_required_fields`
3. `every_case_has_quick_test_command`
4. `every_case_has_allowed_files_scope`
5. `every_case_has_invalid_if`
6. `case_sets_cover_all_six_presets`
7. `quick_preset_has_three_to_four_cases`
8. `release_preset_includes_all_cases`
9. `frontend_preset_only_frontend`
10. `backend_preset_only_backend`
11. `bugfix_preset_only_bugfix`
12. `architecture_preset_includes_architecture_refactor_docs`
13. `fixture_runner_uses_workspace_hygiene`
14. `no_case_calls_external_provider`
15. `no_case_unlocks_external_rivals`

Status `available` ⇔ todos os 15 verdes **e** artifacts presentes (service classes, command, fixture runner, planner, cases action, este doc, seed root).

## 11. Comandos canon

```bash
# Cert + smoke
php artisan test --filter='AtlasForgeRivals|RivalsForge|AtlasRivals|ForgeNativeRivals|FairClaudePolicy|ProviderArenaCorpus'

# Lista corpus
php artisan atlas:forge:rivals cases --json --strict
php artisan atlas:forge:rivals cases --case-set=quick --json --strict
php artisan atlas:forge:rivals cases --case-set=release --json --strict
php artisan atlas:forge:rivals cases --case=arena-frontend-button-loading-state --json --strict

# Arena dry-run corpus
php artisan atlas:forge:rivals run-arena \
  --arm-a=atlas_forge --arm-a-model=sonnet \
  --arm-b=claude_code --arm-b-model=sonnet \
  --mode=local_fake --case-set=quick \
  --json --strict
```

## 12. Limites explícitos desta slice

- **Modo real multi-case** (`fair` / `full_power` com corpus): **honestly blocked**. Slice futura.
- **Adjudicator consumindo weights por caso**: armazenados, ainda não plugged.
- **UI desktop**: corpus é backend-only nesta slice.
- **Sub-corpus de Voice / Cartografia**: fora — DNA inviolável.
- **Tabela Postgres para corpus runs**: file-based, alinhado com o resto do Rivals atual.
- **Auto-execução do `quick_test_command`** pelo runner: o runner prepara o workspace; quem dispara o test é o arm (esta slice só faz dry-run).

## 13. Como adicionar um caso novo (passo a passo)

1. Adicione o manifest em `AtlasForgeRivalsProviderArenaCorpusService::corpus()` respeitando os 16 campos.
2. Crie `storage/forge-rivals-corpus/<case_id>/seed/README.md` + arquivos seed mínimos.
3. Atualize `QUICK_CASE_IDS` se o caso for short o suficiente para entrar no preset `quick`.
4. Atualize esta doc (tabela de casos + qualquer particularidade).
5. Rode `php artisan test --filter='ProviderArenaCorpus'` — todos os 22+ tests devem ficar verdes.
6. Rode `php artisan atlas:forge:rivals cases --case=<id> --json --strict` para conferir o envelope.

## 14. Related docs

- `docs/engineering-knowledge-base/atlas-forge-rivals-perfect-battery-and-adjudicator-v1.md` — orquestrador `run-battery` + adjudicator determinístico que o corpus alimenta.
- `docs/engineering-knowledge-base/atlas-forge-rivals-provider-arena-core-v1.md` — `run-arena` e o arm registry.
- `docs/engineering-knowledge-base/atlas-forge-rivals-evidence-replay-adjudicator-hardening-v2.md` — contrato de evidence/replay que cada caso herda.

---

## Resumo

Corpus declarativo de 12 casos em 9 categorias, 6 case sets, fixture runner com scope guard, integração com `run-arena` em dry-run e cert de 15 invariants — tudo escopado para construir o instrumento de medição da Provider Arena sem invocar provider.

## Papel no Atlas

Corpus é a régua que torna a Provider Arena uma medição reproduzível. Sem ele, comparações entre arms são apenas anedotas.

## Onde Se Encaixa

Sobre a Provider Arena Core (Slice 8) e sob o Perfect Battery (Slice 7). Não substitui nenhum, complementa. Cartografia e Voice são DNA inviolável — corpus jamais toca.

## Contratos

Schema canon `atlas.forge.rivals.provider_arena_corpus.v1`. 16 campos por caso, validados via `validateManifest`. Detalhe completo nas seções 4 e 5.

## Fluxo

`cases` → planner resolve filtros → manifest declarativo → (opcional) fixture runner prepara workspace por caso → arm dispara seu `quick_test_command` → evidence pack ↔ replay manifest. Em dry-run o ciclo termina no plano.

## Regras para IA

Adicionar caso novo só com `allowed_files_scope` distantes de Voice/Cartografia, `quick_test_command` 100% local, weights somando 1.0. Validador bloqueia o que escapar.

## Escopo de Implementacao

12 casos + 6 case sets + fixture runner + planner + cases action + cert (15 invariants) + 32 tests + doc canônica. Loop real multi-case fora de escopo (slice futura).

## Dependencias

`atlas-forge-rivals-perfect-battery-and-adjudicator-v1`, `atlas-forge-rivals-provider-arena-core-v1`. `WorkspaceHygieneService` canônico. Arms registry existente (`atlas_forge`, `claude_code`, `codex_cli`, `gemini_cli`).

## Evidencias

`AtlasForgeRivalsProviderArenaCorpusCertification::evaluate` retorna `available` (15/15) com artefatos presentes. 32 tests verdes em `php artisan test --filter='ProviderArenaCorpus'`.

## Riscos

Risco médio: o corpus declara intenções; arms reais podem violar `allowed_files_scope`. Mitigação: `checkScope` no fixture runner + `forbidden_files_scope` deny-first + cert validates a cada alteração canônica.

## Exemplos

```bash
php artisan atlas:forge:rivals cases --case-set=quick --json --strict
php artisan atlas:forge:rivals run-arena --arm-a=atlas_forge --arm-a-model=sonnet --arm-b=claude_code --arm-b-model=sonnet --mode=local_fake --case-set=quick --json --strict
```

## Proximas Acoes

1. Plugar `quality_gates.weights` por caso no adjudicator (slice futura).
2. Implementar loop real multi-case no `run-arena` (atualmente honestly pending).
3. Adicionar 6+ casos de `performance` para fechar a coluna ainda zerada no by_task_category.
