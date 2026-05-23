---
id: atlas-continuity-intelligence-os
type: engineering_knowledge
title: Atlas Continuity Intelligence OS
status: building
category: workspace-intelligence
priority: 99
implementation_state: read_only_continuity_projection_present
summary: Sistema planejado que transforma conversas longas, runs, docs, testes, Obras e receipts em continuidade operacional pequena, verificavel e vinculada ao workspace.
tags:
  - atlas
  - continuity
  - workspace
  - conversation-fusion
  - context-pack
  - memory
capabilities:
  - raw_conversation_archive
  - conversation_segmentation
  - decision_ledger
  - conflict_report
  - continuity_timeline
  - current_truth_pack
  - task_context_pack
  - conversation_fusion_workspace
decisions:
  - Conversas longas sao materia-prima bruta, nao contexto principal.
  - O Atlas deve preservar bruto para auditoria e promover apenas verdade atual verificavel.
  - ACIOS depende de AWIS; continuidade sem workspace ativo e bloqueada.
  - ACFW e a interface de fusao manual; ACIOS e o runtime de continuidade continua.
  - Current Truth Pack vence resumo antigo, projection e chat quando sustentado por fonte canonica.
maintenance:
  - Atualizar antes de implementar fusao de conversas, continuity graph, current truth pack, task context pack ou memoria de longo prazo por workspace.
  - Manter abaixo de 520 linhas.
  - Rodar docs-health apos alterar.
related_paths:
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-workspace-intelligence-system.md
  - docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md
  - docs/engineering-knowledge-base/atlas-code-reality-usage-intelligence.md
  - docs/engineering-knowledge-base/atlas-cartographic-knowledge-os.md
  - docs/engineering-knowledge-base/atlas-quality-preserving-efficiency-system.md
  - docs/engineering-knowledge-base/atlas-execution-memory-outcome-runtime.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-continuity-intelligence-os
graph_title: Atlas Continuity Intelligence OS
graph_world: atlas
graph_layer: system
graph_kind: system
graph_parent: atlas-workspace-intelligence-system
graph_status: planned
graph_source: repo
product_name: Atlas Continuity Intelligence OS
runtime_acronym: ACIOS
internal_product_name: Atlas Continuity Command
technical_runtime: AtlasContinuityIntelligenceRuntime
human_name: Atlas Continuity Intelligence OS
canonical_name: Atlas Continuity Intelligence OS
technical_name: AtlasContinuityIntelligenceRuntime
cartography_type: system
canonical_source: docs/engineering-knowledge-base/atlas-continuity-intelligence-os.md
owner: workspace-intelligence
repo_paths:
  - docs/engineering-knowledge-base/atlas-continuity-intelligence-os.md
allowed_changes:
  - Evoluir contracts de archive, segmentation, fusion, continuity, truth pack e task pack.
  - Adicionar services, migrations, commands e tests quando implementados.
forbidden_changes:
  - Enviar conversas brutas enormes como contexto principal para provider.
  - Tratar resumo antigo como verdade atual sem fonte.
  - Fundir conversas de workspaces diferentes sem decisao humana explicita.
  - Promover hipotese para decisao canonica sem evidence.
depends_on:
  - atlas-workspace-intelligence-system
  - atlas-ai-knowledge-governance-system
  - atlas-code-reality-usage-intelligence
flows_to:
  - atlas-dev
  - atlas-forge
  - atlas-cartography
  - atlas-memory
unlocks:
  - long-conversation-continuity
  - current-truth-pack
  - provider-safe-task-context
  - cross-session-operational-memory
governs:
  - conversation_fusion
  - continuity_graph
  - current_truth_pack
  - task_context_pack
evidence:
  - docs/engineering-knowledge-base/atlas-continuity-intelligence-os.md
  - app/Services/Ai/WorkspaceIntelligence/AtlasWorkspaceIntelligenceRuntimeService.php
  - tests/Feature/Ai/WorkspaceIntelligence/AtlasWorkspaceIntelligenceRuntimeServiceTest.php
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: critical
visual_tags:
  - continuity
  - truth-pack
  - conversation-fusion
  - context-minimum
ai_entrypoints:
  - Leia este doc antes de implementar fusao de conversas, current truth pack, continuity graph ou ingestion de historico longo.
ai_usage_notes:
  - Se o usuario anexar varias conversas enormes, preserve bruto e gere truth pack; nao cole tudo no prompt.
quality_gates:
  - docs-health
  - future: atlas:continuity:certify --json --strict
failure_modes:
  - Resumo velho substituir decisao atual.
  - Contexto bruto degradar o modelo.
  - Conflito nao resolvido virar plano.
  - Conversas de projetos diferentes contaminarem o workspace.
observability_signals:
  - conversation_archive_hash
  - segmentation_map_hash
  - decision_ledger_hash
  - conflict_report_hash
  - current_truth_pack_hash
  - task_context_pack_hash
next_actions:
  - Criar schemas de archive, segmentation, fusion, truth pack e task pack.
  - Criar commandos read-only de ingestao e certificacao.
  - Integrar com AWIS, Cartografia, Dev e Forge.
---
# Atlas Continuity Intelligence OS

## Resumo

**Nome canonico / produto:** Atlas Continuity Intelligence OS  
**Acronimo tecnico:** ACIOS  
**Nome interno de experiencia / superficie:** Atlas Continuity Command  
**Runtime tecnico:** `AtlasContinuityIntelligenceRuntime`

ACIOS e a camada planejada que permite ao Atlas lidar com conversas enormes,
runs longos e historicos de muitos dias sem degradar o modelo. Ele nao tenta
"lembrar tudo" no prompt. Ele preserva tudo em arquivo/ledger e compila apenas
o estado atual verificavel para cada tarefa.

Regra principal:

```text
Historico bruto e auditoria.
Truth pack e contexto operacional.
Task pack e o que o provider recebe.
```

## Papel no Atlas

ACIOS resolve o problema que aparece quando Claude, Codex, Gemini ou qualquer
provider nasce zerado a cada sessao. O Atlas deve entregar continuidade sem
obrigar o operador a recontar tudo.

Ele transforma quatro conversas de nove dias em:

- arquivo bruto consultavel;
- linha do tempo;
- decisoes finais;
- conflitos;
- pendencias;
- entregas;
- verdade atual;
- contexto minimo para a proxima acao.

## Onde Se Encaixa

```text
AWIS / Workspace ativo
-> ACFW / fusao manual ou automatica
-> ACIOS / continuidade operacional
-> Current Truth Pack
-> Task Context Pack
-> Dev, Forge, Cartografia ou provider
```

AWIS define onde o trabalho vive. ACFW define como conversas sao fundidas.
ACIOS define o que continua verdadeiro e o que pode ser usado com seguranca.

## Contratos

### Raw Conversation Archive

```json
{
  "schema_version": "atlas.raw_conversation_archive.v1",
  "workspace_id": "atlas",
  "source_conversation_ids": ["conv_a", "conv_b"],
  "archive_hash": "sha256:...",
  "message_count": 12000,
  "time_span": {"from": "2026-05-01", "to": "2026-05-09"},
  "access_mode": "audit_only"
}
```

O bruto nunca e enviado inteiro para provider. Ele existe para auditoria,
recuperacao pontual e prova de origem.

### Current Truth Pack

```json
{
  "schema_version": "atlas.current_truth_pack.v1",
  "workspace_id": "atlas",
  "truth_pack_hash": "sha256:...",
  "current_goal": "corrigir e certificar Atlas Dev/Forge",
  "active_decisions": [],
  "superseded_decisions": [],
  "open_blockers": [],
  "canonical_sources": [],
  "conflicts": [],
  "stale": false
}
```

### Task Context Pack

```json
{
  "schema_version": "atlas.task_context_pack.v1",
  "workspace_id": "atlas",
  "task": "corrigir bug de login",
  "must_keep": [],
  "allowed_sources": [],
  "forbidden_context": [],
  "evidence_refs": [],
  "source_hashes": []
}
```

## Fluxo

Fluxo para conversas gigantes:

```text
ingest raw conversations
-> segment by goal/decision/file/blocker/outcome
-> extract candidate facts
-> deduplicate repeated claims
-> classify as decision, hypothesis, blocker, done, abandoned or stale
-> detect conflicts
-> resolve by authority hierarchy
-> emit current_truth_pack
-> emit task_context_pack for the next action
```

Autoridade:

```text
repo docs + code + tests + receipts
> current truth pack
> promoted memory
> conversation summary
> raw chat
```

## Escopo de Implementacao

Blocos:

| Bloco | Funcao | Saida |
|---|---|---|
| Raw Archive | preserva bruto | `archive_hash` |
| Segmentation Map | quebra por assunto | `segmentation_map_hash` |
| Decision Ledger | guarda decisoes finais | `decision_ledger_hash` |
| Conflict Report | aponta contradicoes | `conflict_report_hash` |
| Continuity Timeline | ordena eventos | `timeline_hash` |
| Current Truth Pack | estado atual confiavel | `truth_pack_hash` |
| Task Context Pack | contexto minimo para provider | `task_pack_hash` |
| Stale Gate | bloqueia contexto velho | ready/limited/blocked |
| Cartography Projection | mostra continuidade visual | mapa humano |

Fases:

- Fase 0: ingestao read-only de conversas longas.
- Fase 1: segmentacao e extracao sem promocao.
- Fase 2: conflict report e decision ledger.
- Fase 3: current truth pack manualmente revisavel.
- Fase 4: task context pack para Dev/Forge.
- Fase 5: Cartografia de continuidade.
- Fase 6: certificacao e enforcement parcial.

## Dependencias

- Canonical Glossary: nomes oficiais de Atlas Dev, Atlas Forge, Obra e runtime.
- AWIS: workspace ativo, memory scope e context boundary.
- Knowledge Governance: hierarquia de autoridade.
- ACRUI: realidade de codigo/documentacao.
- AEMOR: outcome memory.
- AQPES/ACCCR/ALVE: economia de tokens e verificacao local.
- Cartografia: leitura humana da continuidade.

## Regras para IA

- Nunca cole conversas enormes no prompt principal.
- Preserve bruto como auditoria e use `task_context_pack` para execucao.
- Nao promova decisao sem fonte.
- Nao resolva conflito por inferencia se docs/codigo/testes discordam.
- Se o workspace estiver ausente, pare e volte para AWIS.
- Se o truth pack estiver stale, bloqueie Dev/Forge ou gere refresh.
- Se uma conversa cita outro workspace, marque como cross-workspace e peça decisao humana.

## Evidencias

Evidencia atual: esta especificacao e projecao ACIOS read-only no runtime AWIS.
Ainda faltam comandos `atlas:continuity:*`, persistence e UI de fusao.

Comandos planejados:

```bash
php artisan atlas:continuity:ingest --workspace=atlas --source=conversation.json --json
php artisan atlas:continuity:fuse --workspace=atlas --conversations=a,b,c,d --json
php artisan atlas:continuity:truth-pack --workspace=atlas --json
php artisan atlas:continuity:task-pack --workspace=atlas --task="corrigir bug" --json
php artisan atlas:continuity:certify --workspace=atlas --json --strict
```

## Riscos

- **Contexto bruto demais:** aumenta custo e reduz qualidade.
- **Resumo falso:** parece coerente, mas perdeu decisao importante.
- **Conflito silencioso:** duas conversas dizem coisas opostas.
- **Fonte fraca:** chat vence doc/codigo/teste por acidente.
- **Workspace bleed:** conversas de outro projeto entram no pack.
- **Stale truth:** verdade antiga continua guiando execucao.
- **Deduplicacao agressiva:** remove nuance ou constraint critica.

Mitigacoes:

- preservar bruto;
- exigir hashes;
- declarar fonte por afirmacao;
- bloquear conflito nao resolvido;
- rodar stale gate;
- nunca promover sem evidence.

## Exemplos

Caso: quatro conversas de nove dias.

```text
raw archive: 4 conversas completas
segmentation: 312 segmentos
decision ledger: 41 decisoes
conflict report: 7 conflitos
current truth pack: 2 paginas operacionais
task context pack: 40-120 linhas para a proxima acao
```

Resultado: o provider nao recebe nove dias de historico. Ele recebe a realidade
atual necessaria para executar a tarefa com fonte e limite.

## Definition Of Done

ACIOS so esta pronto quando:

- ingestao preserva bruto sem vazar para prompt;
- segmentation map e deterministico;
- decision ledger tem fonte por decisao;
- conflict report bloqueia execucao insegura;
- current truth pack passa stale gate;
- task context pack e minimo e verificavel;
- Cartografia mostra timeline e conflitos;
- Dev/Forge usam task pack por padrao;
- `atlas:continuity:certify --json --strict` fica verde.

## Proximas Acoes

1. Implementar schemas read-only de archive, segmentation e truth pack.
2. Criar comandos `atlas:continuity:*` em modo shadow.
3. Integrar AWIS para bloquear continuidade sem workspace ativo.
4. Conectar Current Truth Pack ao Atlas Dev e Atlas Forge.
5. Criar projection visual na Cartografia.
