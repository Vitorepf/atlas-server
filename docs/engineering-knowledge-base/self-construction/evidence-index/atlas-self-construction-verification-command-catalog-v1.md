---
id: atlas-self-construction-verification-command-catalog-v1
type: engineering_knowledge
title: Atlas Self-Construction Verification Command Catalog v1
status: active
category: self_construction_evidence
priority: 80
summary: Ordem recomendada, regras de isolamento e registro de evidencia dos comandos read-only de verificacao do Self-Construction OS. Mantem promotion proibido enquanto OS estiver em construcao.
tags:
  - atlas
  - self-construction
  - verification
  - read-only
  - operator-runbook
capabilities:
  - verification_command_sequencing
  - parallel_claude_coordination
  - evidence_registration_protocol
  - certification_vs_runtime_disambiguation
decisions:
  - Antes/depois de cada macro-sprint roda-se a mesma sequencia read-only para baseline e fechamento.
  - Replay deterministico e docs-health podem rodar em paralelo, mas precisam ser registrados como evidencia separada.
  - Promotion e completion permanecem proibidos enquanto os 4 corredores ativos nao consolidarem runtime.
maintenance:
  - Atualizar a sequencia somente quando comando read-only novo for adicionado ao kernel.
  - Nunca incluir comando mutating; preferir documentar o comando read-only equivalente quando existir.
  - Rodar `php artisan atlas:engineering:knowledge docs-health --json` apos cada edicao.
related_paths:
  - docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md
  - docs/engineering-knowledge-base/self-construction/atlas-self-construction-os-operator-runbook-v1.md
  - docs/engineering-knowledge-base/self-construction/evidence-index/atlas-self-construction-command-evidence-index-v1.md
  - docs/engineering-knowledge-base/self-construction/evidence-index/atlas-agent-control-plane-certification-output-map-v1.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-self-construction-verification-command-catalog-v1
graph_title: Atlas Self-Construction Verification Command Catalog v1
graph_world: atlas
graph_layer: module
graph_kind: index
graph_parent: atlas-self-construction-os
graph_status: active
graph_source: repo
human_name: Atlas Self-Construction Verification Command Catalog v1
canonical_name: Atlas Self-Construction Verification Command Catalog v1
technical_name: atlas-self-construction-verification-command-catalog-v1
cartography_type: index
canonical_source: docs/engineering-knowledge-base/self-construction/evidence-index/atlas-self-construction-verification-command-catalog-v1.md
owner: atlas-self-construction-os
repo_paths:
  - docs/engineering-knowledge-base/self-construction/evidence-index/atlas-self-construction-verification-command-catalog-v1.md
allowed_changes:
  - Reordenar a sequencia recomendada com base em observacao real de operacao multi-Claude.
  - Adicionar item novo a tabela "comandos seguros vs isolados" quando capability nova for liberada em read-only.
forbidden_changes:
  - Incluir comando mutating na sequencia recomendada.
  - Sugerir que rodar a sequencia toda autoriza promotion/completion.
  - Remover o lembrete de que o OS nao esta completo.
depends_on:
  - atlas-self-construction-os
  - agent-control-plane-contract
flows_to:
  - atlas-self-construction-command-evidence-index-v1
  - atlas-agent-control-plane-certification-output-map-v1
unlocks:
  - operator_macro_sprint_protocol
  - safe_parallel_claude_runbook
governs:
  - atlas_self_construction_evidence_corridor
evidence:
  - docs/engineering-knowledge-base/self-construction/evidence-index/atlas-self-construction-verification-command-catalog-v1.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
  - php artisan atlas:ai:architecture-validate --json
requires_evidence: true
risk_level: low
next_actions:
  - Estender com sequencia de fechamento quando completion claim deixar de ser proibida.
  - Documentar protocolo de captura de evidencia para release dossier futuro.
visual_tags:
  - evidence-index
  - verification-runbook
generated_at: 2026-05-14
scope: ordering, isolation rules and evidence registration for read-only verification commands
---
## Resumo

Catalogo de sequencias recomendadas para verificar a Self-Construction
OS sem disparar provider, sem dispatch, sem ledger. Pensado para
convivio com multiplos Claudes simultaneos: distingue comandos que
podem rodar livres em paralelo dos que pedem isolamento, ordena o que
deve vir antes e depois de cada macro-sprint, e explica como **nao**
confundir certification read-only com runtime real.

## Papel no Atlas

Terceiro pilar do corredor de evidencia (junto com o
`atlas-self-construction-command-evidence-index-v1.md` e o
`atlas-agent-control-plane-certification-output-map-v1.md`). Define o
**quando** e **em que ordem** rodar os comandos.

## Onde Se Encaixa

- Apoia o operador (humano) que esta coordenando 4 Claudes simultaneos.
- Nao substitui o runbook canonico do 3o Claude
  (`atlas-self-construction-os-operator-runbook-v1.md`).
- Nao introduz comando novo — apenas reordena os ja permitidos.

## Contratos

Todos os contratos referenciados ja existem:

- `atlas.self_construction_agent_control_plane.v1`
- `atlas.self_construction_agent_control_plane_chain_integrity_certification_status.v1`
- `atlas.self_construction.agent_control_plane_deterministic_chain_replay.v1`
- output JSON de `atlas:engineering:knowledge docs-health`
- output JSON de `atlas:ai:architecture-validate`

## Fluxo

### Antes de iniciar uma macro-sprint

Objetivo: garantir baseline limpo e ponteiro alinhado **antes** de
qualquer outro Claude comecar a editar.

```bash
cd /Users/vitorepf/develop/Atlas/atlas-server

pwd
git status --short
php artisan list | rg 'self-construction|architecture|engineering'

php artisan atlas:ai:self-construction --agent-control-plane --json
php artisan atlas:ai:self-construction --agent-control-plane-chain-integrity-certification-status --json
php artisan atlas:ai:self-construction --agent-control-plane-deterministic-chain-replay-status --json

php artisan atlas:engineering:knowledge docs-health --json
php artisan atlas:ai:architecture-validate --json
```

Criterios de prosseguir (cf.
`atlas-agent-control-plane-certification-output-map-v1.md`):

- Todas as flags `*_allowed` `false`.
- `invariants_all_true=true`, `runtime_safety_all_false=true`.
- `violation_count=0` em chain integrity e replay.
- `current_*`/`expected_*` slice/pointer iguais.
- `replay_hash`, `deterministic_replay_hash`, `proof_bundle_hash`
  registrados (anotar antes da sprint).

### Durante a macro-sprint

Os 4 Claudes ativos editam diretorios distintos. Cada um pode rodar
sem coordenacao:

- `pwd`, `git status --short`
- `php artisan list | rg ...`
- `php artisan atlas:ai:self-construction --agent-control-plane --json`

Evitar **durante** sprint (preferir comeco/fim):

- `--agent-control-plane-deterministic-chain-replay-status --json`
  (replay caro; pode mascarar progress real se rodado varias vezes).
- `atlas:engineering:knowledge docs-health --json` em momentos exatos
  em que outro Claude esta reescrevendo doc canonico (snapshot
  inconsistente).

### Ao fim de cada macro-sprint

```bash
git status --short

php artisan atlas:ai:self-construction --agent-control-plane --json
php artisan atlas:ai:self-construction --agent-control-plane-chain-integrity-certification-status --json
php artisan atlas:ai:self-construction --agent-control-plane-deterministic-chain-replay-status --json
php artisan atlas:engineering:knowledge docs-health --json
php artisan atlas:ai:architecture-validate --json

git diff --check
```

Criterios de "macro-sprint encerrada":

- `replay_hash`/`deterministic_replay_hash`/`proof_bundle_hash`
  mudaram se e somente se houve mudanca real em chain code.
- `next_required_slice`/`next_safe_macro_batch` avancaram **apenas
  quando o trabalho contratual previa**. Avanco inesperado = sinal
  de efeito colateral.
- `docs-health` continua em `failed` ou `passed` pelo mesmo conjunto
  de violacoes conhecidas. Crescimento de `violations[]` exige
  diagnostico.
- `architecture-validate` mantem ou melhora o estado. Regressao em
  `kernel.valid` ou `domains.valid` e bloqueante.

### Antes de qualquer promotion ou completion claim

Estado atual: o Self-Construction OS **nao esta pronto** para
promotion. `not_yet_runtime_capable` tem capabilities pendentes e o
`automatic_dispatch_scheduler_*` ainda esta em fase de signed one-shot
tick. Toda completion claim/promotion e proibida.

Quando estiver pronta (futuro), a sequencia sera:

```bash
php artisan atlas:ai:self-construction --agent-control-plane-chain-integrity-certification-status --json
php artisan atlas:ai:self-construction --agent-control-plane-deterministic-chain-replay-status --json
php artisan atlas:engineering:knowledge docs-health --json
php artisan atlas:ai:architecture-validate --json
```

…e so entao (ja no dominio mutating, fora desta doc) qualquer
promotion gate / completion claim podera ser invocado.

## Comandos seguros para multiplos Claudes simultaneos

| comando | recomendacao |
|---|---|
| `pwd` | livre |
| `git status --short` | livre |
| `php artisan list \| rg …` | livre |
| `atlas:ai:self-construction --agent-control-plane --json` | livre |
| `atlas:ai:self-construction --agent-control-plane-chain-integrity-certification-status --json` | livre |
| `atlas:ai:self-construction --agent-control-plane-deterministic-chain-replay-status --json` | livre, mas caro — evite chamadas simultaneas |
| `atlas:engineering:knowledge docs-health --json` | livre, mas pode dar snapshot inconsistente se outro Claude estiver editando doc canonico nesse instante |
| `atlas:ai:architecture-validate --json` | livre, mesma ressalva acima |

Nada na lista permitida disputa lock de banco / arquivo / ledger.

## Comandos que devem ser isolados

Nenhum comando **permitido a este Claude** precisa de isolamento real,
porque todos sao read-only. O isolamento aqui descrito e apenas para
qualidade da evidencia (nao rodar o `deterministic-chain-replay-status`
em rajadas simultaneas; nao rodar `docs-health` durante reescrita
canonica). Comandos que precisariam de isolamento real (provider start,
dispatch tick, ledger writers, snapshot store persist) sao
**proibidos** durante esta operacao.

## Registro de evidencia

Para cada execucao relevante, anotar (no canal do operador, nao no
ledger):

- timestamp local
- comando rodado (literal)
- `schema_version` retornada
- `status`, `mode`, todas as flags `*_allowed`
- contagens (`violation_count`, `warning_count`, `chain_length`,
  `replayed_slice_count`)
- hashes (`control_plane_hash`, `audit_hash`, `replay_hash`,
  `deterministic_replay_hash`, `proof_bundle_hash`, status hashes)
- ponteiro corrente vs esperado

Esses dados serao a base para o release dossier futuro, mas ainda
**nao** vao para o ledger.

## Como nao confundir dry-run / certification com runtime real

- "Certification status = available" significa "a certificacao esta
  disponivel e esta dizendo `read-only`". **Nao** significa que a
  capability esta rodando.
- `runtime_safety_all_false=true` significa "todas as flags de runtime
  sao `false`" -> **estado seguro**, **nao** estado "tudo rodando".
- `next_build_slices` lista o que pode ser construido, **nao** o que
  foi construido.
- `runtime_contracts_available` e o conjunto de contratos cuja
  implementacao **runtime** esta liberada. Capabilities que aparecem
  em `current_capability` mas **nao** em `runtime_contracts_available`
  tem apenas contract/preflight/service/projection — nao tem runtime.
- `not_yet_runtime_capable` e a lista negativa explicita. Enquanto ela
  tiver itens, o OS esta em construcao.
- `next_safe_macro_batch` indica a **proxima** macro-batch segura,
  **nao** que ela tenha sido executada.

Toda confusao entre certification e runtime real e tratada como
incidente de leitura, nao como bug do codigo.

## Regras para IA

- IA implementadora **nao** pode adicionar comando mutating ao fluxo
  recomendado, mesmo que "so para documentar".
- IA **nao** pode reordenar a sequencia para colocar replay
  deterministico antes da chain integrity certification (a ordem
  protege contra leitura confusa: integrity primeiro, replay depois).
- IA que detectar comando read-only novo deve adicionar entrada na
  tabela "Comandos seguros para multiplos Claudes simultaneos" antes
  de altera-lo no fluxo.

## Escopo de Implementacao

- O catalogo vive em
  `docs/engineering-knowledge-base/self-construction/evidence-index/`.
- Nao toca em `app/`, `tests/`, `routes/`, `bootstrap/`,
  `atlas-desktop/`.
- Edits validos: reordenar comandos read-only, adicionar comando
  read-only novo, atualizar criterios de fechamento.
- Edits proibidos: incluir comando mutating, sugerir promotion
  autorizada, remover lembrete de OS incompleto.

## Dependencias

- `agent-control-plane-contract.md`.
- `atlas-self-construction-os-operator-runbook-v1.md`.
- `atlas-self-construction-command-evidence-index-v1.md`.
- `atlas-agent-control-plane-certification-output-map-v1.md`.

## Evidencias

Sequencia validada read-only em 2026-05-14:

- `--agent-control-plane`: `status=agent_control_plane_ready`,
  todas flags `*_allowed=false`, `mode` comeca com `read_only_`.
- `--agent-control-plane-chain-integrity-certification-status`:
  `status=available`, `invariants_all_true=true`,
  `runtime_safety_all_false=true`, `violation_count=0`,
  `next_action=verify_alignment`.
- `--agent-control-plane-deterministic-chain-replay-status`:
  `status=available`, hashes deterministicos,
  `next_safe_macro_batch=reentry_into_post_start_evidence_corridor`.
- `docs-health --json`: `status=failed` por violacoes conhecidas em
  docs novos (incluindo este), `frontmatter_violation_count=0` apos
  ajuste, `canonical_module_*_violation_count>0` enquanto OS estiver
  em construcao.
- `architecture-validate --json`: `status=failed` arrastado por
  `documentation.status=failed`; `kernel.valid=true`.

## Riscos

- Operador rodar o fluxo "tudo verde" e inferir que pode promover
  completion claim; permanece proibido enquanto `not_yet_runtime_capable`
  estiver nao vazio.
- IA rodar replay deterministico em loop e gerar custo de CPU sem
  evidencia adicional.
- Sequencia ser usada como "checklist de release" e mascarar trabalho
  real pendente nos 4 corredores ativos.

## Exemplos

```bash
# Sequencia minima de baseline antes de macro-sprint
cd /Users/vitorepf/develop/Atlas/atlas-server
pwd && git status --short

php artisan atlas:ai:self-construction --agent-control-plane --json | jq '.status,.execution_allowed,.ledger_write_allowed'
php artisan atlas:ai:self-construction --agent-control-plane-chain-integrity-certification-status --json | jq '.status,.agent_control_plane_chain_integrity_certification_status.violation_count'
php artisan atlas:ai:self-construction --agent-control-plane-deterministic-chain-replay-status --json | jq '.status,.agent_control_plane_deterministic_chain_replay_status.proof_bundle_hash'
```

## Proximas Acoes

- Quando completion claim deixar de ser proibida, adicionar secao
  "Antes de promotion (real)" com a sequencia mutating.
- Documentar como o release dossier vai absorver os hashes
  registrados em "Registro de evidencia".
- Reavaliar o catalogo apos o 1o macro-sprint que esvazie um item de
  `not_yet_runtime_capable`.
