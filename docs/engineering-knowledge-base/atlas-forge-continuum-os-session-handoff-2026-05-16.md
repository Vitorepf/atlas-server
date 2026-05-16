---
title: Atlas Forge Continuum OS Session Handoff 2026-05-16
status: source_material
owner: atlas-code
updated_at: 2026-05-16
canonical: false
note: handoff snapshot frozen by date; tracked as source_material so docs-health treats it as evidence rather than a canonical module doc.
---

# Atlas Forge Continuum OS Session Handoff 2026-05-16

## 1. Identidade da sessão

Nome da sessão: **Atlas Forge Continuum OS**.

Missão principal: transformar o Atlas Code/Forge em um sistema de programação assistida por IA governado, auditável, local-first, forte o suficiente para executar Obras reais com provider real quando autorizado, e mensurável contra rivais externos por baterias justas.

O Forge Continuum OS dentro do Atlas é o eixo operacional que conecta:

- intenção humana;
- Obra;
- Work Item;
- intake;
- contexto;
- plano;
- topology/provider decision;
- execução governada;
- review/repair;
- evidência;
- completion gate;
- Rivals/evaluation;
- aprendizado posterior para Atlas Decide.

Problema resolvido: antes, o Atlas tinha muitos blocos fortes isolados, mas o operador humano ficava perdido e a arquitetura podia parecer “read-model certificado” sem provar execução real. O Forge Continuum OS cria uma cadeia única com receipts, blockers, audit, replay, UI e evidência, para que cada avanço seja verificável e não dependa de confiança verbal em uma IA.

Problema de produto: o humano precisa saber, a qualquer momento, “o que está acontecendo”, “por que parou”, “qual é o próximo botão seguro”, “se gastou token”, “se provider externo foi chamado”, “se pode aprovar”, “onde está a evidência”, e “se o Atlas está de fato melhorando em relação a Claude/Codex/Gemini/provider puro”.

## 2. Meta do Rivals

Neste contexto, **Rivals** é o harness de avaliação comparativa do Atlas Forge contra outros competidores ou contra outros modos do próprio Atlas. Ele não é um sistema de roteamento de provider. Ele não escolhe modelo certo. Ele mede desempenho real e produz evidência consultiva.

Sistemas/produtos/comportamentos a comparar:

- Atlas Forge vs Claude Code;
- Atlas Forge vs Codex;
- Atlas Forge vs Gemini;
- Claude Code vs Codex;
- Sonnet vs Opus;
- Atlas Forge modo justo vs Atlas Forge modo poder total;
- Atlas Forge usando Sonnet vs Atlas Forge usando Codex;
- category batteries: frontend/UI, backend/lógica, bugfix realista, refactor, test design, arquitetura, integração/performance, planejamento;
- prompt modes: `spec-perfect`, `human-normal`, `messy-real`, `enterprise-change`.

Critérios de comparação:

- resultado funcional;
- aderência ao escopo;
- qualidade de patch;
- testes e evidência;
- governança;
- custo;
- tempo;
- dirty workspace before/after;
- replay;
- completion safety;
- confiança da bateria;
- performance por categoria;
- performance por dificuldade L1-L5.

Métricas de sucesso:

- bateria real com provider real, não apenas local fake;
- run id persistido;
- 40 casos release executados;
- 8 categorias com 5 dificuldades cada;
- evidência por caso: manifest, scorecard, atlas/rival receipts, atlas/rival patches, atlas/rival test logs, workspace hashes, difficulty band;
- replay válido;
- matrix evidence lock OK;
- no hard failures de harness;
- no suspicious cases não triados;
- score determinístico;
- report v3/vNext legível;
- claim externo continua bloqueado por design até aprovação humana;
- `provider_performance_signal` advisory-only, sem alterar topology.

O que precisa existir para dizer que o Atlas venceu ou chegou no nível esperado:

- score do Atlas maior que rival por margem acima do tie threshold em bateria confiável;
- ou vitória por categoria/dificuldade claramente explicada;
- nenhum caso inválido contaminando o resultado;
- replay e evidence pack íntegros;
- custo/tempo medidos;
- conclusão humana possível: “Atlas foi melhor em X, pior em Y, empatado em Z”;
- sem usar Rivals como substituto do Atlas Decide.

Regra central de fronteira:

```text
Rivals emits measured evidence; Atlas Decide decides model routing.
```

Flags obrigatórias em qualquer projection Rivals -> Decide:

```json
{
  "advisory_only": true,
  "should_update_provider_topology": false,
  "never_changes_atlas_decide_topology": true,
  "owner_of_model_routing": "atlas_decide",
  "routing_effect": "none"
}
```

## 3. Níveis até o produto final

### Nível 0: Scaffold e contratos

Objetivo: criar schemas, CLIs, docs canônicas, services e tests mínimos sem chamar provider externo.

Capacidades esperadas:

- comandos existem;
- schemas versionados;
- receipts declarados;
- docs canônicas;
- tests unit/feature de contratos;
- blockers honestos.

Critérios de conclusão:

- `php artisan test --filter='Rivals|ForgeRivals|ForgeNativeRivals|FairClaudePolicy'` verde no escopo;
- `docs-health` sem violação no doc novo;
- `git diff --check` limpo.

Riscos:

- criar read-model que parece runtime real;
- mascarar bloqueios;
- inventar score sintético.

Não fazer ainda:

- provider real;
- claim de superioridade;
- auto-update de Atlas Decide.

### Nível 1: Fluxo local mínimo

Objetivo: provar que o harness roda sem provider real usando `local_fake` e gera evidence/replay/report comparável.

Capacidades esperadas:

- setup de worktrees;
- preflight;
- dry-run;
- run local fake;
- evidence pack;
- replay;
- report.

Critérios de conclusão:

- bateria local fake multi-case passa;
- dirty workspace before/after bloqueia corretamente;
- missing artifact invalida case;
- score só aparece quando a evidência é válida.

Riscos:

- local fake esconder bug do provider real;
- single-case v2 parecer multi-case release.

Não fazer ainda:

- dizer que Atlas venceu provider real.

### Nível 2: Memória/contexto confiável

Objetivo: preservar manifests, receipts, logs e histórico de runs em formato reexecutável.

Capacidades esperadas:

- run path resolver canônico;
- evidence por case;
- replay manifest;
- matrix lock;
- report v3;
- provider performance ledger.

Critérios de conclusão:

- report/replay conseguem reabrir uma bateria real existente;
- hashes e paths sobrevivem à troca de sessão;
- qualquer 0-byte receipt ou UTF-8 inválido não derruba o report silenciosamente.

Riscos:

- registros parciais virarem claim;
- report agregar case inválido como score válido.

Não fazer ainda:

- ledger alimentar routing automático.

### Nível 3: Orquestração e ferramentas reais

Objetivo: rodar providers reais em worktrees isolados e coletar custo/tempo/evidência.

Capacidades esperadas:

- `run-battery` release real;
- model lock: sonnet/opus/codex/gemini quando configurados;
- provider policy labels;
- streaming logs;
- stall detector;
- real provider receipt obrigatório;
- custo e duração por arm/case;
- after-clean-check obrigatório.

Critérios de conclusão:

- bateria real Atlas vs Claude Sonnet executada;
- report trusted;
- replay passes;
- no hard failures;
- custo e tempo registrados.

Riscos:

- provider programmatic policy bloquear Rivals;
- provider stdout inválido gerar JSON vazio;
- prompt muito “spec-perfect” favorecer sistemas governados demais.

Não fazer ainda:

- auto-promover conclusão externa.

### Nível 4: Autonomia supervisionada

Objetivo: permitir que Atlas Forge execute e repare sob governança, com humano aprovando quando necessário.

Capacidades esperadas:

- repair loop;
- review gate;
- completion gate;
- rollback;
- provider fallback com child receipt;
- capacity/failure memory;
- operator approval explícito para provider externo.

Critérios de conclusão:

- completion claim só aparece com evidência + review;
- fallback nunca é silencioso;
- external rivals continua separado e bloqueado.

Riscos:

- over-automation;
- “passar no teste” sem entregar produto.

Não fazer ainda:

- auto-merge sem humano.

### Nível 5: Produto diário confiável

Objetivo: tornar Atlas Code/Forge usável por horas de trabalho humano real.

Capacidades esperadas:

- UI com feedback enterprise;
- tela nunca morta;
- “o que está acontecendo” sempre visível;
- próximo passo seguro;
- milestones da Obra;
- safety strip;
- status de custo/provider/token;
- terminal/logs integrados;
- evidence/review/provas legíveis.

Critérios de conclusão:

- operador consegue usar sem explicação externa;
- UX mínima 8.5/10;
- build desktop verde;
- Playwright/browser visual check quando houver mudança frontend.

Riscos:

- UI bonita mas sem semântica;
- informação técnica demais no caminho principal.

Não fazer ainda:

- esconder diagnósticos avançados sem acesso.

### Nível final: Atlas local-first completo

Objetivo: Atlas operar como sistema pessoal local-first completo para programação, memória, avaliação e melhoria contínua.

Capacidades esperadas:

- Forge Continuum OS;
- Atlas Decide com runtime topology;
- Memory/Open Brain;
- Inbox;
- Tool Runtime;
- Autonomy;
- Self-Construction OS;
- Evaluation/Rivals;
- UI premium;
- provider arena e provider ledger como fonte consultiva.

Critérios de conclusão:

- Atlas consegue propor, executar, medir, aprender e melhorar com auditoria;
- Atlas Decide usa dados reais, mas continua dono de routing;
- humano mantém controle de custo, risco e aprovação.

Riscos:

- misturar medição com decisão;
- enfraquecer gates para parecer mais autônomo.

Não fazer ainda:

- permitir que Rivals escreva topology final;
- desbloquear external rivals sem aprovação explícita.

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

## 7. Implementações realizadas

Blocos concluídos nesta sequência ampla:

- Atlas Decide -> provider topology read-model e fallback;
- runtime dispatcher governado;
- provider capacity/failure memory;
- governed provider invocation;
- real provider drivers policy/driver wiring;
- self-improvement governance ladder;
- self-improvement forge activation;
- human-first Forge UX;
- Atlas Code UI/UX refinement;
- Rivals reliability lockdown;
- Rivals operator harness;
- Rivals perfect battery/adjudicator;
- Provider Arena corpus;
- 40-case release matrix com 8 categorias x 5 dificuldades;
- matrix report/evidence lock;
- provider performance ledger advisory;
- real battery Atlas vs Claude Sonnet.

Arquivos alterados por bloco: ver `git status --short` e docs listadas acima. Esta sessão não produziu commit.

Pronto:

- bateria real existe e é reabrível por report/replay;
- report real apresenta 40 casos, 8 categorias, trusted battery;
- matrix lock OK;
- advisory boundary com Atlas Decide aparece no report real;
- sintaxe dos services principais Rivals passou em `php -l`.

Parcial:

- filtro focado de tests Rivals ainda tem 1 falha unitária no fallback hint de signal quando replay falha.
- `claim_status.battery_result_valid=false` pode ser nomenclatura confusa apesar de report interpretável; não corrigir sem entender testes/contrato.

Descartado/estacionado:

- usar Rivals para decidir provider/model diretamente;
- dizer que Atlas venceu globalmente apenas por 76.88 vs 74.38;
- aceitar score quando harness está contaminado.

Dívidas técnicas aceitas:

- aliases legacy como `provider_recommendation` ainda podem existir como alias neutro;
- alguns docs antigos do repo podem ter oversized/frontmatter warnings pré-existentes;
- worktree dirty por múltiplos Claudes precisa de revisão antes de merge/commit.

## 8. Validações

Comandos rodados nesta etapa final:

```bash
cd /Users/vitorepf/develop/Atlas/atlas-server

rg -n "recommended_provider|recommended_model|alternative_recommendation|recommended_provider_signal|advisory recommendation|recommendação Atlas Decide|Recomendação por Arm|Recomendações para Atlas Decide|routing por categoria|decidir routing|modelo certo" \
  app/Services/Ai/Programming/ForgeRivals \
  tests/Unit/Ai/Programming/ForgeRivals \
  docs/engineering-knowledge-base/atlas-forge-rivals-reporting-v1.md \
  docs/engineering-knowledge-base/atlas-forge-rivals-matrix-report-v1.md \
  docs/engineering-knowledge-base/atlas-forge-rivals-provider-performance-ledger-v1.md \
  docs/engineering-knowledge-base/atlas-forge-rivals-perfect-battery-and-adjudicator-v1.md
```

Resultado: única ocorrência sensível remanescente é frase desejada em `atlas-forge-rivals-reporting-v1.md` dizendo que Rivals não escolhe “modelo certo”; isso é trabalho do Atlas Decide.

```bash
php -l app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsRunRealService.php
php -l app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsReportService.php
php -l app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsMatrixReportService.php
php -l app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsDecideSignalProjectionService.php
php -l app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsAdjudicatorV2Service.php
```

Resultado: todos sem syntax errors.

Report real:

```bash
php -d memory_limit=1024M artisan atlas:forge:rivals report --run-id=battery-20260516-145210-yd5pil --json
```

Resumo real:

```json
{
  "status": "ok",
  "winner": "human_review_required_tie",
  "atlas_score": 76.88,
  "rival_score": 74.38,
  "score_source": "multi_case_deterministic_gate_rollup",
  "verdict": "multi_case_valid_with_case_failures",
  "replay_passes": true,
  "hard_failures": [],
  "confidence": {
    "level": "trusted_battery",
    "cases": 40,
    "categories": 8,
    "suspicious_count": 0
  },
  "matrix": {
    "ok": true,
    "invalid": 0,
    "blocks": false
  }
}
```

Replay real:

```bash
php -d memory_limit=1024M artisan atlas:forge:rivals replay --run-id=battery-20260516-145210-yd5pil --json --strict
```

Resumo observado:

```json
{
  "status": "ok",
  "run_id": "battery-20260516-145210-yd5pil",
  "replay_passes": true,
  "verdict": "invalid_tests_failed",
  "external_provider_call": false,
  "provider_tokens_spent": false
}
```

Observação: `verdict=invalid_tests_failed` no replay parece refletir failures reais de competidor/case, não falha de harness, porque `replay_passes=true` e o report/matrix estão OK. Confirmar antes de renomear.

Teste focado rodado:

```bash
php -d memory_limit=1024M artisan test --filter='AtlasForgeRivalsRunBatteryTest|AtlasForgeRivalsRunBatteryReleaseTest|AtlasForgeRivalsReportV3Test|AtlasForgeRivalsReportV3RankingTest|AtlasForgeRivalsBatteryReportV3MultiCaseTest|AtlasForgeRivalsMatrixReportV1Test|AtlasForgeRivalsMatrixEvidenceLockTest|AtlasForgeRivalsPerfectBatteryTest|AtlasForgeRivalsProviderPerformanceLedgerServiceTest'
```

Resultado:

- 139 passed;
- 5 skipped;
- 1 failed.

Falha:

```text
Tests\Unit\Ai\Programming\ForgeRivals\AtlasForgeRivalsReportV3RankingTest::test_signal_fallback_hint_when_replay_fails
Failed asserting that two strings are identical.
- 'rivals_signal_unusable_until_replay_passes'
+ 'rivals_signal_flow_validated_only_do_not_use_for_routing'
```

Testes pendentes:

- reexecutar filtro acima após fix;
- `git diff --check`;
- opcional amplo: `php -d memory_limit=1024M artisan test --filter='Rivals|ForgeRivals|ForgeNativeRivals|FairClaudePolicy'`;
- docs-health se docs adicionais forem alteradas.

Avisos conhecidos que não são regressão:

- `external_rivals_certification` permanece blocked por design.
- `claim_ready=false` mesmo com battery trusted: correto até aprovação humana e política externa.
- Alguns skips são por driver/corpus condition e não necessariamente falha.

Gates obrigatórios para continuar:

- sem syntax error;
- tests focados Rivals verdes;
- report real reabre;
- replay real passa;
- matrix evidence lock OK;
- no hard failures de harness;
- advisory-only boundary preservada.

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

