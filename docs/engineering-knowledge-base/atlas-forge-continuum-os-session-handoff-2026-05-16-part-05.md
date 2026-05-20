---
title: Atlas Forge Continuum OS Session Handoff 2026-05-16 · Parte 5
status: source_material
owner: atlas-code
updated_at: 2026-05-16
canonical: false
line_limit: 300
source_parent: docs/engineering-knowledge-base/atlas-forge-continuum-os-session-handoff-2026-05-16.md
note: source_material split from frozen handoff; evidence only, not a canonical module doc.
---
# Atlas Forge Continuum OS Session Handoff 2026-05-16 · Parte 5

## Resumo

Recorte de material de sessão: 9. Próximo passo recomendado ate 10. Prompt de retomada.

## Fonte

Arquivo pai: `docs/engineering-knowledge-base/atlas-forge-continuum-os-session-handoff-2026-05-16.md`.

## Conteudo Extraido

## 9. Próximo passo recomendado

Próximo elo técnico exato:

Corrigir `provider_performance_signal.fallback_hint` quando replay falha.

Por que vem agora:

- O report real está funcional, mas a suíte focada ainda não está verde.
- Esse bug é no boundary de confiança: se replay falha, o sinal não pode parecer “flow validated”.
- É pequeno e crítico; deve ser resolvido antes de qualquer nova bateria real ou claim.

Como implementar sem abrir frente paralela:

1. Inspecionar o seed de teste:

```bash
cd /Users/vitorepf/develop/Atlas/atlas-server
nl -ba tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsReportV3RankingTest.php | sed -n '340,390p'
```

2. Inspecionar `AtlasForgeRivalsReportService::render()` e fluxo:

```bash
rg -n "aggregateReplay|buildFallbackHint|provider_performance_signal|validity|replay_passes|primaryReplay" app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsReportService.php
```

3. Descobrir se:

- o seed não está marcando replay failure no path que o report realmente lê; ou
- `primaryReplay`/`aggregateReplay` está usando fallback que transforma replay ausente em replay ok; ou
- `validity` deveria considerar `matrix_evidence_lock`/case-level replay drift.

4. Corrigir o service ou o test fixture, escolhendo o menor ajuste que preserve a regra:

```text
se replay não passou, fallback_hint deve ser rivals_signal_unusable_until_replay_passes.
```

Critério de conclusão desse elo:

```bash
php -d memory_limit=1024M artisan test --filter='AtlasForgeRivalsReportV3RankingTest'
php -d memory_limit=1024M artisan test --filter='AtlasForgeRivalsRunBatteryTest|AtlasForgeRivalsRunBatteryReleaseTest|AtlasForgeRivalsReportV3Test|AtlasForgeRivalsReportV3RankingTest|AtlasForgeRivalsBatteryReportV3MultiCaseTest|AtlasForgeRivalsMatrixReportV1Test|AtlasForgeRivalsMatrixEvidenceLockTest|AtlasForgeRivalsPerfectBatteryTest|AtlasForgeRivalsProviderPerformanceLedgerServiceTest'
php -d memory_limit=1024M artisan atlas:forge:rivals report --run-id=battery-20260516-145210-yd5pil --json
php -d memory_limit=1024M artisan atlas:forge:rivals replay --run-id=battery-20260516-145210-yd5pil --json --strict
git diff --check
```

Não abrir nova frente até isso ficar verde.

## 10. Prompt de retomada

Cole o prompt abaixo em uma nova sessão Codex limpa:

```text
Estamos em /Users/vitorepf/develop/Atlas.

Leia primeiro este handoff canônico:
/Users/vitorepf/develop/Atlas/atlas-server/docs/engineering-knowledge-base/atlas-forge-continuum-os-session-handoff-2026-05-16.md

Missão atual:
Corrigir e validar o Atlas Forge Rivals com a bateria real de 40 casos, garantindo que o fluxo execute de ponta a ponta com providers reais, evidência/replay/report válidos e resultado justo/confiável Atlas vs Claude.

Prioridade imediata:
Não implementar nada novo de produto. Corrigir a falha unitária:
Tests\Unit\Ai\Programming\ForgeRivals\AtlasForgeRivalsReportV3RankingTest::test_signal_fallback_hint_when_replay_fails

Regra:
Se replay falha, provider_performance_signal.fallback_hint deve ser "rivals_signal_unusable_until_replay_passes".
Rivals não decide modelo/provider. Rivals só emite measured evidence advisory. Atlas Decide é dono de routing.

Comandos iniciais:
cd /Users/vitorepf/develop/Atlas/atlas-server
git status --short
rg -n "aggregateReplay|buildFallbackHint|provider_performance_signal|validity|replay_passes|primaryReplay" app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsReportService.php
nl -ba tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsReportV3RankingTest.php | sed -n '340,390p'

Depois:
1. Corrigir a menor causa do fallback_hint incorreto.
2. Rodar:
   php -d memory_limit=1024M artisan test --filter='AtlasForgeRivalsReportV3RankingTest'
3. Rodar o filtro focado Rivals:
   php -d memory_limit=1024M artisan test --filter='AtlasForgeRivalsRunBatteryTest|AtlasForgeRivalsRunBatteryReleaseTest|AtlasForgeRivalsReportV3Test|AtlasForgeRivalsReportV3RankingTest|AtlasForgeRivalsBatteryReportV3MultiCaseTest|AtlasForgeRivalsMatrixReportV1Test|AtlasForgeRivalsMatrixEvidenceLockTest|AtlasForgeRivalsPerfectBatteryTest|AtlasForgeRivalsProviderPerformanceLedgerServiceTest'
4. Revalidar bateria real:
   php -d memory_limit=1024M artisan atlas:forge:rivals report --run-id=battery-20260516-145210-yd5pil --json
   php -d memory_limit=1024M artisan atlas:forge:rivals replay --run-id=battery-20260516-145210-yd5pil --json --strict
5. Rodar git diff --check.

Restrições:
- Não resetar nem reverter mudanças de outros Claudes.
- Não abrir frente paralela.
- Não gastar novos tokens de provider até a suíte local focada ficar verde.
- Não chamar o resultado 76.88 vs 74.38 de vitória absoluta; é empate técnico com leve vantagem numérica do Atlas.
- Preservar external_rivals_certification blocked_requires_operator_approval.
- Preservar provider_performance_signal advisory_only=true, should_update_provider_topology=false, never_changes_atlas_decide_topology=true, owner_of_model_routing=atlas_decide, routing_effect=none.
```
