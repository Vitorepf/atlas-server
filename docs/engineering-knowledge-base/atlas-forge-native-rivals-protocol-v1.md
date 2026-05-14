---
id: atlas-forge-native-rivals-protocol-v1
type: engineering_knowledge
title: Atlas Forge-Native Rivals Protocol v1
status: active
category: programming-forge
priority: 100
summary: Protocolo canonico que define que toda bateria Rivals do Atlas avalia exclusivamente o runtime Forge contra um rival externo isolado. Atlas arm = Forge obrigatorio; rival arm = baseline puro; dry-run, preflight e bateria real sao planos separados sem confundir claim com plano.
tags:
  - atlas
  - rivals
  - forge
  - programming
  - protocol
  - clean-battery
capabilities:
  - forge_native_rivals_protocol
  - forge_native_rivals_preflight
  - forge_native_rivals_dry_run
  - forge_native_rivals_case_manifest
decisions:
  - Atlas arm em Rivals SEMPRE roda atraves do Forge. Atlas runs non-Forge sao invalidos para score Rivals.
  - Rival arm e baseline puro (claude_code_baseline, codex_baseline, external_baseline, manual_baseline) e roda em workspace separado.
  - Dry-run nunca chama provider externo. Preflight nunca chama provider externo. Bateria real exige aprovacao operadora explicita.
  - Forge-Native Rivals certification e separada de external_rivals_certification e nunca promove completion sozinho.
  - Synthetic score nao e admitido. Workspace dirty bloqueia bateria paga.
maintenance:
  - Atualize este doc antes de mudar protocolo, preflight, manifest, dry-run ou completion audit de Rivals.
  - Mantenha como pagina-mae dos servicos AtlasForgeNativeRivals*.
related_paths:
  - docs/engineering-knowledge-base/atlas-programming-forge-flow.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
  - docs/engineering-knowledge-base/atlas-forge-runtime-certification-one-shot.md
  - docs/engineering-knowledge-base/atlas-code-forge-fast-path-v1.md
  - docs/engineering-knowledge-base/atlas-code-forge-review-completion-gate-v1.md
  - docs/engineering-knowledge-base/domains/programming-professional-completion-audit.md
  - docs/engineering-knowledge-base/domains/programming-professional-rag-operating-standard.md
  - app/Services/Ai/Programming/AtlasForgeNativeRivalsProtocolService.php
  - app/Services/Ai/Programming/AtlasForgeNativeRivalsCaseManifestService.php
  - app/Services/Ai/Programming/AtlasForgeNativeRivalsPreflightService.php
  - app/Services/Ai/Programming/AtlasForgeNativeRivalsDryRunService.php
  - app/Console/Commands/AtlasProgrammingRivalsForgePreflightCommand.php
  - app/Console/Commands/AtlasProgrammingRivalsForgeDryRunCommand.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-forge-native-rivals-protocol-v1
graph_title: Atlas Forge-Native Rivals Protocol v1
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-programming-forge-flow
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-forge-native-rivals-protocol-v1.md
allowed_changes:
  - Ampliar invariantes, casos e checagens quando o runtime Forge ou os baselines Rivals evoluirem.
  - Endurecer gates (nunca afrouxar).
forbidden_changes:
  - Permitir Atlas arm sem Forge.
  - Aceitar score sintetico.
  - Permitir dry-run virar claim.
  - Remover external_rivals_certification do completion audit.
  - Enfraquecer workspace clean / baseline isolado / aprovacao operadora.
depends_on:
  - atlas-programming-forge-flow
  - atlas-forge-operating-system
  - atlas-code-forge-fast-path-v1
  - atlas-code-forge-review-completion-gate-v1
flows_to:
  - programming-professional-completion-audit
unlocks:
  - forge-native-rivals-clean-battery-v1
  - rivals-protocol-validity
governs:
  - rivals_atlas_arm_runtime
  - rivals_rival_arm_runtime
  - rivals_workspace_policy
  - rivals_replay_manifest
evidence:
  - docs/engineering-knowledge-base/atlas-forge-native-rivals-protocol-v1.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:programming:rivals-forge-preflight --json --strict"
  - "php artisan atlas:programming:rivals-forge-dry-run --json --strict"
  - "php artisan atlas:programming:completion-audit --json"
requires_evidence: true
risk_level: critical
visual_tags:
  - rivals
  - forge
  - protocol
ai_entrypoints:
  - Antes de propor ou rodar qualquer bateria Rivals, ler este doc.
  - Atlas arm em Rivals = Forge obrigatorio.
ai_usage_notes:
  - Dry-run e preflight sao planejamento sem provider. Nunca declarar claim a partir deles.
  - Bateria real so depois de operator approval explicito.
quality_gates:
  - docs-health
  - architecture-validate
  - programming-completion-audit
  - rivals-forge-preflight
  - rivals-forge-dry-run
failure_modes:
  - Rodar Rivals com Atlas arm fora de Forge.
  - Confundir dry-run com claim.
  - Misturar dirty workspace ou baseline colidindo com Atlas workspace.
  - Pagar provider sem aprovacao operadora ou sem case comparable.
observability_signals:
  - forge_native_rivals_certification.status
  - forge_native_rivals_preflight.status
  - forge_native_rivals_dry_run.status
  - case_manifest.atlas_arm_is_forge
next_actions:
  - Rodar `php artisan atlas:programming:rivals-forge-preflight --json` antes de qualquer planejamento de bateria.
  - Rodar `php artisan atlas:programming:rivals-forge-dry-run --case=<id> --json --strict` para validar planejamento sem custo.
  - Apenas com preflight=ready_for_provider_battery e aprovacao operadora, despachar bateria real via runner governado.
---
# Atlas Forge-Native Rivals Protocol v1

## Resumo

Toda avaliacao Atlas em Rivals deve medir o **runtime Forge** contra um
**baseline externo isolado**. Nao existe caminho Atlas paralelo ou ad hoc
dentro do Rivals. O rival nao contamina o core Forge, e nenhum resultado
Rivals pode promover completion sem caso comparavel, workspace limpo,
protocolo valido, gates equivalentes e evidencia replayable.

## Papel no Atlas

Este doc e o contrato canonico que governa **toda** bateria Rivals do
Atlas. Ele declara que o Atlas arm sempre roda atraves do runtime Forge
(via `atlas:code:forge-fast-path`) e que o rival arm e um baseline
externo isolado. Sem este contrato, Rivals poderia comparar Atlas
ad-hoc com rival e gerar score falso.

## Onde Se Encaixa

- Acima: `atlas-programming-forge-flow.md` (mapa do fluxo pesado).
- Lado: `atlas-code-forge-fast-path-v1.md`, `atlas-code-forge-review-completion-gate-v1.md`,
  `atlas-forge-runtime-certification-one-shot.md`.
- Consumido por: `programming-professional-completion-audit.md`,
  `programming-professional-rag-operating-standard.md`,
  `ProgrammingProfessionalCompletionAuditService::forgeNativeRivalsCertification()`.

## Princípio central

- Atlas side = sempre Forge.
- Rivals side = baseline externo/controlado.
- A bateria mede Atlas Forge contra rival, nao "qualquer Atlas" contra rival.
- Dry-run, preflight e bateria real sao **planos separados**. Apenas a
  bateria real, com aprovacao operadora, pode gerar evidencia para claim.

## Contratos

| Schema | Quem produz | Quem consome |
|--------|-------------|--------------|
| `atlas.programming.forge_native_rivals_protocol.v1` | `AtlasForgeNativeRivalsProtocolService` | preflight, dry-run, completion audit |
| `atlas.programming.forge_native_rivals_case_manifest.v1` | `AtlasForgeNativeRivalsCaseManifestService` | preflight, dry-run |
| `atlas.programming.forge_native_rivals_preflight.v1` | `AtlasForgeNativeRivalsPreflightService` + `atlas:programming:rivals-forge-preflight` | operador, completion audit |
| `atlas.programming.forge_native_rivals_dry_run.v1` | `AtlasForgeNativeRivalsDryRunService` + `atlas:programming:rivals-forge-dry-run` | operador, completion audit |
| `atlas.programming.forge_native_rivals_replay_manifest.v1` | dry-run | bateria real (futuro) |
| `atlas.programming.forge_native_rivals_certification.v1` | `ProgrammingProfessionalCompletionAuditService` | `atlas:programming:completion-audit` |

## Fluxo

1. Operador roda `atlas:programming:rivals-forge-preflight --json --strict`.
2. Se ready_for_dry_run, roda `atlas:programming:rivals-forge-dry-run --case=<id> --json --strict` para planejar paired case.
3. Se intencao for bateria paga, operador cria worktree Atlas limpo +
   baseline limpo separado e roda preflight com
   `--intends-provider-battery --confirm-runbook-reviewed --confirm-provider-cost`.
4. Apenas com preflight=ready_for_provider_battery o runner governado
   pode dispatch real (fora do escopo deste protocolo; governado por
   `external_rivals_certification`).
5. `atlas:programming:completion-audit --json` reporta os dois eixos
   separados.

## Regras para IA

- Nao chamar provider externo no preflight ou dry-run.
- Nao remover bloqueio `external_rivals_certification` sem bateria real valida.
- Nao aceitar score sintetico.
- Nao permitir Atlas side sem Forge.
- Nao usar workspace dirty para bateria paga.
- Nao confundir dry-run com bateria real.
- Toda claim precisa ter teste, comando ou evidence path.

## Escopo de Implementacao

| Componente | Path |
|------------|------|
| Protocol Service | `app/Services/Ai/Programming/AtlasForgeNativeRivalsProtocolService.php` |
| Case Manifest Service | `app/Services/Ai/Programming/AtlasForgeNativeRivalsCaseManifestService.php` |
| Preflight Service | `app/Services/Ai/Programming/AtlasForgeNativeRivalsPreflightService.php` |
| Dry-Run Service | `app/Services/Ai/Programming/AtlasForgeNativeRivalsDryRunService.php` |
| Preflight CLI | `app/Console/Commands/AtlasProgrammingRivalsForgePreflightCommand.php` |
| Dry-Run CLI | `app/Console/Commands/AtlasProgrammingRivalsForgeDryRunCommand.php` |
| Completion audit block | `forgeNativeRivalsCertification()` em `ProgrammingProfessionalCompletionAuditService.php` |
| Feature tests | `tests/Feature/Ai/Programming/AtlasForgeNativeRivalsTest.php` |

## Dependencias

- `atlas-programming-forge-flow` — mapa do fluxo Forge.
- `atlas-forge-operating-system` — fabrica que ata Atlas Code → Forge.
- `atlas-code-forge-fast-path-v1` — gateway canonico do Atlas arm.
- `atlas-code-forge-review-completion-gate-v1` — review gate exigido.
- `programming-professional-completion-audit` — consome a certification.

## Evidencias

Comandos minimos para sustentar este protocolo:

```bash
php artisan atlas:engineering:knowledge docs-health --json
php artisan atlas:ai:architecture-validate --json
php artisan atlas:programming:rivals-forge-preflight --json --strict
php artisan atlas:programming:rivals-forge-dry-run --json --strict
php artisan atlas:programming:completion-audit --json
php artisan test --filter='AtlasForgeNativeRivalsTest'
```

## Riscos

| Risco | Bloqueio correto |
|-------|------------------|
| Atlas arm rodar fora do Forge | `case_manifest.atlas_arm_is_forge=false` → `blocked_protocol_invalid` |
| Provider externo despachado de readiness/preflight | preflight nao chama provider; dispatch exige `--confirm-provider-cost` |
| Score sintetico admitido | `synthetic_scores_allowed=false` em protocol, preflight, dry-run e certification |
| Workspace dirty contaminando bateria | preflight bloqueia `workspace_dirty_or_not_git` antes de tudo |
| Baseline colidindo com Atlas workspace | `baseline_workspace_collides_with_atlas_workspace` bloqueia |
| Dry-run virar claim | `forge_native_rivals_certification.promotes_completion_claim=false` |
| `external_rivals_certification` ser liberado por dry-run | Eixos separados; dry-run nunca remove bloqueio external |

## Escopo canonico

| Eixo | Atlas arm | Rival arm |
|------|-----------|-----------|
| Runtime | `forge` (obrigatorio) | `claude_code_baseline`, `codex_baseline`, `external_baseline`, `manual_baseline` |
| Comandos | `atlas:code:forge-fast-path`, `atlas:code:forge-fast-path-status`, `atlas:code:forge-review` | CLI/baseline externo isolado |
| Workspace | Atlas workspace limpo | Workspace baseline **separado** e limpo |
| Aprovacao | Nao requer pagar — Forge core ja certificado | Provider externo requer `--confirm-provider-cost` e `--confirm-runbook-reviewed` |
| Score | Apenas com caso comparavel + replay manifest + evidence pack | Idem |

## Schemas canonicos

- `atlas.programming.forge_native_rivals_protocol.v1` — contrato declarativo
- `atlas.programming.forge_native_rivals_case_manifest.v1` — manifest por case
- `atlas.programming.forge_native_rivals_preflight.v1` — diagnostico read-only
- `atlas.programming.forge_native_rivals_dry_run.v1` — plano sem provider
- `atlas.programming.forge_native_rivals_certification.v1` — eixo do
  completion audit (separado de `external_rivals_certification`)
- `atlas.programming.forge_native_rivals_replay_manifest.v1` — pacote de replay

## Invariantes (resumo)

- `atlas_side_must_use_forge = true`
- `same_case_required = true`
- `same_initial_state_required = true`
- `same_acceptance_gates_required = true`
- `same_timeout_policy_required = true`
- `same_quality_scope_required = true`
- `clean_workspace_required = true`
- `separate_baseline_workspace_required = true`
- `replay_manifest_required = true`
- `evidence_pack_required = true`
- `human_intervention_accounting_required = true`
- `provider_cost_approval_required = true`
- `synthetic_scores_allowed = false`

## `invalid_if`

Um case e marcado invalido (e nao pode gerar score) quando qualquer destes
ocorre:

- `atlas_not_forge` — Atlas arm rodou em runtime fora do Forge.
- `no_same_initial_state` — estado inicial divergente entre arms.
- `missing_acceptance_gates` — gates equivalentes ausentes.
- `missing_replay_manifest` — sem replay manifest.
- `provider_fallback_unapproved` — provider externo sem aprovacao.
- `dirty_workspace` — workspace nao limpo na hora do snapshot.
- `baseline_workspace_collides_with_atlas_workspace` — baselines compartilham
  raiz com Atlas workspace.
- `synthetic_score_admitted` — qualquer pontuacao gerada sinteticamente.

## Estados do preflight

- `ready_for_dry_run` — protocolo valido, workspace limpo, docs presentes,
  Forge runtime disponivel. Pode prosseguir para dry-run sem custo.
- `ready_for_provider_battery` — alem de tudo, baseline workspace separado
  + aprovacao operadora + intent declarado. Pode despachar bateria real
  via runner governado.
- `blocked_dirty_workspace` — workspace Atlas dirty ou nao-Git.
- `blocked_missing_forge_runtime` — classes/comandos Forge ausentes.
- `blocked_missing_baseline_workspace` — baseline ausente, colidente,
  sujo ou nao-Git.
- `blocked_requires_operator_approval` — falta `--confirm-provider-cost`
  ou `--confirm-runbook-reviewed`.
- `blocked_protocol_invalid` — case manifest invalido (Atlas arm nao-Forge,
  doc canonica ausente etc.).

## Diferenca dry-run × preflight × bateria real × claim

| Conceito | O que faz | Provider | Promove claim? |
|----------|-----------|----------|----------------|
| Protocolo | Retorna o contrato canonico | Nao | Nao |
| Preflight | Diagnostica estado e bloqueia condicoes invalidas | Nao | Nao |
| Dry-run | Materializa case + replay manifest planejado | Nao | Nao |
| Bateria real | Roda Atlas Forge + rival com evidencia | Sim, com aprovacao | Talvez, somente se caso for valido e comparavel |
| Claim | Promote completion para Rivals | — | Apenas via `external_rivals_certification` ainda aprovada |

`forge_native_rivals_certification` no completion audit pode ficar
`available` ja a partir de protocolo + dry-run, **sem** liberar
`external_rivals_certification`. O claim Rivals continua bloqueado ate
existir bateria real valida e aprovada.

## Diferenca para `external_rivals_certification`

`external_rivals_certification` continua sendo o eixo que governa o claim
Rivals final, e permanece `blocked_requires_operator_approval` ate
existir bateria paga real **e** valida. `forge_native_rivals_certification`
nao remove esse bloqueio. Os dois eixos sao reportados separadamente.

## Por que provider externo exige aprovacao

Atlas trata pagar provider como uma acao com blast radius alta. O custo e
real (tokens), o resultado pode ser invalido se workspace estiver dirty,
e um caso mal-modelado contamina o ledger. Por isso:

- `--confirm-provider-cost` e `--confirm-runbook-reviewed` sao **obrigatorios**
  no command de preflight quando `--intends-provider-battery` for usado.
- Sem essas flags, a CLI marca `blocked_requires_operator_approval` em vez
  de prosseguir.

## Quais resultados podem ou nao promover completion

| Resultado | Promove `external_rivals_certification`? | Promove completion audit? |
|-----------|-----------------------------------------|---------------------------|
| Protocolo lido | Nao | Nao |
| Preflight `ready_for_dry_run` | Nao | Nao |
| Dry-run `dry_run_passed` | Nao | Nao |
| Bateria real com caso `invalid` | Nao | Nao (e nao deve rerunar antes de triagem) |
| Bateria real com caso `comparable` + replay manifest | Sim (sujeito a operator review) | Sim, apenas com `external_rivals_certification.status=passed` |

## Operacao tipica

1. `php artisan atlas:programming:rivals-forge-preflight --json --strict`
   — confirma estado do workspace, Forge runtime, docs e manifest.
2. `php artisan atlas:programming:rivals-forge-dry-run --case=<id> --json --strict`
   — planeja paired case e replay manifest sem provider.
3. (Opcional) Cria worktree limpa do Atlas e baseline em diretorio
   separado.
4. `--intends-provider-battery --confirm-runbook-reviewed --confirm-provider-cost`
   no preflight para certificar prontidao operacional.
5. Apenas entao operador despacha bateria real via runner governado
   (fora do escopo deste protocolo, governado por `external_rivals_certification`).
6. `php artisan atlas:programming:completion-audit --json` reporta os dois
   eixos: `forge_native_rivals_certification` e `external_rivals_certification`.

## Exemplos

### Preflight diagnostico (sem provider)

```bash
php artisan atlas:programming:rivals-forge-preflight --json
```

Saida (resumida):

```json
{
  "schema_version": "atlas.programming.forge_native_rivals_preflight.v1",
  "status": "blocked_dirty_workspace",
  "ready_for_dry_run": false,
  "ready_for_provider_battery": false,
  "atlas_side_must_use_forge": true,
  "external_provider_call": false,
  "blocking_reasons": ["workspace_dirty_or_not_git"]
}
```

### Dry-run de um case (sem provider)

```bash
php artisan atlas:programming:rivals-forge-dry-run \
  --case=atlas-fair-claude-baseline-case-01 \
  --json --strict
```

Saida (resumida):

```json
{
  "schema_version": "atlas.programming.forge_native_rivals_dry_run.v1",
  "status": "dry_run_passed",
  "external_provider_call": false,
  "provider_tokens_spent": false,
  "atlas_side_must_use_forge": true,
  "planned": {
    "replay_manifest": {
      "schema_version": "atlas.programming.forge_native_rivals_replay_manifest.v1",
      "state": "planned",
      "valid": true,
      "atlas_arm": { "runtime": "forge", "command_template": "php artisan atlas:code:forge-fast-path ..." },
      "rival_arm": { "runtime": "claude_code_baseline" }
    }
  }
}
```

### Completion audit com Forge-Native Rivals

```bash
php artisan atlas:programming:completion-audit --json | jq '.forge_native_rivals_certification, .external_rivals_certification'
```

Saida (resumida):

```json
{
  "schema_version": "atlas.programming.forge_native_rivals_certification.v1",
  "status": "available",
  "atlas_side_must_use_forge": true,
  "atlas_side_forge_runtime_verified": true,
  "separated_from_external_rivals_certification": true,
  "promotes_completion_claim": false
}
{
  "schema_version": "atlas.programming.rivals_readiness.v1",
  "status": "blocked_requires_operator_approval"
}
```

## Regras duras

- Nao chamar provider externo no preflight ou dry-run.
- Nao remover bloqueio `external_rivals_certification` sem bateria real valida.
- Nao aceitar score sintetico.
- Nao permitir Atlas side sem Forge.
- Nao usar workspace dirty para bateria paga.
- Nao confundir dry-run com bateria real.
- Toda claim precisa ter teste, comando ou evidence path.

## Camadas Filhas

- `atlas-rivals-one-shot-enterprise-evaluation-v1.md` — rubrica e evaluation diagnostica que pontuam a qualidade one-shot enterprise; nunca promove claim Rivals, eixo separado de `external_rivals_certification`.
- `atlas-rivals-evidence-pack-replay-manifest-v1.md` — Evidence pack local replayable que alimenta a evaluation com source/hash honestos; nunca chama provider externo nem promove claim.

## Proximas Acoes

1. Manter este protocolo como primeira leitura antes de qualquer planejamento Rivals.
2. Rodar `atlas:programming:rivals-forge-preflight --json --strict` antes de qualquer plano.
3. Rodar `atlas:programming:rivals-forge-dry-run --case=<id> --json --strict` para validar paired case sem custo.
4. Apenas com `--intends-provider-battery --confirm-runbook-reviewed --confirm-provider-cost` e worktrees limpos, despachar bateria real via runner governado.
5. Atualizar este doc e os tests `AtlasForgeNativeRivalsTest` antes de evoluir cases, runtimes ou gates.
