---
id: atlas-rivals-evidence-pack-replay-manifest-v1
type: engineering_knowledge
title: Atlas Rivals Evidence Pack & Replay Manifest v1
status: active
category: programming-forge
priority: 100
summary: Pacote canonico de evidencia local + replay manifest hash para alimentar a Rivals One-Shot Enterprise Evaluation sem provider externo. Cada campo carrega source/hash quando present=true e reason_missing quando present=false; verifier rejeita fake evidence; nunca promove o claim Rivals.
tags:
  - atlas
  - rivals
  - evidence
  - replay
  - manifest
  - forge
capabilities:
  - rivals_evidence_pack
  - rivals_evidence_pack_verifier
  - rivals_evidence_pack_certification
decisions:
  - Evidence Pack e local, replayable e honesto: present=true exige source+hash; present=false exige reason_missing.
  - Tests e quality scans so rodam quando o operador opta explicitamente (--run-tests / --run-quality).
  - Workspace dirty nunca pode ser mascarado como clean.
  - Provider externo nunca e chamado nem em pack, nem em verifier, nem no CLI.
  - Evidence Pack alimenta a One-Shot Enterprise Evaluation, mas nao promove o claim Rivals.
  - external_rivals_certification continua sendo o eixo unico que governa o claim final.
maintenance:
  - Atualize este doc antes de alterar pack schema, verifier, CLI ou audit dessa camada.
  - Mantenha como pagina-mae dos services AtlasRivalsEvidencePack*.
related_paths:
  - docs/engineering-knowledge-base/atlas-rivals-one-shot-enterprise-evaluation-v1.md
  - docs/engineering-knowledge-base/atlas-forge-native-rivals-protocol-v1.md
  - docs/engineering-knowledge-base/atlas-programming-forge-flow.md
  - docs/engineering-knowledge-base/domains/programming-professional-completion-audit.md
  - app/Services/Ai/Programming/AtlasRivalsEvidencePackService.php
  - app/Services/Ai/Programming/AtlasRivalsEvidencePackVerifierService.php
  - app/Console/Commands/AtlasProgrammingRivalsEvidencePackCommand.php
  - app/Console/Commands/AtlasProgrammingRivalsOneShotEvaluateCommand.php
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-rivals-evidence-pack-replay-manifest-v1
graph_title: Atlas Rivals Evidence Pack & Replay Manifest v1
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-rivals-one-shot-enterprise-evaluation-v1
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-rivals-evidence-pack-replay-manifest-v1.md
allowed_changes:
  - Adicionar evidencias novas, hashes ou verificacoes quando o pipeline Forge evoluir.
  - Endurecer verifier (nunca afrouxar).
forbidden_changes:
  - Marcar workspace dirty como clean.
  - Permitir present=true sem source ou hash.
  - Permitir provider externo no pack ou verifier.
  - Promover claim externo a partir desta camada.
  - Aceitar synthetic score como claim.
depends_on:
  - atlas-forge-native-rivals-protocol-v1
  - atlas-rivals-one-shot-enterprise-evaluation-v1
  - atlas-programming-forge-flow
flows_to:
  - programming-professional-completion-audit
unlocks:
  - rivals-evidence-pack-replay-manifest
governs:
  - rivals_evidence_pack_local_diagnostic
  - rivals_evidence_pack_verifier_contract
evidence:
  - docs/engineering-knowledge-base/atlas-rivals-evidence-pack-replay-manifest-v1.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:programming:rivals-evidence-pack --json"
  - "php artisan atlas:programming:rivals-one-shot-evaluate --json --with-evidence-pack"
  - "php artisan atlas:programming:completion-audit --json"
requires_evidence: true
risk_level: critical
visual_tags:
  - rivals
  - evidence
  - replay
ai_entrypoints:
  - Antes de alimentar a One-Shot Evaluation com qualquer evidencia, gerar este pack.
  - Sempre passar pelo verifier antes de aceitar o pack como input.
ai_usage_notes:
  - Pack default e read-only — nao executa testes ou quality scan sem flag explicita.
  - present=true requer source + hash; verifier bloqueia se faltarem.
quality_gates:
  - docs-health
  - architecture-validate
  - rivals-evidence-pack-strict
failure_modes:
  - Mascarar workspace dirty como clean.
  - Aceitar present=true sem hash.
  - Confundir log excerto com evidence verificada.
  - Promover claim externo a partir do pack.
observability_signals:
  - rivals_evidence_pack_certification.status
  - evidence_pack.missing_evidence
  - verification.status
  - workspace.clean
next_actions:
  - Rodar `atlas:programming:rivals-evidence-pack --json` para diagnostico read-only.
  - Rodar `atlas:programming:rivals-evidence-pack --run-tests --run-quality --json --strict` quando quiser evidencia executada.
  - Encadear `atlas:programming:rivals-one-shot-evaluate --with-evidence-pack` para alimentar a rubrica.
---
# Atlas Rivals Evidence Pack & Replay Manifest v1

## Resumo

Rivals One-Shot Enterprise Evaluation precisa de evidencia real para
pontuar dimensoes como `functional_correctness`, `real_tests_and_risk_coverage`,
`implementation_quality` e `observability_and_evidence`. Esta camada
canoniza um **Evidence Pack** local — replayable, hash-based, com cada
campo carregando `source` quando present=true e `reason_missing` quando
present=false. O verifier garante que ninguem inventa evidencia. Provider
externo nunca e chamado.

## Papel no Atlas

Sem Evidence Pack, a Rivals Evaluation fica refem de fixture sintetico.
Com Evidence Pack, a avaliacao consome estado real do workspace:
workspace hash, git status, diff, testes (quando rodados), quality scan
(quando rodado), business rule do case manifest e replay manifest hash.
A camada e diagnostica: nunca promove o claim Rivals, nunca desbloqueia
`external_rivals_certification`.

## Onde Se Encaixa

- Acima: `atlas-rivals-one-shot-enterprise-evaluation-v1.md` (rubrica).
- Lado: `atlas-forge-native-rivals-protocol-v1.md`,
  `atlas-programming-forge-flow.md`.
- Consumido por: `programming-professional-completion-audit.md`,
  `ProgrammingProfessionalCompletionAuditService::rivalsEvidencePackCertification()`,
  `AtlasProgrammingRivalsOneShotEvaluateCommand` (via `--with-evidence-pack`).

## Contratos

| Schema | Quem produz | Quem consome |
|--------|-------------|--------------|
| `atlas.programming.rivals_evidence_pack.v1` | `AtlasRivalsEvidencePackService` | verifier, evaluator, CLI, audit |
| `atlas.programming.rivals_evidence_pack_verification.v1` | `AtlasRivalsEvidencePackVerifierService` | CLI, audit |
| `atlas.programming.rivals_evidence_pack_certification.v1` | `ProgrammingProfessionalCompletionAuditService` | `atlas:programming:completion-audit` |
| `atlas.programming.forge_native_rivals_replay_manifest.v1` | `AtlasForgeNativeRivalsDryRunService` (consumido como input) | pack |

### Campos canonicos do pack

- `schema_version`, `evidence_pack_id` (ULID), `case_id`, `generated_at`
- `workspace`: `path_hash`, `is_git`, `clean`, `dirty_count`, `head_sha`, `branch`
- `replay_manifest`: `schema_version`, `present`, `valid`, `hash`, `case_id`
- `business_rule`: `present`, `objective`, `source=case_manifest`, `hash`
- `canonical_docs`: `consulted[]`, `all_present`, `missing[]`, `hashes`
- `patch_diff`: `present`, `source=git_diff`, `hash`, `size_bytes`, `file_count`, `files[]`, `reason_missing`
- `tests`: `present`, `source=command|not_run`, `command`, `exit_code`, `passed`, `log_hash`, `log_excerpt`, `assertion_count`, `reason_missing`
- `quality_scan`: `present`, `source=command|not_run`, `command`, `exit_code`, `passed`, `log_hash`, `log_excerpt`, `reason_missing`
- `acceptance_gates`: `gates[]`, `evaluated_count`, `passed_count`, `missing_count`
- `human_intervention`: `count`, `source`, `log_hash`, `reason_missing`
- `review_cost_estimate`: `estimated_minutes`, `band`, `source`
- `command_exit_codes`: `{test_command: int, quality_command: int}` (apenas quando present=true)
- `evidence_paths[]`
- `missing_evidence[]`
- `external_provider_call=false`, `claim_ready=false`, `promotes_external_rivals_claim=false`

### Verifier (subset)

- Bloqueia schema invalido, falta de evidence_pack_id ou case_id.
- Bloqueia `external_provider_call=true`, `promotes_external_rivals_claim=true`,
  `claim_ready=true`, `synthetic_scores_allowed=true`.
- Bloqueia `present=true` sem `source` ou `hash` em business_rule,
  patch_diff, tests, quality_scan.
- Bloqueia `workspace.clean=true` quando dirty_count > 0 ou is_git=false.
- Bloqueia incoerencia entre `missing_evidence` e flags `present`.
- Bloqueia exit codes incoerentes entre tests/quality e `command_exit_codes`.

## Fluxo

1. Operador roda `atlas:programming:rivals-evidence-pack --case=<id> --json`.
2. Service usa CaseManifest + DryRun para produzir replay manifest hash
   + business rule do case + canonical docs.
3. Workspace state e capturado read-only (`git rev-parse`, `git status`).
4. `git diff` produz patch evidence quando ha diferencas; ausencia
   gera `missing_patch_diff` honesto.
5. Se `--run-tests` ou `--run-quality`, o service executa o command e
   captura exit code + log_hash + log_excerpt.
6. Verifier valida o pack; CLI imprime/retorna JSON.
7. `atlas:programming:rivals-one-shot-evaluate --with-evidence-pack`
   alimenta a rubrica com `toEvaluationEvidenceInput()`.

## Regras para IA

- Nao chamar provider externo no service, verifier ou CLI.
- Nao marcar `workspace.clean=true` quando dirty_count > 0.
- Nao publicar `present=true` sem `source` e `hash`.
- Nao remover `missing_evidence` campos quando o item esta ausente.
- Nao promover claim externo a partir desta camada.
- Nao alterar `completion_allowed` no audit a partir deste bloco.
- Toda evidencia precisa ter source ou reason_missing.

## Escopo de Implementacao

| Componente | Path |
|------------|------|
| Pack Service | `app/Services/Ai/Programming/AtlasRivalsEvidencePackService.php` |
| Verifier Service | `app/Services/Ai/Programming/AtlasRivalsEvidencePackVerifierService.php` |
| CLI | `app/Console/Commands/AtlasProgrammingRivalsEvidencePackCommand.php` |
| Integration flags | `--with-evidence-pack` em `AtlasProgrammingRivalsOneShotEvaluateCommand` |
| Completion audit block | `rivalsEvidencePackCertification()` em `ProgrammingProfessionalCompletionAuditService.php` |
| Feature tests | `tests/Feature/Ai/Programming/AtlasRivalsEvidencePackTest.php` |

## Dependencias

- `atlas-forge-native-rivals-protocol-v1` — define replay manifest.
- `atlas-rivals-one-shot-enterprise-evaluation-v1` — consome o pack.
- `atlas-programming-forge-flow` — contexto Forge pesado.

## Evidencias

```bash
php artisan atlas:engineering:knowledge docs-health --json
php artisan atlas:ai:architecture-validate --json
php artisan atlas:programming:rivals-evidence-pack --json
php artisan atlas:programming:rivals-evidence-pack --run-tests --run-quality --json --strict
php artisan atlas:programming:rivals-one-shot-evaluate --json --with-evidence-pack
php artisan atlas:programming:completion-audit --json
php artisan test --filter='AtlasRivalsEvidencePackTest|AtlasRivalsOneShotEnterpriseEvaluationTest|AtlasForgeNativeRivalsTest'
```

## Riscos

| Risco | Bloqueio correto |
|-------|------------------|
| Marcar dirty como clean | Workspace state captura git status; verifier bloqueia incoerencia |
| present=true sem hash | Verifier bloqueia `{section}_present_but_hash_missing` |
| provider chamado | `external_provider_call=false` enforced + verifier bloqueia `provider_call_flag_not_false` |
| Synthetic score como claim | Pack declara `synthetic_scores_allowed=false`; verifier bloqueia |
| Missing evidence escondido | `missing_evidence[]` obrigatorio para cada `present=false`; verifier bloqueia incoerencia |
| Pack virar claim Rivals | `promotes_external_rivals_claim=false`, `claim_ready=false`, certification separated |

## Exemplos

### Pack diagnostico read-only

```bash
php artisan atlas:programming:rivals-evidence-pack --json
```

Saida (resumida):

```json
{
  "evidence_pack": {
    "schema_version": "atlas.programming.rivals_evidence_pack.v1",
    "evidence_pack_id": "01KRKCBTB90M2DCV8YPV...",
    "workspace": {"clean": false, "dirty_count": 10, "is_git": true},
    "replay_manifest": {"present": true, "hash": "b757eaec95f08dd9..."},
    "business_rule": {"present": true, "source": "case_manifest"},
    "patch_diff": {"present": true, "source": "git_diff", "size_bytes": 12345},
    "tests": {"present": false, "source": "not_run", "reason_missing": "tests_not_executed"},
    "quality_scan": {"present": false, "source": "not_run", "reason_missing": "quality_scan_not_executed"},
    "external_provider_call": false,
    "claim_ready": false,
    "missing_evidence": ["missing_test_run_log", "missing_quality_scan_log"]
  },
  "verification": {"status": "passed", "blockers": []}
}
```

### Pack com execucao real (opt-in)

```bash
php artisan atlas:programming:rivals-evidence-pack \
  --run-tests --run-quality --json --strict
```

`--strict` retorna exit 1 se test_run_log, patch_diff ou quality_scan_log
forem missing, ou se o verifier nao passar.

### One-Shot evaluate consumindo o pack

```bash
php artisan atlas:programming:rivals-one-shot-evaluate \
  --with-evidence-pack --run-tests --run-quality --json
```

Saida (resumida):

```json
{
  "schema_version": "atlas.programming.rivals_one_shot_enterprise_evaluation.v1",
  "grade": "review_required",
  "total_score": 81,
  "claim_ready": false,
  "promotes_external_rivals_claim": false,
  "evidence_pack": {"evidence_pack_id": "01KRKCBTB...", "workspace_clean": false}
}
```

## Proximas Acoes

1. Rodar pack regularmente para gerar evidencia replayable de cada one-shot.
2. Atualizar verifier sempre que um novo campo present=true for adicionado.
3. Nunca substituir hash por placeholder ou string vazia.
4. Quando bateria provider real existir (fora desta camada), reutilizar
   o pack como insumo verificado.
