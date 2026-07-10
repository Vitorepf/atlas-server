---
id: atlas-rivals-phase-a-enterprise-report-v1
type: engineering_knowledge
title: Atlas Rivals — Phase A Enterprise Report v1
status: active
category: programming
priority: 98
summary: Contrato do relatório empresarial consolidado da Fase A — sempre 10 suites, faces modelo×modelo e Atlas×modelo, agregado nunca claim.
tags:
  - atlas
  - rivals
  - enterprise-report
  - fase-a
capabilities:
  - rivals_enterprise_report
decisions:
  - Agregado dos 10 benches nunca tem claim_allowed=true.
  - suite_rows tem sempre N=count(benchmarks.repos) linhas (not_run se faltar run).
  - Tokens/tempo ausentes viram status missing_data, nunca 0/0 silencioso.
  - Provider real da Fase A é Hermes+Verboo (verboo_kimi_k2_7).
maintenance:
  - Atualizar quando o schema atlas.rivals2.enterprise_report.v1 mudar.
related_paths:
  - app/Services/Ai/Rivals/Core/EnterpriseReportBuilder.php
  - app/Services/Ai/Rivals/Support/SchemaContract.php
  - app/Console/Commands/AtlasRivalsCommand.php
  - docs/superpowers/plans/2026-07-10-rivals-phase-a-finalize.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-rivals-phase-a-enterprise-report-v1
graph_title: Atlas Rivals Phase A Enterprise Report v1
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-rivals-claims-and-reporting-v1
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - app/Services/Ai/Rivals/Core/EnterpriseReportBuilder.php
  - docs/engineering-knowledge-base/atlas-rivals-phase-a-enterprise-report-v1.md
allowed_changes:
  - Estender seções do relatório e eixos com testes.
forbidden_changes:
  - Permitir claim_allowed=true no agregado.
  - Emitir consolidado com menos de 10 suite_rows.
depends_on:
  - atlas-rivals-claims-and-reporting-v1
  - atlas-rivals-product-v1
flows_to:
  - atlas-rivals-operator-runbook-v1
unlocks:
  - rivals_fase_a_enterprise_report
governs:
  - rivals_enterprise_report
evidence:
  - php artisan atlas:rivals report-enterprise --json
required_tests:
  - php artisan test --filter=EnterpriseReportBuilderTest
requires_evidence: true
risk_level: medium
next_actions:
  - Completar Waves 2–6 do plano 2026-07-10-rivals-phase-a-finalize.
---

# Atlas Rivals — Phase A Enterprise Report v1

## Resumo

Contrato do **relatório empresarial consolidado** da Fase A: uma página (JSON + Markdown + CSV) com as 10 suites, capa executiva, face modelo×modelo e face Atlas×modelo. O agregado é **sempre non-claim**.

## Papel no Atlas

Substitui o `report-all` técnico como artefato legível de produto. Claims continuam por run/escopo (`internal_claim_allowed` / `public_claim_allowed`).

## Onde Se Encaixa

- Builder: `App\Services\Ai\Rivals\Core\EnterpriseReportBuilder`
- CLI: `php artisan atlas:rivals report-enterprise --json` (read-only)
- Artefatos: `storage/atlas/rivals/enterprise/{report.json,report.md,report.csv}`
- Schema: `atlas.rivals2.enterprise_report.v1`

## Contratos

### Schema obrigatório

`schema_version`, `built_at`, `report_hash`, `claim_allowed` (**false**), `claim_blockers`, `executive_summary`, `suite_rows` (N=10), `model_matrix`, `atlas_uplift`, `gaps`, `included_run_ids`, `excluded_run_ids`.

### Status por suite

`ok` | `failed` | `blocked` | `missing_data` | `not_run`

`missing_data` quando `pipeline_valid` mas tokens/tempo não estão presentes de forma honesta.

### Faces

1. **modelo × modelo** — `model_vs_model` se ≥2 models bare; senão `single_model_battery`.
2. **Atlas × modelo** — uma entrada por `config('atlas_rivals.uplift_families')` (5 families).

### Custo Verboo

`$0` só com `cost_basis=verboo_subscription_marginal` **e** usage presente. Sem usage ⇒ `missing_data`.

## Fluxo

```bash
php artisan atlas:rivals report-enterprise --json
```

## Regras para IA

- Não promover média dos 10 a claim.
- Não rodar battery execute / native spend fora do Mac com Hermes+Verboo.
- Não misturar com `atlas-rivals-one-shot-enterprise-evaluation-v1` (deprecated / outra camada).

## Escopo de Implementacao

Wave 1 do plano `docs/superpowers/plans/2026-07-10-rivals-phase-a-finalize.md`.

## Dependencias

claims-and-reporting-v1, product-v1, SuiteRegistry, ReportBuilder rows.

## Evidencias

`EnterpriseReportBuilderTest`, `AtlasRivalsCommandTest::test_report_enterprise_*`.

## Riscos

Relatório “bonito” com dados faltando — mitigado por `missing_data` + gaps.

## Exemplos

```bash
php artisan atlas:rivals report-enterprise --json
```

## Proximas Acoes

Waves 2–6 (per-run markdown rico, usage capture, battery dry-run, faces, closure).
