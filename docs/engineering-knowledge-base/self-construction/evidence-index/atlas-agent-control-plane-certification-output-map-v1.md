---
id: atlas-agent-control-plane-certification-output-map-v1
type: engineering_knowledge
title: Atlas Agent Control Plane Certification Output Map v1
status: active
category: self_construction_evidence
priority: 80
summary: Mapa de campos JSON dos comandos read-only de certificacao do Agent Control Plane, com leitura semantica e valores observados em 2026-05-14. Nao habilita runtime.
tags:
  - atlas
  - self-construction
  - certification
  - read-only
  - field-map
capabilities:
  - agent_control_plane_certification_field_map
  - read_only_projection_semantics
  - pointer_alignment_check
  - replay_hash_governance
decisions:
  - Campos *_allowed sao true-negatives e devem permanecer false em projection read-only.
  - runtime_safety_all_false=true significa estado seguro (todas as flags de runtime sao false), nao runtime ativo.
  - replay_hash, deterministic_replay_hash e proof_bundle_hash devem ser estaveis entre execucoes sem mudanca de codigo.
maintenance:
  - Atualizar este mapa quando schema_version dos contratos referenciados mudar.
  - Nunca traduzir campo de projection como se fosse runtime real.
  - Rodar `php artisan atlas:engineering:knowledge docs-health --json` apos cada edicao.
related_paths:
  - docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md
  - docs/engineering-knowledge-base/self-construction/evidence-index/atlas-self-construction-command-evidence-index-v1.md
  - docs/engineering-knowledge-base/self-construction/evidence-index/atlas-self-construction-verification-command-catalog-v1.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-agent-control-plane-certification-output-map-v1
graph_title: Atlas Agent Control Plane Certification Output Map v1
graph_world: atlas
graph_layer: module
graph_kind: index
graph_parent: atlas-self-construction-os
graph_status: active
graph_source: repo
human_name: Atlas Agent Control Plane Certification Output Map v1
canonical_name: Atlas Agent Control Plane Certification Output Map v1
technical_name: atlas-agent-control-plane-certification-output-map-v1
cartography_type: index
canonical_source: docs/engineering-knowledge-base/self-construction/evidence-index/atlas-agent-control-plane-certification-output-map-v1.md
owner: atlas-self-construction-os
repo_paths:
  - docs/engineering-knowledge-base/self-construction/evidence-index/atlas-agent-control-plane-certification-output-map-v1.md
allowed_changes:
  - Atualizar valores observados (coluna direita) quando uma nova execucao read-only for registrada.
  - Adicionar campo JSON novo apenas se exposto por contrato canonico ja existente.
forbidden_changes:
  - Renomear ou silenciar flags `*_allowed`.
  - Tratar `runtime_safety_all_false=true` como autorizacao para runtime.
  - Sugerir que mudanca em replay_hash sem mudanca de codigo seja "esperada".
depends_on:
  - atlas-self-construction-os
  - agent-control-plane-contract
flows_to:
  - atlas-self-construction-command-evidence-index-v1
  - atlas-self-construction-verification-command-catalog-v1
unlocks:
  - certification_vs_runtime_disambiguation
  - replay_hash_drift_detection
governs:
  - atlas_self_construction_evidence_corridor
evidence:
  - docs/engineering-knowledge-base/self-construction/evidence-index/atlas-agent-control-plane-certification-output-map-v1.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
  - php artisan atlas:ai:architecture-validate --json
requires_evidence: true
risk_level: low
next_actions:
  - Reavaliar campos quando promotion gate / completion claim certification entrarem em runtime.
  - Manter colunas alinhadas com schema_version de cada contrato.
visual_tags:
  - evidence-index
  - field-map
generated_at: 2026-05-14
scope: read-only — descricao semantica de campos JSON; nenhuma execucao
---
## Resumo

Mapa de campos JSON que o operador precisa ler para decidir se uma
macro-sprint pode avancar, se a projection continua read-only, se o
ponteiro esta alinhado e se a chain e deterministica. Foi escrito a
partir de execucoes **read-only** de:

- `php artisan atlas:ai:self-construction --agent-control-plane --json`
- `php artisan atlas:ai:self-construction --agent-control-plane-chain-integrity-certification-status --json`
- `php artisan atlas:ai:self-construction --agent-control-plane-deterministic-chain-replay-status --json`

Nenhum campo aqui descreve writer; tudo e projection.

## Papel no Atlas

Camada de traducao entre o JSON do kernel e a decisao operacional do
humano. Faz parte do corredor de evidencia, lado a lado com o
`atlas-self-construction-command-evidence-index-v1.md` e o
`atlas-self-construction-verification-command-catalog-v1.md`.

## Onde Se Encaixa

- Nao substitui `agent-control-plane-contract.md`. La esta o contrato.
- Aqui esta apenas o que cada campo do projection significa para o
  operador que precisa decidir.

## Contratos

| schema_version | comando | natureza |
|---|---|---|
| `atlas.self_construction_agent_control_plane.v1` | `--agent-control-plane --json` | projection completo do Agent Control Plane |
| `atlas.self_construction_agent_control_plane_chain_integrity_certification_status.v1` | `--agent-control-plane-chain-integrity-certification-status --json` | certificacao de integridade da chain |
| `atlas.self_construction.agent_control_plane_deterministic_chain_replay.v1` | `--agent-control-plane-deterministic-chain-replay-status --json` | replay deterministico + proof bundle |

## Fluxo

Para cada execucao: ler `status`/`mode` -> confirmar flags read-only ->
inspecionar contadores -> comparar ponteiros -> registrar hashes ->
decidir se pode prosseguir.

## Tabela canonica de campos

| campo | comando(s) que expoem | tipo | leitura semantica | valor observado em 2026-05-14 |
|---|---|---|---|---|
| `schema_version` | todos | string | Confirma contrato consumido. Mudanca de versao exige releitura desta tabela. | os 3 contratos listados acima |
| `status` | todos | string | `agent_control_plane_ready` no projection, `available` nas certificacoes. Outra string -> diagnosticar antes. | `agent_control_plane_ready` / `available` / `available` |
| `mode` | todos | string | Deve comecar com `read_only_…`. Mudou para algo que nao comeca com `read_only` -> projection regrediu para writer. | `read_only_agent_control_plane_projection` / `read_only_agent_control_plane_chain_integrity_certification_status` / `read_only_agent_control_plane_deterministic_chain_replay_status` |
| `execution_allowed` | todos | bool | **Deve ser `false`** sempre. `true` = projection comecou a executar adapter. | `false` |
| `completion_allowed` | `--agent-control-plane` | bool | **Deve ser `false`**. Nao ha completion claim autorizado nesta fase. | `false` |
| `dispatch_allowed` | todos | bool | **Deve ser `false`**. Nenhum agente e despachado. | `false` |
| `claim_persisted` | `--agent-control-plane` | bool | **Deve ser `false`**. Projection nao persiste claim. | `false` |
| `ledger_write_allowed` | todos | bool | **Deve ser `false`**. Nenhuma escrita no ledger autorizada. | `false` |
| `runtime_write_allowed` | certificacoes | bool | **Deve ser `false`**. Nenhuma escrita no runtime autorizada. | `false` |
| `non_execution_guarantees[]` | todos | array<string> | Lista contratual do que o comando garante nao fazer. Tem que existir e ter >=1 item. | 5 itens em cada |
| `human_summary` | todos | string | Linha curta para humano. Util como label na ata. | "Agent Control Plane projection is ready: …" / "… status is available and aligned with the current horizon." / "… proof bundle is deterministic." |
| `control_plane.control_plane_id` | `--agent-control-plane` | string | Identificador canonico do Agent Control Plane (constante). | `AGENT-CONTROL-PLANE-SELF-CONSTRUCTION-0001` |
| `control_plane.workspace_id` | `--agent-control-plane` | string | Workspace canonico (constante). | `FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001` |
| `control_plane.maturity` | `--agent-control-plane` | string | Estagio atual da maturidade. Mudou sem release nota -> suspeitar regressao. | `durable_packet_claims_with_read_only_control_projection` |
| `control_plane.current_capability[]` | `--agent-control-plane` | array<string> | Conjunto de capabilities ja existentes. Compare com release dossier para detectar regressao silenciosa. | array grande (capabilities preflight/contract/service/status_projection) |
| `control_plane.runtime_contracts_available[]` | `--agent-control-plane` | array<string> | Contratos cuja implementacao **runtime** esta liberada. **Vazio = nenhum runtime real liberado.** | subset de contracts |
| `control_plane.not_yet_runtime_capable[]` | `--agent-control-plane` | array<string> | Capabilities sem runtime. **Lista nao vazia = OS nao esta completo.** | inclui `adapter_execution_runtime`, `automatic_cost_import_runtime`, `automatic_work_product_collection_runtime`, … |
| `control_plane.next_build_slices[]` | `--agent-control-plane` | array<string> | Proximas slices que o operador pode construir. | `[activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_receipt_contract]` |
| `next_required_slice` (alias do primeiro item) | `--agent-control-plane` | string | A slice imediatamente requerida. | `activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_receipt_contract` |
| `control_plane.invariants[]` | `--agent-control-plane` | array<string> | Invariantes que toda execucao deve preservar. **Encolheu = invariante removida sem RFC.** | inclui `no_provider_session_without_packet_claim`, `one_active_reservation_per_packet`, `control_plane_projection_does_not_dispatch_agents`, `post_start_gates_require_accepted_evidence_bridge`, … |
| `control_plane_hash` | `--agent-control-plane` | string (hex) | Hash do projection. Mudou sem mudanca de codigo -> projection nao-deterministico. | (hex 64-char) |
| `agent_control_plane_chain_integrity_certification_status.chain_length` | chain integrity | int | Tamanho da chain certificada. | `34` |
| `…checked_slice_count` | chain integrity | int | Slices efetivamente checadas. Deve igualar `chain_length`. | `34` |
| `…invariants_all_true` | chain integrity | bool | **Deve ser `true`**. `false` = invariante violada. | `true` |
| `…runtime_safety_all_false` | chain integrity | bool | **Deve ser `true`** — significa "todas as flags de runtime sao `false`" (estado seguro). | `true` |
| `…violation_count` | chain integrity, replay | int | **Deve ser `0`**. >0 -> bloqueia promocao. | `0` |
| `…warning_count` | chain integrity, replay | int | Idealmente `0`. Warnings nao bloqueiam mas exigem registro. | `0` |
| `…current_next_required_slice` | chain integrity | string | Slice apontada agora. | `activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_receipt_contract` |
| `…expected_next_required_slice` | chain integrity | string | Slice esperada pelo contrato. **Deve igualar a current.** | igual |
| `…audit_hash` | chain integrity | string (hex) | Hash do audit log da certificacao. | (hex 64-char) |
| `…next_action` | chain integrity | string | Proximo passo recomendado. | `verify_alignment` |
| `agent_control_plane_chain_integrity_certification_status_hash` | chain integrity | string (hex) | Hash agregado do status. Util para registrar evidencia. | (hex 64-char) |
| `agent_control_plane_deterministic_chain_replay_status.replay_id` | replay | string (uuid) | UUID do replay (so o UUID muda entre runs por design). | (uuid v4) |
| `…replay_schema_version` | replay | string | Versao do contrato de replay. | `atlas.self_construction.agent_control_plane_deterministic_chain_replay.v1` |
| `…replayed_slice_count` / `…replayed_edge_count` | replay | int / int | Slices e arestas reexecutadas. **Devem ser iguais a `chain_length`**. | `34` / `34` |
| `…replay_hash` | replay | string (hex) | Hash do replay desta execucao. **Estavel entre runs com mesmo codigo.** | (hex 64-char) |
| `…deterministic_replay_hash` | replay | string (hex) | Hash deterministico (alvo de comparacao). **Estabilidade obrigatoria.** | (hex 64-char) |
| `…proof_bundle_hash` | replay | string (hex) | Hash do proof bundle. **Estabilidade obrigatoria.** | (hex 64-char) |
| `…current_pointer` / `…expected_pointer` | replay | string / string | Mesma semantica de current/expected slice. **Devem ser iguais.** | iguais |
| `…next_safe_macro_batch` | replay | string | Proxima macro-batch segura. | `reentry_into_post_start_evidence_corridor` |
| `agent_control_plane_deterministic_chain_replay_status_hash` | replay | string (hex) | Hash agregado do status do replay. | (hex 64-char) |
| `promotion_allowed` *(referencia conceitual)* | macro-sprint promotion gate (capability `agent_control_plane_macro_sprint_promotion_gate_*`) | bool | Aparece em projections do promotion gate. **Deve ser `false`** enquanto Self-Construction OS estiver `building`. Nao foi executado aqui. | n/d nesta execucao |
| `completion_claim_allowed` *(referencia conceitual)* | runtime pilot certification (capability `agent_control_plane_runtime_pilot_certification_*`) | bool | **Deve ser `false`** ate o OS sair de `building`. | n/d nesta execucao |

## Padrao "tudo verde"

Uma execucao read-only e considerada "tudo verde" quando,
simultaneamente:

- `status` ∈ {`agent_control_plane_ready`, `available`}
- `mode` comeca com `read_only_`
- Todas as flags `*_allowed` sao `false`
- `invariants_all_true=true`
- `runtime_safety_all_false=true`
- `violation_count=0` e idealmente `warning_count=0`
- `current_*` e `expected_*` (slice/pointer) iguais
- `replay_hash`, `deterministic_replay_hash`, `proof_bundle_hash` nao
  mudaram desde a ultima execucao sem mudanca de codigo
- `non_execution_guarantees[]` tem pelo menos 1 item

Qualquer desvio do padrao acima exige diagnostico antes de avancar.

## Regras para IA

- IA **nao** pode interpretar `runtime_safety_all_false=true` como
  "runtime esta liberado". Significa o contrario: todas as flags de
  runtime sao `false`.
- IA **nao** pode propor mudanca em `replay_hash`/`proof_bundle_hash`
  como "esperada" sem release nota explicita.
- IA **nao** pode silenciar diferenca entre `current_*` e `expected_*`
  pointer; isso e indicacao de regressao.
- IA que adicionar campo novo a tabela deve referenciar o
  `schema_version` exato do contrato que expoe o campo.

## Escopo de Implementacao

- O mapa vive em
  `docs/engineering-knowledge-base/self-construction/evidence-index/`.
- Nao toca em `app/`, `tests/`, `routes/`, `bootstrap/` ou
  `atlas-desktop/`.
- Edits validos: atualizar valor observado em 2026-MM-DD para nova
  data; adicionar campo novo se exposto por contrato canonico.
- Edits proibidos: renomear flags, remover colunas, tratar projection
  como runtime.

## Dependencias

- `agent-control-plane-contract.md`.
- Outputs JSON dos 3 comandos read-only listados.

## Evidencias

Execucoes read-only colhidas em 2026-05-14 confirmam os valores
listados na tabela. Hashes especificos sao registrados pelo operador,
nao incluidos aqui para evitar drift sem auditoria.

## Riscos

- Operador confundir certification available com runtime running.
- IA implementadora deletar coluna "valor observado" e perder
  baseline para deteccao de drift.
- Mudanca silenciosa em `replay_hash`/`proof_bundle_hash` indicar
  bug de determinismo nao percebido.

## Exemplos

```bash
# Confirmar "tudo verde" em uma execucao isolada
php artisan atlas:ai:self-construction --agent-control-plane-chain-integrity-certification-status --json \
  | jq '.status,
        .execution_allowed,
        .ledger_write_allowed,
        .runtime_write_allowed,
        .agent_control_plane_chain_integrity_certification_status.invariants_all_true,
        .agent_control_plane_chain_integrity_certification_status.runtime_safety_all_false,
        .agent_control_plane_chain_integrity_certification_status.violation_count'
```

## Proximas Acoes

- Reler este mapa sempre que `schema_version` listada mudar.
- Quando promotion gate / completion claim sairem do estado
  proibido, criar mapa especifico para os campos `promotion_allowed`
  e `completion_claim_allowed` em runtime real.
