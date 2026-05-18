---
id: vox-action-outcome-v1
type: contract
title: VoxActionOutcome v1
status: active
category: contracts
priority: 95
summary: Resultado de uma acao Vox executada. Gravado no AtlasEvidenceLedger como evidencia primaria do que aconteceu apos confirmacao. Carrega status, artifacts, regret signals, follow-up.
tags:
  - atlas-vox
  - contract
  - schema
  - outcome
  - evidence
  - v1
maintenance:
  - Imutavel em campos obrigatorios v1.
related_paths:
  - docs/contracts/vox/README.md
  - docs/contracts/vox/VoxConfirmation.v1.md
  - docs/contracts/vox/VoxIntentPacket.v1.md
  - docs/engineering-knowledge-base/atlas-vox-operational-thinking-interface.md
  - docs/engineering-knowledge-base/atlas-ai-telemetry-evidence-performance.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: vox-action-outcome-v1

graph_title: VoxActionOutcome v1

graph_world: atlas

graph_layer: contract

graph_kind: contract

graph_parent: vox-contracts-v1-index

graph_status: active

graph_source: repo

owner: surface-architecture

repo_paths:
  - docs/contracts/vox/VoxActionOutcome.v1.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
---
# VoxActionOutcome v1

Schema id: `atlas.vox.action_outcome.v1`.

Resultado de uma acao Vox executada. Emitido pelo Executor (Codex CLI shell
out, Claude CLI shell out, filesystem edit, note capture, terminal_propose,
ou no-op em modo dictation). Gravado no `AtlasEvidenceLedger` como evidencia
primaria.

## Quem produz, quem consome

- **Produz**: Executor especifico chamado pelo `ExecutionGate`. Por exemplo
  `app/Services/Ai/Vox/Executors/CodexCliExecutor.php`.
- **Consome**: `AtlasEvidenceLedger`, overlay (para mostrar resultado a
  Vitor), `VoxMemoryCandidateService` (para detectar padroes), Rivals
  framework.

## Campos

| Campo | Tipo | Obrigatorio | Descricao |
| --- | --- | --- | --- |
| `outcome_id` | UUID v4 | sim | Identificador unico do outcome. |
| `session_id` | UUID v4 | sim | Referencia a sessao Vox. |
| `intent_id` | UUID v4 | sim | Referencia ao intent packet. |
| `receipt_id` | UUID v4 | sim | Referencia ao Decision Receipt que autorizou. |
| `executor` | enum | sim | Identificador do executor. Valores: `codex_cli`, `claude_cli`, `filesystem_edit`, `note_capture`, `terminal_propose`, `clipboard_write`, `no_op_dictation`. |
| `executor_version` | string | sim | Versao do executor. Ex.: `codex@1.2.3`, `claude@0.5.1`. Para `terminal_propose`/`note_capture`, versao do componente Vox. |
| `status` | enum | sim | `completed`, `failed`, `aborted`, `escalated`. `escalated` significa que executor identificou risco maior e devolveu controle para human review. |
| `started_at` | ISO 8601 string UTC | sim | Inicio da execucao. |
| `completed_at` | ISO 8601 string UTC | sim | Fim (sucesso, falha ou abort). |
| `duration_ms` | integer | sim | `completed_at - started_at` em ms. |
| `artifacts` | array de objetos | sim | Itens produzidos. Cada item: `{ kind, ref, size_bytes, sha256 }`. `kind` em `diff`, `stdout`, `stderr`, `file`, `clipboard_payload`, `note_id`, `command_string`, `plan_text`. |
| `error` | object \| null | sim | Se `status=failed` ou `aborted`. `{ kind, message, exit_code }`. `null` caso contrario. |
| `regret_signals` | array de objetos | sim | Sinais de regret detectados (V3+) ou marcados explicitamente por Vitor. Cada item: `{ kind, severity, source }`. Lista vazia em V0-V2. |
| `follow_up_required` | boolean | sim | Se `true`, indica que outcome exige acao Vitor (revisar diff, decidir merge, escalation). |
| `follow_up_kind` | enum \| null | sim | Quando `follow_up_required=true`: `review_diff`, `decide_merge`, `manual_terminal_run`, `escalate_human`. `null` caso contrario. |
| `cost_signals` | object | sim | `{ provider_tokens_in, provider_tokens_out, local_compute_ms, network_bytes }`. Em V0-V3 com shell-out CLI ja autenticada, `provider_tokens_*` pode ser estimado ou `null` se nao disponivel. |
| `executor_violations` | array de strings | sim | Lista de violacoes detectadas durante execucao. Ex.: `attempted_destructive_command_blocked`, `path_outside_workspace_blocked`. Lista vazia se nenhuma. |
| `metadata` | object | nao | Campos livres por executor. Ex.: para `codex_cli`, `{ working_directory, codex_session_id }`. |

## Exemplo - Codex CLI executado com sucesso

```json
{
  "schema": "atlas.vox.action_outcome.v1",
  "outcome_id": "44eeddcc-bbaa-9988-7766-554433221100",
  "session_id": "b7c4e1a2-3d8f-4e5a-9b1c-2f6e8d3a4b5c",
  "intent_id": "9a8b7c6d-5e4f-3210-fedc-ba0987654321",
  "receipt_id": "rcpt_0192837465abcdef",
  "executor": "codex_cli",
  "executor_version": "codex@1.2.3",
  "status": "completed",
  "started_at": "2026-05-18T14:33:49.012Z",
  "completed_at": "2026-05-18T14:34:18.667Z",
  "duration_ms": 29655,
  "artifacts": [
    { "kind": "stdout", "ref": "art_stdout_001", "size_bytes": 8421, "sha256": "ab12...ef34" },
    { "kind": "plan_text", "ref": "art_plan_001", "size_bytes": 2103, "sha256": "9c8b...e3f7" }
  ],
  "error": null,
  "regret_signals": [],
  "follow_up_required": true,
  "follow_up_kind": "review_diff",
  "cost_signals": {
    "provider_tokens_in": null,
    "provider_tokens_out": null,
    "local_compute_ms": 29655,
    "network_bytes": 0
  },
  "executor_violations": [],
  "metadata": {
    "working_directory": "/Users/vitorepf/develop/Atlas",
    "codex_session_id": "cdx_2026_05_18_14_33_49"
  }
}
```

## Exemplo - Terminal propose (V3) - comando proposto, NAO executado

```json
{
  "schema": "atlas.vox.action_outcome.v1",
  "outcome_id": "55ffeedd-ccbb-aa99-8877-665544332211",
  "session_id": "...",
  "intent_id": "...",
  "receipt_id": "rcpt_...",
  "executor": "terminal_propose",
  "executor_version": "vox-terminal@0.1.0",
  "status": "completed",
  "started_at": "2026-05-18T14:40:01.000Z",
  "completed_at": "2026-05-18T14:40:01.038Z",
  "duration_ms": 38,
  "artifacts": [
    { "kind": "command_string", "ref": "art_cmd_001", "size_bytes": 76, "sha256": "f00b..." }
  ],
  "error": null,
  "regret_signals": [],
  "follow_up_required": true,
  "follow_up_kind": "manual_terminal_run",
  "cost_signals": {
    "provider_tokens_in": null,
    "provider_tokens_out": null,
    "local_compute_ms": 38,
    "network_bytes": 0
  },
  "executor_violations": [],
  "metadata": {
    "command_proposed": "git log --since=\"1 week ago\" --name-only --pretty=format: | sort -u",
    "command_executed": false
  }
}
```

## Regras invariantes

1. `executor=terminal_propose` SEMPRE tem `metadata.command_executed=false`
   em V3. Executor que tente executar diretamente viola Lei 7 (stop-the-line).
2. `status=failed` exige `error` nao-nulo.
3. `status=completed` -> `error` deve ser `null`.
4. `executor_violations` nao-vazio sempre gera `VOX_ACTION_BLOCKED` adicional
   ao `VOX_EVIDENCE_RECORDED`.
5. Comandos hard-veto (lista em `plans/synchronous-weaving-meteor.md` 8.6)
   nunca chegam aqui - sao bloqueados antes pelo `ExecutionGate`.
6. `artifacts` referenciam via `ref` opaco; conteudo bruto vive em storage
   de artifacts separado com policy de retencao propria. Ledger nao carrega
   payload bruto.

## Eventos Ledger associados

- `VOX_EVIDENCE_RECORDED` emitido sempre com este outcome.
- `VOX_ACTION_BLOCKED` adicionalmente se `executor_violations` nao-vazio ou
  `status=aborted` por seguranca.
- `VOX_MEMORY_CANDIDATE_CREATED` opcionalmente emitido pelo
  `VoxMemoryCandidateService` se padrao recorrente detectado.

## Hard-gates monitorados sobre este contrato

- `destructive_action_without_receipt = 0` (executor que dispara sem receipt
  valido e violacao stop-the-line).
- `executor_violations` count agregado por janela de 7 dias - alerta se > 0.
- `cost_signals.provider_tokens_*` populados em `codex_cli`/`claude_cli`
  esperados nulos OU estimados; vazamento de chave de API valida via
  CLI ja autenticada e aceitavel.

## Versionamento

- v1 (2026-05-18): versao inicial canonica.
