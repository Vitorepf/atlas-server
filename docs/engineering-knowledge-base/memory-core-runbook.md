---
id: atlas-memory-core-runbook
type: engineering_knowledge
title: Atlas Memory Core Runbook
status: active
category: maintenance
priority: 99
summary: Runbook operacional para validar, sincronizar, auditar e recuperar o sistema de memoria, knowledge base e code intelligence do Atlas.
tags:
  - atlas
  - memory
  - runbook
  - operations
capabilities:
  - memory_registry
  - knowledge_base
  - code_intelligence_index
  - provider_projection
  - context_pack_recall
decisions:
  - Operacoes de memoria devem ser auditaveis, repetiveis e pequenas.
  - Dry-run deve preceder operacoes destrutivas ou de escrita em provider projections.
  - Docs canonicos e code index devem ser sincronizados juntos apos mudancas relevantes.
maintenance:
  - Atualize este runbook quando comandos CLI, rotas ou fluxos de validacao mudarem.
  - Prefira /opt/homebrew/bin/php para Artisan neste projeto.
  - Registre validacoes reais no documento mestre depois de cada fase.
related_paths:
  - app/Console/Commands/AtlasMemoryListCommand.php
  - app/Console/Commands/AtlasMemoryAddCommand.php
  - app/Console/Commands/AtlasMemoryPrivacyCommand.php
  - app/Console/Commands/AtlasMemoryProjectionCommand.php
  - app/Console/Commands/AtlasEngineeringKnowledgeCommand.php
  - app/Services/Ai/AtlasMemoryRegistryService.php
  - app/Services/Engineering/EngineeringKnowledgeBaseService.php
  - app/Services/Engineering/EngineeringCodeIntelligenceService.php
---

# Atlas Memory Core Runbook

Este runbook e o procedimento operacional padrao para manter o sistema de
memoria do Atlas confiavel. Ele cobre verificacao rapida, sincronizacao,
auditoria, privacy review, provider projections e validacao de app.

## Regras Operacionais

- Use `/opt/homebrew/bin/php` para Artisan.
- Rode dry-run antes de operacoes destrutivas, purge ou projection apply/write.
- Depois de alterar docs canonicos, rode sync de knowledge e index-code.
- Depois de alterar codigo core, rode index-code.
- Depois de alterar memoria/contexto, rode testes focados antes de ampliar.
- Nao use provider files como fonte primaria; regenere a partir do Atlas.

## Health Check Rapido

```bash
/opt/homebrew/bin/php artisan migrate:status
/opt/homebrew/bin/php artisan route:list --path=memory
/opt/homebrew/bin/php artisan route:list --path=engineering/knowledge
/opt/homebrew/bin/php artisan atlas:engineering:knowledge status --json
/opt/homebrew/bin/php artisan atlas:engineering:knowledge code-status --json
```

Testes focados:

```bash
/opt/homebrew/bin/php artisan test --filter=AtlasMemoryRegistryTest
/opt/homebrew/bin/php artisan test --filter=AtlasEngineeringKnowledgeBaseTest
```

App:

```bash
npm run typecheck
npm run test:front
```

## Sync De Docs Canonicos

Quando mudar `docs/engineering-knowledge-base`:

```bash
/opt/homebrew/bin/php artisan atlas:engineering:knowledge sync --prune
/opt/homebrew/bin/php artisan atlas:engineering:knowledge index-code --prune --workspace=/Users/vitorepf/Develop/atlas/atlas-server
```

Inspecao:

```bash
/opt/homebrew/bin/php artisan atlas:engineering:knowledge list --limit=20
/opt/homebrew/bin/php artisan atlas:engineering:knowledge context --category=architecture --limit=8 --json
/opt/homebrew/bin/php artisan atlas:engineering:knowledge code-status --json
/opt/homebrew/bin/php artisan atlas:engineering:knowledge modules --docs-status=undocumented --json
```

## Code Intelligence

Dry-run sem escrita:

```bash
/opt/homebrew/bin/php artisan atlas:engineering:knowledge index-code --dry-run --workspace=/Users/vitorepf/Develop/atlas/atlas-server
```

Indexacao real:

```bash
/opt/homebrew/bin/php artisan atlas:engineering:knowledge index-code --prune --workspace=/Users/vitorepf/Develop/atlas/atlas-server
```

Auditoria de simbolos:

```bash
/opt/homebrew/bin/php artisan atlas:engineering:knowledge audit-code --workspace=/Users/vitorepf/Develop/atlas/atlas-server --json
/opt/homebrew/bin/php artisan atlas:engineering:knowledge symbols --symbol-type=route --limit=50 --json
/opt/homebrew/bin/php artisan atlas:engineering:knowledge symbols --symbol-type=cli_command --limit=50 --json
/opt/homebrew/bin/php artisan atlas:engineering:knowledge show-module engineering_harness_services --json
```

Nota: `index-code --dry-run` pode mostrar `doc_link_count=0`, porque doc links
sao recalculados a partir do estado persistido durante a indexacao real.
Use `audit-code` para comparar o scan atual com o indice persistido sem escrever
no banco. `fresh` significa que o indice acompanha o workspace; `drift_detected`
significa que `index-code --prune` deve ser considerado antes de montar context
packs confiaveis.

## Memory Registry

Listar memorias:

```bash
/opt/homebrew/bin/php artisan atlas:memory:list --limit=30
/opt/homebrew/bin/php artisan atlas:memory:list --type=decision --status=active --json
```

Adicionar memoria:

```bash
/opt/homebrew/bin/php artisan atlas:memory:add decision "Decisao resumida" --body="Contexto e motivo" --scope=global --priority=80 --importance=4
```

Auditar uso de memoria em trace:

```bash
/opt/homebrew/bin/php artisan atlas:memory:audit TRACE_ID --json
```

## Privacy E Review Queue

Scan sem escrita:

```bash
/opt/homebrew/bin/php artisan atlas:memory:privacy scan --limit=200 --json
```

Aplicar politica calculada:

```bash
/opt/homebrew/bin/php artisan atlas:memory:privacy apply --limit=200 --json
```

Revisar item especifico:

```bash
/opt/homebrew/bin/php artisan atlas:memory:privacy review MEMORY_ID --privacy=private --reviewed-by=operator --note="Motivo da classificacao"
```

Fila unificada:

```bash
/opt/homebrew/bin/php artisan atlas:memory:review-queue --include-unreviewed --limit=100 --json
```

## Governance E Conflitos

Scan de duplicates/conflitos:

```bash
/opt/homebrew/bin/php artisan atlas:memory:govern scan --dry-run --json
/opt/homebrew/bin/php artisan atlas:memory:relations list --status=pending --json
```

Resolver relacao:

```bash
/opt/homebrew/bin/php artisan atlas:memory:relations resolve RELATION_ID --resolution-action=merge --reviewed-by=operator --note="Resolucao aplicada"
```

## Verbatim Store

Listar:

```bash
/opt/homebrew/bin/php artisan atlas:memory:verbatim list --limit=30
```

Adicionar evidencia exata:

```bash
/opt/homebrew/bin/php artisan atlas:memory:verbatim add "Texto exato aprovado" --type=evidence --privacy=normal --allow-external-ai
```

Bloquear ou redigir:

```bash
/opt/homebrew/bin/php artisan atlas:memory:verbatim block --id=VERBATIM_ID --review-note="Nao provider-safe"
/opt/homebrew/bin/php artisan atlas:memory:verbatim redact --id=VERBATIM_ID --review-note="Redacao manual aplicada"
```

## Provider Projection

Status:

```bash
/opt/homebrew/bin/php artisan atlas:memory:projection status --target=all --workspace=/Users/vitorepf/Develop/atlas --json
```

Review antes de aplicar:

```bash
/opt/homebrew/bin/php artisan atlas:memory:projection review --target=all --workspace=/Users/vitorepf/Develop/atlas --json
```

Aplicar somente depois de review:

```bash
/opt/homebrew/bin/php artisan atlas:memory:projection apply --target=all --workspace=/Users/vitorepf/Develop/atlas --yes --json
```

Auditar projection:

```bash
/opt/homebrew/bin/php artisan atlas:memory:projection audit-summary --json
```

Purge exige dry-run/fingerprint no backend e pode exigir operador conforme
configuracao:

```bash
/opt/homebrew/bin/php artisan atlas:memory:projection audit-purge --older-than-days=30 --dry-run --json
```

## Validacao Antes De Encerrar Uma Fase

Backend:

```bash
/opt/homebrew/bin/php artisan test --filter=AtlasMemoryRegistryTest
/opt/homebrew/bin/php artisan test --filter=AtlasEngineeringKnowledgeBaseTest
/opt/homebrew/bin/php artisan route:list --path=memory
/opt/homebrew/bin/php artisan route:list --path=engineering/knowledge
```

Frontend:

```bash
npm run typecheck
npm run test:front
```

Documentacao:

```bash
rg -n "[ \t]+$" Atlas_AI_Memory_Context_Core_Open_Brain.md atlas-server/docs/engineering-knowledge-base
```
