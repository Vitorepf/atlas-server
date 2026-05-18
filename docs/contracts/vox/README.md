---
id: vox-contracts-v1-index
type: contract_index
title: Atlas Vox v1 Contracts Index
status: active
category: contracts
priority: 95
summary: Indice canonico dos 5 contratos v1 do Atlas Vox usados em V0-V3. Schemas obrigatorios para qualquer implementacao de runtime Vox.
tags:
  - atlas-vox
  - contracts
  - schema
  - v1
maintenance:
  - Atualizar apenas via ADR explicita.
  - Contratos v1 sao imutaveis em campos obrigatorios; extensoes vao para v1.x ou v2.
related_paths:
  - docs/engineering-knowledge-base/atlas-vox-operational-thinking-interface.md
  - docs/engineering-knowledge-base/adr/0003-vox-vs-voice-realtime-surface-boundary.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: vox-contracts-v1-index

graph_title: Atlas Vox v1 Contracts Index

graph_world: atlas

graph_layer: contract

graph_kind: contract_index

graph_parent: atlas-vox-operational-thinking-interface

graph_status: active

graph_source: repo

owner: surface-architecture

repo_paths:
  - docs/contracts/vox/README.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

ai_entrypoints:
  - Leia este indice antes de implementar VoxCompiler, VoxController, VoxOverlay ou Mac Edge.

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
---
# Atlas Vox v1 Contracts Index

5 contratos canonicos. Versao `v1` aprovada 2026-05-18 via revisao
arquitetural (`plans/synchronous-weaving-meteor.md`) e ADR 0003. Imutaveis em
campos obrigatorios; extensoes adicionam-se via `v1.x` ou nova versao.

| Contrato | Arquivo | Quem produz | Quem consome |
| --- | --- | --- | --- |
| `VoxSessionPacket.v1` | `VoxSessionPacket.v1.md` | Mac Edge daemon | Kernel (`VoxController`) |
| `VoxTranscript.v1` | `VoxTranscript.v1.md` | Mac Edge (STT local) | Kernel (`VoxCompiler`) |
| `VoxIntentPacket.v1` | `VoxIntentPacket.v1.md` | Kernel (`VoxCompiler`) | Kernel (`AtlasDecide`) |
| `VoxConfirmation.v1` | `VoxConfirmation.v1.md` | Kernel <-> Overlay | Atlas Desktop (`VoxOverlay`) |
| `VoxActionOutcome.v1` | `VoxActionOutcome.v1.md` | Kernel (Executor) | `AtlasEvidenceLedger` |

## Modo Validacao

Cada contrato e Markdown + exemplo JSON + tabela de campos. JSON Schemas
formais (`.schema.json`) sao gerados na Onda 2 quando `VoxCompiler` for
implementado em `app/Services/Ai/Vox/Schema/`. Ate la, Markdown + exemplos sao
fonte de verdade.

## Regras de versionamento

- `v1` campos obrigatorios sao imutaveis. Adicionar campo obrigatorio = `v2`.
- Adicionar campo opcional com default = `v1.x` (compativel).
- Renomear, remover, mudar tipo, mudar enum = nova versao major.
- Migracao entre versoes exige ADR explicita.

## Eventos `VOX_*` no Evidence Ledger

Os 13 eventos canonicos vivem em `atlas-vox-operational-thinking-interface.md`
secao "Eventos Minimos". Dois eventos adicionais previstos pela revisao
arquitetural (a confirmar em Onda 2):

- `VOX_PROMPT_COMPILED` - prompt poderoso gerado em modo
  `prompt_polish`/`intent_compile`.
- `VOX_PERSONAL_DICTIONARY_UPDATED` - correcao recorrente promovida ao
  dicionario pessoal.
