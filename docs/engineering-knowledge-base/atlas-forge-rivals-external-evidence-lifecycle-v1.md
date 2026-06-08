---
id: atlas-forge-rivals-external-evidence-lifecycle-v1
type: engineering_knowledge
title: Atlas Forge Rivals · External Evidence Lifecycle v1
status: active
category: programming-forge
priority: 91
summary: Gate read-only que prova o ciclo externo reprodutivel do Rivals: inventory, bundle portatil, restore, replay, trusted-signal, ledger-record e decide-signal advisory-only.
tags:
  - atlas-forge
  - rivals
  - external-evidence
  - reproducibility
  - atlas-decide
capabilities:
  - rivals_external_evidence_lifecycle
  - portable_evidence_restore
  - trusted_signal_gate
  - advisory_decide_signal
decisions:
  - Evidencia externa restaurada so vira sinal quando bundle, replay, evidence pack, scorecard e ledger passam fail-closed.
  - O gate e read-only: nao chama provider, nao gasta token, nao escreve ledger e nao promove claim.
  - Atlas Decide pode consumir o sinal apenas como advisory input; Rivals nao decide routing.
  - Rivals emits measured evidence; Atlas Decide decides model routing.
maintenance:
  - Atualizar quando inventory, evidence-bundle, replay, trusted-signal, ledger-record ou decide-signal mudarem contrato.
  - Nunca remover as invariantes advisory-only sem nova decisao canonica e testes focados.
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasForgeRivalsExternalEvidenceLifecycleCertification.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsRunInventoryService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsEvidenceBundleManifestService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsReplayService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsTrustedSignalGateService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsProviderPerformanceLedgerService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsDecideSignalProjectionService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsExternalExecutionPreflightService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsExternalLearningGapService.php
  - app/Services/Ai/Programming/ForgeRivals/DeepSwe/AtlasForgeRivalsDeepSweResultIngestService.php
  - tests/Feature/Ai/Programming/AtlasForgeRivalsExternalEvidenceLifecycleCertificationTest.php
  - tests/Feature/Ai/Programming/AtlasForgeRivalsDeepSweCompatibilityTest.php
  - tests/Feature/Ai/Programming/AtlasForgeRivalsMatrixRunnerTest.php
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
owner: programming_rivals
graph_id: atlas-forge-rivals-external-evidence-lifecycle-v1
human_name: "Atlas Forge Rivals · External Evidence Lifecycle v1"
canonical_name: "Atlas Forge Rivals · External Evidence Lifecycle v1"
technical_name: AtlasForgeRivalsExternalEvidenceLifecycleCertification
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-forge-rivals-external-evidence-lifecycle-v1.md
graph_title: Atlas Forge Rivals · External Evidence Lifecycle v1
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-forge-rivals-provider-performance-ledger-v1
graph_status: active
graph_source: repo
repo_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasForgeRivalsExternalEvidenceLifecycleCertification.php
allowed_changes:
  - Evoluir invariantes quando novas etapas de evidence lifecycle forem adicionadas.
forbidden_changes:
  - Chamar provider, gastar tokens, gravar ledger, alterar Atlas Decide topology ou destravar external_rivals_certification dentro deste gate.
depends_on:
  - atlas-forge-rivals-provider-performance-ledger-v1
  - atlas-forge-rivals-intelligence-ledger-v1
flows_to:
  - atlas-decide
unlocks:
  - rivals_reproducible_external_evidence_readiness
governs:
  - rivals_external_evidence_lifecycle
evidence:
  - "php artisan atlas:forge:rivals external-evidence-readiness --json"
  - "php artisan atlas:forge:rivals audit --json"
  - "php artisan test --filter='AtlasForgeRivalsExternalEvidenceLifecycleCertificationTest|AtlasForgeRivalsMatrixRunnerTest'"
evidence_refs:
  - symbol: AtlasForgeRivalsExternalEvidenceLifecycleCertification
  - test: AtlasForgeRivalsExternalEvidenceLifecycleCertificationTest
required_tests:
  - "php artisan test --filter='AtlasForgeRivalsExternalEvidenceLifecycleCertificationTest|AtlasForgeRivalsMatrixRunnerTest'"
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: medium
next_actions:
  - Manter o E2E restore-to-decide verde quando bundle, replay, ledger ou decide-signal mudarem.
  - Adicionar ingest externo real somente sem provider call e sem destravar external_rivals_certification.
---
# Atlas Forge Rivals · External Evidence Lifecycle v1

## Resumo

Este módulo é o gate único de prontidão para evidência externa restaurável do
Rivals. Ele não mede um novo resultado; ele prova que uma evidência já
materializada consegue sobreviver a restore e continuar utilizável sem confiar
em caminhos absolutos antigos ou memória do operador.

## Papel no Atlas

O módulo fica entre a materialização de runs do Rivals e o Provider Performance
Ledger. Ele responde se a evidência é reprodutível o bastante para alimentar
inteligência consultiva por provider/modelo/categoria/dificuldade.

Ele preserva a fronteira: Atlas Decide é dono de routing. Rivals apenas mede e
emite evidência.

## Onde Se Encaixa

Comando canônico:

```bash
php artisan atlas:forge:rivals external-evidence-readiness --json
```

Aliases úteis:

```bash
php artisan atlas:forge:rivals external-evidence --json
php artisan atlas:forge:rivals portable-evidence-readiness --json
```

## Contratos

O gate verifica que existem e estão conectados:

- `runs`: inventário local/restaurado de runs.
- `evidence-bundle` e `evidence-bundle-verify`: manifesto portátil com hash.
- `--bundle-run-dir`: override explícito para diretório restaurado.
- `replay`: remapeamento de paths antigos para o novo run dir restaurado.
- `trusted-signal`: bloqueio antes do ledger se replay/evidence/scorecard falhar.
- `ledger-record`: gravação fail-closed e ranking apenas com evidência válida.
- `decide-signal`: saída machine-readable para Atlas Decide, advisory-only.
- `external-execution-preflight`: checa binários CLI configurados/no `PATH`,
  Provider Arena readiness e comandos de runbook antes de qualquer execução
  paga; não executa provider.
- `external-learning-gap`: lê o ledger e mostra buckets provider/modelo x
  categoria x dificuldade que ainda não têm evidência válida suficiente para
  aprendizado do Atlas Decide.
- `external-runbook-validate`: valida um
  `external_execution_runbook_manifest.v1` exportado antes de qualquer provider
  real. Recalcula fingerprint, exige `dry_run_command`, confirmações reais,
  `model_gap_summary`, strong claim gate bloqueado e invariantes advisory-only.
- `deepswe-batch-ingest`: batch externo com `external_benchmark_claim_gate`
  fail-closed, separando evidência pronta para revisão humana de qualquer
  claim externo. Quando `--plan-manifest` e usado, tambem expõe
  `external_execution_plan_binding_summary` e bloqueia qualquer resultado que
  nao tenha o mesmo fingerprint do runbook revisado.

## Fluxo

Para evidência externa/restaurada:

```bash
php artisan atlas:forge:rivals runs --run-id=<run_id> --json
php artisan atlas:forge:rivals evidence-bundle-verify --input=<manifest.json> --bundle-run-dir=<restored_run_dir> --json
php artisan atlas:forge:rivals replay --run-id=<run_id> --json --strict
php artisan atlas:forge:rivals trusted-signal --run-id=<run_id> --input=<manifest.json> --bundle-run-dir=<restored_run_dir> --task-category=<cat> --role=<role> --json
php artisan atlas:forge:rivals ledger-record --run-id=<run_id> --task-category=<cat> --role=<role> --json --strict
php artisan atlas:forge:rivals decide-signal --task-category=<cat> --role=<role> --json
```

Se qualquer etapa falhar, o sinal não pode alimentar ranking nem claim.

## Regras para IA

Ao alterar este módulo, a IA deve manter:

- sem provider real;
- sem gasto de tokens;
- sem escrita de ledger pelo readiness;
- sem claim externo;
- sem mudança de topology do Atlas Decide.

## Escopo de Implementacao

A certificação `atlas_forge_rivals_external_evidence_lifecycle_certification`
declara verde somente quando todas as invariantes passam:

- `run_inventory_available`
- `portable_bundle_verify_supports_restored_run_dir`
- `replay_supports_restored_artifact_paths`
- `trusted_signal_gate_blocks_untrusted_runs`
- `ledger_record_fail_closed_on_replay_and_evidence`
- `decide_signal_advisory_only`
- `external_rivals_remains_blocked`
- `provider_tokens_not_spent`
- `e2e_restore_to_decide_test_exists`
- `deepswe_ingest_exposes_external_lifecycle`
- `deepswe_readiness_external_runbook_available`
- `deepswe_batch_external_claim_gate_fail_closed`
- `statistical_repeat_operator_runbook_available`
- `external_execution_preflight_available`
- `external_learning_gap_available`
- `external_execution_plan_binding_batch_available`
- `external_runbook_manifest_validation_available`
- `decide_learning_operational_plan_available`

Os invariants DeepSWE provam duas coisas separadas: readiness emite
`external_benchmark_runbook` plan-only com piso de 50 tasks validas e sequencia
operacional ate `deepswe-batch-ingest`; depois, o batch precisa emitir
`external_benchmark_claim_gate` exigindo minimo de 50 runs externos
bem-sucedidos, battery evidence verde, replay verde, matrix verde, ledger verde,
repetição estatística, dimensões completas para Atlas Decide e certificação
humana. Mesmo com evidência completa, o máximo é
`ready_for_human_certification_external_claim_still_blocked`; claim e topology
seguem bloqueados.

O invariant `external_execution_plan_binding_batch_available` garante que
resultado externo importado em batch pode ser ligado a um
`external_execution_runbook_manifest.v1` revisado. Se `--plan-manifest` for
fornecido, todos os arm results precisam declarar o mesmo `plan_fingerprint`;
fingerprint ausente, divergente ou misto bloqueia o batch antes de claim forte.

O invariant `statistical_repeat_operator_runbook_available` fecha a etapa de
reprodutibilidade: `statistical-repeat-dry-run` precisa validar os inputs pelo
Provider Arena sem provider real, emitir `operator_runbook_summary` compacto,
suportar `--output-path` para gravar o `real_execution_runbook` completo e
preservar `claim_ready=false`, `external_provider_call=false`,
`provider_tokens_spent=false` e `routing_effect=none`. O resumo existe para o
operador revisar comandos reais, batches, confirmações de custo/provider e
requisitos antes de claim sem despejar manifestos industriais gigantes no JSON
principal.

O invariant `external_execution_preflight_available` fecha a etapa antes do
primeiro gasto real: `external-execution-preflight` precisa resolver os
binários configurados dos providers (`claude`, `codex`, `gemini`, `cursor` e
`composer`, ou filtro via `--provider`), consultar Provider Arena readiness,
emitir blockers claros como `provider_binary_not_found:<provider>:<binary>` e
manter `real_execution_allowed_by_this_preflight=false`. Ele apenas diz se o
ambiente está pronto para revisão humana de custo/runbook; a execução real
continua exigindo `--confirm-runbook-reviewed`, `--confirm-provider-cost` e
`--confirm-real-provider-call`.

O invariant `external_learning_gap_available` fecha a etapa de planejamento de
aprendizado: `external-learning-gap` precisa transformar o ledger em uma matriz
provider/modelo x categoria x dificuldade x role, calcular
`missing_valid_evidence_count` por bucket e emitir, por bucket, dois comandos:
`dry_run_command` para validar o contrato local sem provider e
`real_execution_command_template` com `--confirm-runbook-reviewed`,
`--confirm-provider-cost` e `--confirm-real-provider-call`. O campo
`next_measurement_command` permanece dry-run por padrão. O filtro `--model`
resolve aliases pelo Provider Model Registry e deixa o runbook mirar um modelo
especifico; modelo invalido vira blocker claro, não plano ambíguo. Ele não
recomenda routing; ele só mostra o que ainda falta para Atlas Decide poder
consumir sinal por segmento com revisão de política.

`external-execution-plan` deve promover essa lacuna para
`model_gap_summary`, agregando por provider/modelo: buckets alvo, buckets
cobertos, runs externos faltantes e status de readiness. Esse resumo também vai
para `external_execution_runbook_manifest.v1`, permitindo revisão de custo e
escopo por modelo antes de qualquer execução real.

`external-runbook-validate --plan-manifest=<path>` fecha a etapa entre plano e
execução paga: o manifesto precisa ser plan-only, preservar fingerprint
recalculável, carregar `model_gap_summary`, manter `strong_claim_gate`
bloqueado, listar buckets faltantes com comando dry-run e template real com
`--confirm-runbook-reviewed`, `--confirm-provider-cost` e
`--confirm-real-provider-call`, além de preservar
`advisory_only=true`, `should_update_provider_topology=false` e
`routing_effect=none`. Qualquer divergência vira blocker antes de provider,
token, ledger ou claim.

O invariant `decide_learning_operational_plan_available` fecha o pacote que o
Atlas Decide consome: `decide-learning` precisa incluir
`evidence_collection_plan.learning_gap_commands_preview` e
`arena_repeat_commands_preview` por segmento, sempre dry-run, sem provider real,
sem token, sem score/claim e sem topology mutation. Assim o consumidor não
precisa inferir o próximo passo a partir de scores; ele recebe comandos
operacionais para checar lacunas e planejar arena/repetição antes de qualquer
preferência.

## Dependencias

Depende de:

- Run Inventory;
- Evidence Bundle Manifest;
- Replay Service;
- Trusted Signal Gate;
- Provider Performance Ledger;
- Decide Signal Projection.
- DeepSWE result ingest, quando a origem for benchmark externo importado.
- Statistical Repeat Dry-Run, quando o sinal quiser virar confiança
  reproduzível por categoria/dificuldade/modelo.
- External Execution Preflight, antes de qualquer bateria paga com CLI externo.
- External Learning Gap, antes de interpretar uma matriz como aprendizado
  suficiente por segmento.

## Evidencias

Evidência mínima:

- `php artisan atlas:forge:rivals external-evidence-readiness --json`
- `php artisan atlas:forge:rivals audit --json`
- `php artisan test --filter='AtlasForgeRivalsExternalEvidenceLifecycleCertificationTest|AtlasForgeRivalsMatrixRunnerTest'`
- `php artisan test --filter='AtlasForgeRivalsDeepSweCompatibilityTest'`
- `php artisan atlas:forge:rivals statistical-repeat-dry-run --n-tasks=2 --output-path=storage/framework/testing/statistical-repeat-runbook-cli.json --json`
- `php artisan atlas:forge:rivals external-execution-preflight --provider=claude,codex,cursor --case-set=quick --json`
- `php artisan atlas:forge:rivals external-learning-gap --provider=claude,codex,cursor --task-category=bugfix --difficulty=L5 --json`

## Riscos

O maior risco é aceitar uma evidência restaurada com paths antigos, hash
alterado, replay quebrado ou `local_fake` como sinal real. Por isso o lifecycle
falha fechado antes do ledger e antes do decide-signal.

## Exemplos

Exemplo de payload confiável:

```json
{
  "status": "ok",
  "certification_status": "available",
  "external_provider_call": false,
  "provider_tokens_spent": false,
  "advisory_only": true,
  "routing_effect": "none"
}
```

## Proximas Acoes

Manter este gate verde junto com qualquer evolução de DeepSWE, CLI runners,
Cursor/Composer ou importação externa de resultados.

## Segurança

Este gate sempre retorna:

- `external_provider_call=false`
- `provider_tokens_spent=false`
- `score_or_claim_allowed=false`
- `claim_ready=false`
- `external_claim_allowed=false`
- `advisory_only=true`
- `should_update_provider_topology=false`
- `never_changes_atlas_decide_topology=true`
- `owner_of_model_routing=atlas_decide`
- `routing_effect=none`

Ele nunca destrava `external_rivals_certification`.
