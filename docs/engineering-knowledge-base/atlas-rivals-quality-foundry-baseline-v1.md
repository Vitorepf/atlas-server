---
id: atlas-rivals-quality-foundry-baseline-v1
title: Atlas Rivals Quality Foundry baseline v1
summary: Baseline executado do Packet 7, com owners, hashes, testes e lacunas RED antes da implementação do trial mundial.
status: active-baseline
---

# Atlas Rivals Quality Foundry — baseline executado

Data do baseline: 2026-07-12.

Este documento congela o ponto de partida do Packet 7 do plano mestre. A
existência de uma classe ou de um teste não é considerada prova de execução
mundial. Ausência de execução nativa, campanha real, adjudicação independente,
janela de outcomes ou replay verificável permanece RED.

## Estado do workspace

- Branch local: `main`.
- WIP paralelo preservado: alteração em `AcosMaxMeasureSeriesRegistry.php`, novo
  `AtlasCodeSymbolEmbeddingCoverageService.php`, migration de embeddings e
  artefatos ACOS não pertencentes a este packet.
- Nenhum arquivo Rivals estava modificado ou staged no início do baseline.
- Não houve reset, revert, limpeza de untracked ou apropriação de WIP paralelo.

## Baseline de testes

Comando executado:

```text
/opt/homebrew/bin/php artisan test tests/Unit/Ai/Rivals tests/Feature/Ai/Rivals
```

Resultado: `139 passed`, `2 failed`, `1033 assertions`, `38.44s`.

Falhas RED congeladas:

1. `Tests\\Feature\\Ai\\Rivals\\ExternalCommandContractTest` —
   `all ten adapters emit unique argv for independent repetitions`.
   O contrato espera `python`, mas o adapter produz `python3` em
   `tests/Feature/Ai/Rivals/ExternalCommandContractTest.php:68`.
2. `Tests\\Feature\\Ai\\Rivals\\FaseABatteryOrchestratorTest` —
   `execute blocked off mac without allow`.
   O executor retorna `rivals_battery_execute_smoke_not_ready:*` antes de
   `rivals_battery_execute_mac_only`, portanto a precedência de segurança está
   RED em `FaseABatteryOrchestrator`.

Essas falhas não são convertidas em verde por alteração de expectativa. O
Packet 7 deverá corrigi-las ou documentar explicitamente a decisão de contrato
antes da campanha hermética.

## Owners existentes e classificação

| Requisito do trial | Owner atual | Estado no baseline |
|---|---|---|
| Preregistration hash e plano estatístico | `Core/Preregistration`, `Core/RunPlan` | implemented_unproven para o trial mundial |
| Run state monotônico e crash-visible | `Core/RunStateMachine` | implemented_and_proven em testes locais |
| Suites, arms, models e comparações pareadas | `Core/SuiteRegistry`, `Core/ArmRegistry`, `Core/ModelRegistry`, `Core/RunPlan` | implemented_unproven para matriz mundial |
| Contamination e canários | `Core/ContaminationGuard` | implemented_unproven para workspace/egress nativo |
| Evidence pack content-addressed | `Core/EvidencePackBuilder`, `Core/ReplayVerifier` | implemented_and_proven em fixtures locais; campanha real RED |
| Estatística ITT, poder e multiplicidade | `Core/StatisticalPolicy` | implemented_unproven para três campanhas reais |
| Adjudicação independente | `Core/Adjudicator` | partial: gates existem; independência, 22 disposições e outcome tardio RED |
| Claims, expiração e revogação | `Core/RivalsClaimAuthority`, `Core/ClaimTier` | implemented_unproven para lifecycle mundial |
| Relatórios multi-eixo | `Core/ReportBuilder`, `Core/ReportBuilderV2`, `Core/EnterpriseReportBuilder` | implemented_and_proven como non-claim local |
| Native adapters e import normalizado | `Adapters/*`, `Core/NativeExecution*` | partial: fixtures e contratos existem; execução/provider real RED |
| Readiness de campanha | `Core/WorldTrialReadiness` | implemented_unproven: exige três campanhas, poder, outcomes e dimensões reais |

## Hashes congelados dos owners críticos

| Arquivo | SHA-256 |
|---|---|
| `app/Services/Ai/Rivals/Support/SchemaContract.php` | `2aaebce33e19892fc3c7345d611afc77be3dd4818ceb541c0a4f76da63de205b` |
| `app/Services/Ai/Rivals/Core/Preregistration.php` | `6aba6dd23433226a401d369d841e07975fcf30040cf66fbd8df8ffbc7e057c50` |
| `app/Services/Ai/Rivals/Core/RunPlan.php` | `907f9724844fa51336432928b041f10b5b3f4fb7866ac023a6a683710020c0dc` |
| `app/Services/Ai/Rivals/Core/WorldTrialReadiness.php` | `d8e36db59d0e590ca11797f6e6685077a50e77d9006e5334ddbe10edaa7dfd44` |
| `app/Services/Ai/Rivals/Core/RivalsClaimAuthority.php` | `0de71e90eb508069cbc8daa5be6b5505afb18b2d8b256bbeb13896faa3fc03b0` |

## Golden fixtures congelados

Os fixtures atuais de importação nativa ficam preservados como baseline, sem
serem tratados como evidência de provider real:

- `tests/Fixtures/Rivals/bfcl_results.json`
- `tests/Fixtures/Rivals/tau2_bench_results.json`
- `tests/Fixtures/Rivals/tau2_bfcl_results.json`
- `tests/Fixtures/Rivals/swe_bench_live_results.json`
- `tests/Fixtures/Rivals/swe_marathon_results.json`
- `tests/Fixtures/Rivals/inspect_evals_results.json`
- `tests/Fixtures/Rivals/aider_polyglot_results.json`
- `tests/Fixtures/Rivals/live_code_bench_results.json`
- `tests/Fixtures/Rivals/hal_harness_results.json`

Fixtures continuam `harness/non-claim`; qualquer promoção exige receipt nativo,
hash de artefato, isolamento, replay e adjudicação independentemente provados.

## Próximo corte implementável

Antes de declarar Packet 7 pronto, executar os RED tests de preregistration,
freeze de unidade, hidden-gold/egress, contamination, invalidação pós-unblinding
e pinning de recursos. O primeiro owner de implementação deve ser o contrato de
criação do run, sem alterar os adapters existentes até que o baseline acima
esteja revalidado.
