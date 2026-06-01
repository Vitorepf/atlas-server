---
id: atlas-self-construction-command-evidence-index-v1
type: engineering_knowledge
title: Atlas Self-Construction Command Evidence Index v1
status: active
category: self_construction_evidence
priority: 80
summary: Catalogo curado dos comandos read-only de verificacao do Atlas Self-Construction OS, com proposito, seguranca em paralelo, campos JSON essenciais e significado de falha. Nao declara o OS completo.
tags:
  - atlas
  - self-construction
  - evidence
  - read-only
  - operator
capabilities:
  - self_construction_evidence_index
  - read_only_verification_catalog
  - operator_safety_in_parallel
  - certification_field_mapping
decisions:
  - Comandos read-only do Self-Construction OS podem ser catalogados como contrato operacional sem disparar dispatch, claim ou ledger.
  - O catalogo serve para operacao com multiplos Claudes simultaneos, distinguindo livre vs caro vs isolar.
  - Promotion, completion claim e self-programming permanecem proibidos enquanto not_yet_runtime_capable estiver nao vazia.
maintenance:
  - Atualizar este catalogo sempre que um comando novo de self-construction read-only for introduzido.
  - Nunca incluir comandos mutating (writers, dispatch, ledger, snapshot store persist).
  - Rodar `php artisan atlas:engineering:knowledge docs-health --json` apos qualquer edicao.
related_paths:
  - docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md
  - docs/engineering-knowledge-base/self-construction/atlas-self-construction-os-operator-runbook-v1.md
  - docs/engineering-knowledge-base/self-construction/evidence-index/atlas-agent-control-plane-certification-output-map-v1.md
  - docs/engineering-knowledge-base/self-construction/evidence-index/atlas-self-construction-verification-command-catalog-v1.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-self-construction-command-evidence-index-v1
graph_title: Atlas Self-Construction Command Evidence Index v1
graph_world: atlas
graph_layer: module
graph_kind: index
graph_parent: atlas-self-construction-os
graph_status: active
graph_source: repo
human_name: Atlas Self-Construction Command Evidence Index v1
canonical_name: Atlas Self-Construction Command Evidence Index v1
technical_name: atlas-self-construction-command-evidence-index-v1
cartography_type: index
canonical_source: docs/engineering-knowledge-base/self-construction/evidence-index/atlas-self-construction-command-evidence-index-v1.md
owner: atlas-self-construction-os
repo_paths:
  - docs/engineering-knowledge-base/self-construction/evidence-index/atlas-self-construction-command-evidence-index-v1.md
allowed_changes:
  - Adicionar linhas para comandos read-only novos com purpose, key_fields e failure_meaning.
  - Refinar a coluna safe_parallel quando o comportamento real for observado em multi-Claude.
forbidden_changes:
  - Listar comandos mutating como se fossem read-only.
  - Sugerir que o catalogo certifica completion ou habilita dispatch.
  - Apagar a coluna failure_meaning ou os non_execution_guarantees.
depends_on:
  - atlas-self-construction-os
  - agent-control-plane-contract
flows_to:
  - atlas-self-construction-verification-command-catalog-v1
  - atlas-agent-control-plane-certification-output-map-v1
unlocks:
  - parallel_claude_operator_safety
  - read_only_verification_runbook
governs:
  - atlas_self_construction_evidence_corridor
evidence:
  - docs/engineering-knowledge-base/self-construction/evidence-index/atlas-self-construction-command-evidence-index-v1.md
evidence_refs:
  - symbol: AtlasSelfConstructionCommandEvidenceIndexService
  - command: atlas:aaeos:self-construction-command-evidence-index
  - test: AtlasSelfConstructionCommandEvidenceIndexTest
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
  - php artisan atlas:ai:architecture-validate --json
requires_evidence: true
risk_level: low
next_actions:
  - Manter alinhado com schema_version dos contratos a cada release do kernel.
  - Estender com novos comandos read-only quando o kernel expor capability nova certificavel.
visual_tags:
  - evidence-index
  - read-only
generated_at: 2026-05-14
scope: read-only catalog of verification commands; no writes, no dispatch
---
## Resumo

Catalogo curado dos comandos read-only que o operador pode rodar com
confianca enquanto multiplos Claudes operam em paralelo sobre o Atlas
Self-Construction OS. Cada linha mapeia comando -> proposito ->
evidencia gerada -> seguranca paralela -> campos JSON criticos ->
significado de falha. Nada aqui dispara provider, escreve ledger,
persiste claim ou avanca ponteiro. O Self-Construction OS **nao esta
completo**: este indice serve como cinto de seguranca para os
macro-sprints restantes.

## Papel no Atlas

Pertence ao corredor de evidencia operacional da Self-Construction OS.
Nao substitui contratos (`agent-control-plane-contract.md`) nem o
runbook operacional vivo do 3o Claude
(`atlas-self-construction-os-operator-runbook-v1.md`): documenta apenas
o ferramental verificacional que cada Claude pode operar lado a lado
sem disputar recursos.

## Onde Se Encaixa

- Acima: contrato canonico do Agent Control Plane e o runbook
  operacional Self-Construction OS.
- Ao lado: outputs JSON do kernel (`atlas:ai:self-construction --*`) e
  do governo documental (`atlas:engineering:knowledge docs-health`).
- Abaixo: telemetria de runtime que ainda **nao existe** (capabilities
  marcadas `not_yet_runtime_capable` no projection do Agent Control
  Plane).

## Contratos

Esta doc nao introduz contrato novo. Observa contratos existentes:

- `atlas.self_construction_agent_control_plane.v1`
- `atlas.self_construction_agent_control_plane_chain_integrity_certification_status.v1`
- `atlas.self_construction.agent_control_plane_deterministic_chain_replay.v1`
- Output JSON de `atlas:engineering:knowledge docs-health`.
- Output JSON de `atlas:ai:architecture-validate`.

## Fluxo

1. Operador roda um subset dos comandos abaixo conforme a fase
   (intra-sprint, fim de macro-sprint, pre-promocao, pos-merge).
2. Outputs JSON sao registrados como evidencia operacional (fora do
   ledger oficial — este catalogo e projection, nao writer).
3. Operador confere `status`, `mode`, `execution_allowed`,
   `ledger_write_allowed` e demais flags antes de tomar decisao.
4. Falhas sao interpretadas pela coluna `failure_meaning` desta tabela
   ou pelo `atlas-agent-control-plane-certification-output-map-v1.md`.

## Tabela canonica de comandos

| command | purpose | safe_parallel | writes_storage | writes_ledger | key_fields (JSON) | failure_meaning |
|---|---|---|---|---|---|---|
| `pwd` | Confirma diretorio base `atlas-server` antes de qualquer artisan. | sim | nao | nao | n/a (stdout string) | Diretorio errado -> todos artisans falham silenciosamente. |
| `git status --short` | Lista arquivos modificados/untracked. Permite detectar que os outros Claudes estao tocando arquivos compartilhados. | sim | nao | nao | n/a (texto curto) | Working tree inesperadamente limpo ou cheio de remocoes -> suspeitar de reset ou conflito entre Claudes. |
| `php artisan list \| rg 'self-construction\|architecture\|engineering'` | Descobre quais comandos canonicos estao registrados nessa versao do kernel. | sim | nao | nao | n/a (linhas `name  description`) | Comando esperado ausente -> registro Console nao carregou (revisar `bootstrap/app.php` em vez de assumir bug do comando). |
| `php artisan atlas:ai:self-construction --agent-control-plane --json` | Projection completo do Agent Control Plane (slices, capacidade, pontos cegos, invariantes). | sim | nao | nao | `schema_version`, `status`, `mode`, `execution_allowed`, `completion_allowed`, `dispatch_allowed`, `claim_persisted`, `ledger_write_allowed`, `control_plane.maturity`, `control_plane.next_build_slices`, `control_plane.not_yet_runtime_capable`, `control_plane.runtime_contracts_available`, `control_plane.invariants`, `control_plane_hash`, `non_execution_guarantees`. | Qualquer `*_allowed` `true` -> projection deixou de ser read-only (regressao critica). `schema_version` divergente -> contrato mudou sem documentacao. |
| `php artisan atlas:ai:self-construction --agent-control-plane-chain-integrity-certification-status --json` | Certifica integridade da chain de slices: pointer atual vs esperado, invariantes, contagem de violacoes. | sim | nao | nao | `status`, `mode`, `execution_allowed`, `ledger_write_allowed`, `runtime_write_allowed`, `agent_control_plane_chain_integrity_certification_status.chain_length`, `.checked_slice_count`, `.invariants_all_true`, `.runtime_safety_all_false`, `.violation_count`, `.warning_count`, `.current_next_required_slice`, `.expected_next_required_slice`, `.audit_hash`, `.next_action`. | `invariants_all_true=false` ou `runtime_safety_all_false=false` ou `violation_count>0` -> bloqueia avanco de macro-sprint. `current_next_required_slice` != `expected_next_required_slice` -> ponteiro desalinhado. |
| `php artisan atlas:ai:self-construction --agent-control-plane-deterministic-chain-replay-status --json` | Replay deterministico da chain; emite proof bundle e replay hashes. | sim, mas caro — preferir isolamento se outros Claudes rodarem o mesmo comando simultaneamente | nao | nao | `status`, `agent_control_plane_deterministic_chain_replay_status.replay_id`, `.replay_schema_version`, `.replayed_slice_count`, `.replayed_edge_count`, `.invariants_all_true`, `.runtime_safety_all_false`, `.violation_count`, `.warning_count`, `.replay_hash`, `.deterministic_replay_hash`, `.proof_bundle_hash`, `.current_pointer`, `.expected_pointer`, `.next_safe_macro_batch`. | Qualquer hash mudando entre duas execucoes sem mudanca de codigo -> nao-determinismo (regressao critica). `runtime_safety_all_false=false` -> projection passou a permitir runtime. |
| `php artisan atlas:engineering:knowledge docs-health --json` | Audita doc set canonico: required docs, oversize, frontmatter, canonical modules. | sim | nao | nao | `status`, `summary.docs_root`, `.doc_count`, `.required_doc_count`, `.required_missing_count`, `.oversized_count`, `.frontmatter_violation_count`, `.canonical_module_coverage_violation_count`, `.canonical_module_violation_count`, `violations[]`. | `status="failed"` aceitavel durante macro-sprint apenas se as violacoes forem **conhecidas**. Crescimento inesperado -> docs auditaveis comprometidas. |
| `php artisan atlas:ai:architecture-validate --json` | Validacao da mother-architecture: kernel, dominios, capabilities, orquestradores, docs. | sim | nao | nao | `status`, `schema_version`, `validated_at`, `kernel.valid`, `documentation.status`, `documentation.summary`, `documentation.violations`, `domains.valid`, `capabilities.valid`, `orchestrators.valid`. | `status="failed"` por culpa so de `documentation` e menos critico que falhas em `kernel`/`domains`. Ler `documentation.violations` primeiro para descartar arrasto vindo de docs-health. |

## Comandos seguros vs comandos de potencial disputa

- **Seguros em paralelo (qualquer Claude pode rodar)**: `pwd`,
  `git status --short`, `php artisan list`, todos os
  `atlas:ai:self-construction --*-status --json`,
  `atlas:ai:self-construction --agent-control-plane --json`,
  `atlas:engineering:knowledge docs-health --json`,
  `atlas:ai:architecture-validate --json`. So leem estado.
- **Cuidado em paralelo**:
  - `atlas:ai:self-construction --agent-control-plane-deterministic-chain-replay-status --json`
    pode ficar caro (replay de 34+ slices).
  - `atlas:ai:architecture-validate --json` le o doc set completo
    (520+ docs); pode dar snapshot inconsistente se outro Claude
    estiver reescrevendo doc canonico no momento.

## Comandos certificadores

- **Runtime safety (projection e read-only)**:
  `--agent-control-plane`,
  `--agent-control-plane-chain-integrity-certification-status`,
  `--agent-control-plane-deterministic-chain-replay-status`.
  Cada um expoe `runtime_safety_all_false`/`execution_allowed=false`/`ledger_write_allowed=false`
  e a lista `non_execution_guarantees`.
- **Ponteiro de slice (pointer)**:
  `--agent-control-plane-chain-integrity-certification-status`
  (`current_next_required_slice` vs `expected_next_required_slice`) e
  `--agent-control-plane-deterministic-chain-replay-status`
  (`current_pointer` vs `expected_pointer`).
- **Arquitetura / docs**: `atlas:engineering:knowledge docs-health --json`
  + `atlas:ai:architecture-validate --json`.

## Regras para IA

- IA implementadora **nao** pode incluir comando mutating neste
  catalogo, nem que seja para "documentar".
- IA **nao** pode declarar capability como `runtime`/`available` so
  porque a projection retorna `status="available"`. Projection
  available = certificacao read-only disponivel, nao runtime executando.
- IA **nao** pode propor remover ou silenciar a coluna
  `failure_meaning` — ela e o ganho operacional principal do catalogo.
- IA que adicionar novo comando precisa rodar pelo menos
  `docs-health --json` e o proprio comando novo antes de fazer commit.

## Escopo de Implementacao

- O catalogo vive em
  `docs/engineering-knowledge-base/self-construction/evidence-index/`.
- Nao toca em `app/`, `tests/`, `routes/`, `bootstrap/`,
  `atlas-desktop/`, nem em outras pastas de docs canonicas.
- Edits validos: adicionar linha de comando read-only novo, refinar
  coluna `failure_meaning`, atualizar `schema_version` listados.
- Edits proibidos: remover colunas, renomear sem release nota, listar
  comando mutating, sugerir que catalogo certifica completion.

## Dependencias

- `agent-control-plane-contract.md` (contrato canonico).
- `atlas-self-construction-os-operator-runbook-v1.md` (runbook vivo).
- Output JSON dos comandos listados na tabela canonica.

## Evidencias

Snapshots read-only colhidos em 2026-05-14 (resumidos):

- `--agent-control-plane`: `status=agent_control_plane_ready`,
  `mode=read_only_agent_control_plane_projection`, todas as flags
  `*_allowed=false`, `maturity=durable_packet_claims_with_read_only_control_projection`,
  `not_yet_runtime_capable` nao vazia.
- `--agent-control-plane-chain-integrity-certification-status`:
  `status=available`, `chain_length=34`, `checked_slice_count=34`,
  `invariants_all_true=true`, `runtime_safety_all_false=true`,
  `violation_count=0`, `warning_count=0`, ponteiros alinhados em
  `activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_receipt_contract`,
  `next_action=verify_alignment`.
- `--agent-control-plane-deterministic-chain-replay-status`:
  `status=available`, `replayed_slice_count=34`,
  `replayed_edge_count=34`, `invariants_all_true=true`,
  `runtime_safety_all_false=true`, `violation_count=0`,
  `next_safe_macro_batch=reentry_into_post_start_evidence_corridor`,
  proof bundle deterministico.

Estes valores **nao** sao garantia futura. Sao baseline para comparacao.

## Riscos

- Operador confundir projection read-only com runtime real e tentar
  promover completion claim sem capability runtime habilitada.
- Multiplos Claudes rodarem replay deterministico em loop e
  saturarem CPU sem ganhar evidencia adicional.
- Edicao silenciosa de `agent-control-plane-contract.md` quebrar
  `schema_version` listados sem atualizar este catalogo.

## Exemplos

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

## Proximas Acoes

- Manter este catalogo sincronizado com `schema_version` dos contratos
  consumidos.
- Quando novo comando read-only for adicionado ao kernel, incluir
  linha nova com `key_fields` e `failure_meaning`.
- Quando completion / promotion deixarem de ser proibidos (ou seja,
  quando `not_yet_runtime_capable` esvaziar), abrir doc separado para
  catalogo dos comandos mutating — **nao** misturar com este.
