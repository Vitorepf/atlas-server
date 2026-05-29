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
  - app/Services/Ai/Programming/ForgeRivals/DeepSwe/AtlasForgeRivalsDeepSweResultIngestService.php
  - tests/Feature/Ai/Programming/AtlasForgeRivalsExternalEvidenceLifecycleCertificationTest.php
  - tests/Feature/Ai/Programming/AtlasForgeRivalsDeepSweCompatibilityTest.php
  - tests/Feature/Ai/Programming/AtlasForgeRivalsMatrixRunnerTest.php
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
owner: programming_rivals
graph_id: atlas-forge-rivals-external-evidence-lifecycle-v1
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

## Dependencias

Depende de:

- Run Inventory;
- Evidence Bundle Manifest;
- Replay Service;
- Trusted Signal Gate;
- Provider Performance Ledger;
- Decide Signal Projection.
- DeepSWE result ingest, quando a origem for benchmark externo importado.

## Evidencias

Evidência mínima:

- `php artisan atlas:forge:rivals external-evidence-readiness --json`
- `php artisan atlas:forge:rivals audit --json`
- `php artisan test --filter='AtlasForgeRivalsExternalEvidenceLifecycleCertificationTest|AtlasForgeRivalsMatrixRunnerTest'`
- `php artisan test --filter='AtlasForgeRivalsDeepSweCompatibilityTest'`

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
