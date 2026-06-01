---
id: atlas-forge-rivals-real-battery-operator-harness-v1-part-02
type: engineering_knowledge
title: Atlas Forge Rivals Real Battery Operator Harness v1 · Parte 2
status: source_material
category: programming-forge
priority: 88
summary: Recorte focado de Atlas Forge Rivals Real Battery Operator Harness v1: Release multi-case (v2 single-button) ate Proximas Acoes.
tags:
  - atlas
  - forge
  - rivals
  - split-doc
capabilities:
  - forge_rivals_documentation_split
decisions:
  - Este recorte preserva detalhe operacional Rivals sem ampliar responsabilidade do índice canônico.
maintenance:
  - Manter abaixo de 520 linhas.
related_paths:
  - docs/engineering-knowledge-base/atlas-forge-rivals-real-battery-operator-harness-v1.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-forge-rivals-real-battery-operator-harness-v1-part-02
graph_title: Atlas Forge Rivals Real Battery Operator Harness v1 Parte 2
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-forge-rivals-real-battery-operator-harness-v1
graph_status: active
graph_source: repo
human_name: Atlas Forge Rivals Real Battery Operator Harness v1 Parte 2
canonical_name: Atlas Forge Rivals Real Battery Operator Harness v1 Parte 2
technical_name: atlas-forge-rivals-real-battery-operator-harness-v1-part-02
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-forge-rivals-real-battery-operator-harness-v1-part-02.md
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-forge-rivals-real-battery-operator-harness-v1-part-02.md
allowed_changes:
  - Atualizar somente o detalhe operacional desta parte.
forbidden_changes:
  - Transformar Rivals em routing, provider decision ou feature de produto.
depends_on:
  - atlas-forge-rivals-real-battery-operator-harness-v1
flows_to:
  - atlas-forge-rivals-reliability-lockdown-v1
unlocks:
  - forge_rivals_readable_cartography
governs:
  - forge_rivals_real_run_authorization
evidence:
  - docs/engineering-knowledge-base/atlas-forge-rivals-real-battery-operator-harness-v1.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Manter este recorte alinhado ao índice canônico.
---
# Atlas Forge Rivals Real Battery Operator Harness v1 · Parte 2

## Resumo

Este recorte preserva uma parte focada de Atlas Forge Rivals Real Battery Operator Harness v1: Release multi-case (v2 single-button) ate Proximas Acoes.

## Papel no Atlas

Mantém detalhe operacional Rivals fora do índice principal para que a cartografia continue legível.

## Onde Se Encaixa

É filho canônico de `docs/engineering-knowledge-base/atlas-forge-rivals-real-battery-operator-harness-v1.md`.

## Contratos

Rivals mede desempenho e evidência. Atlas Decide continua dono de routing/modelo.

## Fluxo

Índice Rivals → recorte operacional → comando/evidência/report correspondente.

## Regras para IA

Não transformar medição em decisão de provider. Não promover claim sem evidência, replay e gates.

## Escopo de Implementacao

Este arquivo guarda apenas o detalhe extraído do documento maior.

## Dependencias

Depende do índice canônico Rivals e do glossário.

## Evidencias

A evidência de origem é o documento principal e docs-health verde.

## Riscos

Risco principal: confundir harness/medição com decisão operacional do Atlas.

## Exemplos

Os exemplos abaixo são o conteúdo extraído, preservado sem perda semântica.

## Proximas Acoes

Atualizar quando o contrato correspondente mudar e rodar docs-health.

## Conteudo Extraido
## Release multi-case (v2 single-button)

A bateria release-ready usa o entrypoint canonico `atlas:forge:rivals run-battery`. Um unico comando, com tres confirmacoes obrigatorias, executa o corpus arena release inteiro (>=12 cases) em worktrees isolados, com streaming JSONL e per-case evidence:

```bash
/opt/homebrew/bin/php artisan atlas:forge:rivals run-battery \
  --preset=release \
  --mode=fair \
  --atlas-model=sonnet \
  --rival=claude_sonnet \
  --confirm-runbook-reviewed \
  --confirm-provider-cost \
  --confirm-real-provider-call \
  --json \
  --strict
```

Contrato:

- `--preset=release` sem `--case-set` mapeia automaticamente para o Provider Arena release corpus (todos os cases canonicos). Quick/smoke/full continuam apontando para a legacy single-case registry, sem regressao.
- Cada case e executado em sequencia no mesmo run_id. Entre cases, ambos os worktrees (atlas + baseline) sao resetados para HEAD via `git reset --hard HEAD` + `git clean -fdx`, garantindo que cada case parta de baseline deterministico.
- Per-case artifacts ficam em `runs/<run_id>/evidence/cases/<case_id>/{atlas_receipt.json, rival_receipt.json, workspace_hashes.json, atlas_patch.diff, rival_patch.diff, atlas_test.log, rival_test.log, atlas_provider_stdout.log, atlas_provider_stderr.log, rival_provider_stdout.log, rival_provider_stderr.log}`.
- Top-level `runs/<run_id>/evidence/{atlas_receipt.json, rival_receipt.json, workspace_hashes.json, manifest.json}` continua existindo como worst-of agregado (exit_code/test_exit_code = primeiro != 0, patch_diff_bytes = MIN, changed/oos/bytecode = UNION). Esse agregado e o que o adjudicator existente le sem precisar de mudancas: qualquer case que sangra fora do escopo, deixa bytecode, falha teste ou falha provider trip a hard gate.
- `manifest.json` ganha campos novos `case_count`, `is_multi_case`, `cases[]`. Para single-case o shape permanece **identico** ao v1 (zero shape drift).
- `events.jsonl` emite `case_started`/`case_finished` por case, alem dos eventos existentes (`provider_started`, `provider_stdout_chunk`, `heartbeat`, `after_clean_check`, `evidence_pack`, `final_report`). Cada `provider_started` carrega `case_id` e `case_subdir`.
- `claim_ready=true` exige TODOS os cases comparable, nenhum killed, e modo != local_fake. Qualquer caso invalido forca `claim_ready=false`. local_fake nunca claima.
- `verdict` agregado e worst-of: `invalid_workspace_after_run` > `invalid_provider_timeout` > `inconclusive` > `invalid_tests_failed` > `invalid_no_patch_diff` > `comparable`.
- `separated_from_external_rivals_certification=true` em todo response, sempre.

### Diferenca dry-run vs run real

- `dry-run`: provider NUNCA invocado. Planeja replay manifest, calcula fingerprint, valida case-set. Mesmo com `--preset=release` nao gasta token. Nao exige confirmacoes.
- `run-real` (e `run-battery` em modo fair/full_power): exige as tres confirmacoes simultaneas; sem qualquer uma o response e `status=blocked` com `missing_confirmation:<flag>`, `external_provider_call=false`, `provider_tokens_spent=false`.

### Fail-closed honesto

O response ja carrega tudo que adjudicator/Claude C/D/E precisam para decidir:

- `status` ∈ {ok, blocked}
- `verdict` ∈ {comparable, invalid_workspace_after_run, invalid_provider_timeout, inconclusive, invalid_tests_failed, invalid_no_patch_diff, invalid_fixture_blocked}
- `claim_ready` (sempre false ate adjudicator + report rodarem em pipeline verde)
- `external_provider_call`, `provider_tokens_spent` (nunca `true` sem as tres confirmacoes)
- `case_count`, `is_multi_case`, `cases[]` (matter-prima multi-case)
- `evidence_paths[]` (lista plana com top-level + per-case artifacts)

### Como Claude C/D/E consomem

- Claude C (adjudicator final): le `manifest.cases[]`, `manifest.score`, `manifest.verdict`, e os per-case receipts em `evidence/cases/<case_id>/`. Hard gates ja sao tripados pelo agregado worst-of, mas Claude C pode reabrir caso a caso para scoring premium.
- Claude D (report premium): le manifest agregado + per-case summaries; gera report.md por case e overview agregado.
- Claude E (continuum / regressao): le decide-signal + ledger entries; consome `manifest.case_count` e `cases[].verdict` para estatistica longitudinal.

Nunca: nenhum desses agentes deve revisar `external_rivals_certification`. O runner garante `separated_from_external_rivals_certification=true` e `claim_ready=false` sempre.

## Nao e feature de produto

Rivals nao tem UI publica, nao gera nota promocional automatica, nao desbloqueia external_rivals_certification. O harness existe para que o time tenha um meio confiavel de medir Atlas Forge contra um baseline externo, em condicoes comparaveis, sem inventar score. Qualquer tentacao de transformar isso em feature deve ser bloqueada na revisao.

## Fluxo correto (11 acoes)

```
1.  Provisionar worktrees limpos (Atlas arm + baseline arm)
2.  Limpar .pyc rastreado (uma vez, com commit explicito)
3.  Preflight Sonnet vs Sonnet
4.  Congelar readiness fingerprint
5.  Dry-run (planeja replay, nao chama provider)
6.  Confirmar runbook revisado, custo de provider e chamada real
7.  Run quick com tres confirmacoes
8.  Acompanhar JSONL streaming em tempo real
9.  Verify evidence pack (after-clean, replay, campos obrigatorios)
10. Decidir: claim valido | blocker | triage fingerprint-scoped
11. Registrar resultado + arquivar run_id
```

## Comandos copy-safe

Cada bloco e auto-contido e nao depende de variavel de ambiente externa. Substitua `<clean-atlas-worktree>` e `<clean-baseline-worktree>` pelos caminhos absolutos das worktrees.

```bash
# (1) Provisionar worktrees limpos
git -C /Users/vitorepf/develop/Atlas/atlas-server worktree add /tmp/rivals/atlas main
git -C /Users/vitorepf/develop/Atlas/atlas-server worktree add /tmp/rivals/baseline main

# (2) Limpar .pyc rastreado (uma unica vez, com commit explicito)
git -C /Users/vitorepf/develop/Atlas/atlas-server rm --cached -r 'runtimes/python/**/__pycache__' '*.pyc' '*.pyo'

# (3) Preflight Sonnet vs Sonnet
/opt/homebrew/bin/php artisan atlas:engineering:benchmark:rivals preflight \
  --workspace=<clean-atlas-worktree> \
  --claude-code-baseline-workspace=<clean-baseline-worktree> \
  --model=sonnet --baseline-model=sonnet \
  --json --strict

# (4) Congelar fingerprint (lido do payload preflight; estavel para dry-run + run)
# (sem comando separado: o fingerprint vive na saida JSON do preflight)

# (5) Dry-run
/opt/homebrew/bin/php artisan atlas:engineering:benchmark:rivals dry-run \
  --workspace=<clean-atlas-worktree> \
  --claude-code-baseline-workspace=<clean-baseline-worktree> \
  --model=sonnet --baseline-model=sonnet \
  --json --strict

# (6) Inspecionar runbook gerado
/opt/homebrew/bin/php artisan atlas:engineering:benchmark:rivals runbook \
  --workspace=<clean-atlas-worktree> \
  --claude-code-baseline-workspace=<clean-baseline-worktree> \
  --model=sonnet --baseline-model=sonnet \
  --markdown

# (7) Run quick real (tres confirmacoes obrigatorias)
/opt/homebrew/bin/php artisan atlas:engineering:benchmark:rivals run \
  --quick \
  --workspace=<clean-atlas-worktree> \
  --claude-code-baseline-workspace=<clean-baseline-worktree> \
  --model=sonnet --baseline-model=sonnet \
  --confirm-runbook-reviewed \
  --confirm-provider-cost \
  --json

# (8) Acompanhar logs streaming
tail -F /Users/vitorepf/develop/Atlas/atlas-server/storage/app/rivals-forge-runs/<run_id>/events.jsonl

# (9) Verify evidence pack
/opt/homebrew/bin/php artisan atlas:engineering:benchmark:rivals verify <run_id> --json

# (10) Replay (reproducir resultado sem novo provider call)
/opt/homebrew/bin/php artisan atlas:engineering:benchmark:rivals replay <run_id> --json

# (11) Triage de bateria invalida (fingerprint-scoped)
/opt/homebrew/bin/php artisan atlas:engineering:benchmark:rivals triage-invalid-battery <run_id> \
  --reviewer="<operator-handle>" \
  --reason="<motivo-objetivo>" \
  --confirm-invalid-battery-quarantine \
  --json
```

## Estados (state machine canonica)

A bateria transita por 15 estados/verdicts canonicos. Qualquer estado fora desta lista deve ser tratado como bug do harness.

1. `not_started` — operador ainda nao rodou preflight.
2. `preflight_blocked` — workspace dirty, fingerprint incompleto, baseline workspace invalido.
3. `preflight_ready` — fingerprint estavel, workspaces limpos.
4. `dry_run_planned` — replay planejado, sem provider.
5. `dry_run_blocked` — fingerprint divergente do preflight ou caso ausente.
6. `awaiting_confirmation` — confirmacoes pendentes antes do run real.
7. `run_in_progress` — runner emitindo JSONL, heartbeat ativo.
8. `run_stalled` — heartbeat parado alem do limite.
9. `run_complete_pending_verify` — runner terminou; verify ainda nao rodou.
10. `valid` — evidence pack ok, after-clean limpo, replay reproducivel.
11. `invalid_dirty_after_run` — workspace ficou dirty depois da execucao.
12. `invalid_evidence_missing` — campos obrigatorios ausentes no evidence pack.
13. `invalid_fingerprint_mismatch` — fingerprint do run != fingerprint congelado.
14. `quarantined_fingerprint_scoped` — triage registrou esse fingerprint como invalido; novo fingerprint pode rodar de novo.
15. `claimed_valid` — bateria virou claim auditavel apos verify + after-clean.

Resultado invalido (11, 12, 13) **nunca** vira score; o orchestrator forca `score=null`.

## Safety: tres gates de confirmacao

O run real exige na mesma invocacao:

- `--confirm-runbook-reviewed` — o operador leu o runbook gerado em (6) acima.
- `--confirm-provider-cost` — o operador aceita o gasto estimado em tokens.
- `--confirm-real-provider-call` — exigido somente quando o runner detecta provider live (preset full e cases sensiveis); o harness instrui o operador quando o terceiro gate e obrigatorio.

Faltar qualquer um dos tres bloqueia o run com motivo explicito. O harness nao infere consentimento.

## Triage fingerprint-scoped

Triage NUNCA invalida um suite inteiro. Ele invalida o fingerprint especifico daquela bateria. Mudou modelo, preset, workspace ou caso? Fingerprint muda, e o novo run pode rodar sem ser bloqueado pelo historico.

```bash
/opt/homebrew/bin/php artisan atlas:engineering:benchmark:rivals triage-invalid-battery <run_id> \
  --reviewer="atlas-operator-vitor" \
  --reason="baseline workspace dirty depois do run; nao reproducivel" \
  --confirm-invalid-battery-quarantine \
  --json
```

Cada triage exige `--reviewer`, `--reason` e `--confirm-invalid-battery-quarantine`. O registry guarda fingerprint, motivo, reviewer e timestamp ISO.

## Evidence + Logs

- Eventos JSONL em `storage/app/rivals-forge-runs/<run_id>/events.jsonl`.
- `run_id` ULID estavel desde o primeiro evento.
- `heartbeat` a cada 10s; ausencia por 60s gera `run_stalled`.
- `final_summary` carrega verdict, score (ou null), after_clean_check, replay_manifest, provider_receipt.
- `after_clean_check` re-roda hygiene apos o run e compara hash before/after.
- Evidence pack guarda before/after hash, diff, test log, quality log, provider receipt, replay manifest executado e timeline.

## Como rodar Sonnet vs Sonnet (passo a passo)

1. Provisionar `/tmp/rivals/atlas` e `/tmp/rivals/baseline` com `git worktree add`.
2. Garantir que ambos os worktrees estao em commit limpo (`git -C <path> status --porcelain` retorna vazio).
3. Rodar preflight Sonnet vs Sonnet com `--strict --json`.
4. Conferir `ready_for_provider_battery=true` e `readiness_fingerprint` no payload.
5. Rodar dry-run com os mesmos modelos e workspaces; conferir que o fingerprint nao mudou.
6. Gerar runbook em markdown e ler integralmente.
7. Rodar `run --quick` com `--confirm-runbook-reviewed --confirm-provider-cost`.
8. Acompanhar JSONL em outro terminal.
9. Apos termino, rodar verify e replay.
10. Se ambos retornarem ok, marcar como `claimed_valid`. Se algum falhar, abrir triage.

## Como interpretar resultado

- `verdict=valid` + `score>=0` + `after_clean=clean` + `replay=ok` -> claim valido.
- `verdict=invalid_*` -> score forcado para `null`. Nenhum claim sai daqui.
- `verdict=run_stalled` -> tratar como invalido; abrir triage com motivo `stalled_runner`.
- `verdict=quarantined_fingerprint_scoped` -> resultado nao publicavel; novo fingerprint pode tentar de novo.

## O que nunca vira claim

- Sem evidence pack real-run completo -> ZERO claim.
- Sem replay reproducivel -> ZERO claim.
- Sem after-clean check verde -> ZERO claim.
- Com fingerprint mismatch -> ZERO claim.
- Com qualquer gate de confirmacao faltando -> a bateria nem comeca.
- Mesmo com `score` numerico, se qualquer item acima falhar, score e descartado.

## Troubleshooting

| Sintoma | Causa provavel | Acao |
| --- | --- | --- |
| `workspace_dirty_before_run` | Mudancas nao commitadas no worktree | `git -C <worktree> status` e commitar/limpar |
| `tracked_python_bytecode_blocked` | `.pyc` rastreado no indice | Rodar o `git rm --cached` do passo (2) uma unica vez |
| `provider_unavailable` | CLI baseline nao encontrado ou key ausente | Conferir `--claude-code-baseline-binary` e env do provider |
| `fingerprint_mismatch` | Algum parametro mudou entre preflight e run | Re-rodar preflight + dry-run + run em sequencia, sem editar opcoes |
| `run_stalled` | Heartbeat parou; provider travou ou CLI bloqueou | Abrir triage com motivo `stalled_runner`; nao re-rodar sem novo fingerprint |
| `evidence_missing_after_clean_check` | Workspace ficou dirty depois do run | Marcar bateria como `invalid_dirty_after_run` e triage |

## Proximas Acoes

1. Provisionar dois worktrees limpos.
2. Rodar preflight + dry-run Sonnet vs Sonnet.
3. Executar quick real com as tres confirmacoes.
4. Rodar `bash scripts/rivals-harness-verify.sh` no fim de cada sessao.
5. Manter triage fingerprint-scoped — historico de invalidos nao deve poluir novo fingerprint.
6. Reabrir este doc antes de qualquer mudanca em comando, estado ou gate.
