---
title: Atlas Forge Continuum OS Session Handoff 2026-05-16 · Parte 3
status: source_material
owner: atlas-code
updated_at: 2026-05-16
canonical: false
line_limit: 300
source_parent: docs/engineering-knowledge-base/atlas-forge-continuum-os-session-handoff-2026-05-16.md
note: source_material split from frozen handoff; evidence only, not a canonical module doc.
---
# Atlas Forge Continuum OS Session Handoff 2026-05-16 · Parte 3

## Resumo

Recorte de material de sessão: 4. Estado atual ate 6. Estado do repo.

## Fonte

Arquivo pai: `docs/engineering-knowledge-base/atlas-forge-continuum-os-session-handoff-2026-05-16.md`.

## Conteudo Extraido

## 4. Estado atual

Ponto exato onde a sessão parou:

- A bateria real `battery-20260516-145210-yd5pil` já foi executada com provider real/tokens.
- Report/replay da bateria real foram revalidados nesta sessão.
- O report real mostra Atlas 76.88 vs Claude rival 74.38.
- O resultado correto é **empate técnico com leve vantagem numérica do Atlas**, não vitória absoluta.
- A vantagem vem da ponderação por dificuldade:
  - 30 casos ambos passaram;
  - 8 casos ambos falharam;
  - 1 caso só Claude passou: `testdesign-l1-add-edge-case-tests`, L1, peso 1;
  - 1 caso só Atlas passou: `testdesign-l5-mutation-baseline`, L5, peso 3;
  - diferença líquida = +2 pontos ponderados em 80 => +2.5 pontos.

Último bloco implementado antes do handoff:

- Ajustes para reforçar boundary Rivals vs Atlas Decide:
  - `AtlasForgeRivalsAdjudicatorV2Service.php`: `recommended_provider_signal` foi trocado para `measured_provider_signal` e `routing_effect=none`.
  - docs de Rivals foram ajustadas para dizer “sinal medido advisory”, não “recomendação de routing”.
  - `AtlasForgeRivalsRunRealService.php` recebeu proteção contra JSON inválido/UTF-8 inválido para evitar receipts 0-byte.
  - receipts 0-byte antigos do case `architecture-schema-versioned-receipt` foram recuperados explicitamente no run real, com campos de recovery.

Próximo bloco planejado:

- Corrigir a inconsistência do teste `AtlasForgeRivalsReportV3RankingTest::test_signal_fallback_hint_when_replay_fails`.
- Esse teste espera `fallback_hint = rivals_signal_unusable_until_replay_passes`, mas o service retornou `rivals_signal_flow_validated_only_do_not_use_for_routing`.

Pendências abertas:

- Investigar por que o seed de replay failed usado pelo teste está chegando em `buildFallbackHint()` com `validity['replay_passes']` truthy, ou por que o teste está exercendo um path de scorecard/manifest que não reflete replay failure.
- Rodar novamente o filtro focado de Rivals após corrigir.
- Rodar `git diff --check`.
- Opcional, rodar filtro amplo `Rivals|ForgeRivals|ForgeNativeRivals|FairClaudePolicy`.

Bloqueios reais:

- Unit test vermelho no filtro focado:
  - `Tests\Unit\Ai\Programming\ForgeRivals\AtlasForgeRivalsReportV3RankingTest::test_signal_fallback_hint_when_replay_fails`
  - erro: esperado `rivals_signal_unusable_until_replay_passes`, recebido `rivals_signal_flow_validated_only_do_not_use_for_routing`.

Decisões tomadas:

- Rivals é medição, não roteamento.
- Atlas Decide é dono de model routing e provider topology.
- Battery real pode gastar token quando explicitamente autorizado.
- Score só vale se harness não contaminar o jogo.
- Falha de competidor é dado medido; evidência ausente/replay drift é falha de harness.
- Resultados suspeitos, como Claude 0/100, devem bloquear claim e exigir triage.

Decisões ainda não tomadas:

- Se `provider_recommendation` legacy no `provider_performance_signal` deve ser removido de vez ou mantido por backward compatibility como alias neutro. Estado atual: mantido como legacy alias neutro, mas docs e novos campos usam `provider_measurement`.
- Se o próximo report schema deve ser v4 ou v3 incrementado. Estado atual: v3.
- Se report final deve considerar `claim_status.battery_result_valid=false` como nomenclatura confusa, já que `battery_evidence_valid=true` e report é interpretável. INCERTO: verificar intenção dos tests atuais antes de renomear.

## 5. Arquitetura e módulos

Módulos principais:

- Forge:
  - execução governada de programação;
  - Obra/Work Item/intake/plan/tasks;
  - provider invocation;
  - review/repair/completion.
- Continuum:
  - cadeia ponta-a-ponta de Forge;
  - certification;
  - topology/capacity/fallback/dispatch.
- Memory/Open Brain:
  - memória persistente e contexto operacional;
  - docs canônicas e project memories.
- Inbox:
  - entrada de intenção humana e transformação em Obra.
- Orchestration:
  - multi-agent e task queues;
  - Self-Construction OS;
  - supervisão por receipts/gates.
- Tool Runtime:
  - execução local, comandos, logs, evidence, sandbox/guardrails.
- Autonomy:
  - execução supervisionada, repair loops, approvals.
- Evaluation/Rivals:
  - provider arena;
  - batteries;
  - report/replay/score/evidence;
  - provider performance ledger consultivo.
- UI:
  - Atlas Code Desktop;
  - Forge cockpit;
  - Define/Review/Proofs/Advanced;
  - Self-Improvement cockpit;
  - visual/UX enterprise.

Como se conectam:

```text
Humano -> Inbox/Intake -> Obra -> Forge Plan -> Atlas Decide -> Provider Topology
       -> Governed Dispatch -> Provider Invocation -> Patch/Test/Evidence
       -> Review/Repair -> Completion Gate -> Ledger/Memory
       -> Rivals mede desempenho externo -> Provider Performance Signal advisory
       -> Atlas Decide pode consultar, mas decide routing sozinho.
```

Fronteiras que não devem ser violadas:

- Rivals não atualiza provider topology.
- Rivals não decide modelo.
- Provider Performance Ledger não faz routing.
- Completion claim não é promovido por dispatcher/invocation/rivals.
- External Rivals permanece `blocked_requires_operator_approval`.
- Fallback nunca é silencioso.
- Provider real exige aprovação/custo/label/policy.
- Atlas Code UI não pode inferir completion no frontend.

Padrões/gates/receipts estabelecidos:

- Decision Receipt;
- child Decision Receipt para fallback;
- provider invocation receipt;
- runtime dispatch plan;
- evidence pack;
- matrix evidence lock;
- replay manifest;
- provider performance signal advisory;
- completion audit certification;
- external rivals certification blocked.

## 6. Estado do repo

Diretório principal:

```text
/Users/vitorepf/develop/Atlas
```

Repos/pastas relevantes:

```text
/Users/vitorepf/develop/Atlas/atlas-server
/Users/vitorepf/develop/Atlas/atlas-desktop
/Users/vitorepf/develop/Atlas-rivals/runs/battery-20260516-145210-yd5pil
/Users/vitorepf/develop/Atlas-rivals/atlas-forge-sonnet
/Users/vitorepf/develop/Atlas-rivals/claude-sonnet-baseline
```

INCERTO: os dois worktrees/clones em `Atlas-rivals/atlas-forge-sonnet` e `Atlas-rivals/claude-sonnet-baseline` podem não existir em toda sessão. Verificar com:

```bash
ls -la /Users/vitorepf/develop/Atlas-rivals
git -C /Users/vitorepf/develop/Atlas-rivals/atlas-forge-sonnet status --short
git -C /Users/vitorepf/develop/Atlas-rivals/claude-sonnet-baseline status --short
```

Arquivos principais tocados no backend Rivals:

- `app/Console/Commands/AtlasForgeRivalsCommand.php`
- `app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsRunRealService.php`
- `app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsReportService.php`
- `app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsMatrixReportService.php`
- `app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsDecideSignalProjectionService.php`
- `app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsAdjudicatorService.php`
- `app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsAdjudicatorV2Service.php`
- `app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsBatteryStateService.php`
- `app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsRunPathResolver.php`
- `app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsSetupService.php`
- `app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsProviderArenaCorpusService.php`
- `app/Services/Ai/Programming/ForgeRivals/Arms/AtlasForgeRivalsArmRegistryService.php`

Docs canônicas relevantes:

- `docs/engineering-knowledge-base/atlas-forge-rivals-benchmark-strategy-v1.md`
- `docs/engineering-knowledge-base/atlas-forge-rivals-battery-modes-and-human-prompts-v1.md`
- `docs/engineering-knowledge-base/atlas-forge-rivals-provider-arena-corpus-v1.md`
- `docs/engineering-knowledge-base/atlas-forge-rivals-provider-arena-core-v1.md`
- `docs/engineering-knowledge-base/atlas-forge-rivals-perfect-battery-and-adjudicator-v1.md`
- `docs/engineering-knowledge-base/atlas-forge-rivals-matrix-report-v1.md`
- `docs/engineering-knowledge-base/atlas-forge-rivals-provider-performance-ledger-v1.md`
- `docs/engineering-knowledge-base/atlas-forge-rivals-reporting-v1.md`
- este handoff: `docs/engineering-knowledge-base/atlas-forge-continuum-os-session-handoff-2026-05-16.md`

Mudanças existentes que devem ser preservadas:

- todas as mudanças de Claude nas áreas Forge Rivals;
- mudanças de Self-Construction OS/Agent Control Plane;
- mudanças de Atlas Dev Efficient Flow;
- mudanças desktop em `atlas-ai`;
- corpus em `storage/forge-rivals-corpus`.

Áreas com diff misto ou risco de conflito:

- `atlas-server` está amplamente dirty com modificações de múltiplos Claudes.
- `atlas-desktop` está dirty com mudanças de Atlas AI/Atlas Dev.
- Não usar `git reset`, `git checkout --` ou remoções destrutivas.
- Não limpar `.pyc` sem comando explícito do operador; já houve discussão sobre bytecode rastreado e dirty workspaces.
