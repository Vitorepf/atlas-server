---
id: atlas-memory-core-failure-modes
type: engineering_knowledge
title: Atlas Memory Core Failure Modes
status: active
category: maintenance
priority: 97
summary: Guia de diagnostico e recuperacao para falhas comuns do Memory Core, Knowledge Base, Code Intelligence, Context Pack e Provider Projection.
tags:
  - atlas
  - memory
  - failure-modes
  - recovery
capabilities:
  - operational_recovery
  - memory_governance
  - context_pack_debug
  - projection_drift_guard
decisions:
  - Falha de contexto deve ser diagnosticada por camada.
  - Recuperacao deve preferir reindexacao e review a edicao manual de estado.
maintenance:
  - Atualize sintomas e comandos quando novos gates ou jobs forem criados.
related_paths:
  - app/Services/Ai/AiContextPackBuilder.php
  - app/Services/Ai/AtlasMemoryRegistryService.php
  - app/Services/Ai/AtlasProviderProjectionAuditService.php
  - app/Services/Engineering/EngineeringContextPackService.php
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-memory-core-failure-modes

graph_title: Atlas Memory Core Failure Modes

graph_world: atlas

graph_layer: module

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo

owner: maintenance

repo_paths:
  - docs/engineering-knowledge-base/memory-core-failure-modes.md

allowed_changes:
  - Atualizar este doc quando codigo, arquitetura, fluxo, evidencia ou decisao canonica mudar.

forbidden_changes:
  - Declarar runtime, maturidade ou prontidao sem evidencia verificavel e gates verdes.

depends_on:
  - atlas-ai-documentation-operating-system

flows_to:
  - atlas-cartography
  - atlas-code

unlocks:
  - ai-safe-implementation-context

governs:
  - maintenance

evidence:
  - docs/engineering-knowledge-base/memory-core-failure-modes.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: low

visual_tags:
  - module
  - module
  - maintenance

ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA, Evidencias e Riscos antes de implementar.

ai_usage_notes:
  - Use repo_paths, allowed_changes, forbidden_changes e required_tests como limites operacionais.

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"

failure_modes:
  - Contexto desatualizado entre doc, codigo, teste e evidencia.

observability_signals:
  - docs-health status ok

next_actions:
  - Manter este doc sincronizado com codigo, testes, evidencias e Cartografia.
---
# Atlas Memory Core Failure Modes

Este guia ajuda a diagnosticar problemas sem misturar camadas. Sempre identifique
se a falha esta em migration, dados, index, privacy, context pack, provider
projection ou UI.

## Tabela De Falhas

| Sintoma | Causa provavel | Diagnostico | Recuperacao |
|---|---|---|---|
| API de memoria retorna erro de tabela | Migration pendente | `migrate:status` | Rodar migrations |
| `/engineering/knowledge` vazio | Docs nao sincronizados | `atlas:engineering:knowledge status --json` | `sync --prune` |
| `knowledge_refs` ausentes no context pack | Knowledge table vazia ou categoria nao ranqueada | `context --category=... --json` | Sync docs e revisar tags/categorias |
| `code_refs` vazios | Code index nao persistido | `code-status --json` | `index-code --prune` |
| `doc_link_count=0` no dry-run | Comportamento esperado | `index-code --dry-run` | Use `code-status` apos index real |
| Docs e codigo divergem | Knowledge docs ou code index stale | hashes `content_hash`, `source_hash`, `docs_hash` | `sync --prune` e `index-code --prune` |
| Context pack grande demais | Budget/limites altos ou refs demais | revisar `ATLAS_AI_*_LIMIT` | reduzir budget/limit ou melhorar ranking |
| Memoria errada entra no prompt | prioridade/confidence/escopo incorreto | `atlas:memory:audit TRACE_ID` | feedback, arquivar, ajustar prioridade |
| Memorias duplicadas/conflitantes | Governance pendente | `atlas:memory:govern scan --dry-run` | revisar relations |
| Conteudo privado aparece em preview | Privacy/redaction pendente | `atlas:memory:privacy scan --json` | apply/review privacy |
| Verbatim nao aparece no recall | bloqueado/sem provider-safe | `atlas:memory:verbatim list --json` | release/redact com review |
| Projection bloqueia apply | drift/manual/unmanaged/stale | `atlas:memory:projection status --json` | review/adopt/apply conforme caso |
| Audit purge bloqueado | falta fingerprint ou operador | dry-run purge | repetir com fingerprint/operador autorizado |
| App nao mostra dado novo | cache/loading ou backend stale | refresh + API direta | sync/index e reload app |

## Diagnostico Por Camada

### 1. Banco E Migrations

```bash
/opt/homebrew/bin/php artisan migrate:status
/opt/homebrew/bin/php artisan route:list --path=memory
/opt/homebrew/bin/php artisan route:list --path=engineering/knowledge
```

Se rota existe mas tabela nao existe, o problema e migration. Se tabela existe
mas resposta esta vazia, o problema e dados/index.

### 2. Knowledge Base

```bash
/opt/homebrew/bin/php artisan atlas:engineering:knowledge status --json
/opt/homebrew/bin/php artisan atlas:engineering:knowledge list --limit=20 --json
/opt/homebrew/bin/php artisan atlas:engineering:knowledge context --category=architecture --json
```

Recuperacao:

```bash
/opt/homebrew/bin/php artisan atlas:engineering:knowledge sync --prune
```

### 3. Code Intelligence

```bash
/opt/homebrew/bin/php artisan atlas:engineering:knowledge code-status --json
/opt/homebrew/bin/php artisan atlas:engineering:knowledge modules --docs-status=undocumented --json
/opt/homebrew/bin/php artisan atlas:engineering:knowledge symbols --symbol-type=route --limit=50 --json
```

Recuperacao:

```bash
/opt/homebrew/bin/php artisan atlas:engineering:knowledge index-code --prune --workspace=/Users/vitorepf/Develop/atlas/atlas-server
```

### 4. Memory Registry

```bash
/opt/homebrew/bin/php artisan atlas:memory:list --limit=50 --json
/opt/homebrew/bin/php artisan atlas:memory:review-queue --include-unreviewed --json
```

Se uma memoria esta errada, prefira arquivar ou corrigir com review. Evite
delete direto, porque perde rastro de auditoria.

### 5. Privacy

```bash
/opt/homebrew/bin/php artisan atlas:memory:privacy scan --limit=200 --json
```

Se houver risco, aplique ou revise:

```bash
/opt/homebrew/bin/php artisan atlas:memory:privacy apply --limit=200 --json
```

### 6. Provider Projection

```bash
/opt/homebrew/bin/php artisan atlas:memory:projection status --target=all --workspace=/Users/vitorepf/Develop/atlas --json
/opt/homebrew/bin/php artisan atlas:memory:projection review --target=all --workspace=/Users/vitorepf/Develop/atlas --json
```

Estados comuns:

- `missing`: projection ainda nao existe;
- `unmanaged`: arquivo humano nao adotado;
- `manual_drift`: bloco gerenciado mudou fora do checksum esperado;
- `stale`: memoria Atlas mudou depois da projection;
- `ok`: projection consistente.

## Escalacao

Escalar para implementacao quando:

- o parser nao reconhece padrao de rota/comando usado pelo codigo;
- privacy class correta ainda permite conteudo indevido;
- context pack precisa de ranking novo;
- projection precisa de target novo;
- app precisa expor fluxo operacional que so existe em CLI.

## Resumo

Guia de diagnostico e recuperacao para falhas comuns do Memory Core, Knowledge Base, Code Intelligence, Context Pack e Provider Projection.

## Papel no Atlas

Define a responsabilidade desta peca dentro da arquitetura Atlas.

## Onde Se Encaixa

Relaciona esta peca com seu sistema, camada, fluxo ou modulo pai.

## Contratos

Declara invariantes, entradas, saidas, limites e obrigacoes relevantes.

## Fluxo

Descreve o caminho operacional ou a sequencia de uso quando aplicavel.

## Regras para IA

Agentes devem respeitar escopo, evidencias, testes e proibicoes antes de alterar codigo.

## Escopo de Implementacao

Mudancas devem permanecer nos caminhos e limites declarados no frontmatter.

## Dependencias

Dependencias canonicas vivem em frontmatter e no corpo deste documento.

## Evidencias

Evidencias aceitas incluem docs, comandos, testes, receipts, reports e paths verificaveis.

## Riscos

Riscos principais devem ser tratados antes de promover status, runtime ou claims de prontidao.

## Exemplos

Exemplos concretos devem ser adicionados quando reduzirem ambiguidade para humanos ou IAs.

## Proximas Acoes

Proximas acoes devem ser concretas, verificaveis e ligadas a gates de qualidade.
