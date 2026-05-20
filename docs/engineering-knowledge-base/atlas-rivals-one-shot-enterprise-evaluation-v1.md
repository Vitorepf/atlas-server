---
id: atlas-rivals-one-shot-enterprise-evaluation-v1
type: engineering_knowledge
title: Atlas Rivals One-Shot Enterprise Evaluation v1
status: active
category: programming-forge
priority: 100
summary: Rubrica e avaliacao canonica que pontuam uma entrega one-shot enterprise do Atlas Code. Qualidade extrema e objetivo primario; tempo bruto e metrica secundaria que jamais decide o veredito. Evaluation e diagnostica local — nunca chama provider externo e nunca promove o claim Rivals.
tags:
  - atlas
  - rivals
  - one-shot
  - enterprise
  - evaluation
  - forge
capabilities:
  - rivals_one_shot_enterprise_rubric
  - rivals_one_shot_enterprise_evaluation
  - rivals_one_shot_enterprise_evaluation_certification
decisions:
  - O objetivo do Rivals nao e medir velocidade bruta — e medir entrega one-shot enterprise.
  - Qualidade pode compensar tempo. Tempo NUNCA pode compensar qualidade ruim.
  - Score e diagnostico local. Apenas bateria provider real, aprovada e validada, pode virar claim externo.
  - Esta camada e separada de external_rivals_certification e jamais a desbloqueia.
  - Synthetic score e sempre proibido como claim.
maintenance:
  - Atualize este doc antes de mexer em rubrica, evaluation, CLI ou completion audit dessa camada.
  - Mantenha como pagina-mae dos services AtlasRivalsOneShotEnterprise*.
related_paths:
  - docs/engineering-knowledge-base/atlas-forge-native-rivals-protocol-v1.md
  - docs/engineering-knowledge-base/atlas-programming-forge-flow.md
  - docs/engineering-knowledge-base/atlas-code-forge-fast-path-v1.md
  - docs/engineering-knowledge-base/atlas-code-forge-review-completion-gate-v1.md
  - docs/engineering-knowledge-base/domains/programming-professional-completion-audit.md
  - app/Services/Ai/Programming/AtlasRivalsOneShotEnterpriseRubricService.php
  - app/Services/Ai/Programming/AtlasRivalsOneShotEnterpriseEvaluationService.php
  - app/Console/Commands/AtlasProgrammingRivalsOneShotEvaluateCommand.php
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-rivals-one-shot-enterprise-evaluation-v1
graph_title: Atlas Rivals One-Shot Enterprise Evaluation v1
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-forge-native-rivals-protocol-v1
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-rivals-one-shot-enterprise-evaluation-v1.md
allowed_changes:
  - Adicionar dimensoes, hard fails ou exemplos quando o padrao enterprise evoluir.
  - Endurecer rubrica (nunca afrouxar).
forbidden_changes:
  - Deixar tempo bruto dominar o score.
  - Permitir score sintetico como claim.
  - Liberar external_rivals_certification a partir desta camada.
  - Marcar Atlas como vencedor sem bateria provider real.
depends_on:
  - atlas-forge-native-rivals-protocol-v1
  - atlas-programming-forge-flow
  - atlas-code-forge-fast-path-v1
flows_to:
  - programming-professional-completion-audit
unlocks:
  - rivals-one-shot-enterprise-evaluation
governs:
  - rivals_scoring_local_diagnostic
  - rivals_one_shot_quality_contract
evidence:
  - docs/engineering-knowledge-base/atlas-rivals-one-shot-enterprise-evaluation-v1.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:programming:rivals-one-shot-evaluate --json --strict"
  - "php artisan atlas:programming:completion-audit --json"
requires_evidence: true
risk_level: critical
visual_tags:
  - rivals
  - one-shot
  - quality
ai_entrypoints:
  - Antes de propor metrica nova para Rivals, ler este doc.
  - Score so e claim quando ha bateria provider real validada.
ai_usage_notes:
  - Tempo entra como metrica observada, nunca como criterio decisivo.
  - Hard fail invalida claim mesmo com soma 100.
quality_gates:
  - docs-health
  - architecture-validate
  - rivals-one-shot-evaluate-strict
failure_modes:
  - Usar tempo para superar qualidade ruim.
  - Aceitar score sintetico como claim.
  - Confundir score diagnostico com vencedor Rivals.
observability_signals:
  - rivals_one_shot_enterprise_evaluation_certification.status
  - rubric.score_weights_total
  - evaluation.grade
  - evaluation.hard_fails
next_actions:
  - Rodar `php artisan atlas:programming:rivals-one-shot-evaluate --case=<id> --json --strict` para diagnosticar a entrega.
  - Atualizar evidence pack quando bateria real existir (fora do escopo desta camada).
---
# Atlas Rivals One-Shot Enterprise Evaluation v1

## Resumo

Rivals nao mede velocidade bruta. Mede se uma entrega one-shot do Atlas
Code e realmente enterprise: regra de negocio correta, aderente a docs
canonicos, completa em uma rodada, com testes reais, governanca Forge,
seguranca operacional e baixo custo de revisao. Tempo entra como
observacao secundaria — quebra empate, mas nunca pode dominar o score.

## Papel no Atlas

Esta camada protege o Rivals de virar "race de velocidade". O rubric
canoniza o que e qualidade one-shot. O evaluation calcula score
diagnostico a partir de manifest + evidence local. O completion audit
expoe a certification em eixo proprio, separado de
`external_rivals_certification`. Sem bateria provider real,
**evaluation continua diagnostico** e nunca promove claim.

## Onde Se Encaixa

- Acima: `atlas-forge-native-rivals-protocol-v1.md` (Atlas arm = Forge obrigatorio).
- Lado: `atlas-code-forge-fast-path-v1.md`, `atlas-code-forge-review-completion-gate-v1.md`.
- Consumido por: `programming-professional-completion-audit.md`,
  `ProgrammingProfessionalCompletionAuditService::rivalsOneShotEnterpriseEvaluationCertification()`.

## Contratos

| Schema | Quem produz | Quem consome |
|--------|-------------|--------------|
| `atlas.programming.rivals_one_shot_enterprise_rubric.v1` | `AtlasRivalsOneShotEnterpriseRubricService` | evaluation, completion audit, CLI |
| `atlas.programming.rivals_one_shot_enterprise_evaluation.v1` | `AtlasRivalsOneShotEnterpriseEvaluationService` | CLI, completion audit |
| `atlas.programming.rivals_one_shot_enterprise_evaluation_certification.v1` | `ProgrammingProfessionalCompletionAuditService` | `atlas:programming:completion-audit` |

### Rubrica (resumo)

- 13 dimensoes pesadas (peso total = 100).
- `primary_objective = one_shot_enterprise_quality`.
- `speed_is_secondary = true`, `quality_can_compensate_time = true`,
  `time_cannot_compensate_quality = true`.
- Hard fails globais invalidam claim mesmo com soma 100.

### Grade

- `enterprise_ready` se score >= 90 e nenhum hard fail.
- `review_required` se score entre 75 e 89 sem hard fail.
- `not_enterprise_ready` se score < 75 sem hard fail.
- `invalid` se qualquer hard fail global ocorrer.

### Hard fails globais

- `atlas_arm_not_forge`
- `missing_replay_manifest`
- `missing_acceptance_gates`
- `missing_business_rule`
- `missing_canonical_docs`
- `missing_tests_or_test_evidence`
- `auto_completion_without_review`
- `fake_evidence`
- `provider_call_without_approval`
- `dirty_workspace_for_claim`
- `synthetic_score_used_as_real_claim`

## Fluxo

1. Operador (ou audit) chama `AtlasRivalsOneShotEnterpriseRubricService`
   para obter o contrato.
2. `AtlasRivalsOneShotEnterpriseEvaluationService` recebe replay manifest +
   case manifest + evidence pack opcional e devolve scoring.
3. CLI `atlas:programming:rivals-one-shot-evaluate` orquestra usando
   `AtlasForgeNativeRivalsCaseManifestService` e
   `AtlasForgeNativeRivalsDryRunService` como fixture local.
4. Completion audit expoe o eixo `rivals_one_shot_enterprise_evaluation_certification`
   sem alterar `completion_allowed` nem desbloquear
   `external_rivals_certification`.

## Regras para IA

- Nao chamar provider externo em evaluation, rubric ou CLI.
- Nao promover claim Rivals a partir desta camada.
- Nao usar score sintetico como claim.
- Nao usar tempo bruto para superar qualidade ruim.
- Nao remover hard fails globais sem decisao operadora explicita.
- Nao enfraquecer external_rivals_certification.
- Nao alterar `completion_allowed` a partir deste bloco.

## Escopo de Implementacao

| Componente | Path |
|------------|------|
| Rubric Service | `app/Services/Ai/Programming/AtlasRivalsOneShotEnterpriseRubricService.php` |
| Evaluation Service | `app/Services/Ai/Programming/AtlasRivalsOneShotEnterpriseEvaluationService.php` |
| CLI | `app/Console/Commands/AtlasProgrammingRivalsOneShotEvaluateCommand.php` |
| Completion audit block | `rivalsOneShotEnterpriseEvaluationCertification()` em `ProgrammingProfessionalCompletionAuditService.php` |
| Feature tests | `tests/Feature/Ai/Programming/AtlasRivalsOneShotEnterpriseEvaluationTest.php` |

## Dependencias

- `atlas-forge-native-rivals-protocol-v1` — Atlas arm = Forge obrigatorio.
- `atlas-programming-forge-flow` — mapa do fluxo Forge pesado.
- `atlas-code-forge-fast-path-v1` — gateway canonico do Atlas arm.

## Evidencias

```bash
php artisan atlas:engineering:knowledge docs-health --json
php artisan atlas:ai:architecture-validate --json
php artisan atlas:programming:rivals-one-shot-evaluate --json --strict
php artisan atlas:programming:completion-audit --json
php artisan test --filter='AtlasRivalsOneShotEnterpriseEvaluationTest'
```

## Riscos

| Risco | Bloqueio correto |
|-------|------------------|
| Tempo dominar score | `time_and_cost_efficiency` tem peso 1 e nunca decide isolado; rubric declara `time_cannot_compensate_quality=true` |
| Score sintetico virar claim | Hard fail `synthetic_score_used_as_real_claim` invalida claim |
| Atlas arm rodar fora do Forge | Hard fail `atlas_arm_not_forge` invalida claim |
| Evaluation virar claim sem bateria real | `claim_ready=false` e `promotes_external_rivals_claim=false` sempre |
| Audit afrouxar para liberar completion | Bloco e diagnostico — nao altera `completion_allowed` |

## Exemplos

### Avaliacao local de um case

```bash
php artisan atlas:programming:rivals-one-shot-evaluate \
  --case=atlas-fair-claude-baseline-case-01 \
  --json --strict
```

Saida (resumida):

```json
{
  "schema_version": "atlas.programming.rivals_one_shot_enterprise_evaluation.v1",
  "grade": "review_required",
  "total_score": 78,
  "max_score": 100,
  "external_provider_call": false,
  "provider_tokens_spent": false,
  "promotes_external_rivals_claim": false,
  "claim_ready": false,
  "speed_is_secondary": true,
  "time_cannot_compensate_quality": true,
  "verdict": {"summary": "Entrega tem qualidade aceitavel mas exige revisao humana ..."},
  "limitations": ["no_real_provider_baseline", "score_is_diagnostic_not_claim"]
}
```

### Completion audit

```bash
php artisan atlas:programming:completion-audit --json \
  | jq '.rivals_one_shot_enterprise_evaluation_certification'
```

Saida (resumida):

```json
{
  "schema_version": "atlas.programming.rivals_one_shot_enterprise_evaluation_certification.v1",
  "status": "available",
  "score_dimensions_count": 13,
  "score_weights_total": 100,
  "speed_is_secondary": true,
  "time_cannot_compensate_quality": true,
  "promotes_external_rivals_claim": false,
  "separated_from_external_rivals_certification": true
}
```

## Camadas Filhas

- `atlas-rivals-evidence-pack-replay-manifest-v1.md` — Evidence pack local replayable e verifier; alimenta este evaluator via `--with-evidence-pack`, sem promover claim externo.

## Proximas Acoes

1. Manter rubric e weights = 100 a cada evolucao.
2. Rodar evaluation toda vez que uma entrega one-shot for fechada localmente.
3. Atualizar limitations quando bateria provider real existir.
4. Nunca usar este score como winner Rivals.
