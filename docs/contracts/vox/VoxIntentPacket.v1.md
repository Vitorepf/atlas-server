---
id: vox-intent-packet-v1
type: contract
title: VoxIntentPacket v1
status: active
category: contracts
priority: 95
summary: Intent estruturado produzido pelo VoxCompiler a partir do transcript. Goal, constraints, context_refs, provider_hint, output_format, risk_class, compiled_prompt. Entra como Input do Atlas Pipeline via OperationEnvelopeFactory.fromVox().
tags:
  - atlas-vox
  - contract
  - schema
  - intent
  - v1
maintenance:
  - Imutavel em campos obrigatorios v1.
  - Reconcilia-se com `atlas.vox.intent_packet.v1` mencionado em atlas-vox-operational-thinking-interface.md V2 secao.
related_paths:
  - docs/contracts/vox/README.md
  - docs/contracts/vox/VoxTranscript.v1.md
  - docs/contracts/vox/VoxConfirmation.v1.md
  - docs/engineering-knowledge-base/atlas-vox-operational-thinking-interface.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: vox-intent-packet-v1

graph_title: VoxIntentPacket v1

graph_world: atlas

graph_layer: contract

graph_kind: contract

graph_parent: vox-contracts-v1-index

graph_status: active

graph_source: repo

owner: surface-architecture

repo_paths:
  - docs/contracts/vox/VoxIntentPacket.v1.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
---
# VoxIntentPacket v1

Schema id: `atlas.vox.intent_packet.v1`.

Produzido pelo `VoxCompiler` (Kernel Laravel) a partir do `VoxTranscript`.
Carrega intencao estruturada pronta para entrar no Atlas Pipeline como Input.
**Este e o contrato mais importante do Atlas Vox**: define como fala humana
vira unidade operacional governavel.

## Quem produz, quem consome

- **Produz**: `atlas-server/app/Services/Ai/Vox/VoxCompiler.php` (modos
  `dictation` em V0, `prompt_polish` em V1, `intent_compile` em V2+).
- **Consome**: `OperationEnvelopeFactory.fromVox()` -> `AtlasDecide` ->
  `DecisionReceiptIssuer`.

## Campos

| Campo | Tipo | Obrigatorio | Descricao |
| --- | --- | --- | --- |
| `session_id` | UUID v4 | sim | Referencia ao `VoxSessionPacket.session_id`. |
| `intent_id` | UUID v4 | sim | Identificador unico do intent packet. |
| `transcript_ref` | UUID v4 | sim | Referencia ao `VoxTranscript.transcript_id`. |
| `mode` | enum | sim | `dictation`, `prompt_polish`, `intent_compile`, `governed_execute`. Deve coincidir com `VoxSessionPacket.mode_requested` (ou reclassificacao para baixo). |
| `goal` | string | sim | Frase imperativa curta declarando o que Vitor quer. Extraida pelo compiler. Em modo `dictation` pode ser vazia. |
| `constraints` | array de strings | sim | Restricoes explicitas. Frases negativas detectadas no transcript ("nao mexa em X", "sem tocar Y"). Sempre lista (pode ser vazia). |
| `context_refs` | array de objetos | sim | Referencias contextuais. Cada item: `{ kind, ref, resolved }`. `kind` em `file`, `selection`, `active_window`, `terminal_recent`, `none`. `ref` e identificador opaco. `resolved` indica se o compiler resolveu sem ambiguidade. |
| `provider_hint` | enum | sim | Pista de provider. `codex_cli`, `claude_cli`, `local`, `auto`. Kernel decide; hint pode ser ignorado. |
| `executor_hint` | enum | sim | Pista de executor. `shell`, `edit`, `note`, `terminal_propose`, `none`. Kernel decide. |
| `output_format` | enum | sim | `diff`, `plan`, `text`, `notes`, `command_proposal`, `none`. |
| `risk_class` | enum | sim | `R0`, `R1`, `R2`, `R3`, `R4` conforme tabela canonica. Proposta do `VoxRiskClassifier`. Kernel pode reclassificar para CIMA, jamais para BAIXO. |
| `risk_reasoning` | string | sim | Justificativa curta da classificacao de risco. Texto humano-legivel. |
| `human_input_text` | string | sim | Transcript original limpo (versao lida no overlay). Preservado para audit. |
| `compiled_prompt` | string \| null | sim | Prompt poderoso gerado em modos `prompt_polish` e `intent_compile`. `null` em modo `dictation`. |
| `compiled_prompt_template` | string \| null | sim | Identificador do template usado para gerar `compiled_prompt`. Ex.: `codex.md@v3`, `claude.md@v2`. `null` em `dictation`. |
| `discordance_hint` | object \| null | nao | Se Vox detectou padrao para discordar (V5+). `null` em V0-V3. |
| `memory_candidate` | object \| null | nao | Se compiler detectou que esta sessao pode virar memoria candidata. Inicialmente `null`; populado em V3+. |
| `compiler_version` | string | sim | Versao do compiler usada. Ex.: `0.1.0`. |

## Exemplo - modo `intent_compile` (V2)

```json
{
  "schema": "atlas.vox.intent_packet.v1",
  "session_id": "b7c4e1a2-3d8f-4e5a-9b1c-2f6e8d3a4b5c",
  "intent_id": "9a8b7c6d-5e4f-3210-fedc-ba0987654321",
  "transcript_ref": "f3e1d2c4-5b6a-7890-abcd-ef1234567890",
  "mode": "intent_compile",
  "goal": "Investigar modulo Voice/Vox com Codex sem editar arquivos",
  "constraints": [
    "nao editar arquivos",
    "nao refatorar amplo",
    "nao alterar banco",
    "primeiro entender, depois propor plano"
  ],
  "context_refs": [
    { "kind": "active_window", "ref": "Atlas Desktop / Workbench / vox", "resolved": true }
  ],
  "provider_hint": "codex_cli",
  "executor_hint": "shell",
  "output_format": "plan",
  "risk_class": "R1",
  "risk_reasoning": "read-only investigacao; codex em modo analise nao edita arquivos",
  "human_input_text": "manda o Codex olhar esse modulo do voice sem mexer",
  "compiled_prompt": "Voce e o Codex trabalhando no projeto Atlas.\n\nObjetivo:\nInvestigar a area atual do modulo Voice/Vox e identificar por que ela esta confusa/fragil.\n\nModo de trabalho:\n1. Primeiro, leia o codigo relevante.\n2. Nao edite arquivos ainda.\n3. Mapeie o fluxo atual.\n4. Identifique problemas concretos, riscos e possiveis causas.\n5. Proponha um plano de correcao pequeno e seguro.\n\nRestricoes:\n- Nao faca refatoracao ampla.\n- Nao altere banco de dados.\n- Nao rode comandos destrutivos.\n- Nao implemente antes de apresentar o diagnostico.\n\nSaida esperada:\n- Resumo do que encontrou.\n- Arquivos relevantes.\n- Hipoteses de problema.\n- Plano recomendado.\n- Perguntas abertas, se houver.",
  "compiled_prompt_template": "codex.md@v3",
  "discordance_hint": null,
  "memory_candidate": null,
  "compiler_version": "0.1.0"
}
```

## Exemplo - modo `dictation` (V0)

```json
{
  "schema": "atlas.vox.intent_packet.v1",
  "session_id": "b7c4e1a2-3d8f-4e5a-9b1c-2f6e8d3a4b5c",
  "intent_id": "0a1b2c3d-4e5f-6789-0123-456789abcdef",
  "transcript_ref": "f3e1d2c4-5b6a-7890-abcd-ef1234567890",
  "mode": "dictation",
  "goal": "",
  "constraints": [],
  "context_refs": [{ "kind": "none", "ref": null, "resolved": true }],
  "provider_hint": "local",
  "executor_hint": "none",
  "output_format": "text",
  "risk_class": "R0",
  "risk_reasoning": "dictation pura: texto vai para clipboard, zero efeito externo",
  "human_input_text": "manda o Codex olhar esse modulo do voice sem mexer",
  "compiled_prompt": null,
  "compiled_prompt_template": null,
  "discordance_hint": null,
  "memory_candidate": null,
  "compiler_version": "0.1.0"
}
```

## Tabela canonica de Risk Class

Replicada de `plans/synchronous-weaving-meteor.md` secao 3.7 para autoridade
local:

| Classe | Significado | Exemplos Vox | Confirmacao obrigatoria? |
| --- | --- | --- | --- |
| R0 | Sem efeito externo | Dictation (texto pro clipboard), prompt polish | Nao |
| R1 | Leitura / analise | "Codex investiga esse modulo sem editar" | Nao (preview opcional) |
| R2 | Edicao local reversivel | Edit em arquivo, captura inbox, criar nota | Sim |
| R3 | Execucao externa contida | Comando terminal nao-destrutivo, run de teste | Sim, com preview de comando |
| R4 | Destrutivo / irreversivel | `rm -rf`, push --force, deploy, alterar git history | Sim, dupla confirmacao + texto literal |

## Regras invariantes

1. `compiled_prompt` deve ser `null` em modo `dictation`. Qualquer outro valor
   e erro do compiler.
2. `compiled_prompt` NAO pode ser `null` em modos `prompt_polish` e
   `intent_compile`. Se o compiler nao conseguiu gerar, retorna 422 ao Mac
   Edge antes de emitir o packet.
3. `risk_class` reclassificado para CIMA pelo Kernel registra
   `VOX_POLICY_EVALUATED` com `risk_uplift_reason`. Reclassificacao para
   BAIXO e proibida.
4. `context_refs` com `resolved: false` exigem confirmacao do Vitor no
   overlay antes de prosseguir (sessao pausa em `compilation_ambiguous`).
5. `constraints` extraidas do transcript original sao SEMPRE preservadas
   no `compiled_prompt`. Compiler que omite constraint detectada e bug.

## Evento Ledger associado

`VOX_INTENT_COMPILED` emitido apos producao bem-sucedida deste packet.
Payload: `session_id`, `intent_id`, `mode`, `goal`, `risk_class`,
`provider_hint`, `executor_hint`, `compiler_version`. **`compiled_prompt` NAO
vai para o ledger por default**; e referenciado via `intent_id`.

Quando `compiled_prompt` e gerado em modos `prompt_polish` /
`intent_compile`, emite-se adicionalmente `VOX_PROMPT_COMPILED` com
`compiled_prompt_template` e contador de tokens estimado.

## Versionamento

- v1 (2026-05-18): versao inicial canonica.
- Substitui versao informal mencionada em `atlas-vox-operational-thinking-interface.md`
  secao "V2: Intent Compiler" - este e o canon agora.

## V6.5 - extensoes additive (2026-05-19)

A Onda V6.5 introduziu campos opcionais que **NUNCA alteram o contrato
v1**. Clientes V3/V4/V5/V6 que nao conhecem o campo simplesmente o
ignoram; clientes V6.5+ leem se vier, caem em fallback se nao vier.

Politica geral:

1. **Additive somente**: nenhum campo legado e renomeado, removido, ou
   movido. `session_id`, `intent_id`, `goal`, `constraints`,
   `compiled_prompt`, etc. permanecem identicos ao v1.
2. **Opcional + nullable**: cada campo novo pode estar ausente OU `null`.
   O parser canonico do desktop (`bridge.ts::voxKernelIntent`) ja trata
   ambos os casos.
3. **Schema versao propria**: cada campo additive carrega seu proprio
   `schema` literal (ex.: `atlas.vox.prompt_quality.v1`). Drift de
   schema interno NAO bumpa o packet v1.
4. **Sem dependencia mutua**: clientes podem renderizar `flow_decision`
   sem ler `prompt_quality`, e vice-versa. UI nunca falha por ausencia.

Campos additive ativos:

| Campo                                     | Schema literal                       | Origem                          | Quando preencher                          |
|-------------------------------------------|--------------------------------------|---------------------------------|-------------------------------------------|
| `intent_packet.prompt_quality`            | `atlas.vox.prompt_quality.v1`        | `VoxPromptSelfCritic` (V6.5)    | Modos `intent_compile`/`governed_execute` |
| `intent_packet.compiler_telemetry.prompt_quality` | `atlas.vox.prompt_quality.v1` | `VoxPromptSelfCritic` (V6.5)    | Cópia interna; mesma origem               |
| `intent_packet.compiler_telemetry.quality_self_check` | (legado V6-FPG-B)        | `VoxPromptCompiler::selfCheck`  | Mantido por back-compat com analytics V6 |
| `flow_decision` (no response, NAO no packet) | `atlas.vox.flow_decision.v1`      | `VoxFlowOrchestrator` (V6.5)    | Modos com decisao explicita de destino    |

Garantias enforcadas por teste:

- `tests/Feature/Ai/Vox/AtlasAiVoxResponseCompatibilityTest` — prova que
  o response carrega os campos legados E aceita os additive sem regredir.
- `tests/Unit/Ai/Vox/VoxPromptSelfCriticTest` — prova que o envelope
  `atlas.vox.prompt_quality.v1` e determinístico e nao quebra clientes V6.
- `apps/desktop/src/lib/__tests__/voxResponseBackCompat.test.ts` (desktop)
  — prova que o parser do bridge aceita response V6 puro E V6.5 cheio.

Politica de drift:

- Acrescentar campo novo no packet: OK desde que seja opcional, nullable,
  carregue schema literal proprio e exista teste back-compat.
- Renomear campo legado: PROIBIDO sem bump para `intent_packet.v2`.
- Tornar campo legado obrigatorio: PROIBIDO.
- Mover campo legado entre `intent_packet` e root: PROIBIDO.
